#!/usr/bin/env bash
#
# Jalankan test suite PHP tanpa membaca maupun merusak cache bootstrap produksi.
#
# KENAPA FILE INI ADA
# Server produksi menjalankan aplikasi dari checkout ini (APP_ENV=production, pgsql,
# bootstrap/cache/config.php aktif). Dua cara "biasa" menjalankan test sama-sama salah:
#
#   php artisan test            -> BAHAYA. Cached config dibaca lebih dulu dan MENANG atas
#                                 <env DB_CONNECTION="sqlite"> di phpunit.xml, jadi koneksi
#                                 resolve ke pgsql "kusumavision_nms" — database produksi.
#                                 Test ber-RefreshDatabase akan menghapus isinya.
#   php artisan config:clear    -> DB aman, tapi cache config produksi ikut terhapus dan
#                                 tidak pernah dipulihkan; situs jalan tanpa cache setelahnya.
#
# Yang benar: arahkan path cache ke lokasi yang tidak ada, supaya framework jatuh kembali ke
# .env + <env> phpunit apa adanya, sementara file cache produksi tidak disentuh sama sekali.
#
# HANYA APP_CONFIG_CACHE dan APP_ROUTES_CACHE yang boleh dialihkan. Keduanya read-only saat
# boot (cuma ditulis oleh config:cache / route:cache). JANGAN alihkan APP_SERVICES_CACHE atau
# APP_PACKAGES_CACHE — keduanya ditulis ulang on-demand saat boot, dan menunjuk ke path yang
# tak bisa ditulis akan melempar exception.
#
# Pemakaian:
#   bash scripts/test.sh                       # seluruh suite
#   bash scripts/test.sh --filter=OdpTest      # argumen diteruskan ke `artisan test`
#   composer test                              # sama saja, lewat composer

set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

export APP_CONFIG_CACHE=/nonexistent/kv-test-config.php
export APP_ROUTES_CACHE=/nonexistent/kv-test-routes.php

# --- Pengaman 1: path pengalihan memang tidak boleh ada -----------------------
for p in "$APP_CONFIG_CACHE" "$APP_ROUTES_CACHE"; do
    if [ -e "$p" ]; then
        echo "ABORT: $p ternyata ada. Pengalihan cache gagal — test bisa memakai config produksi." >&2
        exit 1
    fi
done

# --- Pengaman 2: buktikan koneksi DB benar-benar sqlite sebelum test jalan ----
# Meniru persis <env> di phpunit.xml. Kalau hasilnya bukan sqlite, ada yang berubah
# (phpunit.xml, .env, atau cache) dan test TIDAK boleh diteruskan.
probe=$(APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= \
    php artisan tinker --execute="echo config('database.default').'|'.config('database.connections.'.config('database.default').'.database');" \
    2>/dev/null | tr -d '[:space:]')

if [ "$probe" != "sqlite|:memory:" ]; then
    echo "ABORT: test akan memakai '$probe', bukan 'sqlite|:memory:'." >&2
    echo "       Menjalankan test sekarang berisiko menghapus database produksi." >&2
    echo "       Periksa phpunit.xml, .env, dan bootstrap/cache/ sebelum mencoba lagi." >&2
    exit 1
fi

echo "Pengaman lolos: test memakai sqlite :memory:, cache produksi tidak disentuh."
echo

php artisan test "$@"
