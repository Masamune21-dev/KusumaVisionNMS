# 05 — Database & Model

[← Indeks](README.md) · [← 04 Instalasi & Deploy](04-instalasi-deploy.md) · [06 Routing →](06-routing.md)

DB produksi: **PostgreSQL** (`kusumavision_nms`). Test: **SQLite in-memory**. Migrasi berada di
`database/migrations/` dan **harus tetap kompatibel SQLite**.

## Peta tabel ↔ model

| Tabel | Model | Fungsi |
|-------|-------|--------|
| `users` | `User` | Akun + role (admin/operator/demo) |
| `snmp_olts` | `SnmpOlt` | Inventory OLT + secret + cache live-state JSON |
| `smartolt_onu_registrations` | `SmartOltOnuRegistration` | Audit/record provisioning ONU + script CLI |
| `smartolt_profiles` | `SmartOltProfile` | Katalog profil (onu_type/tcont/vlan/ip), scoped per-OLT |
| `smartolt_card_statuses` | `SmartOltCardStatus` | Status kartu/slot OLT (hasil parse CLI) |
| `smartolt_interface_statuses` | `SmartOltInterfaceStatus` | Status interface uplink/GPON + metrik optik/trafik |
| `alarm_events` | `AlarmEvent` | Alarm aktif/cleared |
| `polling_events` | `PollingEvent` | Log tiap polling/test/provisioning (untuk tren dashboard) |
| `onu_rx_samples` | `OnuRxSample` | Time-series RX power per ONU (histogram distribusi & grafik tren) |
| `telegram_settings` | `TelegramSetting` | Singleton konfigurasi bot Telegram |
| `general_settings` | `GeneralSetting` | Singleton branding (nama app, versi, logo) |
| `audit_logs` | `AuditLog` | Jejak audit immutable |
| `cache`, `jobs`, `sessions` | — | Tabel bawaan Laravel |

## Konsep lintas-model penting

### 1. Secret terenkripsi + `$hidden`
`SnmpOlt` (`snmp_read_community`, `snmp_write_community`, `cli_password`),
`SmartOltOnuRegistration` (`pppoe_password`, `acs_password`),
`TelegramSetting` (`bot_token`, `webhook_secret`) memakai cast `encrypted` dan `$hidden`.
Enkripsi pakai `APP_KEY` → **jangan ganti APP_KEY** tanpa migrasi, atau secret jadi tak terbaca.

Saat edit OLT, field secret kosong **tidak menimpa** nilai lama —
`SmartOltController::withoutEmptySecrets()`.

### 2. Cache live-state JSON: `snmp_olts.last_test_result`
Kolom `json` (cast `array`) menyimpan snapshot terkini OLT + ONU per port (`port_onus`).
Tidak ada tabel ONU. Lihat struktur di [02 — Arsitektur](02-arsitektur.md#cache-live-state-snmp_oltslast_test_result).

### 3. Audit otomatis — trait `Auditable`
Model yang `use Auditable` otomatis menulis `audit_logs` saat created/updated/deleted.
Lihat [11 — Keamanan, RBAC & Audit](11-keamanan-rbac-audit.md). Field volatil dikecualikan via
`$auditExclude` (mis. `last_test_result`, `last_polled_at`).

### 4. Demo isolation — `DemoScope` global scope
Model dengan `is_demo` (`SnmpOlt`, `SmartOltOnuRegistration`, `AlarmEvent`, `PollingEvent`)
memakai `DemoScope`: user role `demo` hanya melihat baris `is_demo = true`; konteks lain
(termasuk console/queue tanpa auth) hanya `is_demo = false`. Lihat [11](11-keamanan-rbac-audit.md).

---

## Detail tabel

### `snmp_olts` — inventory OLT
```
id  name(100)  vendor(100,null)  ip(unique)  snmp_port(=161)
snmp_read_community(text, enc)  snmp_write_community(text,null, enc)
snmp_version(enum v1|v2c|v3 =v2c)
cli_transport(enum telnet|ssh, null)  cli_port(null)  cli_username(100,null)  cli_password(text,null, enc)
polling_enabled(bool)  poll_interval_minutes  rx_poll_interval_minutes
last_test_result(json)  last_tested_at  last_polled_at  last_rx_polled_at
is_demo(bool)  timestamps
```
Method penting di `SnmpOlt`:
- `getHostAddress()` → `ip` atau `ip:port`.
- `isPollDue()` / `isRxPollDue()` → cek interval vs `last_*_polled_at`.
- `pollIntervalMinutes()` / `rxPollIntervalMinutes()` → default 5 menit, minimal 1.
- `defaultCliPort()` → 22 (ssh) / 23 (telnet).
- Relasi: `cardStatuses()`, `interfaceStatuses()` (hasMany).

> Catatan: kolom `polling_enabled`, `*_interval`, `last_polled_at`, `last_rx_polled_at`, `is_demo`
> ditambahkan oleh migrasi `add_polling_fields...`, `add_poll_intervals...`, `add_is_demo_flags`.

### `smartolt_onu_registrations` — record provisioning
Identitas ONU (`serial_number`, `slot`, `port`, `onu_id`, `pon_port`, `oid_index`),
layanan (`onu_type`, `tcont_profile`, `vlan`, `vlan_profile`, `service_name`),
WAN (`wan_mode` pppoe|dhcp|static, `pppoe_username/password`, `ip_profile`, `static_ip/netmask`),
TR069 (`tr069_enabled`, `acs_url/username/password`),
Remote ONT (`remote_ont_enabled/id/mode/protocol`),
eksekusi (`cli_script`, `execution_output`, `execution_error`, `executed_at`, `executed_by`,
`status` = generated/…), `created_by`, `is_demo`.
Relasi: `olt()`, `creator()`, `executor()`. Field besar/sensitif dikecualikan dari audit.

### `smartolt_profiles` — katalog profil
`profile_type` ∈ `SmartOltProfile::TYPES = ['onu_type','tcont','vlan','ip']`, `name`, `source`,
`vlan`, `params(json)`, `notes`, `is_active`, `last_synced_at`.
Di-scope per-OLT lewat `snmp_olt_id` (migrasi `scope_smartolt_profiles_to_olt`); profil global =
`snmp_olt_id = null` sebagai fallback.

### `smartolt_card_statuses` — kartu/slot
`rack, shelf, slot, cfg_type, real_type, port_count, hard_ver, soft_ver, status, raw_line,
refreshed_at`. Unik per `(snmp_olt_id, rack, shelf, slot)`. Diisi `ZteCardUplinkService::parseCards()`.

### `smartolt_interface_statuses` — uplink & GPON iface
Banyak kolom: identitas (`interface`, `interface_type`, `slot`, `port`, `card_type`),
status (`admin_status`, `link_status`, `hybrid_status`, `negotiation`, `speed_mbps`, `duplex`),
VLAN (`native_vlan`, `tagged_vlans` json), kapasitas GPON (`onu_capacity`, `registered_onu_count`),
trafik (`input/output_bps/pps`, throughput %, peak), counter GPON (`gpon_counters` json),
optik (vendor/PN/SN, wavelength, `rx_power_dbm`, `tx_power_dbm`, `temperature_c`, dll +
`optical_thresholds` json), raw (`raw_status/vlan/optical`) dan timestamp refresh terpisah.
Unik per `(snmp_olt_id, interface)`. Diisi `ZteCardUplinkService`.

### `alarm_events`
`signature` (kunci dedup), `type`, `severity` (critical/major/minor/warning), `status`
(active/cleared), `scope`, lokasi (`slot/port/onu_id/serial_number`), `message`, `meta(json)`,
`first_seen_at`, `last_seen_at`, `cleared_at`, `is_demo`.
Konstanta status & severity ada di model. Diisi `AlarmEvaluator`.

### `polling_events`
`kind` (`olt_test`/`olt_poll`/`rx_poll`/`provisioning`), `success`, `message`, `duration_ms`,
`is_demo`. Helper `PollingEvent::log($oltId,$kind,$success,$message,$durationMs)`. Dipakai dashboard
untuk tren.

### `onu_rx_samples`
Time-series RX power per ONU: `snmp_olt_id`, `slot/port/onu_id`, `serial_number`, `rx_power_dbm`,
`polled_at` (tanpa `timestamps`). Composite index `onu_rx_samples_lookup_idx`. Diisi `PollOltJob`
saat RX poll sukses; dibaca via `OnuRxSample::seriesFor()` (grafik tren ONU Detail). Retensi via
command `optical:prune-rx` (lihat [08 — SNMP & Polling](08-snmp-polling.md)). Tanpa `DemoScope` —
isolasi cukup lewat OLT (route ke ONU Detail di-bind ke `SnmpOlt` yang sudah ter-scope).

### `telegram_settings` (singleton)
`enabled`, `bot_token(enc)`, `chat_id` (boleh banyak, dipisah spasi/koma), `webhook_secret(enc)`,
`commands_enabled`, `min_severity`, `notify_on_raise`, `notify_on_clear`, `last_sent_at`, `last_error`.
Helper: `instance()`, `chatIds()`, `isReady()`, `commandsReady()`, `isChatAuthorized()`,
`minSeverityRank()`. Webhook kolom ditambah migrasi `add_webhook_to_telegram_settings`.

### `general_settings` (singleton)
`app_name`, `app_version`, `logo_path`. `brandingPayload()` di-cache 1 jam (key
`general_settings.branding`), dishare ke frontend lewat `HandleInertiaRequests`. Cache di-flush
otomatis saat saved/deleted.

### `audit_logs` (immutable)
`user_id`, `user_name`, `event`, `auditable_type/id`, `description`, `properties(json)`,
`ip_address`, `user_agent`, `created_at` (tanpa `updated_at` → `UPDATED_AT = null`).
Event konstanta: created/updated/deleted/login/logout/login_failed/telnet_opened.

### `users`
`name, email, password(hashed), role(enum UserRole), last_notifications_read_at, email_verified_at`.
`role` cast ke `UserRole`. Method: `isAdmin/isOperator/isDemo`, `canManageOlt`, `canManageUsers`.

## Membuat migrasi/model baru

```bash
php artisan make:migration create_xxx_table
php artisan make:model Xxx
```
Checklist:
1. Pakai tipe yang ada padanannya di SQLite (hindari fitur pgsql-only) → test tetap hijau.
2. Tambah `use Auditable` bila perubahan baris perlu jejak audit; isi `auditLabel()`/`auditTitle()`
   dan `$auditExclude` untuk field volatil/sensitif.
3. Tambah `is_demo` + `DemoScope` bila entitas perlu dipisah demo vs nyata.
4. Cast secret dengan `encrypted` + masukkan ke `$hidden`.

## Selanjutnya

→ [06 — Routing](06-routing.md)
