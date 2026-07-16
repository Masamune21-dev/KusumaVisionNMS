<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_switch_locale_and_it_persists_in_session(): void
    {
        $response = $this->from('/login')->post('/locale', ['locale' => 'en']);

        $response->assertRedirect('/login');
        $this->assertSame('en', session('locale'));
    }

    public function test_authenticated_user_locale_is_saved_to_profile(): void
    {
        $user = User::factory()->create(['locale' => null]);

        $this->actingAs($user)->post('/locale', ['locale' => 'en'])->assertRedirect();

        $this->assertSame('en', $user->fresh()->locale);
    }

    public function test_unsupported_locale_is_rejected(): void
    {
        $this->post('/locale', ['locale' => 'fr'])->assertSessionHasErrors('locale');
        $this->assertNull(session('locale'));
    }

    public function test_set_locale_middleware_applies_user_preference(): void
    {
        $user = User::factory()->create(['locale' => 'en']);

        // Any authenticated page runs the SetLocale middleware.
        $this->actingAs($user)->get('/dashboard');

        $this->assertSame('en', app()->getLocale());
    }

    public function test_locale_is_shared_to_inertia(): void
    {
        $user = User::factory()->create(['locale' => 'en']);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('locale', 'en')
                ->has('locales', 2)
            );
    }
}
