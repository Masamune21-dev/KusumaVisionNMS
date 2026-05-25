<?php

namespace Tests\Feature;

use App\Models\SnmpOlt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmartOltInventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_smartolt_index_can_be_rendered(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('smartolt.index'));

        $response->assertOk();
    }

    public function test_smartolt_detail_can_be_rendered(): void
    {
        $user = User::factory()->create();
        $olt = SnmpOlt::create([
            'name' => 'PATI-ZTE-C320',
            'vendor' => 'ZTE C320',
            'ip' => '10.10.10.2',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'last_test_result' => [
                'ok' => true,
                'driver' => 'zte',
                'latency_ms' => 42,
                'system' => [
                    'sys_name' => 'PAT-C320',
                    'sys_descr' => 'ZXA10 C320',
                    'sys_object_id' => '1.3.6.1.4.1.3902',
                    'sys_uptime' => '123',
                ],
                'ports' => [
                    [
                        'if_index' => 268501760,
                        'name' => 'gpon-olt_1/1/1',
                        'slot' => 1,
                        'port' => 1,
                        'oper_status_code' => 1,
                        'oper_status' => 'up',
                    ],
                ],
                'error' => null,
            ],
            'last_tested_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('smartolt.detail', $olt));

        $response->assertOk();
    }

    public function test_smartolt_port_onus_page_can_be_rendered(): void
    {
        $user = User::factory()->create();
        $olt = SnmpOlt::create([
            'name' => 'PATI-ZTE-C320',
            'vendor' => 'ZTE C320',
            'ip' => '10.10.10.4',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'last_test_result' => [
                'ok' => true,
                'driver' => 'zte',
                'system' => [],
                'ports' => [
                    [
                        'if_index' => 268501760,
                        'name' => 'gpon-olt_1/1/1',
                        'slot' => 1,
                        'port' => 1,
                        'oper_status_code' => 1,
                        'oper_status' => 'up',
                    ],
                ],
                'port_onus' => [
                    '1_1' => [
                        'ok' => true,
                        'slot' => 1,
                        'port' => 1,
                        'if_index' => 268501760,
                        'port_row' => null,
                        'count' => 1,
                        'latency_ms' => 88,
                        'refreshed_at' => now()->toIso8601String(),
                        'error' => null,
                        'onus' => [
                            [
                                'if_index' => 268501760,
                                'onu_id' => 1,
                                'slot' => 1,
                                'port' => 1,
                                'interface' => 'gpon-onu_1/1/1:1',
                                'type_name' => 'F660V7.0',
                                'name' => 'Customer A',
                                'description' => '1$$Customer A$$',
                                'serial_number' => 'ZTEG12345678',
                                'admin_state_code' => 1,
                                'admin_state' => 'active',
                                'phase_state_code' => 3,
                                'phase_state' => 'Working',
                                'online' => true,
                                'last_down_cause_code' => 0,
                                'last_down_cause' => 'Normal',
                            ],
                        ],
                    ],
                ],
                'error' => null,
            ],
        ]);

        $response = $this->actingAs($user)->get(route('smartolt.port-onus', [$olt, 1, 1]));

        $response->assertOk();
    }

    public function test_authenticated_user_can_store_an_olt(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('smartolt.store'), [
            'name' => 'PATI-ZTE-C320',
            'vendor' => 'ZTE C320',
            'ip' => '10.10.10.2',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_write_community' => 'private',
            'snmp_version' => 'v2c',
            'cli_transport' => 'telnet',
            'cli_port' => 23,
            'cli_username' => 'operator',
            'cli_password' => 'secret',
        ]);

        $response->assertRedirect(route('smartolt.index'));

        $olt = SnmpOlt::query()->firstOrFail();

        $this->assertSame('PATI-ZTE-C320', $olt->name);
        $this->assertSame('public', $olt->snmp_read_community);
        $this->assertSame('secret', $olt->cli_password);
    }

    public function test_empty_secret_fields_on_update_preserve_existing_values(): void
    {
        $user = User::factory()->create();
        $olt = SnmpOlt::create([
            'name' => 'OLT Lama',
            'vendor' => 'ZTE C300',
            'ip' => '10.10.10.3',
            'snmp_port' => 161,
            'snmp_read_community' => 'old-public',
            'snmp_write_community' => 'old-private',
            'snmp_version' => 'v2c',
            'cli_transport' => 'ssh',
            'cli_port' => 22,
            'cli_username' => 'admin',
            'cli_password' => 'old-password',
        ]);

        $response = $this->actingAs($user)->put(route('smartolt.update', $olt), [
            'name' => 'OLT Baru',
            'vendor' => 'ZTE C320',
            'ip' => '10.10.10.3',
            'snmp_port' => 161,
            'snmp_read_community' => '',
            'snmp_write_community' => '',
            'snmp_version' => 'v2c',
            'cli_transport' => 'ssh',
            'cli_port' => 22,
            'cli_username' => 'admin2',
            'cli_password' => '',
        ]);

        $response->assertRedirect(route('smartolt.index'));

        $olt->refresh();

        $this->assertSame('OLT Baru', $olt->name);
        $this->assertSame('old-public', $olt->snmp_read_community);
        $this->assertSame('old-private', $olt->snmp_write_community);
        $this->assertSame('old-password', $olt->cli_password);
    }
}
