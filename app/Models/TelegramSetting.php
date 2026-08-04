<?php

namespace App\Models;

use App\Contracts\Telegram\TelegramBotConfig;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TelegramBotConfigTrait;
use Illuminate\Database\Eloquent\Model;

/**
 * Bot Telegram GLOBAL (admin) — mendapat alarm dari SEMUA OLT & command lintas-OLT.
 * Bot per-partner ada di {@see PartnerTelegramBot}. Keduanya berbagi
 * {@see TelegramBotConfigTrait} dan memenuhi {@see TelegramBotConfig}.
 *
 * FILTER ALARM-nya TERPUSAT di {@see AlarmSetting} (Settings → tab Alarm): severity minimum,
 * kirim saat naik/pulih, dan jenis alarm dibaca dari sana, BUKAN dari kolom-kolom senama di
 * tabel ini (kolom lama dipertahankan demi kompatibilitas/rollback). Model ini kini hanya
 * mengurus koneksi bot: token, chat id, saklar aktif, webhook/command.
 * Bot partner tetap memakai filter per-bot miliknya sendiri.
 */
class TelegramSetting extends Model implements TelegramBotConfig
{
    use Auditable, TelegramBotConfigTrait;

    /**
     * @var list<string>
     */
    protected $auditExclude = ['last_sent_at', 'last_error'];

    public function auditLabel(): string
    {
        return 'Pengaturan Telegram';
    }

    public function auditTitle(): string
    {
        return '';
    }

    protected $fillable = [
        'enabled',
        'bot_token',
        'chat_id',
        'webhook_secret',
        'commands_enabled',
        'min_severity',
        'notify_on_raise',
        'notify_on_clear',
        'notify_types',
        'last_sent_at',
        'last_error',
    ];

    protected $hidden = [
        'bot_token',
        'webhook_secret',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'bot_token' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'commands_enabled' => 'boolean',
            'notify_on_raise' => 'boolean',
            'notify_on_clear' => 'boolean',
            'notify_types' => 'array',
            'last_sent_at' => 'datetime',
        ];
    }

    /**
     * The singleton settings row (or a fresh unsaved instance if none exists yet).
     */
    public static function instance(): self
    {
        return static::query()->firstOrNew([]);
    }

    /* --------------------------------------------------------------------- */
    /* Filter alarm — didelegasikan ke kebijakan terpusat (Settings → Alarm). */
    /* --------------------------------------------------------------------- */

    public function minSeverityRank(): int
    {
        return AlarmSetting::policy()->minSeverityRank();
    }

    /**
     * @return array<int, string>
     */
    public function notifyTypes(): array
    {
        return AlarmSetting::policy()->notifyTypes();
    }

    public function shouldNotifyType(?string $type): bool
    {
        return AlarmSetting::policy()->shouldNotifyType($type);
    }

    public function notifyOnRaise(): bool
    {
        return AlarmSetting::policy()->notifyOnRaise();
    }

    public function notifyOnClear(): bool
    {
        return AlarmSetting::policy()->notifyOnClear();
    }
}
