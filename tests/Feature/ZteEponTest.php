<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\PollOltJob;
use App\Models\SmartOltOnuRegistration;
use App\Models\SnmpOlt;
use App\Models\User;
use App\Services\AlarmEvaluator;
use App\Services\Snmp\OltSnmpClient;
use App\Services\ZteCliProvisioningExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Kartu EPON ZTE C300/C320 (App\Support\ZteEpon) — poll lewat CLI, aksi per port, registrasi.
 * Keluaran CLI tiruan mengikuti fixture asli di tests/Unit/ZteEponCliParserTest.php.
 */
class ZteEponTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> awalan perintah → keluaran tiruan */
    private array $responses = [];

    private ?object $executor = null;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();

        $this->responses = [
            'show card' => "  1    1     1    GTGO    GTGOG    8     V1.0.0  V2.1.0          INSERVICE\n"
                ."  1    1     3    ETGO    ETGOD    8     V1.0.0  V2.1.0          INSERVICE\n"
                .'  1    1     4    SMXA    SMXA     3     V1.0.0  V2.1.0          INSERVICE',
            'show epon onu state' => "OnuIndex               OnlineStatus  OamStatus   RegMac\n"
                ."--------------------------------------------------------------------\n"
                ."epon-onu_1/3/1:1       Online       complete     1c87.7912.0c39\n"
                ."epon-onu_1/3/1:2       Offline      idle         0000.0000.0000\n"
                .'ONU Number: 1/2',
            'show running-config interface epon-olt_1/3/1' => "interface epon-olt_1/3/1\n"
                ."  onu 1 type ALL-EPON mac 1c87.7912.0c39 ip-cfg static\n"
                ."  onu 2 type ALL-EPON mac c07e.405b.1dde ip-cfg static\n!",
            'show running-config interface epon-olt_' => '',
            'show pon power onu-rx epon-olt_1/3/1' => 'epon-onu_1/3/1:1      -21.250(dbm)',
            'show pon power onu-rx' => '',
            'show running-config interface epon-onu_1/3/1:1' => "interface epon-onu_1/3/1:1\n  property description \$\$Pelanggan Uji\$\$Jl. Contoh\n  sla-profile EPON-20M vport 1\n  service-port 1 vport 1 user-vlan 110 vlan 110\n!",
            'show running-config interface epon-onu_1/3/1:2' => "interface epon-onu_1/3/1:2\n  property description \$\$Pelanggan Dua\$\$\n!",
            'show onu unauthentication epon-olt_1/3/1' => "Onu Interface    : epon-onu_1/3/1:1\nOnu Model        : RL-4W\nMAC Address      : 1c18.4a0d.d30f\nOnline State     : authentication deny",
        ];
    }

    public function test_poll_with_switch_off_sends_no_cli_and_keeps_gpon_snapshot(): void
    {
        $executor = $this->fakeExecutor();
        $olt = $this->makeOlt(['polling_enabled' => true]);

        (new PollOltJob($olt->id))->handle($this->fakeClient(), new AlarmEvaluator);

        $snapshot = $olt->fresh()->last_test_result;
        $this->assertSame([], $executor->scripts);
        $this->assertSame(['gpon-olt_1/1/1'], array_column($snapshot['ports'], 'name'));
        $this->assertArrayNotHasKey('epon', $snapshot);
    }

    public function test_poll_adds_epon_ports_and_onus_from_cli(): void
    {
        config(['services.zte_epon.enabled' => true]);
        $executor = $this->fakeExecutor();
        $olt = $this->makeOlt(['polling_enabled' => true]);

        (new PollOltJob($olt->id))->handle($this->fakeClient(), new AlarmEvaluator);

        $snapshot = $olt->fresh()->last_test_result;
        $epon = array_values(array_filter($snapshot['ports'], fn ($p) => ($p['pon_type'] ?? null) === 'epon'));
        $this->assertCount(8, $epon);
        $this->assertSame('epon-olt_1/3/1', $epon[0]['name']);
        $this->assertSame('up', $epon[0]['oper_status']);
        $this->assertSame('unknown', $epon[1]['oper_status']);

        $onus = $snapshot['port_onus']['3_1']['onus'];
        $this->assertCount(2, $onus);
        $this->assertSame('epon-onu_1/3/1:1', $onus[0]['interface']);
        $this->assertSame('1C:87:79:12:0C:39', $onus[0]['serial_number']);
        $this->assertTrue($onus[0]['online']);
        $this->assertFalse($onus[1]['online']);
        // Data GPON tetap utuh.
        $this->assertSame('ZTEG08000001', $snapshot['port_onus']['1_1']['onus'][0]['serial_number']);
        // Poll pertama belum tahu port EPON → tanpa running-config per port.
        $this->assertStringNotContainsString('show running-config', $executor->scripts[0]);
    }

    public function test_second_poll_reads_running_config_for_stable_mac_and_carries_names(): void
    {
        config(['services.zte_epon.enabled' => true]);
        $executor = $this->fakeExecutor();
        $olt = $this->makeOlt(['polling_enabled' => true]);

        (new PollOltJob($olt->id))->handle($this->fakeClient(), new AlarmEvaluator);
        $snapshot = $olt->fresh()->last_test_result;
        $snapshot['port_onus']['3_1']['onus'][0]['name'] = 'Pelanggan Uji';
        $olt->forceFill(['last_test_result' => $snapshot, 'last_polled_at' => null])->save();

        (new PollOltJob($olt->id))->handle($this->fakeClient(), new AlarmEvaluator);

        $this->assertStringContainsString('show running-config interface epon-olt_1/3/1', $executor->scripts[1]);
        $onus = $olt->fresh()->last_test_result['port_onus']['3_1']['onus'];
        $this->assertSame('C0:7E:40:5B:1D:DE', $onus[1]['serial_number']);
        $this->assertSame('ALL-EPON', $onus[1]['type_name']);
        $this->assertSame('Pelanggan Uji', $onus[0]['name']);
    }

    public function test_poll_cli_failure_keeps_previous_epon_data(): void
    {
        config(['services.zte_epon.enabled' => true]);
        $olt = $this->makeOlt(['polling_enabled' => true], $this->eponSnapshot());
        $this->fakeExecutor(fail: true);

        (new PollOltJob($olt->id))->handle($this->fakeClient(), new AlarmEvaluator);

        $snapshot = $olt->fresh()->last_test_result;
        $this->assertContains('epon-olt_1/3/1', array_column($snapshot['ports'], 'name'));
        $this->assertCount(2, $snapshot['port_onus']['3_1']['onus']);
        $this->assertFalse($snapshot['epon']['ok']);
    }

    public function test_empty_onu_state_does_not_mark_everything_offline(): void
    {
        config(['services.zte_epon.enabled' => true]);
        $olt = $this->makeOlt(['polling_enabled' => true], $this->eponSnapshot());
        $this->responses['show epon onu state'] = '%Error 20200: Invalid input detected';
        $this->fakeExecutor();

        (new PollOltJob($olt->id))->handle($this->fakeClient(), new AlarmEvaluator);

        $onus = $olt->fresh()->last_test_result['port_onus']['3_1']['onus'];
        $this->assertTrue($onus[0]['online']);
    }

    public function test_gpon_only_olt_is_rechecked_at_most_every_six_hours(): void
    {
        config(['services.zte_epon.enabled' => true]);
        $this->responses['show card'] = '  1    1     1    GTGO    GTGOG    8     V1.0.0  V2.1.0          INSERVICE';
        $executor = $this->fakeExecutor();
        $olt = $this->makeOlt(['polling_enabled' => true]);

        (new PollOltJob($olt->id))->handle($this->fakeClient(), new AlarmEvaluator);
        $olt->forceFill(['last_polled_at' => null])->save();
        (new PollOltJob($olt->id))->handle($this->fakeClient(), new AlarmEvaluator);

        $this->assertCount(1, $executor->scripts);
        $this->assertSame([], $olt->fresh()->last_test_result['epon']['cards']);
    }

    public function test_switching_off_strips_epon_from_snapshot_on_next_poll(): void
    {
        $olt = $this->makeOlt(['polling_enabled' => true], $this->eponSnapshot());
        $this->fakeExecutor();

        (new PollOltJob($olt->id))->handle($this->fakeClient(), new AlarmEvaluator);

        $snapshot = $olt->fresh()->last_test_result;
        $this->assertArrayNotHasKey('3_1', $snapshot['port_onus']);
        $this->assertNotContains('epon-olt_1/3/1', array_column($snapshot['ports'], 'name'));
    }

    public function test_port_page_uses_epon_capabilities(): void
    {
        config(['services.zte_epon.enabled' => true]);
        $olt = $this->makeOlt([], $this->eponSnapshot());

        $this->actingAs($this->admin())
            ->get(route('smartolt.port-onus', [$olt, 3, 1]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('SmartOlt/PortOnus')
                ->where('pon_type', 'epon')
                ->where('olt.capabilities.is_epon_port', true)
                ->where('olt.capabilities.supports_onu_toggle', false)
                ->where('olt.capabilities.supports_reboot', true));

        $this->actingAs($this->admin())
            ->get(route('smartolt.port-onus', [$olt, 1, 1]))
            ->assertInertia(fn ($page) => $page
                ->where('pon_type', 'gpon')
                ->where('olt.capabilities.supports_onu_toggle', true));
    }

    public function test_port_refresh_reads_names_in_second_session(): void
    {
        config(['services.zte_epon.enabled' => true]);
        $executor = $this->fakeExecutor();
        $olt = $this->makeOlt([], $this->eponSnapshot());

        $this->actingAs($this->admin())
            ->post(route('smartolt.port-onus.refresh', [$olt, 3, 1]))
            ->assertRedirect();

        $this->assertCount(2, $executor->scripts);
        $this->assertStringContainsString('show running-config interface epon-onu_1/3/1:2', $executor->scripts[1]);
        $onus = $olt->fresh()->last_test_result['port_onus']['3_1']['onus'];
        $this->assertSame('Pelanggan Uji', $onus[0]['name']);
        $this->assertSame('Jl. Contoh', $onus[0]['description']);
        $this->assertSame('EPON-20M', $onus[0]['sla_profile']);
        $this->assertSame([110], $onus[0]['vlans']);
        $this->assertSame(-21.25, $onus[0]['rx_power_dbm']);
        $this->assertSame('Pelanggan Dua', $onus[1]['name']);
    }

    public function test_reboot_delete_and_rename_use_epon_interfaces(): void
    {
        config(['services.zte_epon.enabled' => true]);
        $executor = $this->fakeExecutor();
        $olt = $this->makeOlt([], $this->eponSnapshot());
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('smartolt.onu.reboot', [$olt, 3, 1, 1]))->assertRedirect();
        $this->assertStringContainsString("pon-onu-mng epon-onu_1/3/1:1\nreboot", $executor->scripts[0]);

        $this->actingAs($admin)->post(route('smartolt.onu.info', [$olt, 3, 1, 1]), ['name' => 'Nama Baru'])->assertRedirect();
        $this->assertStringContainsString("interface epon-onu_1/3/1:1\nproperty description \$\$Nama Baru\$\$Desk Lama", $executor->scripts[1]);
        $this->assertSame('Nama Baru', $olt->fresh()->last_test_result['port_onus']['3_1']['onus'][0]['name']);

        $this->actingAs($admin)->post(route('smartolt.onu.delete', [$olt, 3, 1, 2]))->assertRedirect();
        $this->assertStringContainsString("interface epon-olt_1/3/1\nno onu 2", $executor->scripts[2]);
    }

    public function test_gpon_only_actions_are_refused_on_epon_port(): void
    {
        config(['services.zte_epon.enabled' => true]);
        $executor = $this->fakeExecutor();
        $olt = $this->makeOlt([], $this->eponSnapshot());
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('smartolt.onu.state', [$olt, 3, 1, 1]), ['active' => false])->assertForbidden();
        $this->actingAs($admin)->get(route('smartolt.onu.detail', [$olt, 3, 1, 1]))->assertForbidden();
        $this->actingAs($admin)->postJson(route('smartolt.port.admin-state', $olt), ['slot' => 3, 'port' => 1, 'enabled' => false])->assertForbidden();
        $this->actingAs($admin)->post(route('smartolt.register.store', $olt), $this->gponRegistration(3, 1))->assertStatus(422);

        $this->assertSame([], $executor->scripts);
    }

    public function test_register_form_switches_to_epon_page(): void
    {
        config(['services.zte_epon.enabled' => true]);
        $olt = $this->makeOlt([], $this->eponSnapshot());

        $this->actingAs($this->admin())
            ->get(route('smartolt.register', ['olt' => $olt, 'slot' => 3, 'port' => 1, 'sn' => '1C:18:4A:0D:D3:0F']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('SmartOlt/RegisterEponOnu')
                ->where('defaults.mac', '1C:18:4A:0D:D3:0F')
                ->where('defaults.onu_id', 3)
                ->where('defaults.service_style', 'service-port')
                ->where('suggestions.onu_types.0', 'ALL-EPON'));
    }

    public function test_register_epon_generate_preview_and_execute(): void
    {
        config(['services.zte_epon.enabled' => true]);
        $executor = $this->fakeExecutor();
        $olt = $this->makeOlt([], $this->eponSnapshot());
        $admin = $this->admin();
        $payload = [
            'slot' => 3, 'port' => 1, 'onu_id' => 3, 'mac' => '1c18.4a0d.d30f', 'onu_type' => 'ALL-EPON',
            'customer_name' => 'Pelanggan Baru', 'description' => '', 'sla_profile' => 'EPON-20M', 'vlan' => 110,
            'service_style' => 'service-port', 'onu_vlan_mode' => 'none',
        ];

        $this->actingAs($admin)
            ->postJson(route('smartolt.register-epon.preview', $olt), $payload)
            ->assertOk()
            ->assertJsonPath('script', fn (string $script) => str_contains($script, 'onu 3 type ALL-EPON mac 1c18.4a0d.d30f'));

        $this->actingAs($admin)->post(route('smartolt.register-epon.store', $olt), $payload)->assertRedirect();
        $generated = SmartOltOnuRegistration::sole();
        $this->assertSame('generated', $generated->status);
        $this->assertSame('1C:18:4A:0D:D3:0F', $generated->serial_number);
        $this->assertSame('epon-onu_1/3/1:3', $generated->pon_port);
        $this->assertSame([], $executor->scripts);

        $this->actingAs($admin)->post(route('smartolt.register-epon.store', $olt), [...$payload, 'execute' => true])->assertRedirect();
        $this->assertSame('executed', SmartOltOnuRegistration::latest('id')->first()->status);
        $this->assertStringContainsString('service-port 1 vport 1 user-vlan 110 vlan 110', $executor->scripts[0]);
    }

    public function test_register_epon_rejects_gpon_port_and_bad_mac(): void
    {
        config(['services.zte_epon.enabled' => true]);
        $olt = $this->makeOlt([], $this->eponSnapshot());
        $admin = $this->admin();
        $payload = [
            'slot' => 1, 'port' => 1, 'onu_id' => 3, 'mac' => '1c18.4a0d.d30f', 'onu_type' => 'ALL-EPON',
            'customer_name' => 'X', 'vlan' => 110, 'service_style' => 'service-port', 'onu_vlan_mode' => 'none',
        ];

        $this->actingAs($admin)->post(route('smartolt.register-epon.store', $olt), $payload)->assertStatus(422);
        $this->actingAs($admin)
            ->post(route('smartolt.register-epon.store', $olt), [...$payload, 'slot' => 3, 'mac' => 'ZTEGC1234567'])
            ->assertSessionHasErrors('mac');
        $this->assertSame(0, SmartOltOnuRegistration::count());
    }

    public function test_unconfigured_refresh_appends_epon_rows(): void
    {
        config(['services.zte_epon.enabled' => true]);
        $this->fakeExecutor();
        $this->app->instance(OltSnmpClient::class, $this->fakeClient());
        $olt = $this->makeOlt([], $this->eponSnapshot());

        $this->actingAs($this->admin())->post(route('smartolt.unconfigured.refresh', $olt))->assertRedirect();

        $rows = $olt->fresh()->last_test_result['unconfigured_onus']['onus'];
        $this->assertCount(1, $rows);
        $this->assertSame('1C:18:4A:0D:D3:0F', $rows[0]['serial_number']);
        $this->assertSame('epon', $rows[0]['pon_type']);
        $this->assertSame(3, $rows[0]['suggested_onu_id']);
    }

    public function test_api_rename_and_register_on_epon_port(): void
    {
        config(['services.zte_epon.enabled' => true]);
        $executor = $this->fakeExecutor();
        $olt = $this->makeOlt([], $this->eponSnapshot());
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/olts/{$olt->id}/onus/3/1/1/name", ['description' => 'Desk Baru'])
            ->assertOk();
        $this->assertStringContainsString('property description $$Pelanggan Lama$$Desk Baru', $executor->scripts[0]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/olts/{$olt->id}/register/preview", $this->gponRegistration(3, 1))
            ->assertStatus(422);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    /**
     * @return array<string, mixed>
     */
    private function gponRegistration(int $slot, int $port): array
    {
        return [
            'serial_number' => 'ZTEGC0800001', 'slot' => $slot, 'port' => $port, 'onu_id' => 3,
            'customer_name' => 'Uji', 'onu_type' => 'ALL-ONT', 'tcont_profile' => 'SERVER', 'vlan' => 100,
            'vlan_profile' => 'ServiceName', 'service_name' => 'ServiceName', 'service_mode' => 'vlanpri',
            'wan_mode' => 'bridge',
        ];
    }

    /**
     * Snapshot OLT C320 dengan satu port GPON 1/1 dan satu port EPON 3/1 (2 ONU).
     *
     * @return array<string, mixed>
     */
    private function eponSnapshot(): array
    {
        $now = now()->toIso8601String();
        $eponOnu = fn (int $id, bool $online, string $mac, ?string $name, ?string $desc) => [
            'if_index' => null, 'onu_id' => $id, 'slot' => 3, 'port' => 1, 'interface' => "epon-onu_1/3/1:{$id}",
            'type_name' => 'ALL-EPON', 'name' => $name, 'description' => $desc, 'serial_number' => $mac, 'mac' => $mac,
            'phase_state' => $online ? 'Online' : 'Offline', 'online' => $online, 'last_down_cause' => null,
            'rx_power_dbm' => null, 'pon_type' => 'epon',
        ];

        return [
            'ok' => true,
            'system' => ['sys_descr' => 'ZXA10 C320 Software Version V2.1.0'],
            'ports' => [
                ['if_index' => 268501248, 'name' => 'gpon-olt_1/1/1', 'slot' => 1, 'port' => 1, 'oper_status' => 'up'],
                ['if_index' => null, 'name' => 'epon-olt_1/3/1', 'slot' => 3, 'port' => 1, 'oper_status' => 'up', 'pon_type' => 'epon'],
            ],
            'port_onus' => [
                '1_1' => ['ok' => true, 'slot' => 1, 'port' => 1, 'count' => 1, 'refreshed_at' => $now, 'onus' => [[
                    'if_index' => 268501248, 'onu_id' => 1, 'slot' => 1, 'port' => 1, 'interface' => 'gpon-onu_1/1/1:1',
                    'serial_number' => 'ZTEG08000001', 'online' => true, 'phase_state' => 'Working', 'admin_state' => 'active',
                ]]],
                '3_1' => ['ok' => true, 'slot' => 3, 'port' => 1, 'count' => 2, 'refreshed_at' => $now, 'pon_type' => 'epon', 'onus' => [
                    $eponOnu(1, true, '1C:87:79:12:0C:39', 'Pelanggan Lama', 'Desk Lama'),
                    $eponOnu(2, false, 'C0:7E:40:5B:1D:DE', null, null),
                ]],
            ],
            'epon' => ['ok' => true, 'error' => null, 'checked_at' => $now, 'cards' => [['slot' => 3, 'cfg_type' => 'ETGO', 'real_type' => 'ETGOD', 'ports' => 8, 'status' => 'INSERVICE']]],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  array<string, mixed>|null  $snapshot
     */
    private function makeOlt(array $overrides = [], ?array $snapshot = null): SnmpOlt
    {
        return SnmpOlt::create([
            'name' => 'OLT-UJI-C320',
            'vendor' => 'ZTE C320',
            'ip' => '10.30.0.'.random_int(2, 250),
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'cli_transport' => 'telnet',
            'cli_port' => 23,
            'cli_username' => 'admin',
            'cli_password' => 'secret',
            'last_test_result' => $snapshot,
            ...$overrides,
        ]);
    }

    private function fakeClient(): OltSnmpClient
    {
        return new class extends OltSnmpClient
        {
            public function __construct() {}

            public function snapshot(SnmpOlt $olt): array
            {
                return [
                    'ok' => true,
                    'driver' => 'zte',
                    'latency_ms' => 10,
                    'system' => ['sys_name' => 'OLT-UJI', 'sys_descr' => 'ZXA10 C320 Software Version V2.1.0'],
                    'ports' => [
                        ['if_index' => 268501248, 'name' => 'gpon-olt_1/1/1', 'slot' => 1, 'port' => 1, 'oper_status_code' => 1, 'oper_status' => 'up'],
                    ],
                    'error' => null,
                ];
            }

            public function registeredOnus(SnmpOlt $olt, ?array $ports = null, ?string $scope = null): array
            {
                return [[
                    'if_index' => 268501248, 'onu_id' => 1, 'slot' => 1, 'port' => 1,
                    'interface' => 'gpon-onu_1/1/1:1', 'type_name' => 'F660', 'name' => 'GPON',
                    'description' => '', 'serial_number' => 'ZTEG08000001',
                    'admin_state_code' => 1, 'admin_state' => 'active',
                    'phase_state_code' => 3, 'phase_state' => 'Working', 'online' => true,
                    'last_down_cause_code' => 0, 'last_down_cause' => 'Normal',
                ]];
            }

            public function onuRxPowers(SnmpOlt $olt, ?string $scope = null): array
            {
                return [];
            }

            public function unconfiguredOnusSnapshot(SnmpOlt $olt): array
            {
                return ['ok' => true, 'count' => 0, 'onus' => [], 'latency_ms' => 1, 'error' => null];
            }
        };
    }

    private function fakeExecutor(bool $fail = false): object
    {
        $test = $this;
        $executor = new class($test, $fail) extends ZteCliProvisioningExecutor
        {
            /** @var list<string> */
            public array $scripts = [];

            public function __construct(private ZteEponTest $test, private bool $fail) {}

            public function execute(SnmpOlt $olt, string $script, bool $largeOutput = false): array
            {
                return $this->run($script);
            }

            public function executeConfirmable(SnmpOlt $olt, string $script): array
            {
                return $this->run($script);
            }

            public function executeScan(SnmpOlt $olt, string $script): array
            {
                return $this->run($script);
            }

            /**
             * @return array{ok:bool, output:string, error:string|null}
             */
            private function run(string $script): array
            {
                $this->scripts[] = $script;

                if ($this->fail) {
                    throw new RuntimeException('Telnet 10.30.0.1:23 tidak bisa dihubungi');
                }

                $output = 'OLT-UJI#';
                foreach (explode("\n", $script) as $command) {
                    $output .= "\n> {$command}\n".$this->test->respond($command)."\nOLT-UJI#";
                }

                return ['ok' => true, 'output' => $output, 'error' => null];
            }
        };

        $this->app->instance(ZteCliProvisioningExecutor::class, $executor);
        $this->executor = $executor;

        return $executor;
    }

    /**
     * Keluaran tiruan untuk satu perintah: awalan terpanjang yang cocok.
     */
    public function respond(string $command): string
    {
        $best = '';
        $output = '';
        foreach ($this->responses as $prefix => $text) {
            if (str_starts_with($command, $prefix) && strlen($prefix) > strlen($best)) {
                $best = $prefix;
                $output = $text;
            }
        }

        return $output;
    }
}
