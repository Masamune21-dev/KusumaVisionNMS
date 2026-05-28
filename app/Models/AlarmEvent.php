<?php

namespace App\Models;

use App\Models\Scopes\DemoScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlarmEvent extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_CLEARED = 'cleared';

    public const SEVERITY_CRITICAL = 'critical';

    public const SEVERITY_MAJOR = 'major';

    public const SEVERITY_MINOR = 'minor';

    public const SEVERITY_WARNING = 'warning';

    protected $fillable = [
        'snmp_olt_id',
        'signature',
        'type',
        'severity',
        'status',
        'scope',
        'slot',
        'port',
        'onu_id',
        'serial_number',
        'message',
        'meta',
        'first_seen_at',
        'last_seen_at',
        'cleared_at',
        'is_demo',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new DemoScope);
    }

    protected function casts(): array
    {
        return [
            'slot' => 'integer',
            'port' => 'integer',
            'onu_id' => 'integer',
            'meta' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'cleared_at' => 'datetime',
            'is_demo' => 'boolean',
        ];
    }

    public function olt(): BelongsTo
    {
        return $this->belongsTo(SnmpOlt::class, 'snmp_olt_id');
    }
}
