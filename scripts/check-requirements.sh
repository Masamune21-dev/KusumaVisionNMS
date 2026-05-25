#!/usr/bin/env bash
set -u

failures=0

check_command() {
  local label="$1"
  local command="$2"

  if command -v "$command" >/dev/null 2>&1; then
    printf "[OK]   %s: " "$label"
    case "$command" in
      go)
        "$command" version 2>/dev/null | head -n 1 || true
        ;;
      snmpwalk)
        "$command" -V 2>&1 | head -n 1 || true
        ;;
      node|npm|composer|php|psql|redis-cli)
        "$command" --version 2>/dev/null | head -n 1 || true
        ;;
      *)
        command -v "$command"
        ;;
    esac
  else
    printf "[MISS] %s: %s not found\n" "$label" "$command"
    failures=$((failures + 1))
  fi
}

check_php_extension() {
  local extension="$1"

  if php -m 2>/dev/null | grep -qi "^${extension}$"; then
    printf "[OK]   PHP extension: %s\n" "$extension"
  else
    printf "[MISS] PHP extension: %s\n" "$extension"
    failures=$((failures + 1))
  fi
}

printf "%s\n" "KusumaVision NMS requirement check"
printf "%s\n\n" "=================================="

check_command "PHP" php
check_command "Composer" composer
check_command "Node.js" node
check_command "npm" npm
check_command "Go" go
check_command "PostgreSQL client" psql
check_command "Redis CLI" redis-cli
check_command "SNMP walk" snmpwalk

printf "\n%s\n" "PHP extensions"
printf "%s\n" "--------------"

for extension in bcmath curl dom intl mbstring openssl pcntl pdo_pgsql pdo_sqlite redis snmp sockets xml zip; do
  check_php_extension "$extension"
done

printf "\n%s\n" "Summary"
printf "%s\n" "-------"

if [ "$failures" -eq 0 ]; then
  printf "All required checks passed.\n"
  exit 0
fi

printf "%d check(s) need attention.\n" "$failures"
exit 1
