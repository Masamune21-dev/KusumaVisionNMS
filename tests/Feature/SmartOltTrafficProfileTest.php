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
 * Profil traffic ZTE (`gpon profile traffic`) — dirujuk `gemport N traffic-limit …` dan dipilih dari
 * dropdown Upstream/Downstream Limit di editor ONU.
 */
class SmartOltTrafficProfileTest extends TestCase
{
    use RefreshDatabase;

    /** Bentuk keluaran asli C300/C320 (28 Okt 2026), prompt dianonimkan. */
    private const SHOW_OUTPUT = "OLT-UJI#\n> show gpon profile traffic\r\n"
        ."Profile name  :default          \r\n"
        ."  SIR(kbps)           PIR(kbps)           CBS(kbytes)         PBS(kbytes)       \r\n"
        ."   \r\n"
        ." 9953280             9953280             default             default            \r\n"
        ." \r\n \r\n"
        ."Profile name  :UJI-VOIP-10M          \r\n"
        ."  SIR(kbps)           PIR(kbps)           CBS(kbytes)         PBS(kbytes)       \r\n"
        ."   \r\n"
        ." 10480               11264               256                 512                \r\n"
        ." \r\n \r\n"
        ."OLT-UJI#\r\n";

    public function test_parses_traffic_profiles_from_show_output(): void
    {
        $rows = array_values(array_filter(
            app(ZteProfileCatalogService::class)->parse(self::SHOW_OUTPUT),
            fn (array $row) => $row['profile_type'] === 'traffic',
        ));

        $this->assertSame(['default', 'UJI-VOIP-10M'], array_column($rows, 'name'));
        $this->assertSame(['sir' => 9953280, 'pir' => 9953280, 'cbs' => null, 'pbs' => null], $rows[0]['params']);
        $this->assertSame(['sir' => 10480, 'pir' => 11264, 'cbs' => 256, 'pbs' => 512], $rows[1]['params']);
    }

    public function test_builds_add_rename_and_delete_scripts(): void
    {
        $catalog = app(ZteProfileCatalogService::class);
        $data = ['profile_type' => 'traffic', 'name' => 'UJI-1G', 'params' => ['sir' => 1024000, 'pir' => 1048064]];

        $this->assertSame("conf t\ngpon\nprofile traffic UJI-1G sir 1024000 pir 1048064\nexit", $catalog->buildScript('add', $data));
        $this->assertStringStartsWith("conf t\ngpon\nno profile traffic UJI-LAMA\nexit\n\n", $catalog->buildScript('add', $data, 'UJI-LAMA'));
        $this->assertSame("conf t\ngpon\nno profile traffic UJI-1G\nexit", $catalog->buildScript('delete', $data));
    }

    public function test_store_saves_traffic_profile_and_rejects_pir_below_sir(): void
    {
        $olt = SnmpOlt::create([
            'name' => 'OLT-UJI-C300',
            'vendor' => 'ZTE C300',
            'ip' => '10.30.0.32',
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

        $admin = User::factory()->admin()->create();
        $payload = fn (int $sir, int $pir) => [
            'profile_type' => 'traffic',
            'name' => 'UJI-1G',
            'params' => ['sir' => $sir, 'pir' => $pir],
            'is_active' => true,
            'execute_cli' => false,
        ];

        $this->actingAs($admin)
            ->post(route('smartolt.profiles.store', $olt), $payload(1024000, 512000))
            ->assertSessionHasErrors('params.pir');

        $this->actingAs($admin)
            ->post(route('smartolt.profiles.store', $olt), $payload(1024000, 1048064))
            ->assertRedirect(route('smartolt.profiles.index', $olt));

        $profile = SmartOltProfile::where('snmp_olt_id', $olt->id)->where('profile_type', 'traffic')->sole();
        $this->assertSame('UJI-1G', $profile->name);
        $this->assertSame(1048064, (int) $profile->params['pir']);
        $this->assertSame(0, $executor->calls, 'Tanpa centang Eksekusi CLI, OLT tak boleh disentuh.');
    }
}
