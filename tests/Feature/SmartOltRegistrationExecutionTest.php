<?php

namespace Tests\Feature;

use App\Models\SmartOltOnuRegistration;
use App\Models\SnmpOlt;
use App\Models\User;
use App\Services\ZteCliProvisioningExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmartOltRegistrationExecutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_execute_registration_persists_sanitized_cli_output(): void
    {
        $user = User::factory()->create();
        $olt = $this->makeOlt();
        $registration = SmartOltOnuRegistration::create([
            'snmp_olt_id' => $olt->id,
            'serial_number' => 'CDTCAF1B411B',
            'slot' => 2,
            'port' => 3,
            'onu_id' => 52,
            'pon_port' => 'gpon-onu_1/2/3:52',
            'customer_name' => 'Gazebo Rumah Etan',
            'onu_type' => 'ALL-ONT',
            'tcont_profile' => 'SERVER',
            'vlan' => 1114,
            'service_name' => 'VLAN1114-NEW',
            'wan_mode' => 'pppoe',
            'cli_script' => 'conf t',
            'status' => 'generated',
            'created_by' => $user->id,
        ]);

        $this->app->instance(ZteCliProvisioningExecutor::class, new class extends ZteCliProvisioningExecutor
        {
            public function execute(SnmpOlt $olt, string $script, bool $largeOutput = false): array
            {
                return [
                    'ok' => true,
                    'error' => null,
                    'output' => "\xff\xfb\x01\xff\xfb\x1f\xff\xfb\x18\xff\xfd\x20\xff\xfd\x03"
                        ."Welcome to ZXAN product C300\r\nBMKV-C300#",
                ];
            }
        });

        $response = $this->actingAs($user)
            ->post(route('smartolt.registrations.execute', [$olt, $registration]));

        $response->assertRedirect(route('smartolt.registrations', $olt));

        $registration->refresh();

        $this->assertSame('executed', $registration->status);
        $this->assertSame(1, preg_match('//u', $registration->execution_output));
        $this->assertStringNotContainsString("\xff", $registration->execution_output);
        $this->assertStringContainsString('Welcome to ZXAN product C300', $registration->execution_output);
    }

    public function test_execute_registration_does_not_rerun_an_executed_registration(): void
    {
        $user = User::factory()->create();
        $olt = $this->makeOlt(['ip' => '10.30.0.11']);
        $registration = SmartOltOnuRegistration::create([
            'snmp_olt_id' => $olt->id,
            'serial_number' => 'CDTCAF1B411C',
            'slot' => 2,
            'port' => 3,
            'onu_id' => 53,
            'pon_port' => 'gpon-onu_1/2/3:53',
            'customer_name' => 'Customer Done',
            'onu_type' => 'ALL-ONT',
            'tcont_profile' => 'SERVER',
            'vlan' => 1114,
            'service_name' => 'VLAN1114-NEW',
            'wan_mode' => 'pppoe',
            'cli_script' => 'conf t',
            'status' => 'executed',
            'execution_output' => 'Provisioning OK',
            'executed_at' => now()->subMinute(),
            'executed_by' => $user->id,
            'created_by' => $user->id,
        ]);

        $this->app->instance(ZteCliProvisioningExecutor::class, new class extends ZteCliProvisioningExecutor
        {
            public function execute(SnmpOlt $olt, string $script, bool $largeOutput = false): array
            {
                throw new \RuntimeException('CLI should not rerun for executed registrations.');
            }
        });

        $response = $this->actingAs($user)
            ->post(route('smartolt.registrations.execute', [$olt, $registration]));

        $response
            ->assertRedirect(route('smartolt.registrations', $olt))
            ->assertSessionHas('success', 'Provisioning script ini sudah teregister di OLT.');

        $registration->refresh();

        $this->assertSame('executed', $registration->status);
        $this->assertSame('Provisioning OK', $registration->execution_output);
    }

    private function makeOlt(array $overrides = []): SnmpOlt
    {
        return SnmpOlt::create(array_merge([
            'name' => 'BMKV-C300',
            'vendor' => 'ZTE C300',
            'ip' => '10.30.0.10',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'cli_transport' => 'telnet',
            'cli_port' => 23,
            'cli_username' => 'admin',
            'cli_password' => 'secret',
        ], $overrides));
    }
}
