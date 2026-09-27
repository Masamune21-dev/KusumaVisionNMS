<?php

namespace Tests\Unit;

use App\Models\SnmpOlt;
use App\Services\Hioso\HiosoFaceplateService;
use App\Services\Hioso\HiosoSnmp;
use Tests\TestCase;

/** Stub SNMP HiOSO: walk IF-MIB + get firmware sintetis. */
class FakeHiosoFaceplateSnmp extends HiosoSnmp
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

    public function walk(SnmpOlt $olt, string $oid, int $timeoutUs = 10_000_000, int $retries = 3): array
    {
        return $this->walks[$oid] ?? [];
    }
}

class HiosoFaceplateServiceTest extends TestCase
{
    private function snmp(string $firmware): FakeHiosoFaceplateSnmp
    {
        return new FakeHiosoFaceplateSnmp(
            walks: [
                '1.3.6.1.2.1.2.2.1.2' => [
                    '1.3.6.1.2.1.2.2.1.2.1' => 'Pon-Nni1',
                    '1.3.6.1.2.1.2.2.1.2.5' => 'G1',
                    '1.3.6.1.2.1.2.2.1.2.6' => 'G2',
                    '1.3.6.1.2.1.2.2.1.2.7' => 'G3',
                    '1.3.6.1.2.1.2.2.1.2.8' => 'G4',
                ],
                '1.3.6.1.2.1.2.2.1.8' => [
                    '1.3.6.1.2.1.2.2.1.8.5' => '1',
                    '1.3.6.1.2.1.2.2.1.8.6' => '1',
                    '1.3.6.1.2.1.2.2.1.8.7' => '2',
                    '1.3.6.1.2.1.2.2.1.8.8' => '2',
                ],
            ],
            gets: ['1.3.6.1.4.1.25355.3.1.8.1.1.2.1' => $firmware],
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function ponPorts(): array
    {
        return [
            ['port' => 2, 'name' => 'epon 0/1/2', 'oper_status' => 'down'],
            ['port' => 1, 'name' => 'epon 0/1/1', 'oper_status' => 'up'],
        ];
    }

    public function test_ha7304_layout_follows_printed_panel_labels(): void
    {
        $panel = (new HiosoFaceplateService($this->snmp('1.0.0.1/HA7304/SN2018-03-00007')))
            ->build(new SnmpOlt, $this->ponPorts());

        $this->assertSame('HA7304', $panel['device']['model']);
        $this->assertSame(['PON', 'SFP', 'GE'], array_column($panel['groups'], 'label'));

        // PON urut pos, status turunan scanner.
        $this->assertSame([1, 2], array_column($panel['groups'][0]['ports'], 'pos'));
        $this->assertSame(['up', 'down'], array_column($panel['groups'][0]['ports'], 'status'));

        // G1/G2 = SFP (fiber) sebaris; blok RJ45 2×2 = G4 atas / G3 bawah, CONSOLE atas / MGMT bawah.
        $sfp = $panel['groups'][1];
        $this->assertSame('fiber', $sfp['kind']);
        $this->assertSame(['G1', 'G2'], array_column($sfp['ports'], 'name'));
        $this->assertSame(['up', 'up'], array_column($sfp['ports'], 'status'));

        $ge = $panel['groups'][2];
        $this->assertSame('copper', $ge['kind']);
        $this->assertSame(2, $ge['rows']);
        $this->assertSame(['G4', 'G3', 'CONSOLE', 'MGMT'], array_column($ge['ports'], 'name'));
        $this->assertSame(['down', 'down', 'fixed', 'fixed'], array_column($ge['ports'], 'status'));

        $this->assertSame([], $panel['fixed_ports']);
        $this->assertSame(['ALM', 'SYS', 'MACT', 'PWR'], array_column($panel['leds'], 'label'));
    }

    public function test_ha7304c_uses_same_layout(): void
    {
        $panel = (new HiosoFaceplateService($this->snmp('1.0.0.1/HA7304C/SN2018-03-00007')))
            ->build(new SnmpOlt, $this->ponPorts());

        $this->assertSame(['G4', 'G3', 'CONSOLE', 'MGMT'], array_column($panel['groups'][2]['ports'], 'name'));
    }

    public function test_ha7302_shows_two_rj45_uplinks_and_console_only(): void
    {
        $panel = (new HiosoFaceplateService($this->snmp('7.76/HA7302CSM/SN2020-01-00001')))
            ->build(new SnmpOlt, $this->ponPorts());

        $this->assertSame(['PON', 'GE', ''], array_column($panel['groups'], 'label'));
        $this->assertSame('copper', $panel['groups'][1]['kind']);
        $this->assertSame(1, $panel['groups'][1]['rows']);
        $this->assertSame(['G1', 'G2', 'G3', 'G4'], array_column($panel['groups'][1]['ports'], 'name'));
        $this->assertSame(['CONSOLE'], array_column($panel['groups'][2]['ports'], 'name'));
    }

    public function test_unknown_model_falls_back_to_generic_copper_row(): void
    {
        $panel = (new HiosoFaceplateService($this->snmp('1.0.0.1/HA7308/SN1')))
            ->build(new SnmpOlt, $this->ponPorts());

        $this->assertSame(['PON', 'GE', ''], array_column($panel['groups'], 'label'));
        $this->assertSame(['G1', 'G2', 'G3', 'G4'], array_column($panel['groups'][1]['ports'], 'name'));
        $this->assertSame(['CONSOLE', 'MGMT'], array_column($panel['groups'][2]['ports'], 'name'));
    }

    public function test_returns_null_when_no_interfaces(): void
    {
        $panel = (new HiosoFaceplateService(new FakeHiosoFaceplateSnmp))->build(new SnmpOlt, []);

        $this->assertNull($panel);
    }
}
