<?php

namespace Tests\Unit;

use App\Services\CData\CDataValue;
use PHPUnit\Framework\TestCase;

class CDataValueTest extends TestCase
{
    public function test_clean_strips_type_prefix_and_quotes(): void
    {
        $this->assertSame('OLT-EPON', CDataValue::clean('STRING: "OLT-EPON"'));
        $this->assertNull(CDataValue::clean(''));
        $this->assertNull(CDataValue::clean(null));
    }

    public function test_mac_from_hex_handles_spaced_and_plain_forms(): void
    {
        $this->assertSame('D0:5F:AF:63:0F:2F', CDataValue::macFromHex('D0 5F AF 63 0F 2F'));
        $this->assertSame('D0:5F:AF:63:0F:2F', CDataValue::macFromHex('0xD05FAF630F2F'));
        $this->assertSame('D0:5F:AF:D2:96:DD', CDataValue::macFromHex('d0-5f-af-d2-96-dd'));
        $this->assertNull(CDataValue::macFromHex('not-a-mac'));
    }

    public function test_epon_rx_dbm_converts_centi_dbm_and_flags_no_signal(): void
    {
        $this->assertSame(-16.97, CDataValue::eponRxDbm(-1697));
        $this->assertNull(CDataValue::eponRxDbm(0));      // no signal
        $this->assertNull(CDataValue::eponRxDbm(null));
        $this->assertNull(CDataValue::eponRxDbm(999999)); // garbage di luar jendela
    }

    public function test_oid_last_segments(): void
    {
        $this->assertSame([1, 2, 5], CDataValue::oidLastSegments('1.3.6.1.4.1.34592.1.3.4.1.1.11.1.2.5', 3));
        $this->assertSame([18], CDataValue::oidLastSegments('1.3.6.1.2.1.2.2.1.2.18', 1));
        $this->assertNull(CDataValue::oidLastSegments('1.2', 3));
    }

    public function test_epon_decode_device_index(): void
    {
        // 0x01000100: slot=1, encPort=1 -> port=1, onuId=0
        $this->assertSame(['slot' => 1, 'port' => 1, 'onu_id' => 0], CDataValue::eponDecodeDeviceIndex(0x01000100));
    }

    public function test_parse_epon_onu_name(): void
    {
        $this->assertSame(
            ['slot' => 1, 'port' => 1, 'onu_id' => 1, 'label' => 'pelanggan-A'],
            CDataValue::parseEponOnuName('epon 0/1/1 onu 1 pelanggan-A'),
        );
        $this->assertSame(
            ['slot' => 2, 'port' => 3, 'onu_id' => 7, 'label' => null],
            CDataValue::parseEponOnuName('epon 0/2/3 onu 7'),
        );
        $this->assertNull(CDataValue::parseEponOnuName('random text'));
    }

    public function test_parse_gpon_onu_name(): void
    {
        // Bentuk asli tabel legacy 17409 FD1608S.
        $this->assertSame(
            ['slot' => 0, 'port' => 1, 'onu_id' => 1, 'label' => 'SERVER-PENJAWI'],
            CDataValue::parseGponOnuName('gpon 0/0/1 onu 1 SERVER-PENJAWI'),
        );
        // Label boleh mengandung `/` dan spasi (mis. catatan VLAN).
        $this->assertSame(
            ['slot' => 0, 'port' => 1, 'onu_id' => 4, 'label' => 'Iman Saeronji Sidokerto/ Vlan 24'],
            CDataValue::parseGponOnuName('gpon 0/0/1 onu 4 Iman Saeronji Sidokerto/ Vlan 24'),
        );
        $this->assertSame(
            ['slot' => 1, 'port' => 2, 'onu_id' => 9, 'label' => null],
            CDataValue::parseGponOnuName('gpon 0/1/2 onu 9'),
        );
        $this->assertNull(CDataValue::parseGponOnuName('random text'));
    }

    public function test_gpon_rx_dbm_parses_string_and_drops_garbage(): void
    {
        $this->assertSame(-21.5, CDataValue::gponRxDbm('-21.50'));
        $this->assertSame(-6.56, CDataValue::gponRxDbm('STRING: "-6.56"'));
        $this->assertNull(CDataValue::gponRxDbm('--'));      // N/A
        $this->assertNull(CDataValue::gponRxDbm(null));
        $this->assertNull(CDataValue::gponRxDbm('20.61'));   // positif besar = garbage
    }
}
