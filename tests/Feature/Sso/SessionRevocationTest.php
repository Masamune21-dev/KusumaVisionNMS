<?php

namespace Tests\Feature\Sso;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Perilaku {@see \App\Http\Middleware\EnsureSsoSessionAlive}.
 */
class SessionRevocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sesi_tetap_hidup_selama_sid_ada(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')->assertOk();
        $this->assertAuthenticated();
    }

    /**
     * Inilah yang membuat logout di app lain — dan pencabutan akses oleh admin —
     * terasa pada request BERIKUTNYA, bukan menunggu sesi lokal kedaluwarsa.
     */
    public function test_sid_yang_hilang_langsung_menendang_pengguna(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Redis::connection('sso')->del('sid:'.session('sso.sid'));

        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_sesi_tanpa_sid_sama_sekali_ditolak(): void
    {
        // Sesi peninggalan sebelum SSO aktif: tidak boleh diperlakukan sah.
        $user = User::factory()->create();

        $this->be($user);
        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /**
     * TTL sid diperpanjang tiap request, supaya pengguna yang sibuk di satu app
     * tidak dianggap menganggur oleh pusat.
     */
    public function test_aktivitas_memperpanjang_umur_sesi_hub(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $key = 'sid:'.session('sso.sid');
        Redis::connection('sso')->expire($key, 10);
        $this->assertLessThanOrEqual(10, Redis::connection('sso')->ttl($key));

        $this->get('/dashboard')->assertOk();

        $this->assertGreaterThan(10, Redis::connection('sso')->ttl($key));
    }

    public function test_sesi_darurat_tidak_butuh_sid(): void
    {
        // Login darurat memang dipakai justru ketika IdP tak bisa dihubungi.
        $user = User::factory()->create();

        $this->be($user);
        $this->withSession(['sso.emergency' => true]);

        $this->get('/dashboard')->assertOk();
        $this->assertAuthenticated();
    }
}
