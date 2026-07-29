<?php

namespace App\Services;

use App\Models\OnuOdpLink;
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
     * Snapshot `last_test_result` yang sudah ter-decode, di-memo per instance (= per request).
     *
     * @var array<int, array<string, mixed>>
     */
    private array $snapshots = [];

    /**
     * Prefix rute inventori per OLT, di-memo (driverKey membaca snapshot 2x tiap panggilan).
     *
     * @var array<int, string>
     */
    private array $routePrefixes = [];

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
        // Satu query untuk SEMUA kaitan ODP sekaligus — hindari N+1 di loop ribuan ONU.
        $odpMap = $this->odpLookupMap($olts->pluck('id')->all());

        foreach ($olts as $olt) {
            $portOnus = data_get($this->snapshot($olt), 'port_onus', []);
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
                    $onus[] = $this->normalize($olt, $routePrefix, $onu, $odpMap);
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
     * Seluruh ONU pada satu PON port sebuah OLT (bentuk ter-normalisasi), untuk API mobile.
     *
     * @return array{onus: array<int, array<string, mixed>>, refreshed_at: ?string, count: int}
     */
    public function forPort(SnmpOlt $olt, int $slot, int $port): array
    {
        $routePrefix = $this->routePrefix($olt);
        $entry = data_get($this->snapshot($olt), "port_onus.{$slot}_{$port}", []);
        $odpMap = $this->odpLookupMap([$olt->id], $slot, $port);

        $onus = [];
        foreach (data_get($entry, 'onus', []) as $onu) {
            $onus[] = $this->normalize($olt, $routePrefix, $onu, $odpMap);
        }

        usort($onus, fn (array $a, array $b) => $a['onu_id'] <=> $b['onu_id']);

        return [
            'onus' => $onus,
            'refreshed_at' => data_get($entry, 'refreshed_at'),
            'count' => count($onus),
        ];
    }

    /**
     * Cari satu ONU di cache OLT dan kembalikan bentuk ter-normalisasi (untuk enrich pin peta).
     *
     * Secara default TIDAK melakukan lookup ODP: `OnuOdpService::connectedOnus()` memanggil
     * metode ini di dalam loop, jadi satu query per panggilan justru akan menjadi N+1. Pemanggil
     * satuan yang memang butuh kolom ODP (mis. detail 1 ONU di REST API) mengaktifkannya lewat
     * $withOdp — jangan set true di dalam loop.
     *
     * @return array<string, mixed>|null
     */
    public function findOne(SnmpOlt $olt, int $slot, int $port, int $onuId, bool $withOdp = false): ?array
    {
        $routePrefix = $this->routePrefix($olt);

        $onus = data_get($this->snapshot($olt), "port_onus.{$slot}_{$port}.onus", []);

        foreach ($onus as $onu) {
            if ((int) ($onu['onu_id'] ?? 0) === $onuId) {
                return $this->normalize(
                    $olt,
                    $routePrefix,
                    $onu,
                    $withOdp ? $this->odpLookupMap([$olt->id], $slot, $port) : [],
                );
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
        return $this->routePrefixes[$olt->id] ??= SmartOltSupport::inventoryRoutePrefix(SmartOltSupport::driverKey(
            $olt,
            data_get($this->snapshot($olt), 'system.sys_descr'),
            data_get($this->snapshot($olt), 'system.sys_object_id'),
        ));
    }

    /**
     * Snapshot `last_test_result` OLT, di-decode SEKALI per request.
     *
     * Cast `array` Eloquent menjalankan json_decode ulang di SETIAP akses atribut, sementara
     * findOne() dipanggil ratusan kali per request halaman peta (sekali per pin + sekali per
     * ONU-ODP) dan tiap panggilan menyentuh snapshot 3x (port_onus + 2x driverKey). Pada OLT
     * C300 (snapshot ~1 MB) itu berarti puluhan MB json_decode untuk satu halaman.
     *
     * @return array<string, mixed>
     */
    private function snapshot(SnmpOlt $olt): array
    {
        if (! array_key_exists($olt->id, $this->snapshots)) {
            $snapshot = $olt->last_test_result;
            $this->snapshots[$olt->id] = is_array($snapshot) ? $snapshot : [];
        }

        return $this->snapshots[$olt->id];
    }

    /**
     * Peta kaitan ONU↔ODP di-key "oltId.slot.port.onuId", satu query untuk banyak OLT sekaligus.
     *
     * Query model `OnuOdpLink` langsung (bukan lewat `OnuOdpService`) karena servis itu sudah
     * bergantung pada kelas ini — meng-inject balik akan membuat dependensi melingkar.
     *
     * @param  array<int, int>  $oltIds
     * @return array<string, array{odp_id:int, odp_name:?string}>
     */
    private function odpLookupMap(array $oltIds, ?int $slot = null, ?int $port = null): array
    {
        if ($oltIds === []) {
            return [];
        }

        return OnuOdpLink::query()
            ->whereIn('snmp_olt_id', $oltIds)
            ->when($slot !== null, fn ($query) => $query->where('slot', $slot)->where('port', $port))
            ->with('odp:id,name')
            ->get()
            ->mapWithKeys(fn (OnuOdpLink $link) => [
                "{$link->snmp_olt_id}.{$link->slot}.{$link->port}.{$link->onu_id}" => [
                    'odp_id' => $link->odp_id,
                    'odp_name' => $link->odp?->name,
                ],
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $onu
     * @param  array<string, array{odp_id:int, odp_name:?string}>  $odpMap  key "oltId.slot.port.onuId"
     * @return array<string, mixed>
     */
    private function normalize(SnmpOlt $olt, string $routePrefix, array $onu, array $odpMap = []): array
    {
        $slot = (int) ($onu['slot'] ?? 0);
        $port = (int) ($onu['port'] ?? 0);
        $onuId = (int) ($onu['onu_id'] ?? 0);
        $odp = $odpMap["{$olt->id}.{$slot}.{$port}.{$onuId}"] ?? null;

        return [
            'olt_id' => $olt->id,
            'olt_name' => $olt->name,
            // Nama rute halaman ONU per port (per family) — dipakai frontend membangun link langsung.
            'port_route' => $routePrefix.'.port-onus',
            'olt_cdata' => $routePrefix !== 'smartolt',
            'slot' => $slot,
            'port' => $port,
            'onu_id' => $onuId,
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
            'odp_id' => $odp['odp_id'] ?? null,
            'odp_name' => $odp['odp_name'] ?? null,
        ];
    }
}
