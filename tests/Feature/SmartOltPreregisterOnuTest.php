<?php

namespace Tests\Feature;

use App\Models\SmartOltOnuRegistration;
use App\Models\SmartOltProfile;
use App\Models\SnmpOlt;
use App\Models\User;
use App\Services\Snmp\OltSnmpClient;
use App\Services\ZteCliProvisioningExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Pre-Register ONU: SN diketik manual dan didaftarkan ke OLT sebagai ONU offline sebelum perangkatnya
 * tersambung (C300/C320). Mesin skripnya registrasi biasa; yang dikunci di sini adalah pengaman SN
 * manual (format, SN sudah terdaftar, ONU ID terpakai — semuanya sebelum OLT disentuh), penanda
 * `preregistered`, penyegaran cache port, gate C600, dan status di riwayat registrasi.
 */
class SmartOltPreregisterOnuTest extends TestCase
{
    use RefreshDatabase;

    private function makeOlt(array $overrides = []): SnmpOlt
    {
        $olt = SnmpOlt::create(array_merge([
            'name' => 'BMKV-C320',
            'vendor' => 'ZTE C320',
            'ip' => '10.32.0.10',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'cli_transport' => 'telnet',
            'cli_port' => 23,
            'cli_username' => 'admin',
            'cli_password' => 'secret',
            'last_test_result' => [
                'port_onus' => [
                    '1_2' => ['ok' => true, 'onus' => [
                        ['onu_id' => 1, 'serial_number' => 'ZTEGC0800001', 'online' => true, 'phase_state' => 'Working'],
                        ['onu_id' => 2, 'serial_number' => 'ZTEGC0800002', 'online' => true, 'phase_state' => 'Working'],
                    ]],
                    '1_5' => ['ok' => true, 'onus' => [
                        ['onu_id' => 4, 'serial_number' => 'ZTEGC0800AAA', 'online' => false, 'phase_state' => 'LOS'],
                    ]],
                ],
            ],
        ], $overrides));

        foreach ([['onu_type', 'ALL-ONT'], ['tcont', 'SERVER']] as [$type, $name]) {
            SmartOltProfile::firstOrCreate(
                ['snmp_olt_id' => null, 'profile_type' => $type, 'name' => $name],
                ['is_active' => true],
            );
        }

        return $olt;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'serial_number' => 'zteg-c080 0abc',
            'slot' => 1,
            'port' => 2,
            'onu_id' => 3,
            'customer_name' => 'Pelanggan Pre-Register',
            'onu_type' => 'ALL-ONT',
            'tcont_profile' => 'SERVER',
            'vlan' => 1114,
            'service_name' => 'ServiceName',
            'wan_mode' => 'pppoe',
            'pppoe_username' => 'prereg0800',
            'pppoe_password' => 'rahasia0800',
            'preregister' => true,
            'execute' => true,
        ], $overrides);
    }

    /**
     * Eksekutor palsu yang mencatat skrip; $fail = true → test gagal bila OLT sampai disentuh.
     */
    private function fakeExecutor(bool $fail = false): object
    {
        $executor = new class($fail) extends ZteCliProvisioningExecutor
        {
            public array $scripts = [];

            public function __construct(private readonly bool $fail) {}

            public function execute(SnmpOlt $olt, string $script, bool $largeOutput = false): array
            {
                if ($this->fail) {
                    throw new RuntimeException('OLT tidak boleh disentuh: '.$script);
                }

                $this->scripts[] = $script;

                return ['ok' => true, 'error' => null, 'output' => 'BMKV-C320#'];
            }
        };
        $this->app->instance(ZteCliProvisioningExecutor::class, $executor);

        return $executor;
    }

    private function fakePortSnapshot(): void
    {
        $this->app->instance(OltSnmpClient::class, new class extends OltSnmpClient
        {
            public function portOnusSnapshot(SnmpOlt $olt, int $slot, int $port): array
            {
                return ['ok' => true, 'count' => 3, 'onus' => [
                    ['onu_id' => 1, 'serial_number' => 'ZTEGC0800001', 'online' => true, 'phase_state' => 'Working'],
                    ['onu_id' => 2, 'serial_number' => 'ZTEGC0800002', 'online' => true, 'phase_state' => 'Working'],
                    ['onu_id' => 3, 'serial_number' => 'ZTEGC0800ABC', 'online' => false, 'phase_state' => 'Offline'],
                ]];
            }
        });
    }

    public function test_form_in_pre_mode_sends_flag_and_used_onu_ids(): void
    {
        $olt = $this->makeOlt();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('smartolt.register', ['olt' => $olt->id, 'slot' => 1, 'port' => 2, 'pre' => 1]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('SmartOlt/RegisterOnu')
                ->where('preregister', true)
                ->where('port_used_onu_ids.1_2', [1, 2])
                ->where('port_used_onu_ids.1_5', [4])
                ->where('defaults.onu_id', 3)
                ->where('defaults.serial_number', '')
            );
    }

    public function test_form_ignores_pre_mode_on_c600(): void
    {
        $olt = $this->makeOlt(['name' => 'BMKV-C600', 'vendor' => 'ZTE C600']);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('smartolt.register', ['olt' => $olt->id, 'pre' => 1]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('preregister', false)
                ->where('port_used_onu_ids', [])
            );
    }

    public function test_preregister_writes_offline_onu_with_normalized_serial(): void
    {
        $olt = $this->makeOlt();
        $executor = $this->fakeExecutor();
        $this->fakePortSnapshot();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('smartolt.register.store', $olt), $this->payload())
            ->assertRedirect(route('smartolt.registrations', $olt))
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'ZTEGC0800ABC')
                && str_contains($message, 'gpon-onu_1/1/2:3'));

        $this->assertCount(1, $executor->scripts);
        $this->assertStringContainsString("interface gpon-olt_1/1/2\nonu 3 type ALL-ONT sn ZTEGC0800ABC", $executor->scripts[0]);
        $this->assertStringContainsString('wan-ip 1 mode pppoe username prereg0800', $executor->scripts[0]);

        $row = SmartOltOnuRegistration::firstOrFail();
        $this->assertSame('executed', $row->status);
        $this->assertTrue($row->preregistered);
        $this->assertSame('ZTEGC0800ABC', $row->serial_number);

        // Cache port disegarkan → ONU offline-nya langsung tampil di daftar port.
        $this->assertCount(3, data_get($olt->fresh()->last_test_result, 'port_onus.1_2.onus'));
    }

    public function test_generate_only_keeps_the_marker_without_touching_the_olt(): void
    {
        $olt = $this->makeOlt();
        $this->fakeExecutor(fail: true);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('smartolt.register.store', $olt), $this->payload(['execute' => false]))
            ->assertRedirect(route('smartolt.registrations', $olt));

        $row = SmartOltOnuRegistration::firstOrFail();
        $this->assertSame('generated', $row->status);
        $this->assertTrue($row->preregistered);
    }

    public function test_rejects_invalid_serial_format_before_touching_the_olt(): void
    {
        $olt = $this->makeOlt();
        $this->fakeExecutor(fail: true);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('smartolt.register.store', $olt), $this->payload(['serial_number' => 'ZTEG-XYZ']))
            ->assertSessionHasErrors('serial_number');

        $this->assertDatabaseCount('smartolt_onu_registrations', 0);
    }

    public function test_rejects_serial_already_registered_on_the_olt(): void
    {
        $olt = $this->makeOlt();
        $this->fakeExecutor(fail: true);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('smartolt.register.store', $olt), $this->payload(['serial_number' => 'zteg c0800aaa']))
            ->assertSessionHasErrors(['serial_number' => 'SN ini sudah terdaftar di OLT ini (gpon-onu_1/1/5:4).']);

        $this->assertDatabaseCount('smartolt_onu_registrations', 0);
    }

    public function test_rejects_onu_id_already_used_on_the_port(): void
    {
        $olt = $this->makeOlt();
        $this->fakeExecutor(fail: true);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('smartolt.register.store', $olt), $this->payload(['onu_id' => 2]))
            ->assertSessionHasErrors('onu_id');

        $this->assertDatabaseCount('smartolt_onu_registrations', 0);
    }

    public function test_preregister_is_forbidden_on_c600(): void
    {
        $olt = $this->makeOlt(['name' => 'BMKV-C600', 'vendor' => 'ZTE C600']);
        $this->fakeExecutor(fail: true);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('smartolt.register.store', $olt), $this->payload())
            ->assertForbidden();

        $this->assertDatabaseCount('smartolt_onu_registrations', 0);
    }

    public function test_advanced_preregister_marks_the_row(): void
    {
        $olt = $this->makeOlt();
        $executor = $this->fakeExecutor();
        $this->fakePortSnapshot();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('smartolt.register.advanced.store', $olt), [
                'serial_number' => 'ztegc0800abc',
                'slot' => 1,
                'port' => 2,
                'onu_id' => 3,
                'onu_type' => 'ALL-ONT',
                'preregister' => true,
                'execute' => true,
                'config' => [
                    'name' => 'Pelanggan Pre-Register',
                    'tconts' => [['id' => 1, 'name' => '1', 'profile' => 'SERVER', 'gap' => 'mode0']],
                    'gemports' => [['id' => 1, 'name' => '1', 'tcont' => 1]],
                    'service_ports' => [['id' => 1, 'vport' => 1, 'user_vlan' => 1114, 'vlan' => 1114]],
                    'services' => [['name' => 'ServiceName', 'mode' => 'vlanpri', 'gem' => 1, 'cos' => 0, 'vlan' => 1114]],
                    'wan_ips' => [],
                    'tr069' => false,
                    'remote_ont' => false,
                ],
            ])
            ->assertRedirect(route('smartolt.registrations', $olt))
            ->assertSessionHas('success');

        $this->assertStringContainsString('onu 3 type ALL-ONT sn ZTEGC0800ABC', $executor->scripts[0]);
        $row = SmartOltOnuRegistration::firstOrFail();
        $this->assertTrue($row->preregistered);
        $this->assertSame('ZTEGC0800ABC', $row->serial_number);
    }

    public function test_normal_registration_is_not_marked_and_keeps_serial_as_typed(): void
    {
        $olt = $this->makeOlt();
        $this->fakeExecutor();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('smartolt.register.store', $olt), $this->payload([
                'preregister' => false,
                'serial_number' => 'ZTEGC0800ABC',
            ]))
            ->assertRedirect(route('smartolt.registrations', $olt))
            ->assertSessionHas('success', 'ONU berhasil diregister & dieksekusi ke OLT.');

        $this->assertFalse(SmartOltOnuRegistration::firstOrFail()->preregistered);
    }

    public function test_registrations_page_shows_preregister_status_from_port_cache(): void
    {
        $olt = $this->makeOlt([
            'last_test_result' => ['port_onus' => ['1_2' => ['ok' => true, 'onus' => [
                ['onu_id' => 3, 'serial_number' => 'ZTEGC0800AB3', 'online' => false, 'phase_state' => 'Offline'],
                ['onu_id' => 4, 'serial_number' => 'ZTEGC0800AB4', 'online' => true, 'phase_state' => 'Working'],
                ['onu_id' => 5, 'serial_number' => 'ZTEGC0800AB5', 'online' => false, 'phase_state' => 'DyingGasp'],
                ['onu_id' => 6, 'serial_number' => 'ZTEGC0800FFF', 'online' => true, 'phase_state' => 'Working'],
            ]]]],
        ]);

        $row = fn (int $onuId, string $serial, bool $prereg, string $status = 'executed') => SmartOltOnuRegistration::create([
            'snmp_olt_id' => $olt->id,
            'serial_number' => $serial,
            'slot' => 1,
            'port' => 2,
            'onu_id' => $onuId,
            'pon_port' => "gpon-onu_1/1/2:{$onuId}",
            'customer_name' => "Pelanggan {$onuId}",
            'onu_type' => 'ALL-ONT',
            'tcont_profile' => 'SERVER',
            'vlan' => 1114,
            'wan_mode' => 'pppoe',
            'cli_script' => 'conf t',
            'status' => $status,
            'preregistered' => $prereg,
        ])->id;

        $ids = [
            'waiting' => $row(3, 'ZTEGC0800AB3', true),
            'online' => $row(4, 'ZTEGC0800AB4', true),
            'offline' => $row(5, 'ZTEGC0800AB5', true),
            // Posisi 6 kini dipakai SN lain → ONU pre-register sudah tak ada di OLT.
            'missing' => $row(6, 'ZTEGC0800AB6', true),
            'generated' => $row(7, 'ZTEGC0800AB7', true, 'generated'),
            'normal' => $row(4, 'ZTEGC0800AB4', false),
        ];

        $response = $this->actingAs(User::factory()->admin()->create())
            ->get(route('smartolt.registrations', $olt))
            ->assertOk();

        $byId = collect($response->viewData('page')['props']['registrations'])->keyBy('id');

        $this->assertSame('waiting', $byId[$ids['waiting']]['prereg_status']);
        $this->assertSame('online', $byId[$ids['online']]['prereg_status']);
        $this->assertSame('offline', $byId[$ids['offline']]['prereg_status']);
        $this->assertSame('missing', $byId[$ids['missing']]['prereg_status']);
        $this->assertNull($byId[$ids['generated']]['prereg_status']);
        $this->assertTrue($byId[$ids['generated']]['preregistered']);
        $this->assertNull($byId[$ids['normal']]['prereg_status']);
        $this->assertFalse($byId[$ids['normal']]['preregistered']);
    }
}
