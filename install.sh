#!/usr/bin/env bash
#
# install.sh — Deploy KusumaVision NMS pada server Ubuntu fresh (kosong).
#
# Memasang seluruh runtime (PHP, Composer, Node, PostgreSQL, Redis, Nginx,
# Supervisor, Go, Net-SNMP), menyiapkan database + .env, build frontend & Go
# poller, menjalankan migrasi, dan mendaftarkan daemon (worker, scheduler,
# telnet proxy) + nginx site. Aman dijalankan ulang (idempotent sebisanya).
#
# Pemakaian & daftar environment variable: sudo bash install.sh --help
# (teks bantuan ada di fungsi usage() di bawah, dalam bahasa Indonesia & Inggris).
#
# Bahasa yang dipilih (--lang / APP_LOCALE / pertanyaan pertama) dipakai untuk pesan
# installer SEKALIGUS menjadi bahasa bawaan aplikasi.
#
# Diuji untuk Ubuntu 22.04 / 24.04.
#
set -euo pipefail

# ---------------------------------------------------------------------------
# Konstanta & default
# ---------------------------------------------------------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="${PROJECT_DIR:-$SCRIPT_DIR}"
APP_USER="www-data"

PHP_VERSION="${PHP_VERSION:-8.3}"
PHP_CLI="php${PHP_VERSION}"          # binari CLI versi target (mis. php8.3) — dipakai eksplisit, bukan 'php' polos
NODE_MAJOR="${NODE_MAJOR:-22}"

DB_NAME="${DB_NAME:-kusumavision_nms}"
DB_USER="${DB_USER:-kusumavision}"
DB_PASSWORD="${DB_PASSWORD:-}"

APP_URL="${APP_URL:-}"
ADMIN_NAME="${ADMIN_NAME:-}"
ADMIN_EMAIL="${ADMIN_EMAIL:-}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-}"

ENABLE_UFW="${ENABLE_UFW:-0}"
ASSUME_YES=0
SHOW_HELP=0
# Bahasa bawaan aplikasi (tamu & pengguna yang belum memilih). Tiap pengguna tetap bisa
# menggantinya sendiri dari pemilih bahasa di aplikasi.
APP_LOCALE="${APP_LOCALE:-}"

# Bahasa pesan installer. Sampai bahasa dipilih (galat argumen, --help) ditebak dari
# locale shell; setelah dipilih, UI_LANG = APP_LOCALE.
case "${LC_ALL:-${LC_MESSAGES:-${LANG:-}}}" in
  id*) UI_LANG="id" ;;
  *)   UI_LANG="en" ;;
esac

# ---------------------------------------------------------------------------
# Util logging & bahasa
# ---------------------------------------------------------------------------
c_reset="\033[0m"; c_blue="\033[1;34m"; c_green="\033[1;32m"; c_yellow="\033[1;33m"; c_red="\033[1;31m"
step()  { printf "\n${c_blue}==> %s${c_reset}\n" "$*"; }
info()  { printf "    %s\n" "$*"; }
ok()    { printf "${c_green}[OK]${c_reset}   %s\n" "$*"; }
warn()  { printf "${c_yellow}[WARN]${c_reset} %s\n" "$*"; }
die()   { printf "${c_red}[ERROR]${c_reset} %s\n" "$*" >&2; exit 1; }

# t "teks Indonesia" "English text" -> cetak sesuai UI_LANG.
t() { if [ "$UI_LANG" = "en" ]; then printf '%s' "$2"; else printf '%s' "$1"; fi; }

# row "Label" "nilai" -> baris ringkasan rata kiri (lebar label beda per bahasa).
row() { printf '  %-15s: %s\n' "$1" "$2"; }

confirm() {
  # confirm "pertanyaan" [default Y/n]
  local prompt="$1" default="${2:-Y}" ans
  if [ "$ASSUME_YES" = "1" ]; then return 0; fi
  read -r -p "$prompt [${default}] " ans || true
  ans="${ans:-$default}"
  [[ "$ans" =~ ^[Yy]$ ]]
}

ask() {
  # ask VAR "prompt" "default"  -> set VAR; pakai default bila --yes / kosong
  local __var="$1" __prompt="$2" __default="${3:-}" __val
  if [ "$ASSUME_YES" = "1" ]; then printf -v "$__var" '%s' "$__default"; return; fi
  read -r -p "$__prompt${__default:+ [$__default]}: " __val || true
  printf -v "$__var" '%s' "${__val:-$__default}"
}

usage() {
  if [ "$UI_LANG" = "en" ]; then
    cat <<'USAGE'
Usage: sudo bash install.sh [options]

Installs KusumaVision NMS on a fresh Ubuntu 22.04 / 24.04 server: runtimes (PHP,
Composer, Node, PostgreSQL, Redis, Nginx, Supervisor, Go, Net-SNMP), database and
.env, frontend and Go poller builds, migrations, daemons and the nginx site.
Safe to re-run.

Options:
  -y, --yes        non-interactive (use defaults / environment variables)
  --lang en|id     language of the installer and default language of the app
                   (default id; every user can still switch it in the app)
  -h, --help       show this help

Environment variables (optional, mainly for --yes):
  APP_URL=http://nms.example.com
  DB_NAME=kusumavision_nms  DB_USER=kusumavision  DB_PASSWORD=...
  PHP_VERSION=8.3
  ADMIN_NAME="Admin"  ADMIN_EMAIL=admin@example.com  ADMIN_PASSWORD=...
  ENABLE_UFW=0|1
  APP_LOCALE=en|id                     same as --lang

Examples:
  sudo bash install.sh --lang en                 # interactive, in English
  sudo bash install.sh --yes --lang en           # non-interactive, in English
USAGE
  else
    cat <<'USAGE'
Pemakaian: sudo bash install.sh [opsi]

Memasang KusumaVision NMS di server Ubuntu 22.04 / 24.04 yang masih kosong: runtime
(PHP, Composer, Node, PostgreSQL, Redis, Nginx, Supervisor, Go, Net-SNMP), database
dan .env, build frontend & Go poller, migrasi, daemon, dan nginx site.
Aman dijalankan ulang.

Opsi:
  -y, --yes        non-interaktif (pakai default / environment variable)
  --lang id|en     bahasa installer sekaligus bahasa bawaan aplikasi
                   (default id; tiap pengguna tetap bisa menggantinya di aplikasi)
  -h, --help       tampilkan bantuan ini

Environment variable (opsional, terutama untuk --yes):
  APP_URL=http://nms.example.com
  DB_NAME=kusumavision_nms  DB_USER=kusumavision  DB_PASSWORD=...
  PHP_VERSION=8.3
  ADMIN_NAME="Admin"  ADMIN_EMAIL=admin@example.com  ADMIN_PASSWORD=...
  ENABLE_UFW=0|1
  APP_LOCALE=id|en                     sama dengan --lang

Contoh:
  sudo bash install.sh                           # interaktif
  sudo bash install.sh --yes --lang id           # non-interaktif
USAGE
  fi
}

# Set/replace KEY=value di file .env (escape karakter sed).
set_env() {
  local key="$1" value="$2" file="$PROJECT_DIR/.env" esc
  esc="$(printf '%s' "$value" | sed -e 's/[\/&|]/\\&/g')"
  if grep -qE "^${key}=" "$file"; then
    sed -i -E "s|^${key}=.*|${key}=${esc}|" "$file"
  else
    printf '%s=%s\n' "$key" "$value" >> "$file"
  fi
}

run_artisan() { (cd "$PROJECT_DIR" && "$PHP_CLI" artisan "$@"); }

# ---------------------------------------------------------------------------
# Parse argumen
# ---------------------------------------------------------------------------
while [ $# -gt 0 ]; do
  case "$1" in
    -y|--yes) ASSUME_YES=1 ;;
    --lang=*) APP_LOCALE="${1#--lang=}" ;;
    --lang)
      [ $# -ge 2 ] || die "$(t "--lang butuh nilai: en | id" "--lang needs a value: en | id")"
      APP_LOCALE="$2"; shift ;;
    -h|--help) SHOW_HELP=1 ;;
    *) die "$(t "Argumen tidak dikenal: $1 (pakai --help)" "Unknown argument: $1 (see --help)")" ;;
  esac
  shift
done

if [ -n "$APP_LOCALE" ]; then
  APP_LOCALE="$(printf '%s' "$APP_LOCALE" | tr '[:upper:]' '[:lower:]')"
  case "$APP_LOCALE" in
    id|en) UI_LANG="$APP_LOCALE" ;;
    *) die "$(t "Bahasa tidak dikenal: '$APP_LOCALE' (pilih id atau en)" "Unknown language: '$APP_LOCALE' (choose en or id)")" ;;
  esac
fi

if [ "$SHOW_HELP" = "1" ]; then usage; exit 0; fi

# ---------------------------------------------------------------------------
# Bahasa — ditanyakan paling awal supaya seluruh pesan berikutnya ikut bahasa ini
# ---------------------------------------------------------------------------
if [ -z "$APP_LOCALE" ]; then
  if [ "$ASSUME_YES" = "1" ]; then
    APP_LOCALE="id"
  else
    step "Bahasa / Language"
    info "id = Bahasa Indonesia"
    info "en = English"
    while :; do
      read -r -p "    Pilih bahasa / Choose language [id]: " APP_LOCALE || true
      APP_LOCALE="$(printf '%s' "${APP_LOCALE:-id}" | tr '[:upper:]' '[:lower:]')"
      case "$APP_LOCALE" in id|en) break ;; esac
      warn "Ketik id atau en / Please type en or id"
    done
  fi
  UI_LANG="$APP_LOCALE"
fi

# ---------------------------------------------------------------------------
# Pra-syarat dasar
# ---------------------------------------------------------------------------
step "$(t "Pemeriksaan awal" "Preflight checks")"
[ "$(id -u)" -eq 0 ] || die "$(t "Jalankan sebagai root: sudo bash install.sh" "Run as root: sudo bash install.sh")"
[ -f "$PROJECT_DIR/artisan" ] || die "$(t "Tidak menemukan artisan di $PROJECT_DIR — jalankan dari root repo." "artisan not found in $PROJECT_DIR — run this from the repository root.")"
. /etc/os-release 2>/dev/null || true
[ "${ID:-}" = "ubuntu" ] || warn "$(t "OS terdeteksi '${ID:-unknown}', skrip ini dirancang untuk Ubuntu." "Detected OS '${ID:-unknown}'; this script is designed for Ubuntu.")"
ok "$(t "Root + repo terdeteksi di $PROJECT_DIR (Ubuntu ${VERSION_ID:-?})" "Running as root, repository at $PROJECT_DIR (Ubuntu ${VERSION_ID:-?})")"

# IP utama untuk default APP_URL
PRIMARY_IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
[ -n "${APP_URL}" ] || APP_URL="http://${PRIMARY_IP:-localhost}"

step "$(t "Konfigurasi deployment" "Deployment settings")"
ask APP_URL       "$(t "URL aplikasi (APP_URL)"   "Application URL (APP_URL)")"  "$APP_URL"
ask DB_NAME       "$(t "Nama database PostgreSQL" "PostgreSQL database name")"   "$DB_NAME"
ask DB_USER       "$(t "User database PostgreSQL" "PostgreSQL database user")"   "$DB_USER"
if [ -z "$DB_PASSWORD" ]; then
  if [ "$ASSUME_YES" = "1" ]; then
    DB_PASSWORD="$(openssl rand -base64 18 2>/dev/null | tr -d '/+=' | cut -c1-20)"
    info "$(t "Password DB digenerate otomatis." "Database password generated automatically.")"
  else
    ask DB_PASSWORD "$(t "Password database (kosong = generate otomatis)" "Database password (leave empty to generate one)")" ""
    [ -n "$DB_PASSWORD" ] || DB_PASSWORD="$(openssl rand -base64 18 2>/dev/null | tr -d '/+=' | cut -c1-20)"
  fi
fi

printf '\n'
row "PROJECT_DIR"              "$PROJECT_DIR"
row "APP_URL"                  "$APP_URL"
row "$(t "Bahasa" "Language")" "$APP_LOCALE"
row "PHP"                      "${PHP_VERSION}    Node: ${NODE_MAJOR}.x"
row "Database"                 "${DB_NAME} (user ${DB_USER})"
row "App user"                 "${APP_USER}"
row "UFW"                      "$([ "$ENABLE_UFW" = "1" ] && t "aktif" "enabled" || t "lewati" "skipped")"
printf '\n'
confirm "$(t "Lanjutkan instalasi dengan konfigurasi di atas?" "Continue the installation with the settings above?")" "Y" \
  || die "$(t "Dibatalkan." "Cancelled.")"

export DEBIAN_FRONTEND=noninteractive

# ---------------------------------------------------------------------------
# 1. Paket dasar + repo
# ---------------------------------------------------------------------------
step "$(t "Memasang paket dasar & menambah repository" "Installing base packages & adding repositories")"
apt-get update -y
apt-get install -y ca-certificates curl gnupg lsb-release software-properties-common \
  apt-transport-https unzip git openssl acl

# PHP (ondrej/php memberi PHP versi terbaru + ekstensi di semua Ubuntu)
if ! grep -rq "ondrej/php" /etc/apt/sources.list.d/ 2>/dev/null; then
  add-apt-repository -y ppa:ondrej/php
fi

# NodeSource (Node.js LTS)
if [ ! -f /etc/apt/keyrings/nodesource.gpg ]; then
  mkdir -p /etc/apt/keyrings
  curl -fsSL "https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key" | gpg --dearmor -o /etc/apt/keyrings/nodesource.gpg
  echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_${NODE_MAJOR}.x nodistro main" \
    > /etc/apt/sources.list.d/nodesource.list
fi

apt-get update -y
ok "$(t "Repository siap" "Repositories ready")"

# ---------------------------------------------------------------------------
# 2. Runtime
# ---------------------------------------------------------------------------
step "$(t "Memasang runtime" "Installing runtimes") (PHP ${PHP_VERSION}, PostgreSQL, Redis, Nginx, Supervisor, Go, SNMP, Node)"
apt-get install -y \
  php${PHP_VERSION}-fpm php${PHP_VERSION}-cli php${PHP_VERSION}-common \
  php${PHP_VERSION}-bcmath php${PHP_VERSION}-curl php${PHP_VERSION}-intl \
  php${PHP_VERSION}-mbstring php${PHP_VERSION}-xml php${PHP_VERSION}-zip \
  php${PHP_VERSION}-pgsql php${PHP_VERSION}-sqlite3 php${PHP_VERSION}-snmp \
  php${PHP_VERSION}-redis php${PHP_VERSION}-gd \
  postgresql postgresql-contrib \
  redis-server \
  nginx \
  supervisor \
  golang-go \
  snmp \
  webp \
  nodejs

# Batas unggah PHP: default 2M menolak foto HP (3–8 MB) pada fitur foto ODP.
# Ditulis sebagai file terpisah supaya tidak menimpa php.ini bawaan distro.
cat >"/etc/php/${PHP_VERSION}/fpm/conf.d/99-kusumavision-uploads.ini" <<'PHPINI'
; Foto dokumentasi ODP (dikonversi ke WebP oleh cwebp). Validasi app membatasi 12 MB.
upload_max_filesize = 16M
post_max_size = 20M
PHPINI

systemctl enable --now postgresql redis-server nginx supervisor "php${PHP_VERSION}-fpm" >/dev/null 2>&1 || true

# Samakan CLI default 'php' ke versi target. Tanpa ini, bila server sudah punya PHP
# lebih baru (mis. 8.4) maka 'php' polos menunjuk ke situ → artisan/worker jalan di
# versi berbeda dari PHP-FPM (8.3) → mismatch. Pin agar web & CLI satu versi.
if [ -x "/usr/bin/${PHP_CLI}" ]; then
  update-alternatives --set php "/usr/bin/${PHP_CLI}" >/dev/null 2>&1 || true
fi
ok "$(t "Runtime terpasang" "Runtimes installed") (CLI php: $(${PHP_CLI} -r 'echo PHP_VERSION;' 2>/dev/null || echo '?'))"

# Composer
step "$(t "Memasang Composer" "Installing Composer")"
if ! command -v composer >/dev/null 2>&1; then
  EXPECTED="$(curl -fsSL https://composer.github.io/installer.sig)"
  curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
  ACTUAL="$("$PHP_CLI" -r "echo hash_file('sha384', '/tmp/composer-setup.php');")"
  [ "$EXPECTED" = "$ACTUAL" ] || die "$(t "Checksum installer Composer tidak cocok." "Composer installer checksum mismatch.")"
  "$PHP_CLI" /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
  rm -f /tmp/composer-setup.php
fi
ok "Composer: $(composer --version 2>/dev/null | head -n1)"

# ---------------------------------------------------------------------------
# 3. Database PostgreSQL
# ---------------------------------------------------------------------------
step "$(t "Menyiapkan database PostgreSQL" "Setting up the PostgreSQL database")"
DB_PASSWORD_SQL="${DB_PASSWORD//\'/\'\'}"
sudo -u postgres psql -v ON_ERROR_STOP=1 <<SQL
DO \$\$
BEGIN
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '${DB_USER}') THEN
    CREATE ROLE "${DB_USER}" LOGIN PASSWORD '${DB_PASSWORD_SQL}';
  ELSE
    ALTER ROLE "${DB_USER}" WITH LOGIN PASSWORD '${DB_PASSWORD_SQL}';
  END IF;
END
\$\$;
SQL
if ! sudo -u postgres psql -tAc "SELECT 1 FROM pg_database WHERE datname='${DB_NAME}'" | grep -q 1; then
  sudo -u postgres createdb -O "${DB_USER}" "${DB_NAME}"
fi
sudo -u postgres psql -v ON_ERROR_STOP=1 -c "GRANT ALL PRIVILEGES ON DATABASE \"${DB_NAME}\" TO \"${DB_USER}\";" >/dev/null
# PostgreSQL 15+: butuh hak di schema public
sudo -u postgres psql -d "${DB_NAME}" -c "GRANT ALL ON SCHEMA public TO \"${DB_USER}\";" >/dev/null 2>&1 || true
ok "$(t "Database ${DB_NAME} & user ${DB_USER} siap" "Database ${DB_NAME} and user ${DB_USER} ready")"

# ---------------------------------------------------------------------------
# 4. Dependensi aplikasi
# ---------------------------------------------------------------------------
step "$(t "Memasang dependensi PHP (composer)" "Installing PHP dependencies (composer)")"
# .env harus ada sebelum composer install agar post-script (package:discover) bisa boot.
[ -f "$PROJECT_DIR/.env" ] || cp "$PROJECT_DIR/.env.example" "$PROJECT_DIR/.env"
(cd "$PROJECT_DIR" && COMPOSER_ALLOW_SUPERUSER=1 "$PHP_CLI" "$(command -v composer)" install --no-dev --optimize-autoloader --no-interaction)

step "$(t "Memasang dependensi & build frontend (npm)" "Installing dependencies & building the frontend (npm)")"
if [ -f "$PROJECT_DIR/package-lock.json" ]; then
  (cd "$PROJECT_DIR" && npm ci)
else
  (cd "$PROJECT_DIR" && npm install)
fi
(cd "$PROJECT_DIR" && npm run build)
ok "$(t "Frontend ter-build (public/build)" "Frontend built (public/build)")"

# ---------------------------------------------------------------------------
# 5. .env + APP_KEY
# ---------------------------------------------------------------------------
step "$(t "Menyiapkan .env" "Preparing .env")"
[ -f "$PROJECT_DIR/.env" ] || cp "$PROJECT_DIR/.env.example" "$PROJECT_DIR/.env"
set_env APP_ENV production
set_env APP_DEBUG false
set_env APP_URL "$APP_URL"
set_env APP_LOCALE "$APP_LOCALE"
set_env LOG_LEVEL warning
set_env DB_CONNECTION pgsql
set_env DB_HOST 127.0.0.1
set_env DB_PORT 5432
set_env DB_DATABASE "$DB_NAME"
set_env DB_USERNAME "$DB_USER"
set_env DB_PASSWORD "$DB_PASSWORD"
set_env SESSION_DRIVER redis
set_env SESSION_ENCRYPT true
set_env QUEUE_CONNECTION redis
set_env CACHE_STORE redis
set_env REDIS_HOST 127.0.0.1
set_env SNMP_POLLER_DRIVER go
set_env SNMP_POLLER_BINARY bin/kv-snmp-poller
set_env TELNET_PROXY_HOST 127.0.0.1
set_env TELNET_PROXY_PORT 6002
set_env TELNET_PROXY_WS_URL /telnet-ws

grep -qE '^APP_KEY=base64:' "$PROJECT_DIR/.env" || run_artisan key:generate --force
ok "$(t ".env dikonfigurasi (production)" ".env configured (production)")"

# ---------------------------------------------------------------------------
# 6. Build Go SNMP poller
# ---------------------------------------------------------------------------
step "$(t "Build Go SNMP poller (statis)" "Building the Go SNMP poller (static)")"
# CGO_ENABLED=0 -> binary self-contained (tak tergantung glibc), aman dipindah antar
# server. -mod=mod karena repo punya folder vendor/ (PHP) di root. -trimpath + -s -w
# memperkecil & menstabilkan build.
(cd "$PROJECT_DIR" && CGO_ENABLED=0 go build -mod=mod -trimpath -ldflags='-s -w' -o bin/kv-snmp-poller ./cmd/kv-snmp-poller)
chmod +x "$PROJECT_DIR/bin/kv-snmp-poller"

# Smoke test: pastikan binary benar-benar jalan & emit JSON. Kalau tidak, PollOltJob
# akan diam-diam fallback ke PHP -> ketahuan sekarang, bukan pas produksi.
if KV_SNMP_COMMUNITY=public "$PROJECT_DIR/bin/kv-snmp-poller" --host 127.0.0.1 --timeout 1s --retries 0 2>/dev/null | grep -q '"ok"'; then
  ok "$(t "bin/kv-snmp-poller terbangun & berfungsi (emit JSON)" "bin/kv-snmp-poller built and working (emits JSON)")"
else
  warn "$(t "bin/kv-snmp-poller terbangun TAPI tidak emit JSON — poll akan fallback ke PHP. Cek: file bin/kv-snmp-poller; jalankan manual untuk lihat error." \
            "bin/kv-snmp-poller was built BUT does not emit JSON — polling will fall back to PHP. Check: file bin/kv-snmp-poller; run it manually to see the error.")"
fi

# ---------------------------------------------------------------------------
# 7. Migrasi
# ---------------------------------------------------------------------------
step "$(t "Menjalankan migrasi database" "Running database migrations")"
run_artisan migrate --force
ok "$(t "Migrasi selesai" "Migrations complete")"

# ---------------------------------------------------------------------------
# 8. Permission
# ---------------------------------------------------------------------------
step "$(t "Mengatur permission" "Setting permissions")"
chown -R "${APP_USER}:${APP_USER}" "$PROJECT_DIR/storage" "$PROJECT_DIR/bootstrap/cache"
chmod -R ug+rwX "$PROJECT_DIR/storage" "$PROJECT_DIR/bootstrap/cache"
# .env hanya boleh dibaca root + grup www-data
chown root:"${APP_USER}" "$PROJECT_DIR/.env"
chmod 640 "$PROJECT_DIR/.env"
# storage symlink agar logo upload bisa diakses publik
run_artisan storage:link >/dev/null 2>&1 || true
ok "$(t "Permission diatur" "Permissions set") (.env = 640 root:${APP_USER})"

# ---------------------------------------------------------------------------
# 9. Nginx
# ---------------------------------------------------------------------------
step "$(t "Mengonfigurasi Nginx" "Configuring Nginx")"
SERVER_NAME="$(printf '%s' "$APP_URL" | sed -E 's#^https?://##; s#/.*$##')"
PHP_SOCK="/run/php/php${PHP_VERSION}-fpm.sock"
NGINX_SITE="/etc/nginx/sites-available/kusumavision-nms"
cat > "$NGINX_SITE" <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name ${SERVER_NAME} ${PRIMARY_IP} _;
    root ${PROJECT_DIR}/public;

    index index.php;
    charset utf-8;
    server_tokens off;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";
    add_header Referrer-Policy "strict-origin-when-cross-origin";
    # HSTS — efektif setelah blok ini melayani HTTPS (certbot mengangkatnya ke
    # `listen 443 ssl`); di HTTP diabaikan browser. `always` = kirim juga saat error.
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;

    client_max_body_size 20M;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    # WebSocket proxy untuk browser telnet (daemon telnet:proxy @ 127.0.0.1:6002)
    location /telnet-ws {
        proxy_pass http://127.0.0.1:6002;
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host \$host;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
    }

    # APK Android: jangan di-cache CDN/proxy (Cloudflare) — tiap build harus fresh.
    location ~* ^/downloads/.*\.apk\$ {
        add_header Cache-Control "no-store" always;
        try_files \$uri =404;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    location ~ \.php\$ {
        fastcgi_pass unix:${PHP_SOCK};
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;

        # Header respons Laravel bisa besar (Vite "Link: preload" + nonce CSP);
        # buffer FastCGI default (~8k) meluap -> 502 "upstream sent too big header".
        fastcgi_buffer_size 32k;
        fastcgi_buffers 16 16k;
        fastcgi_busy_buffers_size 64k;
    }

    # Tolak file sensitif
    location ~ /\.(?!well-known).* { deny all; }
    location ~* \.(env|bak|sql|log|ya?ml)\$ { deny all; }
}
NGINX

ln -sf "$NGINX_SITE" /etc/nginx/sites-enabled/kusumavision-nms
[ -e /etc/nginx/sites-enabled/default ] && rm -f /etc/nginx/sites-enabled/default || true
nginx -t
systemctl reload nginx
ok "$(t "Nginx aktif" "Nginx active") (root ${PROJECT_DIR}/public, /telnet-ws → :6002)"

# ---------------------------------------------------------------------------
# 10. Supervisor (worker, scheduler, telnet proxy)
# ---------------------------------------------------------------------------
step "$(t "Mendaftarkan daemon Supervisor" "Registering Supervisor daemons")"
PHP_BIN="$(command -v "$PHP_CLI" || command -v php)"
write_supervisor() {
  local name="$1" cmd="$2"
  cat > "/etc/supervisor/conf.d/${name}.conf" <<SUP
[program:${name}]
process_name=%(program_name)s
command=${cmd}
directory=${PROJECT_DIR}
autostart=true
autorestart=true
user=${APP_USER}
redirect_stderr=true
stdout_logfile=/var/log/supervisor/${name}.log
stopwaitsecs=15
SUP
}
write_supervisor "kusumavision-worker"       "${PHP_BIN} ${PROJECT_DIR}/artisan queue:work redis --tries=1 --max-time=3600"
write_supervisor "kusumavision-scheduler"    "${PHP_BIN} ${PROJECT_DIR}/artisan schedule:work"
write_supervisor "kusumavision-telnet-proxy" "${PHP_BIN} ${PROJECT_DIR}/artisan telnet:proxy"

supervisorctl reread
supervisorctl update
ok "$(t "Daemon worker, scheduler, telnet-proxy terdaftar" "Daemons registered: worker, scheduler, telnet-proxy")"

# ---------------------------------------------------------------------------
# 11. Cache produksi
# ---------------------------------------------------------------------------
step "$(t "Membangun cache produksi (config/route/view)" "Building production caches (config/route/view)")"
run_artisan optimize:clear >/dev/null 2>&1 || true
run_artisan optimize
# artisan dijalankan sbg root -> kembalikan ownership cache ke www-data
chown -R "${APP_USER}:${APP_USER}" "$PROJECT_DIR/storage" "$PROJECT_DIR/bootstrap/cache"
run_artisan queue:restart >/dev/null 2>&1 || true
supervisorctl restart kusumavision-telnet-proxy >/dev/null 2>&1 || true
ok "$(t "Cache produksi siap" "Production caches ready")"

# ---------------------------------------------------------------------------
# 12. Akun admin
# ---------------------------------------------------------------------------
step "$(t "Akun administrator" "Administrator account")"
if [ "$ASSUME_YES" = "1" ] && [ -z "$ADMIN_EMAIL" ]; then
  info "$(t "Mode --yes tanpa ADMIN_EMAIL → lewati. Buat manual nanti: php artisan user:create" \
            "--yes without ADMIN_EMAIL → skipped. Create one later: php artisan user:create")"
elif confirm "$(t "Buat akun admin sekarang?" "Create an admin account now?")" "Y"; then
  ask ADMIN_NAME     "$(t "Nama admin" "Admin name")"   "${ADMIN_NAME:-Administrator}"
  ask ADMIN_EMAIL    "$(t "Email admin" "Admin email")" "${ADMIN_EMAIL:-admin@${SERVER_NAME}}"
  if [ -z "$ADMIN_PASSWORD" ]; then ask ADMIN_PASSWORD "$(t "Password admin" "Admin password")" ""; fi
  if [ -n "$ADMIN_EMAIL" ] && [ -n "$ADMIN_PASSWORD" ]; then
    run_artisan user:create --name="$ADMIN_NAME" --email="$ADMIN_EMAIL" --password="$ADMIN_PASSWORD" \
      && sudo -u postgres psql -d "$DB_NAME" -c "UPDATE users SET role='admin', email_verified_at=now() WHERE email='${ADMIN_EMAIL//\'/\'\'}';" >/dev/null 2>&1 || true
    ok "$(t "Admin dibuat: $ADMIN_EMAIL (role admin)" "Admin created: $ADMIN_EMAIL (role admin)")"
  else
    warn "$(t "Email/password kosong → admin tidak dibuat. Jalankan: php artisan user:create" \
              "Empty email/password → no admin created. Run: php artisan user:create")"
  fi
fi

# ---------------------------------------------------------------------------
# 13. UFW (opsional)
# ---------------------------------------------------------------------------
if [ "$ENABLE_UFW" = "1" ]; then
  step "$(t "Mengonfigurasi UFW (SSH + HTTP)" "Configuring UFW (SSH + HTTP)")"
  apt-get install -y ufw
  ufw allow OpenSSH || ufw allow 22/tcp
  ufw allow 80/tcp
  ufw --force enable
  ok "$(t "UFW aktif (22, 80)" "UFW enabled (22, 80)")"
fi

# ---------------------------------------------------------------------------
# 14. Smoke test + ringkasan
# ---------------------------------------------------------------------------
step "Smoke test"
HOME_CODE="$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1/" || echo 000)"
DASH_CODE="$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1/dashboard" || echo 000)"
info "GET /          → ${HOME_CODE} $(t "(harap 200)" "(expected 200)")"
info "GET /dashboard → ${DASH_CODE} $(t "(harap 302 ke /login)" "(expected 302 to /login)")"
APP_LOCALE="$APP_LOCALE" bash "$PROJECT_DIR/scripts/check-requirements.sh" || true

# printf %b (bukan heredoc) supaya kode warna \033 benar-benar ditafsirkan.
printf '\n%b\n' "${c_green}============================================================${c_reset}"
printf '%b%s%b\n' "${c_green} " "$(t "KusumaVision NMS terpasang." "KusumaVision NMS is installed.")" "${c_reset}"
printf '%b\n\n' "${c_green}============================================================${c_reset}"
row "URL"                                        "${APP_URL}"
row "Project dir"                                "${PROJECT_DIR}"
row "Database"                                   "${DB_NAME} / ${DB_USER}"
row "DB password"                                "${DB_PASSWORD}"
if [ -n "$ADMIN_EMAIL" ]; then row "Admin login" "${ADMIN_EMAIL}"; fi
printf '\n'
row "$(t "Cek daemon" "Daemon status")"          "supervisorctl status"
row "$(t "Log aplikasi" "App log")"              "storage/logs/laravel.log"
row "$(t "Buat user lain" "Add more users")"     "cd ${PROJECT_DIR} && php artisan user:create"
cat <<DONE
  $(t "HTTPS (opsional, disarankan):" "HTTPS (optional, recommended):")
    sudo apt install -y certbot python3-certbot-nginx
    sudo certbot --nginx -d <domain>

  $(t "PENTING: simpan DB password di atas. Setelah ubah .env/config jalankan:" \
      "IMPORTANT: keep the DB password above. After changing .env/config, run:")
    php artisan config:cache && php artisan queue:restart
    supervisorctl restart kusumavision-telnet-proxy

DONE
