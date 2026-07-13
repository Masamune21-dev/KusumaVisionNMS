<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu versi snapshot running-config sebuah OLT (via CLI `show running-config`).
 * Isi config disimpan terenkripsi karena memuat kredensial. Versi identik beruntun
 * di-dedup lewat {@see self::$attributes} sha256 (lihat App\Services\Zte\OltConfigBackupService).
 */
class OltConfigBackup extends Model
{
    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_SCHEDULED = 'scheduled';

    public const STATUS_OK = 'ok';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'snmp_olt_id',
        'content',
        'size_bytes',
        'sha256',
        'trigger',
        'status',
        'error',
        'created_by',
        'captured_at',
    ];

    protected $hidden = [
        'content',
    ];

    protected function casts(): array
    {
        return [
            'snmp_olt_id' => 'integer',
            'created_by' => 'integer',
            'size_bytes' => 'integer',
            'content' => 'encrypted',
            'captured_at' => 'datetime',
        ];
    }

    public function olt(): BelongsTo
    {
        return $this->belongsTo(SnmpOlt::class, 'snmp_olt_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
