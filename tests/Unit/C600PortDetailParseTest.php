<?php

namespace Tests\Unit;

use App\Services\Snmp\OltSnmpClient;
use App\Services\ZteCardUplinkService;
use App\Services\ZteCliProvisioningExecutor;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class C600PortDetailParseTest extends TestCase
{
    private function service(): ZteCardUplinkService
    {
        // Parsers are pure (no I/O); pass unconfigured collaborators.
        return new ZteCardUplinkService(
            $this->createMock(ZteCliProvisioningExecutor::class),
            $this->createMock(OltSnmpClient::class),
        );
    }

    private function invokePrivate(object $obj, string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionMethod($obj, $method);
        $ref->setAccessible(true);

        return $ref->invoke($obj, ...$args);
    }

    /** C600 uplink `show interface xgei-1/10/1` — verified live (GAMER on LAS GALERAS). */
    public function test_parse_c600_uplink_interface(): void
    {
        $output = <<<'OUT'
xgei-1/10/1 admin status is up, line protocol is up, detect status is OK
Description is GAMER
Byname is null
The port negotiation is force
The port is optical
Duplex full
  Interface current rate:
        input  : 228277505 Bps,     178577 pps
        output :  23596770 Bps,      79610 pps
  Interface peak rate:
        input  : 417559927 Bps, output: 140653520 Bps
  Interface utilization:
        input  : 18.26220%,       output  :     1.88774%
OUT;

        $parsed = $this->service()->parseC600UplinkInterface($output, 'xgei-1/10/1');

        $this->assertNotNull($parsed);
        $this->assertSame('up', $parsed['admin_status']);
        $this->assertSame('up', $parsed['link_status']);
        $this->assertSame('GAMER', $parsed['description']);
        $this->assertSame('force', $parsed['negotiation']);
        $this->assertSame('full', $parsed['duplex']);
        $this->assertSame(228277505, $parsed['input_bps']);
        $this->assertSame(178577, $parsed['input_pps']);
        $this->assertSame(23596770, $parsed['output_bps']);
        $this->assertSame(79610, $parsed['output_pps']);
        $this->assertSame(417559927, $parsed['input_peak_bps']);
        $this->assertSame(140653520, $parsed['output_peak_bps']);
        $this->assertSame(18.2622, $parsed['input_throughput_percent']);
        $this->assertSame(1.88774, $parsed['output_throughput_percent']);
    }

    /** "Description is null" → null (bukan string "null"). */
    public function test_parse_c600_uplink_null_description(): void
    {
        $output = "xgei-1/11/1 admin status is up, line protocol is down, detect status is OK\nDescription is null\n";
        $parsed = $this->service()->parseC600UplinkInterface($output, 'xgei-1/11/1');

        $this->assertNotNull($parsed);
        $this->assertSame('down', $parsed['link_status']);
        $this->assertNull($parsed['description']);
    }

    /** C600 `show processor` — verified live (PFU line cards + MPU control). */
    public function test_parse_c600_processors(): void
    {
        $output = <<<'OUT'
            Character CPU(5s) CPU(1m) CPU(5m) Peak PhyMem FreeMem Mem
================================================================================
PFU-1/3/0      N/A     37%     27%     24%     37%   2048   1012  50.586%
--------------------------------------------------------------------------------
PFU-1/5/0      N/A      8%      7%      6%      8%   2048    995  51.416%
--------------------------------------------------------------------------------
MPU-1/10/0     MSC     27%     14%     10%     32%   8192   5910  27.856%
OUT;

        $bySlot = $this->invokePrivate($this->service(), 'parseC600Processors', $output);

        $this->assertSame(['cpu' => 37, 'phy_mem' => 2048, 'mem' => 51], $bySlot[3]);
        $this->assertSame(['cpu' => 8, 'phy_mem' => 2048, 'mem' => 51], $bySlot[5]);
        $this->assertSame(['cpu' => 27, 'phy_mem' => 8192, 'mem' => 28], $bySlot[10]);
    }

    /** C600 GPON OLT-SFP `show optical-module-info gpon_olt-1/3/1` — verified live. */
    public function test_parse_c600_optical_gpon(): void
    {
        $output = <<<'OUT'
Optical Module Position    : gpon_olt-1/3/1
Optical Module State       : online
Vendor-Name    : OEM                      Product-Name   : GPON-OLT-C+++
Sequence-Number: 202404060002             Version-Level  : 10
Optical Module Information:
Module-Type    : SFP/SFP+            Supply-Vol     : 3.214(v)
Connector      : SC                  Temperature    : 36.613(c)
Fiber-Type     : SM
Module-Class   : GPON/C++
Laser-Rate     : 25   (100Mb/s)      TxPower        : 11.095(dbm)
Wavelength     : 1490 (nm)           TxBias-Current : 29.438(mA)
RxNoise        : -36.989(dbm)        TEC-Current    : N/A
RxPower-Upper    : 3  (dbm)          RxPower-Lower    : -20(dbm)
TxPower-Upper    : 9.000 (dbm)       TxPower-Lower    : -14.000(dbm)
OUT;

        $o = $this->service()->parseC600OpticalModuleInfo($output);

        $this->assertNotNull($o);
        $this->assertSame('OEM', $o['optical_vendor_name']);
        $this->assertSame('GPON-OLT-C+++', $o['optical_vendor_pn']);   // Product-Name
        $this->assertSame('202404060002', $o['optical_vendor_sn']);    // Sequence-Number
        $this->assertSame('SFP/SFP+', $o['optical_module_type']);
        $this->assertSame(1490, $o['optical_wavelength_nm']);
        $this->assertSame('SC', $o['optical_connector']);
        $this->assertNull($o['rx_power_dbm']);                          // PON has no single RxPower
        $this->assertSame(11.095, $o['tx_power_dbm']);
        $this->assertSame(29.438, $o['tx_bias_current_ma']);
        $this->assertSame(36.613, $o['temperature_c']);
        $this->assertSame(3.214, $o['supply_voltage_v']);
        $this->assertSame(3.0, $o['optical_thresholds']['RxPower-Upper']);
        $this->assertSame(-14.0, $o['optical_thresholds']['TxPower-Lower']);
    }

    /** C600 uplink SFP `show optical-module-info xgei-1/10/1` — verified live (Rx+Tx). */
    public function test_parse_c600_optical_uplink(): void
    {
        $output = <<<'OUT'
Optical Module Position    : xgei-1/10/1
Optical Module State       : online
Vendor-Name    : OEM                      Product-Name   : SFP-10G-AOC3M
Sequence-Number: 2410180016               Version-Level  : A
Optical Module Information:
Module-Type    : 10GBASE_SR          Supply-Vol     : 3.441(v)    [3.0, 3.6]
Connector      : LC                  Temperature    : 29.753(c)   [-45.0, 90.0]
Fiber-Type     : MM
RxPower        : -0.493(dbm) [-10.0, -1.0]
Laser-Rate     : 103  (100Mb/s)      TxPower        : -0.038(dbm) [-7.3, -1.0]
Wavelength     : 850  (nm)           TxBias-Current : 6.584(mA)   [0.0, 131.0]
OUT;

        $o = $this->service()->parseC600OpticalModuleInfo($output);

        $this->assertNotNull($o);
        $this->assertSame('SFP-10G-AOC3M', $o['optical_vendor_pn']);
        $this->assertSame('2410180016', $o['optical_vendor_sn']);
        $this->assertSame('10GBASE_SR', $o['optical_module_type']);
        $this->assertSame(850, $o['optical_wavelength_nm']);
        $this->assertSame('LC', $o['optical_connector']);
        $this->assertSame(-0.493, $o['rx_power_dbm']);
        $this->assertSame(-0.038, $o['tx_power_dbm']);
        $this->assertSame(6.584, $o['tx_bias_current_ma']);
        $this->assertSame(29.753, $o['temperature_c']);
        $this->assertSame(3.441, $o['supply_voltage_v']);
    }

    /** interfaceMetadata mengenali ejaan C600 (gpon_olt-1/s/p, xgei-1/s/p). */
    public function test_interface_metadata_c600(): void
    {
        $svc = $this->service();

        $gpon = $this->invokePrivate($svc, 'interfaceMetadata', 'gpon_olt-1/3/1');
        $this->assertSame('gpon', $gpon['interface_type']);
        $this->assertSame(3, $gpon['slot']);
        $this->assertSame(1, $gpon['port']);

        $uplink = $this->invokePrivate($svc, 'interfaceMetadata', 'xgei-1/10/2');
        $this->assertSame('uplink', $uplink['interface_type']);
        $this->assertSame(10, $uplink['slot']);
        $this->assertSame(2, $uplink['port']);

        // C300/C320 tetap dikenali.
        $c300 = $this->invokePrivate($svc, 'interfaceMetadata', 'gpon-olt_1/2/4');
        $this->assertSame('gpon', $c300['interface_type']);
        $this->assertSame(2, $c300['slot']);
        $this->assertSame(4, $c300['port']);
    }
}
