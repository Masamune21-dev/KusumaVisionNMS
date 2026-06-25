<?php

namespace App\Jobs;

use App\Models\Tr069BulkTask;
use App\Services\ZteTr069BulkService;
use App\Support\CliOutputSanitizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs a per-OLT "aktifkan TR069 massal" batch in the background, updating the
 * {@see Tr069BulkTask} row as it goes so the UI can render live progress. Telnet
 * writes are not idempotent at the session level — never retry ($tries = 1).
 */
class Tr069BulkConfigJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public bool $failOnTimeout = true;

    public function __construct(public int $taskId) {}

    public function handle(ZteTr069BulkService $service): void
    {
        $task = Tr069BulkTask::find($this->taskId);

        if (! $task || $task->status !== 'queued') {
            return;
        }

        $olt = $task->olt;
        if (! $olt) {
            $task->update(['status' => 'failed', 'error' => 'OLT tidak ditemukan.', 'finished_at' => now()]);

            return;
        }

        $task->update(['status' => 'running', 'started_at' => now()]);

        try {
            $summary = $service->run(
                $olt,
                $task->execute,
                $task->created_by,
                function (array $progress) use ($task): void {
                    $task->update([
                        'processed' => $progress['processed'],
                        'applied_count' => $progress['applied'],
                        'skipped_count' => $progress['skipped'],
                        'failed_count' => $progress['failed'],
                    ]);
                },
            );

            $task->update([
                'status' => 'completed',
                'processed' => $summary['total'],
                'applied_count' => $summary['applied'],
                'skipped_count' => $summary['skipped'],
                'failed_count' => $summary['failed'],
                'items' => $summary['items'],
                'finished_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $task->update([
                'status' => 'failed',
                'error' => CliOutputSanitizer::clean($exception->getMessage()),
                'finished_at' => now(),
            ]);

            throw $exception;
        }
    }
}
