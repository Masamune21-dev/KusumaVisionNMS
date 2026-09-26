#!/usr/bin/env bash
#
# check-requirements.sh — Verifikasi requirement & status deployment KusumaVision NMS.
#
#   bash scripts/check-requirements.sh [--lang en|id]
#
# Bagian "Tools" & "PHP extensions" bersifat WAJIB (mempengaruhi exit code).
# Bagian "Runtime" & "Services" bersifat informatif (warning, tidak menggagalkan)
# karena bisa dijalankan sebelum deploy. Exit 0 bila semua requirement wajib OK.
#
# Bahasa keluaran: --lang, lalu env APP_LOCALE (dikirim install.sh), lalu APP_LOCALE
# di .env, lalu locale shell (id* = Indonesia, selain itu Inggris).
#
set -u

# Saat dijalankan sebagai root, Composer interaktif akan menampilkan peringatan +
# bertanya "Continue as root/super user [yes]?" dan menunggu input. Karena
# pemanggilan composer di bawah membuang stderr (2>/dev/null), promptnya tak
# terlihat sehingga script seolah berhenti menunggu Enter. Set kedua variabel ini
# agar Composer tidak prompt sama sekali.
export COMPOSER_ALLOW_SUPERUSER=1
export COMPOSER_NO_INTERACTION=1

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

UI_LANG="${APP_LOCALE:-}"
case "${1:-}" in
  --lang=*) UI_LANG="${1#--lang=}" ;;
  --lang)   UI_LANG="${2:-}" ;;
esac
if [ -z "$UI_LANG" ] && [ -r "$PROJECT_DIR/.env" ]; then
  UI_LANG="$(sed -n -E 's/^APP_LOCALE="?([A-Za-z]+)"?.*/\1/p' "$PROJECT_DIR/.env" | tail -n1)"
fi
UI_LANG="$(printf '%s' "$UI_LANG" | tr '[:upper:]' '[:lower:]')"
case "$UI_LANG" in
  id|en) ;;
  *) case "${LC_ALL:-${LC_MESSAGES:-${LANG:-}}}" in id*) UI_LANG="id" ;; *) UI_LANG="en" ;; esac ;;
esac

# t "teks Indonesia" "English text" -> cetak sesuai UI_LANG.
t() { if [ "$UI_LANG" = "en" ]; then printf '%s' "$2"; else printf '%s' "$1"; fi; }

failures=0
warnings=0

c_green="\033[1;32m"; c_red="\033[1;31m"; c_yellow="\033[1;33m"; c_reset="\033[0m"
ok()   { printf "${c_green}[OK]${c_reset}   %s\n" "$*"; }
miss() { printf "${c_red}[MISS]${c_reset} %s\n" "$*"; failures=$((failures + 1)); }
warn() { printf "${c_yellow}[WARN]${c_reset} %s\n" "$*"; warnings=$((warnings + 1)); }

# section "Judul" -> judul bagian bergaris bawah sepanjang judulnya.
section() { printf "\n%s\n%s\n" "$1" "$(printf '%s' "$1" | sed 's/./-/g')"; }

# version_ge "3.10" "3.2"  -> true jika $1 >= $2
version_ge() {
  [ "$(printf '%s\n%s\n' "$2" "$1" | sort -V | head -n1)" = "$2" ]
}

# extract_version <command> -> cetak versi numerik pertama (mis. 8.3.6)
extract_version() {
  local cmd="$1" out=""
  case "$cmd" in
    php)       out="$(php -r 'echo PHP_VERSION;' 2>/dev/null)" ;;
    go)        out="$(go version 2>/dev/null | grep -oE 'go[0-9.]+' | head -n1 | tr -d 'go')" ;;
    composer)  out="$(composer --version 2>/dev/null | grep -oE '[0-9]+\.[0-9.]+' | head -n1)" ;;
    node)      out="$(node --version 2>/dev/null | tr -d 'v')" ;;
    npm)       out="$(npm --version 2>/dev/null)" ;;
    psql)      out="$(psql --version 2>/dev/null | grep -oE '[0-9]+(\.[0-9]+)*' | head -n1)" ;;
    redis-cli) out="$(redis-cli --version 2>/dev/null | grep -oE '[0-9]+(\.[0-9]+)*' | head -n1)" ;;
    snmpwalk)  out="$(snmpwalk -V 2>&1 | grep -oE '[0-9]+(\.[0-9]+)*' | head -n1)" ;;
  esac
  printf '%s' "$out"
}

# check_tool "Label" command [min_version]
check_tool() {
  local label="$1" cmd="$2" min="${3:-}" ver
  if ! command -v "$cmd" >/dev/null 2>&1; then
    miss "$label: '$cmd' $(t "tidak ditemukan" "not found")"
    return
  fi
  ver="$(extract_version "$cmd")"
  if [ -n "$min" ] && [ -n "$ver" ]; then
    if version_ge "$ver" "$min"; then
      ok "$label: $ver (min $min)"
    else
      miss "$label: $ver < $(t "minimal" "minimum") $min"
    fi
  else
    ok "$label: ${ver:-$(t "terpasang" "installed")}"
  fi
}

check_php_extension() {
  local extension="$1"
  if php -m 2>/dev/null | grep -qi "^${extension}$"; then
    ok "PHP ext: $extension"
  else
    miss "PHP ext: $extension"
  fi
}

check_artifact() {
  local label="$1" path="$2"
  if [ -e "$PROJECT_DIR/$path" ]; then ok "$label ($path)"; else warn "$label $(t "tidak ada" "missing") ($path)"; fi
}

check_service() {
  local label="$1" svc="$2"
  command -v systemctl >/dev/null 2>&1 || { warn "$label: $(t "systemctl tidak tersedia" "systemctl not available")"; return; }
  if systemctl is-active --quiet "$svc"; then ok "$label: $(t "aktif" "active")"; else warn "$label: $(t "tidak aktif" "inactive") ($svc)"; fi
}

check_supervisor() {
  local prog="$1" state
  command -v supervisorctl >/dev/null 2>&1 || { warn "supervisor: $(t "tidak terpasang" "not installed")"; return; }
  state="$(supervisorctl status "$prog" 2>/dev/null | awk '{print $2}')"
  if [ "$state" = "RUNNING" ]; then ok "daemon $prog: RUNNING"; else warn "daemon $prog: ${state:-$(t "tidak terdaftar" "not registered")}"; fi
}

printf "%s\n" "KusumaVision NMS — requirement & deployment check"
printf "%s\n" "================================================="

section "$(t "Tools (wajib)" "Tools (required)")"
check_tool "PHP"               php       8.2
check_tool "Composer"          composer  2
check_tool "Node.js"           node      20
check_tool "npm"               npm       10
check_tool "Go"                go        1.18
check_tool "PostgreSQL client" psql      14
check_tool "Redis CLI"         redis-cli
check_tool "SNMP walk"         snmpwalk

section "$(t "PHP extensions (wajib)" "PHP extensions (required)")"
for extension in bcmath ctype curl dom fileinfo intl mbstring openssl pcntl pdo_pgsql pdo_sqlite redis snmp tokenizer xml zip; do
  check_php_extension "$extension"
done

section "$(t "Opsional (info)" "Optional (info)")"
# cwebp (paket 'webp'): mengonversi foto ODP ke WebP. Tanpa ini fitur tetap jalan,
# foto hanya disimpan dalam format aslinya (lebih besar).
if command -v cwebp >/dev/null 2>&1; then
  ok "cwebp: $(cwebp -version 2>/dev/null | head -n1)"
else
  warn "$(t "cwebp tidak ditemukan (apt install webp) — foto ODP tak dikonversi ke WebP" \
            "cwebp not found (apt install webp) — ODP photos won't be converted to WebP")"
fi
# Foto dari HP umumnya 3–8 MB; default PHP 2M akan menolaknya.
php_upload="$(php -r 'echo ini_get("upload_max_filesize");' 2>/dev/null || echo '?')"
php_upload_mb="$(php -r '$v=ini_get("upload_max_filesize"); echo (int) $v * (stripos($v,"g") !== false ? 1024 : 1);' 2>/dev/null || echo 0)"
if [ "${php_upload_mb:-0}" -ge 12 ] 2>/dev/null; then
  ok "upload_max_filesize: $php_upload"
else
  warn "upload_max_filesize: $php_upload $(t "(disarankan ≥ 12M untuk foto ODP)" "(≥ 12M recommended for ODP photos)")"
fi

section "$(t "Runtime artefak (info)" "Runtime artifacts (info)")"
check_artifact "Go SNMP poller binary" "bin/kv-snmp-poller"
check_artifact "Frontend build"        "public/build/manifest.json"
check_artifact "$(t "File .env" ".env file")" ".env"
if [ -f "$PROJECT_DIR/.env" ]; then
  if grep -qE '^APP_KEY=base64:' "$PROJECT_DIR/.env"; then
    ok "$(t "APP_KEY ter-set" "APP_KEY is set")"
  else
    warn "$(t "APP_KEY belum di-generate (php artisan key:generate)" "APP_KEY not generated yet (php artisan key:generate)")"
  fi
fi

section "Services (info)"
check_service "PostgreSQL" postgresql
check_service "Redis"      redis-server
check_service "Nginx"      nginx
check_service "Supervisor" supervisor
check_supervisor kusumavision-worker
check_supervisor kusumavision-scheduler
check_supervisor kusumavision-telnet-proxy

section "$(t "Ringkasan" "Summary")"
if [ "$warnings" -gt 0 ]; then
  printf "${c_yellow}%s${c_reset}\n" "$(t "$warnings peringatan (info, tidak menggagalkan)." "$warnings warning(s) (info only, not a failure).")"
fi
if [ "$failures" -eq 0 ]; then
  printf "${c_green}%s${c_reset}\n" "$(t "Semua requirement wajib terpenuhi." "All required checks passed.")"
  exit 0
fi
printf "${c_red}%s${c_reset}\n" "$(t "$failures requirement wajib perlu diperbaiki." "$failures required check(s) need fixing.")"
exit 1
