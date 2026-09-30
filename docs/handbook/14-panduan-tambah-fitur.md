# 14 — Panduan Menambah Fitur

[← Indeks](README.md) · [← 13 Troubleshooting](13-troubleshooting-maintenance.md)

Resep langkah-demi-langkah untuk pekerjaan paling sering. Selalu mulai dari memahami bagian
terkait di handbook ini, lalu ikuti pola yang sudah ada di kode (jangan menciptakan gaya baru).

> **Aturan main wajib** (rinciannya di bagian *Konvensi wajib* di bawah):
> - Migrasi **harus SQLite-compatible** (test pakai SQLite). [→ 05](05-database-model.md)
> - Sintaks CLI ZTE → konsultasi [`SMARTOLT_ZTE_C300_C320_C600_GUIDE.md`](../SMARTOLT_ZTE_C300_C320_C600_GUIDE.md)
>   (C600: [`SMARTOLT_ZTE_C600_GUIDE.md`](../SMARTOLT_ZTE_C600_GUIDE.md)); C-Data/HiOSO: guide masing-masing.
> - Semua string yang tampil ke pengguna **dwibahasa ID/EN** — frontend `$t()`, backend `__()`.
> - Test **hanya** lewat `bash scripts/test.sh` (atau `composer test`) + `npm test`; **jangan**
>   `php artisan test`/`phpunit` polos — di checkout produksi itu men-drop tabel produksi.
> - Di checkout produksi: migrasi dulu sebelum kode yang membacanya, `route:cache` setelah menambah
>   rute, restart daemon setelah mengubah kode job/proxy.
> - Capability vendor baru hanya dinyalakan setelah **terverifikasi di perangkat asli**.
> - Catat perubahan berarti di [`WORKLOG.md`](../../WORKLOG.md) (Created/Changed/Fixed/Notes + verifikasi
>   OLT nyata bila menyentuh SNMP/CLI). Selesai → commit & push (perintah `/done` di
>   `.claude/commands/done.md` merangkum langkahnya).
> - Jalankan `./vendor/bin/pint` sebelum commit; frontend → checklist
>   [`UI_DESIGN_SYSTEM.md`](../../UI_DESIGN_SYSTEM.md) §5.

---

## Konvensi wajib (berlaku sejak Sep 2026)

### A. String dwibahasa (ID/EN)
- **Frontend**: semua teks lewat `$t('namespace.kunci')`; kunci baru ditambahkan ke **kedua**
  `resources/js/lang/id.json` dan `en.json` (tak ada test otomatis untuk JSON ini; fallback `en`
  membuat kunci yang lupa di `id.json` diam-diam tampil Inggris). Pesan hanya boleh interpolasi
  `{param}` — compiler kustom tidak mendukung plural/linked ([12](12-frontend.md)). Enum stabil
  (jenis alarm, status) dikirim mentah dan diterjemahkan by-key di frontend (`lib/alarm.js`).
- **Backend**: pesan error/status (exception yang diteruskan ke UI, `abort`, `'message'`/`'error'`
  JSON, `->with()` flash, pesan validasi kustom) **jangan ditulis literal** — pakai
  `__('grup.kunci', [...])`. Grup: `lang/{id,en}/flash.php` (flash controller), `reports.php`
  (CSV/PDF), `system.php`, dan grup per domain `olt, zte, cdata, hioso, odp, common`. Teks `id` baru
  harus konsisten dengan gaya lama (test berjalan di locale `id`).
- `tests/Unit/LangParityTest` menjaga: kunci & placeholder `id` = `en` di tiap grup, dan setiap
  `__('grup.kunci')` literal di `app/` ada di kedua bahasa (`APP_FALLBACK_LOCALE=en`).
- **Sengaja tetap Indonesia**: teks yang disimpan/diantrekan sebagai data (pesan alarm
  `AlarmEvaluator`, push FCM, item progres task salin-ONU/TR069, error backup config, deskripsi
  `AuditLogger`) dan log. Daemon `telnet-proxy` memakai `users.locale` pemilik tiket;
  rute `api` tidak memasang `SetLocale` (selalu `id`).

### B. Rute baru & `route:cache`
- Produksi memakai **route cache** (`bootstrap/cache/routes-v7.php`), untuk web **dan** API. Rute
  baru → 404/405 di produksi, dan `route('…')` di Vue gagal (Ziggy membaca rute dari cache), sampai
  `php artisan route:cache` dijalankan ulang (sebagai `www-data`).
- **Test tidak menangkap ini**: `scripts/test.sh` mengalihkan `APP_ROUTES_CACHE`, jadi test memakai
  rute segar dan bisa hijau sementara tombol/API live mati.
- Urutan deploy bila ada rute + frontend: `route:cache` → `npm run build` (lalu pastikan
  `public/build` milik `www-data`) → `queue:restart` bila kode job ikut berubah.

### C. Test
- PHPUnit **hanya** `bash scripts/test.sh [--filter=…]` / `composer test`: skrip mengalihkan
  `APP_CONFIG_CACHE` + `APP_ROUTES_CACHE` ke path non-eksisten dan **abort** bila probe koneksi bukan
  `sqlite|:memory:`. `php artisan test` polos membaca config cache produksi → pgsql produksi →
  `RefreshDatabase` men-drop semua tabel. `config:clear` juga bukan solusi (cache config produksi
  hilang).
- Vitest: `npm test` (`tests/js/*.spec.js`).
- Data uji **fiktif** (nama pelanggan/serial/IP rekaan, mis. serial/ID berawalan `0800`) — jangan
  menyalin data pelanggan/OLT produksi ke test.

### D. Bila checkout ini sekaligus produksi
Instalasi `install.sh` menjalankan aplikasi langsung dari checkout repo, jadi setiap edit langsung
tayang:
- **Migrasi dulu, baru kode.** Kode yang membaca kolom/tabel baru langsung error bila tayang sebelum
  `php artisan migrate --force`.
- **Refactor multi-berkas** (hapus/ganti nama simbol lintas berkas) disiapkan di **worktree** lalu
  diterapkan sekaligus — mengedit satu per satu di checkout produksi bisa menghasilkan HTTP 500 di
  jeda antar-edit.
- Daemon long-lived: `php artisan queue:restart` (worker: `PollOltJob` & job lain), restart
  `kusumavision-telnet-proxy` saat 0 sesi aktif. Ubah `.env`/config → `php artisan config:cache`.

### E. Capability vendor & verifikasi live
- Fitur yang bergantung pada vendor/model **wajib** punya flag di `SmartOltSupport::capabilities()`
  (default `false`/mati untuk family yang belum dicek; C600 dinilai terpisah dari C300/C320 lewat
  `isC600()`), ditegakkan di controller (`assertCapability()` / `abort_unless`) dan dipakai UI
  (`olt.capabilities.*`) — **pakai capability, bukan nama family**, untuk memutuskan perilaku.
- Nyalakan capability hanya setelah perintah CLI/OID **terbukti di perangkat asli** (context-help `?`
  polos, `snmpwalk` baca-saja, running-config). OID/sintaks yang tak bisa diverifikasi → biarkan
  `null` dan capability mati. Jangan pakai PDF C600 di `docs/` sebagai sumber OID.
- Catat bukti verifikasinya (model OLT, firmware, tanggal) di WORKLOG dan guide vendor.

---

## Resep 1 — Menambah halaman + route baru

1. **Controller**: tambah method yang `return Inertia::render('Folder/Nama', [props...])`.
   (atau buat controller baru: `php artisan make:controller XxxController`).
2. **Route** di `routes/web.php` dalam grup `auth` (bungkus `role:admin` / `role:admin,operator,partner`
   sesuai kebutuhan; aksi mahal ke OLT → `throttle:olt-refresh`):
   ```php
   Route::get('/fitur', [XxxController::class, 'index'])->name('fitur.index');
   ```
3. **Page Vue** di `resources/js/Pages/Folder/Nama.vue`, pakai `AuthenticatedLayout`. Akses props
   lewat `defineProps`. Pakai `route('fitur.index')` (Ziggy) untuk link/aksi.
4. **Menu** (opsional): tambah item di grup menu `AuthenticatedLayout.vue` (`name: t('nav.…')`,
   `href: route()`, `match`).
5. **Izin**: sembunyikan tombol via `auth.can.*`, tegakkan di backend (`role:` / `canManageOlt()` /
   izin yang lebih sempit seperti `canAccessOltSecrets()`, `canEditOltConnection()`,
   `canSetPonPortAdminState()`). Entitas milik OLT ikut `PartnerOltScope` lewat route-model binding.
5b. **Teks**: kunci `$t()` baru di kedua `lang/{id,en}.json`; pesan backend lewat `__()`.
6. **Tampilan**: ikuti tema (kartu kaca, kelas `kv-*`, palet cyan/sky, tabel responsif, item
   sidebar) — aturan lengkap di [15 — UI & Tema Dashboard](15-ui-tema-dashboard.md).
7. `php artisan route:list` untuk verifikasi; di produksi **`php artisan route:cache`** (sebagai
   `www-data`) sebelum `npm run build` — tanpa itu rute baru 404 walau test hijau.

## Resep 2 — Menambah tabel + model

1. `php artisan make:migration create_xxx_table` — pakai tipe yang aman di SQLite.
2. `php artisan make:model Xxx`. Set `$fillable`, `casts()`.
3. Bila perubahan baris perlu jejak → `use Auditable` + `auditLabel()`/`auditTitle()` +
   `$auditExclude` (field volatil/sensitif).
4. Secret → cast `encrypted` + masuk `$hidden`.
5. Perlu dipisah demo? Tambah kolom `is_demo` + `DemoScope` di `booted()`.
6. Entitas milik OLT → pasang `PartnerOltScope` di `booted()`.
7. `php artisan migrate` (dev) / `migrate --force` (prod) — di produksi **sebelum** kode yang
   membacanya tayang. Tambah test.

Lihat detail & contoh di [05 — Database & Model](05-database-model.md).

## Resep 3 — Menambah aksi SNMP (read) ke OLT

1. Tambah konstanta OID di `OltSnmpClient` (dan `cmd/kv-snmp-poller/main.go` bila perlu dipoll
   terjadwal).
2. Tambah method publik (mis. `fooTable($olt)`) memakai `walk()`/`get()` + helper decode.
3. Bila vendor-spesifik, gerbang lewat `SmartOltSupport` (cek `isC600()` / capability). OLT non-ZTE
   tidak memakai `OltSnmpClient`: tambahkan ke driver `SmartOltSnmpDriver` family terkait
   (`app/Services/CData/*`, `app/Services/Hioso/*`) dan `CDataOltScanner`.
4. Untuk polling terjadwal: tambahkan ke output Go poller (`main.go`) **dan** ke `PollOltJob`
   agar masuk `last_test_result`. Rebuild (sama dengan `install.sh`):
   `CGO_ENABLED=0 go build -mod=mod -trimpath -ldflags='-s -w' -o bin/kv-snmp-poller ./cmd/kv-snmp-poller`.
5. Verifikasi ke OLT nyata (`snmpwalk` baca-saja dulu), catat di WORKLOG. OID yang belum terbukti →
   `null` + capability mati.

Lihat [08 — SNMP & Polling](08-snmp-polling.md).

## Resep 4 — Menambah aksi CLI (write/provisioning)

1. Tambahkan baris perintah di builder yang sesuai:
   - register baru → `ZteProvisioningScriptBuilder`.
   - ubah existing → `ZteOnuReconfigureScriptBuilder` (pola diff baseline→target).
2. Eksekusi via `ZteCliProvisioningExecutor::execute()` / `executeConfirmable()`.
3. Simpan jejak ke `smartolt_onu_registrations` (script dulu, eksekusi belakangan/opsional) +
   `PollingEvent::KIND_PROVISIONING`.
4. Gerbang dengan `assertCapability($olt, 'supports_...')` + izin yang tepat (`canManageOlt()` atau
   yang lebih sempit); catat aksi & kegagalannya lewat `AuditLogger::log()`.
5. **Sintaks perintah wajib dari** [`SMARTOLT_ZTE_C300_C320_C600_GUIDE.md`](../SMARTOLT_ZTE_C300_C320_C600_GUIDE.md).
   Executor mengirim **tiap baris + Enter** — jangan pernah menaruh context-help berargumen
   (`shutdown ?`) di script: perintahnya ikut **dijalankan**.
6. Putuskan eksplisit apakah script diakhiri `write` (lihat tabel "Kapan konfigurasi tersimpan" di
   [09](09-cli-telnet.md)); default proyek: **tanpa** `write`, pengguna menekan Save Config.
7. Teks input bebas yang masuk CLI disanitasi (buang CR/LF & karakter kontrol) — cegah injeksi baris.
8. Verifikasi ke OLT nyata; pastikan password tersensor (`maskSecrets`).

Lihat [09 — CLI & Telnet](09-cli-telnet.md).

## Resep 5 — Menambah jenis alarm

1. Di `AlarmEvaluator`, tambah detektor (pola `portAlarm`/`onuStateAlarms`/`onuRxAlarm`) yang
   mengembalikan entri ke `$detected` dengan `signature` unik, `type`, `severity`, `scope`,
   lokasi, `message`.
2. Pastikan logika **transisi sehat→fault** (jangan alarm device yang sudah fault saat pertama
   dilihat) dan kondisi **clear** (idealnya histeresis seperti RX).
3. `reconcile()` menangani raise/clear, debounce 2 poll (`pending` → `active`), dan pengiriman
   Telegram + FCM. Pertimbangkan korelasi induk-anak (port/ODP/OLT) agar tak menambah banjir alarm.
4. Tambah konstanta `AlarmEvent::TYPE_*` + label, kunci i18n `alarms.type_<jenis>` (id & en), dan —
   bila jenis baru harus ikut terkirim — **migrasi data** yang menambahkannya ke
   `alarm_settings.notify_types` bila kolom itu berisi daftar eksplisit (contoh
   `2026_09_30_000001_add_port_disabled_to_alarm_notify_types`). Pesan alarm tetap Bahasa Indonesia
   (disimpan sebagai data).
5. Tambah test untuk transisi raise + clear (`AlarmEngineTest`).

Lihat [10 — Alarm & Telegram](10-alarm-telegram.md).

## Resep 6 — Menambah command Telegram

1. Tambah case di `TelegramCommandHandler::handle()` + method handler-nya.
2. Hormati `isChatAuthorized()` untuk command yang mengakses data.
3. Escape output (`escape()` MarkdownV2). Update `/help`.
4. Untuk push baru, lewat `TelegramNotifier`.

## Resep 7 — Menambah dependency

- **PHP**: `composer require vendor/paket` → commit `composer.json` + `.lock`.
- **JS**: `npm install paket` → commit `package.json` + lockfile. Awas gotcha manifest Vite untuk
  library banyak-dynamic-import (bungkus `defineAsyncComponent`). [→ 12](12-frontend.md)
- **Go**: edit `go.mod` → `go mod tidy` → rebuild binary.

## Resep 8 — Menambah command artisan / scheduled job

1. `php artisan make:command XxxCommand` → set `$signature`/`$description`.
2. Job berat → `php artisan make:job XxxJob` (implements `ShouldQueue`), pertimbangkan
   `WithoutOverlapping` seperti `PollOltJob`.
3. Jadwalkan di `routes/console.php` (`Schedule::command(...)->...`).
4. Di prod, pastikan worker/scheduler supervisor jalan; setelah ubah kode → `queue:restart`.

---

## Checklist pre-commit / pre-deploy

- [ ] `./vendor/bin/pint` (style) bersih.
- [ ] `bash scripts/test.sh` hijau (jangan `php artisan test` polos di mesin prod) + `npm test`.
- [ ] Migrasi SQLite-compatible; di prod dijalankan **sebelum** kode.
- [ ] Izin/role + capability + demo handling + `PartnerOltScope` sudah benar (lihat [11](11-keamanan-rbac-audit.md)).
- [ ] Secret tidak ter-log; cast `encrypted` + `$hidden`.
- [ ] String dwibahasa: kunci `$t()` di `lang/{id,en}.json`, `__()` di `lang/{id,en}/*.php`
      (`LangParityTest` hijau).
- [ ] (SNMP/CLI) diverifikasi ke OLT nyata; capability baru default mati sampai terbukti.
- [ ] Data uji fiktif (tanpa nama/serial/IP produksi).
- [ ] Entri `WORKLOG.md` ditambahkan.
- [ ] (Prod) rute baru → `route:cache`; `config:cache` bila menyentuh `.env`/config;
      `queue:restart` + restart telnet proxy bila menyentuh kode daemon.
- [ ] (Frontend) checklist `UI_DESIGN_SYSTEM.md` §5, `npm run build`, dan cek tidak ada page 500
      (manifest) / `route()` yang belum terdaftar.

## Referensi cepat
- Pola data dashboard → `DashboardStatsService` ([07](07-modul-fitur.md)).
- Pola laporan + export → `ReportService` ([07](07-modul-fitur.md)).
- Capability vendor → `SmartOltSupport` ([02](02-arsitektur.md)).
- Audit otomatis → trait `Auditable` + `AuditLogger` ([11](11-keamanan-rbac-audit.md)).
