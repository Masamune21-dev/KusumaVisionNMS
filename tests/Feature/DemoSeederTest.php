<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AlarmEvent;
use App\Models\PollingEvent;
use App\Models\SnmpOlt;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seeder_populates_demo_data(): void
    {
        $this->seed(DemoSeeder::class);

        $demo = User::where('email', 'demo@kusumavision.test')->first();
        $this->assertNotNull($demo);
        $this->assertSame(UserRole::Demo, $demo->role);

        $this->assertGreaterThanOrEqual(1, SnmpOlt::withoutGlobalScopes()->where('is_demo', true)->count());
        $this->assertGreaterThanOrEqual(1, AlarmEvent::withoutGlobalScopes()->where('is_demo', true)->count());
        $this->assertGreaterThanOrEqual(1, PollingEvent::withoutGlobalScopes()->where('is_demo', true)->count());
    }

    public function test_demo_data_is_isolated_from_real_users(): void
    {
        // Data nyata (is_demo = false).
        SnmpOlt::create([
            'name' => 'OLT-REAL', 'vendor' => 'ZTE C320', 'ip' => '10.99.0.1',
            'snmp_port' => 161, 'snmp_read_community' => 'public', 'snmp_version' => 'v2c',
        ]);

        $this->seed(DemoSeeder::class);

        $admin = User::factory()->admin()->create();
        $demo = User::where('email', 'demo@kusumavision.test')->first();

        // Admin (user nyata) hanya melihat OLT nyata, tidak melihat OLT demo.
        $this->actingAs($admin)
            ->get(route('smartolt.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('olts', 1));

        // User demo hanya melihat OLT demo (2 dari seeder), bukan OLT nyata.
        $this->actingAs($demo)
            ->get(route('smartolt.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('olts', 2));
    }
}
