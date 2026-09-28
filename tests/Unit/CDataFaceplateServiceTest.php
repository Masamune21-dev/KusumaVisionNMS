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
                '1.3.6.1.4.1.17409.2.3.1.3.1.1.12.1.0' => 'DA00-0000000001',
                '1.3.6.1.4.1.17409.2.3.1.3.1.1.14.1.0' => 'GPON OLT',
            ],
        );

        $panel = (new CDataFaceplateService($snmp))->collect($this->olt());

        // Urutan blok FD1608S kiri→kanan: PON · COMBO GE (SFP) · XGE · COMBO GE (RJ45 bertumpuk) · CONSOLE/MGMT.
        $this->assertSame(['PON 0/0', 'COMBO GE', 'XGE', 'COMBO GE', ''], array_column($panel['groups'], 'label'));
        $this->assertSame([1, 1, 1, 1, 1], array_column($panel['groups'], 'module'));

        $pon = $panel['groups'][0];
        $this->assertSame('fiber', $pon['kind']);
        $this->assertSame(4, $pon['chunk']);
        $this->assertSame(['up', 'down', 'shutdown'], array_column($pon['ports'], 'status'));

        // Combo: konektor SFP & RJ45 mewakili port logis yang sama (status identik).
        $this->assertSame('fiber', $panel['groups'][1]['kind']);
        $this->assertSame(1, $panel['groups'][1]['rows']);
        $this->assertSame('fiber', $panel['groups'][2]['kind']);
        $this->assertSame(1, $panel['groups'][2]['rows']);
        $this->assertSame('copper', $panel['groups'][3]['kind']);
        $this->assertSame(2, $panel['groups'][3]['rows']);
        $this->assertSame(['ge 0/0/1'], array_column($panel['groups'][3]['ports'], 'name'));

        $mgmt = $panel['groups'][4];
        $this->assertSame(2, $mgmt['rows']);
        $this->assertSame(['CONSOLE', 'MGMT'], array_column($mgmt['ports'], 'name'));
        $this->assertTrue($mgmt['ports'][0]['fixed']);
        $this->assertSame([], $panel['fixed_ports']);

        $this->assertSame('FD1608S-B1-NDA0', $panel['device']['model']);
        $this->assertSame('DA00-0000000001', $panel['device']['serial']);
        $this->assertSame('off', collect($panel['leds'])->firstWhere('key', 'alm')['state']);
    }

    /**
     * FD1601S-B1 (live 28 Sep 2026): `.2.1` berisi hostname, model ada di `.3.1`. Panel fisik (datasheet
     * + foto): PON · GE 1-2 RJ45 · 10GE SFP+ · CONSOLE — tanpa blok combo & MGMT milik FD1608S.
     */
    public function test_compact_gpon_layout_and_model_from_product_column(): void
    {
        $snmp = new FakeFaceplateSnmp(
            walks: [
                '1.3.6.1.2.1.2.2.1.2' => [
                    '1.3.6.1.2.1.2.2.1.2.524289' => 'ge 0/0/1',
                    '1.3.6.1.2.1.2.2.1.2.524290' => 'ge 0/0/2',
                    '1.3.6.1.2.1.2.2.1.2.786433' => 'xge 0/0/1',
                    '1.3.6.1.2.1.2.2.1.2.1310721' => 'gpon 0/0/1',
                ],
                '1.3.6.1.2.1.2.2.1.8' => [
                    '1.3.6.1.2.1.2.2.1.8.524289' => '1',
                    '1.3.6.1.2.1.2.2.1.8.524290' => '1',
                    '1.3.6.1.2.1.2.2.1.8.786433' => '2',
                    '1.3.6.1.2.1.2.2.1.8.1310721' => '1',
                ],
            ],
            gets: [
                '1.3.6.1.4.1.17409.2.3.1.2.1.1.2.1' => 'Hostname-Uji',
                '1.3.6.1.4.1.17409.2.3.1.2.1.1.3.1' => 'FD1601S-B1',
                '1.3.6.1.4.1.17409.2.3.1.3.1.1.14.1.0' => 'GPON OLT',
            ],
        );

        $panel = (new CDataFaceplateService($snmp))->collect($this->olt());

        $this->assertSame(['PON 0/0', 'GE', 'XGE', ''], array_column($panel['groups'], 'label'));
        $ge = $panel['groups'][1];
        $this->assertSame('copper', $ge['kind']);
        $this->assertSame(1, $ge['rows']);
        $this->assertSame(['ge 0/0/1', 'ge 0/0/2'], array_column($ge['ports'], 'name'));

        $mgmt = $panel['groups'][3];
        $this->assertSame(1, $mgmt['rows']);
        $this->assertSame(['CONSOLE'], array_column($mgmt['ports'], 'name'));

        $this->assertSame('FD1601S-B1', $panel['device']['model']);
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
                // spt OLT EPON live) → harus di-drop, bukan jadi model.
                '1.3.6.1.4.1.17409.2.3.1.2.1.1.2.1' => '4F 4C 54 2D 43 44 41 00 00 00 00 00 00 00 00 00 ',
                '1.3.6.1.4.1.17409.2.3.1.3.1.1.14.1.0' => 'EPON OLT',
            ],
        );

        $panel = (new CDataFaceplateService($snmp))->collect($this->olt());

        // Kartu ekspansi (slot 2) di kiri = modul 1; papan utama (slot 1) di kanan = modul 2
        // bersama CONSOLE/MGMT.
        $this->assertSame(['PON 0/2', 'PON 0/1', ''], array_column($panel['groups'], 'label'));
        $this->assertSame([1, 2, 2], array_column($panel['groups'], 'module'));
        $this->assertArrayNotHasKey('model', $panel['device']);
        $this->assertSame('EPON OLT', $panel['device']['device_type']);
    }

    public function test_epon_8pon_layout_matches_fd1208s_front_panel(): void
    {
        $descr = [];
        $oper = [];
        foreach ([1, 2] as $slot) {
            foreach ([1, 2, 3, 4] as $n) {
                $descr["1.3.6.1.2.1.2.2.1.2.{$slot}{$n}"] = "epon 0/{$slot}/{$n}";
                $oper["1.3.6.1.2.1.2.2.1.8.{$slot}{$n}"] = '1';
            }
        }
        foreach ([1, 2, 3, 4] as $n) {
            $descr["1.3.6.1.2.1.2.2.1.2.9{$n}"] = "ge 0/0/{$n}";
            $descr["1.3.6.1.2.1.2.2.1.2.8{$n}"] = "xge 0/0/{$n}";
            $oper["1.3.6.1.2.1.2.2.1.8.8{$n}"] = $n === 1 ? '1' : '2';
        }
        $snmp = new FakeFaceplateSnmp(walks: ['1.3.6.1.2.1.2.2.1.2' => $descr, '1.3.6.1.2.1.2.2.1.8' => $oper]);

        $panel = (new CDataFaceplateService($snmp))->collect($this->olt());

        $this->assertSame(['PON 0/2', 'PON 0/1', 'GE', 'XGE', ''], array_column($panel['groups'], 'label'));
        $this->assertSame([1, 2, 2, 2, 2], array_column($panel['groups'], 'module'));

        // GE RJ45 sebaris (bukan combo di EPON).
        $ge = $panel['groups'][2];
        $this->assertSame('copper', $ge['kind']);
        $this->assertSame(1, $ge['rows']);

        // XGE 4 SFP bertumpuk 2×2, konvensi C-Data genap di atas: [2,1,4,3].
        $xge = $panel['groups'][3];
        $this->assertSame(2, $xge['rows']);
        $this->assertSame([2, 1, 4, 3], array_column($xge['ports'], 'pos'));
        $this->assertSame(['down', 'up', 'down', 'down'], array_column($xge['ports'], 'status'));
    }

    public function test_stack_even_on_top_handles_odd_count(): void
    {
        $ports = array_map(fn (int $n) => ['pos' => $n], [1, 2, 3]);

        $this->assertSame([2, 1, 3], array_column(CDataFaceplateService::stackEvenOnTop($ports), 'pos'));
    }

    public function test_returns_null_when_no_interfaces(): void
    {
        $panel = (new CDataFaceplateService(new FakeFaceplateSnmp))->collect($this->olt());

        $this->assertNull($panel);
    }
}
