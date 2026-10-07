# 06 — Routing

[← Indeks](README.md) · [← 05 Database & Model](05-database-model.md) · [07 Modul & Fitur →](07-modul-fitur.md)

Route aplikasi ada di `routes/web.php` dan `routes/auth.php` (Laravel Breeze); frontend memanggilnya
lewat helper Ziggy `route('nama')`. **Ada juga `routes/api.php`** — REST API v1 ber-token Sanctum
untuk aplikasi Android & integrasi (`$apiEnabled = true`, prefix `/api/v1`, nama rute `api.*`,
throttle 120/mnt per user, controller `app/Http/Controllers/Api/V1/*`). Daftar endpoint API lengkap
(method, akses, parameter, bentuk respons) ada di [`docs/API.md`](../API.md) — tidak diulang di sini.

> ⚠️ **Rute web dan API sama-sama di-cache** (`bootstrap/cache/routes-v7.php`). Setiap kali
> menambah, mengubah, atau menghapus rute, **wajib** jalankan `php artisan route:cache` di produksi
> — sebelum itu rute baru membalas 404/405 dan tombol yang memanggilnya mati diam-diam.
> `scripts/test.sh` mengalihkan `APP_ROUTES_CACHE`, jadi test yang hijau **tidak** membuktikan cache
> produksi sudah segar.

## Middleware global (`bootstrap/app.php`)

- Grup `web` (ditambahkan berurutan): `ContentSecurityPolicy` → `SetLocale` →
  `HandleInertiaRequests` → `BlockDemoWrites` → `AddLinkHeadersForPreloadedAssets`.
- Grup `api`: `BlockDemoWrites` (akun demo read-only lewat token juga; hanya `api.auth.logout`
  yang lolos). Tanpa `SetLocale` → pesan API selalu bahasa Indonesia.
- Alias: `role` → `EnsureUserRole` (`role:admin,operator`, 403 bila tak cocok).
- CSRF dikecualikan: `telegram/webhook` dan `telegram/webhook/*`.
- Cookie tema `kv_theme` dikecualikan dari enkripsi cookie (dibaca juga oleh JavaScript; isinya
  divalidasi `App\Support\Theme`).
- Proxy tepercaya dibaca dari `config/trustedproxy.php` (`TRUSTED_PROXIES`, bawaan `127.0.0.1,::1`).
  Di belakang Cloudflare "Flexible" / load balancer di host lain, isi variabel ini — kalau tidak URL
  jadi `http://` dan form berakhir 419.
- Rate limiter (`AppServiceProvider`): `api` 120/mnt dan `olt-refresh` 30/mnt, keduanya per user
  (per IP bila tamu). `throttle:olt-refresh` menjaga aksi mahal (scan/test/save config, telnet
  sinkron).
- `/api/*` selalu dijawab JSON (`shouldRenderJsonWhen`).

## Route publik

| Method | URI | Aksi | Nama |
|--------|-----|------|------|
| GET | `/` | Inertia `Welcome` (landing) | — |
| GET | `/healthz` | JSON status DB + Redis (200 `ok` / 503 `degraded`) untuk monitoring | `healthz` |
| GET | `/up` | Health check bawaan Laravel | — |
| POST | `/locale` | `LocaleController@update` — ganti bahasa ID/EN (tamu & user login) | `locale.update` |
| POST | `/telegram/webhook/{bot?}` | `TelegramWebhookController@handle` (gate: secret token header, no-auth, no-CSRF). `{bot}` kosong = bot global admin, `{bot}`=id = bot partner | `telegram.webhook` |

## Route auth (`routes/auth.php`, Breeze)

Login memakai sesi Laravel lokal (password diperiksa di database NMS sendiri).

| Method | URI | Aksi | Nama | Akses |
|--------|-----|------|------|-------|
| GET | `/login` | `AuthenticatedSessionController@create` | `login` | guest |
| POST | `/login` | `store` — `LoginRequest`, maks. 5 percobaan per email+IP lalu dikunci sementara | — | guest |
| GET | `/forgot-password` | `PasswordResetLinkController@create` | `password.request` | guest |
| POST | `/forgot-password` | `store` (kirim tautan reset via email) | `password.email` | guest |
| GET | `/reset-password/{token}` | `NewPasswordController@create` | `password.reset` | guest |
| POST | `/reset-password` | `store` | `password.store` | guest |
| GET | `/verify-email` | `EmailVerificationPromptController` | `verification.notice` | auth |
| GET | `/verify-email/{id}/{hash}` | `VerifyEmailController` (`signed`, `throttle:6,1`) | `verification.verify` | auth |
| POST | `/email/verification-notification` | `EmailVerificationNotificationController@store` (`throttle:6,1`) | `verification.send` | auth |
| GET | `/confirm-password` | `ConfirmablePasswordController@show` | `password.confirm` | auth |
| POST | `/confirm-password` | `store` | — | auth |
| PUT | `/password` | `PasswordController@update` (ganti sandi dari halaman Profil) | `password.update` | auth |
| POST | `/logout` | `AuthenticatedSessionController@destroy` | `logout` | auth |

Registrasi publik **dimatikan**: `RegisteredUserController` masih ada di repo tetapi tidak di-route
(tidak ada rute `register`, landing menerima `canRegister = false`). User baru dibuat admin lewat
menu Users atau `php artisan user:create`. Login sukses/gagal tercatat di audit log (`login`,
`login_failed`). Reset password lewat email butuh konfigurasi `MAIL_*` yang benar.

## Route aplikasi (`routes/web.php`) — `middleware('auth')`

### Umum
| Method | URI | Aksi | Nama | Akses |
|--------|-----|------|------|-------|
| GET | `/dashboard` | `DashboardController@index` | `dashboard` | auth+verified |
| GET | `/profile` | `ProfileController@edit` — nama/email, ganti sandi, tema, hapus akun | `profile.edit` | auth |
| PATCH | `/profile` | `update` (`ProfileUpdateRequest`; email berubah → `email_verified_at` dikosongkan) | `profile.update` | auth |
| DELETE | `/profile` | `destroy` — hapus akun sendiri (wajib sandi saat ini) | `profile.destroy` | auth |
| PATCH | `/profile/theme` | `updateTheme` (dark/light/system; dipanggil axios, jawab **204**; akun demo cukup cookie) | `profile.theme` | auth |
| GET | `/dashboard/search` | `DashboardSearchController` (invokable, ⌘K, via `GlobalSearchService`) | `dashboard.search` | auth |
| POST | `/notifications/read-all` | `NotificationsController@markAllRead` | `notifications.read-all` | auth |
| POST | `/notifications/alarms/{alarm}/open` | `open` — tandai dibaca + **server** memutuskan tujuan klik bel (`AlarmNotificationTargetResolver`), balas JSON `target_url`/`fallback_url`/`reason` | `notifications.alarms.open` | auth |
| POST | `/notifications/alarms/{alarm}/read` | `markRead` (JSON) | `notifications.alarms.read` | auth |
| GET | `/alarms` | `AlarmController@index` | `alarms.index` | auth |
| GET | `/reports` | `ReportController@index` | `reports.index` | auth |
| GET | `/reports/export/csv` | `ReportController@exportCsv` | `reports.export.csv` | auth |
| GET | `/reports/export/pdf` | `ReportController@exportPdf` | `reports.export.pdf` | auth |
| GET | `/panduan` | `PanduanController` (invokable) | `panduan` | auth |

> **Peta ONU & ODP** (`map.*`, `map.odps.*` termasuk `color`/`photo`, `odp.*`, `onu-odp.assign`)
> punya tabel rute sendiri di [16 — Peta ONU & ODP](16-peta-onu.md#rute). `map.odps.update` juga
> menerima `snmp_olt_id` (pindah OLT; ONU yang tak cocok dilepas).

### Admin only — `middleware('role:admin')`
| Method | URI | Aksi | Nama |
|--------|-----|------|------|
| GET | `/users` | `UserController@index` | `users.index` |
| POST | `/users` | `UserController@store` | `users.store` |
| PUT | `/users/{user}` | `UserController@update` | `users.update` |
| DELETE | `/users/{user}` | `UserController@destroy` | `users.destroy` |
| GET | `/audit-logs` | `AuditLogController@index` | `audit-logs.index` |
| GET | `/settings` | `SettingsController@edit` | `settings.edit` |
| POST | `/settings/api-tokens` | `createApiToken` (token milik admin yang login, akses penuh) | `settings.api-tokens.store` |
| DELETE | `/settings/api-tokens/{token}` | `revokeApiToken` (token milik sendiri) | `settings.api-tokens.destroy` |
| POST | `/settings/general` | `updateGeneral` | `settings.general.update` |
| PUT | `/settings/alarm` | `updateAlarm` — kebijakan alarm terpusat (`alarm_settings`) untuk Telegram & push | `settings.alarm.update` |
| PUT | `/settings/acs` | `updateAcs` — URL **CWMP** yang ditanam ke ONU saat registrasi TR069 | `settings.acs.update` |
| PUT | `/settings/genieacs` | `updateGenieacs` — alamat **NBI** GenieACS yang dibaca dasbor (kata sandi kosong = pertahankan) | `settings.genieacs.update` |
| POST | `/settings/genieacs/test` | `testGenieacs` — uji koneksi NBI memakai pengaturan tersimpan | `settings.genieacs.test` |
| PUT | `/settings/telegram` | `updateTelegram` (koneksi bot saja; filter alarm di tab Alarm) | `settings.telegram.update` |
| POST | `/settings/telegram/test` | `testTelegram` | `settings.telegram.test` |
| POST | `/settings/telegram/webhook/register` | `registerWebhook` | `settings.telegram.webhook.register` |
| POST | `/settings/telegram/webhook/delete` | `deleteWebhook` | `settings.telegram.webhook.delete` |
| PUT | `/settings/fcm` | `updateFcm` (saklar kanal push) | `settings.fcm.update` |
| POST | `/settings/fcm/send` | `sendFcmManual` (kirim notifikasi manual) | `settings.fcm.send` |
| DELETE | `/settings/mobile-devices/tokens/{token}` | `revokeMobileToken` (cabut sesi aplikasi user mana pun) | `settings.mobile-devices.token.destroy` |
| DELETE | `/settings/mobile-devices/fcm/{device}` | `deleteFcmDevice` | `settings.mobile-devices.fcm.destroy` |

Hanya admin yang mengelola user: membuat, mengubah, menghapus, menetapkan role dan assignment OLT
(`olt_ids`, dipakai partner & operator). `UserController` menolak menghapus diri sendiri serta
menghapus/menurunkan admin terakhir.

### Partner — `middleware('role:partner')`
| Method | URI | Aksi | Nama |
|--------|-----|------|------|
| GET | `/partner/telegram` | `Partner\TelegramBotController@edit` | `partner.telegram.edit` |
| PUT | `/partner/telegram` | `update` (bot + filter alarm milik partner sendiri) | `partner.telegram.update` |
| POST | `/partner/telegram/test` | `test` | `partner.telegram.test` |
| POST | `/partner/telegram/webhook/register` | `registerWebhook` | `partner.telegram.webhook.register` |
| POST | `/partner/telegram/webhook/delete` | `deleteWebhook` | `partner.telegram.webhook.delete` |

### Label port PON (non-ZTE)
| Method | URI | Aksi | Nama |
|--------|-----|------|------|
| POST | `/olts/{olt}/port-label` | `OltPortLabelController@store` | `olt.port-label.store` |

Satu rute untuk C-Data dan HiOSO; menyimpan label port di DB NMS (`olt_port_labels`), bukan ke
perangkat. Gated `canManageOlt()` + capability `supports_port_label` → **ZTE ditolak 403** (ZTE
menulis deskripsi portnya ke OLT lewat `smartolt.port.description`). Lihat
[07 Modul & Fitur §4c](07-modul-fitur.md#4c-label-port-pon-sisi-nms-family-non-zte).

### GenieACS / TR-069 (opsional)
Satu set rute untuk **semua family** — posisi ONU sudah cukup mengenali perangkatnya, pola yang
sama dengan `onu-odp.assign` dan `olt.port-label.store`. Kepemilikan OLT ditegakkan otomatis oleh
route-model binding + `PartnerOltScope`. Controller: `GenieacsController`.

| Method | URI | Aksi | Nama | Akses |
|--------|-----|------|------|-------|
| GET | `/olts/{olt}/onus/{slot}/{port}/{onuId}/acs-clients` | `connectedDevices` (memanggil NBI, cache 30 dtk) | `genieacs.onu.clients` | auth |
| POST | `/olts/{olt}/onus/{slot}/{port}/{onuId}/acs-wifi` | `updateWifi` (menulis ke ONU + audit) | `genieacs.onu.wifi` | `role:admin,operator,partner` |
| GET | `/genieacs/devices` | `searchDevices` (tabel lokal, tanpa NBI) | `genieacs.devices.search` | `role:admin,operator,partner` |
| POST | `/genieacs/devices/refresh` | `refreshDevices` (tarik ulang katalog ACS sekarang, memanggil NBI) | `genieacs.devices.refresh` | `role:admin,operator,partner` + `throttle:olt-refresh` |
| POST | `/olts/{olt}/onus/{slot}/{port}/{onuId}/acs-pin` | `pin` (semat manual + audit) | `genieacs.onu.pin` | `role:admin,operator,partner` |
| DELETE | `/olts/{olt}/onus/{slot}/{port}/{onuId}/acs-pin` | `unpin` (+ audit) | `genieacs.onu.unpin` | `role:admin,operator,partner` |

Gerbang `role:` di atas **tidak cukup** — partner ikut lolos. Semua aksi lebih dulu dijaga
`authorizeAcsCatalog()` di controller: `User::canManageAcs()` (admin/operator) untuk cari & tarik
ulang, dan `User::canUseAcsCatalogOn($olt)` (= `canManageAcs()` + OLT global non-demo) untuk aksi pada satu ONU;
selain itu 403. Pencarian device tetap dibatasi peran walau hanya membaca tabel lokal — isinya
memuat **nama secret PPPoE pelanggan**. Padanan API: `api.onus.acs-clients` & `api.onus.acs-wifi`.
Detail di [20 — GenieACS / TR-069](20-genieacs-tr069.md#9-izin--rute).

### SmartOLT (inti, ZTE) — semua `auth`
> Aksi tulis tambahan dijaga `assertCapability()` (driver) & `BlockDemoWrites` (demo read-only).
> Operasi tulis OLT umumnya butuh `canManageOlt()` (admin/operator/partner pemilik).

**Inventory & global**
| Method | URI | Aksi | Nama |
|--------|-----|------|------|
| GET | `/smartolt` | `index` (tab ZTE / C-Data / HiOSO) | `smartolt.index` |
| GET | `/smartolt/create` | `create` (`role:admin,operator,partner`) | `smartolt.create` |
| POST | `/smartolt` | `store` (`role:admin,operator,partner`) | `smartolt.store` |
| GET | `/smartolt/{olt}/edit` | `edit` | `smartolt.edit` |
| PUT | `/smartolt/{olt}` | `update` | `smartolt.update` |
| DELETE | `/smartolt/{olt}` | `destroy` (`role:admin,operator,partner`) | `smartolt.destroy` |
| POST | `/smartolt/{olt}/test` | `test` (SNMP, `throttle:olt-refresh`) | `smartolt.test` |
| POST | `/smartolt/{olt}/refresh` | `refresh` (snapshot penuh, `throttle:olt-refresh`) | `smartolt.refresh` |
| POST | `/smartolt/{olt}/alarms/toggle` | `toggleAlarms` — saklar notifikasi alarm per-OLT, semua family | `smartolt.alarms.toggle` |
| POST | `/smartolt/{olt}/config/save` | `saveConfig` (CLI `write` → memori OLT, `throttle:olt-refresh`) | `smartolt.config.save` |
| GET | `/smartolt/unconfigured` | `unconfiguredGlobal` | `smartolt.unconfigured-all` |
| GET | `/onu-monitoring` | `onuMonitor` | `monitoring.onu` |
| POST | `/onu-monitoring/{olt}/refresh` | `refreshOnuMonitor` (`throttle:olt-refresh`) | `monitoring.onu.refresh` |

**Backup konfigurasi (ZTE)** — `OltConfigBackupController`
| Method | URI | Aksi | Nama |
|--------|-----|------|------|
| GET | `/smartolt/{olt}/config-backups` | `index` | `smartolt.config-backups.index` |
| POST | `/smartolt/{olt}/config-backups` | `store` (backup manual sinkron, `throttle:olt-refresh`) | `smartolt.config-backups.store` |
| POST | `/smartolt/{olt}/config-backups/toggle` | `toggle` (backup harian on/off) | `smartolt.config-backups.toggle` |
| GET | `/smartolt/{olt}/config-backups/{backup}/content` | `content` (JSON) | `smartolt.config-backups.content` |
| GET | `/smartolt/{olt}/config-backups/{backup}/download` | `download` | `smartolt.config-backups.download` |

**Hardware / detail port**
| Method | URI | Aksi | Nama |
|--------|-----|------|------|
| GET | `/smartolt/{olt}/detail` | `detail` (card/uplink + visualisasi chassis) | `smartolt.detail` |
| POST | `/smartolt/{olt}/hardware/refresh` | `refreshHardware` | `smartolt.hardware.refresh` |
| GET | `/smartolt/{olt}/gpon-ports` | `gponPorts` (halaman `SmartOlt/PonPorts`, dipakai bersama C-Data & HiOSO lewat prop `route_prefix`) | `smartolt.gpon-ports` |
| GET | `/smartolt/{olt}/port-detail?interface=` | `portDetail` (GPON/uplink) | `smartolt.port.detail` |
| POST | `/smartolt/{olt}/port-detail/refresh` | `refreshPortDetail` (CLI per-interface) | `smartolt.port.refresh` |
| GET | `/smartolt/{olt}/port-detail/traffic` | `portTraffic` (JSON, uplink) | `smartolt.port.traffic` |
| POST | `/smartolt/{olt}/port-detail/vlan` | `storePortVlan` (JSON) | `smartolt.port.vlan` |
| POST | `/smartolt/{olt}/port-detail/description` | `storePortDescription` (CLI `description …`, semua ZTE; `throttle:olt-refresh`) | `smartolt.port.description` |
| POST | `/smartolt/{olt}/port-detail/admin-state` | `storePortAdminState` — `shutdown`/`no shutdown` port PON via CLI (tanpa `write`); gated `supports_port_admin_write` + `canSetPonPortAdminState()` (admin, atau partner pemilik OLT privat); mematikan menaikkan alarm `port_disabled`. `throttle:olt-refresh` | `smartolt.port.admin-state` |

> Halaman **Port Manager** lama dihapus; navigasinya kini lewat **klik port di visualisasi chassis** (halaman Detail OLT) → halaman **Detail Port** (`PortDetail.vue`).

> **Save Config non-ZTE:** family C-Data & HiOSO punya rute paralel `cdata-olt.config.save` dan `hioso-olt.config.save` (tabel di bawah) — simpan running-config ke memori OLT via CLI (C-Data `enable→config→save`, HiOSO `enable→write`). Semua gated capability `supports_config_save` + `throttle:olt-refresh`. Lihat [09 CLI & Telnet](09-cli-telnet.md).

**ONU per port** (prefix `/smartolt/{olt}/ports/{slot}/{port}`)
| Method | URI | Aksi | Nama |
|--------|-----|------|------|
| GET | `…/onus` | `portOnus` | `smartolt.port-onus` |
| POST | `…/onus/refresh` | `refreshPortOnus` | `smartolt.port-onus.refresh` |
| POST | `…/onus/delete` | `deleteOnus` (hapus massal ONU terpilih, satu sesi CLI, gated `supports_onu_delete`) | `smartolt.port-onus.delete` |
| POST | `…/onus/copy` | `copyOnusToPort` (antre `CopyOnusToPortJob`) | `smartolt.port-onus.copy` |
| GET | `/smartolt/{olt}/copy-tasks/{task}` | `copyTaskStatus` (poll progres) | `smartolt.copy-task.status` |
| POST | `…/tr069-bulk` | `tr069Bulk` (antre `Tr069BulkConfigJob`, gated `supports_onu_config_write`) | `smartolt.tr069-bulk` |
| GET | `/smartolt/{olt}/tr069-bulk/{task}` | `tr069BulkStatus` (poll progres) | `smartolt.tr069-bulk.status` |
| POST | `…/onus/{onuId}/reboot` | `rebootOnu` | `smartolt.onu.reboot` |
| POST | `…/onus/{onuId}/delete` | `deleteOnu` (`no onu {id}`, gated `supports_onu_delete`) | `smartolt.onu.delete` |
| POST | `…/onus/{onuId}/state` | `setOnuState` (enable/disable) | `smartolt.onu.state` |
| POST | `…/onus/{onuId}/info` | `updateOnuInfo` (nama/deskripsi) | `smartolt.onu.info` |
| GET | `…/onus/{onuId}/detail` | `onuDetail` (CLI) | `smartolt.onu.detail` |
| GET | `…/onus/{onuId}/configure` | `configureOnuForm` | `smartolt.onu.configure` |
| POST | `…/onus/{onuId}/configure/preview` | `configureOnuPreview` (JSON diff) | `smartolt.onu.configure.preview` |
| POST | `…/onus/{onuId}/configure` | `configureOnuApply` | `smartolt.onu.configure.apply` |
| POST | `…/onus/{onuId}/configure/item` | `configureOnuItem` — editor per-bagian gaya NetNumen, satu perubahan langsung ke OLT lalu config dibaca ulang (JSON) | `smartolt.onu.configure.item` |
| POST | `…/onus/{onuId}/configure/unbind-profile` | `configureOnuUnbindProfile` — lepas onu-profile C300 (`no onu N profile`) + tulis ulang layanan | `smartolt.onu.configure.unbind-profile` |

**Unconfigured, Bind ONU & provisioning**
| Method | URI | Aksi | Nama |
|--------|-----|------|------|
| GET | `/smartolt/{olt}/unconfigured` | `unconfigured` | `smartolt.unconfigured` |
| POST | `/smartolt/{olt}/unconfigured/refresh` | `refreshUnconfigured` | `smartolt.unconfigured.refresh` |
| GET | `/smartolt/{olt}/ports/{slot}/{port}/replace-candidates` | `replaceCandidates` — kandidat modal **Bind ONU** (ONU terdaftar di port yang sama, SNMP live; `throttle:olt-refresh`) | `smartolt.onu.replace-candidates` |
| POST | `/smartolt/{olt}/ports/{slot}/{port}/onus/{onuId}/replace` | `replaceOnu` — `registration-method sn` memasang SN ONU pengganti ke slot ONU lama; gated `supports_onu_replace` (C300/C320) | `smartolt.onu.replace` |
| GET | `/smartolt/{olt}/register` | `registerOnuForm` | `smartolt.register` |
| GET | `/smartolt/{olt}/register/mgmt-pool` | `registerMgmtPool` (alokasi mgmt-IP C600, `?fresh=1`; `throttle:olt-refresh`) | `smartolt.register.mgmt-pool` |
| POST | `/smartolt/{olt}/register/preview` | `registerOnuPreview` (live raw CLI, tak menyentuh OLT) | `smartolt.register.preview` |
| POST | `/smartolt/{olt}/register/advanced/preview` | `registerOnuAdvancedPreview` (mode Lanjutan) | `smartolt.register.advanced.preview` |
| POST | `/smartolt/{olt}/register/advanced` | `storeOnuAdvanced` | `smartolt.register.advanced.store` |
| POST | `/smartolt/{olt}/register` | `storeOnu` (build script) | `smartolt.register.store` |
| GET | `/smartolt/{olt}/registrations` | `registrations` | `smartolt.registrations` |
| POST | `/smartolt/{olt}/registrations/{registration}/execute` | `executeRegistration` (telnet) | `smartolt.registrations.execute` |
| DELETE | `/smartolt/{olt}/registrations/{registration}` | `destroyRegistration` | `smartolt.registrations.destroy` |

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

### C-Data (`cdata-olt.*`) & HiOSO (`hioso-olt.*`) — semua `auth`
Kedua family punya controller + halaman sendiri (`CDataOltController`, `HiosoOltController`) dengan
pola rute yang sama; `{prefix}` = `/cdata-olt` atau `/hioso-olt`.

| Method | URI | Aksi | Nama (`cdata-olt.` / `hioso-olt.`) |
|--------|-----|------|------|
| GET | `{prefix}` | `index` | `index` |
| GET | `{prefix}/create` | `create` (`role:admin,operator,partner`) | `create` |
| POST | `{prefix}` | `store` (`role:admin,operator,partner`) | `store` |
| GET | `{prefix}/{olt}/edit` | `edit` | `edit` |
| PUT | `{prefix}/{olt}` | `update` | `update` |
| DELETE | `{prefix}/{olt}` | `destroy` (`role:admin,operator,partner`) | `destroy` |
| POST | `{prefix}/{olt}/test` | `test` (`throttle:olt-refresh`) | `test` |
| GET | `{prefix}/{olt}/detail` | `detail` | `detail` |
| POST | `{prefix}/{olt}/refresh` | `refresh` (scan penuh, `throttle:olt-refresh`) | `refresh` |
| POST | `{prefix}/{olt}/config/save` | `saveConfig` (`throttle:olt-refresh`) | `config.save` |
| GET | `{prefix}/{olt}/pon-ports` | `ponPorts` (halaman bersama `SmartOlt/PonPorts`) | `pon-ports` |
| GET | `{prefix}/{olt}/ports/{slot}/{port}/onus` | `portOnus` | `port-onus` |
| POST | `…/onus/refresh` | `refreshPortOnus` | `port-onus.refresh` |
| POST | `…/onus/{onuId}/reboot` | `rebootOnu` | `onu.reboot` |
| POST | `…/onus/{onuId}/state` | `setOnuState` (gated `supports_onu_toggle`) | `onu.state` |
| POST | `…/onus/{onuId}/info` | `updateOnuInfo` (rename) | `onu.info` |
| DELETE | `…/onus/{onuId}` | `deleteOnu` (gated `supports_onu_delete`) | `onu.delete` |

Khusus C-Data:

| Method | URI | Aksi | Nama |
|--------|-----|------|------|
| POST | `/cdata-olt/{olt}/ports/{slot}/{port}/onus/{onuId}/remote-access` | `setOnuRemoteAccess` (GPON FlashV3, gated `supports_onu_remote_access`) | `cdata-olt.onu.remote-access` |
| GET | `/cdata-olt/{olt}/vlans` | `CDataGponPortController@vlans` — lihat VLAN (gated `supports_cli_vlan`) | `cdata-olt.vlans` |
| POST | `/cdata-olt/{olt}/vlans` | `storeVlan` — buat VLAN + tag ke banyak port (`throttle:olt-refresh`) | `cdata-olt.vlans.store` |
| GET | `/cdata-olt/{olt}/port/{kind}/{slot}/{port}` | `portDetail` — `kind` ∈ `gpon`,`epon`,`ge`,`xge` (gated `supports_cli_port_detail`) | `cdata-olt.port.detail` |
| POST | `/cdata-olt/{olt}/port/{kind}/{slot}/{port}/vlan` | `tagPortVlan` — `kind` ∈ `ge`,`xge`,`epon` (`throttle:olt-refresh`) | `cdata-olt.port.vlan` |

VLAN & detail port hanya untuk firmware C-Data V3 (`SmartOltSupport::hasCDataV3Cli()`); tulisnya
dibatasi di controller ke `canEditOltConnection()` (admin/operator, atau pemilik OLT privat) dengan
transport CLI telnet, dan tercatat di audit log. Lihat
[`docs/SMARTOLT_CDATA_GUIDE.md`](../SMARTOLT_CDATA_GUIDE.md) §14.

> **HsAirPo / HSGQ EPON dihapus 29 Sep 2026** — seluruh rute `hsairpo.*` (beserta controller &
> halamannya) sudah tidak ada. Kodenya hanya tersisa di riwayat git.

## Console & schedule (`routes/console.php`)

| Jadwal | Perintah | Guna |
|---|---|---|
| tiap menit | `olts:poll` (`withoutOverlapping`) | dispatch polling OLT yang jatuh tempo (ZTE & non-ZTE) |
| tiap jam, menit 5 | `optical:aggregate-rx` (`withoutOverlapping`) | ringkas `onu_rx_samples` → `onu_rx_hourly` (min/avg/max per jam) |
| harian 02:30 | `olts:backup-config` (`withoutOverlapping`) | backup running-config OLT ZTE yang saklarnya aktif |
| harian 03:15 | `optical:prune-rx` (`withoutOverlapping`) | buang sampel RX lama; **menolak jalan** bila ringkasan belum mencapai batas |
| harian 03:40 | `sanctum:prune-expired --hours=24` | buang sesi aplikasi kedaluwarsa (token push FCM ikut terhapus) |
| tiap 15 menit | `genieacs:match-onu` (`withoutOverlapping(10)`, `runInBackground`) | cocokkan device GenieACS ↔ posisi ONU; tanpa NBI di Pengaturan selesai diam-diam |

Scheduler harus jalan (`schedule:work` di supervisor / cron `schedule:run`), kalau tidak ringkasan RX
berhenti dan pemangkasan ikut tertahan. Di file yang sama ada command bawaan `inspire`.

Command artisan kustom: `user:create`, `api:token`, `olts:poll`, `olts:backup-config`,
`optical:aggregate-rx`, `optical:prune-rx`, `telegram:webhook {set|info|delete}`, `telnet:proxy`,
`genieacs:match-onu`, `genieacs:unlinked-report`.
Lihat [03 Struktur Folder](03-struktur-folder.md) & [08](08-snmp-polling.md)/[09](09-cli-telnet.md)/[10](10-alarm-telegram.md).

## Broadcast channel (`routes/channels.php`)
`App.Models.User.{id}` — privat per user (notifikasi). Backend Reverb.

## Tips
- Setelah menambah/mengubah rute: `php artisan route:cache` di produksi (lihat peringatan di atas).
- Lihat semua route + nama: `php artisan route:list` (bila cache rute ada, yang tampil isi cache —
  cara cepat memastikan cache produksi sudah memuat rute baru).
- Frontend: `route('smartolt.detail', olt.id)` menghasilkan URL; Ziggy di-load di `app.js`. Setiap
  `route('…')` di Vue harus ada di `route:list` — nama yang hilang baru ketahuan saat diklik.
- Route model binding: `{olt}` → `SnmpOlt`, `{user}` → `User`, `{registration}` → registrasi,
  `{profile}` → profil, `{backup}` → `OltConfigBackup`, `{task}` → `CopyOnuTask` /
  `Tr069BulkTask`, `{alarm}` → `AlarmEvent`, `{odp}` → `Odp`, `{pin}` → `OnuMapPin`,
  `{device}` → `FcmDeviceToken`. Model OLT/ODP/alarm kena `PartnerOltScope` (di luar scope → 404).
  `{slot}/{port}/{onuId}` dan `{token}` adalah parameter mentah (int), bukan model.

## Selanjutnya

→ [07 — Modul & Fitur](07-modul-fitur.md)
