# 03 — Struktur Folder

[← Indeks](README.md) · [← 02 Arsitektur](02-arsitektur.md) · [04 Instalasi & Deploy →](04-instalasi-deploy.md)

Peta direktori repo dengan penjelasan singkat tiap bagian. Path relatif ke root repo.

> Diperbarui 30 Sep 2026.

## Akar (root)

```
CLAUDE.md                  Instruksi ringkas proyek untuk asisten/agent
README.md / README.id.md   README proyek (EN/ID)
WORKLOG.md                 Riwayat pekerjaan fase-per-fase (WAJIB diupdate per perubahan)
UI_DESIGN_SYSTEM.md        Aturan design system UI (§3a dua tema, §3b tampilan baku, dll.)
LICENSE                    MIT
composer.json / .lock      Dependensi PHP (`composer test` = `bash scripts/test.sh`)
package.json / lock        Dependensi JS (`npm test` = Vitest)
go.mod / go.sum            Modul Go untuk SNMP poller
vite.config.js             Build frontend (emptyOutDir:false → chunk lama disimpan; chunk ikon `vendor-icons`)
vitest.config.js           Konfigurasi Vitest (tests/js/*.spec.js)
tailwind.config.js / tailwind.tokens.mjs   Tailwind + token warna tema
phpunit.xml                Konfigurasi test (SQLite in-memory)
install.sh                 Deploy satu perintah di Ubuntu bersih (supervisor, nginx, Go poller, …)
Dockerfile / docker-compose.yml / docker/   Deploy "appliance" (lihat 18)
start.* / stop.bat / update.bat             Launcher 1-klik Docker
artisan                    Entry CLI Laravel
.env / .env.example / .env.docker.example   Konfigurasi environment
```

## `app/` — kode backend (PSR-4 `App\`)

```
app/
├── Console/Commands/        Artisan command kustom
│   ├── CreateUserCommand.php       user:create (--role=admin|operator|partner|demo; registrasi publik dimatikan)
│   ├── ApiTokenCommand.php         api:token {email} --name= (token Sanctum; umur = SANCTUM_EXPIRATION)
│   ├── PollOltsCommand.php         olts:poll (dispatch PollOltJob per OLT due)
│   ├── BackupOltConfigsCommand.php olts:backup-config (harian 02:30, ZTE)
│   ├── AggregateOnuRxCommand.php   optical:aggregate-rx (RX per jam)
│   ├── PruneOnuRxSamplesCommand.php optical:prune-rx (retensi RX)
│   ├── TelegramWebhookCommand.php  telegram:webhook {set|info|delete}
│   ├── TelnetProxyCommand.php      telnet:proxy (daemon WS↔telnet)
│   ├── GenieacsMatchOnuCommand.php genieacs:match-onu (tiap 15 menit; --dry-run)
│   └── GenieacsUnlinkedReportCommand.php genieacs:unlinked-report (ONU belum ter-ACS per merk)
│
├── Contracts/
│   ├── SmartOltSnmpDriver.php      Kontrak driver SNMP non-ZTE (ping, ports, ONU, RX, uncfg)
│   └── Telegram/TelegramBotConfig.php
│
├── Enums/
│   └── UserRole.php          Admin | Operator | Partner | Demo (+ label/options)
│
├── Http/
│   ├── Controllers/          Lihat 06-routing.md untuk pemetaan lengkap
│   │   ├── SmartOltController.php       (terbesar — inti ZTE: OLT, port, ONU, register, bind, …)
│   │   ├── SmartOltProfileController.php
│   │   ├── CDataOltController.php / CDataGponPortController.php   C-Data (+ VLAN & detail port V3)
│   │   ├── HiosoOltController.php       HiOSO EPON
│   │   ├── OltConfigBackupController.php / OltPortLabelController.php
│   │   ├── OnuMapController.php / OdpController.php               Peta & ODP
│   │   ├── DashboardController.php / DashboardSearchController.php
│   │   ├── AlarmController.php / ReportController.php
│   │   ├── SettingsController.php / UserController.php / ProfileController.php
│   │   ├── AuditLogController.php / NotificationsController.php
│   │   ├── LocaleController.php / PanduanController.php (/panduan)
│   │   ├── TelegramWebhookController.php / TelnetSessionController.php
│   │   ├── Partner/TelegramBotController.php (bot Telegram milik partner)
│   │   ├── GenieacsController.php       GenieACS: perangkat terhubung, ubah WiFi, cari/semat/lepas, tarik ulang
│   │   ├── Concerns/ManagesOltOwnership.php
│   │   ├── Api/V1/…                     REST API v1 (Auth, Summary, Olt, Onu, OnuAction,
│   │   │                                OnuRegistration, UnconfiguredOnu, Alarm, Search, Odp, Map,
│   │   │                                Device, PublicStatus, Genieacs)
│   │   └── Auth/…                       Breeze (login, lupa/reset password, verifikasi email,
│   │                                    konfirmasi & ganti password; RegisteredUserController ada
│   │                                    tapi tidak dirutekan)
│   ├── Middleware/
│   │   ├── HandleInertiaRequests.php    Share props global ke semua page
│   │   ├── ContentSecurityPolicy.php    CSP ber-nonce (Vite + Ziggy ikut nonce)
│   │   ├── SetLocale.php                Locale per user/sesi (id/en)
│   │   ├── BlockDemoWrites.php          Demo = read-only (web & API)
│   │   └── EnsureUserRole.php           alias 'role:...'
│   └── Requests/             Form request: ProfileUpdateRequest, Auth/LoginRequest (login ber-rate-limit)
│
├── Jobs/
│   ├── PollOltJob.php        Polling 1 OLT (ZTE: Go poller + fallback PHP; non-ZTE: scanner) + alarm
│   ├── BackupOltConfigJob.php  Backup running-config terjadwal
│   ├── CopyOnusToPortJob.php   Salin ONU ke port lain (progres di copy_onu_tasks)
│   ├── Tr069BulkConfigJob.php  TR069 massal per port (progres di tr069_bulk_tasks)
│   └── SendFcmAlarmNotifications.php  Push alarm ke aplikasi Android
│
├── Models/                  Eloquent — lihat 05-database-model.md
│   ├── SnmpOlt  SmartOltOnuRegistration  SmartOltProfile  SmartOltCardStatus
│   ├── SmartOltInterfaceStatus  OltConfigBackup  OltPortLabel  CopyOnuTask  Tr069BulkTask
│   ├── AlarmEvent  AlarmNotificationRead  AlarmSetting  PollingEvent  AuditLog
│   ├── OnuRxSample  OnuRxHourly  OnuMapPin  Odp  OnuOdpLink
│   ├── TelegramSetting  PartnerTelegramBot  FcmSetting  FcmDeviceToken  AcsSetting
│   ├── GeneralSetting  User
│   ├── GenieacsCredential  GenieacsDeviceMap   NBI GenieACS (singleton) + hasil pencocokan
│   ├── Concerns/Auditable.php          Trait audit otomatis
│   ├── Concerns/TelegramBotConfigTrait.php
│   ├── Scopes/DemoScope.php            Global scope is_demo
│   └── Scopes/PartnerOltScope.php      Batasi OLT (dan turunannya) ke assignment partner / operator ber-assignment
│
├── Providers/
│   ├── AppServiceProvider.php          Listener audit login/logout/failed, rate limiter api & olt-refresh
│   └── HorizonServiceProvider.php
│
├── Services/                Logika bisnis (lihat 08/09/10)
│   ├── Snmp/
│   │   ├── OltSnmpClient.php           SNMP read/set ZTE (PHP ext-snmp), C300/C320 + C600
│   │   └── GoSnmpPoller.php            Shell-out ke bin/kv-snmp-poller
│   ├── SmartOltSnmpServiceResolver.php Pilih driver SNMP non-ZTE (C-Data EPON/GPON, HiOSO)
│   ├── ZteCliProvisioningExecutor.php  Sesi telnet ZTE, auto --More--, mask password, saveConfig
│   ├── ZteProvisioningScriptBuilder.php   Script register ONU C300/C320 (mode Sederhana)
│   ├── ZteC600ProvisioningScriptBuilder.php Script register ONU C600 (diakhiri `end` + `write`)
│   ├── ZteOnuReconfigureScriptBuilder.php Diff baseline→target (Configure, register Lanjutan, salin)
│   ├── ZteProfileCatalogService.php    Sync & parse profil dari OLT
│   ├── ZteOnuRxPowerService.php        RX power per-port via CLI
│   ├── ZteOnuDetailService.php         Detail ONU via CLI
│   ├── ZteOnuRunningConfigService.php  Parse running-config ONU (fetch / fetchMany)
│   ├── ZteRemoteOnuService.php         Reboot/hapus/bind (CLI) + enable-disable & set info (SNMP)
│   ├── ZteCardUplinkService.php        Kartu, uplink, GPON iface, VLAN, optik, deskripsi & admin-state port
│   ├── ZteOnuCopyService.php / ZteTr069BulkService.php / ZteUncfgOnuService.php
│   ├── Zte/                            OnuRegistrationService, OnuRegistrationFormDefaults,
│   │                                   UnconfiguredOnuDiscovery, C600MgmtPoolService,
│   │                                   OltConfigBackupService
│   ├── CData/                          CDataOltScanner, CDataEponSnmpService, CDataGponSnmpService,
│   │                                   CDataGponCliService, CDataCliWriteService, CDataGponPortService,
│   │                                   CDataFaceplateService, CDataSnmp, CDataValue,
│   │                                   CDataSnmpMissingOid, Concerns/InteractsWithCDataCli
│   ├── Hioso/                          HiosoEponSnmpService, HiosoCliWriteService,
│   │                                   HiosoFaceplateService, HiosoSnmp, HiosoValue
│   ├── AlarmEvaluator.php              Bandingkan snapshot → raise/clear alarm (debounce, korelasi)
│   ├── Alarm/                          AlarmNotificationService, AlarmNotificationTargetResolver,
│   │                                   OdpAlarmGrouper
│   ├── Fcm/FcmAlarmNotifier.php        Push FCM
│   ├── Telegram/                       TelegramNotifier, TelegramCommandHandler,
│   │                                   TelegramWebhookManager, TelegramKeyboard,
│   │                                   TelegramOnuQueryService, TelegramReply
│   ├── Telnet/TelnetProxyServer.php    Daemon WS↔telnet
│   ├── OnuInventoryService.php         Agregasi ONU lintas OLT (monitoring, peta, API)
│   ├── GlobalSearchService.php         Pencarian ⌘K (web & API)
│   ├── OnuOdpService.php / OltPortLabelService.php
│   ├── Map/OnuMapPayloadService.php    Payload pin peta (dipakai web & API)
│   ├── Odp/OdpPhotoService.php         Foto ODP → WebP via cwebp
│   ├── Dashboard/DashboardStatsService.php  Agregasi data dashboard
│   ├── Report/ReportService.php        Bangun data laporan + filter
│   └── Genieacs/                       Jembatan TR-069, opsional (lihat 20)
│       ├── GenieACSService.php         Klien NBI 7557 (Basic auth & https opsional)
│       ├── GenieACSParserService.php   Parse pohon parameter TR-069 → bentuk tampil
│       ├── GenieacsOnuMatcher.php      Aturan pencocokan MURNI (tanpa HTTP/DB)
│       ├── GenieacsDeviceSyncService.php Tarik katalog + simpan hasil (PROJECTION wajib)
│       ├── GenieacsManualPinService.php  Semat/lepas manual berbasis identitas + cari device
│       ├── GenieacsMapService.php      Lookup lencana ter-ACS (1 query/request)
│       ├── GenieacsDeviceDetailService.php Perangkat terhubung (cache 30 dtk)
│       └── GenieacsWifiService.php     Ubah SSID & kata sandi (satu-satunya jalur tulis)
│
└── Support/                 Util tanpa state
    ├── SmartOltSupport.php   Driver key, capabilities, pola interface, bersih nama
    ├── AuditLogger.php       Helper tulis audit_logs
    ├── CliOutputSanitizer.php Bersihkan output CLI
    ├── PonPortCards.php      Kartu halaman PON Port non-ZTE (label port sisi-NMS)
    ├── OdpColors.php         Palet warna ODP (sumber tunggal web + API)
    ├── Locale.php / Theme.php / DisplayTime.php
    └── Telnet/
        ├── TelnetTicket.php  Tiket terenkripsi sekali pakai (user↔OLT)
        └── TelnetIacFilter.php Strip/jawab IAC telnet negotiation
```

## `cmd/` & `bin/` — Go SNMP poller & skrip build

```
cmd/kv-snmp-poller/main.go   Sumber poller Go (gosnmp; C300/C320 + C600). Build → bin/kv-snmp-poller
cmd/kv-snmp-poller/main_test.go
bin/kv-snmp-poller           Binary hasil build, di-gitignore (dipanggil GoSnmpPoller via Process)
bin/build-apk.sh             Build APK Android (API_BASE_URL)
```
Build (sama dengan `install.sh`): `CGO_ENABLED=0 go build -mod=mod -trimpath -ldflags='-s -w' -o bin/kv-snmp-poller ./cmd/kv-snmp-poller` — `-mod=mod` wajib karena root repo
punya folder `vendor/` milik PHP (tanpanya Go mencoba vendor mode).

## `config/`

`app, auth, broadcasting, cache, database, filesystems, horizon, logging, mail, queue,
reverb, sanctum, services, session, telnet, trustedproxy`. Yang khas proyek ini:
- `services.php` → blok `snmp_poller` (driver/binary/timeout/walk-mode/retensi RX), `acs`
  (URL/kredensial ACS bawaan untuk registrasi TR-069), `fcm`, `map` (titik awal peta), `cwebp`.
- `telnet.php` → host/port proxy, `ws_url`, `ticket_ttl` (bawaan 30 dtk), `connect_timeout`.
- `trustedproxy.php` → `TRUSTED_PROXIES` (bawaan `127.0.0.1,::1`); isi bila di belakang
  Cloudflare "Flexible" / load balancer di host lain.

## `database/`

```
migrations/   Skema (urut tanggal). WAJIB SQLite-compatible (test pakai SQLite).
seeders/      DatabaseSeeder (1 admin test) + DemoSeeder (data demo, JANGAN di prod)
factories/    UserFactory
```

## `routes/`

```
web.php       Route aplikasi (auth-protected) + landing + /locale + telegram webhook
api.php       REST API v1 (/api/v1, Sanctum)
auth.php      Breeze: login, lupa/reset password, verifikasi email, konfirmasi & ganti password,
              logout (tanpa rute registrasi)
console.php   Jadwal: olts:poll, optical:*, sanctum:prune-expired, olts:backup-config,
              genieacs:match-onu
channels.php  Broadcast channel privat user
```

## `lang/` — string backend (`__()`)

`lang/{id,en}/*.php`: `flash`, `reports`, `system`, grup error per domain `olt, zte, cdata, hioso,
odp, acs, common`; `lang/id` juga memuat `auth, validation, pagination, passwords`. Dijaga
`tests/Unit/LangParityTest`.

## `resources/` — frontend

```
resources/
├── css/app.css                Token tema per [data-theme] + kelas kv-*
├── views/app.blade.php        Root Inertia (@routes Ziggy + @vite, ber-nonce CSP)
├── views/reports/pdf.blade.php Template laporan PDF (dompdf)
└── js/
    ├── app.js                 Bootstrap Inertia + Ziggy + i18n + handler vite:preloadError
    ├── i18n.js                vue-i18n + compiler pesan literal kustom
    ├── lang/{id,en}.json      String UI per namespace
    ├── bootstrap.js           Axios global
    ├── Layouts/               AuthenticatedLayout, GuestLayout
    ├── Pages/                 Halaman Inertia (1 file = 1 route render). Lihat 12-frontend.md
    │   ├── Dashboard.vue  Welcome.vue
    │   ├── SmartOlt/…     (Index, Create/Edit, Detail, PonPorts, PortDetail, PortOnus,
    │   │                   OnuMonitor, OnuDetail, ConfigureOnu, RegisterOnu, Registrations,
    │   │                   Profiles, Unconfigured[Global], ConfigBackups, Alarms)
    │   ├── CDataOlt/…     (Create/Edit, Detail, PortOnus, PortDetail, Vlans)
    │   ├── Hioso/…        (Create/Edit, Detail, PortOnus)
    │   ├── Map/  Odp/  Reports/  Settings/  Users/  AuditLogs/  Profile/  Panduan/
    │   └── Partner/TelegramBot · Auth/* (Login, ForgotPassword, ResetPassword, …)
    ├── Components/            Komponen reusable (dasar, Dashboard/*, Shell/*, SmartOlt/*, Map/*,
    │                          CDataOlt/*, Genieacs/* + OnuAcsBadge)
    ├── Composables/           useConfirm, useLocale, usePagination, useRxLevel
    └── lib/                   theme, datetime, alarm, onu, odpColors, oltImage, linediff, …
```

## `tests/`

```
tests/Feature/   Test fitur (HTTP) — termasuk Feature/Api (REST API) & Feature/Auth (Breeze)
tests/Unit/      Test unit (parser CLI/SNMP, builder script, LangParityTest)
tests/js/        Test komponen Vue (Vitest, jsdom)
```
Jalankan **hanya** lewat `bash scripts/test.sh` (PHPUnit) dan `npm test` (Vitest) — lihat
[14](14-panduan-tambah-fitur.md).

## `mobile/`, `scripts/`, `docs/`

- `mobile/` — aplikasi Android Flutter (lihat `mobile/README.md`, `docs/BUILD_APK.md`).
- `scripts/` — `test.sh` (runner PHPUnit aman), `check-requirements.sh`, `snapshot.mjs`, `keys/`.
- `docs/` — handbook ini, indeks `docs/README.md`, referensi CLI/OID per vendor, `API.md`,
  `INSTALL.md`, `DOCKER.md`, dan panduan pengguna PDF di `docs/panduan/`.

## `public/`, `storage/`, `vendor/`, `node_modules/`

Standar Laravel. `storage/app/public` menampung logo upload (disk `public`); foto ODP di disk
**privat** `storage/app/private/odp-photos/`. `public/img/olt/` = gambar produk OLT.
`public/downloads/` = APK hasil `bin/build-apk.sh` (dibuat saat build, gitignored). `bootstrap/cache/` berisi config/route cache di produksi.

## Selanjutnya

→ [04 — Instalasi & Deploy](04-instalasi-deploy.md)
