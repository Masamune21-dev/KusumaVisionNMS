<?php

namespace Tests\Feature;

use App\Models\SnmpOlt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    private function seedOlt(): SnmpOlt
    {
        return SnmpOlt::create([
            'name' => 'PATI-ZTE-C320',
            'vendor' => 'ZTE C320',
            'ip' => '10.40.0.2',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'last_test_result' => [
                'ok' => true,
                'system' => ['sysDescr' => 'OLT-C320-PATI'],
                'ports' => [['name' => 'gpon-olt_1/1/1', 'slot' => 1, 'port' => 1, 'oper_status' => 'up']],
                'port_onus' => [
                    '1_1' => [
                        'slot' => 1, 'port' => 1, 'count' => 2,
                        'onus' => [
                            ['onu_id' => 1, 'online' => true, 'serial_number' => 'ZTEG0001', 'rx_power_dbm' => -22.0],
                            ['onu_id' => 2, 'online' => true, 'serial_number' => 'ZTEG0002', 'rx_power_dbm' => -29.5],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function test_report_index_renders(): void
    {
        $user = User::factory()->create();
        $this->seedOlt();

        $this->actingAs($user)
            ->get(route('reports.index', ['type' => 'onu']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Reports/Index')
                ->where('report.type', 'onu')
                ->has('report.rows', 2)
            );
    }

    public function test_rx_report_flags_critical(): void
    {
        $user = User::factory()->create();
        $this->seedOlt();

        $this->actingAs($user)
            ->get(route('reports.index', ['type' => 'rx']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('report.type', 'rx')
                ->has('report.rows', 2)
                ->where('report.summary.2.value', 1) // 1 critical (< -28 dBm)
            );
    }

    public function test_pon_port_filter_limits_rows(): void
    {
        $user = User::factory()->create();
        $olt = $this->seedOlt();

        // Port 1_1 punya 2 ONU.
        $this->actingAs($user)
            ->get(route('reports.index', ['type' => 'onu', 'olt_id' => $olt->id, 'pon_port' => '1_1']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('report.rows', 2));

        // Port yang tidak ada -> 0 baris.
        $this->actingAs($user)
            ->get(route('reports.index', ['type' => 'onu', 'olt_id' => $olt->id, 'pon_port' => '9_9']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('report.rows', 0));
    }

    public function test_csv_export_returns_csv(): void
    {
        $user = User::factory()->create();
        $this->seedOlt();

        $response = $this->actingAs($user)->get(route('reports.export.csv', ['type' => 'onu']));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
    }

    public function test_pdf_export_returns_pdf(): void
    {
        $user = User::factory()->create();
        $this->seedOlt();

        $response = $this->actingAs($user)->get(route('reports.export.pdf', ['type' => 'olt']));

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
    }
}
