<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Pengaturan push mobile (FCM) — singleton. Kini hanya menyimpan SAKLAR kanal (aktif/tidak)
 * + jejak pengiriman terakhir; FILTER alarm (severity minimum, raise/clear, jenis) TERPUSAT di
 * {@see AlarmSetting} (Settings → tab Alarm) dan dibaca lewat method delegasi di bawah, supaya
 * Telegram & mobile tak bisa lagi berbeda diam-diam. Kolom lama dipertahankan demi rollback.
 */
class FcmSetting extends Model
{
    use Auditable;

    /** @var list<string> */
    protected $auditExclude = ['last_sent_at', 'last_error'];

    public function auditLabel(): string
    {
        return 'Pengaturan Notifikasi Mobile';
    }

    public function auditTitle(): string
    {
        return '';
    }

    /** @deprecated pakai {@see AlarmEvent::SEVERITY_RANK} — alias demi kompatibilitas pemanggil lama. */
    public const SEVERITY_RANK = AlarmEvent::SEVERITY_RANK;

    protected $fillable = [
        'enabled',
        'min_severity',
        'notify_on_raise',
        'notify_on_clear',
        'notify_types',
        'last_sent_at',
        'last_error',
    ];

    /**
     * Default untuk instance baru (firstOrNew) sebelum baris pertama disimpan —
     * agar push aktif "out of the box" saat kredensial terpasang.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'enabled' => true,
        'min_severity' => AlarmEvent::SEVERITY_MAJOR,
        'notify_on_raise' => true,
        'notify_on_clear' => false,
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'notify_on_raise' => 'boolean',
            'notify_on_clear' => 'boolean',
            'notify_types' => 'array',
            'last_sent_at' => 'datetime',
        ];
    }

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
     * Tipe alarm yang dipilih untuk push (null = semua tipe).
     *
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
