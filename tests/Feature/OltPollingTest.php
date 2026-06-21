<?php

namespace Tests\Feature;

use App\Jobs\PollOltJob;
use App\Models\OnuRxSample;
use App\Models\SnmpOlt;
use App\Services\AlarmEvaluator;
use App\Services\Snmp\OltSnmpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
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

    private function fakeClient(array $rxPowers = [], ?string $rxError = null): OltSnmpClient
    {
        return new class($rxPowers, $rxError) extends OltSnmpClient
        {
            public function __construct(private readonly array $rxPowers, private readonly ?string $rxError) {}

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

            public function registeredOnus(SnmpOlt $olt, ?array $ports = null, ?string $scope = null): array
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

            public function onuRxPowers(SnmpOlt $olt, ?string $scope = null): array
            {
                if ($this->rxError !== null) {
                    throw new RuntimeException($this->rxError);
                }

                return $this->rxPowers;
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

    public function test_poll_command_skips_cdata_olts_even_when_enabled(): void
    {
        Queue::fake();

        $zte = $this->makeOlt(['polling_enabled' => true]);
        $cdata = $this->makeOlt(['polling_enabled' => true, 'vendor' => 'C-Data EPON 17409', 'name' => 'CDATA-EPON']);

        $this->artisan('olts:poll')->assertSuccessful();

        // Hanya OLT ZTE yang di-dispatch; C-Data di-refresh saat halaman dibuka, bukan via poller.
        Queue::assertPushed(PollOltJob::class, 1);
        Queue::assertPushed(fn (PollOltJob $job) => $job->oltId === $zte->id);
        Queue::assertNotPushed(fn (PollOltJob $job) => $job->oltId === $cdata->id);
    }

    public function test_poll_job_skips_cdata_olt(): void
    {
        $olt = $this->makeOlt(['polling_enabled' => true, 'vendor' => 'C-Data EPON 17409', 'name' => 'CDATA-EPON']);

        (new PollOltJob($olt->id))->handle($this->fakeClient(), new AlarmEvaluator);

        $olt->refresh();

        $this->assertNull($olt->last_polled_at);
        $this->assertNull($olt->last_test_result);
    }

    public function test_poll_command_skips_enabled_olts_that_are_not_due(): void
    {
        Queue::fake();

        $due = $this->makeOlt([
            'polling_enabled' => true,
            'poll_interval_minutes' => 15,
            'last_polled_at' => now()->subMinutes(16),
        ]);
        $notDue = $this->makeOlt([
            'polling_enabled' => true,
            'poll_interval_minutes' => 15,
            'last_polled_at' => now()->subMinutes(5),
        ]);

        $this->artisan('olts:poll')->assertSuccessful();

        Queue::assertPushed(PollOltJob::class, 1);
        Queue::assertPushed(fn (PollOltJob $job) => $job->oltId === $due->id);
        Queue::assertNotPushed(fn (PollOltJob $job) => $job->oltId === $notDue->id);
    }

    public function test_poll_job_populates_snapshot_and_onu_buckets(): void
    {
        $olt = $this->makeOlt(['polling_enabled' => true]);

        (new PollOltJob($olt->id))->handle($this->fakeClient(), new AlarmEvaluator);

        $olt->refresh();

        $this->assertNotNull($olt->last_polled_at);
        $this->assertTrue((bool) data_get($olt->last_test_result, 'ok'));
        $this->assertCount(1, data_get($olt->last_test_result, 'ports'));
        $this->assertCount(1, data_get($olt->last_test_result, 'port_onus.1_1.onus'));
        $this->assertSame(1, data_get($olt->last_test_result, 'port_onus.1_1.count'));
        $this->assertTrue((bool) data_get($olt->last_test_result, 'port_onus.1_1.onus.0.online'));
    }

    public function test_poll_job_skips_queued_job_when_olt_is_no_longer_due(): void
    {
        $lastPolledAt = now()->subMinute();
        $olt = $this->makeOlt([
            'polling_enabled' => true,
            'poll_interval_minutes' => 5,
            'last_polled_at' => $lastPolledAt,
        ]);

        $client = new class extends OltSnmpClient
        {
            public function snapshot(SnmpOlt $olt): array
            {
                throw new RuntimeException('Poll should have been skipped.');
            }
        };

        (new PollOltJob($olt->id))->handle($client, new AlarmEvaluator);

        $olt->refresh();

        $this->assertSame($lastPolledAt->timestamp, $olt->last_polled_at->timestamp);
        $this->assertNull($olt->last_test_result);
    }

    public function test_poll_job_preserves_existing_rx_power_when_snmp_rx_walk_fails(): void
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

        (new PollOltJob($olt->id))->handle($this->fakeClient(rxError: 'SNMP walk failed'), new AlarmEvaluator);

        $olt->refresh();

        $this->assertSame(-18.5, data_get($olt->last_test_result, 'port_onus.1_1.onus.0.rx_power_dbm'));
        $this->assertSame('-18.500 dBm', data_get($olt->last_test_result, 'port_onus.1_1.onus.0.rx_power_label'));
    }

    public function test_poll_job_updates_rx_power_from_snmp(): void
    {
        $olt = $this->makeOlt(['polling_enabled' => true]);

        (new PollOltJob($olt->id))->handle($this->fakeClient([
            '268501760.1' => [
                'if_index' => 268501760,
                'onu_id' => 1,
                'rx_power_port' => 1,
                'raw_rx_power' => 5635,
                'rx_power_dbm' => -18.73,
                'rx_power_label' => '-18.730 dBm',
                'rx_power_source' => 'snmp_onu_rx',
            ],
        ]), new AlarmEvaluator);

        $olt->refresh();

        $this->assertSame(-18.73, data_get($olt->last_test_result, 'port_onus.1_1.onus.0.rx_power_dbm'));
        $this->assertSame('-18.730 dBm', data_get($olt->last_test_result, 'port_onus.1_1.onus.0.rx_power_label'));
        $this->assertSame('snmp_onu_rx', data_get($olt->last_test_result, 'port_onus.1_1.onus.0.rx_power_source'));
        $this->assertSame(1, data_get($olt->last_test_result, 'port_onus.1_1.rx_power.count'));
        $this->assertNotNull($olt->last_rx_polled_at);
    }

    public function test_poll_job_preserves_existing_rx_power_when_rx_interval_is_not_due(): void
    {
        $lastRxPolledAt = now()->subMinutes(5);
        $olt = $this->makeOlt([
            'polling_enabled' => true,
            'rx_poll_interval_minutes' => 15,
            'last_rx_polled_at' => $lastRxPolledAt,
            'last_test_result' => [
                'port_onus' => [
                    '1_1' => [
                        'rx_power' => [
                            'ok' => true,
                            'source' => 'snmp',
                            'count' => 1,
                            'error' => null,
                            'polled_at' => $lastRxPolledAt->toIso8601String(),
                        ],
                        'onus' => [
                            [
                                'onu_id' => 1,
                                'rx_power_dbm' => -18.5,
                                'rx_power_label' => '-18.500 dBm',
                                'rx_power_source' => 'snmp_onu_rx',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        (new PollOltJob($olt->id))->handle($this->fakeClient(rxError: 'RX should not be polled'), new AlarmEvaluator);

        $olt->refresh();

        $this->assertSame(-18.5, data_get($olt->last_test_result, 'port_onus.1_1.onus.0.rx_power_dbm'));
        $this->assertSame('snmp_onu_rx', data_get($olt->last_test_result, 'port_onus.1_1.onus.0.rx_power_source'));
        $this->assertSame($lastRxPolledAt->toIso8601String(), data_get($olt->last_test_result, 'port_onus.1_1.rx_power.polled_at'));
        $this->assertSame($lastRxPolledAt->timestamp, $olt->last_rx_polled_at->timestamp);
    }

    public function test_poll_job_clears_stale_rx_power_when_snmp_rx_has_no_value(): void
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

        (new PollOltJob($olt->id))->handle($this->fakeClient(), new AlarmEvaluator);

        $olt->refresh();

        $this->assertNull(data_get($olt->last_test_result, 'port_onus.1_1.onus.0.rx_power_dbm'));
        $this->assertSame(0, data_get($olt->last_test_result, 'port_onus.1_1.rx_power.count'));
    }

    public function test_poll_job_skips_disabled_olt(): void
    {
        $olt = $this->makeOlt(['polling_enabled' => false]);

        (new PollOltJob($olt->id))->handle($this->fakeClient(), new AlarmEvaluator);

        $olt->refresh();

        $this->assertNull($olt->last_polled_at);
        $this->assertNull($olt->last_test_result);
    }

    public function test_poll_job_records_rx_power_samples_when_rx_poll_succeeds(): void
    {
        $olt = $this->makeOlt(['polling_enabled' => true]);

        (new PollOltJob($olt->id))->handle($this->fakeClient([
            '268501760.1' => [
                'if_index' => 268501760,
                'onu_id' => 1,
                'rx_power_port' => 1,
                'raw_rx_power' => 5635,
                'rx_power_dbm' => -18.73,
                'rx_power_label' => '-18.730 dBm',
                'rx_power_source' => 'snmp_onu_rx',
            ],
        ]), new AlarmEvaluator);

        $this->assertDatabaseCount('onu_rx_samples', 1);

        $sample = OnuRxSample::first();
        $this->assertSame($olt->id, $sample->snmp_olt_id);
        $this->assertSame(1, $sample->slot);
        $this->assertSame(1, $sample->port);
        $this->assertSame(1, $sample->onu_id);
        $this->assertSame('ZTEG1', $sample->serial_number);
        $this->assertEqualsWithDelta(-18.73, $sample->rx_power_dbm, 0.001);
        $this->assertNotNull($sample->polled_at);
    }

    public function test_poll_job_does_not_record_rx_samples_when_rx_walk_fails(): void
    {
        $olt = $this->makeOlt(['polling_enabled' => true]);

        (new PollOltJob($olt->id))->handle($this->fakeClient(rxError: 'SNMP walk failed'), new AlarmEvaluator);

        $this->assertDatabaseCount('onu_rx_samples', 0);
    }
}
