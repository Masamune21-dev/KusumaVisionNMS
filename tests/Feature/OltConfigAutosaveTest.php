<?php

namespace Tests\Feature;

use App\Models\AlarmEvent;
use App\Models\SnmpOlt;
use App\Models\User;
use App\Services\AlarmEvaluator;
use App\Services\CData\CDataCliWriteService;
use App\Services\Hioso\HiosoCliWriteService;
use App\Services\Hsgq\HsgqWebService;
use App\Services\ZteCliProvisioningExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Mockery;
use Tests\TestCase;

/**
 * Auto save config OLT per-OLT: saklar, penanda "diubah lewat NMS", dan perintah
 * `olts:autosave-config` (jalur perubahan tiap 5 menit + jalur harian `--all`).
 */
class OltConfigAutosaveTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<int> OLT yang sampai ke `saveConfig` family-nya */
    private array $saved = [];

    private function makeOlt(string $name, array $attributes = []): SnmpOlt
    {
        return SnmpOlt::create([
            'name' => $name,
            'vendor' => 'ZTE C320',
            'ip' => '10.41.9.'.(count(SnmpOlt::withoutGlobalScopes()->get()) + 10),
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'cli_transport' => 'telnet',
            'cli_username' => 'admin',
            'cli_password' => 'secret',
            ...$attributes,
        ]);
    }

    /** OLT ber-saklar nyala dengan perubahan belum tersimpan sejak `$dirtyMinutes`, terakhir `$changedMinutes`. */
    private function dirtyOlt(string $name, int $changedMinutes, ?int $dirtyMinutes = null, array $attributes = []): SnmpOlt
    {
        $olt = $this->makeOlt($name, ['config_autosave_enabled' => true, ...$attributes]);
        $olt->forceFill([
            'config_changed_at' => now()->subMinutes($changedMinutes),
            'config_dirty_since' => now()->subMinutes($dirtyMinutes ?? $changedMinutes),
        ])->save();

        return $olt;
    }

    private function fakeZteSave(array $result = ['ok' => true, 'output' => 'done', 'error' => null], ?callable $during = null): void
    {
        $mock = Mockery::mock(ZteCliProvisioningExecutor::class);
        $mock->shouldReceive('saveConfig')->andReturnUsing(function (SnmpOlt $olt) use ($result, $during) {
            $this->saved[] = $olt->id;
            if ($during) {
                $during($olt);
            }

            return $result;
        });
        $this->app->instance(ZteCliProvisioningExecutor::class, $mock);
    }

    public function test_toggle_turns_autosave_on_and_off(): void
    {
        $olt = $this->makeOlt('OLT-UJI-TOGGLE');
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('smartolt.config.autosave', $olt))->assertSessionHas('success');
        $this->assertTrue($olt->fresh()->config_autosave_enabled);

        $this->actingAs($admin)->post(route('smartolt.config.autosave', $olt))->assertSessionHas('success');
        $this->assertFalse($olt->fresh()->config_autosave_enabled);
    }

    public function test_toggle_refuses_olt_that_nms_cannot_save(): void
    {
        $olt = $this->makeOlt('OLT-UJI-SSH', ['cli_transport' => 'ssh']);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('smartolt.config.autosave', $olt))
            ->assertSessionHas('error');

        $this->assertFalse($olt->fresh()->config_autosave_enabled);
    }

    public function test_toggle_is_refused_for_demo_viewer(): void
    {
        // Demo melihat OLT demo tapi hanya-baca (BlockDemoWrites).
        $olt = $this->makeOlt('OLT-UJI-DEMO', ['is_demo' => true]);

        $this->actingAs(User::factory()->demo()->create())
            ->post(route('smartolt.config.autosave', $olt))
            ->assertForbidden();
        $this->assertFalse(SnmpOlt::withoutGlobalScopes()->find($olt->id)->config_autosave_enabled);
    }

    public function test_marker_records_change_but_keeps_oldest_unsaved_time(): void
    {
        Route::middleware(['web', 'auth', 'olt.config-changed'])
            ->post('/_uji/olt/{olt}/tulis/{status}', fn (SnmpOlt $olt, int $status) => response('ok', $status));

        $olt = $this->makeOlt('OLT-UJI-PENANDA');
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->post("/_uji/olt/{$olt->id}/tulis/422")->assertStatus(422);
        $this->assertNull($olt->fresh()->config_dirty_since, 'aksi yang ditolak tidak menandai');

        $this->travelTo(now()->startOfMinute());
        $this->actingAs($user)->post("/_uji/olt/{$olt->id}/tulis/302")->assertStatus(302);
        $first = $olt->fresh();
        $this->assertTrue($first->hasUnsavedConfig());

        $this->travel(3)->minutes();
        $this->actingAs($user)->post("/_uji/olt/{$olt->id}/tulis/200")->assertOk();
        $second = $olt->fresh();
        $this->assertTrue($second->config_changed_at->gt($first->config_changed_at));
        $this->assertTrue($second->config_dirty_since->eq($first->config_dirty_since));
    }

    public function test_write_routes_carry_marker_and_port_shutdown_does_not(): void
    {
        $marked = [
            'smartolt.register.store', 'smartolt.onu.delete', 'smartolt.onu.info', 'smartolt.onu.state',
            'smartolt.onu.configure.item', 'smartolt.port-onus.copy', 'smartolt.tr069-bulk',
            'cdata-olt.onu.info', 'cdata-olt.onu.delete', 'cdata-olt.vlans.store',
            'hioso-olt.onu.info', 'hioso-olt.onu.delete', 'hsgq-olt.onu.info',
            'api.olts.register', 'api.olts.onu.name', 'api.olts.onu.delete',
        ];
        foreach ($marked as $name) {
            $this->assertContains('olt.config-changed', Route::getRoutes()->getByName($name)->gatherMiddleware(), $name);
        }

        // "Matikan Port" sengaja tanpa `write` (port menyala lagi bila OLT reboot) — jangan ditandai.
        foreach (['smartolt.port.admin-state', 'smartolt.onu.reboot'] as $name) {
            $this->assertNotContains('olt.config-changed', Route::getRoutes()->getByName($name)->gatherMiddleware(), $name);
        }
    }

    public function test_change_path_saves_only_settled_or_overdue_olts(): void
    {
        $this->fakeZteSave();

        $settled = $this->dirtyOlt('OLT-UJI-TENANG', changedMinutes: 11);
        $busy = $this->dirtyOlt('OLT-UJI-SIBUK', changedMinutes: 2);
        $overdue = $this->dirtyOlt('OLT-UJI-LAMA', changedMinutes: 1, dirtyMinutes: 61);
        $clean = $this->makeOlt('OLT-UJI-BERSIH', ['config_autosave_enabled' => true]);
        $off = $this->dirtyOlt('OLT-UJI-MATI', changedMinutes: 30, attributes: ['config_autosave_enabled' => false]);

        $this->artisan('olts:autosave-config')->assertSuccessful();

        $this->assertEqualsCanonicalizing([$settled->id, $overdue->id], $this->saved);

        $settled->refresh();
        $this->assertFalse($settled->hasUnsavedConfig());
        $this->assertNotNull($settled->config_saved_at);
        $this->assertSame(SnmpOlt::AUTOSAVE_OK, $settled->config_autosave_status);
        $this->assertTrue($busy->fresh()->hasUnsavedConfig());
        $this->assertNull($clean->fresh()->config_autosave_status);
        $this->assertTrue($off->fresh()->hasUnsavedConfig());
    }

    public function test_daily_path_saves_every_enabled_olt_without_changes(): void
    {
        $this->fakeZteSave();

        $clean = $this->makeOlt('OLT-UJI-HARIAN', ['config_autosave_enabled' => true]);
        $busy = $this->dirtyOlt('OLT-UJI-HARIAN-SIBUK', changedMinutes: 1);
        $this->makeOlt('OLT-UJI-HARIAN-MATI');
        $this->makeOlt('OLT-UJI-HARIAN-SSH', ['config_autosave_enabled' => true, 'cli_transport' => 'ssh']);

        $this->artisan('olts:autosave-config', ['--all' => true])->assertSuccessful();

        $this->assertEqualsCanonicalizing([$clean->id, $busy->id], $this->saved);
    }

    public function test_failed_save_is_recorded_and_retried_after_backoff(): void
    {
        $this->fakeZteSave(['ok' => false, 'output' => '', 'error' => '%Error 20203: telnet timeout']);
        $olt = $this->dirtyOlt('OLT-UJI-GAGAL', changedMinutes: 15);

        $this->artisan('olts:autosave-config')->assertSuccessful();
        $olt->refresh();
        $this->assertSame(SnmpOlt::AUTOSAVE_FAILED, $olt->config_autosave_status);
        $this->assertStringContainsString('telnet timeout', $olt->config_autosave_error);
        $this->assertTrue($olt->hasUnsavedConfig());

        $this->artisan('olts:autosave-config')->assertSuccessful();
        $this->assertCount(1, $this->saved, 'jangan menghantam OLT yang baru gagal tiap 5 menit');

        $this->travel(31)->minutes();
        $this->artisan('olts:autosave-config')->assertSuccessful();
        $this->assertCount(2, $this->saved);
    }

    public function test_open_port_shutdown_defers_without_touching_olt(): void
    {
        $this->fakeZteSave();
        $olt = $this->dirtyOlt('OLT-UJI-PORT-MATI', changedMinutes: 20);
        AlarmEvent::create([
            'snmp_olt_id' => $olt->id,
            'signature' => AlarmEvaluator::portDisabledSignature(1, 3),
            'type' => AlarmEvent::TYPE_PORT_DISABLED,
            'severity' => 'major',
            'status' => AlarmEvent::STATUS_ACTIVE,
            'scope' => 'port',
            'message' => 'Port PON 1/3 dimatikan admin',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->artisan('olts:autosave-config', ['--all' => true])->assertSuccessful();

        $this->assertSame([], $this->saved);
        $olt->refresh();
        $this->assertSame(SnmpOlt::AUTOSAVE_DEFERRED, $olt->config_autosave_status);
        $this->assertTrue($olt->hasUnsavedConfig());
    }

    public function test_change_arriving_during_save_keeps_olt_dirty(): void
    {
        $this->fakeZteSave(during: function (SnmpOlt $olt) {
            $this->travel(2)->seconds();
            SnmpOlt::markConfigChanged($olt->id);
        });
        $olt = $this->dirtyOlt('OLT-UJI-BALAPAN', changedMinutes: 12);

        $this->artisan('olts:autosave-config')->assertSuccessful();

        $olt->refresh();
        $this->assertSame(SnmpOlt::AUTOSAVE_OK, $olt->config_autosave_status);
        $this->assertTrue($olt->hasUnsavedConfig(), 'perubahan setelah save dimulai belum tentu ikut tersimpan');
    }

    public function test_each_family_uses_its_own_save_command(): void
    {
        $calls = [];
        foreach ([CDataCliWriteService::class, HiosoCliWriteService::class, HsgqWebService::class] as $class) {
            $mock = Mockery::mock($class);
            $mock->shouldReceive('saveConfig')->once()->andReturnUsing(function () use (&$calls, $class) {
                $calls[] = $class;

                return ['ok' => true, 'output' => 'done', 'error' => null];
            });
            $this->app->instance($class, $mock);
        }

        $this->dirtyOlt('OLT-UJI-CDATA', changedMinutes: 15, attributes: ['vendor' => 'C-Data EPON 17409']);
        $this->dirtyOlt('OLT-UJI-HIOSO', changedMinutes: 15, attributes: ['vendor' => 'HiOSO EPON 25355']);
        $this->dirtyOlt('OLT-UJI-HSGQ', changedMinutes: 15, attributes: ['vendor' => 'HSGQ 50224', 'web_port' => 8080]);

        $this->artisan('olts:autosave-config')->assertSuccessful();

        $this->assertEqualsCanonicalizing([CDataCliWriteService::class, HiosoCliWriteService::class, HsgqWebService::class], $calls);
    }

    public function test_manual_save_marks_olt_clean(): void
    {
        $this->fakeZteSave();
        $olt = $this->dirtyOlt('OLT-UJI-MANUAL', changedMinutes: 1);

        $this->travel(1)->seconds();
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('smartolt.config.save', $olt))
            ->assertSessionHas('success');

        $olt->refresh();
        $this->assertFalse($olt->hasUnsavedConfig());
        $this->assertNotNull($olt->config_saved_at);
    }

    public function test_olt_list_exposes_autosave_state(): void
    {
        $this->dirtyOlt('OLT-UJI-DAFTAR', changedMinutes: 5);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('smartolt.index'))
            ->assertInertia(fn ($page) => $page
                ->where('olts.0.config_autosave.enabled', true)
                ->where('olts.0.config_autosave.last_status', null)
                ->whereType('olts.0.config_autosave.unsaved_since', 'string'));
    }
}
