<?php

namespace App\Http\Middleware;

use App\Services\Sso\HubSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ikat sesi lokal app ini ke sesi hub di IdP.
 *
 * Inilah yang membuat logout dan pencabutan akses terasa SEKARANG, bukan menunggu
 * sesi lokal kedaluwarsa sendiri: begitu sid hilang dari Redis — karena pengguna
 * logout di app lain, atau admin menonaktifkan akunnya — request berikutnya di
 * sini langsung mengembalikannya ke halaman login.
 */
class EnsureSsoSessionAlive
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return $next($request);
        }

        // Sesi darurat lewat CLI sengaja tidak punya sid; ia memang dipakai justru
        // ketika IdP tidak bisa dihubungi.
        if ($request->session()->get('sso.emergency') === true) {
            return $next($request);
        }

        $sid = $request->session()->get('sso.sid');

        if ($sid && HubSession::touch($sid)) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $target = route('login');

        // Kunjungan Inertia harus dipaksa jadi navigasi halaman penuh; kalau tidak,
        // axios akan mengikuti redirect ini sampai ke domain IdP dan kena CORS.
        return $request->header('X-Inertia')
            ? Inertia::location($target)
            : redirect()->guest($target);
    }
}
