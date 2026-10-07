# 11 — Keamanan, RBAC & Audit

[← Indeks](README.md) · [← 10 Alarm & Telegram](10-alarm-telegram.md) · [12 Frontend →](12-frontend.md)

## A. Role-Based Access Control (RBAC)

### Role — `App\Enums\UserRole`
| Role | Value | Kemampuan |
|------|-------|-----------|
| Administrator | `admin` | Semua: pengguna, audit logs, settings, kelola OLT, matikan/nyalakan port PON OLT global |
| Operator | `operator` | Kelola OLT (CRUD, provisioning, telnet) — **tanpa** user/settings/audit, **tanpa** matikan port PON |
| Partner | `partner` | Mengelola OLT yang di-assign admin **DAN OLT PRIVAT yang ia tambah sendiri** (edit, provisioning, reboot/rename/delete ONU). Boleh **tambah** OLT (jadi privat miliknya) & **hapus** OLT miliknya; **tidak** boleh hapus OLT global yang sekadar di-assign. Telnet, ubah koneksi, dan matikan port PON **hanya di OLT miliknya**. **Tidak** akses user/settings/audit. Punya bot Telegram sendiri (self-service). |
| Demo | `demo` | **Read-only**, hanya melihat data demo (`is_demo=true`) |

Helper di `User`: `isAdmin()`, `isOperator()`, `isPartner()`, `isDemo()`, `canManageOlt()`
(admin+operator+**partner**), `canManageOltInventory()` (admin+operator — gate hapus **device** OLT
global), `canAddOlt()` (admin+operator+**partner** — gate **tambah** OLT), `ownsOlt(SnmpOlt)`
(OLT privat milik user), `canManageUsers()` (admin saja), `isOltScoped()` (partner selalu; operator
bila punya penugasan).
Gerbang per-OLT:
- `isCentralStaff()` — role admin atau operator.
- `canEditOltConnection($olt)` — ubah IP/port/SNMP/CLI dan uji koneksi: admin/operator, atau pemilik
  OLT privat (`ManagesOltOwnership::authorizeOltUpdate`/`authorizeOltConnectionTest`); juga gerbang
  tulis VLAN C-Data.
- `canAccessOltSecrets($olt)` — telnet browser (token **dan** daemon proxy) dan isi backup
  running-config: `canManageOlt()` + (admin/operator atau pemilik OLT). Daftar OLT menampilkan tombol
  Telnet hanya bila `serializeOlt().can_telnet` (= gerbang ini) dan Test SNMP hanya bila
  `connection_locked` false — dulu kedua tombol tampil lalu 403.
- `canWriteOltUplinkConfig($olt)` — tulis config uplink yang langsung `write` (tag VLAN uplink ZTE):
  `canManageOlt()` + (admin/operator atau partner pemilik OLT).
- `canSetPonPortAdminState($olt)` — matikan/nyalakan port PON: `isAdmin() || (isPartner() &&
  ownsOlt($olt))` — **admin**, atau **partner pemilik OLT privat**. Operator & partner yang sekadar
  di-assign → 403.
- `canManageAcs()` — katalog GenieACS (cari device, tarik ulang katalog): `isCentralStaff()` saja.
  Katalog memuat device **seluruh** pelanggan, jadi partner & demo tidak punya akses.
- `canUseAcsCatalogOn($olt)` — semat/lepas pasangan ACS, perangkat terhubung, ubah WiFi:
  `canManageAcs()` **dan** OLT global non-demo (`GenieacsDeviceSyncService::isEligibleOlt()`). Partner
  yang di-assign OLT global tetap **melihat** lencana ter-ACS + PPPoE/IP di tabel ONU, tapi tombolnya
  disembunyikan dan endpoint-nya 403. Lihat [20](20-genieacs-tr069.md#9-izin--rute).

Partner: `partnerOlts()` (OLT ter-assign + milik, pivot `olt_user`), `allowedOltIds()` (id OLT boleh
diakses — **query pivot + `snmp_olts.owner_user_id` langsung**, bukan relasi, agar tak memicu scope
rekursif).

### Kepemilikan OLT privat partner — kolom `snmp_olts.owner_user_id`
`owner_user_id` **null** = OLT **global** (dikelola admin/operator, perilaku lama). **Terisi** = OLT
**privat milik seorang partner** — hanya partner pemilik yang bisa melihat/mengelola; **tersembunyi
total dari admin/operator**. Saat partner menambah OLT (via `SmartOltController`/`CDataOltController`/
`HiosoOltController` store, trait `Concerns\ManagesOltOwnership::claimOltForPartner`), `owner_user_id`
di-set ke id-nya (via `forceFill`, **bukan** mass-assignment) + baris pivot `olt_user` dibuat otomatis
(agar scope/alarm/Telegram/FCM tetap jalan). Hapus OLT di-gate kepemilikan
(`authorizeOltDeletion` → partner hanya OLT miliknya). Saat partner dihapus, `owner_user_id` OLT-nya
di-null-kan (`UserController::destroy`) agar OLT kembali ke pool global (tak yatim). Migrasi backfill
`backfill_partner_owned_olts` mengkonversi OLT lama yang di-assign ke tepat satu partner (tanpa operator)
menjadi privat miliknya.

**Koneksi & rahasia OLT global yang di-assign** (Sep 2026): partner boleh mengubah nama/vendor/polling,
tetapi **tidak** IP/port/SNMP/kredensial CLI — mengganti IP berarti poller mengirim community SNMP dan
telnet proxy mengetik login CLI OLT global ke host pilihan partner. Penjaganya
`Concerns\ManagesOltOwnership::authorizeOltUpdate()` (403 bila kolom koneksi berubah) +
`User::canEditOltConnection()`; uji koneksi, **telnet**, dan **isi backup running-config** memakai
`User::canAccessOltSecrets()` (admin/operator atau pemilik OLT privat). Form OLT menampilkan kolom
koneksi hanya-baca (`connection_locked`).

### Cakupan OLT partner — `App\Models\Scopes\PartnerOltScope`
Global scope (pola sama `DemoScope`). Dipasang di `SnmpOlt` (kolom `id`) dan model ber-`snmp_olt_id`
(`AlarmEvent`, `PollingEvent`, `SmartOltOnuRegistration`, `OnuMapPin`, `Odp`, `OnuOdpLink`,
`OltPortLabel`). Dua cabang:
- **User ter-scope** (partner selalu; operator dengan assignment) → hanya OLT dalam `allowedOltIds()`
  (assignment pivot + OLT privat miliknya).
- **User tak ter-scope** (admin, operator tanpa assignment, demo) → semua OLT **global** (`owner_user_id`
  NULL), tapi OLT **privat partner disembunyikan total** — termasuk dari admin.

Karena **setiap controller memakai route-model binding `SnmpOlt $olt`**, satu scope ini otomatis:
(a) menyaring daftar/detail/edit/refresh/telnet/API/peta/search/report, (b) mengembalikan **404** saat
partner membuka OLT non-assigned / admin membuka OLT privat partner, (c) menyaring alarm (bell, halaman
Alarms, API). No-op untuk konteks console/queue (poller tetap memoll semua OLT termasuk privat).
Assignment global dikelola admin di halaman **Users** (multiselect OLT) — pivot OLT milik privat
**dipertahankan** saat sync (`syncPartnerOlts`) supaya kepemilikan tak lepas. Aksi tambah OLT di-gate
`role:admin,operator,partner`; hapus di-gate role + kepemilikan di controller.

**Satu pengecualian sadar dari "tersembunyi total"**: daftar user (`UserController::index`) menampilkan
**jumlah** OLT partner termasuk OLT privatnya (`total_olt_count` = pivot ∪ `owner_user_id`,
`owned_olt_count` = yang ia tambah sendiri) supaya angkanya tidak menyesatkan admin. Nama/IP/detail OLT
privat tetap tersembunyi. Hitungannya **wajib** lewat `DB::table('snmp_olts')` mentah — relasi
`partnerOlts` kena `PartnerOltScope` sehingga tak pernah memuat OLT privat. Field `assigned_olt_ids`
(pengisi centang form assign) tetap **hanya OLT global**: admin tak boleh menugaskan/mencabut OLT privat
partner, jadi memasukkannya ke situ bisa membuat form menghapus kepemilikan yang tak terlihat di layar.

**Alarm ke partner:** `FcmAlarmNotifier` & `TelegramNotifier` — untuk OLT **global** penerima = admin+operator ∪
partner ter-assign; untuk OLT **privat partner** (`owner_user_id` terisi) admin/operator **tidak** dapat
notif — hanya partner pemiliknya. Bot Telegram partner: lihat [10 — Alarm & Telegram](10-alarm-telegram.md).

### Penegakan akses (3 lapis)
1. **Middleware route** (`EnsureUserRole`, alias `role`) — `role:admin` untuk Users, Audit Logs, dan
   Settings; `role:partner` untuk `partner.telegram.*`; `role:admin,operator,partner` untuk tambah/hapus
   OLT, grup tulis API, dan rute tulis GenieACS. Tidak match → `abort(403)`.
2. **Cek di controller** — gerbang per-OLT dari `User` (lihat di atas): `canAccessOltSecrets()`
   (`TelnetSessionController@token`, `TelnetProxyServer`, isi backup config), `canEditOltConnection()`
   (update/uji koneksi OLT, tulis VLAN C-Data), `canSetPonPortAdminState()` (`storePortAdminState`),
   `canManageOlt()` (label port sisi-NMS), kepemilikan (`authorizeOltDeletion`),
   `canManageAcs()`/`canUseAcsCatalogOn()` (`GenieacsController` web & API — `role:` di rutenya
   meloloskan partner, jadi gerbang ini yang menolak).
3. **Capability driver** — `SmartOltController::assertCapability($olt, 'supports_xxx')` menolak
   aksi yang tidak didukung vendor (lihat `SmartOltSupport::capabilities()` di [02](02-arsitektur.md)).

Di luar tiga lapis itu, **cakupan OLT** (route-model binding + `PartnerOltScope`) dan `BlockDemoWrites`
berlaku untuk semua rute. Sebagian besar aksi ONU ZTE di `smartolt.*` (reboot, state, rename, hapus,
salin, TR069 Massal) memang hanya dijaga cakupan + capability + demo — semua peran non-demo sudah
`canManageOlt()`.

> **Tag VLAN uplink ZTE** (`POST smartolt.port.vlan` → `storePortVlan` → `ZteCardUplinkService::addAndTagVlan()`,
> skripnya **`write` otomatis**) sejak 1 Okt 2026 dijaga `canWriteOltUplinkConfig()`: admin/operator di semua OLT,
> partner hanya di OLT privat miliknya; selain itu 403 (`olt.uplink_write_forbidden`) sebelum CLI. Form VLAN di
> `PortDetail.vue` hanya tampil bila prop `can_write_uplink_vlan` true. Dulu tanpa gerbang peran sama sekali.

### Share ke frontend
`HandleInertiaRequests::share()` mengirim `auth.can` (`manage_users`, `manage_olt`,
`manage_olt_inventory`, `add_olt`, `manage_acs`, `is_partner`, `is_demo`) ke semua page → UI menyembunyikan tombol
sesuai izin. Serialisasi OLT (`serializeOlt`) menambah `is_private` (OLT privat partner) & `owned`
(milik viewer) → tombol **Hapus** muncul saat `manage_olt_inventory` atau `owned`, badge **Privat** saat
`is_private`. **Tetapi backend yang menegakkan** — UI hanya kosmetik.

## B. Demo mode (read-only + isolasi data)

Dua mekanisme bekerja bersama:

1. **`BlockDemoWrites`** (dipasang di grup `web` **dan** `api`, `bootstrap/app.php`) — user role
   `demo` ditolak (`403`, "Mode demo bersifat read-only.") untuk semua request non-GET/HEAD/OPTIONS,
   kecuali rute `logout`, `api.auth.logout`, dan `profile.theme` (tema demo hanya disimpan di cookie,
   baris `users` bersama tak ditulis). Di grup `api` user juga diresolusi lewat guard `sanctum`, jadi
   demo benar-benar read-only, juga lewat token API aplikasi. Konsekuensi yang disadari: **`POST
   /locale` (ganti bahasa) ikut diblok untuk demo** — pengguna demo tak bisa berganti ID/EN (tamu yang
   belum login tetap bisa).
2. **`DemoScope`** (global scope pada model ber-`is_demo`) — query otomatis difilter:
   - user `demo` → hanya baris `is_demo = true`,
   - selain itu (termasuk console/queue tanpa auth) → hanya `is_demo = false`.

Implikasi: data demo dan data produksi bisa hidup di DB yang sama tanpa saling bocor. Model
ber-scope: `SnmpOlt`, `SmartOltOnuRegistration`, `AlarmEvent`, `PollingEvent`.

> `DemoSeeder` mengisi data demo (`is_demo=true`); password akun demo dibuat acak dan ditampilkan
> sekali (atau `DEMO_SEED_PASSWORD`). **Jangan jalankan di DB produksi** — buat
> instance/DB demo terpisah bila perlu. `db:seed` default hanya `DatabaseSeeder` (1 admin test).

## C. Penanganan secret

- **Cast `encrypted` + `$hidden`** pada: `SnmpOlt` (snmp communities, cli_password),
  `SmartOltOnuRegistration` (pppoe/acs password), `TelegramSetting` (bot_token, webhook_secret).
- Enkripsi memakai **`APP_KEY`**. Mengganti APP_KEY membuat semua secret tak terbaca → jangan
  ganti tanpa rencana re-encrypt.
- **Edit OLT**: field secret kosong dipertahankan (`withoutEmptySecrets()`), tidak menimpa dengan
  string kosong.
- **Output CLI** disensor (`maskSecrets`) sebelum disimpan agar password CLI tak bocor ke DB/log.
- **`.env`** permission `640 root:www-data` di prod (lihat [04](04-instalasi-deploy.md)).
- **Telnet ticket** terenkripsi APP_KEY, **sekali pakai** (`jti` dicatat di cache dan dihanguskan saat
  dipakai) dengan TTL 30 detik; proxy tidak menyimpan kredensial — diambil dari OLT saat handshake,
  dan hak aksesnya dicek ulang (`canAccessOltSecrets`) saat itu.
- **Password ACS tidak pernah dikirim ke browser**: form registrasi hanya tahu `acs_password_set`;
  server mengisinya lewat `AcsSetting::fillPassword()` bila form kosong. Sama untuk kata sandi NBI
  GenieACS (`GenieacsCredential`, `encrypted` + `$hidden`; form Pengaturan hanya tahu `password_set`).
- **Token bot Telegram disensor** dari pesan galat & log (`TelegramNotifier::redactToken()`) — URL API
  Telegram memuat token, dan galat cURL menyertakan URL lengkap. Berkas log dibuat `0640`.
- **Token API aplikasi kedaluwarsa** setelah `SANCTUM_EXPIRATION` menit; token push FCM terkait sesi
  login (`fcm_device_tokens.personal_access_token_id`, FK cascade) sehingga logout/sesi dicabut
  menghentikan push ke ponsel itu.

### Layanan luar yang tak punya autentikasi sendiri
**NBI GenieACS (port 7557) bawaannya tanpa autentikasi** — HTTP polos, dan lewat NBI itu siapa pun
bisa mengedit *provision script*, yang berarti eksekusi kode di server ACS. Satu-satunya pengaman
yang berlaku adalah **jalur jaringan**: server NMS menjangkaunya lewat jaringan privat, VPN, atau
reverse proxy berautentikasi (pengguna/kata sandi NBI di Pengaturan dikirim sebagai HTTP Basic
auth); di firewall server ACS hanya **7547 (CWMP)** yang boleh publik karena ONU harus bisa
menghubunginya. Jangan pernah membuat NBI terjangkau dari internet, dan jangan menambah rute yang
meneruskannya ke browser. Lihat [20 §1](20-genieacs-tr069.md#1-topologi--peringatan-keamanan).

## D. Audit trail

Tabel `audit_logs` (immutable, hanya `created_at`). Lihat skema di [05](05-database-model.md).

### Sumber entri
1. **Perubahan model** — trait `App\Models\Concerns\Auditable` mengaitkan
   `created/updated/deleted` → `AuditLogger::model()`. Model yang memakainya: `SnmpOlt`, `User`,
   `SmartOltOnuRegistration`, `SmartOltProfile`, `TelegramSetting`, `PartnerTelegramBot`,
   `GeneralSetting`, `AlarmSetting`, `FcmSetting`, `AcsSetting`, `GenieacsCredential`.
   - **Tidak** ber-`Auditable`: `Odp`, `OnuOdpLink`, `OnuMapPin`, `OltPortLabel` — jadi **hapus ODP**
     (beserta kaitan ONU & fotonya), geser/hapus pin, dan label port **tidak tercatat**. Pemulihan ODP
     terhapus lewat log nginx + backup database ([13](13-troubleshooting-maintenance.md#odp-terhapus-tidak-sengaja)).
   - `auditLabel()`/`auditTitle()` membentuk deskripsi ("Memperbarui OLT OLT-C320-01").
   - `$auditExclude` + `$hidden` + (`id`,`created_at`,`updated_at`,`password`,`remember_token`)
     tidak ikut tersimpan. Field volatil polling (mis. `last_test_result`) dikecualikan.
   - Update tanpa perubahan tersaring (changes kosong → tidak menulis audit).
2. **Event auth** — `AppServiceProvider::boot()` mendengar `Login`/`Logout`/`Failed` →
   `login` / `logout` / `login_failed` (sandi diperiksa lokal; percobaan login dibatasi `LoginRequest`).
3. **Aksi khusus** (`AuditLogger::log()` eksplisit):
   - `telnet_opened` (`TelnetSessionController`).
   - `port.disabled` / `port.enabled` / `port.disable_failed` / `port.enable_failed` — matikan/nyalakan
     port PON ZTE (`storePortAdminState`; properti interface, slot/port, jumlah ONU, galat CLI
     tersanitasi).
   - `onu.replaced` / `onu.replace_failed` — Bind ONU (`replaceOnu`).
   - `updated` pada OLT dengan `success` true/false — buat/tag VLAN C-Data (`CDataGponPortController`).
   - GenieACS: `genieacs.wifi.updated` / `genieacs.wifi.failed` (menulis ke perangkat pelanggan, web &
     API — API menambah `channel: mobile`), `genieacs.pin.created` / `genieacs.pin.removed` /
     `genieacs.pin.failed` (menentukan perangkat siapa yang muncul di halaman pelanggan). **Kata sandi
     WiFi tidak ikut dicatat** — yang direkam cukup siapa mengubah apa.
   - Deskripsi audit (`description`) sengaja tetap bahasa Indonesia (disimpan sebagai data).

### Penulis tunggal — `AuditLogger`
`AuditLogger::log($event, $auditable?, $properties, $description?, $actor?)` menangkap aktor
(`auth()->user()`), IP, user-agent otomatis. `AuditLogger::model()` membentuk deskripsi dari
label/judul model.

### Melihat audit
`AuditLogController@index` (`role:admin`) → `Pages/AuditLogs/Index.vue`. Hanya admin.

## E. CSRF, webhook, dan health

- CSRF aktif untuk semua route web kecuali `telegram/webhook` (gerbangnya secret token header).
- `/up` health check (Laravel) dan `/healthz` (status DB & Redis, 200/503) — boleh dipantau, tidak
  mengandung data sensitif.
- **Proxy tepercaya** hanya dari `TRUSTED_PROXIES` (`config/trustedproxy.php`, bawaan localhost). Jangan
  `trustProxies(at: '*')`: `X-Forwarded-For` bisa dipalsukan sehingga throttle login dan IP audit diakali.
- **Upload logo tanpa SVG** (SVG bisa memuat script → XSS tersimpan).
- Hardening host (nginx deny dotfiles/`.env`, security headers, UFW allow-list, SSH key-only,
  PHP-FPM `display_errors=Off`) didokumentasikan di
  [`docs/LOCAL_PRODUCTION_HARDENING.md`](../LOCAL_PRODUCTION_HARDENING.md).

## Checklist keamanan saat menambah fitur
- [ ] Endpoint tulis OLT? Pasang `canManageOlt()` + `assertCapability()` bila perlu.
- [ ] Menyentuh rahasia/koneksi perangkat, atau memutus banyak pelanggan sekaligus? Pakai gerbang
      per-OLT (`canAccessOltSecrets`, `canEditOltConnection`, `canSetPonPortAdminState`), bukan
      sekadar `canManageOlt()` — partner yang di-assign OLT global tak boleh.
- [ ] Skrip CLI berakhir `write`/`save`? Sadari efeknya mengabadikan **seluruh** running-config
      (termasuk port yang sedang dimatikan dari NMS) — lebih baik tanpa simpan otomatis.
- [ ] Endpoint admin? Bungkus `role:admin`.
- [ ] Aksi tulis penting? Tulis `AuditLogger::log()` untuk sukses **dan** gagal (pola `port.*`).
- [ ] Menyimpan secret? Cast `encrypted` + `$hidden` + jangan log.
- [ ] Entitas baru perlu dipisah demo? Tambah `is_demo` + `DemoScope`.
- [ ] Perubahan baris perlu jejak? `use Auditable` + isi label/title + `$auditExclude`.
- [ ] Demo tidak boleh menulis → otomatis tertangani `BlockDemoWrites` (non-GET diblok).
- [ ] Endpoint **baca** yang isinya data pelanggan (nama secret PPPoE, IP, daftar perangkat)?
      Tetap batasi perannya — "cuma membaca" bukan alasan membukanya ke semua peran.
      Contoh: `genieacs.devices.search` hanya membaca tabel lokal tapi tetap dijaga `canManageAcs()`.
- [ ] Gerbang `role:admin,operator,partner` meloloskan partner. Aturan "hanya staf Pusat" atau
      "hanya OLT tertentu" ditegakkan di controller lewat method `User` (pola `authorizeAcsCatalog()`).
- [ ] Perintah artisan yang membaca data lintas-OLT? **`PartnerOltScope` tidak berlaku di konsol** —
      saring OLT partner/demo secara eksplisit (lihat `GenieacsUnlinkedReportCommand::scopedOlts()`,
      `GenieacsDeviceSyncService::eligibleOlts()`).
- [ ] Memanggil layanan luar yang tak berautentikasi (mis. NBI GenieACS)? Panggil dari server saja,
      jangan pernah buat proxy ke browser.

## Selanjutnya

→ [12 — Frontend](12-frontend.md)
