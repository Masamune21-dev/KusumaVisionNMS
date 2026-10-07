# 07 — Modul & Fitur

[← Indeks](README.md) · [← 06 Routing](06-routing.md) · [08 SNMP & Polling →](08-snmp-polling.md)

Penjelasan per modul: apa fungsinya, controller/service/page yang terlibat, dan alurnya. Untuk
detail SNMP/CLI/alarm lihat bab khusus ([08](08-snmp-polling.md), [09](09-cli-telnet.md),
[10](10-alarm-telegram.md)).

## Navigasi (sidebar `AuthenticatedLayout.vue`)
Menu dikelompokkan (`navGroups`, label dari `nav.group_*`); kelompok yang semua itemnya tersembunyi
ikut hilang:

| Kelompok | Item | Siapa |
|----------|------|-------|
| Ringkasan | Dashboard | semua |
| Jaringan OLT | SmartOLT · Unconfigured · Monitoring ONU | semua |
| Lapangan | Peta ONU · ODP | semua |
| Pantauan | Alarm · Laporan | semua |
| Administrasi | Pengguna · Log Audit · Pengaturan | admin |
| Administrasi — partner | Bot Telegram Saya (`partner.telegram.*`) | partner |
| Bantuan | Panduan (`/panduan`) | semua |

Header desktop juga memuat pencarian ⌘K (`GlobalSearch`), `LanguageSwitcher`, `NotificationBell`,
dan `UserMenu` (tema). Mapping route ada di [06 — Routing](06-routing.md).

---

## 1. Dashboard
- **Controller**: `DashboardController@index` → `DashboardStatsService`.
- **Page**: `Pages/Dashboard.vue` + komponen `Components/Dashboard/*`.
- **Isi props**: `cards` (statCards), `polling_trend` (range 24h/7d/30d), `olt_inventory`
  (per model), `olts` (status), `recent_alarms`, `provisioning` (ringkasan).
- `DashboardStatsService::pollingTrend()` hanya memuat bucket yang **sudah lengkap** (24 × 1 jam /
  28 × 6 jam / 30 × 1 hari; jendela berakhir di awal bucket berjalan, sejak 4 Okt 2026) supaya titik
  paling kanan tak tampak anjlok; totalnya ikut jendela yang sama, jadi bisa tertinggal ≤ 1 bucket.
  `PollingTrendCard` menghitung label sumbu X mundur dari bucket terbaru.
- **Sumber data**: `polling_events` (tren), `last_test_result` (status & ONU count), `alarm_events`,
  `smartolt_onu_registrations`.
- Komponen: `StatCard`, `PollingTrendCard`, `OltInventoryList`, `OnuStatusDonut`,
  `RecentAlarmsTable`, `ProvisioningTimeline`, `RemoteActionsGrid`, `OnuQuickActionModal`, `HeroBanner`.
- **Kartu "Remote ONU actions"** (`RemoteActionsGrid` → `OnuQuickActionModal`) menampilkan enam
  tombol, tapi yang **benar-benar jalan hanya Reboot, Enable, Disable** (dikirim ke
  `{prefix}.onu.reboot` / `{prefix}.onu.state` sesuai family ONU terpilih). **Reset, Upgrade, dan Log
  belum diimplementasikan** — `available: false` di `actionMeta`, modal hanya menampilkan
  `dashboard.modal.not_implemented`. Jangan dokumentasikan ketiganya sebagai fitur.

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

## 4. Halaman Port PON (ketiga family)
- **Page bersama**: `SmartOlt/PonPorts.vue` (prop `route_prefix` = `smartolt`/`cdata-olt`/`hioso-olt`)
  — ZTE lewat `SmartOltController::gponPorts` (rute `smartolt.gpon-ports`), C-Data/HiOSO lewat
  `ponPorts` masing-masing (`cdata-olt.pon-ports`, `hioso-olt.pon-ports`; kartu dirakit
  `App\Support\PonPortCards`). Tidak ada lagi `GponPorts.vue`.
- Kartu per port dari snapshot (`ports[]` di `last_test_result`): status oper/admin, ONU online/total,
  **deskripsi port** — ZTE dari CLI `show interface` → `smartolt_interface_statuses` (C600 fallback
  `if_descr` SNMP); C-Data/HiOSO = label sisi-NMS (§4c). Klik kartu → ONU per port (§6).
- **Edit deskripsi port (ZTE)** ada di halaman **Detail Port** (§5), bukan di halaman ini —
  `storePortDescription` (POST `smartolt.port.description`) menulis CLI `interface gpon…` +
  `description …` **lalu `write` otomatis**; berlaku C300/C320/C600 (penamaan interface via
  `gponOltInterface()`), gate `supports_port_description_write` (UI juga butuh `manage_olt` + telnet).
  Deskripsi ikut tampil di aplikasi Android (field `description` payload `ports` API v1).

### 4c. Label port PON sisi-NMS (family non-ZTE)
- **Kenapa ada**: hanya ZTE yang punya perintah deskripsi port di perangkat. C-Data dan HiOSO
  tak punya padanan yang terverifikasi live (probe `ifAlias` di perangkatnya: kosong,
  atau — pada C-Data GPON V3 — cuma cerminan nama port bawaan agent). Jadi labelnya milik NMS.
- **Data**: tabel `olt_port_labels` (`snmp_olt_id` + `slot` + `port` unik, `label` maks 64 char),
  model `OltPortLabel` (pakai `PartnerOltScope`), service `App\Services\OltPortLabelService`
  (`forOlt()` → peta `{slot}_{port}` ⇒ label; `set()` menyimpan/menghapus + sanitasi teks).
  Sengaja **bukan** di `last_test_result` supaya tak ikut tertimpa tiap scan/poll.
- **Route**: `POST olts/{olt}/port-label` (`olt.port-label.store`, `OltPortLabelController`) — satu
  endpoint dipakai ketiga family. Gate: `canManageOlt()` + capability `supports_port_label`
  (menyala di C-Data EPON/GPON dan HiOSO; **tidak ada** di ZTE → 403, ZTE tetap menulis
  deskripsinya ke perangkat).
- **UI**: label **diedit** lewat komponen bersama `Components/OltPortLabel.vue` di header halaman
  ONU per port (`Pages/{CDataOlt,Hioso}/PortOnus.vue`), dan **ditampilkan** (baca-saja) sebagai
  deskripsi kartu di halaman Port PON bersama (`PonPortCards` mengisi `description` dari label).
  Controller `detail()` masih mengirim prop `port_labels` ke `Pages/{CDataOlt,Hioso}/Detail.vue`,
  tapi halaman Detail kini tak merendernya (tabel port pindah ke halaman Port PON). Tidak ada
  telnet/SNMP yang tersentuh — label murni catatan operator.
- **Aplikasi Android**: `GET /api/v1/olts/{olt}` mengirim label ini lewat field `description` per
  port yang sudah ada (untuk non-ZTE field itu memang selalu `null` sebelumnya), jadi label tampil
  di APK terpasang tanpa rilis baru — model & layar Flutter sudah merender `description`.

### 4b. TR069 Massal (per port PON)
- **Tombol** "TR069 Massal" di **header halaman ONU per port ZTE** (`SmartOlt/PortOnus.vue`) — bukan
  di halaman Port PON. Tampil bila `supports_onu_config_write` (penulis config gaya C300, OFF di C600)
  → `Components/SmartOlt/Tr069BulkModal.vue`, yang menampilkan target ACS (url + username).
- **Cakupan satu PON port**: rute `POST smartolt/{olt}/ports/{slot}/{port}/tr069-bulk`
  (`smartolt.tr069-bulk`); baris `tr069_bulk_tasks` menyimpan `slot`/`port`.
- **Controller**: `tr069Bulk` (POST, antrikan job), `tr069BulkStatus` (GET, poll) · **Service**:
  `ZteTr069BulkService` · **Job**: `Tr069BulkConfigJob`.
- Alur 2 fase via flag `execute`:
  1. **Dry-run** (`execute=false`): pindai running-config tiap ONU per port (1 sesi telnet/port via `ZteOnuRunningConfigService::fetchMany`), laporkan mana yang **akan diaktifkan** vs **sudah aktif (skip)** vs **gagal baca** — tanpa menulis apa pun ke OLT.
  2. **Eksekusi** (`execute=true`): pindai ulang lalu tulis `tr069-mgmt 1 state unlock` + `tr069-mgmt 1 acs … validate basic username … password …` ke ONU yang belum aktif (1 sesi tulis/port; satu blok `pon-onu-mng` per ONU).
- **Skip rule**: ONU dilewati bila TR069 sudah `unlock` **dan** ACS url + username sudah mengarah ke target. Password tidak dipakai sebagai syarat skip (sebagian firmware memasking-nya di `show running-config`), tapi acs line yang ditulis selalu menyertakan password.
- **Target ACS**: `AcsSetting::resolved()` — baris `acs_settings` (Pengaturan → tab ACS), jatuh
  balik per kolom ke `config('services.acs')` (`ACS_URL`/`ACS_USERNAME`/`ACS_PASSWORD` di `.env`).
  Tidak ada nilai bawaan yang di-hardcode di repo — kredensial asli jangan ditulis di kode/dokumen.
  Server tidak menolak target kosong, jadi isi tab ACS (atau `.env`) sebelum eksekusi.
- Progress di-poll seperti Salin ONU (§6b); total = jumlah ONU port itu di cache `port_onus`
  (`cachedOnuCount`, jadi Refresh SNMP dulu agar lengkap).

## 5. Detail Port (per-interface)
- Navigasi: **klik port di visualisasi chassis** (komponen `Components/SmartOlt/OltChassis.vue` di halaman Detail OLT). Port GPON & uplink (XGEI/GEI) bisa diklik; kartu kontrol/power tidak.
- **Controller**: `portDetail` (render), `refreshPortDetail` (refresh 1 interface via CLI — GPON `refreshGponInterface`, uplink `refreshUplinkInterface`), `portTraffic` (JSON polling trafik uplink), `storePortVlan` (tambah/tag VLAN via CLI), `storePortDescription` (§4), `storePortAdminState` (§5a).
- **Page**: `SmartOlt/PortDetail.vue` — status link, trafik (chart live untuk uplink), optical/SFP (redaman RX/TX + threshold), VLAN tagged (uplink), ringkasan ONU + tombol ke daftar ONU (GPON), edit deskripsi (GPON).
- `ZteCardUplinkService` menyediakan status interface, mapping VLAN, info optik, refresh per-interface, tambah VLAN, deskripsi, dan admin-state port PON.
- **Tag VLAN uplink** (`smartolt.port.vlan` → `addAndTagVlan()`): `vlan {id}` + `switchport vlan {id} tag`
  **lalu `write` otomatis**. Dijaga `canWriteOltUplinkConfig()` (sejak 1 Okt 2026): admin/operator di semua OLT,
  partner hanya di OLT privat miliknya — lihat
  [11](11-keamanan-rbac-audit.md#penegakan-akses-3-lapis).

### 5a. Matikan / nyalakan port PON (ZTE C300/C320, Sep 2026)
- Tombol **Matikan Port / Nyalakan Port** di Detail Port GPON → `POST smartolt.port.admin-state`
  (`storePortAdminState`, `throttle:olt-refresh`) → `ZteCardUplinkService::setGponPortAdminState()`:
  `configure terminal` → `interface gpon-olt_1/{s}/{p}` → `shutdown` | `no shutdown` → `exit` → `end`,
  **tanpa `write`** (disengaja: port menyala lagi bila OLT reboot), lalu interface dibaca ulang.
- Gerbang: capability `supports_port_admin_write` (C300/C320; **C600 mati**, belum diverifikasi) +
  `User::canSetPonPortAdminState()` = **admin** (semua OLT), atau **partner pemilik OLT privat**
  (`ownsOlt`). Operator dan partner yang sekadar di-assign → 403.
  UI: prop `can_set_admin_state` + OLT ber-telnet.
- Konfirmasi `useConfirm` (varian `danger` saat mematikan, menyebut jumlah ONU terputus; `warning`
  saat menyalakan). Banner amber `portdetail.disabled_banner` selama port dimatikan dari NMS (prop
  `port_disabled` = ada alarm `port_disabled` terbuka).
- Efek alarm: mematikan menaikkan alarm `port_disabled` + satu notifikasi; menyalakan menutupnya + satu
  notifikasi pulih — detail di [10 §A](10-alarm-telegram.md#port-yang-dimatikan-dari-nms-port_disabled).
  Audit: `port.disabled|enabled|disable_failed|enable_failed`.
- ⚠️ Karena tanpa `write`, aksi lain yang menulis `write` ke OLT yang sama (Save Config, edit deskripsi
  port, tag VLAN uplink, Bind ONU dengan centang simpan) ikut **mengabadikan** `shutdown` — lihat
  [13](13-troubleshooting-maintenance.md#port-pon-dimatikan-dari-nms--save-config).

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
- **Kolom ODP** — dropdown pilih ODP (splitter) per-ONU via komponen bersama
  `Components/OnuOdpCell.vue` (submit `onu-odp.assign`); ada di ketiga family
  (ZTE/C-Data/HiOSO). Detail di [16 — Peta ONU & ODP](16-peta-onu.md).
- **Kolom ACS** (opsional, ketiga family) — lencana ter-GenieACS + PPPoE/IP dari prop `genieacs_map`,
  tombol semat manual, perangkat terhubung, dan ubah WiFi. Lihat §14b.
- **Hapus ONU massal** — centang beberapa ONU → `POST smartolt.port-onus.delete` (`deleteOnus`,
  maks 128, gate `supports_onu_delete`).
- Semua aksi dijaga `assertCapability()` sesuai vendor (lihat `SmartOltSupport`).

### 6b. Salin ONU ke port lain (ZTE C300/C320)
- Centang ONU di halaman ONU per port → modal salin → `POST smartolt.port-onus.copy`
  (`copyOnusToPort`, maks 256 ONU, port tujuan ≠ port asal, OLT yang sama). Gate
  `supports_onu_config_write` (OFF di C600).
- Antre `CopyOnusToPortJob` + tabel `copy_onu_tasks`; progres di-poll lewat
  `smartolt.copy-task.status`. `ZteOnuCopyService` membaca running-config tiap ONU sumber, membangun
  skrip registrasi penuh untuk interface tujuan (onu-id baru, `buildForCopy()`), lalu menyimpan baris
  `smartolt_onu_registrations` — memakai ulang pipeline register/eksekusi/audit. ONU sumber **tidak
  disentuh**. Flag `execute` = dry-run (buat skrip saja) vs langsung eksekusi.

### 6c. Family non-ZTE
- C-Data (`Pages/CDataOlt/PortOnus.vue`) & HiOSO (`Pages/Hioso/PortOnus.vue`) punya halaman ONU per
  port sendiri (rename/reboot/enable-disable/delete via CLI, label port sisi-NMS di header, kolom ODP).
  Sejak 4 Okt 2026 polanya disamakan dengan ZTE: **pemilih port** ◀ dropdown ▶ di header
  (`Components/CDataOlt/PortSwitcher.vue`; daftar port dari cache `olt.last_test_result.ports`, tanpa
  SNMP/perubahan controller; opsi memuat label sisi-NMS; tersembunyi bila OLT hanya punya satu port,
  mis. HiOSO HA7302 agregat), **kartu status** Status data / Total ONU / Online / Refresh terakhir
  (`Components/CDataOlt/PortOnuStats.vue`), dan baris toolbar cari + filter **Online/Offline** (dari
  `onu.online`; tanpa filter admin-state karena EPON/HiOSO melaporkan `unknown`) + filter ODP + Reset.
  Halaman ZTE masih menulis pemilih port & kartunya inline (belum memakai komponen ini).
- **C-Data firmware V3 — VLAN & detail port** (Sep 2026): halaman `cdata-olt.vlans`
  (`Pages/CDataOlt/Vlans.vue`: daftar `show vlan all`, buat VLAN, tag ke beberapa port sekaligus) dan
  `cdata-olt.port.detail` (`Pages/CDataOlt/PortDetail.vue`, `gpon|epon|ge|xge`: status, optik DDM +
  ambang OLT, trafik, VLAN, tag VLAN) lewat `CDataGponPortController` + `CDataGponPortService`. Gate
  `supports_cli_vlan`/`supports_cli_port_detail` = `SmartOltSupport::hasCDataV3Cli()` (sw_version
  faceplate `V3.`). **Tulis** (buat/tag VLAN) hanya `canManageOlt()` + `canEditOltConnection()`
  (admin/operator atau pemilik OLT privat) + telnet, dicatat audit, **tanpa `save` otomatis**. GPON: port PON
  otomatis ikut VLAN baru, uplink Trunk; EPON: uplink & port PON Hybrid, port PON di-tag manual. Rincian
  CLI: `docs/SMARTOLT_CDATA_GUIDE.md` §14.
- **HiOSO HA7302** (`SmartOltSupport::isHiosoHa7302()`, dikenali dari firmware/vendor/nama memuat
  `ha7302`): SNMP menyajikan ONU sebagai ruang LLID datar 1..128 (tanpa `Pon-Nni`, jadi satu port EPON
  agregat), CLI login 3-lapis + dialek beda (`set onu … reboot`, `delete onu 1/{pon}/{onu}`,
  `auth-mode pass|deny`), dan **rename lewat SNMP SET** (`description_mode = 'snmp'`) karena CLI-nya tak
  punya perintah rename. HA7304 tetap rename via CLI. Status online HiOSO diambil dari link-state
  SNMP `.39.1`, **bukan** dari Rx — Rx `na` pada ONU online itu normal
  ([13](13-troubleshooting-maintenance.md#hioso-rx-na-padahal-onu-online)). Rujukan:
  `docs/SMARTOLT_HIOSO_GUIDE.md`.

## 7. ONU Monitoring (lintas OLT)
- **Controller**: `onuMonitor`, `refreshOnuMonitor` · **Page**: `SmartOlt/OnuMonitor.vue`.
- Agregasi cache `port_onus` dari **semua** OLT (status online/offline, RX power, serial, nama).
- `refreshOnuMonitor` melakukan satu walk SNMP penuh OLT terkait lalu menulis cache per port.
- Kolom & filter **ACS** (semua / sudah / belum ter-ACS) dari field `acs` tiap ONU, plus tombol
  perangkat terhubung untuk admin/operator — tanpa penyematan manual (§14b).
- Dipakai juga oleh global search (⌘K) lewat `DashboardSearchController`.

## 8. Unconfigured ONU
- **Per-OLT**: `unconfigured`, `refreshUnconfigured` → `OltSnmpClient::unconfiguredOnus()`.
  Page `SmartOlt/Unconfigured.vue`.
- **Global**: `unconfiguredGlobal` → gabungan semua OLT. Page `SmartOlt/UnconfiguredGlobal.vue`
  (halaman yang ditautkan dari menu; OLT non-ZTE tidak tampil).
- ONU yang muncul di sini adalah kandidat untuk di-register (provisioning).
- **Refresh Discovery** lewat `App\Services\Zte\UnconfiguredOnuDiscovery` (dipakai web & API): tiap
  baris diberi `first_seen_at` — kolom **Pertama Terlihat** + lencana **Baru** (< 24 jam) di halaman
  global. OLT tak menyimpan waktu kemunculan, jadi presisinya = seberapa sering tombol ditekan; SN yang
  sudah ada saat pencatatan pertama ditandai *baseline*.
- **Bind ONU** (ganti ONU rusak tanpa register ulang, C300/C320): tombol per baris →
  `Components/SmartOlt/BindOnuModal.vue` → kandidat dari `GET smartolt.onu.replace-candidates` (SNMP
  live satu port, cadangan cache) → `POST smartolt.onu.replace` (`replaceOnu`):
  `interface gpon-onu_1/S/P:N` → `registration-method sn {SN-baru}`, lalu `write` opsional (centang,
  bawaan nyala). Menolak SN yang tak ada di cache unconfigured, SN di port lain, ONU target tak dikenal,
  dan SN non-alfanumerik. Sukses → SN di `port_onus`, `onu_odp_links`, `onu_map_pins` diperbarui; audit
  `onu.replaced` / `onu.replace_failed`. Gate `supports_onu_replace` (**C600 ditutup**). Terverifikasi
  live 29 Sep 2026; rincian di `docs/SMARTOLT_ZTE_C300_C320_C600_GUIDE.md` §5.6a.

## 9. Provisioning ONU
- **Controller**: `registerOnuForm`, `storeOnu`, `registrations`, `executeRegistration`.
- **Pages**: `SmartOlt/RegisterOnu.vue`, `Registrations.vue`.
- **Alur**:
  1. Form register (serial, slot/port/onu_id, profil, nama + **deskripsi**, WAN PPPoE/DHCP/static,
     TR069, Remote ONT, ODP opsional). Tiga mode di `RegisterOnu.vue`: **Sederhana** (C300/C320),
     **Lanjutan** (editor config per bagian, `storeOnuAdvanced`), **C600**.
  2. `storeOnu` → `ZteProvisioningScriptBuilder::build()` membuat **script CLI** dan menyimpan baris
     `smartolt_onu_registrations`. Dengan `execute=false` ("Generate script saja") status `generated`,
     belum dieksekusi; dengan `execute=true` skrip langsung dijalankan.
  3. `executeRegistration` → eksekusi belakangan dari daftar Registrations via
     `ZteCliProvisioningExecutor` (telnet), simpan output/error + `executed_at/by`, catat
     `PollingEvent::KIND_PROVISIONING`.
- **Kolom Deskripsi** (30 Sep 2026) terpisah dari Nama, opsional, maks **80** karakter, tanpa karakter
  kontrol (`SmartOltController::ONU_DESCRIPTION_RULES`). Kosong → Sederhana memakai konvensi SmartOLT
  `{id}$$nama$$`, Lanjutan = sama dengan Nama, C600 = zona lalu nama. Deskripsi hanya masuk
  `cli_script` (tak ada kolom baru); API/aplikasi mobile belum punya field ini. Nama pelanggan yang
  dibaca NMS tetap dari `name` (`customerNameFromOnu()`, `description` hanya cadangan).
- Profil di-hydrate dari katalog per-OLT (`hydrateProvisioningProfiles`) dengan fallback global; profil
  global disembunyikan bila OLT punya profil aktif bernama sama. Nama onu-type **peka huruf besar**
  (jangan di-`strtoupper`).
- **C600 (Jul 2026): provisioning AKTIF** (`supports_provisioning=true`) — mode **Model B /
  SmartOLT TR069** via `ZteC600ProvisioningScriptBuilder`, strukturnya direproduksi persis dari
  running-config ONU asli (dua service internet+mgmt, mgmt-ip in-band, VEIP/ACS, vport). Pendukung:
  **alokasi mgmt-IP otomatis** (scan IP terpakai di OLT), dropdown ONU Type & T-CONT dari katalog
  profil, preset ACS dari OLT. WAN PPPoE/DHCP/Static **tetap ditolak** di C600; reconfigure/edit ONU
  C600 juga masih OFF (`supports_onu_config_write=false`).
- Detail builder & sintaks di [09 — CLI & Telnet](09-cli-telnet.md).

## 10. Profil layanan
- **Controller**: `SmartOltProfileController` (index/store/syncFromOlt/update/destroy).
- **Service**: `ZteProfileCatalogService` — `syncFromOlt()` menjalankan `show ...` di OLT dan
  parse onu_type/tcont/vlan/ip.
- **Page**: `SmartOlt/Profiles.vue`.
- Profil scoped per-OLT (`snmp_olt_id`), profil global = `snmp_olt_id = null` (fallback).

## 10b. Peta ONU & ODP (ringkas)
Rinciannya di [16 — Peta ONU & ODP](16-peta-onu.md); yang perlu diketahui saat menyentuh modul lain:
- **Peta** (`map.index`, `OnuMapController`, `Components/Map/OnuMap.vue`): pin ONU hijau = online,
  merah = offline (bukan level RX); pin ODP berwarna per PON port. Di atas 350 pin/ODP di layar,
  semuanya digambar sebagai titik kanvas.
- **Kunci posisi**: kolom `locked` (bawaan true) di `onu_map_pins` & `odps`; setelah dibuka, pin bisa
  diseret dan **langsung tersimpan** saat dilepas — tombol Kunci hanya menutup kembali.
- **ODP** (`odp.index`, `Pages/Odp/Index.vue`, `OdpController`): CRUD, ganti OLT/slot/port (kaitan ONU
  yang tak cocok dilepas), dropdown Slot/Port dari scan terakhir, modal Kelola ONU. **Warna** dari
  palet server `OdpColors::PALETTE` (16 warna tanpa hijau/merah, bawaan amber), bawaan menyapu satu
  PON port, ODP baru mewarisi warna port-nya. **Foto** satu per ODP (disk privat, WebP via `cwebp`).
  **Hapus ODP permanen dan tak tercatat di `audit_logs`**.

## 11. Alarms
- **Controller**: `AlarmController@index` · **Page**: `SmartOlt/Alarms.vue`.
- Data dari `alarm_events`. Alarm di-generate `AlarmEvaluator` saat polling. Detail
  [10 — Alarm & Telegram](10-alarm-telegram.md).
- Filter status/severity/scope/jenis/OLT + cari. Filter **Aktif** menyembunyikan alarm non-persisten
  (mis. `onu_offline`, `dying_gasp`) yang sudah dibaca pengguna itu; alarm PENDING tak pernah tampil.
- **Bel notifikasi** (`Components/Shell/NotificationBell.vue`) dishare global lewat
  `HandleInertiaRequests` → `App\Services\Alarm\AlarmNotificationService::payloadFor()` (8 item,
  belum-dibaca dulu). **Klik = deep-link**: `POST notifications.alarms.open` menandai dibaca lalu
  `AlarmNotificationTargetResolver` memutuskan tujuan di **server** — ONU → detail ONU (bila
  `supports_cli_onu_detail`) atau `{prefix}.port-onus?focus={onuId}`; port/ODP → halaman ONU port
  itu; OLT → Detail OLT. ONU yang pindah port diikuti lewat serial; posisi yang kini dipakai ONU lain
  ditolak (`position_reused`) dan bel menampilkan alasan + tautan cadangan ke daftar Alarm.
  Tombol centang = `notifications.alarms.read` (satu alarm), "tandai semua" = `markAllRead`.
  Aturan lengkap di [10 §A Penyajian](10-alarm-telegram.md#penyajian).

## 12. Reports
- **Controller**: `ReportController` (index + exportCsv + exportPdf) · **Service**:
  `App\Services\Report\ReportService`.
- **Page**: `Pages/Reports/Index.vue`.
- **Empat** tipe laporan (`ReportService::TYPES`): `onu` (inventaris ONU + RX power; kolom
  "Serial Number / MAC" — EPON memakai MAC), `olt` (status OLT), `alarm` (riwayat alarm),
  `provisioning` (registrasi ONU). Tidak ada tipe RX tersendiri — RX adalah filter `rx_status`
  (`normal`/`warning`/`critical`) di laporan `onu`.
- Filter: `range` (24h/7d/30d/all, bawaan 7d), `olt_id`, `pon_port` (`{slot}_{port}`, hanya bila satu
  OLT dipilih), `rx_status`, `status` (opsi per tipe). Export CSV (stream) & PDF (dompdf); judul &
  kolom lewat `lang/{id,en}/reports.php`.

## 13. Users & RBAC
- **Controller**: `UserController` (`role:admin`) · **Page**: `Pages/Users/Index.vue`. Model peran di
  [11](11-keamanan-rbac-audit.md).
- Hanya admin yang mengelola akun: CRUD user (nama, email, sandi), pilih peran
  (admin/operator/partner/demo), dan **penugasan OLT** (`olt_ids`, hanya OLT global; relevan untuk
  partner & operator). Partner hanya melihat OLT yang di-assign + OLT privat miliknya; operator tanpa
  penugasan melihat semua OLT. Jumlah OLT di tabel = penugasan + OLT privat milik user itu. Admin
  tidak bisa menghapus dirinya sendiri, dan admin terakhir tidak bisa dihapus/diturunkan.
- Tanpa registrasi publik: akun dibuat lewat halaman ini atau
  `php artisan user:create --name= --email= --password= --role=` (admin pertama saat instalasi).

## 14. Audit Logs
- **Controller**: `AuditLogController@index` (`role:admin`; partner tidak) · **Page**:
  `Pages/AuditLogs/Index.vue`.
- Tampil dari `audit_logs` (immutable). Diisi otomatis trait `Auditable` + listener login + aksi khusus
  (telnet, port PON, Bind ONU, dll. — daftar di [11 §D](11-keamanan-rbac-audit.md#d-audit-trail)).
  Hapus ODP **tidak** tercatat.

## 14b. GenieACS / TR-069 (lintas halaman, opsional)

Bukan halaman tersendiri — modul ini menempel di kolom **ACS** pada halaman ONU per port (ketiga
family) dan Monitoring ONU. Dorman sampai admin mengisi alamat NBI di Pengaturan.

- **Lencana ter-ACS** — dibaca dari tabel lokal `genieacs_device_map`, **tidak pernah** memanggil
  ACS saat merender. Ambang "masih hidup" 2 jam (alasannya di
  [20 §6](20-genieacs-tr069.md#6-lencana-ter-acs-di-tabel-onu)).
- **Perangkat terhubung** — satu-satunya panel yang memanggil NBI saat dibuka; menampilkan
  perangkat **aktif** saja, karena `Hosts.Host` sebenarnya tabel sewa DHCP.
- **Ubah SSID & kata sandi WiFi** — satu-satunya aksi modul ini yang menulis ke perangkat
  pelanggan (audit, tanpa kata sandi).
- **Penyematan manual** — untuk ONU yang tak bisa dicocokkan otomatis (±33% pada armada yang
  diukur) dan untuk membetulkan pasangan yang meleset. Yang disimpan **identitas ONU**, bukan
  posisinya. Tersedia di halaman ONU per port saja; Monitoring ONU tetap baca-saja.
- **Izin** — semua aksi di atas hanya untuk admin/operator (`User::canManageAcs()`) pada OLT global
  non-demo. Partner yang di-assign OLT global tetap melihat lencana + PPPoE/IP, tapi tanpa tombol.

Pengaturan koneksinya ada di **Pengaturan → tab ACS / TR069** (kartu GenieACS (NBI)). Selengkapnya:
[20 — GenieACS / TR-069](20-genieacs-tr069.md).

## 15. Pengaturan (Settings)
- **Controller**: `SettingsController` (`role:admin`) · **Page**: `Pages/Settings/Index.vue`. Tab:
  Umum · ACS · Alarm · Bot Telegram · Notifikasi Mobile · API.
- **Umum**: nama app, versi, upload logo → `general_settings` (branding di-cache + dishare global).
- **Alarm**: **pusat kebijakan alarm** (`alarm_settings`) untuk bot Telegram global **dan** push FCM —
  debounce 2 poll, min severity, raise/clear, jenis alarm, korelasi induk-anak, grup ODP. Detail
  [10 §B](10-alarm-telegram.md#kebijakan-alarm-terpusat--alarm_settings-singleton).
- **Bot Telegram**: kini **koneksi saja** — token/chat id/perintah → `telegram_settings`; tombol test
  kirim, register/delete webhook. Filter alarm tidak lagi di sini. Detail [10](10-alarm-telegram.md).
- **Notifikasi Mobile**: saklar push FCM (`fcm_settings`), kirim notifikasi manual, daftar perangkat
  (cabut token/perangkat).
- **API**: token integrasi Sanctum milik admin yang login — akses penuh (baca + tulis, sesuai peran
  pemiliknya), kedaluwarsa ikut `SANCTUM_EXPIRATION` (bawaan `.env.example` 43200 menit = 30 hari;
  kosong = tak kedaluwarsa); daftar token sendiri + cabut. Token juga bisa diterbitkan lewat
  `php artisan api:token {email} --name=`. Lihat [`docs/API.md`](../API.md).
- **ACS**: **URL CWMP** + username/password yang ditanam ke ONU saat provisioning TR069 / TR069
  Massal (`acs_settings`, singleton; kosong = jatuh balik ke `ACS_*` di `.env`). Tab yang sama memuat
  kartu **GenieACS (NBI)** — host/port/pengguna/kata sandi NBI yang dibaca dasbor
  (`genieacs_credentials`) + tombol **Uji koneksi**. Dua tabel, dua peran; detail
  [20](20-genieacs-tr069.md#2-pengaturan-dua-tabel-dua-peran-yang-sering-tertukar).

## 16. Browser Telnet
- **Controller**: `TelnetSessionController@token` · **Page/Component**: `Components/Shell/TelnetWindow.vue`
  (jendela draggable/min/max, xterm.js).
- Terbit tiket terenkripsi singkat → browser buka WebSocket ke daemon `telnet:proxy`. Detail
  [09 — CLI & Telnet](09-cli-telnet.md).
- Gerbang: `User::canAccessOltSecrets()` (admin/operator, atau partner pemilik OLT privat) — di
  controller token **dan** di daemon proxy. Partner pada OLT global yang sekadar di-assign → 403.

## 17. Profil akun & Auth
- **Auth lokal Laravel Breeze** (`routes/auth.php`, `app/Http/Controllers/Auth/*`): login
  (`LoginRequest`, rate-limit 5 percobaan per email+IP), lupa/reset sandi via email, verifikasi
  email, konfirmasi sandi, logout. Pages `Auth/*` (`Login`, `ForgotPassword`, `ResetPassword`,
  `ConfirmPassword`, `VerifyEmail`). **Registrasi publik off** — `RegisteredUserController` &
  `Auth/Register.vue` ada tapi tidak di-route; admin pertama dibuat `user:create --role=admin`.
- Aplikasi Android login lewat `POST /api/v1/auth/login` (sandi diperiksa lokal, balas token
  Sanctum) — lihat [`docs/API.md`](../API.md).
- `ProfileController` + page `Profile/Edit.vue`: `update` (nama/email, `ProfileUpdateRequest`;
  partial `UpdateProfileInformationForm`), ganti sandi (`PUT password`, partial
  `UpdatePasswordForm`), `destroy` (hapus akun sendiri dengan konfirmasi sandi, partial
  `DeleteUserForm`), dan `updateTheme` (`PATCH profile/theme`, dark/light/system).

## Selanjutnya

→ [08 — SNMP & Polling](08-snmp-polling.md)
