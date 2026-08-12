<?php

namespace App\Services\Map;

use App\Models\Odp;
use App\Models\OnuMapPin;
use App\Models\SnmpOlt;
use App\Services\OnuInventoryService;
use App\Services\OnuOdpService;
use App\Support\SmartOltSupport;
use Illuminate\Support\Collection;

/**
 * Perakit payload Peta ONU (pin ONU + pin ODP + titik tengah), dipakai bersama oleh
 * halaman web (`OnuMapController`) dan REST API v1 (`Api\V1\MapController`, aplikasi Android).
 *
 * Kinerja: enrich pin membaca snapshot `last_test_result` tiap OLT lewat `OnuInventoryService`
 * yang memo snapshot per-request — JANGAN membaca `$olt->last_test_result` langsung di dalam
 * loop (cast `array` men-decode ulang tiap akses; snapshot C300 ~1 MB). Metadata OLT
 * (driver/capabilities/route) juga di-memo di sini supaya `driverKey()` tak dihitung per pin.
 */
class OnuMapPayloadService
{
    /**
     * Metadata OLT ter-memo per instance (= per request), di-key id OLT.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $meta = [];

    public function __construct(
        private readonly OnuInventoryService $inventory,
        private readonly OnuOdpService $odpService,
    ) {}

    /**
     * Metadata (driver, capabilities, prefix rute) untuk koleksi OLT yang diminta saja.
     *
     * @param  Collection<int, SnmpOlt>  $olts
     * @return array<int, array<string, mixed>>
     */
    public function oltMeta(Collection $olts): array
    {
        $result = [];

        foreach ($olts as $olt) {
            if (! isset($this->meta[$olt->id])) {
                // Ambil snapshot sekali ke variabel lokal (bukan 2x data_get($olt->...)).
                $snapshot = $olt->last_test_result ?? [];
                $driver = SmartOltSupport::driverKey(
                    $olt,
                    data_get($snapshot, 'system.sys_descr'),
                    data_get($snapshot, 'system.sys_object_id'),
                );

                $this->meta[$olt->id] = [
                    'olt' => $olt,
                    'driver' => $driver,
                    'capabilities' => SmartOltSupport::capabilities($driver, $olt),
                    'is_cdata' => SmartOltSupport::isNonZte($driver),
                    'port_route' => SmartOltSupport::inventoryRoutePrefix($driver).'.port-onus',
                ];
            }

            $result[$olt->id] = $this->meta[$olt->id];
        }

        return $result;
    }

    /**
     * Pin ONU (ter-enrich status live) untuk OLT yang diberikan.
     *
     * @param  Collection<int, SnmpOlt>  $olts
     * @return Collection<int, array<string, mixed>>
     */
    public function pins(Collection $olts): Collection
    {
        $meta = $this->oltMeta($olts);

        return OnuMapPin::query()
            ->whereIn('snmp_olt_id', array_keys($meta))
            ->orderByDesc('id')
            ->get()
            ->map(fn (OnuMapPin $pin) => $this->serializePin($pin, $meta))
            ->values();
    }

    /**
     * ODP + ONU terhubung (untuk pin ODP kuning + garis ODP→ONU).
     *
     * @param  Collection<int, Odp>  $odps
     * @param  Collection<int, SnmpOlt>  $olts
     * @return Collection<int, array<string, mixed>>
     */
    public function odps(Collection $odps, Collection $olts): Collection
    {
        $meta = $this->oltMeta($olts);
        $connected = $this->odpService->connectedOnus($odps);

        return $odps
            ->map(fn (Odp $odp) => [
                'id' => $odp->id,
                'snmp_olt_id' => $odp->snmp_olt_id,
                'olt_name' => $meta[$odp->snmp_olt_id]['olt']->name ?? null,
                'name' => $odp->name,
                'slot' => $odp->slot,
                'port' => $odp->port,
                'latitude' => (float) $odp->latitude,
                'longitude' => (float) $odp->longitude,
                // Warna pin (null = default amber; fallback ditangani klien web & mobile).
                'color' => $odp->color,
                'locked' => (bool) $odp->locked,
                'notes' => $odp->notes,
                'onus' => $connected[$odp->id] ?? [],
            ])
            ->values();
    }

    /**
     * ONU lintas-OLT untuk dropdown/pencarian modal "Tambah Pin", dipangkas ke kolom yang
     * benar-benar dipakai (bentuk penuh `OnuInventoryService::collect()` ±22 kolom × 4.500
     * ONU ≈ 2 MB JSON).
     *
     * @param  Collection<int, SnmpOlt>  $olts
     * @return array<int, array<string, mixed>>
     */
    public function onuOptions(Collection $olts): array
    {
        return array_map(
            fn (array $onu) => [
                'olt_id' => $onu['olt_id'],
                'olt_name' => $onu['olt_name'],
                'slot' => $onu['slot'],
                'port' => $onu['port'],
                'onu_id' => $onu['onu_id'],
                'interface' => $onu['interface'],
                'serial_number' => $onu['serial_number'],
                'name' => $onu['name'],
                'customer_name' => $onu['customer_name'],
                'online' => $onu['online'],
                'rx_power_dbm' => $onu['rx_power_dbm'],
                'rx_power_label' => $onu['rx_power_label'],
            ],
            $this->inventory->collect($olts)['onus'],
        );
    }

    /**
     * Titik tengah default peta = rata-rata koordinat pin (fallback: Pati, Jawa Tengah).
     *
     * @param  array<int, array<string, mixed>>  $pins
     * @return array{lat: float, lng: float, zoom: int}
     */
    public function defaultCenter(array $pins): array
    {
        if ($pins === []) {
            return ['lat' => -6.7559, 'lng' => 111.0381, 'zoom' => 11];
        }

        $lat = array_sum(array_column($pins, 'latitude')) / count($pins);
        $lng = array_sum(array_column($pins, 'longitude')) / count($pins);

        return ['lat' => $lat, 'lng' => $lng, 'zoom' => count($pins) === 1 ? 15 : 12];
    }

    /**
     * @param  array<int, array<string, mixed>>  $oltMeta
     * @return array<string, mixed>
     */
    private function serializePin(OnuMapPin $pin, array $oltMeta): array
    {
        $meta = $oltMeta[$pin->snmp_olt_id] ?? null;
        $olt = $meta['olt'] ?? null;
        $live = $olt ? $this->inventory->findOne($olt, $pin->slot, $pin->port, $pin->onu_id) : null;

        $liveName = $live['customer_name'] ?? null;
        $interface = $live['interface'] ?? SmartOltSupport::onuInterfaceId(
            $pin->slot,
            $pin->port,
            $pin->onu_id,
            $olt ? SmartOltSupport::isC600($olt) : false,
        );

        return [
            'id' => $pin->id,
            'snmp_olt_id' => $pin->snmp_olt_id,
            'olt_name' => $olt?->name,
            'olt_cdata' => $meta['is_cdata'] ?? false,
            // Nama rute halaman ONU per port (per family) untuk link "buka di Port ONUs".
            'port_route' => $meta['port_route'] ?? 'smartolt.port-onus',
            'capabilities' => $meta['capabilities'] ?? [],
            'slot' => $pin->slot,
            'port' => $pin->port,
            'onu_id' => $pin->onu_id,
            'if_index' => $live['if_index'] ?? null,
            'interface' => $interface,
            'serial_number' => $pin->serial_number ?? ($live['serial_number'] ?? null),
            'latitude' => (float) $pin->latitude,
            'longitude' => (float) $pin->longitude,
            'locked' => (bool) $pin->locked,
            // Nama tampil: override pin → nama ONU live.
            'customer_name' => $pin->customer_name ?: $liveName,
            'customer_name_override' => $pin->customer_name,
            'onu_name' => $liveName,
            'address' => $pin->address,
            'phone' => $pin->phone,
            'notes' => $pin->notes,
            'rx_power_dbm' => $live['rx_power_dbm'] ?? null,
            'rx_power_label' => $live['rx_power_label'] ?? null,
            'online' => (bool) ($live['online'] ?? false),
            'has_live' => $live !== null,
        ];
    }
}
