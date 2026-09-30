# KusumaVision NMS — Aplikasi Android (Flutter)

Aplikasi pendamping (versi **1.8.5+29**) untuk memonitor & memprovisioning OLT/ONU FTTH dari HP,
mengonsumsi **REST API v1** KusumaVision NMS (`/api/v1`, lihat `../docs/API.md`). Cara memakainya
dijelaskan di bab "Aplikasi Android" panduan pengguna PDF (`../docs/panduan/`).

## Fitur

- **Login** akun NMS (Sanctum bearer token, disimpan di Keystore Android).
- **Dashboard** ringkasan OLT/ONU/alarm + persentase online; navigasi bawah Dashboard · OLT · ODP · Peta · Akun.
- **Pencarian global** (OLT, SN ONU, nama pelanggan) dengan deep-link.
- **Inventory OLT** (ZTE, C-Data, HiOSO) → detail OLT → daftar port (+ deskripsi) → **ONU per port** →
  **detail ONU** (RX power berwarna, status beserta sebab down: LOS / Dying Gasp / …).
- **Unconfigured ONU** + discovery live + **Registrasi ONU** (ZTE, mode dasar: preview script → eksekusi,
  ODP opsional).
- **Aksi ONU**: reboot, ubah nama, hapus — mengikuti kapabilitas OLT & peran (demo hanya melihat).
- **ODP** (daftar, cari, filter OLT, detail berisi ONU, foto dokumentasi, warna pin) & **Peta** ONU/ODP
  (baca-saja; pin & CRUD ODP tetap di web).
- **Alarm** dengan filter severity; **notifikasi push FCM** saat alarm naik/turun (deep-link ke ONU/OLT
  terkait), berhenti begitu ponsel logout.
- **Akun**: tema Gelap / Terang / Ikuti sistem, tes push, logout.

## Stack

Flutter 3.44 · Riverpod v2 · dio · go_router · flutter_secure_storage · flutter_map + latlong2 ·
firebase_core/messaging + flutter_local_notifications · Material 3 dark-glass (aksen cyan/sky), dua tema.

## Build

> 🧰 **Belum punya toolchain (Flutter/Android SDK/JDK)?** Panduan pasang **dari nol** (Linux &
> Windows) + minimum spek + signing + install di HP: **[`../docs/BUILD_APK.md`](../docs/BUILD_APK.md)**.
> Bagian di bawah mengasumsikan toolchain sudah terpasang.

```bash
# dari root repo
# WAJIB: alamat API server NMS Anda sendiri (tanpa nilai bawaan)
API_BASE_URL=https://nms.domain-anda.com/api/v1 bash bin/build-apk.sh
# atau manual:
cd mobile
flutter pub get
flutter build apk --release --dart-define=API_BASE_URL=https://<host>/api/v1
```

APK: skrip menghasilkan `app-arm64-v8a-release.apk` + `app-armeabi-v7a-release.apk` (split per ABI) dan
menyalinnya ke `public/downloads/kusumavision-nms.apk` / `kusumavision-nms-arm32.apk`; build manual di atas
menghasilkan `app-release.apk` universal. Semua di `mobile/build/app/outputs/flutter-apk/`.
**Bump `version:` di `pubspec.yaml` tiap rilis** — `versionCode` yang sama ditolak Android saat update.

APK yang dibangun tanpa `--dart-define=API_BASE_URL` sengaja tidak punya alamat bawaan (`lib/core/env.dart`)
dan hanya menampilkan pesan "alamat server belum diatur" — tidak pernah menghubungi server orang lain.

## Firebase (FCM)

Push notifikasi aktif setelah:

1. Taruh `google-services.json` (dari Firebase console) di `mobile/android/app/`.
   Plugin google-services di-apply otomatis bila file ini ada (lihat `android/app/build.gradle.kts`).
2. Di server, taruh service-account JSON di `storage/app/firebase/service-account.json`
   (path bawaan `FIREBASE_CREDENTIALS`, lihat `config/services.php` → `fcm`; isi variabel itu hanya bila
   berkasnya di tempat lain), lalu `php artisan config:cache` + `php artisan queue:restart`.
3. Nyalakan push di web: **Pengaturan → Notifikasi Mobile**.

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
