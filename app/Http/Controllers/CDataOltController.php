<?php

namespace App\Http\Controllers;

use App\Models\PollingEvent;
use App\Models\SnmpOlt;
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
