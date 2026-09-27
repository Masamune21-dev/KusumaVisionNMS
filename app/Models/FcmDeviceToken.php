<?php

namespace App\Models;

use App\Services\Fcm\FcmAlarmNotifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\Sanctum;

/**
 * Token perangkat FCM (aplikasi Android) milik seorang user. Dipakai
 * {@see FcmAlarmNotifier} untuk mengirim push saat alarm naik/turun.
 *
 * Terkait ke sesi login aplikasi (token Sanctum) yang mendaftarkannya: sesi
 * dihapus (logout/prune) → baris ini ikut terhapus (FK cascade), dan
 * {@see scopeDeliverable()} menyaring sesi yang sudah kedaluwarsa.
 */
class FcmDeviceToken extends Model
{
    protected $fillable = [
        'user_id',
        'personal_access_token_id',
        'token',
        'device_name',
        'platform',
        'last_seen_at',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function accessToken(): BelongsTo
    {
        return $this->belongsTo(Sanctum::personalAccessTokenModel(), 'personal_access_token_id');
    }

    /**
     * Hanya perangkat yang sesi login aplikasinya masih sah. Baris lama tanpa
     * kaitan (terdaftar sebelum 26 Sep 2026) tetap dikirimi sampai aplikasi
     * mendaftarkan ulang tokennya — terjadi tiap aplikasi dibuka dalam keadaan login.
     *
     * @param  Builder<FcmDeviceToken>  $query
     * @return Builder<FcmDeviceToken>
     */
    public function scopeDeliverable(Builder $query): Builder
    {
        $expiration = config('sanctum.expiration');

        return $query->where(fn (Builder $q) => $q
            ->whereNull('personal_access_token_id')
            ->orWhereHas('accessToken', function (Builder $t) use ($expiration) {
                $t->where(fn (Builder $e) => $e->whereNull('expires_at')->orWhere('expires_at', '>', now()));
                if ($expiration) {
                    $t->where('created_at', '>', now()->subMinutes((int) $expiration));
                }
            }));
    }
}
