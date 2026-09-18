<?php

namespace Tests\Feature;

use App\Models\AlarmEvent;
use App\Models\Scopes\PartnerOltScope;
use App\Models\SnmpOlt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Role "operator": assignment OLT bersifat OPSIONAL (lihat {@see PartnerOltScope} +
 * {@see User::isOltScoped()}). Tanpa assignment = akses penuh (backward-compatible);
 * dengan assignment = dibatasi ke OLT itu — TAPI tetap boleh mengelola inventori OLT.
 */
class OperatorOltScopeTest extends TestCase
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
            'last_test_result' => ['ok' => true, 'port_onus' => []],
        ]);
    }

    public function test_operator_without_assignment_sees_all_olts(): void
    {
        $this->makeOlt('OLT-A', '10.8.0.1');
        $this->makeOlt('OLT-B', '10.8.0.2');

        $operator = User::factory()->create(); // default role = operator, tanpa assignment
        $this->actingAs($operator);

        $this->assertSame(2, SnmpOlt::query()->count());
    }

    public function test_operator_with_assignment_is_scoped_to_assigned(): void
    {
        $assigned = $this->makeOlt('OLT-ASSIGNED', '10.8.1.1');
        $other = $this->makeOlt('OLT-OTHER', '10.8.1.2');

        $operator = User::factory()->create();
        $operator->partnerOlts()->sync([$assigned->id]);

        $this->actingAs($operator);

        $this->assertSame([$assigned->id], SnmpOlt::query()->pluck('id')->all());
    }

    public function test_operator_gets_404_opening_unassigned_olt(): void
    {
        $assigned = $this->makeOlt('OLT-ASSIGNED', '10.8.2.1');
        $other = $this->makeOlt('OLT-OTHER', '10.8.2.2');

        $operator = User::factory()->create();
        $operator->partnerOlts()->sync([$assigned->id]);

        $this->actingAs($operator)->get(route('smartolt.edit', $assigned))->assertOk();
        $this->actingAs($operator)->get(route('smartolt.edit', $other))->assertNotFound();
    }

    public function test_scoped_operator_alarms_are_scoped_to_assigned_olts(): void
    {
        $assigned = $this->makeOlt('OLT-ASSIGNED', '10.8.3.1');
        $other = $this->makeOlt('OLT-OTHER', '10.8.3.2');

        $operator = User::factory()->create();
        $operator->partnerOlts()->sync([$assigned->id]);

        $this->makeAlarm($assigned, 'los-assigned');
        $this->makeAlarm($other, 'los-other');

        $this->actingAs($operator);

        $this->assertSame(['los-assigned'], AlarmEvent::query()->pluck('signature')->all());
    }

    public function test_scoped_operator_still_manages_olt_inventory(): void
    {
        $assigned = $this->makeOlt('OLT-ASSIGNED', '10.8.4.1');

        $operator = User::factory()->create();
        $operator->partnerOlts()->sync([$assigned->id]);

        // Berbeda dari partner: operator tetap boleh membuka form & membuat OLT baru.
        $this->actingAs($operator)->get(route('smartolt.create'))->assertOk();
        $this->actingAs($operator)->post(route('smartolt.store'), [
            'name' => 'OLT-BARU', 'vendor' => 'ZTE C320', 'ip' => '10.8.4.9',
            'snmp_port' => 161, 'snmp_read_community' => 'public', 'snmp_version' => 'v2c',
        ])->assertRedirect();

        $this->assertDatabaseHas('snmp_olts', ['name' => 'OLT-BARU']);
    }

    public function test_admin_stores_olt_assignment_for_operator(): void
    {
        $olt = $this->makeOlt('OLT-ASSIGNED', '10.8.5.1');
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'Operator Scoped',
            'email' => 'op.scoped@contoh.com',
            'role' => 'operator',
            'password' => 'password123',
            'olt_ids' => [$olt->id],
        ])->assertRedirect();

        $created = User::where('email', 'op.scoped@contoh.com')->firstOrFail();
        $this->assertSame([$olt->id], $created->partnerOlts()->pluck('snmp_olts.id')->all());
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
