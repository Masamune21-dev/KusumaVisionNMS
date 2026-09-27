<?php

namespace App\Console\Commands;

use App\Models\OnuRxHourly;
use App\Models\OnuRxSample;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Padatkan `onu_rx_samples` menjadi satu baris per ONU per jam.
 *
 * Dijalankan tiap jam sebelum prune: sampel mentah baru boleh dibuang setelah
 * jamnya terangkum di sini. Urutan itu penting — kalau prune duluan, riwayat
 * panjangnya hilang permanen.
 */
class AggregateOnuRxCommand extends Command
{
    protected $signature = 'optical:aggregate-rx
        {--hours= : Berapa jam ke belakang yang diproses (default: sejak jam terakhir yang sudah terangkum)}
        {--max-hours= : Batas atas jam per eksekusi (default 48; saat --hours diberikan, defaultnya mengikuti --hours)}';

    protected $description = 'Ringkas onu_rx_samples menjadi rata-rata/min/max per jam di onu_rx_hourly';

    public function handle(): int
    {
        // Jam berjalan sengaja dilewati: datanya belum lengkap, dan merangkumnya
        // sekarang berarti menyimpan angka yang salah sampai ditimpa jam depan.
        $end = now()->startOfHour();
        $start = $this->resolveStart($end);

        if ($start === null) {
            $this->info('Belum ada sampel RX untuk diringkas.');

            return self::SUCCESS;
        }

        if ($start >= $end) {
            $this->info('Tidak ada jam baru untuk diringkas.');

            return self::SUCCESS;
        }

        // Kalau pemanggil menyebut --hours secara eksplisit, dia memang minta
        // rentang itu diproses; batas 48 jam hanya untuk jalur terjadwal supaya
        // backfill panjang tidak menahan scheduler.
        $maxHours = $this->option('max-hours') !== null
            ? max(1, (int) $this->option('max-hours'))
            : ($this->option('hours') !== null ? max(1, (int) $this->option('hours')) : 48);
        $hours = 0;
        $rows = 0;

        for ($hour = $start->copy(); $hour < $end && $hours < $maxHours; $hour->addHour(), $hours++) {
            $rows += $this->aggregateHour($hour);
        }

        $this->info("Meringkas {$hours} jam → {$rows} baris di onu_rx_hourly (mulai {$start->toDateTimeString()}).");

        return self::SUCCESS;
    }

    /**
     * Jam pertama yang perlu diproses.
     */
    private function resolveStart(Carbon $end): ?Carbon
    {
        if ($this->option('hours') !== null) {
            return $end->copy()->subHours(max(1, (int) $this->option('hours')));
        }

        // Jam terakhir yang sudah terangkum diproses ULANG, bukan dilewati:
        // saat jam itu dirangkum, sebagian sampelnya bisa jadi belum masuk.
        $last = OnuRxHourly::query()->max('bucket_hour');

        if ($last !== null) {
            return Carbon::parse($last)->startOfHour();
        }

        $oldest = OnuRxSample::query()->min('polled_at');

        return $oldest === null ? null : Carbon::parse($oldest)->startOfHour();
    }

    /**
     * Rangkum satu jam. Mengembalikan jumlah baris yang ditulis.
     */
    private function aggregateHour(Carbon $hour): int
    {
        $next = $hour->copy()->addHour();
        $written = 0;

        OnuRxSample::query()
            ->selectRaw('snmp_olt_id, slot, port, onu_id, max(serial_number) as serial_number,'
                .' min(rx_power_dbm) as rx_min, avg(rx_power_dbm) as rx_avg,'
                .' max(rx_power_dbm) as rx_max, count(*) as sample_count')
            ->where('polled_at', '>=', $hour)
            ->where('polled_at', '<', $next)
            ->groupBy('snmp_olt_id', 'slot', 'port', 'onu_id')
            ->orderBy('snmp_olt_id')
            ->orderBy('slot')
            ->orderBy('port')
            ->orderBy('onu_id')
            // chunk biasa memakai offset dan melambat di jutaan baris; di sini
            // hasil group-by-nya kecil (satu baris per ONU), tapi cursor tetap
            // lebih hemat memori daripada get() sekaligus.
            ->cursor()
            ->chunk(500)
            ->each(function ($group) use ($hour, &$written) {
                $payload = $group->map(fn ($row) => [
                    'snmp_olt_id' => (int) $row->snmp_olt_id,
                    'slot' => (int) $row->slot,
                    'port' => (int) $row->port,
                    'onu_id' => (int) $row->onu_id,
                    'serial_number' => $row->serial_number,
                    'bucket_hour' => $hour->toDateTimeString(),
                    'rx_min_dbm' => round((float) $row->rx_min, 2),
                    'rx_avg_dbm' => round((float) $row->rx_avg, 2),
                    'rx_max_dbm' => round((float) $row->rx_max, 2),
                    'sample_count' => (int) $row->sample_count,
                ])->all();

                DB::table('onu_rx_hourly')->upsert(
                    $payload,
                    ['snmp_olt_id', 'slot', 'port', 'onu_id', 'bucket_hour'],
                    ['serial_number', 'rx_min_dbm', 'rx_avg_dbm', 'rx_max_dbm', 'sample_count'],
                );

                $written += count($payload);
            });

        return $written;
    }
}
