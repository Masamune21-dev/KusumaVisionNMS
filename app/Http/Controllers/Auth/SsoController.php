<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Console\Commands\SsoEmergencyLogin;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Sso\SsoClient;
use App\Support\Locale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sisi klien alur SSO: mengarahkan ke IdP, lalu menerima kembali identitasnya.
 */
class SsoController extends Controller
{
    public function __construct(private readonly SsoClient $sso) {}

    /**
     * Mulai alur login. Rute ini juga yang dituju middleware `auth`, jadi membuka
     * halaman mana pun tanpa sesi akan langsung menggulirkan proses SSO — bila
     * pengguna sudah login di app lain, ia melewatinya tanpa mengisi form apa pun.
     */
    public function redirect(Request $request): Response|RedirectResponse
    {
        $state = Str::random(40);
        $verifier = Str::random(96);

        $request->session()->put('sso.state', $state);
        $request->session()->put('sso.verifier', $verifier);

        $url = $this->sso->authorizeUrl($state, $verifier);

        /*
         * Kunjungan Inertia berjalan lewat XHR. Redirect 302 ke domain lain akan
         * diikuti axios lalu diblokir CORS, jadi IdP harus dituju sebagai navigasi
         * halaman penuh — itulah yang dilakukan Inertia::location (409 +
         * X-Inertia-Location).
         */
        return $request->header('X-Inertia')
            ? Inertia::location($url)
            : redirect()->away($url);
    }

    /**
     * Terima kembali dari IdP dan terbitkan sesi lokal.
     */
    public function callback(Request $request): RedirectResponse
    {
        $state = $request->session()->pull('sso.state');
        $verifier = $request->session()->pull('sso.verifier');

        // `state` mengikat balasan ini ke permintaan yang tadi kita mulai sendiri.
        // Tanpa pemeriksaan ini, siapa pun bisa memancing browser korban menelan
        // authorization code milik akun lain.
        abort_if($state === null || $verifier === null, 403, 'Sesi SSO tidak ditemukan. Silakan ulangi.');
        abort_unless(hash_equals($state, (string) $request->query('state', '')), 403, 'Parameter state tidak cocok.');

        $code = (string) $request->query('code', '');
        abort_if($code === '', 400, 'Kode otorisasi tidak ada.');

        try {
            $claims = $this->sso->exchange($code, $verifier);
        } catch (RuntimeException $e) {
            Log::warning('SSO: penukaran kode gagal', ['message' => $e->getMessage()]);

            abort(503, 'Tidak bisa menghubungi pusat login. Coba lagi sebentar lagi.');
        }

        $role = UserRole::tryFrom($claims['role']);

        // Penjaga terakhir: role yang tidak dikenal app ini tidak boleh menjelma
        // jadi sesi login, sekalipun IdP mengirimnya.
        abort_if($role === null, 403, "Role \"{$claims['role']}\" tidak dikenali aplikasi ini.");

        $user = $this->resolveUser($claims, $role);

        Auth::login($user);

        // Cegah session fixation: id sesi tamu tidak boleh membawa status login.
        $request->session()->regenerate();

        $request->session()->put('sso.sid', $claims['sid']);
        $request->session()->put('sso.apps', $claims['apps']);

        $this->adoptGuestLocale($request);

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Logout: bersihkan sesi lokal, lalu serahkan ke IdP supaya sesi hub ikut mati
     * dan app lain ikut logout pada request berikutnya.
     */
    public function logout(Request $request): Response|RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $url = $this->sso->logoutUrl();

        /*
         * Tombol logout memakai <Link method="post"> — permintaan XHR Inertia.
         * Redirect 302 ke domain lain akan diikuti axios lalu gagal (CORS /
         * balasan bukan-Inertia), jadi IdP harus dituju sebagai navigasi halaman
         * penuh lewat Inertia::location (409 + X-Inertia-Location).
         */
        return $request->header('X-Inertia')
            ? Inertia::location($url)
            : redirect()->away($url);
    }

    /**
     * Bahasa yang dipilih tamu secara eksplisit sebelum login (`session('locale')`
     * hanya terisi oleh klik switcher, bukan sekadar melihat halaman) dijadikan
     * preferensi akun, agar dashboard ikut bahasa yang barusan dipilih.
     *
     * Perilaku ini dulu ada di AuthenticatedSessionController. Ia tetap relevan
     * setelah pindah ke SSO karena session app ini bertahan selama perjalanan ke
     * IdP dan kembali — jadi pilihan bahasa di halaman publik tidak hilang.
     */
    private function adoptGuestLocale(Request $request): void
    {
        $chosen = $request->session()->get('locale');
        $user = $request->user();

        if ($user && Locale::isSupported($chosen) && $chosen !== $user->locale) {
            $user->forceFill(['locale' => $chosen])->save();
        }
    }

    /**
     * Login darurat lewat tautan bertanda tangan dari CLI.
     *
     * Sesi yang lahir di sini sengaja TIDAK punya `sso.sid` dan ditandai
     * `sso.emergency`, karena justru dipakai saat IdP tidak bisa dihubungi.
     * {@see \App\Http\Middleware\EnsureSsoSessionAlive} melewatkan sesi seperti ini.
     */
    public function emergencyLogin(Request $request, int $user): RedirectResponse
    {
        $nonce = (string) $request->query('nonce', '');
        $key = SsoEmergencyLogin::cacheKey($user);
        $stored = Cache::get($key);

        abort_if($stored === null || $nonce === '' || ! Hash::check($nonce, $stored), 403, 'Tautan darurat tidak berlaku.');

        // Sekali pakai: nonce dibuang sebelum sesi terbit.
        Cache::forget($key);

        $account = User::findOrFail($user);

        Auth::login($account);
        $request->session()->regenerate();
        $request->session()->put('sso.emergency', true);

        Log::warning('SSO: login darurat dipakai', [
            'email' => $account->email,
            'ip' => $request->ip(),
        ]);

        return redirect()->route('dashboard');
    }

    /**
     * Temukan baris lokal untuk identitas ini, atau buat bila belum ada.
     *
     * Pencocokan mengutamakan `sso_user_id` supaya penggantian email di IdP tidak
     * memutus hubungan akun. Membuat baris baru di sini AMAN karena IdP hanya
     * menerbitkan klaim untuk orang yang memang sudah diberi hak akses ke app ini;
     * baris lokal sekadar cerminnya, tempat menyimpan data operasional seperti
     * penugasan OLT.
     */
    private function resolveUser(array $claims, UserRole $role): User
    {
        $user = User::where('sso_user_id', $claims['sub'])->first()
            ?? User::whereRaw('lower(email) = ?', [Str::lower($claims['email'])])->first();

        if ($user === null) {
            $user = new User;
            // Kolom password tidak lagi dipakai untuk login, tetapi tetap NOT NULL.
            // Diisi nilai acak yang tidak pernah diberitahukan ke siapa pun.
            $user->password = Str::random(64);

            Log::info('SSO: membuat baris pengguna lokal', ['email' => $claims['email']]);
        }

        $user->forceFill([
            'sso_user_id' => $claims['sub'],
            'name' => $claims['name'],
            'email' => $claims['email'],
            'role' => $role,
        ])->save();

        return $user;
    }
}
