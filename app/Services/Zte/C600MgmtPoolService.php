<?php

namespace App\Services\Zte;

use App\Models\SmartOltOnuRegistration;
use App\Models\SnmpOlt;
use App\Services\ZteCliProvisioningExecutor;
use Illuminate\Support\Facades\Cache;

/**
 * Auto-alokasi mgmt-IP C600 dengan membaca IP yang BENAR-BENAR terpakai di OLT (menghindari bentrok
 * dengan alokasi SmartOLT yang ikut mengelola OLT ini). Sumber kebenaran = `show running-config`.
 *
 * Kunci reliabilitas (terbukti live): **`terminal length 0`** wajib mendahului scan — tanpa itu
 * pager `--More--` memotong output ~1 layar (~12 baris) & sisanya hilang. Dengan pager mati +
 * `execute(..., largeOutput: true)`, ~632 baris mgmt-ip terbaca penuh (~18 dtk) di LAS GALERAS.
 *
 * Baris mgmt-ip memuat SEMUA parameter pool → mask/gateway/vlan/priority/host diturunkan dari config
 * OLT, tak perlu setelan manual. CIDR dihitung dari IP contoh & mask.
 */
class C600MgmtPoolService
{
    private const CACHE_TTL = 600; // 10 menit — scan ~18 dtk, jangan diulang tiap muat form.

    public function __construct(private readonly ZteCliProvisioningExecutor $executor) {}

    /**
     * Pool mgmt (parameter + daftar IP terpakai), di-cache. `$fresh` memaksa scan ulang.
     *
     * @return array{cidr:?string, mask:?string, gateway:?string, vlan:?int, priority:int, host:int, used:array<int,string>, used_count:int, scanned_at:string}
     */
    public function pool(SnmpOlt $olt, bool $fresh = false): array
    {
        $key = "c600_mgmt_pool_{$olt->id}";

        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, self::CACHE_TTL, fn () => $this->scan($olt));
    }

    /**
     * IP mgmt bebas terendah di CIDR (kecualikan network/gateway/broadcast, IP terpakai di OLT, dan
     * IP yang baru dialokasikan aplikasi ini tapi mungkin belum masuk cache scan). Null bila pool tak
     * terbaca / penuh.
     *
     * @return array{mgmt_ip:string, mask:string, gateway:string, vlan:int, priority:int, host:int, cidr:string, used_count:int, free_count:int}|null
     */
    public function nextFreeIp(SnmpOlt $olt, bool $fresh = false): ?array
    {
        $pool = $this->pool($olt, $fresh);

        if ($pool['cidr'] === null || $pool['mask'] === null || $pool['gateway'] === null) {
            return null;
        }

        [$network, $bits] = explode('/', $pool['cidr']);
        $start = ip2long($network);
        $total = 1 << (32 - (int) $bits);
        $gateway = ip2long($pool['gateway']);

        $used = array_fill_keys($pool['used'], true);
        foreach ($this->recentAppIps($olt) as $ip) {
            $used[$ip] = true;
        }

        $free = null;
        $freeCount = 0;
        for ($i = 1; $i < $total - 1; $i++) {   // lewati network (.0) & broadcast (terakhir)
            $long = $start + $i;
            if ($long === $gateway) {
                continue;
            }
            $ip = long2ip($long);
            if (isset($used[$ip])) {
                continue;
            }
            $freeCount++;
            if ($free === null) {
                $free = $ip;
            }
        }

        if ($free === null) {
            return null;
        }

        return [
            'mgmt_ip' => $free,
            'mask' => $pool['mask'],
            'gateway' => $pool['gateway'],
            'vlan' => $pool['vlan'],
            'priority' => $pool['priority'],
            'host' => $pool['host'],
            'cidr' => $pool['cidr'],
            'used_count' => $pool['used_count'],
            'free_count' => $freeCount,
        ];
    }

    /**
     * @return array{cidr:?string, mask:?string, gateway:?string, vlan:?int, priority:int, host:int, used:array<int,string>, used_count:int, scanned_at:string}
     */
    private function scan(SnmpOlt $olt): array
    {
        // `terminal length 0` mematikan pager → seluruh baris mgmt-ip streaming tanpa `--More--`.
        $result = $this->executor->execute($olt, "terminal length 0\nshow running-config | include mgmt-ip", true);
        $raw = (string) ($result['output'] ?? '');

        // ZTE membungkus baris panjang di kolom tetap, MEMOTONG di tengah token — termasuk di tengah
        // IP (mis. `route 0.0.0.0 0.0.0.0 10\n.64.64.1 host 2`). Buang newline lalu rapatkan spasi
        // yang menempel titik (bekas titik-potong di dalam IP) supaya IP utuh kembali.
        $flat = str_replace(["\r", "\n"], '', $raw);
        $flat = preg_replace('/(\d)\s+\./', '$1.', $flat) ?? $flat;
        $flat = preg_replace('/\.\s+(\d)/', '.$1', $flat) ?? $flat;

        // IP terpakai: `mgmt-ip {ip}` — IP ada di awal baris, tak pernah terpotong wrap → andal.
        preg_match_all('/mgmt-ip (\d+\.\d+\.\d+\.\d+)/', $flat, $ipm);
        $used = [];
        foreach ($ipm[1] as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                $used[$ip] = true;
            }
        }

        // Parameter pool representatif dari entri lengkap pertama.
        $mask = $gateway = $cidr = null;
        $vlan = null;
        $priority = 2;
        $host = 2;
        if (preg_match(
            '/mgmt-ip (\d+\.\d+\.\d+\.\d+) (\d+\.\d+\.\d+\.\d+) vlan (\d+) priority (\d+) route \S+ \S+ (\d+\.\d+\.\d+\.\d+) host (\d+)/',
            $flat,
            $m,
        )) {
            $mask = $m[2];
            $vlan = (int) $m[3];
            $priority = (int) $m[4];
            $gateway = $m[5];
            $host = (int) $m[6];
            $cidr = $this->cidrFor($m[1], $mask);
        }

        return [
            'cidr' => $cidr,
            'mask' => $mask,
            'gateway' => $gateway,
            'vlan' => $vlan,
            'priority' => $priority,
            'host' => $host,
            'used' => array_keys($used),
            'used_count' => count($used),
            'scanned_at' => now()->toIso8601String(),
        ];
    }

    /**
     * IP mgmt yang di-provision aplikasi ini baru-baru ini (dari cli_script audit) — menutup celah
     * registrasi beruntun dalam jendela cache sebelum scan berikutnya melihat IP baru.
     *
     * @return array<int, string>
     */
    private function recentAppIps(SnmpOlt $olt): array
    {
        $scripts = SmartOltOnuRegistration::withoutGlobalScopes()
            ->where('snmp_olt_id', $olt->id)
            ->whereIn('status', ['generated', 'executed'])
            ->latest('id')
            ->limit(200)
            ->pluck('cli_script');

        $ips = [];
        foreach ($scripts as $script) {
            if (preg_match('/mgmt-ip (\d+\.\d+\.\d+\.\d+)/', (string) $script, $m)) {
                $ips[] = $m[1];
            }
        }

        return $ips;
    }

    private function cidrFor(string $ip, string $mask): ?string
    {
        $ipLong = ip2long($ip);
        $maskLong = ip2long($mask);
        if ($ipLong === false || $maskLong === false) {
            return null;
        }

        $network = long2ip($ipLong & $maskLong);
        $bits = substr_count(decbin($maskLong), '1');

        return "{$network}/{$bits}";
    }
}
