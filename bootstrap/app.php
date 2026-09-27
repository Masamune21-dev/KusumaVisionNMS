<?php

use App\Http\Middleware\BlockDemoWrites;
use App\Http\Middleware\ContentSecurityPolicy;
use App\Http\Middleware\EnsureUserRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Proxy tepercaya dibaca dari config/trustedproxy.php (env TRUSTED_PROXIES, bawaan
        // hanya localhost). Dulu `at: '*'`: siapa pun bisa memalsukan X-Forwarded-For
        // sehingga throttle login/olt-refresh dan IP di audit log bisa diakali. Deployment
        // di belakang Cloudflare "Flexible" / load balancer di host lain WAJIB mengisi
        // TRUSTED_PROXIES (lihat .env.example), kalau tidak URL jadi http:// → 419.

        $middleware->web(append: [
            ContentSecurityPolicy::class,
            SetLocale::class,
            HandleInertiaRequests::class,
            BlockDemoWrites::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // API bertoken (Sanctum): user demo tetap read-only.
        $middleware->api(append: [
            BlockDemoWrites::class,
        ]);

        $middleware->alias([
            'role' => EnsureUserRole::class,
        ]);

        // Telegram posts to the webhook without a CSRF token; the secret token header is the gate.
        // Termasuk webhook per-partner (telegram/webhook/{bot}).
        $middleware->validateCsrfTokens(except: [
            'telegram/webhook',
            'telegram/webhook/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Permintaan ke /api/* selalu dijawab JSON (bukan redirect/HTML),
        // walau klien lupa mengirim header Accept: application/json.
        $exceptions->shouldRenderJsonWhen(
            fn ($request, $throwable) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
