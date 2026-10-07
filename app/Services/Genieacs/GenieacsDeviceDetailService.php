<?php

namespace App\Services\Genieacs;

use App\Models\GenieacsCredential;
use App\Models\GenieacsDeviceMap;
use Illuminate\Support\Facades\Cache;

/**
 * Detail satu device GenieACS untuk panel "perangkat terhubung" di tabel ONU.
 *
 * Berbeda dari {@see GenieacsMapService} yang hanya membaca tabel lokal, kelas
 * ini MEMANGGIL NBI — jadi hanya boleh dipakai atas permintaan pengguna (klik
 * tombol), tidak pernah saat merender daftar. Hasilnya di-cache pendek supaya
 * membuka-menutup panel berulang tidak membebani ACS.
 */
class GenieacsDeviceDetailService
{
    private const CACHE_TTL_SECONDS = 30;

    private const REQUEST_TIMEOUT = 20;

    private const CONNECT_TIMEOUT = 5;

    /**
     * Daftar perangkat yang terhubung ke satu ONU.
     *
     * @return array<string, mixed>
     */
    public function connectedDevices(int $oltId, int $slot, int $port, int $onuId, bool $fresh = false): array
    {
        $row = GenieacsDeviceMap::query()
            ->where('snmp_olt_id', $oltId)
            ->where('slot', $slot)
            ->where('port', $port)
            ->where('onu_id', $onuId)
            ->first();

        if (! $row) {
            return $this->failure('not_linked');
        }

        $cacheKey = "genieacs:clients:{$row->device_id}";

        if ($fresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($row) {
            $client = GenieacsCredential::client(self::REQUEST_TIMEOUT, self::CONNECT_TIMEOUT);

            if (! $client) {
                return $this->failure('not_configured');
            }

            $response = $client->getDevice($row->device_id);

            if (! ($response['success'] ?? false)) {
                return $this->failure($response['error'] ?? 'unreachable');
            }

            $doc = $response['data'] ?? [];
            $parsed = GenieACSParserService::parseFull($doc);

            $hosts = $this->markActive(
                array_values($parsed['lan_hosts'] ?? []),
                $parsed['wifi_networks'] ?? [],
                $doc,
            );

            $active = array_values(array_filter($hosts, fn (array $h) => $h['active'] === true));
            $unknown = array_values(array_filter($hosts, fn (array $h) => $h['active'] === null));

            return [
                'ok' => true,
                'error' => null,
                'device_id' => $row->device_id,
                'product_class' => $row->product_class,
                'last_inform_at' => $row->last_inform_at?->toIso8601String(),
                'hosts' => $hosts,
                'active_count' => count($active),
                'unknown_count' => count($unknown),
                'total_count' => count($hosts),
                'wifi_networks' => array_values($parsed['wifi_networks'] ?? []),
                'fetched_at' => now()->toIso8601String(),
            ];
        });
    }

    /**
     * Tandai perangkat mana yang BENAR-BENAR tersambung saat ini.
     *
     * Tabel `Hosts.Host` milik ONU adalah tabel sewa DHCP: isinya menumpuk dan
     * memuat perangkat yang sudah pergi selama sewanya belum kedaluwarsa. Pada
     * satu unit nyata tabelnya berisi 64 entri sementara yang benar-benar
     * tersambung hanya 3. Jadi jumlah barisnya TIDAK boleh disamakan dengan
     * "perangkat terhubung".
     *
     * Dua sumber bukti, diurut dari yang paling kuat:
     *   1. Tabel asosiasi WiFi (`AssociatedDevice`) — daftar radio, hanya berisi
     *      klien yang sedang tersambung. Tersedia di 1.421 device.
     *   2. Field `Active` pada entri host — untuk perangkat berkabel. Saat ini
     *      BELUM diambil provision (2 dari 2.188 device), jadi hampir selalu
     *      tak diketahui sampai `Hosts.Host.*.Active` ikut di-declare.
     *
     * Yang tak terbukti ditandai `null` (tidak diketahui), bukan dipaksa jadi
     * false — menghapus perangkat berkabel yang nyata jauh lebih menyesatkan
     * daripada mengakui datanya belum ada.
     *
     * @param  array<int, array<string, mixed>>  $hosts
     * @param  array<int, array<string, mixed>>  $wifiNetworks
     * @param  array<string, mixed>  $doc
     * @return array<int, array<string, mixed>>
     */
    private function markActive(array $hosts, array $wifiNetworks, array $doc): array
    {
        $associated = [];
        foreach ($wifiNetworks as $network) {
            foreach ($network['clients'] ?? [] as $client) {
                if ($mac = GenieacsOnuMatcher::normalizeMac($client['mac_address'] ?? null)) {
                    $associated[$mac] = true;
                }
            }
        }

        $reported = $this->reportedActiveFlags($doc);

        return array_map(function (array $host) use ($associated, $reported): array {
            $mac = GenieacsOnuMatcher::normalizeMac($host['mac_address'] ?? null);

            if ($mac !== null && isset($associated[$mac])) {
                return [...$host, 'active' => true, 'active_source' => 'wifi'];
            }

            if ($mac !== null && array_key_exists($mac, $reported)) {
                return [...$host, 'active' => $reported[$mac], 'active_source' => 'device'];
            }

            return [...$host, 'active' => null, 'active_source' => null];
        }, $hosts);
    }

    /**
     * Nilai `Active` yang benar-benar DILAPORKAN ONU, ber-key MAC ternormalisasi.
     *
     * Dibaca dari dokumen mentah, bukan dari hasil parser — parser mengisi
     * default `true` ketika field itu absen, dan default itu akan menyamarkan
     * "tidak tahu" menjadi "aktif".
     *
     * @param  array<string, mixed>  $doc
     * @return array<string, bool>
     */
    private function reportedActiveFlags(array $doc): array
    {
        $entries = data_get($doc, 'InternetGatewayDevice.LANDevice.1.Hosts.Host');
        $flags = [];

        if (! is_array($entries)) {
            return $flags;
        }

        foreach ($entries as $key => $entry) {
            if (! is_array($entry) || str_starts_with((string) $key, '_')) {
                continue;
            }

            $mac = GenieacsOnuMatcher::normalizeMac(data_get($entry, 'MACAddress._value'));
            if ($mac === null || ! is_array($entry['Active'] ?? null) || ! array_key_exists('_value', $entry['Active'])) {
                continue;
            }

            $flags[$mac] = filter_var($entry['Active']['_value'], FILTER_VALIDATE_BOOLEAN);
        }

        return $flags;
    }

    /**
     * @return array<string, mixed>
     */
    private function failure(string $error): array
    {
        return [
            'ok' => false,
            'error' => $error,
            'device_id' => null,
            'product_class' => null,
            'last_inform_at' => null,
            'hosts' => [],
            'active_count' => 0,
            'unknown_count' => 0,
            'total_count' => 0,
            'wifi_networks' => [],
            'fetched_at' => now()->toIso8601String(),
        ];
    }
}
