<?php

namespace Tests\Feature;

use App\Models\SmartOltFanStatus;
use App\Models\SmartOltFanSummary;
use App\Models\SnmpOlt;
use App\Models\User;
use App\Services\Snmp\OltSnmpClient;
use App\Services\ZteCliProvisioningExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmartOltFanStatusTest extends TestCase
{
    use RefreshDatabase;

    private function makeOlt(array $overrides = []): SnmpOlt
    {
        return SnmpOlt::create(array_merge([
            'name' => 'BMKV-C320',
            'vendor' => 'ZTE C320',
            'ip' => '10.31.0.'.random_int(2, 250),
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'cli_transport' => 'telnet',
            'cli_port' => 23,
            'cli_username' => 'admin',
            'cli_password' => 'secret',
        ], $overrides));
    }

    private function fakeSnmp(): void
    {
        $base = '1.3.6.1.4.1.3902.1015.2.1.3.10.10';
        $table = $base.'.10.1';

        $this->app->instance(OltSnmpClient::class, new class($base, $table) extends OltSnmpClient
        {
            public function __construct(private string $base, private string $table) {}

            public function walk(SnmpOlt $olt, string $oid, bool $plain = false): array
            {
                return [
                    $this->base.'.1.0' => 'INTEGER: 1',
                    $this->base.'.6.0' => 'INTEGER: 20',
                    $this->base.'.7.0' => 'INTEGER: 36',
                    $this->base.'.8.0' => 'INTEGER: 44',
                    $this->base.'.9.0' => 'INTEGER: 51',
                    $this->base.'.11.0' => 'INTEGER: 20',
                    $this->base.'.12.0' => 'INTEGER: 35',
                    $this->base.'.13.0' => 'INTEGER: 50',
                    $this->base.'.14.0' => 'INTEGER: 75',
                    $this->table.'.2.1' => 'INTEGER: 3',
                    $this->table.'.3.1' => 'INTEGER: 3',
                    $this->table.'.4.1' => 'INTEGER: 1',
                    $this->table.'.5.1' => 'INTEGER: 1',
                    $this->table.'.6.1' => 'INTEGER: 1',
                    $this->table.'.7.1' => 'INTEGER: 3246',
                ];
            }

            public function get(SnmpOlt $olt, string $oid): ?string
            {
                return 'INTEGER: 39';
            }
        });
    }

    public function test_refresh_reads_fan_from_snmp_and_persists(): void
    {
        $user = User::factory()->create();
        $olt = $this->makeOlt();
        $this->fakeSnmp();

        $response = $this->actingAs($user)->post(route('smartolt.fan.refresh', $olt));

        $response->assertRedirect(route('smartolt.detail', $olt));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('smartolt_fan_summaries', [
            'snmp_olt_id' => $olt->id,
            'source' => 'snmp',
            'mode' => 'auto',
            'mode_code' => 1,
            'temp_c' => 39,
        ]);
        $this->assertDatabaseHas('smartolt_fan_statuses', [
            'snmp_olt_id' => $olt->id,
            'fan_index' => 1,
            'status' => 'normal',
            'level' => 3,
            'speed_rpm' => 3246,
        ]);
    }

    public function test_refresh_reads_c600_fan_from_cli(): void
    {
        $user = User::factory()->create();
        $olt = $this->makeOlt([
            'name' => 'ZTE C600',
            'vendor' => 'ZTE C600',
            'last_test_result' => ['system' => ['sys_descr' => 'ZXA10 C600']],
        ]);

        $this->app->instance(ZteCliProvisioningExecutor::class, new class extends ZteCliProvisioningExecutor
        {
            public function execute(SnmpOlt $olt, string $script, bool $largeOutput = false): array
            {
                return [
                    'ok' => true,
                    'error' => null,
                    'output' => <<<'OUT'
[shelf 1 LCC]:
Fan speed mini level: 1
Fan speed mode: auto
Fan       name: FAN
Index      Status      Speed
  0    work normally   44%
  1    work normally   45%

ZXAN#exit
OUT,
                ];
            }
        });

        $response = $this->actingAs($user)->post(route('smartolt.fan.refresh', $olt));

        $response->assertRedirect(route('smartolt.detail', $olt));

        $summary = SmartOltFanSummary::query()->where('snmp_olt_id', $olt->id)->firstOrFail();
        $this->assertSame('cli', $summary->source);
        $this->assertSame('auto', $summary->mode);
        $this->assertSame(1, $summary->min_level);

        $this->assertSame(2, SmartOltFanStatus::query()->where('snmp_olt_id', $olt->id)->count());
        $this->assertDatabaseHas('smartolt_fan_statuses', [
            'snmp_olt_id' => $olt->id,
            'fan_index' => 1,
            'speed_percent' => 45,
        ]);
    }

    public function test_detail_renders_stored_fan_without_touching_device(): void
    {
        $user = User::factory()->create();
        $olt = $this->makeOlt();

        SmartOltFanSummary::create([
            'snmp_olt_id' => $olt->id,
            'source' => 'snmp',
            'mode' => 'auto',
            'mode_code' => 1,
            'temp_c' => 39,
            'thresholds' => [20, 36, 44, 51],
            'percents' => [20, 35, 50, 75],
            'refreshed_at' => now(),
        ]);
        SmartOltFanStatus::create([
            'snmp_olt_id' => $olt->id,
            'rack' => 1,
            'shelf' => 1,
            'fan_index' => 1,
            'fan_name' => 'Fan 1',
            'status' => 'normal',
            'level' => 3,
            'speed_rpm' => 3246,
            'refreshed_at' => now(),
        ]);

        $this->app->instance(OltSnmpClient::class, new class extends OltSnmpClient
        {
            public function walk(SnmpOlt $olt, string $oid, bool $plain = false): array
            {
                throw new \RuntimeException('SNMP should not run while rendering detail.');
            }

            public function get(SnmpOlt $olt, string $oid): ?string
            {
                throw new \RuntimeException('SNMP should not run while rendering detail.');
            }
        });

        $response = $this->actingAs($user)->get(route('smartolt.detail', $olt));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('SmartOlt/Detail')
            ->where('fan.mode', 'auto')
            ->where('fan.temp_c', 39)
            ->where('fan.unit', 'rpm')
            ->has('fan.fans', 1)
            ->where('fan.fans.0.speed_rpm', 3246)
        );
    }

    public function test_refresh_rejects_unsupported_vendor_without_writing(): void
    {
        $user = User::factory()->create();
        $olt = $this->makeOlt(['name' => 'OLT-CDATA', 'vendor' => 'C-Data EPON']);

        $response = $this->actingAs($user)->post(route('smartolt.fan.refresh', $olt));

        $response->assertRedirect(route('smartolt.detail', $olt));
        $response->assertSessionHas('error');
        $this->assertSame(0, SmartOltFanSummary::query()->where('snmp_olt_id', $olt->id)->count());
    }
}
