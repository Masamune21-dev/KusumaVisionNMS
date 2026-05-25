<?php

namespace App\Http\Controllers;

use App\Models\SnmpOlt;
use App\Services\Snmp\OltSnmpClient;
use App\Support\SmartOltSupport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SmartOltController extends Controller
{
    public function index(): Response
    {
        $olts = SnmpOlt::query()
            ->latest()
            ->get()
            ->map(fn (SnmpOlt $olt) => $this->serializeOlt($olt));

        return Inertia::render('SmartOlt/Index', [
            'olts' => $olts,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('SmartOlt/Create', [
            'defaults' => [
                'snmp_port' => 161,
                'snmp_version' => 'v2c',
                'cli_transport' => null,
                'cli_port' => null,
            ],
        ]);
    }

    public function detail(SnmpOlt $olt): Response
    {
        return Inertia::render('SmartOlt/Detail', [
            'olt' => $this->serializeOlt($olt),
            'snapshot' => $this->serializeSnapshot($olt),
        ]);
    }

    public function portOnus(SnmpOlt $olt, int $slot, int $port): Response
    {
        return Inertia::render('SmartOlt/PortOnus', [
            'olt' => $this->serializeOlt($olt),
            'slot' => $slot,
            'port' => $port,
            'snapshot' => $this->serializePortOnusSnapshot($olt, $slot, $port),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        SnmpOlt::create($this->validated($request));

        return redirect()
            ->route('smartolt.index')
            ->with('success', 'OLT berhasil ditambahkan.');
    }

    public function edit(SnmpOlt $olt): Response
    {
        return Inertia::render('SmartOlt/Edit', [
            'olt' => $this->serializeOlt($olt),
        ]);
    }

    public function update(Request $request, SnmpOlt $olt): RedirectResponse
    {
        $olt->update($this->withoutEmptySecrets($this->validated($request, $olt)));

        return redirect()
            ->route('smartolt.index')
            ->with('success', 'OLT berhasil diperbarui.');
    }

    public function destroy(SnmpOlt $olt): RedirectResponse
    {
        $olt->delete();

        return redirect()
            ->route('smartolt.index')
            ->with('success', 'OLT berhasil dihapus.');
    }

    public function test(SnmpOlt $olt, OltSnmpClient $client): RedirectResponse
    {
        $result = $client->test($olt);

        $olt->forceFill([
            'last_test_result' => $result,
            'last_tested_at' => now(),
        ])->save();

        $message = $result['ok']
            ? sprintf('SNMP OK. Driver: %s. Latency: %sms.', $result['driver'], $result['latency_ms'])
            : sprintf('SNMP gagal: %s', $result['error'] ?? 'unknown error');

        return redirect()
            ->route('smartolt.index')
            ->with($result['ok'] ? 'success' : 'error', $message);
    }

    public function refresh(SnmpOlt $olt, OltSnmpClient $client): RedirectResponse
    {
        $result = $client->snapshot($olt);

        $olt->forceFill([
            'last_test_result' => $result,
            'last_tested_at' => now(),
        ])->save();

        $message = $result['ok']
            ? sprintf('Refresh SNMP OK. %s GPON port ditemukan.', count($result['ports'] ?? []))
            : sprintf('Refresh SNMP gagal: %s', $result['error'] ?? 'unknown error');

        return redirect()
            ->route('smartolt.detail', $olt)
            ->with($result['ok'] ? 'success' : 'error', $message);
    }

    public function refreshPortOnus(SnmpOlt $olt, int $slot, int $port, OltSnmpClient $client): RedirectResponse
    {
        $result = $client->portOnusSnapshot($olt, $slot, $port);
        $result['refreshed_at'] = now()->toIso8601String();

        $snapshot = $olt->last_test_result ?? [];
        data_set($snapshot, "port_onus.{$slot}_{$port}", $result);

        $olt->forceFill([
            'last_test_result' => $snapshot,
        ])->save();

        $message = $result['ok']
            ? sprintf('Refresh ONU OK. %s ONU ditemukan di slot %s port %s.', $result['count'], $slot, $port)
            : sprintf('Refresh ONU gagal: %s', $result['error'] ?? 'unknown error');

        return redirect()
            ->route('smartolt.port-onus', [$olt, $slot, $port])
            ->with($result['ok'] ? 'success' : 'error', $message);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?SnmpOlt $olt = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'vendor' => ['nullable', 'string', 'max:100'],
            'ip' => [
                'required',
                'ip',
                Rule::unique('snmp_olts', 'ip')->ignore($olt),
            ],
            'snmp_port' => ['required', 'integer', 'between:1,65535'],
            'snmp_read_community' => [$olt ? 'nullable' : 'required', 'string', 'max:255'],
            'snmp_write_community' => ['nullable', 'string', 'max:255'],
            'snmp_version' => ['required', Rule::in(['v1', 'v2c', 'v3'])],
            'cli_transport' => ['nullable', Rule::in(['telnet', 'ssh'])],
            'cli_port' => ['nullable', 'integer', 'between:1,65535'],
            'cli_username' => ['nullable', 'string', 'max:100'],
            'cli_password' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /**
     * @param array<string, mixed> $data
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
            'driver' => $driver,
            'capabilities' => SmartOltSupport::capabilities($driver),
            'last_test_result' => $olt->last_test_result,
            'last_tested_at' => $olt->last_tested_at?->toIso8601String(),
            'created_at' => $olt->created_at?->toIso8601String(),
            'updated_at' => $olt->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSnapshot(SnmpOlt $olt): array
    {
        $snapshot = $olt->last_test_result ?? [];

        return [
            'ok' => (bool) data_get($snapshot, 'ok', false),
            'driver' => data_get($snapshot, 'driver'),
            'latency_ms' => data_get($snapshot, 'latency_ms'),
            'system' => data_get($snapshot, 'system', []),
            'ports' => data_get($snapshot, 'ports', []),
            'error' => data_get($snapshot, 'error'),
            'last_tested_at' => $olt->last_tested_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePortOnusSnapshot(SnmpOlt $olt, int $slot, int $port): array
    {
        $snapshot = data_get($olt->last_test_result ?? [], "port_onus.{$slot}_{$port}", []);

        return [
            'ok' => (bool) data_get($snapshot, 'ok', false),
            'slot' => $slot,
            'port' => $port,
            'if_index' => data_get($snapshot, 'if_index'),
            'port_row' => data_get($snapshot, 'port_row'),
            'onus' => data_get($snapshot, 'onus', []),
            'count' => data_get($snapshot, 'count', 0),
            'latency_ms' => data_get($snapshot, 'latency_ms'),
            'error' => data_get($snapshot, 'error'),
            'refreshed_at' => data_get($snapshot, 'refreshed_at'),
        ];
    }
}
