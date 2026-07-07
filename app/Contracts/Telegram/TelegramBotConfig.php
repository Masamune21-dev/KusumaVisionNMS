<?php

namespace App\Contracts\Telegram;

use App\Models\PartnerTelegramBot;
use App\Models\TelegramSetting;
use App\Services\Telegram\TelegramNotifier;
use App\Services\Telegram\TelegramWebhookManager;

/**
 * Kontrak konfigurasi sebuah bot Telegram. Diimplementasikan oleh {@see TelegramSetting}
 * (bot global admin — semua OLT) dan {@see PartnerTelegramBot} (bot milik partner,
 * dibatasi ke OLT yang di-assign). Membuat {@see TelegramNotifier},
 * {@see TelegramWebhookManager}, dan webhook memperlakukan keduanya seragam.
 */
interface TelegramBotConfig
{
    public function botToken(): string;

    public function webhookSecret(): string;

    /**
     * @return array<int, string>
     */
    public function chatIds(): array;

    public function isConfigured(): bool;

    public function isReady(): bool;

    public function commandsReady(): bool;

    public function isChatAuthorized(string $chatId): bool;

    public function minSeverityRank(): int;

    public function shouldNotifyType(?string $type): bool;

    public function notifyOnRaise(): bool;

    public function notifyOnClear(): bool;

    /**
     * URL webhook yang didaftarkan ke Telegram untuk bot ini.
     */
    public function webhookUrl(): string;

    /**
     * Aktifkan command: buat webhook_secret bila kosong + set commands_enabled, lalu simpan.
     */
    public function markCommandsEnabled(): void;

    public function markCommandsDisabled(): void;

    /**
     * Catat hasil pengiriman terakhir (health push alarm).
     */
    public function recordDelivery(?string $error): void;
}
