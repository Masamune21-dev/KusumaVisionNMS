<?php

namespace App\Services\Telegram;

use App\Contracts\Telegram\TelegramBotConfig;
use App\Models\TelegramSetting;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Registers / inspects / removes the Telegram webhook for inbound commands.
 *
 * Shared by the telegram:webhook artisan command, the Settings UI buttons, and the
 * partner self-service page so the setup logic lives in one place. Beroperasi atas
 * {@see TelegramBotConfig} — bot global (default) atau bot partner.
 */
class TelegramWebhookManager
{
    private const API_BASE = 'https://api.telegram.org';

    /**
     * Point Telegram at our webhook endpoint, generating the secret token if missing.
     *
     * @return array{ok: bool, message: string, url?: string}
     */
    public function register(?TelegramBotConfig $config = null): array
    {
        $config ??= TelegramSetting::instance();

        if ($config->botToken() === '') {
            return ['ok' => false, 'message' => 'Bot token belum dikonfigurasi.'];
        }

        $config->markCommandsEnabled();

        $url = $config->webhookUrl();

        $result = $this->call($config->botToken(), 'setWebhook', [
            'url' => $url,
            'secret_token' => $config->webhookSecret(),
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
    public function info(?TelegramBotConfig $config = null): array
    {
        $config ??= TelegramSetting::instance();

        if ($config->botToken() === '') {
            return ['ok' => false, 'message' => 'Bot token belum dikonfigurasi.'];
        }

        $result = $this->call($config->botToken(), 'getWebhookInfo');

        if (! $result['ok']) {
            return $result;
        }

        return ['ok' => true, 'message' => 'OK', 'info' => $result['result'] ?? []];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function delete(?TelegramBotConfig $config = null): array
    {
        $config ??= TelegramSetting::instance();

        if ($config->botToken() === '') {
            return ['ok' => false, 'message' => 'Bot token belum dikonfigurasi.'];
        }

        $result = $this->call($config->botToken(), 'deleteWebhook', ['drop_pending_updates' => false]);

        if (! $result['ok']) {
            return $result;
        }

        $config->markCommandsDisabled();

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
