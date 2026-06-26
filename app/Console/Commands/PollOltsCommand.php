<?php

namespace App\Console\Commands;

use App\Jobs\PollOltJob;
use App\Models\SnmpOlt;
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
                // ZTE & C-Data sama-sama ikut polling background; PollOltJob memilih jalur per family.
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
