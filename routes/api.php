<?php

use App\Http\Controllers\Api\V1\AlarmController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\OltController;
use App\Http\Controllers\Api\V1\OnuController;
use App\Http\Controllers\Api\V1\PublicStatusController;
use App\Http\Controllers\Api\V1\SummaryController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| REST API v1 (KusumaVision NMS)
|--------------------------------------------------------------------------
| Read-only monitoring API untuk dipanggil dari web aplikasi lain & Android.
| Autentikasi: Bearer token (Laravel Sanctum). Lihat docs/API.md.
| Semua rute di-prefix `/api` (bootstrap/app.php) + grup `v1` di bawah ini.
|
| SAKLAR ON/OFF — API sengaja DIMATIKAN selama belum dipakai aplikasi mana pun,
| agar nol permukaan serangan (login & status publik pun tertutup → semua /api*
| jadi 404). Untuk MENGAKTIFKAN kembali saat aplikasi web/Android sudah siap:
|   1) ubah $apiEnabled = true di bawah,
|   2) `sudo systemctl reload php8.3-fpm`.
| Route tidak di-cache, jadi tak perlu route:cache.
*/

$apiEnabled = false;

// Di environment testing, rute selalu didaftarkan agar test API tetap berjalan.
if (! $apiEnabled && ! app()->environment('testing')) {
    return;
}

Route::prefix('v1')->group(function () {
    // Publik — tukar kredensial dengan token akses.
    Route::post('auth/login', [AuthController::class, 'login'])->name('api.auth.login');

    // Publik (tanpa token) — status agregat aman untuk di-embed di web lain.
    // Hanya angka, tanpa data pelanggan / IP OLT. CORS aktif untuk `api/*`.
    Route::get('public/status', PublicStatusController::class)->name('api.public.status');

    // Terproteksi token (Authorization: Bearer <token>).
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [AuthController::class, 'me'])->name('api.me');
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('api.auth.logout');

        Route::get('summary', [SummaryController::class, 'index'])->name('api.summary');

        Route::get('olts', [OltController::class, 'index'])->name('api.olts.index');
        Route::get('olts/{olt}', [OltController::class, 'show'])->name('api.olts.show');
        Route::get('olts/{olt}/onus/{slot}/{port}/{onuId}', [OnuController::class, 'show'])
            ->whereNumber(['slot', 'port', 'onuId'])
            ->name('api.olts.onu.show');

        Route::get('onus', [OnuController::class, 'index'])->name('api.onus.index');

        Route::get('alarms', [AlarmController::class, 'index'])->name('api.alarms.index');
    });
});
