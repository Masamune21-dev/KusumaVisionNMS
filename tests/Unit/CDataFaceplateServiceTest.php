<?php

namespace Tests\Unit;

use App\Models\SnmpOlt;
use App\Services\CData\CDataFaceplateService;
use App\Services\CData\CDataSnmp;
use Tests\TestCase;

/** Stub SNMP untuk faceplate: walk IF-MIB + get tabel device sintetis. */
class FakeFaceplateSnmp extends CDataSnmp
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

class CDataFaceplateServiceTest extends TestCase
{
    private function olt(): SnmpOlt
    {
        return new SnmpOlt(['snmp_version' => 'v2c']);
    }

    public function test_classifies_gpon_ports_and_keeps_clean_model(): void
    {
        $snmp = new FakeFaceplateSnmp(
            walks: [
                '1.3.6.1.2.1.2.2.1.2' => [   // ifDescr
                    '1.3.6.1.2.1.2.2.1.2.524289' => 'ge 0/0/1',
                    '1.3.6.1.2.1.2.2.1.2.786433' => 'xge 0/0/1',
                    '1.3.6.1.2.1.2.2.1.2.1310721' => 'gpon 0/0/1',
                    '1.3.6.1.2.1.2.2.1.2.1310722' => 'gpon 0/0/2',
                    '1.3.6.1.2.1.2.2.1.2.1310723' => 'gpon 0/0/3',
                ],
                '1.3.6.1.2.1.2.2.1.8' => [   // ifOperStatus
                    '1.3.6.1.2.1.2.2.1.8.524289' => '2',
                    '1.3.6.1.2.1.2.2.1.8.786433' => '1',
                    '1.3.6.1.2.1.2.2.1.8.1310721' => '1',
                    '1.3.6.1.2.1.2.2.1.8.1310722' => '2',
                    '1.3.6.1.2.1.2.2.1.8.1310723' => '2',
                ],
                '1.3.6.1.2.1.2.2.1.7' => [   // ifAdminStatus
                    '1.3.6.1.2.1.2.2.1.7.1310723' => '2',  // shutdown
                ],
            ],
            gets: [
                '1.3.6.1.4.1.17409.2.3.1.2.1.1.2.1' => 'FD1608S-B1-NDA0',
                '1.3.6.1.4.1.17409.2.3.1.3.1.1.12.1.0' => 'DA22-2411000162',
                '1.3.6.1.4.1.17409.2.3.1.3.1.1.14.1.0' => 'GPON OLT',
            ],
        );

        $panel = (new CDataFaceplateService($snmp))->collect($this->olt());

        // Urutan grup: PON dulu, lalu GE, lalu XGE.
        $this->assertSame(['PON 0/0', 'GE', 'XGE'], array_column($panel['groups'], 'label'));

        $pon = $panel['groups'][0];
        $this->assertSame('fiber', $pon['kind']);
        $this->assertSame(['up', 'down', 'shutdown'], array_column($pon['ports'], 'status'));

        $this->assertSame('copper', $panel['groups'][1]['kind']);
        $this->assertSame('fiber', $panel['groups'][2]['kind']);

        $this->assertSame('FD1608S-B1-NDA0', $panel['device']['model']);
        $this->assertSame('DA22-2411000162', $panel['device']['serial']);
        $this->assertSame('off', collect($panel['leds'])->firstWhere('key', 'alm')['state']);
    }

    public function test_subgroups_epon_pon_per_frame_and_drops_hex_model(): void
    {
        $snmp = new FakeFaceplateSnmp(
            walks: [
                '1.3.6.1.2.1.2.2.1.2' => [
                    '1.3.6.1.2.1.2.2.1.2.17825793' => 'epon 0/1/1',
                    '1.3.6.1.2.1.2.2.1.2.34603009' => 'epon 0/2/1',
                ],
                '1.3.6.1.2.1.2.2.1.8' => [
                    '1.3.6.1.2.1.2.2.1.8.17825793' => '1',
                    '1.3.6.1.2.1.2.2.1.8.34603009' => '1',
                ],
            ],
            gets: [
                // Field nama fixed-width null-padded balik sbg Hex-STRING (dgn trailing space,
                // spt PEKALONGAN live) → harus di-drop, bukan jadi model.
                '1.3.6.1.4.1.17409.2.3.1.2.1.1.2.1' => '4F 4C 54 2D 43 44 41 00 00 00 00 00 00 00 00 00 ',
                '1.3.6.1.4.1.17409.2.3.1.3.1.1.14.1.0' => 'EPON OLT',
            ],
        );

        $panel = (new CDataFaceplateService($snmp))->collect($this->olt());

        $this->assertSame(['PON 0/1', 'PON 0/2'], array_column($panel['groups'], 'label'));
        $this->assertArrayNotHasKey('model', $panel['device']);
        $this->assertSame('EPON OLT', $panel['device']['device_type']);
    }

    public function test_returns_null_when_no_interfaces(): void
    {
        $panel = (new CDataFaceplateService(new FakeFaceplateSnmp))->collect($this->olt());

        $this->assertNull($panel);
    }
}
