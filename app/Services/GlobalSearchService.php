<?php

namespace App\Services;

use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\DashboardSearchController;
use App\Models\SnmpOlt;
use App\Support\SmartOltSupport;

/**
 * Pencarian global lintas-OLT: cocokkan OLT (nama/IP) dan ONU (SN/nama/interface/MAC)
 * dari cache `snmp_olts.last_test_result.port_onus`. Sumber tunggal yang dipakai bersama
 * oleh pencarian web (⌘K, {@see DashboardSearchController}) dan REST
 * API mobile ({@see SearchController}).
 *
 * Mengembalikan item ter-struktur (tanpa URL) sehingga tiap pemanggil bebas membangun
 * navigasinya sendiri: web → `route()`, mobile → deep-link.
 */
class GlobalSearchService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function search(string $query, int $limit = 10): array
    {
        $query = trim($query);

        if (mb_strlen($query) < 2) {
            return [];
        }

        $results = $this->matchOlts($query);
        $results = array_merge($results, $this->matchOnus($query, $limit - count($results)));

        return array_slice($results, 0, $limit);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function matchOlts(string $query): array
    {
        $matches = [];

        $olts = SnmpOlt::query()
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                    ->orWhere('ip', 'like', "%{$query}%");
            })
            ->limit(5)
            ->get(['id', 'name', 'ip', 'vendor', 'last_test_result']);

        foreach ($olts as $olt) {
            $matches[] = [
                'type' => 'olt',
                'olt_id' => $olt->id,
                'olt_name' => $olt->name,
                'route_prefix' => SmartOltSupport::inventoryRoutePrefix($this->driver($olt)),
                'slot' => null,
                'port' => null,
                'onu_id' => null,
                'serial_number' => null,
                'search_value' => null,
                'label' => $olt->name,
                'sublabel' => $olt->ip,
            ];
        }

        return $matches;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function matchOnus(string $query, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $needle = strtolower($query);
        $matches = [];

        $olts = SnmpOlt::query()->get(['id', 'name', 'vendor', 'last_test_result']);
        foreach ($olts as $olt) {
            $routePrefix = SmartOltSupport::inventoryRoutePrefix($this->driver($olt));

            foreach (($olt->last_test_result['port_onus'] ?? []) as $port) {
                foreach (($port['onus'] ?? []) as $onu) {
                    $serialValue = (string) ($onu['serial_number'] ?? $onu['sn'] ?? $onu['serial'] ?? '');
                    $serial = strtolower($serialValue);
                    $name = strtolower((string) ($onu['name'] ?? ''));
                    $interface = strtolower((string) ($onu['interface'] ?? ''));
                    // ONU EPON tak punya serial terpisah (identitas = MAC) → ikut cocokkan MAC.
                    $mac = strtolower((string) ($onu['mac'] ?? ''));

                    if ($serial === '' && $name === '' && $interface === '' && $mac === '') {
                        continue;
                    }
                    if (! str_contains($serial, $needle) && ! str_contains($name, $needle)
                        && ! str_contains($interface, $needle) && ! str_contains($mac, $needle)) {
                        continue;
                    }

                    $slot = $port['slot'] ?? null;
                    $portNo = $port['port'] ?? null;
                    $hasPort = $slot !== null && $portNo !== null;
                    $label = $serialValue !== '' ? $serialValue : ($onu['name'] ?? $onu['mac'] ?? $onu['interface'] ?? 'ONU');

                    $sublabelParts = [$olt->name];
                    if ($hasPort) {
                        $sublabelParts[] = "{$slot}/{$portNo}";
                    }
                    if (($onu['name'] ?? '') !== '') {
                        $sublabelParts[] = $onu['name'];
                    }

                    $matches[] = [
                        'type' => 'onu',
                        'olt_id' => $olt->id,
                        'olt_name' => $olt->name,
                        'route_prefix' => $routePrefix,
                        'slot' => $hasPort ? (int) $slot : null,
                        'port' => $hasPort ? (int) $portNo : null,
                        'onu_id' => isset($onu['onu_id']) ? (int) $onu['onu_id'] : (isset($onu['id']) ? (int) $onu['id'] : null),
                        'serial_number' => $serialValue !== '' ? $serialValue : null,
                        'search_value' => $serialValue !== '' ? $serialValue : ($onu['name'] ?? null),
                        'label' => $label,
                        'sublabel' => implode(' · ', $sublabelParts),
                    ];

                    if (count($matches) >= $limit) {
                        return $matches;
                    }
                }
            }
        }

        return $matches;
    }

    private function driver(SnmpOlt $olt): string
    {
        return SmartOltSupport::driverKey(
            $olt,
            data_get($olt->last_test_result, 'system.sys_descr'),
            data_get($olt->last_test_result, 'system.sys_object_id'),
        );
    }
}
