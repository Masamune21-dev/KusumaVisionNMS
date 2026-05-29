<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Catatan jejak audit (audit trail) untuk aksi penting di sistem:
 * perubahan data (create/update/delete) dan event keamanan (login/logout).
 * Baris audit bersifat immutable — hanya punya created_at.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    public const EVENT_CREATED = 'created';

    public const EVENT_UPDATED = 'updated';

    public const EVENT_DELETED = 'deleted';

    public const EVENT_LOGIN = 'login';

    public const EVENT_LOGOUT = 'logout';

    public const EVENT_LOGIN_FAILED = 'login_failed';

    public const EVENT_TELNET_OPENED = 'telnet_opened';

    protected $fillable = [
        'user_id',
        'user_name',
        'event',
        'auditable_type',
        'auditable_id',
        'description',
        'properties',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
