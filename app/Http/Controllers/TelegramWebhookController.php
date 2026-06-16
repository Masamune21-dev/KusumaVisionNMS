<?php

namespace App\Http\Controllers;

use App\Models\TelegramSetting;
use App\Services\Telegram\TelegramCommandHandler;
use App\Services\Telegram\TelegramNotifier;
use App\Services\Telegram\TelegramReply;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Inbound Telegram webhook. Public route (no auth) — the gate is the secret token
 * header that only Telegram knows. Always returns 200 on accepted updates so Telegram
 * does not retry; a wrong/missing secret returns 403.
 *
 * Handles two update kinds: text messages (slash commands) and callback queries
 * (inline-button presses driving the interactive menu).
 */
class TelegramWebhookController extends Controller
{
    public function handle(
        Request $request,
        TelegramCommandHandler $handler,
        TelegramNotifier $notifier,
    ): JsonResponse {
        $setting = TelegramSetting::instance();

        $expected = (string) $setting->webhook_secret;
        $provided = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');

        if ($expected === '' || ! hash_equals($expected, $provided)) {
            return response()->json(['ok' => false], 403);
        }

        // Webhook still registered but command handling turned off — accept & ignore.
        if (! $setting->commandsReady()) {
            return response()->json(['ok' => true]);
        }

        try {
            if ($request->input('callback_query') !== null) {
                $this->handleCallback($request, $handler, $notifier, $setting);
            } else {
                $this->handleMessage($request, $handler, $notifier, $setting);
            }
        } catch (Throwable $exception) {
            // A handler bug must not make Telegram retry the same update forever.
            Log::warning('Telegram update handling failed', [
                'error' => $exception->getMessage(),
            ]);
        }

        return response()->json(['ok' => true]);
    }

    private function handleMessage(
        Request $request,
        TelegramCommandHandler $handler,
        TelegramNotifier $notifier,
        TelegramSetting $setting,
    ): void {
        $chatId = $request->input('message.chat.id');
        $text = $request->input('message.text');

        if ($chatId === null || ! is_string($text)) {
            return;
        }

        $reply = $handler->handle($text, (string) $chatId, $setting);

        if ($reply instanceof TelegramReply) {
            $notifier->sendTo((string) $chatId, $reply->text, $reply->keyboard);
        }
    }

    private function handleCallback(
        Request $request,
        TelegramCommandHandler $handler,
        TelegramNotifier $notifier,
        TelegramSetting $setting,
    ): void {
        $callbackId = (string) $request->input('callback_query.id', '');
        $chatId = $request->input('callback_query.message.chat.id');
        $messageId = $request->input('callback_query.message.message_id');
        $data = $request->input('callback_query.data');

        // Always dismiss the button spinner, even if we render nothing.
        if ($callbackId !== '') {
            $notifier->answerCallback($callbackId);
        }

        if ($chatId === null || ! is_string($data)) {
            return;
        }

        $reply = $handler->handleCallback($data, (string) $chatId, $setting);

        if (! $reply instanceof TelegramReply) {
            return;
        }

        // Edit the message in place when we have its id; otherwise send a new one.
        if (is_numeric($messageId)) {
            $notifier->editMessage((string) $chatId, (int) $messageId, $reply->text, $reply->keyboard);
        } else {
            $notifier->sendTo((string) $chatId, $reply->text, $reply->keyboard);
        }
    }
}
