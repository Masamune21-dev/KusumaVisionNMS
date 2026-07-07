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

echo "==> build apk --release (API_BASE_URL=$API_BASE_URL)"
flutter build apk --release --dart-define=API_BASE_URL="$API_BASE_URL"

APK="$ROOT/mobile/build/app/outputs/flutter-apk/app-release.apk"
echo "==> APK: $APK ($(du -h "$APK" | cut -f1))"

# Salin ke public/downloads agar bisa di-sideload dari NMS itu sendiri (opsional).
DEST="$ROOT/public/downloads"
mkdir -p "$DEST"
cp "$APK" "$DEST/kusumavision-nms.apk"
echo "==> Disalin ke $DEST/kusumavision-nms.apk (URL: /downloads/kusumavision-nms.apk)"
