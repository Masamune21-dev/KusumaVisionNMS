# Berkontribusi

Terima kasih sudah tertarik membantu KusumaVision NMS. Repo ini adalah NMS GPON/FTTH untuk OLT
ZTE, C-Data, HiOSO, dan HSGQ (Laravel 12 + Vue 3/Inertia). Panduan singkat ini menjaga kualitas
dan keamanan karena banyak orang menjalankan perangkat lunak ini di jaringan produksi mereka.

## Sebelum mulai

- Cari dulu di [issue](https://github.com/Masamune21-dev/KusumaVisionNMS/issues) dan pull request
  yang ada — mungkin sudah dilaporkan atau sedang dikerjakan.
- Untuk perubahan besar (fitur baru, perubahan skema database, perubahan perilaku polling),
  buka issue dulu agar arahnya disepakati sebelum menulis banyak kode.
- Jangan pernah menempelkan IP perangkat sungguhan, nama pelanggan, nomor telepon asli, kredensial
  SNMP/telnet, atau isi `.env` di issue maupun PR. Data uji memakai alamat dokumentasi
  (`192.0.2.0/24`, `198.51.100.0/24`, `203.0.113.0/24`) dan nomor fiktif berawalan `0800`.

## Ukuran PR

- **Satu topik per PR.** Jangan campur perbaikan bug, perubahan tampilan, dan perubahan pipeline CI
  dalam satu PR — PR seperti itu sulit ditinjau dan sering ditolak.
- Sertakan **test** untuk perilaku yang diubah. Repo ini menjalankan PHPUnit dan Vitest; PR tanpa
  test hanya diterima untuk perubahan dokumentasi atau teks.
- Jangan mengubah berkas workflow GitHub Actions kecuali PR-nya memang tentang itu. Workflow baru
  yang membaca secrets tidak akan digabung dari kontributor luar.
- Jangan menghapus atribusi pemilik/kopyright di footer, PDF, atau notifikasi. Lisensi MIT
  mewajibkan copyright notice dipertahankan.

## Menjalankan di lokal

```bash
composer install
npm ci
cp .env.example .env
php artisan key:generate

npm run build            # WAJIB sebelum test — tanpa manifest Vite, test halaman gagal
bash scripts/test.sh     # PHPUnit (sqlite in-memory)
npm test                 # Vitest (komponen Vue di tests/js/)
./vendor/bin/pint        # gaya kode PHP
```

Test PHPUnit **hanya** lewat `scripts/test.sh` (atau `composer test`). Skrip itu mengalihkan cache
config/rute ke path yang tidak ada sehingga test memakai sqlite; `php artisan test` polos pada
instalasi produksi bisa menyentuh database sungguhan.

## Konvensi kode

- PHP 8.3, gaya Laravel + Pint. Migrasi **harus kompatibel sqlite** (test berjalan di
  `sqlite::memory:`), jadi hindari sintaks khusus PostgreSQL/MySQL di migrasi.
- Vue 3 + Inertia; ikuti komponen dan pola yang sudah ada di folder terdekat.
- Semua teks yang dilihat pengguna harus lewat i18n: `lang/{id,en}/*.php` untuk backend dan
  `resources/js/lang/{id,en}.json` untuk frontend. Jangan menaruh string satu bahasa langsung di
  template.
- Komentar dan pesan commit dalam bahasa Indonesia atau Inggris, keduanya diterima.
- Jangan menambah dependensi baru tanpa alasan kuat — jelaskan di deskripsi PR.

## Pull request

1. Fork, buat branch bertema (`fix/hioso-port`, `feat/...`), lalu ajukan PR ke `main`.
2. Isi deskripsi: masalahnya apa, apa yang diubah, dan bagaimana Anda mengujinya (tempel ringkasan
   hasil test).
3. Pastikan `bash scripts/test.sh`, `npm test`, dan `pint` bersih.
4. Kalau PR mengubah tampilan, sertakan tangkapan layar sebelum/sesudah.
5. Review bisa meminta perubahan; mohon tanggapi di thread yang sama, jangan membuka PR baru.

Kontribusi yang digabung akan dicatat di [`CONTRIBUTORS.md`](CONTRIBUTORS.md).

## Lisensi

Dengan mengirim kontribusi, Anda menyetujui kontribusi tersebut dilisensikan di bawah
[Lisensi MIT](LICENSE) yang berlaku di repo ini.