<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Pengaturan alarm TERPUSAT — singleton, satu-satunya sumber kebijakan alarm aplikasi
 * (halaman Settings → tab **Alarm**). Dipakai bersama SEMUA kanal notifikasi:
 * bot Telegram global ({@see TelegramSetting}) dan push mobile ({@see FcmSetting});
 * tab kanal hanya mengurus koneksi (token/chat id, saklar push, daftar perangkat).
 *
 * Isinya:
 *  - `confirm_before_notify` — debounce anti-flap 2 poll vs realtime ({@see App\Services\AlarmEvaluator}).
 *  - `min_severity` / `notify_on_raise` / `notify_on_clear` / `notify_types` — filter alarm mana
 *    yang diteruskan ke penerima.
 *  - `suppress_child_alarms` — korelasi root-cause: saat port PON atau ODP down, alarm ONU di
 *    bawahnya TIDAK dinotifikasikan (cukup induknya). Event tetap tercatat untuk UI/riwayat.
 *  - `group_odp_alarms` — beberapa ONU 1 ODP down (belum semua) dirangkum jadi 1 pesan grup.
 *
 * CATATAN: bot Telegram per-partner ({@see PartnerTelegramBot}) tetap memakai filter miliknya
 * sendiri — partner mengatur botnya di halaman partner, bukan di Settings admin.
 */
class AlarmSetting extends Model
{
    use Auditable;

    protected $fillable = [
        'confirm_before_notify',
        'min_severity',
        'notify_on_raise',
        'notify_on_clear',
        'notify_types',
        'suppress_child_alarms',
        'group_odp_alarms',
    ];

    /**
     * Default untuk instance baru (firstOrNew) sebelum baris pertama disimpan — agar
     * perilaku "out of the box" aman: debounce 2 poll aktif, korelasi root-cause aktif,
     * semua jenis alarm dikirim saat naik.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'confirm_before_notify' => true,
        'min_severity' => AlarmEvent::SEVERITY_WARNING,
        'notify_on_raise' => true,
        'notify_on_clear' => false,
        'suppress_child_alarms' => true,
        'group_odp_alarms' => true,
    ];

    protected function casts(): array
    {
        return [
            'confirm_before_notify' => 'boolean',
            'notify_on_raise' => 'boolean',
            'notify_on_clear' => 'boolean',
            'notify_types' => 'array',
            'suppress_child_alarms' => 'boolean',
            'group_odp_alarms' => 'boolean',
        ];
    }

    public function auditLabel(): string
    {
        return 'Pengaturan Alarm';
    }

    public function auditTitle(): string
    {
        return '';
    }

    public static function instance(): self
    {
        return static::query()->firstOrNew([]);
    }

    /**
     * Instance yang selalu bisa dibaca: bila tabel belum ada (fresh checkout sebelum migrasi)
     * kembalikan objek default in-memory alih-alih melempar. Dipakai jalur polling/notifikasi
     * yang tak boleh gagal karena pengaturan.
     */
    public static function policy(): self
    {
        try {
            return static::instance();
        } catch (\Throwable) {
            return new self;
        }
    }

    /**
     * Apakah notifikasi harus menunggu konfirmasi poll ke-2 (debounce anti-flap) sebelum dikirim.
     */
    public static function confirmBeforeNotify(): bool
    {
        return (bool) static::policy()->confirm_before_notify;
    }

    /**
     * Korelasi root-cause aktif: port PON / ODP down menahan notifikasi alarm ONU anaknya.
     */
    public static function suppressChildAlarms(): bool
    {
        return (bool) static::policy()->suppress_child_alarms;
    }

    /**
     * Rangkum beberapa ONU down dalam 1 ODP menjadi satu pesan grup.
     */
    public static function groupOdpAlarms(): bool
    {
        return (bool) static::policy()->group_odp_alarms;
    }

    public function minSeverityRank(): int
    {
        return AlarmEvent::SEVERITY_RANK[$this->min_severity] ?? 1;
    }

    /**
     * Jenis alarm yang dipilih untuk dikirim. null = semua jenis.
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
}
