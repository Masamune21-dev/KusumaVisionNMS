# CLAUDE.md

KusumaVision NMS — FTTH/GPON Network Management System (Laravel 12 + Vue 3/Inertia + Tailwind) owned by PT BERKAH MEDIA KUSUMA VISION (BMKV). A SmartOLT/NetNumen alternative for Indonesian ISPs to manage ZTE GPON OLTs and provision ONUs.

The user communicates in Indonesian — respond in Indonesian (bilingual when discussing code/English terms).

**Detailed developer docs live in `docs/handbook/`** (per-topic: overview, architecture, folder map, install/deploy, DB schema, routing, modules, SNMP/polling, CLI/telnet, alarm/Telegram, security/RBAC, frontend, troubleshooting, how-to-add-features). Start there for deep dives, and keep it in sync when structure or conventions change. License is proprietary (`LICENSE`).

## Commands

```bash
composer dev        # serve + queue + pail logs + vite (concurrently)
php artisan serve --host=0.0.0.0 --port=8000
npm run dev         # vite
php artisan test    # PHPUnit (uses in-memory sqlite, see phpunit.xml)
./vendor/bin/pint   # code style
php artisan telnet:proxy           # WebSocket<->telnet proxy daemon (browser terminal)
go build -o bin/kv-snmp-poller ./cmd/kv-snmp-poller   # rebuild Go SNMP poller
sudo bash install.sh               # one-command deploy on a fresh Ubuntu server (--yes for non-interactive)
bash scripts/check-requirements.sh # verify tools/extensions/artifacts/services (+ min versions)
```

App DB is PostgreSQL (`kusumavision_nms`), Redis for cache/session/queue, locale `id`. Tests run on in-memory sqlite, so migrations must stay sqlite-compatible.

## Scope reality (important)

The PRD (`KusumaVision_NMS_PRD.md`) describes a broad vision; the **built scope is narrower**. Do not assume PRD features exist:

- **ZTE C300/C320 only.** `App\Support\SmartOltSupport::driverKey()` returns `unknown` for anything else and disables all capabilities.
- **SNMP read = v1/v2c only.** v3 throws (`OltSnmpClient`).
- **CLI execution = Telnet only.** `ZteCliProvisioningExecutor` rejects SSH (phpseclib installed but no SSH driver wired). Interactive browser telnet also Telnet-only (see Architecture).
- **No TimescaleDB, no `onus`/`optical_metrics` tables, no multi-vendor, no AI** — these remain PRD vision, not code.
- **Scheduled polling DOES use a Go engine** (`bin/kv-snmp-poller`, source `cmd/kv-snmp-poller/`) — see Architecture. On-demand actions (manual refresh, provisioning, telnet, per-port RX) are synchronous PHP in controllers/services.
- Per-OLT live state is cached as JSON in `snmp_olts.last_test_result`.

## Architecture

- `app/Models/SnmpOlt.php` — OLT inventory. Secrets (`snmp_read_community`, `snmp_write_community`, `cli_password`) use `encrypted` casts and are `$hidden`. On edit, empty secret fields preserve existing values (`withoutEmptySecrets`).
- `app/Services/Snmp/OltSnmpClient.php` — SNMP read: system info, GPON ports (IF-MIB), ZTE ONU table, unconfigured ONU discovery. Parsing quirks are tuned against real firmware (`OLT-C320-PATI`).
- `app/Services/ZteCliProvisioningExecutor.php` — Telnet session; auto-continues `--More--` pagers; masks CLI password in captured output.
- `app/Services/ZteProvisioningScriptBuilder.php` — builds ONU provisioning CLI scripts (register, VLAN/T-CONT, PPPoE/DHCP/static WAN, TR069, Remote ONT).
- `app/Services/ZteProfileCatalogService.php` — per-OLT profile sync; runs `show ...` commands and parses onu_type/tcont/vlan/ip profiles.
- `app/Services/ZteOnuRxPowerService.php` — ONU RX power via `show pon power onu-rx`.
- `app/Http/Controllers/SmartOltController.php` + `SmartOltProfileController.php` — all SmartOLT routes (`routes/web.php`, auth-protected). `onuMonitor()`/`refreshOnuMonitor()` back the cross-OLT **ONU Monitoring** page (route `monitoring.onu`, `Pages/SmartOlt/OnuMonitor.vue`): aggregates cached `port_onus` across all OLTs; refresh does one full-OLT SNMP walk and writes per-port cache.
- **Go SNMP poller** — `cmd/kv-snmp-poller/main.go` (+ `go.mod`) builds to `bin/kv-snmp-poller`. `app/Services/Snmp/GoSnmpPoller.php` shells out to it (Symfony Process, JSON out); `enabled()` when `SNMP_POLLER_DRIVER=go` (`config/services.php`) and the binary exists. `app/Jobs/PollOltJob.php` (scheduled, runs under supervisor worker) uses the Go poller when enabled and **falls back to `OltSnmpClient`** on failure. Prod `.env` sets `SNMP_POLLER_DRIVER=go`.
- **Browser telnet (xterm.js)** — `app/Services/Telnet/TelnetProxyServer.php` is a WebSocket↔telnet proxy (built on react/socket + ratchet/rfc6455 already pulled by Reverb) run via `php artisan telnet:proxy`. `app/Http/Controllers/TelnetSessionController.php` issues a short-lived encrypted ticket (`app/Support/Telnet/TelnetTicket.php`, URL-safe); `TelnetIacFilter` strips/answers IAC. Frontend: `resources/js/Components/Shell/TelnetWindow.vue` (draggable/min/max). In prod the daemon binds localhost and is reached via nginx `/telnet-ws`; runs under supervisor (`kusumavision-telnet-proxy`).
- `app/Http/Controllers/DashboardSearchController.php` — global search (⌘K): matches OLT by name/ip and ONU by `serial_number`/name/interface from cached `port_onus`.
- Frontend: `resources/js/Pages/SmartOlt/*.vue` (Inertia pages).

## Conventions

- Provisioning writes an audit row in `smartolt_onu_registrations` (script generated first, executed later/optionally). Profiles are scoped per-OLT with global (`snmp_olt_id = null`) fallback.
- `SMARTOLT_ZTE_C300_C320_GUIDE.md` is the **authoritative ZTE CLI command reference** — consult it over guessing command syntax.
- `WORKLOG.md` logs work phase-by-phase (Created/Changed/Notes, with real-OLT verification). Add a matching entry for meaningful changes.
- UI flash messages and many user-facing strings are in Indonesian.
- **UI/tema dashboard** (dark glass, aksen cyan/sky, kelas `kv-*` di `resources/css/app.css`, shell `AuthenticatedLayout`): saat menambah/ubah halaman atau komponen, ikuti aturan di `docs/handbook/15-ui-tema-dashboard.md` (pakai `kv-*` dulu, kartu kaca semi-transparan, tabel responsif desktop+mobile, ikon Lucide, gerbang `auth.can`, string Indonesia).
- Live test OLTs: id=1 (`OLT-C320-PATI`) and id=2.
- **Prod runs cached config** (`bootstrap/cache/config.php`); `.env` is `640 root:www-data` so the app user can read it. After any `.env`/config change run `php artisan config:cache` so the cache picks it up, then restart the supervisor daemons (`queue:restart` for `kusumavision-worker`, `supervisorctl restart kusumavision-telnet-proxy`). Long-lived daemons need a restart to pick up code changes. (Keep `.env` group-readable by `www-data` — if it's `root:root`, a cleared config falls back to sqlite and the site 500s.)
- **Fresh-server deploy** is automated by `install.sh` (installs runtime, DB, `.env`, build, Go poller, migrations, nginx site + supervisor daemons `kusumavision-worker`/`-scheduler`/`-telnet-proxy`). `scripts/check-requirements.sh` validates tools/extensions/services. Full walkthrough: `docs/handbook/04-instalasi-deploy.md`.
