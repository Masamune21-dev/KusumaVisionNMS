<?php

namespace Tests\Feature\Sso;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\SimulatesSso;
use Tests\TestCase;

class SsoClientTest extends TestCase
{
    use RefreshDatabase, SimulatesSso;

    public function test_tamu_diarahkan_ke_idp_dengan_pkce(): void
    {
        $response = $this->get('/sso/redirect');

        $location = $response->headers->get('Location');
        $this->assertStringStartsWith(config('sso.issuer').'/authorize', $location);

        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame(config('sso.client_id'), $query['client_id']);
        $this->assertSame(config('sso.redirect_uri'), $query['redirect_uri']);
        $this->assertSame('S256', $query['code_challenge_method']);

        // Verifier tetap di session dan TIDAK ikut terkirim — itu inti PKCE.
        $this->assertNotEmpty(session('sso.verifier'));
        $this->assertArrayNotHasKey('code_verifier', $query);
        $this->assertSame(
            \App\Support\Pkce::challengeFor(session('sso.verifier')),
            $query['code_challenge'],
        );
    }

    public function test_halaman_terlindungi_mengarah_ke_alur_sso(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_tidak_ada_lagi_form_login_lokal(): void
    {
        // Password hanya boleh dikirim ke satu origin: IdP. /login masih ada
        // sebagai pengalihan untuk bookmark lama, tapi hanya menerima GET —
        // tidak ada lagi endpoint yang menerima kiriman kredensial di sini.
        $this->post('/login', ['email' => 'a@b.test', 'password' => 'x'])
            ->assertMethodNotAllowed();
        $this->get('/login')->assertRedirect(route('login'));

        $this->get('/register')->assertNotFound();
        $this->get('/forgot-password')->assertNotFound();
        $this->post('/reset-password', [])->assertNotFound();
    }

    public function test_state_yang_tidak_cocok_ditolak(): void
    {
        $this->fakeIdpClaims();

        $this->withSession($this->ssoRedirectSession())
            ->get($this->ssoCallbackUrl(state: 'state-milik-orang-lain'))
            ->assertForbidden();

        $this->assertGuest();
        Http::assertNothingSent();
    }

    public function test_callback_tanpa_sesi_redirect_ditolak(): void
    {
        $this->fakeIdpClaims();

        $this->get($this->ssoCallbackUrl())->assertForbidden();

        $this->assertGuest();
        Http::assertNothingSent();
    }

    public function test_callback_sah_menautkan_dan_melogin_pengguna_yang_sudah_ada(): void
    {
        $user = User::factory()->create([
            'email' => 'orang@example.test',
            'role' => UserRole::Demo,
        ]);

        $claims = $this->fakeIdpClaims(['role' => 'operator']);

        $this->withSession($this->ssoRedirectSession())
            ->get($this->ssoCallbackUrl())
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user->fresh());

        $user->refresh();
        $this->assertSame($claims['sub'], $user->sso_user_id);
        // Role dari IdP menang atas role lokal — IdP adalah sumber kebenarannya.
        $this->assertSame(UserRole::Operator, $user->role);
        $this->assertSame($claims['sid'], session('sso.sid'));
        $this->assertSame(['nms'], session('sso.apps'));
    }

    public function test_pencocokan_memakai_sso_user_id_walau_email_berubah(): void
    {
        $ssoId = (string) Str::uuid();
        $user = User::factory()->create([
            'email' => 'email-lama@example.test',
            'sso_user_id' => $ssoId,
        ]);

        $this->fakeIdpClaims(['sub' => $ssoId, 'email' => 'email-baru@example.test']);

        $this->withSession($this->ssoRedirectSession())->get($this->ssoCallbackUrl());

        $this->assertAuthenticatedAs($user->fresh());
        $this->assertSame('email-baru@example.test', $user->fresh()->email);
    }

    public function test_role_yang_tidak_dikenal_app_ini_ditolak(): void
    {
        User::factory()->create(['email' => 'orang@example.test']);

        // "kasir" hanya ada di Billing.
        $this->fakeIdpClaims(['role' => 'kasir']);

        $this->withSession($this->ssoRedirectSession())
            ->get($this->ssoCallbackUrl())
            ->assertForbidden();

        $this->assertGuest();
    }

    public function test_idp_yang_tidak_bisa_dihubungi_tidak_menerbitkan_sesi(): void
    {
        Http::fake([
            rtrim(config('sso.issuer'), '/').'/oauth/token' => Http::response(
                ['error' => 'invalid_grant', 'error_description' => 'Kode kedaluwarsa'], 400
            ),
        ]);

        $this->withSession($this->ssoRedirectSession())
            ->get($this->ssoCallbackUrl())
            ->assertStatus(503);

        $this->assertGuest();
    }

    public function test_logout_membersihkan_sesi_lokal_lalu_menyerahkan_ke_idp(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $response->assertRedirect(config('sso.issuer').'/logout?client_id='.config('sso.client_id'));
        $this->assertGuest();
    }

    /**
     * Tombol logout di UI adalah <Link method="post"> — permintaan XHR Inertia.
     * Balasan 302 ke domain lain akan diikuti axios lalu gagal, jadi jawabannya
     * harus 409 + X-Inertia-Location supaya klien melakukan navigasi penuh.
     */
    public function test_logout_dari_inertia_memakai_navigasi_halaman_penuh(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->withHeaders(['X-Inertia' => 'true'])
            ->post('/logout');

        $response->assertStatus(409);
        $response->assertHeader(
            'X-Inertia-Location',
            config('sso.issuer').'/logout?client_id='.config('sso.client_id'),
        );
        $this->assertGuest();
    }
}
