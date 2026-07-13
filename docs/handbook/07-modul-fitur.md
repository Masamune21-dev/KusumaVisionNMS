# 07 — Modul & Fitur

[← Indeks](README.md) · [← 06 Routing](06-routing.md) · [08 SNMP & Polling →](08-snmp-polling.md)

Penjelasan per modul: apa fungsinya, controller/service/page yang terlibat, dan alurnya. Untuk
detail SNMP/CLI/alarm lihat bab khusus ([08](08-snmp-polling.md), [09](09-cli-telnet.md),
[10](10-alarm-telegram.md)).

## Navigasi (sidebar `AuthenticatedLayout.vue`)
Dashboard · SmartOLT · ONU Monitoring · Unconfigured · Alarms · Report — lalu (admin saja):
Users · Audit Logs · Pengaturan. Mapping route ada di [06 — Routing](06-routing.md).

---

## 1. Dashboard
- **Controller**: `DashboardController@index` → `DashboardStatsService`.
- **Page**: `Pages/Dashboard.vue` + komponen `Components/Dashboard/*`.
- **Isi props**: `cards` (statCards), `polling_trend` (range 24h/7d/30d), `olt_inventory`
  (per model), `olts` (status), `recent_alarms`, `provisioning` (ringkasan).
- **Sumber data**: `polling_events` (tren), `last_test_result` (status & ONU count), `alarm_events`,
  `smartolt_onu_registrations`.
- Komponen: `StatCard`, `PollingTrendCard`, `OltInventoryList`, `OnuStatusDonut`,
  `RecentAlarmsTable`, `ProvisioningTimeline`, `RemoteActionsGrid`, `OnuQuickActionModal`, `HeroBanner`.

## 2. SmartOLT — inventory & CRUD
- **Controller**: `SmartOltController` (index/create/store/edit/update/destroy).
- **Pages**: `SmartOlt/Index.vue`, `Create.vue`, `Edit.vue`, partial `OltForm.vue`.
- **Validasi**: `SmartOltController::validated()`. Saat update, secret kosong dipertahankan via
  `withoutEmptySecrets()`.
- **Test koneksi** (`test`) → `OltSnmpClient::test()` → simpan ringkas ke `last_test_result`,
  catat `PollingEvent::KIND_OLT_TEST`.
- **Refresh** (`refresh`) → `OltSnmpClient::snapshot()` + `registeredOnus()` → tulis `port_onus`.
- **Save Config** (`saveConfig`, tombol ikon `Save` di baris aksi — ZTE & non-ZTE) → simpan
  running-config ke **memori OLT** via CLI (persist; beda dari _backup ke DB_). ZTE `write`,
  C-Data `enable→config→save`, HiOSO `enable→write`. Gated capability `supports_config_save`.
  Lihat [09 CLI & Telnet §C-bis](09-cli-telnet.md).
  > Tombol lama **"Refresh ONU (scan penuh)"** di daftar OLT C-Data/HiOSO sudah **dihapus** —
  > scan penuh manual kini lewat tombol "Scan ONU" di halaman Detail OLT.

## 3. Detail OLT / Hardware (card & uplink)
- **Controller**: `detail`, `refreshHardware` → `ZteCardUplinkService`.
- **Page**: `SmartOlt/Detail.vue`.
- Menampilkan kartu/slot (`smartolt_card_statuses`) + interface uplink (`smartolt_interface_statuses`)
  termasuk metrik optik (RX/TX dBm, suhu) hasil parse CLI `show card` / `show interface`.

## 4. GPON Ports
- **Controller**: `gponPorts` · **Page**: `SmartOlt/GponPorts.vue`.
- Daftar port PON dari snapshot (`ports[]` di `last_test_result`): status oper/admin, jumlah ONU.

### 4b. TR069 Massal (per-OLT)
- **Tombol** "TR069 Massal" di header GPON Ports (gate `supports_cli_onu_configure`, ZTE saja) → `Components/SmartOlt/Tr069BulkModal.vue`.
- **Controller**: `tr069Bulk` (POST, antrikan job), `tr069BulkStatus` (GET, poll) · **Service**: `ZteTr069BulkService` · **Job**: `Tr069BulkConfigJob` + tabel `tr069_bulk_tasks`.
- Alur 2 fase via flag `execute`:
  1. **Dry-run** (`execute=false`): pindai running-config tiap ONU per port (1 sesi telnet/port via `ZteOnuRunningConfigService::fetchMany`), laporkan mana yang **akan diaktifkan** vs **sudah aktif (skip)** vs **gagal baca** — tanpa menulis apa pun ke OLT.
  2. **Eksekusi** (`execute=true`): pindai ulang lalu tulis `tr069-mgmt 1 state unlock` + `tr069-mgmt 1 acs … validate basic username … password …` ke ONU yang belum aktif (1 sesi tulis/port; satu blok `pon-onu-mng` per ONU).
- **Skip rule**: ONU dilewati bila TR069 sudah `unlock` **dan** ACS url + username sudah mengarah ke target. Password tidak dipakai sebagai syarat skip (sebagian firmware memasking-nya di `show running-config`), tapi acs line yang ditulis selalu menyertakan password.
- **Default ACS**: `config('services.acs')` → di-set lewat `ACS_URL`/`ACS_USERNAME`/`ACS_PASSWORD` di `.env` (atau tab Settings). Tidak ada nilai bawaan yang di-hardcode di repo — kredensial asli jangan ditulis di kode/dokumen.
- Progress di-poll mirip Copy ONU; total = jumlah ONU di cache `port_onus` OLT itu (Refresh SNMP dulu agar lengkap).

## 5. Detail Port (per-interface)
- Navigasi: **klik port di visualisasi chassis** (komponen `Components/SmartOlt/OltChassis.vue` di halaman Detail OLT). Port GPON & uplink (XGEI/GEI) bisa diklik; kartu kontrol/power tidak.
- **Controller**: `portDetail` (render), `refreshPortDetail` (refresh 1 interface via CLI — GPON `refreshGponInterface`, uplink `refreshUplinkInterface`), `portTraffic` (JSON polling trafik uplink), `storePortVlan` (tambah/tag VLAN via CLI).
- **Page**: `SmartOlt/PortDetail.vue` — status link, trafik (chart live untuk uplink), optical/SFP (redaman RX/TX + threshold), VLAN tagged (uplink), ringkasan ONU + tombol ke daftar ONU (GPON).
- `ZteCardUplinkService` menyediakan status interface, mapping VLAN, info optik, refresh per-interface, dan tambah VLAN.

## 6. ONU per Port
- **Controller**: `portOnus`, `refreshPortOnus`, dan aksi per-ONU: `rebootOnu`, `setOnuState`
  (enable/disable), `updateOnuInfo` (nama/deskripsi), `onuDetail`, `configureOnu*`.
- **Pages**: `SmartOlt/PortOnus.vue`, `OnuDetail.vue`, `ConfigureOnu.vue`.
- **Aksi ONU**:
  - Reboot → `ZteRemoteOnuService::reboot()` (CLI).
  - Enable/disable → `setActiveState()` (SNMP set).
  - Set info → `setInfo()` (SNMP set nama/deskripsi).
  - Detail → `ZteOnuDetailService::fetch()` (parse CLI).
  - **Reconfigure** → baca running-config (`ZteOnuRunningConfigService`) sebagai baseline →
    `configureOnuPreview` menghasilkan diff script (`ZteOnuReconfigureScriptBuilder`) →
    `configureOnuApply` mengeksekusi via `ZteCliProvisioningExecutor`.
- Semua aksi dijaga `assertCapability()` sesuai vendor (lihat `SmartOltSupport`).

## 7. ONU Monitoring (lintas OLT)
- **Controller**: `onuMonitor`, `refreshOnuMonitor` · **Page**: `SmartOlt/OnuMonitor.vue`.
- Agregasi cache `port_onus` dari **semua** OLT (status online/offline, RX power, serial, nama).
- `refreshOnuMonitor` melakukan satu walk SNMP penuh OLT terkait lalu menulis cache per port.
- Dipakai juga oleh global search (⌘K) lewat `DashboardSearchController`.

## 8. Unconfigured ONU
- **Per-OLT**: `unconfigured`, `refreshUnconfigured` → `OltSnmpClient::unconfiguredOnus()`.
  Page `SmartOlt/Unconfigured.vue`.
- **Global**: `unconfiguredGlobal` → gabungan semua OLT. Page `SmartOlt/UnconfiguredGlobal.vue`.
- ONU yang muncul di sini adalah kandidat untuk di-register (provisioning).

## 9. Provisioning ONU
- **Controller**: `registerOnuForm`, `storeOnu`, `registrations`, `executeRegistration`.
- **Pages**: `SmartOlt/RegisterOnu.vue`, `Registrations.vue`.
- **Alur**:
  1. Form register (serial, slot/port/onu_id, profil, WAN PPPoE/DHCP/static, TR069, Remote ONT).
  2. `storeOnu` → `ZteProvisioningScriptBuilder::build()` membuat **script CLI**, simpan baris
     `smartolt_onu_registrations` (status `generated`). **Belum dieksekusi.**
  3. `executeRegistration` → eksekusi script via `ZteCliProvisioningExecutor` (telnet), simpan
     output/error + `executed_at/by`, catat `PollingEvent::KIND_PROVISIONING`.
- Profil di-hydrate dari katalog per-OLT (`hydrateProvisioningProfiles`) dengan fallback global.
- Detail builder & sintaks di [09 — CLI & Telnet](09-cli-telnet.md).

## 10. Profil layanan
- **Controller**: `SmartOltProfileController` (index/store/syncFromOlt/update/destroy).
- **Service**: `ZteProfileCatalogService` — `syncFromOlt()` menjalankan `show ...` di OLT dan
  parse onu_type/tcont/vlan/ip.
- **Page**: `SmartOlt/Profiles.vue`.
- Profil scoped per-OLT (`snmp_olt_id`), profil global = `snmp_olt_id = null` (fallback).

## 11. Alarms
- **Controller**: `AlarmController@index` · **Page**: `SmartOlt/Alarms.vue`.
- Data dari `alarm_events`. Alarm di-generate `AlarmEvaluator` saat polling. Detail
  [10 — Alarm & Telegram](10-alarm-telegram.md).
- Notifikasi (bell) dishare global lewat `HandleInertiaRequests::notificationsPayload()`.
  `NotificationsController@markAllRead` set `last_notifications_read_at`.

## 12. Reports
- **Controller**: `ReportController` (index + exportCsv + exportPdf) · **Service**: `ReportService`.
- **Page**: `Pages/Reports/Index.vue`.
- Tipe laporan: `onuInventory`, `rxPower`, `oltStatus`, `alarmHistory`, `provisioning` — dengan
  filter (range waktu, OLT, dll). Export CSV (stream) & PDF (dompdf).

## 13. Users & RBAC
- **Controller**: `UserController` (admin only) · **Page**: `Pages/Users/Index.vue`.
- CRUD user + set role (admin/operator/demo). Lihat [11](11-keamanan-rbac-audit.md).

## 14. Audit Logs
- **Controller**: `AuditLogController@index` (admin) · **Page**: `Pages/AuditLogs/Index.vue`.
- Tampil dari `audit_logs` (immutable). Diisi otomatis trait `Auditable` + listener login.

## 15. Pengaturan (Settings)
- **Controller**: `SettingsController` (admin) · **Page**: `Pages/Settings/Index.vue`.
- **Umum**: nama app, versi, upload logo → `general_settings` (branding di-cache + dishare global).
- **Telegram**: token/chat/min severity/notify flags → `telegram_settings`; tombol test kirim,
  register/delete webhook. Detail [10](10-alarm-telegram.md).

## 16. Browser Telnet
- **Controller**: `TelnetSessionController@token` · **Page/Component**: `Components/Shell/TelnetWindow.vue`
  (jendela draggable/min/max, xterm.js).
- Terbit tiket terenkripsi singkat → browser buka WebSocket ke daemon `telnet:proxy`. Detail
  [09 — CLI & Telnet](09-cli-telnet.md).

## 17. Profil akun & Auth
- `ProfileController` (edit/update/destroy akun sendiri), pages `Profile/*`.
- Auth Breeze (login, reset password, verifikasi email), pages `Auth/*`. Registrasi publik off.

## Selanjutnya

→ [08 — SNMP & Polling](08-snmp-polling.md)
