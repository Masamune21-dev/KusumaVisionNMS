<?php

namespace App\Console\Commands;

use App\Jobs\PollOltJob;
use App\Models\SnmpOlt;
use Illuminate\Console\Command;

class PollOltsCommand extends Command
{
    protected $signature = 'olts:poll';

    protected $description = 'Dispatch a background SNMP poll job for every polling-enabled OLT';

    public function handle(): int
    {
        $count = 0;

        SnmpOlt::query()
            ->where('polling_enabled', true)
            ->orderBy('id')
            ->each(function (SnmpOlt $olt) use (&$count) {
                PollOltJob::dispatch($olt->id);
                $count++;
            });

        $this->info("Dispatched {$count} OLT poll job(s).");

        return self::SUCCESS;
    }
}
