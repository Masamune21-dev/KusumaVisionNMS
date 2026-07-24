<?php

namespace Tests\Feature;

use App\Models\OnuZoneLink;
use App\Models\SnmpOlt;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ZoneTest extends TestCase
{
    use RefreshDatabase;

    private function makeOlt(array $lastTestResult = []): SnmpOlt
    {
        return SnmpOlt::create([
            'name' => 'PATI-ZTE-C320',
            'vendor' => 'ZTE C320',
            'ip' => '10.30.0.'.random_int(2, 250),
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'last_test_result' => $lastTestResult,
        ]);
    }

    public function test_zone_name_is_stored_uppercase(): void
    {
        $zone = Zone::create(['name' => 'palmarito']);

        $this->assertSame('PALMARITO', $zone->fresh()->name);
    }

    public function test_admin_can_create_zone(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('zones.store'), ['name' => 'rincon'])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('zones', ['name' => 'RINCON']);
    }

    public function test_zone_name_is_unique_case_insensitively(): void
    {
        Zone::create(['name' => 'RINCON']);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('zones.store'), ['name' => 'rincon'])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, Zone::where('name', 'RINCON')->count());
    }

    public function test_admin_can_rename_zone(): void
    {
        $zone = Zone::create(['name' => 'LOS TOCONES']);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put(route('zones.update', $zone), ['name' => 'los tocones ii'])
            ->assertSessionHas('success');

        $this->assertSame('LOS TOCONES II', $zone->fresh()->name);
    }

    public function test_non_admin_cannot_manage_zones(): void
    {
        $operator = User::factory()->create();

        $this->actingAs($operator)
            ->get(route('zones.index'))
            ->assertForbidden();

        $this->actingAs($operator)
            ->post(route('zones.store'), ['name' => 'GUAZUMA'])
            ->assertForbidden();
    }

    public function test_deleting_zone_without_reassignment_clears_links(): void
    {
        $zone = Zone::create(['name' => 'EL VALLE']);
        $olt = $this->makeOlt();
        $link = OnuZoneLink::create([
            'zone_id' => $zone->id,
            'snmp_olt_id' => $olt->id,
            'slot' => 1,
            'port' => 1,
            'onu_id' => 1,
            'serial_number' => 'ZTEG0001',
        ]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->delete(route('zones.destroy', $zone))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('zones', ['id' => $zone->id]);
        $this->assertNull($link->fresh()->zone_id);
    }

    public function test_deleting_zone_with_reassignment_moves_links(): void
    {
        $source = Zone::create(['name' => 'GUAZUMA']);
        $target = Zone::create(['name' => 'LA COLMENA']);
        $olt = $this->makeOlt();
        $link = OnuZoneLink::create([
            'zone_id' => $source->id,
            'snmp_olt_id' => $olt->id,
            'slot' => 1,
            'port' => 1,
            'onu_id' => 2,
            'serial_number' => 'ZTEG0002',
        ]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->delete(route('zones.destroy', $source), ['reassign_to' => $target->id])
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('zones', ['id' => $source->id]);
        $this->assertSame($target->id, $link->fresh()->zone_id);
    }

    public function test_operator_can_assign_zone_to_onu(): void
    {
        $zone = Zone::create(['name' => 'BARRIO LA PLANTA']);
        $olt = $this->makeOlt();
        $operator = User::factory()->create();

        $this->actingAs($operator)
            ->post(route('onu-zone.assign'), [
                'snmp_olt_id' => $olt->id,
                'slot' => 1,
                'port' => 2,
                'onu_id' => 3,
                'serial_number' => 'ZTEG0003',
                'zone_id' => $zone->id,
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('onu_zone_links', [
            'snmp_olt_id' => $olt->id,
            'slot' => 1,
            'port' => 2,
            'onu_id' => 3,
            'zone_id' => $zone->id,
        ]);
    }

    public function test_assigning_null_zone_clears_existing_link(): void
    {
        $zone = Zone::create(['name' => 'LLANADA AL MEDIO']);
        $olt = $this->makeOlt();
        OnuZoneLink::create([
            'zone_id' => $zone->id,
            'snmp_olt_id' => $olt->id,
            'slot' => 1,
            'port' => 1,
            'onu_id' => 4,
            'serial_number' => 'ZTEG0004',
        ]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('onu-zone.assign'), [
                'snmp_olt_id' => $olt->id,
                'slot' => 1,
                'port' => 1,
                'onu_id' => 4,
                'zone_id' => null,
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('onu_zone_links', [
            'snmp_olt_id' => $olt->id,
            'slot' => 1,
            'port' => 1,
            'onu_id' => 4,
        ]);
    }

    public function test_onu_monitoring_exposes_zone_name_and_options(): void
    {
        $zone = Zone::create(['name' => 'SEIBATABLODUA']);
        $olt = $this->makeOlt([
            'ok' => true,
            'port_onus' => [
                '1_1' => [
                    'refreshed_at' => now()->toIso8601String(),
                    'onus' => [
                        ['slot' => 1, 'port' => 1, 'onu_id' => 5, 'serial_number' => 'ZTEG0005', 'online' => true],
                    ],
                ],
            ],
        ]);
        OnuZoneLink::create([
            'zone_id' => $zone->id,
            'snmp_olt_id' => $olt->id,
            'slot' => 1,
            'port' => 1,
            'onu_id' => 5,
            'serial_number' => 'ZTEG0005',
        ]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('monitoring.onu'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('SmartOlt/OnuMonitor')
                ->has('zones', 1)
                ->where('zones.0.name', 'SEIBATABLODUA')
                ->where('onus', fn ($onus) => $onus->firstWhere('serial_number', 'ZTEG0005')['zone_name'] === 'SEIBATABLODUA')
            );
    }
}
