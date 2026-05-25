# KusumaVision NMS

**Unified FTTH Network Management Platform** — PT Berkah Media Kusuma Vision (BMKV).

Platform manajemen jaringan FTTH berbasis web untuk mengelola OLT GPON **ZTE C300/C320 (ZXA10)**: monitoring OLT/ONU, provisioning ONU, remote management, background polling, alarm engine, dan dashboard. Dibangun sebagai alternatif modern untuk SmartOLT/NetNumen bagi ISP FTTH di Indonesia.

> Riwayat pengembangan per fase: [`WORKLOG.md`](WORKLOG.md).

---

## Fitur

- **Inventory OLT** — CRUD OLT, uji koneksi SNMP, kredensial tersimpan terenkripsi.
- **Monitoring** — GPON port (up/down), ONU per port (online/offline, phase state, RX power via SNMP), search ONU langsung dari halaman Detail.
- **Discovery ONU unconfigured** — temukan ONU baru via OID ZTE, langsung ke form provisioning.
- **Provisioning ONU** — VLAN, T-CONT, PPPoE/DHCP/Static, TR069, Remote ONT; tersimpan sebagai audit log lalu dieksekusi via Telnet.
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
- Ekstensi PHP: `bcmath curl dom intl mbstring openssl pcntl pdo_pgsql pdo_sqlite redis snmp sockets xml zip`
- Go **1.18+** (opsional — diperlukan hanya jika ingin build binary Go SNMP poller)

---

## Instalasi Lengkap (Ubuntu 22.04)

### 1. Instalasi paket sistem

```bash
# Update & install dependensi
apt update && apt upgrade -y

# Nginx + PHP 8.3 + FPM + ekstensi
apt install -y nginx php8.3 php8.3-fpm php8.3-cli \
    php8.3-bcmath php8.3-curl php8.3-dom php8.3-intl \
    php8.3-mbstring php8.3-pgsql php8.3-redis php8.3-snmp \
    php8.3-xml php8.3-zip php8.3-sqlite3 \
    postgresql redis-server curl unzip git

# Composer
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Node.js 22
curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
apt install -y nodejs
```

### 2. Instalasi Go & Build SNMP Poller (opsional)

> Lewati langkah ini jika ingin tetap menggunakan PHP poller (default). Go poller lebih efisien di server dengan banyak OLT.

```bash
# Install Go 1.22 (atau versi terbaru)
wget https://go.dev/dl/go1.22.5.linux-amd64.tar.gz
tar -C /usr/local -xzf go1.22.5.linux-amd64.tar.gz
echo 'export PATH=$PATH:/usr/local/go/bin' >> /etc/profile.d/go.sh
source /etc/profile.d/go.sh

# Verifikasi
go version
```

Build binary poller:

```bash
cd /var/www/KusumaVisionNMS
go mod download
go build -o bin/kv-snmp-poller ./cmd/kv-snmp-poller
chmod +x bin/kv-snmp-poller
```

Aktifkan Go driver di `.env`:

```dotenv
SNMP_POLLER_DRIVER=go
SNMP_POLLER_BINARY=bin/kv-snmp-poller
```

> Jika binary tidak ditemukan atau tidak executable, sistem otomatis fallback ke PHP poller.

### 4. Siapkan database PostgreSQL

```bash
su - postgres -c "psql -c \"CREATE USER kusumavision WITH PASSWORD 'ganti_password_ini';\""
su - postgres -c "psql -c \"CREATE DATABASE kusumavision_nms OWNER kusumavision;\""
```

### 5. Clone dan konfigurasi aplikasi

```bash
cd /var/www
git clone git@github.com:Masamune21-dev/KusumaVisionNMS.git KusumaVisionNMS
cd KusumaVisionNMS

# Dependensi PHP & JS
composer install --no-dev --optimize-autoloader
npm install

# Environment
cp .env.example .env
php artisan key:generate
```

Edit `.env` sesuai server:

```dotenv
APP_NAME="KusumaVision NMS"
APP_ENV=production
APP_DEBUG=false
APP_URL=http://ip-server-anda

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=kusumavision_nms
DB_USERNAME=kusumavision
DB_PASSWORD=ganti_password_ini

CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379

# Polling SNMP (opsional — default sudah cukup)
SNMP_POLLER_DRIVER=php
```

### 6. Migrasi database dan build aset

```bash
php artisan migrate --force
npm run build
php artisan storage:link

# Permission storage untuk www-data
chown -R www-data:www-data /var/www/KusumaVisionNMS/storage \
    /var/www/KusumaVisionNMS/bootstrap/cache
chmod -R 775 /var/www/KusumaVisionNMS/storage \
    /var/www/KusumaVisionNMS/bootstrap/cache
```

### 7. Konfigurasi Nginx

Buat file konfigurasi site:

```bash
nano /etc/nginx/sites-available/kusumavision-nms
```

Isi dengan:

```nginx
server {
    listen 80;
    server_name ip-server-anda;   # atau nama domain

    root /var/www/KusumaVisionNMS/public;
    index index.php;

    charset utf-8;
    client_max_body_size 64M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { log_not_found off; access_log off; }
    location = /robots.txt  { log_not_found off; access_log off; }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    error_log  /var/log/nginx/kusumavision-nms.error.log;
    access_log /var/log/nginx/kusumavision-nms.access.log;
}
```

Aktifkan site dan restart Nginx:

```bash
# Nonaktifkan default site
rm -f /etc/nginx/sites-enabled/default

# Aktifkan site KusumaVision
ln -s /etc/nginx/sites-available/kusumavision-nms /etc/nginx/sites-enabled/

# Test konfigurasi & restart
nginx -t && systemctl restart nginx
systemctl enable nginx php8.3-fpm
```

### 8. Konfigurasi Queue Worker (Supervisor)

Install Supervisor untuk menjaga queue worker tetap berjalan:

```bash
apt install -y supervisor
```

Buat file konfigurasi:

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

### 9. Konfigurasi Laravel Scheduler (Cron)

Tambahkan entri cron untuk menjalankan scheduler Laravel setiap menit:

```bash
crontab -e -u www-data
```

Tambahkan baris:

```cron
* * * * * php /var/www/KusumaVisionNMS/artisan schedule:run >> /dev/null 2>&1
```

> Scheduler menjalankan `olts:poll` setiap menit. Setiap OLT hanya benar-benar di-poll sesuai interval masing-masing (`poll_interval_minutes`).

### 10. Buat akun pertama

Registrasi publik dinonaktifkan. Buat user pertama lewat Artisan:

```bash
php artisan user:create
```

Atau langsung satu baris tanpa interaktif:

```bash
php artisan user:create --name="Admin BMKV" --email="admin@bmkv.net" --password="P@ssw0rd123"
```

Untuk user berikutnya, perintah yang sama bisa dijalankan kapan saja dari server.

---

## Menjalankan di Development

Satu perintah menjalankan server + queue + log + Vite secara paralel:

```bash
composer dev
```

Atau pisah per proses:

```bash
php artisan serve --host=0.0.0.0 --port=8000
npm run dev
php artisan queue:work
php artisan schedule:work
```

---

## Perintah Berguna

```bash
php artisan olts:poll      # dispatch poll semua OLT yang sudah due sekarang
php artisan test           # jalankan test suite (SQLite in-memory)
./vendor/bin/pint          # format kode PHP
npm run build              # build aset produksi
```

---

## Catatan & Batasan

- **Vendor:** hanya ZTE C300/C320. OLT non-ZTE terdeteksi sebagai `unknown` dengan kapabilitas dimatikan.
- **SNMP:** v1/v2c saja (v3 belum didukung). Fitur enable/disable & edit info ONU butuh **write community** terisi.
- **CLI:** eksekusi provisioning/reboot hanya via **Telnet** (`cli_transport=telnet`).
- **Poll interval:** per-OLT, dapat diubah di form Edit OLT. Default 5 menit untuk polling SNMP, 5 menit untuk RX power.
- Dashboard & alarm seakurat poll terakhir — pastikan queue worker dan cron scheduler aktif.

---

## Lisensi

Proprietary — PT Berkah Media Kusuma Vision (BMKV).
