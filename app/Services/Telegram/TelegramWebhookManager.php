<?php

namespace App\Services\Telegram;

use App\Models\TelegramSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Registers / inspects / removes the Telegram webhook for inbound commands.
 *
 * Shared by the telegram:webhook artisan command and the Settings UI buttons so the
 * setup logic lives in one place.
 */
class TelegramWebhookManager
{
    private const API_BASE = 'https://api.telegram.org';

    /**
     * Point Telegram at our webhook endpoint, generating the secret token if missing.
     *
     * @return array{ok: bool, message: string, url?: string}
     */
    public function register(): array
    {
        $setting = TelegramSetting::instance();

        if (blank($setting->bot_token)) {
            return ['ok' => false, 'message' => 'Bot token belum dikonfigurasi.'];
        }

        if (blank($setting->webhook_secret)) {
            $setting->webhook_secret = Str::random(48);
        }
        $setting->commands_enabled = true;
        $setting->save();

        $url = route('telegram.webhook');

        $result = $this->call($setting->bot_token, 'setWebhook', [
            'url' => $url,
            'secret_token' => $setting->webhook_secret,
            'allowed_updates' => ['message', 'callback_query'],
            'drop_pending_updates' => true,
        ]);

        if (! $result['ok']) {
            return $result;
        }

        return ['ok' => true, 'message' => 'Webhook terdaftar.', 'url' => $url];
    }

    /**
     * @return array{ok: bool, message: string, info?: array<string, mixed>}
     */
    public function info(): array
    {
        $setting = TelegramSetting::instance();

        if (blank($setting->bot_token)) {
            return ['ok' => false, 'message' => 'Bot token belum dikonfigurasi.'];
        }

        $result = $this->call($setting->bot_token, 'getWebhookInfo');

        if (! $result['ok']) {
            return $result;
        }

        return ['ok' => true, 'message' => 'OK', 'info' => $result['result'] ?? []];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function delete(): array
    {
        $setting = TelegramSetting::instance();

        if (blank($setting->bot_token)) {
            return ['ok' => false, 'message' => 'Bot token belum dikonfigurasi.'];
        }

        $result = $this->call($setting->bot_token, 'deleteWebhook', ['drop_pending_updates' => false]);

        if (! $result['ok']) {
            return $result;
        }

        $setting->commands_enabled = false;
        $setting->save();

        return ['ok' => true, 'message' => 'Webhook dihapus.'];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, message: string, result?: array<string, mixed>}
     */
    private function call(string $token, string $method, array $payload = []): array
    {
        try {
            $response = Http::asJson()
                ->timeout(15)
                ->post(self::API_BASE."/bot{$token}/{$method}", $payload);
        } catch (Throwable $exception) {
            return ['ok' => false, 'message' => $exception->getMessage()];
        }

        if (! $response->successful() || $response->json('ok') !== true) {
            return [
                'ok' => false,
                'message' => $response->json('description') ?: 'HTTP '.$response->status().' dari Telegram.',
            ];
        }

        return ['ok' => true, 'message' => 'OK', 'result' => $response->json('result') ?? []];
    }
}
