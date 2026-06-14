<?php

namespace App\Console\Commands;

use App\Models\OnuRxSample;
use Illuminate\Console\Command;

class PruneOnuRxSamplesCommand extends Command
{
    protected $signature = 'optical:prune-rx {--days= : Hapus sample RX lebih lama dari N hari (default dari config)}';

    protected $description = 'Prune riwayat RX power ONU (onu_rx_samples) yang melewati masa retensi';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('services.snmp_poller.rx_sample_retention_days', 90));

        if ($days < 1) {
            $this->error('Retensi minimal 1 hari.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $deleted = 0;
        $chunk = 5000;

        // Hapus bertahap (pilih id lalu hapus whereIn) agar portabel lintas driver
        // (sqlite/pgsql) dan tidak mengunci tabel saat volume besar.
        do {
            $ids = OnuRxSample::query()
                ->where('polled_at', '<', $cutoff)
                ->limit($chunk)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += OnuRxSample::whereIn('id', $ids)->delete();
        } while ($ids->count() === $chunk);

        $this->info("Pruned {$deleted} RX sample(s) older than {$days} day(s) (sebelum {$cutoff->toDateTimeString()}).");

        return self::SUCCESS;
    }
}
