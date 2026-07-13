<?php

namespace Tests\Feature;

use App\Models\SnmpOlt;
use App\Models\User;
use App\Services\CData\CDataCliWriteService;
use App\Services\Hioso\HiosoCliWriteService;
use App\Services\ZteCliProvisioningExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Tombol "Save Config" per-OLT (halaman SmartOLT Index): simpan running-config ke memori OLT via CLI.
 * ZTE `write`, C-Data `enable→config→save`, HiOSO `enable→write` — endpoint per family di-cover di sini.
 */
class OltConfigSaveTest extends TestCase
{
    use RefreshDatabase;

    private function makeOlt(string $vendor, string $ip): SnmpOlt
    {
        return SnmpOlt::create([
            'name' => 'OLT-'.$ip,
            'vendor' => $vendor,
            'ip' => $ip,
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'cli_transport' => 'telnet',
            'cli_username' => 'admin',
            'cli_password' => 'secret',
        ]);
    }

    public function test_zte_save_config_invokes_write(): void
    {
        $mock = Mockery::mock(ZteCliProvisioningExecutor::class);
        $mock->shouldReceive('saveConfig')->once()->andReturn(['ok' => true, 'output' => 'done', 'error' => null]);
        $this->app->instance(ZteCliProvisioningExecutor::class, $mock);

        $olt = $this->makeOlt('ZTE C320', '10.41.0.2');

        $this->actingAs(User::factory()->create())
            ->post(route('smartolt.config.save', $olt))
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    public function test_cdata_save_config_invokes_write(): void
    {
        $mock = Mockery::mock(CDataCliWriteService::class);
        $mock->shouldReceive('saveConfig')->once()->andReturn(['ok' => true, 'output' => 'done', 'error' => null]);
        $this->app->instance(CDataCliWriteService::class, $mock);

        $olt = $this->makeOlt('C-Data EPON 17409', '10.41.0.3');

        $this->actingAs(User::factory()->create())
            ->post(route('cdata-olt.config.save', $olt))
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    public function test_hioso_save_config_invokes_write(): void
    {
        $mock = Mockery::mock(HiosoCliWriteService::class);
        $mock->shouldReceive('saveConfig')->once()->andReturn(['ok' => true, 'output' => 'done', 'error' => null]);
        $this->app->instance(HiosoCliWriteService::class, $mock);

        $olt = $this->makeOlt('HiOSO EPON 25355', '10.41.0.4');

        $this->actingAs(User::factory()->create())
            ->post(route('hioso-olt.config.save', $olt))
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    public function test_save_config_reports_cli_error(): void
    {
        $mock = Mockery::mock(ZteCliProvisioningExecutor::class);
        $mock->shouldReceive('saveConfig')->once()->andReturn(['ok' => false, 'output' => '', 'error' => '%Error saving']);
        $this->app->instance(ZteCliProvisioningExecutor::class, $mock);

        $olt = $this->makeOlt('ZTE C320', '10.41.0.5');

        $this->actingAs(User::factory()->create())
            ->post(route('smartolt.config.save', $olt))
            ->assertRedirect()
            ->assertSessionHas('error');
    }
}
