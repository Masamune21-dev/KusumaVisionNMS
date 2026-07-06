# KusumaVision NMS — Instalasi via Docker (appliance 1 PC)

Panduan memasang KusumaVision NMS **lengkap di satu PC** (Windows / Linux / macOS) memakai Docker —
mirip cara NetNumen dipasang di satu mesin. Seluruh komponen (web, database, cache, poller SNMP,
worker, scheduler, telnet proxy) berjalan sebagai container; **data tersimpan permanen** dan aman
antar restart. Cocok dibagikan ke banyak lokasi.

> Ingin memasang langsung ke server Ubuntu tanpa Docker? Pakai `install.sh` (lihat
> [handbook 04](handbook/04-instalasi-deploy.md)). Dokumen ini khusus jalur Docker.

---

## 1. Prasyarat

- **Docker** terpasang & berjalan:
  - Windows/macOS: **Docker Desktop** (<https://www.docker.com/products/docker-desktop/>).
  - Linux: **Docker Engine** + plugin **compose** (`docker compose version` harus jalan).
- Koneksi jaringan dari PC ke OLT (SNMP UDP/161 & telnet TCP/23). Container memakai jaringan PC
  (NAT) untuk menjangkau OLT — tidak perlu setelan khusus.
- RAM ± 2 GB bebas.

Cek: `docker --version` dan `docker compose version` menampilkan versi.

---

## 2. Menjalankan (mode source — paling umum)

Dari folder proyek:

**Windows** — double-click **`start.bat`**.

**Linux / macOS**:
```bash
./start.sh
```

**Manual (semua OS)**:
```bash
cp .env.docker.example .env      # sekali; lalu edit DB_PASSWORD & admin (lihat §4)
docker compose up -d --build     # build image + jalankan (pertama kali agak lama)
```

Setelah container sehat, buka **<http://localhost:8080>** (atau port sesuai `APP_PORT` di `.env`).

Cek status:
```bash
docker compose ps          # semua service "running/healthy"
docker compose logs -f app # log app (php-fpm, nginx, worker, scheduler, telnet-proxy)
```

Pertama kali, `app` menunggu DB siap → migrasi → build cache → baru web hidup (lihat log).

---

## 3. Yang terjadi otomatis saat pertama start

`docker/entrypoint.sh` menyiapkan semuanya (idempotent, aman diulang):

1. Membuat skeleton `storage/` + izin.
2. Menunggu PostgreSQL siap.
3. **APP_KEY** — bila `.env` mengosongkannya, digenerate sekali lalu disimpan permanen di volume
   (`storage/app/.appkey`). Key stabil antar restart → data terenkripsi tetap terbaca.
4. `php artisan migrate --force`.
5. Membuat admin awal bila `ADMIN_EMAIL`/`ADMIN_PASSWORD` diisi & tabel users masih kosong (§4).
6. `storage:link` + `php artisan optimize` (cache config/route/view produksi).
7. Menyalakan php-fpm, nginx, queue worker, scheduler, telnet-proxy via supervisor.

---

## 4. Membuat akun admin

**Cara A — otomatis saat instalasi pertama.** Sebelum start pertama, isi di `.env`:
```
ADMIN_NAME=Administrator
ADMIN_EMAIL=admin@bmkv.net
ADMIN_PASSWORD=passwordkuat
```
Admin dibuat otomatis (role `admin`) hanya jika belum ada user.

**Cara B — manual, kapan saja:**
```bash
docker compose exec app php artisan user:create --name="Admin" --email=admin@bmkv.net --password=passwordkuat
```

---

## 5. Lokasi data & backup

Data hidup di **named volume** Docker (bukan di folder proyek), jadi aman walau container dihapus:

| Volume | Isi |
|--------|-----|
| `pgdata` | Database PostgreSQL |
| `redisdata` | Cache / session / antrean job |
| `app_storage` | Upload, log, `APP_KEY` |

**Backup database:**
```bash
docker compose exec -T db pg_dump -U kusumavision kusumavision_nms > backup.sql
```
**Restore:**
```bash
docker compose exec -T db psql -U kusumavision -d kusumavision_nms < backup.sql
```

---

## 6. Operasi harian

```bash
docker compose down          # hentikan (data tetap aman)   — atau stop.bat
docker compose up -d         # nyalakan lagi
docker compose restart app   # restart hanya app (mis. setelah ubah .env)
docker compose logs -f app   # pantau log
```

Setelah mengubah `.env`, jalankan `docker compose up -d` (recreate app) agar env baru terpakai —
entrypoint akan membangun ulang cache config otomatis.

---

## 7. Update ke versi kode baru (mode source)

```bash
git pull        # ambil kode terbaru
docker compose up -d --build   # rebuild + jalankan; migrasi jalan otomatis
```
Di Windows bisa pakai **`update.bat`**. Data tetap aman di volume.

---

## 8. Membagikan ke PC lain

**Opsi A — bawa source (butuh folder proyek).** Salin folder proyek → di PC tujuan jalankan
`start.bat` / `./start.sh`. Docker akan build sendiri. Paling sederhana.

**Opsi B — image prebuilt tanpa source (sembunyikan kode, cocok lisensi proprietary).**
Di PC pembuat:
```bash
docker build -t kusumavision/nms:latest .
docker save kusumavision/nms:latest | gzip > kusumavision-nms-image.tar.gz
```
Kirim **`kusumavision-nms-image.tar.gz`** + `docker-compose.yml` + `.env.docker.example`. Di PC tujuan:
```bash
docker load < kusumavision-nms-image.tar.gz
cp .env.docker.example .env      # edit password/admin
docker compose up -d             # TANPA --build → pakai image yang sudah di-load
```
(Compose memakai image `kusumavision/nms:latest` yang sudah ada; bagian `build:` diabaikan karena
image sudah tersedia dan tidak diminta rebuild.)

---

## 9. Troubleshooting

| Gejala | Sebab / solusi |
|--------|----------------|
| `port is already allocated` | Port 8080 dipakai app lain. Ubah `APP_PORT` di `.env` lalu `docker compose up -d`. |
| Web tak kebuka, `app` restart terus | `docker compose logs app` — cek error migrasi/APP_KEY/DB. Pastikan `db` `healthy` dulu. |
| `docker compose` tidak dikenal | Docker Desktop belum jalan / plugin compose belum ada. |
| Lupa password admin | `docker compose exec app php artisan user:create ...` buat user baru, atau reset via tinker. |
| Ganti PC tapi ingin bawa data | Backup (`pg_dump`) di §5, restore di PC baru. |
| Ingin mulai bersih total | `docker compose down -v` (⚠️ **menghapus semua data volume**). |
| **Windows:** build gagal saat unduh, pesan `gzip`/`unexpected EOF`/`failed to register layer` | Layer image korup karena koneksi putus saat mengunduh base image (node/go/php ± 2 GB). **Ulang** `docker compose build --pull` (Docker melanjutkan dari layer yang sudah ada). Kalau jaringan tak stabil: build **sekali** di PC berjaringan bagus lalu distribusi via **image prebuilt** (§8 Opsi B). |
| **Windows:** container `app` langsung exit / `entrypoint.sh: no such file or directory` / `\r: command not found` | Script terkena **CRLF** (diedit di Notepad atau di-zip ulang di Windows). Image kini menormalkan CRLF saat build — cukup **build ulang** (`docker compose up -d --build`). Simpan `*.sh`/`Dockerfile`/`.env` sebagai **LF**. |
| **Windows:** login gagal / 500 padahal DB sehat, setelah edit `.env` di Notepad | `.env` tersimpan **CRLF** → nilai (mis. `APP_KEY`) berakhiran `\r`. Entrypoint kini membersihkan CR pada var app, tapi paling aman: simpan `.env` sebagai **LF** (VS Code: klik `CRLF`→`LF` kanan-bawah), atau kosongkan `APP_KEY` agar digenerate otomatis, lalu `docker compose up -d`. |
| **Windows:** Docker Desktop tak mau start / error **WSL 2** | Aktifkan virtualisasi di BIOS + fitur *Virtual Machine Platform*/*WSL*, jalankan `wsl --update`, restart. Docker Desktop harus berstatus **Running** sebelum `start.bat`. |

Referensi arsitektur container: [handbook 18 — Docker Appliance](handbook/18-docker-appliance.md).
