<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use App\Support\Locale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocaleController extends Controller
{
    /**
     * Ganti bahasa UI. Simpan ke session (tamu & user) dan ke profil bila login,
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

        return back();
    }
}
