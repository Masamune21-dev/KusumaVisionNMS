<?php

namespace App\Http\Controllers;

use App\Models\SnmpOlt;
use App\Models\SmartOltOnuRegistration;
use App\Models\SmartOltProfile;
use App\Services\Snmp\OltSnmpClient;
use App\Services\ZteProvisioningScriptBuilder;
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

    public function unconfigured(SnmpOlt $olt): Response
    {
        return Inertia::render('SmartOlt/Unconfigured', [
            'olt' => $this->serializeOlt($olt),
            'snapshot' => $this->serializeUnconfiguredSnapshot($olt),
        ]);
    }

    public function registerOnuForm(Request $request, SnmpOlt $olt): Response
    {
        $slot = (int) $request->query('slot');
        $port = (int) $request->query('port');

        return Inertia::render('SmartOlt/RegisterOnu', [
            'olt' => $this->serializeOlt($olt),
            'profiles' => SmartOltProfileController::profileOptions(),
            'defaults' => [
                'serial_number' => (string) $request->query('sn', ''),
                'slot' => $slot ?: null,
                'port' => $port ?: null,
                'onu_id' => $this->suggestNextOnuId($olt, $slot, $port),
                'oid_index' => (string) $request->query('oid_index', ''),
                'customer_name' => '',
                'onu_type' => $this->firstProfileName('onu_type', 'ALL-ONT'),
                'tcont_profile' => $this->firstProfileName('tcont', 'SERVER'),
                'vlan' => 100,
                'vlan_profile' => $this->firstProfileName('vlan', 'ServiceName'),
                'service_name' => $this->firstProfileName('vlan', 'ServiceName'),
                'wan_mode' => 'pppoe',
                'pppoe_username' => '',
                'pppoe_password' => '',
                'ip_profile' => $this->firstProfileName('ip', 'INTERNET'),
                'static_ip' => '',
                'static_netmask' => '24',
            ],
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

    public function refreshUnconfigured(SnmpOlt $olt, OltSnmpClient $client): RedirectResponse
    {
        $result = $client->unconfiguredOnusSnapshot($olt);
        $result['refreshed_at'] = now()->toIso8601String();

        $snapshot = $olt->last_test_result ?? [];
        data_set($snapshot, 'unconfigured_onus', $result);

        $olt->forceFill([
            'last_test_result' => $snapshot,
        ])->save();

        $message = $result['ok']
            ? sprintf('Discovery unconfigured ONU OK. %s ONU ditemukan.', $result['count'])
            : sprintf('Discovery unconfigured ONU gagal: %s', $result['error'] ?? 'unknown error');

        return redirect()
            ->route('smartolt.unconfigured', $olt)
            ->with($result['ok'] ? 'success' : 'error', $message);
    }

    public function storeOnu(Request $request, SnmpOlt $olt, ZteProvisioningScriptBuilder $builder): RedirectResponse
    {
        $data = $this->hydrateProvisioningProfiles($this->validatedProvisioning($request));
        $script = $builder->build($data);

        SmartOltOnuRegistration::create([
            ...$data,
            'snmp_olt_id' => $olt->id,
            'pon_port' => sprintf('gpon-onu_1/%d/%d:%d', $data['slot'], $data['port'], $data['onu_id']),
            'cli_script' => $script,
            'status' => 'generated',
            'created_by' => $request->user()?->id,
        ]);

        return redirect()
            ->route('smartolt.registrations', $olt)
            ->with('success', 'Provisioning script berhasil digenerate dan disimpan ke audit log.');
    }

    public function registrations(SnmpOlt $olt): Response
    {
        $registrations = SmartOltOnuRegistration::query()
            ->where('snmp_olt_id', $olt->id)
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (SmartOltOnuRegistration $registration) => [
                'id' => $registration->id,
                'serial_number' => $registration->serial_number,
                'pon_port' => $registration->pon_port,
                'customer_name' => $registration->customer_name,
                'onu_type' => $registration->onu_type,
                'tcont_profile' => $registration->tcont_profile,
                'vlan' => $registration->vlan,
                'wan_mode' => $registration->wan_mode,
                'status' => $registration->status,
                'cli_script' => $registration->cli_script,
                'created_at' => $registration->created_at?->toIso8601String(),
            ]);

        return Inertia::render('SmartOlt/Registrations', [
            'olt' => $this->serializeOlt($olt),
            'registrations' => $registrations,
        ]);
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
     * @return array<string, mixed>
     */
    private function validatedProvisioning(Request $request): array
    {
        return $request->validate([
            'serial_number' => ['required', 'string', 'max:64'],
            'slot' => ['required', 'integer', 'between:1,255'],
            'port' => ['required', 'integer', 'between:1,255'],
            'onu_id' => ['required', 'integer', 'between:1,4096'],
            'oid_index' => ['nullable', 'string', 'max:191'],
            'customer_name' => ['required', 'string', 'max:191'],
            'onu_type' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._-]+$/', $this->activeProfileRule('onu_type')],
            'tcont_profile' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._-]+$/', $this->activeProfileRule('tcont')],
            'vlan' => ['required', 'integer', 'between:1,4094'],
            'vlan_profile' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._-]+$/', $this->activeProfileRule('vlan')],
            'service_name' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._-]+$/'],
            'wan_mode' => ['required', Rule::in(['pppoe', 'dhcp', 'static'])],
            'pppoe_username' => ['nullable', 'string', 'max:120'],
            'pppoe_password' => ['nullable', 'string', 'max:120'],
            'ip_profile' => ['nullable', 'required_if:wan_mode,static', 'string', 'max:120', 'regex:/^[A-Za-z0-9._-]+$/', $this->activeProfileRule('ip')],
            'static_ip' => ['nullable', 'required_if:wan_mode,static', 'ip'],
            'static_netmask' => ['nullable', 'required_if:wan_mode,static', 'integer', 'between:1,32'],
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

    /**
     * @return array<string, mixed>
     */
    private function serializeUnconfiguredSnapshot(SnmpOlt $olt): array
    {
        $snapshot = data_get($olt->last_test_result ?? [], 'unconfigured_onus', []);

        return [
            'ok' => (bool) data_get($snapshot, 'ok', false),
            'onus' => data_get($snapshot, 'onus', []),
            'count' => data_get($snapshot, 'count', 0),
            'latency_ms' => data_get($snapshot, 'latency_ms'),
            'error' => data_get($snapshot, 'error'),
            'refreshed_at' => data_get($snapshot, 'refreshed_at'),
        ];
    }

    private function suggestNextOnuId(SnmpOlt $olt, int $slot, int $port): int
    {
        if ($slot < 1 || $port < 1) {
            return 1;
        }

        $onus = data_get($olt->last_test_result ?? [], "port_onus.{$slot}_{$port}.onus", []);
        $used = collect($onus)->pluck('onu_id')->map(fn ($id) => (int) $id)->flip();

        for ($id = 1; $id <= 4096; $id++) {
            if (! isset($used[$id])) {
                return $id;
            }
        }

        return 4096;
    }

    private function firstProfileName(string $type, string $fallback): string
    {
        return SmartOltProfile::query()
            ->where('profile_type', $type)
            ->where('is_active', true)
            ->orderBy('name')
            ->value('name') ?? $fallback;
    }

    private function activeProfileRule(string $type): mixed
    {
        return Rule::exists('smartolt_profiles', 'name')
            ->where('profile_type', $type)
            ->where('is_active', true);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function hydrateProvisioningProfiles(array $data): array
    {
        if (($data['vlan_profile'] ?? null) === null || $data['vlan_profile'] === '') {
            return $data;
        }

        $profile = SmartOltProfile::query()
            ->where('profile_type', 'vlan')
            ->where('is_active', true)
            ->where('name', $data['vlan_profile'])
            ->first();

        if ($profile) {
            $data['vlan'] = $profile->vlan;
            $data['service_name'] = $profile->name;
        }

        return $data;
    }
}
