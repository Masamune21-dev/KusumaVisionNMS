<?php

namespace App\Jobs;

use App\Models\SnmpOlt;
use App\Services\HsAirPo\HsAirPoCliService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Throwable;

/**
 * Ambil Rx SELURUH ONU online di satu PON HsAirPo (CLI `optical-info` per-ONU, ~2 dtk/ONU) di
 * background — dipakai tombol "Refresh Rx (semua)" karena mengambilnya di request web menembus 100 dtk
 * Cloudflare (mis. PON 53 ONU ≈ 122 dtk → 504).
 *
 * Rx & progres ditulis inkremental ke `last_test_result.hsairpo_rx[.status]` — **di luar** `port_onus`
 * supaya tak terhapus scan/poll (scanner hanya menulis ulang `port_onus`). Halaman port menggabungkannya
 * saat render & mem-poll status selama `running`.
 */
class RefreshHsAirPoPortRxJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Cukup lama untuk port terpadat (128 ONU × ~2,5 dtk). */
    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(
        public int $oltId,
        public int $slot,
        public int $port,
    ) {}

    public function handle(HsAirPoCliService $cli): void
    {
        $olt = SnmpOlt::find($this->oltId);
        if ($olt === null) {
            return;
        }

        $online = collect(data_get($olt->last_test_result, "port_onus.{$this->slot}_{$this->port}.onus", []))
            ->where('online', true)
            ->pluck('onu_id')
            ->map(fn ($v): int => (int) $v)
            ->values()
            ->all();

        $total = count($online);
        $done = 0;
        $this->persist(null, null, 'running', $total, 0);

        try {
            $cli->fetchPortRx($olt, $this->port, $online, function (int $onuId, ?array $entry) use (&$done, $total): void {
                $done++;
                $this->persist($onuId, $entry, 'running', $total, $done);
            });
            $this->persist(null, null, 'done', $total, $done);
        } catch (Throwable $e) {
            $this->persist(null, null, 'error', $total, $done, $e->getMessage());

            throw $e;
        }
    }

    /**
     * Satu load+save: set Rx satu ONU (bila ada) + status port. Muat ulang tiap kali supaya perubahan
     * scan/poll yang berjalan bersamaan tak saling menimpa (hsairpo_rx & port_onus terpisah).
     *
     * @param  array{dbm: float, label: string}|null  $entry
     */
    private function persist(?int $onuId, ?array $entry, string $status, int $total, int $done, ?string $error = null): void
    {
        $olt = SnmpOlt::find($this->oltId);
        if ($olt === null) {
            return;
        }

        $snapshot = $olt->last_test_result ?? [];

        if ($onuId !== null) {
            $path = "hsairpo_rx.{$this->slot}_{$this->port}.{$onuId}";
            if ($entry === null) {
                Arr::forget($snapshot, $path); // tak terbaca → buang Rx basi
            } else {
                data_set($snapshot, $path, ['dbm' => $entry['dbm'], 'label' => $entry['label'], 'at' => now()->toIso8601String()]);
            }
        }

        data_set($snapshot, "hsairpo_rx_status.{$this->slot}_{$this->port}", array_filter([
            'status' => $status,
            'total' => $total,
            'done' => $done,
            'error' => $error,
            'at' => now()->toIso8601String(),
        ], fn ($v) => $v !== null));

        $olt->forceFill(['last_test_result' => $snapshot])->save();
    }
}
