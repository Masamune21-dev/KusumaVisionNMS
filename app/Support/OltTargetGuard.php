<?php

namespace App\Support;

use App\Models\SnmpOlt;
use RuntimeException;

/**
 * Penjaga alamat tujuan koneksi OLT (CLI telnet, proxy telnet web, klien web HSGQ).
 *
 * Server NMS sendiri yang membuka socket ke `ip:cli_port` sebuah OLT, dan proxy telnet
 * meneruskan byte dari browser apa adanya. Tanpa penjaga, akun yang berhak menambah OLT
 * bisa memakai server ini sebagai jembatan ke alamat mana pun yang terjangkau darinya —
 * termasuk layanan internal yang tak punya autentikasi sendiri (ACS/TR-069, panel
 * manajemen, basis data) dan layanan loopback mesin ini.
 *
 * Aturannya:
 *  - Alamat yang tak pernah menjadi OLT selalu ditolak: 0.0.0.0/8, loopback, link-local,
 *    multicast/cadangan, alamat server NMS ini sendiri, dan daftar
 *    `NMS_OLT_BLOCKED_CIDRS` (lihat `config/olt.php`).
 *  - OLT GLOBAL (dibuat staf Pusat) bebas di luar daftar itu — VLAN manajemen, tunnel,
 *    dan blok publik ISP memang tempatnya.
 *  - OLT milik PARTNER hanya boleh di jalur yang disiapkan admin lewat
 *    `NMS_OLT_PARTNER_CIDRS`. Daftar kosong = partner tidak boleh menambah OLT sendiri
 *    (OLT untuk partner dibuat staf Pusat lalu di-assign ke dia).
 *
 * Validasi form memakai {@see allowsAddress()}; titik sambung (proxy telnet, klien CLI tiap
 * family) memeriksa ulang lewat {@see assertAllowed()} supaya baris OLT yang tersimpan
 * sebelum aturan ini berlaku ikut tertahan.
 */
final class OltTargetGuard
{
    /** @var array<int, string> */
    private const ALWAYS_BLOCKED = [
        '0.0.0.0/8',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '224.0.0.0/4',
        '240.0.0.0/4',
    ];

    /** @var array<int, string>|null */
    private static ?array $ownHostCache = null;

    public static function allows(SnmpOlt $olt): bool
    {
        return self::allowsAddress((string) $olt->ip, $olt->owner_user_id);
    }

    /**
     * @throws RuntimeException bila alamat OLT ini tidak boleh dihubungi
     */
    public static function assertAllowed(SnmpOlt $olt): void
    {
        if (self::allows($olt)) {
            return;
        }

        throw new RuntimeException(__($olt->owner_user_id === null
            ? 'olt.target_not_allowed'
            : 'olt.target_partner_range'));
    }

    public static function allowsAddress(string $ip, ?int $ownerUserId): bool
    {
        $ip = trim($ip);

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            if (self::inCidrs($ip, self::blockedCidrs())) {
                return false;
            }

            if ($ownerUserId === null) {
                return true;
            }

            $partnerCidrs = config('olt.target_guard.partner_cidrs', []);

            return is_array($partnerCidrs) && $partnerCidrs !== [] && self::inCidrs($ip, $partnerCidrs);
        }

        // IPv6 tidak pernah dipakai OLT partner; untuk OLT Pusat tolak bentuk yang berujung
        // ke mesin ini sendiri atau membungkus IPv4 terlarang.
        if ($ownerUserId !== null || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return false;
        }

        $bin = (string) inet_pton($ip);

        if ($bin === str_repeat("\0", 15)."\1" || $bin === str_repeat("\0", 16)) {
            return false;
        }

        // fe80::/10 link-local
        if (ord($bin[0]) === 0xFE && (ord($bin[1]) & 0xC0) === 0x80) {
            return false;
        }

        // ::ffff:a.b.c.d (IPv4-mapped) dinilai sebagai IPv4-nya.
        if (substr($bin, 0, 12) === str_repeat("\0", 10)."\xFF\xFF") {
            return self::allowsAddress((string) inet_ntop(substr($bin, 12)), $ownerUserId);
        }

        return true;
    }

    /**
     * Alamat yang selalu ditolak: daftar bawaan + `NMS_OLT_BLOCKED_CIDRS` + alamat mesin ini.
     *
     * @return array<int, string>
     */
    public static function blockedCidrs(): array
    {
        $extra = config('olt.target_guard.blocked_cidrs', []);

        return array_values(array_unique(array_merge(
            self::ALWAYS_BLOCKED,
            is_array($extra) ? $extra : [],
            self::ownHostCidrs(),
        )));
    }

    /**
     * Alamat IPv4 mesin ini (best-effort): tujuannya menolak OLT yang menunjuk server NMS
     * sendiri, tempat layanan internal biasanya mendengarkan. Disimpan di cache proses
     * karena alamat mesin tak berubah selama proses hidup.
     *
     * @return array<int, string>
     */
    private static function ownHostCidrs(): array
    {
        if (self::$ownHostCache !== null) {
            return self::$ownHostCache;
        }

        $ips = [];

        $serverAddr = $_SERVER['SERVER_ADDR'] ?? null;
        if (is_string($serverAddr) && $serverAddr !== '') {
            $ips[] = $serverAddr;
        }

        $host = gethostname();
        if (is_string($host) && $host !== '') {
            foreach (gethostbynamel($host) ?: [] as $ip) {
                $ips[] = $ip;
            }
        }

        $cidrs = [];
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $cidrs[] = $ip.'/32';
            }
        }

        return self::$ownHostCache = array_values(array_unique($cidrs));
    }

    /**
     * @param  array<int, string>  $cidrs
     */
    private static function inCidrs(string $ip, array $cidrs): bool
    {
        $long = ip2long($ip);

        if ($long === false) {
            return false;
        }

        foreach ($cidrs as $cidr) {
            if (! is_string($cidr) || ! str_contains($cidr, '/')) {
                continue;
            }

            [$net, $bits] = explode('/', $cidr, 2);

            $netLong = ip2long($net);
            $bits = (int) $bits;

            if ($netLong === false || $bits < 0 || $bits > 32) {
                continue;
            }

            $mask = $bits === 0 ? 0 : (~0 << (32 - $bits)) & 0xFFFFFFFF;

            if (($long & $mask) === ($netLong & $mask)) {
                return true;
            }
        }

        return false;
    }
}
