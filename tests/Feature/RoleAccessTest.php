<?php

namespace Tests\Feature;

use App\Models\SnmpOlt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_access_user_management(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('users.index'))->assertOk();
    }

    public function test_operator_cannot_access_user_management(): void
    {
        $operator = User::factory()->create(); // default operator

        $this->actingAs($operator)->get(route('users.index'))->assertForbidden();
    }

    public function test_demo_user_is_blocked_from_write_requests(): void
    {
        $demo = User::factory()->demo()->create();
        $olt = SnmpOlt::create([
            'name' => 'X', 'vendor' => 'ZTE C320', 'ip' => '10.0.0.9',
            'snmp_port' => 161, 'snmp_read_community' => 'public', 'snmp_version' => 'v2c',
            'is_demo' => true,
        ]);

        $this->actingAs($demo)
            ->post(route('smartolt.test', $olt))
            ->assertForbidden();
    }

    public function test_demo_user_can_read_pages(): void
    {
        $demo = User::factory()->demo()->create();

        $this->actingAs($demo)->get(route('dashboard'))->assertOk();
        $this->actingAs($demo)->get(route('reports.index'))->assertOk();
    }

    public function test_last_admin_cannot_be_demoted(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put(route('users.update', $admin), [
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => 'operator',
            ])
            ->assertSessionHas('error');

        $this->assertTrue($admin->fresh()->isAdmin());
    }
}
