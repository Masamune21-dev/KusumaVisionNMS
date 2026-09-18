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
        // Percayai header X-Forwarded-* dari reverse proxy (Cloudflare, nginx, LB).
        // Tanpa ini, deployment di belakang Cloudflare "Flexible" (origin HTTP :80)
        // membuat Laravel/Ziggy men-generate URL http:// padahal halaman diakses
        // https:// -> axios menganggap POST login cross-origin dan TIDAK memasang
        // header X-XSRF-TOKEN -> 419 Page Expired permanen di semua browser.
        $middleware->trustProxies(at: '*');

        $middleware->web(append: [
            ContentSecurityPolicy::class,
            SetLocale::class,
            HandleInertiaRequests::class,
            BlockDemoWrites::class,
            AddLinkHeadersForPreloadedAssets::class,
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
