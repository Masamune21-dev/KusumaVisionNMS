# 14 — Panduan Menambah Fitur

[← Indeks](README.md) · [← 13 Troubleshooting](13-troubleshooting-maintenance.md)

Resep langkah-demi-langkah untuk pekerjaan paling sering. Selalu mulai dari memahami bagian
terkait di handbook ini, lalu ikuti pola yang sudah ada di kode (jangan menciptakan gaya baru).

> **Aturan main wajib:**
> - Migrasi **harus SQLite-compatible** (test pakai SQLite). [→ 05](05-database-model.md)
> - Sintaks CLI ZTE → konsultasi [`SMARTOLT_ZTE_C300_C320_GUIDE.md`](../SMARTOLT_ZTE_C300_C320_GUIDE.md).
> - String UI/flash/pesan dalam **Bahasa Indonesia**.
> - Catat perubahan berarti di [`WORKLOG.md`](../../WORKLOG.md) (Created/Changed/Notes + verifikasi
>   OLT nyata bila menyentuh SNMP/CLI). Selesai → push (lihat skill `/done`).
> - Jalankan `php artisan test` & `./vendor/bin/pint` sebelum commit.

---

## Resep 1 — Menambah halaman + route baru

1. **Controller**: tambah method yang `return Inertia::render('Folder/Nama', [props...])`.
   (atau buat controller baru: `php artisan make:controller XxxController`).
2. **Route** di `routes/web.php` dalam grup `auth` (bungkus `role:admin` bila khusus admin):
   ```php
   Route::get('/fitur', [XxxController::class, 'index'])->name('fitur.index');
   ```
3. **Page Vue** di `resources/js/Pages/Folder/Nama.vue`, pakai `AuthenticatedLayout`. Akses props
   lewat `defineProps`. Pakai `route('fitur.index')` (Ziggy) untuk link/aksi.
4. **Menu** (opsional): tambah item di array `links` `AuthenticatedLayout.vue` dengan `match`.
5. **Izin**: sembunyikan tombol via `auth.can.*`, tegakkan di backend (`role:` / `canManageOlt()`).
6. `php artisan route:list` untuk verifikasi.

## Resep 2 — Menambah tabel + model

1. `php artisan make:migration create_xxx_table` — pakai tipe yang aman di SQLite.
2. `php artisan make:model Xxx`. Set `$fillable`, `casts()`.
3. Bila perubahan baris perlu jejak → `use Auditable` + `auditLabel()`/`auditTitle()` +
   `$auditExclude` (field volatil/sensitif).
4. Secret → cast `encrypted` + masuk `$hidden`.
5. Perlu dipisah demo? Tambah kolom `is_demo` + `DemoScope` di `booted()`.
6. `php artisan migrate` (dev) / `migrate --force` (prod). Tambah test.

Lihat detail & contoh di [05 — Database & Model](05-database-model.md).

## Resep 3 — Menambah aksi SNMP (read) ke OLT

1. Tambah konstanta OID di `OltSnmpClient` (dan `cmd/kv-snmp-poller/main.go` bila perlu dipoll
   terjadwal).
2. Tambah method publik (mis. `fooTable($olt)`) memakai `walk()`/`get()` + helper decode.
3. Bila vendor-spesifik, gerbang lewat `SmartOltSupport` (cek `isC600()` / capability).
4. Untuk polling terjadwal: tambahkan ke output Go poller (`main.go`) **dan** ke `PollOltJob`
   agar masuk `last_test_result`. Rebuild: `go build -o bin/kv-snmp-poller ./cmd/kv-snmp-poller`.
5. Verifikasi ke OLT nyata (`snmpwalk` dulu), catat di WORKLOG.

Lihat [08 — SNMP & Polling](08-snmp-polling.md).

## Resep 4 — Menambah aksi CLI (write/provisioning)

1. Tambahkan baris perintah di builder yang sesuai:
   - register baru → `ZteProvisioningScriptBuilder`.
   - ubah existing → `ZteOnuReconfigureScriptBuilder` (pola diff baseline→target).
2. Eksekusi via `ZteCliProvisioningExecutor::execute()` / `executeConfirmable()`.
3. Simpan jejak ke `smartolt_onu_registrations` (script dulu, eksekusi belakangan/opsional) +
   `PollingEvent::KIND_PROVISIONING`.
4. Gerbang dengan `assertCapability($olt, 'supports_...')` + `canManageOlt()`.
5. **Sintaks perintah wajib dari** [`SMARTOLT_ZTE_C300_C320_GUIDE.md`](../SMARTOLT_ZTE_C300_C320_GUIDE.md).
6. Verifikasi ke OLT nyata; pastikan password tersensor (`maskSecrets`).

Lihat [09 — CLI & Telnet](09-cli-telnet.md).

## Resep 5 — Menambah jenis alarm

1. Di `AlarmEvaluator`, tambah detektor (pola `portAlarm`/`onuStateAlarms`/`onuRxAlarm`) yang
   mengembalikan entri ke `$detected` dengan `signature` unik, `type`, `severity`, `scope`,
   lokasi, `message`.
2. Pastikan logika **transisi sehat→fault** (jangan alarm device yang sudah fault saat pertama
   dilihat) dan kondisi **clear** (idealnya histeresis seperti RX).
3. `reconcile()` & notifikasi Telegram otomatis menangani raise/clear.
4. Tambah test untuk transisi raise + clear.

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
- [ ] `php artisan test` hijau (clear config cache dulu bila mesin prod).
- [ ] Migrasi SQLite-compatible.
- [ ] Izin/role + capability + demo handling sudah benar (lihat [11](11-keamanan-rbac-audit.md)).
- [ ] Secret tidak ter-log; cast `encrypted` + `$hidden`.
- [ ] String UI Bahasa Indonesia.
- [ ] (SNMP/CLI) diverifikasi ke OLT nyata.
- [ ] Entri `WORKLOG.md` ditambahkan.
- [ ] (Prod) `config:cache` + `queue:restart` + restart telnet proxy bila menyentuh config/daemon.
- [ ] (Frontend) `npm run build` dan cek tidak ada page 500 (manifest).

## Referensi cepat
- Pola data dashboard → `DashboardStatsService` ([07](07-modul-fitur.md)).
- Pola laporan + export → `ReportService` ([07](07-modul-fitur.md)).
- Capability vendor → `SmartOltSupport` ([02](02-arsitektur.md)).
- Audit otomatis → trait `Auditable` + `AuditLogger` ([11](11-keamanan-rbac-audit.md)).
