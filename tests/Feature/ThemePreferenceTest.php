<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Theme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Preferensi tema antarmuka (gelap / terang / ikut sistem).
 *
 * Disimpan di dua tempat dengan alasan berbeda: kolom `users.theme` supaya
 * pilihan ikut berpindah perangkat, dan cookie `kv_theme` supaya render
 * pertama server sudah bertema benar — localStorage tidak ikut dalam
 * permintaan dokumen, jadi tanpa cookie pasti ada kedipan. Cookie bersifat
 * host-only.
 */
class ThemePreferenceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function bawaannya_gelap_selama_pengguna_belum_memilih(): void
    {
        $user = User::factory()->admin()->create(['theme' => null]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-theme="dark"', false)
            ->assertInertia(fn (Assert $page) => $page->where('theme', Theme::DARK));
    }

    #[Test]
    public function pilihan_tersimpan_di_kolom_dan_cookie(): void
    {
        $user = User::factory()->admin()->create();

        $response = $this->actingAs($user)
            ->patch(route('profile.theme'), ['theme' => Theme::LIGHT]);

        $response->assertRedirect();
        $this->assertSame(Theme::LIGHT, $user->fresh()->theme);

        // Cookie WAJIB tidak terenkripsi: JavaScript ikut menulis dan membacanya.
        // assertCookie akan mencoba mendekripsi dan justru lolos kalau cookienya
        // terenkripsi — persis keadaan yang harus dicegah.
        $response->assertPlainCookie(Theme::COOKIE, Theme::LIGHT);
    }

    #[Test]
    public function panggilan_xhr_dijawab_204_tanpa_memuat_ulang_halaman(): void
    {
        // Jalur yang dipakai lib/theme.js (axios). Jawaban 204 — bukan redirect —
        // karena redirect membuat Inertia memuat ulang halaman dan menghapus
        // konten sekali-tampil seperti token API baru di Pengaturan.
        $user = User::factory()->admin()->create(['theme' => null]);

        $response = $this->actingAs($user)
            ->patchJson(route('profile.theme'), ['theme' => Theme::LIGHT]);

        $response->assertNoContent();
        $response->assertPlainCookie(Theme::COOKIE, Theme::LIGHT);
        $this->assertSame(Theme::LIGHT, $user->fresh()->theme);
    }

    #[Test]
    public function klien_tidak_menyimpan_tema_lewat_kunjungan_inertia(): void
    {
        $js = (string) file_get_contents(resource_path('js/lib/theme.js'));

        $this->assertStringNotContainsString(
            'router.patch',
            $js,
            'lib/theme.js kembali memakai router.patch: kunjungan Inertia memuat ulang halaman '.
            'dan menghapus token API yang hanya tampil sekali. Pakai axios + jawaban 204.'
        );
        $this->assertStringContainsString("axios.patch(route('profile.theme')", $js);
    }

    #[Test]
    public function atribut_data_theme_di_html_mengikuti_pilihan(): void
    {
        $user = User::factory()->admin()->create(['theme' => Theme::LIGHT]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-theme="light"', false)
            ->assertSee('color-scheme: light', false);
    }

    #[Test]
    public function pilihan_system_dirender_sebagai_nilai_konkret(): void
    {
        $user = User::factory()->admin()->create(['theme' => Theme::SYSTEM]);

        $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $response->assertSee('data-theme="dark"', false);
        $response->assertDontSee('data-theme="system"', false);
        $response->assertInertia(fn (Assert $page) => $page->where('theme', Theme::SYSTEM));
    }

    #[Test]
    public function skrip_penyetel_tema_membawa_nonce_csp(): void
    {
        // CSP app ini melarang skrip inline tanpa nonce; skrip yang diblokir
        // tidak memberi pesan apa pun dan pilihan 'system' diam-diam tak berlaku.
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<script nonce="[^"]+">\s*\(function \(\) \{\s*try \{\s*if \(/',
            $html,
            'Skrip penyetel tema di <head> tidak membawa nonce CSP.'
        );
    }

    #[Test]
    #[DataProvider('nilaiTidakSah')]
    public function nilai_di_luar_daftar_ditolak(string $nilai): void
    {
        $user = User::factory()->admin()->create(['theme' => Theme::DARK]);

        $this->actingAs($user)
            ->patch(route('profile.theme'), ['theme' => $nilai])
            ->assertSessionHasErrors('theme');

        $this->assertSame(Theme::DARK, $user->fresh()->theme);
    }

    public static function nilaiTidakSah(): array
    {
        return [
            'kosong' => [''],
            'tak dikenal' => ['midnight'],
            'beda huruf besar-kecil' => ['Dark'],
            'penyuntikan' => ['dark"><script>'],
        ];
    }

    #[Test]
    public function cookie_rusak_tidak_membuat_halaman_tak_bertema(): void
    {
        $user = User::factory()->admin()->create(['theme' => null]);

        $this->actingAs($user)
            ->withUnencryptedCookie(Theme::COOKIE, 'entah-apa')
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-theme="dark"', false);
    }

    #[Test]
    public function operator_dan_partner_boleh_mengganti_temanya(): void
    {
        foreach (['operator', 'partner'] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user)
                ->patch(route('profile.theme'), ['theme' => Theme::LIGHT])
                ->assertRedirect();

            $this->assertSame(Theme::LIGHT, $user->fresh()->theme, "Peran {$role} gagal menyimpan tema.");
        }
    }

    #[Test]
    public function akun_demo_boleh_mengganti_tema_tanpa_menulis_barisnya(): void
    {
        // Akun demo read-only dan dipakai bersama banyak pengunjung: pilihan
        // satu orang tidak boleh mengubah tampilan orang lain. Maka rutenya
        // dibebaskan dari BlockDemoWrites, tetapi hanya cookie yang ditulis.
        $user = User::factory()->demo()->create(['theme' => null]);

        $response = $this->actingAs($user)
            ->patch(route('profile.theme'), ['theme' => Theme::LIGHT]);

        $response->assertRedirect();
        $response->assertPlainCookie(Theme::COOKIE, Theme::LIGHT);
        $this->assertNull($user->fresh()->theme);
    }

    #[Test]
    public function tamu_tidak_bisa_menyimpan_tema(): void
    {
        $this->patch(route('profile.theme'), ['theme' => Theme::LIGHT])
            ->assertRedirect(route('login'));
    }

    #[Test]
    public function halaman_publik_memakai_pilihan_dari_cookie(): void
    {
        $this->withUnencryptedCookie(Theme::COOKIE, Theme::LIGHT)
            ->get('/')
            ->assertOk()
            ->assertSee('data-theme="light"', false)
            ->assertInertia(fn (Assert $page) => $page->where('theme', Theme::LIGHT));
    }

    /**
     * Rute baru otomatis ikut pagar peran/demo; kondisi Vue tidak terjangkau
     * suite PHP, jadi berkas menunya diperiksa langsung supaya pemilih tema
     * tidak diam-diam dibungkus `v-if` peran tertentu.
     */
    #[Test]
    public function pemilih_tema_di_menu_pengguna_terbuka_untuk_semua_peran(): void
    {
        $menu = (string) file_get_contents(resource_path('js/Components/Shell/UserMenu.vue'));
        $this->assertStringContainsString('role="radiogroup"', $menu);

        $radiogroup = substr($menu, strpos($menu, 'role="radiogroup"') - 200, 260);
        foreach (['is_admin', 'isAdmin', 'can.', 'is_demo'] as $gerbang) {
            $this->assertStringNotContainsString($gerbang, $radiogroup, "Pemilih tema dibatasi `{$gerbang}`.");
        }

        // Header (tempat UserMenu) tidak tampil di HP; drawer navigasi HP wajib
        // punya pemilihnya sendiri, kalau tidak pengguna HP tak bisa ganti tema.
        $layout = (string) file_get_contents(resource_path('js/Layouts/AuthenticatedLayout.vue'));
        $this->assertStringContainsString('<ThemeSegmented', $layout, 'Drawer HP kehilangan pemilih tema.');
    }
}
