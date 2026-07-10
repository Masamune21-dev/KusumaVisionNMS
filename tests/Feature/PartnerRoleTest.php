<?php

namespace Tests\Feature;

use App\Models\AlarmEvent;
use App\Models\Scopes\PartnerOltScope;
use App\Models\SnmpOlt;
use App\Models\User;
use App\Services\ZteRemoteOnuService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Role "partner": mengelola OLT yang di-assign admin (global) DAN OLT PRIVAT yang
 * ia tambah sendiri (`owner_user_id` = id-nya). OLT privat tersembunyi total dari
 * admin/operator. Partner boleh menambah OLT (jadi privat miliknya) & menghapus OLT
 * miliknya, tapi TIDAK boleh menghapus OLT global yang sekadar di-assign. Ditegakkan
 * lewat {@see PartnerOltScope} + gate inventori/kepemilikan.
 */
class PartnerRoleTest extends TestCase
{
    use RefreshDatabase;

    private function makeOlt(string $name, string $ip): SnmpOlt
    {
        return SnmpOlt::create([
            'name' => $name,
            'vendor' => 'ZTE C320',
            'ip' => $ip,
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'cli_transport' => 'telnet',
            'last_test_result' => [
                'ok' => true,
                'port_onus' => ['1_1' => ['slot' => 1, 'port' => 1, 'onus' => [
                    ['onu_id' => 5, 'slot' => 1, 'port' => 1, 'if_index' => 123, 'name' => 'X', 'online' => true],
                ]]],
            ],
        ]);
    }

    /**
     * @return array{0: User, 1: SnmpOlt, 2: SnmpOlt}
     */
    private function partnerWithOneOfTwoOlts(): array
    {
        $assigned = $this->makeOlt('OLT-ASSIGNED', '10.9.0.1');
        $other = $this->makeOlt('OLT-OTHER', '10.9.0.2');

        $partner = User::factory()->partner()->create();
        $partner->partnerOlts()->sync([$assigned->id]);

        return [$partner, $assigned, $other];
    }

    public function test_partner_query_scope_hides_unassigned_olts(): void
    {
        [$partner, $assigned] = $this->partnerWithOneOfTwoOlts();

        $this->actingAs($partner);

        $this->assertSame([$assigned->id], SnmpOlt::query()->pluck('id')->all());
    }

    public function test_admin_still_sees_all_olts(): void
    {
        $this->partnerWithOneOfTwoOlts();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin);

        $this->assertSame(2, SnmpOlt::query()->count());
    }

    public function test_partner_index_only_lists_assigned_olt(): void
    {
        [$partner, $assigned, $other] = $this->partnerWithOneOfTwoOlts();

        $response = $this->actingAs($partner)->get(route('smartolt.index'))->assertOk();

        $response->assertSee($assigned->name);
        $response->assertDontSee($other->name);
    }

    public function test_partner_gets_404_opening_unassigned_olt(): void
    {
        [$partner, $assigned, $other] = $this->partnerWithOneOfTwoOlts();

        $this->actingAs($partner)->get(route('smartolt.edit', $assigned))->assertOk();
        $this->actingAs($partner)->get(route('smartolt.edit', $other))->assertNotFound();
    }

    public function test_partner_can_create_own_private_olt(): void
    {
        [$partner] = $this->partnerWithOneOfTwoOlts();

        $this->actingAs($partner)->get(route('smartolt.create'))->assertOk();
        $this->actingAs($partner)->post(route('smartolt.store'), [
            'name' => 'OLT-MILIK-PARTNER', 'vendor' => 'ZTE C320', 'ip' => '10.9.9.9',
            'snmp_port' => 161, 'snmp_read_community' => 'public', 'snmp_version' => 'v2c',
        ])->assertRedirect();

        $created = SnmpOlt::withoutGlobalScopes()->where('name', 'OLT-MILIK-PARTNER')->firstOrFail();

        // OLT jadi privat milik partner + baris pivot dibuat otomatis.
        $this->assertSame($partner->id, $created->owner_user_id);
        $this->assertDatabaseHas('olt_user', ['user_id' => $partner->id, 'snmp_olt_id' => $created->id]);
    }

    public function test_partner_private_olt_is_hidden_from_admin_and_operator(): void
    {
        [$partner] = $this->partnerWithOneOfTwoOlts();

        $this->actingAs($partner)->post(route('smartolt.store'), [
            'name' => 'OLT-PRIVAT', 'vendor' => 'ZTE C320', 'ip' => '10.9.9.10',
            'snmp_port' => 161, 'snmp_read_community' => 'public', 'snmp_version' => 'v2c',
        ])->assertRedirect();

        $private = SnmpOlt::withoutGlobalScopes()->where('name', 'OLT-PRIVAT')->firstOrFail();

        // Admin (tak ter-scope) TIDAK melihat OLT privat partner.
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $this->assertNotContains($private->id, SnmpOlt::query()->pluck('id')->all());
        $this->actingAs($admin)->get(route('smartolt.edit', $private))->assertNotFound();

        // Operator tanpa assignment (akses "penuh") pun tak melihatnya.
        $operator = User::factory()->create();
        $this->actingAs($operator);
        $this->assertNotContains($private->id, SnmpOlt::query()->pluck('id')->all());

        // Partner pemilik tetap melihatnya.
        $this->actingAs($partner);
        $this->assertContains($private->id, SnmpOlt::query()->pluck('id')->all());
    }

    public function test_partner_can_delete_own_olt(): void
    {
        [$partner] = $this->partnerWithOneOfTwoOlts();

        $this->actingAs($partner)->post(route('smartolt.store'), [
            'name' => 'OLT-HAPUS', 'vendor' => 'ZTE C320', 'ip' => '10.9.9.11',
            'snmp_port' => 161, 'snmp_read_community' => 'public', 'snmp_version' => 'v2c',
        ])->assertRedirect();

        $own = SnmpOlt::withoutGlobalScopes()->where('name', 'OLT-HAPUS')->firstOrFail();

        $this->actingAs($partner)->delete(route('smartolt.destroy', $own))->assertRedirect();
        $this->assertDatabaseMissing('snmp_olts', ['id' => $own->id]);
    }

    public function test_partner_cannot_delete_assigned_global_olt(): void
    {
        [$partner, $assigned] = $this->partnerWithOneOfTwoOlts();

        // OLT global yang sekadar di-assign (owner_user_id null) tak boleh dihapus partner.
        $this->actingAs($partner)->delete(route('smartolt.destroy', $assigned))->assertForbidden();

        $this->assertDatabaseHas('snmp_olts', ['id' => $assigned->id]);
    }

    public function test_partner_can_edit_assigned_olt(): void
    {
        [$partner, $assigned] = $this->partnerWithOneOfTwoOlts();

        $this->actingAs($partner)
            ->put(route('smartolt.update', $assigned), [
                'name' => 'OLT-RENAMED', 'vendor' => 'ZTE C320', 'ip' => '10.9.0.1',
                'snmp_port' => 161, 'snmp_version' => 'v2c', 'cli_transport' => 'telnet',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('snmp_olts', ['id' => $assigned->id, 'name' => 'OLT-RENAMED']);
    }

    public function test_partner_alarms_are_scoped_to_assigned_olts(): void
    {
        [$partner, $assigned, $other] = $this->partnerWithOneOfTwoOlts();

        $this->makeAlarm($assigned, 'los-assigned');
        $this->makeAlarm($other, 'los-other');

        $this->actingAs($partner);

        $signatures = AlarmEvent::query()->pluck('signature')->all();
        $this->assertSame(['los-assigned'], $signatures);
    }

    public function test_partner_cannot_access_users_or_settings(): void
    {
        [$partner] = $this->partnerWithOneOfTwoOlts();

        $this->actingAs($partner)->get(route('users.index'))->assertForbidden();
        $this->actingAs($partner)->get(route('settings.edit'))->assertForbidden();
    }

    // --- API v1 (mobile) ---------------------------------------------------

    public function test_partner_api_lists_only_assigned_olts(): void
    {
        [$partner, $assigned, $other] = $this->partnerWithOneOfTwoOlts();

        $this->actingAs($partner, 'sanctum')
            ->getJson('/api/v1/olts')
            ->assertOk()
            ->assertJsonFragment(['name' => $assigned->name])
            ->assertJsonMissing(['name' => $other->name]);
    }

    public function test_partner_api_reboot_allowed_on_assigned_404_on_other(): void
    {
        [$partner, $assigned, $other] = $this->partnerWithOneOfTwoOlts();

        $mock = Mockery::mock(ZteRemoteOnuService::class);
        $mock->shouldReceive('reboot')->once()->andReturn(['ok' => true, 'error' => null]);
        $this->app->instance(ZteRemoteOnuService::class, $mock);

        $this->actingAs($partner, 'sanctum')
            ->postJson("/api/v1/olts/{$assigned->id}/onus/1/1/5/reboot")
            ->assertOk()
            ->assertJsonPath('data.ok', true);

        $this->actingAs($partner, 'sanctum')
            ->postJson("/api/v1/olts/{$other->id}/onus/1/1/5/reboot")
            ->assertNotFound();
    }

    private function makeAlarm(SnmpOlt $olt, string $signature): AlarmEvent
    {
        return AlarmEvent::create([
            'snmp_olt_id' => $olt->id,
            'signature' => $signature,
            'type' => 'los',
            'severity' => AlarmEvent::SEVERITY_MAJOR,
            'status' => AlarmEvent::STATUS_ACTIVE,
            'scope' => 'onu',
            'message' => 'LOS',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
    }
}
