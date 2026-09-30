# Mode Demo

> **Status per 1 Okt 2026:** mekanisme di bawah (dibuat Mei 2026) masih berlaku — kolom `is_demo`,
> `DemoScope`, `BlockDemoWrites`, dan `DemoSeeder` ada di kode. Yang berubah: password akun seeder kini
> **acak** (bukan lagi `password`); flag `is_demo` hanya ada di **4 tabel** (lihat Prinsip) sehingga data
> yang ditambahkan belakangan (ODP, pin peta, riwayat RX, dll.) tidak ikut dipisah — docblock `DemoSeeder`
> kini menyarankan instance/DB demo **terpisah**, bukan database produksi. Role `demo` tetap read-only di web
> maupun API (kecuali logout & ganti tema).

Mode demo menyajikan data contoh (bukan OLT nyata) untuk presentasi/uji coba, dengan akun
read-only. Berjalan **pada instance & database yang sama** dengan data produksi — pemisahan
dilakukan lewat flag `is_demo` + global scope, bukan database terpisah.

## Prinsip

- **Isolasi via `is_demo`.** Kolom `is_demo` ada di `snmp_olts`, `alarm_events`,
  `polling_events`, `smartolt_onu_registrations`. `App\Models\Scopes\DemoScope` (global scope
  di tiap model) memastikan:
  - user role **demo** hanya melihat baris `is_demo = true` (data contoh),
  - user lain (admin/operator) dan konteks console/queue hanya melihat `is_demo = false` (data nyata).
- **Role `demo` read-only.** `BlockDemoWrites` menolak semua request non-GET (kecuali logout & ganti tema);
  tombol aksi disembunyikan dan banner "Mode Demo" tampil.
- Polling/scheduler berjalan di konteks console (tanpa auth) → hanya menyentuh OLT nyata; OLT
  demo bersifat statis (tidak ikut di-poll).

## Akun

| Email | Password | Role |
|---|---|---|
| `demo@kusumavision.test` | lihat di bawah | demo (read-only, lihat data demo) |
| `admin@kusumavision.test` | lihat di bawah | admin |

Password kedua akun **dibuat acak** saat seeder dijalankan dan ditampilkan sekali di keluaran
perintah — dulu keduanya `password`, yang langsung bisa ditebak begitu demo dibuka ke internet.
Untuk nilai tetap (mis. CI), setel `DEMO_SEED_PASSWORD` di `.env` sebelum menjalankan seeder.

> Tetap ganti password sebelum demo dibuka ke publik. Anda juga bisa membuat user role `demo` lain
> lewat halaman Users — semuanya otomatis melihat data demo.

## Mengaktifkan data demo

```bash
php artisan migrate --force                       # tambah kolom is_demo (jika belum)
php artisan db:seed --class=DemoSeeder --force    # isi data demo (ber-flag is_demo=true)
```

Baris buatan `DemoSeeder` ber-flag `is_demo=true` dan tersembunyi dari user nyata, tetapi seeder juga
membuat/memperbarui dua akun (`admin@…test` ber-role admin) — karena itu jalankan di instance demo
terpisah, bukan di database produksi. `updateOrCreate` memakai `withoutGlobalScopes()` sehingga idempotent
(boleh dijalankan ulang tanpa duplikat).

## Isi data demo

- 2 OLT (`OLT-DEMO-PATI` C320, `OLT-DEMO-JUWANA` C300) dengan `last_test_result` lengkap
  (port up/down, ONU online/offline, RX power bervariasi termasuk warning/critical).
- ~200 polling event per OLT (sebagian gagal) untuk mengisi tren Dashboard & Report.
- Alarm contoh (critical/major/minor/warning, aktif & selesai).
- Registrasi provisioning contoh (executed/generated/failed).

## Menghapus data demo

```bash
php artisan tinker --execute="
App\Models\PollingEvent::withoutGlobalScopes()->where('is_demo',true)->delete();
App\Models\AlarmEvent::withoutGlobalScopes()->where('is_demo',true)->delete();
App\Models\SmartOltOnuRegistration::withoutGlobalScopes()->where('is_demo',true)->delete();
App\Models\SnmpOlt::withoutGlobalScopes()->where('is_demo',true)->delete();
"
```

## Verifikasi

- Login `demo@kusumavision.test` → banner "Mode Demo", hanya OLT demo yang tampil, tombol aksi hilang.
- Login admin/operator → hanya OLT nyata yang tampil (data demo tidak mengganggu).
- Dashboard & Report (CSV/PDF) untuk demo terisi data contoh.
- Aksi tulis sebagai demo → ditolak 403 oleh `BlockDemoWrites`.
