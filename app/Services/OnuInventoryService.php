<?php

namespace App\Services;

use App\Models\SnmpOlt;
use App\Support\SmartOltSupport;
use Illuminate\Support\Collection;

/**
 * Agregasi ONU lintas-OLT dari cache `snmp_olts.last_test_result.port_onus`.
 *
 * Sumber tunggal yang dipakai bersama oleh ONU Monitoring (tabular) dan Peta ONU
 * (dropdown + search global di modal tambah pin). ONU tidak punya tabel sendiri —
 * semua data berasal dari snapshot SNMP/CLI terakhir yang tersimpan per-OLT.
 */
class OnuInventoryService
{
    /**
     * Kumpulkan seluruh ONU dari semua OLT (atau koleksi OLT yang diberikan).
     *
     * @param  Collection<int, SnmpOlt>|null  $olts
     * @return array{onus: array<int, array<string, mixed>>, refreshed_at: array<int, string>}
     */
    public function collect(?Collection $olts = null): array
    {
        $olts ??= SnmpOlt::query()->orderBy('name')->get();

        $onus = [];
        $refreshedAt = [];

        foreach ($olts as $olt) {
            $portOnus = data_get($olt->last_test_result ?? [], 'port_onus', []);
            if (! is_array($portOnus)) {
                continue;
            }

            $routePrefix = $this->routePrefix($olt);

            foreach ($portOnus as $entry) {
                $entryRefreshed = data_get($entry, 'refreshed_at');
                if ($entryRefreshed && (! isset($refreshedAt[$olt->id]) || $entryRefreshed > $refreshedAt[$olt->id])) {
                    $refreshedAt[$olt->id] = $entryRefreshed;
                }

                foreach (data_get($entry, 'onus', []) as $onu) {
                    $onus[] = $this->normalize($olt, $routePrefix, $onu);
                }
            }
        }

        usort(
            $onus,
            fn (array $a, array $b) => [$a['olt_name'], $a['slot'], $a['port'], $a['onu_id']]
                <=> [$b['olt_name'], $b['slot'], $b['port'], $b['onu_id']],
        );

        return ['onus' => $onus, 'refreshed_at' => $refreshedAt];
    }

    /**
     * Cari satu ONU di cache OLT dan kembalikan bentuk ter-normalisasi (untuk enrich pin peta).
     *
     * @return array<string, mixed>|null
     */
    public function findOne(SnmpOlt $olt, int $slot, int $port, int $onuId): ?array
    {
        $routePrefix = $this->routePrefix($olt);

        $onus = data_get($olt->last_test_result ?? [], "port_onus.{$slot}_{$port}.onus", []);

        foreach ($onus as $onu) {
            if ((int) ($onu['onu_id'] ?? 0) === $onuId) {
                return $this->normalize($olt, $routePrefix, $onu);
            }
        }

        return null;
    }

    /**
     * Prefix rute inventori family OLT (smartolt / cdata-olt / hioso-olt) untuk membangun link
     * halaman ONU per port di frontend (ONU monitoring & peta).
     */
    private function routePrefix(SnmpOlt $olt): string
    {
        return SmartOltSupport::inventoryRoutePrefix(SmartOltSupport::driverKey(
            $olt,
            data_get($olt->last_test_result, 'system.sys_descr'),
            data_get($olt->last_test_result, 'system.sys_object_id'),
        ));
    }

    /**
     * @param  array<string, mixed>  $onu
     * @return array<string, mixed>
     */
    private function normalize(SnmpOlt $olt, string $routePrefix, array $onu): array
    {
        return [
            'olt_id' => $olt->id,
            'olt_name' => $olt->name,
            // Nama rute halaman ONU per port (per family) — dipakai frontend membangun link langsung.
            'port_route' => $routePrefix.'.port-onus',
            'olt_cdata' => $routePrefix !== 'smartolt',
            'slot' => (int) ($onu['slot'] ?? 0),
            'port' => (int) ($onu['port'] ?? 0),
            'onu_id' => (int) ($onu['onu_id'] ?? 0),
            'if_index' => isset($onu['if_index']) ? (int) $onu['if_index'] : null,
            'interface' => $onu['interface'] ?? null,
            'serial_number' => $onu['serial_number'] ?? null,
            'mac' => $onu['mac'] ?? null,
            'type_name' => $onu['type_name'] ?? null,
            'name' => $onu['name'] ?? null,
            'description' => $onu['description'] ?? null,
            'customer_name' => SmartOltSupport::customerNameFromOnu($onu),
            'admin_state' => $onu['admin_state'] ?? 'unknown',
            'phase_state' => $onu['phase_state'] ?? 'Unknown',
            'online' => (bool) ($onu['online'] ?? false),
            'last_down_cause' => $onu['last_down_cause'] ?? null,
            'rx_power_dbm' => $onu['rx_power_dbm'] ?? null,
            'rx_power_label' => $onu['rx_power_label'] ?? null,
        ];
    }
}
