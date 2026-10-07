<?php

namespace App\Services\Genieacs;

use App\Models\GenieacsCredential;
use App\Models\GenieacsDeviceMap;
use Illuminate\Support\Facades\Cache;

/**
 * Ubah nama (SSID) dan kata sandi WiFi sebuah ONU lewat GenieACS.
 *
 * Satu-satunya jalur di modul ini yang MENULIS ke perangkat pelanggan, jadi
 * bentuknya sengaja sempit: satu ONU, satu WLAN, satu aksi.
 *
 * Penulisan didelegasikan ke {@see GenieACSService::setWiFiConfigForDevice()}
 * yang diport dari dashboard GACS. Kelebihannya: ia membaca pohon parameter
 * device lebih dulu, lalu HANYA menulis jalur yang benar-benar ada pada ONU itu
 * — jalur standar (`KeyPassphrase`, `PreSharedKey.1.KeyPassphrase`) maupun
 * jalur vendor (`X_CMS_KeyPassphrase` untuk C-Data, `X_CT-COM_`, `X_ZTE-COM_`,
 * dan seterusnya). Itu penting di sini: pada armada ini `PreSharedKey` NOL
 * terisi di 1.214 unit C-Data, yang dipakai justru `X_CMS_KeyPassphrase`
 * (tersedia di 1.206 device untuk WLAN 1 maupun 2).
 */
class GenieacsWifiService
{
    /** Menulis butuh menunggu ONU merespons connection request. */
    private const REQUEST_TIMEOUT = 45;

    private const CONNECT_TIMEOUT = 10;

    /**
     * @return array<string, mixed>
     */
    public function update(
        int $oltId,
        int $slot,
        int $port,
        int $onuId,
        string $ssid,
        string $password,
        int $wlanIndex = 1,
        string $securityMode = 'WPA2PSK',
    ): array {
        $row = GenieacsDeviceMap::query()
            ->where('snmp_olt_id', $oltId)
            ->where('slot', $slot)
            ->where('port', $port)
            ->where('onu_id', $onuId)
            ->first();

        if (! $row) {
            return ['ok' => false, 'error' => 'not_linked'];
        }

        $client = GenieacsCredential::client(self::REQUEST_TIMEOUT, self::CONNECT_TIMEOUT);

        if (! $client) {
            return ['ok' => false, 'error' => 'not_configured'];
        }

        // Pohon parameter dibaca dulu: penulis memakainya untuk memilih jalur
        // passphrase yang memang dimiliki ONU ini, bukan menebak.
        $device = $client->getDevice($row->device_id);

        if (! ($device['success'] ?? false)) {
            return ['ok' => false, 'error' => $device['error'] ?? 'unreachable'];
        }

        $result = $client->setWiFiConfigForDevice(
            $row->device_id,
            $device['data'] ?? [],
            $ssid,
            $password,
            $wlanIndex,
            $securityMode,
        );

        if ($result['success'] ?? false) {
            // Panel perangkat terhubung membaca dokumen yang sama; buang
            // cachenya supaya SSID baru langsung terlihat.
            Cache::forget("genieacs:clients:{$row->device_id}");
        }

        return [
            'ok' => (bool) ($result['success'] ?? false),
            'error' => $result['success'] ?? false ? null : ($result['error'] ?? 'write_failed'),
            'device_id' => $row->device_id,
            'wlan_index' => $wlanIndex,
            'ssid' => $ssid,
        ];
    }
}
