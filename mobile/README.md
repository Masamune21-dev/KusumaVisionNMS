# KusumaVision NMS — Aplikasi Android (Flutter)

Aplikasi pendamping untuk memonitor & memprovisioning OLT/ONU FTTH GPON dari HP,
mengonsumsi **REST API v1** KusumaVision NMS (`/api/v1`, lihat `../docs/API.md`).

## Fitur

- **Login** (Sanctum bearer token) + auto-refresh sesi.
- **Dashboard** ringkasan OLT/ONU/alarm + persentase online.
- **Pencarian global** (OLT, SN ONU, nama pelanggan) dengan deep-link.
- **Inventory OLT** → detail OLT → daftar port → **ONU per port** → **detail ONU** (RX power berwarna).
- **Unconfigured ONU** + discovery live + CTA **Registrasi ONU** (ZTE, mode dasar: preview script → eksekusi).
- **Aksi ONU**: reboot & ubah nama (admin/operator; demo read-only).
- **Alarm** dengan filter severity.
- **Notifikasi push FCM** saat alarm naik/turun (deep-link ke ONU/OLT terkait).

## Stack

Flutter 3.44 · Riverpod v2 · dio · go_router · flutter_secure_storage ·
firebase_core/messaging + flutter_local_notifications · Material 3 dark-glass (aksen cyan/sky).

## Build

> 🧰 **Belum punya toolchain (Flutter/Android SDK/JDK)?** Panduan pasang **dari nol** (Linux &
> Windows) + minimum spek + signing + install di HP: **[`../docs/BUILD_APK.md`](../docs/BUILD_APK.md)**.
> Bagian di bawah mengasumsikan toolchain sudah terpasang.

```bash
# dari root repo
API_BASE_URL=https://nms.kusumavision.net/api/v1 bash bin/build-apk.sh
# atau manual:
cd mobile
flutter pub get
flutter build apk --release --dart-define=API_BASE_URL=https://<host>/api/v1
```

APK: `mobile/build/app/outputs/flutter-apk/app-release.apk`.

## Firebase (FCM)

Push notifikasi aktif setelah:

1. Taruh `google-services.json` (dari Firebase console) di `mobile/android/app/`.
   Plugin google-services di-apply otomatis bila file ini ada (lihat `android/app/build.gradle.kts`).
2. Di server, taruh service-account JSON di `storage/app/firebase/service-account.json`
   dan set `FIREBASE_CREDENTIALS` di `.env` (lihat `config/services.php` → `fcm`),
   lalu `php artisan config:cache` + `php artisan queue:restart`.

Tanpa langkah di atas aplikasi tetap berjalan penuh, hanya push yang non-aktif.

## Rilis (signing)

Debug key dipakai bila belum ada keystore. Untuk rilis:

1. `keytool -genkey -v -keystore ~/kv-release.jks -keyalg RSA -keysize 2048 -validity 10000 -alias kv`
2. Buat `mobile/android/key.properties`:
   ```
   storeFile=/abs/path/kv-release.jks
   storePassword=...
   keyAlias=kv
   keyPassword=...
   ```
   (file ini di-gitignore; build release otomatis memakainya bila ada.)

## Konfigurasi build server

`android/gradle.properties` dikonstrain untuk server 8GB (heap 2g, tanpa daemon,
worker maks 2) agar build tidak menghabiskan RAM. Naikkan bila di mesin lebih besar.
