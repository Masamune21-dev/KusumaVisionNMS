<?php

namespace Tests\Feature;

use App\Models\GenieacsCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pengaturan NBI GenieACS (tab ACS). Menjaga tiga hal yang mudah rusak diam-diam:
 * password tidak pernah bocor ke browser, field kosong berarti "pertahankan",
 * dan endpoint ini hanya untuk admin.
 */
class SettingsGenieacsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'host' => '192.0.2.10',
            'port' => 7557,
            'username' => 'nms',
            'password' => 'rahasia-nbi',
        ], $overrides);
    }

    public function test_admin_can_save_genieacs_settings(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put(route('settings.genieacs.update'), $this->payload())
            ->assertRedirect();

        $setting = GenieacsCredential::instance();

        $this->assertSame('192.0.2.10', $setting->host);
        $this->assertSame(7557, $setting->port);
        $this->assertSame('nms', $setting->username);
        $this->assertSame('rahasia-nbi', $setting->password);
    }

    public function test_blank_password_keeps_the_stored_one(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put(route('settings.genieacs.update'), $this->payload());
        $this->actingAs($admin)->put(route('settings.genieacs.update'), $this->payload([
            'host' => '192.0.2.11',
            'password' => '',
        ]));

        $setting = GenieacsCredential::instance();

        $this->assertSame('192.0.2.11', $setting->host);
        $this->assertSame('rahasia-nbi', $setting->password);
    }

    public function test_password_is_never_sent_to_the_browser(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->put(route('settings.genieacs.update'), $this->payload());

        $this->actingAs($admin)
            ->get(route('settings.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('genieacs.host', '192.0.2.10')
                ->where('genieacs.password_set', true)
                ->missing('genieacs.password')
            );
    }

    public function test_changing_target_resets_the_connection_status(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->put(route('settings.genieacs.update'), $this->payload());

        $setting = GenieacsCredential::instance();
        $setting->is_connected = true;
        $setting->last_test_at = now();
        $setting->save();

        $this->actingAs($admin)->put(route('settings.genieacs.update'), $this->payload([
            'host' => '192.0.2.12',
        ]));

        $setting = GenieacsCredential::instance()->refresh();

        $this->assertFalse($setting->is_connected);
        $this->assertNull($setting->last_test_at);
    }

    public function test_test_connection_records_success(): void
    {
        Http::fake([
            '*/devices*' => Http::response([['_id' => 'dummy']], 200),
            '*/users*' => Http::response(['role' => 'admin'], 200),
        ]);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->put(route('settings.genieacs.update'), $this->payload());

        $this->actingAs($admin)
            ->post(route('settings.genieacs.test'))
            ->assertRedirect()
            ->assertSessionHas('success');

        $setting = GenieacsCredential::instance();

        $this->assertTrue($setting->is_connected);
        $this->assertNotNull($setting->last_test_at);
        $this->assertNull($setting->last_test_error);
    }

    public function test_test_connection_records_failure(): void
    {
        Http::fake(['*' => Http::response('nope', 500)]);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->put(route('settings.genieacs.update'), $this->payload());

        $this->actingAs($admin)
            ->post(route('settings.genieacs.test'))
            ->assertRedirect()
            ->assertSessionHas('error');

        $setting = GenieacsCredential::instance();

        $this->assertFalse($setting->is_connected);
        $this->assertNotNull($setting->last_test_at);
    }

    public function test_test_connection_refuses_when_host_is_empty(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('settings.genieacs.test'))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_non_admin_cannot_touch_genieacs_settings(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);

        $this->actingAs($operator)
            ->put(route('settings.genieacs.update'), $this->payload())
            ->assertForbidden();

        $this->actingAs($operator)
            ->post(route('settings.genieacs.test'))
            ->assertForbidden();
    }

    public function test_host_may_carry_a_scheme(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->put(route('settings.genieacs.update'), $this->payload([
            'host' => 'https://acs.internal',
        ]));

        Http::fake(['*' => Http::response([], 200)]);

        $this->actingAs($admin)->post(route('settings.genieacs.test'));

        // Skema pada host dipakai apa adanya, bukan dipaksa http.
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://acs.internal:7557/'));
    }
}
