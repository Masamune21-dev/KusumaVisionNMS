<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SnmpOlt;
use App\Models\User;
use App\Services\Genieacs\GenieacsDeviceDetailService;
use App\Services\Genieacs\GenieacsWifiService;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Aksi GenieACS untuk aplikasi mobile.
 *
 * Sengaja tipis: seluruh logikanya dipakai bersama halaman web lewat
 * {@see GenieacsDeviceDetailService} dan {@see GenieacsWifiService}, jadi
 * perilaku web dan aplikasi tidak bisa menyimpang diam-diam.
 *
 * Penanda "ONU ini sudah ter-ACS atau belum" TIDAK ada di sini — field `acs`
 * sudah ikut pada daftar ONU (`OnuInventoryService::forPort()`), sehingga
 * aplikasi tidak perlu memanggil apa pun tambahan untuk menampilkannya.
 */
class GenieacsController extends Controller
{
    /**
     * Perangkat yang terhubung ke satu ONU (host LAN + klien WiFi).
     */
    public function connectedDevices(
        Request $request,
        SnmpOlt $olt,
        int $slot,
        int $port,
        int $onuId,
        GenieacsDeviceDetailService $detail,
    ): JsonResponse {
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
     * Ubah SSID & kata sandi WiFi satu ONU.
     *
     * Menulis ke perangkat pelanggan — berada di grup tulis API
     * (`role:admin,operator,partner` + `BlockDemoWrites`) dan dicatat ke audit.
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

        // Kata sandi tidak ikut dicatat.
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
                'channel' => 'mobile',
            ],
            description: sprintf(
                '[Aplikasi] WiFi ONU %s slot %d port %d ONU %d (SSID %d) diubah menjadi "%s"%s',
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
     * Sama dengan web: data & tulis lewat ACS hanya untuk staf Pusat, dan hanya
     * pada OLT yang memakai ACS ({@see User::canUseAcsCatalogOn()}).
     */
    private function authorizeAcsCatalog(Request $request, SnmpOlt $olt): void
    {
        abort_unless((bool) $request->user()?->canUseAcsCatalogOn($olt), 403);
    }
}
