<?php

namespace Tests\Feature;

use App\Models\SnmpOlt;
use App\Models\User;
use App\Support\SmartOltSupport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CDataOltInventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_cdata_olt_index_can_be_rendered(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('cdata-olt.index'))
            ->assertOk();
    }

    public function test_create_and_edit_pages_can_be_rendered(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('cdata-olt.create'))->assertOk();

        $olt = SnmpOlt::create([
            'name' => 'CDATA-EPON-1',
            'vendor' => 'C-Data EPON 17409',
            'ip' => '10.20.0.6',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
        ]);

        $this->actingAs($user)->get(route('cdata-olt.edit', $olt))->assertOk();
    }

    public function test_stored_cdata_olt_appears_only_in_cdata_index_not_smartolt(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('cdata-olt.store'), [
                'name' => 'CDATA-GPON-1',
                'vendor' => 'C-Data GPON 34592',
                'ip' => '10.20.0.5',
                'snmp_port' => 161,
                'snmp_read_community' => 'public',
                'snmp_version' => 'v2c',
                'cli_transport' => 'telnet',
                'cli_port' => 23,
                'polling_enabled' => true,
                'poll_interval_minutes' => 5,
                'rx_poll_interval_minutes' => 5,
            ])
            ->assertRedirect(route('cdata-olt.index'));

        $olt = SnmpOlt::where('ip', '10.20.0.5')->firstOrFail();
        $this->assertSame(SmartOltSupport::DRIVER_CDATA_GPON, SmartOltSupport::driverKey($olt));

        // Muncul di halaman OLT C-Data.
        $this->actingAs($user)
            ->get(route('cdata-olt.index'))
            ->assertInertia(fn ($page) => $page->component('CDataOlt/Index')->has('olts', 1));

        // Tidak bocor ke halaman SmartOLT (ZTE).
        $this->actingAs($user)
            ->get(route('smartolt.index'))
            ->assertInertia(fn ($page) => $page->component('SmartOlt/Index')->has('olts', 0));
    }

    public function test_detail_and_port_onus_pages_render(): void
    {
        $user = User::factory()->create();
        $olt = SnmpOlt::create([
            'name' => 'CDATA-GPON-2',
            'vendor' => 'C-Data GPON 34592',
            'ip' => '10.20.0.7',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
        ]);

        $this->actingAs($user)
            ->get(route('cdata-olt.detail', $olt))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('CDataOlt/Detail'));

        // slot 0 valid untuk GPON C-Data.
        $this->actingAs($user)
            ->get(route('cdata-olt.port-onus', [$olt, 0, 1]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('CDataOlt/PortOnus'));
    }

    public function test_global_search_links_cdata_onu_to_cdata_route(): void
    {
        $user = User::factory()->create();
        SnmpOlt::create([
            'name' => 'CDATA-GPON-3',
            'vendor' => 'C-Data GPON 34592',
            'ip' => '10.20.0.8',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'last_test_result' => [
                'port_onus' => [
                    '0_1' => [
                        'slot' => 0,
                        'port' => 1,
                        'onus' => [
                            ['onu_key' => '0.1.5', 'slot' => 0, 'port' => 1, 'onu_id' => 5, 'serial_number' => 'TESTSN12345', 'name' => 'Pelanggan Uji', 'interface' => 'gpon 0/0/1:5'],
                        ],
                    ],
                ],
            ],
        ]);

        $response = $this->actingAs($user)->getJson(route('dashboard.search', ['q' => 'TESTSN12345']));

        $response->assertOk();
        $url = $response->json('results.0.url');
        $this->assertStringContainsString('cdata-olt', $url);
        $this->assertStringContainsString('ports/0/1', $url); // slot 0 tetap terlink
    }

    public function test_zte_olt_is_not_listed_in_cdata_index(): void
    {
        $user = User::factory()->create();

        SnmpOlt::create([
            'name' => 'PATI-ZTE-C320',
            'vendor' => 'ZTE C320',
            'ip' => '10.10.10.9',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
        ]);

        $this->actingAs($user)
            ->get(route('cdata-olt.index'))
            ->assertInertia(fn ($page) => $page->component('CDataOlt/Index')->has('olts', 0));
    }
}
