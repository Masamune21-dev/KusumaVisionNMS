# Build & Install Aplikasi Android (APK)

Panduan lengkap membangun **aplikasi Android KusumaVision NMS** ([`mobile/`](../mobile/), Flutter)
**dari nol** — termasuk memasang toolchain (JDK, Android SDK, Flutter), spek minimum, menandatangani
(signing), dan memasang APK di HP.

> **Perlu build sendiri?** Tidak selalu. Kalau hanya ingin **memakai** aplikasi, langsung ke
> **[§7 Install APK di HP](#7-install-apk-di-hp)** — pakai APK jadi. Build sendiri hanya perlu jika
> Anda **mengubah kode** di `mobile/`.

Aplikasi ini **mengonsumsi REST API v1** server NMS (`/api/v1`). Jadi server harus sudah berjalan
([INSTALL.md](INSTALL.md)) dan **API v1 diaktifkan** (Pengaturan → API & Token) sebelum aplikasi bisa login.

---

## 1. Minimum spek untuk build

Build APK jauh lebih berat dari menjalankan server web (Gradle + Android SDK + Flutter compiler).

| Sumber daya | Minimum | Disarankan | Kenapa |
|---|---|---|---|
| **RAM** | **8 GB** | 16 GB | Di bawah 8 GB, Gradle **swap-thrash** → build lambat/gagal/hang. Repo ini mengonstrain heap Gradle ke 2 GB agar muat di 8 GB (lihat [§8](#8-catatan-server-8-gb-swap)). |
| **Disk kosong** | **15 GB** | 20 GB+ | Flutter ~2,3 GB + Android SDK ~3,1 GB + cache Gradle/Pub + output. |
| **CPU** | 2 core | 4 core+ | Kompilasi Kotlin/Dart paralel. |
| **OS** | Ubuntu 20.04+/Debian, **atau** Windows 10/11, **atau** macOS | — | Semua bisa. Lihat jalur masing-masing. |
| **HP / emulator** | **tidak perlu** untuk build | — | APK dibangun tanpa perangkat; perangkat hanya untuk memasang hasilnya. |

**Pilihan mesin build:**

- **VPS Linux headless** (mis. 8 GB RAM, 4 vCPU) — hemat, cukup **cmdline-tools** (tanpa GUI Android Studio). Ini yang dipakai server ini. → [§4a](#4a-linux-ubuntu--debian--vps-headless)
- **PC Windows** — paling mudah pakai **Android Studio** (mengurus SDK/JDK otomatis). → [§4b](#4b-windows)
- **VM / container** — sama seperti Linux headless; pastikan alokasi RAM ≥ 8 GB dan disk ≥ 20 GB. Docker khusus build Flutter dimungkinkan tapi di luar cakupan dokumen ini; lebih praktis VM Ubuntu biasa.

**Hasil build:** APK release ± **53 MB** di `mobile/build/app/outputs/flutter-apk/app-release.apk`.

**Versi toolchain yang dipakai proyek:** Flutter **3.44** (Dart SDK `^3.12.2`), **JDK 17**, Android
SDK (platform android-35/36, build-tools 35/36), Gradle 9.x, AGP 9.x, Kotlin 2.3.x.
`applicationId` = `net.kusumavision.nms`, minSdk = Android **6.0 (API 23)** (disyaratkan Firebase Messaging).

---

## 2. Ringkasan alur

```
Install toolchain (JDK17 + Android SDK + Flutter)   ← sekali saja per mesin
        │
        ▼
flutter doctor  →  pastikan semua ✓
        │
        ▼
bin/build-apk.sh  (set API_BASE_URL ke server Anda)
        │
        ▼
app-release.apk  →  salin ke HP  →  install (sideload)
```

---

## 3. Prasyarat toolchain (yang harus terpasang)

1. **JDK 17** (`java-17-openjdk`) — Gradle/AGP butuh Java 17.
2. **Android SDK** — minimal: `platform-tools`, `cmdline-tools`, `build-tools`, satu `platform` (android-35/36).
3. **Flutter 3.44** (stable) — membawa Dart.

Jalur pemasangan berbeda per OS: **Linux → [§4a](#4a-linux-ubuntu--debian--vps-headless)**,
**Windows → [§4b](#4b-windows)**.

---

## 4. Pasang toolchain

### 4a. Linux (Ubuntu / Debian / VPS headless)

Cara ini **tanpa GUI** — cocok untuk VPS. Semua ditaruh di `/opt` (seperti di server produksi ini).

```bash
# --- 0. Paket dasar ---
sudo apt update
sudo apt install -y openjdk-17-jdk curl git unzip xz-utils libglu1-mesa

# --- 1. JDK 17 (verifikasi) ---
java -version          # harus "17.x"
export JAVA_HOME=/usr/lib/jvm/java-17-openjdk-amd64

# --- 2. Android SDK (command-line tools saja) ---
sudo mkdir -p /opt/android-sdk/cmdline-tools
cd /tmp
# Ambil "Command line tools only" terbaru dari https://developer.android.com/studio#command-line-tools-only
curl -o cmdtools.zip https://dl.google.com/android/repository/commandlinetools-linux-11076708_latest.zip
unzip -q cmdtools.zip
sudo mv cmdline-tools /opt/android-sdk/cmdline-tools/latest

export ANDROID_SDK_ROOT=/opt/android-sdk
export ANDROID_HOME=/opt/android-sdk
export PATH="$PATH:/opt/android-sdk/cmdline-tools/latest/bin:/opt/android-sdk/platform-tools"

# Pasang paket SDK yang dibutuhkan (sesuaikan versi ke yang dipakai proyek)
sdkmanager --install "platform-tools" "platforms;android-36" "build-tools;36.0.0"
yes | sdkmanager --licenses          # WAJIB — terima semua lisensi Android SDK

# --- 3. Flutter 3.44 (stable) ---
sudo mkdir -p /opt && cd /opt
sudo git clone --depth 1 -b stable https://github.com/flutter/flutter.git
export PATH="$PATH:/opt/flutter/bin"
flutter --version                     # harus 3.44.x

# --- 4. Jadikan env permanen (semua user) ---
sudo tee /etc/profile.d/flutter.sh >/dev/null <<'EOF'
export JAVA_HOME=/usr/lib/jvm/java-17-openjdk-amd64
export ANDROID_SDK_ROOT=/opt/android-sdk
export ANDROID_HOME=/opt/android-sdk
export PATH=$PATH:/opt/flutter/bin:/opt/android-sdk/cmdline-tools/latest/bin:/opt/android-sdk/platform-tools
EOF
source /etc/profile.d/flutter.sh

# --- 5. Verifikasi ---
flutter config --android-sdk /opt/android-sdk
flutter doctor           # target: baris "Android toolchain" dan "Flutter" ✓
```

> `flutter doctor` boleh menampilkan tanda ✗ untuk **Android Studio**, **Chrome**, **Linux desktop** —
> **abaikan**; untuk build APK yang wajib ✓ hanya **Flutter** + **Android toolchain** (SDK + lisensi).

> **Jangan build sebagai `www-data`.** Di server produksi yang juga menjalankan NMS, build sebagai
> user biasa/root — lihat [§8](#8-catatan-server-8-gb-swap).

### 4b. Windows

**Cara termudah — Android Studio mengurus SDK/JDK otomatis:**

1. Pasang **[Android Studio](https://developer.android.com/studio)**. Saat wizard pertama, biarkan
   ia mengunduh **Android SDK**, **platform-tools**, dan **build-tools**. (Android Studio membawa
   JDK 17 bawaan — tidak perlu install JDK terpisah.)
2. Buka **SDK Manager** (More Actions → SDK Manager) → tab **SDK Platforms**: centang **Android 14/15
   (API 34–36)**. Tab **SDK Tools**: centang **Android SDK Command-line Tools** + **Android SDK Build-Tools**.
3. Pasang **[Flutter](https://docs.flutter.dev/get-started/install/windows)**:
   - Unduh Flutter SDK (stable), ekstrak ke mis. `C:\src\flutter` (hindari folder ber-spasi/`Program Files`).
   - Tambahkan `C:\src\flutter\bin` ke **PATH** (Environment Variables).
4. Buka **PowerShell** baru:
   ```powershell
   flutter --version
   flutter doctor --android-licenses    # ketik "y" untuk semua
   flutter doctor
   ```
   Pastikan **Flutter** & **Android toolchain** ✓.

> Alternatif tanpa Android Studio (Windows): pasang **JDK 17** (Temurin/Adoptium) + **command-line
> tools** Android secara manual (mirip langkah Linux), lalu set `JAVA_HOME`, `ANDROID_SDK_ROOT`,
> dan PATH. Android Studio jauh lebih ringkas untuk pemula Windows.

### 4c. macOS

Sama seperti Windows via Android Studio, plus `brew install --cask flutter` atau unduh manual.
Untuk build **APK Android** tidak perlu Xcode (Xcode hanya untuk build iOS, yang tidak dicakup proyek ini).

---

## 5. Build APK

### Cara cepat (skrip repo)

Dari **root repo**, set `API_BASE_URL` ke URL API server NMS Anda:

```bash
API_BASE_URL=https://nms.kusumavision.net/api/v1 bash bin/build-apk.sh
```

Skrip [`bin/build-apk.sh`](../bin/build-apk.sh) melakukan: `flutter pub get` → `flutter analyze` →
`flutter build apk --release` (dengan `--dart-define=API_BASE_URL=...`) → menyalin hasil ke
`public/downloads/kusumavision-nms.apk` agar bisa **diunduh langsung dari NMS** (lihat [§7](#7-install-apk-di-hp)).

Skrip sudah menyetel `JAVA_HOME`, `ANDROID_SDK_ROOT`, dan `PATH` ke `/opt` — sesuaikan bila toolchain
Anda di lokasi lain (mis. Windows/macOS, jalankan cara manual di bawah).

### Cara manual (semua OS)

```bash
cd mobile
flutter pub get
flutter build apk --release --dart-define=API_BASE_URL=https://<host>/api/v1
```

Hasil: `mobile/build/app/outputs/flutter-apk/app-release.apk`.

> **`API_BASE_URL` itu WAJIB & permanen di APK.** Nilai ini di-*compile* ke dalam APK — kalau salah,
> aplikasi tak bisa login. Untuk banyak lokasi dengan server berbeda, build APK terpisah per URL
> (atau arahkan semua ke satu domain publik).

### ⚠️ Wajib bump versi tiap rilis

Sebelum build APK rilis baru, **naikkan `version:` di [`mobile/pubspec.yaml`](../mobile/pubspec.yaml)**
(format `versionName+versionCode`, mis. `1.1.5+9` → `1.1.6+10`). Android **menolak update** bila
`versionCode` (angka setelah `+`) sama dengan yang sudah terpasang.

---

## 6. Signing (rilis)

Tanpa keystore, Flutter memakai **debug key** (cukup untuk uji internal). Untuk distribusi resmi,
tandatangani dengan keystore rilis:

```bash
# 1. Buat keystore (sekali) — simpan file & password baik-baik, JANGAN commit
keytool -genkey -v -keystore ~/kv-release.jks -keyalg RSA -keysize 2048 -validity 10000 -alias kv

# 2. Buat mobile/android/key.properties (di-gitignore)
```
Isi `mobile/android/key.properties`:
```properties
storeFile=/abs/path/kv-release.jks
storePassword=...
keyAlias=kv
keyPassword=...
```
Build release otomatis memakainya bila file ini ada. **Simpan keystore + password** — hilang berarti
tak bisa merilis update yang dianggap "aplikasi sama" oleh Android.

---

## 7. Install APK di HP

1. **Dapatkan APK-nya:**
   - **Dari server NMS** (paling mudah): buka `https://<host-nms>/downloads/kusumavision-nms.apk` di
     browser HP, atau lewat tombol unduh APK di **Pengaturan** web. (Ini hasil salinan `bin/build-apk.sh`.)
   - Atau salin file `app-release.apk` via kabel/USB/Bluetooth/chat.
2. **Izinkan instalasi dari sumber tak dikenal:** saat membuka APK, Android meminta izin
   *"Install unknown apps"* untuk browser/File Manager yang dipakai → **Izinkan**.
   (Android 8+: Setelan → Aplikasi → [browser] → Instal aplikasi tak dikenal → aktifkan.)
3. **Buka & install** APK, lalu jalankan aplikasi.
4. **Login** dengan URL API sudah tertanam (dari `API_BASE_URL` saat build) memakai akun operator/admin NMS.

> **Syarat HP:** Android **6.0 (API 23) ke atas** (batas dari Firebase Messaging). Push notifikasi
> hanya aktif bila Firebase dikonfigurasi ([§9](#9-firebase--push-fcm-opsional)); tanpa itu aplikasi
> tetap berfungsi penuh, hanya tanpa push.

---

## 8. Catatan server 8 GB (swap)

[`mobile/android/gradle.properties`](../mobile/android/gradle.properties) sengaja dikonstrain untuk
mesin 8 GB: `-Xmx2g`, `org.gradle.daemon=false`, `workers.max=2`. Default Flutter (`-Xmx8G`) pernah
membuat server ini **swap-thrash sampai reboot**.

- Di mesin **≥ 16 GB**, boleh naikkan heap (mis. `-Xmx4g`) + aktifkan daemon untuk build lebih cepat.
- **Jangan build sebagai `www-data`** di server produksi (proses berebut RAM dengan php-fpm/postgres/redis) —
  build sebagai user biasa/root, di luar jam sibuk.

---

## 9. Firebase / Push FCM (opsional)

Push notifikasi alarm ke HP aktif setelah:

1. Taruh **`google-services.json`** (dari Firebase Console) di `mobile/android/app/`.
   Plugin google-services di-apply otomatis bila file ini ada.
2. Di server: taruh service-account JSON di `storage/app/firebase/service-account.json`, set
   `FIREBASE_CREDENTIALS` di `.env`, lalu `php artisan config:cache` + `php artisan queue:restart`.

Tanpa langkah di atas, aplikasi tetap berjalan penuh — hanya push yang non-aktif. Detail:
[`mobile/README.md`](../mobile/README.md).

---

## 10. Troubleshooting

| Gejala | Sebab / solusi |
|---|---|
| Build hang / OOM / server nyaris reboot | RAM < 8 GB atau heap terlalu besar. Pastikan `gradle.properties` `-Xmx2g` + `daemon=false` ([§8](#8-catatan-server-8-gb-swap)). Tambah RAM/swap. |
| `Android sdkmanager tool not found` / `cmdline-tools component is missing` | Command-line tools belum terpasang / salah folder. Harus di `.../cmdline-tools/latest/`. Ulangi [§4a](#4a-linux-ubuntu--debian--vps-headless) langkah 2. |
| `Android license status unknown` di `flutter doctor` | Belum terima lisensi. Jalankan `yes \| sdkmanager --licenses` (Linux) atau `flutter doctor --android-licenses` (Windows). |
| `Unsupported Java` / butuh Java 17 | JDK bukan 17. Set `JAVA_HOME` ke `java-17-openjdk`. |
| APK terpasang tapi tak bisa login | `API_BASE_URL` salah saat build, atau API v1 belum diaktifkan di server (Pengaturan → API & Token), atau HP tak bisa menjangkau server. |
| Android menolak update ("app not installed") | `versionCode` sama / signature beda. Bump `version:` di `pubspec.yaml`; pastikan pakai keystore yang sama ([§5](#5-build-apk), [§6](#6-signing-rilis)). |
| `flutter analyze` gagal → build berhenti | Perbaiki error yang dilaporkan, atau build manual (`flutter build apk --release ...`) untuk lewati `analyze`. |

---

## Referensi

- **[mobile/README.md](../mobile/README.md)** — fitur aplikasi, stack, Firebase, signing.
- **[bin/build-apk.sh](../bin/build-apk.sh)** — skrip build.
- **[docs/API.md](API.md)** — REST API v1 yang dikonsumsi aplikasi.
- **[docs/INSTALL.md](INSTALL.md)** — instalasi server (harus jalan lebih dulu).
- Flutter install resmi: <https://docs.flutter.dev/get-started/install>
</content>
