<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesOltOwnership;
use App\Models\OnuMapPin;
use App\Models\PollingEvent;
use App\Models\SnmpOlt;
use App\Services\CData\CDataOltScanner;
use App\Services\Genieacs\GenieacsMapService;
use App\Services\Hsgq\HsgqSnmpService;
use App\Services\Hsgq\HsgqWebService;
use App\Services\OltPortLabelService;
use App\Services\OnuOdpService;
use App\Services\SmartOltSnmpServiceResolver;
use App\Support\PonPortCards;
use App\Support\SmartOltSupport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Halaman OLT HSGQ asli (Shenzhen HS Fiber, enterprise 50224) — EPON & GPON, satu controller.
 *
 * ⚠️ Driver belum terverifikasi di perangkat (lihat `docs/SMARTOLT_HSGQ_GUIDE.md`). Inventori, status,
 * dan Rx dibaca via SNMP lewat {@see CDataOltScanner} bersama (resolver → {@see HsgqSnmpService});
 * reboot, ganti nama/deskripsi, dan simpan konfigurasi via API JSON web OLT ({@see HsgqWebService}).
 * Tombol Test menyimpan laporan diagnostik (`last_test_result.hsgq_diag`) yang bisa disalin pengguna
 * untuk verifikasi. Hapus, enable/disable, dan registrasi ONU tidak punya rute: payload-nya tak dikenal.
 */
class HsgqOltController extends Controller
{
    use ManagesOltOwnership;

    /** TTL cache auto-refresh: scan ulang saat halaman dibuka hanya bila cache lebih tua dari ini. */
    private const CACHE_TTL_MINUTES = 5;

    public function index(): RedirectResponse
    {
        return redirect()->route('smartolt.index', ['tab' => 'hsgq']);
    }

    public function create(): Response
    {
        return Inertia::render('Hsgq/Create', [
            'defaults' => [
                'vendor' => 'HSGQ 50224',
                'snmp_port' => 161,
                'snmp_version' => 'v2c',
                'web_port' => 80,
                'cli_transport' => null,
                'cli_port' => 23,
            ],
        ]);
    }

    public function store(Request $request, CDataOltScanner $scanner): RedirectResponse
    {
        $olt = SnmpOlt::create($this->validated($request));
        $this->claimOltForPartner($olt, $request->user());
        $redirect = redirect()->route('smartolt.index', ['tab' => 'hsgq']);

        // Scan awal sekali supaya ONU langsung searchable di global search tanpa perlu buka halaman OLT.
        try {
            $count = $scanner->scan($olt);

            return $redirect->with('success', sprintf(__('flash.olt_added_scan_fmt'), $count));
        } catch (Throwable $exception) {
            return $redirect->with('success', __('flash.olt_added_scan_failed').$exception->getMessage().__('hsgq.scan_retry_on_open'));
        }
    }

    public function edit(SnmpOlt $olt): Response
    {
        return Inertia::render('Hsgq/Edit', [
            'olt' => $this->serializeOlt($olt),
        ]);
    }

    public function update(Request $request, SnmpOlt $olt): RedirectResponse
    {
        $data = $this->withoutEmptySecrets($this->validated($request, $olt));
        $this->authorizeOltUpdate($olt, $request->user(), $data);
        $olt->update($data);

        return redirect()
            ->route('smartolt.index', ['tab' => 'hsgq'])
            ->with('success', __('hsgq.olt_updated'));
    }

    public function destroy(Request $request, SnmpOlt $olt): RedirectResponse
    {
        $this->authorizeOltDeletion($olt, $request->user());
        $olt->delete();

        return redirect()
            ->route('smartolt.index', ['tab' => 'hsgq'])
            ->with('success', __('hsgq.olt_deleted'));
    }

    /**
     * Uji koneksi + laporan diagnostik SNMP & web. Laporan disimpan di snapshot dan ditampilkan di
     * halaman Detail (bisa disalin) — alat utama memverifikasi driver di perangkat pertama.
     */
    public function test(Request $request, SnmpOlt $olt, HsgqSnmpService $snmp, HsgqWebService $web): RedirectResponse
    {
        $this->authorizeOltConnectionTest($olt, $request->user());

        $startedAt = microtime(true);
        $snmpReport = $snmp->diagnose($olt);
        $latency = (int) round((microtime(true) - $startedAt) * 1000);
        $webReport = $web->diagnose($olt);
        $ok = (bool) ($snmpReport['snmp_ok'] ?? false);
        $error = $ok ? null : ($snmpReport['errors'][0] ?? __('hsgq.snmp_no_answer'));

        $snapshot = $olt->last_test_result ?? [];
        if ($ok) {
            try {
                $snapshot['system'] = $snmp->getSystemInfo($olt);
            } catch (Throwable) {
                // laporan diagnostik tetap disimpan
            }
        }

        $snapshot['ok'] = $ok;
        $snapshot['error'] = $error;
        $snapshot['latency_ms'] = $latency;
        $snapshot['driver'] = SmartOltSupport::DRIVER_HSGQ;
        $snapshot['hsgq_diag'] = [
            'tested_at' => now()->toIso8601String(),
            'snmp' => $snmpReport,
            'web' => $webReport,
        ];

        $olt->forceFill(['last_test_result' => $snapshot, 'last_tested_at' => now()])->save();

        PollingEvent::log($olt->id, PollingEvent::KIND_OLT_TEST, $ok, $error, $latency);

        $message = $ok
            ? __('hsgq.test_ok', [
                'family' => SmartOltSupport::capabilities(SmartOltSupport::DRIVER_HSGQ, $olt)['vendor_family'],
                'latency' => $latency,
                'onus' => (int) ($snmpReport['onu_count'] ?? 0),
            ])
            : sprintf(__('flash.snmp_failed_fmt'), $error);

        return redirect()
            ->route('hsgq-olt.detail', $olt)
            ->with($ok ? 'success' : 'error', $message);
    }

    public function detail(SnmpOlt $olt, CDataOltScanner $scanner, OltPortLabelService $labels): Response
    {
        $this->ensureFreshScan($olt, $scanner);

        return Inertia::render('Hsgq/Detail', [
            'olt' => $this->serializeOlt($olt),
            'snapshot' => $this->serializeSnapshot($olt),
            'port_labels' => $labels->forOlt($olt),
        ]);
    }

    /**
     * Halaman "PON Port" — kartu per port + cari ONU. Halaman Vue `SmartOlt/PonPorts` dipakai bersama
     * semua family (prop `route_prefix`).
     */
    public function ponPorts(SnmpOlt $olt, CDataOltScanner $scanner, OltPortLabelService $labels): Response
    {
        $this->ensureFreshScan($olt, $scanner);

        return Inertia::render('SmartOlt/PonPorts', [
            'olt' => $this->serializeOlt($olt),
            'route_prefix' => 'hsgq-olt',
            'ports' => PonPortCards::build($olt->last_test_result ?? [], $labels->forOlt($olt)),
            'scanned_at' => data_get($olt->last_test_result, 'onu_scanned_at'),
        ]);
    }

    public function portOnus(Request $request, SnmpOlt $olt, int $slot, int $port, CDataOltScanner $scanner, OnuOdpService $odpService, OltPortLabelService $labels, GenieacsMapService $genieacsMap): Response
    {
        $this->ensureFreshScan($olt, $scanner);

        return Inertia::render('Hsgq/PortOnus', [
            'olt' => $this->serializeOlt($olt),
            'slot' => $slot,
            'port' => $port,
            'snapshot' => data_get($olt->last_test_result, "port_onus.{$slot}_{$port}"),
            'port_row' => collect(data_get($olt->last_test_result, 'ports', []))
                ->first(fn ($row) => is_array($row) && (int) ($row['slot'] ?? 0) === $slot && (int) ($row['port'] ?? 0) === $port),
            'focus' => $request->query('focus'),
            'q' => $request->query('q'),
            'pinned_onu_ids' => OnuMapPin::query()
                ->where('snmp_olt_id', $olt->id)
                ->where('slot', $slot)
                ->where('port', $port)
                ->pluck('onu_id')
                ->all(),
            'odps' => $odpService->odpsForOlt($olt, $slot, $port),
            'odp_links' => $odpService->linksForPort($olt, $slot, $port),
            // Penanda ter-ACS per ONU — dari tabel lokal genieacs_device_map, tanpa memanggil GenieACS.
            'genieacs_map' => $genieacsMap->forPort($olt->id, $slot, $port),
            'port_labels' => $labels->forOlt($olt),
        ]);
    }

    public function refresh(SnmpOlt $olt, CDataOltScanner $scanner): RedirectResponse
    {
        $back = back(fallback: route('hsgq-olt.detail', $olt));

        try {
            $count = $scanner->scan($olt);

            return $back->with('success', sprintf(__('flash.scan_ok_fmt'), $count, $olt->name));
        } catch (Throwable $exception) {
            return $back->with('error', __('flash.onu_scan_failed').$exception->getMessage());
        }
    }

    public function refreshPortOnus(SnmpOlt $olt, int $slot, int $port, SmartOltSnmpServiceResolver $resolver): RedirectResponse
    {
        $back = redirect()->route('hsgq-olt.port-onus', [$olt, $slot, $port]);

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

            return $back->with('success', sprintf(__('flash.refresh_ok_fmt'), count($onus), $slot, $port));
        } catch (Throwable $exception) {
            return $back->with('error', __('flash.onu_refresh_failed').$exception->getMessage());
        }
    }

    public function rebootOnu(Request $request, SnmpOlt $olt, int $slot, int $port, int $onuId, HsgqWebService $web): RedirectResponse
    {
        $this->assertWritable($request, $olt, 'supports_reboot');
        $back = redirect()->route('hsgq-olt.port-onus', [$olt, $slot, $port]);
        $result = $web->reboot($olt, $slot, $port, $onuId);

        return $back->with(
            $result['ok'] ? 'success' : 'error',
            $result['ok']
                ? sprintf(__('flash.reboot_sent_slot_fmt'), $slot, $port, $onuId)
                : __('flash.onu_reboot_failed').$result['error'],
        );
    }

    public function updateOnuInfo(Request $request, SnmpOlt $olt, int $slot, int $port, int $onuId, HsgqWebService $web): RedirectResponse
    {
        $this->assertWritable($request, $olt, 'supports_onu_info_write');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:'.HsgqWebService::NAME_MAX],
            'description' => ['nullable', 'string', 'max:'.HsgqWebService::NAME_MAX],
        ]);
        $back = redirect()->route('hsgq-olt.port-onus', [$olt, $slot, $port]);

        $result = $web->rename($olt, $slot, $port, $onuId, $data['name'], $data['description'] ?? null);
        if (! $result['ok']) {
            return $back->with('error', __('flash.onu_rename_failed').$result['error']);
        }

        $this->mutateCachedOnu($olt, $slot, $port, $onuId, function (array $onu) use ($result) {
            $onu['name'] = $result['name'];
            $onu['description'] = $result['description'] !== '' ? $result['description'] : null;

            return $onu;
        });

        return $back->with('success', __('hsgq.onu_renamed_save_hint'));
    }

    public function saveConfig(Request $request, SnmpOlt $olt, HsgqWebService $web): RedirectResponse
    {
        $this->assertWritable($request, $olt, 'supports_config_save');
        $back = back(fallback: route('hsgq-olt.detail', $olt));
        $result = $web->saveConfig($olt);

        return $back->with(
            $result['ok'] ? 'success' : 'error',
            $result['ok']
                ? __('hsgq.config_saved', ['name' => $olt->name])
                : __('flash.config_save_failed').$result['error'],
        );
    }

    /**
     * Auto-refresh saat halaman dibuka: re-scan penuh hanya bila cache lebih tua dari
     * {@see self::CACHE_TTL_MINUTES}. Gagal → halaman tampil dari cache terakhir.
     */
    private function ensureFreshScan(SnmpOlt $olt, CDataOltScanner $scanner): void
    {
        // OLT demo tak punya perangkat: jangan scan dan jangan menulis cache pada GET.
        if ($olt->is_demo) {
            return;
        }

        $scannedAt = data_get($olt->last_test_result, 'onu_scanned_at');

        if (is_string($scannedAt) && Carbon::parse($scannedAt)->gt(now()->subMinutes(self::CACHE_TTL_MINUTES))) {
            return;
        }

        try {
            $scanner->scan($olt);
        } catch (Throwable) {
            // biarkan halaman tampil dari cache terakhir
        }
    }

    private function assertWritable(Request $request, SnmpOlt $olt, string $capability): void
    {
        abort_unless((bool) $request->user()?->canManageOlt(), 403);
        $this->assertCapability($olt, $capability);
    }

    private function assertCapability(SnmpOlt $olt, string $capability): void
    {
        abort_unless(
            (bool) (SmartOltSupport::capabilities($this->driverOf($olt), $olt)[$capability] ?? false),
            403,
            __('hsgq.action_unsupported'),
        );
    }

    private function driverOf(SnmpOlt $olt): string
    {
        return SmartOltSupport::driverKey(
            $olt,
            data_get($olt->last_test_result, 'system.sys_descr'),
            data_get($olt->last_test_result, 'system.sys_object_id'),
        );
    }

    private function mutateCachedOnu(SnmpOlt $olt, int $slot, int $port, int $onuId, callable $mutator): void
    {
        $snapshot = $olt->last_test_result ?? [];
        $path = "port_onus.{$slot}_{$port}.onus";
        $onus = data_get($snapshot, $path);

        if (! is_array($onus)) {
            return;
        }

        foreach ($onus as $index => $onu) {
            if ((int) ($onu['onu_id'] ?? -1) === $onuId) {
                $onus[$index] = $mutator($onu);
            }
        }

        data_set($snapshot, $path, $onus);
        $olt->forceFill(['last_test_result' => $snapshot])->save();
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

        $onus = collect(data_get($result, 'port_onus', []))->flatMap(fn ($entry) => $entry['onus'] ?? []);

        return [
            'system' => data_get($result, 'system'),
            'ports' => data_get($result, 'ports', []),
            'port_counts' => $counts,
            'scanned_at' => data_get($result, 'onu_scanned_at'),
            // Status ONU belum cocok dengan penghitung OLT → UI memberi peringatan, alarm offline ditahan.
            'status_unverified' => $onus->contains(fn ($onu) => is_array($onu) && ($onu['status_verified'] ?? true) === false),
            'diag' => data_get($result, 'hsgq_diag'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeOlt(SnmpOlt $olt): array
    {
        $driver = $this->driverOf($olt);

        return [
            'id' => $olt->id,
            'name' => $olt->name,
            'vendor' => $olt->vendor,
            'ip' => $olt->ip,
            'snmp_port' => $olt->snmp_port,
            'snmp_version' => $olt->snmp_version,
            'web_port' => $olt->web_port,
            'cli_transport' => $olt->cli_transport,
            'cli_port' => $olt->cli_port,
            'cli_username' => $olt->cli_username,
            // Kolom koneksi terkunci untuk partner pada OLT global yang di-assign.
            'connection_locked' => ! (bool) auth()->user()?->canEditOltConnection($olt),
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
            'snmp_version' => ['required', Rule::in(['v1', 'v2c'])],
            'web_port' => ['nullable', 'integer', 'between:1,65535'],
            'cli_transport' => ['nullable', Rule::in(['telnet', 'ssh'])],
            'cli_port' => ['nullable', 'integer', 'between:1,65535'],
            'cli_username' => ['nullable', 'string', 'max:100'],
            'cli_password' => ['nullable', 'string', 'max:255'],
            'polling_enabled' => ['boolean'],
            'poll_interval_minutes' => ['nullable', 'integer', 'between:1,1440'],
            'rx_poll_interval_minutes' => ['nullable', 'integer', 'between:1,1440'],
        ], [
            'ip.unique' => __('olt.ip_port_taken'),
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
