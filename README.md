# KusumaVision NMS

**Unified FTTH Network Management Platform** — PT Berkah Media Kusuma Vision (BMKV).

Platform manajemen jaringan FTTH berbasis web untuk mengelola OLT GPON **ZTE C300/C320 (ZXA10)**: monitoring OLT/ONU, provisioning ONU, remote management, background polling, alarm engine, dan dashboard. Dibangun sebagai alternatif modern untuk SmartOLT/NetNumen bagi ISP FTTH di Indonesia.

> Dokumentasi fitur lengkap: [`docs/KusumaVision_NMS_Dokumentasi_Fitur.pdf`](docs/KusumaVision_NMS_Dokumentasi_Fitur.pdf). Riwayat pengembangan per fase: [`WORKLOG.md`](WORKLOG.md).

## Fitur

- **Inventory OLT** — CRUD OLT, uji koneksi SNMP, kredensial (community & password CLI) tersimpan terenkripsi.
- **Monitoring** — GPON port (up/down) dan ONU per port (online/offline, phase state, RX power).
- **Discovery ONU unconfigured** — temukan ONU baru via OID ZTE, langsung ke form provisioning.
- **Provisioning ONU** — VLAN, T-CONT, PPPoE/DHCP/Static, TR069, Remote ONT; tersimpan sebagai audit log lalu dieksekusi via Telnet.
- **Manajemen Profile** — ONU Type / T-CONT / VLAN / IP per-OLT, dengan sinkronisasi langsung dari OLT.
- **Remote ONU Management** — reboot (CLI), enable/disable & edit nama/deskripsi (SNMP SET).
- **Background polling** — job terjadwal men-snapshot tiap OLT (SNMP) tiap 5 menit.
- **Alarm engine** — siklus raise/clear untuk `olt_unreachable`, `port_down`, `los`, `onu_offline`, `dying_gasp`, `high_rx_attenuation`.
- **Dashboard** — ringkasan OLT/ONU/alarm dengan grafik (ApexCharts).

## Stack Teknologi

| Lapisan | Teknologi |
|---|---|
| Backend | Laravel 12 (PHP 8.3), Inertia.js, Sanctum |
| Frontend | Vue 3 + Inertia, TailwindCSS, ApexCharts |
| Database | PostgreSQL |
| Cache / Queue / Session | Redis (queue diproses queue worker / Horizon) |
| Akses OLT | SNMP v1/v2c (read & write), CLI Telnet |

## Persyaratan

- PHP **8.3**, Composer, Node.js **22**, npm
- PostgreSQL, Redis, Net-SNMP (`snmpwalk`)
- Ekstensi PHP: `bcmath`, `curl`, `dom`, `intl`, `mbstring`, `openssl`, `pcntl`, `pdo_pgsql`, `pdo_sqlite`, `redis`, `snmp`, `sockets`, `xml`, `zip`

Cek otomatis kelengkapan environment:

```bash
bash scripts/check-requirements.sh
```

## Instalasi

```bash
# 1. Dependensi PHP & JS
composer install
npm install

# 2. Konfigurasi environment
cp .env.example .env
php artisan key:generate
```

Sunting `.env` sesuai server Anda (nilai default ada di `.env.example`):

```dotenv
DB_CONNECTION=pgsql
DB_DATABASE=kusumavision_nms
DB_USERNAME=kusumavision
DB_PASSWORD=secret

QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
REDIS_HOST=127.0.0.1
```

Siapkan database lalu jalankan migrasi dan build aset:

```bash
# Buat database PostgreSQL terlebih dahulu (mis. createdb kusumavision_nms)
php artisan migrate
npm run build
```

Buat user pertama lewat halaman registrasi (`/register`) setelah aplikasi berjalan.

## Menjalankan

### Development

Satu perintah menjalankan server + queue + log + Vite secara paralel:

```bash
composer dev
```

### Produksi

Jalankan ketiga proses berikut (kelola dengan Supervisor/systemd):

```bash
php artisan serve --host=0.0.0.0 --port=8000   # atau Nginx + PHP-FPM
php artisan queue:work redis --tries=1          # atau: php artisan horizon
php artisan schedule:work                       # atau entri cron Laravel scheduler
```

> **Penting:** background polling & alarm hanya ter-update otomatis bila **queue worker** dan **scheduler** berjalan. Scheduler memicu `olts:poll` tiap 5 menit; worker yang mengeksekusinya.

Build aset untuk produksi: `npm run build`.

## Perintah Berguna

```bash
php artisan olts:poll      # dispatch poll semua OLT (auto-poll aktif) sekarang
php artisan test           # jalankan test suite
./vendor/bin/pint          # format kode (PHP)
```

## Catatan & Batasan

- **Vendor:** hanya ZTE C300/C320. OLT non-ZTE terdeteksi `unknown` dengan kapabilitas dimatikan.
- **SNMP:** v1/v2c (v3 belum didukung). Enable/disable & edit info ONU butuh **write community** terisi.
- **CLI:** eksekusi provisioning/reboot hanya via **Telnet** (set `cli_transport=telnet` + username/password).
- Dashboard & alarm seakurat poll terakhir — jaga scheduler dan worker tetap hidup.

## Lisensi

Proprietary — PT Berkah Media Kusuma Vision (BMKV).
