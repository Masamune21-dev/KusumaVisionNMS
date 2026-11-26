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

    public function test_advanced_preview_accepts_long_gemport_form_and_rejects_unknown_tokens(): void
    {
        $user = User::factory()->create();
        $olt = $this->makeOlt();

        $payload = $this->payload();
        $payload['config']['gemports'][0] = ['id' => 1, 'name' => 'FF', 'type' => 'unicast', 'tcont' => 1, 'dir' => 'both'];
        $payload['config']['gemports'][1] = ['id' => 2, 'name' => '2', 'type' => null, 'tcont' => 1, 'dir' => null];

        $script = $this->actingAs($user)
            ->postJson(route('smartolt.register.advanced.preview', $olt), $payload)
            ->assertOk()
            ->json('script');

        $this->assertStringContainsString("\ngemport 1 name FF unicast tcont 1 dir both\n", $script);
        $this->assertStringContainsString("\ngemport 2 name 2 tcont 1\n", $script);

        $payload['config']['gemports'][0]['type'] = 'anycast';
        $payload['config']['gemports'][0]['dir'] = 'up';
        $this->actingAs($user)
            ->postJson(route('smartolt.register.advanced.preview', $olt), $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['config.gemports.0.type', 'config.gemports.0.dir']);
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

    public function test_advanced_preview_writes_separate_description(): void
    {
        $user = User::factory()->create();
        $olt = $this->makeOlt();
        $payload = $this->payload();
        $payload['config']['description'] = 'Perum Contoh (ODP 3)';

        $this->actingAs($user)
            ->postJson(route('smartolt.register.advanced.preview', $olt), $payload)
            ->assertOk()
            ->assertJsonPath('script', fn (string $script) => str_contains($script, "name Pelanggan Hotspot\ndescription Perum Contoh (ODP 3)\n"));

        $payload['config']['description'] = str_repeat('x', 81);
        $this->actingAs($user)
            ->postJson(route('smartolt.register.advanced.preview', $olt), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('config.description');
    }

    public function test_advanced_preview_accepts_veip_uni_vlan(): void
    {
        $user = User::factory()->create();
        $olt = $this->makeOlt();
        $payload = $this->payload();
        $payload['config']['vlan_ports'] = [
            ['port_type' => 'veip', 'port' => 1, 'mode' => 'hybrid', 'def_vlan' => 100, 'priority' => null],
        ];

        $this->actingAs($user)
            ->postJson(route('smartolt.register.advanced.preview', $olt), $payload)
            ->assertOk()
            ->assertJsonPath('script', fn (string $script) => str_contains($script, 'vlan port veip_1 mode hybrid def-vlan 100'));

        $payload['config']['vlan_ports'][0]['port_type'] = 'pots';
        $this->actingAs($user)
            ->postJson(route('smartolt.register.advanced.preview', $olt), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('config.vlan_ports.0.port_type');
    }

    public function test_advanced_preview_accepts_service_port_modes(): void
    {
        $user = User::factory()->create();
        $olt = $this->makeOlt();
        $payload = $this->payload();
        $payload['config']['service_ports'] = [
            ['id' => 1, 'vport' => 1, 'mode' => 'transparent', 'user_vlan' => 1114, 'vlan' => 1114],
            ['id' => 2, 'vport' => 1, 'mode' => 'tag', 'user_vlan' => 10, 'vlan' => 1010],
            ['id' => 3, 'vport' => 1, 'mode' => 'untagged', 'user_vlan' => 7, 'vlan' => 100],
        ];

        $this->actingAs($user)
            ->postJson(route('smartolt.register.advanced.preview', $olt), $payload)
            ->assertOk()
            ->assertJsonPath('script', function (string $script) {
                // transparent memakai angka user-vlan (tanpa VLAN tujuan); untagged membuang user-vlan.
                return str_contains($script, 'service-port 1 vport 1 user-vlan 1114 transparent')
                    && ! str_contains($script, 'user-vlan transparent')
                    && str_contains($script, 'service-port 2 vport 1 user-vlan 10 vlan 1010')
                    && str_contains($script, 'service-port 3 vport 1 user-vlan untagged vlan 100');
            });

        // Mode tak dikenal ditolak sebelum menyentuh OLT.
        $payload['config']['service_ports'][0]['mode'] = 'bogus';
        $this->actingAs($user)
            ->postJson(route('smartolt.register.advanced.preview', $olt), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('config.service_ports.0.mode');
    }

    public function test_simple_register_writes_separate_description(): void
    {
        $user = User::factory()->create();
        $olt = $this->makeOlt();
        SmartOltProfile::create(['snmp_olt_id' => null, 'profile_type' => 'tcont', 'name' => 'SERVER', 'is_active' => true]);

        $payload = [
            'serial_number' => 'ZTEGCAF12345', 'slot' => 1, 'port' => 2, 'onu_id' => 7,
            'customer_name' => 'Budi Santoso', 'description' => 'Perum Contoh (ODP 3)',
            'onu_type' => 'ALL-ONT', 'tcont_profile' => 'SERVER', 'vlan' => 100,
            'service_name' => 'ServiceName', 'wan_mode' => 'dhcp',
        ];

        $this->actingAs($user)
            ->postJson(route('smartolt.register.preview', $olt), $payload)
            ->assertOk()
            ->assertJsonPath('script', fn (string $script) => str_contains($script, "name Budi Santoso\ndescription Perum Contoh (ODP 3)\n"));

        $this->actingAs($user)
            ->post(route('smartolt.register.store', $olt), [...$payload, 'execute' => false])
            ->assertRedirect(route('smartolt.registrations', $olt));
        $this->assertStringContainsString('description Perum Contoh (ODP 3)', SmartOltOnuRegistration::firstOrFail()->cli_script);

        $this->actingAs($user)
            ->post(route('smartolt.register.store', $olt), [...$payload, 'description' => str_repeat('x', 81), 'execute' => false])
            ->assertSessionHasErrors('description');
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
