# 18 — Docker Appliance

Kemasan Docker membungkus **seluruh stack** KusumaVision NMS menjadi container sehingga bisa dipasang
lengkap di satu PC (Windows/Linux/macOS) — mirip cara NetNumen dipasang — dan mudah dibagikan ke banyak
lokasi. **Tidak ada perubahan kode aplikasi**; ini murni lapisan kemasan yang paritas fungsional dengan
`install.sh` (lihat [04 — Instalasi & Deploy](04-instalasi-deploy.md)).

Panduan operator (langkah pasang, backup, distribusi): [`docs/DOCKER.md`](../DOCKER.md).

---

## Peta file

| File | Peran |
|------|-------|
| `Dockerfile` | Image multi-stage: (1) `node` build Vite → `public/build`, (2) `golang` build `bin/kv-snmp-poller`, (3) `php:8.3-fpm` runtime + nginx + supervisor + composer install. |
| `docker-compose.yml` | 3 service: `app`, `db` (postgres:16), `redis` (redis:7) + volume & healthcheck. |
| `.dockerignore` | Kecualikan artefak yang dibangun ulang (vendor/node_modules/bin), rahasia (`.env`), data runtime, dokumentasi. `.env.example` sengaja **tidak** dikecualikan (dibutuhkan saat build). |
| `docker/nginx.conf` | Server block (root `public/`, `/telnet-ws` → 127.0.0.1:6002, fastcgi → 127.0.0.1:9000) — adaptasi dari blok nginx `install.sh`. |
| `docker/php.ini` | Override produksi (memory 512M, upload 20M, opcache on). |
| `docker/supervisord.conf` | Program di container `app`: php-fpm, nginx, `queue:work`, `schedule:work`, `telnet:proxy`. Semua log → stdout. |
| `docker/entrypoint.sh` | First-run/boot: storage skeleton, tunggu DB, APP_KEY persist, migrate, admin opsional, `optimize`, lalu `exec supervisord`. |
| `.env.docker.example` | Template `.env` **host-side** (dibaca compose, bukan `.env` Laravel): `APP_PORT`, `DB_*`, `ACS_*`, `ADMIN_*`. |
| `start.bat`/`stop.bat`/`update.bat`, `start.sh` | Launcher 1-klik. |

---

## Arsitektur (3 container)

```
                 ┌─────────────────────────── container: app ───────────────────────────┐
  browser :80/   │  nginx ──fastcgi──▶ php-fpm (Laravel)                                  │
  host APP_PORT ─┤    └─/telnet-ws─▶ 127.0.0.1:6002 (telnet:proxy)                        │
                 │  supervisor menjaga: php-fpm · nginx · queue:work · schedule:work ·    │
                 │                       telnet:proxy                                     │
                 │  bin/kv-snmp-poller (Go, dipanggil PollOltJob)                         │
                 └───────────────┬───────────────────────────────┬────────────────────────┘
                                 │ pgsql (db:5432)                │ redis (redis:6379)
                        ┌────────▼────────┐             ┌─────────▼─────────┐
                        │ container: db   │             │ container: redis  │
                        │ postgres:16     │             │ redis:7           │
                        │ volume pgdata   │             │ volume redisdata  │
                        └─────────────────┘             └───────────────────┘
        volume app_storage → /var/www/html/storage (upload, log, APP_KEY)
```

- **app all-in-one** dipilih (nginx+php-fpm+3 daemon dalam satu container via supervisor) agar hanya
  **satu port** yang di-publish — paling ramah sebagai "appliance". DB & Redis tetap container official
  terpisah supaya data & versinya bersih.
- Daemon = **sama persis** dengan supervisor di `install.sh` (`kusumavision-worker`/`-scheduler`/
  `-telnet-proxy`). nginx mem-proxy `/telnet-ws` ke daemon telnet di container yang sama, jadi
  `TELNET_PROXY_WS_URL=/telnet-ws` bekerja identik.
- **SNMP poller Go** — biner di-build di stage `golang` (CGO_ENABLED=0, statis) lalu di-COPY;
  `SNMP_POLLER_DRIVER=go` diset di compose → jalur poll sama seperti produksi.

---

## Konfigurasi & environment

- Runtime **tidak** memakai file `.env` Laravel; config datang dari **environment** yang disuntik compose:
  - `env_file: .env` → nilai tunable user (APP_URL, DB_*, ACS_*, ADMIN_*).
  - blok `environment:` → infrastruktur tetap (host jaringan `db`/`redis`, `REDIS_CLIENT=phpredis`,
    driver redis untuk session/queue/cache, `SNMP_POLLER_DRIVER=go`, telnet proxy).
- Entrypoint menjalankan `php artisan optimize` **setelah** env terisi → menghindari gotcha "config
  ter-cache jatuh ke sqlite" (lihat [04](04-instalasi-deploy.md) & memori proyek). Karena app produksi
  memang jalan dengan config ter-cache, ini konsisten.

### APP_KEY (persistensi enkripsi)

Secret OLT (`snmp_*_community`, `cli_password`) dan session terenkripsi memakai `APP_KEY`. Agar
`docker compose up` bisa jalan tanpa tool di PC tujuan **sekaligus** key stabil antar restart:

- Jika `APP_KEY` diisi di `.env` → dipakai apa adanya.
- Jika kosong → entrypoint generate `php artisan key:generate --show`, simpan ke
  `storage/app/.appkey` (di volume `app_storage`), dan pakai ulang di boot berikutnya.

> ⚠️ Kalau volume `app_storage` dihapus, `APP_KEY` ikut hilang → data terenkripsi lama tak terbaca.
> Untuk migrasi antar-PC, bawa `APP_KEY` yang sama (isikan di `.env`) atau backup volume.

---

## Persistensi & masking volume

`app_storage` di-mount ke `/var/www/html/storage`, **menutupi** isi storage dari image. Karena itu
entrypoint selalu `mkdir -p` skeleton `storage/framework/{cache,sessions,views}`, `storage/logs`,
`storage/app/{public,private}` dan `chown www-data` di tiap boot — menangani first-run (volume kosong)
sekaligus upgrade.

`bootstrap/cache` **bukan** volume (ikut image), jadi cache `optimize` dibangun ulang tiap start.

---

## Dua mode distribusi

1. **Source** — bawa folder proyek, `docker compose up -d --build`. Compose punya `build:` context.
2. **Image prebuilt** — `docker build` → `docker save | gzip` → kirim tar + `docker-compose.yml` +
   `.env.docker.example`. Di tujuan `docker load` lalu `docker compose up -d` (tanpa `--build`):
   compose memakai image `kusumavision/nms:latest` yang sudah ada (bagian `build:` diabaikan karena
   image tersedia & rebuild tidak diminta). Praktis bila penerima tak perlu source-nya.

---

## Verifikasi

```bash
docker compose up -d --build
docker compose ps                                   # app/db/redis healthy
curl -s -o /dev/null -w '%{http_code}\n' localhost:8080/        # 200
curl -s -o /dev/null -w '%{http_code}\n' localhost:8080/dashboard   # 302 → /login
docker compose exec app php artisan migrate:status  # migrasi di Postgres container
docker compose exec app bin/kv-snmp-poller --host 127.0.0.1 --timeout 1s --retries 0  # emit JSON
docker compose down && docker compose up -d         # data & login tetap ada (volume persist)
```

Relasi ke `install.sh`: keduanya memasang stack identik. Perbedaan hanya **wadah** — Docker mengisolasi
runtime & datanya di container/volume, `install.sh` memasang langsung ke host Ubuntu.
