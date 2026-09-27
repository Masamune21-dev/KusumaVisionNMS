<?php

namespace Tests\Feature;

use App\Models\SnmpOlt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Halaman port GPON ZTE memakai halaman bersama `SmartOlt/PonPorts` (sama dengan C-Data/HiOSO).
 */
class SmartOltGponPortsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_zte_gpon_ports_use_the_shared_pon_ports_page(): void
    {
        $olt = SnmpOlt::create([
            'name' => 'OLT-UJI-C300',
            'vendor' => 'ZTE C300',
            'ip' => '10.30.0.40',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'last_test_result' => [
                'ok' => true,
                'ports' => [['slot' => 1, 'port' => 1, 'name' => 'gpon-olt_1/1/1', 'oper_status' => 'up', 'if_index' => 268501248]],
                'port_onus' => ['1_1' => ['onus' => [
                    ['onu_id' => 1, 'name' => 'uji0800', 'serial_number' => 'ZTEGC0800001', 'interface' => 'gpon-onu_1/1/1:1', 'online' => true],
                    ['onu_id' => 2, 'name' => 'uji0801', 'serial_number' => 'ZTEGC0800002', 'interface' => 'gpon-onu_1/1/1:2', 'online' => false],
                ]]],
            ],
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('smartolt.gpon-ports', $olt))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('SmartOlt/PonPorts')
                ->where('route_prefix', 'smartolt')
                ->where('ports.0.onu_count', 2)
                ->where('ports.0.online_onu_count', 1));
    }
}
