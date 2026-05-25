<?php

namespace App\Jobs;

use App\Models\SnmpOlt;
use App\Services\AlarmEvaluator;
use App\Services\Snmp\OltSnmpClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class PollOltJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $oltId) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->oltId))->dontRelease()];
    }

    public function handle(OltSnmpClient $client, AlarmEvaluator $alarms): void
    {
        $olt = SnmpOlt::find($this->oltId);

        if (! $olt || ! $olt->polling_enabled) {
            return;
        }

        $snapshot = $client->snapshot($olt);
        $merged = array_merge($olt->last_test_result ?? [], [
            'ok' => $snapshot['ok'],
            'driver' => $snapshot['driver'],
            'latency_ms' => $snapshot['latency_ms'],
            'system' => $snapshot['system'],
            'ports' => $snapshot['ports'],
            'error' => $snapshot['error'],
            'polled_at' => now()->toIso8601String(),
        ]);

        if ($snapshot['ok']) {
            try {
                $onus = $client->registeredOnus($olt, $snapshot['ports']);
                $merged = $this->bucketOnusIntoPorts($merged, $snapshot['ports'], $onus);
                $merged['onu_poll_error'] = null;
            } catch (Throwable $exception) {
                $merged['onu_poll_error'] = $exception->getMessage();
            }
        }

        $olt->forceFill([
            'last_test_result' => $merged,
            'last_tested_at' => now(),
            'last_polled_at' => now(),
        ])->save();

        $alarms->evaluate($olt);
    }

    /**
     * @param  array<string, mixed>  $merged
     * @param  array<int, array<string, mixed>>  $ports
     * @param  array<int, array<string, mixed>>  $onus
     * @return array<string, mixed>
     */
    private function bucketOnusIntoPorts(array $merged, array $ports, array $onus): array
    {
        $byKey = [];

        foreach ($onus as $onu) {
            $byKey["{$onu['slot']}_{$onu['port']}"][] = $onu;
        }

        foreach ($ports as $port) {
            $key = "{$port['slot']}_{$port['port']}";
            $rowsForPort = $byKey[$key] ?? [];
            $rxById = $this->existingRxByOnuId(data_get($merged, "port_onus.{$key}.onus", []));

            $rows = array_map(fn (array $onu) => [
                ...$onu,
                'rx_power_dbm' => $rxById[(int) $onu['onu_id']]['rx_power_dbm'] ?? null,
                'rx_power_label' => $rxById[(int) $onu['onu_id']]['rx_power_label'] ?? null,
            ], $rowsForPort);

            data_set($merged, "port_onus.{$key}", [
                'ok' => true,
                'slot' => (int) $port['slot'],
                'port' => (int) $port['port'],
                'if_index' => $rows[0]['if_index'] ?? $port['if_index'],
                'port_row' => $port,
                'onus' => $rows,
                'count' => count($rows),
                'latency_ms' => null,
                'rx_power' => data_get($merged, "port_onus.{$key}.rx_power", []),
                'error' => null,
                'refreshed_at' => now()->toIso8601String(),
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
                    'rx_power_dbm' => $onu['rx_power_dbm'],
                    'rx_power_label' => $onu['rx_power_label'] ?? null,
                ];
            }
        }

        return $map;
    }
}
