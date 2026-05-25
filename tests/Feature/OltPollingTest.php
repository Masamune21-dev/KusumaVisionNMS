<?php

namespace Tests\Feature;

use App\Jobs\PollOltJob;
use App\Models\SnmpOlt;
use App\Services\AlarmEvaluator;
use App\Services\Snmp\OltSnmpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OltPollingTest extends TestCase
{
    use RefreshDatabase;

    private function makeOlt(array $overrides = []): SnmpOlt
    {
        return SnmpOlt::create(array_merge([
            'name' => 'PATI-ZTE-C320',
            'vendor' => 'ZTE C320',
            'ip' => '10.20.0.'.random_int(2, 250),
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
        ], $overrides));
    }

    private function fakeClient(): OltSnmpClient
    {
        return new class extends OltSnmpClient
        {
            public function snapshot(SnmpOlt $olt): array
            {
                return [
                    'ok' => true,
                    'driver' => 'zte',
                    'latency_ms' => 12,
                    'system' => ['sys_name' => 'PAT-C320'],
                    'ports' => [
                        ['if_index' => 268501760, 'name' => 'gpon-olt_1/1/1', 'slot' => 1, 'port' => 1, 'oper_status_code' => 1, 'oper_status' => 'up'],
                    ],
                    'error' => null,
                ];
            }

            public function registeredOnus(SnmpOlt $olt, ?array $ports = null): array
            {
                return [
                    [
                        'if_index' => 268501760, 'onu_id' => 1, 'slot' => 1, 'port' => 1,
                        'interface' => 'gpon-onu_1/1/1:1', 'type_name' => 'F660', 'name' => 'A',
                        'description' => '', 'serial_number' => 'ZTEG1',
                        'admin_state_code' => 1, 'admin_state' => 'active',
                        'phase_state_code' => 3, 'phase_state' => 'Working', 'online' => true,
                        'last_down_cause_code' => 0, 'last_down_cause' => 'Normal',
                    ],
                ];
            }
        };
    }

    public function test_poll_command_dispatches_one_job_per_polling_enabled_olt(): void
    {
        Queue::fake();

        $enabledA = $this->makeOlt(['polling_enabled' => true]);
        $enabledB = $this->makeOlt(['polling_enabled' => true]);
        $disabled = $this->makeOlt(['polling_enabled' => false]);

        $this->artisan('olts:poll')->assertSuccessful();

        Queue::assertPushed(PollOltJob::class, 2);
        Queue::assertPushed(fn (PollOltJob $job) => $job->oltId === $enabledA->id);
        Queue::assertPushed(fn (PollOltJob $job) => $job->oltId === $enabledB->id);
        Queue::assertNotPushed(fn (PollOltJob $job) => $job->oltId === $disabled->id);
    }

    public function test_poll_job_populates_snapshot_and_onu_buckets(): void
    {
        $olt = $this->makeOlt(['polling_enabled' => true]);

        (new PollOltJob($olt->id))->handle($this->fakeClient(), new AlarmEvaluator());

        $olt->refresh();

        $this->assertNotNull($olt->last_polled_at);
        $this->assertTrue((bool) data_get($olt->last_test_result, 'ok'));
        $this->assertCount(1, data_get($olt->last_test_result, 'ports'));
        $this->assertCount(1, data_get($olt->last_test_result, 'port_onus.1_1.onus'));
        $this->assertSame(1, data_get($olt->last_test_result, 'port_onus.1_1.count'));
        $this->assertTrue((bool) data_get($olt->last_test_result, 'port_onus.1_1.onus.0.online'));
    }

    public function test_poll_job_preserves_existing_rx_power(): void
    {
        $olt = $this->makeOlt([
            'polling_enabled' => true,
            'last_test_result' => [
                'port_onus' => [
                    '1_1' => [
                        'onus' => [
                            ['onu_id' => 1, 'rx_power_dbm' => -18.5, 'rx_power_label' => '-18.500 dBm'],
                        ],
                    ],
                ],
            ],
        ]);

        (new PollOltJob($olt->id))->handle($this->fakeClient(), new AlarmEvaluator());

        $olt->refresh();

        $this->assertSame(-18.5, data_get($olt->last_test_result, 'port_onus.1_1.onus.0.rx_power_dbm'));
        $this->assertSame('-18.500 dBm', data_get($olt->last_test_result, 'port_onus.1_1.onus.0.rx_power_label'));
    }

    public function test_poll_job_skips_disabled_olt(): void
    {
        $olt = $this->makeOlt(['polling_enabled' => false]);

        (new PollOltJob($olt->id))->handle($this->fakeClient(), new AlarmEvaluator());

        $olt->refresh();

        $this->assertNull($olt->last_polled_at);
        $this->assertNull($olt->last_test_result);
    }
}
