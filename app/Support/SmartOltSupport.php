<?php

namespace App\Support;

use App\Models\SnmpOlt;
use App\Services\SmartOltSnmpServiceResolver;

class SmartOltSupport
{
    public const DRIVER_ZTE = 'zte';

    public const DRIVER_CDATA_EPON = 'cdata-epon-17409';

    public const DRIVER_CDATA_GPON = 'cdata-gpon-34592';

    public const DRIVER_HIOSO_EPON = 'hioso-epon-25355';

    public const DRIVER_UNKNOWN = 'unknown';

    public static function driverKey(?SnmpOlt $olt, ?string $sysDescr = null, ?string $sysObjectId = null): string
    {
        $haystack = strtolower(implode(' ', array_filter([
            $olt?->vendor,
            $olt?->name,
            $sysDescr,
            $sysObjectId,
        ])));

        // ZTE diperiksa lebih dulu (prioritas; "epon" milik C-Data hanya berlaku tanpa "zte").
        foreach (['zte', '3902', 'c300', 'c320', 'c600'] as $needle) {
            if (str_contains($haystack, $needle)) {
                return self::DRIVER_ZTE;
            }
        }

        // HiOSO / V-Sol EPON (enterprise 25355) — vendor distinct, diperiksa sebelum needle "epon" C-Data.
        foreach (['hioso', 'ha7304', '25355', 'v-sol', 'vsol', 'v-solution'] as $needle) {
            if (str_contains($haystack, $needle)) {
                return self::DRIVER_HIOSO_EPON;
            }
        }

        // C-Data GPON 34592 — hint spesifik menang atas "cdata"/"epon" generik.
        foreach (['34592', 'cdata native', 'c-data native', 'cdata gpon', 'c-data gpon', 'fd-onu', 'fd-olt', 'fd1608', 'fd1216', 'fd1616'] as $needle) {
            if (str_contains($haystack, $needle)) {
                return self::DRIVER_CDATA_GPON;
            }
        }

        // C-Data / ODM EPON 17409.
        foreach (['17409', 'nscrtv', 'fd1108', 'fd1208', 'fd1504', 'epon'] as $needle) {
            if (str_contains($haystack, $needle)) {
                return self::DRIVER_CDATA_EPON;
            }
        }

        // "cdata"/"c-data" polos tanpa hint family → default EPON (sesuai guide §1; sysObjectID saat Test akan mengoreksi).
        foreach (['cdata', 'c-data'] as $needle) {
            if (str_contains($haystack, $needle)) {
                return self::DRIVER_CDATA_EPON;
            }
        }

        return self::DRIVER_UNKNOWN;
    }

    public static function isCData(string $driver): bool
    {
        return in_array($driver, [self::DRIVER_CDATA_EPON, self::DRIVER_CDATA_GPON], true);
    }

    public static function isHioso(string $driver): bool
    {
        return $driver === self::DRIVER_HIOSO_EPON;
    }

    /**
     * Family non-ZTE yang digerakkan {@see SmartOltSnmpServiceResolver} + scanner
     * (C-Data EPON/GPON & HiOSO). Dipakai untuk routing/pengelompokan (tab inventory, halaman
     * `cdata-olt.*`, jalur polling scanner, link search/monitoring/peta) — bukan gating write,
     * yang tetap memakai {@see self::isCData()} karena CLI write masih spesifik C-Data.
     */
    public static function isNonZte(string $driver): bool
    {
        return self::isCData($driver) || self::isHioso($driver);
    }

    /**
     * Prefix nama rute inventori untuk sebuah driver: `hioso-olt` (HiOSO), `cdata-olt` (C-Data),
     * atau `smartolt` (ZTE + unknown). Sumber tunggal pemilihan rute detail/port-onus lintas halaman
     * (global search, peta, ONU monitoring) sehingga tiap family memakai controller-nya sendiri.
     */
    public static function inventoryRoutePrefix(string $driver): string
    {
        if (self::isHioso($driver)) {
            return 'hioso-olt';
        }

        if (self::isCData($driver)) {
            return 'cdata-olt';
        }

        return 'smartolt';
    }

    /**
     * Deteksi firmware FlashV3.x (inventory/SN/MAC/optical hanya via CLI) dari hasil probe yang di-cache tombol Test.
     */
    public static function isCDataGponV3(?SnmpOlt $olt): bool
    {
        if ($olt === null) {
            return false;
        }

        return (bool) data_get($olt->last_test_result, 'cdata.firmware_v3', false)
            || str_contains(strtolower((string) data_get($olt->last_test_result, 'cdata.firmware_variant', '')), 'v3');
    }

    public static function isC600(?SnmpOlt $olt): bool
    {
        if ($olt === null) {
            return false;
        }

        $haystack = strtolower(implode(' ', array_filter([
            $olt->vendor,
            $olt->name,
            data_get($olt->last_test_result, 'system.sys_descr'),
        ])));

        if (str_contains($haystack, 'c600')) {
            return true;
        }

        // sysObjectID is the only self-describing marker before the first Test writes
        // sys_descr; a C600 reports .1.3.6.1.4.1.3902.1082.1001.600.1.1 (verified live).
        return str_contains(
            (string) data_get($olt->last_test_result, 'system.sys_object_id', ''),
            '3902.1082.1001.600',
        );
    }

    /**
     * Label teknologi PON milik OLT untuk teks alarm/notifikasi: 'GPON' atau 'EPON'
     * (C-Data EPON & HiOSO EPON → 'EPON'; ZTE & C-Data GPON → 'GPON'). Default 'GPON'.
     * Memakai pemilih family yang sama dengan jalur polling ({@see self::driverKey()}).
     */
    public static function ponLabel(?SnmpOlt $olt): string
    {
        $driver = self::driverKey($olt);

        return (string) (self::capabilities($driver, $olt)['pon_label'] ?? 'GPON');
    }

    /**
     * Nama interface CLI. C600 memakai eja `gpon_onu-` + **3-tier** `1/{slot}/{port}` — bukan
     * `gpon-onu_1/1/{slot}/{port}` 4-tier seperti dugaan awal. Terbukti dari running-config C600 asli
     * (`interface gpon_olt-1/3/13`, `pon-onu-mng gpon_onu-1/3/13:8`) dan cocok dgn ifName SNMP-nya.
     */
    public static function onuInterfaceId(int $slot, int $port, int $onuId, bool $isC600 = false): string
    {
        return $isC600
            ? sprintf('gpon_onu-1/%d/%d:%d', $slot, $port, $onuId)
            : sprintf('gpon-onu_1/%d/%d:%d', $slot, $port, $onuId);
    }

    public static function gponOltInterface(int $slot, int $port, bool $isC600 = false): string
    {
        return $isC600
            ? sprintf('gpon_olt-1/%d/%d', $slot, $port)
            : sprintf('gpon-olt_1/%d/%d', $slot, $port);
    }

    /**
     * @return array<string, mixed>
     */
    public static function capabilities(string $driver, ?SnmpOlt $olt = null): array
    {
        if ($driver === self::DRIVER_CDATA_EPON) {
            return self::cdataEponCapabilities();
        }

        if ($driver === self::DRIVER_CDATA_GPON) {
            return self::cdataGponCapabilities($olt);
        }

        if ($driver === self::DRIVER_HIOSO_EPON) {
            return self::hiosoEponCapabilities();
        }

        if ($driver !== self::DRIVER_ZTE) {
            return [
                'driver' => self::DRIVER_UNKNOWN,
                'vendor_family' => 'Unknown',
                'supports_provisioning' => false,
                'supports_cli_onu_detail' => false,
                'supports_cli_onu_configure' => false,
                'supports_snmp_rx' => false,
                'supports_reboot' => false,
                'supports_onu_toggle' => false,
                'supports_onu_info_write' => false,
                'supports_config_save' => false,
            ];
        }

        $isC600 = self::isC600($olt);

        return [
            'driver' => self::DRIVER_ZTE,
            'vendor_family' => $isC600 ? 'ZTE GPON (C600)' : 'ZTE GPON',
            'pon_label' => 'GPON',
            'port_label' => 'GPON Port',
            'port_name_prefix' => $isC600 ? 'gpon_olt-1' : 'gpon-olt_1',
            'onu_interface_pattern' => $isC600 ? 'gpon_onu-1/%d/%d:%d' : 'gpon-onu_1/%d/%d:%d',
            'is_c600' => $isC600,
            // C600: OID tulis nama/admin-state belum ditemukan di perangkat asli (lihat
            // docs/SMARTOLT_ZTE_C600_GUIDE.md), jadi rename & enable/disable ditutup sampai
            // kolomnya terbukti — jangan dibuka dengan OID tebakan. Rx ONU sudah terpetakan.
            'supports_snmp_rx' => true,
            'supports_cli_rx' => true,
            'supports_cli_onu_detail' => true,
            'supports_cli_onu_configure' => true,
            // Menulis ulang config ONU (preview/apply) — MATI untuk C600 karena builder
            // delta masih gaya C300 (tcont/gemport/service-port), sedang C600 pakai model
            // vport (vport-mode/vport-map). Configure C600 = lihat-saja sampai builder C600 ada.
            'supports_onu_config_write' => ! $isC600,
            'supports_reboot' => true,
            'reboot_mode' => 'cli',
            // Provisioning C600 = Model B / SmartOLT TR069 (ZteC600ProvisioningScriptBuilder), struktur
            // direproduksi PERSIS dari running-config ONU asli di lapangan (dua-service internet+mgmt,
            // mgmt-ip in-band, VEIP/ACS, tr069-mgmt tergabung `tag pri`). Berbeda dari C300 (dipisah
            // via OnuRegistrationService::c600Rules + buildFor). WAN pppoe/dhcp/static tetap ditolak.
            'supports_provisioning' => true,
            'supports_onu_delete' => true,
            'supports_separate_description' => ! $isC600,
            'supports_onu_info_write' => ! $isC600,
            'description_mode' => 'snmp',
            'supports_onu_toggle' => ! $isC600,
            // Simpan running-config ke memori via CLI `write` (bisa ~30 detik di C300 config besar).
            'supports_config_save' => true,
            'rx_source_label' => 'Rx ONU (SNMP)',
        ];
    }

    /**
     * C-Data EPON 17409 — v1 read-only (semua write dimatikan, disiapkan untuk fase berikutnya).
     *
     * @return array<string, mixed>
     */
    private static function cdataEponCapabilities(): array
    {
        return [
            'driver' => self::DRIVER_CDATA_EPON,
            'vendor_family' => 'C-Data EPON',
            'pon_label' => 'EPON',
            'port_label' => 'EPON Port',
            'port_name_prefix' => 'epon 0',
            'onu_interface_pattern' => 'epon 0/%d/%d onu %d',
            'is_c600' => false,
            'read_only' => false,
            'supports_snmp_rx' => true,
            'supports_cli_rx' => false,
            'supports_cli_onu_detail' => false,
            'supports_cli_onu_configure' => false,
            'supports_reboot' => true,
            'reboot_mode' => 'cli_cdata',
            'supports_provisioning' => false,
            'supports_onu_delete' => true,
            'supports_separate_description' => false,
            'supports_onu_info_write' => true,
            'description_mode' => 'cli_cdata',
            'supports_onu_toggle' => true,
            // Simpan running-config via CLI: enable → config → save.
            'supports_config_save' => true,
            'rx_source_label' => 'Rx ONU (SNMP)',
        ];
    }

    /**
     * C-Data GPON 34592 — v1 read-only. Optical/inventory pada FlashV3.x hanya tersedia via CLI.
     *
     * @return array<string, mixed>
     */
    private static function cdataGponCapabilities(?SnmpOlt $olt): array
    {
        $isV3 = self::isCDataGponV3($olt);

        return [
            'driver' => self::DRIVER_CDATA_GPON,
            'vendor_family' => $isV3 ? 'C-Data GPON (FlashV3)' : 'C-Data GPON',
            'pon_label' => 'GPON',
            'port_label' => 'GPON Port',
            'port_name_prefix' => 'gpon 0',
            'onu_interface_pattern' => 'gpon 0/%d/%d:%d',
            'is_c600' => false,
            'is_v3' => $isV3,
            'read_only' => false,
            'supports_snmp_rx' => ! $isV3,
            'supports_cli_rx' => $isV3,
            'supports_cli_onu_detail' => false,
            'supports_cli_onu_configure' => false,
            'supports_reboot' => true,
            'reboot_mode' => 'cli_cdata',
            'supports_provisioning' => false,
            'supports_onu_delete' => true,
            'supports_separate_description' => false,
            'supports_onu_info_write' => true,
            'description_mode' => 'cli_cdata',
            'supports_onu_toggle' => true,
            // Simpan running-config via CLI: enable → config → save.
            'supports_config_save' => true,
            'rx_source_label' => $isV3 ? 'Rx ONU (CLI)' : 'Rx ONU (SNMP DDM)',
        ];
    }

    /**
     * HiOSO / V-Sol EPON 25355 — inventory + Rx via SNMP; aksi tulis ONU (rename, reboot & delete) via
     * CLI telnet `conf t` → `interface epon 0/{port}` → `onu {id} name|reboot` / `no onu {id}`
     * (rename/reboot guide §5.5; delete `no onu {id}` guide §5.6 — verifikasi live via UI). Provisioning belum ada.
     *
     * @return array<string, mixed>
     */
    private static function hiosoEponCapabilities(): array
    {
        return [
            'driver' => self::DRIVER_HIOSO_EPON,
            'vendor_family' => 'HiOSO / V-Sol EPON',
            'pon_label' => 'EPON',
            'port_label' => 'EPON Port',
            'port_name_prefix' => 'epon 0',
            'onu_interface_pattern' => 'epon 0/%d/%d:%d',
            'is_c600' => false,
            'read_only' => false,
            'supports_snmp_rx' => true,
            'supports_cli_rx' => false,
            'supports_cli_onu_detail' => false,
            'supports_cli_onu_configure' => false,
            'supports_reboot' => true,
            'reboot_mode' => 'cli_hioso',
            'supports_provisioning' => false,
            'supports_onu_delete' => true,
            'supports_separate_description' => false,
            'supports_onu_info_write' => true,
            'description_mode' => 'cli_hioso',
            'supports_onu_toggle' => true,
            // Simpan running-config via CLI: enable → write.
            'supports_config_save' => true,
            'rx_source_label' => 'Rx ONU (SNMP)',
        ];
    }

    /**
     * @param  array<string, mixed>  $onu
     */
    public static function customerNameFromOnu(array $onu): ?string
    {
        $serial = (string) ($onu['serial_number'] ?? '');

        return self::cleanCustomerName($onu['name'] ?? null, $serial)
            ?? self::cleanCustomerName($onu['description'] ?? null, $serial);
    }

    public static function cleanCustomerName(mixed $value, string $serial = ''): ?string
    {
        $name = trim((string) $value);

        if ($name === '') {
            return null;
        }

        if (preg_match('/\$\$(.*?)\$\$/', $name, $matches)) {
            $name = trim($matches[1]);
        }

        $lower = strtolower($name);

        if (
            $name === ''
            || in_array($lower, ['-', 'n/a', 'na', 'null', 'none'], true)
            || ($serial !== '' && strcasecmp($name, $serial) === 0)
            || str_starts_with($lower, 'gpon-onu_')  // C300/C320
            || str_starts_with($lower, 'gpon_onu-')  // C600
        ) {
            return null;
        }

        return $name;
    }
}
