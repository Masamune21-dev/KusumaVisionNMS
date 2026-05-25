<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SmartOltController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/smartolt', [SmartOltController::class, 'index'])->name('smartolt.index');
    Route::get('/smartolt/create', [SmartOltController::class, 'create'])->name('smartolt.create');
    Route::post('/smartolt', [SmartOltController::class, 'store'])->name('smartolt.store');
    Route::get('/smartolt/{olt}/edit', [SmartOltController::class, 'edit'])->name('smartolt.edit');
    Route::put('/smartolt/{olt}', [SmartOltController::class, 'update'])->name('smartolt.update');
    Route::delete('/smartolt/{olt}', [SmartOltController::class, 'destroy'])->name('smartolt.destroy');
    Route::post('/smartolt/{olt}/test', [SmartOltController::class, 'test'])->name('smartolt.test');
});

require __DIR__.'/auth.php';
