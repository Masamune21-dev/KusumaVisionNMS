<?php

namespace Tests\Feature;

use App\Models\OnuZoneLink;
use App\Models\SnmpOlt;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Zona bersifat generik lintas family — pastikan halaman Port ONU C-Data & HiOSO
 * (bukan cuma ZTE) ikut menerima prop zones/zone_links, dan endpoint assign yang sama
 * (onu-zone.assign) benar-benar bisa dipakai untuk ONU non-ZTE, termasuk gerbang
 * PartnerOltScope (partner tak bisa assign zona ke OLT yang bukan miliknya).
 */
class ZoneNonZteAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function makeOlt(string $vendor, string $sysDescr, array $extraOnu = []): SnmpOlt
    {
        return SnmpOlt::create([
            'name' => 'NONZTE-TEST-'.$vendor,
            'vendor' => $vendor,
            'ip' => '10.40.0.'.random_int(2, 250),
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'last_test_result' => [
                'ok' => true,
                'onu_scanned_at' => now()->toIso8601String(), // cache "segar" — halaman tak re-scan live
                'system' => ['sys_descr' => $sysDescr],
                'port_onus' => [
                    '0_1' => ['slot' => 0, 'port' => 1, 'onus' => [
                        array_merge(['onu_id' => 5, 'slot' => 0, 'port' => 1, 'serial_number' => 'NZ0001', 'online' => true], $extraOnu),
                    ]],
                ],
            ],
        ]);
    }

    public function test_cdata_port_onus_page_exposes_zones_props(): void
    {
        $olt = $this->makeOlt('C-Data FD1208S', 'EPON OLT');
        Zone::create(['name' => 'RINCON']);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('cdata-olt.port-onus', [$olt, 0, 1]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('CDataOlt/PortOnus')
                ->has('zones', 1)
                ->where('zones.0.name', 'RINCON'));
    }

    public function test_hioso_port_onus_page_exposes_zones_props(): void
    {
        $olt = $this->makeOlt('HIOSO HA7304', 'HIOSO OLT HA7304');
        Zone::create(['name' => 'PALMARITO']);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('hioso-olt.port-onus', [$olt, 0, 1]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Hioso/PortOnus')
                ->has('zones', 1)
                ->where('zones.0.name', 'PALMARITO'));
    }

    public function test_operator_can_assign_zone_to_cdata_onu(): void
    {
        $olt = $this->makeOlt('C-Data FD1208S', 'EPON OLT');
        $zone = Zone::create(['name' => 'GUAZUMA']);
        $operator = User::factory()->create();

        $this->actingAs($operator)
            ->post(route('onu-zone.assign'), [
                'snmp_olt_id' => $olt->id, 'slot' => 0, 'port' => 1, 'onu_id' => 5,
                'serial_number' => 'NZ0001', 'zone_id' => $zone->id,
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('onu_zone_links', [
            'snmp_olt_id' => $olt->id, 'slot' => 0, 'port' => 1, 'onu_id' => 5, 'zone_id' => $zone->id,
        ]);
    }

    public function test_operator_can_assign_zone_to_hioso_onu(): void
    {
        $olt = $this->makeOlt('HIOSO HA7304', 'HIOSO OLT HA7304');
        $zone = Zone::create(['name' => 'LOS TOCONES']);
        $operator = User::factory()->create();

        $this->actingAs($operator)
            ->post(route('onu-zone.assign'), [
                'snmp_olt_id' => $olt->id, 'slot' => 0, 'port' => 1, 'onu_id' => 5,
                'serial_number' => 'NZ0001', 'zone_id' => $zone->id,
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('onu_zone_links', [
            'snmp_olt_id' => $olt->id, 'slot' => 0, 'port' => 1, 'onu_id' => 5, 'zone_id' => $zone->id,
        ]);
    }

    public function test_partner_cannot_assign_zone_to_olt_they_do_not_own(): void
    {
        $olt = $this->makeOlt('C-Data FD1208S', 'EPON OLT'); // tak di-assign ke partner manapun
        $zone = Zone::create(['name' => 'EL VALLE']);
        $partner = User::factory()->partner()->create();

        $this->actingAs($partner)
            ->post(route('onu-zone.assign'), [
                'snmp_olt_id' => $olt->id, 'slot' => 0, 'port' => 1, 'onu_id' => 5,
                'serial_number' => 'NZ0001', 'zone_id' => $zone->id,
            ])
            ->assertNotFound();

        $this->assertSame(0, OnuZoneLink::count());
    }
}
