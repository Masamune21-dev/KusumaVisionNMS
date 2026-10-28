<?php

namespace Tests\Feature;

use App\Models\SmartOltProfile;
use App\Models\SnmpOlt;
use App\Models\User;
use App\Services\ZteCliProvisioningExecutor;
use App\Services\ZteProfileCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pengaman CLI halaman Profiles: catatan profil tak boleh menjadi baris CLI tambahan, dan perintah
 * profil (sintaks ZTE) hanya dikirim ke OLT yang driver-nya mendukung provisioning ZTE.
 */
class SmartOltProfileCliGuardTest extends TestCase
{
    use RefreshDatabase;

    private ZteCliProvisioningExecutor $executor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->executor = new class extends ZteCliProvisioningExecutor
        {
            public int $calls = 0;

            public function execute(SnmpOlt $olt, string $script, bool $largeOutput = false): array
            {
                $this->calls++;

                return ['ok' => true, 'error' => null, 'output' => ''];
            }
        };
        $this->app->instance(ZteCliProvisioningExecutor::class, $this->executor);
    }

    public function test_profile_notes_cannot_inject_extra_cli_lines(): void
    {
        $script = app(ZteProfileCatalogService::class)->buildScript('add', [
            'profile_type' => 'onu_type',
            'name' => 'F660',
            'notes' => "catatan\nend\r\nconfigure terminal\ninterface gpon-olt_1/1/1\nshutdown",
        ]);

        $this->assertSame([
            'conf t',
            'pon',
            'onu-type F660 gpon description "catatan end configure terminal interface gpon-olt_1/1/1 shutdown"',
            'exit',
        ], explode("\n", $script));
    }

    public function test_profile_cli_is_refused_on_non_zte_olt(): void
    {
        $olt = $this->olt('OLT-UJI-EPON', 'C-Data EPON', '192.0.2.10');
        $admin = User::factory()->admin()->create();
        $profile = SmartOltProfile::create([
            'snmp_olt_id' => $olt->id,
            'profile_type' => 'tcont',
            'name' => 'UJI-LAMA',
            'params' => ['type' => 4, 'maximum' => 102400],
            'is_active' => true,
        ]);

        $this->actingAs($admin)->post(route('smartolt.profiles.sync', $olt))->assertForbidden();
        $this->actingAs($admin)
            ->post(route('smartolt.profiles.store', $olt), $this->tcontPayload('UJI-BARU', true))
            ->assertForbidden();
        $this->actingAs($admin)
            ->put(route('smartolt.profiles.update', [$olt, $profile]), $this->tcontPayload('UJI-LAMA', true))
            ->assertForbidden();
        $this->actingAs($admin)
            ->delete(route('smartolt.profiles.destroy', [$olt, $profile]), ['execute_cli' => true])
            ->assertForbidden();

        $this->assertSame(0, $this->executor->calls, 'OLT non-ZTE tak boleh menerima perintah profil ZTE.');
        $this->assertSame(['UJI-LAMA'], SmartOltProfile::where('snmp_olt_id', $olt->id)->pluck('name')->all());

        // Tanpa Eksekusi CLI, katalog NMS tetap bisa diisi.
        $this->actingAs($admin)
            ->post(route('smartolt.profiles.store', $olt), $this->tcontPayload('UJI-BARU', false))
            ->assertRedirect(route('smartolt.profiles.index', $olt));
        $this->assertSame(0, $this->executor->calls);
    }

    public function test_profile_cli_runs_on_zte_olt(): void
    {
        $olt = $this->olt('OLT-UJI-C300', 'ZTE C300', '192.0.2.20');

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('smartolt.profiles.store', $olt), $this->tcontPayload('UJI-BARU', true))
            ->assertRedirect(route('smartolt.profiles.index', $olt));

        $this->assertSame(1, $this->executor->calls);
    }

    private function olt(string $name, string $vendor, string $ip): SnmpOlt
    {
        return SnmpOlt::create([
            'name' => $name,
            'vendor' => $vendor,
            'ip' => $ip,
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'cli_transport' => 'telnet',
            'cli_port' => 23,
            'cli_username' => 'admin',
            'cli_password' => 'secret',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function tcontPayload(string $name, bool $executeCli): array
    {
        return [
            'profile_type' => 'tcont',
            'name' => $name,
            'params' => ['type' => 4, 'maximum' => 102400],
            'is_active' => true,
            'execute_cli' => $executeCli,
        ];
    }
}
