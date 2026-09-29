<?php

namespace Tests\Unit;

use App\Services\CData\CDataGponPortService;
use PHPUnit\Framework\TestCase;

/**
 * Parser CLI VLAN & detail port C-Data GPON. Sampel = output asli FD1608S-B1-NDA0 V3 (29 Sep 2026),
 * deskripsi VLAN & serial transceiver diganti fiktif.
 */
class CDataGponPortParseTest extends TestCase
{
    private const VLANS = <<<'TXT'
show vlan all
  VLAN ID: 1
  VLAN Description:
  User-bridge: disable
  VLAN Type: Normal vlan
  Tagged Ports:
    gpon 0/0/1             gpon 0/0/2             gpon 0/0/3
    gpon 0/0/4             gpon 0/0/5             gpon 0/0/6
    gpon 0/0/7             gpon 0/0/8
  Untagged Ports:
    ge 0/0/1               ge 0/0/2               ge 0/0/3
    ge 0/0/4               xge 0/0/1              xge 0/0/2

  VLAN ID: 27
  VLAN Description:
  User-bridge: disable
  VLAN Type: L3intf vlan
  Tagged Ports:
    gpon 0/0/1             gpon 0/0/2             gpon 0/0/3
    gpon 0/0/4             gpon 0/0/5             gpon 0/0/6
    gpon 0/0/7             gpon 0/0/8             xge 0/0/1
  Untagged Ports:  none

  VLAN ID: 28
  VLAN Description: UJI
  User-bridge: disable
  VLAN Type: Normal vlan
  Tagged Ports:
    gpon 0/0/1             gpon 0/0/2             gpon 0/0/3
    gpon 0/0/4             gpon 0/0/5             gpon 0/0/6
    gpon 0/0/7             gpon 0/0/8
  Untagged Ports:  none

FD1608S-B1-NDA0#
TXT;

    private const XGE_INFO = <<<'TXT'
show port info 1
-------------------------------------------------------------------------------------
 Port                           : xge 0/0/1
 Admin States                   : enable
 Link States                    : Up
 Port Description               : --
 Flow Control                   : on
 The Maximum Frame Size         : 1526
 Port Rate                      : --
 Media Type                     : Fiber
 Auto Negotiation               : enable
 Link Duplex                    : Autonegotiation(full)
 Link Speed                     : Autonegotiation(10000)
-------------------------------------------------------------------------------------
 Isolate                        : disable
-------------------------------------------------------------------------------------
 Native-vlan                    : 1
 Link type                      : Trunk
 Priority                       : 0
 Tagged Vlan ID                 :
  210,220,230,300,310,320,330,700,810,900
 Untagged Vlan ID               :
  1
 Broadcast storm control        : 150 pps(default:150 pps)
-------------------------------------------------------------------------------------
FD1608S-B1-NDA0(config-xge-0/0)#
TXT;

    /** Daftar tagged terbungkus ke baris berikut TANPA koma (`…,810 ⏎ 900`). */
    private const GPON_INFO = <<<'TXT'
show port info 1
-------------------------------------------------------------------------------------
 Port                           : gpon 0/0/1
 Optical Module Status          : normal
 Admin State                    : enable
 Link State                     : on
 Port Description               : --
 Max guaranteed Bindwitch(Kbps) : 1024000
-------------------------------------------------------------------------------------
 Native-vlan                    : 1
 Link type                      : Trunk
 Priority                       : 0
 Tagged Vlan ID                 :
  1,210,220,230-231,300,310,320,330,700,810
 900
 Untagged Vlan ID               : --
-------------------------------------------------------------------------------------
FD1608S-B1-NDA0(config-gpon-0/0)#
TXT;

    private const GPON_DDM = <<<'TXT'
show port ddm-info gpon 0/0/1
gpon 0/0/1 Transceiver information:
-------------------------------------------------------------------------------------
 Temp(C)                                 : 43.38
 Temp warning threshold(C)               : [0.00,70.00]
 Temp alarm threshold(C)                 : [-10.00,100.00]
 Voltage(V)                              : 3.24
 Bias(mA)                                : 13.94
 RX power(dBM)                           : -26.02
 TX power(dBM)                           : 9.47
 Tx power current warning threshold(dBm) : [0.00,9.00]
 Tx power current alarm threshold(dBm)   : [-1.00,10.00]
-------------------------------------------------------------------------------------
 Transceiver Type  : OC48/STM16_NA_SFP
 Connector Type    : SC
 Wavelength(nm)    : 1490(nm)
 Transfer Distance : 20km(9um)
 Diagnostic Monitor: YES
 Vendor Name       : OEM
 Ordering Name     : Class C++++
 Serial Number     : UJI0000000001
 Manufact Date     : 20241014
-------------------------------------------------------------------------------------

FD1608S-B1-NDA0(config)#
TXT;

    private const STATS = <<<'TXT'
show statistics port 1
-------------------------------------------------------------------------------------
 Port: xge 0/0/1        Rx rate(kbps):128976     Tx rate(kbps):13011
                           L1  (kbps):131045        L1  (kbps):14076
-------------------------------------------------------------------------------------
                                RX                 TX
-------------------------------------------------------------------------------------
 Octets                         : 59326795772273    5907736008127
 Packets                        : 48744304448       26250742452
 Discards                       : 11553689          0
 Errors                         : 0                 0
 frames 64 octets               : 69276258          10339121
 Rate pps                       : 12931             6656
 Utilization                    : 1.30              0.13
-------------------------------------------------------------------------------------
FD1608S-B1-NDA0(config-xge-0/0)#
TXT;

    private function service(): CDataGponPortService
    {
        return new CDataGponPortService;
    }

    public function test_parses_vlan_blocks_with_wrapped_port_lists(): void
    {
        $vlans = $this->service()->parseVlans(self::VLANS);

        $this->assertSame([1, 27, 28], array_column($vlans, 'id'));

        [$default, $l3, $test] = $vlans;
        $this->assertCount(8, $default['tagged']);
        $this->assertSame(['ge 0/0/1', 'ge 0/0/2', 'ge 0/0/3', 'ge 0/0/4', 'xge 0/0/1', 'xge 0/0/2'], $default['untagged']);
        $this->assertNull($default['description']);

        $this->assertSame('L3intf vlan', $l3['type']);
        $this->assertContains('xge 0/0/1', $l3['tagged']);
        $this->assertSame([], $l3['untagged']); // "none"

        $this->assertSame('UJI', $test['description']);
        $this->assertNotContains('xge 0/0/1', $test['tagged']);
        $this->assertCount(8, $test['tagged']);
    }

    public function test_empty_show_vlan_output_means_vlan_absent(): void
    {
        $this->assertSame([], $this->service()->parseVlans("show vlan 3999\r\nFD1608S-B1-NDA0(config)# "));
    }

    public function test_parses_uplink_port_info(): void
    {
        $info = $this->service()->parsePortInfo(self::XGE_INFO);

        $this->assertSame('enable', $info['admin_status']);
        $this->assertTrue($info['link_up']);
        $this->assertSame(10000, $info['speed_mbps']);
        $this->assertSame('full', $info['duplex']);
        $this->assertSame('Trunk', $info['mode']);
        $this->assertSame(1, $info['native_vlan']);
        $this->assertSame(1526, $info['mtu']);
        $this->assertNull($info['description']); // "--"
        $this->assertSame(['210', '220', '230', '300', '310', '320', '330', '700', '810', '900'], $info['tagged']);
        $this->assertSame(['1'], $info['untagged']);
        $this->assertContains(['label' => 'Broadcast storm control', 'value' => '150 pps(default:150 pps)'], $info['attributes']);
    }

    public function test_parses_gpon_port_info_with_wrapped_vlan_list(): void
    {
        $info = $this->service()->parsePortInfo(self::GPON_INFO);

        $this->assertSame('enable', $info['admin_status']); // "Admin State" (tunggal) di GPON
        $this->assertTrue($info['link_up']); // "on"
        $this->assertSame('normal', $info['optical_status']);
        $this->assertSame(['1', '210', '220', '230-231', '300', '310', '320', '330', '700', '810', '900'], $info['tagged']);
        $this->assertSame([], $info['untagged']);
    }

    public function test_parses_ddm_with_thresholds_and_absent_module(): void
    {
        $ddm = $this->service()->parseDdm(self::GPON_DDM);

        $this->assertTrue($ddm['present']);
        $this->assertSame(-26.02, $ddm['rx_power_dbm']);
        $this->assertSame(9.47, $ddm['tx_power_dbm']);
        $this->assertSame(1490, $ddm['wavelength_nm']);
        $this->assertSame('Class C++++', $ddm['vendor_pn']);
        $this->assertSame([0.0, 9.0], $ddm['thresholds']['tx_warn']);
        $this->assertSame([-10.0, 100.0], $ddm['thresholds']['temp_alarm']);

        $absent = $this->service()->parseDdm("show port ddm-info xge 0/0/2\r\nxge 0/0/2 Transceiver information: \r\n Info: Transceiver is absent.\r\n");
        $this->assertSame(['present' => false], $absent);

        // GE RJ45 di FD1601S (tanpa slot SFP).
        $copper = $this->service()->parseDdm("show port ddm-info ge 0/0/1\r\n Transceiver parm error!\r\nOLT(config)# ");
        $this->assertSame(['present' => false], $copper);
    }

    public function test_parses_statistics(): void
    {
        $stats = $this->service()->parseStatistics(self::STATS);

        $this->assertSame(128976, $stats['rx_kbps']);
        $this->assertSame(13011, $stats['tx_kbps']);
        $this->assertSame('59326795772273', $stats['rx_octets']);
        $this->assertSame('11553689', $stats['rx_discards']);
        $this->assertSame(12931, $stats['rx_pps']);
        $this->assertSame(1.3, $stats['rx_utilization']);
    }

    /** EPON FD1304E: port PON tanpa `show port info` → `show port state`; label huruf kecil & spasi ganda. */
    public function test_parses_epon_port_state_and_vlan_names(): void
    {
        $state = <<<'TXT'
show port state 1
-----------------------------------------------------------------------------
  Frame/Slot                        : 0/1 
  Port                              : 1 
  Optical Module status             : normal 
  Admin state                       : enable 
  Link  state                       : on 
-----------------------------------------------------------------------------
  Port Name                         : epon 0/1/1 
  Port Description                  :  
  Native vlan                       : 1 
  Maximum frame size                : 1526 
  Flow-control                      : on 
-----------------------------------------------------------------------------
OLT(config-epon-0/1)# 
TXT;

        $info = $this->service()->parsePortInfo($state);
        $this->assertSame('enable', $info['admin_status']);
        $this->assertTrue($info['link_up']);
        $this->assertSame('normal', $info['optical_status']);
        $this->assertSame(1, $info['native_vlan']);
        $this->assertSame(1526, $info['mtu']);
        $this->assertSame('on', $info['flow_control']);
        $this->assertNull($info['description']);

        $vlans = $this->service()->parseVlans("  VLAN ID: 450\r\n  VLAN Type: Normal vlan\r\n  Tagged Ports: \r\n    xge 0/0/1              epon 0/2/1           \r\n  Untagged Ports:  none\r\n");
        $this->assertSame(['xge 0/0/1', 'epon 0/2/1'], $vlans[0]['tagged']);

        $ddm = $this->service()->parseDdm("epon 0/1/1 Transceiver information: \r\n Temp(C)           :38.69\r\n TX power(dBM)     :9.43\r\n RX power(dBM)     : -24.95\r\n Vendor Name       : OEM              \r\n Product name      : PX20++++         \r\n");
        $this->assertSame(38.69, $ddm['temperature_c']);
        $this->assertSame(-24.95, $ddm['rx_power_dbm']);
        $this->assertSame('PX20++++', $ddm['vendor_pn']);
        $this->assertSame([], $ddm['thresholds']);
    }

    public function test_parses_port_vlan_block(): void
    {
        $out = <<<'TXT'
show port vlan 2
-------------------------------------------------------------------------------------
 Port: xge 0/0/2   Mode: Access  Native-Vlan: 1   Priority: 0
-------------------------------------------------------------------------------------
 Tagged-Vlan:
 --
-------------------------------------------------------------------------------------
 Untagged-Vlan:
 1
-------------------------------------------------------------------------------------
FD1608S-B1-NDA0(config-xge-0/0)#
TXT;

        $this->assertSame(
            ['mode' => 'Access', 'native_vlan' => 1, 'tagged' => [], 'untagged' => ['1']],
            $this->service()->parsePortVlan($out),
        );
    }

    public function test_vlan_list_helpers(): void
    {
        $ranges = CDataGponPortService::parseVlanList('210,220,230-231 300');

        $this->assertTrue(CDataGponPortService::vlanListContains($ranges, 231));
        $this->assertFalse(CDataGponPortService::vlanListContains($ranges, 232));
        $this->assertSame('210,220-221,230-232,300', CDataGponPortService::compressVlanRanges([...$ranges, [221, 221], [232, 232]]));
        $this->assertSame([210, 220, 230, 231, 300], CDataGponPortService::expandVlanRanges($ranges));
    }
}
