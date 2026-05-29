<?php

namespace App\Http\Controllers;

use App\Models\TelegramSetting;
use App\Services\Telegram\TelegramCommandHandler;
use App\Services\Telegram\TelegramNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Inbound Telegram webhook. Public route (no auth) — the gate is the secret token
 * header that only Telegram knows. Always returns 200 on accepted updates so Telegram
 * does not retry; a wrong/missing secret returns 403.
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

        $chatId = $request->input('message.chat.id');
        $text = $request->input('message.text');

        // Non-message updates (edited messages, callbacks, etc.) are ignored.
        if ($chatId === null || ! is_string($text)) {
            return response()->json(['ok' => true]);
        }

        try {
            $reply = $handler->handle($text, (string) $chatId, $setting);

            if ($reply !== null) {
                $notifier->sendTo((string) $chatId, $reply);
            }
        } catch (Throwable $exception) {
            // A handler bug must not make Telegram retry the same update forever.
            Log::warning('Telegram command handling failed', [
                'chat_id' => $chatId,
                'error' => $exception->getMessage(),
            ]);
        }

        return response()->json(['ok' => true]);
    }
}
