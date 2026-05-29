<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\SnmpOlt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_audit_logs_page(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('audit-logs.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('AuditLogs/Index'));
    }

    public function test_operator_cannot_view_audit_logs_page(): void
    {
        $operator = User::factory()->create(); // default operator

        $this->actingAs($operator)->get(route('audit-logs.index'))->assertForbidden();
    }

    public function test_model_changes_are_audited_without_secrets(): void
    {
        $admin = User::factory()->admin()->create();

        $olt = SnmpOlt::create([
            'name' => 'AUDIT-OLT',
            'ip' => '10.0.0.9',
            'snmp_port' => 161,
            'snmp_read_community' => 'top-secret',
            'snmp_version' => 'v2c',
            'cli_transport' => 'telnet',
        ]);

        $created = AuditLog::where('event', 'created')
            ->where('auditable_type', SnmpOlt::class)
            ->where('auditable_id', $olt->id)
            ->firstOrFail();

        $this->assertSame('Menambahkan OLT AUDIT-OLT', $created->description);
        $this->assertArrayNotHasKey('snmp_read_community', $created->properties['attributes']);

        $olt->update(['name' => 'AUDIT-OLT-2']);

        $updated = AuditLog::where('event', 'updated')->where('auditable_id', $olt->id)->firstOrFail();
        $this->assertSame('AUDIT-OLT', $updated->properties['old']['name']);
        $this->assertSame('AUDIT-OLT-2', $updated->properties['new']['name']);
    }

    public function test_audit_log_can_be_filtered_by_event(): void
    {
        $admin = User::factory()->admin()->create();

        AuditLog::create(['event' => 'login', 'description' => 'Login ke sistem', 'user_name' => 'A']);
        AuditLog::create(['event' => 'deleted', 'description' => 'Menghapus OLT X', 'user_name' => 'A']);

        $this->actingAs($admin)
            ->get(route('audit-logs.index', ['event' => 'login']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('AuditLogs/Index')
                ->where('logs.total', 1)
                ->where('logs.data.0.event', 'login'));
    }
}
