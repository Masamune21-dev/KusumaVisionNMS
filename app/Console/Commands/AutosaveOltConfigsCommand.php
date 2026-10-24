<?php

namespace App\Console\Commands;

use App\Models\SnmpOlt;
use App\Services\Olt\OltConfigAutosaveService;
use Illuminate\Console\Command;

/**
 * Auto save config OLT ber-saklar nyala ({@see OltConfigAutosaveService}). Sengaja sinkron, satu
 * OLT bergantian, di proses latar scheduler — BUKAN antrean: `write` ZTE bisa 30–120 detik per OLT,
 * sedangkan worker antrean juga melayani poll SNMP dan push alarm.
 */
class AutosaveOltConfigsCommand extends Command
{
    protected $signature = 'olts:autosave-config
        {--all : Simpan semua OLT ber-saklar auto save walau tak ada perubahan tercatat (jalur harian)}';

    protected $description = 'Auto save running-config ke memori OLT untuk OLT yang saklar auto save-nya nyala';

    public function handle(OltConfigAutosaveService $autosave): int
    {
        $force = (bool) $this->option('all');
        $counts = [SnmpOlt::AUTOSAVE_OK => 0, SnmpOlt::AUTOSAVE_FAILED => 0, SnmpOlt::AUTOSAVE_DEFERRED => 0];

        SnmpOlt::query()
            ->where('config_autosave_enabled', true)
            ->when(! $force, fn ($query) => $query->whereNotNull('config_dirty_since'))
            ->orderBy('id')
            ->each(function (SnmpOlt $olt) use ($autosave, $force, &$counts) {
                $status = $autosave->run($olt, $force);

                if ($status !== null) {
                    $counts[$status]++;
                    $this->line("{$olt->name}: {$status}");
                }
            });

        $this->info(sprintf(
            'Auto save: %d tersimpan, %d gagal, %d ditunda.',
            $counts[SnmpOlt::AUTOSAVE_OK],
            $counts[SnmpOlt::AUTOSAVE_FAILED],
            $counts[SnmpOlt::AUTOSAVE_DEFERRED],
        ));

        return self::SUCCESS;
    }
}
