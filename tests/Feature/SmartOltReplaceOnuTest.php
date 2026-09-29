<?php

namespace Tests\Feature;

use App\Models\OnuMapPin;
use App\Models\SnmpOlt;
use App\Models\User;
use App\Services\Snmp\OltSnmpClient;
use App\Services\ZteCliProvisioningExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Bind ONU" (ganti SN ONU lama dengan ONU unconfigured di port yang sama) — ZTE C300/C320.
 */
class SmartOltReplaceOnuTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_SN = 'ZTEGC0000001';

    public function test_bind_runs_registration_method_and_moves_serial_everywhere(): void
    {
        $user = User::factory()->create();
        $olt = $this->makeOlt();
        $executor = $this->fakeExecutor();

        OnuMapPin::create([
            'snmp_olt_id' => $olt->id, 'slot' => 4, 'port' => 9, 'onu_id' => 1,
            'serial_number' => 'ZTEGC9140803', 'latitude' => -6.7, 'longitude' => 111.0,
        ]);

        $this->actingAs($user)
            ->post(route('smartolt.onu.replace', [$olt, 4, 9, 1]), ['serial_number' => self::NEW_SN, 'save_config' => true])
            ->assertRedirect(route('smartolt.port-onus', [$olt, 4, 9]))
            ->assertSessionHas('success', fn (string $m): bool => str_contains($m, 'ZTEGC9140803') && str_contains($m, self::NEW_SN));

        $this->assertCount(1, $executor->scripts);
        $this->assertStringContainsString(
            "interface gpon-onu_1/4/9:1\nregistration-method sn ".self::NEW_SN."\nexit",
            $executor->scripts[0],
        );
        $this->assertSame(1, $executor->saves);

        $olt->refresh();
        $onus = collect(data_get($olt->last_test_result, 'port_onus.4_9.onus'))->keyBy('onu_id');
        $this->assertSame(self::NEW_SN, $onus[1]['serial_number']);
        $this->assertSame('ZTEG22222222', $onus[2]['serial_number']);

        $uncfg = collect(data_get($olt->last_test_result, 'unconfigured_onus.onus'))->pluck('serial_number')->all();
        $this->assertSame(['ZTEGC0000002'], $uncfg);
        $this->assertSame(1, data_get($olt->last_test_result, 'unconfigured_onus.count'));

        $this->assertSame(self::NEW_SN, OnuMapPin::query()->value('serial_number'));
        $this->assertDatabaseHas('audit_logs', ['event' => 'onu.replaced', 'auditable_id' => $olt->id]);
    }

    public function test_bind_without_save_config_skips_write(): void
    {
        $olt = $this->makeOlt();
        $executor = $this->fakeExecutor();

        $this->actingAs(User::factory()->create())
            ->post(route('smartolt.onu.replace', [$olt, 4, 9, 1]), ['serial_number' => self::NEW_SN, 'save_config' => false])
            ->assertSessionHas('success');

        $this->assertSame(0, $executor->saves);
    }

    public function test_failed_write_is_reported_but_bind_is_kept(): void
    {
        $olt = $this->makeOlt();
        $this->fakeExecutor(saveOk: false);

        $this->actingAs(User::factory()->create())
            ->post(route('smartolt.onu.replace', [$olt, 4, 9, 1]), ['serial_number' => self::NEW_SN, 'save_config' => true])
            ->assertRedirect(route('smartolt.port-onus', [$olt, 4, 9]))
            ->assertSessionHas('error', fn (string $m): bool => str_contains($m, 'write'));

        $onus = collect(data_get($olt->refresh()->last_test_result, 'port_onus.4_9.onus'))->keyBy('onu_id');
        $this->assertSame(self::NEW_SN, $onus[1]['serial_number']);
    }

    public function test_cli_error_leaves_cache_untouched_and_is_audited(): void
    {
        $olt = $this->makeOlt();
        $this->fakeExecutor(error: "%Error 20200: Invalid input detected at '^' marker.");

        $this->actingAs(User::factory()->create())
            ->post(route('smartolt.onu.replace', [$olt, 4, 9, 1]), ['serial_number' => self::NEW_SN])
            ->assertRedirect(route('smartolt.unconfigured-all', ['olt_id' => $olt->id]))
            ->assertSessionHas('error', fn (string $m): bool => str_contains($m, '20200'));

        $olt->refresh();
        $onus = collect(data_get($olt->last_test_result, 'port_onus.4_9.onus'))->keyBy('onu_id');
        $this->assertSame('ZTEGC9140803', $onus[1]['serial_number']);
        $this->assertCount(2, data_get($olt->last_test_result, 'unconfigured_onus.onus'));
        $this->assertDatabaseHas('audit_logs', ['event' => 'onu.replace_failed']);
    }

    public function test_serial_detected_on_another_port_is_rejected(): void
    {
        $olt = $this->makeOlt();
        $executor = $this->fakeExecutor();

        $this->actingAs(User::factory()->create())
            ->post(route('smartolt.onu.replace', [$olt, 4, 9, 1]), ['serial_number' => 'ZTEGC0000002'])
            ->assertSessionHas('error', fn (string $m): bool => str_contains($m, 'Port 10'));

        $this->assertSame([], $executor->scripts);
    }

    public function test_serial_missing_from_unconfigured_list_is_rejected(): void
    {
        $olt = $this->makeOlt();
        $executor = $this->fakeExecutor();

        $this->actingAs(User::factory()->create())
            ->post(route('smartolt.onu.replace', [$olt, 4, 9, 1]), ['serial_number' => 'ZTEGC9999999'])
            ->assertSessionHas('error');

        $this->assertSame([], $executor->scripts);
    }

    public function test_unknown_target_onu_is_rejected(): void
    {
        $olt = $this->makeOlt();
        $executor = $this->fakeExecutor();

        $this->actingAs(User::factory()->create())
            ->post(route('smartolt.onu.replace', [$olt, 4, 9, 7]), ['serial_number' => self::NEW_SN])
            ->assertSessionHas('error');

        $this->assertSame([], $executor->scripts);
    }

    public function test_serial_must_be_plain_alphanumeric(): void
    {
        $olt = $this->makeOlt();
        $executor = $this->fakeExecutor();

        // Baris baru di SN = perintah CLI tambahan; wajib ditolak sebelum sampai ke OLT.
        $this->actingAs(User::factory()->create())
            ->post(route('smartolt.onu.replace', [$olt, 4, 9, 1]), ['serial_number' => "ZTEGC0000001\nno onu 2"])
            ->assertSessionHasErrors('serial_number');

        $this->assertSame([], $executor->scripts);
    }

    public function test_c600_is_not_supported_yet(): void
    {
        $olt = $this->makeOlt(['name' => 'OLT-C600-UJI', 'vendor' => 'ZTE C600']);
        $executor = $this->fakeExecutor();

        $this->actingAs(User::factory()->create())
            ->post(route('smartolt.onu.replace', [$olt, 4, 9, 1]), ['serial_number' => self::NEW_SN])
            ->assertForbidden();

        $this->actingAs(User::factory()->create())
            ->getJson(route('smartolt.onu.replace-candidates', [$olt, 4, 9]))
            ->assertForbidden();

        $this->assertSame([], $executor->scripts);
    }

    public function test_candidates_are_read_live_with_offline_onus_first(): void
    {
        $olt = $this->makeOlt();

        $this->mock(OltSnmpClient::class)
            ->shouldReceive('portOnusSnapshot')
            ->once()
            ->andReturn([
                'ok' => true,
                'count' => 3,
                'onus' => [
                    ['onu_id' => 1, 'serial_number' => 'ZTEGC9140803', 'name' => 'Pelanggan Satu', 'type_name' => 'ALL-ONT', 'online' => true, 'phase_state' => 'working'],
                    ['onu_id' => 2, 'serial_number' => 'ZTEG22222222', 'name' => 'Pelanggan Dua', 'type_name' => 'F660', 'online' => false, 'phase_state' => 'LOS'],
                    ['onu_id' => 3, 'serial_number' => 'ZTEG33333333', 'name' => null, 'type_name' => 'F609', 'online' => true, 'phase_state' => 'working'],
                ],
                'error' => null,
            ]);

        $response = $this->actingAs(User::factory()->create())
            ->getJson(route('smartolt.onu.replace-candidates', [$olt, 4, 9]))
            ->assertOk()
            ->assertJsonPath('live', true);

        $this->assertSame([2, 1, 3], array_column($response->json('onus'), 'onu_id'));
        $this->assertSame('gpon-onu_1/4/9:2', $response->json('onus.0.interface'));
        $this->assertSame('Pelanggan Dua', $response->json('onus.0.customer_name'));

        // Hasil baca live ikut menyegarkan cache port (dipakai validasi bind sesudahnya).
        $this->assertCount(3, data_get($olt->refresh()->last_test_result, 'port_onus.4_9.onus'));
    }

    public function test_candidates_fall_back_to_cache_when_olt_is_silent(): void
    {
        $olt = $this->makeOlt();

        $this->mock(OltSnmpClient::class)
            ->shouldReceive('portOnusSnapshot')
            ->andReturn(['ok' => false, 'onus' => [], 'count' => 0, 'error' => 'Timeout']);

        $response = $this->actingAs(User::factory()->create())
            ->getJson(route('smartolt.onu.replace-candidates', [$olt, 4, 9]))
            ->assertOk()
            ->assertJsonPath('live', false)
            ->assertJsonPath('error', 'Timeout');

        $this->assertSame([1, 2], array_column($response->json('onus'), 'onu_id'));
    }

    /**
     * @return ZteCliProvisioningExecutor&object{scripts: list<string>, saves: int}
     */
    private function fakeExecutor(?string $error = null, bool $saveOk = true): ZteCliProvisioningExecutor
    {
        $executor = new class($error, $saveOk) extends ZteCliProvisioningExecutor
        {
            public array $scripts = [];

            public int $saves = 0;

            public function __construct(private readonly ?string $error, private readonly bool $saveOk) {}

            public function execute(SnmpOlt $olt, string $script, bool $largeOutput = false): array
            {
                $this->scripts[] = $script;

                return ['ok' => $this->error === null, 'error' => $this->error, 'output' => 'BMKV-C300(config-if)#'];
            }

            public function saveConfig(SnmpOlt $olt): array
            {
                $this->saves++;

                return $this->saveOk
                    ? ['ok' => true, 'error' => null, 'output' => '[OK]']
                    : ['ok' => false, 'error' => 'Timeout menunggu prompt', 'output' => ''];
            }
        };
        $this->app->instance(ZteCliProvisioningExecutor::class, $executor);

        return $executor;
    }

    private function makeOlt(array $overrides = []): SnmpOlt
    {
        return SnmpOlt::create(array_merge([
            'name' => 'BMKV-C300',
            'vendor' => 'ZTE C300',
            'ip' => '10.30.0.30',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'cli_transport' => 'telnet',
            'cli_port' => 23,
            'cli_username' => 'admin',
            'cli_password' => 'secret',
            'last_test_result' => [
                'port_onus' => [
                    '4_9' => [
                        'ok' => true,
                        'count' => 2,
                        'onus' => [
                            ['onu_id' => 1, 'serial_number' => 'ZTEGC9140803', 'type_name' => 'ALL-ONT', 'online' => false, 'phase_state' => 'LOS'],
                            ['onu_id' => 2, 'serial_number' => 'ZTEG22222222', 'type_name' => 'F660', 'online' => true, 'phase_state' => 'working'],
                        ],
                    ],
                ],
                'unconfigured_onus' => [
                    'ok' => true,
                    'count' => 2,
                    'onus' => [
                        ['serial_number' => self::NEW_SN, 'slot' => 4, 'port' => 9],
                        ['serial_number' => 'ZTEGC0000002', 'slot' => 4, 'port' => 10],
                    ],
                ],
            ],
        ], $overrides));
    }
}
