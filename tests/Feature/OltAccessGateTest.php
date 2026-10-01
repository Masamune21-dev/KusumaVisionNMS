<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\SnmpOlt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OltAccessGateTest extends TestCase
{
    use RefreshDatabase;

    private function makeOlt(string $name, string $ip, ?int $ownerId = null): SnmpOlt
    {
        $olt = SnmpOlt::create([
            'name' => $name, 'vendor' => 'ZTE C320', 'ip' => $ip, 'snmp_port' => 161,
            'snmp_read_community' => 'public', 'snmp_version' => 'v2c',
            'cli_transport' => 'telnet', 'cli_port' => 23, 'cli_username' => 'zte', 'cli_password' => 'rahasia',
        ]);

        // owner_user_id diset controller (bukan mass-assignment) → tiru lewat forceFill.
        if ($ownerId !== null) {
            $olt->forceFill(['owner_user_id' => $ownerId])->save();
        }

        return $olt;
    }

    public function test_uplink_vlan_write_is_limited_to_admin_operator_or_the_owning_partner(): void
    {
        $global = $this->makeOlt('OLT-UJI-GLOBAL', '10.9.0.1');
        $partner = User::factory()->partner()->create();
        $partner->partnerOlts()->sync([$global->id]);
        $private = $this->makeOlt('OLT-UJI-MITRA', '10.9.0.2', $partner->id);

        $this->assertTrue(User::factory()->admin()->create()->canWriteOltUplinkConfig($global));
        $this->assertTrue(User::factory()->create(['role' => UserRole::Operator])->canWriteOltUplinkConfig($global));
        $this->assertFalse($partner->canWriteOltUplinkConfig($global));
        $this->assertTrue($partner->canWriteOltUplinkConfig($private));
        $this->assertFalse(User::factory()->partner()->create()->canWriteOltUplinkConfig($private));
        $this->assertFalse(User::factory()->demo()->create()->canWriteOltUplinkConfig($global));

        // Partner yang sekadar di-assign ke OLT global ditolak SEBELUM CLI (dulu langsung tag + write).
        $this->actingAs($partner)
            ->postJson(route('smartolt.port.vlan', $global), ['interface' => 'xgei_1/3/1', 'vlan_id' => 100])
            ->assertForbidden();
    }

    public function test_olt_list_offers_telnet_and_snmp_test_only_where_the_server_allows(): void
    {
        $global = $this->makeOlt('OLT-UJI-GLOBAL', '10.9.0.1');
        $partner = User::factory()->partner()->create();
        $private = $this->makeOlt('OLT-UJI-MITRA', '10.9.0.2', $partner->id);
        $partner->partnerOlts()->sync([$global->id, $private->id]);

        $this->actingAs($partner)->get(route('smartolt.index'))
            ->assertInertia(fn ($page) => $page
                ->where('olts', fn ($rows) => collect($rows)->firstWhere('id', $global->id)['can_telnet'] === false
                    && collect($rows)->firstWhere('id', $global->id)['connection_locked'] === true
                    && collect($rows)->firstWhere('id', $private->id)['can_telnet'] === true
                    && collect($rows)->firstWhere('id', $private->id)['connection_locked'] === false));
    }
}
