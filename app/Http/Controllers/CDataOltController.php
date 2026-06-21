<?php

namespace App\Http\Controllers;

use App\Models\PollingEvent;
use App\Models\SnmpOlt;
use App\Services\SmartOltSnmpServiceResolver;
use App\Services\Snmp\OltSnmpClient;
use App\Support\SmartOltSupport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Halaman OLT C-Data — inventori OLT non-ZTE (C-Data EPON 17409 & GPON 34592).
 *
 * v1 = monitoring read-only. Fase 1 ini: CRUD inventori + Test/probe family.
 * Probe memakai SNMP get generik (sysDescr/sysObjectID) lewat {@see OltSnmpClient};
 * driver SNMP/CLI C-Data konkret di-resolve via SmartOltSnmpServiceResolver pada Fase 2.
 */
class CDataOltController extends Controller
{
    /** Kolom status tabel ONU v3 — walk berisi data = firmware FlashV3.x (guide §3.3 & §5.5). */
    private const CDATA_GPON_V3_STATUS_OID = '1.3.6.1.4.1.34592.1.5.1.1.2.18.12.1.1';

    public function index(): Response
    {
        $olts = SnmpOlt::query()
            ->latest()
            ->get()
            ->map(fn (SnmpOlt $olt) => $this->serializeOlt($olt))
            ->filter(fn (array $row) => SmartOltSupport::isCData($row['driver']))
            ->values();

        return Inertia::render('CDataOlt/Index', [
            'olts' => $olts,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('CDataOlt/Create', [
            'defaults' => [
                'vendor' => 'C-Data GPON 34592',
                'snmp_port' => 161,
                'snmp_version' => 'v2c',
                'cli_transport' => 'telnet',
                'cli_port' => 23,
                'poll_interval_minutes' => 5,
                'rx_poll_interval_minutes' => 5,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        SnmpOlt::create($this->validated($request));

        return redirect()
            ->route('cdata-olt.index')
            ->with('success', 'OLT C-Data berhasil ditambahkan.');
    }

    public function edit(SnmpOlt $olt): Response
    {
        return Inertia::render('CDataOlt/Edit', [
            'olt' => $this->serializeOlt($olt),
        ]);
    }

    public function update(Request $request, SnmpOlt $olt): RedirectResponse
    {
        $olt->update($this->withoutEmptySecrets($this->validated($request, $olt)));

        return redirect()
            ->route('cdata-olt.index')
            ->with('success', 'OLT C-Data berhasil diperbarui.');
    }

    public function destroy(SnmpOlt $olt): RedirectResponse
    {
        $olt->delete();

        return redirect()
            ->route('cdata-olt.index')
            ->with('success', 'OLT C-Data berhasil dihapus.');
    }

    public function test(SnmpOlt $olt, OltSnmpClient $client): RedirectResponse
    {
        // sysDescr/sysObjectID = MIB-II standar (vendor-neutral); driverKey memetakan ke family C-Data.
        $result = $client->test($olt);

        // Probe firmware FlashV3.x untuk family GPON (inventory/optical hanya via CLI bila V3).
        if (($result['ok'] ?? false) && ($result['driver'] ?? null) === SmartOltSupport::DRIVER_CDATA_GPON) {
            try {
                $isV3 = count($client->walk($olt, self::CDATA_GPON_V3_STATUS_OID)) > 0;
            } catch (Throwable) {
                $isV3 = false;
            }

            $result['cdata'] = [
                'firmware_v3' => $isV3,
                'firmware_variant' => $isV3 ? 'FlashV3.x' : 'legacy',
            ];
        }

        $olt->forceFill([
            'last_test_result' => $result,
            'last_tested_at' => now(),
        ])->save();

        PollingEvent::log(
            $olt->id,
            PollingEvent::KIND_OLT_TEST,
            (bool) ($result['ok'] ?? false),
            $result['error'] ?? null,
            isset($result['latency_ms']) ? (int) $result['latency_ms'] : null,
        );

        $message = $result['ok']
            ? sprintf(
                'SNMP OK. Family: %s. Latency: %sms.',
                SmartOltSupport::capabilities($result['driver'], $olt)['vendor_family'],
                $result['latency_ms'],
            )
            : sprintf('SNMP gagal: %s', $result['error'] ?? 'unknown error');

        return redirect()
            ->route('cdata-olt.index')
            ->with($result['ok'] ? 'success' : 'error', $message);
    }

    public function detail(SnmpOlt $olt): Response
    {
        return Inertia::render('CDataOlt/Detail', [
            'olt' => $this->serializeOlt($olt),
            'snapshot' => $this->serializeSnapshot($olt),
        ]);
    }

    public function portOnus(Request $request, SnmpOlt $olt, int $slot, int $port): Response
    {
        return Inertia::render('CDataOlt/PortOnus', [
            'olt' => $this->serializeOlt($olt),
            'slot' => $slot,
            'port' => $port,
            'snapshot' => data_get($olt->last_test_result, "port_onus.{$slot}_{$port}"),
            'focus' => $request->query('focus'),
            'q' => $request->query('q'),
        ]);
    }

    /**
     * Scan penuh: baca system + ports + seluruh ONU (SNMP utk EPON, CLI utk GPON V3), tulis cache
     * `port_onus` dlm bentuk sama dgn ZTE supaya muncul di ONU Monitoring + global search.
     */
    public function refresh(SnmpOlt $olt, SmartOltSnmpServiceResolver $resolver): RedirectResponse
    {
        $back = redirect()->route('cdata-olt.detail', $olt);

        try {
            $driver = $resolver->resolve($olt);
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

            return $back->with('success', sprintf('Scan ONU OK. %s ONU ditemukan di %s.', count($onus), $olt->name));
        } catch (Throwable $exception) {
            return $back->with('error', 'Scan ONU gagal: '.$exception->getMessage());
        }
    }

    public function refreshPortOnus(SnmpOlt $olt, int $slot, int $port, SmartOltSnmpServiceResolver $resolver): RedirectResponse
    {
        $back = redirect()->route('cdata-olt.port-onus', [$olt, $slot, $port]);

        try {
            $onus = $resolver->resolve($olt)->getRegisteredOnusByPort($olt, $slot, $port);

            $snapshot = $olt->last_test_result ?? [];
            data_set($snapshot, "port_onus.{$slot}_{$port}", [
                'ok' => true,
                'slot' => $slot,
                'port' => $port,
                'onus' => $onus,
                'count' => count($onus),
                'error' => null,
                'refreshed_at' => now()->toIso8601String(),
            ]);
            $olt->forceFill(['last_test_result' => $snapshot])->save();

            return $back->with('success', sprintf('Refresh ONU OK. %s ONU di slot %s port %s.', count($onus), $slot, $port));
        } catch (Throwable $exception) {
            return $back->with('error', 'Refresh ONU gagal: '.$exception->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSnapshot(SnmpOlt $olt): array
    {
        $result = $olt->last_test_result ?? [];
        $counts = [];

        foreach (data_get($result, 'port_onus', []) as $key => $entry) {
            $onus = $entry['onus'] ?? [];
            $counts[$key] = [
                'count' => (int) ($entry['count'] ?? count($onus)),
                'online' => collect($onus)->where('online', true)->count(),
                'refreshed_at' => $entry['refreshed_at'] ?? null,
            ];
        }

        return [
            'system' => data_get($result, 'system'),
            'ports' => data_get($result, 'ports', []),
            'port_counts' => $counts,
            'firmware_v3' => (bool) data_get($result, 'cdata.firmware_v3', false),
            'scanned_at' => data_get($result, 'onu_scanned_at'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeOlt(SnmpOlt $olt): array
    {
        $driver = SmartOltSupport::driverKey(
            $olt,
            data_get($olt->last_test_result, 'system.sys_descr'),
            data_get($olt->last_test_result, 'system.sys_object_id'),
        );

        return [
            'id' => $olt->id,
            'name' => $olt->name,
            'vendor' => $olt->vendor,
            'ip' => $olt->ip,
            'snmp_port' => $olt->snmp_port,
            'snmp_version' => $olt->snmp_version,
            'cli_transport' => $olt->cli_transport,
            'cli_port' => $olt->cli_port,
            'cli_username' => $olt->cli_username,
            'polling_enabled' => (bool) $olt->polling_enabled,
            'poll_interval_minutes' => $olt->pollIntervalMinutes(),
            'rx_poll_interval_minutes' => $olt->rxPollIntervalMinutes(),
            'driver' => $driver,
            'capabilities' => SmartOltSupport::capabilities($driver, $olt),
            'last_test_result' => $olt->last_test_result,
            'last_tested_at' => $olt->last_tested_at?->toIso8601String(),
            'last_polled_at' => $olt->last_polled_at?->toIso8601String(),
            'last_rx_polled_at' => $olt->last_rx_polled_at?->toIso8601String(),
            'created_at' => $olt->created_at?->toIso8601String(),
            'updated_at' => $olt->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?SnmpOlt $olt = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'vendor' => ['required', 'string', 'max:100'],
            'ip' => ['required', 'ip', Rule::unique('snmp_olts', 'ip')->ignore($olt)],
            'snmp_port' => ['required', 'integer', 'between:1,65535'],
            'snmp_read_community' => [$olt ? 'nullable' : 'required', 'string', 'max:255'],
            'snmp_write_community' => ['nullable', 'string', 'max:255'],
            'snmp_version' => ['required', Rule::in(['v1', 'v2c'])],
            'cli_transport' => ['nullable', Rule::in(['telnet', 'ssh'])],
            'cli_port' => ['nullable', 'integer', 'between:1,65535'],
            'cli_username' => ['nullable', 'string', 'max:100'],
            'cli_password' => ['nullable', 'string', 'max:255'],
            'polling_enabled' => ['boolean'],
            'poll_interval_minutes' => ['nullable', 'integer', 'between:1,1440'],
            'rx_poll_interval_minutes' => ['nullable', 'integer', 'between:1,1440'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withoutEmptySecrets(array $data): array
    {
        foreach (['snmp_read_community', 'snmp_write_community', 'cli_password'] as $key) {
            if (($data[$key] ?? null) === null || $data[$key] === '') {
                unset($data[$key]);
            }
        }

        return $data;
    }
}
