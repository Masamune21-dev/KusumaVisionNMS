<?php

namespace Tests\Feature\Api;

use App\Contracts\SmartOltSnmpDriver;
use App\Models\SmartOltOnuRegistration;
use App\Models\SmartOltProfile;
use App\Models\SnmpOlt;
use App\Models\User;
use App\Services\CData\CDataCliWriteService;
use App\Services\SmartOltSnmpServiceResolver;
use App\Services\ZteCliProvisioningExecutor;
use App\Services\ZteRemoteOnuService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Endpoint tulis mobile: registrasi ONU + reboot/rename, dengan gating role
 * (admin & operator boleh, demo diblokir). DB test bisa berisi data seed, jadi
 * asersi memakai registration_id dari response (bukan firstOrFail).
 */
class ApiV1WriteTest extends TestCase
{
    use RefreshDatabase;

    private function seedOlt(bool $demo = false, string $name = 'OLT-C320-TEST', string $vendor = 'ZTE C320', string $sysDescr = 'ZTE ZXA10 C320'): SnmpOlt
    {
        $olt = SnmpOlt::create([
            'name' => $name,
            'vendor' => $vendor,
            'ip' => $demo ? '10.40.0.7' : '10.40.0.2',
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'is_demo' => $demo,
            'last_test_result' => [
                'ok' => true,
                'system' => ['sys_descr' => $sysDescr],
                'port_onus' => [
                    '1_1' => ['slot' => 1, 'port' => 1, 'onus' => [
                        ['onu_id' => 5, 'slot' => 1, 'port' => 1, 'if_index' => 123, 'name' => 'Lama'],
                    ]],
                ],
            ],
        ]);

        foreach (['onu_type' => 'ALL-ONT', 'tcont' => 'SERVER'] as $type => $name) {
            SmartOltProfile::create([
                'snmp_olt_id' => $olt->id, 'profile_type' => $type, 'name' => $name,
                'is_active' => true, 'is_demo' => $demo,
            ]);
        }

        return $olt;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'serial_number' => 'ZTEGCAF12345',
            'slot' => 1, 'port' => 1, 'onu_id' => 6,
            'customer_name' => 'Pak Budi',
            'onu_type' => 'ALL-ONT',
            'tcont_profile' => 'SERVER',
            'vlan' => 100,
            'service_name' => 'ServiceName',
            'wan_mode' => 'pppoe',
        ];
    }

    public function test_register_preview_returns_script(): void
    {
        $olt = $this->seedOlt();
        $operator = User::factory()->create();

        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/v1/olts/{$olt->id}/register/preview", $this->payload())
            ->assertOk()
            ->assertJsonPath('data.script', fn ($s) => is_string($s) && str_contains($s, 'ZTEGCAF12345'));
    }

    public function test_register_generated_saves_audit_without_executing(): void
    {
        $olt = $this->seedOlt();
        $operator = User::factory()->create();

        $this->app->instance(ZteCliProvisioningExecutor::class, new class extends ZteCliProvisioningExecutor
        {
            public function execute(SnmpOlt $olt, string $script, bool $largeOutput = false): array
            {
                throw new \RuntimeException('Tidak boleh eksekusi saat execute=false.');
            }
        });

        $res = $this->actingAs($operator, 'sanctum')
            ->postJson("/api/v1/olts/{$olt->id}/register", [...$this->payload(), 'execute' => false])
            ->assertOk()
            ->assertJsonPath('data.status', 'generated');

        $id = $res->json('data.registration_id');
        $this->assertSame('generated', SmartOltOnuRegistration::withoutGlobalScopes()->findOrFail($id)->status);
    }

    public function test_register_execute_marks_executed(): void
    {
        $olt = $this->seedOlt();
        $operator = User::factory()->create();

        $this->app->instance(ZteCliProvisioningExecutor::class, new class extends ZteCliProvisioningExecutor
        {
            public function execute(SnmpOlt $olt, string $script, bool $largeOutput = false): array
            {
                return ['ok' => true, 'error' => null, 'output' => 'BMKV-C320#'];
            }
        });

        $res = $this->actingAs($operator, 'sanctum')
            ->postJson("/api/v1/olts/{$olt->id}/register", [...$this->payload(), 'execute' => true])
            ->assertOk()
            ->assertJsonPath('data.status', 'executed');

        $id = $res->json('data.registration_id');
        $this->assertNotNull(SmartOltOnuRegistration::withoutGlobalScopes()->findOrFail($id)->executed_at);
    }

    public function test_demo_user_cannot_register(): void
    {
        $olt = $this->seedOlt(demo: true);
        $demo = User::factory()->demo()->create();

        $this->actingAs($demo, 'sanctum')
            ->postJson("/api/v1/olts/{$olt->id}/register", [...$this->payload(), 'execute' => false])
            ->assertStatus(403);
    }

    public function test_reboot_onu_uses_remote_service(): void
    {
        $olt = $this->seedOlt();
        $operator = User::factory()->create();

        $mock = Mockery::mock(ZteRemoteOnuService::class);
        $mock->shouldReceive('reboot')->once()->andReturn(['ok' => true, 'error' => null]);
        $this->app->instance(ZteRemoteOnuService::class, $mock);

        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/v1/olts/{$olt->id}/onus/1/1/5/reboot")
            ->assertOk()
            ->assertJsonPath('data.ok', true);
    }

    public function test_rename_onu_updates_cache(): void
    {
        $olt = $this->seedOlt();
        $operator = User::factory()->create();

        $mock = Mockery::mock(ZteRemoteOnuService::class);
        $mock->shouldReceive('setInfo')->once();
        $this->app->instance(ZteRemoteOnuService::class, $mock);

        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/v1/olts/{$olt->id}/onus/1/1/5/name", ['name' => 'Baru'])
            ->assertOk()
            ->assertJsonPath('data.ok', true);

        $olt->refresh();
        $this->assertSame('Baru', $olt->last_test_result['port_onus']['1_1']['onus'][0]['name']);
    }

    public function test_delete_onu_zte_removes_from_cache(): void
    {
        $olt = $this->seedOlt();
        $operator = User::factory()->create();

        $mock = Mockery::mock(ZteRemoteOnuService::class);
        $mock->shouldReceive('delete')->once()
            ->withArgs(fn (SnmpOlt $o, int $slot, int $port, int $onuId) => $o->id === $olt->id && $slot === 1 && $port === 1 && $onuId === 5)
            ->andReturn(['ok' => true, 'output' => '', 'error' => null]);
        $this->app->instance(ZteRemoteOnuService::class, $mock);

        $this->actingAs($operator, 'sanctum')
            ->deleteJson("/api/v1/olts/{$olt->id}/onus/1/1/5")
            ->assertOk()
            ->assertJsonPath('data.ok', true);

        $olt->refresh();
        $this->assertSame([], $olt->last_test_result['port_onus']['1_1']['onus']);
    }

    public function test_delete_onu_cdata_uses_cdata_service(): void
    {
        $olt = $this->seedOlt(name: 'OLT-CD-TEST', vendor: 'C-Data FD1208S', sysDescr: 'EPON OLT');
        $operator = User::factory()->create();

        $mock = Mockery::mock(CDataCliWriteService::class);
        $mock->shouldReceive('delete')->once()
            ->withArgs(fn (SnmpOlt $o, string $iface, int $slot, int $port, int $onuId) => $o->id === $olt->id && $iface === 'epon' && $slot === 1 && $port === 1 && $onuId === 5)
            ->andReturn(['ok' => true, 'output' => '', 'error' => null]);
        $this->app->instance(CDataCliWriteService::class, $mock);

        $this->actingAs($operator, 'sanctum')
            ->deleteJson("/api/v1/olts/{$olt->id}/onus/1/1/5")
            ->assertOk()
            ->assertJsonPath('data.ok', true);
    }

    public function test_delete_onu_unknown_driver_rejected(): void
    {
        $olt = $this->seedOlt(name: 'OLT-X-TEST', vendor: 'Huawei MA5800', sysDescr: 'MA5800-X7');
        $operator = User::factory()->create();

        $this->actingAs($operator, 'sanctum')
            ->deleteJson("/api/v1/olts/{$olt->id}/onus/1/1/5")
            ->assertStatus(422);
    }

    public function test_demo_user_cannot_delete_onu(): void
    {
        $olt = $this->seedOlt(demo: true);
        $demo = User::factory()->demo()->create();

        $this->actingAs($demo, 'sanctum')
            ->deleteJson("/api/v1/olts/{$olt->id}/onus/1/1/5")
            ->assertStatus(403);
    }

    public function test_reboot_onu_cdata_uses_cdata_service(): void
    {
        $olt = $this->seedOlt(name: 'OLT-CD-TEST', vendor: 'C-Data FD1208S', sysDescr: 'EPON OLT');
        $operator = User::factory()->create();

        $mock = Mockery::mock(CDataCliWriteService::class);
        $mock->shouldReceive('reboot')->once()
            ->withArgs(fn (SnmpOlt $o, string $iface, int $slot, int $port, int $onuId) => $iface === 'epon' && $onuId === 5)
            ->andReturn(['ok' => true, 'output' => '', 'error' => null]);
        $this->app->instance(CDataCliWriteService::class, $mock);

        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/v1/olts/{$olt->id}/onus/1/1/5/reboot")
            ->assertOk()
            ->assertJsonPath('data.ok', true);
    }

    public function test_refresh_port_non_zte_queries_driver(): void
    {
        $olt = $this->seedOlt();
        $operator = User::factory()->create();

        $driver = Mockery::mock(SmartOltSnmpDriver::class);
        $driver->shouldReceive('getRegisteredOnusByPort')->once()
            ->andReturn([['onu_id' => 9, 'slot' => 1, 'port' => 1, 'online' => true]]);

        $resolver = Mockery::mock(SmartOltSnmpServiceResolver::class);
        $resolver->shouldReceive('isNonZte')->andReturn(true);
        $resolver->shouldReceive('resolve')->andReturn($driver);
        $this->app->instance(SmartOltSnmpServiceResolver::class, $resolver);

        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/v1/olts/{$olt->id}/ports/1/1/refresh")
            ->assertOk()
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.count', 1);

        $olt->refresh();
        $this->assertSame(9, $olt->last_test_result['port_onus']['1_1']['onus'][0]['onu_id']);
    }
}
