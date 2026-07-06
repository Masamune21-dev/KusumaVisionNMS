<?php

namespace App\Http\Controllers;

use App\Models\OnuMapPin;
use App\Models\SnmpOlt;
use App\Services\CData\CDataCliWriteService;
use App\Services\Hioso\HiosoCliWriteService;
use App\Services\OnuInventoryService;
use App\Services\ZteRemoteOnuService;
use App\Support\SmartOltSupport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;

class OnuMapController extends Controller
{
    public function __construct(private readonly OnuInventoryService $inventory) {}

    public function index(): Response
    {
        $olts = SnmpOlt::query()->orderBy('name')->get();

        // Index OLT + capabilities sekali agar enrich pin tidak N+1.
        $oltMeta = [];
        foreach ($olts as $olt) {
            $driver = SmartOltSupport::driverKey(
                $olt,
                data_get($olt->last_test_result, 'system.sys_descr'),
                data_get($olt->last_test_result, 'system.sys_object_id'),
            );

            $oltMeta[$olt->id] = [
                'olt' => $olt,
                'driver' => $driver,
                'capabilities' => SmartOltSupport::capabilities($driver, $olt),
                'is_cdata' => SmartOltSupport::isNonZte($driver),
                'port_route' => SmartOltSupport::inventoryRoutePrefix($driver).'.port-onus',
            ];
        }

        $pins = OnuMapPin::query()
            ->whereIn('snmp_olt_id', array_keys($oltMeta))
            ->orderByDesc('id')
            ->get()
            ->map(fn (OnuMapPin $pin) => $this->serializePin($pin, $oltMeta))
            ->values();

        $aggregated = $this->inventory->collect($olts);

        // Fokus ke pin tertentu (dari tombol "Lihat di Peta" di Port ONUs).
        $focus = $this->focusFromRequest();
        $focusPin = $focus
            ? $pins->first(fn (array $p) => $p['snmp_olt_id'] === $focus['snmp_olt_id']
                && $p['slot'] === $focus['slot']
                && $p['port'] === $focus['port']
                && $p['onu_id'] === $focus['onu_id'])
            : null;

        return Inertia::render('Map/Index', [
            'pins' => $pins,
            'olts' => $olts->map(fn (SnmpOlt $olt) => [
                'id' => $olt->id,
                'name' => $olt->name,
                'ip' => $olt->ip,
            ])->values(),
            'onus' => $aggregated['onus'],
            'default_center' => $focusPin
                ? ['lat' => $focusPin['latitude'], 'lng' => $focusPin['longitude'], 'zoom' => 17]
                : $this->defaultCenter($pins->all()),
            'placement' => $this->placementFromRequest(),
            'focus_pin_id' => $focusPin['id'] ?? null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatePin($request);

        OnuMapPin::query()->updateOrCreate(
            [
                'snmp_olt_id' => $data['snmp_olt_id'],
                'slot' => $data['slot'],
                'port' => $data['port'],
                'onu_id' => $data['onu_id'],
            ],
            [
                'serial_number' => $data['serial_number'] ?? null,
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
                'customer_name' => $data['customer_name'] ?? null,
                'address' => $data['address'] ?? null,
                'phone' => $data['phone'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()?->id,
            ],
        );

        return redirect()
            ->route('map.index')
            ->with('success', 'Pin ONU tersimpan di peta.');
    }

    public function update(Request $request, OnuMapPin $pin): RedirectResponse
    {
        $data = $request->validate([
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'customer_name' => ['nullable', 'string', 'max:191'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $pin->fill(array_filter(
            $data,
            fn ($value, $key) => in_array($key, ['latitude', 'longitude'], true) ? $value !== null : true,
            ARRAY_FILTER_USE_BOTH,
        ));
        $pin->save();

        return redirect()
            ->route('map.index')
            ->with('success', 'Pin ONU diperbarui.');
    }

    public function destroy(OnuMapPin $pin): RedirectResponse
    {
        $pin->delete();

        return redirect()
            ->route('map.index')
            ->with('success', 'Pin ONU dihapus dari peta.');
    }

    /**
     * Reboot ONU dari detail pin — delegasi ke service ZTE / C-Data, lalu balik ke peta
     * (berbeda dari rute smartolt/cdata yang redirect ke halaman Port ONUs).
     */
    public function rebootPin(OnuMapPin $pin, ZteRemoteOnuService $zte, CDataCliWriteService $cdata, HiosoCliWriteService $hioso): RedirectResponse
    {
        $olt = $pin->olt;
        $back = redirect()->route('map.index');
        $this->assertPinCapability($olt, 'supports_reboot');

        try {
            if ($this->isHioso($olt)) {
                $result = $hioso->reboot($olt, $pin->port, $pin->onu_id);
                $ok = (bool) ($result['ok'] ?? false);
                $error = $result['error'] ?? null;
            } elseif ($this->isCdata($olt)) {
                $result = $cdata->reboot($olt, $this->ifaceKeyword($olt), $pin->slot, $pin->port, $pin->onu_id);
                $ok = (bool) ($result['ok'] ?? false);
                $error = $result['error'] ?? null;
            } else {
                $result = $zte->reboot($olt, $pin->slot, $pin->port, $pin->onu_id);
                $ok = (bool) ($result['ok'] ?? false);
                $error = $result['error'] ?? null;
            }

            return $back->with(
                $ok ? 'success' : 'error',
                $ok ? 'Perintah reboot ONU terkirim. ONU restart ~30–60 detik.' : 'Reboot ONU selesai dengan indikasi error: '.$error,
            );
        } catch (\Throwable $exception) {
            return $back->with('error', 'Reboot ONU gagal: '.$exception->getMessage());
        }
    }

    /**
     * Ganti nama ONU dari detail pin — delegasi ke service ZTE / C-Data, lalu balik ke peta.
     */
    public function renamePin(Request $request, OnuMapPin $pin, ZteRemoteOnuService $zte, CDataCliWriteService $cdata, HiosoCliWriteService $hioso): RedirectResponse
    {
        $olt = $pin->olt;
        $back = redirect()->route('map.index');
        $this->assertPinCapability($olt, 'supports_onu_info_write');

        $data = $request->validate(['name' => ['nullable', 'string', 'max:128']]);
        $name = trim((string) ($data['name'] ?? ''));

        try {
            if ($this->isHioso($olt)) {
                $result = $hioso->setName($olt, $pin->port, $pin->onu_id, $name);
                if (! ($result['ok'] ?? false)) {
                    return $back->with('error', 'Ubah nama ONU gagal: '.($result['error'] ?? ''));
                }
            } elseif ($this->isCdata($olt)) {
                $result = $cdata->setDescription($olt, $this->ifaceKeyword($olt), $pin->slot, $pin->port, $pin->onu_id, $name);
                if (! ($result['ok'] ?? false)) {
                    return $back->with('error', 'Ubah nama ONU gagal: '.($result['error'] ?? ''));
                }
            } else {
                $ifIndex = $this->resolveOnuIfIndex($olt, $pin->slot, $pin->port, $pin->onu_id);
                $zte->setInfo($olt, $ifIndex, $pin->onu_id, $name !== '' ? $name : null, null);
            }

            $this->mutateCachedOnuName($olt, $pin->slot, $pin->port, $pin->onu_id, $name !== '' ? $name : null);

            return $back->with('success', $name !== '' ? 'Nama ONU berhasil diperbarui.' : 'Nama ONU berhasil dihapus.');
        } catch (\Throwable $exception) {
            return $back->with('error', 'Ubah nama ONU gagal: '.$exception->getMessage());
        }
    }

    /**
     * Ekstrak koordinat dari URL Google Maps yang di-paste (termasuk link pendek
     * maps.app.goo.gl / goo.gl yang di-resolve via redirect server-side).
     */
    public function resolveLink(Request $request): JsonResponse
    {
        $data = $request->validate([
            'url' => ['required', 'string', 'max:2048'],
        ]);

        $url = trim($data['url']);

        // Link pendek perlu di-follow dulu agar koordinat muncul di URL final.
        if (preg_match('#https?://(maps\.app\.goo\.gl|goo\.gl/maps)#i', $url)) {
            $url = $this->expandShortLink($url) ?? $url;
        }

        $coords = $this->parseCoordinates($url);

        if ($coords === null) {
            return response()->json([
                'ok' => false,
                'error' => 'Koordinat tidak ditemukan di link. Pastikan ini link lokasi Google Maps.',
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'latitude' => $coords['lat'],
            'longitude' => $coords['lng'],
        ]);
    }

    /**
     * @return array{lat: float, lng: float}|null
     */
    private function parseCoordinates(string $url): ?array
    {
        $url = urldecode($url);

        $patterns = [
            '/@(-?\d+\.\d+),(-?\d+\.\d+)/',          // .../@lat,lng,zoom
            '/[?&]q=(-?\d+\.\d+),(-?\d+\.\d+)/',      // ?q=lat,lng
            '/[?&]ll=(-?\d+\.\d+),(-?\d+\.\d+)/',     // ?ll=lat,lng
            '/!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)/',       // !3dlat!4dlng
            '/(-?\d+\.\d+),\s*(-?\d+\.\d+)/',         // bare "lat, lng"
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $m)) {
                $lat = (float) $m[1];
                $lng = (float) $m[2];

                if ($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) {
                    return ['lat' => $lat, 'lng' => $lng];
                }
            }
        }

        return null;
    }

    private function expandShortLink(string $url): ?string
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (KusumaVisionNMS Map Link Resolver)',
            ])->timeout(8)->get($url);

            // URL setelah seluruh redirect di-follow.
            $final = (string) $response->effectiveUri();

            return $final !== '' ? $final : null;
        } catch (\Throwable) {
            return null;
        }
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

    /**
     * @param  array<int, array<string, mixed>>  $pins
     * @return array{lat: float, lng: float, zoom: int}
     */
    private function defaultCenter(array $pins): array
    {
        if ($pins === []) {
            // Fallback: Pati, Jawa Tengah (lokasi OLT live utama).
            return ['lat' => -6.7559, 'lng' => 111.0381, 'zoom' => 11];
        }

        $lat = array_sum(array_column($pins, 'latitude')) / count($pins);
        $lng = array_sum(array_column($pins, 'longitude')) / count($pins);

        return ['lat' => $lat, 'lng' => $lng, 'zoom' => count($pins) === 1 ? 15 : 12];
    }

    /**
     * Mode placement dari Port ONUs ("klik langsung di map") — pre-target ONU tertentu.
     *
     * @return array<string, int>|null
     */
    private function placementFromRequest(): ?array
    {
        return $this->onuKeyFromRequest('place');
    }

    /**
     * Fokus ke pin ONU tertentu ("Lihat di Peta" dari Port ONUs).
     *
     * @return array<string, int>|null
     */
    private function focusFromRequest(): ?array
    {
        return $this->onuKeyFromRequest('focus');
    }

    /**
     * @return array<string, int>|null
     */
    private function onuKeyFromRequest(string $prefix): ?array
    {
        $values = [];

        foreach (['olt', 'slot', 'port', 'onu'] as $part) {
            $value = request()->query("{$prefix}_{$part}");
            if ($value === null || ! is_numeric($value)) {
                return null;
            }
            $values[$part] = (int) $value;
        }

        return [
            'snmp_olt_id' => $values['olt'],
            'slot' => $values['slot'],
            'port' => $values['port'],
            'onu_id' => $values['onu'],
        ];
    }

    private function driverOf(SnmpOlt $olt): string
    {
        return SmartOltSupport::driverKey(
            $olt,
            data_get($olt->last_test_result, 'system.sys_descr'),
            data_get($olt->last_test_result, 'system.sys_object_id'),
        );
    }

    private function isCdata(SnmpOlt $olt): bool
    {
        return SmartOltSupport::isCData($this->driverOf($olt));
    }

    private function isHioso(SnmpOlt $olt): bool
    {
        return SmartOltSupport::isHioso($this->driverOf($olt));
    }

    private function ifaceKeyword(SnmpOlt $olt): string
    {
        return $this->driverOf($olt) === SmartOltSupport::DRIVER_CDATA_EPON ? 'epon' : 'gpon';
    }

    private function assertPinCapability(SnmpOlt $olt, string $capability): void
    {
        abort_unless(
            (bool) (SmartOltSupport::capabilities($this->driverOf($olt), $olt)[$capability] ?? false),
            403,
            'Aksi ini tidak didukung untuk OLT ini.',
        );
    }

    private function resolveOnuIfIndex(SnmpOlt $olt, int $slot, int $port, int $onuId): int
    {
        $onus = data_get($olt->last_test_result ?? [], "port_onus.{$slot}_{$port}.onus", []);

        foreach ($onus as $onu) {
            if ((int) ($onu['onu_id'] ?? 0) === $onuId && ($onu['if_index'] ?? null) !== null) {
                return (int) $onu['if_index'];
            }
        }

        return 0x10000000 | ($slot << 16) | ($port << 8);
    }

    private function mutateCachedOnuName(SnmpOlt $olt, int $slot, int $port, int $onuId, ?string $name): void
    {
        $snapshot = $olt->last_test_result ?? [];
        $path = "port_onus.{$slot}_{$port}.onus";
        $onus = data_get($snapshot, $path);

        if (! is_array($onus)) {
            return;
        }

        foreach ($onus as $index => $onu) {
            if ((int) ($onu['onu_id'] ?? 0) === $onuId) {
                $onus[$index]['name'] = $name;
            }
        }

        data_set($snapshot, $path, $onus);
        $olt->forceFill(['last_test_result' => $snapshot])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePin(Request $request): array
    {
        return $request->validate([
            'snmp_olt_id' => ['required', 'integer', 'exists:snmp_olts,id'],
            'slot' => ['required', 'integer', 'min:0'],
            'port' => ['required', 'integer', 'min:0'],
            'onu_id' => ['required', 'integer', 'min:0'],
            'serial_number' => ['nullable', 'string', 'max:64'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'customer_name' => ['nullable', 'string', 'max:191'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
