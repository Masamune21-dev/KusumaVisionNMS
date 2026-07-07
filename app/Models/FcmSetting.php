<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Pengaturan push mobile (FCM) — singleton. Menentukan alarm mana yang diteruskan
 * ke aplikasi Android (severity minimum, raise/clear, filter per-tipe). Mirror
 * {@see TelegramSetting} tapi untuk kanal FCM.
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

    public const SEVERITY_RANK = [
        AlarmEvent::SEVERITY_WARNING => 1,
        AlarmEvent::SEVERITY_MINOR => 2,
        AlarmEvent::SEVERITY_MAJOR => 3,
        AlarmEvent::SEVERITY_CRITICAL => 4,
    ];

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

    public function minSeverityRank(): int
    {
        return self::SEVERITY_RANK[$this->min_severity] ?? self::SEVERITY_RANK[AlarmEvent::SEVERITY_MAJOR];
    }

    /**
     * Tipe alarm yang dipilih untuk push (null = semua tipe).
     *
     * @return array<int, string>
     */
    public function notifyTypes(): array
    {
        return $this->notify_types ?? AlarmEvent::types();
    }
}
