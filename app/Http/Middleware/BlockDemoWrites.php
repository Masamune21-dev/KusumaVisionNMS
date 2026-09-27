<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BlockDemoWrites
{
    /**
     * Demo users are read-only: any non-GET request is rejected,
     * except logout so they can still end their session, and the theme
     * switch — a display preference that for demo users lives in a cookie only
     * (ProfileController::updateTheme never writes their shared users row).
     *
     * Dipasang di grup `web` DAN `api`. Di grup `api` middleware ini berjalan
     * sebelum `auth:sanctum` (rute), jadi user diresolusi juga lewat guard
     * sanctum (bearer token) — bukan hanya guard sesi default.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user() ?? $request->user('sanctum');

        if ($user && $user->isDemo()
            && ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)
            && ! $request->routeIs('logout', 'api.auth.logout', 'profile.theme')
        ) {
            abort(403, 'Mode demo bersifat read-only.');
        }

        return $next($request);
    }
}
