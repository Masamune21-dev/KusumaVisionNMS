<?php

use App\Http\Controllers\Auth\SsoController;
use Illuminate\Support\Facades\Route;

/*
 * Autentikasi app ini sepenuhnya didelegasikan ke sso.kusumavision.net.
 *
 * Tidak ada lagi form login, registrasi, maupun reset password di sini — password
 * hanya pernah dikirim ke satu origin, yaitu IdP. Halaman-halaman itu kini hidup
 * di https://sso.kusumavision.net.
 */

Route::middleware('guest')->group(function () {
    /*
     * Diberi nama `login` supaya seluruh mekanisme bawaan Laravel yang
     * mengarahkan tamu ke rute bernama "login" (middleware `auth`,
     * `redirect()->guest()`, dan ratusan `route('login')` di Vue) tetap bekerja
     * tanpa perlu diubah satu per satu.
     */
    Route::get('sso/redirect', [SsoController::class, 'redirect'])->name('login');

    Route::get('sso/callback', [SsoController::class, 'callback'])->name('sso.callback');

    // Bookmark lama ke /login tetap bekerja.
    Route::get('login', fn () => redirect()->route('login'));

    /*
     * Jalur darurat: hanya bisa diterbitkan dari shell server lewat
     * `php artisan sso:emergency-login`, dipakai ketika IdP tidak bisa dihubungi.
     * Tautannya bertanda tangan, berlaku 5 menit, dan sekali pakai.
     */
    Route::get('sso/emergency/{user}', [SsoController::class, 'emergencyLogin'])
        ->middleware('signed')
        ->name('sso.emergency');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [SsoController::class, 'logout'])->name('logout');
});
