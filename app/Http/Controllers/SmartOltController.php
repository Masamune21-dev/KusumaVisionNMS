<?php

namespace App\Http\Controllers;

use App\Models\PollingEvent;
use App\Models\SmartOltOnuRegistration;
use App\Models\SmartOltProfile;
use App\Models\SnmpOlt;
use App\Services\Snmp\OltSnmpClient;
use App\Services\ZteCardUplinkService;
use App\Services\ZteCliProvisioningExecutor;
use App\Services\ZteOnuDetailService;
use App\Services\ZteOnuReconfigureScriptBuilder;
use App\Services\ZteOnuRunningConfigService;
use App\Services\ZteProvisioningScriptBuilder;
use App\Services\ZteRemoteOnuService;
use App\Support\CliOutputSanitizer;
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

    public function detail(SnmpOlt $olt, ZteCardUplinkService $service): Response
    {
        return Inertia::render('SmartOlt/Detail', [
            'olt' => $this->serializeOlt($olt),
            'snapshot' => $this->serializeSnapshot($olt),
            'cards' => $service->getCardStatus($olt),
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
        $interfaceDetails = $service->getStoredInterfaceDetails($olt);
        $uplinkInterfaces = $service->getStoredUplinkInterfaces($olt);
        $vlansByInterface = collect($interfaceDetails)
            ->where('interface_type', 'uplink')
            ->mapWithKeys(fn (array $row) => [$row['interface'] => $row['tagged_vlans'] ?? []])
            ->all();

        return Inertia::render('SmartOlt/PortManager', [
            'olt' => $this->serializeOlt($olt),
            'uplink_interfaces' => $uplinkInterfaces,
            'vlans_by_interface' => $vlansByInterface,
            'interface_details' => $interfaceDetails,
        ]);
    }

    public function refreshDashboard(SnmpOlt $olt, ZteCardUplinkService $service, OltSnmpClient $snmpClient): RedirectResponse
    {
        try {
            $cards = $service->refreshCardStatus($olt);
            $interfaces = $service->refreshInterfaceDetails($olt, $cards);

            // Refresh GPON port list from SNMP so Port Manager shows up-to-date GPON ports.
            // Non-fatal: CLI data is already saved; SNMP failure just leaves the old snapshot.
            try {
                $ports = $snmpClient->gponPorts($olt);
                $snapshot = $olt->last_test_result ?? [];
                data_set($snapshot, 'ports', $ports);
                $olt->forceFill(['last_test_result' => $snapshot])->save();
                $gponCount = count($ports);
            } catch (\Throwable) {
                $gponCount = null;
            }

            $msg = sprintf('Data berhasil diperbarui. %s card, %s interface uplink', count($cards), count($interfaces));
            if ($gponCount !== null) {
                $msg .= sprintf(', %s GPON port', $gponCount);
            }

            return redirect()
                ->route('smartolt.port-manager', $olt)
                ->with('success', $msg.'.');
        } catch (\Throwable $e) {
            return redirect()
                ->route('smartolt.port-manager', $olt)
                ->with('error', 'Refresh gagal: '.$e->getMessage());
        }
    }

    public function refreshDashboardInterface(Request $request, SnmpOlt $olt, ZteCardUplinkService $service): RedirectResponse
    {
        $data = $request->validate([
            'interface' => ['required', 'string', 'regex:/^gpon(?:-olt)?_\d+\/\d+\/\d+$/'],
        ]);

        try {
            $service->refreshGponInterface($olt, $data['interface']);

            return redirect()
                ->route('smartolt.port-manager', $olt)
                ->with('success', "Detail {$data['interface']} berhasil diperbarui dari OLT.");
        } catch (\Throwable $e) {
            return redirect()
                ->route('smartolt.port-manager', $olt)
                ->with('error', "Refresh {$data['interface']} gagal: ".$e->getMessage());
        }
    }

    public function refreshHardware(SnmpOlt $olt, ZteCardUplinkService $service): RedirectResponse
    {
        try {
            $cards = $service->refreshCardStatus($olt);

            return redirect()
                ->route('smartolt.detail', $olt)
                ->with('success', sprintf('Status hardware berhasil diperbarui. %s card ditemukan.', count($cards)));
        } catch (\Throwable $e) {
            return redirect()
                ->route('smartolt.detail', $olt)
                ->with('error', 'Refresh hardware gagal: '.$e->getMessage());
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

    public function portOnus(Request $request, SnmpOlt $olt, int $slot, int $port): Response
    {
        return Inertia::render('SmartOlt/PortOnus', [
            'olt' => $this->serializeOlt($olt),
            'slot' => $slot,
            'port' => $port,
            'snapshot' => $this->serializePortOnusSnapshot($olt, $slot, $port),
            'initial_search' => (string) $request->query('q', ''),
            'focus_onu_id' => $request->query('focus') !== null ? (int) $request->query('focus') : null,
        ]);
    }

    public function onuMonitor(): Response
    {
        $olts = SnmpOlt::query()->orderBy('name')->get();

        $onus = [];
        $refreshedAt = [];

        foreach ($olts as $olt) {
            $portOnus = data_get($olt->last_test_result ?? [], 'port_onus', []);
            if (! is_array($portOnus)) {
                continue;
            }

            foreach ($portOnus as $entry) {
                $entryRefreshed = data_get($entry, 'refreshed_at');
                if ($entryRefreshed && (! isset($refreshedAt[$olt->id]) || $entryRefreshed > $refreshedAt[$olt->id])) {
                    $refreshedAt[$olt->id] = $entryRefreshed;
                }

                foreach (data_get($entry, 'onus', []) as $onu) {
                    $onus[] = [
                        'olt_id' => $olt->id,
                        'olt_name' => $olt->name,
                        'slot' => (int) ($onu['slot'] ?? 0),
                        'port' => (int) ($onu['port'] ?? 0),
                        'onu_id' => (int) ($onu['onu_id'] ?? 0),
                        'interface' => $onu['interface'] ?? null,
                        'serial_number' => $onu['serial_number'] ?? null,
                        'type_name' => $onu['type_name'] ?? null,
                        'name' => $onu['name'] ?? null,
                        'description' => $onu['description'] ?? null,
                        'admin_state' => $onu['admin_state'] ?? 'unknown',
                        'phase_state' => $onu['phase_state'] ?? 'Unknown',
                        'online' => (bool) ($onu['online'] ?? false),
                        'last_down_cause' => $onu['last_down_cause'] ?? null,
                        'rx_power_dbm' => $onu['rx_power_dbm'] ?? null,
                        'rx_power_label' => $onu['rx_power_label'] ?? null,
                    ];
                }
            }
        }

        usort(
            $onus,
            fn (array $a, array $b) => [$a['olt_name'], $a['slot'], $a['port'], $a['onu_id']]
                <=> [$b['olt_name'], $b['slot'], $b['port'], $b['onu_id']],
        );

        return Inertia::render('SmartOlt/OnuMonitor', [
            'olts' => $olts->map(fn (SnmpOlt $olt) => [
                'id' => $olt->id,
                'name' => $olt->name,
                'ip' => $olt->ip,
            ])->values(),
            'onus' => $onus,
            'refreshed_at' => $refreshedAt,
        ]);
    }

    public function refreshOnuMonitor(SnmpOlt $olt, OltSnmpClient $client): RedirectResponse
    {
        $back = redirect()->route('monitoring.onu', ['olt_id' => $olt->id]);

        try {
            $ports = $client->gponPorts($olt);
            $onus = $client->registeredOnus($olt, $ports);

            $rxError = null;
            try {
                $onus = $client->mergeOnuRxPowers($onus, $client->onuRxPowers($olt));
            } catch (\Throwable $exception) {
                $rxError = $exception->getMessage();
            }

            $byPort = [];
            foreach ($onus as $onu) {
                $byPort["{$onu['slot']}_{$onu['port']}"][] = $onu;
            }

            $snapshot = $olt->last_test_result ?? [];
            data_set($snapshot, 'ports', $ports);
            $now = now()->toIso8601String();

            // Index ports by slot_port so ONUs that resolved via if-index fallback still get a row.
            $portRows = [];
            foreach ($ports as $portRow) {
                $portRows["{$portRow['slot']}_{$portRow['port']}"] = $portRow;
            }

            foreach (array_keys($byPort + $portRows) as $key) {
                $portOnus = $byPort[$key] ?? [];
                $portRow = $portRows[$key] ?? null;

                data_set($snapshot, "port_onus.{$key}", [
                    'ok' => true,
                    'slot' => (int) ($portOnus[0]['slot'] ?? $portRow['slot'] ?? 0),
                    'port' => (int) ($portOnus[0]['port'] ?? $portRow['port'] ?? 0),
                    'if_index' => (int) ($portOnus[0]['if_index'] ?? $portRow['if_index'] ?? 0),
                    'port_row' => $portRow,
                    'onus' => $portOnus,
                    'count' => count($portOnus),
                    'rx_power' => [
                        'ok' => $rxError === null,
                        'source' => 'snmp',
                        'count' => 0,
                        'error' => $rxError,
                    ],
                    'error' => null,
                    'refreshed_at' => $now,
                ]);
            }

            $olt->forceFill(['last_test_result' => $snapshot])->save();

            $message = sprintf('Scan ONU OK. %s ONU ditemukan di %s.', count($onus), $olt->name);
            if ($rxError !== null) {
                $message .= ' (RX power gagal dibaca)';
            }

            return $back->with('success', $message);
        } catch (\Throwable $exception) {
            return $back->with('error', 'Scan ONU gagal: '.$exception->getMessage());
        }
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
        $suggestedOnuId = (int) $request->query('suggested_onu_id');

        return Inertia::render('SmartOlt/RegisterOnu', [
            'olt' => $this->serializeOlt($olt),
            'profiles' => SmartOltProfileController::profileOptions($olt),
            'defaults' => [
                'serial_number' => (string) $request->query('sn', ''),
                'slot' => $slot ?: null,
                'port' => $port ?: null,
                'onu_id' => $this->suggestNextOnuId($olt, $slot, $port, $suggestedOnuId),
                'oid_index' => (string) $request->query('oid_index', ''),
                'customer_name' => '',
                'onu_type' => $this->firstProfileName($olt, 'onu_type', 'ALL-ONT'),
                'tcont_profile' => $this->firstProfileName($olt, 'tcont', 'SERVER'),
                'vlan' => 100,
                'vlan_profile' => $this->firstProfileName($olt, 'vlan', 'ServiceName'),
                'service_name' => $this->firstProfileName($olt, 'vlan', 'ServiceName'),
                'service_mode' => 'vlanpri',
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

        PollingEvent::log(
            $olt->id,
            PollingEvent::KIND_OLT_TEST,
            (bool) ($result['ok'] ?? false),
            $result['error'] ?? null,
            isset($result['latency_ms']) ? (int) $result['latency_ms'] : null,
        );

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

        // Merge ke existing snapshot agar port_onus & unconfigured_onus yang sudah di-cache tidak terhapus.
        $merged = array_merge($olt->last_test_result ?? [], $result);

        $olt->forceFill([
            'last_test_result' => $merged,
            'last_tested_at' => now(),
        ])->save();

        PollingEvent::log(
            $olt->id,
            PollingEvent::KIND_OLT_POLL,
            (bool) ($result['ok'] ?? false),
            $result['error'] ?? null,
        );

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
            ->route('smartolt.unconfigured-all', ['olt_id' => $olt->id])
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
                        ? sprintf('Perintah reboot ONU %s terkirim. ONU restart 30-60 detik.', SmartOltSupport::onuInterfaceId($slot, $port, $onuId, SmartOltSupport::isC600($olt)))
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

    public function onuDetail(SnmpOlt $olt, int $slot, int $port, int $onuId, ZteOnuDetailService $service): Response
    {
        $this->assertCapability($olt, 'supports_cli_onu_detail');

        $live = $service->fetch($olt, $slot, $port, $onuId);
        $cached = $this->findCachedOnu($olt, $slot, $port, $onuId);

        return Inertia::render('SmartOlt/OnuDetail', [
            'olt' => $this->serializeOlt($olt),
            'slot' => $slot,
            'port' => $port,
            'onu_id' => $onuId,
            'interface' => SmartOltSupport::onuInterfaceId($slot, $port, $onuId, SmartOltSupport::isC600($olt)),
            'meta' => [
                'sn' => $cached['serial_number'] ?? null,
                'name' => $cached['name'] ?? null,
            ],
            'groups' => $live['groups'],
            'raw' => $live['raw'],
            'fetch_ok' => $live['ok'],
            'fetch_error' => $live['error'],
        ]);
    }

    public function configureOnuForm(SnmpOlt $olt, int $slot, int $port, int $onuId, ZteOnuRunningConfigService $service): Response
    {
        $this->assertCapability($olt, 'supports_cli_onu_configure');

        $live = $service->fetch($olt, $slot, $port, $onuId);
        $cached = $this->findCachedOnu($olt, $slot, $port, $onuId);

        return Inertia::render('SmartOlt/ConfigureOnu', [
            'olt' => $this->serializeOlt($olt),
            'slot' => $slot,
            'port' => $port,
            'onu_id' => $onuId,
            'interface' => SmartOltSupport::onuInterfaceId($slot, $port, $onuId, SmartOltSupport::isC600($olt)),
            'profiles' => SmartOltProfileController::profileOptions($olt),
            'meta' => [
                'sn' => $cached['serial_number'] ?? null,
                'name' => $cached['name'] ?? null,
            ],
            'config' => $live['config'],
            'raw' => $live['raw'],
            'fetch_ok' => $live['ok'],
            'fetch_error' => $live['error'],
        ]);
    }

    public function configureOnuPreview(Request $request, SnmpOlt $olt, int $slot, int $port, int $onuId, ZteOnuReconfigureScriptBuilder $builder): JsonResponse
    {
        $this->assertCapability($olt, 'supports_cli_onu_configure');

        $baseline = $request->input('baseline', []);
        $target = $request->input('config', []);

        $delta = $builder->build(
            is_array($baseline) ? $baseline : [],
            is_array($target) ? $target : [],
            ['onu_iface' => SmartOltSupport::onuInterfaceId($slot, $port, $onuId, SmartOltSupport::isC600($olt))],
        );

        return response()->json([
            'script' => $delta['script'],
            'changes' => $delta['changes'],
        ]);
    }

    public function configureOnuApply(Request $request, SnmpOlt $olt, int $slot, int $port, int $onuId, ZteOnuReconfigureScriptBuilder $builder, ZteCliProvisioningExecutor $executor): RedirectResponse
    {
        $this->assertCapability($olt, 'supports_cli_onu_configure');

        $target = $this->validatedReconfigure($request);
        $baseline = $request->input('baseline', []);
        $iface = SmartOltSupport::onuInterfaceId($slot, $port, $onuId, SmartOltSupport::isC600($olt));

        $delta = $builder->build(is_array($baseline) ? $baseline : [], $target, ['onu_iface' => $iface]);

        $back = redirect()->route('smartolt.onu.configure', [$olt, $slot, $port, $onuId]);

        if ($delta['script'] === '') {
            return $back->with('error', 'Tidak ada perubahan config untuk di-apply.');
        }

        $cached = $this->findCachedOnu($olt, $slot, $port, $onuId);
        $base = [
            'snmp_olt_id' => $olt->id,
            'serial_number' => (string) ($cached['serial_number'] ?? ''),
            'slot' => $slot,
            'port' => $port,
            'onu_id' => $onuId,
            'pon_port' => $iface,
            'customer_name' => (string) ($target['name'] ?? ($cached['name'] ?? '')),
            'vlan' => $this->resolvePrimaryVlan($target),
            'vlan_profile' => $target['vlan_profile'] ?? null,
            'wan_mode' => in_array($target['wan_mode'] ?? '', ['pppoe', 'dhcp', 'static'], true) ? $target['wan_mode'] : 'pppoe',
            'pppoe_username' => $target['pppoe_username'] ?? null,
            'ip_profile' => $target['ip_profile'] ?? null,
            'static_ip' => $target['static_ip'] ?? null,
            'static_netmask' => isset($target['static_mask_length']) ? (string) $target['static_mask_length'] : null,
            'tr069_enabled' => (bool) ($target['tr069'] ?? false),
            'acs_url' => $target['acs_url'] ?? null,
            'acs_username' => $target['acs_username'] ?? null,
            'remote_ont_enabled' => (bool) ($target['remote_ont'] ?? false),
            'remote_ont_id' => $target['remote_ont_id'] ?? null,
            'remote_ont_mode' => $target['remote_ont_mode'] ?? null,
            'remote_ont_protocol' => $target['remote_ont_protocol'] ?? null,
            'cli_script' => $delta['script'],
            'created_by' => $request->user()?->id,
        ];

        try {
            $result = $executor->execute($olt, $delta['script']);
            $output = CliOutputSanitizer::clean($result['output']);
            $error = $result['error'] === null ? null : CliOutputSanitizer::clean($result['error']);

            SmartOltOnuRegistration::create([
                ...$base,
                'execution_output' => $output,
                'execution_error' => $error,
                'executed_at' => now(),
                'executed_by' => $request->user()?->id,
                'status' => $result['ok'] ? 'reconfigured' : 'reconfig_failed',
            ]);

            return $back->with(
                $result['ok'] ? 'success' : 'error',
                $result['ok']
                    ? 'Konfigurasi ONU berhasil di-apply ke OLT.'
                    : 'Apply konfigurasi selesai dengan indikasi error: '.$error,
            );
        } catch (\Throwable $exception) {
            $error = CliOutputSanitizer::clean($exception->getMessage());

            SmartOltOnuRegistration::create([
                ...$base,
                'execution_error' => $error,
                'executed_at' => now(),
                'executed_by' => $request->user()?->id,
                'status' => 'reconfig_failed',
            ]);

            return $back->with('error', 'Apply konfigurasi gagal: '.$error);
        }
    }

    public function storeOnu(Request $request, SnmpOlt $olt, ZteProvisioningScriptBuilder $builder): RedirectResponse
    {
        $data = $this->hydrateProvisioningProfiles($olt, $this->validatedProvisioning($request, $olt));
        $data['is_c600'] = SmartOltSupport::isC600($olt);
        $script = $builder->build($data);

        SmartOltOnuRegistration::create([
            ...$data,
            'snmp_olt_id' => $olt->id,
            'pon_port' => SmartOltSupport::onuInterfaceId(
                (int) $data['slot'],
                (int) $data['port'],
                (int) $data['onu_id'],
                SmartOltSupport::isC600($olt),
            ),
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

        if ($registration->status === 'executed') {
            return redirect()
                ->route('smartolt.registrations', $olt)
                ->with('success', 'Provisioning script ini sudah teregister di OLT.');
        }

        try {
            $result = $executor->execute($olt, $registration->cli_script);
            $output = CliOutputSanitizer::clean($result['output']);
            $error = $result['error'] === null ? null : CliOutputSanitizer::clean($result['error']);

            $registration->update([
                'status' => $result['ok'] ? 'executed' : 'failed',
                'execution_output' => $output,
                'execution_error' => $error,
                'executed_at' => now(),
                'executed_by' => $request->user()?->id,
            ]);

            return redirect()
                ->route('smartolt.registrations', $olt)
                ->with(
                    $result['ok'] ? 'success' : 'error',
                    $result['ok']
                        ? 'Provisioning script berhasil dieksekusi ke OLT.'
                        : 'Provisioning script selesai dengan indikasi error: '.$error,
                );
        } catch (\Throwable $exception) {
            $error = CliOutputSanitizer::clean($exception->getMessage());

            $registration->update([
                'status' => 'failed',
                'execution_error' => $error,
                'executed_at' => now(),
                'executed_by' => $request->user()?->id,
            ]);

            return redirect()
                ->route('smartolt.registrations', $olt)
                ->with('error', 'Eksekusi provisioning gagal: '.$error);
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
            'service_mode' => ['nullable', Rule::in(['vlanpri', 'transparent'])],
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
            'remote_ont_id' => ['nullable', 'required_if:remote_ont_enabled,true,1', 'integer', 'between:1,4095'],
            'remote_ont_mode' => ['nullable', 'required_if:remote_ont_enabled,true,1', Rule::in(['forward', 'discard'])],
            'remote_ont_protocol' => ['nullable', 'required_if:remote_ont_enabled,true,1', Rule::in(['web', 'telnet', 'ssh', 'ftp', 'tftp', 'snmp'])],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedReconfigure(Request $request): array
    {
        $validated = $request->validate([
            'config' => ['required', 'array'],
            'config.name' => ['required', 'string', 'max:191'],
            'config.tconts' => ['array'],
            'config.tconts.*.id' => ['required', 'integer', 'between:1,8'],
            'config.tconts.*.name' => ['nullable', 'string', 'max:64'],
            'config.tconts.*.profile' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._-]+$/'],
            'config.tconts.*.gap' => ['nullable', 'string', 'max:32'],
            'config.gemports' => ['array'],
            'config.gemports.*.id' => ['required', 'integer', 'between:1,128'],
            'config.gemports.*.name' => ['nullable', 'string', 'max:64'],
            'config.gemports.*.tcont' => ['nullable', 'integer', 'between:1,8'],
            'config.gemports.*.traffic_up' => ['nullable', 'string', 'max:32'],
            'config.gemports.*.traffic_down' => ['nullable', 'string', 'max:32'],
            'config.service_ports' => ['array'],
            'config.service_ports.*.id' => ['required', 'integer', 'between:1,128'],
            'config.service_ports.*.vport' => ['nullable', 'integer', 'between:1,128'],
            'config.service_ports.*.user_vlan' => ['nullable', 'integer', 'between:1,4094'],
            'config.service_ports.*.vlan' => ['nullable', 'integer', 'between:1,4094'],
            'config.services' => ['array'],
            'config.services.*.name' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._-]+$/'],
            'config.services.*.type' => ['nullable', 'string', 'max:32'],
            'config.services.*.mode' => ['nullable', Rule::in(['vlanpri', 'transparent'])],
            'config.services.*.gem' => ['nullable', 'integer', 'between:1,128'],
            'config.services.*.cos' => ['nullable', 'integer', 'between:0,7'],
            'config.services.*.vlan' => ['nullable', 'integer', 'between:1,4094'],
            'config.vlan_ports' => ['array'],
            'config.vlan_ports.*.port_type' => ['nullable', Rule::in(['eth', 'wifi'])],
            'config.vlan_ports.*.port' => ['nullable', 'integer', 'between:1,8'],
            'config.vlan_ports.*.mode' => ['nullable', 'string', 'max:32'],
            'config.vlan_ports.*.vlan' => ['nullable', 'integer', 'between:1,4094'],
            'config.vlan_ports.*.def_vlan' => ['nullable', 'integer', 'between:1,4094'],
            'config.vlan_ports.*.priority' => ['nullable', 'integer', 'between:0,7'],
            'config.wan_services' => ['array'],
            'config.wan_services.*.id' => ['required', 'integer', 'between:1,128'],
            'config.wan_services.*.ethuni' => ['nullable', 'string', 'max:32'],
            'config.wan_services.*.ssid' => ['nullable', 'string', 'max:64'],
            'config.wan_services.*.service' => ['nullable', 'string', 'max:64'],
            'config.wan_services.*.mvlan' => ['nullable', 'string', 'max:32'],
            'config.wan_services.*.host' => ['nullable', 'string', 'max:32'],
            'config.wan_mode' => ['required', Rule::in(['none', 'pppoe', 'dhcp', 'static'])],
            'config.vlan_profile' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._-]+$/'],
            'config.pppoe_username' => ['nullable', 'string', 'max:120'],
            'config.pppoe_password' => ['nullable', 'string', 'max:120'],
            'config.ip_profile' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._-]+$/'],
            'config.static_ip' => ['nullable', 'ip'],
            'config.static_mask_length' => ['nullable', 'integer', 'between:1,32'],
            'config.tr069' => ['boolean'],
            'config.acs_url' => ['nullable', 'url', 'max:255'],
            'config.acs_username' => ['nullable', 'string', 'max:120'],
            'config.acs_password' => ['nullable', 'string', 'max:120'],
            'config.remote_ont' => ['boolean'],
            'config.remote_ont_id' => ['nullable', 'integer', 'between:1,4095'],
            'config.remote_ont_mode' => ['nullable', Rule::in(['forward', 'discard'])],
            'config.remote_ont_protocol' => ['nullable', Rule::in(['web', 'telnet', 'ssh', 'ftp', 'tftp', 'snmp'])],
        ]);

        return $validated['config'];
    }

    /**
     * @return array<string, mixed>
     */
    private function findCachedOnu(SnmpOlt $olt, int $slot, int $port, int $onuId): array
    {
        $onus = data_get($olt->last_test_result ?? [], "port_onus.{$slot}_{$port}.onus", []);

        foreach ($onus as $onu) {
            if ((int) ($onu['onu_id'] ?? 0) === $onuId) {
                return $onu;
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function resolvePrimaryVlan(array $config): int
    {
        foreach (($config['service_ports'] ?? []) as $row) {
            if ((int) ($row['vlan'] ?? 0) > 0) {
                return (int) $row['vlan'];
            }
        }

        foreach (($config['services'] ?? []) as $row) {
            if ((int) ($row['vlan'] ?? 0) > 0) {
                return (int) $row['vlan'];
            }
        }

        return 0;
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

    private function suggestNextOnuId(SnmpOlt $olt, int $slot, int $port, int $fallback = 1): int
    {
        if ($slot < 1 || $port < 1) {
            return $fallback >= 1 && $fallback <= 4096 ? $fallback : 1;
        }

        $onus = data_get($olt->last_test_result ?? [], "port_onus.{$slot}_{$port}.onus", []);

        if ($onus === []) {
            return $fallback >= 1 && $fallback <= 4096 ? $fallback : 1;
        }

        $used = array_fill_keys(
            array_filter(
                array_map('intval', array_column($onus, 'onu_id')),
                fn (int $id) => $id > 0,
            ),
            true,
        );

        for ($id = 1; $id <= 4096; $id++) {
            if (! isset($used[$id])) {
                return $id;
            }
        }

        return $fallback >= 1 && $fallback <= 4096 ? $fallback : 1;
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
