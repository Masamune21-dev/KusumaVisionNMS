# 11 — Keamanan, RBAC & Audit

[← Indeks](README.md) · [← 10 Alarm & Telegram](10-alarm-telegram.md) · [12 Frontend →](12-frontend.md)

## A. Role-Based Access Control (RBAC)

### Role — `App\Enums\UserRole`
| Role | Value | Kemampuan |
|------|-------|-----------|
| Administrator | `admin` | Semua: kelola user, audit logs, settings, kelola OLT |
| Operator | `operator` | Kelola OLT (CRUD, provisioning, telnet) — **tanpa** user/settings/audit |
| Partner | `partner` | Mengelola OLT yang di-assign admin **DAN OLT PRIVAT yang ia tambah sendiri** (edit, provisioning, telnet, reboot/rename/delete ONU). Boleh **tambah** OLT (jadi privat miliknya) & **hapus** OLT miliknya; **tidak** boleh hapus OLT global yang sekadar di-assign; **tidak** akses user/settings/audit. Punya bot Telegram sendiri (self-service). |
| Demo | `demo` | **Read-only**, hanya melihat data demo (`is_demo=true`) |

Helper di `User`: `isAdmin()`, `isOperator()`, `isPartner()`, `isDemo()`, `canManageOlt()`
(admin+operator+**partner**), `canManageOltInventory()` (admin+operator — gate hapus **device** OLT
global), `canAddOlt()` (admin+operator+**partner** — gate **tambah** OLT), `ownsOlt(SnmpOlt)`
(OLT privat milik user), `canManageUsers()` (admin). Partner: `partnerOlts()` (OLT ter-assign + milik,
pivot `olt_user`), `allowedOltIds()` (id OLT boleh diakses — **query pivot + `snmp_olts.owner_user_id`
langsung**, bukan relasi, agar tak memicu scope rekursif).

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

### Cakupan OLT partner — `App\Models\Scopes\PartnerOltScope`
Global scope (pola sama `DemoScope`). Dipasang di `SnmpOlt` (kolom `id`) dan model ber-`snmp_olt_id`
(`AlarmEvent`, `PollingEvent`, `SmartOltOnuRegistration`, `OnuMapPin`). Dua cabang:
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

**Alarm ke partner:** `FcmAlarmNotifier` & `TelegramNotifier` — untuk OLT **global** penerima = admin+operator ∪
partner ter-assign; untuk OLT **privat partner** (`owner_user_id` terisi) admin/operator **tidak** dapat
notif — hanya partner pemiliknya. Bot Telegram partner: lihat [10 — Alarm & Telegram](10-alarm-telegram.md).

### Penegakan akses (3 lapis)
1. **Middleware route** — `role:admin` (`EnsureUserRole`) membungkus grup Users/Audit/Settings.
   Tidak match → `abort(403)`.
2. **Cek di controller** — aksi OLT/telnet memanggil `abort_unless($user->canManageOlt(), 403)`
   (mis. `TelnetSessionController@token`).
3. **Capability driver** — `SmartOltController::assertCapability($olt, 'supports_xxx')` menolak
   aksi yang tidak didukung vendor (lihat `SmartOltSupport::capabilities()` di [02](02-arsitektur.md)).

### Share ke frontend
`HandleInertiaRequests::share()` mengirim `auth.can` (`manage_users`, `manage_olt`,
`manage_olt_inventory`, `add_olt`, `is_partner`, `is_demo`) ke semua page → UI menyembunyikan tombol
sesuai izin. Serialisasi OLT (`serializeOlt`) menambah `is_private` (OLT privat partner) & `owned`
(milik viewer) → tombol **Hapus** muncul saat `manage_olt_inventory` atau `owned`, badge **Privat** saat
`is_private`. **Tetapi backend yang menegakkan** — UI hanya kosmetik.

## B. Demo mode (read-only + isolasi data)

Dua mekanisme bekerja bersama:

1. **`BlockDemoWrites`** (middleware global) — user role `demo` ditolak (`403`) untuk semua
   request non-GET/HEAD/OPTIONS, kecuali `logout`. Jadi demo benar-benar read-only.
2. **`DemoScope`** (global scope pada model ber-`is_demo`) — query otomatis difilter:
   - user `demo` → hanya baris `is_demo = true`,
   - selain itu (termasuk console/queue tanpa auth) → hanya `is_demo = false`.

Implikasi: data demo dan data produksi bisa hidup di DB yang sama tanpa saling bocor. Model
ber-scope: `SnmpOlt`, `SmartOltOnuRegistration`, `AlarmEvent`, `PollingEvent`.

> `DemoSeeder` mengisi data demo (`is_demo=true`). **Jangan jalankan di DB produksi** — buat
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
- **Telnet ticket** terenkripsi APP_KEY + TTL pendek; proxy tidak menyimpan kredensial — diambil
  dari OLT saat handshake.

## D. Audit trail

Tabel `audit_logs` (immutable, hanya `created_at`). Lihat skema di [05](05-database-model.md).

### Sumber entri
1. **Perubahan model** — trait `App\Models\Concerns\Auditable` mengaitkan
   `created/updated/deleted` → `AuditLogger::model()`. Model yang memakainya: `SnmpOlt`, `User`,
   `SmartOltOnuRegistration`, `SmartOltProfile`, `TelegramSetting`, `GeneralSetting`.
   - `auditLabel()`/`auditTitle()` membentuk deskripsi ("Memperbarui OLT OLT-C320-PATI").
   - `$auditExclude` + `$hidden` + (`id`,`created_at`,`updated_at`,`password`,`remember_token`)
     tidak ikut tersimpan. Field volatil polling (mis. `last_test_result`) dikecualikan.
   - Update tanpa perubahan tersaring (changes kosong → tidak menulis audit).
2. **Event auth** — `AppServiceProvider::boot()` mendengar `Login`/`Logout`/`Failed` →
   `login` / `logout` / `login_failed`.
3. **Aksi khusus** — `telnet_opened` (`TelnetSessionController`).

### Penulis tunggal — `AuditLogger`
`AuditLogger::log($event, $auditable?, $properties, $description?, $actor?)` menangkap aktor
(`auth()->user()`), IP, user-agent otomatis. `AuditLogger::model()` membentuk deskripsi dari
label/judul model.

### Melihat audit
`AuditLogController@index` (admin) → `Pages/AuditLogs/Index.vue`. Hanya admin.

## E. CSRF, webhook, dan health

- CSRF aktif untuk semua route web kecuali `telegram/webhook` (gerbangnya secret token header).
- `/up` health check (Laravel) — boleh dipantau, tidak mengandung data sensitif.
- Hardening host (nginx deny dotfiles/`.env`, security headers, UFW allow-list, SSH key-only,
  PHP-FPM `display_errors=Off`) didokumentasikan di
  [`docs/LOCAL_PRODUCTION_HARDENING.md`](../LOCAL_PRODUCTION_HARDENING.md).

## Checklist keamanan saat menambah fitur
- [ ] Endpoint tulis OLT? Pasang `canManageOlt()` + `assertCapability()` bila perlu.
- [ ] Endpoint admin? Bungkus `role:admin`.
- [ ] Menyimpan secret? Cast `encrypted` + `$hidden` + jangan log.
- [ ] Entitas baru perlu dipisah demo? Tambah `is_demo` + `DemoScope`.
- [ ] Perubahan baris perlu jejak? `use Auditable` + isi label/title + `$auditExclude`.
- [ ] Demo tidak boleh menulis → otomatis tertangani `BlockDemoWrites` (non-GET diblok).

## Selanjutnya

→ [12 — Frontend](12-frontend.md)
