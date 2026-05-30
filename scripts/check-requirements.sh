#!/usr/bin/env bash
#
# check-requirements.sh — Verifikasi requirement & status deployment KusumaVision NMS.
#
#   bash scripts/check-requirements.sh
#
# Bagian "Tools" & "PHP extensions" bersifat WAJIB (mempengaruhi exit code).
# Bagian "Runtime" & "Services" bersifat informatif (warning, tidak menggagalkan)
# karena bisa dijalankan sebelum deploy. Exit 0 bila semua requirement wajib OK.
#
set -u

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

failures=0
warnings=0

c_green="\033[1;32m"; c_red="\033[1;31m"; c_yellow="\033[1;33m"; c_reset="\033[0m"
ok()   { printf "${c_green}[OK]${c_reset}   %s\n" "$*"; }
miss() { printf "${c_red}[MISS]${c_reset} %s\n" "$*"; failures=$((failures + 1)); }
warn() { printf "${c_yellow}[WARN]${c_reset} %s\n" "$*"; warnings=$((warnings + 1)); }

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
    miss "$label: '$cmd' tidak ditemukan"
    return
  fi
  ver="$(extract_version "$cmd")"
  if [ -n "$min" ] && [ -n "$ver" ]; then
    if version_ge "$ver" "$min"; then
      ok "$label: $ver (min $min)"
    else
      miss "$label: $ver < minimal $min"
    fi
  else
    ok "$label: ${ver:-terpasang}"
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
  if [ -e "$PROJECT_DIR/$path" ]; then ok "$label ($path)"; else warn "$label tidak ada ($path)"; fi
}

check_service() {
  local label="$1" svc="$2"
  command -v systemctl >/dev/null 2>&1 || { warn "$label: systemctl tidak tersedia"; return; }
  if systemctl is-active --quiet "$svc"; then ok "$label: aktif"; else warn "$label: tidak aktif ($svc)"; fi
}

check_supervisor() {
  local prog="$1" state
  command -v supervisorctl >/dev/null 2>&1 || { warn "supervisor: tidak terpasang"; return; }
  state="$(supervisorctl status "$prog" 2>/dev/null | awk '{print $2}')"
  if [ "$state" = "RUNNING" ]; then ok "daemon $prog: RUNNING"; else warn "daemon $prog: ${state:-tidak terdaftar}"; fi
}

printf "%s\n" "KusumaVision NMS — requirement & deployment check"
printf "%s\n" "================================================="

printf "\n%s\n%s\n" "Tools (wajib)" "-------------"
check_tool "PHP"               php       8.2
check_tool "Composer"          composer  2
check_tool "Node.js"           node      20
check_tool "npm"               npm       10
check_tool "Go"                go        1.18
check_tool "PostgreSQL client" psql      14
check_tool "Redis CLI"         redis-cli
check_tool "SNMP walk"         snmpwalk

printf "\n%s\n%s\n" "PHP extensions (wajib)" "----------------------"
for extension in bcmath ctype curl dom fileinfo intl mbstring openssl pcntl pdo_pgsql pdo_sqlite redis snmp tokenizer xml zip; do
  check_php_extension "$extension"
done

printf "\n%s\n%s\n" "Runtime artefak (info)" "----------------------"
check_artifact "Go SNMP poller binary" "bin/kv-snmp-poller"
check_artifact "Frontend build"        "public/build/manifest.json"
check_artifact "File .env"             ".env"
if [ -f "$PROJECT_DIR/.env" ]; then
  if grep -qE '^APP_KEY=base64:' "$PROJECT_DIR/.env"; then ok "APP_KEY ter-set"; else warn "APP_KEY belum di-generate (php artisan key:generate)"; fi
fi

printf "\n%s\n%s\n" "Services (info)" "---------------"
check_service "PostgreSQL" postgresql
check_service "Redis"      redis-server
check_service "Nginx"      nginx
check_service "Supervisor" supervisor
check_supervisor kusumavision-worker
check_supervisor kusumavision-scheduler
check_supervisor kusumavision-telnet-proxy

printf "\n%s\n%s\n" "Ringkasan" "---------"
if [ "$warnings" -gt 0 ]; then printf "${c_yellow}%d peringatan (info, tidak menggagalkan).${c_reset}\n" "$warnings"; fi
if [ "$failures" -eq 0 ]; then
  printf "${c_green}Semua requirement wajib terpenuhi.${c_reset}\n"
  exit 0
fi
printf "${c_red}%d requirement wajib perlu diperbaiki.${c_reset}\n" "$failures"
exit 1
