<?php

namespace App\Console\Commands;

use App\Jobs\BackupOltConfigJob;
use App\Models\SnmpOlt;
use App\Support\SmartOltSupport;
use Illuminate\Console\Command;

class BackupOltConfigsCommand extends Command
{
    protected $signature = 'olts:backup-config';

    protected $description = 'Dispatch running-config backup jobs for backup-enabled ZTE OLTs';

    public function handle(): int
    {
        $dispatched = 0;

        SnmpOlt::query()
            ->where('config_backup_enabled', true)
            ->orderBy('id')
            ->each(function (SnmpOlt $olt) use (&$dispatched) {
                // Backup config (CLI `show running-config`) saat ini hanya untuk family ZTE.
                if (SmartOltSupport::driverKey($olt) !== SmartOltSupport::DRIVER_ZTE) {
                    return;
                }

                BackupOltConfigJob::dispatch($olt->id);
                $dispatched++;
            });

        $this->info("Dispatched {$dispatched} OLT config-backup job(s).");

        return self::SUCCESS;
    }
}
