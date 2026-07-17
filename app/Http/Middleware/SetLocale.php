<?php

namespace App\Http\Middleware;

use App\Support\Locale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menentukan bahasa aktif tiap request dengan prioritas:
 *   1. Preferensi user login (`users.locale`)
 *   2. Pilihan tersimpan di session (dipakai tamu sebelum login)
 *   3. Cookie preferensi (`Locale::COOKIE`) — bertahan melewati logout yang
 *      meng-`invalidate()` session, sehingga Welcome/Login tetap ikut bahasa
 *      yang dipilih user di dashboard.
 *   4. Default aplikasi (`config('app.locale')`)
 *
 * Harus berjalan SEBELUM {@see HandleInertiaRequests} agar prop `locale` yang
 * dibagikan ke frontend mencerminkan locale yang sudah di-set.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->user()?->locale
            ?? $request->session()->get('locale')
            ?? $request->cookie(Locale::COOKIE)
            ?? config('app.locale');

        app()->setLocale(Locale::normalize($locale));

        return $next($request);
    }
}
