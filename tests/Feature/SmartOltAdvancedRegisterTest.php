<?php

namespace Tests\Feature;

use App\Models\SmartOltOnuRegistration;
use App\Models\SmartOltProfile;
use App\Models\SnmpOlt;
use App\Models\User;
use App\Services\ZteCliProvisioningExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmartOltAdvancedRegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_advanced_preview_builds_full_registration_with_multiple_gemports(): void
    {
        $user = User::factory()->create();
        $olt = $this->makeOlt();

        $response = $this->actingAs($user)
            ->postJson(route('smartolt.register.advanced.preview', $olt), $this->payload());

        $response->assertOk();
        $script = $response->json('script');

        // Registrasi OLT-side + dua gemport + dua service (hotspot di gemport 2).
        $this->assertStringContainsString('onu 7 type ALL-ONT sn ZTEGCAF12345', $script);
        $this->assertStringContainsString('interface gpon-onu_1/1/2:7', $script);
        $this->assertStringContainsString('gemport 2 name 2 tcont 1', $script);
        $this->assertStringContainsString('service Hotspot gemport 2 cos 0 vlan 15', $script);
        $this->assertStringContainsString('service-port 2 vport 1 user-vlan 15 vlan 15', $script);
    }

    public function test_advanced_store_generated_saves_audit_without_executing(): void
    {
        $user = User::factory()->create();
        $olt = $this->makeOlt();

        $this->app->instance(ZteCliProvisioningExecutor::class, new class extends ZteCliProvisioningExecutor
        {
            public function execute(SnmpOlt $olt, string $script, bool $largeOutput = false): array
            {
                throw new \RuntimeException('Must not execute when execute=false.');
            }
        });

        $response = $this->actingAs($user)
            ->post(route('smartolt.register.advanced.store', $olt), [...$this->payload(), 'execute' => false]);

        $response->assertRedirect(route('smartolt.registrations', $olt));

        $registration = SmartOltOnuRegistration::firstOrFail();
        $this->assertSame('generated', $registration->status);
        $this->assertSame('ZTEGCAF12345', $registration->serial_number);
        $this->assertSame(1114, $registration->vlan); // primary vlan = service-port pertama (resolvePrimaryVlan)
        $this->assertStringContainsString('service Hotspot gemport 2 cos 0 vlan 15', $registration->cli_script);
        $this->assertNull($registration->executed_at);
    }

    public function test_advanced_store_execute_marks_executed(): void
    {
        $user = User::factory()->create();
        $olt = $this->makeOlt();

        $this->app->instance(ZteCliProvisioningExecutor::class, new class extends ZteCliProvisioningExecutor
        {
            public function execute(SnmpOlt $olt, string $script, bool $largeOutput = false): array
            {
                return ['ok' => true, 'error' => null, 'output' => 'BMKV-C300#'];
            }
        });

        $response = $this->actingAs($user)
            ->post(route('smartolt.register.advanced.store', $olt), [...$this->payload(), 'execute' => true]);

        $response->assertRedirect(route('smartolt.registrations', $olt));

        $registration = SmartOltOnuRegistration::firstOrFail();
        $this->assertSame('executed', $registration->status);
        $this->assertNotNull($registration->executed_at);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'serial_number' => 'ZTEGCAF12345',
            'slot' => 1,
            'port' => 2,
            'onu_id' => 7,
            'onu_type' => 'ALL-ONT',
            'config' => [
                'name' => 'Pelanggan Hotspot',
                'tconts' => [['id' => 1, 'name' => '1', 'profile' => 'SERVER', 'gap' => 'mode0']],
                'gemports' => [
                    ['id' => 1, 'name' => '1', 'tcont' => 1],
                    ['id' => 2, 'name' => '2', 'tcont' => 1],
                ],
                'service_ports' => [
                    ['id' => 1, 'vport' => 1, 'user_vlan' => 1114, 'vlan' => 1114],
                    ['id' => 2, 'vport' => 1, 'user_vlan' => 15, 'vlan' => 15],
                ],
                'services' => [
                    ['name' => 'ServiceName', 'mode' => 'vlanpri', 'gem' => 1, 'cos' => 0, 'vlan' => 1114],
                    ['name' => 'Hotspot', 'mode' => 'vlanpri', 'gem' => 2, 'cos' => 0, 'vlan' => 15],
                ],
                'wan_ips' => [[
                    'id' => 1,
                    'mode' => 'pppoe',
                    'pppoe_username' => 'hotspotuser',
                    'pppoe_password' => 'rahasia',
                    'ping_response' => true,
                    'traceroute_response' => true,
                ]],
                'tr069' => false,
                'remote_ont' => false,
            ],
        ];
    }

    private function makeOlt(array $overrides = []): SnmpOlt
    {
        $olt = SnmpOlt::create(array_merge([
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

        SmartOltProfile::create([
            'snmp_olt_id' => null,
            'profile_type' => 'onu_type',
            'name' => 'ALL-ONT',
            'is_active' => true,
        ]);

        return $olt;
    }
}
