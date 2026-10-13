<?php

namespace Tests\Unit;

use App\Models\SnmpOlt;
use App\Services\Snmp\OltSnmpClient;
use App\Services\ZteCardUplinkService;
use App\Services\ZteCliProvisioningExecutor;
use Tests\TestCase;

/**
 * Ejaan gei/xgei port uplink ZTE tak bisa ditebak dari tipe kartu: SMXA C320 bisa `gei_1/4/2` atau
 * `xgei_1/4/2` (port 10GE) dengan baris `show card` identik, dan HUVQ C300 = 2× xgei + 2× gei.
 * Bentuk ifName di bawah disalin dari OLT produksi (13 Okt 2026).
 */
class ZteUplinkInterfaceNamesTest extends TestCase
{
    /**
     * @param  array<string, array<string, string>>  $walks  OID dasar => baris walk
     */
    private function snmp(array $walks): OltSnmpClient
    {
        return new class($walks) extends OltSnmpClient
        {
            public function __construct(private array $walks) {}

            public function walk(SnmpOlt $olt, string $oid, bool $plain = false): array
            {
                return $this->walks[$oid] ?? [];
            }
        };
    }

    private function service(): ZteCardUplinkService
    {
        return new ZteCardUplinkService(
            $this->createMock(ZteCliProvisioningExecutor::class),
            $this->createMock(OltSnmpClient::class),
        );
    }

    public function test_reads_uplink_names_from_if_name(): void
    {
        $snmp = $this->snmp([
            '1.3.6.1.2.1.31.1.1.1.1' => [
                '1.3.6.1.2.1.31.1.1.1.1.268435456' => 'gpon-olt_1/1/1',
                '1.3.6.1.2.1.31.1.1.1.1.268632064' => 'gei_1/4/1',
                '1.3.6.1.2.1.31.1.1.1.1.268632320' => 'xgei_1/4/2',
                '1.3.6.1.2.1.31.1.1.1.1.268632576' => 'gei_1/4/3',
            ],
        ]);

        $this->assertSame(
            ['4/1' => 'gei_1/4/1', '4/2' => 'xgei_1/4/2', '4/3' => 'gei_1/4/3'],
            $snmp->uplinkInterfaceNames(new SnmpOlt),
        );
    }

    /** Sebagian firmware C320 mengembalikan ifName tanpa uplink tapi ifDescr lengkap. */
    public function test_falls_back_to_if_descr(): void
    {
        $snmp = $this->snmp([
            '1.3.6.1.2.1.31.1.1.1.1' => ['1.3.6.1.2.1.31.1.1.1.1.268632064' => ''],
            '1.3.6.1.2.1.2.2.1.2' => [
                '1.3.6.1.2.1.2.2.1.2.268632064' => 'gei_1/4/1',
                '1.3.6.1.2.1.2.2.1.2.268632320' => 'xgei_1/4/2',
            ],
        ]);

        $this->assertSame(
            ['4/1' => 'gei_1/4/1', '4/2' => 'xgei_1/4/2'],
            $snmp->uplinkInterfaceNames(new SnmpOlt),
        );
    }

    public function test_reads_c600_spelling(): void
    {
        $snmp = $this->snmp([
            '1.3.6.1.2.1.31.1.1.1.1' => ['1.3.6.1.2.1.31.1.1.1.1.1' => 'xgei-1/10/1'],
        ]);

        $this->assertSame(['10/1' => 'xgei-1/10/1'], $snmp->uplinkInterfaceNames(new SnmpOlt));
    }

    public function test_discovery_uses_known_names_per_port(): void
    {
        $cards = [
            ['slot' => 4, 'cfg_type' => 'SMXA', 'port_count' => 3, 'status' => 'INSERVICE'],
            ['slot' => 19, 'cfg_type' => 'HUVQ', 'port_count' => 4, 'status' => 'INSERVICE'],
        ];
        $known = [
            '4/1' => 'gei_1/4/1', '4/2' => 'xgei_1/4/2', '4/3' => 'gei_1/4/3',
            '19/1' => 'xgei_1/19/1', '19/2' => 'xgei_1/19/2', '19/3' => 'gei_1/19/3', '19/4' => 'gei_1/19/4',
            // Port kartu kontrol (bukan uplink) tak ikut masuk inventaris.
            '10/1' => 'gei_1/10/1',
        ];

        $interfaces = $this->service()->discoverUplinkInterfaces($cards, $known);

        $this->assertSame(
            ['gei_1/4/1', 'xgei_1/4/2', 'gei_1/4/3', 'xgei_1/19/1', 'xgei_1/19/2', 'gei_1/19/3', 'gei_1/19/4'],
            array_column($interfaces, 'interface'),
        );
        $this->assertSame(19, $interfaces[5]['slot']);
        $this->assertSame(3, $interfaces[5]['port']);
        $this->assertSame('HUVQ', $interfaces[5]['card_type']);
    }

    /** Tanpa SNMP: perilaku lama (tebakan per tipe kartu). */
    public function test_discovery_without_known_names_guesses_from_card_type(): void
    {
        $interfaces = $this->service()->discoverUplinkInterfaces([
            ['slot' => 4, 'cfg_type' => 'SMXA', 'port_count' => 2, 'status' => 'INSERVICE'],
            ['slot' => 20, 'cfg_type' => 'HUVQ', 'port_count' => 1, 'status' => 'INSERVICE'],
        ]);

        $this->assertSame(['gei_1/4/1', 'gei_1/4/2', 'xgei_1/20/1'], array_column($interfaces, 'interface'));
    }
}
