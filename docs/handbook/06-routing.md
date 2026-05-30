# 06 — Routing

[← Indeks](README.md) · [← 05 Database & Model](05-database-model.md) · [07 Modul & Fitur →](07-modul-fitur.md)

Semua route ada di `routes/web.php` (aplikasi) dan `routes/auth.php` (Breeze). Frontend memanggil
route via helper Ziggy `route('nama')`. Tidak ada `routes/api.php` terpisah — semua lewat web +
Inertia/JSON.

## Middleware global (urutan)

Dari `bootstrap/app.php`, grup `web`:
`HandleInertiaRequests` → `BlockDemoWrites` → `AddLinkHeadersForPreloadedAssets`.
Alias `role` → `EnsureUserRole`. `telegram/webhook` dikecualikan CSRF.

## Route publik

| Method | URI | Aksi | Nama |
|--------|-----|------|------|
| GET | `/` | Inertia `Welcome` (landing) | — |
| POST | `/telegram/webhook` | `TelegramWebhookController@handle` (gate: secret token header, no-auth, no-CSRF) | `telegram.webhook` |
| GET | `/up` | Health check Laravel | — |

## Route auth (`routes/auth.php`, Breeze)

Grup `guest`: `login` (GET/POST), `password.request`, `password.email`, `password.reset`,
`password.store`.
Grup `auth`: `verification.notice`, `verification.verify` (signed+throttle), `verification.send`,
`password.confirm`, `password.update`, `logout`.

> Registrasi publik **dimatikan** — buat user via `php artisan user:create` atau menu Users.

## Route aplikasi (`routes/web.php`) — `middleware('auth')`

### Umum
| Method | URI | Aksi | Nama | Akses |
|--------|-----|------|------|-------|
| GET | `/dashboard` | `DashboardController@index` | `dashboard` | auth+verified |
| GET | `/profile` | `ProfileController@edit` | `profile.edit` | auth |
| PATCH | `/profile` | `ProfileController@update` | `profile.update` | auth |
| DELETE | `/profile` | `ProfileController@destroy` | `profile.destroy` | auth |
| GET | `/dashboard/search` | `DashboardSearchController` (invokable, ⌘K) | `dashboard.search` | auth |
| POST | `/notifications/read-all` | `NotificationsController@markAllRead` | `notifications.read-all` | auth |
| GET | `/alarms` | `AlarmController@index` | `alarms.index` | auth |
| GET | `/reports` | `ReportController@index` | `reports.index` | auth |
| GET | `/reports/export/csv` | `ReportController@exportCsv` | `reports.export.csv` | auth |
| GET | `/reports/export/pdf` | `ReportController@exportPdf` | `reports.export.pdf` | auth |

### Admin only — `middleware('role:admin')`
| Method | URI | Aksi | Nama |
|--------|-----|------|------|
| GET | `/users` | `UserController@index` | `users.index` |
| POST | `/users` | `UserController@store` | `users.store` |
| PUT | `/users/{user}` | `UserController@update` | `users.update` |
| DELETE | `/users/{user}` | `UserController@destroy` | `users.destroy` |
| GET | `/audit-logs` | `AuditLogController@index` | `audit-logs.index` |
| GET | `/settings` | `SettingsController@edit` | `settings.edit` |
| POST | `/settings/general` | `SettingsController@updateGeneral` | `settings.general.update` |
| PUT | `/settings/telegram` | `SettingsController@updateTelegram` | `settings.telegram.update` |
| POST | `/settings/telegram/test` | `SettingsController@testTelegram` | `settings.telegram.test` |
| POST | `/settings/telegram/webhook/register` | `SettingsController@registerWebhook` | `settings.telegram.webhook.register` |
| POST | `/settings/telegram/webhook/delete` | `SettingsController@deleteWebhook` | `settings.telegram.webhook.delete` |

### SmartOLT (inti) — semua `auth`
> Aksi tulis tambahan dijaga `assertCapability()` (driver) & `BlockDemoWrites` (demo read-only).
> Operasi tulis OLT umumnya butuh `canManageOlt()` (admin/operator).

**Inventory & global**
| Method | URI | Aksi | Nama |
|--------|-----|------|------|
| GET | `/smartolt` | `index` | `smartolt.index` |
| GET | `/smartolt/create` | `create` | `smartolt.create` |
| POST | `/smartolt` | `store` | `smartolt.store` |
| GET | `/smartolt/{olt}/edit` | `edit` | `smartolt.edit` |
| PUT | `/smartolt/{olt}` | `update` | `smartolt.update` |
| DELETE | `/smartolt/{olt}` | `destroy` | `smartolt.destroy` |
| POST | `/smartolt/{olt}/test` | `test` (SNMP) | `smartolt.test` |
| POST | `/smartolt/{olt}/refresh` | `refresh` (snapshot penuh) | `smartolt.refresh` |
| GET | `/smartolt/unconfigured` | `unconfiguredGlobal` | `smartolt.unconfigured-all` |
| GET | `/onu-monitoring` | `onuMonitor` | `monitoring.onu` |
| POST | `/onu-monitoring/{olt}/refresh` | `refreshOnuMonitor` | `monitoring.onu.refresh` |

**Hardware / port manager / detail**
| Method | URI | Aksi | Nama |
|--------|-----|------|------|
| GET | `/smartolt/{olt}/detail` | `detail` (card/uplink) | `smartolt.detail` |
| POST | `/smartolt/{olt}/hardware/refresh` | `refreshHardware` | `smartolt.hardware.refresh` |
| GET | `/smartolt/{olt}/gpon-ports` | `gponPorts` | `smartolt.gpon-ports` |
| GET | `/smartolt/{olt}/port-manager` | `dashboard` | `smartolt.port-manager` |
| POST | `/smartolt/{olt}/port-manager/refresh` | `refreshDashboard` | `smartolt.port-manager.refresh` |
| POST | `/smartolt/{olt}/port-manager/interface/refresh` | `refreshDashboardInterface` | `smartolt.port-manager.interface.refresh` |
| GET | `/smartolt/{olt}/port-manager/traffic` | `dashboardTraffic` (JSON) | `smartolt.port-manager.traffic` |
| POST | `/smartolt/{olt}/port-manager/vlan` | `storeDashboardVlan` (JSON) | `smartolt.port-manager.vlan` |

**ONU per port**
| Method | URI | Aksi | Nama |
|--------|-----|------|------|
| GET | `/smartolt/{olt}/ports/{slot}/{port}/onus` | `portOnus` | `smartolt.port-onus` |
| POST | `…/onus/refresh` | `refreshPortOnus` | `smartolt.port-onus.refresh` |
| POST | `…/onus/{onuId}/reboot` | `rebootOnu` | `smartolt.onu.reboot` |
| POST | `…/onus/{onuId}/state` | `setOnuState` (enable/disable) | `smartolt.onu.state` |
| POST | `…/onus/{onuId}/info` | `updateOnuInfo` (nama/deskripsi) | `smartolt.onu.info` |
| GET | `…/onus/{onuId}/detail` | `onuDetail` (CLI) | `smartolt.onu.detail` |
| GET | `…/onus/{onuId}/configure` | `configureOnuForm` | `smartolt.onu.configure` |
| POST | `…/onus/{onuId}/configure/preview` | `configureOnuPreview` (JSON diff) | `smartolt.onu.configure.preview` |
| POST | `…/onus/{onuId}/configure` | `configureOnuApply` | `smartolt.onu.configure.apply` |

**Unconfigured & provisioning**
| Method | URI | Aksi | Nama |
|--------|-----|------|------|
| GET | `/smartolt/{olt}/unconfigured` | `unconfigured` | `smartolt.unconfigured` |
| POST | `/smartolt/{olt}/unconfigured/refresh` | `refreshUnconfigured` | `smartolt.unconfigured.refresh` |
| GET | `/smartolt/{olt}/register` | `registerOnuForm` | `smartolt.register` |
| POST | `/smartolt/{olt}/register` | `storeOnu` (build script) | `smartolt.register.store` |
| GET | `/smartolt/{olt}/registrations` | `registrations` | `smartolt.registrations` |
| POST | `/smartolt/{olt}/registrations/{registration}/execute` | `executeRegistration` (telnet) | `smartolt.registrations.execute` |

**Profil**
| Method | URI | Aksi | Nama |
|--------|-----|------|------|
| GET | `/smartolt/{olt}/profiles` | `SmartOltProfileController@index` | `smartolt.profiles.index` |
| POST | `/smartolt/{olt}/profiles` | `store` | `smartolt.profiles.store` |
| POST | `/smartolt/{olt}/profiles/sync` | `syncFromOlt` | `smartolt.profiles.sync` |
| PUT | `/smartolt/{olt}/profiles/{profile}` | `update` | `smartolt.profiles.update` |
| DELETE | `/smartolt/{olt}/profiles/{profile}` | `destroy` | `smartolt.profiles.destroy` |

**Telnet**
| Method | URI | Aksi | Nama |
|--------|-----|------|------|
| POST | `/smartolt/{olt}/telnet/token` | `TelnetSessionController@token` (terbit tiket WS) | `smartolt.telnet.token` |

## Console & schedule (`routes/console.php`)
- `Schedule::command('olts:poll')->everyMinute()->withoutOverlapping();`
- Command `inspire` (bawaan).

Command artisan kustom: `user:create`, `olts:poll`, `telegram:webhook {set|info|delete}`,
`telnet:proxy`. Lihat [03 Struktur Folder](03-struktur-folder.md) & [08](08-snmp-polling.md)/[09](09-cli-telnet.md)/[10](10-alarm-telegram.md).

## Broadcast channel (`routes/channels.php`)
`App.Models.User.{id}` — privat per user (notifikasi). Backend Reverb.

## Tips
- Lihat semua route + nama: `php artisan route:list`.
- Frontend: `route('smartolt.detail', olt.id)` menghasilkan URL; Ziggy di-load di `app.js`.
- Route model binding: `{olt}` → `SnmpOlt`, `{user}` → `User`, `{registration}` → registrasi,
  `{profile}` → profil. `{slot}/{port}/{onuId}` adalah parameter mentah (int), bukan model.

## Selanjutnya

→ [07 — Modul & Fitur](07-modul-fitur.md)
