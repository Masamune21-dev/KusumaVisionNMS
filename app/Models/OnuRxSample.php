<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class OnuRxSample extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'snmp_olt_id',
        'slot',
        'port',
        'onu_id',
        'serial_number',
        'rx_power_dbm',
        'polled_at',
    ];

    protected function casts(): array
    {
        return [
            'slot' => 'integer',
            'port' => 'integer',
            'onu_id' => 'integer',
            'rx_power_dbm' => 'float',
            'polled_at' => 'datetime',
        ];
    }

    public function olt(): BelongsTo
    {
        return $this->belongsTo(SnmpOlt::class, 'snmp_olt_id');
    }

    /**
     * Riwayat RX power satu ONU sejak $since, urut waktu menaik (untuk grafik tren).
     *
     * @return Collection<int, array{polled_at:string, rx_power_dbm:float}>
     */
    public static function seriesFor(int $oltId, int $slot, int $port, int $onuId, Carbon $since): Collection
    {
        return static::query()
            ->where('snmp_olt_id', $oltId)
            ->where('slot', $slot)
            ->where('port', $port)
            ->where('onu_id', $onuId)
            ->where('polled_at', '>=', $since)
            ->orderBy('polled_at')
            ->get(['polled_at', 'rx_power_dbm'])
            ->map(fn (self $sample) => [
                'polled_at' => $sample->polled_at->toIso8601String(),
                'rx_power_dbm' => (float) $sample->rx_power_dbm,
            ]);
    }
}
