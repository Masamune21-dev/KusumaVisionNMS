<?php

namespace Tests\Feature;

use App\Jobs\BackupOltConfigJob;
use App\Models\OltConfigBackup;
use App\Models\SnmpOlt;
use App\Models\User;
use App\Services\Zte\OltConfigBackupService;
use App\Services\ZteCliProvisioningExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

class OltConfigBackupTest extends TestCase
{
    use RefreshDatabase;

    private const CONFIG_A = "!<running-config>\ninterface gpon-olt_1/1/1\n onu 1 type ZTE-F660 sn ZTEGAAAA0001\n exit\n!<end>";

    private const CONFIG_B = "!<running-config>\ninterface gpon-olt_1/1/1\n onu 1 type ZTE-F660 sn ZTEGAAAA0001\n onu 2 type ZTE-F660 sn ZTEGAAAA0002\n exit\n!<end>";

    private function makeOlt(array $over = []): SnmpOlt
    {
        return SnmpOlt::create(array_merge([
            'name' => 'PATI-ZTE-C320',
            'vendor' => 'ZTE C320',
            'ip' => '10.40.0.'.random_int(2, 250),
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'cli_username' => 'admin',
            'cli_password' => 'secret',
        ], $over));
    }

    private function mockExecutor(array $return): void
    {
        $mock = Mockery::mock(ZteCliProvisioningExecutor::class);
        $mock->shouldReceive('execute')->andReturn($return);
        $this->app->instance(ZteCliProvisioningExecutor::class, $mock);
    }

    public function test_capture_creates_backup_version(): void
    {
        $this->mockExecutor(['ok' => true, 'output' => self::CONFIG_A, 'error' => null]);
        $olt = $this->makeOlt();

        $result = app(OltConfigBackupService::class)->capture($olt, OltConfigBackup::TRIGGER_MANUAL);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['changed']);
        $this->assertDatabaseCount('olt_config_backups', 1);

        $backup = OltConfigBackup::first();
        $this->assertSame('ok', $backup->status);
        $this->assertNotNull($backup->sha256);
        $this->assertStringContainsString('ZTE-F660', (string) $backup->content);
    }

    public function test_identical_config_is_deduplicated(): void
    {
        $this->mockExecutor(['ok' => true, 'output' => self::CONFIG_A, 'error' => null]);
        $olt = $this->makeOlt();
        $service = app(OltConfigBackupService::class);

        $service->capture($olt);
        $second = $service->capture($olt);

        $this->assertTrue($second['ok']);
        $this->assertFalse($second['changed'], 'Config identik tak boleh membuat versi baru');
        $this->assertDatabaseCount('olt_config_backups', 1);
    }

    public function test_changed_config_creates_new_version(): void
    {
        $olt = $this->makeOlt();

        $this->mockExecutor(['ok' => true, 'output' => self::CONFIG_A, 'error' => null]);
        app(OltConfigBackupService::class)->capture($olt);

        $this->mockExecutor(['ok' => true, 'output' => self::CONFIG_B, 'error' => null]);
        $result = app(OltConfigBackupService::class)->capture($olt);

        $this->assertTrue($result['changed']);
        $this->assertDatabaseCount('olt_config_backups', 2);
    }

    public function test_failed_capture_records_failed_row(): void
    {
        $this->mockExecutor(['ok' => false, 'output' => '', 'error' => 'telnet timeout']);
        $olt = $this->makeOlt();

        $result = app(OltConfigBackupService::class)->capture($olt);

        $this->assertFalse($result['ok']);
        $this->assertDatabaseHas('olt_config_backups', ['snmp_olt_id' => $olt->id, 'status' => 'failed']);
        $this->assertNull(OltConfigBackup::first()->content);
    }

    public function test_non_zte_olt_is_rejected(): void
    {
        $olt = $this->makeOlt(['name' => 'FD1608S', 'vendor' => 'C-Data GPON']);

        $result = app(OltConfigBackupService::class)->capture($olt);

        $this->assertFalse($result['ok']);
        $this->assertDatabaseCount('olt_config_backups', 0);
    }

    public function test_manual_backup_route_stores_version(): void
    {
        $this->mockExecutor(['ok' => true, 'output' => self::CONFIG_A, 'error' => null]);
        $olt = $this->makeOlt();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('smartolt.config-backups.store', $olt->id))
            ->assertRedirect();

        $this->assertDatabaseCount('olt_config_backups', 1);
    }

    public function test_toggle_flips_daily_backup_flag(): void
    {
        $olt = $this->makeOlt(['config_backup_enabled' => false]);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('smartolt.config-backups.toggle', $olt->id))->assertRedirect();

        $this->assertTrue($olt->fresh()->config_backup_enabled);
    }

    public function test_content_and_download_are_scoped_to_owning_olt(): void
    {
        $oltA = $this->makeOlt();
        $oltB = $this->makeOlt();
        $user = User::factory()->create();

        $backup = OltConfigBackup::create([
            'snmp_olt_id' => $oltA->id,
            'content' => self::CONFIG_A,
            'size_bytes' => strlen(self::CONFIG_A),
            'sha256' => hash('sha256', self::CONFIG_A),
            'trigger' => 'manual',
            'status' => 'ok',
            'captured_at' => now(),
        ]);

        // Backup milik OLT A tak boleh diakses lewat rute OLT B.
        $this->actingAs($user)
            ->get(route('smartolt.config-backups.content', [$oltB->id, $backup->id]))
            ->assertNotFound();

        $this->actingAs($user)
            ->get(route('smartolt.config-backups.content', [$oltA->id, $backup->id]))
            ->assertOk()
            ->assertJsonPath('content', self::CONFIG_A);
    }

    public function test_command_dispatches_jobs_for_enabled_zte_olts_only(): void
    {
        Bus::fake();

        $enabledZte = $this->makeOlt(['config_backup_enabled' => true]);
        $this->makeOlt(['config_backup_enabled' => false]);                                   // ZTE, off
        $this->makeOlt(['config_backup_enabled' => true, 'name' => 'FD1608S', 'vendor' => 'C-Data']); // non-ZTE, on

        $this->artisan('olts:backup-config')->assertSuccessful();

        Bus::assertDispatchedTimes(BackupOltConfigJob::class, 1);
        Bus::assertDispatched(BackupOltConfigJob::class, fn (BackupOltConfigJob $job) => $job->oltId === $enabledZte->id);
    }

    public function test_index_page_renders(): void
    {
        $olt = $this->makeOlt();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('smartolt.config-backups.index', $olt->id))
            ->assertOk();
    }
}
