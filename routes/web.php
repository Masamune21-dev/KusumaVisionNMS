<?php

use App\Http\Controllers\AlarmController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SmartOltController;
use App\Http\Controllers\SmartOltProfileController;
use App\Http\Controllers\UserController;
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

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/alarms', [AlarmController::class, 'index'])->name('alarms.index');

    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
    Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

    Route::get('/smartolt', [SmartOltController::class, 'index'])->name('smartolt.index');
    Route::get('/smartolt/create', [SmartOltController::class, 'create'])->name('smartolt.create');
    Route::post('/smartolt', [SmartOltController::class, 'store'])->name('smartolt.store');
    Route::get('/smartolt/unconfigured', [SmartOltController::class, 'unconfiguredGlobal'])->name('smartolt.unconfigured-all');
    Route::get('/smartolt/{olt}/detail', [SmartOltController::class, 'detail'])->name('smartolt.detail');
    Route::post('/smartolt/{olt}/hardware/refresh', [SmartOltController::class, 'refreshHardware'])->name('smartolt.hardware.refresh');
    Route::get('/smartolt/{olt}/gpon-ports', [SmartOltController::class, 'gponPorts'])->name('smartolt.gpon-ports');
    Route::get('/smartolt/{olt}/port-manager', [SmartOltController::class, 'dashboard'])->name('smartolt.port-manager');
    Route::post('/smartolt/{olt}/port-manager/refresh', [SmartOltController::class, 'refreshDashboard'])->name('smartolt.port-manager.refresh');
    Route::post('/smartolt/{olt}/port-manager/interface/refresh', [SmartOltController::class, 'refreshDashboardInterface'])->name('smartolt.port-manager.interface.refresh');
    Route::get('/smartolt/{olt}/port-manager/traffic', [SmartOltController::class, 'dashboardTraffic'])->name('smartolt.port-manager.traffic');
    Route::post('/smartolt/{olt}/port-manager/vlan', [SmartOltController::class, 'storeDashboardVlan'])->name('smartolt.port-manager.vlan');
    Route::get('/smartolt/{olt}/ports/{slot}/{port}/onus', [SmartOltController::class, 'portOnus'])->name('smartolt.port-onus');
    Route::get('/smartolt/{olt}/unconfigured', [SmartOltController::class, 'unconfigured'])->name('smartolt.unconfigured');
    Route::post('/smartolt/{olt}/unconfigured/refresh', [SmartOltController::class, 'refreshUnconfigured'])->name('smartolt.unconfigured.refresh');
    Route::get('/smartolt/{olt}/register', [SmartOltController::class, 'registerOnuForm'])->name('smartolt.register');
    Route::post('/smartolt/{olt}/register', [SmartOltController::class, 'storeOnu'])->name('smartolt.register.store');
    Route::get('/smartolt/{olt}/registrations', [SmartOltController::class, 'registrations'])->name('smartolt.registrations');
    Route::post('/smartolt/{olt}/registrations/{registration}/execute', [SmartOltController::class, 'executeRegistration'])->name('smartolt.registrations.execute');
    Route::get('/smartolt/{olt}/profiles', [SmartOltProfileController::class, 'index'])->name('smartolt.profiles.index');
    Route::post('/smartolt/{olt}/profiles', [SmartOltProfileController::class, 'store'])->name('smartolt.profiles.store');
    Route::post('/smartolt/{olt}/profiles/sync', [SmartOltProfileController::class, 'syncFromOlt'])->name('smartolt.profiles.sync');
    Route::put('/smartolt/{olt}/profiles/{profile}', [SmartOltProfileController::class, 'update'])->name('smartolt.profiles.update');
    Route::delete('/smartolt/{olt}/profiles/{profile}', [SmartOltProfileController::class, 'destroy'])->name('smartolt.profiles.destroy');
    Route::get('/smartolt/{olt}/edit', [SmartOltController::class, 'edit'])->name('smartolt.edit');
    Route::put('/smartolt/{olt}', [SmartOltController::class, 'update'])->name('smartolt.update');
    Route::delete('/smartolt/{olt}', [SmartOltController::class, 'destroy'])->name('smartolt.destroy');
    Route::post('/smartolt/{olt}/test', [SmartOltController::class, 'test'])->name('smartolt.test');
    Route::post('/smartolt/{olt}/refresh', [SmartOltController::class, 'refresh'])->name('smartolt.refresh');
    Route::post('/smartolt/{olt}/ports/{slot}/{port}/onus/refresh', [SmartOltController::class, 'refreshPortOnus'])->name('smartolt.port-onus.refresh');
    Route::post('/smartolt/{olt}/ports/{slot}/{port}/onus/{onuId}/reboot', [SmartOltController::class, 'rebootOnu'])->name('smartolt.onu.reboot');
    Route::post('/smartolt/{olt}/ports/{slot}/{port}/onus/{onuId}/state', [SmartOltController::class, 'setOnuState'])->name('smartolt.onu.state');
    Route::post('/smartolt/{olt}/ports/{slot}/{port}/onus/{onuId}/info', [SmartOltController::class, 'updateOnuInfo'])->name('smartolt.onu.info');
});

require __DIR__.'/auth.php';
