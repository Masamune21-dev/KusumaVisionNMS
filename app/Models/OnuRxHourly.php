<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Ringkasan RX per jam per ONU — sumber grafik tren untuk rentang panjang.
 *
 * Lihat migrasi `create_onu_rx_hourly_table` untuk alasan tabel ini ada.
 */
class OnuRxHourly extends Model
{
    protected $table = 'onu_rx_hourly';

    public $timestamps = false;

    protected $fillable = [
        'snmp_olt_id',
        'slot',
        'port',
        'onu_id',
        'serial_number',
        'bucket_hour',
        'rx_min_dbm',
        'rx_avg_dbm',
        'rx_max_dbm',
        'sample_count',
    ];

    protected function casts(): array
    {
        return [
            'slot' => 'integer',
            'port' => 'integer',
            'onu_id' => 'integer',
            'bucket_hour' => 'datetime',
            'rx_min_dbm' => 'float',
            'rx_avg_dbm' => 'float',
            'rx_max_dbm' => 'float',
            'sample_count' => 'integer',
        ];
    }

    public function olt(): BelongsTo
    {
        return $this->belongsTo(SnmpOlt::class, 'snmp_olt_id');
    }

    /**
     * Riwayat RX satu ONU sejak $since, satu titik per jam, urut menaik.
     *
     * Bentuk keluarannya sengaja sama persis dengan {@see OnuRxSample::seriesFor()}
     * (`polled_at` + `rx_power_dbm`) supaya pemanggil dan frontend tidak perlu
     * tahu dari tabel mana datanya; `rx_avg_dbm` yang dipetakan ke `rx_power_dbm`,
     * sedangkan min/max ikut dikirim untuk pemanggil yang mau menggambar pita.
     *
     * @return Collection<int, array{polled_at:string, rx_power_dbm:float, rx_min_dbm:float, rx_max_dbm:float}>
     */
    public static function seriesFor(int $oltId, int $slot, int $port, int $onuId, Carbon $since): Collection
    {
        return static::query()
            ->where('snmp_olt_id', $oltId)
            ->where('slot', $slot)
            ->where('port', $port)
            ->where('onu_id', $onuId)
            ->where('bucket_hour', '>=', $since)
            ->orderBy('bucket_hour')
            ->get(['bucket_hour', 'rx_min_dbm', 'rx_avg_dbm', 'rx_max_dbm'])
            ->map(fn (self $row) => [
                'polled_at' => $row->bucket_hour->toIso8601String(),
                'rx_power_dbm' => (float) $row->rx_avg_dbm,
                'rx_min_dbm' => (float) $row->rx_min_dbm,
                'rx_max_dbm' => (float) $row->rx_max_dbm,
            ]);
    }
}
