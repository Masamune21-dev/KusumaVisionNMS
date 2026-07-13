<?php

namespace App\Jobs;

use App\Models\OltConfigBackup;
use App\Models\SnmpOlt;
use App\Services\Zte\OltConfigBackupService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Backup running-config satu OLT (jalur terjadwal harian). Dispatch oleh
 * {@see App\Console\Commands\BackupOltConfigsCommand}. Backup manual dari UI
 * berjalan sinkron di controller agar hasilnya langsung terlihat.
 */
class BackupOltConfigJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    public bool $failOnTimeout = true;

    public function __construct(public int $oltId) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('cfgbackup:'.$this->oltId))->expireAfter($this->timeout + 120)->dontRelease()];
    }

    public function handle(OltConfigBackupService $service): void
    {
        $olt = SnmpOlt::find($this->oltId);

        if (! $olt || ! $olt->config_backup_enabled) {
            return;
        }

        $service->capture($olt, OltConfigBackup::TRIGGER_SCHEDULED);
    }
}
