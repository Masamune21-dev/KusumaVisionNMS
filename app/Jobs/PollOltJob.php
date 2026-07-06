<?php

namespace App\Jobs;

use App\Models\OnuRxSample;
use App\Models\PollingEvent;
use App\Models\SnmpOlt;
use App\Services\AlarmEvaluator;
use App\Services\CData\CDataOltScanner;
use App\Services\Snmp\GoSnmpPoller;
use App\Services\Snmp\OltSnmpClient;
use App\Support\SmartOltSupport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Throwable;

class PollOltJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public bool $failOnTimeout = true;

    public function __construct(public int $oltId) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->oltId))->expireAfter($this->timeout + 300)->dontRelease()];
    }

    public function handle(OltSnmpClient $client, AlarmEvaluator $alarms, ?GoSnmpPoller $goPoller = null, ?CDataOltScanner $cdataScanner = null): void
    {
        $olt = SnmpOlt::find($this->oltId);

        if (! $olt || ! $olt->polling_enabled || ! $olt->isPollDue()) {
            return;
        }

        // OLT non-ZTE (C-Data EPON/GPON, HiOSO) dipoll lewat scanner+resolver, bukan poller ZTE (Go/OltSnmpClient).
        if (SmartOltSupport::isNonZte(SmartOltSupport::driverKey($olt))) {
            $this->pollViaScanner($olt, $alarms, $cdataScanner ?? app(CDataOltScanner::class));

            return;
        }

        $previousSnapshot = $olt->last_test_result ?? [];
        $now = now();
        $rxPollDue = $olt->isRxPollDue();
        $rxPollSucceeded = false;
        $rxPowerError = null;
        $preserveExistingRx = ! $rxPollDue;
        $poller = 'php';
        $goPollerError = null;
        $snapshot = null;
        $onus = null;

        $goPoller ??= app(GoSnmpPoller::class);

        if ($goPoller->enabled()) {
            try {
                $poll = $goPoller->poll($olt, $rxPollDue);
                $ports = $poll['ports'] ?? [];
                $goOnus = $poll['onus'] ?? [];
                $rxPowerError = $rxPollDue ? data_get($poll, 'rx_power.error') : null;
                $rxPowerError = $rxPowerError === null ? null : (string) $rxPowerError;
                $rxPollSucceeded = $rxPollDue && data_get($poll, 'rx_power.ok') === true;
                $preserveExistingRx = ! $rxPollDue || ! $rxPollSucceeded;
                $poller = 'go';
                $snapshot = [
                    'ok' => (bool) ($poll['ok'] ?? false),
                    'driver' => (string) ($poll['driver'] ?? 'unknown'),
                    'latency_ms' => (int) ($poll['latency_ms'] ?? 0),
                    'system' => is_array($poll['system'] ?? null) ? $poll['system'] : [],
                    'ports' => is_array($ports) ? array_values($ports) : [],
                    'error' => $poll['error'] ?? null,
                ];
                $onus = is_array($goOnus) ? array_values($goOnus) : [];
            } catch (Throwable $exception) {
                $goPollerError = $exception->getMessage();
            }
        }

        $snapshot ??= $client->snapshot($olt);
        $merged = array_merge($olt->last_test_result ?? [], [
            'ok' => $snapshot['ok'],
            'driver' => $snapshot['driver'],
            'latency_ms' => $snapshot['latency_ms'],
            'system' => $snapshot['system'],
            'ports' => $snapshot['ports'],
            'error' => $snapshot['error'],
            'poller' => $poller,
            'go_poller_error' => $goPollerError,
            'polled_at' => $now->toIso8601String(),
        ]);

        if ($snapshot['ok']) {
            try {
                if ($onus === null) {
                    $onus = $client->registeredOnus($olt, $snapshot['ports']);

                    if ($rxPollDue) {
                        try {
                            $onus = $client->mergeOnuRxPowers($onus, $client->onuRxPowers($olt));
                            $rxPollSucceeded = true;
                        } catch (Throwable $exception) {
                            $rxPowerError = $exception->getMessage();
                            $preserveExistingRx = true;
                        }
                    }
                }

                $merged = $this->bucketOnusIntoPorts($merged, $snapshot['ports'], $onus, $rxPowerError, $preserveExistingRx, $rxPollSucceeded, $now);
                $merged['onu_poll_error'] = null;
            } catch (Throwable $exception) {
                $merged['onu_poll_error'] = $exception->getMessage();
            }
        }

        $updates = [
            'last_test_result' => $merged,
            'last_tested_at' => $now,
            'last_polled_at' => $now,
        ];

        if ($rxPollSucceeded) {
            $updates['last_rx_polled_at'] = $now;
        }

        $olt->forceFill($updates)->save();

        if ($rxPollSucceeded && is_array($onus)) {
            $this->recordRxSamples($olt->id, $onus, $now);
        }

        $alarms->evaluate($olt, $previousSnapshot);

        PollingEvent::log(
            $olt->id,
            PollingEvent::KIND_OLT_POLL,
            (bool) ($snapshot['ok'] ?? false),
            $snapshot['error'] ?? $merged['onu_poll_error'] ?? $goPollerError,
            isset($snapshot['latency_ms']) ? (int) $snapshot['latency_ms'] : null,
        );

        if ($rxPollDue) {
            PollingEvent::log(
                $olt->id,
                PollingEvent::KIND_RX_POLL,
                $rxPollSucceeded,
                $rxPowerError,
            );
        }
    }

    /**
     * Poll OLT non-ZTE (C-Data EPON/GPON & HiOSO): scan penuh lewat {@see CDataOltScanner} (driver
     * SNMP/CLI menulis `last_test_result.port_onus` dgn bentuk sama spt ZTE), lalu samakan
     * housekeeping ZTE — tandai `ok`/error utk AlarmEvaluator, catat sampel RX, evaluasi alarm,
     * & log PollingEvent.
     */
    private function pollViaScanner(SnmpOlt $olt, AlarmEvaluator $alarms, CDataOltScanner $scanner): void
    {
        $now = now();
        $previousSnapshot = $olt->last_test_result ?? [];
        $rxPollDue = $olt->isRxPollDue();
        $ok = false;
        $error = null;
        $onus = [];

        try {
            // Scanner me-resolve driver (EPON SNMP / GPON V3 SNMP+CLI), tulis system/ports/port_onus.
            $scanner->scan($olt);
            $onus = collect(data_get($olt->last_test_result, 'port_onus', []))
                ->flatMap(fn ($port) => is_array($port['onus'] ?? null) ? $port['onus'] : [])
                ->all();
            $ok = true;
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }

        // Scanner tak menulis penanda `ok` top-level yang dibutuhkan AlarmEvaluator → set di sini.
        $snapshot = $olt->last_test_result ?? [];
        $snapshot['ok'] = $ok;
        $snapshot['error'] = $error;
        $snapshot['poller'] = 'cdata';
        $snapshot['polled_at'] = $now->toIso8601String();

        $updates = [
            'last_test_result' => $snapshot,
            'last_tested_at' => $now,
            'last_polled_at' => $now,
        ];
        if ($ok && $rxPollDue) {
            $updates['last_rx_polled_at'] = $now;
        }

        $olt->forceFill($updates)->save();

        // Rx C-Data ikut tiap scan (EPON via SNMP, GPON via CLI) → catat time-series sesuai cadence RX.
        if ($ok && $rxPollDue) {
            $this->recordRxSamples($olt->id, $onus, $now);
        }

        $alarms->evaluate($olt, $previousSnapshot);

        PollingEvent::log($olt->id, PollingEvent::KIND_OLT_POLL, $ok, $error);
        if ($rxPollDue) {
            PollingEvent::log($olt->id, PollingEvent::KIND_RX_POLL, $ok, $error);
        }
    }

    public function failed(?Throwable $exception): void
    {
        PollingEvent::log(
            $this->oltId,
            PollingEvent::KIND_OLT_POLL,
            false,
            $exception?->getMessage(),
        );
    }

    /**
     * Simpan satu titik time-series RX power per ONU (hanya yang punya nilai numerik).
     * Dipanggil hanya saat RX poll sukses agar tidak menulis nilai yang di-preserve.
     *
     * @param  array<int, array<string, mixed>>  $onus
     */
    private function recordRxSamples(int $oltId, array $onus, Carbon $polledAt): void
    {
        $rows = [];

        foreach ($onus as $onu) {
            $rx = $onu['rx_power_dbm'] ?? null;

            if (! is_numeric($rx)) {
                continue;
            }

            $rows[] = [
                'snmp_olt_id' => $oltId,
                'slot' => (int) ($onu['slot'] ?? 0),
                'port' => (int) ($onu['port'] ?? 0),
                'onu_id' => (int) ($onu['onu_id'] ?? 0),
                'serial_number' => $onu['serial_number'] ?? null,
                'rx_power_dbm' => round((float) $rx, 2),
                'polled_at' => $polledAt,
            ];
        }

        if ($rows !== []) {
            OnuRxSample::insert($rows);
        }
    }

    /**
     * @param  array<string, mixed>  $merged
     * @param  array<int, array<string, mixed>>  $ports
     * @param  array<int, array<string, mixed>>  $onus
     * @return array<string, mixed>
     */
    private function bucketOnusIntoPorts(array $merged, array $ports, array $onus, ?string $rxPowerError, bool $preserveExistingRx, bool $rxPollSucceeded, Carbon $polledAt): array
    {
        $byKey = [];

        foreach ($onus as $onu) {
            $byKey["{$onu['slot']}_{$onu['port']}"][] = $onu;
        }

        foreach ($ports as $port) {
            $key = "{$port['slot']}_{$port['port']}";
            $rowsForPort = $byKey[$key] ?? [];
            $rxById = $preserveExistingRx
                ? $this->existingRxByOnuId(data_get($merged, "port_onus.{$key}.onus", []))
                : [];

            $rows = array_map(function (array $onu) use ($rxById) {
                $existing = $rxById[(int) $onu['onu_id']] ?? [];

                return [
                    ...$onu,
                    'rx_power_port' => $onu['rx_power_port'] ?? $existing['rx_power_port'] ?? null,
                    'raw_rx_power' => $onu['raw_rx_power'] ?? $existing['raw_rx_power'] ?? null,
                    'rx_power_dbm' => $onu['rx_power_dbm'] ?? $existing['rx_power_dbm'] ?? null,
                    'rx_power_label' => $onu['rx_power_label'] ?? $existing['rx_power_label'] ?? null,
                    'rx_power_source' => $onu['rx_power_source'] ?? $existing['rx_power_source'] ?? null,
                ];
            }, $rowsForPort);

            data_set($merged, "port_onus.{$key}", [
                'ok' => true,
                'slot' => (int) $port['slot'],
                'port' => (int) $port['port'],
                'if_index' => $rows[0]['if_index'] ?? $port['if_index'],
                'port_row' => $port,
                'onus' => $rows,
                'count' => count($rows),
                'latency_ms' => null,
                'rx_power' => $this->rxPowerMeta(
                    data_get($merged, "port_onus.{$key}.rx_power", []),
                    $rows,
                    $rxPowerError,
                    $rxPollSucceeded,
                    $polledAt,
                ),
                'error' => null,
                'refreshed_at' => $polledAt->toIso8601String(),
            ]);
        }

        return $merged;
    }

    /**
     * @param  array<int, array<string, mixed>>  $onus
     * @return array<int, array<string, mixed>>
     */
    private function existingRxByOnuId(array $onus): array
    {
        $map = [];

        foreach ($onus as $onu) {
            if (($onu['rx_power_dbm'] ?? null) !== null) {
                $map[(int) $onu['onu_id']] = [
                    'rx_power_port' => $onu['rx_power_port'] ?? null,
                    'raw_rx_power' => $onu['raw_rx_power'] ?? null,
                    'rx_power_dbm' => $onu['rx_power_dbm'],
                    'rx_power_label' => $onu['rx_power_label'] ?? null,
                    'rx_power_source' => $onu['rx_power_source'] ?? null,
                ];
            }
        }

        return $map;
    }

    /**
     * @param  array<int, array<string, mixed>>  $onus
     */
    private function countSnmpRxPowers(array $onus): int
    {
        return count(array_filter(
            $onus,
            fn (array $onu) => str_starts_with((string) ($onu['rx_power_source'] ?? ''), 'snmp')
                && ($onu['rx_power_dbm'] ?? null) !== null,
        ));
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function rxPowerMeta(array $existing, array $rows, ?string $rxPowerError, bool $rxPollSucceeded, Carbon $polledAt): array
    {
        if (! $rxPollSucceeded && $rxPowerError === null && $existing !== []) {
            return [
                ...$existing,
                'count' => $this->countSnmpRxPowers($rows),
            ];
        }

        return [
            'ok' => $rxPowerError === null,
            'source' => 'snmp',
            'count' => $this->countSnmpRxPowers($rows),
            'error' => $rxPowerError,
            'polled_at' => $rxPollSucceeded
                ? $polledAt->toIso8601String()
                : ($existing['polled_at'] ?? null),
        ];
    }
}
