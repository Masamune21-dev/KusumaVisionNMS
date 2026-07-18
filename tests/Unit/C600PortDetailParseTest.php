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
