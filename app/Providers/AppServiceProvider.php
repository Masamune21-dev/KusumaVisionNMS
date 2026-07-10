<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Support\AuditLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Rate limiter untuk grup rute API (dipakai middleware `throttle:api`).
        // 120 request/menit per token (atau per IP bila belum login).
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->id ?: $request->ip()));

        // Rate limiter untuk aksi refresh/test on-demand yang MAHAL (SNMP walk penuh /
        // sesi telnet sinkron). Tanpa gerbang ini seorang user terautentikasi bisa
        // membanjiri OLT & server (DoS). 30 req/menit per user cukup longgar utk
        // pemakaian manual, tapi menahan hammering skrip. Dipakai `throttle:olt-refresh`.
        RateLimiter::for('olt-refresh', fn (Request $request) => Limit::perMinute(30)
            ->by($request->user()?->id ?: $request->ip()));

        // Prefetch eager seluruh chunk app dinonaktifkan: di landing publik ini
        // mem-prefetch puluhan asset (Dashboard/Auth/Telnet/dll) yang tidak
        // dibutuhkan pengunjung, dan tiap deploy (hash berubah) memicu badai
        // request 503 saat cache CDN masih dingin. Inertia tetap memuat chunk
        // halaman tujuan saat dibuka. Aktifkan lagi bila perlu: Vite::prefetch(concurrency: 3);

        Event::listen(Login::class, function (Login $event) {
            AuditLogger::log(AuditLog::EVENT_LOGIN, null, [], 'Login ke sistem', $event->user);
        });

        Event::listen(Logout::class, function (Logout $event) {
            if ($event->user === null) {
                return;
            }

            AuditLogger::log(AuditLog::EVENT_LOGOUT, null, [], 'Logout dari sistem', $event->user);
        });

        Event::listen(Failed::class, function (Failed $event) {
            AuditLogger::log(
                AuditLog::EVENT_LOGIN_FAILED,
                null,
                ['email' => $event->credentials['email'] ?? null],
                'Percobaan login gagal',
            );
        });
    }
}
