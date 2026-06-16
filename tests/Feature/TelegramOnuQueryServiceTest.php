<?php

namespace Tests\Feature;

use App\Models\SnmpOlt;
use App\Services\Telegram\TelegramOnuQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramOnuQueryServiceTest extends TestCase
{
    use RefreshDatabase;

    private function seedData(): void
    {
        SnmpOlt::create([
            'name' => 'OLT-A', 'vendor' => 'ZTE C320', 'ip' => '10.0.0.1',
            'snmp_port' => 161, 'snmp_read_community' => 'public', 'snmp_version' => 'v2c',
            'last_test_result' => ['ok' => true, 'port_onus' => ['1_1' => ['slot' => 1, 'port' => 1, 'onus' => [
                // online + healthy
                ['slot' => 1, 'port' => 1, 'onu_id' => 1, 'online' => true, 'phase_state' => 'Working', 'rx_power_dbm' => -20.0],
                // offline, genuine LOS
                ['slot' => 1, 'port' => 1, 'onu_id' => 2, 'online' => false, 'phase_state' => 'LOS', 'last_down_cause' => 'LOS'],
                // offline, admin-disabled (not a LOS cause)
                ['slot' => 1, 'port' => 1, 'onu_id' => 3, 'online' => false, 'phase_state' => 'Offline', 'last_down_cause' => 'Manual'],
                // online but warning attenuation
                ['slot' => 1, 'port' => 1, 'onu_id' => 4, 'online' => true, 'phase_state' => 'Working', 'rx_power_dbm' => -26.5],
                // online but critical attenuation
                ['slot' => 1, 'port' => 1, 'onu_id' => 5, 'online' => true, 'phase_state' => 'Working', 'rx_power_dbm' => -30.2],
            ]]]],
        ]);
    }

    public function test_los_list_includes_all_offline_los_cause_first(): void
    {
        $this->seedData();
        $list = app(TelegramOnuQueryService::class)->losOnus(0);

        $this->assertCount(2, $list);
        // Genuine LOS sorts before admin-disabled.
        $this->assertSame(2, $list[0]['onu_id']);
        $this->assertTrue($list[0]['los_cause']);
        $this->assertSame(3, $list[1]['onu_id']);
        $this->assertFalse($list[1]['los_cause']);
    }

    public function test_rx_list_is_worst_first(): void
    {
        $this->seedData();
        $list = app(TelegramOnuQueryService::class)->rxOnus(0);

        // Only the two out-of-band online ONUs; critical (-30.2) before warning (-26.5).
        $this->assertCount(2, $list);
        $this->assertSame(5, $list[0]['onu_id']);
        $this->assertSame(4, $list[1]['onu_id']);
    }

    public function test_olt_summary_counts_los_and_rx_alert(): void
    {
        $this->seedData();
        $olt = SnmpOlt::first();
        $summary = app(TelegramOnuQueryService::class)->oltSummary($olt->id);

        $this->assertSame(5, $summary['total']);
        $this->assertSame(3, $summary['online']);
        $this->assertSame(2, $summary['offline']);
        $this->assertSame(1, $summary['los']);       // only the genuine LOS cause
        $this->assertSame(2, $summary['rx_alert']);  // warning + critical
    }
}
