<?php

namespace Tests\Feature;

use App\Models\SmartOltOnuRegistration;
use App\Models\SmartOltProfile;
use App\Models\SnmpOlt;
use App\Models\User;
use App\Services\Snmp\OltSnmpClient;
use App\Services\ZteCliProvisioningExecutor;
use App\Services\ZteOnuRxPowerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_alarms_toggle_flips_flag_per_olt(): void
    {
        $user = User::factory()->create();
        $olt = SnmpOlt::create([
            'name' => 'PATI-ZTE-C320',
            'vendor' => 'ZTE C320',
            'ip' => '10.30.0.9',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
        ]);

        $this->assertTrue($olt->alarms_enabled);

        $this->actingAs($user)->post(route('smartolt.alarms.toggle', $olt))->assertRedirect();
        $this->assertFalse($olt->fresh()->alarms_enabled);

        $this->actingAs($user)->post(route('smartolt.alarms.toggle', $olt))->assertRedirect();
        $this->assertTrue($olt->fresh()->alarms_enabled);
    }

    public function test_alarms_toggle_partner_flips_own_pivot_not_olt_flag(): void
    {
        $partner = User::factory()->partner()->create();
        $olt = SnmpOlt::create([
            'name' => 'PATI-ZTE-C320',
            'vendor' => 'ZTE C320',
            'ip' => '10.30.0.10',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
        ]);
        $partner->partnerOlts()->sync([$olt->id]);

        $pivot = fn () => (bool) DB::table('olt_user')
            ->where('user_id', $partner->id)->where('snmp_olt_id', $olt->id)->value('alarms_enabled');

        $this->assertTrue($pivot());

        $this->actingAs($partner)->post(route('smartolt.alarms.toggle', $olt))->assertRedirect();

        // Saklar partner (pivot) mati; saklar OLT (admin) TIDAK ikut berubah.
        $this->assertFalse($pivot());
        $this->assertTrue((bool) $olt->fresh()->alarms_enabled);
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
                                'rx_power_dbm' => -18.762,
                                'rx_power_label' => '-18.762 dBm',
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

    public function test_onu_rx_power_output_can_be_parsed(): void
    {
        $service = new ZteOnuRxPowerService(new ZteCliProvisioningExecutor);

        $powers = $service->parse(<<<'OUT'
gpon-onu_1/2/2:1    -18.762(dbm)
--More--
gpon-onu_1/2/2:3    -22.100(dbm)
OUT);

        $this->assertSame(-18.762, $powers[1]['rx_power_dbm']);
        $this->assertSame('-22.100 dBm', $powers[3]['rx_power_label']);
    }

    public function test_onu_rx_power_can_be_read_from_snmp_onu_rx_table(): void
    {
        $olt = SnmpOlt::create([
            'name' => 'PATI-ZTE-C320',
            'vendor' => 'ZTE C320',
            'ip' => '10.10.10.40',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
        ]);
        $client = new class extends OltSnmpClient
        {
            public function walk(SnmpOlt $olt, string $oid): array
            {
                return [
                    "{$oid}.268501760.1.1" => 'INTEGER: 5635',
                    "{$oid}.268501760.2.1" => '-28930',
                    "{$oid}.268501760.3.1" => '-280',
                    "{$oid}.268501760.4.1" => '-32768',
                ];
            }
        };

        $powers = $client->onuRxPowers($olt);

        $this->assertSame(-18.73, $powers['268501760.1']['rx_power_dbm']);
        $this->assertSame('snmp_onu_rx', $powers['268501760.1']['rx_power_source']);
        $this->assertSame(1, $powers['268501760.1']['rx_power_port']);
        $this->assertSame('-28.930 dBm', $powers['268501760.2']['rx_power_label']);
        $this->assertSame(-28.0, $powers['268501760.3']['rx_power_dbm']);
        $this->assertArrayNotHasKey('268501760.4', $powers);
    }

    public function test_unconfigured_onu_page_can_be_rendered(): void
    {
        $user = User::factory()->create();
        $olt = SnmpOlt::create([
            'name' => 'PATI-ZTE-C320',
            'vendor' => 'ZTE C320',
            'ip' => '10.10.10.5',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'last_test_result' => [
                'unconfigured_onus' => [
                    'ok' => true,
                    'count' => 1,
                    'refreshed_at' => now()->toIso8601String(),
                    'onus' => [
                        [
                            'serial_number' => 'ZTEG12345678',
                            'slot' => 2,
                            'port' => 1,
                            'oid_index' => '268566784.1',
                            'source_oid' => '1.3.6.1.4.1.3902.1012.3.13.3.1.2',
                        ],
                    ],
                ],
            ],
        ]);

        $response = $this->actingAs($user)->get(route('smartolt.unconfigured', $olt));

        $response->assertOk();
    }

    public function test_register_onu_form_can_be_rendered(): void
    {
        $user = User::factory()->create();
        $olt = SnmpOlt::create([
            'name' => 'PATI-ZTE-C320',
            'vendor' => 'ZTE C320',
            'ip' => '10.10.10.6',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
        ]);

        $response = $this->actingAs($user)->get(route('smartolt.register', [
            'olt' => $olt,
            'sn' => 'ZTEG12345678',
            'slot' => 2,
            'port' => 1,
        ]));

        $response->assertOk();
    }

    public function test_register_onu_form_suggests_next_free_onu_id_from_cached_port_snapshot(): void
    {
        $user = User::factory()->create();
        $olt = SnmpOlt::create([
            'name' => 'PATI-ZTE-C320',
            'vendor' => 'ZTE C320',
            'ip' => '10.10.10.13',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'cli_transport' => 'telnet',
            'cli_port' => 23,
            'cli_username' => 'admin',
            'cli_password' => 'secret',
            'last_test_result' => [
                'port_onus' => [
                    '2_1' => [
                        'onus' => [
                            ['onu_id' => 1],
                            ['onu_id' => 2],
                            ['onu_id' => 4],
                        ],
                    ],
                    '2_2' => [
                        'onus' => [
                            ['onu_id' => 1],
                        ],
                    ],
                ],
            ],
        ]);

        $response = $this->actingAs($user)->get(route('smartolt.register', [
            'olt' => $olt,
            'sn' => 'ZTEG12345678',
            'slot' => 2,
            'port' => 1,
        ]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('defaults.onu_id', 3));
    }

    public function test_register_onu_form_uses_unconfigured_suggested_onu_id_when_cli_is_unavailable(): void
    {
        $user = User::factory()->create();
        $olt = SnmpOlt::create([
            'name' => 'PATI-ZTE-C320',
            'vendor' => 'ZTE C320',
            'ip' => '10.10.10.14',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
        ]);

        $response = $this->actingAs($user)->get(route('smartolt.register', [
            'olt' => $olt,
            'sn' => 'ZTEG12345678',
            'slot' => 2,
            'port' => 1,
            'suggested_onu_id' => 7,
        ]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('defaults.onu_id', 7));
    }

    public function test_profile_management_page_can_create_profile(): void
    {
        $user = User::factory()->create();
        $olt = SnmpOlt::create([
            'name' => 'PATI-ZTE-C320',
            'vendor' => 'ZTE C320',
            'ip' => '10.10.10.10',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
        ]);

        $response = $this->actingAs($user)->get(route('smartolt.profiles.index', $olt));

        $response->assertOk();

        $response = $this->actingAs($user)->post(route('smartolt.profiles.store', $olt), [
            'profile_type' => 'vlan',
            'name' => 'BUSINESS',
            'vlan' => 200,
            'params' => [
                'tag_mode' => 'tag',
                'pri' => 0,
            ],
            'notes' => 'Business customer VLAN',
            'is_active' => true,
            'execute_cli' => false,
        ]);

        $response->assertRedirect(route('smartolt.profiles.index', $olt));

        $this->assertDatabaseHas('smartolt_profiles', [
            'snmp_olt_id' => $olt->id,
            'profile_type' => 'vlan',
            'name' => 'BUSINESS',
            'vlan' => 200,
            'is_active' => true,
        ]);
    }

    public function test_profiles_can_be_synced_from_olt_cli_output(): void
    {
        $user = User::factory()->create();
        $olt = SnmpOlt::create([
            'name' => 'PATI-ZTE-C320',
            'vendor' => 'ZTE C320',
            'ip' => '10.10.10.11',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'cli_transport' => 'telnet',
            'cli_port' => 23,
            'cli_username' => 'admin',
            'cli_password' => 'secret',
        ]);

        $this->app->instance(ZteCliProvisioningExecutor::class, new class extends ZteCliProvisioningExecutor
        {
            public function execute(SnmpOlt $olt, string $script, bool $largeOutput = false): array
            {
                return [
                    'ok' => true,
                    'error' => null,
                    'output' => <<<'OUT'
Profile name :SERVER
 Type           FBW(kbps)   ABW(kbps)   MBW(kbps)
 4              0           0           1000000
Profile name:  PPPOEPATI
Tag mode:      tag
CVLAN:         22
CVLAN priority:0
Profile name:         ST
Gateway:              192.0.2.1
Primary DNS:          8.8.8.8
ONU type name:          ALL-ONT
PON type:               gpon
Description:            4ETH,4WIFI,2POTS
OUT,
                ];
            }
        });

        $response = $this->actingAs($user)->post(route('smartolt.profiles.sync', $olt));

        $response->assertRedirect(route('smartolt.profiles.index', $olt));

        $this->assertDatabaseHas('smartolt_profiles', [
            'snmp_olt_id' => $olt->id,
            'profile_type' => 'tcont',
            'name' => 'SERVER',
        ]);
        $this->assertDatabaseHas('smartolt_profiles', [
            'snmp_olt_id' => $olt->id,
            'profile_type' => 'vlan',
            'name' => 'PPPOEPATI',
            'vlan' => 22,
        ]);
        $this->assertDatabaseHas('smartolt_profiles', [
            'snmp_olt_id' => $olt->id,
            'profile_type' => 'ip',
            'name' => 'ST',
        ]);
        $this->assertDatabaseHas('smartolt_profiles', [
            'snmp_olt_id' => $olt->id,
            'profile_type' => 'onu_type',
            'name' => 'ALL-ONT',
        ]);
    }

    public function test_provisioning_script_can_be_generated(): void
    {
        $user = User::factory()->create();
        $olt = SnmpOlt::create([
            'name' => 'PATI-ZTE-C320',
            'vendor' => 'ZTE C320',
            'ip' => '10.10.10.7',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
        ]);

        $response = $this->actingAs($user)->post(route('smartolt.register.store', $olt), [
            'serial_number' => 'ZTEG12345678',
            'slot' => 2,
            'port' => 1,
            'onu_id' => 3,
            'customer_name' => 'Customer A',
            'onu_type' => 'ALL-ONT',
            'tcont_profile' => 'SERVER',
            'vlan' => 100,
            'service_name' => 'ServiceName',
            'wan_mode' => 'pppoe',
            'pppoe_username' => 'customer_a',
            'pppoe_password' => 'secret123',
        ]);

        $response->assertRedirect(route('smartolt.registrations', $olt));

        $this->assertDatabaseHas('smartolt_onu_registrations', [
            'snmp_olt_id' => $olt->id,
            'serial_number' => 'ZTEG12345678',
            'pon_port' => 'gpon-onu_1/2/1:3',
            'status' => 'generated',
        ]);
    }

    public function test_static_provisioning_uses_profile_dropdown_values_and_prefix_subnet(): void
    {
        $user = User::factory()->create();
        $olt = SnmpOlt::create([
            'name' => 'PATI-ZTE-C320',
            'vendor' => 'ZTE C320',
            'ip' => '10.10.10.8',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
        ]);

        SmartOltProfile::create([
            'snmp_olt_id' => $olt->id,
            'profile_type' => 'vlan',
            'name' => 'STATICBUS',
            'vlan' => 321,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->post(route('smartolt.register.store', $olt), [
            'serial_number' => 'ZTEG87654321',
            'slot' => 2,
            'port' => 2,
            'onu_id' => 1,
            'customer_name' => 'Static Customer',
            'onu_type' => 'ALL-ONT',
            'tcont_profile' => 'SERVER',
            'vlan' => 999,
            'vlan_profile' => 'STATICBUS',
            'service_name' => 'ManualName',
            'wan_mode' => 'static',
            'ip_profile' => 'INTERNET',
            'static_ip' => '192.0.2.10',
            'static_netmask' => '24',
        ]);

        $response->assertRedirect(route('smartolt.registrations', $olt));

        $registration = SmartOltOnuRegistration::query()
            ->where('serial_number', 'ZTEG87654321')
            ->firstOrFail();

        // VLAN tetap ikut profile (321), tetapi service_name independen — pakai input user.
        $this->assertSame(321, $registration->vlan);
        $this->assertStringContainsString('service ManualName gemport 1 cos 0 vlan 321', $registration->cli_script);
        $this->assertStringContainsString('ip-profile INTERNET ip-address 192.0.2.10 mask 24', $registration->cli_script);
        $this->assertStringNotContainsString('255.255.255.0', $registration->cli_script);
    }

    public function test_provisioning_script_can_include_tr069_and_remote_ont(): void
    {
        $user = User::factory()->create();
        $olt = SnmpOlt::create([
            'name' => 'PATI-ZTE-C320',
            'vendor' => 'ZTE C320',
            'ip' => '10.10.10.12',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
        ]);

        $response = $this->actingAs($user)->post(route('smartolt.register.store', $olt), [
            'serial_number' => 'ZTEG87654322',
            'slot' => 2,
            'port' => 2,
            'onu_id' => 2,
            'customer_name' => 'TR069 Customer',
            'onu_type' => 'ALL-ONT',
            'tcont_profile' => 'SERVER',
            'vlan' => 100,
            'service_name' => 'ServiceName',
            'wan_mode' => 'dhcp',
            'tr069_enabled' => true,
            'acs_url' => 'http://acs.example.net:7547',
            'acs_username' => 'acsuser',
            'acs_password' => 'acspass123!',
            'remote_ont_enabled' => true,
            'remote_ont_id' => 1,
            'remote_ont_mode' => 'forward',
            'remote_ont_protocol' => 'web',
        ]);

        $response->assertRedirect(route('smartolt.registrations', $olt));

        $registration = SmartOltOnuRegistration::query()
            ->where('serial_number', 'ZTEG87654322')
            ->firstOrFail();

        $this->assertTrue($registration->tr069_enabled);
        $this->assertTrue($registration->remote_ont_enabled);
        $this->assertStringContainsString('tr069-mgmt 1 state unlock', $registration->cli_script);
        $this->assertStringContainsString('tr069-mgmt 1 acs http://acs.example.net:7547 validate basic username acsuser password acspass123!', $registration->cli_script);
        $this->assertStringContainsString('security-mgmt 1 state enable mode forward protocol web', $registration->cli_script);
    }

    public function test_generated_registration_can_be_marked_executed(): void
    {
        $user = User::factory()->create();
        $olt = SnmpOlt::create([
            'name' => 'PATI-ZTE-C320',
            'vendor' => 'ZTE C320',
            'ip' => '10.10.10.9',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'cli_transport' => 'telnet',
            'cli_port' => 23,
            'cli_username' => 'admin',
            'cli_password' => 'secret',
        ]);
        $registration = SmartOltOnuRegistration::create([
            'snmp_olt_id' => $olt->id,
            'serial_number' => 'ZTEG12345679',
            'slot' => 2,
            'port' => 1,
            'onu_id' => 4,
            'pon_port' => 'gpon-onu_1/2/1:4',
            'customer_name' => 'Customer B',
            'onu_type' => 'ALL-ONT',
            'tcont_profile' => 'SERVER',
            'vlan' => 100,
            'service_name' => 'ServiceName',
            'wan_mode' => 'dhcp',
            'cli_script' => 'conf t',
            'status' => 'generated',
            'created_by' => $user->id,
        ]);

        $this->app->instance(ZteCliProvisioningExecutor::class, new class extends ZteCliProvisioningExecutor
        {
            public function execute(SnmpOlt $olt, string $script, bool $largeOutput = false): array
            {
                return [
                    'ok' => true,
                    'output' => 'Provisioning OK',
                    'error' => null,
                ];
            }
        });

        $response = $this->actingAs($user)->post(route('smartolt.registrations.execute', [$olt, $registration]));

        $response->assertRedirect(route('smartolt.registrations', $olt));

        $registration->refresh();

        $this->assertSame('executed', $registration->status);
        $this->assertSame('Provisioning OK', $registration->execution_output);
        $this->assertSame($user->id, $registration->executed_by);
        $this->assertNotNull($registration->executed_at);
    }

    public function test_onu_reboot_sends_cli_script_via_confirmable_executor(): void
    {
        $user = User::factory()->create();
        $olt = SnmpOlt::create([
            'name' => 'PATI-ZTE-C320',
            'vendor' => 'ZTE C320',
            'ip' => '10.10.10.20',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'cli_transport' => 'telnet',
            'cli_port' => 23,
            'cli_username' => 'admin',
            'cli_password' => 'secret',
        ]);

        $executor = new class extends ZteCliProvisioningExecutor
        {
            /** @var array<int, string> */
            public array $scripts = [];

            public function executeConfirmable(SnmpOlt $olt, string $script): array
            {
                $this->scripts[] = $script;

                return ['ok' => true, 'output' => 'reboot ok', 'error' => null];
            }
        };
        $this->app->instance(ZteCliProvisioningExecutor::class, $executor);

        $response = $this->actingAs($user)->post(route('smartolt.onu.reboot', [$olt, 2, 1, 1]));

        $response->assertRedirect(route('smartolt.port-onus', [$olt, 2, 1]));
        $this->assertCount(1, $executor->scripts);
        $this->assertStringContainsString('pon-onu-mng gpon-onu_1/2/1:1', $executor->scripts[0]);
        $this->assertStringContainsString('reboot', $executor->scripts[0]);
    }

    public function test_onu_state_toggle_writes_admin_state_via_snmp_set(): void
    {
        $user = User::factory()->create();
        $olt = SnmpOlt::create([
            'name' => 'PATI-ZTE-C320',
            'vendor' => 'ZTE C320',
            'ip' => '10.10.10.21',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_write_community' => 'private',
            'snmp_version' => 'v2c',
        ]);

        $snmp = new class extends OltSnmpClient
        {
            /** @var array<int, array<string, string>> */
            public array $sets = [];

            public function set(SnmpOlt $olt, string $oid, string $type, string $value): bool
            {
                $this->sets[] = compact('oid', 'type', 'value');

                return true;
            }
        };
        $this->app->instance(OltSnmpClient::class, $snmp);

        $response = $this->actingAs($user)->post(route('smartolt.onu.state', [$olt, 2, 1, 1]), [
            'active' => 0,
            'if_index' => 268566784,
        ]);

        $response->assertRedirect(route('smartolt.port-onus', [$olt, 2, 1]));
        $this->assertCount(1, $snmp->sets);
        $this->assertSame('1.3.6.1.4.1.3902.1012.3.28.1.1.17.268566784.1', $snmp->sets[0]['oid']);
        $this->assertSame('i', $snmp->sets[0]['type']);
        $this->assertSame('2', $snmp->sets[0]['value']);
    }

    public function test_onu_info_update_writes_name_and_description_via_snmp_set(): void
    {
        $user = User::factory()->create();
        $olt = SnmpOlt::create([
            'name' => 'PATI-ZTE-C320',
            'vendor' => 'ZTE C320',
            'ip' => '10.10.10.22',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_write_community' => 'private',
            'snmp_version' => 'v2c',
        ]);

        $snmp = new class extends OltSnmpClient
        {
            /** @var array<int, array<string, string>> */
            public array $sets = [];

            public function set(SnmpOlt $olt, string $oid, string $type, string $value): bool
            {
                $this->sets[] = compact('oid', 'type', 'value');

                return true;
            }
        };
        $this->app->instance(OltSnmpClient::class, $snmp);

        $response = $this->actingAs($user)->post(route('smartolt.onu.info', [$olt, 2, 1, 1]), [
            'name' => 'Budi Santoso',
            'description' => 'Paket 50Mbps',
            'if_index' => 268566784,
        ]);

        $response->assertRedirect(route('smartolt.port-onus', [$olt, 2, 1]));
        $this->assertCount(2, $snmp->sets);
        $this->assertSame('1.3.6.1.4.1.3902.1012.3.28.1.1.2.268566784.1', $snmp->sets[0]['oid']);
        $this->assertSame('Budi Santoso', $snmp->sets[0]['value']);
        $this->assertSame('1.3.6.1.4.1.3902.1012.3.28.1.1.3.268566784.1', $snmp->sets[1]['oid']);
        $this->assertSame('Paket 50Mbps', $snmp->sets[1]['value']);
    }

    public function test_remote_onu_actions_are_forbidden_for_non_zte_driver(): void
    {
        $user = User::factory()->create();
        $olt = SnmpOlt::create([
            'name' => 'Lab OLT',
            'vendor' => 'Generic Vendor',
            'ip' => '10.10.10.23',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
        ]);

        $response = $this->actingAs($user)->post(route('smartolt.onu.reboot', [$olt, 2, 1, 1]));

        $response->assertForbidden();
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
