<?php

namespace Tests\Feature;

use App\Jobs\Tr069BulkConfigJob;
use App\Models\SnmpOlt;
use App\Models\Tr069BulkTask;
use App\Models\User;
use App\Services\ZteCliProvisioningExecutor;
use App\Services\ZteTr069BulkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SmartOltTr069BulkTest extends TestCase
{
    use RefreshDatabase;

    public function test_endpoint_queues_a_task_with_cached_onu_total(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $olt = $this->makeOlt();

        $response = $this->actingAs($user)->postJson(
            route('smartolt.tr069-bulk', $olt),
            ['execute' => false],
        );

        $response->assertOk()->assertJson(['ok' => true]);

        $task = Tr069BulkTask::firstOrFail();
        $this->assertSame('queued', $task->status);
        $this->assertFalse($task->execute);
        $this->assertSame(3, $task->total); // 5,6 di 2/3 + 1 di 2/4

        Queue::assertPushed(Tr069BulkConfigJob::class, fn (Tr069BulkConfigJob $job) => $job->taskId === $task->id);
    }

    public function test_dry_run_reports_intent_without_writing(): void
    {
        $olt = $this->makeOlt(['ip' => '10.31.0.11']);
        $fake = $this->fakeExecutor(activeOnuIds: []); // semua ONU belum aktif TR069

        $task = $this->makeTask($olt, execute: false);
        (new Tr069BulkConfigJob($task->id))->handle(app(ZteTr069BulkService::class));

        $task->refresh();
        $this->assertSame('completed', $task->status);
        $this->assertSame(3, $task->applied_count); // semua "akan diaktifkan"
        $this->assertSame(0, $task->skipped_count);
        $this->assertSame(0, $task->failed_count);
        $this->assertSame(3, $task->processed);
        $this->assertCount(3, $task->items);
        $this->assertSame('would-apply', $task->items[0]['status']);

        // Dry-run tidak boleh menulis (tidak ada session "conf t"/pon-onu-mng).
        $this->assertSame([], $fake->writes);
    }

    public function test_execute_skips_already_active_and_writes_the_rest(): void
    {
        $olt = $this->makeOlt(['ip' => '10.31.0.12']);
        $fake = $this->fakeExecutor(activeOnuIds: [5]); // ONU 5 sudah aktif → skip

        $task = $this->makeTask($olt, execute: true);
        (new Tr069BulkConfigJob($task->id))->handle(app(ZteTr069BulkService::class));

        $task->refresh();
        $this->assertSame('completed', $task->status);
        $this->assertSame(1, $task->skipped_count);
        $this->assertSame(2, $task->applied_count); // ONU 6 dan 1
        $this->assertSame(0, $task->failed_count);

        // Dua session tulis (port 2/3 untuk ONU 6, port 2/4 untuk ONU 1).
        $this->assertCount(2, $fake->writes);
        $joined = implode("\n", $fake->writes);
        $this->assertStringContainsString('pon-onu-mng gpon-onu_1/2/3:6', $joined);
        $this->assertStringContainsString('pon-onu-mng gpon-onu_1/2/4:1', $joined);
        $this->assertStringContainsString('tr069-mgmt 1 acs http://acs.bmkv.net:7547 validate basic username cms password kusuma123!', $joined);
        // ONU 5 yang sudah aktif tidak ikut ditulis.
        $this->assertStringNotContainsString(':5', $joined);
    }

    public function test_status_endpoint_returns_progress(): void
    {
        $user = User::factory()->create();
        $olt = $this->makeOlt(['ip' => '10.31.0.13']);
        $task = Tr069BulkTask::create([
            'snmp_olt_id' => $olt->id,
            'created_by' => $user->id,
            'execute' => true,
            'total' => 3,
            'processed' => 2,
            'applied_count' => 1,
            'skipped_count' => 1,
            'status' => 'running',
        ]);

        $this->actingAs($user)
            ->getJson(route('smartolt.tr069-bulk.status', [$olt, $task]))
            ->assertOk()
            ->assertJson(['status' => 'running', 'total' => 3, 'processed' => 2, 'applied' => 1, 'skipped' => 1, 'finished' => false]);
    }

    private function makeTask(SnmpOlt $olt, bool $execute): Tr069BulkTask
    {
        return Tr069BulkTask::create([
            'snmp_olt_id' => $olt->id,
            'execute' => $execute,
            'total' => 3,
            'status' => 'queued',
        ]);
    }

    /**
     * Executor palsu: untuk read mengembalikan running-config per interface (ONU
     * pada $activeOnuIds dibubuhi TR069 aktif ke ACS target); untuk write merekam
     * script-nya ke $writes.
     *
     * @param  array<int, int>  $activeOnuIds
     */
    private function fakeExecutor(array $activeOnuIds): object
    {
        $fake = new class($activeOnuIds) extends ZteCliProvisioningExecutor
        {
            /** @var array<int, string> */
            public array $writes = [];

            /** @param array<int, int> $activeOnuIds */
            public function __construct(private array $activeOnuIds) {}

            public function execute(SnmpOlt $olt, string $script): array
            {
                if (str_contains($script, 'show running-config interface')) {
                    return ['ok' => true, 'error' => null, 'output' => $this->readOutput($script)];
                }

                if (str_contains($script, 'tr069-mgmt 1 state unlock')) {
                    $this->writes[] = $script;
                }

                return ['ok' => true, 'error' => null, 'output' => 'done'];
            }

            private function readOutput(string $script): string
            {
                $out = '';
                foreach (preg_split('/\r?\n/', $script) ?: [] as $cmd) {
                    $out .= "> {$cmd}\n";
                    if (preg_match('/show running-config interface (gpon-onu_\S+)/', $cmd, $m)) {
                        $out .= "interface {$m[1]}\n  name Pelanggan\n  tcont 1 name 1 profile SERVER\n"
                            ."  gemport 1 name 1 tcont 1\n  service-port 1 vport 1 user-vlan 100 vlan 100\n!\nend\n";
                    } elseif (preg_match('/show onu running config (gpon-onu_\S+:(\d+))/', $cmd, $m)) {
                        $out .= "pon-onu-mng {$m[1]}\n  service ServiceName gemport 1 cos 0 vlan 100\n";
                        if (in_array((int) $m[2], $this->activeOnuIds, true)) {
                            $out .= "  tr069-mgmt 1 state unlock\n"
                                ."  tr069-mgmt 1 acs http://acs.bmkv.net:7547 validate basic username cms password kusuma123!\n";
                        }
                        $out .= "  wan-ip 1 mode dhcp host 1\n";
                    }
                }

                return $out;
            }
        };

        $this->app->instance(ZteCliProvisioningExecutor::class, $fake);

        return $fake;
    }

    private function makeOlt(array $overrides = []): SnmpOlt
    {
        return SnmpOlt::create(array_merge([
            'name' => 'BMKV-C300',
            'vendor' => 'ZTE C300',
            'ip' => '10.31.0.10',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'cli_transport' => 'telnet',
            'cli_port' => 23,
            'cli_username' => 'admin',
            'cli_password' => 'secret',
            'last_test_result' => [
                'port_onus' => [
                    '2_3' => [
                        'ok' => true,
                        'onus' => [
                            ['onu_id' => 5, 'serial_number' => 'ZTEG11111111', 'type_name' => 'F660', 'name' => 'Pelanggan A'],
                            ['onu_id' => 6, 'serial_number' => 'ZTEG22222222', 'type_name' => 'F660', 'name' => 'Pelanggan B'],
                        ],
                    ],
                    '2_4' => [
                        'ok' => true,
                        'onus' => [
                            ['onu_id' => 1, 'serial_number' => 'ZTEG99999999', 'type_name' => 'F660', 'name' => 'Existing'],
                        ],
                    ],
                ],
            ],
        ], $overrides));
    }
}
