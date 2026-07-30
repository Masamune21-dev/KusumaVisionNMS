<?php

namespace Tests\Unit;

use App\Models\SnmpOlt;
use App\Support\SmartOltSupport;
use Tests\TestCase;

/**
 * Varian HiOSO HA7302 (mis. HA7302CSM v7.76) dibedakan dari HA7304: SNMP menyajikan ONU sebagai ruang
 * LLID datar dan CLI-nya berbeda (login 3-lapis, node pon, tanpa `interface epon`). Karena itu aksi CLI
 * per-ONU di-OFF-kan sampai index pon/onuId terpetakan aman, sedang rename via SNMP SET tetap ada.
 */
class HiosoHa7302CapabilitiesTest extends TestCase
{
    private function hiosoOlt(?string $firmware): SnmpOlt
    {
        $olt = new SnmpOlt(['name' => 'OLT HIOSO', 'vendor' => 'HiOSO EPON 25355']);
        if ($firmware !== null) {
            $olt->last_test_result = ['system' => ['firmware' => $firmware]];
        }

        return $olt;
    }

    public function test_ha7304_keeps_cli_write_actions(): void
    {
        $olt = $this->hiosoOlt('1.0.0.1/HA7304/SN2018-03-00007');
        $driver = SmartOltSupport::driverKey($olt);
        $caps = SmartOltSupport::capabilities($driver, $olt);

        $this->assertSame(SmartOltSupport::DRIVER_HIOSO_EPON, $driver);
        $this->assertFalse(SmartOltSupport::isHiosoHa7302($olt));
        $this->assertTrue($caps['supports_reboot']);
        $this->assertTrue($caps['supports_onu_toggle']);
        $this->assertTrue($caps['supports_onu_delete']);
        $this->assertTrue($caps['supports_config_save']);
        $this->assertSame('cli_hioso', $caps['description_mode']);
    }

    public function test_ha7302_enables_cli_writes_and_renames_via_snmp(): void
    {
        $olt = $this->hiosoOlt('v7.76/HA7302CSM/HA202301050001');
        $driver = SmartOltSupport::driverKey($olt);
        $caps = SmartOltSupport::capabilities($driver, $olt);

        $this->assertSame(SmartOltSupport::DRIVER_HIOSO_EPON, $driver, 'vendor "HiOSO … 25355" tetap terdeteksi HiOSO');
        $this->assertTrue(SmartOltSupport::isHiosoHa7302($olt));
        $this->assertTrue($caps['is_ha7302']);

        // Rename lewat SNMP SET (CLI HA7302 tak punya rename).
        $this->assertTrue($caps['supports_snmp_rx']);
        $this->assertTrue($caps['supports_onu_info_write']);
        $this->assertSame('snmp', $caps['description_mode']);

        // Aksi CLI per-ONU AKTIF — pemetaan LLID-datar→CLI onuId terverifikasi 1:1 via search mac-address.
        $this->assertTrue($caps['supports_reboot']);
        $this->assertTrue($caps['supports_onu_toggle']);
        $this->assertTrue($caps['supports_onu_delete']);
        $this->assertTrue($caps['supports_config_save']);
        $this->assertSame('HiOSO / V-Sol EPON (HA7302)', $caps['vendor_family']);
    }
}
