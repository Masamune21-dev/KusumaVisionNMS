<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

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
     * Sumbernya dipilih di sini, bukan di pemanggil: sampel mentah hanya
     * disimpan beberapa hari (lihat `services.snmp_poller.rx_sample_retention_days`),
     * jadi permintaan yang menjangkau lebih jauh dilayani ringkasan per jam.
     * Dengan begitu grafik 30 hari tetap utuh tanpa satu pun pemanggil perlu
     * tahu tabel mana yang sedang dibaca — bentuk keluarannya identik.
     *
     * @return Collection<int, array{polled_at:string, rx_power_dbm:float}>
     */
    public static function seriesFor(int $oltId, int $slot, int $port, int $onuId, Carbon $since): Collection
    {
        if (static::needsHourly($since)) {
            return OnuRxHourly::seriesFor($oltId, $slot, $port, $onuId, $since);
        }

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

    /**
     * Benar bila $since menjangkau lebih jauh dari umur sampel mentah.
     *
     * Diberi margin satu hari supaya permintaan yang persis menyentuh tepi
     * retensi tidak menampilkan grafik yang ujungnya terpotong prune.
     */
    protected static function needsHourly(Carbon $since): bool
    {
        if (! Schema::hasTable('onu_rx_hourly')) {
            return false;
        }

        $rawDays = (int) config('services.snmp_poller.rx_sample_retention_days', 3);

        return $since < now()->subDays(max(1, $rawDays - 1));
    }
}
