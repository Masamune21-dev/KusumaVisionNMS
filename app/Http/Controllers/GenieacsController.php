<?php

namespace App\Http\Controllers;

use App\Models\SnmpOlt;
use App\Services\Genieacs\GenieacsDeviceDetailService;
use App\Services\Genieacs\GenieacsDeviceSyncService;
use App\Services\Genieacs\GenieacsManualPinService;
use App\Services\Genieacs\GenieacsWifiService;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Endpoint GenieACS yang dipakai tabel ONU semua family.
 *
 * Rutenya sengaja satu untuk semua family (pola yang sama dengan
 * `onu-odp.assign` dan `olt.port-label.store`) — posisi ONU sudah cukup
 * mengenali perangkat, apa pun vendor OLT-nya. Kepemilikan OLT ditegakkan
 * otomatis oleh route-model binding + PartnerOltScope.
 */
class GenieacsController extends Controller
{
    /**
     * Perangkat yang terhubung ke satu ONU (host LAN + klien WiFi).
     *
     * Memanggil NBI, jadi hanya dipanggil saat pengguna menekan tombol —
     * tidak pernah saat merender daftar ONU.
     */
    public function connectedDevices(
        Request $request,
        SnmpOlt $olt,
        int $slot,
        int $port,
        int $onuId,
        GenieacsDeviceDetailService $detail,
    ): JsonResponse {
        // Isinya data pelanggan (host LAN, sandi WiFi) — sama dengan pin: hanya
        // staf Pusat dan hanya pada OLT yang memakai ACS (User::canUseAcsCatalogOn).
        $this->authorizeAcsCatalog($request, $olt);

        $result = $detail->connectedDevices(
            $olt->id,
            $slot,
            $port,
            $onuId,
            fresh: $request->boolean('fresh'),
        );

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    /**
     * Daftar device ACS untuk pemilih penyematan manual.
     *
     * Hanya membaca tabel lokal `genieacs_device_map`, jadi aman dipanggil
     * berulang selagi pengguna mengetik.
     */
    public function searchDevices(Request $request, GenieacsManualPinService $pins): JsonResponse
    {
        $this->authorizeAcsCatalog($request);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:64'],
            'limit' => ['nullable', 'integer', 'between:1,100'],
        ]);

        return response()->json([
            'ok' => true,
            'devices' => $pins->search(
                $validated['q'] ?? null,
                (int) ($validated['limit'] ?? 25),
            ),
        ]);
    }

    /**
     * Tarik ulang katalog GenieACS sekarang juga, tanpa menunggu jadwal 15 menit.
     *
     * Untuk ONU yang BARU di-set TR069-nya: device sudah tampak di web GenieACS
     * tapi belum ada di tabel lokal, jadi tak bisa dicari di pemilih pin. Ini
     * sinkronisasi yang sama persis dengan `genieacs:match-onu` (projection
     * ringan, ±2 dtk). Dua gerbang supaya tombolnya tak jadi beban ke ACS:
     * kunci (satu sinkronisasi pada satu waktu) dan jeda 20 detik bersama
     * untuk semua pengguna — dalam jeda itu hasil terakhir sudah cukup segar.
     */
    public function refreshDevices(Request $request, GenieacsDeviceSyncService $sync): JsonResponse
    {
        $this->authorizeAcsCatalog($request);

        if (Cache::has('genieacs:device-refresh:cooldown')) {
            return response()->json(['ok' => true, 'skipped' => true]);
        }

        $lock = Cache::lock('genieacs:device-refresh', 120);

        if (! $lock->get()) {
            return response()->json(['ok' => false, 'error' => 'sync_busy'], 409);
        }

        try {
            $result = $sync->sync();
        } finally {
            $lock->release();
        }

        if (! $result['ok']) {
            return response()->json(['ok' => false, 'error' => 'sync_failed'], 502);
        }

        Cache::put('genieacs:device-refresh:cooldown', true, 20);

        return response()->json([
            'ok' => true,
            'skipped' => false,
            'devices' => $result['devices'],
            'duration_ms' => $result['duration_ms'],
        ]);
    }

    /**
     * Sematkan sebuah device ACS ke ONU ini.
     *
     * Yang tersimpan adalah IDENTITAS ONU (serial/MAC), bukan posisinya — lihat
     * {@see GenieacsManualPinService}. Dicatat ke audit karena menentukan
     * perangkat siapa yang muncul di halaman pelanggan ini.
     */
    public function pin(
        Request $request,
        SnmpOlt $olt,
        int $slot,
        int $port,
        int $onuId,
        GenieacsManualPinService $pins,
    ): JsonResponse {
        $this->authorizeAcsCatalog($request, $olt);

        $validated = $request->validate([
            'device_id' => ['required', 'string', 'max:255'],
        ]);

        $result = $pins->pin($validated['device_id'], $olt, $slot, $port, $onuId, $request->user()?->id);

        AuditLogger::log(
            event: $result['ok'] ? 'genieacs.pin.created' : 'genieacs.pin.failed',
            auditable: $olt,
            properties: [
                'slot' => $slot,
                'port' => $port,
                'onu_id' => $onuId,
                'device_id' => $validated['device_id'],
                'manual_ref_type' => $result['manual_ref_type'] ?? null,
                'manual_ref' => $result['manual_ref'] ?? null,
                'error' => $result['error'] ?? null,
            ],
            description: sprintf(
                'Device ACS %s disematkan ke ONU %s slot %d port %d ONU %d%s',
                $validated['device_id'],
                $olt->name,
                $slot,
                $port,
                $onuId,
                $result['ok'] ? '' : ' — GAGAL: '.($result['error'] ?? '?'),
            ),
        );

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    /**
     * Lepas pasangan ACS ONU ini, lalu kembalikan ke pencocokan otomatis.
     */
    public function unpin(
        Request $request,
        SnmpOlt $olt,
        int $slot,
        int $port,
        int $onuId,
        GenieacsManualPinService $pins,
    ): JsonResponse {
        $this->authorizeAcsCatalog($request, $olt);

        $result = $pins->unpin($olt->id, $slot, $port, $onuId);

        AuditLogger::log(
            event: $result['ok'] ? 'genieacs.pin.removed' : 'genieacs.pin.failed',
            auditable: $olt,
            properties: [
                'slot' => $slot,
                'port' => $port,
                'onu_id' => $onuId,
                'device_id' => $result['device_id'] ?? null,
                'rematch_method' => $result['rematch_method'] ?? null,
                'error' => $result['error'] ?? null,
            ],
            description: sprintf(
                'Pasangan ACS ONU %s slot %d port %d ONU %d dilepas%s',
                $olt->name,
                $slot,
                $port,
                $onuId,
                $result['ok'] ? '' : ' — GAGAL: '.($result['error'] ?? '?'),
            ),
        );

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    /**
     * Ubah SSID dan kata sandi WiFi sebuah ONU.
     *
     * SATU-SATUNYA aksi di modul ini yang menulis ke perangkat pelanggan.
     * Karena itu: dibatasi peran tulis, dicatat ke audit, dan tidak pernah
     * dijalankan massal — satu ONU, satu WLAN, satu permintaan.
     */
    public function updateWifi(
        Request $request,
        SnmpOlt $olt,
        int $slot,
        int $port,
        int $onuId,
        GenieacsWifiService $wifi,
    ): JsonResponse {
        $this->authorizeAcsCatalog($request, $olt);

        $validated = $request->validate([
            'ssid' => ['required', 'string', 'min:1', 'max:32'],
            // WPA-PSK: 8-63 karakter. Lebih pendek ditolak ONU dengan CWMP fault,
            // jadi dicegat di sini supaya pesannya jelas, bukan "write_failed".
            'password' => ['required', 'string', 'min:8', 'max:63'],
            'wlan_index' => ['required', 'integer', 'between:1,8'],
            'security_mode' => ['nullable', 'string', 'max:32'],
        ]);

        $result = $wifi->update(
            $olt->id,
            $slot,
            $port,
            $onuId,
            $validated['ssid'],
            $validated['password'],
            (int) $validated['wlan_index'],
            $validated['security_mode'] ?? 'WPA2PSK',
        );

        // Kata sandi TIDAK ikut dicatat — yang direkam cukup siapa mengubah apa.
        AuditLogger::log(
            event: $result['ok'] ? 'genieacs.wifi.updated' : 'genieacs.wifi.failed',
            auditable: $olt,
            properties: [
                'slot' => $slot,
                'port' => $port,
                'onu_id' => $onuId,
                'wlan_index' => (int) $validated['wlan_index'],
                'ssid' => $validated['ssid'],
                'device_id' => $result['device_id'] ?? null,
                'error' => $result['error'] ?? null,
            ],
            description: sprintf(
                'WiFi ONU %s slot %d port %d ONU %d (SSID %d) diubah menjadi "%s"%s',
                $olt->name,
                $slot,
                $port,
                $onuId,
                $validated['wlan_index'],
                $validated['ssid'],
                $result['ok'] ? '' : ' — GAGAL: '.($result['error'] ?? '?'),
            ),
        );

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    /**
     * Katalog ACS milik staf Pusat ({@see \App\Models\User::canManageAcs()}).
     * Untuk aksi pada sebuah ONU, OLT-nya juga harus memakai ACS — OLT global
     * non-demo ({@see \App\Models\User::canUseAcsCatalogOn()}). Gerbang
     * `role:` di rute saja tak cukup: partner ikut lolos.
     */
    private function authorizeAcsCatalog(Request $request, ?SnmpOlt $olt = null): void
    {
        $user = $request->user();

        abort_unless((bool) $user?->canManageAcs(), 403);
        abort_if($olt !== null && ! $user->canUseAcsCatalogOn($olt), 403);
    }
}
