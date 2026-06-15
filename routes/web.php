<?php

use App\Http\Controllers\AlarmController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DashboardSearchController;
use App\Http\Controllers\NotificationsController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SmartOltController;
use App\Http\Controllers\SmartOltProfileController;
use App\Http\Controllers\TelegramWebhookController;
use App\Http\Controllers\TelnetSessionController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
    ]);
});

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

// Inbound Telegram bot commands. Public (no auth) — guarded by the secret token header;
// CSRF-exempt (see bootstrap/app.php).
Route::post('/telegram/webhook', [TelegramWebhookController::class, 'handle'])->name('telegram.webhook');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/dashboard/search', DashboardSearchController::class)->name('dashboard.search');
    Route::post('/notifications/read-all', [NotificationsController::class, 'markAllRead'])->name('notifications.read-all');

    Route::get('/alarms', [AlarmController::class, 'index'])->name('alarms.index');

    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/export/csv', [ReportController::class, 'exportCsv'])->name('reports.export.csv');
    Route::get('/reports/export/pdf', [ReportController::class, 'exportPdf'])->name('reports.export.pdf');

    Route::middleware('role:admin')->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

        Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');

        Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::post('/settings/general', [SettingsController::class, 'updateGeneral'])->name('settings.general.update');
        Route::put('/settings/telegram', [SettingsController::class, 'updateTelegram'])->name('settings.telegram.update');
        Route::post('/settings/telegram/test', [SettingsController::class, 'testTelegram'])->name('settings.telegram.test');
        Route::post('/settings/telegram/webhook/register', [SettingsController::class, 'registerWebhook'])->name('settings.telegram.webhook.register');
        Route::post('/settings/telegram/webhook/delete', [SettingsController::class, 'deleteWebhook'])->name('settings.telegram.webhook.delete');
    });

    Route::get('/smartolt', [SmartOltController::class, 'index'])->name('smartolt.index');
    Route::get('/smartolt/create', [SmartOltController::class, 'create'])->name('smartolt.create');
    Route::post('/smartolt', [SmartOltController::class, 'store'])->name('smartolt.store');
    Route::get('/smartolt/unconfigured', [SmartOltController::class, 'unconfiguredGlobal'])->name('smartolt.unconfigured-all');
    Route::get('/onu-monitoring', [SmartOltController::class, 'onuMonitor'])->name('monitoring.onu');
    Route::post('/onu-monitoring/{olt}/refresh', [SmartOltController::class, 'refreshOnuMonitor'])->name('monitoring.onu.refresh');
    Route::get('/smartolt/{olt}/detail', [SmartOltController::class, 'detail'])->name('smartolt.detail');
    Route::post('/smartolt/{olt}/hardware/refresh', [SmartOltController::class, 'refreshHardware'])->name('smartolt.hardware.refresh');
    Route::get('/smartolt/{olt}/gpon-ports', [SmartOltController::class, 'gponPorts'])->name('smartolt.gpon-ports');
    Route::get('/smartolt/{olt}/port-detail', [SmartOltController::class, 'portDetail'])->name('smartolt.port.detail');
    Route::post('/smartolt/{olt}/port-detail/refresh', [SmartOltController::class, 'refreshPortDetail'])->name('smartolt.port.refresh');
    Route::get('/smartolt/{olt}/port-detail/traffic', [SmartOltController::class, 'portTraffic'])->name('smartolt.port.traffic');
    Route::post('/smartolt/{olt}/port-detail/vlan', [SmartOltController::class, 'storePortVlan'])->name('smartolt.port.vlan');
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
    Route::post('/smartolt/{olt}/telnet/token', [TelnetSessionController::class, 'token'])->name('smartolt.telnet.token');
    Route::post('/smartolt/{olt}/test', [SmartOltController::class, 'test'])->name('smartolt.test');
    Route::post('/smartolt/{olt}/refresh', [SmartOltController::class, 'refresh'])->name('smartolt.refresh');
    Route::post('/smartolt/{olt}/ports/{slot}/{port}/onus/refresh', [SmartOltController::class, 'refreshPortOnus'])->name('smartolt.port-onus.refresh');
    Route::post('/smartolt/{olt}/ports/{slot}/{port}/onus/{onuId}/reboot', [SmartOltController::class, 'rebootOnu'])->name('smartolt.onu.reboot');
    Route::post('/smartolt/{olt}/ports/{slot}/{port}/onus/{onuId}/state', [SmartOltController::class, 'setOnuState'])->name('smartolt.onu.state');
    Route::post('/smartolt/{olt}/ports/{slot}/{port}/onus/{onuId}/info', [SmartOltController::class, 'updateOnuInfo'])->name('smartolt.onu.info');
    Route::get('/smartolt/{olt}/ports/{slot}/{port}/onus/{onuId}/detail', [SmartOltController::class, 'onuDetail'])->name('smartolt.onu.detail');
    Route::get('/smartolt/{olt}/ports/{slot}/{port}/onus/{onuId}/configure', [SmartOltController::class, 'configureOnuForm'])->name('smartolt.onu.configure');
    Route::post('/smartolt/{olt}/ports/{slot}/{port}/onus/{onuId}/configure/preview', [SmartOltController::class, 'configureOnuPreview'])->name('smartolt.onu.configure.preview');
    Route::post('/smartolt/{olt}/ports/{slot}/{port}/onus/{onuId}/configure', [SmartOltController::class, 'configureOnuApply'])->name('smartolt.onu.configure.apply');
});

require __DIR__.'/auth.php';
