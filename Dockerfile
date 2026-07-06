# syntax=docker/dockerfile:1
#
# KusumaVision NMS — image "appliance" all-in-one.
# Membungkus nginx + php-fpm + daemon (queue worker, scheduler, telnet proxy)
# lewat supervisor. PostgreSQL & Redis dijalankan sebagai container terpisah
# (lihat docker-compose.yml). Paritas fungsional dengan install.sh.
#
# Build:  docker build -t kusumavision/nms:latest .
# Jalan:  docker compose up -d
# ---------------------------------------------------------------------------

# ---------------------------------------------------------------------------
# Stage 1 — build frontend (Vite → public/build). Murni JS, tak butuh PHP.
# ---------------------------------------------------------------------------
FROM node:22-bookworm-slim AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . .
RUN npm run build

# ---------------------------------------------------------------------------
# Stage 2 — build Go SNMP poller (biner statis, sama flag seperti install.sh).
# ---------------------------------------------------------------------------
FROM golang:1.22-bookworm AS gobuild
WORKDIR /src
COPY go.mod go.sum ./
RUN go mod download
COPY cmd ./cmd
RUN CGO_ENABLED=0 go build -mod=mod -trimpath -ldflags='-s -w' -o /out/kv-snmp-poller ./cmd/kv-snmp-poller

# ---------------------------------------------------------------------------
# Stage 3 — image runtime PHP-FPM + nginx + supervisor.
# ---------------------------------------------------------------------------
FROM php:8.3-fpm-bookworm AS app

# install-php-extensions mengurus dependensi build & runtime tiap ekstensi lalu
# membersihkannya sendiri (jauh lebih ringkas & andal ketimbang rakit manual).
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions \
        pdo_pgsql pgsql bcmath intl mbstring xml zip gd pcntl sockets snmp redis opcache

# Runtime pendukung: nginx (web), supervisor (proses), postgresql-client (pg_isready),
# git+unzip (composer dist), snmp (net-snmp CLI, opsional untuk debug).
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
        nginx supervisor postgresql-client git unzip curl ca-certificates snmp \
 && rm -rf /var/lib/apt/lists/* \
 && rm -f /etc/nginx/sites-enabled/default

# Composer dari image resmi.
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Konfig container.
COPY docker/nginx.conf       /etc/nginx/conf.d/default.conf
COPY docker/php.ini          /usr/local/etc/php/conf.d/zz-kusumavision.ini
COPY docker/supervisord.conf /etc/supervisor/conf.d/kusumavision.conf
COPY docker/entrypoint.sh    /usr/local/bin/entrypoint.sh
# Normalisasi CRLF->LF: bila file ini pernah diedit / di-zip ulang di Windows,
# shebang & baris config bisa berakhiran \r sehingga container gagal start dengan
# error samar ("no such file or directory" pada entrypoint, supervisor/nginx tolak
# baris). Sekali sapu di sini menutup seluruh kelas kegagalan itu.
RUN sed -i 's/\r$//' \
        /usr/local/bin/entrypoint.sh \
        /etc/nginx/conf.d/default.conf \
        /etc/supervisor/conf.d/kusumavision.conf \
        /usr/local/etc/php/conf.d/zz-kusumavision.ini \
 && chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html

# Dependensi PHP dulu (layer cache saat kode berubah tapi lock tetap).
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --no-progress --prefer-dist

# Sumber aplikasi + artefak dari stage lain.
COPY . .
COPY --from=frontend /app/public/build /var/www/html/public/build
COPY --from=gobuild  /out/kv-snmp-poller /var/www/html/bin/kv-snmp-poller

# Autoload optimal + package discovery. .env sementara agar artisan bisa boot saat
# build; dihapus lagi supaya runtime murni membaca environment dari compose.
RUN cp .env.example .env \
 && composer dump-autoload --optimize --no-dev --no-interaction \
 && rm -f .env \
 && chmod +x bin/kv-snmp-poller \
 && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 80

# Entrypoint menyiapkan storage/APP_KEY/migrasi/cache lalu exec supervisord.
# Dipanggil via bash eksplisit supaya tetap jalan walau shebang sempat ber-CRLF.
ENTRYPOINT ["/bin/bash", "/usr/local/bin/entrypoint.sh"]
