#!/usr/bin/env bash
#
# entrypoint.sh — first-run & boot container app KusumaVision NMS.
# Idempotent: aman dijalankan ulang tiap restart / upgrade.
#
#   1. Siapkan skeleton storage (volume mulai kosong) + izin.
#   2. Tunggu PostgreSQL siap.
#   3. APP_KEY: pakai dari env; kalau kosong, generate sekali & simpan di volume.
#   4. migrate --force  → skema DB.
#   5. Admin opsional (ADMIN_EMAIL/ADMIN_PASSWORD, hanya bila tabel users kosong).
#   6. storage:link + optimize (config/route/view cache, sama seperti install.sh).
#   7. Kembalikan ownership ke www-data, exec supervisord.
set -euo pipefail

APP_DIR=/var/www/html
cd "$APP_DIR"

log() { printf '\033[1;34m[entrypoint]\033[0m %s\n' "$*"; }

# ---------------------------------------------------------------------------
# 0. Bersihkan trailing CR (\r) dari nilai .env host bila diedit di Windows
#    (CRLF). Nilai ber-\r merusak APP_KEY (dekripsi gagal → 500) atau APP_URL
#    tanpa pesan jelas. HANYA var yang dikonsumsi app — DB_* sengaja dilewati
#    karena harus identik dengan yang dipakai container "db" (postgres).
# ---------------------------------------------------------------------------
for _v in APP_KEY APP_NAME APP_URL APP_LOCALE \
          ACS_URL ACS_USERNAME ACS_PASSWORD \
          ADMIN_NAME ADMIN_EMAIL ADMIN_PASSWORD; do
  eval "_cur=\${$_v-}"
  case "$_cur" in
    *$'\r') export "$_v=${_cur%$'\r'}" ;;
  esac
done

# ---------------------------------------------------------------------------
# 1. Skeleton storage (mount volume mulai kosong → framework butuh subfolder ini)
# ---------------------------------------------------------------------------
log "Menyiapkan storage & izin"
mkdir -p \
  storage/app/public \
  storage/app/private \
  storage/framework/cache/data \
  storage/framework/sessions \
  storage/framework/views \
  storage/framework/testing \
  storage/logs \
  bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache

# ---------------------------------------------------------------------------
# 2. Tunggu PostgreSQL
# ---------------------------------------------------------------------------
DB_HOST="${DB_HOST:-db}"
DB_PORT="${DB_PORT:-5432}"
DB_USERNAME="${DB_USERNAME:-kusumavision}"
log "Menunggu PostgreSQL di ${DB_HOST}:${DB_PORT}"
for i in $(seq 1 60); do
  if pg_isready -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" >/dev/null 2>&1; then
    log "PostgreSQL siap"
    break
  fi
  [ "$i" = "60" ] && { log "PostgreSQL tak kunjung siap setelah 60x — lanjut, migrasi mungkin gagal"; }
  sleep 2
done

# ---------------------------------------------------------------------------
# 3. APP_KEY — pakai env; kalau kosong generate sekali & persist di volume storage
# ---------------------------------------------------------------------------
KEY_FILE=storage/app/.appkey
if [ -z "${APP_KEY:-}" ]; then
  if [ -s "$KEY_FILE" ]; then
    APP_KEY="$(cat "$KEY_FILE")"
    log "APP_KEY dimuat dari volume"
  else
    APP_KEY="$(php artisan key:generate --show)"
    printf '%s' "$APP_KEY" > "$KEY_FILE"
    chown www-data:www-data "$KEY_FILE"
    log "APP_KEY digenerate & disimpan (persist antar restart)"
  fi
  export APP_KEY
fi

# ---------------------------------------------------------------------------
# 4. Migrasi
# ---------------------------------------------------------------------------
log "Menjalankan migrasi"
php artisan migrate --force

# ---------------------------------------------------------------------------
# 5. Admin opsional (hanya saat instalasi pertama / tabel users kosong)
# ---------------------------------------------------------------------------
if [ -n "${ADMIN_EMAIL:-}" ] && [ -n "${ADMIN_PASSWORD:-}" ]; then
  USER_COUNT="$(php artisan tinker --execute='echo \App\Models\User::count();' 2>/dev/null | tail -n1 | tr -dc '0-9')"
  if [ "${USER_COUNT:-0}" = "0" ]; then
    log "Membuat akun admin: ${ADMIN_EMAIL}"
    php artisan user:create --name="${ADMIN_NAME:-Administrator}" --email="$ADMIN_EMAIL" --password="$ADMIN_PASSWORD" || true
    php artisan tinker --execute="\App\Models\User::where('email', strtolower(getenv('ADMIN_EMAIL')))->update(['role' => 'admin', 'email_verified_at' => now()]);" || true
  fi
fi

# ---------------------------------------------------------------------------
# 6. storage:link + cache produksi
# ---------------------------------------------------------------------------
php artisan storage:link 2>/dev/null || true
log "Membangun cache produksi (optimize)"
php artisan optimize:clear >/dev/null 2>&1 || true
php artisan optimize

# ---------------------------------------------------------------------------
# 7. Ownership akhir + jalankan semua proses
# ---------------------------------------------------------------------------
chown -R www-data:www-data storage bootstrap/cache
log "Siap — menyalakan php-fpm, nginx, worker, scheduler, telnet-proxy"
exec supervisord -n -c /etc/supervisor/supervisord.conf
