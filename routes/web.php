<?php

use App\Http\Controllers\AlarmController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\CDataOltController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DashboardSearchController;
use App\Http\Controllers\HiosoOltController;
use App\Http\Controllers\NotificationsController;
use App\Http\Controllers\OnuMapController;
use App\Http\Controllers\PanduanController;
use App\Http\Controllers\Partner\TelegramBotController as PartnerTelegramBotController;
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
// CSRF-exempt (see bootstrap/app.php). {bot} kosong = bot global admin; {bot}=id =
// bot milik partner (webhook menyetel auth user = partner untuk membatasi query OLT).
Route::post('/telegram/webhook/{bot?}', [TelegramWebhookController::class, 'handle'])
    ->whereNumber('bot')
    ->name('telegram.webhook');

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

    Route::get('/panduan', PanduanController::class)->name('panduan');

    Route::middleware('role:admin')->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

        Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');

        Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::post('/settings/api-tokens', [SettingsController::class, 'createApiToken'])->name('settings.api-tokens.store');
        Route::delete('/settings/api-tokens/{token}', [SettingsController::class, 'revokeApiToken'])->whereNumber('token')->name('settings.api-tokens.destroy');
        Route::post('/settings/general', [SettingsController::class, 'updateGeneral'])->name('settings.general.update');
        Route::put('/settings/alarm', [SettingsController::class, 'updateAlarm'])->name('settings.alarm.update');
        Route::put('/settings/acs', [SettingsController::class, 'updateAcs'])->name('settings.acs.update');
        Route::put('/settings/telegram', [SettingsController::class, 'updateTelegram'])->name('settings.telegram.update');
        Route::post('/settings/telegram/test', [SettingsController::class, 'testTelegram'])->name('settings.telegram.test');
        Route::post('/settings/telegram/webhook/register', [SettingsController::class, 'registerWebhook'])->name('settings.telegram.webhook.register');
        Route::post('/settings/telegram/webhook/delete', [SettingsController::class, 'deleteWebhook'])->name('settings.telegram.webhook.delete');
        Route::put('/settings/fcm', [SettingsController::class, 'updateFcm'])->name('settings.fcm.update');
        Route::post('/settings/fcm/send', [SettingsController::class, 'sendFcmManual'])->name('settings.fcm.send');
    });

    // Bot Telegram self-service milik partner (bot sendiri, terbatas OLT yang di-assign).
    Route::middleware('role:partner')->group(function () {
        Route::get('/partner/telegram', [PartnerTelegramBotController::class, 'edit'])->name('partner.telegram.edit');
        Route::put('/partner/telegram', [PartnerTelegramBotController::class, 'update'])->name('partner.telegram.update');
        Route::post('/partner/telegram/test', [PartnerTelegramBotController::class, 'test'])->name('partner.telegram.test');
        Route::post('/partner/telegram/webhook/register', [PartnerTelegramBotController::class, 'registerWebhook'])->name('partner.telegram.webhook.register');
        Route::post('/partner/telegram/webhook/delete', [PartnerTelegramBotController::class, 'deleteWebhook'])->name('partner.telegram.webhook.delete');
    });

    // OLT C-Data (non-ZTE: EPON 17409 & GPON 34592) — v1 read-only monitoring.
    Route::get('/cdata-olt', [CDataOltController::class, 'index'])->name('cdata-olt.index');
    // Tambah OLT = admin/operator (pool global) ATAU partner (jadi OLT privat miliknya).
    // Hapus dibatasi kepemilikan di controller (partner hanya OLT miliknya).
    Route::get('/cdata-olt/create', [CDataOltController::class, 'create'])->middleware('role:admin,operator,partner')->name('cdata-olt.create');
    Route::post('/cdata-olt', [CDataOltController::class, 'store'])->middleware('role:admin,operator,partner')->name('cdata-olt.store');
    Route::get('/cdata-olt/{olt}/edit', [CDataOltController::class, 'edit'])->name('cdata-olt.edit');
    Route::put('/cdata-olt/{olt}', [CDataOltController::class, 'update'])->name('cdata-olt.update');
    Route::delete('/cdata-olt/{olt}', [CDataOltController::class, 'destroy'])->middleware('role:admin,operator,partner')->name('cdata-olt.destroy');
    Route::post('/cdata-olt/{olt}/test', [CDataOltController::class, 'test'])->middleware('throttle:olt-refresh')->name('cdata-olt.test');
    Route::get('/cdata-olt/{olt}/detail', [CDataOltController::class, 'detail'])->name('cdata-olt.detail');
    Route::post('/cdata-olt/{olt}/refresh', [CDataOltController::class, 'refresh'])->middleware('throttle:olt-refresh')->name('cdata-olt.refresh');
    Route::get('/cdata-olt/{olt}/ports/{slot}/{port}/onus', [CDataOltController::class, 'portOnus'])->name('cdata-olt.port-onus');
    Route::post('/cdata-olt/{olt}/ports/{slot}/{port}/onus/refresh', [CDataOltController::class, 'refreshPortOnus'])->name('cdata-olt.port-onus.refresh');
    Route::post('/cdata-olt/{olt}/ports/{slot}/{port}/onus/{onuId}/reboot', [CDataOltController::class, 'rebootOnu'])->name('cdata-olt.onu.reboot');
    Route::post('/cdata-olt/{olt}/ports/{slot}/{port}/onus/{onuId}/info', [CDataOltController::class, 'updateOnuInfo'])->name('cdata-olt.onu.info');
    Route::delete('/cdata-olt/{olt}/ports/{slot}/{port}/onus/{onuId}', [CDataOltController::class, 'deleteOnu'])->name('cdata-olt.onu.delete');

    // OLT HiOSO / V-Sol EPON (enterprise 25355, mis. HA7304) — inventori + rename/reboot ONU (CLI).
    Route::get('/hioso-olt', [HiosoOltController::class, 'index'])->name('hioso-olt.index');
    Route::get('/hioso-olt/create', [HiosoOltController::class, 'create'])->middleware('role:admin,operator,partner')->name('hioso-olt.create');
    Route::post('/hioso-olt', [HiosoOltController::class, 'store'])->middleware('role:admin,operator,partner')->name('hioso-olt.store');
    Route::get('/hioso-olt/{olt}/edit', [HiosoOltController::class, 'edit'])->name('hioso-olt.edit');
    Route::put('/hioso-olt/{olt}', [HiosoOltController::class, 'update'])->name('hioso-olt.update');
    Route::delete('/hioso-olt/{olt}', [HiosoOltController::class, 'destroy'])->middleware('role:admin,operator,partner')->name('hioso-olt.destroy');
    Route::post('/hioso-olt/{olt}/test', [HiosoOltController::class, 'test'])->middleware('throttle:olt-refresh')->name('hioso-olt.test');
    Route::get('/hioso-olt/{olt}/detail', [HiosoOltController::class, 'detail'])->name('hioso-olt.detail');
    Route::post('/hioso-olt/{olt}/refresh', [HiosoOltController::class, 'refresh'])->middleware('throttle:olt-refresh')->name('hioso-olt.refresh');
    Route::get('/hioso-olt/{olt}/ports/{slot}/{port}/onus', [HiosoOltController::class, 'portOnus'])->name('hioso-olt.port-onus');
    Route::post('/hioso-olt/{olt}/ports/{slot}/{port}/onus/refresh', [HiosoOltController::class, 'refreshPortOnus'])->name('hioso-olt.port-onus.refresh');
    Route::post('/hioso-olt/{olt}/ports/{slot}/{port}/onus/{onuId}/reboot', [HiosoOltController::class, 'rebootOnu'])->name('hioso-olt.onu.reboot');
    Route::post('/hioso-olt/{olt}/ports/{slot}/{port}/onus/{onuId}/info', [HiosoOltController::class, 'updateOnuInfo'])->name('hioso-olt.onu.info');
    Route::delete('/hioso-olt/{olt}/ports/{slot}/{port}/onus/{onuId}', [HiosoOltController::class, 'deleteOnu'])->name('hioso-olt.onu.delete');

    Route::get('/smartolt', [SmartOltController::class, 'index'])->name('smartolt.index');
    Route::get('/smartolt/create', [SmartOltController::class, 'create'])->middleware('role:admin,operator,partner')->name('smartolt.create');
    Route::post('/smartolt', [SmartOltController::class, 'store'])->middleware('role:admin,operator,partner')->name('smartolt.store');
    Route::get('/smartolt/unconfigured', [SmartOltController::class, 'unconfiguredGlobal'])->name('smartolt.unconfigured-all');
    Route::get('/onu-monitoring', [SmartOltController::class, 'onuMonitor'])->name('monitoring.onu');
    Route::post('/onu-monitoring/{olt}/refresh', [SmartOltController::class, 'refreshOnuMonitor'])->middleware('throttle:olt-refresh')->name('monitoring.onu.refresh');

    // Peta ONU — sebaran pin ONU pelanggan lintas-OLT (Leaflet + tile Google keyless).
    Route::get('/map', [OnuMapController::class, 'index'])->name('map.index');
    Route::post('/map/resolve-link', [OnuMapController::class, 'resolveLink'])->name('map.resolve-link');
    Route::post('/map/pins', [OnuMapController::class, 'store'])->name('map.pins.store');
    Route::put('/map/pins/{pin}', [OnuMapController::class, 'update'])->name('map.pins.update');
    Route::delete('/map/pins/{pin}', [OnuMapController::class, 'destroy'])->name('map.pins.destroy');
    Route::post('/map/pins/{pin}/reboot', [OnuMapController::class, 'rebootPin'])->name('map.pins.reboot');
    Route::post('/map/pins/{pin}/rename', [OnuMapController::class, 'renamePin'])->name('map.pins.rename');
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
    Route::post('/smartolt/{olt}/register/preview', [SmartOltController::class, 'registerOnuPreview'])->name('smartolt.register.preview');
    Route::post('/smartolt/{olt}/register/advanced/preview', [SmartOltController::class, 'registerOnuAdvancedPreview'])->name('smartolt.register.advanced.preview');
    Route::post('/smartolt/{olt}/register/advanced', [SmartOltController::class, 'storeOnuAdvanced'])->name('smartolt.register.advanced.store');
    Route::post('/smartolt/{olt}/register', [SmartOltController::class, 'storeOnu'])->name('smartolt.register.store');
    Route::get('/smartolt/{olt}/registrations', [SmartOltController::class, 'registrations'])->name('smartolt.registrations');
    Route::post('/smartolt/{olt}/registrations/{registration}/execute', [SmartOltController::class, 'executeRegistration'])->name('smartolt.registrations.execute');
    Route::delete('/smartolt/{olt}/registrations/{registration}', [SmartOltController::class, 'destroyRegistration'])->name('smartolt.registrations.destroy');
    Route::get('/smartolt/{olt}/profiles', [SmartOltProfileController::class, 'index'])->name('smartolt.profiles.index');
    Route::post('/smartolt/{olt}/profiles', [SmartOltProfileController::class, 'store'])->name('smartolt.profiles.store');
    Route::post('/smartolt/{olt}/profiles/sync', [SmartOltProfileController::class, 'syncFromOlt'])->name('smartolt.profiles.sync');
    Route::put('/smartolt/{olt}/profiles/{profile}', [SmartOltProfileController::class, 'update'])->name('smartolt.profiles.update');
    Route::delete('/smartolt/{olt}/profiles/{profile}', [SmartOltProfileController::class, 'destroy'])->name('smartolt.profiles.destroy');
    Route::get('/smartolt/{olt}/edit', [SmartOltController::class, 'edit'])->name('smartolt.edit');
    Route::put('/smartolt/{olt}', [SmartOltController::class, 'update'])->name('smartolt.update');
    Route::delete('/smartolt/{olt}', [SmartOltController::class, 'destroy'])->middleware('role:admin,operator,partner')->name('smartolt.destroy');
    Route::post('/smartolt/{olt}/telnet/token', [TelnetSessionController::class, 'token'])->name('smartolt.telnet.token');
    Route::post('/smartolt/{olt}/test', [SmartOltController::class, 'test'])->middleware('throttle:olt-refresh')->name('smartolt.test');
    // Toggle alarm per-OLT (mute) — dipakai semua family (ZTE/C-Data/HiOSO), hanya membalik flag SnmpOlt.
    Route::post('/smartolt/{olt}/alarms/toggle', [SmartOltController::class, 'toggleAlarms'])->name('smartolt.alarms.toggle');
    Route::post('/smartolt/{olt}/refresh', [SmartOltController::class, 'refresh'])->middleware('throttle:olt-refresh')->name('smartolt.refresh');
    Route::post('/smartolt/{olt}/ports/{slot}/{port}/onus/refresh', [SmartOltController::class, 'refreshPortOnus'])->name('smartolt.port-onus.refresh');
    Route::post('/smartolt/{olt}/ports/{slot}/{port}/onus/copy', [SmartOltController::class, 'copyOnusToPort'])->name('smartolt.port-onus.copy');
    Route::get('/smartolt/{olt}/copy-tasks/{task}', [SmartOltController::class, 'copyTaskStatus'])->name('smartolt.copy-task.status');
    Route::post('/smartolt/{olt}/ports/{slot}/{port}/tr069-bulk', [SmartOltController::class, 'tr069Bulk'])->name('smartolt.tr069-bulk');
    Route::get('/smartolt/{olt}/tr069-bulk/{task}', [SmartOltController::class, 'tr069BulkStatus'])->name('smartolt.tr069-bulk.status');
    Route::post('/smartolt/{olt}/ports/{slot}/{port}/onus/{onuId}/reboot', [SmartOltController::class, 'rebootOnu'])->name('smartolt.onu.reboot');
    Route::post('/smartolt/{olt}/ports/{slot}/{port}/onus/{onuId}/delete', [SmartOltController::class, 'deleteOnu'])->name('smartolt.onu.delete');
    Route::post('/smartolt/{olt}/ports/{slot}/{port}/onus/{onuId}/state', [SmartOltController::class, 'setOnuState'])->name('smartolt.onu.state');
    Route::post('/smartolt/{olt}/ports/{slot}/{port}/onus/{onuId}/info', [SmartOltController::class, 'updateOnuInfo'])->name('smartolt.onu.info');
    Route::get('/smartolt/{olt}/ports/{slot}/{port}/onus/{onuId}/detail', [SmartOltController::class, 'onuDetail'])->name('smartolt.onu.detail');
    Route::get('/smartolt/{olt}/ports/{slot}/{port}/onus/{onuId}/configure', [SmartOltController::class, 'configureOnuForm'])->name('smartolt.onu.configure');
    Route::post('/smartolt/{olt}/ports/{slot}/{port}/onus/{onuId}/configure/preview', [SmartOltController::class, 'configureOnuPreview'])->name('smartolt.onu.configure.preview');
    Route::post('/smartolt/{olt}/ports/{slot}/{port}/onus/{onuId}/configure', [SmartOltController::class, 'configureOnuApply'])->name('smartolt.onu.configure.apply');
});

require __DIR__.'/auth.php';
