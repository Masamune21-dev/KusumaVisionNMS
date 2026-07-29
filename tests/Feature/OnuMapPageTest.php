<?php

namespace Tests\Feature;

use App\Models\Odp;
use App\Models\OnuMapPin;
use App\Models\OnuOdpLink;
use App\Models\SnmpOlt;
use App\Models\User;
use App\Services\Map\OnuMapPayloadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Halaman Peta ONU (`map.index`). Payload pin/ODP dirakit {@see OnuMapPayloadService}
 * yang dipakai bersama REST API v1 — test ini menjaga bentuk prop halaman web tetap
 * sama setelah perakitnya dipindah ke service.
 */
class OnuMapPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_map_page_renders_pins_and_odps(): void
    {
        $olt = SnmpOlt::create([
            'name' => 'OLT-C320-PATI',
            'vendor' => 'ZTE C320',
            'ip' => '10.40.0.2',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'last_test_result' => [
                'ok' => true,
                'system' => ['sys_descr' => 'ZTE ZXA10 C320'],
                'port_onus' => ['1_1' => ['slot' => 1, 'port' => 1, 'onus' => [
                    ['onu_id' => 5, 'slot' => 1, 'port' => 1, 'serial_number' => 'ZTEG00000005', 'name' => 'PELANGGAN A', 'online' => true, 'interface' => 'gpon-onu_1/1/1:5'],
                ]]],
            ],
        ]);

        $odp = Odp::create([
            'snmp_olt_id' => $olt->id,
            'name' => 'ODP-01',
            'slot' => 1,
            'port' => 1,
            'latitude' => -6.75,
            'longitude' => 111.03,
        ]);

        OnuOdpLink::create([
            'odp_id' => $odp->id,
            'snmp_olt_id' => $olt->id,
            'slot' => 1,
            'port' => 1,
            'onu_id' => 5,
        ]);

        OnuMapPin::create([
            'snmp_olt_id' => $olt->id,
            'slot' => 1,
            'port' => 1,
            'onu_id' => 5,
            'serial_number' => 'ZTEG00000005',
            'latitude' => -6.7,
            'longitude' => 111.0,
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('map.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Map/Index')
                ->has('pins', 1)
                ->where('pins.0.onu_id', 5)
                ->where('pins.0.online', true)
                // Nama tampil jatuh ke nama ONU live saat pin tak punya override.
                ->where('pins.0.customer_name', 'PELANGGAN A')
                ->where('pins.0.port_route', 'smartolt.port-onus')
                ->has('odps', 1)
                ->where('odps.0.name', 'ODP-01')
                ->has('odps.0.onus', 1)
                ->where('odps.0.onus.0.onu_id', 5)
                ->has('olts', 1)
                ->where('default_center.lat', -6.7));
    }
}
