<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use App\Support\Locale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\Rule;

class LocaleController extends Controller
{
    /**
     * Ganti bahasa UI. Simpan ke session (tamu & user), ke profil bila login,
     * dan ke cookie awet (bertahan melewati logout yang meng-invalidate session),
     * lalu kembali ke halaman asal — locale aktif dibaca ulang oleh {@see SetLocale}.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in(Locale::codes())],
        ]);

        $locale = $validated['locale'];

        $request->session()->put('locale', $locale);

        if ($user = $request->user()) {
            $user->forceFill(['locale' => $locale])->save();
        }

        // Cookie awet 1 tahun: satu-satunya jejak preferensi yang selamat dari
        // session()->invalidate() saat logout → Welcome/Login ikut bahasa terakhir.
        return back()->withCookie(Cookie::make(Locale::COOKIE, $locale, 60 * 24 * 365));
    }
}
