# CLAUDE.md

KusumaVision NMS — FTTH/GPON Network Management System (Laravel 12 + Vue 3/Inertia + Tailwind) owned by PT BERKAH MEDIA KUSUMA VISION (BMKV). A SmartOLT/NetNumen alternative for Indonesian ISPs to manage ZTE GPON OLTs and provision ONUs.

The user communicates in Indonesian — respond in Indonesian (bilingual when discussing code/English terms).

## Commands

```bash
composer dev        # serve + queue + pail logs + vite (concurrently)
php artisan serve --host=0.0.0.0 --port=8000
npm run dev         # vite
php artisan test    # PHPUnit (uses in-memory sqlite, see phpunit.xml)
./vendor/bin/pint   # code style
```

App DB is PostgreSQL (`kusumavision_nms`), Redis for cache/session/queue, locale `id`. Tests run on in-memory sqlite, so migrations must stay sqlite-compatible.

## Scope reality (important)

The PRD (`KusumaVision_NMS_PRD.md`) describes a broad vision; the **built scope is narrower**. Do not assume PRD features exist:

- **ZTE C300/C320 only.** `App\Support\SmartOltSupport::driverKey()` returns `unknown` for anything else and disables all capabilities.
- **SNMP read = v1/v2c only.** v3 throws (`OltSnmpClient`).
- **CLI execution = Telnet only.** `ZteCliProvisioningExecutor` rejects SSH (phpseclib installed but no SSH driver wired).
- **No Go polling engine, no TimescaleDB, no `onus`/`optical_metrics` tables, no multi-vendor, no AI** — these are PRD vision, not code. All SNMP/CLI is synchronous PHP in controllers.
- Per-OLT live state is cached as JSON in `snmp_olts.last_test_result`.

## Architecture

- `app/Models/SnmpOlt.php` — OLT inventory. Secrets (`snmp_read_community`, `snmp_write_community`, `cli_password`) use `encrypted` casts and are `$hidden`. On edit, empty secret fields preserve existing values (`withoutEmptySecrets`).
- `app/Services/Snmp/OltSnmpClient.php` — SNMP read: system info, GPON ports (IF-MIB), ZTE ONU table, unconfigured ONU discovery. Parsing quirks are tuned against real firmware (`OLT-C320-PATI`).
- `app/Services/ZteCliProvisioningExecutor.php` — Telnet session; auto-continues `--More--` pagers; masks CLI password in captured output.
- `app/Services/ZteProvisioningScriptBuilder.php` — builds ONU provisioning CLI scripts (register, VLAN/T-CONT, PPPoE/DHCP/static WAN, TR069, Remote ONT).
- `app/Services/ZteProfileCatalogService.php` — per-OLT profile sync; runs `show ...` commands and parses onu_type/tcont/vlan/ip profiles.
- `app/Services/ZteOnuRxPowerService.php` — ONU RX power via `show pon power onu-rx`.
- `app/Http/Controllers/SmartOltController.php` + `SmartOltProfileController.php` — all SmartOLT routes (`routes/web.php`, auth-protected).
- Frontend: `resources/js/Pages/SmartOlt/*.vue` (Inertia pages).

## Conventions

- Provisioning writes an audit row in `smartolt_onu_registrations` (script generated first, executed later/optionally). Profiles are scoped per-OLT with global (`snmp_olt_id = null`) fallback.
- `SMARTOLT_ZTE_C300_C320_GUIDE.md` is the **authoritative ZTE CLI command reference** — consult it over guessing command syntax.
- `WORKLOG.md` logs work phase-by-phase (Created/Changed/Notes, with real-OLT verification). Add a matching entry for meaningful changes.
- UI flash messages and many user-facing strings are in Indonesian.
- Live test OLTs: id=1 (`OLT-C320-PATI`) and id=2.
