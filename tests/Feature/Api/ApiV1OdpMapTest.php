<?php

namespace Tests\Feature\Api;

use App\Models\Odp;
use App\Models\OnuMapPin;
use App\Models\OnuOdpLink;
use App\Models\Scopes\PartnerOltScope;
use App\Models\SnmpOlt;
use App\Models\User;
use App\Support\OdpColors;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Endpoint ODP & Peta untuk aplikasi Android: daftar ODP, ONU di dalam ODP,
 * payload peta (pin ONU + pin ODP), serta kolom ODP pada detail ONU dan
 * opsi ODP pada form registrasi.
 *
 * Otorisasi bersandar pada {@see PartnerOltScope} (global scope model), bukan policy.
 */
class ApiV1OdpMapTest extends TestCase
{
    use RefreshDatabase;

    private function makeOlt(string $name = 'OLT-C320-PATI', string $ip = '10.40.0.2'): SnmpOlt
    {
        return SnmpOlt::create([
            'name' => $name,
            'vendor' => 'ZTE C320',
            'ip' => $ip,
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'cli_transport' => 'telnet',
            'last_test_result' => [
                'ok' => true,
                'system' => ['sys_name' => $name, 'sys_descr' => 'ZTE ZXA10 C320'],
                'port_onus' => [
                    '1_1' => [
                        'slot' => 1, 'port' => 1, 'refreshed_at' => '2026-07-29T10:00:00+07:00',
                        'onus' => [
                            ['onu_id' => 5, 'slot' => 1, 'port' => 1, 'serial_number' => 'ZTEG00000005', 'name' => 'PELANGGAN A', 'online' => true, 'rx_power_dbm' => -21.5, 'rx_power_label' => '-21.50 dBm'],
                            ['onu_id' => 6, 'slot' => 1, 'port' => 1, 'serial_number' => 'ZTEG00000006', 'name' => 'PELANGGAN B', 'online' => false],
                        ],
                    ],
                ],
            ],
        ]);
    }

    private function makeOdp(SnmpOlt $olt, string $name = 'ODP-01', ?int $slot = 1, ?int $port = 1): Odp
    {
        return Odp::create([
            'snmp_olt_id' => $olt->id,
            'name' => $name,
            'slot' => $slot,
            'port' => $port,
            'latitude' => -6.75,
            'longitude' => 111.03,
        ]);
    }

    private function link(Odp $odp, SnmpOlt $olt, int $onuId, ?string $serial = null): OnuOdpLink
    {
        return OnuOdpLink::create([
            'snmp_olt_id' => $olt->id,
            'slot' => 1,
            'port' => 1,
            'onu_id' => $onuId,
            'odp_id' => $odp->id,
            'serial_number' => $serial,
        ]);
    }

    public function test_odp_index_returns_list_with_onu_count(): void
    {
        $olt = $this->makeOlt();
        $odp = $this->makeOdp($olt);
        $this->link($odp, $olt, 5, 'ZTEG00000005');

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/v1/odps')
            ->assertOk()
            ->assertJsonPath('meta.count', 1)
            ->assertJsonPath('data.0.id', $odp->id)
            ->assertJsonPath('data.0.name', 'ODP-01')
            ->assertJsonPath('data.0.olt_name', 'OLT-C320-PATI')
            ->assertJsonPath('data.0.slot', 1)
            ->assertJsonPath('data.0.onu_count', 1);
    }

    public function test_odp_index_ships_the_colour_palette_for_the_app(): void
    {
        $olt = $this->makeOlt();
        $this->makeOdp($olt)->forceFill(['color' => '#8b5cf6'])->save();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/v1/odps')
            ->assertOk()
            ->assertJsonPath('data.0.color', '#8b5cf6')
            ->assertJsonPath('meta.color_default', OdpColors::DEFAULT)
            ->assertJsonCount(count(OdpColors::PALETTE), 'meta.color_palette');
    }

    public function test_app_can_colour_a_whole_pon_port(): void
    {
        $olt = $this->makeOlt();
        $target = $this->makeOdp($olt, 'ODP-1/1-A', 1, 1);
        $sibling = $this->makeOdp($olt, 'ODP-1/1-B', 1, 1);
        $otherPort = $this->makeOdp($olt, 'ODP-1/2', 1, 2);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson("/api/v1/odps/{$target->id}/color", ['color' => '#22d3ee'])
            ->assertOk()
            ->assertJsonPath('data.color', '#22d3ee')
            ->assertJsonPath('data.updated', 2);

        $this->assertSame('#22d3ee', $sibling->fresh()->color);
        $this->assertNull($otherPort->fresh()->color);
    }

    public function test_app_colour_endpoint_rejects_demo_and_foreign_odp(): void
    {
        $mine = $this->makeOlt('OLT-MINE', '10.8.0.1');
        $foreign = $this->makeOlt('OLT-FOREIGN', '10.8.0.2');
        $myOdp = $this->makeOdp($mine, 'ODP-MINE');
        $foreignOdp = $this->makeOdp($foreign, 'ODP-FOREIGN');

        // Akun demo read-only (BlockDemoWrites) — sama seperti aksi tulis ONU.
        $this->actingAs(User::factory()->demo()->create(), 'sanctum')
            ->postJson("/api/v1/odps/{$myOdp->id}/color", ['color' => '#22d3ee'])
            ->assertForbidden();

        $partner = User::factory()->partner()->create();
        $partner->partnerOlts()->sync([$mine->id]);

        $this->actingAs($partner, 'sanctum')
            ->postJson("/api/v1/odps/{$foreignOdp->id}/color", ['color' => '#22d3ee'])
            ->assertNotFound();

        $this->assertNull($myOdp->fresh()->color);
        $this->assertNull($foreignOdp->fresh()->color);
    }

    public function test_map_payload_includes_odp_colour(): void
    {
        $olt = $this->makeOlt();
        $this->makeOdp($olt)->forceFill(['color' => '#ec4899'])->save();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/v1/map')
            ->assertOk()
            ->assertJsonPath('data.odps.0.color', '#ec4899');
    }

    public function test_odp_index_filters_by_olt_and_query(): void
    {
        $first = $this->makeOlt('OLT-A', '10.8.0.1');
        $second = $this->makeOlt('OLT-B', '10.8.0.2');
        $this->makeOdp($first, 'ODP-MELATI');
        $this->makeOdp($second, 'ODP-KENANGA');

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/odps?olt_id={$second->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'ODP-KENANGA');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/odps?q=melati')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'ODP-MELATI');
    }

    public function test_odp_onus_returns_connected_onus_with_live_status(): void
    {
        $olt = $this->makeOlt();
        $odp = $this->makeOdp($olt);
        $this->link($odp, $olt, 5, 'ZTEG00000005');
        $this->link($odp, $olt, 6, 'ZTEG00000006');

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson("/api/v1/odps/{$odp->id}/onus")
            ->assertOk()
            ->assertJsonPath('meta.count', 2)
            ->assertJsonPath('meta.online', 1)
            ->assertJsonPath('data.0.onu_id', 5)
            ->assertJsonPath('data.0.name', 'PELANGGAN A')
            ->assertJsonPath('data.0.online', true)
            // RX ikut supaya daftar ONU dalam ODP bisa menampilkan level sinyal.
            ->assertJsonPath('data.0.rx_power_dbm', -21.5)
            ->assertJsonPath('data.1.online', false);
    }

    public function test_odp_show_returns_single_odp(): void
    {
        $olt = $this->makeOlt();
        $odp = $this->makeOdp($olt);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson("/api/v1/odps/{$odp->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $odp->id)
            ->assertJsonPath('data.onu_count', 0);
    }

    public function test_partner_cannot_see_odps_of_other_olts(): void
    {
        $mine = $this->makeOlt('OLT-MINE', '10.8.0.1');
        $foreign = $this->makeOlt('OLT-FOREIGN', '10.8.0.2');
        $this->makeOdp($mine, 'ODP-MINE');
        $foreignOdp = $this->makeOdp($foreign, 'ODP-FOREIGN');

        $partner = User::factory()->partner()->create();
        $partner->partnerOlts()->sync([$mine->id]);

        $this->actingAs($partner, 'sanctum')
            ->getJson('/api/v1/odps')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'ODP-MINE');

        $this->actingAs($partner, 'sanctum')
            ->getJson("/api/v1/odps/{$foreignOdp->id}/onus")
            ->assertNotFound();
    }

    public function test_map_endpoint_returns_pins_and_odps(): void
    {
        $olt = $this->makeOlt();
        $odp = $this->makeOdp($olt);
        $this->link($odp, $olt, 5, 'ZTEG00000005');

        OnuMapPin::create([
            'snmp_olt_id' => $olt->id,
            'slot' => 1,
            'port' => 1,
            'onu_id' => 5,
            'serial_number' => 'ZTEG00000005',
            'latitude' => -6.7,
            'longitude' => 111.0,
            'customer_name' => 'PELANGGAN A',
        ]);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/v1/map')
            ->assertOk()
            ->assertJsonPath('meta.pins', 1)
            ->assertJsonPath('meta.odps', 1)
            ->assertJsonPath('data.pins.0.onu_id', 5)
            ->assertJsonPath('data.pins.0.online', true)
            ->assertJsonPath('data.pins.0.customer_name', 'PELANGGAN A')
            ->assertJsonPath('data.odps.0.id', $odp->id)
            // ONU terhubung dipakai menggambar garis ODP→ONU; koordinatnya dari pin ONU.
            ->assertJsonPath('data.odps.0.onus.0.onu_id', 5)
            ->assertJsonPath('data.odps.0.onus.0.latitude', -6.7)
            ->assertJsonPath('data.olts.0.id', $olt->id);
    }

    public function test_map_center_falls_back_to_odp_when_no_pins(): void
    {
        $olt = $this->makeOlt();
        $this->makeOdp($olt);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/v1/map')
            ->assertOk()
            ->assertJsonPath('meta.pins', 0)
            ->assertJsonPath('data.default_center.lat', -6.75)
            ->assertJsonPath('data.default_center.lng', 111.03);
    }

    public function test_onu_detail_includes_odp_columns(): void
    {
        $olt = $this->makeOlt();
        $odp = $this->makeOdp($olt);
        $this->link($odp, $olt, 5, 'ZTEG00000005');

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/olts/{$olt->id}/onus/1/1/5")
            ->assertOk()
            ->assertJsonPath('data.odp_id', $odp->id)
            ->assertJsonPath('data.odp_name', 'ODP-01');

        // ONU tanpa kaitan ODP tetap mengembalikan null (bukan kaitan ONU tetangga).
        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/olts/{$olt->id}/onus/1/1/6")
            ->assertOk()
            ->assertJsonPath('data.odp_id', null);
    }

    public function test_register_options_include_odps_of_the_olt(): void
    {
        $olt = $this->makeOlt();
        $odp = $this->makeOdp($olt, 'ODP-REG', 1, 2);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson("/api/v1/olts/{$olt->id}/register/options")
            ->assertOk()
            ->assertJsonPath('data.odps.0.id', $odp->id)
            ->assertJsonPath('data.odps.0.name', 'ODP-REG')
            // slot/port ikut supaya klien bisa menyaring dropdown per PON port.
            ->assertJsonPath('data.odps.0.slot', 1)
            ->assertJsonPath('data.odps.0.port', 2);
    }
}
