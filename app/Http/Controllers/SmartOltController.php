<?php

namespace App\Http\Controllers;

use App\Models\SmartOltOnuRegistration;
use App\Models\SmartOltProfile;
use App\Models\SnmpOlt;
use App\Services\Snmp\OltSnmpClient;
use App\Services\ZteCardUplinkService;
use App\Services\ZteCliProvisioningExecutor;
use Illuminate\Support\Facades\Cache;
use App\Services\ZteProvisioningScriptBuilder;
use App\Services\ZteRemoteOnuService;
use App\Support\SmartOltSupport;
use Illuminate\Http\JsonResponse;
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
                'poll_interval_minutes' => 5,
                'rx_poll_interval_minutes' => 5,
            ],
        ]);
    }

    public function detail(SnmpOlt $olt): Response
    {
        return Inertia::render('SmartOlt/Detail', [
            'olt' => $this->serializeOlt($olt),
            'snapshot' => $this->serializeSnapshot($olt),
            'cards' => Cache::get("olt:{$olt->id}:cards", []),
        ]);
    }

    public function gponPorts(SnmpOlt $olt): Response
    {
        return Inertia::render('SmartOlt/GponPorts', [
            'olt' => $this->serializeOlt($olt),
            'snapshot' => $this->serializeSnapshot($olt),
        ]);
    }

    public function unconfiguredGlobal(Request $request): Response
    {
        $olts = SnmpOlt::query()
            ->orderBy('name')
            ->get()
            ->map(fn (SnmpOlt $olt) => $this->serializeOlt($olt));

        $selectedOlt = null;
        $snapshot = null;

        if ($id = $request->query('olt_id')) {
            $olt = SnmpOlt::find((int) $id);
            if ($olt) {
                $selectedOlt = $this->serializeOlt($olt);
                $snapshot = $this->serializeUnconfiguredSnapshot($olt);
            }
        }

        return Inertia::render('SmartOlt/UnconfiguredGlobal', [
            'olts' => $olts,
            'selected_olt' => $selectedOlt,
            'snapshot' => $snapshot,
        ]);
    }

    public function dashboard(SnmpOlt $olt, ZteCardUplinkService $service): Response
    {
        $cards = Cache::get("olt:{$olt->id}:cards", []);

        if (empty($cards)) {
            try {
                $cards = $service->getCardStatus($olt);
            } catch (\Throwable) {
                $cards = [];
            }
        }

        $uplinkInterfaces = $service->discoverUplinkInterfaces($cards);

        $vlansByInterface = [];
        foreach ($uplinkInterfaces as $iface) {
            try {
                $vlansByInterface[$iface['interface']] = $service->getVlanMapping($olt, $iface['interface'])['tagged_vlans'];
            } catch (\Throwable) {
                $vlansByInterface[$iface['interface']] = [];
            }
        }

        return Inertia::render('SmartOlt/PortManager', [
            'olt' => $this->serializeOlt($olt),
            'uplink_interfaces' => $uplinkInterfaces,
            'vlans_by_interface' => $vlansByInterface,
        ]);
    }

    public function refreshDashboard(SnmpOlt $olt, ZteCardUplinkService $service): RedirectResponse
    {
        try {
            $cards = $service->refreshCardStatus($olt);
            $uplinkInterfaces = $service->discoverUplinkInterfaces($cards);

            foreach ($uplinkInterfaces as $iface) {
                try {
                    $service->refreshVlanMapping($olt, $iface['interface']);
                } catch (\Throwable) {
                    //
                }
            }

            return redirect()
                ->route('smartolt.dashboard', $olt)
                ->with('success', 'Data VLAN berhasil diperbarui dari OLT.');
        } catch (\Throwable $e) {
            return redirect()
                ->route('smartolt.dashboard', $olt)
                ->with('error', 'Refresh gagal: '.$e->getMessage());
        }
    }

    public function dashboardTraffic(Request $request, SnmpOlt $olt, ZteCardUplinkService $service): JsonResponse
    {
        $interface = $request->query('interface', '');

        if (! preg_match('/^(?:xgei|gei)_\d+\/\d+\/\d+$/', $interface)) {
            return response()->json(['error' => 'Parameter interface tidak valid.'], 422);
        }

        try {
            return response()->json($service->getUplinkInfo($olt, $interface));
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function storeDashboardVlan(Request $request, SnmpOlt $olt, ZteCardUplinkService $service): JsonResponse
    {
        $data = $request->validate([
            'interface' => ['required', 'string', 'regex:/^(?:xgei|gei)_\d+\/\d+\/\d+$/'],
            'vlan_id' => ['required', 'integer', 'min:1', 'max:4094'],
        ]);

        try {
            $result = $service->addAndTagVlan($olt, $data['interface'], (int) $data['vlan_id']);

            return response()->json([
                'ok' => $result['ok'],
                'message' => $result['ok']
                    ? "VLAN {$data['vlan_id']} berhasil ditambahkan ke {$data['interface']}."
                    : 'Eksekusi CLI selesai dengan error: '.($result['error'] ?? 'unknown'),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }
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

    public function registerOnuForm(Request $request, SnmpOlt $olt, ZteCliProvisioningExecutor $executor): Response
    {
        $slot = (int) $request->query('slot');
        $port = (int) $request->query('port');
        $suggestedOnuId = (int) $request->query('suggested_onu_id');

        return Inertia::render('SmartOlt/RegisterOnu', [
            'olt' => $this->serializeOlt($olt),
            'profiles' => SmartOltProfileController::profileOptions($olt),
            'defaults' => [
                'serial_number' => (string) $request->query('sn', ''),
                'slot' => $slot ?: null,
                'port' => $port ?: null,
                'onu_id' => $this->suggestNextOnuId($olt, $slot, $port, $suggestedOnuId, $executor),
                'oid_index' => (string) $request->query('oid_index', ''),
                'customer_name' => '',
                'onu_type' => $this->firstProfileName($olt, 'onu_type', 'ALL-ONT'),
                'tcont_profile' => $this->firstProfileName($olt, 'tcont', 'SERVER'),
                'vlan' => 100,
                'vlan_profile' => $this->firstProfileName($olt, 'vlan', 'ServiceName'),
                'service_name' => $this->firstProfileName($olt, 'vlan', 'ServiceName'),
                'wan_mode' => 'pppoe',
                'pppoe_username' => '',
                'pppoe_password' => '',
                'ip_profile' => $this->firstProfileName($olt, 'ip', 'INTERNET'),
                'static_ip' => '',
                'static_netmask' => '24',
                'tr069_enabled' => false,
                'acs_url' => 'http://acs.bmkv.net:7547',
                'acs_username' => 'cms',
                'acs_password' => 'kusuma123!',
                'remote_ont_enabled' => false,
                'remote_ont_id' => 1,
                'remote_ont_mode' => 'forward',
                'remote_ont_protocol' => 'web',
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

    public function rebootOnu(SnmpOlt $olt, int $slot, int $port, int $onuId, ZteRemoteOnuService $remote): RedirectResponse
    {
        $this->assertCapability($olt, 'supports_reboot');

        try {
            $result = $remote->reboot($olt, $slot, $port, $onuId);

            return redirect()
                ->route('smartolt.port-onus', [$olt, $slot, $port])
                ->with(
                    $result['ok'] ? 'success' : 'error',
                    $result['ok']
                        ? sprintf('Perintah reboot ONU gpon-onu_1/%d/%d:%d terkirim. ONU restart 30-60 detik.', $slot, $port, $onuId)
                        : 'Reboot ONU selesai dengan indikasi error: '.$result['error'],
                );
        } catch (\Throwable $exception) {
            return redirect()
                ->route('smartolt.port-onus', [$olt, $slot, $port])
                ->with('error', 'Reboot ONU gagal: '.$exception->getMessage());
        }
    }

    public function setOnuState(Request $request, SnmpOlt $olt, int $slot, int $port, int $onuId, ZteRemoteOnuService $remote): RedirectResponse
    {
        $this->assertCapability($olt, 'supports_onu_toggle');

        $data = $request->validate([
            'active' => ['required', 'boolean'],
            'if_index' => ['nullable', 'integer'],
        ]);

        $active = (bool) $data['active'];
        $ifIndex = $this->resolveOnuIfIndex($olt, $slot, $port, $onuId, $data['if_index'] ?? null);

        try {
            $remote->setActiveState($olt, $ifIndex, $onuId, $active);
            $this->mutateCachedOnu($olt, $slot, $port, $onuId, function (array $onu) use ($active) {
                $onu['admin_state_code'] = $active ? 1 : 2;
                $onu['admin_state'] = $active ? 'active' : 'disabled';

                return $onu;
            });

            return redirect()
                ->route('smartolt.port-onus', [$olt, $slot, $port])
                ->with('success', $active ? 'ONU berhasil di-enable.' : 'ONU berhasil di-disable.');
        } catch (\Throwable $exception) {
            return redirect()
                ->route('smartolt.port-onus', [$olt, $slot, $port])
                ->with('error', 'Ubah status ONU gagal: '.$exception->getMessage());
        }
    }

    public function updateOnuInfo(Request $request, SnmpOlt $olt, int $slot, int $port, int $onuId, ZteRemoteOnuService $remote): RedirectResponse
    {
        $this->assertCapability($olt, 'supports_onu_info_write');

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:191'],
            'if_index' => ['nullable', 'integer'],
        ]);

        $name = ($data['name'] ?? '') !== '' ? $data['name'] : null;
        $description = ($data['description'] ?? '') !== '' ? $data['description'] : null;

        if ($name === null && $description === null) {
            return redirect()
                ->route('smartolt.port-onus', [$olt, $slot, $port])
                ->with('error', 'Isi minimal nama atau deskripsi ONU.');
        }

        $ifIndex = $this->resolveOnuIfIndex($olt, $slot, $port, $onuId, $data['if_index'] ?? null);

        try {
            $remote->setInfo($olt, $ifIndex, $onuId, $name, $description);
            $this->mutateCachedOnu($olt, $slot, $port, $onuId, function (array $onu) use ($name, $description) {
                if ($name !== null) {
                    $onu['name'] = $name;
                }
                if ($description !== null) {
                    $onu['description'] = $description;
                }

                return $onu;
            });

            return redirect()
                ->route('smartolt.port-onus', [$olt, $slot, $port])
                ->with('success', 'Info ONU berhasil diperbarui.');
        } catch (\Throwable $exception) {
            return redirect()
                ->route('smartolt.port-onus', [$olt, $slot, $port])
                ->with('error', 'Update info ONU gagal: '.$exception->getMessage());
        }
    }

    public function storeOnu(Request $request, SnmpOlt $olt, ZteProvisioningScriptBuilder $builder): RedirectResponse
    {
        $data = $this->hydrateProvisioningProfiles($olt, $this->validatedProvisioning($request, $olt));
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
                'execution_output' => $registration->execution_output,
                'execution_error' => $registration->execution_error,
                'created_at' => $registration->created_at?->toIso8601String(),
                'executed_at' => $registration->executed_at?->toIso8601String(),
            ]);

        return Inertia::render('SmartOlt/Registrations', [
            'olt' => $this->serializeOlt($olt),
            'registrations' => $registrations,
        ]);
    }

    public function executeRegistration(
        Request $request,
        SnmpOlt $olt,
        SmartOltOnuRegistration $registration,
        ZteCliProvisioningExecutor $executor,
    ): RedirectResponse {
        abort_unless($registration->snmp_olt_id === $olt->id, 404);

        try {
            $result = $executor->execute($olt, $registration->cli_script);

            $registration->update([
                'status' => $result['ok'] ? 'executed' : 'failed',
                'execution_output' => $result['output'],
                'execution_error' => $result['error'],
                'executed_at' => now(),
                'executed_by' => $request->user()?->id,
            ]);

            return redirect()
                ->route('smartolt.registrations', $olt)
                ->with(
                    $result['ok'] ? 'success' : 'error',
                    $result['ok']
                        ? 'Provisioning script berhasil dieksekusi ke OLT.'
                        : 'Provisioning script selesai dengan indikasi error: '.$result['error'],
                );
        } catch (\Throwable $exception) {
            $registration->update([
                'status' => 'failed',
                'execution_error' => $exception->getMessage(),
                'executed_at' => now(),
                'executed_by' => $request->user()?->id,
            ]);

            return redirect()
                ->route('smartolt.registrations', $olt)
                ->with('error', 'Eksekusi provisioning gagal: '.$exception->getMessage());
        }
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
            'polling_enabled' => ['boolean'],
            'poll_interval_minutes' => ['nullable', 'integer', 'between:1,1440'],
            'rx_poll_interval_minutes' => ['nullable', 'integer', 'between:1,1440'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedProvisioning(Request $request, SnmpOlt $olt): array
    {
        return $request->validate([
            'serial_number' => ['required', 'string', 'max:64'],
            'slot' => ['required', 'integer', 'between:1,255'],
            'port' => ['required', 'integer', 'between:1,255'],
            'onu_id' => ['required', 'integer', 'between:1,4096'],
            'oid_index' => ['nullable', 'string', 'max:191'],
            'customer_name' => ['required', 'string', 'max:191'],
            'onu_type' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._-]+$/', $this->activeProfileRule($olt, 'onu_type')],
            'tcont_profile' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._-]+$/', $this->activeProfileRule($olt, 'tcont')],
            'vlan' => ['required', 'integer', 'between:1,4094'],
            'vlan_profile' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._-]+$/', $this->activeProfileRule($olt, 'vlan')],
            'service_name' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._-]+$/'],
            'wan_mode' => ['required', Rule::in(['pppoe', 'dhcp', 'static'])],
            'pppoe_username' => ['nullable', 'string', 'max:120'],
            'pppoe_password' => ['nullable', 'string', 'max:120'],
            'ip_profile' => ['nullable', 'required_if:wan_mode,static', 'string', 'max:120', 'regex:/^[A-Za-z0-9._-]+$/', $this->activeProfileRule($olt, 'ip')],
            'static_ip' => ['nullable', 'required_if:wan_mode,static', 'ip'],
            'static_netmask' => ['nullable', 'required_if:wan_mode,static', 'integer', 'between:1,32'],
            'tr069_enabled' => ['boolean'],
            'acs_url' => ['nullable', 'required_if:tr069_enabled,true,1', 'url', 'max:255'],
            'acs_username' => ['nullable', 'required_if:tr069_enabled,true,1', 'string', 'max:120'],
            'acs_password' => ['nullable', 'required_if:tr069_enabled,true,1', 'string', 'max:120'],
            'remote_ont_enabled' => ['boolean'],
            'remote_ont_id' => ['nullable', 'required_if:remote_ont_enabled,true,1', 'integer', 'between:1,16'],
            'remote_ont_mode' => ['nullable', 'required_if:remote_ont_enabled,true,1', Rule::in(['forward', 'discard'])],
            'remote_ont_protocol' => ['nullable', 'required_if:remote_ont_enabled,true,1', Rule::in(['web', 'telnet', 'ssh', 'ftp', 'tftp', 'snmp'])],
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
            'capabilities' => SmartOltSupport::capabilities($driver),
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
    private function serializeSnapshot(SnmpOlt $olt): array
    {
        $snapshot = $olt->last_test_result ?? [];
        $ports = collect(data_get($snapshot, 'ports', []))
            ->map(function (array $port) use ($snapshot) {
                $slot = data_get($port, 'slot');
                $portNumber = data_get($port, 'port');
                $onus = data_get($snapshot, "port_onus.{$slot}_{$portNumber}.onus", []);
                $onuSearchItems = collect($onus)
                    ->map(function (array $onu) {
                        $searchParts = [
                            $onu['interface'] ?? null,
                            $onu['serial_number'] ?? null,
                            $onu['name'] ?? null,
                            $onu['description'] ?? null,
                        ];

                        return [
                            'onu_id' => data_get($onu, 'onu_id'),
                            'interface' => data_get($onu, 'interface'),
                            'serial_number' => data_get($onu, 'serial_number'),
                            'name' => data_get($onu, 'name'),
                            'description' => data_get($onu, 'description'),
                            'online' => (bool) data_get($onu, 'online', false),
                            'search_text' => collect($searchParts)->filter()->implode(' '),
                        ];
                    })
                    ->values()
                    ->all();
                $searchText = collect($onuSearchItems)->pluck('search_text')->filter()->implode(' ');

                return [
                    ...$port,
                    'onu_count' => count($onus),
                    'online_onu_count' => collect($onus)->where('online', true)->count(),
                    'onu_search_items' => $onuSearchItems,
                    'search_text' => trim(data_get($port, 'name').' '.$slot.'/'.$portNumber.' '.$searchText),
                ];
            })
            ->values()
            ->all();

        return [
            'ok' => (bool) data_get($snapshot, 'ok', false),
            'driver' => data_get($snapshot, 'driver'),
            'latency_ms' => data_get($snapshot, 'latency_ms'),
            'system' => data_get($snapshot, 'system', []),
            'ports' => $ports,
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
            'rx_power' => data_get($snapshot, 'rx_power', []),
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

    private function assertCapability(SnmpOlt $olt, string $capability): void
    {
        $driver = SmartOltSupport::driverKey(
            $olt,
            data_get($olt->last_test_result, 'system.sys_descr'),
            data_get($olt->last_test_result, 'system.sys_object_id'),
        );

        abort_unless(
            (bool) (SmartOltSupport::capabilities($driver)[$capability] ?? false),
            403,
            'Aksi ini tidak didukung untuk driver OLT ini.',
        );
    }

    private function resolveOnuIfIndex(SnmpOlt $olt, int $slot, int $port, int $onuId, ?int $provided): int
    {
        if ($provided !== null && $provided > 0) {
            return $provided;
        }

        $onus = data_get($olt->last_test_result ?? [], "port_onus.{$slot}_{$port}.onus", []);

        foreach ($onus as $onu) {
            if ((int) ($onu['onu_id'] ?? 0) === $onuId && ($onu['if_index'] ?? null) !== null) {
                return (int) $onu['if_index'];
            }
        }

        return 0x10000000 | ($slot << 16) | ($port << 8);
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutator
     */
    private function mutateCachedOnu(SnmpOlt $olt, int $slot, int $port, int $onuId, callable $mutator): void
    {
        $snapshot = $olt->last_test_result ?? [];
        $path = "port_onus.{$slot}_{$port}.onus";
        $onus = data_get($snapshot, $path);

        if (! is_array($onus)) {
            return;
        }

        foreach ($onus as $index => $onu) {
            if ((int) ($onu['onu_id'] ?? 0) === $onuId) {
                $onus[$index] = $mutator($onu);
            }
        }

        data_set($snapshot, $path, $onus);

        $olt->forceFill(['last_test_result' => $snapshot])->save();
    }

    private function suggestNextOnuId(SnmpOlt $olt, int $slot, int $port, int $fallback = 1, ?ZteCliProvisioningExecutor $executor = null): int
    {
        if ($slot < 1 || $port < 1) {
            return $fallback >= 1 && $fallback <= 4096 ? $fallback : 1;
        }

        $used = [];

        if ($executor && $this->canUseCliForOnuState($olt)) {
            try {
                $output = $executor->execute($olt, sprintf(
                    "terminal length 0\nshow gpon onu state gpon-olt_1/%d/%d",
                    $slot,
                    $port,
                ));

                $used = $this->extractUsedOnuIdsFromStateOutput($output['output'] ?? '', $slot, $port);
            } catch (\Throwable) {
                // Fall back to cached data and query hints below.
            }
        }

        $onus = data_get($olt->last_test_result ?? [], "port_onus.{$slot}_{$port}.onus", []);

        if ($used === []) {
            $used = collect($onus)->pluck('onu_id')->map(fn ($id) => (int) $id)->all();
        }

        if ($used === []) {
            return $fallback >= 1 && $fallback <= 4096 ? $fallback : 1;
        }

        $used = array_fill_keys(array_filter(array_map('intval', $used), fn (int $id) => $id > 0), true);

        for ($id = 1; $id <= 4096; $id++) {
            if (! isset($used[$id])) {
                return $id;
            }
        }

        return $fallback >= 1 && $fallback <= 4096 ? $fallback : 1;
    }

    private function canUseCliForOnuState(SnmpOlt $olt): bool
    {
        return $olt->cli_transport === 'telnet'
            && (bool) $olt->cli_username
            && (bool) $olt->cli_password;
    }

    /**
     * @return array<int, int>
     */
    private function extractUsedOnuIdsFromStateOutput(string $output, int $slot, int $port): array
    {
        if ($output === '') {
            return [];
        }

        preg_match_all('/(\d+)\/(\d+)\/(\d+):(\d+)/', $output, $matches, PREG_SET_ORDER);

        $ids = [];

        foreach ($matches as $match) {
            if ((int) $match[2] !== $slot || (int) $match[3] !== $port) {
                continue;
            }

            $ids[] = (int) $match[4];
        }

        return array_values(array_unique($ids));
    }

    private function firstProfileName(SnmpOlt $olt, string $type, string $fallback): string
    {
        return SmartOltProfile::query()
            ->where('profile_type', $type)
            ->where('is_active', true)
            ->where(function ($query) use ($olt) {
                $query->where('snmp_olt_id', $olt->id)
                    ->orWhereNull('snmp_olt_id');
            })
            ->orderBy('name')
            ->value('name') ?? $fallback;
    }

    private function activeProfileRule(SnmpOlt $olt, string $type): mixed
    {
        return Rule::exists('smartolt_profiles', 'name')
            ->where('profile_type', $type)
            ->where('is_active', true)
            ->where(function ($query) use ($olt) {
                $query->where('snmp_olt_id', $olt->id)
                    ->orWhereNull('snmp_olt_id');
            });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function hydrateProvisioningProfiles(SnmpOlt $olt, array $data): array
    {
        if (($data['vlan_profile'] ?? null) === null || $data['vlan_profile'] === '') {
            return $data;
        }

        $profile = SmartOltProfile::query()
            ->where('profile_type', 'vlan')
            ->where('is_active', true)
            ->where(function ($query) use ($olt) {
                $query->where('snmp_olt_id', $olt->id)
                    ->orWhereNull('snmp_olt_id');
            })
            ->where('name', $data['vlan_profile'])
            ->first();

        if ($profile) {
            $data['vlan'] = $profile->vlan;
            $data['service_name'] = $profile->name;
        }

        return $data;
    }
}
