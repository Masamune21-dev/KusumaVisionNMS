<?php

namespace Tests\Feature;

use App\Models\Odp;
use App\Models\OnuMapPin;
use App\Models\OnuOdpLink;
use App\Models\Scopes\PartnerOltScope;
use App\Models\SnmpOlt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Inertia;
use Tests\TestCase;

/**
 * Halaman pengelolaan ODP (`odp.index` / `odp.onus`) + kunci-buka posisi pin.
 *
 * Otorisasi ODP tidak memakai policy: seluruhnya bersandar pada {@see PartnerOltScope}
 * (global scope di model Odp/OnuMapPin/OnuOdpLink + findOrFail pada SnmpOlt), jadi
 * yang diuji di sini adalah efek scope itu lewat HTTP.
 */
class OdpTest extends TestCase
{
    use RefreshDatabase;

    private function makeOlt(string $name, string $ip): SnmpOlt
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
                'port_onus' => ['1_1' => ['slot' => 1, 'port' => 1, 'onus' => [
                    ['onu_id' => 5, 'slot' => 1, 'port' => 1, 'serial_number' => 'ZTEG00000005', 'name' => 'PELANGGAN A', 'online' => true],
                    ['onu_id' => 6, 'slot' => 1, 'port' => 1, 'serial_number' => 'ZTEG00000006', 'name' => 'PELANGGAN B', 'online' => false],
                ]]],
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

    public function test_index_lists_odps_with_onu_count(): void
    {
        $olt = $this->makeOlt('OLT-A', '10.8.0.1');
        $odp = $this->makeOdp($olt);
        OnuOdpLink::create([
            'odp_id' => $odp->id,
            'snmp_olt_id' => $olt->id,
            'slot' => 1,
            'port' => 1,
            'onu_id' => 5,
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('odp.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Odp/Index')
                ->has('odps', 1)
                ->where('odps.0.name', 'ODP-01')
                ->where('odps.0.onu_count', 1)
                ->where('odps.0.olt_name', 'OLT-A')
                ->has('olts', 1));
    }

    public function test_partner_only_sees_odps_of_its_own_olt(): void
    {
        $mine = $this->makeOlt('OLT-MINE', '10.8.0.1');
        $other = $this->makeOlt('OLT-OTHER', '10.8.0.2');
        $this->makeOdp($mine, 'ODP-MINE');
        $foreignOdp = $this->makeOdp($other, 'ODP-OTHER');

        $partner = User::factory()->partner()->create();
        $partner->partnerOlts()->sync([$mine->id]);

        $this->actingAs($partner)
            ->get(route('odp.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('odps', 1)->where('odps.0.name', 'ODP-MINE'));

        // ODP OLT lain 404 lewat route-model binding (kena global scope).
        $this->actingAs($partner)->get(route('odp.onus', $foreignOdp))->assertNotFound();
    }

    public function test_onus_endpoint_splits_connected_and_available(): void
    {
        $olt = $this->makeOlt('OLT-A', '10.8.0.1');
        $odp = $this->makeOdp($olt);
        OnuOdpLink::create([
            'odp_id' => $odp->id,
            'snmp_olt_id' => $olt->id,
            'slot' => 1,
            'port' => 1,
            'onu_id' => 5,
        ]);

        $response = $this->actingAs(User::factory()->admin()->create())
            ->getJson(route('odp.onus', $odp))
            ->assertOk();

        $this->assertSame([5], array_column($response->json('connected'), 'onu_id'));
        $this->assertSame([6], array_column($response->json('available'), 'onu_id'));
    }

    public function test_assign_and_release_onu_from_odp(): void
    {
        $olt = $this->makeOlt('OLT-A', '10.8.0.1');
        $odp = $this->makeOdp($olt);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('onu-odp.assign'), [
            'snmp_olt_id' => $olt->id,
            'slot' => 1,
            'port' => 1,
            'onu_id' => 5,
            'serial_number' => 'ZTEG00000005',
            'odp_id' => $odp->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('onu_odp_links', [
            'odp_id' => $odp->id,
            'snmp_olt_id' => $olt->id,
            'onu_id' => 5,
        ]);

        // odp_id absen = lepas kaitan.
        $this->actingAs($admin)->post(route('onu-odp.assign'), [
            'snmp_olt_id' => $olt->id,
            'slot' => 1,
            'port' => 1,
            'onu_id' => 5,
        ])->assertRedirect();

        $this->assertDatabaseMissing('onu_odp_links', ['snmp_olt_id' => $olt->id, 'onu_id' => 5]);
    }

    public function test_odp_crud_stays_on_the_calling_page(): void
    {
        $olt = $this->makeOlt('OLT-A', '10.8.0.1');
        $admin = User::factory()->admin()->create();

        // store/update/destroy memakai back() supaya bisa dipanggil dari peta MAUPUN halaman ODP.
        $this->actingAs($admin)
            ->from(route('odp.index'))
            ->post(route('map.odps.store'), [
                'snmp_olt_id' => $olt->id,
                'name' => 'ODP-BARU',
                'slot' => 1,
                'port' => 1,
                'latitude' => -6.75,
                'longitude' => 111.03,
            ])
            ->assertRedirect(route('odp.index'));

        $odp = Odp::query()->firstOrFail();
        $this->assertTrue($odp->locked, 'ODP baru harus terkunci secara default.');

        $this->actingAs($admin)
            ->from(route('odp.index'))
            ->put(route('map.odps.update', $odp), ['name' => 'ODP-GANTI'])
            ->assertRedirect(route('odp.index'));

        $this->assertSame('ODP-GANTI', $odp->fresh()->name);
    }

    public function test_unlock_then_drag_then_lock_persists_new_coordinates(): void
    {
        $olt = $this->makeOlt('OLT-A', '10.8.0.1');
        $odp = $this->makeOdp($olt);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put(route('map.odps.update', $odp), ['locked' => false])->assertRedirect();
        $this->assertFalse($odp->fresh()->locked);

        // Geser: hanya koordinat, nama TIDAK ikut dikirim (rule 'sometimes') dan tak boleh hilang.
        $this->actingAs($admin)->put(route('map.odps.update', $odp), [
            'latitude' => -7.1,
            'longitude' => 112.5,
        ])->assertRedirect();

        $moved = $odp->fresh();
        $this->assertSame(-7.1, (float) $moved->latitude);
        $this->assertSame(112.5, (float) $moved->longitude);
        $this->assertSame('ODP-01', $moved->name);
        $this->assertFalse($moved->locked, 'Geser tak boleh mengunci sendiri.');

        $this->actingAs($admin)->put(route('map.odps.update', $odp), ['locked' => true])->assertRedirect();
        $this->assertTrue($odp->fresh()->locked);
    }

    public function test_map_pin_is_locked_by_default_and_can_be_unlocked(): void
    {
        $olt = $this->makeOlt('OLT-A', '10.8.0.1');
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('map.pins.store'), [
            'snmp_olt_id' => $olt->id,
            'slot' => 1,
            'port' => 1,
            'onu_id' => 5,
            'serial_number' => 'ZTEG00000005',
            'latitude' => -6.75,
            'longitude' => 111.03,
        ])->assertRedirect();

        $pin = OnuMapPin::query()->firstOrFail();
        $this->assertTrue($pin->locked);

        $this->actingAs($admin)->put(route('map.pins.update', $pin), ['locked' => false])->assertRedirect();
        $this->assertFalse($pin->fresh()->locked);

        // Geser tak boleh menghapus field pelanggan yang tidak dikirim.
        $pin->forceFill(['customer_name' => 'PELANGGAN A'])->save();
        $this->actingAs($admin)->put(route('map.pins.update', $pin), [
            'latitude' => -7.2,
            'longitude' => 112.6,
        ])->assertRedirect();

        $moved = $pin->fresh();
        $this->assertSame(-7.2, (float) $moved->latitude);
        $this->assertSame('PELANGGAN A', $moved->customer_name);
    }

    /**
     * Peta memuat ~4.500 ONU lintas-OLT hanya untuk dropdown modal "Tambah Pin", jadi prop
     * `onus` sengaja optional: absen saat peta dibuka, baru dikirim ketika frontend memintanya
     * (partial reload `only: ['onus']`). Bila suatu saat dibuat eager lagi, tiap kunjungan —
     * termasuk tiap geser/kunci pin — akan menyeret ulang payload megabyte-an itu.
     */
    public function test_map_defers_onu_list_until_requested(): void
    {
        $olt = $this->makeOlt('OLT-A', '10.8.0.1');
        $this->makeOdp($olt);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('map.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('pins')
                ->has('odps', 1)
                ->missing('onus'));

        // Partial reload menjawab JSON (bukan view), jadi diperiksa langsung pada payload-nya.
        $this->actingAs($admin)
            ->get(route('map.index'), [
                'X-Inertia' => 'true',
                'X-Inertia-Version' => (string) Inertia::getVersion(),
                'X-Inertia-Partial-Component' => 'Map/Index',
                'X-Inertia-Partial-Data' => 'onus',
            ])
            ->assertOk()
            ->assertJsonCount(2, 'props.onus')
            ->assertJsonPath('props.onus.0.onu_id', 5)
            ->assertJsonPath('props.onus.0.serial_number', 'ZTEG00000005')
            ->assertJsonMissingPath('props.odps');
    }

    public function test_onu_monitoring_carries_odp_columns(): void
    {
        $olt = $this->makeOlt('OLT-A', '10.8.0.1');
        $odp = $this->makeOdp($olt);
        OnuOdpLink::create([
            'odp_id' => $odp->id,
            'snmp_olt_id' => $olt->id,
            'slot' => 1,
            'port' => 1,
            'onu_id' => 5,
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('monitoring.onu'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('odps', 1)
                ->where('onus.0.odp_id', $odp->id)
                ->where('onus.0.odp_name', 'ODP-01')
                ->where('onus.1.odp_id', null));
    }
}
