<?php

namespace Tests\Feature;

use App\Models\AlarmEvent;
use App\Models\SnmpOlt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_aggregates_olt_onu_and_alarm_stats(): void
    {
        $user = User::factory()->create();

        $olt = SnmpOlt::create([
            'name' => 'PATI-ZTE-C320',
            'vendor' => 'ZTE C320',
            'ip' => '10.40.0.2',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'last_test_result' => [
                'ok' => true,
                'system' => ['sysDescr' => 'OLT-C320-PATI'],
                'ports' => [
                    ['name' => 'gpon-olt_1/1/1', 'slot' => 1, 'port' => 1, 'oper_status' => 'up'],
                    ['name' => 'gpon-olt_1/1/2', 'slot' => 1, 'port' => 2, 'oper_status' => 'down'],
                ],
                'port_onus' => [
                    '1_1' => [
                        'slot' => 1,
                        'port' => 1,
                        'count' => 5,
                        'onus' => [
                            ['onu_id' => 1, 'online' => true, 'rx_power_dbm' => -20.0],  // sehat
                            ['onu_id' => 2, 'online' => false],                            // offline
                            ['onu_id' => 3, 'online' => true, 'rx_power_dbm' => -26.5],  // warning: RX rendah
                            ['onu_id' => 4, 'online' => true, 'rx_power_dbm' => -8.0],   // warning: RX terlalu kuat
                            ['onu_id' => 5, 'online' => false, 'rx_power_dbm' => -30.0], // offline, RX basi -> bukan warning
                        ],
                    ],
                ],
            ],
        ]);

        AlarmEvent::create([
            'snmp_olt_id' => $olt->id,
            'signature' => 'port:1/2:port_down',
            'type' => 'port_down',
            'severity' => 'critical',
            'status' => 'active',
            'scope' => 'port',
            'message' => 'port down',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('cards.olt.total', 1)
            ->where('cards.olt.online', 1)
            ->where('cards.onu.total', 5)
            ->where('cards.onu.online', 3)
            ->where('cards.onu.offline', 2)
            ->where('cards.onu.warning', 2)
            ->where('cards.alarms.critical', 1)
            ->where('cards.alarms.total', 1)
            ->has('polling_trend.labels')
            ->has('olt_inventory', 1, fn ($row) => $row
                ->where('model', 'ZTE C320')
                ->where('unit', 1)
                ->where('up', 1)
                ->where('down', 0)
            )
            ->has('olts', 1)
            ->has('provisioning', 4)
            ->has('recent_alarms', 1)
        );
    }
}
