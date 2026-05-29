<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Support\AuditLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Vite;
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
        Vite::prefetch(concurrency: 3);

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
