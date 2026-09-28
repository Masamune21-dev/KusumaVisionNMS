<?php

namespace Tests\Unit;

use App\Models\SnmpOlt;
use App\Services\CData\CDataEponSnmpService;
use App\Services\CData\CDataGponCliService;
use App\Services\CData\CDataGponSnmpService;
use App\Services\CData\CDataSnmp;
use App\Services\CData\CDataSnmpMissingOid;
use App\Services\Hioso\HiosoEponSnmpService;
use App\Services\SmartOltSnmpServiceResolver;
use App\Support\SmartOltSupport;
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
            '1.3.6.1.4.1.17409.2.3.4.1.1.7' => ["1.3.6.1.4.1.17409.2.3.4.1.1.7.{$idx}" => 'D0 5F AF 00 00 01'],
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
        $this->assertSame('D0:5F:AF:00:00:01', $onu['mac']);
        $this->assertSame('CDTC12345678', $onu['serial_number']);
        $this->assertSame('25AR', $onu['type_name']);
        $this->assertSame('pelanggan-A', $onu['name']);
        $this->assertSame(-16.97, $onu['rx_power_dbm']);
    }

    public function test_epon_serial_equal_to_mac_is_dropped(): void
    {
        // Firmware EPON nyata: kolom serial (.28) = MAC (.7) persis → jangan duplikasi sbg "serial".
        $idx = '21495809';
        $snmp = new FakeCDataSnmp([
            '1.3.6.1.4.1.17409.2.3.4.1.1.2' => ["1.3.6.1.4.1.17409.2.3.4.1.1.2.{$idx}" => 'epon 0/1/1 onu 1 PelangganX'],
            '1.3.6.1.4.1.17409.2.3.4.1.1.8' => ["1.3.6.1.4.1.17409.2.3.4.1.1.8.{$idx}" => '1'],
            '1.3.6.1.4.1.17409.2.3.4.1.1.7' => ["1.3.6.1.4.1.17409.2.3.4.1.1.7.{$idx}" => 'DC 71 37 00 00 03'],
            '1.3.6.1.4.1.17409.2.3.4.1.1.28' => ["1.3.6.1.4.1.17409.2.3.4.1.1.28.{$idx}" => 'DC 71 37 00 00 03'], // = MAC
        ]);

        $onu = (new CDataEponSnmpService($snmp))->getRegisteredOnus($this->olt())[0];

        $this->assertSame('DC:71:37:00:00:03', $onu['mac']);
        $this->assertNull($onu['serial_number']); // tak menduplikasi MAC sebagai serial
    }

    public function test_gpon_legacy_driver_parses_slot_port_onu(): void
    {
        $snmp = new FakeCDataSnmp([
            '1.3.6.1.4.1.34592.1.5.1.1.2.18.12.1.1' => [], // bukan V3
            '1.3.6.1.4.1.34592.1.3.4.1.1.11' => ['1.3.6.1.4.1.34592.1.3.4.1.1.11.1.2.5' => '1'],
            '1.3.6.1.4.1.34592.1.3.4.1.1.4' => ['1.3.6.1.4.1.34592.1.3.4.1.1.4.1.2.5' => 'PelangganX'],
            '1.3.6.1.4.1.34592.1.5.1.1.2.18.2.1.5' => [],
        ]);

        $driver = new CDataGponSnmpService($snmp, new CDataGponCliService);
        $this->assertFalse($driver->isV3($this->olt()));

        $onus = $driver->getRegisteredOnus($this->olt());
        $this->assertCount(1, $onus);
        $onu = $onus[0];
        $this->assertSame([1, 2, 5], [$onu['slot'], $onu['port'], $onu['onu_id']]);
        $this->assertSame('gpon 0/1/2:5', $onu['interface']);
        $this->assertSame('PelangganX', $onu['name']);
        $this->assertTrue($onu['online']);
    }

    public function test_gpon_v3_driver_builds_full_inventory_from_snmp(): void
    {
        // Master = tabel nama legacy 17409 (beri slot/port/onuId + label); status/Rx 34592 .21 di-join
        // via onuIndex (col3). Tanpa kredensial telnet → jalur SNMP murni, tanpa enrich CLI.
        $idxA = '4718593'; // gpon 0/1/3 onu 1 (online + Rx terisi)
        $idxB = '4718594'; // gpon 0/1/3 onu 2 (offline, MAC & Rx kosong)
        $sufA = '1.0.3.1.1';
        $sufB = '1.0.3.2.1';

        $snmp = new FakeCDataSnmp([
            // penanda V3
            '1.3.6.1.4.1.34592.1.5.1.1.2.18.12.1.1' => ["1.3.6.1.4.1.34592.1.5.1.1.2.18.12.1.1.{$sufA}" => '1'],
            // tabel nama (master)
            '1.3.6.1.4.1.17409.2.8.4.1.1.2' => [
                "1.3.6.1.4.1.17409.2.8.4.1.1.2.{$idxA}" => 'gpon 0/1/3 onu 1 V3-ONU',
                "1.3.6.1.4.1.17409.2.8.4.1.1.2.{$idxB}" => 'gpon 0/1/3 onu 2 Offline-ONU',
            ],
            // MAC (hanya onu 1)
            '1.3.6.1.4.1.17409.2.3.4.7.1.3' => ["1.3.6.1.4.1.17409.2.3.4.7.1.3.{$idxA}.1" => 'D0 5F AF 00 00 01'],
            // join onuIndex
            '1.3.6.1.4.1.34592.1.5.1.1.2.21.1.1.3' => [
                "1.3.6.1.4.1.34592.1.5.1.1.2.21.1.1.3.{$sufA}" => $idxA,
                "1.3.6.1.4.1.34592.1.5.1.1.2.21.1.1.3.{$sufB}" => $idxB,
            ],
            // status: onu 1 online (1), onu 2 offline (-1)
            '1.3.6.1.4.1.34592.1.5.1.1.2.21.1.1.2' => [
                "1.3.6.1.4.1.34592.1.5.1.1.2.21.1.1.2.{$sufA}" => '1',
                "1.3.6.1.4.1.34592.1.5.1.1.2.21.1.1.2.{$sufB}" => '-1',
            ],
            // Rx: onu 1 terisi, onu 2 '--'
            '1.3.6.1.4.1.34592.1.5.1.1.2.21.1.1.5' => [
                "1.3.6.1.4.1.34592.1.5.1.1.2.21.1.1.5.{$sufA}" => '-21.50',
                "1.3.6.1.4.1.34592.1.5.1.1.2.21.1.1.5.{$sufB}" => '--',
            ],
        ]);

        $driver = new CDataGponSnmpService($snmp, new CDataGponCliService);
        $this->assertTrue($driver->isV3($this->olt()));

        $onus = $driver->getRegisteredOnus($this->olt());
        $this->assertCount(2, $onus);

        [$a, $b] = $onus;
        $this->assertSame([1, 3, 1], [$a['slot'], $a['port'], $a['onu_id']]);
        $this->assertSame('gpon 0/1/3:1', $a['interface']);
        $this->assertSame('V3-ONU', $a['name']);
        $this->assertSame('D0:5F:AF:00:00:01', $a['mac']);
        $this->assertTrue($a['online']);
        $this->assertSame('Online', $a['phase_state']);
        $this->assertSame(-21.5, $a['rx_power_dbm']);
        $this->assertSame('snmp', $a['source']);
        $this->assertNull($a['serial_number']); // kolom SN tak ada di data uji ini

        $this->assertSame([1, 3, 2], [$b['slot'], $b['port'], $b['onu_id']]);
        $this->assertFalse($b['online']);
        $this->assertSame('Offline', $b['phase_state']);
        $this->assertNull($b['mac']);
        $this->assertNull($b['rx_power_dbm']);
    }

    /**
     * FD1601S-B1 V3.2.5 (live 28 Sep 2026): tanpa tabel 34592 `.18.12`/legacy, tabel `.21` isinya -1
     * semua — seluruh atribut datang dari NSCRTV `17409.2.8.4`. Dulu terbaca 0 ONU.
     */
    public function test_gpon_compact_model_reads_inventory_from_nscrtv_table(): void
    {
        $up = '4718593';   // gpon 0/0/1 onu 1
        $down = '4718595'; // gpon 0/0/1 onu 3
        $base = '1.3.6.1.4.1.17409.2.8.4.1.1';

        $snmp = new FakeCDataSnmp([
            "{$base}.2" => ["{$base}.2.{$up}" => 'gpon 0/0/1 onu 1 Pelanggan Uji', "{$base}.2.{$down}" => 'gpon 0/0/1 onu 3'],
            "{$base}.3" => ["{$base}.3.{$up}" => '5A 54 45 47 1A 2B 3C 4D', "{$base}.3.{$down}" => 'CDTCABCD'],
            "{$base}.5" => ["{$base}.5.{$up}" => 'ZTEG', "{$base}.5.{$down}" => 'CDTC'],
            "{$base}.6" => ["{$base}.6.{$up}" => 'F609V5.3', "{$base}.6.{$down}" => 'FD511G'],
            "{$base}.7" => ["{$base}.7.{$up}" => '1', "{$base}.7.{$down}" => '2'],
            "{$base}.103" => ["{$base}.103.{$up}" => '--', "{$base}.103.{$down}" => 'LOS'],
            '1.3.6.1.4.1.17409.2.8.4.4.1.4' => [
                "1.3.6.1.4.1.17409.2.8.4.4.1.4.{$up}.0.1310721" => '-2495',
                "1.3.6.1.4.1.17409.2.8.4.4.1.4.{$down}.0.1310721" => '-1', // sentinel ONU offline
            ],
        ]);

        $driver = new CDataGponSnmpService($snmp, new CDataGponCliService);
        $this->assertFalse($driver->isV3($this->olt()));
        $this->assertSame(2, $driver->countRegisteredOnus($this->olt()));

        [$a, $b] = $driver->getRegisteredOnus($this->olt());

        $this->assertSame('gpon 0/0/1:1', $a['interface']);
        $this->assertSame('Pelanggan Uji', $a['name']);
        $this->assertSame('ZTEG1A2B3C4D', $a['serial_number']);
        $this->assertSame('ZTEG', $a['vendor_id']);
        $this->assertSame('F609V5.3', $a['type_name']);
        $this->assertTrue($a['online']);
        $this->assertSame('Online', $a['phase_state']);
        $this->assertNull($a['last_down_cause']);
        $this->assertSame(-24.95, $a['rx_power_dbm']);
        $this->assertSame('-24.95 dBm', $a['rx_power_label']);

        $this->assertSame('gpon 0/0/1:3', $b['interface']);
        $this->assertNull($b['name']);
        $this->assertSame('CDTC41424344', $b['serial_number']); // SN tercetak penuh → STRING 8 karakter
        $this->assertFalse($b['online']);
        $this->assertSame('Offline', $b['phase_state']);
        $this->assertSame('LOS', $b['last_down_cause']);
        $this->assertNull($b['rx_power_dbm']);

        $this->assertSame(['0.1.1' => -24.95], $driver->getPortRxMap($this->olt()));
    }

    /**
     * FD1608S (live 28 Sep 2026): tabel `.21` melaporkan -1 untuk ONU yang online menurut
     * CLI — status wajib dari onuOperationStatus `.7`, bukan dari `.21`.
     */
    public function test_gpon_oper_status_wins_over_unreliable_optical_table(): void
    {
        $idx = '4718600';
        $suffix = '1.0.1.8.1';

        $snmp = new FakeCDataSnmp([
            '1.3.6.1.4.1.34592.1.5.1.1.2.18.12.1.1' => ["1.3.6.1.4.1.34592.1.5.1.1.2.18.12.1.1.{$suffix}" => '1'],
            '1.3.6.1.4.1.17409.2.8.4.1.1.2' => ["1.3.6.1.4.1.17409.2.8.4.1.1.2.{$idx}" => 'gpon 0/0/1 onu 8 X'],
            '1.3.6.1.4.1.17409.2.8.4.1.1.7' => ["1.3.6.1.4.1.17409.2.8.4.1.1.7.{$idx}" => '1'],
            '1.3.6.1.4.1.17409.2.8.4.4.1.4' => ["1.3.6.1.4.1.17409.2.8.4.4.1.4.{$idx}.0.0" => '-1358'],
            '1.3.6.1.4.1.34592.1.5.1.1.2.21.1.1.3' => ["1.3.6.1.4.1.34592.1.5.1.1.2.21.1.1.3.{$suffix}" => $idx],
            '1.3.6.1.4.1.34592.1.5.1.1.2.21.1.1.2' => ["1.3.6.1.4.1.34592.1.5.1.1.2.21.1.1.2.{$suffix}" => '-1'],
        ]);

        $onu = (new CDataGponSnmpService($snmp, new CDataGponCliService))->getRegisteredOnus($this->olt())[0];

        $this->assertTrue($onu['online']);
        $this->assertSame(-13.58, $onu['rx_power_dbm']);
    }

    public function test_gpon_timeout_on_onu_table_fails_scan_instead_of_emptying_it(): void
    {
        $snmp = new class extends FakeCDataSnmp
        {
            public function walk(SnmpOlt $olt, string $oid): array
            {
                throw new \RuntimeException("SNMP walk gagal untuk {$oid}");
            }
        };

        $this->expectException(\RuntimeException::class);
        (new CDataGponSnmpService($snmp, new CDataGponCliService))->getRegisteredOnus($this->olt());
    }

    public function test_gpon_missing_tables_yield_empty_inventory(): void
    {
        $snmp = new class extends FakeCDataSnmp
        {
            public function walk(SnmpOlt $olt, string $oid): array
            {
                throw new CDataSnmpMissingOid("OID {$oid} tak tersedia");
            }
        };

        $this->assertSame([], (new CDataGponSnmpService($snmp, new CDataGponCliService))->getRegisteredOnus($this->olt()));
    }

    public function test_compact_gpon_models_resolve_to_gpon_driver(): void
    {
        foreach (['C-Data FD1601S', 'FD1602S-B1', 'cdata fd1604s'] as $vendor) {
            $this->assertSame(SmartOltSupport::DRIVER_CDATA_GPON, SmartOltSupport::driverKey(new SnmpOlt(['vendor' => $vendor])), $vendor);
        }
    }

    public function test_resolver_returns_correct_driver_per_family(): void
    {
        $resolver = new SmartOltSnmpServiceResolver(new CDataSnmp, new CDataGponCliService);

        $this->assertInstanceOf(
            CDataEponSnmpService::class,
            $resolver->resolve(new SnmpOlt(['vendor' => 'C-Data EPON 17409', 'snmp_version' => 'v2c'])),
        );
        $this->assertInstanceOf(
            CDataGponSnmpService::class,
            $resolver->resolve(new SnmpOlt(['vendor' => 'C-Data GPON 34592', 'snmp_version' => 'v2c'])),
        );
        $this->assertInstanceOf(
            HiosoEponSnmpService::class,
            $resolver->resolve(new SnmpOlt(['vendor' => 'HIOSO', 'snmp_version' => 'v2c'])),
        );

        $this->expectException(\RuntimeException::class);
        $resolver->resolve(new SnmpOlt(['vendor' => 'ZTE C320']));
    }
}
