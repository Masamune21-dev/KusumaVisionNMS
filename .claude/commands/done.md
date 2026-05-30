# done — Selesai, update WORKLOG dan push ke GitHub

Jalankan langkah-langkah berikut secara berurutan setiap kali dipanggil:

## 1. Audit perubahan

Jalankan perintah berikut untuk melihat semua perubahan sejak commit terakhir:
- `git diff HEAD` — diff lengkap semua file yang berubah
- `git status` — file untracked baru
- `git log --oneline -5` — 5 commit terakhir untuk konteks

## 2. Tulis entri WORKLOG

Buka `WORKLOG.md`. Tambahkan entri baru di **bagian paling bawah** file mengikuti format berikut persis:

```
### <Judul Singkat Perubahan>

Created:

- `path/ke/file.php` — deskripsi singkat (jika ada file baru)

Changed:

- `path/ke/file.vue` — deskripsi singkat apa yang diubah dan mengapa

Notes:

- Catatan penting tentang keputusan desain, edge case, atau hal yang diverifikasi di OLT nyata.
```

Aturan penulisan WORKLOG:
- Jika tidak ada tanggal hari ini di WORKLOG, tambahkan heading `## YYYY-MM-DD` (gunakan tanggal hari ini) sebelum entry baru
- Jika sudah ada tanggal hari ini, langsung tambahkan entry baru di bawah entry terakhir hari itu
- Tulis dalam bahasa Indonesia
- Jangan tulis ulang entry yang sudah ada
- Hanya catat file yang benar-benar berubah dalam sesi ini
- Jika hanya ada perubahan minor UI (tweak), cukup satu baris di Changed

## 3. Commit semua perubahan

Stage semua perubahan yang relevan (termasuk WORKLOG.md yang baru diupdate), lalu buat commit dengan format pesan:

```
<tipe>: <deskripsi singkat dalam bahasa Inggris>

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
```

Tipe commit: `feat` (fitur baru), `fix` (perbaikan bug), `chore` (refactor/konsistensi), `ui` (perubahan tampilan), `docs` (dokumentasi)

> Jika perubahan menyentuh struktur, konvensi, perintah, atau alur deploy proyek, pastikan `CLAUDE.md` dan `docs/handbook/` ikut disinkronkan sebelum commit.

## 4. Push ke GitHub

```bash
git push origin main
```

Setelah push berhasil, tampilkan ringkasan:
- Nama commit dan hash
- File yang di-commit
- Konfirmasi push berhasil
