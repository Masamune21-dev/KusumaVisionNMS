<?php

namespace Tests\Feature;

use App\Models\Odp;
use App\Models\OnuOdpLink;
use App\Models\SmartOltOnuRegistration;
use App\Models\SmartOltProfile;
use App\Models\SnmpOlt;
use App\Models\User;
use App\Services\ZteCliProvisioningExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Field ODP opsional pada registrasi ONU.
 *
 * Aturan yang dikunci di sini: ODP dikaitkan HANYA setelah CLI benar-benar sukses
 * (bukan saat generate-script), dan kegagalan mengaitkan tidak boleh membatalkan
 * registrasi maupun menghasilkan baris audit 'failed' kedua.
 */
class OdpRegistrationLinkTest extends TestCase
{
    use RefreshDatabase;

    private function makeOlt(array $overrides = []): SnmpOlt
    {
        $olt = SnmpOlt::create(array_merge([
            'name' => 'BMKV-C300',
            'vendor' => 'ZTE C300',
            'ip' => '10.31.0.10',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'cli_transport' => 'telnet',
            'cli_port' => 23,
            'cli_username' => 'admin',
            'cli_password' => 'secret',
        ], $overrides));

        foreach ([['onu_type', 'ALL-ONT'], ['tcont', 'SERVER']] as [$type, $name]) {
            SmartOltProfile::create([
                'snmp_olt_id' => null,
                'profile_type' => $type,
                'name' => $name,
                'is_active' => true,
            ]);
        }

        return $olt;
    }

    private function makeOdp(SnmpOlt $olt, string $name = 'ODP-01', ?int $slot = 1, ?int $port = 2): Odp
    {
        return Odp::create([
            'snmp_olt_id' => $olt->id,
            'name' => $name,
            'slot' => $slot,
            'port' => $port,
            'latitude' => -6.75,
            'longitude' => 111.03,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(?int $odpId): array
    {
        return array_filter([
            'serial_number' => 'ZTEGCAF12345',
            'slot' => 1,
            'port' => 2,
            'onu_id' => 7,
            'customer_name' => 'Pelanggan ODP',
            'onu_type' => 'ALL-ONT',
            'tcont_profile' => 'SERVER',
            'vlan' => 1114,
            'service_name' => 'ServiceName',
            'wan_mode' => 'pppoe',
            'odp_id' => $odpId,
        ], fn ($value) => $value !== null);
    }

    private function fakeExecutor(bool $ok): void
    {
        $this->app->instance(ZteCliProvisioningExecutor::class, new class($ok) extends ZteCliProvisioningExecutor
        {
            public function __construct(private readonly bool $ok) {}

            public function execute(SnmpOlt $olt, string $script, bool $largeOutput = false): array
            {
                return [
                    'ok' => $this->ok,
                    'error' => $this->ok ? null : 'Invalid input',
                    'output' => 'BMKV-C300#',
                ];
            }
        });
    }

    public function test_generate_only_does_not_link_the_odp(): void
    {
        $olt = $this->makeOlt();
        $odp = $this->makeOdp($olt);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('smartolt.register.store', $olt), [...$this->payload($odp->id), 'execute' => false])
            ->assertRedirect(route('smartolt.registrations', $olt));

        $this->assertSame('generated', SmartOltOnuRegistration::firstOrFail()->status);
        $this->assertDatabaseCount('onu_odp_links', 0);
    }

    public function test_successful_execution_links_the_onu_to_the_odp(): void
    {
        $olt = $this->makeOlt();
        $odp = $this->makeOdp($olt);
        $this->fakeExecutor(true);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('smartolt.register.store', $olt), [...$this->payload($odp->id), 'execute' => true])
            ->assertRedirect(route('smartolt.registrations', $olt))
            ->assertSessionHas('success');

        $this->assertSame('executed', SmartOltOnuRegistration::firstOrFail()->status);
        $this->assertDatabaseHas('onu_odp_links', [
            'odp_id' => $odp->id,
            'snmp_olt_id' => $olt->id,
            'slot' => 1,
            'port' => 2,
            'onu_id' => 7,
            'serial_number' => 'ZTEGCAF12345',
        ]);
    }

    public function test_failed_execution_leaves_no_link_and_a_single_audit_row(): void
    {
        $olt = $this->makeOlt();
        $odp = $this->makeOdp($olt);
        $this->fakeExecutor(false);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('smartolt.register.store', $olt), [...$this->payload($odp->id), 'execute' => true])
            ->assertRedirect(route('smartolt.registrations', $olt));

        $this->assertDatabaseCount('smartolt_onu_registrations', 1);
        $this->assertSame('failed', SmartOltOnuRegistration::firstOrFail()->status);
        $this->assertDatabaseCount('onu_odp_links', 0);
    }

    public function test_registration_without_odp_leaves_existing_links_untouched(): void
    {
        $olt = $this->makeOlt();
        $odp = $this->makeOdp($olt);
        OnuOdpLink::create([
            'odp_id' => $odp->id,
            'snmp_olt_id' => $olt->id,
            'slot' => 1,
            'port' => 2,
            'onu_id' => 7,
        ]);
        $this->fakeExecutor(true);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('smartolt.register.store', $olt), [...$this->payload(null), 'execute' => true])
            ->assertRedirect(route('smartolt.registrations', $olt));

        // odp_id kosong = jangan sentuh kaitan (assign(null) akan MENGHAPUS link).
        $this->assertDatabaseCount('onu_odp_links', 1);
    }

    public function test_odp_of_another_olt_warns_but_keeps_the_registration(): void
    {
        $olt = $this->makeOlt();
        $foreign = $this->makeOlt(['name' => 'OLT-LAIN', 'ip' => '10.31.0.11']);
        $foreignOdp = $this->makeOdp($foreign, 'ODP-LAIN');
        $this->fakeExecutor(true);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('smartolt.register.store', $olt), [...$this->payload($foreignOdp->id), 'execute' => true])
            ->assertRedirect(route('smartolt.registrations', $olt))
            ->assertSessionHas('success');

        $this->assertSame('executed', SmartOltOnuRegistration::firstOrFail()->status);
        $this->assertDatabaseCount('onu_odp_links', 0);
    }

    public function test_advanced_registration_links_the_odp_after_execution(): void
    {
        $olt = $this->makeOlt();
        $odp = $this->makeOdp($olt);
        $this->fakeExecutor(true);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('smartolt.register.advanced.store', $olt), [
                'serial_number' => 'ZTEGCAF12345',
                'slot' => 1,
                'port' => 2,
                'onu_id' => 7,
                'onu_type' => 'ALL-ONT',
                'odp_id' => $odp->id,
                'execute' => true,
                'config' => [
                    'name' => 'Pelanggan ODP',
                    'tconts' => [['id' => 1, 'name' => '1', 'profile' => 'SERVER', 'gap' => 'mode0']],
                    'gemports' => [['id' => 1, 'name' => '1', 'tcont' => 1]],
                    'service_ports' => [['id' => 1, 'vport' => 1, 'user_vlan' => 1114, 'vlan' => 1114]],
                    'services' => [['name' => 'ServiceName', 'mode' => 'vlanpri', 'gem' => 1, 'cos' => 0, 'vlan' => 1114]],
                    'wan_ips' => [[
                        'id' => 1,
                        'mode' => 'pppoe',
                        'pppoe_username' => 'user',
                        'pppoe_password' => 'rahasia',
                        'ping_response' => true,
                        'traceroute_response' => true,
                    ]],
                    'tr069' => false,
                    'remote_ont' => false,
                ],
            ])
            ->assertRedirect(route('smartolt.registrations', $olt));

        $this->assertDatabaseHas('onu_odp_links', ['odp_id' => $odp->id, 'onu_id' => 7]);
    }
}
