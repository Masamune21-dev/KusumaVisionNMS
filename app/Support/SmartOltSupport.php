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

    /** HsAirPo / HSGQ (OEM Shenzhen Photon Broadband, enterprise 12170) — 4PON EPON, inventori CLI-only. */
    public const DRIVER_HSAIRPO_EPON = 'hsairpo-epon-12170';

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

        // HsAirPo / HSGQ (OEM Photon Broadband, enterprise 12170) — juga diperiksa sebelum needle
        // "epon" C-Data. sysDescr perangkat ini KOSONG (spasi), jadi sysObjectID `.1.3.6.1.4.1.12170.2.3`
        // adalah penanda paling andal; needle brand dipakai bila operator mengetiknya di vendor/nama.
        foreach (['hsairpo', 'hsgq', 'photon', '12170'] as $needle) {
            if (str_contains($haystack, $needle)) {
                return self::DRIVER_HSAIRPO_EPON;
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

    public static function isHsAirPo(string $driver): bool
    {
        return $driver === self::DRIVER_HSAIRPO_EPON;
    }

    /**
     * Family non-ZTE yang digerakkan {@see SmartOltSnmpServiceResolver} + scanner
     * (C-Data EPON/GPON & HiOSO). Dipakai untuk routing/pengelompokan (tab inventory, halaman
     * `cdata-olt.*`, jalur polling scanner, link search/monitoring/peta) — bukan gating write,
     * yang tetap memakai {@see self::isCData()} karena CLI write masih spesifik C-Data.
     */
    public static function isNonZte(string $driver): bool
    {
        return self::isCData($driver) || self::isHioso($driver) || self::isHsAirPo($driver);
    }

    /**
     * Prefix nama rute inventori untuk sebuah driver: `hsairpo-olt` (HsAirPo/HSGQ), `hioso-olt`
     * (HiOSO), `cdata-olt` (C-Data), atau `smartolt` (ZTE + unknown). Sumber tunggal pemilihan rute
     * detail/port-onus lintas halaman (global search, peta, ONU monitoring) sehingga tiap family
     * memakai controller-nya sendiri.
     */
    public static function inventoryRoutePrefix(string $driver): string
    {
        if (self::isHsAirPo($driver)) {
            return 'hsairpo-olt';
        }

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
            return self::hiosoEponCapabilities($olt);
        }

        if ($driver === self::DRIVER_HSAIRPO_EPON) {
            return self::hsAirPoEponCapabilities();
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
            // Edit deskripsi port PON via CLI `interface … / description …` — jalan di ketiga
            // family ZTE (C300/C320/C600), penamaan interface via gponOltInterface(). Bukan SNMP.
            'supports_port_description_write' => true,
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
            // Buka/tutup akses remote web ONT via `ont security-mgmt` (klon sintaks ZTE, tak ada di
            // manual resmi — terverifikasi live FD1608S-B1 V3 Jul 2026, efektif juga utk ONT merk ZTE).
            'supports_onu_remote_access' => $isV3,
            'rx_source_label' => $isV3 ? 'Rx ONU (CLI)' : 'Rx ONU (SNMP DDM)',
        ];
    }

    /**
     * HiOSO / V-Sol EPON 25355 — inventory + Rx via SNMP; aksi tulis ONU (rename, reboot & delete) via
     * CLI telnet `conf t` → `interface epon 0/{port}` → `onu {id} name|reboot` / `no onu {id}`
     * (rename/reboot guide §5.5; delete `no onu {id}` guide §5.6 — verifikasi live via UI). Provisioning belum ada.
     *
     * **Varian HA7302** (mis. HA7302CSM v7.76, terverifikasi live Jul 2026): SNMP menyajikan ONU sebagai
     * satu ruang LLID datar (index `{oltId=1}.{onu}`, `onu` 1..128 per PON) tanpa `Pon-Nni` di IF-MIB,
     * tapi OID nama/MAC/Rx kanonik sama → inventory + Rx terbaca. CLI-nya login **3-lapis** dan dialek beda
     * (node `epon`→`pon 1/{pon}`→`set onu {onu} reboot`; `delete onu 1/{pon}/{onu}`;
     * `set pon 1/{pon} onu {onu} auth-mode pass|deny`), TAK ada `interface epon` maupun rename CLI.
     * Pemetaan LLID-datar→CLI onuId **terverifikasi 1:1** via `search mac-address` (flat index == CLI onuId,
     * PON = index SNMP; 4/4 MAC cocok) → reboot/enable-disable/delete/save **AKTIF**. Rename tetap lewat
     * **SNMP SET** (OID nama writable, `description_mode='snmp'`) karena CLI HA7302 tak punya rename.
     *
     * @return array<string, mixed>
     */
    private static function hiosoEponCapabilities(?SnmpOlt $olt = null): array
    {
        $isHa7302 = self::isHiosoHa7302($olt);

        return [
            'driver' => self::DRIVER_HIOSO_EPON,
            'vendor_family' => $isHa7302 ? 'HiOSO / V-Sol EPON (HA7302)' : 'HiOSO / V-Sol EPON',
            'pon_label' => 'EPON',
            'port_label' => 'EPON Port',
            'port_name_prefix' => 'epon 0',
            'onu_interface_pattern' => 'epon 0/%d/%d:%d',
            'is_c600' => false,
            'is_ha7302' => $isHa7302,
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
            // HA7302: rename hanya via SNMP SET (CLI tak punya rename); HA7304: rename via CLI.
            'supports_onu_info_write' => true,
            'description_mode' => $isHa7302 ? 'snmp' : 'cli_hioso',
            'supports_onu_toggle' => true,
            'supports_config_save' => true,
            'rx_source_label' => 'Rx ONU (SNMP)',
        ];
    }

    /**
     * HsAirPo / HSGQ EPON 12170 (OEM Photon Broadband, mis. "4PON EPON-OLT") — **Fase A: read-only**.
     *
     * Perangkat ini TIDAK meng-expose tabel ONU di SNMP (terverifikasi full-walk live Jul 2026):
     * SNMP hanya menyajikan MIB-2 device+port, FDB bridge, skalar vendor, dan jumlah ONU online
     * per-PON. Karena itu inventori ONU CLI-first (`show epon onu all info`, 1 perintah/scan) —
     * lihat `docs/SMARTOLT_HSAIRPO_GUIDE.md`.
     *
     * Rx per-ONU ada di CLI (`show epon port {n} onu {id} optical-info`) tapi per-ONU dan mahal
     * (116 perintah/poll) → Fase B, jadi `supports_cli_rx` masih false. Aksi tulis (Fase C) belum
     * diverifikasi live sehingga semua write dimatikan.
     *
     * @return array<string, mixed>
     */
    private static function hsAirPoEponCapabilities(): array
    {
        return [
            'driver' => self::DRIVER_HSAIRPO_EPON,
            'vendor_family' => 'HsAirPo / HSGQ EPON',
            'pon_label' => 'EPON',
            'port_label' => 'EPON Port',
            'port_name_prefix' => 'pon',
            'onu_interface_pattern' => 'pon%2$d:%3$d',
            'is_c600' => false,
            'read_only' => false,
            'supports_snmp_rx' => false,
            // Rx per-ONU via CLI `show epon port {n} onu {id} optical-info` (per-ONU; varian `all` hang).
            'supports_cli_rx' => true,
            'supports_cli_onu_detail' => false,
            'supports_cli_onu_configure' => false,
            // Aksi tulis via CLI config `epon port {pon} onu {onu} <verb>` (terverifikasi live via help).
            'supports_reboot' => true,
            'reboot_mode' => 'cli_hsairpo',
            'supports_provisioning' => false,
            'supports_onu_delete' => true,
            'supports_separate_description' => false,
            'supports_onu_info_write' => true,
            'description_mode' => 'cli_hsairpo',
            // Enable/disable (activate / no activate) sengaja OFF — semantik belum diuji live.
            'supports_onu_toggle' => false,
            'supports_config_save' => false,
            'rx_source_label' => 'Rx ONU (CLI)',
        ];
    }

    /**
     * Apakah OLT HiOSO ini varian **HA7302** (mis. HA7302CSM/HA7302CST) — dibedakan dari HA7304 karena
     * layout SNMP (LLID datar) & dialek CLI-nya berbeda. Sinyal utama: string firmware OID
     * `.25355.3.1.8.1.1.2.1` (mis. `v7.76/HA7302CSM/…`) yang discan ke `last_test_result.system.firmware`;
     * juga menerima hint dari vendor/name/sysDescr bila operator menandainya manual.
     */
    public static function isHiosoHa7302(?SnmpOlt $olt): bool
    {
        if ($olt === null) {
            return false;
        }

        $haystack = strtolower(implode(' ', array_filter([
            data_get($olt->last_test_result, 'system.firmware'),
            data_get($olt->last_test_result, 'system.sys_descr'),
            $olt->vendor,
            $olt->name,
        ])));

        return str_contains($haystack, 'ha7302');
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
