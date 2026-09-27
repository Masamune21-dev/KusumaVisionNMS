<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Support\Theme;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }

    /**
     * Simpan preferensi tema: dark | light | system.
     *
     * Ditulis ke dua tempat: kolom supaya ikut berpindah perangkat, cookie supaya
     * render pertama server sudah bertema benar. Akun demo dipakai bersama banyak
     * pengunjung, jadi barisnya tidak ditulis — pilihan mereka cukup di cookie.
     */
    public function updateTheme(Request $request): RedirectResponse|HttpResponse
    {
        $validated = $request->validate([
            'theme' => ['required', 'string', 'in:'.implode(',', Theme::options())],
        ]);

        $user = $request->user();
        if (! $user->isDemo()) {
            $user->forceFill(['theme' => $validated['theme']])->save();
        }

        $cookie = Cookie::make(Theme::COOKIE, $validated['theme'], Theme::COOKIE_MINUTES);

        // Klien (lib/theme.js) memanggil lewat axios dan sudah melukis temanya
        // sendiri: jawab 204 tanpa isi. Mengalihkan kembali (back()) membuat
        // Inertia memuat ulang halaman — token API yang hanya tampil sekali,
        // modal terbuka, dan isian form yang belum disimpan ikut hilang.
        if ($request->expectsJson()) {
            return response()->noContent()->withCookie($cookie);
        }

        return back()->withCookie($cookie);
    }
}
