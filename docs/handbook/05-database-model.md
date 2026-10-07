# 05 — Database & Model

[← Indeks](README.md) · [← 04 Instalasi & Deploy](04-instalasi-deploy.md) · [06 Routing →](06-routing.md)

DB produksi: **PostgreSQL** (`kusumavision_nms`). Test: **SQLite in-memory**. Migrasi berada di
`database/migrations/` dan **harus tetap kompatibel SQLite**.

> Diperbarui 30 Sep 2026.

## Peta tabel ↔ model

| Tabel | Model | Fungsi |
|-------|-------|--------|
| `users` | `User` | Akun + role (admin/operator/partner/demo), locale, tema |
| `snmp_olts` | `SnmpOlt` | Inventory OLT + secret + cache live-state JSON |
| `smartolt_onu_registrations` | `SmartOltOnuRegistration` | Audit/record provisioning ONU + script CLI |
| `smartolt_profiles` | `SmartOltProfile` | Katalog profil (onu_type/tcont/vlan/ip), scoped per-OLT |
| `smartolt_card_statuses` | `SmartOltCardStatus` | Status kartu/slot OLT (hasil parse CLI) |
| `smartolt_interface_statuses` | `SmartOltInterfaceStatus` | Status interface uplink/GPON + metrik optik/trafik |
| `alarm_events` | `AlarmEvent` | Alarm pending/active/cleared (termasuk `odp_down`, `port_disabled`) |
| `alarm_notification_reads` | `AlarmNotificationRead` | Status baca bel notifikasi per user per alarm (pivot) |
| `polling_events` | `PollingEvent` | Log tiap polling/test/provisioning (untuk tren dashboard) |
| `onu_rx_samples` | `OnuRxSample` | Time-series RX power per ONU, **mentah** — histogram distribusi & grafik 24 jam |
| `onu_rx_hourly` | `OnuRxHourly` | Ringkasan min/avg/max per jam — grafik 7 & 30 hari |
| `onu_map_pins` | `OnuMapPin` | Pin ONU di Peta (referensi OLT/slot/port/onu + koordinat + field pelanggan + kunci posisi `locked`) |
| `odps` | `Odp` | Pin ODP/splitter lapangan di Peta (per-OLT: nama, slot/port, koordinat, warna, foto, kunci posisi, notes) |
| `onu_odp_links` | `OnuOdpLink` | Relasi ONU↔ODP (kunci komposit ONU, unik 1 ODP/ONU) |
| `olt_config_backups` | `OltConfigBackup` | Riwayat backup running-config OLT (content terenkripsi, sha256 dedup) |
| `olt_port_labels` | `OltPortLabel` | Label port PON sisi-NMS untuk family non-ZTE (unik per OLT+slot+port; tak pernah ditulis ke OLT) |
| `copy_onu_tasks` | `CopyOnuTask` | Progres batch job salin konfigurasi ONU antar port |
| `tr069_bulk_tasks` | `Tr069BulkTask` | Progres batch job TR069 massal per-port |
| `olt_user` (pivot) | — | Kepemilikan OLT partner + saklar alarm per-partner (`alarms_enabled`) |
| `partner_telegram_bots` | `PartnerTelegramBot` | Bot Telegram privat per-partner |
| `alarm_settings` | `AlarmSetting` | Singleton **kebijakan alarm terpusat** (debounce, severity minimum, raise/clear, jenis, korelasi, grup ODP) |
| `fcm_device_tokens` | `FcmDeviceToken` | Token perangkat push FCM, terikat sesi Sanctum (`personal_access_token_id`) |
| `fcm_settings` | `FcmSetting` | Singleton saklar kanal push mobile (filter alarm ikut `alarm_settings`) |
| `personal_access_tokens` | — (Sanctum) | Token API: sesi aplikasi Android & token integrasi |
| `acs_settings` | `AcsSetting` | Singleton default ACS/TR069 (`url`, `username`, `password`) — URL **CWMP** yang ditanam ke ONU saat registrasi TR069 |
| `genieacs_credentials` | `GenieacsCredential` | Singleton alamat **NBI** GenieACS yang dibaca dasbor (`host`, `port`, `username`, `password`, status uji) — beda peran dari `acs_settings` |
| `genieacs_device_map` | `GenieacsDeviceMap` | Hasil pencocokan device GenieACS ↔ posisi ONU (+ pin manual berbasis identitas), unik per `device_id` |
| `telegram_settings` | `TelegramSetting` | Singleton koneksi bot Telegram global (filter alarm ikut `alarm_settings`) |
| `general_settings` | `GeneralSetting` | Singleton branding (nama app, versi, logo) |
| `audit_logs` | `AuditLog` | Jejak audit immutable |
| `cache`, `jobs`, `sessions`, `password_reset_tokens` | — | Tabel bawaan Laravel |

Tabel lama `zones`/`onu_zone_links` sudah di-drop (`2026_07_25_000001_drop_zones_tables`).

> Detail tabel Peta/ODP di [16 — Peta ONU & ODP](16-peta-onu.md); backup config di
> [09 — CLI & Telnet](09-cli-telnet.md); FCM/alarm di [10 — Alarm & Telegram](10-alarm-telegram.md);
> tabel GenieACS di [20 — GenieACS / TR-069](20-genieacs-tr069.md#2-pengaturan-dua-tabel-dua-peran-yang-sering-tertukar).

## Konsep lintas-model penting

### 1. Secret terenkripsi + `$hidden`
`SnmpOlt` (`snmp_read_community`, `snmp_write_community`, `cli_password`),
`SmartOltOnuRegistration` (`pppoe_password`, `acs_password`),
`TelegramSetting` & `PartnerTelegramBot` (`bot_token`, `webhook_secret`),
`AcsSetting` (`password`), `GenieacsCredential` (`password`), dan `OltConfigBackup` (`content`)
memakai cast `encrypted` (+ `$hidden` untuk secret yang bisa ikut terserialisasi).
Enkripsi pakai `APP_KEY` → **jangan ganti APP_KEY** tanpa migrasi, atau secret jadi tak terbaca.

Saat edit OLT, field secret kosong **tidak menimpa** nilai lama —
`SmartOltController::withoutEmptySecrets()`.

### 1b. Tabel yang hanya menyimpan **referensi** ONU
`onu_map_pins`, `onu_odp_links`, dan `genieacs_device_map` sama-sama menunjuk ONU lewat
`(snmp_olt_id, slot, port, onu_id)` — bukan lewat foreign key, karena **tidak ada tabel ONU**.
Konsekuensinya: baris bisa menggantung saat ONU dipindah/dicabut, dan tiap tabel menangani itu
sendiri (mis. Bind ONU memperbarui `serial_number` di `onu_odp_links`/`onu_map_pins` pada posisi
yang sama — `SmartOltController`). `genieacs_device_map` menanganinya dengan menyimpan
**identitas** ONU pada pin manual (`manual_ref_type`/`manual_ref`) lalu menurunkan posisinya ulang
tiap sinkronisasi; pin yang identitasnya hilang ditandai `manual_stale`. Lihat
[20](20-genieacs-tr069.md#5-penyematan-manual--yang-disimpan-identitas-bukan-posisi).
`olt_port_labels` memakai pola yang sama untuk port (`snmp_olt_id, slot, port`).

### 2. Cache live-state JSON: `snmp_olts.last_test_result`
Kolom `json` (cast `array`) menyimpan snapshot terkini OLT + ONU per port (`port_onus`), daftar
unconfigured (`unconfigured_onus` + `unconfigured_seen` untuk "Pertama Terlihat"), dan untuk
non-ZTE faceplate (`panel`). Tidak ada tabel ONU. Lihat struktur di [02 — Arsitektur](02-arsitektur.md#cache-live-state-snmp_oltslast_test_result).

### 3. Audit otomatis — trait `Auditable`
Model yang `use Auditable` otomatis menulis `audit_logs` saat created/updated/deleted.
Lihat [11 — Keamanan, RBAC & Audit](11-keamanan-rbac-audit.md). Field volatil dikecualikan via
`$auditExclude` (mis. `last_test_result`, `last_polled_at`).

### 4. Demo isolation — `DemoScope` global scope
Model dengan `is_demo` (`SnmpOlt`, `SmartOltOnuRegistration`, `AlarmEvent`, `PollingEvent`)
memakai `DemoScope`: user role `demo` hanya melihat baris `is_demo = true`; konteks lain
(termasuk console/queue tanpa auth) hanya `is_demo = false`. Lihat [11](11-keamanan-rbac-audit.md).

### 5. Cakupan OLT — `PartnerOltScope` global scope
`SnmpOlt` dan tabel turunan OLT (`AlarmEvent`, `PollingEvent`, `SmartOltOnuRegistration`,
`OnuMapPin`, `Odp`, `OnuOdpLink`, `OltPortLabel`) memasang `PartnerOltScope`: partner (selalu)
serta operator yang punya assignment (tanpa assignment = semua OLT) hanya melihat OLT miliknya
(`snmp_olts.owner_user_id`) / yang di-assign (pivot `olt_user`) — penentunya `User::isOltScoped()`.
Route-model binding ikut ter-scope → OLT di luar cakupan = 404. **Di console/queue scope ini tidak
berlaku** (tanpa user) — command yang menyaring per pemilik harus melakukannya eksplisit.

---

## Detail tabel

### `snmp_olts` — inventory OLT
```
id  owner_user_id(null → OLT global; terisi → OLT privat partner)
name(100)  vendor(100,null)  ip  snmp_port(=161)          unik (ip, snmp_port)
snmp_read_community(text, enc)  snmp_write_community(text,null, enc)
snmp_version(enum v1|v2c|v3 =v2c)
cli_transport(enum telnet|ssh, null)  cli_port(null)  cli_username(100,null)  cli_password(text,null, enc)
polling_enabled(bool)  alarms_enabled(bool=true)  config_backup_enabled(bool=false)
poll_interval_minutes  rx_poll_interval_minutes
last_test_result(json)  last_tested_at  last_polled_at  last_rx_polled_at
is_demo(bool)  timestamps
```
Method penting di `SnmpOlt`:
- `getHostAddress()` → `ip` atau `ip:port`.
- `isPollDue()` / `isRxPollDue()` → cek interval vs `last_*_polled_at`.
- `pollIntervalMinutes()` / `rxPollIntervalMinutes()` → default 5 menit, minimal 1.
- `defaultCliPort()` → 22 (ssh) / 23 (telnet).
- Relasi: `cardStatuses()`, `interfaceStatuses()`, `configBackups()` (hasMany), `partners()`
  (pivot `olt_user`, + `alarms_enabled` per partner), `owner()`; `isPrivatelyOwned()`.

> Catatan: kolom `polling_enabled`, `*_interval`, `last_polled_at`, `last_rx_polled_at`, `is_demo`
> ditambahkan oleh migrasi `add_polling_fields...`, `add_poll_intervals...`, `add_is_demo_flags`;
> `alarms_enabled`, `owner_user_id`, `config_backup_enabled` oleh migrasi Juli 2026. Unik `ip` diganti
> unik `(ip, snmp_port)` (`make_snmp_olts_ip_unique_per_snmp_port`) supaya beberapa OLT di balik NAT
> satu IP bisa didaftarkan.

### `smartolt_onu_registrations` — record provisioning
Identitas ONU (`serial_number`, `slot`, `port`, `onu_id`, `pon_port`, `oid_index`),
layanan (`onu_type`, `tcont_profile`, `vlan`, `vlan_profile`, `service_name`),
WAN (`wan_mode` pppoe|dhcp|static|bridge|tr069, `pppoe_username/password`, `ip_profile`, `static_ip/netmask`),
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
`snmp_olt_id`, `signature` (kunci dedup), `type` (`olt_unreachable`, `port_down`, `port_disabled`,
`odp_down`, `los`, `dying_gasp`, `onu_offline`, `high_rx_attenuation`), `severity`
(critical/major/minor/warning), `status` (**pending**/active/cleared), `scope` (olt/port/odp/onu),
lokasi (`slot/port/onu_id/serial_number`), `message` (teks Indonesia, disimpan sebagai data),
`meta(json)` (mis. `affected_onus`, `odp_id`, `notified`), `first_seen_at`, `last_seen_at`,
`cleared_at`, `is_demo`. Konstanta status, type & severity ada di model. Diisi `AlarmEvaluator`
(dan langsung oleh aksi matikan/nyalakan port untuk `port_disabled`). Baris `pending` = belum
terkonfirmasi debounce 2 poll, tidak tampil di UI/API.

### `alarm_notification_reads`
Pivot `user_id` ↔ `alarm_event_id` + `read_at`, unik per pasangan. Status baca per-notifikasi di
bel; `users.last_notifications_read_at` tetap dipakai untuk "tandai semua dibaca".

### `alarm_settings` (singleton)
`confirm_before_notify` (debounce 2 poll), `min_severity`, `notify_on_raise`, `notify_on_clear`,
`notify_types` (json, null = semua jenis), `suppress_child_alarms`, `group_odp_alarms`. Satu-satunya
sumber kebijakan alarm (`AlarmSetting::policy()`); `TelegramSetting` & `FcmSetting` mendelegasikan
filter ke sini. **Jenis alarm baru** yang harus ikut terkirim butuh migrasi data yang menambahkannya
ke `notify_types` bila kolom itu berisi daftar eksplisit (contoh:
`2026_09_30_000001_add_port_disabled_to_alarm_notify_types`).

### `polling_events`
`kind` (`olt_test`/`olt_poll`/`rx_poll`/`provisioning`), `success`, `message`, `duration_ms`,
`is_demo`. Helper `PollingEvent::log($oltId,$kind,$success,$message,$durationMs)`. Dipakai dashboard
untuk tren.

### `onu_rx_samples`
Time-series RX power per ONU: `snmp_olt_id`, `slot/port/onu_id`, `serial_number`, `rx_power_dbm`,
`polled_at` (tanpa `timestamps`). Composite index `onu_rx_samples_lookup_idx`. Diisi `PollOltJob`
saat RX poll sukses; dibaca via `OnuRxSample::seriesFor()` (grafik tren ONU Detail). Retensi
**3 hari** via command `optical:prune-rx` (lihat [08 — SNMP & Polling](08-snmp-polling.md)).
Tanpa `DemoScope` — isolasi cukup lewat OLT (route ke ONU Detail di-bind ke `SnmpOlt` yang
sudah ter-scope).

Tabel ini **hanya melayani grafik 24 jam**. Rentang 7 & 30 hari dilayani `onu_rx_hourly`.

### `onu_rx_hourly`
Ringkasan per jam: satu baris per ONU per jam berisi `rx_min_dbm`, `rx_avg_dbm`,
`rx_max_dbm`, dan `sample_count`. Kunci uniknya (ONU + jam) membuat pengisiannya idempoten
lewat `upsert`. Retensi **45 hari**.

**Min & max ikut disimpan, bukan avg saja**, dan itu bukan kelebihan data: lonjakan redaman
sesaat justru gejala yang dicari teknisi, dan rata-rata per jam akan menelannya.

Kenapa tabel ini ada: dengan ribuan ONU × ~213 sampel/hari × retensi 30 hari, `onu_rx_samples`
tumbuh ke **puluhan juta baris dan belasan GB** — sebagian besar ukuran database NMS, dan dump
cadangan harian ikut membengkak. Padahal grafik 7 & 30 hari tidak pernah butuh kepadatan
sepadat itu.

Perhatikan keseimbangannya: dengan 5.000 ONU, **tiap 30 hari retensi di tabel per jam
berharga ~3,6 juta baris**. Retensi panjang di sini justru bisa menghasilkan tabel yang
lebih besar daripada tabel mentahnya.

### `telegram_settings` (singleton)
`enabled`, `bot_token(enc)`, `chat_id` (boleh banyak, dipisah spasi/koma), `webhook_secret(enc)`,
`commands_enabled`, `last_sent_at`, `last_error`. Kolom filter lama (`min_severity`,
`notify_on_raise`, `notify_on_clear`, `notify_types`) dipertahankan demi rollback tapi **tak dipakai**
— helper `minSeverityRank()`/`notifyTypes()`/… mendelegasikan ke `AlarmSetting::policy()`.
Helper lain: `instance()`, `chatIds()`, `isReady()`, `commandsReady()`, `isChatAuthorized()`.
Bot per partner punya tabel sendiri `partner_telegram_bots` (dengan filter sendiri).

### `fcm_device_tokens`
`user_id`, `personal_access_token_id` (FK ke `personal_access_tokens`, **cascade**), `token` (unik),
`device_name`, `platform`, `last_seen_at`. Token push terkait **sesi login aplikasi** yang
mendaftarkannya: logout / token dicabut / `sanctum:prune-expired` → baris ikut terhapus. Pengiriman
selalu lewat scope `FcmDeviceToken::deliverable()` (sesi masih sah; baris lama tanpa kaitan sesi —
`personal_access_token_id` NULL — tetap dikirimi).

### `odps`
`snmp_olt_id`, `name`, `slot`/`port` (nullable), `latitude`/`longitude`, `locked` (bool, default
true), `color` (hex nullable, null = amber default; diwarisi per PON port), `photo_path` (disk privat),
`notes`, `created_by`. Relasi ONU lewat `onu_odp_links` (unik 1 ODP per ONU). **Hapus ODP permanen
dan tidak tercatat di `audit_logs`.** Detail di [16](16-peta-onu.md).

### `olt_port_labels`
`snmp_olt_id`, `slot`, `port`, `label` (maks 64), unik `(snmp_olt_id, slot, port)`. Label port PON
sisi-NMS untuk C-Data/HiOSO (capability `supports_port_label`); tidak pernah ditulis ke OLT dan
sengaja di luar `last_test_result` supaya selamat dari scan.

### `genieacs_credentials` (singleton)
`host`, `port` (=7557), `username` (null = NBI tanpa autentikasi), `password` (enc, `$hidden`),
`role` (dari `/users` NBI saat uji), `is_connected`, `last_test_at`, `last_test_error`. Helper:
`instance()`, `isConfigured()`, `client()` (klien NBI, null bila host kosong). `Auditable`.
Diisi admin di Pengaturan → tab ACS / TR069; **berbeda** dari `acs_settings` (URL CWMP yang ditanam
ke ONU). Detail di [20](20-genieacs-tr069.md).

### `genieacs_device_map`
`device_id` (unik), `serial_number`, `pon_mac` (12 hex huruf kecil), `manufacturer`,
`product_class`, `pppoe_username`, `tr069_ip`, posisi `snmp_olt_id`/`slot`/`port`/`onu_id` (null =
belum tercocok), `match_method` (`serial`|`mac`|`manual`), `matched_at`, pin manual
`manual_ref_type`/`manual_ref`/`manual_stale`/`manual_by`/`manual_at`, `last_inform_at` (dikonversi
ke zona waktu aplikasi). Ditulis `genieacs:match-onu` (upsert per `device_id`; device yang hilang
dari ACS dihapus) dan penyematan manual. **Tanpa** `PartnerOltScope` — akses dijaga
`User::canManageAcs()`/`canUseAcsCatalogOn()` di controller.

### `general_settings` (singleton)
`app_name`, `app_version`, `logo_path`. `brandingPayload()` di-cache 1 jam (key
`general_settings.branding`), dishare ke frontend lewat `HandleInertiaRequests`. Cache di-flush
otomatis saat saved/deleted.

### `audit_logs` (immutable)
`user_id`, `user_name`, `event`, `auditable_type/id`, `description`, `properties(json)`,
`ip_address`, `user_agent`, `created_at` (tanpa `updated_at` → `UPDATED_AT = null`).
Event konstanta: created/updated/deleted/login/logout/login_failed/telnet_opened.

### `users`
`name, email, email_verified_at, password(hashed), remember_token, role(enum UserRole:
admin|operator|partner|demo, default operator), locale (id|en|null), theme (dark|light|system|null),
last_notifications_read_at`. `role` cast ke `UserRole`. `theme` null = belum memilih → cookie
`kv_theme` → gelap (lihat `App\Support\Theme`). Password diperiksa lokal (login Breeze & `POST
/api/v1/auth/login`); akun dibuat admin lewat menu Users atau `php artisan user:create`.

Method izin: `isAdmin/isOperator/isPartner/isDemo`, `isOltScoped`, `canManageOlt`,
`canManageOltInventory`, `canAddOlt`, `ownsOlt`, `isCentralStaff` (admin/operator),
`canEditOltConnection`, `canAccessOltSecrets` (telnet & isi backup config),
`canSetPonPortAdminState`, `canManageUsers` (admin saja), `canManageAcs` (= `isCentralStaff`) &
`canUseAcsCatalogOn` (katalog GenieACS). Detail di [11](11-keamanan-rbac-audit.md).

## Membuat migrasi/model baru

```bash
php artisan make:migration create_xxx_table
php artisan make:model Xxx
```
Checklist:
1. Pakai tipe yang ada padanannya di SQLite (hindari fitur pgsql-only) → test tetap hijau.
   **Di produksi jalankan migrasi SEBELUM kode yang membaca kolom baru tayang** — lihat
   [14](14-panduan-tambah-fitur.md).
2. Tambah `use Auditable` bila perubahan baris perlu jejak audit; isi `auditLabel()`/`auditTitle()`
   dan `$auditExclude` untuk field volatil/sensitif.
3. Tambah `is_demo` + `DemoScope` bila entitas perlu dipisah demo vs nyata.
4. Cast secret dengan `encrypted` + masukkan ke `$hidden`.
5. Entitas milik OLT → pasang `PartnerOltScope` supaya partner tak melihat OLT orang lain.

## Selanjutnya

→ [06 — Routing](06-routing.md)
