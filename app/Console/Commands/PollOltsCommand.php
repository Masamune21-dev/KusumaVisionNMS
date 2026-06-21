<?php

namespace App\Console\Commands;

use App\Jobs\PollOltJob;
use App\Models\SnmpOlt;
use App\Support\SmartOltSupport;
use Illuminate\Console\Command;

class PollOltsCommand extends Command
{
    protected $signature = 'olts:poll';

    protected $description = 'Dispatch background SNMP poll jobs for polling-enabled OLTs that are due';

    public function handle(): int
    {
        $dispatched = 0;
        $skipped = 0;

        SnmpOlt::query()
            ->where('polling_enabled', true)
            ->orderBy('id')
            ->each(function (SnmpOlt $olt) use (&$dispatched, &$skipped) {
                // OLT C-Data tidak ikut polling background — di-refresh saat halaman dibuka (CDataOltController).
                if (SmartOltSupport::isCData(SmartOltSupport::driverKey($olt))) {
                    $skipped++;

                    return;
                }

                if (! $olt->isPollDue()) {
                    $skipped++;

                    return;
                }

                PollOltJob::dispatch($olt->id);
                $dispatched++;
            });

        $this->info("Dispatched {$dispatched} OLT poll job(s). Skipped {$skipped} not-due OLT(s).");

        return self::SUCCESS;
    }
}
