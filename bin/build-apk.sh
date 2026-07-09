#!/usr/bin/env bash
# Build APK release aplikasi Android KusumaVision NMS (mobile/).
#
# Pakai:
#   API_BASE_URL=https://nms.kusumavision.net/api/v1 bash bin/build-apk.sh
#
# Prasyarat (sudah dipasang oleh sesi setup): JDK 17, Android SDK di /opt/android-sdk,
# Flutter di /opt/flutter. gradle.properties di mobile/android sudah dikonstrain untuk
# server 8GB (heap 2g, tanpa daemon) agar tidak swap-thrash.
set -euo pipefail

export JAVA_HOME="${JAVA_HOME:-/usr/lib/jvm/java-17-openjdk-amd64}"
export ANDROID_SDK_ROOT="${ANDROID_SDK_ROOT:-/opt/android-sdk}"
export ANDROID_HOME="$ANDROID_SDK_ROOT"
export PATH="$PATH:/opt/flutter/bin:$ANDROID_SDK_ROOT/platform-tools"

API_BASE_URL="${API_BASE_URL:-https://nms.kusumavision.net/api/v1}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT/mobile"

echo "==> flutter pub get"
flutter pub get

echo "==> flutter analyze"
flutter analyze

echo "==> build apk --release --split-per-abi (API_BASE_URL=$API_BASE_URL)"
# --split-per-abi: satu APK per arsitektur (bukan universal 3-in-1) → jauh lebih kecil.
# --target-platform android-arm,android-arm64: buang x86_64 (emulator-only) yang tak
# dipakai HP asli. Hasil: app-arm64-v8a-release.apk (~20MB) + app-armeabi-v7a-release.apk.
flutter build apk --release --split-per-abi \
  --target-platform android-arm,android-arm64 \
  --dart-define=API_BASE_URL="$API_BASE_URL"

OUT="$ROOT/mobile/build/app/outputs/flutter-apk"
ARM64="$OUT/app-arm64-v8a-release.apk"
ARM32="$OUT/app-armeabi-v7a-release.apk"
echo "==> APK arm64: $ARM64 ($(du -h "$ARM64" | cut -f1))"
echo "==> APK arm32: $ARM32 ($(du -h "$ARM32" | cut -f1))"

# Salin ke public/downloads agar bisa di-sideload dari NMS itu sendiri (opsional).
# arm64 = download utama (mencakup hampir semua HP modern); arm32 = fallback HP lama.
DEST="$ROOT/public/downloads"
mkdir -p "$DEST"
cp "$ARM64" "$DEST/kusumavision-nms.apk"
cp "$ARM32" "$DEST/kusumavision-nms-arm32.apk"
echo "==> Disalin: $DEST/kusumavision-nms.apk (arm64, /downloads/kusumavision-nms.apk)"
echo "==>         $DEST/kusumavision-nms-arm32.apk (fallback HP 32-bit lama)"
