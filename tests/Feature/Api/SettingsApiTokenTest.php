<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsApiTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_api_token_and_use_it(): void
    {
        $admin = User::factory()->admin()->create();

        $res = $this->actingAs($admin)->post(route('settings.api-tokens.store'), [
            'name' => 'Web Billing',
        ]);

        $res->assertRedirect();
        $res->assertSessionHas('apiToken');
        $this->assertSame(1, $admin->tokens()->count());

        // Token plain-text hasil flash harus benar-benar bisa dipakai di API.
        $plain = session('apiToken');
        $this->withHeader('Authorization', "Bearer {$plain}")
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.id', $admin->id);
    }

    public function test_admin_can_revoke_token(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('temp');
        $id = $token->accessToken->id;

        $this->actingAs($admin)
            ->delete(route('settings.api-tokens.destroy', $id))
            ->assertRedirect();

        $this->assertSame(0, $admin->tokens()->count());
    }

    public function test_non_admin_cannot_manage_tokens(): void
    {
        $operator = User::factory()->create(); // default role operator

        $this->actingAs($operator)
            ->post(route('settings.api-tokens.store'), ['name' => 'x'])
            ->assertForbidden();
    }
}
