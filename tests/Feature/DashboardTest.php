<?php

namespace Tests\Feature;

use App\Models\AlarmEvent;
use App\Models\PollingEvent;
use App\Models\SnmpOlt;
use App\Models\User;
use App\Services\Dashboard\DashboardStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
                ->where('name', 'PATI-ZTE-C320')
                ->where('model', 'ZTE C320')
                ->where('reachable', true)
                ->where('unit', 1)
                ->where('up', 1)
                ->where('down', 0)
                ->etc()
            )
            ->has('olts', 1)
            ->has('provisioning', 4)
            ->has('recent_alarms', 1)
        );
    }

    public function test_polling_trend_only_counts_completed_buckets(): void
    {
        config(['app.display_timezone' => 'Asia/Jakarta']);
        $this->travelTo(Carbon::parse('2026-10-04 05:32:00', 'Asia/Jakarta'));

        $event = fn (string $at, bool $success = true) => (new PollingEvent)->forceFill([
            'kind' => PollingEvent::KIND_OLT_POLL,
            'success' => $success,
            'created_at' => Carbon::parse($at, 'Asia/Jakarta'),
            'updated_at' => Carbon::parse($at, 'Asia/Jakarta'),
        ])->save();

        $event('2026-10-03 04:59:00');          // sebelum jendela 24 jam
        $event('2026-10-03 05:10:00');          // bucket pertama
        $event('2026-10-04 04:05:00');
        $event('2026-10-04 04:40:00');
        $event('2026-10-04 04:59:00', false);   // bucket terakhir yang sudah lengkap
        $event('2026-10-04 05:10:00');          // jam berjalan: belum lengkap, tak ikut
        $event('2026-10-04 05:31:00', false);

        $trend = app(DashboardStatsService::class)->pollingTrend('24h');

        $this->assertCount(24, $trend['labels']);
        $this->assertSame('05:00', $trend['labels'][0]);
        $this->assertSame('04:00', $trend['labels'][23]);
        $this->assertSame(1, $trend['success'][0]);
        $this->assertSame(2, $trend['success'][23]);
        $this->assertSame(1, $trend['failed'][23]);
        $this->assertSame(['success' => 3, 'failed' => 1], $trend['totals']);

        $week = app(DashboardStatsService::class)->pollingTrend('7d');
        $this->assertCount(28, $week['labels']);
        $this->assertSame('03 Oct 18:00', end($week['labels']));   // 00:00–06:00 masih berjalan
    }
}
