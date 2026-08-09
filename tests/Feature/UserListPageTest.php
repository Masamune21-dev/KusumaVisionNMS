<?php

namespace Tests\Feature;

use App\Models\SnmpOlt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class UserListPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_list_is_sorted_by_role_hierarchy_then_name(): void
    {
        // Dibuat acak-acakan supaya urutan hasil benar-benar dari pengurutan, bukan urutan insert.
        User::factory()->partner()->create(['name' => 'Andian', 'email' => 'andian@example.test']);
        User::factory()->demo()->create(['name' => 'demo', 'email' => 'demo@example.test']);
        User::factory()->create(['name' => 'Kiky', 'email' => 'kiky@example.test']); // operator
        User::factory()->admin()->create(['name' => 'BMKV', 'email' => 'bmkv@example.test']);
        User::factory()->partner()->create(['name' => 'Alaik', 'email' => 'alaik@example.test']);
        User::factory()->create(['name' => 'NOC', 'email' => 'noc@example.test']); // operator

        $admin = User::factory()->admin()->create(['name' => 'Masamune', 'email' => 'masamune@example.test']);

        $this->actingAs($admin)
            ->get(route('users.index'))
            ->assertInertia(function (AssertableInertia $page) {
                $rows = collect($page->toArray()['props']['users']);

                // Administrator dulu, lalu Operator, Partner, Demo.
                $this->assertSame(
                    ['admin', 'admin', 'operator', 'operator', 'partner', 'partner', 'demo'],
                    $rows->pluck('role')->all(),
                );

                // Di dalam satu role, nama tetap menaik (mengandalkan sort stabil PHP 8).
                $this->assertSame(
                    ['BMKV', 'Masamune', 'Kiky', 'NOC', 'Alaik', 'Andian', 'demo'],
                    $rows->pluck('name')->all(),
                );
            });
    }

    public function test_partner_olt_count_includes_self_added_private_olts(): void
    {
        $partner = User::factory()->partner()->create(['name' => 'Mitra', 'email' => 'mitra@example.test']);

        // OLT global yang di-assign admin.
        $assigned = $this->makeOlt('OLT-GLOBAL', '10.0.0.1');
        $partner->partnerOlts()->attach($assigned->id);

        // Dua OLT privat yang ditambahkan partner sendiri. Alur `smartolt.store` juga
        // membuat baris pivot-nya, jadi ditiru di sini supaya tidak terhitung dobel.
        foreach ([['OLT-PRIVAT-1', '10.0.0.2'], ['OLT-PRIVAT-2', '10.0.0.3']] as [$name, $ip]) {
            $olt = $this->makeOlt($name, $ip);
            $olt->owner_user_id = $partner->id; // bukan fillable — set langsung
            $olt->save();
            $partner->partnerOlts()->attach($olt->id);
        }

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('users.index'))
            ->assertInertia(function (AssertableInertia $page) use ($assigned) {
                $row = collect($page->toArray()['props']['users'])->firstWhere('email', 'mitra@example.test');

                $this->assertSame(3, $row['total_olt_count'], 'total = 1 di-assign + 2 milik sendiri');
                $this->assertSame(2, $row['owned_olt_count']);

                // Form assign tetap hanya boleh mencentang OLT global: OLT privat partner
                // tersembunyi dari admin oleh PartnerOltScope, jadi tak boleh ikut di sini.
                $this->assertSame([$assigned->id], $row['assigned_olt_ids']);
            });
    }

    private function makeOlt(string $name, string $ip): SnmpOlt
    {
        return SnmpOlt::create([
            'name' => $name,
            'vendor' => 'ZTE C320',
            'ip' => $ip,
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
        ]);
    }
}
