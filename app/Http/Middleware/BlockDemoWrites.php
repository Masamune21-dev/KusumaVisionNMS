<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BlockDemoWrites
{
    /**
     * Demo users are read-only: any non-GET request is rejected,
     * except logout so they can still end their session.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->isDemo()
            && ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)
            && ! $request->routeIs('logout')
        ) {
            abort(403, 'Mode demo bersifat read-only.');
        }

        return $next($request);
    }
}
