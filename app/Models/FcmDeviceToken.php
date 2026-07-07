<?php

namespace App\Models;

use App\Services\Fcm\FcmAlarmNotifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Token perangkat FCM (aplikasi Android) milik seorang user. Dipakai
 * {@see FcmAlarmNotifier} untuk mengirim push saat alarm naik/turun.
 */
class FcmDeviceToken extends Model
{
    protected $fillable = [
        'user_id',
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
}
