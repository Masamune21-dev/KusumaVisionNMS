<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Locale;
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

    public function test_locale_choice_is_written_to_cookie(): void
    {
        $this->post('/locale', ['locale' => 'en'])
            ->assertCookie(Locale::COOKIE, 'en');
    }

    public function test_locale_cookie_survives_logout_and_applies_for_guest(): void
    {
        // Skenario bug: user pilih 'en', response memasang cookie preferensi
        // (terenkripsi). Setelah logout meng-invalidate session, request tamu
        // berikutnya yang membawa cookie itu harus tetap berbahasa 'en'.
        $cookie = $this->post('/locale', ['locale' => 'en'])->getCookie(Locale::COOKIE);

        $this->withCookie(Locale::COOKIE, $cookie->getValue())->get('/');

        $this->assertSame('en', app()->getLocale());
    }

    public function test_login_adopts_guest_chosen_locale(): void
    {
        // Skenario: tamu klik ganti bahasa ke 'en' di layar login (mengisi
        // session('locale')), lalu login ke akun ber-preferensi 'id'. Bahasa
        // pilihan tamu harus diadopsi jadi preferensi akun → dashboard ikut 'en'.
        $user = User::factory()->create(['locale' => 'id']);

        $this->withSession(['locale' => 'en'])
            ->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertSame('en', $user->fresh()->locale);
    }

    public function test_login_keeps_account_locale_when_guest_did_not_toggle(): void
    {
        // Tanpa klik switcher (session tak punya 'locale'), preferensi akun
        // harus tetap dihormati — bukan tertimpa cookie/ambient tamu.
        $user = User::factory()->create(['locale' => 'id']);

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->assertSame('id', $user->fresh()->locale);
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
