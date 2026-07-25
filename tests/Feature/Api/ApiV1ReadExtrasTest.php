<?php

namespace Tests\Feature\Api;

use App\Models\SmartOltProfile;
use App\Models\SnmpOlt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Endpoint baca tambahan untuk aplikasi mobile: search, ONU per port,
 * unconfigured, register options, dan kapabilitas pada detail OLT.
 */
class ApiV1ReadExtrasTest extends TestCase
{
    use RefreshDatabase;

    private function seedOlt(): SnmpOlt
    {
        return SnmpOlt::create([
            'name' => 'OLT-C320-PATI',
            'vendor' => 'ZTE C320',
            'ip' => '10.40.0.2',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'polling_enabled' => true,
            'last_test_result' => [
                'ok' => true,
                'system' => ['sys_name' => 'OLT-C320-PATI', 'sys_descr' => 'ZTE ZXA10 C320'],
                'ports' => [
                    ['name' => 'gpon-olt_1/1/1', 'slot' => 1, 'port' => 1, 'if_index' => 285278209, 'oper_status' => 'up'],
                ],
                'port_onus' => [
                    '1_1' => [
                        'slot' => 1, 'port' => 1, 'count' => 2, 'refreshed_at' => '2026-06-28T10:00:00+07:00',
                        'onus' => [
                            ['onu_id' => 2, 'slot' => 1, 'port' => 1, 'serial_number' => 'ZTEGC0000002', 'name' => 'Andi', 'online' => false],
                            ['onu_id' => 1, 'slot' => 1, 'port' => 1, 'serial_number' => 'ZTEGC0000001', 'name' => 'Budi', 'online' => true, 'rx_power_dbm' => -20.0],
                        ],
                    ],
                ],
                'unconfigured_onus' => [
                    'ok' => true, 'count' => 1, 'refreshed_at' => '2026-06-28T10:05:00+07:00',
                    'onus' => [['serial_number' => 'ZTEGCNEW0001', 'slot' => 1, 'port' => 1]],
                ],
            ],
        ]);
    }

    public function test_search_finds_olt_and_onu(): void
    {
        $olt = $this->seedOlt();
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/search?q=PATI')
            ->assertOk()
            ->assertJsonPath('data.0.type', 'olt')
            ->assertJsonPath('data.0.olt_id', $olt->id);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/search?q=ZTEGC0000001')
            ->assertOk()
            ->assertJsonPath('data.0.type', 'onu')
            ->assertJsonPath('data.0.serial_number', 'ZTEGC0000001')
            ->assertJsonPath('data.0.slot', 1)
            ->assertJsonPath('data.0.onu_id', 1);
    }

    public function test_search_ignores_short_queries(): void
    {
        $this->seedOlt();
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/search?q=a')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_port_onus_endpoint_sorted_by_onu_id(): void
    {
        $olt = $this->seedOlt();
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->getJson("/api/v1/olts/{$olt->id}/ports/1/1/onus")
            ->assertOk()
            ->assertJsonPath('meta.count', 2)
            ->assertJsonPath('meta.refreshed_at', '2026-06-28T10:00:00+07:00')
            ->assertJsonPath('data.0.onu_id', 1)
            ->assertJsonPath('data.1.onu_id', 2);
    }

    public function test_unconfigured_endpoint(): void
    {
        $olt = $this->seedOlt();
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->getJson("/api/v1/olts/{$olt->id}/unconfigured")
            ->assertOk()
            ->assertJsonPath('meta.count', 1)
            ->assertJsonPath('data.0.serial_number', 'ZTEGCNEW0001');
    }

    public function test_register_options_returns_defaults_and_capabilities(): void
    {
        $olt = $this->seedOlt();
        SmartOltProfile::create([
            'snmp_olt_id' => $olt->id, 'profile_type' => 'onu_type', 'name' => 'ALL-ONT', 'is_active' => true,
        ]);
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->getJson("/api/v1/olts/{$olt->id}/register/options?slot=1&port=1")
            ->assertOk()
            ->assertJsonPath('data.capabilities.supports_provisioning', true)
            ->assertJsonPath('data.defaults.slot', 1)
            // ONU 1 & 2 dipakai → saran berikutnya = 3.
            ->assertJsonPath('data.defaults.onu_id', 3)
            ->assertJsonPath('data.profiles.onu_type.0.name', 'ALL-ONT');
    }

    public function test_olt_detail_includes_capabilities(): void
    {
        $olt = $this->seedOlt();
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->getJson("/api/v1/olts/{$olt->id}")
            ->assertOk()
            ->assertJsonPath('data.capabilities.driver', 'zte')
            ->assertJsonPath('data.capabilities.supports_provisioning', true);
    }
}
