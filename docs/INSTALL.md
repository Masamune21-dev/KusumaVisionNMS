# Panduan Instalasi KusumaVision NMS

Dokumen ini adalah **titik awal** instalasi. Ikuti 3 langkah: **(1)** pilih jalur yang cocok untuk
Anda, **(2)** pastikan spek minimum terpenuhi, **(3)** ikuti langkah jalur pilihan. Setiap jalur
menaut ke panduan detailnya.

> Ingin memasang **aplikasi Android (APK)**? Itu terpisah dari server — lihat
> **[docs/BUILD_APK.md](BUILD_APK.md)**.

---

## 1. Pilih jalur instalasi

Ada 3 jalur. Pilih **satu** sesuai kondisi Anda:

| Kondisi Anda | Jalur | Ringkas |
|---|---|---|
| Pakai **Windows / macOS**, atau ingin pasang lengkap di **1 PC** untuk dibagikan ke banyak lokasi (seperti NetNumen) | **A — Docker appliance** | Install Docker Desktop → `start.bat` / `./start.sh` → buka `http://localhost:8080` |
| Punya **server / VPS Ubuntu kosong** (22.04 / 24.04) dan mau produksi permanen | **B — `install.sh`** | `sudo bash install.sh` — pasang semuanya otomatis dalam 1 perintah |
| **Developer** yang butuh kontrol penuh / ngoprek kode | **C — Manual (dev)** | Ikuti langkah manual di handbook |

**Bingung?** Aturan sederhana:
- **Windows/macOS** atau mau cepat & portable → **Jalur A (Docker)**.
- **Server Linux untuk produksi** → **Jalur B (`install.sh`)**.
- **Ngoprek/kembangkan kode** → **Jalur C (manual)**.

---

## 2. Minimum spek

Sesuaikan dengan jalur yang Anda pilih. Angka "minimum" = bisa jalan; "disarankan" = nyaman untuk
puluhan OLT + banyak ONU.

### 2a. Menjalankan aplikasi web

| Sumber daya | Minimum | Disarankan | Catatan |
|---|---|---|---|
| **CPU** | 2 core | 4 core | Poller SNMP + worker antrean |
| **RAM** | 2 GB (Docker: 4 GB) | 4–8 GB | PostgreSQL + Redis + PHP-FPM + Go poller + worker |
| **Disk** | 15 GB | 25 GB+ | OS + runtime + DB (tumbuh mengikuti jumlah OLT/ONU & log) |
| **OS** | Ubuntu 22.04/24.04 (Jalur B) · Windows 10/11 / macOS / Linux + Docker (Jalur A) | — | — |
| **Jaringan** | Bisa menjangkau OLT: **SNMP UDP/161** + **Telnet TCP/23** | — | Server & OLT idealnya satu jaringan/VPN |

> **Skala kasar:** ~2 GB RAM cukup untuk belasan OLT. Untuk 30+ OLT dengan polling rapat + banyak
> ONU, naikkan ke 4–8 GB RAM dan 4 core.

### 2b. Membangun (build) APK Android

Build APK **berat** (butuh Flutter + Android SDK + JDK). Detail & langkah: **[BUILD_APK.md](BUILD_APK.md)**.
Ringkasnya:

| Sumber daya | Minimum | Disarankan |
|---|---|---|
| **RAM** | 8 GB (di bawah ini Gradle swap-thrash) | 16 GB |
| **Disk kosong** | 15 GB (toolchain ~5,4 GB + cache Gradle + output) | 20 GB+ |
| **CPU** | 2 core | 4 core+ |

> **Penting:** membangun APK **tidak wajib**. Kalau hanya ingin memakai aplikasi Android, cukup
> **unduh APK jadi** yang sudah dibuild (lihat [BUILD_APK.md §Install di HP](BUILD_APK.md)). Build
> sendiri hanya perlu jika Anda mengubah kode aplikasi mobile.

---

## 3. Jalur A — Docker appliance (Windows / macOS / Linux)

Seluruh stack (web, PostgreSQL, Redis, poller Go, worker, scheduler, telnet-proxy) berjalan sebagai
container. Data tersimpan permanen di volume. **Paling mudah & portabel.**

1. **Pasang Docker**
   - Windows / macOS: **[Docker Desktop](https://www.docker.com/products/docker-desktop/)** (pastikan status **Running**).
     - Windows perlu **WSL 2** aktif (virtualisasi ON di BIOS + `wsl --update`).
   - Linux: **Docker Engine** + plugin compose (`docker compose version` harus jalan).
2. **Ambil folder proyek** (git clone atau salin dari flashdisk).
3. **Jalankan:**
   - **Windows:** double-click **`start.bat`**.
   - **Linux / macOS:** `./start.sh`
   - **Manual (semua OS):**
     ```bash
     cp .env.docker.example .env      # sekali; edit DB_PASSWORD & ADMIN_* (lihat DOCKER.md §4)
     docker compose up -d --build     # pertama kali agak lama
     ```
4. Buka **<http://localhost:8080>**. Login pakai admin yang diisi di `.env` (atau buat manual, lihat §5).

📖 **Panduan lengkap** (backup, update, distribusi image prebuilt tanpa source, troubleshooting
Windows CRLF/WSL): **[docs/DOCKER.md](DOCKER.md)**.

---

## 4. Jalur B — `install.sh` (server Ubuntu kosong)

Satu perintah memasang **seluruh** runtime (PHP 8.3, Composer, Node 22, PostgreSQL, Redis, Nginx,
Supervisor, Go, Net-SNMP), membuat database + `.env`, build frontend & Go poller, migrasi, lalu
mendaftarkan daemon Supervisor + Nginx site, dan opsional membuat akun admin.

```bash
cd /var/www
sudo mkdir -p KusumaVisionNMS
sudo chown "$USER:$USER" KusumaVisionNMS
git clone https://github.com/Masamune21-dev/KusumaVisionNMS.git KusumaVisionNMS
cd KusumaVisionNMS

sudo bash install.sh                 # interaktif — ditanya APP_URL, DB, akun admin
```

Atau **non-interaktif** (isi via environment variable):

```bash
sudo APP_URL=http://nms.example.com \
     ADMIN_EMAIL=admin@bmkv.net ADMIN_PASSWORD='GANTI_DENGAN_PASSWORD_KUAT' \
     ENABLE_UFW=1 bash install.sh --yes
```

- Skrip **aman dijalankan ulang** (idempotent). Opsi lain: `sudo bash install.sh --help`.
- Verifikasi kapan saja: `bash scripts/check-requirements.sh`.
- Di akhir, skrip mencetak **password database** yang digenerate — **simpan**.

📖 Detail tiap langkah + gotcha config cache: **[handbook 04 — Instalasi & Deploy](handbook/04-instalasi-deploy.md)**.
Hardening OS/Nginx/UFW/SSH: **[LOCAL_PRODUCTION_HARDENING.md](LOCAL_PRODUCTION_HARDENING.md)**.

---

## 5. Jalur C — Manual (dev / kontrol penuh)

Untuk lingkungan pengembangan atau bila ingin memahami tiap langkah. Ringkas:

```bash
# 1. Dependensi
composer install
npm install

# 2. Environment
cp .env.example .env
php artisan key:generate

# 3. Sesuaikan .env (DB PostgreSQL, Redis) — lihat handbook 04

# 4. Database
php artisan migrate

# 5. Buat admin (registrasi publik dimatikan). --role=admin wajib (bawaan operator);
#    password minimal 8 karakter.
php artisan user:create --name="Admin" --email=admin@example.com --password=passwordkuat --role=admin

# 6. Jalankan (semua sekaligus)
composer dev            # serve + queue + logs + vite
```

📖 Langkah lengkap (Supervisor, Nginx, telnet-proxy, harden ke production): **[handbook 04](handbook/04-instalasi-deploy.md)**.

---

## 6. Setelah instalasi (semua jalur)

- **Buat/masuk admin.** Jika belum punya akun (Jalur A/B tanpa `ADMIN_*`), buat:
  - Docker: `docker compose exec app php artisan user:create --name="Admin" --email=admin@bmkv.net --password=passwordkuat --role=admin`
  - Ubuntu/manual: `php artisan user:create --name="Admin" --email=admin@bmkv.net --password=passwordkuat --role=admin`
  - Tanpa `--role=admin` akun dibuat sebagai **operator** (tak bisa membuka Pengaturan & Users). Password
    minimal 8 karakter. Akun yang terlanjur operator dinaikkan dari menu **Users** oleh admin lain, atau
    bila belum ada admin sama sekali:
    `sudo -u postgres psql -d kusumavision_nms -c "UPDATE users SET role='admin' WHERE email='admin@bmkv.net';"`
    (Docker: `docker compose exec db psql -U kusumavision -d kusumavision_nms -c "…"`).
- **Cek sehat.** `bash scripts/check-requirements.sh` (Jalur B/C) atau `docker compose ps` (Jalur A).
- **Tambah OLT pertama.** Login → menu **SmartOLT** → tambah OLT → **Test SNMP**.
- **Opsional:** notifikasi Telegram, ACS/TR069, token API, push FCM mobile — semua dari menu
  **Pengaturan** (admin). Lihat [README.md](../README.md).

---

## 7. Aplikasi Android (APK)

Aplikasi Android **mengonsumsi REST API v1** server ini, jadi server harus jalan lebih dulu dan
API v1 diaktifkan (Pengaturan → API & Token). Dua pilihan:

- **Cuma mau pakai** → unduh **APK jadi** dan sideload ke HP. Lihat **[BUILD_APK.md → Install di HP](BUILD_APK.md)**.
- **Mau build sendiri** (setelah ubah kode mobile) → ikuti **[BUILD_APK.md](BUILD_APK.md)** (install
  toolchain Flutter/SDK/JDK di Linux atau Windows + spek + signing).

---

## 8. Troubleshooting cepat

| Gejala | Lihat |
|---|---|
| Docker: port bentrok / container restart / CRLF di Windows | [DOCKER.md §9](DOCKER.md) |
| Site 500 setelah ubah `.env`/config | [handbook 04 — Gotcha config cache](handbook/04-instalasi-deploy.md) |
| Polling tak jalan / data tak ter-refresh | Pastikan daemon `queue`/`scheduler` aktif ([handbook 13](handbook/13-troubleshooting-maintenance.md)) |
| Build APK gagal (RAM/Gradle/lisensi) | [BUILD_APK.md §Troubleshooting](BUILD_APK.md) |
| Cek requirement kurang apa | `bash scripts/check-requirements.sh` |

---

## 9. Update ke versi baru

**Docker:** `git pull` lalu `docker compose up -d --build` (atau `update.bat`) — migrasi & build jalan
otomatis. Lihat [DOCKER.md §7](DOCKER.md).

**Server `install.sh` / manual** — dari folder proyek, dengan pengguna yang sama seperti saat instalasi:

```bash
git pull
composer install --no-dev --optimize-autoloader --no-interaction
npm ci && npm run build
CGO_ENABLED=0 go build -mod=mod -trimpath -ldflags='-s -w' -o bin/kv-snmp-poller ./cmd/kv-snmp-poller
php artisan migrate --force
php artisan optimize:clear && php artisan optimize
php artisan queue:restart
sudo supervisorctl restart kusumavision-telnet-proxy
```

Lalu muat ulang browser dengan Ctrl+Shift+R (nama berkas aset berubah).

### Catatan pembaruan September 2026

- **Di belakang Cloudflare "Flexible" atau load balancer di host lain? Isi `TRUSTED_PROXIES`** di `.env`
  (IP/CIDR proxy, atau `*` bila origin tak bisa diakses langsung), lalu `php artisan config:cache`.
  Bawaannya kini hanya localhost; tanpa itu skema https tak terdeteksi dan login gagal 419. Docker
  dengan reverse proxy di host: lihat `.env.docker.example`. Instalasi `install.sh` (nginx + certbot di
  host yang sama) tidak terdampak.
- **Riwayat RX kini diringkas per jam** (tabel baru `onu_rx_hourly`). Scheduler mengejar riwayat lama
  48 jam per jalan; untuk langsung lengkap jalankan sekali `php artisan optical:aggregate-rx --hours=720`.
  Sampel mentah kini disimpan 3 hari (`SNMP_POLLER_RX_RETENTION_DAYS`).
- **Dua tema** (Gelap/Terang/Ikuti sistem) — tanpa memilih, tampilan tetap gelap.
- **Titik awal peta** bisa diarahkan ke wilayah kerja utama lewat `MAP_HOME_LAT`/`MAP_HOME_LNG` (opsional).
- **Aplikasi Android 1.8.5** — bangun APK baru ([BUILD_APK.md](BUILD_APK.md)); APK lama tetap bisa login.
- Opsional untuk HTTPS: `SESSION_SECURE_COOKIE=true`. Token aplikasi kini kedaluwarsa setelah
  `SANCTUM_EXPIRATION` menit (contoh 43200 = 30 hari).

---

## Referensi

- **[README.md](../README.md)** — ikhtisar fitur & stack.
- **[docs/DOCKER.md](DOCKER.md)** — Docker appliance lengkap.
- **[handbook 04](handbook/04-instalasi-deploy.md)** — instalasi & deploy teknis.
- **[docs/BUILD_APK.md](BUILD_APK.md)** — build & install aplikasi Android.
- **[LOCAL_PRODUCTION_HARDENING.md](LOCAL_PRODUCTION_HARDENING.md)** — hardening produksi.
