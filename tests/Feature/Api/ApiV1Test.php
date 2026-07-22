<?php

namespace Tests\Feature\Api;

use App\Models\SnmpOlt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiV1Test extends TestCase
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
                            ['onu_id' => 1, 'slot' => 1, 'port' => 1, 'serial_number' => 'ZTEGC0000001', 'name' => 'Budi', 'online' => true, 'rx_power_dbm' => -20.0],
                            ['onu_id' => 2, 'slot' => 1, 'port' => 1, 'serial_number' => 'ZTEGC0000002', 'name' => 'Andi', 'online' => false],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function test_login_returns_a_bearer_token(): void
    {
        User::factory()->admin()->create(['email' => 'admin@bmkv.net', 'password' => 'secret123']);

        $res = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@bmkv.net',
            'password' => 'secret123',
            'device_name' => 'phpunit',
        ]);

        $res->assertOk()
            ->assertJsonStructure(['data' => ['token', 'token_type', 'user' => ['id', 'email', 'role']]]);
        $this->assertNotEmpty($res->json('data.token'));
    }

    public function test_login_rejects_bad_credentials(): void
    {
        User::factory()->create(['email' => 'a@b.net', 'password' => 'secret123']);

        $this->postJson('/api/v1/auth/login', ['email' => 'a@b.net', 'password' => 'wrong'])
            ->assertStatus(422);
    }

    public function test_protected_endpoint_requires_token(): void
    {
        $this->getJson('/api/v1/onus')->assertStatus(401);
    }

    public function test_onus_endpoint_returns_inventory(): void
    {
        $this->seedOlt();
        $user = User::factory()->create();

        $res = $this->actingAs($user, 'sanctum')->getJson('/api/v1/onus');

        $res->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.serial_number', 'ZTEGC0000001');
    }

    public function test_onus_endpoint_filters_offline(): void
    {
        $this->seedOlt();
        $user = User::factory()->create();

        $res = $this->actingAs($user, 'sanctum')->getJson('/api/v1/onus?status=offline');

        $res->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.onu_id', 2);
    }

    public function test_single_onu_detail(): void
    {
        $olt = $this->seedOlt();
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->getJson("/api/v1/olts/{$olt->id}/onus/1/1/1")
            ->assertOk()
            ->assertJsonPath('data.name', 'Budi');

        $this->actingAs($user, 'sanctum')->getJson("/api/v1/olts/{$olt->id}/onus/1/1/99")
            ->assertStatus(404);
    }

    public function test_olts_and_detail(): void
    {
        $olt = $this->seedOlt();
        // Deskripsi port PON hasil parse CLI — harus ikut terekspos di payload ports.
        $olt->interfaceStatuses()->create([
            'interface' => 'gpon-olt_1/1/1',
            'interface_type' => 'gpon',
            'slot' => 1,
            'port' => 1,
            'description' => 'KETANEN LAMA',
        ]);
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/olts')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'OLT-C320-PATI')
            ->assertJsonPath('data.0.onu_total', 2)
            ->assertJsonPath('data.0.onu_online', 1);

        $this->actingAs($user, 'sanctum')->getJson("/api/v1/olts/{$olt->id}")
            ->assertOk()
            ->assertJsonPath('data.system.sys_name', 'OLT-C320-PATI')
            ->assertJsonPath('data.ports.0.onu_online', 1)
            ->assertJsonPath('data.ports.0.description', 'KETANEN LAMA');
    }

    public function test_public_status_needs_no_token(): void
    {
        $this->seedOlt();

        $this->getJson('/api/v1/public/status')
            ->assertOk()
            ->assertJsonPath('data.olt.total', 1)
            ->assertJsonPath('data.onu.online', 1)
            ->assertJsonPath('data.olts.0.name', 'OLT-C320-PATI')
            // Tidak boleh membocorkan data pelanggan / IP OLT.
            ->assertJsonMissing(['ip' => '10.40.0.2'])
            ->assertJsonMissingPath('data.olts.0.onus');
    }

    public function test_summary_endpoint(): void
    {
        $this->seedOlt();
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/summary')
            ->assertOk()
            ->assertJsonPath('data.olt.total', 1)
            ->assertJsonPath('data.onu.total', 2)
            ->assertJsonPath('data.onu.online', 1);
    }
}
