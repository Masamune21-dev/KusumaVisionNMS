# KusumaVision NMS

**Unified FTTH Network Management Platform** — PT Berkah Media Kusuma Vision (BMKV).

Platform manajemen jaringan FTTH berbasis web untuk mengelola OLT GPON **ZTE C300/C320/C600 (ZXA10)**: monitoring OLT/ONU, provisioning ONU, remote management, background polling, alarm engine, dan dashboard. Dibangun sebagai alternatif modern untuk SmartOLT/NetNumen bagi ISP FTTH di Indonesia.

> Riwayat pengembangan per fase: [`WORKLOG.md`](WORKLOG.md).

---

## Fitur

- **Inventory OLT** — CRUD OLT, uji koneksi SNMP, kredensial tersimpan terenkripsi.
- **Monitoring** — GPON port (up/down), ONU per port (online/offline, phase state, RX power via SNMP), search ONU langsung dari halaman Detail.
- **Discovery ONU unconfigured** — temukan ONU baru via OID ZTE, langsung ke form provisioning.
- **Provisioning ONU** — VLAN, T-CONT, PPPoE/DHCP/Static, TR069, Remote ONT; tersimpan sebagai audit log lalu dieksekusi via Telnet.
- **Detail ONU (CLI)** — baca `show gpon onu detail-info` + `show pon power attenuation`, divisualisasikan: gauge RX power berzona warna, bar atenuasi up/down, chip metrik optik (temperature/voltage/bias), status & last-event.
- **Configure ONU (CLI)** — reconfigure ONU existing dari live running-config dengan **delta script** (hanya baris yang berubah), preview live + panel *what will change*, lalu apply via Telnet (audit `reconfigured`/`reconfig_failed`).
- **Manajemen Profile** — ONU Type / T-CONT / VLAN / IP per-OLT, sinkronisasi langsung dari OLT.
- **Remote ONU Management** — reboot (CLI), enable/disable & edit nama/deskripsi (SNMP SET).
- **Background polling** — interval poll per-OLT yang dapat dikonfigurasi (default 5 menit), RX power di-poll pada interval terpisah.
- **Alarm engine** — siklus raise/clear untuk `olt_unreachable`, `port_down`, `los`, `onu_offline`, `dying_gasp`, `high_rx_attenuation`.
- **Dashboard** — ringkasan OLT/ONU/alarm dengan grafik (ApexCharts).

---

## Stack Teknologi

| Lapisan | Teknologi |
|---|---|
| Backend | Laravel 12 (PHP 8.3), Inertia.js |
| Frontend | Vue 3 + Inertia, TailwindCSS, ApexCharts |
| Database | PostgreSQL |
| Cache / Queue / Session | Redis |
| Web Server | Nginx + PHP-FPM 8.3 |
| Akses OLT | SNMP v1/v2c (read & write), CLI Telnet |
| SNMP Poller (opsional) | Go 1.18+ — binary `bin/kv-snmp-poller` |

---

## Persyaratan

- PHP **8.3** + PHP-FPM 8.3
- Composer, Node.js **22**, npm
- PostgreSQL, Redis
- Nginx
- UFW / firewall host
- Ekstensi PHP: `bcmath curl dom intl mbstring openssl pcntl pdo_pgsql pdo_sqlite redis snmp sockets xml zip`
- Go **1.18+** (opsional — diperlukan hanya jika ingin build binary Go SNMP poller)

---

## Instalasi (Ubuntu 22.04)

### Langkah 1 — Clone repo

```bash
cd /var/www
git clone git@github.com:Masamune21-dev/KusumaVisionNMS.git KusumaVisionNMS
cd KusumaVisionNMS
```

### Langkah 2 — Cek requirement

Setelah clone, jalankan script cek requirement yang sudah tersedia:

```bash
bash scripts/check-requirements.sh
```

Script ini memeriksa PHP, Composer, Node.js, npm, PostgreSQL, Redis, dan semua ekstensi PHP yang dibutuhkan. Output `[OK]` berarti tersedia, `[MISS]` berarti perlu diinstall.

**Jika ada yang `[MISS]`, install dulu:**

```bash
# Update package list
apt update

# Nginx + PHP 8.3 + semua ekstensi
apt install -y nginx php8.3 php8.3-fpm php8.3-cli \
    php8.3-bcmath php8.3-curl php8.3-dom php8.3-intl \
    php8.3-mbstring php8.3-pgsql php8.3-redis php8.3-snmp \
    php8.3-xml php8.3-zip php8.3-sqlite3 \
    postgresql redis-server curl unzip git supervisor ufw

# Composer
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Node.js 22
curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
apt install -y nodejs
```

Setelah install, jalankan ulang script untuk konfirmasi semua sudah `[OK]`:

```bash
bash scripts/check-requirements.sh
```

### Langkah 3 — Siapkan database PostgreSQL

```bash
su - postgres -c "psql -c \"CREATE USER kusumavision WITH PASSWORD 'ganti_password_ini';\""
su - postgres -c "psql -c \"CREATE DATABASE kusumavision_nms OWNER kusumavision;\""
```

### Langkah 4 — Konfigurasi environment

```bash
cp .env.example .env
```

Edit `.env` sesuai server:

```dotenv
APP_NAME="KusumaVision NMS"
APP_ENV=production
APP_DEBUG=false
APP_URL=http://ip-server-anda
LOG_LEVEL=warning

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=kusumavision_nms
DB_USERNAME=kusumavision
DB_PASSWORD=ganti_password_ini

CACHE_STORE=redis
SESSION_DRIVER=redis
SESSION_ENCRYPT=true
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

### Langkah 5 — Setup aplikasi

Satu perintah menangani seluruh setup: install dependensi PHP & JS, generate key, migrasi database, dan build aset:

```bash
composer setup
```

Perintah ini menjalankan secara berurutan:
1. `composer install`
2. Salin `.env.example` → `.env` (jika belum ada)
3. `php artisan key:generate`
4. `php artisan migrate --force`
5. `npm install`
6. `npm run build`

Setelah selesai, set permission storage:

```bash
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
php artisan storage:link
php artisan optimize
```

### Langkah 6 — Queue Worker (Supervisor)

```bash
nano /etc/supervisor/conf.d/kusumavision-worker.conf
```

Isi dengan:

```ini
[program:kusumavision-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/KusumaVisionNMS/artisan queue:work redis --tries=1 --timeout=120 --sleep=3
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/supervisor/kusumavision-worker.log
stopwaitsecs=120
```

```bash
supervisorctl reread && supervisorctl update && supervisorctl start kusumavision-worker:*
```

### Langkah 7 — Laravel Scheduler (Supervisor)

Rekomendasi production lokal adalah menjalankan scheduler via Supervisor agar mudah dipantau:

```ini
[program:kusumavision-scheduler]
command=php /var/www/KusumaVisionNMS/artisan schedule:work
directory=/var/www/KusumaVisionNMS
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/KusumaVisionNMS/storage/logs/scheduler.log
```

```bash
supervisorctl reread && supervisorctl update && supervisorctl start kusumavision-scheduler
```

> Scheduler menjalankan `olts:poll` setiap menit. Setiap OLT hanya benar-benar di-poll sesuai interval masing-masing (`poll_interval_minutes`).

Alternatif cron tetap bisa dipakai:

```cron
* * * * * php /var/www/KusumaVisionNMS/artisan schedule:run >> /dev/null 2>&1
```

### Langkah 8 — Buat akun pertama

Registrasi publik dinonaktifkan. Buat user pertama lewat Artisan:

```bash
php artisan user:create --name="Admin BMKV" --email="admin@bmkv.net" --password="P@ssw0rd123"
```

---

## Instalasi Go SNMP Poller (opsional)

> Lewati jika ingin tetap menggunakan PHP poller (default).
> Karena repo Laravel memiliki folder `vendor/`, jalankan command Go dengan `-mod=mod`.

```bash
# Install Go 1.22
wget https://go.dev/dl/go1.22.5.linux-amd64.tar.gz
tar -C /usr/local -xzf go1.22.5.linux-amd64.tar.gz
echo 'export PATH=$PATH:/usr/local/go/bin' >> /etc/profile.d/go.sh
source /etc/profile.d/go.sh

# Build binary
go mod download
go build -mod=mod -o bin/kv-snmp-poller ./cmd/kv-snmp-poller
chmod +x bin/kv-snmp-poller
```

Aktifkan di `.env`:

```dotenv
SNMP_POLLER_DRIVER=go
SNMP_POLLER_BINARY=bin/kv-snmp-poller
```

> Jika binary tidak ditemukan atau tidak executable, sistem otomatis fallback ke PHP poller.

---

## Menjalankan di Development

```bash
composer dev
```

Menjalankan server + queue + log + Vite secara paralel. Atau pisah per proses:

```bash
php artisan serve --host=0.0.0.0 --port=8000
npm run dev
php artisan queue:work
php artisan schedule:work
```

---

## Perintah Berguna

```bash
php artisan olts:poll             # dispatch poll semua OLT yang sudah due sekarang
php artisan config:clear --ansi   # clear config sebelum test
php artisan test                  # jalankan test suite (SQLite in-memory)
php artisan optimize              # cache config/routes/views untuk production
./vendor/bin/pint                 # format kode PHP
npm run build                     # build aset produksi
composer audit                    # audit advisory Composer
npm audit --omit=dev              # audit dependency runtime frontend
```

## Production Lokal & Hardening

Panduan hardening OS/aplikasi tersedia di [`docs/LOCAL_PRODUCTION_HARDENING.md`](docs/LOCAL_PRODUCTION_HARDENING.md).

Ringkasan konfigurasi production lokal yang direkomendasikan:

- Laravel: `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_ENCRYPT=true`, `php artisan optimize`.
- File secret: `.env` tidak masuk Git dan permission `640 root:www-data`.
- Nginx: root harus ke `public/`, deny dotfiles/file sensitif, security headers aktif, allow-list IP dibatasi.
- SSH: key-only (`PasswordAuthentication no`), root hanya via key (`PermitRootLogin prohibit-password`), X11 forwarding off.
- UFW: default deny incoming, allow outgoing, buka hanya port yang diperlukan dari subnet tepercaya.
- Queue/scheduler: jalankan via Supervisor dan pastikan `queue:work` serta `schedule:work` aktif.

---

## Catatan & Batasan

- **Vendor:** ZTE C300/C320 dan C600 (ZXA10). OLT C600 terdeteksi otomatis dari nama/vendor mengandung `"c600"` dan menggunakan OID `.1082` serta interface 4-tier. OLT non-ZTE terdeteksi sebagai `unknown` dengan kapabilitas dimatikan.
- **SNMP:** v1/v2c saja (v3 belum didukung). Fitur enable/disable & edit info ONU butuh **write community** terisi.
- **CLI:** eksekusi provisioning/reboot hanya via **Telnet** (`cli_transport=telnet`).
- **Poll interval:** per-OLT, dapat diubah di form Edit OLT. Default 5 menit untuk polling SNMP, 5 menit untuk RX power.
- Dashboard & alarm seakurat poll terakhir — pastikan queue worker dan cron scheduler aktif.

---

## Lisensi

Proprietary — PT Berkah Media Kusuma Vision (BMKV).
