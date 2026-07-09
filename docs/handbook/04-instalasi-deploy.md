# 04 — Instalasi & Deploy

[← Indeks](README.md) · [← 03 Struktur Folder](03-struktur-folder.md) · [05 Database & Model →](05-database-model.md)

Dokumen ini fokus pada langkah praktis. Detail hardening produksi (nginx, UFW, SSH, PHP-FPM)
ada di [`docs/LOCAL_PRODUCTION_HARDENING.md`](../LOCAL_PRODUCTION_HARDENING.md). Status runtime
yang terpasang ada di [`docs/INSTALLATION_STATUS.md`](../INSTALLATION_STATUS.md).

> 🚀 **Pengguna baru** (bukan developer) sebaiknya mulai dari **[`docs/INSTALL.md`](../INSTALL.md)** —
> peta keputusan per-OS + minimum spek. Build **APK Android**: **[`docs/BUILD_APK.md`](../BUILD_APK.md)**.

## ⚡ Cara cepat — `install.sh` (server Ubuntu kosong)

Untuk deploy satu-perintah di Ubuntu fresh (22.04/24.04), pakai skrip
[`install.sh`](../../install.sh) di root repo. Skrip memasang seluruh runtime (PHP, Composer,
Node, PostgreSQL, Redis, Nginx, Supervisor, Go, Net-SNMP), menyiapkan DB + `.env`, build frontend
& Go poller, migrasi, lalu mendaftarkan daemon Supervisor + nginx site.

```bash
git clone <repo> /var/www/KusumaVisionNMS
cd /var/www/KusumaVisionNMS
sudo bash install.sh                 # interaktif (tanya APP_URL, DB, admin)
# atau non-interaktif:
sudo APP_URL=http://nms.example.com ADMIN_EMAIL=admin@example.com ADMIN_PASSWORD=rahasia \
     bash install.sh --yes
```

Verifikasi kapan saja dengan [`scripts/check-requirements.sh`](../../scripts/check-requirements.sh)
(cek versi tool, ekstensi PHP, artefak build, dan status service/daemon).

Bagian di bawah menjelaskan langkah **manual** (untuk dev, atau bila ingin paham yang dikerjakan
`install.sh`).

## Prasyarat runtime

| Komponen | Versi | Catatan |
|----------|-------|---------|
| PHP | 8.2+ (prod pakai 8.3) | ekstensi: `pdo_pgsql`, `redis`/predis, `snmp`, `sqlite3` (untuk test) |
| Composer | 2.x | |
| Node.js | 22 / npm 10 | untuk build Vite |
| PostgreSQL | 14+ | database `kusumavision_nms` |
| Redis | — | cache, session, queue |
| Go | 1.18+ | hanya untuk build ulang `bin/kv-snmp-poller` |
| Net-SNMP | — | `snmpget`/`snmpwalk` membantu debugging; PHP ext-snmp dipakai `OltSnmpClient` |
| Supervisor | — | jalankan worker, scheduler, telnet proxy di prod |
| Nginx + PHP-FPM | — | web server prod |

## Setup lokal (dev)

```bash
# 1. Dependensi
composer install
npm install

# 2. Environment
cp .env.example .env
php artisan key:generate          # mengisi APP_KEY (WAJIB — dipakai enkripsi secret & tiket telnet)

# 3. Sesuaikan .env (DB, Redis). Untuk dev cepat boleh:
#    DB_CONNECTION=pgsql + DB_DATABASE=kusumavision_nms
#    SNMP_POLLER_DRIVER=php (atau go bila binary sudah dibuild)

# 4. Database
php artisan migrate
php artisan db:seed                # DatabaseSeeder → 1 admin test@example.com
#   (opsional, HANYA untuk instance demo:) php artisan db:seed --class=DemoSeeder

# 5. Buat user admin nyata (registrasi publik dimatikan)
php artisan user:create --name="Admin" --email=admin@example.com --password=rahasia
```

### Menjalankan dev

Cara cepat (semua sekaligus, dari `composer.json` script `dev`):
```bash
composer dev      # serve + queue:listen + pail (logs) + vite, via concurrently
```

Atau manual di terminal terpisah:
```bash
php artisan serve --host=0.0.0.0 --port=8000
npm run dev
php artisan queue:work            # agar PollOltJob jalan
php artisan schedule:work         # agar olts:poll ter-trigger tiap menit
php artisan telnet:proxy          # bila ingin coba browser telnet
# opsional:
php artisan reverb:start
php artisan horizon
```

> Tanpa `schedule:work` + `queue:work`, polling otomatis tidak berjalan (data live tidak
> ter-refresh sendiri). Refresh manual dari UI tetap bekerja karena sinkron.

## Build frontend

```bash
npm run dev       # dev server HMR
npm run build     # produksi → public/build (emptyOutDir:false: chunk lama disimpan utk sesi aktif)
```

> `vite.config.js` sengaja `emptyOutDir: false` agar deploy tidak mematikan sesi yang sedang
> memuat chunk hash lama. `app.js` juga punya handler `vite:preloadError` yang reload halaman
> sekali bila asset hash berubah.

## Build Go SNMP poller

```bash
go build -o bin/kv-snmp-poller ./cmd/kv-snmp-poller
chmod +x bin/kv-snmp-poller
```
Aktif bila `SNMP_POLLER_DRIVER=go` (`config/services.php`) **dan** binary ada + executable
(`GoSnmpPoller::enabled()`).

## Variabel `.env` penting (khas proyek)

```dotenv
APP_LOCALE=id
DB_CONNECTION=pgsql
DB_DATABASE=kusumavision_nms
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
CACHE_STORE=redis
BROADCAST_CONNECTION=reverb

# SNMP poller (Go)
SNMP_POLLER_DRIVER=go            # prod: go | dev: php
SNMP_POLLER_BINARY=bin/kv-snmp-poller
SNMP_POLLER_REQUEST_TIMEOUT=10s
SNMP_POLLER_PROCESS_TIMEOUT=300
SNMP_POLLER_RETRIES=2
SNMP_POLLER_WALK_MODE=bulk
SNMP_POLLER_MAX_REPETITIONS=10

# Browser telnet proxy
TELNET_PROXY_HOST=127.0.0.1
TELNET_PROXY_PORT=6002
TELNET_PROXY_WS_URL=             # prod: wss://domain/telnet-ws (nginx). Dev: kosong → ws://host:6002
TELNET_PROXY_TICKET_TTL=60
TELNET_PROXY_CONNECT_TIMEOUT=10
```

## Deploy / refresh produksi

Urutan baku (lihat juga LOCAL_PRODUCTION_HARDENING.md):
```bash
cd /var/www/KusumaVisionNMS
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan optimize:clear
php artisan optimize                 # cache config + route + view
chown -R www-data:www-data storage bootstrap/cache
php artisan queue:restart            # worker pakai kode baru
supervisorctl restart kusumavision-telnet-proxy   # daemon telnet pakai kode baru
```

### ⚠️ Gotcha config cache (sangat penting)

- **Produksi memuat config ter-cache** (`bootstrap/cache/config.php`). Setiap ubah `.env`/config:
  ```bash
  php artisan config:cache
  ```
  lalu **restart daemon** (`queue:restart`, `supervisorctl restart kusumavision-telnet-proxy`).
- **`.env` harus `640 root:www-data`** agar user `www-data` bisa membacanya. Bila jadi `root:root`
  dan cache config di-clear, app fallback ke SQLite → **site 500**.
- **Saat menjalankan test di mesin yang config-nya ter-cache**, clear dulu:
  ```bash
  php artisan config:clear && php artisan test && php artisan optimize
  ```
  Kalau tidak, test bisa "nyasar" ke koneksi pgsql alih-alih SQLite test.

> Memori proyek mencatat dua gotcha ini — patuhi agar tidak menjatuhkan site.

## Daemon supervisor (produksi)

| Program | Perintah | Fungsi |
|---------|----------|--------|
| `kusumavision-worker` | `php artisan queue:work redis --tries=1` | Jalankan `PollOltJob` (polling) |
| `kusumavision-scheduler` | `php artisan schedule:work` | Trigger `olts:poll` tiap menit |
| `kusumavision-telnet-proxy` | `php artisan telnet:proxy` | Daemon WS↔telnet (localhost, di-proxy nginx `/telnet-ws`) |

Cek: `supervisorctl status`. Setelah ubah kode job/service: **`php artisan queue:restart`**
(daemon long-lived tidak otomatis memakai kode baru).

## Smoke test pasca-deploy

```bash
curl -sS -o /dev/null -w 'home %{http_code}\n'       http://<host>/
curl -sS -o /dev/null -w 'dash %{http_code}\n'        http://<host>/dashboard   # harus 302 → /login
curl -sS -o /dev/null -w 'env  %{http_code}\n'        http://<host>/.env        # harus 403
supervisorctl status
```

## Testing

```bash
php artisan test           # PHPUnit, SQLite in-memory (phpunit.xml)
./vendor/bin/pint          # code style (Laravel Pint)
```
Migrasi **wajib kompatibel SQLite** karena test jalan di SQLite walau prod PostgreSQL.

## Selanjutnya

→ [05 — Database & Model](05-database-model.md)
