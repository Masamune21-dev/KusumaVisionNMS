<?php

namespace App\Support;

use App\Models\SnmpOlt;

/**
 * Kartu EPON di ZTE C300/C320 (ETGO 8-port, ETGH 16-port, ETTO 10G-EPON) — jalur yang bercabang
 * dari GPON per PORT, bukan per OLT: satu chassis bisa berisi kartu GTG (GPON) dan ETG (EPON)
 * sekaligus, dan slot/port tetap unik karena satu slot hanya memuat satu kartu.
 *
 * ⚠️ BELUM TERVERIFIKASI DI PERANGKAT ASLI. Dibangun (Okt 2026) tanpa OLT berkartu ETG untuk diuji —
 * pengembang hanya punya OLT ZTE berkartu GTGO/GTGH. Sumber sintaks: keluaran asli operator C300 V1.2.5P3
 * (`show epon onu state`, running-config `onu N type T mac M`), manual konfigurasi awal C300
 * berbahasa Tionghoa (blok `show onu unauthentication`, `show card` ETGO/ETGOD), dan catatan
 * lapangan repo MIT s4lfanet/nms-ztec320 (C320 V2.1: MAC bertitik wajib, `ALL-EPON`,
 * `property description`, `service-port … vport 1`). Karena itu seluruh jalur ini di belakang
 * saklar `services.zte_epon.enabled` (env `ZTE_EPON_ENABLED`, bawaan MATI): saat mati, port EPON
 * tetap dibuang seperti sebelumnya dan tak ada perintah EPON yang pernah dikirim ke OLT.
 */
class ZteEpon
{
    /** Kartu EPON = CfgType/RealType `show card` berawalan ETG (1G) atau ETT (10G-EPON). */
    private const CARD_PREFIX = '/^ET[GT]/i';

    public static function enabled(): bool
    {
        return (bool) config('services.zte_epon.enabled', false);
    }

    /**
     * Saklar menyala dan OLT ini ZTE C300/C320. C600 tak punya kartu EPON di NMS (penamaan
     * interface-nya pun beda), jadi tak pernah dicabangkan ke sini.
     */
    public static function appliesTo(?SnmpOlt $olt): bool
    {
        if ($olt === null || ! self::enabled()) {
            return false;
        }

        $driver = SmartOltSupport::driverKey(
            $olt,
            data_get($olt->last_test_result, 'system.sys_descr'),
            data_get($olt->last_test_result, 'system.sys_object_id'),
        );

        return $driver === SmartOltSupport::DRIVER_ZTE && ! SmartOltSupport::isC600($olt);
    }

    public static function isEponCardType(?string $type): bool
    {
        return $type !== null && preg_match(self::CARD_PREFIX, trim($type)) === 1;
    }

    /**
     * Port EPON menurut snapshot (`ports[]` hasil poll yang ditandai `pon_type=epon`). Hanya
     * dibaca bila saklar menyala, supaya data sisa dari masa uji coba tak mengubah perilaku
     * jalur GPON setelah saklar dimatikan lagi.
     */
    public static function isEponPort(?SnmpOlt $olt, int $slot, int $port): bool
    {
        if (! self::appliesTo($olt)) {
            return false;
        }

        foreach (self::ports($olt->last_test_result ?? []) as $row) {
            if ((int) ($row['slot'] ?? 0) === $slot && (int) ($row['port'] ?? 0) === $port) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return list<array<string, mixed>>
     */
    public static function ports(array $snapshot): array
    {
        return array_values(array_filter(
            is_array($snapshot['ports'] ?? null) ? $snapshot['ports'] : [],
            fn ($row): bool => is_array($row) && ($row['pon_type'] ?? null) === 'epon',
        ));
    }

    public static function oltInterface(int $slot, int $port): string
    {
        return sprintf('epon-olt_1/%d/%d', $slot, $port);
    }

    public static function onuInterface(int $slot, int $port, int $onuId): string
    {
        return sprintf('epon-onu_1/%d/%d:%d', $slot, $port, $onuId);
    }

    /** 12 digit hex MAC, tanpa pemisah, huruf besar — atau null bila bukan MAC. */
    public static function macHex(?string $value): ?string
    {
        $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', (string) $value) ?? '');

        if (strlen($hex) !== 12 || $hex === '000000000000') {
            return null;
        }

        // Tolak masukan yang ternyata bukan MAC berpemisah (mis. SN GPON `ZTEGC1234567` yang
        // kebetulan menyisakan 12 hex setelah huruf non-hex dibuang).
        $stripped = preg_replace('/[\s:.\-]/', '', (string) $value) ?? '';

        return strlen($stripped) === 12 ? $hex : null;
    }

    /**
     * Ejaan MAC yang WAJIB untuk CLI EPON ZTE: `xxxx.xxxx.xxxx` huruf kecil. Hex polos atau
     * berpemisah titik dua ditolak OLT dengan `%Error 20202: Invalid input` (catatan lapangan C320).
     */
    public static function cliMac(?string $value): ?string
    {
        $hex = self::macHex($value);

        return $hex === null ? null : strtolower(implode('.', str_split($hex, 4)));
    }

    /**
     * Ejaan MAC di snapshot/UI: `AA:BB:CC:DD:EE:FF` — sama dengan HiOSO EPON, sehingga pencocok
     * GenieACS (serial ber-`:` = MAC) dan laporan ONU memperlakukannya sebagai MAC.
     */
    public static function displayMac(?string $value): ?string
    {
        $hex = self::macHex($value);

        return $hex === null ? null : implode(':', str_split($hex, 2));
    }

    /**
     * Kapabilitas sebuah port EPON, diturunkan dari kapabilitas OLT ZTE-nya. Yang dibuka hanya
     * yang punya jalur EPON sendiri: daftar ONU (CLI), registrasi, reboot, hapus, ganti nama.
     * Fitur bersintaks GPON (OMCI pon-onu-mng WAN/TR069, tcont/gemport, Bind `registration-method
     * sn`, Pre-Register, SNMP SET admin-state, detail-info, salin ONU, port shutdown/deskripsi)
     * DITUTUP — sintaks EPON-nya tak punya sumber yang bisa dipegang.
     *
     * @param  array<string, mixed>  $caps
     * @return array<string, mixed>
     */
    public static function portCapabilities(array $caps): array
    {
        return [
            ...$caps,
            'vendor_family' => 'ZTE EPON',
            'pon_label' => 'EPON',
            'port_label' => 'EPON Port',
            'port_name_prefix' => 'epon-olt_1',
            'onu_interface_pattern' => 'epon-onu_1/%d/%d:%d',
            'is_epon_port' => true,
            'epon_unverified' => true,
            'supports_snmp_rx' => false,
            'supports_cli_rx' => false,
            'supports_cli_onu_detail' => false,
            'supports_cli_onu_configure' => false,
            'supports_onu_config_write' => false,
            'supports_reboot' => true,
            'supports_provisioning' => true,
            'supports_onu_delete' => true,
            'supports_onu_replace' => false,
            'supports_onu_preregister' => false,
            'supports_separate_description' => true,
            'supports_onu_info_write' => true,
            'description_mode' => 'cli',
            'supports_onu_toggle' => false,
            'supports_port_description_write' => false,
            'supports_port_admin_write' => false,
            'rx_source_label' => 'Rx ONU (CLI)',
        ];
    }

    /**
     * `ports[]` di snapshot ditulis ulang setiap Test SNMP / refresh / poll dari IF-MIB, yang
     * TIDAK memuat port EPON. Pemanggil yang menimpa `ports` memakai ini untuk membawa port EPON
     * dari snapshot lama, supaya port & ONU EPON tak hilang sampai poll berikutnya.
     *
     * @param  array<string, mixed>  $next
     * @param  array<string, mixed>  $previous
     * @return array<string, mixed>
     */
    public static function carryPorts(?SnmpOlt $olt, array $next, array $previous): array
    {
        if (! self::appliesTo($olt) || ! is_array($next['ports'] ?? null)) {
            return $next;
        }

        $epon = self::ports($previous);
        if ($epon === []) {
            return $next;
        }

        $taken = [];
        foreach ($next['ports'] as $row) {
            $taken[((int) ($row['slot'] ?? 0)).'_'.((int) ($row['port'] ?? 0))] = true;
        }

        foreach ($epon as $row) {
            if (! isset($taken[((int) $row['slot']).'_'.((int) $row['port'])])) {
                $next['ports'][] = $row;
            }
        }

        $next['ports'] = self::sortPorts($next['ports']);

        return $next;
    }

    /**
     * Buang semua jejak EPON dari snapshot — dipakai poll saat saklar mati (atau OLT bukan C300/
     * C320 lagi), supaya mematikan fitur langsung mengembalikan perilaku lama: port & ONU EPON
     * tak lagi tampil, dicari, maupun dievaluasi alarm.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public static function strip(array $snapshot): array
    {
        if (is_array($snapshot['ports'] ?? null)) {
            $snapshot['ports'] = array_values(array_filter(
                $snapshot['ports'],
                fn ($row): bool => ! is_array($row) || ($row['pon_type'] ?? null) !== 'epon',
            ));
        }

        if (is_array($snapshot['port_onus'] ?? null)) {
            foreach ($snapshot['port_onus'] as $key => $entry) {
                if (is_array($entry) && ($entry['pon_type'] ?? null) === 'epon') {
                    unset($snapshot['port_onus'][$key]);
                }
            }
        }

        unset($snapshot['epon']);

        return $snapshot;
    }

    /**
     * @param  array<int, array<string, mixed>>  $ports
     * @return list<array<string, mixed>>
     */
    public static function sortPorts(array $ports): array
    {
        $ports = array_values($ports);
        usort($ports, fn (array $a, array $b): int => [(int) ($a['slot'] ?? 0), (int) ($a['port'] ?? 0)] <=> [(int) ($b['slot'] ?? 0), (int) ($b['port'] ?? 0)]);

        return $ports;
    }
}
