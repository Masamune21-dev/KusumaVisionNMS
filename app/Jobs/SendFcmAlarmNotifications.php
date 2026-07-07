<?php

namespace App\Jobs;

use App\Models\AlarmEvent;
use App\Models\SnmpOlt;
use App\Services\AlarmEvaluator;
use App\Services\Fcm\FcmAlarmNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Kirim push FCM untuk alarm yang baru naik/turun, di luar request polling
 * (di-dispatch dari {@see AlarmEvaluator}). Berjalan di worker Redis
 * yang sudah ada. Aman: bila FCM belum dikonfigurasi, job jadi no-op.
 */
class SendFcmAlarmNotifications implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    /**
     * @param  array<int, int>  $raisedIds
     * @param  array<int, int>  $clearedIds
     */
    public function __construct(
        public int $oltId,
        public array $raisedIds,
        public array $clearedIds,
    ) {}

    public function handle(FcmAlarmNotifier $notifier): void
    {
        if (! $notifier->enabled()) {
            return;
        }

        $olt = SnmpOlt::find($this->oltId);
        if ($olt === null) {
            return;
        }

        $raised = $this->raisedIds !== []
            ? AlarmEvent::query()->whereIn('id', $this->raisedIds)->get()
            : collect();
        $cleared = $this->clearedIds !== []
            ? AlarmEvent::query()->whereIn('id', $this->clearedIds)->get()
            : collect();

        $notifier->notify($olt, $raised, $cleared);
    }
}
