<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Pengaturan perilaku alarm — singleton. Menentukan APAKAH notifikasi (Telegram + FCM)
 * dikirim langsung saat fault pertama terdeteksi (realtime) atau menunggu konfirmasi poll
 * berikutnya (debounce anti-flap 2 poll). Dibaca oleh {@see App\Services\AlarmEvaluator}.
 */
class AlarmSetting extends Model
{
    use Auditable;

    protected $fillable = [
        'confirm_before_notify',
    ];

    /**
     * Default untuk instance baru (firstOrNew) sebelum baris pertama disimpan —
     * agar debounce 2 poll aktif "out of the box" (perilaku lama, aman).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'confirm_before_notify' => true,
    ];

    protected function casts(): array
    {
        return [
            'confirm_before_notify' => 'boolean',
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
     * Apakah notifikasi harus menunggu konfirmasi poll ke-2 (debounce anti-flap) sebelum dikirim.
     * Defensif: bila tabel belum ada (fresh checkout sebelum migrasi) kembalikan default aman (true).
     */
    public static function confirmBeforeNotify(): bool
    {
        try {
            return (bool) static::instance()->confirm_before_notify;
        } catch (\Throwable) {
            return true;
        }
    }
}
