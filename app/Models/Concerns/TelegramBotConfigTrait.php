<?php

namespace App\Models\Concerns;

use App\Contracts\Telegram\TelegramBotConfig;
use App\Models\AlarmEvent;
use App\Models\PartnerTelegramBot;
use App\Models\TelegramSetting;
use Illuminate\Support\Str;

/**
 * Logika bersama bot Telegram (parse chat id, kesiapan, filter severity/jenis, webhook).
 * Dipakai {@see TelegramSetting} dan {@see PartnerTelegramBot} yang
 * memiliki kolom identik. Memenuhi {@see TelegramBotConfig}.
 */
trait TelegramBotConfigTrait
{
    /**
     * Urutan severity (rendah → tinggi) untuk filter minimal severity.
     */
    public const SEVERITY_RANK = [
        AlarmEvent::SEVERITY_WARNING => 1,
        AlarmEvent::SEVERITY_MINOR => 2,
        AlarmEvent::SEVERITY_MAJOR => 3,
        AlarmEvent::SEVERITY_CRITICAL => 4,
    ];

    public function botToken(): string
    {
        return (string) $this->bot_token;
    }

    public function webhookSecret(): string
    {
        return (string) $this->webhook_secret;
    }

    /**
     * @return array<int, string>
     */
    public function chatIds(): array
    {
        if (blank($this->chat_id)) {
            return [];
        }

        return array_values(array_filter(
            preg_split('/[\s,]+/', trim((string) $this->chat_id)) ?: [],
            fn (string $id) => $id !== '',
        ));
    }

    public function isConfigured(): bool
    {
        return filled($this->bot_token) && $this->chatIds() !== [];
    }

    public function isReady(): bool
    {
        return $this->enabled && $this->isConfigured();
    }

    /**
     * Command inbound aktif & lengkap.
     */
    public function commandsReady(): bool
    {
        return $this->commands_enabled
            && filled($this->bot_token)
            && filled($this->webhook_secret);
    }

    /**
     * Hanya chat di allow-list yang boleh menjalankan command data.
     */
    public function isChatAuthorized(string $chatId): bool
    {
        return in_array(trim($chatId), $this->chatIds(), true);
    }

    public function minSeverityRank(): int
    {
        return self::SEVERITY_RANK[$this->min_severity] ?? 1;
    }

    /**
     * Jenis alarm yang dipilih untuk dikirim. null = semua jenis (default/kompat lama).
     *
     * @return array<int, string>
     */
    public function notifyTypes(): array
    {
        return $this->notify_types ?? AlarmEvent::types();
    }

    public function shouldNotifyType(?string $type): bool
    {
        if ($type === null || $this->notify_types === null) {
            return true;
        }

        return in_array($type, $this->notify_types, true);
    }

    public function notifyOnRaise(): bool
    {
        return (bool) $this->notify_on_raise;
    }

    public function notifyOnClear(): bool
    {
        return (bool) $this->notify_on_clear;
    }

    /**
     * Parameter route webhook (override di partner bot untuk menyisipkan id).
     *
     * @return array<string, mixed>
     */
    public function webhookRouteParameters(): array
    {
        return [];
    }

    public function webhookUrl(): string
    {
        return route('telegram.webhook', $this->webhookRouteParameters());
    }

    public function markCommandsEnabled(): void
    {
        if (blank($this->webhook_secret)) {
            $this->webhook_secret = Str::random(48);
        }
        $this->commands_enabled = true;
        $this->save();
    }

    public function markCommandsDisabled(): void
    {
        $this->commands_enabled = false;
        $this->save();
    }

    public function recordDelivery(?string $error): void
    {
        $this->forceFill([
            'last_sent_at' => now(),
            'last_error' => $error,
        ])->save();
    }
}
