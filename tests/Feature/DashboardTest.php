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
                'ports' => [
                    ['name' => 'gpon-olt_1/1/1', 'slot' => 1, 'port' => 1, 'oper_status' => 'up'],
                    ['name' => 'gpon-olt_1/1/2', 'slot' => 1, 'port' => 2, 'oper_status' => 'down'],
                ],
                'port_onus' => [
                    '1_1' => [
                        'count' => 2,
                        'onus' => [
                            ['onu_id' => 1, 'online' => true],
                            ['onu_id' => 2, 'online' => false],
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
            ->where('stats.olts_total', 1)
            ->where('stats.onu_total', 2)
            ->where('stats.onu_online', 1)
            ->where('stats.onu_offline', 1)
            ->where('stats.ports_up', 1)
            ->where('stats.ports_down', 1)
            ->where('alarms.critical', 1)
        );
    }
}
