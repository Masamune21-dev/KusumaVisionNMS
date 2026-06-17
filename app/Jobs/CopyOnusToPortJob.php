<?php

namespace App\Jobs;

use App\Models\CopyOnuTask;
use App\Services\ZteOnuCopyService;
use App\Support\CliOutputSanitizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs a batch "copy ONU config to another port" in the background, updating the
 * {@see CopyOnuTask} row as it goes so the UI can render live progress. Telnet
 * writes are not idempotent — never retry ($tries = 1).
 */
class CopyOnusToPortJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public bool $failOnTimeout = true;

    public function __construct(public int $taskId) {}

    public function handle(ZteOnuCopyService $service): void
    {
        $task = CopyOnuTask::find($this->taskId);

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
            $summary = $service->copy(
                $olt,
                $task->src_slot,
                $task->src_port,
                $task->onu_ids,
                $task->dst_slot,
                $task->dst_port,
                $task->execute,
                $task->created_by,
                function (array $progress) use ($task): void {
                    // Counters only per ONU (cheap); the per-ONU log is written once at the end.
                    $task->update([
                        'processed' => $progress['processed'],
                        'created_count' => $progress['created'],
                        'executed_count' => $progress['executed'],
                        'failed_count' => $progress['failed'],
                    ]);
                },
            );

            $task->update([
                'status' => 'completed',
                'processed' => count($summary['items']),
                'created_count' => $summary['created'],
                'executed_count' => $summary['executed'],
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
