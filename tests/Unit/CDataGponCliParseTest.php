<?php

namespace Tests\Unit;

use App\Services\CData\CDataGponCliService;
use PHPUnit\Framework\TestCase;

class CDataGponCliParseTest extends TestCase
{
    /** Sampel nyata `show ont info all` dari FD1608S V3.3.86 (#277). */
    private const SAMPLE = <<<'TXT'
----------------------------------------------------------------------------------------
  F/S P  ONT    SN               Control  Run     Config    Match     Last       Desc
         ID                      flag     state   state     state     down-cause
----------------------------------------------------------------------------------------
  0/0 1  1      CDTCAFD296DB     Active   Online  success   match     --         SERVER-PENJAWI
  0/0 1  2      ZTEGC2C52215     Active   Online  success   match     reboot     Pak Muh Sidokerto
  0/0 1  4      ZTEGC89FE178     Active   Online  success   match     dying-gasp Iman Saeronji Sidokerto/ Vlan 24
  0/0 1  32     ZTEGCD7D32CF     Deactive Offline success   match     dying-gasp Mbah Wi Kunden Sidokerto
----------------------------------------------------------------------------------------
  Total: 31,  online: 31,  deactive: 0,  success: 31 , failed: 0
TXT;

    public function test_parses_only_ont_rows_with_fields(): void
    {
        $onus = (new CDataGponCliService)->parseOntInfo(self::SAMPLE);

        // 4 baris ONU; header/separator/Total diabaikan.
        $this->assertCount(4, $onus);

        $first = $onus[0];
        $this->assertSame([0, 1, 1], [$first['slot'], $first['port'], $first['onu_id']]);
        $this->assertSame('gpon 0/0/1:1', $first['interface']);
        $this->assertSame('CDTCAFD296DB', $first['serial_number']);
        $this->assertSame('CDTC', $first['vendor_id']);
        $this->assertSame('enable', $first['admin_state']);
        $this->assertTrue($first['online']);
        $this->assertNull($first['last_down_cause']); // "--"
        $this->assertSame('SERVER-PENJAWI', $first['name']);
        $this->assertSame('cli', $first['source']);
    }

    public function test_keeps_description_with_spaces_and_slashes(): void
    {
        $onus = (new CDataGponCliService)->parseOntInfo(self::SAMPLE);

        $this->assertSame('Iman Saeronji Sidokerto/ Vlan 24', $onus[2]['name']);
        $this->assertSame('dying-gasp', $onus[2]['last_down_cause']);
    }

    public function test_offline_and_deactive_states(): void
    {
        $onus = (new CDataGponCliService)->parseOntInfo(self::SAMPLE);

        $last = $onus[3]; // ONT 32, Deactive/Offline
        $this->assertSame(32, $last['onu_id']);
        $this->assertFalse($last['online']);
        $this->assertSame('disable', $last['admin_state']);
    }
}
