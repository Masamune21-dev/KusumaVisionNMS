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
- **Scheduled polling DOES use a Go engine** (`bin/kv-snmp-poller`, source `cmd/kv-snmp-poller/`) — see Architecture. On-demand actions (manual refresh, provisioning, telnet, per-port RX) are synchronous PHP in controllers/services — **except batch jobs**: copy-ONU-to-port (`app/Jobs/CopyOnusToPortJob.php` + `copy_onu_tasks`) and **bulk-enable-TR069** (`app/Jobs/Tr069BulkConfigJob.php` + `tr069_bulk_tasks`), both polled for progress.
- Per-OLT live state is cached as JSON in `snmp_olts.last_test_result`.

## Architecture

- `app/Models/SnmpOlt.php` — OLT inventory. Secrets (`snmp_read_community`, `snmp_write_community`, `cli_password`) use `encrypted` casts and are `$hidden`. On edit, empty secret fields preserve existing values (`withoutEmptySecrets`).
- `app/Services/Snmp/OltSnmpClient.php` — SNMP read: system info, GPON ports (IF-MIB), ZTE ONU table, unconfigured ONU discovery. Parsing quirks are tuned against real firmware (`OLT-C320-PATI`).
- `app/Services/ZteCliProvisioningExecutor.php` — Telnet session; auto-continues `--More--` pagers; masks CLI password in captured output.
- `app/Services/ZteProvisioningScriptBuilder.php` — builds ONU provisioning CLI scripts (register, VLAN/T-CONT, PPPoE/DHCP/static WAN, TR069, Remote ONT).
- `app/Services/ZteProfileCatalogService.php` — per-OLT profile sync; runs `show ...` commands and parses onu_type/tcont/vlan/ip profiles.
- `app/Services/ZteOnuRxPowerService.php` — ONU RX power via `show pon power onu-rx`.
- `app/Http/Controllers/SmartOltController.php` + `SmartOltProfileController.php` — all SmartOLT routes (`routes/web.php`, auth-protected). `onuMonitor()`/`refreshOnuMonitor()` back the cross-OLT **ONU Monitoring** page (route `monitoring.onu`, `Pages/SmartOlt/OnuMonitor.vue`): aggregates cached `port_onus` across all OLTs; refresh does one full-OLT SNMP walk and writes per-port cache. Per-port page (`Pages/SmartOlt/PortOnus.vue`) also has: **copy ONU config to another port** (batch — reads each source ONU's running-config in one telnet session, rebuilds a full registration via `ZteOnuReconfigureScriptBuilder::buildForCopy()`, queued job + progress modal), **delete ONU** (`no onu {id}`, gated `supports_onu_delete`). Per-port refresh (`portOnusSnapshot`) walks only the port's ONU-table subtree on C300/C320 (scoped by `zteEncodeIfIndex` prefix) — not the whole OLT.
- **Go SNMP poller** — `cmd/kv-snmp-poller/main.go` (+ `go.mod`) builds to `bin/kv-snmp-poller`. `app/Services/Snmp/GoSnmpPoller.php` shells out to it (Symfony Process, JSON out); `enabled()` when `SNMP_POLLER_DRIVER=go` (`config/services.php`) and the binary exists. `app/Jobs/PollOltJob.php` (scheduled, runs under supervisor worker) uses the Go poller when enabled and **falls back to `OltSnmpClient`** on failure. Prod `.env` sets `SNMP_POLLER_DRIVER=go`.
- **C-Data juga ikut scheduled polling** — `PollOltJob` mendeteksi family C-Data (`SmartOltSupport::isCData`) lalu mengambil jalur sendiri `pollCData()`: scan penuh via `CDataOltScanner` (driver EPON SNMP / GPON V3 SNMP+CLI menulis `last_test_result.port_onus` bentuk-ZTE), set penanda `ok` top-level (dibutuhkan `AlarmEvaluator`, scanner tak menyetelnya), catat sampel RX (`onu_rx_samples`) saat RX due, evaluasi alarm, log `PollingEvent`. `PollOltsCommand` (`olts:poll`, tiap menit) men-dispatch SEMUA OLT `polling_enabled` yang due (ZTE **dan** C-Data). On-demand refresh C-Data (`CDataOltController`) tetap ada untuk refresh manual/halaman.
- **Browser telnet (xterm.js)** — `app/Services/Telnet/TelnetProxyServer.php` is a WebSocket↔telnet proxy (built on react/socket + ratchet/rfc6455 already pulled by Reverb) run via `php artisan telnet:proxy`. `app/Http/Controllers/TelnetSessionController.php` issues a short-lived encrypted ticket (`app/Support/Telnet/TelnetTicket.php`, URL-safe); `TelnetIacFilter` strips/answers IAC. Frontend: `resources/js/Components/Shell/TelnetWindow.vue` (draggable/min/max). In prod the daemon binds localhost and is reached via nginx `/telnet-ws`; runs under supervisor (`kusumavision-telnet-proxy`).
- **TR069 massal (per-port)** — tombol "TR069 Massal" di halaman ONU per port (`Pages/SmartOlt/PortOnus.vue` + `Components/SmartOlt/Tr069BulkModal.vue`) mengaktifkan TR069/ACS di **semua ONU satu PON port** OLT ZTE. `app/Services/ZteTr069BulkService.php` di-scope ke slot/port (lewat `$onlySlot`/`$onlyPort`; null = seluruh OLT, dipakai baris task lama), membaca running-config tiap ONU (1 sesi telnet/port via `ZteOnuRunningConfigService::fetchMany`), **men-skip** ONU yang TR069-nya sudah unlock & ACS-nya (url+username) sudah mengarah ke target, lalu menulis `tr069-mgmt 1 state unlock` + acs line ke sisanya (1 sesi tulis/port). Dua fase lewat flag `execute`: dry-run (pindai saja) lalu eksekusi. Jalan di queued job (`Tr069BulkConfigJob` + tabel `tr069_bulk_tasks` yang menyimpan `slot`/`port`, di-poll). Default ACS dari `config('services.acs')` (`ACS_URL`/`ACS_USERNAME`/`ACS_PASSWORD`, default `http://acs.bmkv.net:7547`/`cms`/`kusuma123!`). Routes `smartolt.tr069-bulk` (POST `…/ports/{slot}/{port}/tr069-bulk`) + `smartolt.tr069-bulk.status` (GET), gated `supports_cli_onu_configure`.
- `app/Http/Controllers/DashboardSearchController.php` — global search (⌘K): matches OLT by name/ip and ONU by `serial_number`/name/interface from cached `port_onus`.
- **Peta ONU** — `app/Http/Controllers/OnuMapController.php` + `Pages/Map/Index.vue` (route `map.index`): Leaflet map of customer-ONU pins across all OLTs (ZTE & C-Data). Pins live in table `onu_map_pins` (only a *reference* `snmp_olt_id/slot/port/onu_id` + coords + optional customer fields — ONUs themselves stay cacheless), enriched live via `app/Services/OnuInventoryService.php` (cross-OLT ONU aggregation extracted from `onuMonitor()`, shared by both). Map component `Components/Map/OnuMap.vue` is **lazy-loaded** (`defineAsyncComponent`) and uses **keyless Google tiles** (`mt{s}.google.com/vt`, unofficial/free) + OSM fallback. Add a pin via map-click modal (`AddPinModal.vue`, OLT→port→ONU dropdowns + global search) or the **Add Map** button on Port ONUs pages (paste Google Maps link → `map.resolve-link` parses coords; or "click on map" placement mode). Pin detail (`PinDetailCard.vue`) has edit-name/reboot that hit **map-specific** routes `map.pins.reboot|rename` (delegate to `ZteRemoteOnuService`/`CDataCliWriteService` then redirect back to `/map`, unlike `smartolt.onu.*`/`cdata-olt.onu.*` which redirect to the port page). See `docs/handbook/16-peta-onu.md`.
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
