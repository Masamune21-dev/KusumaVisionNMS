<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesOltOwnership;
use App\Models\OnuMapPin;
use App\Models\PollingEvent;
use App\Models\SnmpOlt;
use App\Services\CData\CDataCliWriteService;
use App\Services\CData\CDataOltScanner;
use App\Services\OnuOdpService;
use App\Services\SmartOltSnmpServiceResolver;
use App\Services\Snmp\OltSnmpClient;
use App\Support\SmartOltSupport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
    use ManagesOltOwnership;

    /** Kolom status tabel ONU v3 — walk berisi data = firmware FlashV3.x (guide §3.3 & §5.5). */
    private const CDATA_GPON_V3_STATUS_OID = '1.3.6.1.4.1.34592.1.5.1.1.2.18.12.1.1';

    /** TTL cache auto-refresh: scan ulang saat halaman dibuka hanya bila cache lebih tua dari ini. */
    private const CACHE_TTL_MINUTES = 5;

    public function index(): RedirectResponse
    {
        // Inventori C-Data kini jadi tab di halaman SmartOLT — arahkan ke sana.
        return redirect()->route('smartolt.index', ['tab' => 'cdata']);
    }

    public function create(): Response
    {
        return Inertia::render('CDataOlt/Create', [
            'defaults' => [
                'family' => 'cdata',
                'vendor' => 'C-Data GPON 34592',
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
        $redirect = redirect()->route('smartolt.index', ['tab' => 'cdata']);

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
        return Inertia::render('CDataOlt/Edit', [
            'olt' => $this->serializeOlt($olt),
        ]);
    }

    public function update(Request $request, SnmpOlt $olt): RedirectResponse
    {
        $olt->update($this->withoutEmptySecrets($this->validated($request, $olt)));

        return redirect()
            ->route('smartolt.index', ['tab' => 'cdata'])
            ->with('success', __('flash.olt_cdata_updated'));
    }

    public function destroy(Request $request, SnmpOlt $olt): RedirectResponse
    {
        $this->authorizeOltDeletion($olt, $request->user());
        $olt->delete();

        return redirect()
            ->route('smartolt.index', ['tab' => 'cdata'])
            ->with('success', __('flash.olt_cdata_deleted'));
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
            ->route('smartolt.index', ['tab' => 'cdata'])
            ->with($result['ok'] ? 'success' : 'error', $message);
    }

    public function detail(SnmpOlt $olt, CDataOltScanner $scanner): Response
    {
        $this->ensureFreshScan($olt, $scanner);

        return Inertia::render('CDataOlt/Detail', [
            'olt' => $this->serializeOlt($olt),
            'snapshot' => $this->serializeSnapshot($olt),
        ]);
    }

    public function portOnus(Request $request, SnmpOlt $olt, int $slot, int $port, CDataOltScanner $scanner, OnuOdpService $odpService): Response
    {
        $this->ensureFreshScan($olt, $scanner);

        return Inertia::render('CDataOlt/PortOnus', [
            'olt' => $this->serializeOlt($olt),
            'slot' => $slot,
            'port' => $port,
            'snapshot' => data_get($olt->last_test_result, "port_onus.{$slot}_{$port}"),
            'focus' => $request->query('focus'),
            'q' => $request->query('q'),
            'pinned_onu_ids' => OnuMapPin::query()
                ->where('snmp_olt_id', $olt->id)
                ->where('slot', $slot)
                ->where('port', $port)
                ->pluck('onu_id')
                ->all(),
            'odps' => $odpService->odpsForOlt($olt),
            'odp_links' => $odpService->linksForPort($olt, $slot, $port),
        ]);
    }

    /**
     * Scan penuh: baca system + ports + seluruh ONU (SNMP utk EPON, CLI utk GPON V3), tulis cache
     * `port_onus` dlm bentuk sama dgn ZTE supaya muncul di ONU Monitoring + global search.
     */
    public function refresh(SnmpOlt $olt, CDataOltScanner $scanner): RedirectResponse
    {
        // Kembali ke halaman pemicu (Index atau Detail); fallback ke Detail bila tak ada referer.
        $back = back(fallback: route('cdata-olt.detail', $olt));

        try {
            $count = $scanner->scan($olt);

            return $back->with('success', sprintf(__('flash.scan_ok_fmt'), $count, $olt->name));
        } catch (Throwable $exception) {
            return $back->with('error', __('flash.onu_scan_failed').$exception->getMessage());
        }
    }

    /**
     * Auto-refresh saat halaman Detail/PortOnus dibuka: re-scan penuh hanya bila cache lebih tua dari
     * {@see self::CACHE_TTL_MINUTES} atau belum pernah di-scan. Sinkron — menunggu scan selesai sebelum
     * render (EPON via SNMP cepat, GPON V3 via CLI ~10 detik). Kegagalan diabaikan agar halaman tetap
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

            return $back->with('success', sprintf(__('flash.refresh_ok_fmt'), count($onus), $slot, $port));
        } catch (Throwable $exception) {
            return $back->with('error', __('flash.onu_refresh_failed').$exception->getMessage());
        }
    }

    public function rebootOnu(SnmpOlt $olt, int $slot, int $port, int $onuId, CDataCliWriteService $writer): RedirectResponse
    {
        $this->assertCapability($olt, 'supports_reboot');
        $back = redirect()->route('cdata-olt.port-onus', [$olt, $slot, $port]);

        try {
            $result = $writer->reboot($olt, $this->ifaceKeyword($olt), $slot, $port, $onuId);

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
     * Enable/disable ONU C-Data via CLI (`ont enable|disable` EPON, `ont activate|deactivate` GPON).
     * Tidak menghapus registrasi — hanya aktif/nonaktif. Gated `supports_onu_toggle`.
     */
    public function setOnuState(Request $request, SnmpOlt $olt, int $slot, int $port, int $onuId, CDataCliWriteService $writer): RedirectResponse
    {
        $this->assertCapability($olt, 'supports_onu_toggle');
        $active = (bool) $request->validate(['active' => ['required', 'boolean']])['active'];
        $back = redirect()->route('cdata-olt.port-onus', [$olt, $slot, $port]);

        try {
            $result = $writer->setState($olt, $this->ifaceKeyword($olt), $slot, $port, $onuId, $active);
            if (! $result['ok']) {
                return $back->with('error', __('flash.onu_state_warn').$result['error']);
            }

            $this->mutateCachedOnu($olt, $slot, $port, $onuId, function (array $onu) use ($active) {
                $onu['admin_state'] = $active ? 'enable' : 'disable';

                return $onu;
            });

            return $back->with('success', $active ? __('flash.onu_enabled') : __('flash.onu_disabled'));
        } catch (Throwable $exception) {
            return $back->with('error', __('flash.onu_state_failed').$exception->getMessage());
        }
    }

    public function updateOnuInfo(Request $request, SnmpOlt $olt, int $slot, int $port, int $onuId, CDataCliWriteService $writer): RedirectResponse
    {
        $this->assertCapability($olt, 'supports_onu_info_write');
        $data = $request->validate(['name' => ['nullable', 'string', 'max:128']]);
        $name = trim((string) ($data['name'] ?? ''));
        $back = redirect()->route('cdata-olt.port-onus', [$olt, $slot, $port]);

        try {
            $result = $writer->setDescription($olt, $this->ifaceKeyword($olt), $slot, $port, $onuId, $name);
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
     * Buka/tutup akses remote web ONT via CLI `ont security-mgmt` (GPON FlashV3 saja).
     * Gated `supports_onu_remote_access`. State terakhir di-cache di baris ONU (`remote_web`)
     * sebagai indikator UI — hilang (kembali unknown) setelah scan penuh, bukan sumber kebenaran.
     */
    public function setOnuRemoteAccess(Request $request, SnmpOlt $olt, int $slot, int $port, int $onuId, CDataCliWriteService $writer): RedirectResponse
    {
        $this->assertCapability($olt, 'supports_onu_remote_access');
        $enable = (bool) $request->validate(['enable' => ['required', 'boolean']])['enable'];
        $back = redirect()->route('cdata-olt.port-onus', [$olt, $slot, $port]);

        try {
            $result = $writer->setRemoteAccess($olt, $this->ifaceKeyword($olt), $slot, $port, $onuId, $enable);
            if (! $result['ok']) {
                return $back->with('error', __('flash.onu_remote_warn').$result['error']);
            }

            $this->mutateCachedOnu($olt, $slot, $port, $onuId, function (array $onu) use ($enable) {
                $onu['remote_web'] = $enable;

                return $onu;
            });

            return $back->with('success', $enable ? __('flash.onu_remote_enabled') : __('flash.onu_remote_disabled'));
        } catch (Throwable $exception) {
            return $back->with('error', __('flash.onu_remote_failed').$exception->getMessage());
        }
    }

    /**
     * Hapus (deregister) ONU dari OLT C-Data via CLI `ont delete {port} {onuId}`
     * (sintaks identik EPON & GPON). Destruktif — gated `supports_onu_delete`.
     */
    public function deleteOnu(SnmpOlt $olt, int $slot, int $port, int $onuId, CDataCliWriteService $writer): RedirectResponse
    {
        $this->assertCapability($olt, 'supports_onu_delete');
        $back = redirect()->route('cdata-olt.port-onus', [$olt, $slot, $port]);

        try {
            $result = $writer->delete($olt, $this->ifaceKeyword($olt), $slot, $port, $onuId);

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

    /**
     * Simpan running-config OLT ke memori via CLI (`enable` → `config` → `save`). Sinkron.
     * Gated capability `supports_config_save`.
     */
    public function saveConfig(SnmpOlt $olt, CDataCliWriteService $writer): RedirectResponse
    {
        $this->assertCapability($olt, 'supports_config_save');
        $back = back(fallback: route('cdata-olt.detail', $olt));

        try {
            $result = $writer->saveConfig($olt);

            return $back->with(
                $result['ok'] ? 'success' : 'error',
                $result['ok']
                    ? __('flash.config_saved', ['name' => $olt->name])
                    : __('flash.config_save_warn').$result['error'],
            );
        } catch (Throwable $exception) {
            return $back->with('error', __('flash.config_save_failed').$exception->getMessage());
        }
    }

    private function ifaceKeyword(SnmpOlt $olt): string
    {
        return $this->driverOf($olt) === SmartOltSupport::DRIVER_CDATA_EPON ? 'epon' : 'gpon';
    }

    private function driverOf(SnmpOlt $olt): string
    {
        return SmartOltSupport::driverKey(
            $olt,
            data_get($olt->last_test_result, 'system.sys_descr'),
            data_get($olt->last_test_result, 'system.sys_object_id'),
        );
    }

    private function assertCapability(SnmpOlt $olt, string $capability): void
    {
        abort_unless(
            (bool) (SmartOltSupport::capabilities($this->driverOf($olt), $olt)[$capability] ?? false),
            403,
            'Aksi ini tidak didukung untuk OLT ini.',
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
            if ((int) ($onu['onu_id'] ?? 0) === $onuId) {
                $onus[$index] = $mutator($onu);
            }
        }

        data_set($snapshot, $path, $onus);
        $olt->forceFill(['last_test_result' => $snapshot])->save();
    }

    /**
     * Buang satu ONU dari cache `port_onus` setelah delete sukses, dan sesuaikan count.
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
            'panel' => data_get($result, 'panel'),
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
