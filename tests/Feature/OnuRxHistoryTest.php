<?php

namespace Tests\Feature;

use App\Models\OnuRxSample;
use App\Models\SnmpOlt;
use App\Models\User;
use App\Services\ZteOnuDetailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnuRxHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function makeOlt(): SnmpOlt
    {
        return SnmpOlt::create([
            'name' => 'PATI-ZTE-C320',
            'vendor' => 'ZTE C320',
            'ip' => '10.20.0.5',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
        ]);
    }

    private function sample(SnmpOlt $olt, float $rx, $polledAt, int $onuId = 1): OnuRxSample
    {
        return OnuRxSample::create([
            'snmp_olt_id' => $olt->id,
            'slot' => 1,
            'port' => 1,
            'onu_id' => $onuId,
            'serial_number' => 'ZTEG'.$onuId,
            'rx_power_dbm' => $rx,
            'polled_at' => $polledAt,
        ]);
    }

    public function test_series_for_filters_by_range_and_orders_ascending(): void
    {
        $olt = $this->makeOlt();
        $this->sample($olt, -19.0, now()->subDays(10)); // di luar rentang 7 hari
        $this->sample($olt, -20.5, now()->subDays(2));
        $this->sample($olt, -18.0, now()->subHour());
        $this->sample($olt, -25.0, now()->subHour(), onuId: 2); // ONU lain, harus diabaikan

        $series = OnuRxSample::seriesFor($olt->id, 1, 1, 1, now()->subDays(7));

        $this->assertCount(2, $series);
        $this->assertEqualsWithDelta(-20.5, $series[0]['rx_power_dbm'], 0.001);
        $this->assertEqualsWithDelta(-18.0, $series[1]['rx_power_dbm'], 0.001);
    }

    public function test_prune_command_deletes_samples_older_than_retention(): void
    {
        $olt = $this->makeOlt();
        $old = $this->sample($olt, -19.0, now()->subDays(5));
        $fresh = $this->sample($olt, -18.0, now()->subHours(2));

        $this->artisan('optical:prune-rx', ['--days' => 1])->assertSuccessful();

        $this->assertDatabaseMissing('onu_rx_samples', ['id' => $old->id]);
        $this->assertDatabaseHas('onu_rx_samples', ['id' => $fresh->id]);
    }

    public function test_onu_detail_exposes_rx_history(): void
    {
        $olt = $this->makeOlt();
        $this->sample($olt, -20.0, now()->subDays(2));
        $this->sample($olt, -19.0, now()->subHour());

        // Stub live CLI fetch agar tidak membuka sesi telnet saat test.
        $this->app->instance(ZteOnuDetailService::class, new class extends ZteOnuDetailService
        {
            public function __construct() {}

            public function fetch(SnmpOlt $olt, int $slot, int $port, int $onuId): array
            {
                return [
                    'ok' => false,
                    'groups' => ['identity' => [], 'state' => [], 'optical' => [], 'last_event' => [], 'all' => []],
                    'raw' => '',
                    'error' => null,
                ];
            }
        });

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('smartolt.onu.detail', ['olt' => $olt->id, 'slot' => 1, 'port' => 1, 'onuId' => 1, 'range' => '7d']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('SmartOlt/OnuDetail')
                ->where('range', '7d')
                ->has('rx_history', 2)
            );
    }
}
