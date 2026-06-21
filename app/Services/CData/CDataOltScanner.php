<?php

namespace App\Services\CData;

use App\Http\Controllers\CDataOltController;
use App\Models\SnmpOlt;
use App\Services\SmartOltSnmpServiceResolver;

/**
 * Scan penuh OLT C-Data (system + ports + seluruh ONU) lalu tulis cache
 * `last_test_result.port_onus` dalam bentuk yang sama dengan ZTE supaya konsisten di halaman
 * Detail/PortOnus, ONU Monitoring, & global search.
 *
 * Sinkron: EPON via SNMP cepat, GPON V3 via CLI ~10 detik. Dipakai bersama oleh
 * {@see CDataOltController} (refresh manual, auto-refresh halaman,
 * scan saat OLT dibuat) dan bot Telegram (perintah /refresh).
 */
class CDataOltScanner
{
    public function __construct(private readonly SmartOltSnmpServiceResolver $resolver) {}

    /**
     * @return int jumlah ONU yang ditemukan
     */
    public function scan(SnmpOlt $olt): int
    {
        $driver = $this->resolver->resolve($olt);
        $system = $driver->getSystemInfo($olt);
        $ports = $driver->getPorts($olt);
        $onus = $driver->getRegisteredOnus($olt);

        $now = now()->toIso8601String();
        $byPort = [];
        foreach ($onus as $onu) {
            $byPort["{$onu['slot']}_{$onu['port']}"][] = $onu;
        }
        $portRows = [];
        foreach ($ports as $portRow) {
            $portRows["{$portRow['slot']}_{$portRow['port']}"] = $portRow;
        }

        $snapshot = $olt->last_test_result ?? [];
        data_set($snapshot, 'system', $system);
        data_set($snapshot, 'ports', $ports);
        data_set($snapshot, 'cdata.firmware_v3', (bool) data_get($system, 'firmware_v3', data_get($snapshot, 'cdata.firmware_v3', false)));
        data_set($snapshot, 'onu_scanned_at', $now);
        $snapshot['port_onus'] = [];

        foreach (array_keys($byPort + $portRows) as $key) {
            $portOnus = $byPort[$key] ?? [];
            $portRow = $portRows[$key] ?? null;
            data_set($snapshot, "port_onus.{$key}", [
                'ok' => true,
                'slot' => (int) ($portOnus[0]['slot'] ?? $portRow['slot'] ?? 0),
                'port' => (int) ($portOnus[0]['port'] ?? $portRow['port'] ?? 0),
                'port_row' => $portRow,
                'onus' => $portOnus,
                'count' => count($portOnus),
                'error' => null,
                'refreshed_at' => $now,
            ]);
        }

        $olt->forceFill(['last_test_result' => $snapshot, 'last_polled_at' => now()])->save();

        return count($onus);
    }
}
