<?php

namespace App\Models;

use App\Models\Scopes\DemoScope;
use App\Models\Scopes\PartnerOltScope;
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

    public const TYPE_OLT_UNREACHABLE = 'olt_unreachable';

    public const TYPE_PORT_DOWN = 'port_down';

    public const TYPE_LOS = 'los';

    public const TYPE_DYING_GASP = 'dying_gasp';

    public const TYPE_ONU_OFFLINE = 'onu_offline';

    public const TYPE_HIGH_RX = 'high_rx_attenuation';

    /**
     * Every alarm type the evaluator can raise, with a human label. Single source
     * of truth for the Telegram per-type notification filter (Settings → Telegram).
     */
    public const TYPE_LABELS = [
        self::TYPE_OLT_UNREACHABLE => 'OLT tidak terhubung',
        self::TYPE_PORT_DOWN => 'Port GPON down',
        self::TYPE_LOS => 'Loss of Signal (LOS)',
        self::TYPE_DYING_GASP => 'Dying Gasp',
        self::TYPE_ONU_OFFLINE => 'ONU offline',
        self::TYPE_HIGH_RX => 'Redaman RX tinggi / di luar rentang',
    ];

    /**
     * @return array<int, string>
     */
    public static function types(): array
    {
        return array_keys(self::TYPE_LABELS);
    }

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
        static::addGlobalScope(new PartnerOltScope);
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
