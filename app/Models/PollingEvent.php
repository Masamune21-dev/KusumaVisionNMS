<?php

namespace App\Models;

use App\Models\Scopes\DemoScope;
use App\Models\Scopes\PartnerOltScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PollingEvent extends Model
{
    public const KIND_OLT_TEST = 'olt_test';

    public const KIND_OLT_POLL = 'olt_poll';

    public const KIND_RX_POLL = 'rx_poll';

    public const KIND_PROVISIONING = 'provisioning';

    protected $fillable = [
        'snmp_olt_id',
        'kind',
        'success',
        'message',
        'duration_ms',
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
            'success' => 'boolean',
            'duration_ms' => 'integer',
            'is_demo' => 'boolean',
        ];
    }

    public function olt(): BelongsTo
    {
        return $this->belongsTo(SnmpOlt::class, 'snmp_olt_id');
    }

    public static function log(?int $oltId, string $kind, bool $success, ?string $message = null, ?int $durationMs = null): void
    {
        static::create([
            'snmp_olt_id' => $oltId,
            'kind' => $kind,
            'success' => $success,
            'message' => $message ? mb_substr($message, 0, 240) : null,
            'duration_ms' => $durationMs,
        ]);
    }
}
