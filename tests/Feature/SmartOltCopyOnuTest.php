<?php

namespace Tests\Feature;

use App\Jobs\CopyOnusToPortJob;
use App\Models\CopyOnuTask;
use App\Models\SmartOltOnuRegistration;
use App\Models\SnmpOlt;
use App\Models\User;
use App\Services\ZteCliProvisioningExecutor;
use App\Services\ZteOnuCopyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SmartOltCopyOnuTest extends TestCase
{
    use RefreshDatabase;

    public function test_copy_endpoint_queues_a_task_without_running_it(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $olt = $this->makeOlt();

        $response = $this->actingAs($user)->postJson(
            route('smartolt.port-onus.copy', [$olt, 2, 3]),
            ['onu_ids' => [5, 6], 'dst_slot' => 2, 'dst_port' => 4, 'execute' => true],
        );

        $response->assertOk()->assertJson(['ok' => true]);

        $task = CopyOnuTask::firstOrFail();
        $this->assertSame('queued', $task->status);
        $this->assertSame(2, $task->total);
        $this->assertSame([5, 6], $task->onu_ids);
        $this->assertSame(2, $task->dst_slot);
        $this->assertSame(4, $task->dst_port);
        $this->assertTrue($task->execute);

        Queue::assertPushed(CopyOnusToPortJob::class, fn (CopyOnusToPortJob $job) => $job->taskId === $task->id);
        $this->assertDatabaseCount('smartolt_onu_registrations', 0);
    }

    public function test_copy_to_same_port_is_rejected(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $olt = $this->makeOlt(['ip' => '10.30.0.21']);

        $response = $this->actingAs($user)->postJson(
            route('smartolt.port-onus.copy', [$olt, 2, 3]),
            ['onu_ids' => [5], 'dst_slot' => 2, 'dst_port' => 3],
        );

        $response->assertStatus(422)->assertJson(['ok' => false]);
        $this->assertDatabaseCount('copy_onu_tasks', 0);
        Queue::assertNothingPushed();
    }

    public function test_job_generates_registrations_on_target_port(): void
    {
        $user = User::factory()->create();
        $olt = $this->makeOlt(['ip' => '10.30.0.22']);
        $this->fakeRunningConfigReader();

        $task = CopyOnuTask::create([
            'snmp_olt_id' => $olt->id,
            'created_by' => $user->id,
            'src_slot' => 2,
            'src_port' => 3,
            'dst_slot' => 2,
            'dst_port' => 4,
            'execute' => false,
            'onu_ids' => [5, 6],
            'total' => 2,
            'status' => 'queued',
        ]);

        (new CopyOnusToPortJob($task->id))->handle(app(ZteOnuCopyService::class));

        $task->refresh();
        $this->assertSame('completed', $task->status);
        $this->assertSame(2, $task->created_count);
        $this->assertSame(2, $task->processed);
        $this->assertNotNull($task->finished_at);

        $rows = SmartOltOnuRegistration::query()
            ->where('snmp_olt_id', $olt->id)
            ->orderBy('onu_id')
            ->get();

        $this->assertCount(2, $rows);
        // Target port 2/4 already has onu-id 1 cached, so the batch claims 2 and 3.
        $this->assertSame([2, 3], $rows->pluck('onu_id')->all());

        $first = $rows->firstWhere('onu_id', 2);
        $this->assertSame(2, $first->slot);
        $this->assertSame(4, $first->port);
        $this->assertSame('generated', $first->status);
        $this->assertSame('ZTEG11111111', $first->serial_number);
        $this->assertSame('gpon-onu_1/2/4:2', $first->pon_port);
        $this->assertStringContainsString('interface gpon-olt_1/2/4', $first->cli_script);
        $this->assertStringContainsString('onu 2 type F660 sn ZTEG11111111', $first->cli_script);
    }

    public function test_copy_task_status_endpoint_returns_progress(): void
    {
        $user = User::factory()->create();
        $olt = $this->makeOlt(['ip' => '10.30.0.23']);
        $task = CopyOnuTask::create([
            'snmp_olt_id' => $olt->id,
            'created_by' => $user->id,
            'src_slot' => 2, 'src_port' => 3, 'dst_slot' => 2, 'dst_port' => 4,
            'execute' => true, 'onu_ids' => [5, 6], 'total' => 2,
            'processed' => 1, 'created_count' => 1, 'executed_count' => 1, 'status' => 'running',
        ]);

        $this->actingAs($user)
            ->getJson(route('smartolt.copy-task.status', [$olt, $task]))
            ->assertOk()
            ->assertJson(['status' => 'running', 'total' => 2, 'processed' => 1, 'executed' => 1, 'finished' => false]);
    }

    /**
     * Bind an executor that echoes a parseable running-config block per requested
     * interface, so {@see ZteOnuRunningConfigService::fetchMany()} can segment the
     * combined dump and produce real configs.
     */
    private function fakeRunningConfigReader(): void
    {
        $this->app->instance(ZteCliProvisioningExecutor::class, new class extends ZteCliProvisioningExecutor
        {
            public function execute(SnmpOlt $olt, string $script, bool $largeOutput = false): array
            {
                $out = '';
                foreach (preg_split('/\r?\n/', $script) ?: [] as $cmd) {
                    $out .= "> {$cmd}\n";
                    if (preg_match('/show running-config interface (gpon-onu_\S+)/', $cmd, $m)) {
                        $out .= "interface {$m[1]}\n  name Pelanggan\n  tcont 1 name 1 profile SERVER\n"
                            ."  gemport 1 name 1 tcont 1\n  service-port 1 vport 1 user-vlan 100 vlan 100\n!\nend\n";
                    } elseif (preg_match('/show onu running config (gpon-onu_\S+)/', $cmd, $m)) {
                        $out .= "pon-onu-mng {$m[1]}\n  service ServiceName gemport 1 cos 0 vlan 100\n  wan-ip 1 mode dhcp host 1\n";
                    }
                }

                return ['ok' => true, 'error' => null, 'output' => $out];
            }
        });
    }

    private function makeOlt(array $overrides = []): SnmpOlt
    {
        return SnmpOlt::create(array_merge([
            'name' => 'BMKV-C300',
            'vendor' => 'ZTE C300',
            'ip' => '10.30.0.20',
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
                            ['onu_id' => 5, 'serial_number' => 'ZTEG11111111', 'type_name' => 'F660', 'name' => 'Pelanggan A', 'if_index' => 123],
                            ['onu_id' => 6, 'serial_number' => 'ZTEG22222222', 'type_name' => 'F660', 'name' => 'Pelanggan B', 'if_index' => 124],
                        ],
                    ],
                    '2_4' => [
                        'ok' => true,
                        'onus' => [
                            ['onu_id' => 1, 'serial_number' => 'ZTEG99999999', 'type_name' => 'F660', 'name' => 'Existing', 'if_index' => 130],
                        ],
                    ],
                ],
            ],
        ], $overrides));
    }
}
