# 03 — Struktur Folder

[← Indeks](README.md) · [← 02 Arsitektur](02-arsitektur.md) · [04 Instalasi & Deploy →](04-instalasi-deploy.md)

Peta direktori repo dengan penjelasan singkat tiap bagian. Path relatif ke root proyek
`/var/www/KusumaVisionNMS`.

## Akar (root)

```
CLAUDE.md                  Instruksi ringkas proyek untuk asisten/agent
README.md                  README publik
WORKLOG.md                 Riwayat pekerjaan fase-per-fase (WAJIB diupdate per perubahan)
composer.json / .lock      Dependensi PHP
package.json / lock        Dependensi JS
go.mod / go.sum            Modul Go untuk SNMP poller
vite.config.js             Konfigurasi build frontend (emptyOutDir:false → chunk lama disimpan)
tailwind.config.js         Konfigurasi Tailwind
phpunit.xml                Konfigurasi test (SQLite in-memory)
artisan                    Entry CLI Laravel
.env / .env.example        Konfigurasi environment
```

## `app/` — kode backend (PSR-4 `App\`)

```
app/
├── Console/Commands/        Artisan command kustom
│   ├── CreateUserCommand.php       user:create (registrasi publik dimatikan)
│   ├── PollOltsCommand.php         olts:poll (dispatch PollOltJob per OLT due)
│   ├── TelegramWebhookCommand.php  telegram:webhook {set|info|delete}
│   └── TelnetProxyCommand.php      telnet:proxy (daemon WS↔telnet)
│
├── Enums/
│   └── UserRole.php          Admin | Operator | Demo (+ label/options)
│
├── Http/
│   ├── Controllers/          Lihat 06-routing.md untuk pemetaan lengkap
│   │   ├── SmartOltController.php       (~1300 baris — inti SmartOLT)
│   │   ├── SmartOltProfileController.php
│   │   ├── DashboardController.php / DashboardSearchController.php
│   │   ├── AlarmController.php / ReportController.php
│   │   ├── SettingsController.php / UserController.php
│   │   ├── AuditLogController.php / NotificationsController.php
│   │   ├── TelegramWebhookController.php / TelnetSessionController.php
│   │   ├── ProfileController.php
│   │   └── Auth/…                       (Breeze scaffolding)
│   ├── Middleware/
│   │   ├── HandleInertiaRequests.php    Share props global ke semua page
│   │   ├── BlockDemoWrites.php          Demo = read-only
│   │   └── EnsureUserRole.php           alias 'role:...'
│   └── Requests/             Form request (ProfileUpdateRequest, Auth/*)
│
├── Jobs/
│   └── PollOltJob.php        Job polling 1 OLT (Go poller + fallback PHP + alarm)
│
├── Models/                  Eloquent — lihat 05-database-model.md
│   ├── SnmpOlt.php  SmartOltOnuRegistration.php  SmartOltProfile.php
│   ├── SmartOltCardStatus.php  SmartOltInterfaceStatus.php
│   ├── AlarmEvent.php  PollingEvent.php  AuditLog.php
│   ├── TelegramSetting.php  GeneralSetting.php  User.php
│   ├── Concerns/Auditable.php          Trait audit otomatis
│   └── Scopes/DemoScope.php            Global scope is_demo
│
├── Providers/
│   ├── AppServiceProvider.php          Listener audit login/logout/failed
│   └── HorizonServiceProvider.php
│
├── Services/                Logika bisnis (lihat 08/09/10)
│   ├── Snmp/
│   │   ├── OltSnmpClient.php           SNMP read (PHP ext-snmp), parsing ZTE
│   │   └── GoSnmpPoller.php            Shell-out ke bin/kv-snmp-poller
│   ├── ZteCliProvisioningExecutor.php  Sesi telnet, auto --More--, mask password
│   ├── ZteProvisioningScriptBuilder.php Generate script register ONU
│   ├── ZteOnuReconfigureScriptBuilder.php Diff baseline→target → script perubahan
│   ├── ZteProfileCatalogService.php    Sync & parse profil dari OLT
│   ├── ZteOnuRxPowerService.php        RX power per-port via CLI
│   ├── ZteOnuDetailService.php         Detail ONU via CLI
│   ├── ZteOnuRunningConfigService.php  Parse running-config ONU (baseline reconfigure)
│   ├── ZteRemoteOnuService.php         Reboot / enable-disable / set info ONU
│   ├── ZteCardUplinkService.php        Card status, uplink, GPON iface, VLAN, optik
│   ├── AlarmEvaluator.php              Bandingkan snapshot → raise/clear alarm
│   ├── Dashboard/DashboardStatsService.php  Agregasi data dashboard
│   ├── Report/ReportService.php        Bangun data laporan + filter
│   └── Telegram/
│       ├── TelegramNotifier.php        Kirim notifikasi alarm + test
│       ├── TelegramCommandHandler.php  Handle command inbound (/status, /onu, ...)
│       └── TelegramWebhookManager.php  Register/info/delete webhook
│   └── Telnet/TelnetProxyServer.php    Daemon WS↔telnet
│
└── Support/                 Util tanpa state
    ├── SmartOltSupport.php   Driver key, capabilities, pola interface, bersih nama
    ├── AuditLogger.php       Helper tulis audit_logs
    ├── CliOutputSanitizer.php Bersihkan output CLI
    └── Telnet/
        ├── TelnetTicket.php  Tiket terenkripsi singkat (user↔OLT)
        └── TelnetIacFilter.php Strip/jawab IAC telnet negotiation
```

## `cmd/` & `bin/` — Go SNMP poller

```
cmd/kv-snmp-poller/main.go   Sumber poller Go (gosnmp). Build → bin/kv-snmp-poller
bin/kv-snmp-poller           Binary hasil build (dipanggil GoSnmpPoller via Process)
```
Build: `go build -o bin/kv-snmp-poller ./cmd/kv-snmp-poller`

## `config/`

`app, auth, broadcasting, cache, database, filesystems, horizon, logging, mail, queue,
reverb, services, session, telnet`. Yang khas proyek ini:
- `services.php` → blok `snmp_poller` (driver/binary/timeout/walk-mode).
- `telnet.php` → host/port proxy, `ws_url`, `ticket_ttl`, `connect_timeout`.

## `database/`

```
migrations/   Skema (urut tanggal). WAJIB SQLite-compatible (test pakai SQLite).
seeders/      DatabaseSeeder (1 admin test) + DemoSeeder (data demo, JANGAN di prod)
factories/    UserFactory
```

## `routes/`

```
web.php       Semua route aplikasi (auth-protected) + landing + telegram webhook
auth.php      Route Breeze (login, register, reset password, verifikasi email)
console.php   Schedule olts:poll everyMinute + command inspire
channels.php  Broadcast channel privat user
```

## `resources/` — frontend

```
resources/
├── css/app.css
├── views/app.blade.php        Root Inertia
└── js/
    ├── app.js                 Bootstrap Inertia + Ziggy + handler vite:preloadError
    ├── bootstrap.js           Axios global
    ├── Layouts/               AuthenticatedLayout, GuestLayout
    ├── Pages/                 Halaman Inertia (1 file = 1 route render). Lihat 12-frontend.md
    │   ├── Dashboard.vue  Welcome.vue
    │   ├── SmartOlt/…     (Index, Detail, GponPorts, PortManager, PortOnus,
    │   │                   OnuMonitor, OnuDetail, ConfigureOnu, RegisterOnu,
    │   │                   Registrations, Profiles, Unconfigured[Global], Create/Edit)
    │   ├── Reports/  Settings/  Users/  AuditLogs/  Profile/  Auth/
    ├── Components/             Komponen reusable (button, modal, Dashboard/*, Shell/*)
    ├── Composables/useConfirm.js
    └── lib/datetime.js
```

## `tests/`

```
tests/Feature/   Test fitur (HTTP)
tests/Unit/      Test unit
```

## `public/`, `storage/`, `vendor/`, `node_modules/`

Standar Laravel. `storage/app/public` menampung logo upload (disk `public`). `bootstrap/cache/`
berisi config/route cache di produksi.

## Selanjutnya

→ [04 — Instalasi & Deploy](04-instalasi-deploy.md)
