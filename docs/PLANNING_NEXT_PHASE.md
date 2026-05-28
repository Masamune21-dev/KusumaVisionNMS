# Planning Fase Berikutnya — Report, Role User & Mode Demo

Dokumen perencanaan untuk tiga fitur baru KusumaVision NMS:

1. **Halaman Report** — laporan operasional (ONU, OLT, alarm, provisioning, RX power) dengan ekspor CSV & PDF.
2. **Role User (RBAC)** — hak akses berjenjang yang dikelola di halaman Users.
3. **Mode Demo** — role `demo` read-only + DB demo terisolasi untuk keperluan presentasi/uji coba.

Status: **rencana** — belum diimplementasikan. Dokumen ini menjadi acuan eksekusi per-fase.

---

## Keputusan desain (disepakati)

| Topik | Keputusan | Alasan |
|---|---|---|
| Model RBAC | Kolom `role` enum sederhana di tabel `users` + Gate/middleware Laravel | Set role tetap & kecil, tanpa dependency baru. Kolom disimpan sebagai `string` agar tetap kompatibel dengan SQLite (test). |
| Daftar role | `admin`, `operator`, `demo` | Cukup untuk kebutuhan saat ini; Viewer tidak dipisah. |
| Isolasi data demo | ~~DB terpisah~~ → **Direvisi**: flag `is_demo` + global scope `DemoScope` pada satu instance | User menjalankan satu instance, sehingga demo perlu lihat data dummy yang terisolasi dari data nyata di DB yang sama. Lihat [DEMO_DEPLOYMENT.md](DEMO_DEPLOYMENT.md). |
| Report | On-screen (tabel + chart + filter) + ekspor **CSV** dan **PDF** | CSV ringan via native response; PDF via `barryvdh/laravel-dompdf`. |

### Matriks hak akses

| Kapabilitas | admin | operator | demo |
|---|:---:|:---:|:---:|
| Lihat Dashboard / SmartOLT / Alarms / Report | ✅ | ✅ | ✅ (read-only) |
| Tambah/Edit/Hapus OLT | ✅ | ✅ | ❌ |
| Provisioning & konfigurasi ONU (write CLI) | ✅ | ✅ | ❌ |
| Reboot / set state ONU | ✅ | ✅ | ❌ |
| Sinkron & kelola profil | ✅ | ✅ | ❌ |
| Ekspor report (CSV/PDF) | ✅ | ✅ | ✅ |
| Kelola Users & Role | ✅ | ❌ | ❌ |
| Semua request non-GET | ✅ | ✅ (selain Users) | ❌ (diblokir total) |

Aturan ringkas:
- **admin** = akses penuh, satu-satunya yang bisa kelola user/role.
- **operator** = semua operasi OLT/ONU, **tidak** bisa kelola user.
- **demo** = hanya `GET` (read-only) di seluruh aplikasi; semua tombol aksi disembunyikan di UI.

---

## Fase A — Role User (RBAC)

Fondasi untuk Fase B & C, jadi dikerjakan lebih dulu.

### A1. Enum & migrasi

**Create:**
- `app/Enums/UserRole.php` — enum string `Admin = 'admin'`, `Operator = 'operator'`, `Demo = 'demo'`. Tambah helper `label()` (Indonesia) & `values()`.
- `database/migrations/xxxx_add_role_to_users_table.php`
  - `$table->string('role')->default('operator')->after('email');`
  - Disimpan sebagai `string` (bukan enum native Postgres) demi kompatibilitas SQLite test.
  - `down()`: `dropColumn('role')`.

### A2. Model User

**Changed:** [app/Models/User.php](../app/Models/User.php)
- Tambah `'role'` ke `$fillable`.
- Cast `'role' => UserRole::class`.
- Helper: `isAdmin()`, `isOperator()`, `isDemo()`, `canManageOlt()` (admin|operator), `canManageUsers()` (admin).

### A3. Otorisasi (Gate + middleware)

**Create:**
- `app/Http/Middleware/EnsureUserRole.php` — middleware berparameter, mis. `role:admin` / `role:admin,operator`; `abort(403)` bila tidak cocok.
- `app/Http/Middleware/BlockDemoWrites.php` — bila `auth()->user()?->isDemo()` dan method bukan `GET`/`HEAD`/`OPTIONS`, `abort(403, 'Mode demo: read-only.')`. Diterapkan global di grup `auth` sehingga konsisten tanpa menandai tiap route.

**Changed:** [bootstrap/app.php](../bootstrap/app.php)
- Daftarkan alias: `$middleware->alias(['role' => EnsureUserRole::class]);`
- Append `BlockDemoWrites` setelah `HandleInertiaRequests` di grup web (atau pasang di grup `auth` pada `routes/web.php`).

### A4. Proteksi route

**Changed:** [routes/web.php](../routes/web.php)
- Bungkus route Users dengan `->middleware('role:admin')` (index/store/update/destroy).
- Route SmartOLT yang mutasi (store/update/destroy/register/execute/configure/reboot/state/profiles/sync/vlan/refresh) → `role:admin,operator`. Route `GET` SmartOLT tetap terbuka untuk semua role login.
- `BlockDemoWrites` menjadi pengaman lapis kedua agar demo tak pernah bisa menulis.

### A5. Bagikan role & abilities ke frontend

**Changed:** [app/Http/Middleware/HandleInertiaRequests.php](../app/Http/Middleware/HandleInertiaRequests.php)
- Perluas `auth.user` agar menyertakan `role`.
- Tambah `auth.can`:
  ```php
  'can' => [
      'manage_users' => (bool) $user?->canManageUsers(),
      'manage_olt'   => (bool) $user?->canManageOlt(),
      'is_demo'      => (bool) $user?->isDemo(),
  ],
  ```

### A6. UI Users — kelola role

**Changed:** [resources/js/Pages/Users/Index.vue](../resources/js/Pages/Users/Index.vue)
- Tambah field `role` di `useForm` + dropdown (Admin/Operator/Demo) pada modal create/edit.
- Tampilkan **badge role** di tabel & kartu mobile (warna: admin=cyan, operator=emerald, demo=amber).
- Halaman ini sudah otomatis hanya bisa diakses admin (route `role:admin`).

**Changed:** [app/Http/Controllers/UserController.php](../app/Http/Controllers/UserController.php)
- Validasi `role` → `['required', Rule::enum(UserRole::class)]` di `store`/`update`.
- Sertakan `role` di payload `index()`.
- Pertahankan guard "tidak bisa hapus akun sendiri"; tambah guard "admin terakhir tidak boleh dihapus/diturunkan" agar sistem tak terkunci.

### A7. Navigasi & gating UI

**Changed:** [resources/js/Layouts/AuthenticatedLayout.vue](../resources/js/Layouts/AuthenticatedLayout.vue)
- Item nav **Users** hanya tampil bila `auth.can.manage_users`.
- Tambah item nav **Report** (Fase B) untuk semua role.
- Di halaman SmartOLT: sembunyikan/disable tombol aksi (Tambah OLT, Register, Configure, Reboot, Hapus, dll) bila `!auth.can.manage_olt` atau `auth.can.is_demo`.

### A8. Seeder & migrasi data lama

**Changed:** [database/seeders/DatabaseSeeder.php](../database/seeders/DatabaseSeeder.php)
- User test default → `role = admin`.
- User existing di produksi diberi `role = admin` lewat default migrasi/komando satu kali (`php artisan tinker` atau data migration) agar tidak terkunci.

### A9. Test

**Create:** `tests/Feature/RoleAccessTest.php`
- operator diblokir di route Users (403).
- demo diblokir di semua POST/PUT/DELETE (403) tapi boleh GET.
- admin lolos semua.

---

## Fase B — Halaman Report

Memanfaatkan data yang sudah ada (`SnmpOlt.last_test_result`, `AlarmEvent`, `PollingEvent`, `SmartOltOnuRegistration`). Banyak agregasi sudah ada di [DashboardStatsService](../app/Services/Dashboard/DashboardStatsService.php) dan bisa dipakai ulang/diperluas.

### B1. Service laporan

**Create:** `app/Services/Report/ReportService.php` — kumpulan builder baris laporan, menerima filter `['range' => '24h|7d|30d|custom', 'from', 'to', 'olt_id' => ?int]`:

- `onuInventory()` — daftar ONU dari `last_test_result.port_onus[].onus[]`: OLT, slot/port, onu_id, SN, tipe, status online/offline, rx_power, nama pelanggan.
- `oltStatus()` — ringkasan per-OLT (reuse `DashboardStatsService::oltStatuses()`).
- `alarmHistory()` — dari `AlarmEvent` (semua status), kolom: OLT, severity, type, pesan, first/last seen, durasi.
- `provisioning()` — dari `SmartOltOnuRegistration`: tanggal, OLT, SN, pelanggan, mode WAN, status, executor.
- `rxPower()` — ONU dengan `rx_power`, ditandai normal / warning (< -25 dBm) / critical (< -28 dBm).

Catatan: skema `last_test_result` mengikuti yang ditulis `OltSnmpClient`/`SmartOltController` (`ok`, `system`, `ports[].oper_status`, `port_onus[].count`, `port_onus[].onus[].{online,rx_power,sn,...}`). ReportService **membaca** struktur ini, jangan mengubahnya.

### B2. Controller & route

**Create:** `app/Http/Controllers/ReportController.php`
- `index()` → render `Reports/Index` dengan data laporan terpilih + daftar OLT untuk filter.
- `exportCsv()` → `StreamedResponse` CSV sesuai `type` + filter.
- `exportPdf()` → render blade ke PDF.

**Changed:** [routes/web.php](../routes/web.php) (dalam grup `auth`):
```php
Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
Route::get('/reports/export/csv', [ReportController::class, 'exportCsv'])->name('reports.export.csv');
Route::get('/reports/export/pdf', [ReportController::class, 'exportPdf'])->name('reports.export.pdf');
```
Semua `GET` → bisa diakses semua role (termasuk demo). Tidak perlu `role:` khusus.

### B3. Halaman Vue

**Create:** `resources/js/Pages/Reports/Index.vue`
- Tab/selector jenis laporan (ONU / OLT / Alarm / Provisioning / RX Power).
- Filter: rentang tanggal (24h/7d/30d/custom), per-OLT.
- Tabel hasil + ringkasan kartu; opsional chart (mengikuti komponen chart Dashboard yang ada).
- Tombol **Export CSV** & **Export PDF** (link ke route dengan query filter).
- Ikuti gaya glassmorphism + pola mobile-card/`kv-table-desktop` yang sudah dipakai di halaman lain.

### B4. PDF

**Create:**
- `composer require barryvdh/laravel-dompdf`
- `resources/views/reports/pdf.blade.php` — template cetak dengan header BMKV/KusumaVision, metadata filter, tabel laporan.

### B5. Test

**Create:** `tests/Feature/ReportTest.php`
- `/reports` render 200 untuk admin/operator/demo.
- Export CSV mengembalikan `text/csv` dengan header kolom benar.
- Export PDF mengembalikan `application/pdf`.

---

## Fase C — Mode Demo (role + DB terpisah)

Demo dijalankan sebagai **deployment/DB terpisah** yang seluruh isinya data demo. Tidak ada flag/scoping di DB produksi.

### C1. Enforcement read-only

Sudah ditangani Fase A via `BlockDemoWrites` + gating UI. Tambahan:
- **Changed:** `AuthenticatedLayout.vue` — banner kecil "Mode Demo — read-only" saat `auth.can.is_demo`.
- Pastikan semua tombol mutasi tersembunyi/disabled untuk demo (bukan hanya diblokir server) demi UX.

### C2. Demo seeder

**Create:** `database/seeders/DemoSeeder.php` — mengisi DB demo dengan data realistis:
- **Users:** `admin demo` (role admin, untuk operator demo) + `demo` (role demo, `demo@kusumavision.test`).
- **`SnmpOlt`:** 2–3 OLT (mis. `OLT-DEMO-PATI` C320, `OLT-DEMO-JUWANA` C300) dengan `last_test_result` JSON lengkap meniru output nyata: `ok=true`, `system.sysDescr` mengandung "ZTE C320/C300", `ports[]` dengan `oper_status`, `port_onus[]` berisi puluhan ONU (online/offline, `rx_power` bervariasi termasuk beberapa warning/critical, SN, tipe, nama pelanggan). Kredensial SNMP/CLI diisi dummy.
- **`AlarmEvent`:** campuran severity (critical/major/minor/warning), status aktif & cleared, `first_seen_at`/`last_seen_at` tersebar beberapa hari.
- **`PollingEvent`:** ratusan event sukses + sebagian gagal selama 7–30 hari agar tren Dashboard/Report terisi.
- **`SmartOltOnuRegistration`:** beberapa registrasi dengan status `generated`/`executed`/`failed`.
- **(Opsional)** `SmartOltProfile`, `SmartOltInterfaceStatus`, `SmartOltCardStatus` agar halaman PortManager/Detail tampak hidup.

Penting: bentuk JSON `last_test_result` harus cocok dengan yang dibaca `DashboardStatsService` & halaman `SmartOlt/*` (online share, sparkline, status per OLT). Verifikasi dengan membuka Dashboard setelah seeding.

### C3. Catatan deployment demo

**Create:** `docs/DEMO_DEPLOYMENT.md` (atau bagian di README) berisi:
- DB terpisah, mis. `kusumavision_nms_demo` + `.env.demo`.
- Provisioning: `php artisan migrate:fresh --seed --seeder=DemoSeeder` di instance demo.
- Kredensial demo (akun `demo`), URL demo, dan peringatan **jangan** jalankan `DemoSeeder` di DB produksi.

### C4. Test

**Create:** `tests/Feature/DemoSeederTest.php`
- `DemoSeeder` jalan tanpa error di SQLite.
- User `demo` ada dengan role `demo`.
- Setelah seed: ada ≥1 OLT, ≥1 alarm, ≥1 polling event.

---

## Urutan eksekusi & checklist

1. **Fase A (RBAC)** — fondasi; tanpa ini Fase B/C tidak aman.
   - [ ] Enum + migrasi `role`
   - [ ] Model + helper
   - [ ] Middleware `role` & `BlockDemoWrites` + alias
   - [ ] Proteksi route
   - [ ] Share `auth.can` ke Inertia
   - [ ] UI Users (dropdown + badge) + UserController
   - [ ] Gating nav & tombol aksi
   - [ ] Seeder admin + test
2. **Fase B (Report)**
   - [ ] ReportService
   - [ ] Controller + route
   - [ ] `Reports/Index.vue`
   - [ ] CSV
   - [ ] dompdf + template PDF
   - [ ] Test
3. **Fase C (Demo)**
   - [ ] Banner read-only + gating final
   - [ ] DemoSeeder
   - [ ] Docs deployment demo
   - [ ] Test

Setiap fase selesai → update [WORKLOG.md](../WORKLOG.md) (format Created/Changed/Notes) lalu `/done`.

---

## Risiko & catatan

- **Kompatibilitas SQLite:** kolom `role` harus `string`, jangan enum native Postgres — test memakai SQLite in-memory.
- **Lock-out admin:** wajib guard agar admin terakhir tidak bisa dihapus/diturunkan rolenya.
- **Demo bocor:** isolasi via DB terpisah dipilih supaya tak perlu scoping; jangan pernah seed demo ke DB produksi. `BlockDemoWrites` tetap dipasang sebagai jaring pengaman jika suatu saat demo & data nyata satu instance.
- **Skema `last_test_result`:** ReportService & DemoSeeder bergantung pada bentuk JSON ini; verifikasi terhadap OLT nyata (id=1 `OLT-C320-PATI`, id=2) dan terhadap pembaca di `DashboardStatsService`.
- **PDF dependency:** `barryvdh/laravel-dompdf` menambah paket; pastikan masuk `composer.json` dan teruji di environment produksi lokal.
