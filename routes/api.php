<?php

use App\Http\Controllers\Api\V1\AlarmController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\OltController;
use App\Http\Controllers\Api\V1\OnuActionController;
use App\Http\Controllers\Api\V1\OnuController;
use App\Http\Controllers\Api\V1\OnuRegistrationController;
use App\Http\Controllers\Api\V1\PublicStatusController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\SummaryController;
use App\Http\Controllers\Api\V1\UnconfiguredOnuController;
use App\Http\Middleware\BlockDemoWrites;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| REST API v1 (KusumaVision NMS)
|--------------------------------------------------------------------------
| API monitoring + aksi untuk web aplikasi lain & aplikasi Android (mobile/).
| Autentikasi: Bearer token (Laravel Sanctum). Lihat docs/API.md.
| Semua rute di-prefix `/api` (bootstrap/app.php) + grup `v1` di bawah ini.
|
| SAKLAR ON/OFF — set $apiEnabled=false untuk menutup total permukaan API
| (semua /api* jadi 404). Setelah mengubah: `sudo systemctl reload php8.3-fpm`.
| Route tidak di-cache, jadi tak perlu route:cache.
*/

$apiEnabled = true;

// Di environment testing, rute selalu didaftarkan agar test API tetap berjalan.
if (! $apiEnabled && ! app()->environment('testing')) {
    return;
}

Route::prefix('v1')->group(function () {
    // Publik — tukar kredensial dengan token akses (throttle ketat: anti brute-force).
    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1')
        ->name('api.auth.login');

    // Publik (tanpa token) — status agregat aman untuk di-embed di web lain.
    // Hanya angka, tanpa data pelanggan / IP OLT. CORS aktif untuk `api/*`.
    Route::get('public/status', PublicStatusController::class)
        ->middleware('throttle:api')
        ->name('api.public.status');

    // Terproteksi token (Authorization: Bearer <token>). 120 req/menit per token.
    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        Route::get('me', [AuthController::class, 'me'])->name('api.me');
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('api.auth.logout');

        Route::get('summary', [SummaryController::class, 'index'])->name('api.summary');
        Route::get('search', [SearchController::class, 'index'])->name('api.search');

        // Registrasi/pencabutan token perangkat untuk push FCM (aplikasi Android).
        Route::post('devices', [DeviceController::class, 'store'])->name('api.devices.store');
        Route::delete('devices', [DeviceController::class, 'destroy'])->name('api.devices.destroy');
        Route::post('devices/test', [DeviceController::class, 'test'])->name('api.devices.test');

        Route::get('olts', [OltController::class, 'index'])->name('api.olts.index');
        Route::get('olts/{olt}', [OltController::class, 'show'])->name('api.olts.show');

        Route::get('olts/{olt}/ports/{slot}/{port}/onus', [OnuController::class, 'portIndex'])
            ->whereNumber(['slot', 'port'])
            ->name('api.olts.port-onus');
        Route::get('olts/{olt}/onus/{slot}/{port}/{onuId}', [OnuController::class, 'show'])
            ->whereNumber(['slot', 'port', 'onuId'])
            ->name('api.olts.onu.show');

        Route::get('olts/{olt}/unconfigured', [UnconfiguredOnuController::class, 'index'])
            ->name('api.olts.unconfigured');
        Route::get('olts/{olt}/register/options', [OnuRegistrationController::class, 'options'])
            ->name('api.olts.register.options');

        Route::get('onus', [OnuController::class, 'index'])->name('api.onus.index');

        Route::get('alarms', [AlarmController::class, 'index'])->name('api.alarms.index');

        // --- Aksi TULIS (admin, operator & partner; demo diblokir). ZTE-only di controller.
        //     Partner otomatis dibatasi ke OLT yang di-assign (PartnerOltScope → 404 di luar itu). ---
        Route::middleware(['role:admin,operator,partner', BlockDemoWrites::class])->group(function () {
            Route::post('olts/{olt}/register/preview', [OnuRegistrationController::class, 'preview'])
                ->name('api.olts.register.preview');
            Route::post('olts/{olt}/register', [OnuRegistrationController::class, 'store'])
                ->name('api.olts.register');

            Route::post('olts/{olt}/unconfigured/refresh', [OnuActionController::class, 'refreshUnconfigured'])
                ->name('api.olts.unconfigured.refresh');
            Route::post('olts/{olt}/ports/{slot}/{port}/refresh', [OnuActionController::class, 'refreshPort'])
                ->whereNumber(['slot', 'port'])
                ->name('api.olts.port.refresh');

            Route::post('olts/{olt}/onus/{slot}/{port}/{onuId}/reboot', [OnuActionController::class, 'reboot'])
                ->whereNumber(['slot', 'port', 'onuId'])
                ->name('api.olts.onu.reboot');
            Route::post('olts/{olt}/onus/{slot}/{port}/{onuId}/name', [OnuActionController::class, 'rename'])
                ->whereNumber(['slot', 'port', 'onuId'])
                ->name('api.olts.onu.name');
        });
    });
});
