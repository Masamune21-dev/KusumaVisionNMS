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

    public function test_onu_report_flags_rx_critical(): void
    {
        $user = User::factory()->create();
        $this->seedOlt();

        // Inventaris ONU kini menyatu dengan RX Power dalam satu laporan.
        $this->actingAs($user)
            ->get(route('reports.index', ['type' => 'onu']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('report.type', 'onu')
                ->has('report.rows', 2)
                ->where('report.summary.4.value', 1) // 1 RX critical (< -28 dBm)
            );
    }

    public function test_onu_report_filters_by_redaman(): void
    {
        $user = User::factory()->create();
        $this->seedOlt();

        // Filter redaman critical -> hanya ONU dengan RX < -28 dBm yang tampil.
        $this->actingAs($user)
            ->get(route('reports.index', ['type' => 'onu', 'rx_status' => 'critical']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('report.rows', 1)
                ->where('report.rows.0.serial_number', 'ZTEG0002')
                ->where('report.summary.4.value', 1) // ringkasan tetap penuh dataset
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

    public function test_onu_report_shows_mac_for_epon_olt(): void
    {
        $user = User::factory()->create();

        // EPON (HiOSO/C-Data) tak punya serial sungguhan — identitas ONU adalah MAC.
        // C-Data EPON menyimpan serial_number = null; HiOSO menyimpan serial_number = MAC.
        SnmpOlt::create([
            'name' => 'PATI-HIOSO-EPON',
            'vendor' => 'HiOSO HA7304',
            'ip' => '10.40.0.9',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'last_test_result' => [
                'ok' => true,
                'port_onus' => [
                    '1_1' => [
                        'slot' => 1, 'port' => 1, 'count' => 2,
                        'onus' => [
                            // Gaya HiOSO: serial_number = MAC.
                            ['onu_id' => 1, 'online' => true, 'serial_number' => 'AA:BB:CC:00:00:01', 'mac' => 'AA:BB:CC:00:00:01'],
                            // Gaya C-Data EPON: serial_number null, MAC di kolom mac.
                            ['onu_id' => 2, 'online' => true, 'serial_number' => null, 'mac' => 'AA:BB:CC:00:00:02'],
                        ],
                    ],
                ],
            ],
        ]);

        $this->actingAs($user)
            ->get(route('reports.index', ['type' => 'onu']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('report.columns.3.label', 'Serial Number / MAC')
                ->where('report.rows.0.serial_number', 'AA:BB:CC:00:00:01')
                ->where('report.rows.1.serial_number', 'AA:BB:CC:00:00:02')
            );
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
