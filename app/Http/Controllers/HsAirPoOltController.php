<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesOltOwnership;
use App\Jobs\RefreshHsAirPoPortRxJob;
use App\Models\OnuMapPin;
use App\Models\PollingEvent;
use App\Models\SnmpOlt;
use App\Services\CData\CDataOltScanner;
use App\Services\HsAirPo\HsAirPoCliService;
use App\Services\HsAirPo\HsAirPoEponService;
use App\Services\OltPortLabelService;
use App\Services\OnuOdpService;
use App\Services\SmartOltSnmpServiceResolver;
use App\Services\Snmp\OltSnmpClient;
use App\Support\SmartOltSupport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Halaman OLT HsAirPo / HSGQ EPON (OEM Photon Broadband, enterprise 12170).
 *
 * Family CLI-first: SNMP perangkat ini tak punya tabel ONU sama sekali, jadi inventori dibaca
 * {@see HsAirPoEponService} lewat CLI (`show epon onu all info`) sementara sistem & port PON dari
 * MIB-2. Scan penuh memakai {@see CDataOltScanner} bersama (resolver memilih driver-nya) sehingga
 * ONU muncul di ONU Monitoring, global search, alarm, dan peta seperti family lain.
 *
 * **Aksi tulis ONU via CLI** {@see HsAirPoCliService} (config `epon port {pon} onu {onu} <verb>`):
 * rename (`description`), reboot, delete — terverifikasi live via context-help. **Rx per-ONU** via
 * `show epon port {pon} onu {onu} optical-info` (~2 dtk/ONU): tombol per-ONU di kolom Rx
 * ({@see self::refreshOnuRx}) ATAU se-port di **background** ({@see self::refreshPortRx} →
 * {@see RefreshHsAirPoPortRxJob}) — TAK sinkron se-port karena puluhan ONU menembus 100 dtk (504). Rx
 * disimpan di side-store `hsairpo_rx` yang selamat dari scan ({@see self::mergeRx}/{@see self::putRx}).
 * Enable/disable & save-config belum dibuka (semantik belum diuji live).
 */
class HsAirPoOltController extends Controller
{
    use ManagesOltOwnership;

    /** TTL cache auto-refresh: scan ulang saat halaman dibuka hanya bila cache lebih tua dari ini. */
    private const CACHE_TTL_MINUTES = 5;

    public function index(): RedirectResponse
    {
        return redirect()->route('smartolt.index', ['tab' => 'hsairpo']);
    }

    public function create(): Response
    {
        return Inertia::render('HsAirPo/Create', [
            'defaults' => [
                'vendor' => 'HsAirPo HSGQ EPON 12170',
                'snmp_port' => 161,
                'snmp_version' => 'v2c',
                'cli_transport' => 'telnet',
                'cli_port' => 23,
            ],
        ]);
    }

    public function store(Request $request, CDataOltScanner $scanner): RedirectResponse
    {
        $olt = SnmpOlt::create($this->validated($request));
        $this->claimOltForPartner($olt, $request->user());
        $redirect = redirect()->route('smartolt.index', ['tab' => 'hsairpo']);

        // Scan awal sekali supaya ONU langsung searchable di global search tanpa perlu buka halaman OLT.
        try {
            $count = $scanner->scan($olt);

            return $redirect->with('success', sprintf(__('flash.olt_added_scan_fmt'), $count));
        } catch (Throwable $exception) {
            return $redirect->with('success', __('flash.olt_added_scan_failed').$exception->getMessage().') — akan dicoba lagi saat halaman dibuka.');
        }
    }

    public function edit(SnmpOlt $olt): Response
    {
        return Inertia::render('HsAirPo/Edit', [
            'olt' => $this->serializeOlt($olt),
        ]);
    }

    public function update(Request $request, SnmpOlt $olt): RedirectResponse
    {
        $olt->update($this->withoutEmptySecrets($this->validated($request, $olt)));

        return redirect()
            ->route('smartolt.index', ['tab' => 'hsairpo'])
            ->with('success', __('flash.olt_hsairpo_updated'));
    }

    public function destroy(Request $request, SnmpOlt $olt): RedirectResponse
    {
        $this->authorizeOltDeletion($olt, $request->user());
        $olt->delete();

        return redirect()
            ->route('smartolt.index', ['tab' => 'hsairpo'])
            ->with('success', __('flash.olt_hsairpo_deleted'));
    }

    public function test(SnmpOlt $olt, OltSnmpClient $client): RedirectResponse
    {
        // sysDescr HsAirPo KOSONG; sysObjectID `.1.3.6.1.4.1.12170.2.3` yang memetakan family-nya.
        $result = $client->test($olt);

        // Test hanya cek koneksi — TIDAK memuat port_onus. Merge ke cache scan terakhir
        // supaya inventori ONU tak terhapus saat menekan Test.
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
            ? sprintf(
                'SNMP OK. Family: %s. Latency: %sms.',
                SmartOltSupport::capabilities($result['driver'], $olt)['vendor_family'],
                $result['latency_ms'],
            )
            : sprintf(__('flash.snmp_failed_fmt'), $result['error'] ?? 'unknown error');

        return redirect()
            ->route('smartolt.index', ['tab' => 'hsairpo'])
            ->with($result['ok'] ? 'success' : 'error', $message);
    }

    public function detail(SnmpOlt $olt, CDataOltScanner $scanner, OltPortLabelService $labels): Response
    {
        $this->ensureFreshScan($olt, $scanner);

        return Inertia::render('HsAirPo/Detail', [
            'olt' => $this->serializeOlt($olt),
            'snapshot' => $this->serializeSnapshot($olt),
            'port_labels' => $labels->forOlt($olt),
        ]);
    }

    public function portOnus(Request $request, SnmpOlt $olt, int $slot, int $port, CDataOltScanner $scanner, OnuOdpService $odpService, OltPortLabelService $labels): Response
    {
        $this->ensureFreshScan($olt, $scanner);

        return Inertia::render('HsAirPo/PortOnus', [
            'olt' => $this->serializeOlt($olt),
            'slot' => $slot,
            'port' => $port,
            // Gabungkan Rx dari side-store (`hsairpo_rx`) yang SELAMAT dari scan ke daftar ONU snapshot.
            'snapshot' => $this->mergeRx($olt, $slot, $port, data_get($olt->last_test_result, "port_onus.{$slot}_{$port}")),
            'rx_status' => data_get($olt->last_test_result, "hsairpo_rx_status.{$slot}_{$port}"),
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
            'port_labels' => $labels->forOlt($olt),
        ]);
    }

    /**
     * Scan penuh: sistem + port PON via SNMP, seluruh ONU via CLI, lalu tulis cache `port_onus`
     * dalam bentuk sama dengan ZTE supaya muncul di ONU Monitoring + global search.
     */
    public function refresh(SnmpOlt $olt, CDataOltScanner $scanner): RedirectResponse
    {
        $back = back(fallback: route('hsairpo-olt.detail', $olt));

        try {
            $count = $scanner->scan($olt);

            return $back->with('success', sprintf(__('flash.scan_ok_fmt'), $count, $olt->name));
        } catch (Throwable $exception) {
            return $back->with('error', __('flash.onu_scan_failed').$exception->getMessage());
        }
    }

    /**
     * Refresh satu port. Inventori family ini hanya bisa dibaca sekaligus (`show epon onu all
     * info`), jadi biayanya sama dengan scan penuh — hasilnya disaring ke port yang diminta. Sekalian
     * mengambil **Rx per-ONU** (CLI `optical-info`, hanya ONU online) dan menggabungkannya — ini tempat
     * satu-satunya Rx diambil karena mahal (1 perintah/ONU, varian `all` membekukan CLI).
     */
    public function refreshPortOnus(SnmpOlt $olt, int $slot, int $port, SmartOltSnmpServiceResolver $resolver): RedirectResponse
    {
        $back = redirect()->route('hsairpo-olt.port-onus', [$olt, $slot, $port]);

        try {
            // Inventori saja (cepat, 1 perintah CLI). Rx TIDAK diambil di sini — untuk port padat
            // (puluhan ONU × ~2 dtk/ONU) itu menembus batas 100 dtk Cloudflare → 504. Rx diambil per-ONU
            // (tombol kolom Rx) atau se-port di background ({@see self::refreshPortRx}); nilainya disimpan
            // di side-store `hsairpo_rx` yang tak terhapus refresh inventori ini.
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

    /**
     * Ambil Rx SATU ONU (CLI `optical-info`) dan gabungkan ke cache — dipanggil tombol per-ONU di kolom
     * Rx. Cepat (~2-3 dtk) sehingga tak kena 504 seperti pengambilan Rx sekaligus se-port. Gated
     * `supports_cli_rx`.
     */
    public function refreshOnuRx(SnmpOlt $olt, int $slot, int $port, int $onuId, HsAirPoCliService $cli): RedirectResponse
    {
        $this->assertCapability($olt, 'supports_cli_rx');
        $back = redirect()->route('hsairpo-olt.port-onus', [$olt, $slot, $port]);

        try {
            $entry = $cli->fetchPortRx($olt, $port, [$onuId])[$onuId] ?? null;
            $this->putRx($olt, $slot, $port, $onuId, $entry);

            return $back->with(
                $entry !== null ? 'success' : 'error',
                $entry !== null
                    ? sprintf(__('flash.rx_read_ok_fmt'), $onuId, $entry['label'])
                    : sprintf(__('flash.rx_read_none_fmt'), $onuId),
            );
        } catch (Throwable $exception) {
            return $back->with('error', __('flash.rx_read_failed').$exception->getMessage());
        }
    }

    /**
     * Ambil Rx SELURUH ONU online 1 port di **background** ({@see RefreshHsAirPoPortRxJob}). Dipakai
     * karena port padat (puluhan ONU × ~2 dtk) menembus 100 dtk Cloudflare bila dikerjakan sinkron.
     * Set status `running` lalu dispatch; halaman mem-poll & mengisi Rx bertahap. Gated `supports_cli_rx`.
     */
    public function refreshPortRx(SnmpOlt $olt, int $slot, int $port): RedirectResponse
    {
        $this->assertCapability($olt, 'supports_cli_rx');
        $back = redirect()->route('hsairpo-olt.port-onus', [$olt, $slot, $port]);

        $online = collect(data_get($olt->last_test_result, "port_onus.{$slot}_{$port}.onus", []))
            ->where('online', true)
            ->count();

        if ($online === 0) {
            return $back->with('error', __('flash.rx_port_empty'));
        }

        $snapshot = $olt->last_test_result ?? [];
        data_set($snapshot, "hsairpo_rx_status.{$slot}_{$port}", [
            'status' => 'running',
            'total' => $online,
            'done' => 0,
            'at' => now()->toIso8601String(),
        ]);
        $olt->forceFill(['last_test_result' => $snapshot])->save();

        RefreshHsAirPoPortRxJob::dispatch($olt->id, $slot, $port);

        return $back->with('success', sprintf(__('flash.rx_port_dispatched_fmt'), $online));
    }

    /**
     * Simpan Rx satu ONU ke side-store `hsairpo_rx.{slot}_{port}.{onu}` (di LUAR `port_onus`) sehingga
     * SELAMAT dari scan/poll — scanner hanya menulis ulang `port_onus`. null = buang Rx basi.
     *
     * @param  array{dbm: float, label: string}|null  $entry
     */
    private function putRx(SnmpOlt $olt, int $slot, int $port, int $onuId, ?array $entry): void
    {
        $snapshot = $olt->last_test_result ?? [];
        $path = "hsairpo_rx.{$slot}_{$port}.{$onuId}";

        if ($entry === null) {
            Arr::forget($snapshot, $path);
        } else {
            data_set($snapshot, $path, ['dbm' => $entry['dbm'], 'label' => $entry['label'], 'at' => now()->toIso8601String()]);
        }

        $olt->forceFill(['last_test_result' => $snapshot])->save();
    }

    /**
     * Gabungkan Rx side-store ke daftar ONU snapshot untuk ditampilkan (tanpa mengubah cache).
     *
     * @param  array<string, mixed>|null  $snapshot
     * @return array<string, mixed>|null
     */
    private function mergeRx(SnmpOlt $olt, int $slot, int $port, ?array $snapshot): ?array
    {
        if (! is_array($snapshot) || ! isset($snapshot['onus']) || ! is_array($snapshot['onus'])) {
            return $snapshot;
        }

        $rx = (array) data_get($olt->last_test_result, "hsairpo_rx.{$slot}_{$port}", []);
        if ($rx === []) {
            return $snapshot;
        }

        foreach ($snapshot['onus'] as &$onu) {
            $entry = $rx[(int) $onu['onu_id']] ?? $rx[(string) $onu['onu_id']] ?? null;
            if (is_array($entry)) {
                $onu['rx_power_dbm'] = $entry['dbm'] ?? null;
                $onu['rx_power_label'] = $entry['label'] ?? null;
                $onu['rx_power_source'] = 'cli';
            }
        }
        unset($onu);

        return $snapshot;
    }

    /**
     * Reboot satu ONU via CLI. Gated `supports_reboot`.
     */
    public function rebootOnu(SnmpOlt $olt, int $slot, int $port, int $onuId, HsAirPoCliService $cli): RedirectResponse
    {
        $this->assertCapability($olt, 'supports_reboot');
        $back = redirect()->route('hsairpo-olt.port-onus', [$olt, $slot, $port]);

        try {
            $result = $cli->reboot($olt, $port, $onuId);

            return $back->with(
                $result['ok'] ? 'success' : 'error',
                $result['ok']
                    ? sprintf(__('flash.reboot_sent_slot_fmt'), $slot, $port, $onuId)
                    : __('flash.reboot_warn').$result['error'],
            );
        } catch (Throwable $exception) {
            return $back->with('error', __('flash.onu_reboot_failed').$exception->getMessage());
        }
    }

    /**
     * Set nama/deskripsi ONU via CLI `... description`. Gated `supports_onu_info_write`.
     */
    public function updateOnuInfo(Request $request, SnmpOlt $olt, int $slot, int $port, int $onuId, HsAirPoCliService $cli): RedirectResponse
    {
        $this->assertCapability($olt, 'supports_onu_info_write');
        $data = $request->validate(['name' => ['nullable', 'string', 'max:64']]);
        $name = trim((string) ($data['name'] ?? ''));
        $back = redirect()->route('hsairpo-olt.port-onus', [$olt, $slot, $port]);

        try {
            $result = $cli->setDescription($olt, $port, $onuId, $name);
            if (! $result['ok']) {
                return $back->with('error', __('flash.onu_rename_failed').$result['error']);
            }

            $this->mutateCachedOnu($olt, $slot, $port, $onuId, function (array $onu) use ($name) {
                $onu['name'] = $name !== '' ? $name : null;
                $onu['description'] = $onu['name'];

                return $onu;
            });

            return $back->with('success', $name !== '' ? __('flash.onu_renamed') : __('flash.onu_name_cleared'));
        } catch (Throwable $exception) {
            return $back->with('error', __('flash.onu_rename_failed').$exception->getMessage());
        }
    }

    /**
     * Hapus ONU via CLI `... delete`. Destruktif — gated `supports_onu_delete`.
     */
    public function deleteOnu(SnmpOlt $olt, int $slot, int $port, int $onuId, HsAirPoCliService $cli): RedirectResponse
    {
        $this->assertCapability($olt, 'supports_onu_delete');
        $back = redirect()->route('hsairpo-olt.port-onus', [$olt, $slot, $port]);

        try {
            $result = $cli->delete($olt, $port, $onuId);

            if ($result['ok']) {
                $this->removeCachedOnu($olt, $slot, $port, $onuId);
            }

            return $back->with(
                $result['ok'] ? 'success' : 'error',
                $result['ok']
                    ? sprintf(__('flash.onu_deleted_fmt'), $slot, $port, $onuId)
                    : __('flash.onu_delete_warn').$result['error'],
            );
        } catch (Throwable $exception) {
            return $back->with('error', __('flash.onu_delete_failed').$exception->getMessage());
        }
    }

    private function assertCapability(SnmpOlt $olt, string $capability): void
    {
        abort_unless(
            (bool) (SmartOltSupport::capabilities($this->driverOf($olt), $olt)[$capability] ?? false),
            403,
            'Aksi ini tidak didukung untuk OLT ini.',
        );
    }

    /**
     * Ubah satu ONU di cache `port_onus` (mis. setelah rename), lalu simpan.
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
     * Buang satu ONU dari cache `port_onus` setelah delete sukses, sesuaikan count.
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

    /**
     * Auto-refresh saat halaman Detail/PortOnus dibuka: re-scan penuh hanya bila cache lebih tua dari
     * {@see self::CACHE_TTL_MINUTES} atau belum pernah di-scan. Kegagalan diabaikan agar halaman tetap
     * tampil dari cache terakhir; tombol refresh manual yang akan memunculkan pesan error.
     */
    private function ensureFreshScan(SnmpOlt $olt, CDataOltScanner $scanner): void
    {
        $scannedAt = data_get($olt->last_test_result, 'onu_scanned_at');

        if (is_string($scannedAt) && Carbon::parse($scannedAt)->gt(now()->subMinutes(self::CACHE_TTL_MINUTES))) {
            return; // cache masih segar dalam jendela TTL
        }

        try {
            $scanner->scan($olt);
        } catch (Throwable) {
            // biarkan halaman tampil dari cache terakhir
        }
    }

    private function driverOf(SnmpOlt $olt): string
    {
        return SmartOltSupport::driverKey(
            $olt,
            data_get($olt->last_test_result, 'system.sys_descr'),
            data_get($olt->last_test_result, 'system.sys_object_id'),
        );
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
            'scanned_at' => data_get($result, 'onu_scanned_at'),
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
            // CLI wajib untuk family ini: inventori ONU HANYA ada di CLI (SNMP tak punya tabel ONU).
            'cli_transport' => ['nullable', Rule::in(['telnet'])],
            'cli_port' => ['nullable', 'integer', 'between:1,65535'],
            'cli_username' => ['nullable', 'string', 'max:100'],
            'cli_password' => ['nullable', 'string', 'max:255'],
            'polling_enabled' => ['boolean'],
            'poll_interval_minutes' => ['nullable', 'integer', 'between:1,1440'],
            'rx_poll_interval_minutes' => ['nullable', 'integer', 'between:1,1440'],
        ], [
            'ip.unique' => 'Kombinasi IP + SNMP port ini sudah dipakai OLT lain. Ubah SNMP port bila ingin memakai IP yang sama.',
            'cli_transport.in' => 'CLI HsAirPo hanya mendukung Telnet.',
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
