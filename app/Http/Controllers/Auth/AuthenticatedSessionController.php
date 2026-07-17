<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Support\Locale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        $this->adoptGuestLocale($request);

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Bahasa yang dipilih tamu secara eksplisit di layar login (`session('locale')`
     * hanya terisi oleh klik switcher, bukan sekadar melihat halaman) dijadikan
     * preferensi akun, agar dashboard ikut bahasa yang barusan dipilih — bukan
     * preferensi lama akun. Tanpa klik switcher, preferensi akun tetap dihormati.
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
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
