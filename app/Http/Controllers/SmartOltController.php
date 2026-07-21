<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesOltOwnership;
use App\Jobs\CopyOnusToPortJob;
use App\Jobs\Tr069BulkConfigJob;
use App\Models\AcsSetting;
use App\Models\CopyOnuTask;
use App\Models\OnuMapPin;
use App\Models\OnuRxSample;
use App\Models\PollingEvent;
use App\Models\SmartOltOnuRegistration;
use App\Models\SmartOltProfile;
use App\Models\SnmpOlt;
use App\Models\Tr069BulkTask;
use App\Services\Fcm\FcmAlarmNotifier;
use App\Services\OnuInventoryService;
use App\Services\SmartOltSnmpServiceResolver;
use App\Services\Snmp\OltSnmpClient;
use App\Services\Telegram\TelegramNotifier;
use App\Services\Zte\C600MgmtPoolService;
use App\Services\Zte\OnuRegistrationService;
use App\Services\ZteCardUplinkService;
use App\Services\ZteCliProvisioningExecutor;
use App\Services\ZteOnuDetailService;
use App\Services\ZteOnuReconfigureScriptBuilder;
use App\Services\ZteOnuRunningConfigService;
use App\Services\ZteProvisioningScriptBuilder;
use App\Services\ZteRemoteOnuService;
use App\Services\ZteTr069BulkService;
use App\Support\CliOutputSanitizer;
use App\Support\SmartOltSupport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SmartOltController extends Controller
{
    use ManagesOltOwnership;

    // Nama interface port yang valid untuk halaman detail port — mencakup ejaan C300/C320
    // (gpon-olt_1/s/p, xgei_1/s/p) DAN C600/TITAN (gpon_olt-1/s/p, xgei-1/s/p).
    private const PORT_INTERFACE_REGEX = '/^(?:gpon(?:-olt_|_olt-|_)\d+\/\d+\/\d+|(?:xgei|gei)[_-]\d+\/\d+\/\d+)$/';

    // Subset uplink saja (traffic live & tag VLAN tak berlaku untuk port GPON).
    private const UPLINK_INTERFACE_REGEX = '/^(?:xgei|gei)[_-]\d+\/\d+\/\d+$/';

    /**
     * Cache saklar alarm partner per-OLT untuk request ini (id OLT → bool), agar
     * serialisasi daftar OLT tak N+1. Hanya di-isi bila viewer seorang partner.
     *
     * @var array<int, bool>|null
     */
    private ?array $partnerAlarmMap = null;

    public function index(): Response
    {
        // Tiga tab: OLT ZTE (+ unknown), OLT C-Data, OLT HiOSO. Pisahkan berdasarkan driver.
        $rows = SnmpOlt::query()
            ->orderBy('name')
            ->get()
            ->map(fn (SnmpOlt $olt) => $this->serializeOlt($olt));

        return Inertia::render('SmartOlt/Index', [
            'olts' => $rows->reject(fn (array $row) => SmartOltSupport::isNonZte($row['driver']))->values(),
            'cdataOlts' => $rows->filter(fn (array $row) => SmartOltSupport::isCData($row['driver']))->values(),
            'hiosoOlts' => $rows->filter(fn (array $row) => SmartOltSupport::isHioso($row['driver']))->values(),
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
        // Lean per-interface status (link/admin) so the chassis can colour uplink ports live.
        $interfaces = collect($service->getStoredInterfaceDetails($olt))
            ->map(fn (array $row) => [
                'interface' => $row['interface'],
                'link_status' => $row['link_status'] ?? null,
                'admin_status' => $row['admin_status'] ?? null,
            ])
            ->values()
            ->all();

        return Inertia::render('SmartOlt/Detail', [
            'olt' => $this->serializeOlt($olt),
            'snapshot' => $this->serializeSnapshot($olt),
            'cards' => $service->getCardStatus($olt),
            'interfaces' => $interfaces,
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
            ->map(fn (SnmpOlt $olt) => $this->serializeOlt($olt))
            // OLT non-ZTE (C-Data/HiOSO) tidak punya alur provisioning di sini; hanya ZTE + unknown yang tampil.
            ->reject(fn (array $row) => SmartOltSupport::isNonZte($row['driver']))
            ->values();

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

    public function portDetail(Request $request, SnmpOlt $olt, ZteCardUplinkService $service): Response
    {
        $data = $request->validate([
            'interface' => ['required', 'string', 'regex:'.self::PORT_INTERFACE_REGEX],
        ]);

        $interface = $data['interface'];
        $type = str_starts_with($interface, 'gpon') ? 'gpon' : 'uplink';

        $detail = collect($service->getStoredInterfaceDetails($olt))
            ->firstWhere('interface', $interface);

        $slot = null;
        $port = null;
        $onuSummary = null;

        // Tail {slot}/{port} — separator is `_` on C300/C320, `-` on C600 (gpon_olt-1/3/1).
        if (preg_match('/[_-](\d+)\/(\d+)\/(\d+)$/', $interface, $m)) {
            $slot = (int) $m[2];
            $port = (int) $m[3];
        }

        if ($type === 'gpon' && $slot !== null) {
            $onus = data_get($olt->last_test_result ?? [], "port_onus.{$slot}_{$port}.onus", []);
            $onuSummary = [
                'total' => is_array($onus) ? count($onus) : 0,
                'online' => collect($onus)->where('online', true)->count(),
            ];
        }

        return Inertia::render('SmartOlt/PortDetail', [
            'olt' => $this->serializeOlt($olt),
            'interface' => $interface,
            'type' => $type,
            'slot' => $slot,
            'port' => $port,
            'card_type' => $detail['card_type'] ?? null,
            'detail' => $detail,
            'onu_summary' => $onuSummary,
        ]);
    }

    public function refreshPortDetail(Request $request, SnmpOlt $olt, ZteCardUplinkService $service): RedirectResponse
    {
        $data = $request->validate([
            'interface' => ['required', 'string', 'regex:'.self::PORT_INTERFACE_REGEX],
        ]);

        $interface = $data['interface'];
        $back = redirect()->route('smartolt.port.detail', ['olt' => $olt->id, 'interface' => $interface]);

        try {
            if (str_starts_with($interface, 'gpon')) {
                $service->refreshGponInterface($olt, $interface);
            } else {
                $service->refreshUplinkInterface($olt, $interface);
            }

            return $back->with('success', __('flash.port_detail_refreshed', ['interface' => $interface]));
        } catch (\Throwable $e) {
            return $back->with('error', __('flash.port_refresh_failed', ['interface' => $interface]).$e->getMessage());
        }
    }

    public function refreshHardware(SnmpOlt $olt, ZteCardUplinkService $service): RedirectResponse
    {
        try {
            $cards = $service->refreshCardStatus($olt);

            // Also refresh uplink interface status so the chassis can show per-port link up/down.
            // Non-fatal: card data is already saved; skip silently if no uplink cards / CLI hiccup.
            $uplinkCount = 0;
            try {
                $uplinkCount = count($service->refreshInterfaceDetails($olt, $cards));
            } catch (\Throwable) {
                //
            }

            $msg = sprintf(__('flash.hardware_ok_fmt'), count($cards));
            if ($uplinkCount > 0) {
                $msg .= sprintf(__('flash.hardware_uplink_fmt'), $uplinkCount);
            }

            return redirect()
                ->route('smartolt.detail', $olt)
                ->with('success', $msg);
        } catch (\Throwable $e) {
            return redirect()
                ->route('smartolt.detail', $olt)
                ->with('error', __('flash.hardware_refresh_failed').$e->getMessage());
        }
    }

    public function portTraffic(Request $request, SnmpOlt $olt, ZteCardUplinkService $service): JsonResponse
    {
        $interface = $request->query('interface', '');

        if (! preg_match(self::UPLINK_INTERFACE_REGEX, $interface)) {
            return response()->json(['error' => 'Parameter interface tidak valid.'], 422);
        }

        try {
            return response()->json($service->getUplinkInfo($olt, $interface));
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function storePortVlan(Request $request, SnmpOlt $olt, ZteCardUplinkService $service): JsonResponse
    {
        $data = $request->validate([
            'interface' => ['required', 'string', 'regex:'.self::UPLINK_INTERFACE_REGEX],
            'vlan_id' => ['required', 'integer', 'min:1', 'max:4094'],
        ]);

        try {
            $result = $service->addAndTagVlan($olt, $data['interface'], (int) $data['vlan_id']);

            return response()->json([
                'ok' => $result['ok'],
                'message' => $result['ok']
                    ? __('flash.vlan_added', ['vlan' => $data['vlan_id'], 'interface' => $data['interface']])
                    : __('flash.cli_error_prefix').($result['error'] ?? 'unknown'),
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
            // Target ACS untuk modal TR069 massal (tanpa password).
            'acs' => collect(AcsSetting::resolved())->only(['url', 'username'])->all(),
            'pinned_onu_ids' => OnuMapPin::query()
                ->where('snmp_olt_id', $olt->id)
                ->where('slot', $slot)
                ->where('port', $port)
                ->pluck('onu_id')
                ->all(),
        ]);
    }

    public function onuMonitor(OnuInventoryService $inventory): Response
    {
        $olts = SnmpOlt::query()->orderBy('name')->get();
        $aggregated = $inventory->collect($olts);

        return Inertia::render('SmartOlt/OnuMonitor', [
            'olts' => $olts->map(fn (SnmpOlt $olt) => [
                'id' => $olt->id,
                'name' => $olt->name,
                'ip' => $olt->ip,
            ])->values(),
            'onus' => $aggregated['onus'],
            'refreshed_at' => $aggregated['refreshed_at'],
        ]);
    }

    public function refreshOnuMonitor(SnmpOlt $olt, OltSnmpClient $client, SmartOltSnmpServiceResolver $resolver): RedirectResponse
    {
        $back = redirect()->route('monitoring.onu', ['olt_id' => $olt->id]);

        // OLT non-ZTE (C-Data/HiOSO) discan via driver resolver; tulis cache port_onus bentuk sama.
        $driver = SmartOltSupport::driverKey(
            $olt,
            data_get($olt->last_test_result, 'system.sys_descr'),
            data_get($olt->last_test_result, 'system.sys_object_id'),
        );
        if (SmartOltSupport::isNonZte($driver)) {
            return $this->refreshCdataMonitor($olt, $resolver, $back);
        }

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

            $message = sprintf(__('flash.scan_ok_fmt'), count($onus), $olt->name);
            if ($rxError !== null) {
                $message .= __('flash.rx_read_failed_suffix');
            }

            return $back->with('success', $message);
        } catch (\Throwable $exception) {
            return $back->with('error', __('flash.onu_scan_failed').$exception->getMessage());
        }
    }

    /**
     * Scan OLT C-Data untuk halaman ONU Monitoring — tulis cache port_onus (bentuk sama dgn ZTE)
     * lewat driver resolver (EPON SNMP / GPON CLI).
     */
    private function refreshCdataMonitor(SnmpOlt $olt, SmartOltSnmpServiceResolver $resolver, RedirectResponse $back): RedirectResponse
    {
        try {
            $svc = $resolver->resolve($olt);
            $ports = $svc->getPorts($olt);
            $onus = $svc->getRegisteredOnus($olt);
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
            data_set($snapshot, 'ports', $ports);
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

            return $back->with('success', sprintf(__('flash.scan_ok_fmt'), count($onus), $olt->name));
        } catch (\Throwable $exception) {
            return $back->with('error', __('flash.onu_scan_failed').$exception->getMessage());
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
        $isC600 = SmartOltSupport::isC600($olt);
        // ACS efektif = baris Settings bila terisi, fallback config/env (AcsSetting::resolved).
        $acs = AcsSetting::resolved();

        // Identitas ONU — bagian bersama semua bentuk form.
        $identity = [
            'serial_number' => (string) $request->query('sn', ''),
            'slot' => $slot ?: null,
            'port' => $port ?: null,
            'onu_id' => $this->suggestNextOnuId($olt, $slot, $port, (int) $request->query('suggested_onu_id')),
            'oid_index' => (string) $request->query('oid_index', ''),
        ];

        // Hanya blok default milik family OLT ini yang dibangun penuh — form family
        // lain tak dirender frontend, jadi query profil & payload-nya dilewati.
        return Inertia::render('SmartOlt/RegisterOnu', [
            'olt' => $this->serializeOlt($olt),
            'profiles' => SmartOltProfileController::profileOptions($olt),
            'defaults' => $isC600 ? $identity : [
                ...$identity,
                'customer_name' => '',
                'onu_type' => $this->firstProfileName($olt, 'onu_type', 'ALL-ONT'),
                'tcont_profile' => $this->firstProfileName($olt, 'tcont', 'SERVER'),
                'vlan' => 100,
                'vlan_profile' => $this->firstProfileName($olt, 'vlan', 'ServiceName'),
                'service_name' => 'ServiceName',
                'service_mode' => 'vlanpri',
                'wan_mode' => 'pppoe',
                'pppoe_username' => '',
                'pppoe_password' => '',
                'ip_profile' => $this->firstProfileName($olt, 'ip', 'INTERNET'),
                'static_ip' => '',
                'static_netmask' => '24',
                'tr069_enabled' => false,
                'acs_url' => $acs['url'],
                'acs_username' => $acs['username'],
                'acs_password' => $acs['password'],
                'remote_ont_enabled' => false,
                'remote_ont_id' => 1,
                'remote_ont_mode' => 'forward',
                'remote_ont_protocol' => 'web',
            ],
            // Default form C600 (Model B / SmartOLT TR069). Nilai VLAN/profil/subnet mengikuti
            // pola lapangan yang terverifikasi; mgmt-ip WAJIB diisi unik per ONU oleh operator.
            'c600_defaults' => ! $isC600 ? null : [
                ...$identity,
                'customer_name' => '',
                'onu_type' => (string) $request->query('model', ''),
                'zone' => '',
                'internet_vlan' => 200,
                'internet_tcont_profile' => $this->firstProfileName($olt, 'tcont', '10MB'),
                'mgmt_vlan' => 601,
                'mgmt_tcont_profile' => 'SMARTOLT-VOIPMNG-10M',
                'egress_traffic_policy' => 'SMARTOLT-10M-DOWN',
                'mgmt_ip' => '',
                'mgmt_mask' => '255.255.240.0',
                'mgmt_gateway' => '',
                'mgmt_priority' => 2,
                'mgmt_host' => 2,
                'acs_url' => $acs['url'],
                'acs_username' => $acs['username'],
                'acs_password' => $acs['password'],
                'remote_ont_enabled' => false,
            ],
            // Pre-fill template standar untuk mode Lanjutan (editor granular):
            // 1 T-CONT + 1 gemport + 1 service-port + 1 service + 1 WAN-IP, semua
            // bisa ditambah/diubah baris per baris.
            'advanced_defaults' => $isC600 ? null : [
                'name' => '',
                'tconts' => [['id' => 1, 'name' => '1', 'profile' => $this->firstProfileName($olt, 'tcont', 'SERVER'), 'gap' => 'mode0']],
                'gemports' => [['id' => 1, 'name' => '1', 'tcont' => 1, 'traffic_up' => '', 'traffic_down' => '']],
                'service_ports' => [['id' => 1, 'vport' => 1, 'user_vlan' => 100, 'vlan' => 100]],
                'services' => [['name' => 'ServiceName', 'type' => null, 'mode' => 'vlanpri', 'gem' => 1, 'cos' => 0, 'vlan' => 100]],
                'vlan_ports' => [],
                'wan_services' => [],
                'wan_ips' => [[
                    'id' => 1,
                    'mode' => 'pppoe',
                    'vlan_profile' => $this->firstProfileName($olt, 'vlan', 'ServiceName'),
                    'pppoe_username' => '',
                    'pppoe_password' => '',
                    'ip_profile' => $this->firstProfileName($olt, 'ip', 'INTERNET'),
                    'static_ip' => '',
                    'static_mask_length' => 24,
                    'host' => 1,
                    'ping_response' => true,
                    'traceroute_response' => true,
                ]],
                'tr069' => false,
                'acs_url' => $acs['url'],
                'acs_username' => $acs['username'],
                'acs_password' => $acs['password'],
                'remote_ont' => false,
                'remote_ont_id' => 1,
                'remote_ont_mode' => 'forward',
                'remote_ont_protocol' => 'web',
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $olt = SnmpOlt::create($this->validated($request));
        $this->claimOltForPartner($olt, $request->user());

        return redirect()
            ->route('smartolt.index')
            ->with('success', __('flash.olt_added'));
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
            ->with('success', __('flash.olt_updated'));
    }

    public function destroy(Request $request, SnmpOlt $olt): RedirectResponse
    {
        $this->authorizeOltDeletion($olt, $request->user());
        $olt->delete();

        return redirect()
            ->route('smartolt.index')
            ->with('success', __('flash.olt_deleted'));
    }

    public function test(SnmpOlt $olt, OltSnmpClient $client): RedirectResponse
    {
        $result = $client->test($olt);

        // Test hanya cek koneksi (ok/driver/latency/system) — TIDAK memuat ports/port_onus.
        // Merge ke cache poll terakhir supaya inventori (GPON port & ONU) tak terhapus.
        $olt->forceFill([
            'last_test_result' => array_merge($olt->last_test_result ?? [], $result),
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
            ? sprintf(__('flash.snmp_ok_fmt'), $result['driver'], $result['latency_ms'])
            : sprintf(__('flash.snmp_failed_fmt'), $result['error'] ?? 'unknown error');

        return redirect()
            ->route('smartolt.index')
            ->with($result['ok'] ? 'success' : 'error', $message);
    }

    /**
     * Nyalakan/matikan alarm untuk satu OLT — per-penerima, bukan mute evaluasi:
     * - PARTNER → membalik saklar alarm miliknya sendiri (`olt_user.alarms_enabled`) untuk OLT
     *   yang di-assign; hanya memengaruhi webhook/FCM partner tsb (independen dari admin).
     * - ADMIN/OPERATOR → membalik saklar OLT (`snmp_olts.alarms_enabled`) yang menggerbang bot
     *   global + FCM admin/operator. Operator "ngikut administrator" (saklar yang sama).
     *
     * Evaluasi alarm tetap jalan (event tercatat) — yang di-gerbang hanya pengiriman notifikasi
     * di {@see TelegramNotifier} & {@see FcmAlarmNotifier}.
     * Berlaku semua family (ZTE, C-Data, HiOSO). Route-model-binding + PartnerOltScope menjamin
     * partner hanya bisa menyentuh OLT yang di-assign ke dia.
     */
    public function toggleAlarms(Request $request, SnmpOlt $olt): RedirectResponse
    {
        $user = $request->user();

        if ($user->isPartner()) {
            $current = (bool) (DB::table('olt_user')
                ->where('user_id', $user->id)
                ->where('snmp_olt_id', $olt->id)
                ->value('alarms_enabled') ?? true);
            $enabled = ! $current;

            DB::table('olt_user')
                ->where('user_id', $user->id)
                ->where('snmp_olt_id', $olt->id)
                ->update(['alarms_enabled' => $enabled, 'updated_at' => now()]);

            return back()->with(
                'success',
                $enabled
                    ? __('flash.alarm_partner_on', ['name' => $olt->name])
                    : __('flash.alarm_partner_off', ['name' => $olt->name]),
            );
        }

        $enabled = ! $olt->alarms_enabled;
        $olt->update(['alarms_enabled' => $enabled]);

        return back()->with(
            'success',
            $enabled
                ? __('flash.alarm_on', ['name' => $olt->name])
                : __('flash.alarm_off', ['name' => $olt->name]),
        );
    }

    /**
     * Simpan running-config OLT ZTE ke memori (perintah CLI `write`). Sinkron — di C300 config
     * besar write bisa ~30 detik. Gated capability `supports_config_save`.
     */
    public function saveConfig(SnmpOlt $olt, ZteCliProvisioningExecutor $executor): RedirectResponse
    {
        $this->assertCapability($olt, 'supports_config_save');
        $back = back(fallback: route('smartolt.index'));

        try {
            $result = $executor->saveConfig($olt);

            return $back->with(
                $result['ok'] ? 'success' : 'error',
                $result['ok']
                    ? __('flash.config_saved_write', ['name' => $olt->name])
                    : 'Simpan konfigurasi selesai dengan indikasi error: '.$result['error'],
            );
        } catch (\Throwable $exception) {
            return $back->with('error', __('flash.config_save_failed').$exception->getMessage());
        }
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
            ? sprintf(__('flash.refresh_snmp_ok_fmt'), count($result['ports'] ?? []))
            : sprintf(__('flash.refresh_snmp_failed_fmt'), $result['error'] ?? 'unknown error');

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
            ? sprintf(__('flash.refresh_onu_ok_slot_fmt'), $result['count'], $slot, $port)
            : sprintf(__('flash.refresh_onu_failed_fmt'), $result['error'] ?? 'unknown error');

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
            ? sprintf(__('flash.uncfg_ok_fmt'), $result['count'])
            : sprintf(__('flash.uncfg_failed_fmt'), $result['error'] ?? 'unknown error');

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
                        ? sprintf(__('flash.reboot_sent_iface_fmt'), SmartOltSupport::onuInterfaceId($slot, $port, $onuId, SmartOltSupport::isC600($olt)))
                        : 'Reboot ONU selesai dengan indikasi error: '.$result['error'],
                );
        } catch (\Throwable $exception) {
            return redirect()
                ->route('smartolt.port-onus', [$olt, $slot, $port])
                ->with('error', __('flash.onu_reboot_failed').$exception->getMessage());
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
                ->with('success', $active ? __('flash.onu_enabled') : __('flash.onu_disabled'));
        } catch (\Throwable $exception) {
            return redirect()
                ->route('smartolt.port-onus', [$olt, $slot, $port])
                ->with('error', __('flash.onu_state_failed').$exception->getMessage());
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
                ->with('error', __('flash.onu_info_required'));
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
                ->with('success', __('flash.onu_info_updated'));
        } catch (\Throwable $exception) {
            return redirect()
                ->route('smartolt.port-onus', [$olt, $slot, $port])
                ->with('error', __('flash.onu_info_failed').$exception->getMessage());
        }
    }

    public function onuDetail(Request $request, SnmpOlt $olt, int $slot, int $port, int $onuId, ZteOnuDetailService $service): Response
    {
        $this->assertCapability($olt, 'supports_cli_onu_detail');

        $cached = $this->findCachedOnu($olt, $slot, $port, $onuId);

        $range = $this->rxRange($request->query('range'));
        $rxHistory = OnuRxSample::seriesFor($olt->id, $slot, $port, $onuId, $range['since']);

        // Live CLI fetch (telnet) di-defer ke closure agar partial reload — mis. saat ganti
        // rentang grafik tren (only: rx_history) — tidak memicu sesi telnet baru.
        $live = null;
        $resolveLive = function () use (&$live, $service, $olt, $slot, $port, $onuId): array {
            return $live ??= $service->fetch($olt, $slot, $port, $onuId);
        };

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
            'groups' => fn () => $resolveLive()['groups'],
            'raw' => fn () => $resolveLive()['raw'],
            'fetch_ok' => fn () => $resolveLive()['ok'],
            'fetch_error' => fn () => $resolveLive()['error'],
            'rx_history' => $rxHistory,
            'range' => $range['key'],
        ]);
    }

    /**
     * Petakan rentang waktu grafik tren RX ke titik mulai (default 7 hari).
     *
     * @return array{key:string, since:Carbon}
     */
    private function rxRange(?string $range): array
    {
        $key = in_array($range, ['24h', '7d', '30d'], true) ? $range : '7d';

        $since = match ($key) {
            '24h' => now()->subDay(),
            '30d' => now()->subDays(30),
            default => now()->subDays(7),
        };

        return ['key' => $key, 'since' => $since];
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
        $this->assertCapability($olt, 'supports_onu_config_write');

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
        $this->assertCapability($olt, 'supports_onu_config_write');

        $target = $this->validatedReconfigure($request);
        $baseline = $request->input('baseline', []);
        $iface = SmartOltSupport::onuInterfaceId($slot, $port, $onuId, SmartOltSupport::isC600($olt));

        $delta = $builder->build(is_array($baseline) ? $baseline : [], $target, ['onu_iface' => $iface]);

        $back = redirect()->route('smartolt.onu.configure', [$olt, $slot, $port, $onuId]);

        if ($delta['script'] === '') {
            return $back->with('error', __('flash.no_config_changes'));
        }

        $cached = $this->findCachedOnu($olt, $slot, $port, $onuId);
        $primaryWan = is_array($target['wan_ips'] ?? null) ? ($target['wan_ips'][0] ?? []) : [];
        $base = [
            'snmp_olt_id' => $olt->id,
            'serial_number' => (string) ($cached['serial_number'] ?? ''),
            'slot' => $slot,
            'port' => $port,
            'onu_id' => $onuId,
            'pon_port' => $iface,
            'customer_name' => (string) ($target['name'] ?? ($cached['name'] ?? '')),
            'vlan' => $this->resolvePrimaryVlan($target),
            'vlan_profile' => $primaryWan['vlan_profile'] ?? null,
            'wan_mode' => in_array($primaryWan['mode'] ?? '', ['pppoe', 'dhcp', 'static'], true) ? $primaryWan['mode'] : 'pppoe',
            'pppoe_username' => $primaryWan['pppoe_username'] ?? null,
            'ip_profile' => $primaryWan['ip_profile'] ?? null,
            'static_ip' => $primaryWan['static_ip'] ?? null,
            'static_netmask' => isset($primaryWan['static_mask_length']) ? (string) $primaryWan['static_mask_length'] : null,
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
                    ? __('flash.config_applied')
                    : __('flash.config_apply_rejected').$error,
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

            return $back->with('error', __('flash.apply_config_failed').$error);
        }
    }

    /**
     * Queue a batch copy of registered ONUs from this port to another port on the
     * same OLT. Returns JSON with a task id; the heavy work (CLI reads + optional
     * execute, far too long for one HTTP request) runs in {@see CopyOnusToPortJob}
     * and the client polls copyTaskStatus() for live progress. Source ONUs are
     * left untouched.
     */
    public function copyOnusToPort(Request $request, SnmpOlt $olt, int $slot, int $port): JsonResponse
    {
        // Rebuild registrasi gaya C300 (tcont/gemport/service-port) — butuh capability TULIS
        // config, bukan sekadar CLI configure (C600 read-only untuk jalur ini).
        $this->assertCapability($olt, 'supports_onu_config_write');

        $data = $request->validate([
            'onu_ids' => ['required', 'array', 'min:1', 'max:256'],
            'onu_ids.*' => ['integer', 'between:1,4096'],
            'dst_slot' => ['required', 'integer', 'between:1,255'],
            'dst_port' => ['required', 'integer', 'between:1,255'],
            'execute' => ['boolean'],
        ]);

        $dstSlot = (int) $data['dst_slot'];
        $dstPort = (int) $data['dst_port'];

        if ($dstSlot === $slot && $dstPort === $port) {
            return response()->json(['ok' => false, 'message' => 'Port tujuan harus berbeda dari port asal.'], 422);
        }

        $onuIds = array_values(array_unique(array_map('intval', $data['onu_ids'])));

        $task = CopyOnuTask::create([
            'snmp_olt_id' => $olt->id,
            'created_by' => $request->user()?->id,
            'src_slot' => $slot,
            'src_port' => $port,
            'dst_slot' => $dstSlot,
            'dst_port' => $dstPort,
            'execute' => (bool) ($data['execute'] ?? false),
            'onu_ids' => $onuIds,
            'total' => count($onuIds),
            'status' => 'queued',
        ]);

        CopyOnusToPortJob::dispatch($task->id);

        return response()->json([
            'ok' => true,
            'task_id' => $task->id,
            'status_url' => route('smartolt.copy-task.status', [$olt, $task]),
            'registrations_url' => route('smartolt.registrations', $olt),
        ]);
    }

    public function copyTaskStatus(SnmpOlt $olt, CopyOnuTask $task): JsonResponse
    {
        abort_unless($task->snmp_olt_id === $olt->id, 404);

        return response()->json($task->progressPayload());
    }

    /**
     * Kick off a per-port "aktifkan TR069 massal" batch (all ONUs on one PON
     * port). Two phases share this endpoint via the `execute` flag: dry-run (scan
     * only) and execute (write). Both skip ONUs already pointing at the target
     * ACS. Runs in a queued job; the frontend polls {@see tr069BulkStatus()} for
     * live progress.
     */
    public function tr069Bulk(Request $request, SnmpOlt $olt, int $slot, int $port, ZteTr069BulkService $service): JsonResponse
    {
        // Menulis baris tr069-mgmt gaya C300 (dua-baris state+acs) — gate capability tulis config.
        $this->assertCapability($olt, 'supports_onu_config_write');

        $data = $request->validate([
            'execute' => ['boolean'],
        ]);

        $task = Tr069BulkTask::create([
            'snmp_olt_id' => $olt->id,
            'created_by' => $request->user()?->id,
            'slot' => $slot,
            'port' => $port,
            'execute' => (bool) ($data['execute'] ?? false),
            'total' => $service->cachedOnuCount($olt, $slot, $port),
            'status' => 'queued',
        ]);

        Tr069BulkConfigJob::dispatch($task->id);

        return response()->json([
            'ok' => true,
            'task_id' => $task->id,
            'status_url' => route('smartolt.tr069-bulk.status', [$olt, $task]),
        ]);
    }

    public function tr069BulkStatus(SnmpOlt $olt, Tr069BulkTask $task): JsonResponse
    {
        abort_unless($task->snmp_olt_id === $olt->id, 404);

        return response()->json($task->progressPayload());
    }

    /**
     * Delete (deregister) an ONU on the OLT via `no onu {id}` under the GPON-OLT
     * interface (guide §8 rollback). Destructive: removes the ONU registration.
     * CLI-nya di {@see ZteRemoteOnuService::delete} (dipakai juga API v1).
     */
    public function deleteOnu(SnmpOlt $olt, int $slot, int $port, int $onuId, ZteRemoteOnuService $remote): RedirectResponse
    {
        $this->assertCapability($olt, 'supports_onu_delete');

        $iface = SmartOltSupport::gponOltInterface($slot, $port, SmartOltSupport::isC600($olt));
        $back = redirect()->route('smartolt.port-onus', [$olt, $slot, $port]);

        try {
            $result = $remote->delete($olt, $slot, $port, $onuId);
            $error = $result['error'] === null ? null : CliOutputSanitizer::clean($result['error']);

            if ($result['ok']) {
                $this->removeCachedOnu($olt, $slot, $port, $onuId);
            }

            return $back->with(
                $result['ok'] ? 'success' : 'error',
                $result['ok']
                    ? "ONU {$onuId} berhasil dihapus dari {$iface}."
                    : 'Hapus ONU selesai dengan indikasi error: '.$error,
            );
        } catch (\Throwable $exception) {
            return $back->with('error', __('flash.onu_delete_failed').CliOutputSanitizer::clean($exception->getMessage()));
        }
    }

    /**
     * Auto-alokasi mgmt-IP C600: baca IP terpakai dari OLT (menghindari bentrok SmartOLT) lalu
     * kembalikan IP bebas terendah + parameter pool (mask/gateway/vlan/priority/host) yang diturunkan
     * dari config. `?fresh=1` memaksa scan ulang (abaikan cache ~10 mnt). Read-only ke OLT.
     */
    public function registerMgmtPool(Request $request, SnmpOlt $olt, C600MgmtPoolService $pool): JsonResponse
    {
        if (! SmartOltSupport::isC600($olt)) {
            return response()->json(['error' => 'Auto mgmt-IP hanya untuk OLT C600.'], 422);
        }

        $this->assertCapability($olt, 'supports_provisioning');

        try {
            $fresh = $request->boolean('fresh');
            $next = $pool->nextFreeIp($olt, $fresh);

            if ($next === null) {
                return response()->json(['error' => 'Pool mgmt-IP tidak terbaca dari OLT atau sudah penuh.'], 422);
            }

            // Preset TR069 (ACS url/username/password) dari OLT → registrasi konsisten dgn ONU lain.
            return response()->json([...$next, ...$pool->tr069Preset($olt, $fresh)]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Build a live preview of the provisioning CLI script from the (possibly
     * incomplete) register form, without strict validation, so the form's
     * left-hand "live raw CLI" panel can update on every keystroke. Read-only:
     * never touches the OLT.
     */
    public function registerOnuPreview(Request $request, SnmpOlt $olt, ZteProvisioningScriptBuilder $builder, OnuRegistrationService $registration): JsonResponse
    {
        // C600 = builder Model B lewat OnuRegistrationService. Preview toleran form parsial:
        // builder C600 melempar bila field wajib kosong → tampilkan pesan alih-alih 500.
        if (SmartOltSupport::isC600($olt)) {
            try {
                return response()->json(['script' => $registration->buildScript($olt, $request->all())]);
            } catch (\Throwable) {
                return response()->json(['script' => '! Lengkapi field wajib (VLAN, profil TCONT, mgmt-ip, ACS) untuk melihat script.']);
            }
        }

        $data = $this->hydrateProvisioningProfiles($olt, $this->previewProvisioningInput($request));

        return response()->json(['script' => $builder->build($data)]);
    }

    public function storeOnu(Request $request, SnmpOlt $olt, ZteProvisioningScriptBuilder $builder, ZteCliProvisioningExecutor $executor, OnuRegistrationService $registration): RedirectResponse
    {
        // C600 = jalur Model B (validasi c600Rules + builder C600) lewat OnuRegistrationService.
        if (SmartOltSupport::isC600($olt)) {
            $execute = $request->boolean('execute');
            if ($execute) {
                $this->assertCapability($olt, 'supports_cli_onu_configure');
            }

            $validated = $request->validate($registration->rules($olt));
            $result = $registration->register($olt, $validated, $execute, $request->user()?->id);

            $flash = match ($result['status']) {
                'executed' => ['success', __('flash.registered_ok')],
                'generated' => ['success', __('flash.prov_generated')],
                default => ['error', __('flash.register_rejected').($result['error'] ?? '')],
            };

            return redirect()
                ->route('smartolt.registrations', $olt)
                ->with($flash[0], $flash[1]);
        }

        $data = $this->hydrateProvisioningProfiles($olt, $this->validatedProvisioning($request, $olt));
        $script = $builder->build($data);
        $execute = $request->boolean('execute');

        $base = [
            ...$data,
            'snmp_olt_id' => $olt->id,
            // Cabang C600 sudah return di atas — jalur ini selalu penamaan C300/C320.
            'pon_port' => SmartOltSupport::onuInterfaceId(
                (int) $data['slot'],
                (int) $data['port'],
                (int) $data['onu_id'],
                false,
            ),
            'cli_script' => $script,
            'created_by' => $request->user()?->id,
        ];

        // Hanya simpan script (audit) — tanpa menyentuh OLT.
        if (! $execute) {
            SmartOltOnuRegistration::create([...$base, 'status' => 'generated']);

            return redirect()
                ->route('smartolt.registrations', $olt)
                ->with('success', __('flash.prov_generated'));
        }

        // Eksekusi langsung ke OLT via Telnet.
        $this->assertCapability($olt, 'supports_cli_onu_configure');

        try {
            $result = $executor->execute($olt, $script);
            $output = CliOutputSanitizer::clean($result['output']);
            $error = $result['error'] === null ? null : CliOutputSanitizer::clean($result['error']);

            SmartOltOnuRegistration::create([
                ...$base,
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
                        ? __('flash.registered_ok')
                        : __('flash.register_rejected').$error,
                );
        } catch (\Throwable $exception) {
            $error = CliOutputSanitizer::clean($exception->getMessage());

            SmartOltOnuRegistration::create([
                ...$base,
                'status' => 'failed',
                'execution_error' => $error,
                'executed_at' => now(),
                'executed_by' => $request->user()?->id,
            ]);

            return redirect()
                ->route('smartolt.registrations', $olt)
                ->with('error', __('flash.register_exec_failed').$error);
        }
    }

    /**
     * Live preview for the advanced (granular) registration: builds a full
     * registration script from the composed tcont/gemport/service-port/service
     * rows, same shape as Configure ONU but for a brand-new ONU.
     */
    public function registerOnuAdvancedPreview(Request $request, SnmpOlt $olt, ZteOnuReconfigureScriptBuilder $builder): JsonResponse
    {
        // Mode Lanjutan menyusun config granular gaya C300 — gate capability tulis config
        // (di C600 form Lanjutan memang tak dirender; registrasi C600 lewat jalur Model B).
        $this->assertCapability($olt, 'supports_onu_config_write');

        [$header, $config] = $this->validatedAdvancedProvisioning($request, $olt);

        $script = $builder->buildForRegistration($config, $this->advancedRegistrationContext($olt, $header));

        return response()->json(['script' => $script]);
    }

    /**
     * Persist (and optionally execute) an advanced registration. Mirrors
     * {@see storeOnu} but the CLI script comes from the granular editor instead
     * of the fixed single-service template.
     */
    public function storeOnuAdvanced(Request $request, SnmpOlt $olt, ZteOnuReconfigureScriptBuilder $builder, ZteCliProvisioningExecutor $executor): RedirectResponse
    {
        [$header, $config] = $this->validatedAdvancedProvisioning($request, $olt);
        $context = $this->advancedRegistrationContext($olt, $header);
        $script = $builder->buildForRegistration($config, $context);
        $execute = $request->boolean('execute');

        $primaryWan = is_array($config['wan_ips'][0] ?? null) ? $config['wan_ips'][0] : [];
        $base = [
            'snmp_olt_id' => $olt->id,
            'serial_number' => (string) $header['serial_number'],
            'slot' => (int) $header['slot'],
            'port' => (int) $header['port'],
            'onu_id' => (int) $header['onu_id'],
            'pon_port' => $context['onu_iface'],
            'onu_type' => (string) $header['onu_type'],
            'customer_name' => (string) ($config['name'] ?? ''),
            'vlan' => $this->resolvePrimaryVlan($config),
            'vlan_profile' => $primaryWan['vlan_profile'] ?? null,
            'wan_mode' => in_array($primaryWan['mode'] ?? '', ['pppoe', 'dhcp', 'static'], true) ? $primaryWan['mode'] : 'pppoe',
            'pppoe_username' => $primaryWan['pppoe_username'] ?? null,
            'ip_profile' => $primaryWan['ip_profile'] ?? null,
            'static_ip' => $primaryWan['static_ip'] ?? null,
            'static_netmask' => isset($primaryWan['static_mask_length']) ? (string) $primaryWan['static_mask_length'] : null,
            'tr069_enabled' => (bool) ($config['tr069'] ?? false),
            'acs_url' => $config['acs_url'] ?? null,
            'acs_username' => $config['acs_username'] ?? null,
            'remote_ont_enabled' => (bool) ($config['remote_ont'] ?? false),
            'remote_ont_id' => $config['remote_ont_id'] ?? null,
            'remote_ont_mode' => $config['remote_ont_mode'] ?? null,
            'remote_ont_protocol' => $config['remote_ont_protocol'] ?? null,
            'cli_script' => $script,
            'created_by' => $request->user()?->id,
        ];

        // Hanya simpan script (audit) — tanpa menyentuh OLT.
        if (! $execute) {
            SmartOltOnuRegistration::create([...$base, 'status' => 'generated']);

            return redirect()
                ->route('smartolt.registrations', $olt)
                ->with('success', __('flash.prov_generated_advanced'));
        }

        // Eksekusi langsung ke OLT via Telnet — script granular gaya C300 (gate capability tulis).
        $this->assertCapability($olt, 'supports_onu_config_write');

        try {
            $result = $executor->execute($olt, $script);
            $output = CliOutputSanitizer::clean($result['output']);
            $error = $result['error'] === null ? null : CliOutputSanitizer::clean($result['error']);

            SmartOltOnuRegistration::create([
                ...$base,
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
                        ? __('flash.registered_ok')
                        : __('flash.register_rejected').$error,
                );
        } catch (\Throwable $exception) {
            $error = CliOutputSanitizer::clean($exception->getMessage());

            SmartOltOnuRegistration::create([
                ...$base,
                'status' => 'failed',
                'execution_error' => $error,
                'executed_at' => now(),
                'executed_by' => $request->user()?->id,
            ]);

            return redirect()
                ->route('smartolt.registrations', $olt)
                ->with('error', __('flash.register_exec_failed').$error);
        }
    }

    /**
     * Lenient (non-validating) gather of register form inputs for live preview.
     *
     * @return array<string, mixed>
     */
    private function previewProvisioningInput(Request $request): array
    {
        return [
            'serial_number' => (string) $request->input('serial_number', ''),
            'slot' => (int) $request->input('slot', 0),
            'port' => (int) $request->input('port', 0),
            'onu_id' => (int) $request->input('onu_id', 0),
            'oid_index' => (string) $request->input('oid_index', ''),
            'customer_name' => (string) $request->input('customer_name', ''),
            'onu_type' => (string) ($request->input('onu_type') ?: 'ALL-ONT'),
            'tcont_profile' => (string) ($request->input('tcont_profile') ?: 'SERVER'),
            'vlan' => (int) $request->input('vlan', 100),
            'vlan_profile' => (string) $request->input('vlan_profile', ''),
            'service_name' => (string) ($request->input('service_name') ?: 'ServiceName'),
            'service_mode' => (string) ($request->input('service_mode') ?: 'vlanpri'),
            'wan_mode' => (string) ($request->input('wan_mode') ?: 'pppoe'),
            'pppoe_username' => (string) $request->input('pppoe_username', ''),
            'pppoe_password' => (string) $request->input('pppoe_password', ''),
            'ip_profile' => (string) $request->input('ip_profile', ''),
            'static_ip' => (string) $request->input('static_ip', ''),
            'static_netmask' => (string) ($request->input('static_netmask') ?: '24'),
            'tr069_enabled' => $request->boolean('tr069_enabled'),
            'acs_url' => (string) $request->input('acs_url', ''),
            'acs_username' => (string) $request->input('acs_username', ''),
            'acs_password' => (string) $request->input('acs_password', ''),
            'remote_ont_enabled' => $request->boolean('remote_ont_enabled'),
            'remote_ont_id' => (int) $request->input('remote_ont_id', 1),
            'remote_ont_mode' => (string) ($request->input('remote_ont_mode') ?: 'forward'),
            'remote_ont_protocol' => (string) ($request->input('remote_ont_protocol') ?: 'web'),
        ];
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

        if (in_array($registration->status, ['executed', 'reconfigured'], true)) {
            return redirect()
                ->route('smartolt.registrations', $olt)
                ->with('success', __('flash.prov_already_registered'));
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
                        ? __('flash.prov_executed')
                        : __('flash.prov_rejected').$error,
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
                ->with('error', __('flash.prov_exec_failed').$error);
        }
    }

    public function destroyRegistration(
        SnmpOlt $olt,
        SmartOltOnuRegistration $registration,
    ): RedirectResponse {
        abort_unless($registration->snmp_olt_id === $olt->id, 404);

        if (in_array($registration->status, ['executed', 'reconfigured'], true)) {
            return redirect()
                ->route('smartolt.registrations', $olt)
                ->with('error', __('flash.prov_delete_blocked'));
        }

        $registration->delete();

        return redirect()
            ->route('smartolt.registrations', $olt)
            ->with('success', __('flash.prov_deleted'));
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
                Rule::unique('snmp_olts', 'ip')
                    ->where(fn ($query) => $query->where('snmp_port', $request->integer('snmp_port')))
                    ->ignore($olt),
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
        ], [
            'ip.unique' => 'Kombinasi IP + SNMP port ini sudah dipakai OLT lain. Ubah SNMP port bila ingin memakai IP yang sama.',
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
            'wan_mode' => ['required', Rule::in(['pppoe', 'dhcp', 'static', 'bridge'])],
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
        $validated = $request->validate($this->reconfigureConfigRules());

        return $validated['config'];
    }

    /**
     * Shared validation rules for the granular ONU config payload — used by both
     * the reconfigure (Configure ONU) flow and the advanced registration flow.
     *
     * @return array<string, mixed>
     */
    private function reconfigureConfigRules(): array
    {
        return [
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
            'config.wan_services.*.services' => ['array'],
            'config.wan_services.*.services.*' => [Rule::in(['internet', 'tr069', 'voip', 'other'])],
            'config.wan_services.*.ethuni' => ['nullable', 'string', 'max:32'],
            'config.wan_services.*.ssid' => ['nullable', 'string', 'max:64'],
            'config.wan_services.*.mvlan' => ['nullable', 'string', 'max:32'],
            'config.wan_services.*.host' => ['nullable', 'string', 'max:32'],
            'config.wan_ips' => ['array'],
            'config.wan_ips.*.id' => ['required', 'integer', 'between:1,8'],
            'config.wan_ips.*.mode' => ['required', Rule::in(['pppoe', 'dhcp', 'static'])],
            'config.wan_ips.*.vlan_profile' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._-]+$/'],
            'config.wan_ips.*.pppoe_username' => ['nullable', 'string', 'max:120'],
            'config.wan_ips.*.pppoe_password' => ['nullable', 'string', 'max:120'],
            'config.wan_ips.*.ip_profile' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._-]+$/'],
            'config.wan_ips.*.static_ip' => ['nullable', 'ip'],
            'config.wan_ips.*.static_mask_length' => ['nullable', 'integer', 'between:1,32'],
            'config.wan_ips.*.host' => ['nullable', 'integer', 'between:1,16'],
            'config.wan_ips.*.ping_response' => ['boolean'],
            'config.wan_ips.*.traceroute_response' => ['boolean'],
            'config.tr069' => ['boolean'],
            'config.acs_url' => ['nullable', 'url', 'max:255'],
            'config.acs_username' => ['nullable', 'string', 'max:120'],
            'config.acs_password' => ['nullable', 'string', 'max:120'],
            'config.remote_ont' => ['boolean'],
            'config.remote_ont_id' => ['nullable', 'integer', 'between:1,4095'],
            'config.remote_ont_mode' => ['nullable', Rule::in(['forward', 'discard'])],
            'config.remote_ont_protocol' => ['nullable', Rule::in(['web', 'telnet', 'ssh', 'ftp', 'tftp', 'snmp'])],
        ];
    }

    /**
     * Validate the advanced-registration payload: registration header (SN, slot,
     * port, onu_id, onu_type) + the granular config (reuses reconfigure rules).
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} [header, config]
     */
    private function validatedAdvancedProvisioning(Request $request, SnmpOlt $olt): array
    {
        $validated = $request->validate([
            'serial_number' => ['required', 'string', 'max:64'],
            'slot' => ['required', 'integer', 'between:1,255'],
            'port' => ['required', 'integer', 'between:1,255'],
            'onu_id' => ['required', 'integer', 'between:1,4096'],
            'oid_index' => ['nullable', 'string', 'max:191'],
            'onu_type' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._-]+$/', $this->activeProfileRule($olt, 'onu_type')],
            ...$this->reconfigureConfigRules(),
        ]);

        return [
            [
                'serial_number' => $validated['serial_number'],
                'slot' => (int) $validated['slot'],
                'port' => (int) $validated['port'],
                'onu_id' => (int) $validated['onu_id'],
                'oid_index' => $validated['oid_index'] ?? null,
                'onu_type' => $validated['onu_type'],
            ],
            $validated['config'],
        ];
    }

    /**
     * @param  array<string, mixed>  $header
     * @return array{olt_iface:string, onu_iface:string, onu_id:int, sn:string, onu_type:string, is_c600:bool}
     */
    private function advancedRegistrationContext(SnmpOlt $olt, array $header): array
    {
        $isC600 = SmartOltSupport::isC600($olt);

        return [
            'olt_iface' => SmartOltSupport::gponOltInterface((int) $header['slot'], (int) $header['port'], $isC600),
            'onu_iface' => SmartOltSupport::onuInterfaceId((int) $header['slot'], (int) $header['port'], (int) $header['onu_id'], $isC600),
            'onu_id' => (int) $header['onu_id'],
            'sn' => (string) $header['serial_number'],
            'onu_type' => (string) $header['onu_type'],
            'is_c600' => $isC600,
        ];
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
     * Saklar alarm efektif untuk viewer saat ini terhadap OLT ini:
     * - partner → saklar webhook-nya sendiri (`olt_user.alarms_enabled`, default true),
     * - admin/operator (atau tanpa auth) → saklar OLT (`snmp_olts.alarms_enabled`).
     */
    private function viewerAlarmsEnabled(SnmpOlt $olt): bool
    {
        $user = request()->user();

        if ($user && $user->isPartner()) {
            $this->partnerAlarmMap ??= DB::table('olt_user')
                ->where('user_id', $user->id)
                ->pluck('alarms_enabled', 'snmp_olt_id')
                ->map(fn ($v) => (bool) $v)
                ->all();

            return $this->partnerAlarmMap[$olt->id] ?? true;
        }

        return (bool) $olt->alarms_enabled;
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
            // Efektif per-penerima: partner lihat saklar webhook-nya sendiri (pivot),
            // admin/operator lihat saklar OLT.
            'alarms_enabled' => $this->viewerAlarmsEnabled($olt),
            'poll_interval_minutes' => $olt->pollIntervalMinutes(),
            'rx_poll_interval_minutes' => $olt->rxPollIntervalMinutes(),
            'driver' => $driver,
            'capabilities' => SmartOltSupport::capabilities($driver, $olt),
            // Kepemilikan: is_private = OLT privat partner (bukan global); owned = milik viewer.
            'is_private' => $olt->owner_user_id !== null,
            'owned' => $olt->owner_user_id !== null && $olt->owner_user_id === auth()->id(),
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
            // Teruskan $olt agar kapabilitas yang bergantung C600 (mis. supports_onu_config_write,
            // supports_provisioning) dinilai benar — tanpa ini isC600 selalu false di jalur ini.
            (bool) (SmartOltSupport::capabilities($driver, $olt)[$capability] ?? false),
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

    /**
     * Drop a deleted ONU from the cached port snapshot so the UI reflects the
     * removal immediately (without a full SNMP refresh).
     */
    private function removeCachedOnu(SnmpOlt $olt, int $slot, int $port, int $onuId): void
    {
        $snapshot = $olt->last_test_result ?? [];
        $path = "port_onus.{$slot}_{$port}.onus";
        $onus = data_get($snapshot, $path);

        if (! is_array($onus)) {
            return;
        }

        $onus = array_values(array_filter($onus, fn (array $onu): bool => (int) ($onu['onu_id'] ?? 0) !== $onuId));
        data_set($snapshot, $path, $onus);

        if (data_get($snapshot, "port_onus.{$slot}_{$port}.count") !== null) {
            data_set($snapshot, "port_onus.{$slot}_{$port}.count", count($onus));
        }

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
            // VLAN tetap mengikuti profile, tetapi service_name dibiarkan apa adanya
            // (input user / default 'ServiceName') — tidak lagi dipaksa = nama profile.
            $data['vlan'] = $profile->vlan;
        }

        return $data;
    }
}
