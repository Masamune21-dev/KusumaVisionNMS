<?php

namespace App\Http\Controllers;

use App\Models\OnuMapPin;
use App\Models\PollingEvent;
use App\Models\SnmpOlt;
use App\Services\CData\CDataOltScanner;
use App\Services\Hioso\HiosoCliWriteService;
use App\Services\Hioso\HiosoEponSnmpService;
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
 * Halaman OLT HiOSO / V-Sol EPON (enterprise 25355, mis. HA7304) — inventori & aksi ONU.
 *
 * Dipisah dari {@see CDataOltController} supaya HiOSO punya controller, rute (`hioso-olt.*`), dan
 * halaman (`Hioso/*`) sendiri. Scan penuh memakai {@see CDataOltScanner} bersama (resolver memilih
 * {@see HiosoEponSnmpService}); inventory + Rx via SNMP, aksi tulis (rename &
 * reboot) via CLI telnet {@see HiosoCliWriteService}. Delete/provisioning belum ada (guide §5.6/§13).
 */
class HiosoOltController extends Controller
{
    /** TTL cache auto-refresh: scan ulang saat halaman dibuka hanya bila cache lebih tua dari ini. */
    private const CACHE_TTL_MINUTES = 5;

    public function index(): RedirectResponse
    {
        return redirect()->route('smartolt.index', ['tab' => 'hioso']);
    }

    public function create(): Response
    {
        return Inertia::render('Hioso/Create', [
            'defaults' => [
                'vendor' => 'HiOSO EPON 25355',
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
        $redirect = redirect()->route('smartolt.index', ['tab' => 'hioso']);

        // Scan awal sekali supaya ONU langsung searchable di global search tanpa perlu buka halaman OLT.
        try {
            $count = $scanner->scan($olt);

            return $redirect->with('success', sprintf('OLT ditambahkan. Scan awal: %s ONU.', $count));
        } catch (Throwable $exception) {
            return $redirect->with('success', 'OLT ditambahkan. Scan awal gagal ('.$exception->getMessage().') — akan dicoba lagi saat halaman dibuka.');
        }
    }

    public function edit(SnmpOlt $olt): Response
    {
        return Inertia::render('Hioso/Edit', [
            'olt' => $this->serializeOlt($olt),
        ]);
    }

    public function update(Request $request, SnmpOlt $olt): RedirectResponse
    {
        $olt->update($this->withoutEmptySecrets($this->validated($request, $olt)));

        return redirect()
            ->route('smartolt.index', ['tab' => 'hioso'])
            ->with('success', 'OLT HiOSO berhasil diperbarui.');
    }

    public function destroy(SnmpOlt $olt): RedirectResponse
    {
        $olt->delete();

        return redirect()
            ->route('smartolt.index', ['tab' => 'hioso'])
            ->with('success', 'OLT HiOSO berhasil dihapus.');
    }

    public function test(SnmpOlt $olt, OltSnmpClient $client): RedirectResponse
    {
        // sysDescr/sysObjectID = MIB-II standar (vendor-neutral); driverKey memetakan ke family HiOSO 25355.
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
            ? sprintf(
                'SNMP OK. Family: %s. Latency: %sms.',
                SmartOltSupport::capabilities($result['driver'], $olt)['vendor_family'],
                $result['latency_ms'],
            )
            : sprintf('SNMP gagal: %s', $result['error'] ?? 'unknown error');

        return redirect()
            ->route('smartolt.index', ['tab' => 'hioso'])
            ->with($result['ok'] ? 'success' : 'error', $message);
    }

    public function detail(SnmpOlt $olt, CDataOltScanner $scanner): Response
    {
        $this->ensureFreshScan($olt, $scanner);

        return Inertia::render('Hioso/Detail', [
            'olt' => $this->serializeOlt($olt),
            'snapshot' => $this->serializeSnapshot($olt),
        ]);
    }

    public function portOnus(Request $request, SnmpOlt $olt, int $slot, int $port, CDataOltScanner $scanner): Response
    {
        $this->ensureFreshScan($olt, $scanner);

        return Inertia::render('Hioso/PortOnus', [
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
        ]);
    }

    /**
     * Scan penuh: baca system + ports + seluruh ONU via SNMP (resolver → HiosoEponSnmpService), tulis
     * cache `port_onus` dlm bentuk sama dgn ZTE supaya muncul di ONU Monitoring + global search.
     */
    public function refresh(SnmpOlt $olt, CDataOltScanner $scanner): RedirectResponse
    {
        $back = back(fallback: route('hioso-olt.detail', $olt));

        try {
            $count = $scanner->scan($olt);

            return $back->with('success', sprintf('Scan ONU OK. %s ONU ditemukan di %s.', $count, $olt->name));
        } catch (Throwable $exception) {
            return $back->with('error', 'Scan ONU gagal: '.$exception->getMessage());
        }
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

    public function refreshPortOnus(SnmpOlt $olt, int $slot, int $port, SmartOltSnmpServiceResolver $resolver): RedirectResponse
    {
        $back = redirect()->route('hioso-olt.port-onus', [$olt, $slot, $port]);

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

    public function rebootOnu(SnmpOlt $olt, int $slot, int $port, int $onuId, HiosoCliWriteService $hioso): RedirectResponse
    {
        $this->assertCapability($olt, 'supports_reboot');
        $back = redirect()->route('hioso-olt.port-onus', [$olt, $slot, $port]);

        try {
            $result = $hioso->reboot($olt, $port, $onuId);

            return $back->with(
                $result['ok'] ? 'success' : 'error',
                $result['ok']
                    ? sprintf('Perintah reboot ONU %d/%d/%d terkirim. ONU restart ~30–60 detik.', $slot, $port, $onuId)
                    : 'Reboot ONU selesai dengan indikasi error: '.$result['error'],
            );
        } catch (Throwable $exception) {
            return $back->with('error', 'Reboot ONU gagal: '.$exception->getMessage());
        }
    }

    public function updateOnuInfo(Request $request, SnmpOlt $olt, int $slot, int $port, int $onuId, HiosoCliWriteService $hioso): RedirectResponse
    {
        $this->assertCapability($olt, 'supports_onu_info_write');
        $data = $request->validate(['name' => ['nullable', 'string', 'max:128']]);
        $name = trim((string) ($data['name'] ?? ''));
        $back = redirect()->route('hioso-olt.port-onus', [$olt, $slot, $port]);

        try {
            $result = $hioso->setName($olt, $port, $onuId, $name);
            if (! $result['ok']) {
                return $back->with('error', 'Ubah nama ONU gagal: '.$result['error']);
            }

            $this->mutateCachedOnu($olt, $slot, $port, $onuId, function (array $onu) use ($name) {
                $onu['name'] = $name !== '' ? $name : null;
                $onu['description'] = $onu['name'];

                return $onu;
            });

            return $back->with('success', $name !== '' ? 'Nama ONU berhasil diperbarui.' : 'Nama ONU berhasil dihapus.');
        } catch (Throwable $exception) {
            return $back->with('error', 'Ubah nama ONU gagal: '.$exception->getMessage());
        }
    }

    /**
     * Hapus (deregister) ONU HiOSO via CLI `no onu {id}` di dalam `interface epon 0/{port}`
     * (guide §5.6). Destruktif — gated `supports_onu_delete`.
     */
    public function deleteOnu(SnmpOlt $olt, int $slot, int $port, int $onuId, HiosoCliWriteService $hioso): RedirectResponse
    {
        $this->assertCapability($olt, 'supports_onu_delete');
        $back = redirect()->route('hioso-olt.port-onus', [$olt, $slot, $port]);

        try {
            $result = $hioso->delete($olt, $port, $onuId);

            if ($result['ok']) {
                $this->removeCachedOnu($olt, $slot, $port, $onuId);
            }

            return $back->with(
                $result['ok'] ? 'success' : 'error',
                $result['ok']
                    ? sprintf('ONU %d/%d/%d berhasil dihapus dari OLT.', $slot, $port, $onuId)
                    : 'Hapus ONU selesai dengan indikasi error: '.$result['error'],
            );
        } catch (Throwable $exception) {
            return $back->with('error', 'Hapus ONU gagal: '.$exception->getMessage());
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
