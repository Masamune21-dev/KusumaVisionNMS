<?php

namespace Tests\Feature;

use App\Models\SnmpOlt;
use App\Models\User;
use App\Services\ZteCliProvisioningExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ONU yang terikat onu-profile C300: perubahan service/T-CONT/GEM ditolak OLT (%Code 64007),
 * jadi NMS tidak boleh mengirim skripnya sama sekali.
 */
class SmartOltConfigureOnuProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_apply_is_blocked_before_reaching_the_olt(): void
    {
        $olt = SnmpOlt::create([
            'name' => 'OLT-UJI-C300',
            'vendor' => 'ZTE C300',
            'ip' => '10.30.0.31',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'cli_transport' => 'telnet',
            'cli_port' => 23,
            'cli_username' => 'admin',
            'cli_password' => 'secret',
        ]);

        $executor = new class extends ZteCliProvisioningExecutor
        {
            public int $calls = 0;

            public function execute(SnmpOlt $olt, string $script, bool $largeOutput = false): array
            {
                $this->calls++;

                return ['ok' => true, 'error' => null, 'output' => ''];
            }
        };
        $this->app->instance(ZteCliProvisioningExecutor::class, $executor);

        $service = fn (int $vlan) => [['name' => 'ServiceName', 'gem' => 1, 'cos' => 0, 'vlan' => $vlan, 'mode' => 'vlanpri']];

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('smartolt.onu.configure.apply', [$olt, 3, 16, 54]), [
                'baseline' => ['onu_profile' => 'VLAN2100', 'name' => 'Uji', 'services' => $service(2100)],
                'config' => ['name' => 'Uji', 'services' => $service(2101)],
            ])
            ->assertRedirect(route('smartolt.onu.configure', [$olt, 3, 16, 54]))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'VLAN2100'));

        $this->assertSame(0, $executor->calls, 'Skrip yang pasti ditolak 64007 tidak boleh dikirim ke OLT.');
    }
}
