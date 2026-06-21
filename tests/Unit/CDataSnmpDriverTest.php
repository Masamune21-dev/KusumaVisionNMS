<?php

namespace Tests\Unit;

use App\Models\SnmpOlt;
use App\Services\CData\CDataEponSnmpService;
use App\Services\CData\CDataGponSnmpService;
use App\Services\CData\CDataSnmp;
use App\Services\SmartOltSnmpServiceResolver;
use Tests\TestCase;

/**
 * Stub SNMP: kembalikan data walk/get sintetis di-key oleh base-OID yang diminta driver.
 */
class FakeCDataSnmp extends CDataSnmp
{
    /**
     * @param  array<string, array<string, string>>  $walks
     * @param  array<string, ?string>  $gets
     */
    public function __construct(private array $walks = [], private array $gets = []) {}

    public function get(SnmpOlt $olt, string $oid): ?string
    {
        return $this->gets[$oid] ?? null;
    }

    public function walk(SnmpOlt $olt, string $oid): array
    {
        return $this->walks[$oid] ?? [];
    }
}

class CDataSnmpDriverTest extends TestCase
{
    private function olt(): SnmpOlt
    {
        return new SnmpOlt(['snmp_version' => 'v2c']);
    }

    public function test_epon_driver_parses_inventory_and_rx(): void
    {
        $idx = '16777472'; // device-index (key)
        $snmp = new FakeCDataSnmp([
            '1.3.6.1.4.1.17409.2.3.4.1.1.2' => ["1.3.6.1.4.1.17409.2.3.4.1.1.2.{$idx}" => 'epon 0/1/1 onu 1 pelanggan-A'],
            '1.3.6.1.4.1.17409.2.3.4.1.1.8' => ["1.3.6.1.4.1.17409.2.3.4.1.1.8.{$idx}" => '1'],
            '1.3.6.1.4.1.17409.2.3.4.1.1.7' => ["1.3.6.1.4.1.17409.2.3.4.1.1.7.{$idx}" => 'D0 5F AF 63 0F 2F'],
            '1.3.6.1.4.1.17409.2.3.4.1.1.25' => ["1.3.6.1.4.1.17409.2.3.4.1.1.25.{$idx}" => 'CDTC'],
            '1.3.6.1.4.1.17409.2.3.4.1.1.26' => ["1.3.6.1.4.1.17409.2.3.4.1.1.26.{$idx}" => '25AR'],
            '1.3.6.1.4.1.17409.2.3.4.1.1.28' => ["1.3.6.1.4.1.17409.2.3.4.1.1.28.{$idx}" => 'CDTC12345678'],
            '1.3.6.1.4.1.17409.2.3.4.2.1.4' => ["1.3.6.1.4.1.17409.2.3.4.2.1.4.{$idx}.1.1" => '-1697'],
        ]);

        $onus = (new CDataEponSnmpService($snmp))->getRegisteredOnus($this->olt());

        $this->assertCount(1, $onus);
        $onu = $onus[0];
        $this->assertSame(1, $onu['slot']);
        $this->assertSame(1, $onu['port']);
        $this->assertSame(1, $onu['onu_id']);
        $this->assertSame('epon 0/1/1 onu 1', $onu['interface']);
        $this->assertTrue($onu['online']);
        $this->assertSame('D0:5F:AF:63:0F:2F', $onu['mac']);
        $this->assertSame('CDTC12345678', $onu['serial_number']);
        $this->assertSame('25AR', $onu['type_name']);
        $this->assertSame('pelanggan-A', $onu['name']);
        $this->assertSame(-16.97, $onu['rx_power_dbm']);
    }

    public function test_gpon_legacy_driver_parses_slot_port_onu(): void
    {
        $snmp = new FakeCDataSnmp([
            '1.3.6.1.4.1.34592.1.5.1.1.2.18.12.1.1' => [], // bukan V3
            '1.3.6.1.4.1.34592.1.3.4.1.1.11' => ['1.3.6.1.4.1.34592.1.3.4.1.1.11.1.2.5' => '1'],
            '1.3.6.1.4.1.34592.1.3.4.1.1.4' => ['1.3.6.1.4.1.34592.1.3.4.1.1.4.1.2.5' => 'PelangganX'],
            '1.3.6.1.4.1.34592.1.5.1.1.2.18.2.1.5' => [],
        ]);

        $driver = new CDataGponSnmpService($snmp);
        $this->assertFalse($driver->isV3($this->olt()));

        $onus = $driver->getRegisteredOnus($this->olt());
        $this->assertCount(1, $onus);
        $onu = $onus[0];
        $this->assertSame([1, 2, 5], [$onu['slot'], $onu['port'], $onu['onu_id']]);
        $this->assertSame('gpon 0/1/2:5', $onu['interface']);
        $this->assertSame('PelangganX', $onu['name']);
        $this->assertTrue($onu['online']);
    }

    public function test_gpon_v3_driver_uses_v3_table_and_ifdescr_map(): void
    {
        $suffix = '1.0.18.12.1'; // .1.0.<ifIndex=18>.<flow=12>.<onuId=1>
        $snmp = new FakeCDataSnmp([
            '1.3.6.1.4.1.34592.1.5.1.1.2.18.12.1.1' => ["1.3.6.1.4.1.34592.1.5.1.1.2.18.12.1.1.{$suffix}" => '1'],
            '1.3.6.1.4.1.34592.1.5.1.1.2.18.12.1.10' => ["1.3.6.1.4.1.34592.1.5.1.1.2.18.12.1.10.{$suffix}" => 'V3-ONU'],
            '1.3.6.1.4.1.34592.1.5.1.1.2.18.12.1.11' => [],
            '1.3.6.1.2.1.2.2.1.2' => ['1.3.6.1.2.1.2.2.1.2.18' => 'gpon 0/1/3'],
        ]);

        $driver = new CDataGponSnmpService($snmp);
        $this->assertTrue($driver->isV3($this->olt()));

        $onus = $driver->getRegisteredOnus($this->olt());
        $this->assertCount(1, $onus);
        $onu = $onus[0];
        $this->assertSame([1, 3, 1], [$onu['slot'], $onu['port'], $onu['onu_id']]);
        $this->assertSame('gpon 0/1/3:1', $onu['interface']);
        $this->assertSame('V3-ONU', $onu['name']);
        $this->assertTrue($onu['v3']);
    }

    public function test_resolver_returns_correct_driver_per_family(): void
    {
        $resolver = new SmartOltSnmpServiceResolver(new CDataSnmp);

        $this->assertInstanceOf(
            CDataEponSnmpService::class,
            $resolver->resolve(new SnmpOlt(['vendor' => 'C-Data EPON 17409', 'snmp_version' => 'v2c'])),
        );
        $this->assertInstanceOf(
            CDataGponSnmpService::class,
            $resolver->resolve(new SnmpOlt(['vendor' => 'C-Data GPON 34592', 'snmp_version' => 'v2c'])),
        );

        $this->expectException(\RuntimeException::class);
        $resolver->resolve(new SnmpOlt(['vendor' => 'ZTE C320']));
    }
}
