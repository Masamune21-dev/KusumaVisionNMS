# Worklog

## 2026-07-10

### Security review nms.kusumavision.net: fix injeksi CLI + patch CVE dependency + hardening

**Permintaan user:** minta di-pentest/di-tes keamanan web-nya sendiri ("andai kamu hacker, apa yang kamu lakukan"). Karena punya source, dilakukan audit kode attack-surface langsung, lalu kerjakan semua remediasi.

**Temuan utama — injeksi perintah CLI (🔴):** field teks-bebas registrasi ONU ZTE (`customer_name`, `serial_number`, `pppoe_username/password`, `acs_username/password`) hanya divalidasi `string|max:N` **tanpa filter**, lalu disisipkan mentah ke baris CLI oleh `ZteProvisioningScriptBuilder`. Eksekutor memecah skrip dengan `explode("\n")` lalu kirim tiap baris sebagai perintah telnet → **newline di field = injeksi perintah config-mode ke OLT** (mis. `no onu 5`). Driver HiOSO/C-Data sudah menyanitasi; builder ZTE belum. Rename ONU ZTE aman (via SNMP SET, bukan CLI).

Changed:
- `app/Services/ZteProvisioningScriptBuilder.php` — helper `cli()` buang karakter kontrol (CR/LF, `\x00-\x1F`, `\x7F`) → spasi sebelum interpolasi; diterapkan ke serial, nama, kredensial PPPoE & ACS.
- `app/Services/ZteOnuReconfigureScriptBuilder.php` — `str()` ikut strip karakter kontrol (jalur copy-ONU, defense-in-depth).
- `app/Services/Zte/OnuRegistrationService.php` — validasi diperketat pakai anchor `\z` (bukan `$`, cegah bypass trailing-newline): serial `^[A-Za-z0-9:_.-]+\z`, pppoe/acs `^\S+\z`, `customer_name` `not_regex` blokir karakter kontrol.
- `tests/Unit/ZteOnuConfigureTest.php` — test baru membuktikan payload injeksi menyatu jadi satu baris `name`, tak jadi perintah terpisah.
- **Dependency:** `composer update` terarah (guzzle/psr7/laravel/phpseclib) → Laravel `12.63.0`, `composer audit` bersih (sebelumnya 7 advisory/4 paket, termasuk CRLF-injection psr7 & Signed-URL path-confusion Laravel). `npm audit` = 0.
- `app/Providers/AppServiceProvider.php` — limiter `olt-refresh` (30/mnt/user).
- `routes/web.php` — `throttle:olt-refresh` di `smartolt.test|refresh`, `cdata-olt.test|refresh`, `hioso-olt.test|refresh`, `monitoring.onu.refresh` (anti-DoS SNMP walk/telnet sinkron).
- `install.sh` + `docker/nginx.conf` — tambah header HSTS (`always`, efektif di 443).
- `docs/SECURITY_AUDIT_2026-07.md` — laporan audit + checklist deploy & hardening ops.

Notes:
- Postur yang sudah aman (terverifikasi review, tak diubah): isolasi partner via `PartnerOltScope` (anti-IDOR), throttle brute-force login, tiket telnet AES+TTL, secret `encrypted`+`$hidden`. `APP_DEBUG` prod terverifikasi `false`.
- Verifikasi: 77/77 unit test lolos; `route:list` boot OK; Pint bersih. Kegagalan Feature test registrasi = **PRE-EXISTING 419/CSRF** (terbukti gagal identik via `git stash`, lingkungan test sandbox — bukan regresi; nol kegagalan 422 baru dari validasi).
- Deploy: kode builder/validasi/provider berubah → prod perlu `composer install --no-dev`, `config:cache`, `reload php8.3-fpm`, `queue:restart`. HSTS ke blok 443 live + `reload nginx` = langkah ops manual (lihat checklist doc). **Terapkan di prod:** `route:cache` + `queue:restart` sudah dijalankan; HSTS ternyata **sudah ada** di nginx 443 live.

**Ronde 2 (temuan lanjutan, terverifikasi ke kode):**
- **SSRF resolver link Peta ONU** — `OnuMapController::resolveLink` fetch link pendek Google Maps; gate `preg_match` **tak ter-anchor** (`http://169.254.169.254/#https://goo.gl/maps` lolos) + Guzzle follow-redirect otomatis → bisa tembak metadata cloud/host internal. Fix: gate host via `parse_url` + allowlist persis; `expandShortLink` matikan redirect otomatis & validasi tiap hop (`hostResolvesPublic` tolak IP privat/loopback/link-local/reserved). Test baru `tests/Unit/OnuMapLinkResolverTest.php` (3 lolos).
- **Password admin contoh** `P@ssw0rd123` di `README.md`/`docs/INSTALL.md` → placeholder `GANTI_DENGAN_PASSWORD_KUAT`. `install.sh` tak hardcode password (default kosong). **Aksi ops:** rotasi password admin live bila pernah dipakai.
- **`user:create` default role** — command tak set role → jatuh ke default kolom DB `operator` (install.sh malah workaround `UPDATE ... role='admin'`). Tambah opsi `--role` tervalidasi `UserRole::values()` (default `operator`) + set role eksplisit.
- Advisory (bukan kode): docs sebut OLT live pakai SNMP community `public` — ganti di perangkat + ACL UDP/161.
- Verifikasi ronde 2: 80/80 unit test lolos; Pint bersih. SSRF fix = web controller → live via opcache auto-revalidate (tanpa restart); `user:create` = CLI (fresh tiap run).

### Alarm/notif: nama pelanggan di semua kanal + fix label PON (EPON salah tertulis "GPON")

**Keluhan user:** (1) minta alarm & notif alert semua OLT ikut mengirim nama pelanggan; (2) di HP ada notif salah — OLT **EPON** tapi notifnya "port **GPON** down". Cek semua kanal: web, Telegram, APK.

**Diagnosis:** "GPON" di-hardcode di `AlarmEvaluator` (pesan port-down & recovery) dan di label `AlarmEvent::TYPE_PORT_DOWN` = 'Port GPON down' — label ini dipakai judul push FCM, `type_label` API, dan opsi filter Settings. Jadi OLT EPON (C-Data/HiOSO) selalu tertulis "GPON". Nama pelanggan sebenarnya sudah ada di `meta.customer_name` (dari `customerNameFromOnu`) tapi hanya ditampilkan Telegram + web; push FCM & list alarm mobile belum.

Changed:
- `app/Support/SmartOltSupport.php` — tambah `ponLabel(?SnmpOlt)` → 'GPON'/'EPON' dari `capabilities()['pon_label']` (C-Data EPON & HiOSO → EPON), memakai `driverKey()` yang sama dengan jalur polling. Sumber tunggal label teknologi PON untuk teks alarm.
- `app/Models/AlarmEvent.php` — label generik `TYPE_PORT_DOWN` dinetralkan 'Port GPON down' → 'Port PON down'; tambah `typeLabel($type, $ponLabel='GPON')` yang menyadari family (port-down → "Port {GPON|EPON} down").
- `app/Services/AlarmEvaluator.php` — set `$this->ponLabel = SmartOltSupport::ponLabel($olt)` di awal `evaluate()`; pesan port-down (`portAlarm`) & recovery (`buildRecovery`) tak lagi hardcode "GPON port".
- `app/Services/Fcm/FcmAlarmNotifier.php` — judul push pakai `AlarmEvent::typeLabel()` family-aware; body & data payload push kini menyertakan `👤 nama pelanggan` (dari `meta.customer_name`, dibersihkan `cleanCustomerName`).
- `app/Http/Controllers/Api/V1/AlarmController.php` — eager-load `olt:...,vendor`; `type_label` family-aware; tambah field `customer_name` ke tiap item list alarm.
- `mobile/lib/models/alarm.dart` — model `Alarm` tambah `customerName` (parse `customer_name`).
- `mobile/lib/features/alarms/alarm_list_screen.dart` — kartu alarm menampilkan baris nama pelanggan (ikon user) bila ada.
- `mobile/pubspec.yaml` — bump `1.1.6+10` → `1.1.7+11` (wajib tiap rilis APK).

Notes:
- Perbaikan label berlaku ke SEMUA kanal: web (`alarm.message`), Telegram (body), push FCM (title+body), API (`type_label`). Push FCM latar belakang otomatis benar karena title/body dikendalikan server — tak perlu ubah kode Dart untuk isi push; perubahan Dart hanya untuk menampilkan nama pelanggan di list alarm dalam app.
- Verifikasi: `AlarmEngineTest` 16 passed; suite Api/FCM/Telegram/Support 84 passed. 1 gagal `refresh_port_non_zte` = **PRE-EXISTING** (terbukti gagal identik via `git stash`, di luar area ini). Pint bersih; `flutter analyze` file berubah: no issues.
- Deploy: kode job/service berubah → prod perlu `php artisan config:cache` (sudah) + `php artisan queue:restart` (worker `kusumavision-worker`). APK perlu rebuild `bash bin/build-apk.sh` untuk membawa perubahan mobile.

## 2026-07-09

### Fix: Docker halaman blank putih — nginx baru buang port dari HTTP_HOST → URL aset salah port

**Keluhan user:** setelah fix build Ziggy (di bawah), `docker compose up` sukses & container Healthy,
tapi buka `http://localhost:8080` → **halaman putih** (judul tab "KusumaVision NMS" muncul = HTML/PHP
jalan, tapi body kosong, Vue tak pernah mount).

**Diagnosis:** image nginx terbaru (bookworm, nginx ≥1.30) mengubah default `fastcgi_params`:
`fastcgi_param HTTP_HOST` kini `$host` (host **tanpa** port) sebagai hardening keamanan, bukan lagi
`$http_host` (bawa port). Container listen di `:80` internal tapi di-publish ke `:8080` (APP_PORT).
Karena `HTTP_HOST` sampai ke PHP tanpa port, `Request::getHost()` Laravel kehilangan `:8080` → semua
URL aset (`@vite` JS/CSS) & root di-generate ke `http://localhost/build/...` (port 80, tak ter-publish)
→ **setiap aset 404 → blank putih**. Native `install.sh` tak terdampak (jalan di port standar 80/443,
di situ `$host` == `$http_host`).

**Perbaikan (`docker/nginx.conf`):** di dalam `location ~ \.php$` setelah `include fastcgi_params;`
tambahkan override eksplisit `fastcgi_param HTTP_HOST $http_host;` (kembalikan port). Terverifikasi
user di Windows: setelah rebuild+restart, URL aset kembali `http://localhost:8080/build/assets/...` dan
landing page render penuh.

**Files:** `docker/nginx.conf` (1 baris `fastcgi_param` + komentar).

### Fix: Docker build gagal di stage frontend — Ziggy tak ter-resolve (`vendor/` di-.dockerignore)

**Keluhan user:** `start.bat` di Windows gagal saat `docker compose up -d --build`. Stage `frontend`
(`npm run build`) error: `Could not resolve "../../vendor/tightenco/ziggy" from "resources/js/app.js"`.

**Diagnosis:** `resources/js/app.js:7` meng-import `ZiggyVue` dari `../../vendor/tightenco/ziggy` (paket
Composer, bukan npm), tapi `.dockerignore:12` mengecualikan `vendor/`. Di stage `frontend`
(`node:22-bookworm-slim`, `COPY . .` lalu `npm run build`) folder itu absen → Vite gagal resolve. Di
host/dev build sukses karena `vendor/` sudah terisi `composer install`. Bug murni Dockerfile, lintas-OS
(bukan khusus Windows). Hanya Ziggy yang di-import dari `vendor/` (grep `resources/js` → 1 hit).

**Perbaikan (`Dockerfile`):** tambah **stage 0 `vendor`** (image `composer:2`) yang `composer install
--no-scripts --no-autoloader --ignore-platform-reqs` (hanya mengunduh paket terkunci lock; image composer
tak punya ekstensi PHP app, dan paket ini murni PHP/JS), lalu di stage `frontend` `COPY --from=vendor
/app/vendor/tightenco/ziggy ./vendor/tightenco/ziggy` sebelum `npm run build`. Stage `app` (runtime)
tak disentuh — tetap `composer install` di image ber-ekstensi + `dump-autoload` seperti semula.

**Verifikasi:** `docker build --target frontend .` di server → `✓ built in 20.57s`, image ter-export
(exit 0). Sebelumnya gagal di `npm run build`.

**Files:** `Dockerfile` (stage `vendor` + 1 baris COPY di stage `frontend`).

### Docs: panduan instalasi master + build APK dari nol (perbaikan onboarding)

**Permintaan user:** cek langkah & file instalasi, permudah + perdetail biar pengguna paham; cek apakah
sudah ada langkah instalasi APK mobile; kasih saran minimum spek untuk build APK (VPS/Windows/VM/container);
tutorial instalasi berbeda per-OS (Linux/Windows/lainnya).

**Audit temuan:** instalasi web sudah kuat (`install.sh`, `docs/DOCKER.md`, `scripts/check-requirements.sh`,
README 3-jalur). Gap: **(1)** build APK tak punya panduan pasang toolchain dari nol — `bin/build-apk.sh`
mengasumsikan Flutter/Android SDK/JDK sudah di `/opt`; tak disebut di README/handbook; **(2)** tak ada
minimum spek di mana pun (kecuali "±2GB" Docker & komentar "8GB" gradle); **(3)** tak ada cara install APK
di HP; **(4)** tak ada peta keputusan OS di depan. Spek nyata diukur di server: Flutter 2,3 GB + Android SDK
3,1 GB (→ ~10 GB disk), build jalan di RAM 8 GB (gradle heap 2g), APK ~53 MB.

**Created:**
- `docs/INSTALL.md` — panduan master: peta keputusan per-OS (Docker/`install.sh`/manual), **tabel minimum
  spek** (web Ubuntu/Docker + build APK), langkah ringkas tiap jalur + routing ke dok detail, pasca-instalasi,
  troubleshooting cepat.
- `docs/BUILD_APK.md` — build APK **dari nol**: minimum spek build (VPS Linux headless / Windows / VM),
  pasang toolchain per-OS (Linux `sdkmanager`+Flutter+env `/opt`; Windows via Android Studio; macOS),
  `flutter doctor`, build (`bin/build-apk.sh` + manual, `API_BASE_URL`), bump versi, signing keystore,
  **install APK di HP** (sideload + unknown sources), catatan server 8GB/swap, Firebase FCM, troubleshooting.

**Changed:** `README.md` (callout "mulai dari `docs/INSTALL.md`", tabel minimum spek ringkas, section baru
**Aplikasi Android (APK)**, link kedua dok di bagian Dokumentasi); `mobile/README.md` (penunjuk ke BUILD_APK
untuk toolchain dari nol); `docs/handbook/04-instalasi-deploy.md` (penunjuk pengguna baru → INSTALL/BUILD_APK).

**Notes:** hanya dokumentasi, tak ada perubahan kode/perilaku app. Semua tautan internal diverifikasi
menunjuk file yang ada.

## 2026-07-08

### Fix: alarm Telegram/mobile membanjir palsu — debounce konfirmasi 2 poll (semua OLT) + smoothing HiOSO

**Keluhan user:** OLT-HIOSO-PATI mengirim alarm `port_down` (`GPON port epon 0/1/3 oper status down`)
lalu `kembali up` berulang-ulang ke Telegram, padahal saat dicek langsung di OLT port-nya **masih hidup**;
di web & mobile pun tak ada alarm (sudah keburu ter-clear tiap ~6 menit). Permintaan lanjutan: **SEMUA
jenis alert (LOS/offline/dying gasp/port down/RX) di SEMUA OLT** harus nunggu **2 poll (~10 mnt)** sebelum
dikirim — kalau poll ke-2 sudah normal lagi, alert tak usah dikirim.

**Diagnosis (data produksi):** port `epon 0/1/3` hanya punya **1 ONU** (RX -17.24 dBm = sehat). Status
port PON HiOSO diturunkan dari jumlah ONU online di `CDataOltScanner` (ifOperStatus HA7304 tak reliable).
Di link lossy, HiOSO sesekali melaporkan RX ONU `na`/`0` untuk **satu siklus poll** walau online; driver
lama langsung menandai offline → port 1-ONU turun `down` → alarm CRITICAL; poll berikutnya normal →
`kembali up`. **47× port_down + 47× onu_offline berpasangan** dalam beberapa hari (semua port memunculkan
onu_offline palsu; hanya port 1-ONU yang ikut port_down).

**Perbaikan (dua lapis):**
1. **Debounce konfirmasi 2 poll — universal, semua jenis & semua OLT** di `AlarmEvaluator`. Status baru
   `AlarmEvent::STATUS_PENDING`: fault yang baru terdeteksi dicatat PENDING (belum dikirim, tak tampil di
   UI/hitungan aktif). Notifikasi raise baru dikirim bila fault **masih ada di poll berikutnya** (promote
   PENDING→ACTIVE). Bila pulih sebelum konfirmasi, baris pending **dihapus diam-diam** (tak ada notif down
   maupun clear). `openAlarms()` kini ambil ACTIVE+PENDING; deteksi transisi (port/onu/rx/unreachable)
   pakai keduanya. `AlarmController` web+API mengecualikan PENDING dari daftar (`whereIn active,cleared`).
2. **Smoothing HiOSO** (`HiosoEponSnmpService`) `MAX_OFFLINE_STRIKES = 2`: ONU online baru ditandai
   offline di SNAPSHOT setelah `na` beruntun 2 poll — mencegah dashboard/faceplate "berkedip" down pada 1
   sampel `na` buruk (murni penghalus tampilan; gerbang alarm ada di lapis #1). RX debounce dibawa
   `snmp_stale` (dikecualikan time-series `PollOltJob:245`); baris Rx ABSEN tetap carry-forward, tak
   menambah strike; `offline_strikes` disimpan per-ONU di `buildOnu`. Efek gabungan: transien 1–2 siklus
   HiOSO tak beralarm & tak berkedip; outage HiOSO sungguhan beralarm ~3 poll (~15 mnt), OLT lain ~2 poll.

**Files:** `app/Models/AlarmEvent.php` (const `STATUS_PENDING`), `app/Services/AlarmEvaluator.php`
(pending create/promote/drop + `openAlarms`), `app/Http/Controllers/AlarmController.php` +
`app/Http/Controllers/Api/V1/AlarmController.php` (exclude pending), `app/Services/Hioso/HiosoEponSnmpService.php`.
Tests: `AlarmEngineTest` (pola 2-poll + `test_transient_fault_recovers_before_confirmation_is_not_alarmed`),
`TelegramSettingsTest` (2-poll), `HiosoSnmpDriverTest` (3 test debounce `na`).

**Tests:** full suite `php artisan test` di sqlite **311 pass** (3 gagal: 2 kini diperbaiki + 1
`ApiV1WriteTest::test_refresh_port_non_zte` 422 **pre-existing**, diverifikasi via git stash). ⚠️ **Test
WAJIB dijalankan dgn config cache disingkirkan** — kalau tidak, `bootstrap/cache/config.php` (pgsql)
menimpa sqlite phpunit.xml → test nyasar ke DB PRODUKSI. `queue:restart` agar worker `kusumavision-worker`
memuat kode baru (hanya perubahan kode PHP, tak ada `.env`/config).

## 2026-07-07

### Tombol aksi per-OLT: alarm On/Off — per-PENERIMA (admin/operator vs partner)

**Permintaan user:** tombol per-OLT nyalakan/matikan alarm. Refinement: **partner** (punya webhook
sendiri) bisa on/off alarm OLT yang di-assign ke dia — memengaruhi HANYA webhook/FCM partner tsb.
**Admin** punya saklarnya sendiri: saat admin off, admin tak menerima, tapi partner tetap menerima
bila saklar partner-nya on. **Operator** "ngikut administrator" (pakai saklar admin, tanpa toggle sendiri).

**Keputusan penting:** saklar **bukan** mute evaluasi. Evaluasi alarm SELALU jalan (event tetap
tercatat, dashboard akurat); yang di-gerbang hanya **pengiriman notifikasi** per-penerima.
- `snmp_olts.alarms_enabled` = saklar **admin/operator** → gerbang bot global Telegram + FCM admin/operator.
- `olt_user.alarms_enabled` (pivot) = saklar **per-partner-per-OLT** → gerbang bot Telegram partner + FCM partner.
Independen satu sama lain. Berlaku semua family (ZTE, C-Data, HiOSO); satu route lintas tab.

**Perubahan:**
1. Migrasi `...add_alarms_enabled_to_snmp_olts_table` (kolom OLT) + `...add_alarms_enabled_to_olt_user_table`
   (kolom pivot), keduanya boolean default `true`, non-destruktif.
2. `app/Models/SnmpOlt.php` — `alarms_enabled` fillable+cast; `$attributes` default true (instance baru konsisten).
3. `app/Models/User.php` — `partnerOlts()` `->withPivot('alarms_enabled')`.
4. `app/Services/AlarmEvaluator.php` — hapus mute; evaluasi selalu jalan (gating pindah ke notifier).
5. `app/Services/Telegram/TelegramNotifier.php` — `configsFor()`: bot global hanya bila `$olt->alarms_enabled`;
   bot partner hanya bila pivot `olt_user.alarms_enabled=true`.
6. `app/Services/Fcm/FcmAlarmNotifier.php` — `recipientUserIds()`: admin+operator hanya bila `$olt->alarms_enabled`;
   partner independen, hanya bila pivot on.
7. `app/Http/Controllers/SmartOltController.php` — `toggleAlarms(Request,SnmpOlt)` bercabang role (partner→pivot,
   admin/operator→flag OLT); `serializeOlt.alarms_enabled` jadi **viewer-effective** via `viewerAlarmsEnabled()`
   (partner lihat pivot-nya, memoized anti-N+1).
8. `routes/web.php` — `POST smartolt/{olt}/alarms/toggle` → `smartolt.alarms.toggle`.
9. `resources/js/Pages/SmartOlt/Index.vue` — IconButton toggle (`BellRing`/`BellOff`, judul role-aware
   `alarmTitle()` — partner: "Alarm webhook Anda …") + indikator "Alarm: On/Off" di 4 lokasi.

**Tests (hijau, 64+55):** `AlarmEngineTest::test_alarms_disabled_olt_still_records_events`;
`PartnerTelegramBotTest::test_admin_alarm_off_silences_global_but_partner_still_receives` &
`…partner_alarm_off_silences_partner_bot_but_not_global`; `SmartOltInventoryTest::…toggle_flips_flag_per_olt`
& `…partner_flips_own_pivot_not_olt_flag`. Verifikasi live: `recipientUserIds(OLT564 admin-off)` = hanya
partner. Build vite + `config:cache`/`route:cache` + `queue:restart` + reload php-fpm.

### Fix: tombol "Test SNMP" menghapus inventori (ports/ONU jadi 0)

**Keluhan user:** setelah menekan Test SNMP, GPON Port & Total ONU jadi 0 (dikira efek mematikan alarm —
ternyata bukan; polling tetap jalan normal).

**Diagnosis:** `test()` mengembalikan hanya `ok/driver/latency/system/error` (tanpa `ports`/`port_onus`),
lalu controller **menimpa** seluruh `last_test_result` → inventori hasil poll terhapus sampai poll berikutnya.
`PollOltJob` justru `array_merge`. Terpicu saat user menekan Test; independen dari fitur alarm.

**Perbaikan:** `SmartOltController::test()`, `CDataOltController::test()`, `HiosoOltController::test()` kini
`array_merge($olt->last_test_result ?? [], $result)` — cek koneksi memperbarui ok/system/latency tanpa
menghapus ports/port_onus. Data OLT-564 dipulihkan via satu poll sinkron (8 ports/ONU). Reload php-fpm.

### Role "Operator" — bisa di-assign OLT (opsional) seperti partner

**Permintaan user:** di form Tambah/Edit User, role **operator** bisa di-assign OLT juga seperti
partner, agar aksesnya bisa dipersempit ke OLT tertentu.

**Keputusan (ditanyakan ke user):** (1) operator TANPA assignment = **lihat semua OLT**
(assignment opsional/pembatas, backward-compatible — operator lama tak kehilangan akses);
(2) operator yang di-scope **tetap boleh** mengelola inventori OLT (tambah/hapus device).
Beda dari partner yang: tanpa assignment = tak lihat apa pun, dan tak boleh kelola inventori.

**Perubahan:**
1. `app/Models/User.php` — helper baru `isOltScoped()`: partner selalu true; operator true hanya
   bila punya assignment; admin/demo false. Relasi `partnerOlts` kini dipakai partner + operator.
2. `app/Models/Scopes/PartnerOltScope.php` — gerbang scope dari `isPartner()` → `isOltScoped()`,
   jadi operator ber-assignment ikut dibatasi (SnmpOlt + AlarmEvent/PollingEvent/registrasi/pin peta).
3. `app/Http/Controllers/UserController.php` — `syncPartnerOlts` kini sync assignment untuk
   partner **dan** operator (role lain tetap dikosongkan).
4. `app/Services/Fcm/FcmAlarmNotifier.php` — `recipientUserIds` diselaraskan: operator ber-assignment
   hanya terima push alarm dari OLT-nya (bukan semua) → tutup bocor info & spam notifikasi.
5. `resources/js/Pages/Users/Index.vue` — picker OLT muncul untuk operator juga (`showOltAssignment`),
   hint teks role-aware, badge "N OLT di-assign" di daftar untuk operator ber-assignment.

**Tests:** `tests/Feature/OperatorOltScopeTest.php` (baru, 6 test): tanpa-assignment lihat semua,
ber-assignment ter-scope + 404 OLT lain, alarm ter-scope, tetap bisa kelola inventori, admin
menyimpan assignment operator. 22/22 hijau (operator + partner + partner-telegram).
Build vite sukses. (Test dijalankan dengan `APP_CONFIG_CACHE` di-bypass — config prod ter-cache
memaksa env production→419 CSRF, gotcha lama.)

### Fix polling HiOSO tak lengkap — walk per-PON + carry-forward roster ONU

**Keluhan user:** hasil polling ONU OLT HiOSO tak lengkap & berubah-ubah — kadang satu port ke-poll
semua, kadang sebagian, kadang cuma namanya, kadang cuma Rx-nya sebagian.

**Diagnosis (verifikasi live 3 OLT):** `HiosoEponSnmpService::getRegisteredOnus` walk **seluruh tabel**
MAC/Nama/Rx sekaligus. Di link WAN lossy (OLT via port-forward), walk tabel besar pada PON padat
sering **terpotong** → hitungan ONU/PON melompat-lompat. Reproduksi: NDOKATON (id 410) total ONU
loncat **53↔37/39** (port 1: 27↔~12). Uji `snmpbulkwalk` mentah: walk penuh truncate, tapi walk
**di-scope per-PON** (`.11.1.{PON}`) stabil **27/27 (6×)**. PATI & PEKALONGAN link sehat → tak
kelihatan; NDOKATON link terburuk → parah.

**Perbaikan:**
1. **Walk per-PON** (`walkTable`): tiap tabel MAC/Nama/Rx di-walk per PON (`{base}.{PON}`) lalu
   digabung, bukan satu walk raksasa. Daftar PON dari `getPorts()` (ifDescr, kecil & stabil).
   Fallback ke walk seluruh-tabel bila ifDescr kosong (perilaku lama).
2. **Carry-forward roster** (`previousOnus` + `MAX_MISSED_POLLS=12`): poll yang masih terpotong hanya
   boleh MENAMBAH/meng-update ONU, **tak pernah menghapus** ONU yang sudah dikenal. Registrasi EPON
   stabil (MAC menetap; ONU mati tetap lapor `na`), jadi baris MAC yang hilang total = walk tak
   sampai, bukan ONU terhapus → dipertahankan (Rx `snmp_stale`, tak masuk time-series). ONU yang benar
   di-delete hilang sendiri setelah absen 12 poll beruntun.
3. Anchor target-key per-PON untuk Nama/Rx (sudah ada) tetap memaksa `robustWalk` mengulang sampai
   ONU per PON ter-cover; nama yang absen di-carry dari snapshot.

**Changed:** `app/Services/Hioso/HiosoEponSnmpService.php` — `getRegisteredOnus` (walk per-PON +
carry-forward), `rxScan`/`getPortRxMap`/`countRegisteredOnus` ikut per-PON, `previousOnuState`→
`previousOnus` (record penuh), helper baru `walkTable`/`buildOnu`/`prevRx`, `robustWalk` early-break
subtree kosong, field baru `missed_polls` di record ONU.

**Tests:** `tests/Unit/HiosoSnmpDriverTest.php` +3 (per-PON scoping, carry-forward, drop >MAX) — 8/8
hijau; Unit suite 74/74. (2 kegagalan `HiosoOltTest` = 419 CSRF, **pre-existing di main**, tak terkait.)

**Verifikasi live:** simulasi poller `scan()` NDOKATON **8×** → total **stabil 53** tiap poll
(p1:27 p3:10 p4:16), `carried`/`stale_rx` naik saat walk terpotong lalu pulih. PEKALONGAN & PATI
tetap stabil (`carried=0`, tanpa carry keliru).

### Role "Partner" — OLT ter-assign, alarm ter-scope, bot Telegram sendiri, mobile ikut

**Permintaan user:** buat role **partner** yang hanya bisa mengelola (lihat + edit) OLT yang admin
izinkan, hanya menerima alarm dari OLT itu, punya **bot Telegram sendiri** yang partner daftarkan,
dan di **mobile** otomatis dibatasi ke OLT yang dipilihkan admin.

**Desain inti:** satu **global scope** `PartnerOltScope` pada `SnmpOlt` (meniru `DemoScope`) menyembunyikan
OLT non-assigned di seluruh app (web+API+peta+search+report+alarm) & memberi **404** via route-model
binding — tanpa menyentuh tiap controller. Partner = setara operator, TAPI hanya pada OLT ter-assign;
**tidak** boleh tambah/hapus device OLT, **tidak** akses Users/Settings/Audit.

**Created:**
- `app/Models/Scopes/PartnerOltScope.php` — batasi partner ke `olt_user` (kolom `id` utk SnmpOlt,
  `snmp_olt_id` utk model lain); no-op utk admin/operator/demo & konteks console/queue.
- Migrasi `..._create_olt_user_table.php` (pivot `user_id`×`snmp_olt_id`) & `..._create_partner_telegram_bots_table.php`.
- `app/Contracts/Telegram/TelegramBotConfig.php` + `app/Models/Concerns/TelegramBotConfigTrait.php` —
  kontrak & logika bot bersama; `TelegramSetting` (global) & `PartnerTelegramBot` (per-partner) implement.
- `app/Http/Controllers/Partner/TelegramBotController.php` + `resources/js/Pages/Partner/TelegramBot.vue`
  (halaman self-service "Bot Telegram Saya", rute `partner.telegram.*` middleware `role:partner`).
- Test `tests/Feature/PartnerRoleTest.php` (11) & `PartnerTelegramBotTest.php` (5) — semua hijau.

**Changed:**
- `UserRole` enum + `User` (relasi `partnerOlts`, `telegramBot`, `allowedOltIds()` [query pivot langsung
  demi hindari **rekursi** dgn scope], `isPartner()`, `canManageOlt()` +partner, `canManageOltInventory()`).
- `SnmpOlt`/`AlarmEvent`/`PollingEvent`/`SmartOltOnuRegistration`/`OnuMapPin` — daftar `PartnerOltScope`.
- `routes/web.php` — create/store/destroy device OLT (3 controller) di-gate `role:admin,operator`;
  webhook jadi `/telegram/webhook/{bot?}` (partner bot); grup `partner.telegram.*`.
- `TelegramNotifier::notify()` — kirim ke bot global + tiap bot partner yg assigned ke OLT; `sendTo/
  editMessage/answerCallback/sendTest/dispatch` terima `?TelegramBotConfig`. `TelegramWebhookManager` &
  `TelegramCommandHandler` generik atas `TelegramBotConfig`. `TelegramWebhookController` memetakan
  `{bot}`→PartnerTelegramBot + `Auth::setUser(partner)` (scope OLT command otomatis).
- `FcmAlarmNotifier::notify()` — penerima dibatasi admin+operator ∪ partner assigned ke OLT (bukan broadcast).
- `HandleInertiaRequests` share `auth.can.{is_partner,manage_olt_inventory}`; `Users/Index.vue` multiselect
  OLT utk partner; `SmartOlt/Index.vue` tombol Tambah/Hapus OLT digate `manage_olt_inventory`; sidebar
  "Bot Telegram Saya"; `bootstrap/app.php` CSRF-exempt `telegram/webhook/*`.
- API: grup tulis `role:admin,operator,partner`; mobile `user.dart` `canWrite` +partner.

**Notes/verifikasi:** `php artisan test` (sqlite in-memory) — 16 test baru hijau + suite lama (297 total).
Migrasi 2 tabel + `config:cache` + php-fpm reload + `queue:restart` sudah dijalankan di server; APK di-build ulang.
**Gotcha:** test nyasar ke pgsql krn config ter-cache → `config:clear` sebelum test, `config:cache` sesudah
(lihat [[project_prod_deploy_gotchas]]). **Bug halus yg diperbaiki:** `allowedOltIds()` sempat query relasi
`partnerOlts()` (SnmpOlt) → memicu PartnerOltScope → rekursi tak terhingga; diganti query tabel `olt_user`
langsung. Setelah deploy sisa: daftar-ulang webhook bot Telegram (global via Settings, partner via "Bot Telegram Saya").

### API/mobile — refresh live per-port untuk OLT non-ZTE (C-Data/HiOSO)

Changed:

- `app/Http/Controllers/Api/V1/OnuActionController.php` — `refreshPort` kini mendukung non-ZTE:
  bila `SmartOltSnmpServiceResolver::isNonZte`, ambil ONU per-port lewat driver
  (`getRegisteredOnusByPort`) dan tulis `port_onus.{slot}_{port}` bentuk-ZTE; ZTE tetap walk subtree.
- `mobile/lib/features/onus/port_onus_screen.dart` — tombol refresh live tampil untuk SEMUA family
  (tak lagi khusus ZTE); cukup gate izin tulis (`canWrite`).
- `mobile/pubspec.yaml` — bump versi APK 1.0.1+2 → 1.0.2+3.
- `tests/Feature/Api/ApiV1WriteTest.php` — tambah `test_refresh_port_non_zte_queries_driver`
  (verifikasi jalur driver non-ZTE menulis cache port).

Notes:

- Melengkapi tombol refresh per-port di halaman web C-Data/HiOSO agar paritas fungsional di mobile.

## 2026-07-06

### Docker appliance — hardening lintas-perangkat (Windows) + regenerasi paket distribusi

**Permintaan user:** coba pasang Docker di server dev ini lalu hidupkan container instalasi baru; kalau
tak bisa, **perbaiki paket Docker supaya andal dipakai di perangkat lain** (kemarin gagal pasang di
Windows, penyebab tak jelas — dugaan seputar ekstraksi/"zip").

**Temuan lingkungan (kenapa tak dijalankan di sini):** server dev = **LXC Proxmox** (`systemd-detect-virt
= lxc`, kernel `6.14.8-2-pve`). Docker Engine v29.6.1 + compose v5.3.0 terpasang & daemon aktif (storage
`overlayfs`, cgroup v2), image bisa di-pull, TAPI **container gagal init**: `runc create ... open sysctl
net.ipv4.ip_unprivileged_port_start file: reopen fd 8: permission denied`. Ini batasan host: runc
me-reopen fd sysctl lewat magic-link yang menabrak proteksi LXC (`/proc/sys` di-mount ro). Remount
`/proc/sys` rw dari dalam **tidak menolong**. Perlu perbaikan **host-side Proxmox** (LXC `features:
nesting=1`, atau jalankan Docker di VM — bukan LXC). Sesuai arahan user, berhenti memaksakan di sini;
`docker build`/`run` juga tak bisa divalidasi lokal → verifikasi final di perangkat target.

**Changed:**
- `Dockerfile` — (1) setelah COPY konfig+entrypoint, **`sed -i 's/\r$//'`** menormalkan CRLF→LF pada
  `entrypoint.sh`, `nginx.conf`, `supervisord.conf`, `php.ini` (menutup kelas kegagalan Windows: file
  diedit/di-zip ulang jadi CRLF → shebang `bash\r` gagal exec / config ditolak). (2) `ENTRYPOINT`
  kini `["/bin/bash", "/usr/local/bin/entrypoint.sh"]` (dijalankan via bash eksplisit, tahan shebang
  CRLF). Tak mengubah perilaku di Linux.
- `docker/entrypoint.sh` — tambah **langkah 0**: strip trailing `\r` dari var yang dikonsumsi app
  (`APP_KEY APP_NAME APP_URL APP_LOCALE ACS_* ADMIN_*`) bila `.env` host diedit di Notepad (CRLF).
  **Sengaja tidak menyentuh `DB_*`** — nilai itu harus identik dengan yang diterima container `db`
  (postgres); strip sebelah malah bikin auth DB gagal. Diuji unit (`bash -n` OK; APP_KEY/APP_URL bersih,
  DB_PASSWORD tetap ber-`\r`).
- `docs/DOCKER.md` — §9 troubleshooting: 4 baris khusus Windows (build gagal unduh `gzip`/`unexpected
  EOF` → ulang `build --pull` / pakai image prebuilt §8B; container exit `entrypoint.sh: no such file`
  → CRLF, build ulang; login 500 setelah edit `.env` → simpan LF / kosongkan APP_KEY; Docker Desktop /
  WSL2 tak start). Juga membuang 1 baris duplikat `docker compose tidak dikenal`.
- `kusumavision-nms-docker.zip` — **diregenerasi** (3.4 MB, 514+ file) dengan perbaikan di atas. Exclude
  sama seperti versi awal (`.git`/`vendor`/`node_modules`/`bin`/`public/build`), **tanpa secret** (hanya
  `.env.example` + `.env.docker.example`), LF terjaga.

**Notes:** Perbaikan bersifat build-time & idempotent; tak ada perubahan kode aplikasi. Bila jaringan di
lokasi target tak stabil, jalur paling andal tetap **image prebuilt** (`docker save | gzip` → `docker
load`, docs §8 Opsi B) supaya perangkat target tak perlu build/unduh base image ± 2 GB.

## 2026-07-03

### Kemasan Docker "appliance" — install lengkap di 1 PC (seperti NetNumen), buat dibagikan

**Permintaan user:** "bisa ngga web aplikasi ini dijadikan software data dan instalasinya lengkap di PC
seperti NetNumen" → pilih **Docker**, untuk **dibagikan ke banyak PC/lokasi**.

**Konteks:** app bukan satu biner — butuh PHP-FPM (Laravel 12), PostgreSQL, Redis, biner Go poller, plus
3 daemon (worker/scheduler/telnet-proxy) + nginx. Sebelumnya deploy hanya via `install.sh` (khusus host
Ubuntu). Docker mengemas semuanya jadi container, data persist di volume, jalan di Windows/Linux/macOS.
**Tidak mengubah kode aplikasi** — murni lapisan kemasan, paritas fungsional dengan `install.sh`.

**Created:**
- `Dockerfile` — multi-stage: (1) `node:22` build Vite → `public/build`; (2) `golang:1.22` build statis
  `bin/kv-snmp-poller` (CGO_ENABLED=0, flag sama seperti install.sh); (3) `php:8.3-fpm` runtime —
  ekstensi via `install-php-extensions` (`pdo_pgsql pgsql bcmath intl mbstring xml zip gd pcntl sockets
  snmp redis opcache`) + nginx/supervisor/postgresql-client/curl; `composer install --no-dev`; COPY
  source + `public/build` + biner Go.
- `docker-compose.yml` — service `app` (all-in-one), `db` (postgres:16-alpine), `redis` (redis:7-alpine);
  volumes `pgdata`/`redisdata`/`app_storage`; healthcheck (`/up`, `pg_isready`, `redis-cli ping`);
  `depends_on service_healthy`; port `${APP_PORT:-8080}:80`. `image: kusumavision/nms:latest` + `build:`
  → mendukung 2 mode distribusi (source `--build` / prebuilt `docker load`).
- `docker/nginx.conf` (root `public/`, `/telnet-ws`→127.0.0.1:6002, fastcgi→127.0.0.1:9000),
  `docker/php.ini` (memory 512M, upload 20M, opcache), `docker/supervisord.conf` (php-fpm, nginx,
  `queue:work`, `schedule:work`, `telnet:proxy` — sama seperti supervisor install.sh; log→stdout),
  `docker/entrypoint.sh` (storage skeleton utk volume-mask, tunggu DB, **APP_KEY auto-generate & persist
  di `storage/app/.appkey`** bila kosong, `migrate --force`, admin opsional `ADMIN_*` bila users kosong,
  `optimize`, exec supervisord — idempotent).
- `.dockerignore` (kecualikan vendor/node_modules/bin/rahasia/data runtime; **pertahankan** `.env.example`
  yang dibutuhkan build), `.env.docker.example` (env host-side: `APP_PORT`/`DB_*`/`ACS_*`/`ADMIN_*`).
- Launcher `start.bat`/`stop.bat`/`update.bat` (Windows) + `start.sh` (Linux/macOS).
- Dokumentasi: `docs/DOCKER.md` (panduan operator: pasang, admin, backup pg_dump, update, 2 mode
  distribusi, troubleshooting) + `docs/handbook/18-docker-appliance.md` (arsitektur container) + entri
  index handbook. Update `CLAUDE.md` (Commands + catatan Architecture jalur Docker).

**Keputusan desain:** app all-in-one (1 port publish, ramah appliance) sementara db/redis container
official terpisah; APP_KEY persist di volume supaya `docker compose up` tanpa tool host tapi enkripsi
stabil antar restart; env dari compose (bukan `.env` Laravel) → `optimize` dijalankan setelah env terisi
untuk menghindari gotcha config-cache→sqlite.

**Verifikasi:** Docker **tidak tersedia di environment build ini**, jadi build image belum dijalankan.
Validasi statis: `bash -n` semua skrip shell, YAML compose lint. Perlu dijalankan di PC ber-Docker:
`docker compose up -d --build` → `docker compose ps` sehat; `curl localhost:8080/` = 200,
`/dashboard` = 302; `migrate:status` di Postgres container; `bin/kv-snmp-poller` emit JSON;
`down && up -d` → data & login tetap ada. Perintah lengkap ada di `docs/DOCKER.md` §9 &
`docs/handbook/18-docker-appliance.md`.

### Delete ONU HiOSO (CLI `no onu {id}`) — untuk diuji langsung di UI

**Permintaan user:** aktifkan delete ONU HiOSO, mau langsung dicoba di UI (guide §5.6 menandai
`no onu {ONU}` sebagai kandidat belum diuji).

**Changed:**
- `HiosoCliWriteService::delete($olt, $port, $onuId)` — `no onu {id}` di dalam `interface epon 0/{port}`
  (`runInPon`, auto-jawab prompt konfirmasi bila muncul).
- `SmartOltSupport::hiosoEponCapabilities()` — `supports_onu_delete` → `true`.
- `HiosoOltController::deleteOnu()` (gated `supports_onu_delete`) + helper `removeCachedOnu` (buang ONU
  dari cache + sesuaikan count); rute `DELETE hioso-olt.onu.delete`.
- `Pages/Hioso/PortOnus.vue` — tombol Hapus (desktop+mobile, `canDelete`) + modal konfirmasi + `deleteOnu`.

**Test:** `HiosoOltTest::test_delete_calls_cli_no_onu_and_removes_from_cache` (fake writer, cek dipanggil
`['delete', port, onuId]` + ONU hilang dari cache). Semua test HiOSO/C-Data write lulus, pint bersih, build sukses.

**Verifikasi live (HA7304 OLT-HIOSO-NDOKATON):** kandidat `no onu {id}` (guide §5.6) & `onu {id} delete`
KEDUANYA ditolak (`% [DEFAULT] Unknown command`). Probe help CLI `EPON(epon_0/1)# ?` → verb delete ada
di **level interface**, bukan di bawah `onu {id}`: `delete onu {id}` ("delete config") & `dereg onu {id}`
("De-register onu"). Sub-command `onu {id}` hanya activate/deactivate/name/reboot/vlan/dst (tak ada delete).
**Syntax final: `delete onu {id}`** (lebih permanen dari dereg) — diuji live menghapus `epon 0/1/1:7`:
`ok=true`, ONU 7 hilang dari tabel (port 1 tinggal 1–6, 8–28). `HiosoCliWriteService::delete` dipakai.

### HiOSO dipisah: controller + rute + halaman sendiri (bukan lagi nebeng C-Data)

**Permintaan user:** "bisa ngga bikin hioso controller sendiri" — pisah penuh (controller + rute +
halaman `Hioso/*`), tujuan kerapian/organisasi kode. Sebelumnya HiOSO menumpang `CDataOltController`
+ rute `cdata-olt.*` + halaman `CDataOlt/*` dengan trik `?family=hioso`.

**Changed:**
- **`app/Http/Controllers/HiosoOltController.php`** (baru) — cermin CDataOltController tapi HiOSO-only:
  create/store/edit/update/destroy/test/detail/portOnus/refresh/refreshPortOnus + rebootOnu &
  updateOnuInfo (selalu `HiosoCliWriteService`, tanpa cabang). Tanpa deleteOnu (belum ada) & tanpa
  probe firmware V3 C-Data. Semua redirect → tab `hioso`.
- **Rute `hioso-olt.*`** (`routes/web.php`) — 13 rute paralel `cdata-olt.*` minus `onu.delete`.
- **Halaman `resources/js/Pages/Hioso/*`** — Create/Edit/Detail/PortOnus + `Partials/HiosoOltForm`
  (versi bersih: vendor tetap HiOSO, tanpa select family, tanpa blok firmware V3, tanpa tombol
  delete; rujuk rute `hioso-olt.*`). Reuse komponen presentasi `Components/CDataOlt/OltFaceplate`.
- **`SmartOltSupport::inventoryRoutePrefix($driver)`** (baru) — sumber tunggal pemilihan prefix rute
  inventori: `smartolt` / `cdata-olt` / `hioso-olt`. Dipakai `DashboardSearchController` (detail &
  port-onus), `OnuInventoryService` & `OnuMapController` (field baru `port_route` menggantikan
  boolean `olt_cdata` di frontend `OnuMonitor.vue` & `PinDetailCard.vue`).
- **`SmartOlt/Index.vue`** — tab non-ZTE berbagi body tabel; aksi (detail/edit/test/refresh/destroy/
  create) kini pilih prefix via helper `nonZteRoute()` berdasarkan `isHiosoTab`.
- **`OnuMapController` reboot/rename pin** — tambah cabang HiOSO (`HiosoCliWriteService`); sebelumnya
  HiOSO salah jatuh ke jalur ZTE (bug laten karena hanya cek `isCdata`).
- **`CDataOltController` dibersihkan** — hapus semua cabang HiOSO (`?family=hioso`, `tabFor()`,
  `isHioso` di reboot/rename, import `HiosoCliWriteService`); kini murni C-Data.
- **`AuthenticatedLayout.vue`** — nav match SmartOLT tambah `hioso-olt.*`.

**Test:** `tests/Feature/HiosoOltTest.php` (baru) — create form preset, store+scan+redirect tab hioso
+ global search tautkan ke `hioso-olt`, detail/port render dari cache, edit render. `fakeHiosoScan`
bind resolver + faceplate palsu (hindari SNMP timeout WAN). Test `test_create_form_presets_hioso_family`
dipindah dari CDataOltInventoryTest; test tab HiOSO tetap. Semua lulus, pint bersih, `npm run build`
sukses (halaman Hioso/* masuk manifest).

**Deploy note:** ada rute baru → `php artisan route:cache` di prod + reload php-fpm (opcache) untuk
controller baru. Frontend perlu `npm run build`.

### Polling HiOSO: Rx & status ONU sebagian tak terload saat polling terjadwal

**Permintaan user:** polling HiOSO belum lengkap — ONU-nya semua terload, tapi Rx & status kadang
tak benar/tak muncul; kalau di-refresh manual semua muncul.

**Diagnosis:** refresh manual (`CDataOltController::refresh`) dan polling terjadwal
(`PollOltJob::pollViaScanner`) memanggil kode yang **sama** (`CDataOltScanner::scan` →
`HiosoEponSnmpService`) — jadi bedanya bukan logika, tapi keandalan walk SNMP. Di
`getRegisteredOnus`: tabel **MAC** di-walk `robustWalk` (itu sebabnya semua ONU selalu terload),
tapi **Nama** cuma di-walk sekali & **Rx** lewat `robustWalk` tanpa acuan kelengkapan. Status
`online` diturunkan dari `rx !== null`, jadi kalau walk Rx terpotong (link WAN memutus GETBULK di
tengah — lebih sering saat polling terjadwal men-scan banyak OLT bersamaan), ONU yang Rx-nya hilang
salah tampak **Offline** & Rx kosong. `robustWalk` lama juga berhenti pada **satu** iterasi tanpa
baris baru → dua walk yang sama-sama pendek dikira "stabil" padahal belum lengkap.

**Changed:** `app/Services/Hioso/HiosoEponSnmpService.php`
- `getRegisteredOnus` kini mengumpulkan kunci ONU terdaftar (`{PON}.{ONU}` ber-MAC non-nol) dari
  tabel MAC dulu, lalu walk **Nama & Rx dengan kunci itu sebagai TARGET kelengkapan**. (Nama kini
  robust juga, tak lagi single-walk.)
- `robustWalk($olt, $oid, $targetKeys = [], $maxAttempts = 5)`: berhenti saat (1) semua target
  ter-cover (jalur cepat link sehat, umumnya 1 walk), (2) **dua** attempt beruntun tanpa baris baru
  (bukan satu — tahan prefix-terpotong yang kebetulan sama), atau (3) maxAttempts. Helper baru
  `coversKeys`.

**Test:** `tests/Unit/HiosoSnmpDriverTest.php` — `QueuedHiosoSnmp` (antrean hasil walk per-OID,
walk pertama terpotong lalu lengkap) + `test_robust_walk_recovers_rx_and_status_from_partial_walks`:
ONU yang hilang di walk Rx pertama dipulihkan walk kedua (online + Rx benar), bukan tercatat offline.
Dua test HiOSO lama tetap lulus. Pint bersih.

**Deploy note:** hanya kode driver PHP (dipakai `PollOltJob` di worker supervisor) — jalankan
`php artisan queue:restart` agar worker memuat kode baru. Trade-off: saat link lossy, walk Rx/Nama
bisa mengulang sampai 5× (lebih lambat tapi lengkap); saat sehat tetap ~1 walk karena target langsung
ter-cover.

### Endpoint ACS/TR069 bisa diatur dari Pengaturan (dipakai TR069 massal)

**Permintaan user:** (1) konfirmasi apakah scan TR069 massal juga menangkap ONU yang TR069-nya
sudah aktif tapi URL/username/password ACS-nya beda; (2) tambahkan di Pengaturan untuk set
URL/username/password ACS sehingga scan otomatis menargetkan yang belum sesuai (termasuk TR069
belum aktif).

**Konfirmasi perilaku (jawaban #1):** `ZteTr069BulkService::alreadyActive` men-skip ONU hanya bila
TR069 aktif **DAN** ACS URL cocok **DAN** username cocok. Jadi ONU dgn URL/username beda **sudah**
ditulis ulang; hanya kasus "URL+username sama, password beda" yang tak terdeteksi (password di
running-config di-mask firmware). User setuju ini cukup — password selalu mengikuti url+username.

**Changed (fitur #2):**
- **Model + tabel `AcsSetting`** (singleton, mirip `TelegramSetting`) — `url`/`username`/`password`
  (`encrypted` + `$hidden`). `resolved()` pakai nilai tersimpan, fallback ke `config('services.acs')`
  (env `ACS_*`) bila kosong; defensif thd tabel belum ada. Migrasi
  `2026_07_02_010000_create_acs_settings_table`.
- **`ZteTr069BulkService::acs()`** kini `AcsSetting::resolved()` (bukan lagi `config()` langsung) —
  jadi URL/username **dan** password dari Pengaturan dipakai di skip-check & script tulis.
- **Pengaturan:** tab baru "ACS / TR069" (`Settings/Index.vue`) + `SettingsController::updateAcs`
  + route `PUT settings/acs` (`settings.acs.update`). Password kosong = pertahankan lama.
- **Modal TR069 massal** (`Tr069BulkModal.vue`) tak lagi hardcode endpoint/user ACS — terima prop
  `acs` (url+username, tanpa password) dari `SmartOltController::portOnus` → `PortOnus.vue`.
- **Test:** `SmartOltTr069BulkTest::test_uses_acs_endpoint_configured_in_settings` — set ACS custom,
  ONU yg dulu "aktif" ke ACS lama kini ikut ditulis ulang dgn ACS baru (skip=0, applied=3, script
  memuat url/user/pass baru, tak ada host ACS lama).

**Verifikasi:** tinker round-trip — fallback→config, simpan→dipakai, password TERENKRIPSI di DB (raw
200 char, tanpa plaintext), service baca nilai sama. Migrasi dijalankan di DB live. Full test TR069
(7) + TelegramSettings (8, render Settings) lulus, pint bersih, `npm run build` sukses.

**Deploy note:** `ZteTr069BulkService` dipakai di `Tr069BulkConfigJob` (worker supervisor) — jalankan
`php artisan queue:restart` agar worker memuat kode baru. Bila route/config di-cache di prod,
`route:cache` (rute `settings.acs` baru) + reload php-fpm (opcache).

### Izinkan IP OLT sama selama SNMP port berbeda

**Permintaan user:** bisa menambahkan OLT dengan IP yang sama asalkan port SNMP-nya berbeda
(satu perangkat mengekspos beberapa OLT via port SNMP berbeda). Sebelumnya validasi menolak
dengan "The ip has already been taken."

**Changed:**
- **DB:** migrasi `2026_07_02_000000_make_snmp_olts_ip_unique_per_snmp_port.php` — drop unique
  `snmp_olts_ip_unique` (kolom `ip`), ganti unique komposit `(ip, snmp_port)`
  (`snmp_olts_ip_snmp_port_unique`). Punya `down()` (balik ke unique `ip` tunggal). Sqlite-compatible.
- **Validasi:** `CDataOltController::validated` & `SmartOltController::validated` — rule `ip.unique`
  kini `->where(snmp_port = request.snmp_port)->ignore($olt)` sehingga bentrok hanya bila IP **dan**
  port SNMP sama. Pesan error diperjelas: "Kombinasi IP + SNMP port ini sudah dipakai OLT lain…".
- **Test:** `CDataOltInventoryTest::test_same_ip_allowed_with_different_snmp_port` — IP sama port
  beda tersimpan dua-duanya; IP+port sama ditolak (`assertSessionHasErrors('ip')`).

**Catatan:** tak ada kode yang me-lookup OLT by IP (semua by id) — aman. Migrasi sudah dijalankan
di DB live (pgsql); index komposit terverifikasi. Full CData suite lulus, pint bersih.

## 2026-07-01

### HiOSO: aksi tulis ONU (rename + reboot) + decouple service dari C-Data

**Permintaan user:** aktifkan aksi tulis ONU HiOSO, dan **HiOSO pakai service sendiri — jangan
menumpang kode C-Data**.

**Decouple (service layer HiOSO berdiri sendiri):**
- `app/Services/Hioso/HiosoSnmp.php` (baru) — transport SNMP v1/v2c sendiri (default timeout/retry
  10s/3 untuk WAN). `HiosoValue.php` (baru) — helper parsing sendiri (clean/macFromHex/oidLastSegments/rxDbm).
- `HiosoEponSnmpService` & `HiosoFaceplateService` tak lagi memakai `CDataSnmp`/`CDataValue` →
  pindah ke `HiosoSnmp`/`HiosoValue`. Resolver resolve HiOSO via container (`app(...)`).
- `CDataSnmp::walk` dikembalikan ke signature semula (param timeout/retries tadi hanya utk HiOSO,
  kini di `HiosoSnmp`). Test fake diselaraskan.

**Aksi tulis (CLI telnet, guide §5.5):**
- `HiosoCliWriteService.php` (baru) — **self-contained**, tanpa trait C-Data. Telnet CRLF, banner
  login longgar (15/20s), prompt `EPON>`/`EPON#`. `setName`: `conf t` → `interface epon 0/{PON}` →
  `onu {ONU} name {label}` → `end` (nama alfanumerik+`_-.`, spasi→`_`, maks 32). `reboot`:
  `onu {ONU} reboot`. Deteksi error + mask password sendiri.
- `SmartOltSupport::hiosoEponCapabilities`: `supports_reboot`+`supports_onu_info_write` = true
  (`reboot_mode`/`description_mode` = `cli_hioso`); delete masih off (guide §5.6 belum diuji).
- `CDataOltController::rebootOnu`/`updateOnuInfo` branch ke `HiosoCliWriteService` bila `isHioso`
  (C-Data tetap `CDataCliWriteService`). UI `CDataOlt/PortOnus.vue` sudah gate tombol via caps →
  otomatis muncul.

**Verifikasi live (OLT-HIOSO-NDOKATON):** READ via HiosoSnmp = 55 ONU; WRITE rename
`epon 0/1/1:1` → `kvtest…` (SNMP konfirmasi berubah) → restore → nama asli (konfirmasi). ok=true,
error=null. Reboot pakai jalur identik (tak ditembak agar tak outage). **Full suite 238 lulus**, pint bersih.

### HiOSO polling: perbaikan 3 masalah (worker basi, ONU hantu, walk terpotong)

**Laporan user:** (1) polling HiOSO gagal terus (autopoll dimatikan), (2) `epon 0/1/2` di web OLT
KOSONG tapi scan menampilkan 19 ONU, (3) jumlah ONU per port kadang berkurang/tak lengkap.

**Akar masalah & perbaikan:**

1. **Polling gagal = worker supervisor menjalankan kode LAMA.** `kusumavision-worker` (uptime 2 hari)
   dijalankan sebelum kode HiOSO ada; di kode lama vendor "HiOSO EPON 25355" mengandung "epon" →
   salah diklasifikasikan C-Data EPON → walk OID 17409 → gagal. **Fix:** `supervisorctl restart
   kusumavision-worker:*` (muat kode baru). Sejak restart, semua poll `success=true`.
   *(Catatan: sempat salah diagnosa — query cek pakai kolom `ok`; kolom sebenarnya `success`.)*

2. **ONU hantu (PON2 = 19, harusnya 0).** Tabel nama `.37.1` memuat slot ter-reserve ber-MAC
   `000000000000` yang bukan ONU nyata (web OLT hanya hitung slot ber-MAC non-nol). **Fix:**
   `HiosoEponSnmpService::getRegisteredOnus` kini **iterasi dari tabel MAC** & skip MAC nol
   (`ZERO_MAC`); nama/Rx jadi lookup. `countRegisteredOnus` juga hitung MAC non-nol.
   Hasil: 28/0/10/17 = **55 ONU**, 50 online — persis sama dgn web OLT.

3. **Walk terpotong (jumlah berubah-ubah).** Link WAN ke HiOSO kadang memutus GETBULK di tengah →
   hasil partial. **Fix:** (a) `CDataSnmp::walk` kini terima `timeoutUs`/`retries` (default lama utk
   C-Data), HiOSO pakai 10s/3; (b) `robustWalk` — walk berulang (maks 3) lalu **gabung by-OID**
   sampai stabil, karena registrasi ONU tetap antar-walk. Stabilitas naik dari ~50% → ~85–100% run
   memberi 55/50 penuh.

4. **Status port PON "unknown".** ifOperStatus HiOSO tak reliable → `getPorts` mengembalikan
   `unknown`. **Fix:** `CDataOltScanner` menurunkan status dari jumlah ONU online (guide §6): ada ONU
   online = `up`, ada ONU tapi semua offline = `down`, tak ada ONU = tetap `unknown`. Hanya port
   ber-status `unknown` yang diturunkan (C-Data up/down dari ifOperStatus tak diubah). Hasil:
   PON1/3/4 = up, PON2 (kosong) = unknown.

5. **Faceplate panel-depan HiOSO.** `CDataFaceplateService` mengklasifikasi port dari pola nama
   C-Data (`epon/ge/xge 0/x/y`) yang tak cocok penamaan HiOSO (`Pon-Nni1..4`, `G1..G4`) → panel
   kosong. **Fix:** `HiosoFaceplateService` baru bikin layout fisik HA7304 (SNMP cuma expose 8 if,
   tak bedakan SFP/LAN, tak ada MGMT/Console): **4 PON (fiber, status dari ONU online) + 2 SFP
   (fiber, G3/G4) + 2 GE (copper, G1/G2, status ifOper) + MGMT + Console** (RJ45 statis).
   `CDataOltScanner` memilih faceplate per driver; `OltFaceplate.vue` render `fixed_ports`
   (default MGMT; HiOSO MGMT+Console). Device: HA7304 / SN / sw dari signature firmware.
   *Asumsi: G1/G2=GE copper, G3/G4=SFP fiber — bisa dibalik di `HiosoFaceplateService` bila panel beda.*

Changed: `app/Services/Hioso/HiosoEponSnmpService.php` (iterasi MAC, ZERO_MAC filter, robustWalk,
WALK_TIMEOUT_US 10s/3), `app/Services/CData/CDataSnmp.php` (param timeout/retries),
`app/Services/CData/CDataOltScanner.php` (status port turunan + pilih faceplate per driver),
`app/Services/Hioso/HiosoFaceplateService.php` (baru), `resources/js/Components/CDataOlt/OltFaceplate.vue`
(fixed_ports data-driven), test fake `walk()` diselaraskan (Hioso/CData/Faceplate). **Full suite 238
lulus.** Verifikasi live: poll OK, total 55, per-PON 28/0/10/17, status PON1/3/4=up; panel HA7304
4 PON + 2 SFP + 2 GE + MGMT + Console. Polling HiOSO aktif.

## 2026-06-30

### Driver OLT HiOSO / V-Sol EPON (25355) — family ke-4, read-only v1

**Tujuan (dari user):** petakan OID OLT HiOSO (`OLT-HIOSO-NDOKATON`, HA7304) supaya bisa dipakai di
SmartOLT. User menyediakan blueprint `docs/SMARTOLT_HIOSO_GUIDE.md` (referensi vendor dari project
lama). Scope yang disepakati: **B — read-only dulu** (deteksi vendor + daftar ONU + Rx di UI + ikut
polling); aksi tulis (rename/reboot CLI) menyusul.

**Verifikasi OID live (`103.189.249.161:2238`, v2c `SNMPREAD`):** sysObjectID `25355.4.3`, model
`HA7304/SN2018-03-00007`. Tiga OID ONU kanonik (index `.{PON}.{ONU}`, slot selalu 1) terbukti cocok
dengan guide §4.3: nama `25355.3.2.6.3.2.1.37.1`, MAC `25355.3.2.6.3.2.1.11.1`, Rx `25355.3.2.6.14.2.1.8.1`.
Catatan: **jangan walk** subtree `25355.3.2.6.2.1.*` (puluhan ribu entry — walk awal sempat nyangkut di
sini & keliru disimpulkan "tidak ada ONU"); pakai OID singular. ifTable `Pon-Nni*` tak reliable untuk
status PON → online diturunkan dari Rx valid.

**Arsitektur:** HiOSO menumpang infra non-ZTE yang sudah ada (resolver + `CDataOltScanner` + controller
`cdata-olt.*` + halaman `CDataOlt/*`), bukan §12 guide (yang dari project lain: `HiosoSnmpService`/
contract resolver/Blade — tidak ada di repo ini).

Created:

- `app/Services/Hioso/HiosoEponSnmpService.php` — driver SNMP read (implements `SmartOltSnmpDriver`):
  `getSystemInfo`/`getPorts`/`getRegisteredOnus`/`getPortRxMap`/dst. Pakai `CDataSnmp` (transport v1/v2c
  non-ZTE bersama, timeout floor 5s/2 retries) + helper `CDataValue` (clean/macFromHex/oidLastSegments).
  Output ONU bentuk-ZTE (slot=1, port=PON, onu_id; `interface` `epon 0/1/{port}:{onu}`; serial=MAC).
- `tests/Unit/HiosoSnmpDriverTest.php` — 2 test (parse inventory+Rx+offline `na`; buang Rx 0/out-of-range).

Changed:

- `app/Support/SmartOltSupport.php` — konstanta `DRIVER_HIOSO_EPON`, deteksi di `driverKey()`
  (needle `hioso|ha7304|25355|v-sol|vsol|v-solution`, sebelum needle `epon` C-Data), helper `isHioso()`
  + `isNonZte()` (= isCData||isHioso, untuk routing non-ZTE; gating write tetap `isCData`),
  `hiosoEponCapabilities()` read-only (snmp_rx on, semua write off).
- `app/Services/SmartOltSnmpServiceResolver.php` — `supports()`→`isNonZte`, `resolve()` map HiOSO →
  `HiosoEponSnmpService`.
- `app/Jobs/PollOltJob.php` — branch `isCData`→`isNonZte`; rename `pollCData`→`pollViaScanner`.
- Generalisasi titik routing non-ZTE `isCData`→`isNonZte`: `SmartOltController` (tab index, reject
  unconfigured, branch refresh monitor), `OnuInventoryService` (collect/findOne), `DashboardSearchController`
  (3 link), `OnuMapController` (`is_cdata` flag pin), `TelegramCommandHandler` (`/refresh`).
  *Tidak diubah:* `Api/V1/OltController.is_cdata` (field klasifikasi vendor, akurat — `driver` membedakan)
  & `OnuMapController::isCdata()` private (jalur write C-Data, sudah di-gate capability).
- **UI tab HiOSO terpisah:** `SmartOltController::index()` partisi 3 arah (zte/cdata/hioso),
  `resources/js/Pages/SmartOlt/Index.vue` tab ketiga "OLT HiOSO" (body tabel non-ZTE dipakai bersama,
  data di-switch per tab aktif). `CDataOltController::create()` terima `?family=hioso` (preset vendor
  `HiOSO EPON 25355`) + helper `tabFor()` → redirect store/update/test/refresh/destroy ke tab yang benar.
  `CDataOlt/Partials/CDataOltForm.vue` tambah opsi vendor HiOSO + label "Family OLT" + tombol Batal
  sadar-tab; `Create.vue`/`Edit.vue` judul family-aware; `Detail.vue` back-link sadar-tab.
- `docs/SMARTOLT_HIOSO_GUIDE.md` — dipindah dari root + catatan status implementasi nyata (§0).

**Verifikasi live (OLT sementara, lalu dihapus):** driver `hioso-epon-25355`, vendor_family
`HiOSO / V-Sol EPON`, scan = **74 ONU** (41 online) di 4 port; nama/MAC/Rx/online benar. Read-only
(reboot=0). **Full suite 236 test lulus** (1262 assertions).

## 2026-06-28

### REST API v1 (read-only) — untuk web aplikasi lain & Android

**Tujuan (dari user):** sediakan API agar aplikasi eksternal (web lain + Android) tinggal
memanggil endpoint untuk mengambil "hasil"/data monitoring. Lengkap dengan metode + dokumen.

**Desain:** API read-only `/api/v1/*`, autentikasi Bearer token (Laravel Sanctum, sudah
ter-install di composer tapi belum dikonfigurasi). Data dari snapshot polling terakhir
(`snmp_olts.last_test_result`) lewat service yang sudah ada — **tidak** menyentuh OLT live,
jadi cepat & aman. Aksi tulis (register/reboot/hapus) belum diekspos (roadmap v2).

**Endpoint:** `POST auth/login`, `GET me`, `POST auth/logout`, `GET summary`,
`GET olts`, `GET olts/{olt}`, `GET onus` (filter olt_id/status/q + paginasi),
`GET olts/{olt}/onus/{slot}/{port}/{onuId}`, `GET alarms`.

Created:

- `routes/api.php` — grup `v1`, `login` publik, sisanya `auth:sanctum`.
- `app/Http/Controllers/Api/V1/{Auth,Summary,Olt,Onu,Alarm}Controller.php` — controller tipis;
  reuse `OnuInventoryService`, `DashboardStatsService`, `SnmpOlt`, `AlarmEvent`. Envelope JSON
  konsisten `{data, meta}`; error format Laravel standar.
- `app/Http/Controllers/Api/V1/PublicStatusController.php` — **endpoint PUBLIK tanpa token**
  `GET /api/v1/public/status` (atas permintaan user untuk di-embed di web lain). HANYA angka
  agregat (OLT/ONU online-offline, alarm aktif, status per-OLT) — TANPA data pelanggan/IP OLT.
  CORS default Laravel sudah aktif untuk `api/*` (`allowed_origins: *`). Cache 30 detik.
- `app/Console/Commands/ApiTokenCommand.php` — `php artisan api:token <email> [--name=]` untuk
  token server-ke-server (integrasi backend lain tanpa UI login).
- `database/migrations/2026_06_28_000000_create_personal_access_tokens_table.php` — tabel Sanctum
  (sqlite-compatible; salinan migrasi standar Sanctum).
- `docs/API.md` — dokumentasi lengkap: auth (login/token/logout), tabel param tiap endpoint,
  contoh `curl` + bentuk JSON hasil, kode status/error, contoh klien JS(fetch)/Kotlin(Retrofit)/PHP(Guzzle),
  catatan operasional deploy + roadmap.
- `tests/Feature/Api/ApiV1Test.php` — 8 test (login ok/gagal, 401 tanpa token, /onus inventory+filter,
  detail ONU 200/404, /olts + detail, /summary). **Semua lulus**; full suite 230 test lulus.

Changed:

- `bootstrap/app.php` — daftarkan `api: routes/api.php` (`apiPrefix: 'api'`); `shouldRenderJsonWhen`
  agar `/api/*` selalu balas JSON walau klien lupa header Accept.
- `app/Models/User.php` — tambah trait `Laravel\Sanctum\HasApiTokens`.
- `app/Providers/AppServiceProvider.php` — rate limiter `api` (120 req/menit per token/IP).

UI manajemen token (Pengaturan → tab "API & Token"):

- `app/Http/Controllers/SettingsController.php` — `createApiToken` (flash plain-text token sekali),
  `revokeApiToken`, helper `apiTokensPayload` (guard `Schema::hasTable` agar tak 500 sebelum migrate).
  `edit()` kini mengirim prop `api` (base_url, public_status_url, new_token, tokens).
- `routes/web.php` — `settings.api-tokens.store` (POST) + `settings.api-tokens.destroy` (DELETE),
  di grup `role:admin`.
- `resources/js/Pages/Settings/Index.vue` — tab baru "API & Token": tampil Base URL + URL status publik
  (tombol salin), banner token-baru (tampil sekali + salin), form buat token, tabel/kartu daftar token
  + tombol cabut, peringatan simpan token di server. Build Vite OK.
- `tests/Feature/Api/SettingsApiTokenTest.php` — admin buat token (lalu token dipakai ke `/api/v1/me`),
  cabut token, non-admin 403. Semua lulus (total API+settings 12 test).

Notes / deploy:

- Rute **tidak** di-cache di prod (tak ada `bootstrap/cache/routes-*.php`) → endpoint langsung aktif
  begitu file ada. Yang masih perlu di server prod: `php artisan migrate` (buat tabel token; UI tab
  "API & Token" juga butuh ini — sudah di-guard `Schema::hasTable` agar halaman tak 500 sebelum migrate)
  lalu **`npm run build`** (aset Settings page baru) dan reload php-fpm. Tak ada perubahan `.env`/config.
  (Prod: migrate + reload php-fpm sudah dijalankan user.)

Keamanan — API dimatikan default (saklar):

- Atas permintaan user (API belum dipakai aplikasi mana pun → nol permukaan serangan), seluruh `/api`
  DIMATIKAN via saklar `$apiEnabled = false` di `routes/api.php` (return lebih awal; environment
  `testing` dikecualikan agar test API tetap jalan). Saat mati: semua `/api/*` → 404 (login & status
  publik ikut tertutup). **Aktifkan kembali:** `$apiEnabled = true` + `reload php8.3-fpm` (1 baris, rute
  tak di-cache). `SettingsController::edit()` mengirim `api.enabled = Route::has('api.public.status')`;
  tab UI menampilkan banner "API dinonaktifkan" + men-disable tombol Buat Token, dan `createApiToken`
  menolak server-side saat mati. Verifikasi: `route:list --path=api/v1` kosong di prod, 12 test API+settings
  tetap lulus di env testing.

### SmartOLT — Register ONU "mode Lanjutan" (editor granular) + fix modify service-port/service

**Problem (dari user, ONU profile-bound `==Configured by profile: VLAN1114==`):** edit/ tambah
config di Configure ONU sering ditolak OLT. Dua akar masalah ditemukan + 1 fitur baru.

1. **Modify service-port ditolak `%Code 66661: already existed`.** ZTE menolak membuat ulang
   `service-port {id}` yang sudah ada. Fix: saat MENGUBAH entri yang sudah ada, emit
   `no service-port {id}` dulu baru buat ulang (id baru / path copy & registrasi tetap tambah
   langsung). **Diverifikasi live**: `no service-port 2` + `service-port 2 …` lolos di OLT-C300-SEKARJALAK.
2. **Modify service di pon-onu-mng ditolak `%Code 64007: conflicting with u-profile`.** Pola sama —
   `diffServices` kini `no service {name}` dulu sebelum buat ulang saat modify. (Catatan: untuk
   **tambah service baru** ke ONU yang masih profile-bound, OLT tetap bisa menolak `64007` — itu
   batasan profile sisi OLT, bukan builder. Service kedua harus pakai **gemport sendiri**.)
3. **Register ONU mode Lanjutan** — register kini punya 2 mode: *Sederhana* (wizard template 1 service,
   lama) dan *Lanjutan* (editor granular per-baris tcont/gemport/service-port/service/uni-vlan/wan-ip),
   pre-fill template standar. Menyelesaikan kasus multi-service (mis. hotspot di gemport 2) yang tak
   bisa lewat wizard. Script registrasi penuh dibangun via `buildForRegistration` (delegasi ke
   `buildForCopy` — full build dari baseline kosong + baris `onu N type T sn S`).
4. **Notif jujur saat OLT menolak.** `detectError` dulu hanya kenal keyword Inggris → error ZTE
   `%Code …` lolos jadi `ok=true` (lapor "berhasil" palsu). Kini scan per-baris: kenali
   `%Code`/`%Error`/forbidden/exists/conflicting (abaikan `%Info` & warning "password is not strong"),
   dan **sebut command yang ditolak**. Flash `!ok` jadi "Konfigurasi/Registrasi/Provisioning belum
   berhasil — bagian ini ditolak OLT: `<command>` → %Code …". Success hanya saat benar-benar penuh ok.
5. **Hapus UNI VLAN.** `diffVlanPorts` dulu tak punya loop hapus → buang baris tak ber-efek. Kini:
   baris yang dihapus / di-set mode `na` meng-emit **`no vlan port {token} mode`** (keyword `mode`
   tanpa nilai). Verifikasi live di C300: `vlan port … mode na` ditolak `%Error 20202 Invalid input`,
   `no vlan port {token}` saja `%Error 20203 Incomplete`, `no vlan port {token} mode` **diterima**.
   Opsi "na (hapus)" ditambah di dropdown UNI VLAN.

Created:

- `resources/js/Components/SmartOlt/OnuConfigEditor.vue` — editor granular bersama (semua seksi tabel
  + WAN-IP/TR069/Remote-ONT + style `kv-*`), memutasi objek `config` di tempat. Dipakai ConfigureOnu
  **dan** RegisterOnu (mode Lanjutan). UNI VLAN punya opsi mode "na (hapus)" + hint.
- `tests/Feature/SmartOltAdvancedRegisterTest.php` — preview multi-gemport, store generated (audit
  tanpa eksekusi), store execute → `executed` (3 test).

Changed:

- `app/Services/ZteOnuReconfigureScriptBuilder.php` — `diffServicePorts`/`diffServices` no-then-recreate
  saat modify; tambah `buildForRegistration()`; `diffVlanPorts` emit `no vlan port {token} mode` saat
  hapus (helper `emitUniVlanDelete`), `vlanPortLine`/`uniToken` untuk mode sentinel `na`.
- `app/Services/ZteCliProvisioningExecutor.php` — `detectError` scan per-baris + `isErrorLine` (kenali
  error ZTE, sebut command gagal).
- `app/Http/Controllers/SmartOltController.php` — `registerOnuAdvancedPreview` + `storeOnuAdvanced` +
  `validatedAdvancedProvisioning` + `advancedRegistrationContext`; `reconfigureConfigRules()` diekstrak
  (dipakai bersama reconfigure); `registerOnuForm` kirim `advanced_defaults`; flash `!ok` di apply/
  register/execute diubah jadi "belum berhasil — bagian ini ditolak OLT: …".
- `routes/web.php` — `smartolt.register.advanced.preview` + `smartolt.register.advanced.store`.
- `resources/js/Pages/SmartOlt/ConfigureOnu.vue` — pakai `OnuConfigEditor` (editor inline diekstrak).
- `resources/js/Pages/SmartOlt/RegisterOnu.vue` — toggle Sederhana/Lanjutan + `advForm` + preview/apply
  mode-aware ke route advanced.
- `tests/Unit/ZteOnuConfigureTest.php` — test modify service-port & service no-then-recreate, +
  service baru tanpa `no`, + UNI VLAN hapus/explicit `na`, + `detectError` kenali `%Code`/abaikan banner.

Notes:

- Suffix `==Configured by profile: VLAN1114==` di nama ONU **dibaca apa adanya dari OLT** (bukan dari
  app) — bekas provisioning lewat platform SmartOLT; ONU itu ter-bind u-profile sehingga sebagian
  perubahan service ditolak OLT.
- Test suite penuh **218 passed**. Ingat gotcha: `php artisan config:clear` sebelum `php artisan test`
  (config ter-cache bikin test jalan sebagai env non-testing → 419/pgsql).

### C-Data — visualisasi faceplate (panel depan) di halaman Detail EPON & GPON

Halaman Detail OLT C-Data kini menampilkan **faceplate** ala SmartOLT (sesuai referensi gambar
user): chassis + port PON/GE/XGE dengan ikon fiber/copper, warna per status (up/down/shutdown),
cluster LED (SYS/ALM/MGMT), legend; plus baris stat ringkas (Total/Online/Offline ONU, Port up) dan
identitas device (model/serial/HW/SW) di kartu Info Sistem.

**SNMP ditelusuri & diverifikasi live** (read-only) di EPON-TAYU (#276) & FD1608S (#277):

- **Port fisik via IF-MIB** `ifDescr/ifOperStatus/ifAdminStatus` — klasifikasi dari prefix nama
  (`epon|gpon`=PON fiber, `ge`=uplink copper, `xge`=uplink fiber). Cocok persis dengan 2 gambar
  referensi: EPON = PON 0/1+0/2 (4+4) + GE(4) + XGE(4); FD1608S = PON 0/0 (8) + GE(4) + XGE(2).
- **Identitas device** `17409.2.3.1.*` (kedua family lapor 17409): model (GPON bersih `FD1608S-…`;
  EPON Hex-STRING null-padded → di-drop), serial, versi HW/SW, device type.
- **Health (CPU/suhu/memori) TIDAK ADA** via SNMP (host-resources/ENTITY-SENSOR/entPhysical kosong)
  → LED ALM tidak dikarang (tetap `off`).

Created:

- `app/Services/CData/CDataFaceplateService.php` — kumpulkan port + klasifikasi + identitas device
  dari IF-MIB & tabel `17409.2.3.1.*`; murni SNMP read, best-effort.
- `resources/js/Components/CDataOlt/OltFaceplate.vue` — render chassis/port/LED/legend (CSS, ikon SVG
  fiber/copper inline), horizontal-scroll di mobile.
- `tests/Unit/CDataFaceplateServiceTest.php` — klasifikasi port, subgrup PON per frame, drop model
  hex, null bila tak ada interface (3 test).

Changed:

- `app/Services/CData/CDataOltScanner.php` — inject `CDataFaceplateService`, isi `snapshot.panel`
  (try/catch; kegagalan tak menggagalkan scan/polling).
- `app/Http/Controllers/CDataOltController.php` — `serializeSnapshot` expose `panel`.
- `resources/js/Pages/CDataOlt/Detail.vue` — kartu "Panel Depan" + baris stat ringkas + field
  model/serial/HW/SW/tipe di Info Sistem.
- `docs/SMARTOLT_CDATA_GUIDE.md` — §5b baru (IF-MIB faceplate + tabel device `17409.2.3.1.*`).

Notes:

- Panel di-cache di `last_test_result.panel` (TTL-gated seperti data lain); discan ulang via tombol
  "Scan ONU" / auto saat cache stale. **Pre-populate ke-7 OLT C-Data** lewat CLI (kode baru) agar
  langsung tampil tanpa nunggu poll; scanner lama di worker mempertahankan key `panel` (tak terhapus).
- `productModel()` mem-buang nilai Hex-STRING termasuk yang ber-**trailing space** (kasus PEKALONGAN
  `4F 4C 54 … 00 ` → drop, headline fallback `EPON OLT`); ditambah ke unit test.
- prod: `opcache.validate_timestamps=On revalidate_freq=2` → php-fpm auto-pickup ~2 dtk (tak perlu
  reload manual). Worker long-lived → `php artisan queue:restart` agar poll latar pakai kode baru.
- Semua probe SNMP read-only. Test: 11 lulus (3 faceplate + 5 driver + 3 write) + 35 polling/telegram
  dgn config cache dipindah sementara → sqlite, lalu dipulihkan byte-identik.

## 2026-06-27

### C-Data — aksi Delete ONU (EPON & GPON) via CLI `ont delete`

Tambah aksi hapus/deregister ONU di halaman ONU per-port OLT C-Data (EPON & GPON), mirror pola
reboot yang sudah ada. **Command ditelusuri & diverifikasi live** lewat context-help CLI (read-only
`?`) di FD1608S (GPON, OLT 277) dan FD1108S (EPON, OLT 276): sintaks **identik**
`ont delete {port} {onuId}` (arg ke-2 boleh `<1-128>` | `all` | `offline-list`). Kandidat awal
`no ont {port} {onuId}` terbukti **salah** (grup `no ont` tak punya bentuk delete).

Changed:

- `app/Services/CData/CDataCliWriteService.php` — method `delete()` baru: `ont delete {port} {onuId}`
  di submode `interface {epon|gpon} 0/{slot}`, `confirm: true` (OLT minta y/n → dijawab otomatis).
- `app/Support/SmartOltSupport.php` — `supports_onu_delete` EPON & GPON `false → true`.
- `app/Http/Controllers/CDataOltController.php` — `deleteOnu()` (gated `supports_onu_delete`) +
  helper `removeCachedOnu()` (buang ONU dari cache `port_onus` + sesuaikan `count`).
- `routes/web.php` — `DELETE cdata-olt/{olt}/ports/{slot}/{port}/onus/{onuId}` → `cdata-olt.onu.delete`.
- `resources/js/Pages/CDataOlt/PortOnus.vue` — tombol Trash (desktop + mobile) gated `canDelete`,
  `ConfirmModal` konfirmasi destruktif.
- `tests/Feature/CDataOltWriteTest.php` — fake `delete()` + test wiring CLI + hapus cache (count→0).
- `docs/SMARTOLT_CDATA_GUIDE.md` — §6.1/§6.2 tambah `ont delete` + catatan verifikasi live;
  §7 baris matriks "Delete / deregister ONU" + flag `supports_onu_delete`.

### UI — hero banner gradient brand + semua card transparan

- `resources/js/Components/Dashboard/HeroBanner.vue` — hero tak lagi panel slate rata: base diagonal
  brand (slate→sky/cyan), layer `hero-tint` (wash cyan/indigo) + `hero-glow` (radial cyan mengisi sisi
  kanan kosong), scrim kiri-saja untuk kontras teks, judul gradient putih→sky.
- `resources/css/app.css` — semua permukaan glass (`kv-glass-panel/card`, `kv-filter`, `kv-panel`,
  `kv-card`, `kv-stat`) `bg-slate-900/40 → /10` (benar-benar tembus, andalkan `backdrop-blur`); hover
  `/60 → /20`. Input/select/tombol sengaja tetap pekat untuk keterbacaan.

Notes:

- Probe CLI live murni read-only (`ont ?`, `no ?`, `ont delete ?`), tiap baris ketik dibersihkan
  Ctrl-U — **tidak ada delete yang dieksekusi**.
- Test dijalankan dengan `bootstrap/cache/config.php` dipindah sementara → sqlite (3 C-Data write
  lulus, termasuk delete). Tanpa itu, `php artisan test` POST/DELETE kena 419 karena config prod
  ter-cache (session/CSRF ala prod) — sudah dipulihkan byte-identik.
- Aksi sinkron di controller (bukan queued job) → tak perlu `queue:restart`; tapi agar live di prod,
  php-fpm perlu reload opcache (assets sudah `npm run build`).

## 2026-06-26

### SmartOLT — gabung inventori OLT C-Data jadi tab (OLT ZTE / OLT C-Data)

Halaman OLT C-Data yang sebelumnya berdiri sendiri (menu sidebar terpisah) kini jadi **tab di halaman
SmartOLT**, gaya tab seperti halaman Pengaturan (state disinkronkan ke query `?tab`).

Changed:

- `app/Http/Controllers/SmartOltController.php` — `index()` kini mem-`partition` semua OLT jadi dua
  prop: `olts` (ZTE + unknown) dan `cdataOlts` (C-Data) untuk satu halaman dua tab.
- `app/Http/Controllers/CDataOltController.php` — `index()` kini **redirect** ke
  `smartolt.index?tab=cdata` (return type jadi `RedirectResponse`). Redirect `store`/`update`/
  `destroy`/`test` diarahkan ke `smartolt.index?tab=cdata` agar tab C-Data tetap aktif + flash
  bertahan; `refresh` tetap pakai `back()` (kembali ke URL pemicu yang sudah memuat `?tab=cdata`).
- `resources/js/Pages/SmartOlt/Index.vue` — ditambah tab bar (OLT ZTE / OLT C-Data); tabel ZTE +
  tabel C-Data (lengkap dengan tombol Refresh scan-penuh, badge FlashV3.x, info auto-poll) dalam
  satu halaman. `activeTab` dibaca dari `?tab` + disinkron ke URL via `history.replaceState` supaya
  bertahan saat reload/redirect-back. Tombol "Tambah OLT" di header mengikuti tab aktif.
- `resources/js/Layouts/AuthenticatedLayout.vue` — item menu "OLT C-Data" dihapus; menu "SmartOLT"
  kini `match` array `['smartolt.*','cdata-olt.*']` agar tetap aktif di halaman C-Data; `isActive`
  mendukung match array. Import ikon `Server` yang tak terpakai dibuang.
- `resources/js/Pages/CDataOlt/Detail.vue` & `Partials/CDataOltForm.vue` — back-link/Batal kini ke
  `smartolt.index?tab=cdata`.
- `tests/Feature/CDataOltInventoryTest.php` — disesuaikan: `cdata-olt.index` → assert redirect;
  store assert redirect ke `smartolt.index?tab=cdata`; pemisahan ZTE/C-Data diuji lewat prop
  `olts`/`cdataOlts` pada `SmartOlt/Index`.

Removed:

- `resources/js/Pages/CDataOlt/Index.vue` — tak terpakai (route `cdata-olt.index` kini redirect).

Notes:

- Route name `cdata-olt.index` sengaja dipertahankan (jadi redirect) untuk kompatibilitas
  back-link/bookmark — tak ada perubahan daftar route, jadi route cache prod tetap valid.
- Test dijalankan dengan `APP_CONFIG_CACHE` override → sqlite (9 CData + 23 SmartOlt/CData write
  lulus). Tanpa override, `php artisan test` nyasar ke pgsql/OLT live karena config ter-cache prod.

## 2026-06-23

### OLT C-Data — tombol Refresh ONU (scan penuh) di halaman index

Changed:

- `resources/js/Pages/CDataOlt/Index.vue` — tombol Refresh ONU per-OLT (desktop & mobile) memanggil
  `cdata-olt.refresh` (scan penuh system + ports + seluruh ONU; lebih berat dari Test SNMP), ikon
  RotateCw berputar selama proses (`refreshingId`). Melengkapi auto-refresh-saat-buka & command
  `/refresh` Telegram (commit aa48377).

### Peta ONU — pemolesan UI marker/kartu + tombol kontekstual "Lihat di Peta"

Lanjutan halaman Peta ONU (lihat entri 2026-06-22): perbaikan tampilan & UX dari masukan operator.

Changed:

- `resources/js/Components/Map/OnuMap.vue` — marker diganti ke bentuk **ikon Lucide `MapPin`**
  (sama seperti header/nav), diisi warna sesuai level RX + titik putih; ukuran dikecilkan (26px).
  Default base layer kini **OpenStreetMap** (sebelumnya Google Streets); emit `pin-position` juga
  saat `onMounted` agar kartu detail langsung muncul untuk pin yang difokuskan.
- `resources/js/Pages/Map/Index.vue` — kartu detail kini **menempel tepat di atas pin** (mengikuti
  pan/zoom via `pin-position`) menggantikan overlay pojok kiri-atas; latar kartu dibuat hampir solid
  (`slate-950/95`) + panah penunjuk; dukung prop `focus_pin_id` → auto-select pin saat dibuka.
- `resources/js/Components/Map/PinDetailCard.vue` — tombol dirapikan jadi grid 2 kolom + tombol
  danger "Hapus Pin" full-width; font diperkecil; latar lebih solid (tidak transparan).
- `app/Http/Controllers/OnuMapController.php` — `index()` dukung param `focus_olt/slot/port/onu`
  → center peta ke pin (zoom 17) + kirim `focus_pin_id`. Helper `onuKeyFromRequest()` dipakai bersama
  oleh `placementFromRequest()` & `focusFromRequest()`.
- `app/Http/Controllers/SmartOltController.php` & `CDataOltController.php` — `portOnus()` kirim
  `pinned_onu_ids` (ONU yang sudah punya pin di port itu).
- `resources/js/Pages/SmartOlt/PortOnus.vue` & `CDataOlt/PortOnus.vue` — tombol per-ONU jadi
  **kontekstual**: belum ada pin → "Tambah ke Peta" (ikon MapPin, buka modal); sudah ada pin →
  "Lihat di Peta" (ikon MapPinned hijau) → buka `/map` fokus ke pin tsb.

Notes:

- Belum diverifikasi operator di OLT live untuk aksi tulis (edit nama/reboot) dari kartu pin.

## 2026-06-22

### Halaman Peta ONU — sebaran pin ONU pelanggan lintas-OLT (Leaflet + Google keyless)

Peta geografis baru untuk menandai lokasi ONU pelanggan dari **semua OLT** (ZTE & C-Data),
melihat redaman RX per lokasi, dan aksi cepat (ganti nama / reboot) langsung dari pin.

**Created:**
- `database/migrations/2026_06_22_000000_create_onu_map_pins_table.php` — tabel `onu_map_pins`
  (koordinat + ref ONU `snmp_olt_id/slot/port/onu_id` + `serial_number` jangkar, field tambahan
  `customer_name/address/phone/notes`, `created_by`). Unique `(olt,slot,port,onu)` → 1 pin/ONU.
- `app/Models/OnuMapPin.php` — model + relasi `olt`/`creator`.
- `app/Services/OnuInventoryService.php` — agregasi ONU lintas-OLT dari cache `port_onus`
  (`collect()` + `findOne()`); sumber tunggal untuk ONU Monitoring & dropdown/search peta.
- `app/Http/Controllers/OnuMapController.php` — `index` (pin di-enrich data ONU live + capabilities),
  `store`/`update`/`destroy` (updateOrCreate per kunci ONU), `resolveLink` (parse koordinat URL
  Google Maps + follow redirect link pendek `maps.app.goo.gl`/`goo.gl`), `rebootPin`/`renamePin`
  (delegasi ke `ZteRemoteOnuService`/`CDataCliWriteService` lalu **balik ke peta**, beda dgn rute
  port-onus existing yang redirect ke halaman port).
- `resources/js/Pages/Map/Index.vue` — halaman peta (peta **lazy-load** via `defineAsyncComponent`),
  toolbar tambah-pin, overlay panel detail, mode placement dari Port ONUs.
- `resources/js/Components/Map/OnuMap.vue` — Leaflet; layer **Google keyless** (`mt{s}.google.com/vt`
  Streets/Satelit/Hybrid/Terrain) + OSM fallback via `L.control.layers`; marker `divIcon` warna per
  level RX (legenda), pulsa untuk offline; emit `map-click` (mode tambah) & `select-pin`.
- `resources/js/Components/Map/AddPinModal.vue` — dropdown bertingkat OLT→Port→ONU + **search global**;
  koordinat (dari klik peta/editable) + field pelanggan tambahan.
- `resources/js/Components/Map/PinDetailCard.vue` — detail pin (nama, OLT, port, RX badge, status) +
  aksi Edit Nama (modal) & Reboot (gerbang `caps`), Detail ONU/Port/Google Maps, Hapus pin.
- `resources/js/Composables/useRxLevel.js` — `rxLevel`/`rxBadgeClass`/`rxMarkerColor` (sumber tunggal
  ambang RX, dipakai OnuMonitor + peta).

**Changed:**
- `routes/web.php` — grup rute `map.*` (index, pins store/update/destroy/reboot/rename, resolve-link).
- `resources/js/Layouts/AuthenticatedLayout.vue` — nav item **Peta ONU** (ikon MapPin).
- `app/Http/Controllers/SmartOltController.php` — `onuMonitor()` pakai `OnuInventoryService` (DRY).
- `resources/js/Pages/SmartOlt/OnuMonitor.vue` — pakai composable `useRxLevel` (hapus duplikasi).
- `resources/js/Pages/SmartOlt/PortOnus.vue` & `resources/js/Pages/CDataOlt/PortOnus.vue` — tombol
  **Add Map** per-ONU (desktop+mobile): modal 2 opsi → paste link Google Maps (pin otomatis) /
  klik langsung di peta (buka peta mode placement pra-target ONU).
- `package.json` — dependency `leaflet`.

**Notes:**
- Tile Google keyless = endpoint tidak resmi (gratis, tanpa API key, cocok NMS internal); bila diblokir
  Google, ganti ke layer OpenStreetMap dari switcher.
- Build OK (`OnuMap` chunk async 152 kB, manifest aman). Migrasi jalan di sqlite (full suite 150 passed)
  & sudah diterapkan ke DB pgsql prod. **Belum diverifikasi operator di OLT live.**

## 2026-06-21

### Halaman OLT C-Data — aksi write: rename & reboot ONU (CLI)

Aksi tulis pertama untuk C-Data (rename/deskripsi + reboot), EPON & GPON. **Sintaks `ont` identik**
kedua family (terverifikasi via help CLI `?` di #276 & #277, read-only) — beda hanya keyword interface.

- `app/Services/CData/Concerns/InteractsWithCDataCli.php` (baru) — trait plumbing telnet (login/enable,
  baca berbasis prompt, pager, konfirmasi y/n). `CDataGponCliService` di-refactor pakai trait ini.
- `app/Services/CData/CDataCliWriteService.php` (baru) — `setDescription()` (`ont description {port}
  {onuId} <teks>` / `no ont description …`, sanitasi max 128, kosong→hapus) & `reboot()` (`ont reboot
  {port} {onuId}`, auto-jawab konfirmasi); submode `interface {epon|gpon} 0/{slot}`; mask password.
- `app/Support/SmartOltSupport.php` — capability C-Data EPON & GPON: `supports_reboot` &
  `supports_onu_info_write` = true (`*_mode = cli_cdata`), `read_only=false`. `supports_onu_toggle`
  tetap false (enable/disable belum diminta).
- `app/Http/Controllers/CDataOltController.php` — `rebootOnu()` & `updateOnuInfo()` (gate capability,
  `mutateCachedOnu` utk update nama di cache, flash). Helper `ifaceKeyword()` (epon/gpon dari driver).
- `routes/web.php` — `cdata-olt.onu.reboot` & `cdata-olt.onu.info`.
- `resources/js/Pages/CDataOlt/PortOnus.vue` — tombol Ubah nama (modal) & Reboot (ConfirmModal danger),
  gerbang `auth.can.manage_olt` + capability.
- `tests/Feature/CDataOltWriteTest.php` (baru) — rename memanggil CLI + update cache; reboot pakai
  keyword `epon`. Full suite 190 passed.
- **Terverifikasi operator (2026-06-21):** tombol Rename & Reboot berfungsi nyata di OLT live.

### Guide C-Data disinkronkan dengan temuan OLT live

`docs/SMARTOLT_CDATA_GUIDE.md` diperbarui agar akurat dgn hardware nyata (sebelumnya blueprint):
- §1: koreksi — FD1608S V3 melaporkan `sysObjectID 17409` (bukan 34592); family by `vendor`, bukan sysObjectID.
- §5.5: tabel `…18.26.1` (enumerasi 1 baris/ONU, nilai `-1`) utk count; legacy `1.3.4.1.1.*`/`18.2.1.*` absen di V3.
- §11: daftar file diganti ke implementasi nyata (`CData/CData*`, resolver, kontrak read-only v1).
- §13 (baru): verifikasi lapangan — tabel device, format kolom asli `show ont info all` & `show ont
  optical-info` (arg = port, harus di submode `interface gpon 0/{slot}`), CLI baca berbasis prompt.

### Halaman OLT C-Data — Rx per-ONU GPON via CLI (`show ont optical-info`)

Melengkapi GPON V3: Rx per-ONU (sebelumnya kosong; SNMP tak punya per-ONU). Diambil via CLI
dalam **satu sesi** bersama inventory.

- `app/Services/CData/CDataGponCliService.php` — `getOnts()` kini: `show ont info all` → grup per port →
  (submode `config` → `interface gpon 0/{slot}` → `show ont optical-info {port} all`) → `parseOpticalInfo()`
  (kolom `ONT_ID Rx Tx OLT_Rx Temp Volt Current`, `--`=N/A) → enrich `rx_power_dbm`/`rx_power_label`.
  Helper `command()` + prompt `PROMPT_CMD` (`#` enable/config/interface). Format diparse dari output asli #277.
- `tests/Unit/CDataGponCliParseTest.php` — +1 test `parseOpticalInfo` (Rx per ONT + `--`).
- **Verifikasi live #277:** 31/31 ONU dapat Rx (mis. −18,83 / −23,09 dBm) dalam **~430 ms** (info+optical
  satu sesi); cache `port_onus` terisi Rx → tampil berwarna di PortOnus & ONU Monitoring. Full suite 188 passed.

### Halaman OLT C-Data — Fase 2b: halaman Detail & PortOnus + integrasi ONU Monitoring/search

UI read-only + integrasi cache, menampilkan inventory ONU C-Data di browser & lintas-OLT.

- `app/Http/Controllers/CDataOltController.php` — `detail()` (system + ports + jumlah ONU/port),
  `portOnus()` (ONU per port dari cache + filter + highlight `focus`), `refresh()` (scan penuh via
  driver → tulis cache `port_onus` bentuk sama ZTE: system, ports, onus/slot_port), `refreshPortOnus()`
  (scan 1 port). Helper `serializeSnapshot()`.
- `routes/web.php` — `cdata-olt.{detail,refresh,port-onus,port-onus.refresh}`.
- `resources/js/Pages/CDataOlt/{Detail,PortOnus}.vue` (baru) + Index.vue dapat tombol Detail (Eye).
  PortOnus: klasifikasi redaman Rx (Good/Warning/Critical), status online/offline, last-down-cause.
- **Integrasi lintas-OLT:** `SmartOltController::refreshOnuMonitor()` kini driver-aware (C-Data lewat
  resolver, `refreshCdataMonitor()`); `onuMonitor()` menyertakan `olt_cdata` per ONU; `OnuMonitor.vue`
  `portOnuHref` & `DashboardSearchController` (OLT + ONU) memilih route `cdata-olt.*` vs `smartolt.*`.
- Fix: search/link slot **0** (GPON C-Data F/S `0/0`) — `$slot && $portNo` falsy utk slot 0 → diganti
  `!== null` supaya tetap nge-link ke port, bukan jatuh ke detail.
- `tests/Feature/CDataOltInventoryTest.php` — +3 test (Detail/PortOnus render, search link cdata + slot 0).
- **Verifikasi live:** scan #276 → 258 ONU (707ms), #277 → 31 ONU (346ms); ONU Monitoring lintas-OLT kini
  memuat **289 ONU C-Data**; global search ONU GPON → `cdata-olt/277/ports/0/1/onus`. Full suite 187 passed.

### Halaman OLT C-Data — Fase 2c: inventory penuh GPON via CLI (`show ont info all`)

Fix lanjutan setelah verifikasi: GPON FD1608S (#277, FlashV3) lewat SNMP cuma balas **1 ONU**,
padahal nyatanya **31 ONU**. Solusi: baca inventory via CLI telnet.

- `app/Services/CData/CDataGponCliService.php` (baru) — sesi telnet (login `User name:`/`Password:`
  CRLF strict, `enable`, auto-jawab pager), `show ont info all` → parse tabel
  `F/S P ONT_ID SN CONTROL RUN CONFIG MATCH LAST_DOWN DESC` (DESC boleh spasi/slash). Output bentuk
  cache sama dgn driver SNMP (+`source=cli`). Format diparse dari output asli #277, bukan tebakan.
- `app/Services/CData/CDataGponSnmpService.php` — `getRegisteredOnus()`: bila V3 **dan** kredensial
  telnet ada → pakai CLI; fallback ke SNMP v3 (parsial) bila CLI gagal/ kosong. Inject `CDataGponCliService`.
- `app/Services/SmartOltSnmpServiceResolver.php` — inject + teruskan `CDataGponCliService` ke driver GPON.
- `tests/Unit/CDataGponCliParseTest.php` (baru) — 3 test parser pakai sampel `show ont info all` asli
  (desc berspasi/slash, `--`→null, Deactive/Offline). Test lain disesuaikan (constructor +CLI).
- **Verifikasi live #277:** driver GPON kini balas **31 ONU** (`source=cli`, ~10,6 s) — interface, SN,
  online, admin, last-down-cause, deskripsi semua benar. `php artisan test` = 185 passed.
- **Pemetaan ulang OID #277 (walk langsung device):** dikonfirmasi tak ada jalur SNMP untuk atribut
  31 ONU. Tabel atribut V3 `.18.12.*` = 1 baris; `.18.26.1.{2..6}` meng-enumerasi 31 ONU tapi nilai
  `-1` (statistik kosong); `34592.1.3.100.*` = tabel sistem/counter (bukan inventory); tabel EPON
  `17409.*` & FD-ONU legacy `1.3.4.1.1.*`/`18.2.1.*` tidak ada. ⇒ CLI memang satu-satunya sumber atribut.
- Fix turunan: `countRegisteredOnus()` V3 dulu pakai `.18.12` → keliru lapor 1; kini pakai enumerasi
  `.18.26.1.2` → benar **31** (terverifikasi live). Atribut tetap via CLI.
- **Optimasi baca CLI:** read loop diubah dari "tunggu jeda diam X detik" → **berbasis prompt** (`readUntil`
  berhenti begitu prompt `#`/`>` muncul di ekor buffer). Inventory GPON #277 turun dari **~10,6 s → ~0,25 s**
  (3× konsisten), sama cepat dengan app lama. Login juga prompt-aware (`User name:`/`Password:`).
- Sisa: Rx per-ONU GPON (`show ont optical-info {port} all`) belum di-enrich — kandidat berikutnya.

### Halaman OLT C-Data — Fase 2a: layer driver SNMP (EPON + GPON) + wiring resolver

Driver SNMP read C-Data konkret (implements `SmartOltSnmpDriver`). Belum ada UI — fokus parsing
inventory yang teruji unit (data walk sintetis, tanpa perangkat). Bentuk array ONU disamakan dengan
cache `port_onus` ZTE (slot/port/onu_id/interface/serial_number/name/online/rx_power_dbm/…) supaya
nanti otomatis muncul di ONU Monitoring + search (integrasi cache = Fase 2b).

- `app/Services/CData/CDataValue.php` — helper parsing murni: clean, toInt, macFromHex (spaced/plain),
  eponRxDbm (centi-dBm `/100`, raw 0 = no signal), oidLastSegments, eponDecodeDeviceIndex (bitwise §3.1),
  parseEponOnuName (`epon 0/s/p onu id desc`).
- `app/Services/CData/CDataSnmp.php` — koneksi SNMP low-level (v1/v2c, output OID numerik untuk
  suffix-matching); `get()`/`walk()` overridable (di-stub saat test).
- `app/Services/CData/CDataEponSnmpService.php` — EPON 17409: inventory (name/mac/status/vendor/model/
  serial), Rx `2.3.4.2.1.4` (index `.deviceIndex.x.y`); slot/port/onuId dari onuName, fallback decode.
- `app/Services/CData/CDataGponSnmpService.php` — GPON 34592: legacy (index `slot.port.onuId`) + deteksi
  V3 (`…18.12.1.1`) → tabel v3 (index `.1.0.ifIndex.flow.onuId`, map slot/port via ifDescr `gpon X/Y/Z`).
  Rx per-ONU belum tersedia via SNMP (DDM hanya per-port; V3 → CLI di 2c).
- `app/Services/SmartOltSnmpServiceResolver.php` — kini me-return driver konkret (EPON/GPON); ZTE &
  unknown tetap exception. Inject `CDataSnmp`.
- `tests/Unit/CDataValueTest.php` + `tests/Unit/CDataSnmpDriverTest.php` — 10 test (helper + 3 driver
  end-to-end dgn stub SNMP + resolver per-family). `php artisan test` = 182 passed, nol regresi.

**Verifikasi OLT live** (#276 EPON `172.27.10.103`, #277 GPON FD1608S `172.27.10.105`):
- EPON #276: **258 ONU dalam ~650 ms**, slot/port/onuId dari onuName, online, MAC, Rx (mis. -17.14 dBm),
  nama pelanggan — semua benar.
- GPON #277: V3 terdeteksi, parsing slot/port benar, **tetapi SNMP hanya balas 1 ONU** (batasan
  firmware FlashV3 — guide §3.3). Inventory penuh menunggu CLI (Fase 2c).
- **Temuan penting:** kedua device melaporkan `sysObjectID = .1.3.6.1.4.1.17409` (GPON sekalipun) →
  auto-deteksi via sysObjectID saja salah; klasifikasi berbasis string `vendor` benar. Akibat ini:
  fix kosmetik dari verifikasi — `clean()` buang anotasi net-snmp `(0x..)`, serial EPON dinormalisasi
  ke MAC ber-":", dan `ping()` GPON dibuat sadar-V3 (jangan andalkan 34592 di sysObjectID).

### Halaman OLT C-Data — Fase 1: halaman inventori + Test/probe family

Halaman baru **OLT C-Data** (menu nav sendiri, prefix `/cdata-olt`) untuk CRUD inventori OLT
C-Data + Test koneksi. Belum ada read inventory ONU (itu Fase 2) — fokus identifikasi device.

- `app/Http/Controllers/CDataOltController.php` — index (hanya OLT C-Data via `isCData`), create,
  store, edit, update, destroy, test. `test()` pakai SNMP get generik (`OltSnmpClient::test`) untuk
  sysDescr/sysObjectID → `driverKey`; untuk family GPON, walk `…18.12.1.1` → set `cdata.firmware_v3`
  (deteksi FlashV3.x). SNMP dibatasi v1/v2c. Log `PollingEvent::KIND_OLT_TEST`.
- `routes/web.php` — 7 route `cdata-olt.*` (index/create/store/edit/update/destroy/test).
- `resources/js/Layouts/AuthenticatedLayout.vue` — menu nav "OLT C-Data" (ikon Server) setelah SmartOLT.
- `resources/js/Pages/CDataOlt/{Index,Create,Edit}.vue` + `Partials/CDataOltForm.vue` — tema `kv-*`,
  form dengan **Family select** (EPON 17409 / GPON 34592 → tulis ke kolom `vendor`), default CLI telnet
  (untuk inventory GPON V3 nanti). Index: badge family + badge `FlashV3.x`, status Test, aksi
  Test/Edit/Telnet/Hapus (Telnet reuse `smartolt.telnet.token`, vendor-neutral).
- `tests/Feature/CDataOltInventoryTest.php` — index/create/edit render; OLT C-Data tersimpan & hanya
  muncul di halaman C-Data (tidak bocor ke SmartOLT); OLT ZTE tidak muncul di halaman C-Data.
- Verifikasi: `php artisan test` CDataOlt+SmartOlt = 25 passed; `npm run build` OK (3 halaman + form
  ter-bundle); Pint bersih. Belum diverifikasi ke OLT C-Data live (menyusul saat probe perangkat).

### Halaman OLT C-Data — Fase 0: fondasi driver (non-ZTE)

Awal fitur halaman baru **OLT C-Data** (OLT non-ZTE: C-Data EPON `17409` & GPON `34592`, vendor lain
menyusul). Blueprint: `docs/SMARTOLT_CDATA_GUIDE.md`. Scope v1 disepakati = **monitoring read-only**
dan **terintegrasi** ke ONU Monitoring + global search. Fase 0 ini hanya fondasi (belum ada UI),
ZTE sengaja **tidak** di-refactor agar tetap stabil.

- `app/Support/SmartOltSupport.php` — konstanta `DRIVER_CDATA_EPON` / `DRIVER_CDATA_GPON`;
  `driverKey()` diperluas (ZTE prioritas → GPON 34592 hint spesifik → EPON 17409 → `cdata` polos
  default EPON; sysObjectID mengoreksi saat Test). Helper `isCData()`, `isCDataGponV3()`, dan
  capability matrix C-Data EPON/GPON (semua write = false, `read_only` = true; GPON V3 → Rx via CLI).
- `app/Contracts/SmartOltSnmpDriver.php` — kontrak read driver C-Data (ping, getSystemInfo, getPorts,
  getRegisteredOnus[ByPort], getPortRxMap, countRegisteredOnus, getUnconfiguredOnus). ONU dipaksa
  bentuk cache yang sama dengan ZTE (`onu_key`, interface, status, rx) agar konsisten lintas-OLT.
- `app/Services/SmartOltSnmpServiceResolver.php` — resolver family → driver C-Data; Fase 0 melempar
  exception deskriptif (driver konkret di-wire Fase 2). ZTE tetap pakai `OltSnmpClient` langsung.
- `app/Http/Controllers/SmartOltController.php` — `index()` mem-filter C-Data keluar (`isCData`)
  supaya tidak bocor ke halaman SmartOLT (ZTE); ZTE + unknown tetap tampil.
- Verifikasi: klasifikasi `driverKey` 9 kasus benar; `php artisan test` SmartOLT/Demo = 25 passed.
  Belum ada verifikasi OLT live (Fase 0 tanpa koneksi perangkat) — akan dilakukan mulai Fase 2.

### OLT C-Data: lepas dari polling background → auto-refresh saat halaman dibuka + command Telegram /refresh

Model refresh OLT C-Data dirombak (disetujui user): C-Data **tidak ikut polling background** (sekaligus
menambal bug lama — C-Data default `polling_enabled=true` sempat terpoll pakai driver ZTE), diganti
auto-refresh sinkron saat halaman dibuka (TTL 5 menit) + scan sekali saat OLT dibuat, dan bisa
disegarkan dari bot Telegram.

Created:

- `app/Services/CData/CDataOltScanner.php` — service `scan(SnmpOlt): int`: scan penuh (system + ports +
  seluruh ONU) lalu tulis cache `last_test_result.port_onus` bentuk sama dgn ZTE. Logika dipindah dari
  controller agar dipakai bersama controller + bot Telegram.

Changed:

- `app/Console/Commands/PollOltsCommand.php` & `app/Jobs/PollOltJob.php` — guard `SmartOltSupport::isCData`
  → OLT C-Data di-skip dari polling background (job juga early-return defensif bila ada dispatch nyasar).
- `app/Http/Controllers/CDataOltController.php` — `detail()`/`portOnus()` panggil `ensureFreshScan()`
  (re-scan via `CDataOltScanner` hanya bila cache > `CACHE_TTL_MINUTES` = 5m; sinkron); `store()` scan
  sekali saat OLT dibuat (tutup celah global search); `refresh()` delegasi ke scanner; `index()`
  `latest()` → `orderBy('name')`.
- `app/Http/Controllers/SmartOltController.php` — `index()` `latest()` → `orderBy('name')` (urut nama).
- `app/Services/Telegram/TelegramCommandHandler.php` — command baru `/refresh [nama|id]` (alias
  `/segarkan`): scan ulang OLT C-Data via `CDataOltScanner` lalu lapor per-OLT (handler yg tadinya
  read-only kini punya 1 pengecualian ini, OLT ZTE diabaikan); `/help` ditulis ulang jadi detail &
  terkelompok (Pantau jaringan / OLT & port / ONU & pelanggan / Aksi / Lainnya) lengkap alias & contoh.
- `resources/js/Pages/CDataOlt/Partials/CDataOltForm.vue` — hapus section form **Auto-Poll SNMP**
  (checkbox `polling_enabled` + interval poll/RX) di Add/Edit; tak relevan untuk C-Data.
- `resources/js/Pages/CDataOlt/Index.vue` — buang badge status polling (mobile + desktop).
- `tests/Feature/{CDataOltInventoryTest,OltPollingTest,TelegramWebhookTest}.php` — test baru:
  poll skip C-Data, scan-on-create searchable, auto-scan stale vs skip fresh, `/refresh` scan C-Data +
  abaikan ZTE, `/help` publik & memuat perintah.

Notes:

- TTL auto-refresh = konstanta `CACHE_TTL_MINUTES` (5m), bukan lagi `poll_interval_minutes` (field form
  dihapus). Cache `port_onus` persisten di DB → global search ONU tetap muncul untuk OLT yang pernah
  di-scan walau halamannya tak dibuka.
- `/refresh` sinkron di webhook: EPON via SNMP cepat, GPON V3 via CLI ~10 detik/OLT — pakai
  `/refresh <nama>` untuk satu OLT bila mau cepat; kegagalan per-OLT ditangkap (tak bikin Telegram retry).
- Verifikasi: `php artisan test` → 197 passed; `npm run build` sukses; Pint passed. Belum diverifikasi
  di OLT live sesi ini (perubahan alur refresh/command, bukan parsing baru).

### Landing page: tonjolkan fitur terbaru (multi-vendor OLT C-Data + bot Telegram interaktif)

Changed:

- `resources/js/Pages/Welcome.vue` — (1) kartu fitur baru **"OLT C-Data EPON/GPON"** jadi entri pertama
  grid Fitur dengan badge **"Baru"** (multi-vendor, monitoring lintas-OLT, rename & reboot ONU); tambah
  dukungan render `f.badge` inline di judul kartu. (2) Hero pill `C-Data EPON/GPON`. (3) Hardware strip
  dari "ZTE C-series" → **"ZTE C-series + C-Data EPON/GPON"** (label "Multi-Vendor Hardware") + pill
  `C-Data EPON`/`C-Data GPON`. (4) Fitur Telegram: "bot read-only" → **bot interaktif** (menu tombol,
  cari pelanggan, refresh OLT C-Data). (5) Marquee tambah `OLT C-Data EPON/GPON` & `Multi-Vendor OLT`.
  (6) Modul tambah kartu **"OLT C-Data"**. (7) Import ikon `Router`.

Notes:

- Badge "Baru" dirender inline (flex di `<h3>`), bukan `absolute`, karena `.kv-spotlight > *` memaksa
  `position: relative` pada anak langsung kartu → badge absolut akan salah posisi.
- Klaim dijaga akurat dgn scope nyata (C-Data = monitoring + rename/reboot; tidak overclaim provisioning).
  Galeri "Tampilan Aplikasi" tetap pakai screenshot ZTE asli (belum ada capture halaman C-Data).
- Verifikasi: `npm run build` sukses.

## 2026-06-17

### Refresh ONU per-port jauh lebih cepat (SNMP walk di-scope per-port)

Tombol "Refresh ONU" di halaman ONU per-port sangat lambat. `portOnusSnapshot()` ternyata walk
**seluruh tabel ONU OLT** (7 tabel) + **RX power seluruh OLT** + IF-MIB `gponPorts`, baru difilter
ke satu port. Diukur di OLT live: OLT#2 SEKARJALAK (2.123 ONU) **~56 dtk**, OLT#1 PATI (164 ONU)
**~11 dtk** per refresh.

Fix: tabel ONU ZTE di-index `{prefixIndex}.{onuId}` dengan `prefixIndex = zteEncodeIfIndex(slot,port)`.
Untuk C300/C320, walk hanya subtree `OID.{prefix}` (ONU port itu saja) + lewati `gponPorts`
(`port_row` tak dipakai frontend). Hasil setelah fix: OLT#2 2/3 (119 ONU) **~3,75 dtk**, OLT#1 2/5
(47 ONU) **~1,5 dtk** — sekitar **7–15× lebih cepat**, jumlah ONU & RX identik.

- `app/Services/Snmp/OltSnmpClient.php` — `registeredOnus($olt, $ports=null, $scope=null)` &
  `onuRxPowers($olt, $scope=null)`: bila `$scope` (prefix index) diberi, walk `joinOid(base,scope)`
  saja; scoped walk tak butuh IF-MIB port map (slot/port dari prefix via `decodeIfIndex`).
  `portOnusSnapshot()`: jalur scoped untuk non-C600 (skip `gponPorts`), C600 tetap full-walk.
- `tests/Feature/OltPollingTest.php` — sesuaikan signature override anonim (`$scope` baru).
- Poll terjadwal (`PollOltJob`, full OLT) tidak diubah. Deploy: reload php8.3-fpm.

### Aksi Delete ONU (deregister di OLT)

Tombol hapus ONU di halaman ONU per-port. CLI: di context `interface gpon-olt_x/y/z` →
`no onu {id}` (guide §8 rollback). Diverifikasi di OLT live #2 (C300-SEKARJALAK):
`no onu 1` pada `gpon-olt_1/4/9` → `.[Successful]` (menghapus ONU sisa uji copy).

- `app/Support/SmartOltSupport.php` — capability baru `supports_onu_delete` (ZTE = true).
- `app/Http/Controllers/SmartOltController.php` — `deleteOnu()` (gate `supports_onu_delete`):
  eksekusi `conf t / interface gpon-olt_… / no onu {id} / exit` via `ZteCliProvisioningExecutor`,
  lalu `removeCachedOnu()` membuang ONU dari cache `port_onus` (UI langsung update tanpa refresh
  penuh). Sinkron (aksi 1 ONU, cepat).
- `routes/web.php` — `POST …/onus/{onuId}/delete` → `smartolt.onu.delete`.
- `resources/js/Pages/SmartOlt/PortOnus.vue` — IconButton Trash2 (desktop + kartu mobile) dengan
  konfirmasi danger; di-gate `supports_onu_delete`.
- `tests/Feature/SmartOltDeleteOnuTest.php` — assert script `no onu 1` + `interface gpon-olt_1/4/9`
  terkirim dan ONU dibuang dari cache (count ikut turun).

### Copy konfigurasi ONU antar-port (batch) — pindah pelanggan tanpa register manual

Operator butuh memindahkan banyak pelanggan dari satu PON port ke port lain (OLT sama) tanpa
mengetik ulang registrasi satu per satu. Solusinya menumpang pipeline registrasi yang sudah ada:
baca running-config tiap ONU sumber → bangun script registrasi penuh untuk interface tujuan
(onu-id baru) → simpan sebagai baris `smartolt_onu_registrations` (status `generated`) → opsional
langsung dieksekusi. ONU di port asal **tidak disentuh** (copy, bukan move).

**Batch dijalankan di background job + progress bar** (bukan sinkron): batch 72 ONU + eksekusi =
±144 sesi telnet, jauh melebihi timeout 1 request HTTP. Versi sinkron pertama juga gagal-diam saat
operator pilih 72 ONU (cap lama 64 menolak validasi tanpa pesan). Untuk **ringan**: baca
running-config semua ONU sumber dalam **satu sesi telnet** (bukan satu sesi per ONU).

Created:

- `app/Services/ZteOnuCopyService.php` — orchestrator batch. Pra-baca config semua ONU dalam 1 sesi
  (`fetchMany`), lalu per ONU: alokasikan onu-id bebas terendah di port tujuan (anti-tabrakan dalam
  batch + vs cache target), bangun script via `buildForCopy`, simpan registrasi, eksekusi bila
  diminta. Callback `$onProgress` per ONU untuk update progres. Balikkan `{created, executed, failed, items[]}`.
- `database/migrations/..._create_copy_onu_tasks_table.php` + `app/Models/CopyOnuTask.php` — record
  progres batch (status queued/running/completed/failed, total/processed/created/executed/failed,
  items[]); `progressPayload()` untuk endpoint polling.
- `app/Jobs/CopyOnusToPortJob.php` — jalankan batch di queue (`$tries=1` — telnet tak idempoten,
  `$timeout=3600`), update `CopyOnuTask` per ONU.
- `tests/Feature/SmartOltCopyOnuTest.php` — endpoint antri task + dispatch job (Queue::fake), guard
  tujuan==asal (422), job menghasilkan 2 registrasi di port tujuan (id 2 & 3), endpoint status.

Changed:

- `app/Services/ZteOnuReconfigureScriptBuilder.php` — tambah `buildForCopy(config, context)`: script
  registrasi **penuh** (diff vs baseline kosong → semua direktif ter-emit) + prefiks baris OLT-side
  `interface gpon-olt_…` / `onu N type T sn S` + `encrypt 1 enable downstream` (samakan dengan
  `ZteProvisioningScriptBuilder`). C600 tanpa baris `description`. Reuse seluruh formatter privat
  (multi T-CONT/gemport/service-port, service, UNI-VLAN, WAN binding, multi WAN-IP, TR069,
  Remote ONT) sehingga ONU multi-WAN ikut tersalin utuh.
- `app/Services/ZteOnuRunningConfigService.php` — `fetchMany(olt, slot, port, ids)`: baca banyak ONU
  dalam 1 sesi telnet, lalu `segmentByInterface()` memecah dump gabungan per-interface (split di
  echo `show running-config interface gpon-onu_…`) dan parse tiap segmen; `looksConfigured()` jadi
  flag `ok` per ONU.
- `app/Http/Controllers/SmartOltController.php` — `copyOnusToPort()` kini **JSON**: validasi
  `onu_ids[≤256]` + tujuan + `execute`, buat `CopyOnuTask`, dispatch job, balikkan `{task_id, status_url}`.
  Tambah `copyTaskStatus()` (polling progres). Gate `supports_cli_onu_configure`.
- `routes/web.php` — `POST …/onus/copy` (`smartolt.port-onus.copy`) +
  `GET …/copy-tasks/{task}` (`smartolt.copy-task.status`).
- `resources/js/Pages/SmartOlt/PortOnus.vue` — checkbox seleksi (desktop + kartu mobile + "pilih
  semua" mengikuti filter), toolbar "Copy ke port lain", modal **3 fase**: form (pilih port tujuan
  dropdown/manual + opsi eksekusi) → running (progress bar + counter dibuat/dieksekusi/gagal, boleh
  ditutup, job tetap jalan, polling 1.5s via axios) → done (ringkasan + daftar gagal + link
  Registrations). Semua di-gate `supports_cli_onu_configure`.
- `tests/Unit/ZteOnuConfigureTest.php` — 3 test: 2× `buildForCopy` + 1× `fetchMany` segmentasi 1-sesi.

Notes:

- Baca running-config kini **1 sesi telnet untuk seluruh batch** (ringan). Eksekusi tetap per-ONU
  (di background) agar status & progres akurat per ONU.
- **Fix verifikasi OLT live**: baris `wan N service …` (mis. `wan 1 service internet tr069 host 1`)
  ditolak C300 saat input (`%Error 20201: Invalid command key word`) walau muncul di running-config —
  `buildForCopy` kini melewatinya; WAN dibuat penuh oleh `wan-ip N mode …` (selaras
  `ZteProvisioningScriptBuilder`). Sisa script (register/name/tcont/gemport/service-port/vlan-port/
  wan-ip/tr069/security-mgmt) sudah terbukti sukses di OLT live.
- Butuh worker queue jalan (`kusumavision-worker`). Setelah ubah kode job → `php artisan queue:restart`.
  Deploy: migrasi `copy_onu_tasks` dijalankan, worker di-restart.
- SN GPON unik — pada eksekusi nyata pastikan ONU sudah dipindah fisik / dihapus dari port asal agar
  tidak ditolak OLT. Default "generate dulu" memitigasi risiko. Belum diverifikasi di OLT live.

## 2026-06-16

### Bot Telegram interaktif — navigasi tombol LOS & redaman tinggi

Bot Telegram tadinya **text-only** (`message.text` saja; `callback_query` dibuang). Operator minta
navigasi tekan-tekan: buka OLT → pilih port → lihat daftar ONU (paginasi next/prev) → lihat siapa
yang LOS dan siapa yang redamannya tinggi, plus perintah langsung untuk dua daftar itu.

Created:

- `app/Services/Telegram/TelegramReply.php` — DTO `{text, keyboard}` yang dipakai bersama jalur
  command teks dan callback tombol (render layar sama → `sendMessage` baru atau `editMessageText`).
- `app/Services/Telegram/TelegramKeyboard.php` — encode/parse `callback_data` ringkas (<64 byte,
  mis. `on:5:1:2:1:3`), builder tombol/pager/back, konstanta `FILTER_ALL/LOS/RX`, `SRC_*`, `PAGE_SIZE`.
- `app/Services/Telegram/TelegramOnuQueryService.php` — query read-only atas cache `port_onus`:
  daftar OLT + ringkasan (online/offline/los/rx_alert), port per-OLT, ONU per-port, daftar LOS &
  redaman tinggi (global/per-OLT, urut terparah), detail ONU, `allOnus()` untuk search. **Sumber
  tunggal klasifikasi RX/LOS bot**: RX bertingkat `RX_WARN_DBM=-25`/`RX_CRIT_DBM=-28`/`RX_HIGH_DBM=-8`
  (`rxSeverity/rxIsAlert/rxBars/statusIcon/rxLine`); LOS = `online=false` (🔴 bila `last_down_cause`/
  `phase_state` ∈ {LOS,LOSi,DyingGasp}, selain itu ⚫). Customer pakai `SmartOltSupport` + fallback
  registrasi (DB) hanya di detail (list pakai data cache, hemat query).

Changed:

- `app/Services/Telegram/TelegramWebhookManager.php` — `allowed_updates` jadi
  `['message','callback_query']` (tombol tak terkirim Telegram tanpa ini → **wajib daftar-ulang
  webhook setelah deploy**).
- `app/Services/Telegram/TelegramNotifier.php` — `sendTo()` terima param `$keyboard` opsional
  (`reply_markup` inline); tambah `editMessage()` (edit in-place; "not modified"=sukses, error
  lain→fallback `sendTo`) + `answerCallback()` (matikan spinner, best-effort); refactor request ke
  helper `apiCall()`.
- `app/Services/Telegram/TelegramCommandHandler.php` — refactor besar: `handle()` balik `TelegramReply`
  (bukan string) + `handleCallback()` baru; kumpulan "screen renderer" (mainMenu, status, oltList,
  oltDetail, portList paginasi, portOnu paginasi+filter, onuDetail, losScreen, rxScreen, alarms
  paginasi, provisioning, onuSearch dengan tombol). Command baru: `/menu` (`/start`), `/los [olt]`,
  `/redaman` (`/rx`) `[olt]`, `/search` (`/cari`). Otorisasi allow-list berlaku juga untuk callback.
- `app/Services/Telegram/TelegramCommandHandler.php` (search global) — `/search`/`/cari` (+`/onu`)
  substring match lintas-OLT atas serial/nama/customer/interface (cap `SEARCH_LIMIT=60`), hasil >1
  jadi daftar tombol **berpaginasi**. Query disimpan di `Cache` (`tg:search:{token}`, TTL 1 jam) di
  balik token acak karena `callback_data` tak muat teks: tombol halaman `sr:{token}:{page}`, tombol
  ONU `su:{token}:{page}:olt:slot:port:onu`. Tombol menu "🔎 Cari ONU" buka instruksi (`srh`).
- `app/Http/Controllers/TelegramWebhookController.php` — cabang `handleMessage` vs `handleCallback`;
  callback selalu `answerCallback` lalu `editMessage` (fallback `sendTo` bila tak ada message_id).
- `docs/handbook/10-alarm-telegram.md` — dokumentasikan arsitektur handler, alur menu, command baru,
  allowed_updates, dan catatan ambang RX khusus bot (AlarmEvaluator/Dashboard tak diubah).

Notes:

- Ambang RX bot sengaja terpisah dari `AlarmEvaluator` (−28/−8 + histeresis) & `DashboardStatsService`
  (−25/−10) agar tak mengubah perilaku alarm/kartu dashboard. Keputusan produk: bertingkat −25/−28.
- `rx_power_dbm` bisa `null` (RX polling belum jalan) → daftar redaman hanya cakup ONU dgn data RX,
  detail tampil "RX belum terukur".
- Tes: `tests/Feature/TelegramWebhookTest.php` (+10: /menu, /los, /redaman, navigasi callback+edit,
  unauthorized callback, noop, allowed_updates, /search pager, paginasi+detail callback, token
  kedaluwarsa), `tests/Feature/TelegramOnuQueryServiceTest.php` (LOS urut, RX terparah, ringkasan
  count), `tests/Unit/TelegramKeyboardTest.php` (codec <64 byte, pager, rxSeverity). Full suite
  **159 passed**. Belum diverifikasi ke bot/OLT live.

### Configure ONU — multi WAN-IP + ping/traceroute response

Bagian **WAN** di halaman Configure ONU (CLI) tadinya hanya mendukung satu WAN-IP (`wan-ip 1`) dengan
field flat (`wan_mode`, `vlan_profile`, `pppoe_*`, `ip_profile`, `static_*`). Operator butuh bisa
membuat **lebih dari satu WAN-IP** (`wan-ip 1`, `wan-ip 2`, …) dan menyalakan **ping-response /
traceroute-response** per WAN-IP — sesuai CLI ZTE:

```
wan-ip 1 mode pppoe username U password P vlan-profile X host 1
wan-ip 1 ping-response enable traceroute-response enable
```

Changed:

- `app/Services/ZteOnuRunningConfigService.php` — parse WAN sekarang menghasilkan array `wan_ips`
  (per index `id`, plus `host`, `ping_response`, `traceroute_response`) menggantikan field flat. Tambah
  parsing baris `wan-ip {id} ping-response {enable|disable} traceroute-response {…}` + `host {n}`;
  `ensureWanIp()` seed/merge entry by id (urutan baris bebas), `ksort`+`array_values` di akhir.
- `app/Services/ZteOnuReconfigureScriptBuilder.php` — `diffWanIp` (single) → `diffWanIps` (iterasi
  array, key by id): emit ulang baris `wan-ip {id} mode …` bila berubah, baris probe terpisah hanya bila
  ping/trace berubah (mode tak ikut ter-emit), dan `no wan-ip {id}` untuk WAN-IP yang dihapus. `wanIpLine`
  kini terima `(row, mode, id)` + suffix `host {n}`; helper `fmtProbe` untuk change-list.
- `app/Http/Controllers/SmartOltController.php` — `validatedReconfigure` ganti rule flat WAN dengan
  `config.wan_ips.*` (id 1-8, mode pppoe/dhcp/static, host 1-16, ping_response/traceroute_response
  boolean). Audit row di `configureOnuApply` ambil field legacy (`wan_mode`, `vlan_profile`, dst.) dari
  `wan_ips[0]`.
- `resources/js/Pages/SmartOlt/ConfigureOnu.vue` — section WAN jadi editor multi-kartu: tombol header
  **Tambah WAN-IP** (`addWanIp`), tiap kartu punya badge WAN-IP {id} + hapus, selector Mode (PPPoE/DHCP/
  Static), field Index/Host/VLAN Profile, field PPPoE (toggle lihat password per-baris via `pppoeShown`)
  / Static, dan dua tombol toggle **Ping Response** & **Traceroute Response** (hijau saat aktif) + preview
  baris CLI. `summary` panel kiri turunkan info WAN dari `wan_ips`.
- `resources/js/Pages/SmartOlt/ConfigureOnu.vue` (fix dropdown) — VLAN/IP Profile yang dibaca dari running-config
  live tapi belum ada di katalog profil ter-sync (mis. `vlan-profile VLAN1114-NEW`) dulu jatuh ke
  "Tanpa profile" karena tak ada `<option>` yang cocok (berisiko nilainya hilang saat Apply). Sekarang nilai
  live disisipkan sebagai option `{nama} (dari OLT)` via computed `vlanProfileNames`/`ipProfileNames` sehingga
  tetap tampil & dipertahankan.
- `app/Services/ZteOnuRunningConfigService.php` (fix wrap parse) — root cause `vlan-profile KSM-PPPOE-VLAN-125`
  kebaca cuma `KSM`: ZTE membungkus baris config panjang pada lebar terminal dan **memotong nilai di tengah
  token** (`KSM` + `-PPPOE-VLAN-125`). `normalizeLines` lama menyambung baris continuation dengan menambah
  spasi → token pecah. Sekarang sambungan dilakukan **verbatim** (gabung potongan raw apa adanya): karena
  device hanya menyisipkan newline ke stream asli (char-wrap, spasi batas tetap ada di fragmen, continuation
  tak di-indent ulang, `CliOutputSanitizer` tak rtrim baris), penggabungan ulang merekonstruksi baris persis —
  benar untuk wrap di tengah token maupun di batas spasi. Test: `test_parses_wan_ip_value_wrapped_mid_token`
  & `test_parses_wan_ip_wrapped_at_space_boundary`.
- `resources/js/Pages/SmartOlt/ConfigureOnu.vue` (UI) — kolom **Type** dihapus dari tabel PON-ONU-MNG /
  Service (grid `cols.service` jadi 6 kolom, min-w 720→600); field `type` tetap di-parse tapi tak
  ditampilkan/diedit — builder reconfigure memang tak pernah meng-emit `type` di baris `service …`.
- `resources/js/Pages/SmartOlt/ConfigureOnu.vue` (UNI VLAN) — opsi Mode jadi **tag/hybrid/trunk/transparent**
  (ganti `access`→`tag`, default baris baru `tag`); kolom **VLAN** dihapus (cukup Def VLAN) → grid
  `cols.uniVlan` 6 kolom, min-w 760→680, `addVlanPort` tak lagi set `vlan`. Nilai `vlan` dari config
  live tetap dipertahankan saat round-trip (tak terhapus diam-diam). Mode **trunk** & **transparent**:
  input Def VLAN + Priority auto-disabled di UI, dan builder `vlanPortLine` skip emit `def-vlan`/`priority`
  untuk kedua mode itu (hanya tag/hybrid yang memetakan). Test: `test_uni_vlan_trunk_and_transparent_omit_def_vlan_and_priority`.

### WAN Service Binding — parser fleksibel + UI ala NetNumen

Bug: baris device `wan 2 service other mvlan 1001` tak ke-load karena parser lama pakai regex strict
`wan N ethuni X ssid Y service Z mvlan M host H` (semua field wajib, urutan tetap) — padahal NetNumen
emit token opsional & longgar urutannya.

Changed:

- `app/Services/ZteOnuRunningConfigService.php` — parse `wan {id}` fleksibel: tiap token (`service`,
  `mvlan`, `ethuni`, `ssid`, `host`) dimatch independen via `parseWanService`. `service` boleh multi-tipe
  (`internet tr069 voip other`) → `normalizeServiceTypes` jadi array kanonik (urutan internet/tr069/voip/
  other, dedup, hanya tipe dikenal). Field model `service` (string) → `services` (array).
- `app/Services/ZteOnuReconfigureScriptBuilder.php` — `diffWanServices` diff per-baris via `wanServiceLine`
  (bandingkan baris ter-render, bukan format kaku). Baris: `wan {id} service {tipe…} [mvlan] [ethuni]
  [ssid] [host]`; **mvlan hanya di-emit bila `other` dipilih**; `no wan {id}` saat dihapus. `normalizeServices`
  terima array/string (kanonik) → urutan service deterministik, tak ada delta palsu. Helper lama
  `fmtWanService` dihapus.
- `app/Http/Controllers/SmartOltController.php` — validasi `config.wan_services.*.services` array
  (`Rule::in([internet,tr069,voip,other])`), hapus rule `service` string.
- `resources/js/Pages/SmartOlt/ConfigureOnu.vue` — section dari tabel → kartu per-WAN (konsisten WAN-IP).
  Service Type = tombol multi-pilih (Internet/TR069/VoIP/Other) via `toggleWanServiceType`; field **MVLAN
  muncul hanya saat Other dipilih**; WAN ID/Host/Ethuni/SSID; preview baris CLI live (`wanServicePreview`,
  urutan token persis seperti builder). `cols.wanService` dihapus.
- `tests/Unit/ZteOnuConfigureTest.php` — 3 test: parse fleksibel (`wan 2 service other mvlan 1001` +
  multi-service), mvlan hanya untuk other, urutan service kanonik (tak ada delta palsu).

Notes:

- Urutan token CLI (`service … mvlan … ethuni … ssid … host`) sesuai satu-satunya sampel device nyata
  (`wan 2 service other mvlan 1001`); **perlu verifikasi live** untuk kombinasi internet+ethuni+ssid+host.
- Fix lanjutan (MVLAN kebaca `1001The`): baris trailing non-keyword setelah `wan …` (mis. teks/banner
  device) ikut tergabung saat unwrap verbatim → mencemari nilai. `parseWanService` kini capture token
  numerik/list dengan pola ketat (`mvlan/host` = `\d+`, `ethuni/ssid` = `[\d,\-]+`) jadi teks nyangkut
  diabaikan. Test: `test_wan_binding_numeric_tokens_ignore_trailing_text`.
- `tests/Unit/ZteOnuConfigureTest.php` — assertion WAN diubah ke shape `wan_ips`; tambah 4 test: parse
  multi WAN-IP + probe, enable probe hanya emit baris probe, tambah WAN-IP kedua, hapus WAN-IP (`no wan-ip`).

Notes:

- `RegisterOnu.vue` + `ZteProvisioningScriptBuilder` (alur provisioning awal) **tidak diubah** — masih
  pakai model WAN flat single; perubahan ini khusus alur reconfigure/Configure ONU.
- Verifikasi: `php artisan test` (ZteOnuConfigureTest 13 + SmartOltInventory/RegistrationExecution 36
  passed setelah `config:clear` — cached config sempat bikin 419 CSRF), Pint passed, `npm run build` OK.
  Belum diuji ke OLT live.

## 2026-06-15

### Monitoring beban processor per-board (CPU/Mem/PhyMem) di chassis

Output CLI `show processor` (CPU 5s/1m/5m, PhyMem, Memory% per slot) belum tampil di dashboard.
Ditemukan padanan SNMP-nya di **zxAnCardTable** (`1.3.6.1.4.1.3902.1015.2.1.1.3.1.X`, index
`rack.shelf.slot` — sama dengan kolom Rack/Shelf/Slot CLI): kolom `.9` = CPU%, `.11` = Memory%,
`.19` = PhyMem (MB). Diverifikasi langsung ke OLT live C300 (172.27.10.102) & C320 (172.27.10.101) —
nilai cocok dengan screenshot operator (Mem 21/40/58/23/7%, PhyMem 1024/512/2048/128 MB). SNMP hanya
mengekspos satu angka CPU (bukan pecahan 5s/1m/5m), dan kartu tanpa CPU (power `PRWG`, slot 0/1)
melapor PhyMem 0. Penempatan UI dipilih user: **overlay mini-bar CPU/Mem di tiap board pada
Visualisasi Chassis + detail saat hover** (bukan panel/tabel terpisah), karena board & processor
adalah objek yang sama. Diisi saat **Refresh Hardware** (gabung ke alur CLI `show card`) supaya
halaman detail tetap baca DB/cache (cepat, tanpa SNMP live).

Created:

- `database/migrations/2026_06_15_100000_add_processor_load_to_smartolt_card_statuses.php` — kolom
  nullable `cpu_load`, `mem_load`, `phy_mem_mb` di `smartolt_card_statuses`. Sqlite-compatible.

Changed:

- `app/Services/Snmp/OltSnmpClient.php` — const OID `ZTE_CARD_CPU/MEM/PHYMEM` + method
  `cardProcessors()` (walk 3 kolom, key `rack.shelf.slot`) + helper `cardIndexSuffix()`.
- `app/Services/ZteCardUplinkService.php` — inject `OltSnmpClient`; `mergeProcessorLoad()` gabung
  CPU/Mem/PhyMem ke baris card by rack/shelf/slot (non-fatal saat SNMP gagal; gerbang PhyMem>0 agar
  kartu power tak dapat bar); `serializeCard()` ekspos 3 field baru.
- `app/Models/SmartOltCardStatus.php` — fillable + cast integer untuk 3 kolom baru.
- `resources/js/Components/SmartOlt/OltChassis.vue` — helper `procFor`/`loadBarClass`/`procTitle` +
  computed `procByCardId`; overlay 2 mini-bar (CPU cyan / Mem sky, amber>70% merah>85%) di board
  orientasi vertikal (C300) & horizontal (C320), tooltip `CPU% · Mem% · PhyMem MB` saat hover; catatan legend.
- `tests/Feature/SmartOltHardwareInterfaceTest.php` — fake `OltSnmpClient` di test refresh (hermetik,
  tanpa I/O jaringan) + assert `cpu_load/mem_load/phy_mem_mb` tersimpan.

Notes:

- Verifikasi nyata: `refreshCardStatus()` ke OLT C300 live → slot 2/3/4 GTGH, 10/11 SCXN, 19/20 HUVQ
  terisi CPU/Mem/PhyMem; PRWG (power) null. Test suite (5/5) hijau saat config cache di-clear (gotcha
  pgsql/CSRF cached-config sudah dikenal); `php artisan config:cache` dijalankan ulang setelah test.
- OID identik untuk C300 & C320, jadi aman lintas driver.

## 2026-06-14

### Histori RX Power (time-series) + visualisasi distribusi & tren

NMS sebelumnya tidak menyimpan data historis apa pun — polling hanya menulis snapshot terakhir ke
`snmp_olts.last_test_result` + log event. Padahal riwayat **RX power** adalah indikator dini
degradasi fiber/splitter (RX turun perlahan sebelum ONU mati). Slice pertama roadmap: simpan
time-series RX per ONU dari job polling yang sudah ada, lalu tampilkan **histogram distribusi RX**
lintas-ONU di ONU Monitoring dan **grafik tren RX 24h/7d/30d** per-ONU di ONU Detail. Ambang zona
konsisten dengan yang sudah dipakai (`kritis < -28`, `warning -28..-25/-10..-8`, `sehat`, overload
`≥ -8`) — sama dengan `rxLevel()` di OnuMonitor & `ReportService`.

Created:

- `database/migrations/2026_06_14_000000_create_onu_rx_samples_table.php` — tabel time-series
  ringan (`snmp_olt_id`, `slot`, `port`, `onu_id`, `serial_number`, `rx_power_dbm`, `polled_at`)
  + composite index `onu_rx_samples_lookup_idx` untuk query tren. Sqlite-compatible.
- `app/Models/OnuRxSample.php` — model (`$timestamps = false`) + static `seriesFor()` (riwayat satu
  ONU sejak `$since`, urut waktu menaik).
- `app/Console/Commands/PruneOnuRxSamplesCommand.php` — `optical:prune-rx {--days=}` (default
  `config services.snmp_poller.rx_sample_retention_days` = 90), hapus bertahap (pilih id → whereIn,
  portabel sqlite/pgsql).
- `resources/js/Components/SmartOlt/RxDistributionCard.vue` — histogram bin dBm (ApexCharts bar)
  diwarnai per zona + legend ringkas (Sehat/Warning/Kritis). Dihitung client-side, tanpa ubah backend.
- `resources/js/Components/SmartOlt/RxTrendCard.vue` — area chart RX vs waktu + pita zona +
  toggle rentang (router.reload partial), ringkasan terakhir/rata-rata/tertinggi/terendah.
- `tests/Feature/OnuRxHistoryTest.php` — `seriesFor` (filter range+urutan), prune command,
  prop `rx_history` di route onu.detail (stub `ZteOnuDetailService` agar tak buka telnet).

Changed:

- `app/Jobs/PollOltJob.php` — `recordRxSamples()` bulk-insert sample RX **hanya saat RX poll
  sukses** (pakai list `$onus` yang sudah di-merge, baik jalur Go poller maupun PHP).
- `app/Http/Controllers/SmartOltController.php` — `onuDetail()` baca `range` (default 7d) →
  `OnuRxSample::seriesFor`, kirim props `rx_history`+`range`. Props live CLI fetch dijadikan
  **lazy closure** + memoized supaya partial reload (ganti rentang grafik) tidak memicu sesi telnet.
- `config/services.php` — tambah `snmp_poller.rx_sample_retention_days` (env `SNMP_POLLER_RX_RETENTION_DAYS`).
- `routes/console.php` — jadwal `optical:prune-rx` harian 03:15.
- `resources/js/Pages/SmartOlt/OnuMonitor.vue` — render `RxDistributionCard` (data `oltScopedOnus`)
  di bawah stat cards.
- `resources/js/Pages/SmartOlt/OnuDetail.vue` — props `rx_history`/`range`. Panel **Optical**
  diubah: RX power jadi **speedometer** (ApexCharts radialBar, busur diwarnai per zona + nilai dBm
  di tengah), Optical & **Tren RX Power** disusun **2 kolom** (`xl:grid-cols-2`), kartu
  Temperature/Voltage/Bias Current dihapus (selalu kosong di firmware ini).
- `tests/Feature/OltPollingTest.php` — 2 test baru: sample tercatat saat RX sukses; tidak tercatat
  saat RX walk gagal.

Notes:

- Diverifikasi: `./vendor/bin/pint` bersih, `php artisan test` **129 passed**, `npm run build` sukses.
- Gotcha terulang: `php artisan test` nyasar ke **pgsql** karena config ter-cache override
  `phpunit.xml` → `config:clear` dulu (test pakai sqlite :memory:), lalu `config:cache` ulang.
  Tidak ada kerusakan data (RefreshDatabase pakai transaksi yang di-rollback).
- Deploy diterapkan di server: `config:cache`, `php artisan migrate --force` (tabel onu_rx_samples
  dibuat di pgsql), `npm run build`, `queue:restart` (worker pakai PollOltJob baru). Sample akan
  mulai terisi pada siklus RX poll berikutnya.
- Di luar scope (slice berikutnya): sparkline per-baris di OnuMonitor, tren TX/suhu & trafik per-PON,
  peta geografis OLT, live push Reverb, bulk operations.

## 2026-06-02

### Kartu filter seragam — komponen FilterCard + toolbar satu baris lintas halaman

Tampilan kartu filter sebelumnya beda-beda di tiap halaman (3 pola: header inline+grid+tombol,
header ikon-tile+flex select, grid polos tanpa header). Diseragamkan jadi satu bentuk via komponen
`FilterCard` + kelas `kv-filter-*`, lalu dipadatkan jadi **toolbar satu baris** (cari `lg:flex-1` +
kontrol `w-full sm:w-auto`) agar tidak memanjang ke 2 baris. Tanpa label di atas tiap kontrol —
opsi pertama dibuat self-describing ("Semua Severity", "Semua OLT", dst), input tanggal pakai `title`.

Created:

- `resources/js/Components/Shell/FilterCard.vue` — shell kartu filter standar (shell kaca +
  header ikon-tile + judul/subjudul + slot `#actions` + body). Dipakai semua halaman ber-filter.

Changed:

- `resources/css/app.css` — kelas `kv-filter`, `kv-filter-head/body`, `kv-filter-grid`,
  `kv-filter-label`, `kv-filter-control` (kontrol seragam 44px), `kv-filter-actions`,
  `kv-filter-reset`/`kv-filter-apply`.
- `resources/js/Pages/SmartOlt/Alarms.vue`, `resources/js/Pages/AuditLogs/Index.vue`,
  `resources/js/Pages/Reports/Index.vue`, `resources/js/Pages/SmartOlt/OnuMonitor.vue` — filter
  diubah ke `FilterCard` + toolbar satu baris (Alarms/AuditLogs server-side dgn tombol Terapkan;
  Reports/OnuMonitor live).
- `resources/js/Pages/SmartOlt/PortOnus.vue`, `resources/js/Pages/SmartOlt/GponPorts.vue` —
  toolbar inline di header tabel diselaraskan ke `kv-filter-control`/`kv-filter-reset` (tetap inline,
  bukan kartu terpisah).
- `docs/handbook/15-ui-tema-dashboard.md` — bagian "Kartu filter (pola wajib)": standar = toolbar
  satu baris via `FilterCard`, grid berlabel jadi alternatif.

Notes:

- Konsistensi di level **shell kartu + kontrol**; layout internal menyesuaikan jumlah field. Label
  dilepas hanya jika opsi self-describing (Alarms/AuditLogs/OnuMonitor/Reports semua aman).
- PortManager tidak diubah (select-nya kontrol kontekstual di dalam panel, bukan kartu filter).
- Diverifikasi `npm run build` (sukses) + reload php-fpm.

### Perbaiki gambar halaman detail OLT (C320 rusak, tambah C600)

Halaman detail OLT (`Detail.vue`) menampilkan foto hardware per model. Referensi `/img/c320.webp`
**tidak ada** (file asli `c320(1).webp`) → gambar C320 patah/404, termasuk OLT utama `OLT-C320-PATI`.

Created:

- `public/img/c320.webp` — disalin dari `c320(1).webp` agar referensi `/img/c320.webp` valid.
- `public/img/c600.webp` — konversi `c600.png` via `cwebp -q 82` (≈34 KB).

Changed:

- `resources/js/Pages/SmartOlt/Detail.vue` — `oltImage` tambah mapping `c600` → `/img/c600.webp`
  (selain c320 & c300).

Notes:

- C300 tetap pakai `c300.webp` resolusi tinggi; `c300(1).png` yang diunggah hanya thumbnail low-res
  jadi **tidak** dipakai (akan pecah di `max-h-96`).
- Diverifikasi via curl lokal: `/img/c300.webp`, `/img/c320.webp`, `/img/c600.webp` → semua
  `200 image/webp` (c320 sebelumnya 404).
- File unggahan mentah `c300(1).png`/`c600.png` dibiarkan untracked (tidak ikut commit).

## 2026-06-01

### Filter per-jenis alarm Telegram — pilih jenis alert yang dikirim di Pengaturan

Sebelumnya semua jenis alarm yang lolos `min_severity` selalu dikirim ke Telegram. Sekarang admin
bisa memilih jenis alarm mana yang masuk Telegram (mis. hanya **LOS, Dying Gasp, Redaman RX tinggi,
Port GPON down**) lewat checkbox di **Pengaturan → Bot Telegram**. Filter berlaku untuk notifikasi
raise maupun clear.

Created:

- `database/migrations/2026_06_01_000000_add_notify_types_to_telegram_settings_table.php` — kolom
  `notify_types` (json, nullable) di `telegram_settings`. `null` = semua jenis (kompat lama);
  array eksplisit (termasuk kosong) dihormati apa adanya. Sqlite-compatible.

Changed:

- `app/Models/AlarmEvent.php` — tambah konstanta `TYPE_*` (6 jenis) + `TYPE_LABELS` (label ID) +
  `types()` sebagai **sumber tunggal** daftar jenis alarm.
- `app/Services/AlarmEvaluator.php` — literal string jenis (`'los'`, `'port_down'`, dll) diganti
  konstanta `AlarmEvent::TYPE_*` agar tidak drift dengan daftar di filter.
- `app/Models/TelegramSetting.php` — `notify_types` fillable + cast `array`; helper
  `notifyTypes()` (null → semua) & `shouldNotifyType($type)`.
- `app/Services/Telegram/TelegramNotifier.php` — `notify()` lewati alarm yang jenisnya tak dicentang
  (`shouldNotifyType()`), berlaku untuk raise & clear; filter severity tetap.
- `app/Http/Controllers/SettingsController.php` — payload `telegram.notify_types` +
  `alarmTypeOptions`; validasi `notify_types` (array of `AlarmEvent::types()`), hanya overwrite bila
  field dikirim (absen → pertahankan set lama), disimpan ternormalisasi & berurutan kanonis.
- `resources/js/Pages/Settings/Index.vue` — grup checkbox "Jenis alarm yang dikirim" (Pilih semua /
  Kosongkan semua, peringatan saat kosong) di tab Telegram.

Notes:

- Default & kompat lama: instalasi yang sudah ada (`notify_types = null`) tetap menerima semua jenis
  alarm sampai admin mengubah pilihan. Mengosongkan semua centang = membisukan semua notifikasi alarm
  (perintah bot tetap jalan).
- Diverifikasi: `php artisan test` (TelegramSettings/TelegramWebhook/AlarmEngine — 31 passed),
  `./vendor/bin/pint` (passed), `npm run build` (sukses), `php artisan migrate --force` (DONE).
- Catatan: saat menjalankan test, `bootstrap/cache/config.php` harus di-`config:clear` dulu (kalau
  ter-cache, test nyasar ke pgsql & gagal palsu); sudah di-`config:cache` ulang setelahnya.

## 2026-05-30

### Overhaul halaman Welcome — animatif, interaktif & premium (GSAP + Lenis + tsParticles)

Merombak total landing page (`Welcome.vue`) agar lebih profesional, modern, dan interaktif. Stack animasi di-upgrade dari AOS ke GSAP + ScrollTrigger (scroll reveal), Lenis (smooth scroll), tsParticles (latar jaringan), typed.js (CLI typewriter), dan NumberFlow (statistik beranimasi). Mengikuti rekomendasi skill `ui-ux-pro-max` (pola "Real-Time / Operations Landing", efek hemat & bermakna, hormati `prefers-reduced-motion`).

Created:

- `resources/js/Components/Shell/ParticleNetwork.vue` — latar partikel saling terhubung garis (topologi fiber/GPON) berbasis tsParticles (slim bundle), reaktif kursor (mode grab). Skip total saat reduced-motion.

Changed:

- `resources/js/Pages/Welcome.vue` — overhaul struktur & animasi:
  - **Hero baru**: latar `ParticleNetwork`, preview dashboard dengan **tilt 3D + parallax** (direktif `v-tilt`, anak `data-depth`), kartu **terminal CLI hidup** yang mengetik command ZTE (`show gpon onu state ...`) via typed.js, chip status ONU "live".
  - **Stats band baru**: 4 statistik dengan **NumberFlow** (count-up saat masuk viewport via IntersectionObserver).
  - **Section "Cara Kerja" baru**: 4 langkah (Hubungkan OLT → Discovery/Polling → Provisioning → Monitor/Alarm).
  - **Scroll reveal**: AOS dihapus, diganti `ScrollTrigger.batch` (stagger) pada elemen `[data-reveal]`. Intro hero pakai animasi **CSS murni** (`.reveal-hero`) agar selalu tampil walau JS belum siap.
  - **Magnetic button** (direktif `v-magnetic`) pada CTA utama; **tilt** pada kartu fitur & langkah.
  - Smooth scroll **Lenis** disinkronkan ke ScrollTrigger via `gsap.ticker`; anchor nav pakai `lenis.scrollTo` (offset header). Cleanup di `onBeforeUnmount`.
  - `ParticleNetwork` dimuat via **`defineAsyncComponent`** → tsParticles jadi chunk terpisah `ParticleNetwork-*.js` (~31 KB gzip, lazy). GSAP/ScrollTrigger/Lenis di-import statis. Welcome chunk ~70 KB gzip.
- `app/Providers/AppServiceProvider.php` — **menonaktifkan `Vite::prefetch(concurrency: 3)`**. Prefetch eager memuat SELURUH chunk app (~60) di setiap halaman termasuk landing publik; tiap deploy (hash berubah) + cache CDN dingin → badai request **503** di console (script prefetcher `(index)` menembak hash lama yang sudah terhapus build baru). Dimatikan; Inertia tetap memuat chunk halaman tujuan saat dibuka. Mudah dikembalikan (1 baris).
- `resources/js/app.js` — handler `vite:preloadError`: auto-reload sekali (throttle 10 dtk via sessionStorage) saat preload chunk gagal (mis. setelah deploy hash berubah) agar browser memuat HTML + asset map terbaru.
- `vite.config.js` — `build.emptyOutDir: false`: pertahankan chunk hash lama saat rebuild agar tab/sesi aktif tidak patah ketika deploy. Konsekuensi: `public/build/assets/` menumpuk seiring waktu — perlu dibersihkan berkala saat deploy.

Notes:

- **PENTING — penyebab 500 saat pertama deploy & fix-nya:** meng-import statis komponen yang menarik library dengan banyak `import()` dinamis internal (di sini `@tsparticles/slim`) ke dalam sebuah Inertia page membuat Rollup **menggabungkan facade chunk page** sehingga key `resources/js/Pages/Welcome.vue` **hilang dari Vite manifest**. `app.blade.php` mem-preload `@vite([... "resources/js/Pages/{$page['component']}.vue"])` → `Unable to locate file in Vite manifest` → **HTTP 500**. Solusi: bungkus komponen tsParticles dengan `defineAsyncComponent` (chunk terpisah, facade page tetap utuh). Setelah build, **reload php-fpm** (`systemctl reload php8.3-fpm`) karena Laravel meng-cache manifest Vite di memori per-worker. Diverifikasi: `https://nms.kusumavision.net/` → **HTTP 200**.
- Dependency baru: `gsap`, `lenis`, `@tsparticles/engine`, `@tsparticles/slim`, `@number-flow/vue`, `typed.js`. AOS belum di-uninstall (masih di `package.json`) tetapi tidak lagi dipakai di Welcome — bisa dibersihkan terpisah bila tak dipakai halaman lain.
- Aksesibilitas: semua animasi (partikel, tilt, magnetic, reveal, typed, NumberFlow) dimatikan/diabaikan saat `prefers-reduced-motion: reduce`. Tema dark dipertahankan (sesuai konteks ops/NOC console).
- Diverifikasi dengan `npm run build` (sukses) + cek key manifest + HTTP 200. Verifikasi visual mendetail (partikel, typed, count-up, tilt bergerak mulus) sebaiknya dicek manual di browser.

### Bot Telegram — webhook perintah (inbound, read-only)

Sebelumnya bot Telegram hanya outbound (notifikasi alarm + tes). Sekarang bot bisa menerima perintah via webhook dan membalas data jaringan. Akses perintah data dibatasi hanya untuk `chat_id` terdaftar; chat lain hanya bisa `/start /help /id /ping`. Semua perintah **read-only** (tidak ada aksi tulis ke OLT).

Created:

- `database/migrations/2026_05_30_000000_add_webhook_to_telegram_settings_table.php` - tambah kolom `webhook_secret` (encrypted, nullable) & `commands_enabled` (boolean, default false) ke `telegram_settings`. Sqlite-compatible.
- `app/Services/Telegram/TelegramCommandHandler.php` - builder jawaban semua perintah (`/status /olt [nama|id] /alarm /onu <serial|nama> /prov /id /ping /help`). Baca sumber data yang sama dengan UI (`DashboardStatsService`, `last_test_result.port_onus`, `alarm_events`, `smartolt_onu_registrations`) → jawaban konsisten dengan dashboard. Otorisasi via `TelegramSetting::isChatAuthorized()`. Output HTML (parse_mode), escaping konsisten dengan notifier.
- `app/Services/Telegram/TelegramWebhookManager.php` - register/info/delete webhook ke Telegram (`setWebhook`/`getWebhookInfo`/`deleteWebhook`); generate `webhook_secret` (`Str::random(48)`) saat register. Dipakai bersama artisan command & SettingsController.
- `app/Http/Controllers/TelegramWebhookController.php` - endpoint publik `POST /telegram/webhook`; validasi header `X-Telegram-Bot-Api-Secret-Token` (`hash_equals`) → 403 bila salah; abaikan update non-pesan; selalu balas 200 agar Telegram tidak retry.
- `app/Console/Commands/TelegramWebhookCommand.php` - `php artisan telegram:webhook {set|info|delete}`.
- `tests/Feature/TelegramWebhookTest.php` - 9 test (secret salah→403, commands off→tak balas, chat tak terdaftar→ditolak tanpa bocor data, /id & /ping publik, /status, /onu found/not-found, update non-pesan diabaikan, route register memanggil setWebhook).

Changed:

- `app/Models/TelegramSetting.php` - tambah `webhook_secret`/`commands_enabled` (fillable, hidden, cast); helper `commandsReady()` & `isChatAuthorized()`.
- `app/Services/Telegram/TelegramNotifier.php` - method publik `sendTo($chatId, $text)` untuk balas ke satu chat (tanpa menyentuh `last_sent_at`); konstanta `SEVERITY_EMOJI` dijadikan public agar dipakai handler.
- `app/Http/Controllers/SettingsController.php` - payload `telegram` tambah `commands_enabled`+`webhook_set`; validasi/fill `commands_enabled`; method `registerWebhook()`/`deleteWebhook()`.
- `routes/web.php` - route publik `telegram.webhook`; route admin `settings.telegram.webhook.register`/`.delete`.
- `bootstrap/app.php` - `validateCsrfTokens(except: ['telegram/webhook'])`.
- `resources/js/Pages/Settings/Index.vue` - tab Telegram: toggle "Aktifkan perintah bot", badge status webhook, tombol Daftarkan/Hapus Webhook, daftar perintah.

Notes:

- Diverifikasi: `php artisan test` (121 passed), `./vendor/bin/pint` (file baru bersih; temuan `bootstrap/app.php` pre-existing, tidak disentuh), `npm run build`.
- **Deploy:** jalankan `php artisan migrate --force`, lalu setup webhook — isi bot token + chat ID di Pengaturan, centang "Aktifkan perintah bot", klik **Daftarkan Webhook** (atau `php artisan telegram:webhook set`). Webhook butuh URL HTTPS publik valid (`APP_URL`); pastikan nginx meneruskan `POST /telegram/webhook`. Cek dengan `php artisan telegram:webhook info`.
- Batasan: murni read-only; aksi (reboot/refresh) belum ada — bisa ditambah kemudian dengan konfirmasi ekstra.

### Configure ONU — perbaikan tampilan mobile

Changed:

- `resources/js/Pages/SmartOlt/ConfigureOnu.vue` - enam tabel baris-berulang (T-CONT, GEM Port, Service-port, Service, UNI VLAN, WAN Service Binding) sebelumnya memaksa scroll horizontal di mobile (`min-w-[600px]`–`820px`). Sekarang responsif: tetap grid tabel di ≥768px, dan menjadi kartu ber-label (label per-field) di layar kecil. Karena `grid-template-columns` dipasang via inline style, pemilihan layout dikendalikan reaktif lewat `isWide` (matchMedia 768px) + listener resize. Tombol hapus baris jadi tombol full-width "Hapus baris" di mobile; action bar bawah (Batal/Apply) full-width di mobile. Tambah CSS scoped `kv-rowcard`/`kv-cell`/`kv-action-cell`/`kv-flabel`/`kv-del-mobile`.

Notes:

- Tata letak desktop tidak berubah (jumlah kolom grid tetap cocok dengan `cols.*`).
- Diverifikasi dengan `npm run build`.

### Pengaturan: Tab Umum (branding aplikasi)

Created:

- `database/migrations/2026_05_29_140000_create_general_settings_table.php` - tabel singleton `general_settings` (`app_name`, `app_version`, `logo_path`).
- `app/Models/GeneralSetting.php` - model singleton (pola `instance()` seperti `TelegramSetting`), `logoUrl()`, dan `brandingPayload()` yang ter-cache + defensif (fallback default bila tabel belum ada); cache di-bust pada event `saved`/`deleted`.

Changed:

- `app/Http/Controllers/SettingsController.php` - `edit()` kini mengirim payload `general` + `appInfo` (tech stack); tambah `updateGeneral()` (validasi nama/versi, unggah/hapus logo ke disk `public/branding`).
- `routes/web.php` - route `POST /settings/general` (`settings.general.update`, admin-only).
- `app/Http/Middleware/HandleInertiaRequests.php` - share `branding` (nama/versi/logo) global; `systemInfo.version` kini ambil dari `GeneralSetting`.
- `resources/js/Components/ApplicationLogo.vue` - render logo unggahan bila ada, fallback ke SVG bawaan.
- `resources/js/Layouts/AuthenticatedLayout.vue` - nama aplikasi (mobile bar, sidebar, footer) ikut `branding.name`.
- `resources/js/Pages/Settings/Index.vue` - dibuat 2 tab: **Umum** (identitas aplikasi: nama, versi, logo + kartu Informasi Sistem/tech stack) dan **Bot Telegram** (form lama dipindah tanpa perubahan).

Notes:

- Diverifikasi dengan `php artisan migrate`, `npm run build`, `./vendor/bin/pint`, dan `php artisan test` (112 passed).
- Symlink `public/storage` sudah ada; logo unggahan disajikan via `/storage/branding/...`.
- Deploy prod: jalankan `php artisan migrate --force` (kolom sqlite-compatible untuk test).

## 2026-05-28

### Local Production Hardening

Changed:

- `composer.lock` - patched Symfony security advisories affecting `symfony/http-foundation`, `symfony/polyfill-intl-idn`, and `symfony/routing`.
- `routes/web.php` and `resources/js/Pages/Welcome.vue` - removed public Laravel/PHP version exposure from the landing page payload/UI.
- `tests/Feature/Auth/RegistrationTest.php` - aligned coverage with the intended security posture: public self-registration is not available.
- `tests/Feature/SmartOltInventoryTest.php` - aligned ONU ID suggestion coverage with cached port snapshot behavior.
- `README.md` - documented production local setup, Nginx hardening, Supervisor scheduler, audit commands, and firewall/SSH baseline.
- `docs/INSTALLATION_STATUS.md` - updated runtime, hardening, verification, and production-local status.
- `docs/LOCAL_PRODUCTION_HARDENING.md` - added operational hardening guide for Laravel, Nginx, PHP-FPM, SSH, UFW, Supervisor, dependency audits, and smoke tests.

Notes:

- Server is configured for local production at `http://192.168.99.61`.
- UFW default incoming policy is deny; SSH/HTTP are allowed from private LAN ranges plus `103.189.248.0/24` and `103.189.249.0/24`.
- SSH password authentication is disabled; access is key-only.
- Verified with `npm run build`, `php artisan test`, `composer audit`, `npm audit --omit=dev`, and HTTP smoke tests.

### Mobile UI Pass

Changed:

- `resources/js/Layouts/AuthenticatedLayout.vue` - added mobile search/notification actions, safer sidebar behavior after desktop collapse, and overflow guards for the app shell.
- `resources/css/app.css` - added reusable mobile data-card utilities and mobile-friendly background handling.
- `resources/js/Pages/SmartOlt/*.vue`, `resources/js/Pages/Users/Index.vue`, and dashboard table components - added mobile card views for wide operational tables while keeping desktop tables intact.

Notes:

- Mobile views now avoid forcing horizontal table scrolling for OLT inventory, alarms, users, profiles, hardware cards, ONU lists, unconfigured ONU lists, and Port Manager summaries.
- Verified with `npm run build`.

## 2026-05-25

### Baseline

- Initialized local git repository on branch `main`.
- Committed Laravel 12 scaffold as `8c5f836 chore: scaffold Laravel application`.

### Phase 1 - OLT Inventory

Created:

- `database/migrations/2026_05_25_073700_create_snmp_olts_table.php`
- `app/Models/SnmpOlt.php`
- `app/Support/SmartOltSupport.php`
- `app/Services/Snmp/OltSnmpClient.php`
- `app/Http/Controllers/SmartOltController.php`
- `resources/js/Pages/SmartOlt/Index.vue`
- `resources/js/Pages/SmartOlt/Create.vue`
- `resources/js/Pages/SmartOlt/Edit.vue`
- `resources/js/Pages/SmartOlt/Partials/OltForm.vue`
- `tests/Feature/SmartOltInventoryTest.php`

Changed:

- `app/Http/Middleware/HandleInertiaRequests.php` - shared flash messages with Inertia pages.
- `routes/web.php` - added authenticated SmartOLT inventory routes.
- `resources/js/Layouts/AuthenticatedLayout.vue` - added SmartOLT navigation link.
- `app/Services/Snmp/OltSnmpClient.php` - rejects SNMP v3 in initial tester until v3 credentials are implemented.

Notes:

- OLT secrets use Laravel encrypted casts.
- Empty secret fields on edit preserve existing encrypted values.
- SNMP test currently reads system OIDs and stores result in `last_test_result`.

### Phase 2 - OLT Detail SNMP Read-Only

Created:

- `resources/js/Pages/SmartOlt/Detail.vue`

Changed:

- `app/Services/Snmp/OltSnmpClient.php` - added SNMP snapshot, IF-MIB walk, GPON port parser, and IF oper status decoder.
- `app/Http/Controllers/SmartOltController.php` - added detail and refresh actions.
- `routes/web.php` - added SmartOLT detail and refresh routes.
- `resources/js/Pages/SmartOlt/Index.vue` - added Detail action.
- `tests/Feature/SmartOltInventoryTest.php` - added detail page coverage.

Notes:

- Detail page reads cached `last_test_result` so it remains accessible when OLT is unreachable.
- Refresh SNMP updates system info and GPON ports in `last_test_result`.

### Phase 3 - ONU Per-Port Monitoring

Created:

- `resources/js/Pages/SmartOlt/PortOnus.vue`

Changed:

- `app/Services/Snmp/OltSnmpClient.php` - added ZTE registered ONU walks, SN decoder, admin/phase/last-down decoders, and per-port ONU snapshots.
- `app/Http/Controllers/SmartOltController.php` - added port ONU page and refresh action.
- `routes/web.php` - added port ONU read and refresh routes.
- `resources/js/Pages/SmartOlt/Detail.vue` - linked each GPON port to its ONU page.
- `tests/Feature/SmartOltInventoryTest.php` - added port ONU page coverage.

Notes:

- Per-port ONU cache is stored in `last_test_result.port_onus.{slot}_{port}`.
- Current implementation uses the ZTE modern ONU management table; legacy fallback remains a later task.
- Firmware `OLT-C320-PATI` exposes GPON port names via IF-MIB `ifName` (`gpon_1/2/1`), so GPON port detection now checks `ifName` before `ifDescr`.
- Firmware `OLT-C320-PATI` uses different IF-MIB port ifIndex values than ZTE ONU table ifIndex values, so per-port ONU filtering now matches decoded `slot/port` instead of raw ifIndex equality.

### Phase 4 - Unconfigured ONU and Provisioning Preview

Created:

- `database/migrations/2026_05_25_081500_create_smartolt_onu_registrations_table.php`
- `app/Models/SmartOltOnuRegistration.php`
- `app/Services/ZteProvisioningScriptBuilder.php`
- `resources/js/Pages/SmartOlt/Unconfigured.vue`
- `resources/js/Pages/SmartOlt/RegisterOnu.vue`
- `resources/js/Pages/SmartOlt/Registrations.vue`

Changed:

- `app/Services/Snmp/OltSnmpClient.php` - added ZTE unconfigured ONU discovery across documented OID candidates.
- `app/Services/Snmp/OltSnmpClient.php` - skips unavailable unconfigured ONU OID candidates and continues probing remaining candidates.
- `app/Http/Controllers/SmartOltController.php` - added unconfigured discovery, register form, generated script storage, and registration history.
- `app/Models/SmartOltOnuRegistration.php` - set explicit database table name for Laravel model resolution.
- `routes/web.php` - added unconfigured, register, and registration history routes.
- `resources/js/Pages/SmartOlt/Detail.vue` - added Unconfigured and Registration navigation.
- `tests/Feature/SmartOltInventoryTest.php` - added unconfigured and provisioning preview coverage.

Notes:

- Provisioning currently generates and stores the CLI script only; CLI execution will be implemented after Telnet/SSH session handling is added.
- Verified against OLT `id=1`: unconfigured ONU discovery returned SN `ZTEGCD7D2FD6` on slot 2 port 2 with suggested ONU ID 1.

### Phase 5 - Provisioning Profile Management

Created:

- `database/migrations/2026_05_25_093000_create_smartolt_profiles_table.php`
- `app/Models/SmartOltProfile.php`
- `app/Http/Controllers/SmartOltProfileController.php`
- `resources/js/Pages/SmartOlt/Profiles.vue`

Changed:

- `routes/web.php` - added SmartOLT profile management routes.
- `app/Http/Controllers/SmartOltController.php` - loads active ONU Type, T-CONT, VLAN, and IP profiles into provisioning defaults and validates selected profile values.
- `app/Services/ZteProvisioningScriptBuilder.php` - static WAN provisioning now accepts prefix subnet values such as `24`.
- `resources/js/Pages/SmartOlt/RegisterOnu.vue` - replaced profile text inputs with dropdowns and changed static netmask input to prefix subnet.
- `resources/js/Pages/SmartOlt/Index.vue` - added navigation to profile management.
- `tests/Feature/SmartOltInventoryTest.php` - added coverage for profile management and static provisioning profile loading.

Notes:

- Default profiles are inserted by migration: `ALL-ONT`, `SERVER`, `ServiceName` VLAN 100, and `INTERNET`.
- VLAN profile selection overwrites the submitted VLAN and service name with the active profile's configured values.

### Phase 6 - Provisioning Execution Audit

Created:

- `database/migrations/2026_05_25_101500_add_execution_fields_to_smartolt_onu_registrations_table.php`
- `app/Services/ZteCliProvisioningExecutor.php`

Changed:

- `app/Models/SmartOltOnuRegistration.php` - added execution output/error metadata and executor relation.
- `app/Http/Controllers/SmartOltController.php` - added provisioning execution action with status updates.
- `routes/web.php` - added registration execution route.
- `resources/js/Pages/SmartOlt/Registrations.vue` - added Execute action and execution output display.
- `tests/Feature/SmartOltInventoryTest.php` - added execution status coverage with a fake executor.

Notes:

- Automatic execution currently supports Telnet only. SSH is intentionally rejected with a clear message until an SSH driver is selected.
- Execution output is stored for audit and CLI password values are masked from captured output.

### Phase 7 - OLT-Scoped CLI Profile Sync

Created:

- `database/migrations/2026_05_25_112000_scope_smartolt_profiles_to_olt.php`
- `app/Services/ZteProfileCatalogService.php`

Changed:

- `app/Models/SmartOltProfile.php` - added OLT scope, CLI source metadata, params JSON, and sync timestamp.
- `app/Http/Controllers/SmartOltProfileController.php` - changed profile management to per-OLT, added sync-from-OLT, and optional CLI execution for add/edit/delete.
- `app/Http/Controllers/SmartOltController.php` - provisioning profile dropdowns now load profiles scoped to the selected OLT with global defaults as fallback.
- `routes/web.php` - moved profile routes to `/smartolt/{olt}/profiles` and added sync route.
- `resources/js/Pages/SmartOlt/Index.vue` - profile action now links to the selected OLT.
- `resources/js/Pages/SmartOlt/Profiles.vue` - added OLT header, Sync Dari OLT action, CLI execution checkboxes, and type-specific profile parameters.
- `tests/Feature/SmartOltInventoryTest.php` - added CLI profile sync coverage.

Notes:

- Verified read-only CLI against OLT `id=1`: `show gpon profile tcont`, `show gpon onu profile vlan`, `show gpon onu profile ip`, and `show onu-type` returned live profiles from `OLT-C320-PATI`.
- Profile add/delete CLI scripts follow the SmartOLT guide commands for `pon` and `gpon` config modes.

### Phase 8 - ONU RX Power in Port Table

Created:

- `app/Services/ZteOnuRxPowerService.php`

Changed:

- `app/Http/Controllers/SmartOltController.php` - refresh ONU per port now reads `show pon power onu-rx gpon-olt_1/{slot}/{port}` via CLI and merges RX values into cached ONU rows.
- `resources/js/Pages/SmartOlt/PortOnus.vue` - added ONU RX column with simple signal health coloring and RX error display.
- `tests/Feature/SmartOltInventoryTest.php` - added RX parser coverage and table fixture field.

Notes:

- If CLI RX read fails, SNMP ONU table still refreshes and the RX error is stored under `last_test_result.port_onus.{slot}_{port}.rx_power.error`.
- Verified against OLT `id=1` slot 2 port 1: `gpon-onu_1/2/1:3` returned ONU RX `-14.260 dBm`.
- Telnet reader now auto-continues pager prompts such as `--More--` by sending Enter, so RX output above one CLI page can be read fully.
- Verified long RX output against OLT `id=2` slot 2 port 3: parser read 112 RX values and reached the final CLI prompt without leftover pager markers.

### Phase 9 - Provisioning TR069 and Remote ONT

Created:

- `database/migrations/2026_05_25_123000_add_remote_ont_tr069_to_smartolt_onu_registrations_table.php`

Changed:

- `app/Http/Controllers/SmartOltController.php` - added provisioning defaults and validation for TR069 ACS and Remote ONT security management fields.
- `app/Models/SmartOltOnuRegistration.php` - added encrypted ACS password and casts for TR069/Remote ONT options.
- `app/Services/ZteProvisioningScriptBuilder.php` - emits `tr069-mgmt` and `security-mgmt` lines inside `pon-onu-mng` when enabled.
- `resources/js/Pages/SmartOlt/RegisterOnu.vue` - added TR069 and Remote ONT controls to the provisioning form.
- `tests/Feature/SmartOltInventoryTest.php` - added provisioning script coverage for TR069 and Remote ONT commands.

Notes:

- TR069 default follows the SmartOLT guide: ACS URL/user/password di-set lewat `.env` (`ACS_URL`/`ACS_USERNAME`/`ACS_PASSWORD`) — kredensial asli tidak ditulis di repo.

### Phase 10 - Profile Delete Scope and Live ONU ID Suggestion

Changed:

- `app/Http/Controllers/SmartOltProfileController.php` - profile management page now lists only profiles owned by the selected OLT, avoiding delete/update 404s from global fallback rows.
- `resources/js/Pages/SmartOlt/Profiles.vue` - hides edit/delete actions for any fallback profile row if present.
- `app/Http/Controllers/SmartOltController.php` - register form now reads `show gpon onu state gpon-olt_1/{slot}/{port}` to find the next free ONU ID, with cache and unconfigured suggested ID as fallback.
- `resources/js/Pages/SmartOlt/Unconfigured.vue` - passes the unconfigured ONU suggested ID into the register form.
- `tests/Feature/SmartOltInventoryTest.php` - added coverage for live CLI ONU ID suggestion and unconfigured suggested ID fallback.

Notes:

- Provisioning ONU ID is automatic from CLI state output. If CLI is unavailable, it falls back to cached port data or the unconfigured suggested ID; gaps such as used IDs `1,2,4` suggest `3`.

### Phase 12 - Remote ONU Management

Created:

- `app/Services/ZteRemoteOnuService.php`

Changed:

- `app/Services/Snmp/OltSnmpClient.php` - added `set()` for SNMP write (admin state, name, description) using the write community; rejects v3 and missing write community.
- `app/Services/ZteCliProvisioningExecutor.php` - extracted shared `run()` and added `executeConfirmable()` that auto-answers `y` to reboot confirmation prompts; `execute()` keeps its signature so existing test fakes are unaffected.
- `app/Http/Controllers/SmartOltController.php` - added `rebootOnu`, `setOnuState`, `updateOnuInfo`; capability-gated via `assertCapability`; updates the cached `port_onus` row so admin/name reflect immediately; resolves the ONU-table ifIndex from cache (falls back to request value, then encoded port ifIndex).
- `routes/web.php` - added `smartolt.onu.reboot`, `smartolt.onu.state`, `smartolt.onu.info` POST routes scoped under `onus/{onuId}`.
- `resources/js/Pages/SmartOlt/PortOnus.vue` - added per-row Reboot (confirm), Enable/Disable (label from admin_state), and Edit Info modal (name/description); buttons gated by OLT capabilities.
- `tests/Feature/SmartOltInventoryTest.php` - added coverage for reboot CLI script, SNMP SET on toggle and info, and capability gate (403 for non-ZTE driver).

Notes:

- Reboot uses CLI (`pon-onu-mng gpon-onu_1/{slot}/{port}:{onuId}` then `reboot`), per guide §5.5/§12.5. Enable/disable and edit name/description use SNMP SET (guide §5.6/§5.7), which is faster than CLI but requires the OLT write community to be set.
- The ONU admin-state/name/description OIDs are indexed by the ONU-table ifIndex (`.28.1.1.x.{ifIndex}.{onuId}`), which on `OLT-C320-PATI` differs from the IF-MIB port ifIndex; the frontend passes the row's `if_index` and the controller prefers it.
- Tests use fakes for both the CLI executor and SNMP client. Verified working against a live OLT on 2026-05-25: edit info, enable, disable, and reboot all function from the UI.

### UI Consistency Pass

Created:

- `resources/js/Components/ConfirmModal.vue`
- `resources/js/Components/IconButton.vue`
- `resources/js/Composables/useConfirm.js`

Changed:

- Replaced all native `window.confirm()` calls with the reusable `ConfirmModal` + `useConfirm()` promise flow (Index delete, Profiles delete x2, Registrations execute, PortOnus reboot/toggle).
- Row "Aksi" buttons across Index, Detail, Unconfigured, Registrations, Profiles, PortOnus are now icon-only (`IconButton`) with tooltips, standardized icon vocabulary, and variant colors. Header/toolbar buttons keep their labels.
- PortOnus icons: Reboot uses `Power`; Enable/Disable uses a dynamic toggle switch (`ToggleRight` active/amber, `ToggleLeft` disabled/green); Edit uses `Pencil`.

### Phase 13 - Background Polling Foundation

Created:

- `database/migrations/2026_05_25_140000_add_polling_fields_to_snmp_olts_table.php`
- `app/Jobs/PollOltJob.php`
- `app/Console/Commands/PollOltsCommand.php`
- `tests/Feature/OltPollingTest.php`

Changed:

- `app/Models/SnmpOlt.php` - added `polling_enabled` (bool, default true) and `last_polled_at` to fillable/casts.
- `routes/console.php` - schedules `olts:poll` every five minutes with `withoutOverlapping()`.
- `app/Http/Controllers/SmartOltController.php` - validates and serializes `polling_enabled` + `last_polled_at`.
- `resources/js/Pages/SmartOlt/Partials/OltForm.vue` - added auto-poll enable checkbox.
- `resources/js/Pages/SmartOlt/Index.vue` and `Detail.vue` - show auto-poll On/Off status and last poll time.

Notes:

- Poll depth is "standard": SNMP only (system info + GPON ports + full ONU table walk bucketed into `port_onus.{slot}_{port}` for online/offline). No CLI/Telnet RX during background poll to keep OLT load low; manual port refresh still adds RX.
- `PollOltJob` merges into the existing `last_test_result` instead of overwriting, preserving previously fetched RX power and unconfigured ONU data; RX is carried over per ONU by `onu_id`.
- `WithoutOverlapping($oltId)->dontRelease()` prevents stacked polls for the same OLT. Errors (OLT unreachable, SNMP v3) are stored in the snapshot, not thrown, so one bad OLT does not block the others.
- Requires a running scheduler (`php artisan schedule:work` or cron) and a queue worker/Horizon to actually execute.
- Verified end-to-end against live OLTs on 2026-05-25 (command -> redis -> worker): OLT-C320-PATI 8 ports / 156 ONU / 132 online (~1s); OLT-C300-SEKARJALAK 48 ports / 2084 ONU / 1400 online (~32s). The large C300 poll is heavy (~32s) but well within the 5-minute cycle, and `WithoutOverlapping` prevents stacking.

### Phase 14 - Alarm Engine (basic)

Created:

- `database/migrations/2026_05_25_150000_create_alarm_events_table.php`
- `app/Models/AlarmEvent.php`
- `app/Services/AlarmEvaluator.php`
- `app/Http/Controllers/AlarmController.php`
- `resources/js/Pages/SmartOlt/Alarms.vue`
- `tests/Feature/AlarmEngineTest.php`

Changed:

- `app/Jobs/PollOltJob.php` - calls `AlarmEvaluator::evaluate()` after each snapshot save.
- `routes/web.php` - added `alarms.index` (`GET /alarms`).
- `resources/js/Layouts/AuthenticatedLayout.vue` - added Alarms nav link (desktop + responsive).

Notes:

- Stateful raise/clear lifecycle: an active alarm is keyed by a `signature` (e.g. `onu:{serial}:los`, `port:{slot}/{port}:port_down`, `olt:unreachable`). Each evaluation updates `last_seen_at` on still-present conditions, raises new ones, and clears (status=cleared, cleared_at) conditions no longer present. No duplicate spam.
- Types & severity (tuned 2026-05-25 so `critical` stays actionable): `olt_unreachable` critical (skips ONU/port eval when OLT down), `port_down` critical, `los` major, `onu_offline` minor, `dying_gasp` minor, `high_rx_attenuation` warning (only when RX present, outside -28..-8 dBm). Admin-disabled ONUs are skipped.
- Rationale: live poll first produced 2079 active alarms dominated by 1948 `dying_gasp` (each customer ONT powered off reports dying gasp). Subscriber-side down events were downgraded to minor/major so `critical` is reserved for network-side faults. After tuning, live distribution = critical 10 (all `port_down`), major 70 (`los`), minor 1956, warning 43.
- Flapping and PON-port correlation (one alarm when most ONUs on a port drop together) are intentionally deferred.

### Phase 15 - Dashboard

Created:

- `app/Http/Controllers/DashboardController.php`
- `resources/js/Components/Pagination.vue`
- `tests/Feature/DashboardTest.php`

Changed:

- `routes/web.php` - `/dashboard` now uses `DashboardController` instead of a static Inertia render.
- `resources/js/Pages/Dashboard.vue` - rebuilt from the Breeze placeholder into a real dashboard: 4 stat cards (OLT online/total, ONU online, ONU offline, critical alarms), three ApexCharts (ONU online/offline donut, alarm severity donut, ONU-per-OLT stacked bar), per-OLT status table, and a recent-active-alarms panel.
- `app/Http/Controllers/AlarmController.php` - alarms list now `paginate(20)->withQueryString()->through()` instead of a flat 300-row list.
- `resources/js/Pages/SmartOlt/Alarms.vue` - renders `alarms.data`, a "showing X-Y of N" line, and the `Pagination` component.
- `tests/Feature/AlarmEngineTest.php` - added pagination assertion (25 alarms -> 20 per page).

Notes:

- Charts use `vue3-apexcharts` imported locally in `Dashboard.vue` (not registered globally). ApexCharts is heavy (~570KB) but the Dashboard chunk is code-split, so it only loads on that page.
- Dashboard aggregates entirely from the cached `last_test_result` snapshots + `alarm_events` (no extra live SNMP), so it renders instantly and reflects the latest background poll.
- Verified aggregation against live data on 2026-05-25: 2 OLTs online, 56 ports (10 down), 2240 ONU (1501 online / 739 offline), 2079 active alarms (10 critical / 70 major / 1956 minor / 43 warning).

### Phase 16 - Configurable Poll Intervals, SNMP RX Power, dan Go Poller

Created:

- `database/migrations/2026_05_25_151500_add_poll_intervals_to_snmp_olts_table.php` — kolom `poll_interval_minutes`, `rx_poll_interval_minutes` (default 5), dan `last_rx_polled_at` di tabel `snmp_olts`.
- `app/Services/Snmp/GoSnmpPoller.php` — wrapper opsional untuk binary Go SNMP poller (`bin/kv-snmp-poller`); diaktifkan dengan `SNMP_POLLER_DRIVER=go` dan binary yang ada.

Changed:

- `app/Models/SnmpOlt.php` — tambah `poll_interval_minutes`, `rx_poll_interval_minutes`, `last_rx_polled_at` ke fillable/casts; tambah method `isPollDue()`, `isRxPollDue()`, `pollIntervalMinutes()`, `rxPollIntervalMinutes()`.
- `app/Services/Snmp/OltSnmpClient.php` — tambah OID `ZTE_ONU_RX_POWER` (`1.3.6.1.4.1.3902.1012.3.50.12.1.1.10`); method baru `onuRxPowers()` (SNMP walk seluruh ONU RX power), `mergeOnuRxPowers()`, dan helper internal `extractOnuPortIndex()`, `convertOnuRxPowerToDbm()`, `onuRxPowerKey()`, `countSnmpRxPowers()`, `intFromValue()`; `portOnusSnapshot()` sekarang mengambil RX via SNMP.
- `app/Jobs/PollOltJob.php` — refactor besar: RX power kini via SNMP walk bukan CLI; RX hanya di-poll saat `isRxPollDue()` berlaku; nilai RX lama dipertahankan saat interval belum lewat; `last_rx_polled_at` di-update setelah RX berhasil; data per-ONU diperkaya dengan `rx_power_source`, `rx_power_port`, `raw_rx_power`; Go poller dicoba lebih dulu jika dikonfigurasi, jatuh balik ke PHP.
- `app/Console/Commands/PollOltsCommand.php` — hanya dispatch job untuk OLT yang `isPollDue()` (skip OLT yang belum waktunya); laporan dispatched vs skipped.
- `routes/console.php` — scheduler `olts:poll` diubah dari `everyFiveMinutes()` ke `everyMinute()` karena setiap OLT kini menjaga interval sendiri.
- `app/Http/Controllers/SmartOltController.php` — `refreshPortOnus()` dihapus ketergantungan `ZteOnuRxPowerService` (RX sudah di dalam `OltSnmpClient`); validasi dan serialisasi ditambah `poll_interval_minutes`, `rx_poll_interval_minutes`, `last_rx_polled_at`; `serializeSnapshot()` memperkaya tiap port dengan `onu_count`, `online_onu_count`, `onu_search_items`, dan `search_text` untuk pencarian frontend.
- `app/Support/SmartOltSupport.php` — tambah kapabilitas `supports_snmp_rx`; `rx_source_label` diperbarui ke `Rx ONU (SNMP)`.
- `config/services.php` dan `.env.example` — tambah blok konfigurasi `snmp_poller` (driver, binary, timeout, retries, walk_mode, max_repetitions).
- `.gitignore` — tambah `/bin/kv-snmp-poller`.
- `resources/js/Pages/SmartOlt/Detail.vue` — tabel port diganti dengan grid kartu; tiap kartu menampilkan nama port, status, jumlah ONU online/total; tambah search bar untuk filter port/ONU berdasarkan SN, nama, atau deskripsi; hasil pencarian menampilkan preview ONU yang cocok.
- `resources/js/Pages/SmartOlt/Index.vue` — tampilkan interval polling (`Xm · RX Xm`) di kolom auto-poll.
- `resources/js/Pages/SmartOlt/Partials/OltForm.vue` — tambah input `poll_interval_minutes` dan `rx_poll_interval_minutes`.
- `resources/js/Pages/SmartOlt/PortOnus.vue` — label UX diperbaiki: "Status Cache" → "Data", "OK/Empty" → "Tersedia/Kosong"; hapus `ifIndex` dari subtitle.
- `resources/js/Pages/SmartOlt/Unconfigured.vue` — label UX diperbaiki: "Detected ONU" → "ONU Terdeteksi", hapus kolom "Source OID".
- `tests/Feature/OltPollingTest.php` — tambah coverage: poll interval due/not-due, RX dari SNMP, preservasi RX saat interval belum lewat, pembersihan RX lama saat SNMP kosong.
- `tests/Feature/SmartOltInventoryTest.php` — tambah coverage `onuRxPowers()` via SNMP dengan multi-format raw value (`INTEGER:`, signed decimal, signed short, -32768 sentinel invalid).

Notes:

- RX power kini full SNMP (tidak perlu CLI/Telnet untuk background poll). Nilai raw dari ZTE ONU RX OID dikodekan dalam tiga format berbeda tergantung firmware: milli-dBm (`-18500`), deci-dBm (`-185`), dan linear 14-bit (`5635` → `(raw * 0.002) - 30`). Fungsi `convertOnuRxPowerToDbm()` mendeteksi dan mengkonversi ketiganya.
- Scheduler kini jalan tiap menit, tapi masing-masing OLT hanya benar-benar di-poll sesuai `poll_interval_minutes`-nya — lebih fleksibel dari sebelumnya yang fixed 5 menit untuk semua.
- Go poller adalah opsional akselerasi; default tetap PHP. Binary `bin/kv-snmp-poller` di-gitignore.

### Phase 17 - UI Consistency Pass

Changed:

- `resources/js/Components/Modal.vue` — container diubah ke `flex min-h-full items-center justify-center` sehingga modal muncul di tengah viewport secara vertikal maupun horizontal; `mb-6` dihapus dari panel modal.
- `resources/js/Layouts/AuthenticatedLayout.vue` — header wrapper ditambah `min-h-[68px] flex items-center` untuk konsistensi tinggi antar halaman; import `usePage` dari Inertia; slot `<main>` dibungkus `<Transition name="page" mode="out-in">` dengan key `page.component` sehingga setiap navigasi antar halaman memiliki efek animasi fade + slide.
- `resources/css/app.css` — tambah CSS kelas `.page-enter-active`, `.page-leave-active`, `.page-enter-from`, `.page-leave-to` untuk animasi transisi halaman (fade 180ms masuk, 120ms keluar, dengan geseran vertikal 4–6px).
- `resources/js/Pages/SmartOlt/Index.vue` — header kolom Aksi diubah dari `text-right` ke `text-center`; action cell menggunakan `flex justify-center`.
- `resources/js/Pages/SmartOlt/PortOnus.vue` — header kolom Aksi diubah dari `text-right` ke `text-center`; action cell menggunakan `flex justify-center`.
- `resources/js/Pages/SmartOlt/Profiles.vue` — header kolom Aksi diubah dari `text-right` ke `text-center`; action cell (mode view & mode edit) menggunakan `flex justify-center`.
- `resources/js/Pages/SmartOlt/Unconfigured.vue` — header kolom Aksi diubah dari `text-right` ke `text-center`; action cell menggunakan `flex justify-center`.

Notes:

- Transisi `out-in` memastikan halaman lama selesai fade-out sebelum halaman baru fade-in — menghilangkan kesan header "melompat" saat navigasi.
- Modal center fix berlaku untuk semua modal di seluruh aplikasi (ConfirmModal, edit info ONU, dsb.) karena semuanya memakai komponen `Modal.vue`.

## 2026-05-25 (lanjutan)

### Phase 18 - Manajemen User

Created:

- `app/Http/Controllers/UserController.php` — CRUD user: index, store, update, destroy; proteksi self-delete; password opsional saat edit.
- `resources/js/Pages/Users/Index.vue` — halaman tabel user dengan modal tambah/edit (name, email, password) dan konfirmasi hapus; label "(Anda)" untuk user aktif; avatar inisial.
- `.claude/commands/done.md` — skill `/done` untuk update WORKLOG dan push GitHub setiap selesai task.

Changed:

- `routes/web.php` — tambah 4 route user: `GET /users`, `POST /users`, `PUT /users/{user}`, `DELETE /users/{user}`.
- `resources/js/Layouts/AuthenticatedLayout.vue` — tambah nav link **Users** (desktop + responsive).

Notes:

- Registrasi publik sudah dinonaktifkan sejak commit sebelumnya; halaman ini menjadi satu-satunya cara menambah user baru selain `php artisan user:create`.
- Self-delete diblokir di controller (kembalikan error flash jika `$user->id === $request->user()->id`).

### UI Consistency Pass (lanjutan)

Changed:

- `resources/js/Pages/Profile/Edit.vue` — `py-12` → `py-8`, `shadow sm:rounded-lg` → `rounded-lg shadow-sm`, `p-4 sm:p-8` → `p-6`, tambah `px-4` pada container; sekarang konsisten dengan semua halaman lain.
- `resources/js/Pages/Users/Index.vue` — `max-w-4xl` → `max-w-7xl` (konsisten dengan halaman tabel lainnya).

### Detail OLT - Gambar Hardware dan Info Tambahan

Changed:

- `resources/js/Pages/SmartOlt/Detail.vue` — card Capability diganti card gambar OLT (`/img/c320.jpg` atau `/img/c300.jpg` sesuai nama OLT, fallback icon Router); tambah computed `oltImage`, `onuTotal`, `onuOnline`; card Latency diganti card **Total ONU** (online / total); sysUptime diformat dari timeticks ke `Xh Xj Xm Xd`.
- `resources/js/Pages/Dashboard.vue` — chart ONU per OLT diubah dari horizontal bar ke vertical column chart (`horizontal: false`, `columnWidth: 50%`, label X rotasi -30°).

---

## 2026-05-26

### Dashboard OLT — Status Card, Trafik Uplink, VLAN Mapping, Form Add VLAN

Created:

- `app/Services/ZteCardUplinkService.php` — service baru untuk operasi CLI terkait hardware dan uplink: `getCardStatus()` (parse `show card`), `discoverUplinkInterfaces()` (deteksi otomatis dari tipe card), `getUplinkInfo()` (parse `show interface`, status + traffic Bps), `getVlanMapping()` (parse `show vlan port`, support range notation multi-baris), `addAndTagVlan()` (eksekusi script `configure terminal → vlan → switchport vlan tag → write`). Card status dan VLAN di-cache 5 menit; traffic selalu fresh.
- `resources/js/Pages/SmartOlt/Dashboard.vue` — halaman dashboard baru: tabel status card hardware (INSERVICE/STANDBY/OFFLINE), indikator UP/DOWN interface uplink, grafik trafik real-time ApexCharts (polling 10 detik), badge VLAN tagged per range, form tambah & tag VLAN dengan toast notification.

Changed:

- `app/Http/Controllers/SmartOltController.php` — tambah 4 method: `dashboard()` (render halaman Inertia), `refreshDashboard()` (POST, paksa reload dari CLI dan invalidate cache), `dashboardTraffic()` (GET JSON, live traffic polling tiap 10 detik), `storeDashboardVlan()` (POST JSON, eksekusi CLI tambah VLAN + invalidate cache).
- `routes/web.php` — daftarkan 4 route baru: `smartolt.dashboard`, `smartolt.dashboard.refresh`, `smartolt.dashboard.traffic`, `smartolt.dashboard.vlan`.
- `resources/js/Pages/SmartOlt/Detail.vue` — tambah tombol **Dashboard** di action bar header yang link ke halaman dashboard baru.

Notes:

- Diverifikasi langsung di OLT-1 (C320-PATI) dan OLT-2 (C300-SEKARJALAK). Format CLI berbeda dari dokumen PRD: interval traffic `20 seconds` (bukan 300), satuan `Bps` bytes/sec (bukan bits/sec), VLAN list pakai range notation (`20-120`) dan bisa multi-baris.
- Interface naming berbeda per chassis: C300 HUVQ → `xgei_1/{slot}/1-2`; C320 SMXA → `gei_1/{slot}/1-N`. SCXN juga punya `gei_` tapi bukan uplink traffic, sengaja di-skip dari discovery.
- Parser VLAN (`parseTaggedVlans`) handle `\r\n` dan akumulasi multi-baris sampai bertemu baris non-VLAN (prompt CLI).
- Grafik trafik: Y-axis dan tooltip otomatis format B/s → KB/s → MB/s → GB/s. Polling berhenti saat komponen di-unmount (`onBeforeUnmount`).

### Perbaikan Navigasi dan ONU ID dari Cache

Changed:

- `resources/js/Pages/SmartOlt/PortOnus.vue` — tombol kembali diubah dari `smartolt.detail` ke `smartolt.gpon-ports` dengan label "GPON Port & ONU".
- `resources/js/Pages/SmartOlt/Registrations.vue` — tombol kembali diubah dari `smartolt.detail` ke `smartolt.unconfigured-all?olt_id={id}` agar kembali ke halaman Unconfigured dengan OLT tetap terpilih.
- `app/Http/Controllers/SmartOltController.php` — `refreshUnconfigured()` redirect ke `smartolt.unconfigured-all` (bukan `smartolt.unconfigured` lama) agar flash message muncul di halaman yang benar. Hapus CLI call dari `suggestNextOnuId()` — sekarang hanya pakai data cache `last_test_result.port_onus.{slot}_{port}.onus`; hapus helper `canUseCliForOnuState()` dan `extractUsedOnuIdsFromStateOutput()` yang tidak lagi dipakai; hapus injeksi `ZteCliProvisioningExecutor` dari `registerOnuForm()`.
- `README.md` — tambah seksi instalasi Go (step 2) dan cara build binary `bin/kv-snmp-poller`; tambah Go ke tabel stack teknologi dan daftar persyaratan; renumber step instalasi 1–10.

Notes:

- Suggest ONU ID via cache sudah cukup karena data port ONU di-refresh setiap kali SNMP Refresh dijalankan. Telnet call saat buka form registrasi memperlambat halaman tanpa manfaat signifikan.
- Binary Go poller sudah ada di `cmd/kv-snmp-poller/main.go` dan `go.mod`; diaktifkan via `SNMP_POLLER_DRIVER=go` di `.env`.

### Port Manager — Chart Area Gradient dan Refactor Axios

Changed:

- `resources/js/Pages/SmartOlt/PortManager.vue` — chart trafik uplink diubah dari `line` ke `area` dengan gradient fill (opasitas 35% → 5%); urutan warna dibalik (hijau = RX/In, biru = TX/Out); formatter Y-axis ditingkatkan: tampilkan suffix `G` untuk nilai ≥ 1000 Mbps; nama seri disederhanakan menjadi `In (Mbps)` / `Out (Mbps)`; `dataLabels` dimatikan; border X-axis disembunyikan. `submitVlan` direfactor dari raw `fetch` API ke `axios.post` agar konsisten dengan seluruh codebase; error message kini ambil dari `e.response?.data?.message` sehingga pesan error dari server tertampil dengan benar; hapus dead code (manual VLAN fetch + komentar usang).
- `resources/js/Pages/SmartOlt/RegisterOnu.vue` — tombol Batal diubah route-nya dari `smartolt.unconfigured` ke `smartolt.unconfigured-all` dengan parameter `{ olt_id: olt.id }` agar navigasi kembali ke halaman Unconfigured global yang benar.

Notes:

- Chart `area` lebih informatif secara visual dibanding `line` karena area terisi menunjukkan volume trafik secara intuitif.
- `fetch` diganti `axios` karena axios sudah di-import global via Inertia dan menangani CSRF token secara otomatis; error response juga lebih mudah di-parse melalui `e.response?.data`.

### SmartOLT — Hardware dan Detail Interface Persisten

Created:

- `smartolt_card_statuses` dan `smartolt_interface_statuses` migrations — tabel cache persisten untuk `show card`, port-status, VLAN tagged, dan data optical-module-info bila nanti direfresh per interface.
- `2026_05_26_102000_add_gpon_metrics_to_smartolt_interface_statuses_table.php` — tambah kolom GPON metrics: ONU capacity/registered, rate Bps/pps, throughput %, peak rate, dan counters JSON.
- `App\Models\SmartOltCardStatus` dan `App\Models\SmartOltInterfaceStatus` — model Eloquent untuk data hardware dan detail interface.
- `tests/Feature/SmartOltHardwareInterfaceTest.php` — coverage agar halaman detail membaca hardware dari DB tanpa CLI, refresh hardware menyimpan `show card`, dan refresh Port Manager menyimpan detail interface.

Changed:

- `app/Services/ZteCardUplinkService.php` — source-of-truth card/VLAN/interface dipindah dari Laravel cache ke database; refresh CLI default sekarang parse `show card`, `show interface port-status`, dan `show vlan port` lalu persist ke tabel baru.
- `app/Http/Controllers/SmartOltController.php` — halaman Detail OLT dan Port Manager tidak lagi fallback ke CLI saat GET; tambah `refreshHardware()` dan perluas `refreshDashboard()` untuk update hardware + detail interface.
- `resources/js/Pages/SmartOlt/Detail.vue` — panel Status Card / Hardware selalu tampil dari DB dan punya tombol **Refresh Hardware**.
- `resources/js/Pages/SmartOlt/PortManager.vue` — tambah tabel **Detail Interface** dari DB; tombol **Refresh Data** memuat ulang isi tabel; live traffic tidak auto-start saat halaman dibuka.
- `resources/js/Pages/SmartOlt/PortManager.vue` — tabel interface dipisah menjadi **Port Uplink** dan **GPON Port**. GPON port punya tombol refresh per row.
- `routes/web.php` — tambah route `smartolt.hardware.refresh`.
- `routes/web.php` — tambah route `smartolt.dashboard.interface.refresh` untuk refresh satu GPON port.

Notes:

- Output C300 live menunjukkan `show interface port-status` wajib memakai interface leaf seperti `xgei_1/20/1`; command sampai slot saja (`xgei_1/20`) invalid.
- Parser optical mendukung format dua kolom ZTE seperti `Vendor-Name`/`Vendor-Pn`, `RxPower`/`TxPower`, dan `Temperature`/`Supply-Vol`.
- Refresh optical massal sengaja tidak dimasukkan ke tombol **Refresh Data** karena C300 dengan banyak PON/uplink bisa melewati timeout HTTP; optical sebaiknya dibuat per-interface atau background job.
- Refresh GPON per row menjalankan dua command: `show interface gpon-olt_1/{slot}/{port}` dan `show interface optical-module-info gpon-olt_1/{slot}/{port}`.
- Verifikasi: `php artisan test --filter=SmartOltHardwareInterfaceTest`, partial `SmartOltInventoryTest` render detail/index, `npm run build -- --mode=development`; real OLT id=2 refresh read-only turun dari 33.28s menjadi 17.66s; refresh per-port `gpon-olt_1/2/1` berhasil membaca status `activate/up`, ONU `25/128`, traffic, vendor optic, Tx power, dan temperature dalam 10.39s.

### Alarm — Multi-Filter, Customer Name, dan Footer Global

Changed:

- `app/Http/Controllers/AlarmController.php` — tambah filter multi-dimensi: severity, scope, type, OLT (`olt_id`), dan full-text search (`q`); setiap alarm kini menyertakan field `customer_name` yang di-lookup dari `smartolt_onu_registrations` dan fallback ke data `last_test_result` snapshot.
- `app/Services/AlarmEvaluator.php` — method baru `onuMeta()` mengekstrak `customer_name`, `onu_name`, `onu_description` dari data ONU dan menyertakannya ke field `meta` alarm (dipakai di `onuScopeFields()` dan `onuRxAlarm()`).
- `app/Support/SmartOltSupport.php` — tambah static helper `customerNameFromOnu()` dan `cleanCustomerName()`; handle format `$$...$$` ZTE, strip nilai junk (`-`, `n/a`, `gpon-onu_*`), dan skip jika nama sama dengan serial.
- `resources/js/Pages/SmartOlt/Alarms.vue` — panel filter baru (Cari, Severity, OLT, Scope, Tipe); severity summary card kini clickable untuk filter langsung; tombol status tambah opsi "Selesai" (`cleared`); reset filter satu klik.
- `resources/js/Layouts/AuthenticatedLayout.vue` — tambah global footer fixed-bottom dengan teks copyright; body diberi `pb-10` agar konten tidak tertutup footer.
- `app/Jobs/PollOltJob.php` — tambah `tries=1`, `timeout=600`, `failOnTimeout=true`; `WithoutOverlapping` middleware kini memakai `expireAfter(timeout+300)` agar lock tidak tersangkut selamanya bila job timeout.
- `config/queue.php` dan `.env.example` — tambah `REDIS_QUEUE_RETRY_AFTER=900` agar Redis queue tidak retry job panjang sebelum timeout.
- `app/Services/Snmp/OltSnmpClient.php` — normalisasi prefix port `gpon_` → `gpon-olt_` pada SNMP label agar nama port dari SNMP selalu cocok dengan nama CLI (C300 melaporkan `gpon_1/2/1` via SNMP tapi CLI-nya pakai `gpon-olt_1/2/1`).
- `app/Http/Controllers/SmartOltController.php` — `refresh()` kini merge snapshot baru ke `last_test_result` yang ada (bukan overwrite), sehingga data `port_onus` dan `unconfigured_onus` yang di-cache tidak terhapus saat SNMP refresh biasa.
- `app/Models/SnmpOlt.php` — tambah relasi `cardStatuses()` dan `interfaceStatuses()` ke model baru.
- `routes/web.php` — rename route grup dari `smartolt.dashboard.*` ke `smartolt.port-manager.*`; URL `/smartolt/{olt}/dashboard` → `/smartolt/{olt}/port-manager`.
- `tests/Feature/AlarmEngineTest.php` — tambah test filter multi-param, test customer_name dari snapshot, dan assert meta `customer_name` pada alarm RX attenuation.
- `tests/Feature/OltPollingTest.php` — tambah test bahwa `PollOltJob` skip jika OLT belum waktunya di-poll.

Notes:

- Customer name di-lookup dua lapis: pertama dari `smartolt_onu_registrations` (data provisioning), fallback ke snapshot `last_test_result.port_onus.*.onus` (data SNMP live). Ini memastikan nama pelanggan tampil meski ONU belum pernah diregistrasi lewat sistem.
- Route rename dari `dashboard` ke `port-manager` lebih deskriptif dan menghindari konflik bila ke depan ada halaman dashboard terpisah.
- `expireAfter(900)` pada `WithoutOverlapping` penting: tanpa ini, lock Redis tidak pernah expire bila job mati mendadak (OOM, kill), dan OLT berikutnya tidak akan di-poll.

### ZTE C600 Support — SNMP + CLI + Provisioning

Created:

- `docs/ZTE ZXA10 C600 SNMP ifIndex Structure and Calculation.pdf` — dokumentasi formula ifIndex C600 (4-tier: rack/shelf/slot/port).
- `docs/ZTE ZXA10 C600 SNMP OID Management Guide.pdf` — OID tabel ONU config dan status C600 (.1082 subtree).
- `docs/ZTE ZXA10 C600 SNMP OIDs for ONU Optical Power.pdf` — OID RX power C600 dan formula konversi.
- `docs/SNMP Discovery Guide for ZTE C600 Unconfigured ONUs.pdf` — OID discovery ONU belum terkonfigurasi.
- `docs/ZXA10 C600 vs C300 CLI Command Migration Guide.pdf` — perbandingan CLI C300/C320 vs C600.
- `docs/ZTE ZXA10 C600 Line Card Identification and CLI Codes.pdf` — kode card type C600 (GFGH, GFXH, XGEI, dll).
- `docs/ZTE Titan C600 ONU Admin Status SNMP Configuration.pdf` — OID admin state ONU C600.

Changed:

- `app/Support/SmartOltSupport.php` — tambah `'c600'` ke keyword deteksi; tambah helper statis `isC600()`, `onuInterfaceId()`, `gponOltInterface()`; update `capabilities()` terima parameter opsional `$olt` untuk metadata C600 (vendor_family, port_name_prefix, is_c600, supports_separate_description).
- `app/Services/Snmp/OltSnmpClient.php` — tambah konstanta OID C600 (.1082 subtree): `C600_ONU_TYPE/NAME/SN/ADMIN_STATE/PHASE_STATE/LAST_DOWN_CAUSE/RX_POWER` dan `C600_UNCFG_OIDS`; tambah `onuOids()` helper per model; refactor `registeredOnus()`, `onuRxPowers()`, `unconfiguredOnus()` jadi model-aware; update `zteEncodeIfIndex()` dan `decodeIfIndex()` terima `$olt` untuk encoding 4-tier C600; update `parseSlotPort()` kenali interface 4-tier; update `resolvePortLabel()` kenali pola 3 dan 4 angka; update `decodePhaseState()` untuk phase code C600 (mulai dari 1, bukan 0).
- `app/Services/ZteRemoteOnuService.php` — tambah konstanta OID C600 untuk admin state dan name; `reboot()` kini pakai `SmartOltSupport::onuInterfaceId()` sehingga generate interface 4-tier untuk C600; `setActiveState()` dan `setInfo()` pilih OID berdasarkan model; description SNMP SET di-skip untuk C600 (tidak ada OID terpisah).
- `app/Services/ZteProvisioningScriptBuilder.php` — baca `is_c600` dari `$data`; gunakan `SmartOltSupport::gponOltInterface()` dan `onuInterfaceId()` untuk generate interface 3-tier/4-tier; perintah `description` dihilangkan untuk C600.
- `app/Services/ZteOnuRxPowerService.php` — `portRxPower()` pakai `SmartOltSupport::gponOltInterface()` untuk CLI command 4-tier; `parse()` gunakan regex berbeda untuk C600 (4 angka di nama interface).
- `app/Services/ZteCardUplinkService.php` — tambah konstanta card type C600: `C600_XGEI_CARDS` (`XGEI`, `SFUL`, `SFUM`), `C600_GEI_CARDS` (`GEI`), `C600_GPON_CARDS` (`GFGH`, `GFXH`, `GFXL`); `discoverUplinkInterfaces()` generate interface 4-tier (`xgei-1/1/slot/port`) untuk card C600.
- `app/Http/Controllers/SmartOltController.php` — inject `is_c600` ke data provisioning sebelum dikirim ke builder; `pon_port` audit record pakai `SmartOltSupport::onuInterfaceId()`; reboot flash message pakai interface dinamis; `capabilities()` dipanggil dengan `$olt` agar metadata C600 tersedia di frontend.

Notes:

- Deteksi C600 berdasarkan substring `'c600'` di `$olt->name`, `$olt->vendor`, atau `sysDescr` dari `last_test_result`. Cukup set nama OLT mengandung "C600" saat input data.
- ifIndex C600 berbeda total: C600 pakai 4-tier `(1<<28)|(1<<24)|(1<<16)|(slot<<8)|port` vs C300/C320 `0x10000000|(slot<<16)|(port<<8)`. Tanpa fix ini semua SNMP lookup ONU akan salah slot/port.
- Semua OID C600 ada di subtree `.1082.500` (zxAccessNode/zxAnPon), berbeda dari C300/C320 yang pakai `.1012` (ZTE-GPON-MIB legacy).
- Phase state C600 mulai dari 1 (bukan 0): `4=Working` (bukan `3`). `online` check diupdate sesuai.
- C600 tidak punya OID deskripsi ONU terpisah; field description bernilai `null` dan perintah `description` tidak dimasukkan ke provisioning script.
- Belum diverifikasi di real C600 device — perlu test langsung untuk konfirmasi ifIndex encode dan OID walks.

### Konsistensi Design System — Dark & Light Glassmorphism

Changed:

- `resources/js/Pages/SmartOlt/Profiles.vue` — terapkan DARK glassmorphism: content wrapper `from-slate-900 via-slate-800 to-indigo-950`, setiap section jadi dark glass card (`bg-white/[0.06] border-white/10 backdrop-blur-xl`), flash messages dark glass style, table header `text-slate-400 bg-white/[0.03]`, badge aktif/nonaktif pakai `ring-1` dark variant, checkbox label `text-slate-300`.
- `resources/js/Pages/SmartOlt/Create.vue` — content wrapper ganti ke LIGHT gradient `from-slate-50 via-blue-50/80 to-indigo-100/60`; white card wrapper dihapus karena form sudah dihandle OltForm.vue.
- `resources/js/Pages/SmartOlt/Edit.vue` — sama seperti Create.vue, content wrapper light gradient.
- `resources/js/Pages/SmartOlt/Partials/OltForm.vue` — refactor dari satu flat form menjadi 4 LIGHT glass section terpisah (Identitas OLT, Konfigurasi SNMP, Konfigurasi CLI, Auto-Poll); setiap section punya header icon (`Cpu`, `Network`, `KeyRound`, `Activity` dari Lucide); submit bar jadi light glass floating bar di bawah; import ikon ditambahkan.
- `resources/js/Pages/SmartOlt/PortManager.vue` — terapkan DARK glassmorphism menyeluruh: content wrapper dark gradient, flash messages dark glass, 3 section card (Trafik Uplink, Port Uplink, GPON Port) pakai dark glass card dengan header icon; table header/rows dark styling; `vlanBadgeColor()` dan `linkBadgeColor()` diganti ke dark variant (`*/15 text-*/300 ring-1 ring-*/25`); status indicator pill (UP/DOWN/loading) dark style; VLAN inline panel dark; tombol VLAN aksi dark; refresh button per GPON row dark.

Notes:

- `<script setup>` tidak diubah di Profiles.vue dan PortManager.vue — hanya template dan dua helper function badge di PortManager yang diupdate return value class-nya.
- OltForm.vue kini import `{ Activity, Cpu, KeyRound, Network }` dari `@lucide/vue` untuk ikon section header.
- Form komponen (`TextInput`, `InputLabel`, `InputError`) tetap light-styled karena didesain untuk background putih — LIGHT glassmorphism menjaga kontras agar tetap terbaca.

### Sidebar Navigation + Konsistensi Semua Halaman

Changed:

- `resources/js/Layouts/AuthenticatedLayout.vue` — total rewrite: navbar atas → sidebar kiri tetap `w-64` dark (`bg-slate-900`); mobile overlay + hamburger dengan Transition fade; logo + nav link dengan active state `bg-white/10`; user section di bawah sidebar (avatar initials + link Profil & Keluar); main content `lg:pl-64 flex flex-col min-h-screen`; footer in-flow (bukan `fixed bottom-0`) untuk hindari content overlap.
- `resources/js/Pages/Dashboard.vue` — dark glassmorphism: stat cards, chart containers, OLT table, alarm list; ApexCharts options `background: 'transparent'`, label/axis color `#94a3b8`, grid `rgba(255,255,255,0.06)`; `severityClass` dark variant.
- `resources/js/Pages/SmartOlt/Alarms.vue` — dark glassmorphism: severity cards clickable dengan `ring-2 ring-indigo-500/50` saat aktif; filter panel input `bg-white/[0.08]` dan select `bg-slate-800`; status toggle (Aktif/Selesai/Semua) masuk ke wrapper dark glass; `severityClass` dan `statusClass` dark variant.
- `resources/js/Pages/Users/Index.vue` — dark glassmorphism: table, avatar `bg-indigo-500/20 text-indigo-300 ring-1 ring-indigo-500/30`, flash messages dark glass.
- `resources/js/Pages/SmartOlt/Index.vue` — dark glassmorphism: OLT cards, status dot glowing `shadow-[0_0_8px_...]`, ZTE badge `bg-sky-500/15`, action buttons dark.
- `resources/js/Pages/SmartOlt/GponPorts.vue` — dark glassmorphism: 3 stat cards, port cards `border-emerald-500/20 bg-white/[0.06]` untuk port up; search input dark; badge "Selesai" `bg-emerald-500/20 ring-emerald-500/30`.
- `resources/js/Pages/SmartOlt/Detail.vue` — dark glassmorphism: 4 stat cards, System Info card dengan icon badge sky, hardware table dark; `cardStatusColor()` return dark badge class.
- `resources/js/Pages/SmartOlt/Registrations.vue` — dark glassmorphism: registration items `divide-white/[0.06]`; `<pre>` script dan execution output `rounded-xl bg-slate-950 border border-white/[0.06]`; `statusClass()` dark variant.
- `resources/js/Pages/SmartOlt/Unconfigured.vue` — dark glassmorphism: stat cards, tabel ONU unconfigured, flash messages.
- `resources/js/Pages/SmartOlt/UnconfiguredGlobal.vue` — dark glassmorphism: OLT selector cards dengan selected state indigo, summary cards, tabel ONU.
- `resources/js/Pages/SmartOlt/PortOnus.vue` — dark glassmorphism: 4 stat cards, ONU table, `rxBadgeClass()` dengan threshold warna (-28/-8 merah, -25/-10 amber, hijau untuk normal), phase dot glowing.
- `resources/js/Pages/SmartOlt/RegisterOnu.vue` — LIGHT glassmorphism: 4 section kartu (Identitas/GPON/WAN/Fitur) masing-masing dengan icon header; WAN Mode jadi visual button selector (PPPOE/DHCP/STATIC); submit bar light glass floating.

Notes:

- Design token konsisten di semua halaman — DARK: `bg-white/[0.06] border-white/10 backdrop-blur-xl` di atas `bg-gradient-to-br from-slate-900 via-slate-800 to-indigo-950`; LIGHT: `bg-white/70 border-white/70 backdrop-blur-xl` di atas `from-slate-50 via-blue-50/80 to-indigo-100/60`.
- Halaman form (RegisterOnu, Create, Edit, OltForm) pakai LIGHT karena komponen `TextInput`/`InputLabel` hard-coded untuk background terang.
- Sidebar mobile menggunakan Transition Vue bawaan untuk overlay fade — tidak butuh library animasi tambahan.
- Build Vite `npm run build` berhasil 13.84s tanpa error setelah semua perubahan digabung.

### Fix Layout: Header Putih, Footer Sticky, Background Gap

Changed:

- `resources/js/Layouts/AuthenticatedLayout.vue` — tiga perbaikan: (1) outer wrapper `bg-gray-100` → `bg-slate-950` menghilangkan celah putih di bawah konten halaman pendek; (2) header `bg-white shadow-sm` → `bg-slate-900/95 backdrop-blur-sm border-white/10` dengan `[&_h2]:!text-white [&_p]:!text-slate-400` agar judul/subtitle di semua slot header otomatis jadi warna gelap tanpa ubah tiap halaman; (3) footer `bg-white` → `bg-slate-900/95 backdrop-blur-sm` + `sticky bottom-0 z-10` agar footer selalu terlihat di bawah viewport.
- `resources/js/Pages/Profile/Edit.vue` — content wrapper dari `py-8` polos ke LIGHT glassmorphism `from-slate-50 via-blue-50/80`; card `bg-white p-6 shadow-sm` → `bg-white/70 backdrop-blur-xl border-white/70 rounded-2xl shadow-xl`.

Notes:

- `[&_h2]:!text-white` di header layout menggunakan arbitrary variant Tailwind + `!important` modifier untuk override `text-gray-800` di slot konten tiap halaman — satu tempat, berlaku global.
- `sticky bottom-0` pada footer bekerja karena flex container punya `min-h-screen`: saat konten pendek, footer natural di bawah (via `flex-1` pada main); saat konten panjang, footer sticky ke bawah viewport saat scroll.
- Outer `bg-slate-950` adalah fallback: semua area transparent di dalam stack meneruskan ke bg ini, menghilangkan `bg-gray-100` yang bocor ke area kosong.

### Phase 19 - CLI Output Sanitization dan Glasmorphism Design Refinement

Created:

- `app/Support/CliOutputSanitizer.php` — utility class untuk membersihkan CLI output dari telnet control sequences, normalize UTF-8, dan hapus invalid control characters. Method statis `clean()` memproses output melalui tiga tahap: (1) strip Telnet protocol sequences (0xFF commands), (2) normalize UTF-8 dengan fallback iconv, (3) hapus ANSI escape sequences dan unprintable chars. Method privat `normalizeUtf8()` detect dan repair UTF-8 breaks; `stripTelnetControlSequences()` iterate byte-by-byte skip 0xFF protocol blocks.
- `tests/Unit/CliOutputSanitizerTest.php` — coverage lengkap: test clean output pass-through, strip telnet 0xFF IAC+ECHO, strip ANSI color `\x1B[...m`, normalize UTF-8 invalid bytes, remove null bytes dan control chars, preserve newlines/tabs.
- `tests/Feature/SmartOltRegistrationExecutionTest.php` — feature test provisioning execution: tester fakes `ZteCliProvisioningExecutor` dan `OltSnmpClient`, assert execution output di-sanitize sebelum disimpan, check flash message dan status `executed`/`failed`, test double-execute guard (status=`executed` render error flash bukan rerun).

Changed:

- `app/Http/Controllers/SmartOltController.php` — import `CliOutputSanitizer`; method `executeRegistration()` sanitize `$result['output']` dan `$result['error']` sebelum save ke DB; guard double-execute jika registration sudah status `executed`; error message di flash juga sanitize `$error`.
- `app/Services/ZteCliProvisioningExecutor.php` — import `CliOutputSanitizer`; method privat `run()` sanitize output sebelum `detectError()` parse (mencegah false positive dari ANSI sequences).
- `resources/css/app.css` — expand custom @layer components: tambah `.kv-panel`, `.kv-card`, `.kv-section`, `.kv-table`, `.kv-badge`, `.kv-input`, `.kv-button`, `.kv-text-*` untuk design consistency. Tambah `.kv-glass-dark` dan `.kv-glass-light` untuk glasmorphism base styles. Kelas layout `.kv-page`, `.kv-page-compact`, `.kv-container`, `.kv-container-narrow` sudah ada dari phase sebelumnya.
- Vue components (Checkbox, Modal, ConfirmModal, DangerButton, DropdownLink, IconButton, InputLabel, NavLink, Pagination, PrimaryButton, ResponsiveNavLink, SecondaryButton, TextInput) — update untuk align dengan design system: class binding sesuaikan ke dark/light context, shadow consistency, border radius 2xl, text color match glasmorphism palette.
- `resources/js/Layouts/AuthenticatedLayout.vue` — sidebar mobile transition dibuat smooth dengan fade + scale; header positioning refinement `sticky top-0 z-20` untuk tetap terlihat saat scroll; main content jadi `flex-1` agar panjang viewport minimum tercapai.
- `resources/js/Layouts/GuestLayout.vue` — background gradient update ke slate-950 base; card container `.kv-card` dengan light glass style.
- `resources/js/Pages/Auth/*` (Login, Register, ConfirmPassword, ForgotPassword, VerifyEmail) — terapkan LIGHT glassmorphism: background gradient `from-slate-50 via-blue-50/80 to-indigo-100/60`, form card glass, button consistency.
- `resources/js/Pages/Dashboard.vue` — rebuild dengan dark glassmorphism: content wrapper gradient dark, stat card 4x grid dark glass, chart container dark glass, alarms panel dark glass.
- `resources/js/Pages/Profile/Edit.vue` — terapkan design system: form section grid dark glass untuk security info, delete account button danger variant.
- `resources/js/Pages/SmartOlt/Detail.vue` — port card grid layout sesuaikan ukuran responsif, search bar dark glass style, card typography refinement.
- `resources/js/Pages/SmartOlt/GponPorts.vue` — table port list dark glass header/rows, status indicator pill styling.
- `resources/js/Pages/SmartOlt/Index.vue` — table header dark glass background, action column center align, pagination component integrate.
- `resources/js/Pages/SmartOlt/Unconfigured.vue` — ONU list table dark glass, unconfigured badge color scheme, register button primary.
- `resources/js/Pages/SmartOlt/UnconfiguredGlobal.vue` — global unconfigured view dark theme, OLT filter dropdown dark style.
- `resources/js/Pages/SmartOlt/RegisterOnu.vue` — form wrapper light gradient background, input section grid layout, profile dropdown konsisten styling.
- `resources/js/Pages/SmartOlt/Registrations.vue` — registration table dark glass, status badge (pending/executed/failed) color scheme, execute action button danger variant untuk confirm.
- `resources/js/Pages/SmartOlt/Alarms.vue` — alarm filter panel dark glass, severity chip clickable dengan hover effect, table dark styling, pagination component.
- `resources/js/Pages/Users/Index.vue` — user table dark glass, user avatar circle size, modal form light glass background.
- `resources/js/Pages/Welcome.vue` — landing page refresh dark theme, feature grid glasmorphism card, CTA button primary variant.
- `.gitignore` — tambah `skills-lock.json` ke ignore list (generated file dari tool).

Notes:

- CliOutputSanitizer penting untuk provisioning audit: Telnet stream mengandung 0xFF protocol bytes dan ANSI escape sequences warna; output mentah tidak bisa disimpan langsung ke DB tanpa corruption atau field overflow.
- Glasmorphism design refinement mencakup konsistensi color palette (slate-50 light / slate-900-950 dark), backdrop blur (xl untuk main card, sm untuk subtle backgrounds), border colors (white/10 dark / white/70 light), shadow consistency (shadow-lg + ring-1).
- Build `npm run build` selesai 14.29s tanpa error; codebase siap production.
- Verifikasi: test `php artisan test` mencakup unit test CliOutputSanitizer (10 test case) dan feature test execution (3 scenarios); real OLT provisioning execution capture output, sanitize, store, dan display di UI tanpa corruption.

## 2026-05-28

### Phase 20 - Detail ONU & Configure ONU (CLI)

Created:

- `app/Services/ZteOnuRunningConfigService.php` — baca live running-config (`show running-config interface …` + `show onu running config …`) lalu parse ke struktur form Configure (guide Section 7). `normalizeLines()` repair line-wrap khas ZTE (`vlan-profi le` → `vlan-profile`, dst.) + gabung continuation token; `parse()` kenali pattern name/description, tcont, gemport, service-port, service (pon-onu-mng, `type` opsional), vlan port (UNI), wan binding, wan-ip (pppoe/dhcp/static + vlan-profile), tr069-mgmt, security-mgmt; konversi mask dotted→length.
- `app/Services/ZteOnuReconfigureScriptBuilder.php` — `build(baseline, target, context)` hasilkan **delta script** (guide Section 5.4): hanya emit baris CLI yang berubah, dibungkus blok `interface`/`pon-onu-mng`, plus daftar `changes` (label, from, to) untuk panel "What Will Change". Tanpa perubahan → script kosong. Diff per-section by id/name, `no …` untuk row yang dihapus, re-emit penuh `wan-ip 1 …` bila mode/credential/profile berubah, toggle tr069 (unlock/lock) & security-mgmt (enable/disable).
- `app/Services/ZteOnuDetailService.php` — baca `show gpon onu detail-info` + `show pon power attenuation` (guide Section 6). `parse()` dual-pass: build all-map (normalize key snake_case, skip echo/prompt/attenuation/session rows) → bucket ke grup identity/state/optical/last_event via `pick()` (exact dulu, lalu substring). `applyAttenuation()` isi onu_rx/tx + att up/down (dan optical Rx/Tx bila kosong); `applySessionHistory()` isi last_event dari tabel session (row OfflineTime `0000-` = sesi current).
- `resources/js/Pages/SmartOlt/ConfigureOnu.vue` — halaman Configure sesuai desain: panel kiri CURRENT CONFIG + Raw terminal; kanan form semua section multi-row (T-CONT, GEM Port, Service-port, PON-ONU-MNG/Service, UNI VLAN, WAN binding) dengan header kolom + scroll horizontal, WAN mode selector, TR069 & Remote-ONT toggle; bawah GENERATED SCRIPT (delta-live, debounce 400ms ke endpoint preview) + WHAT WILL CHANGE + Apply/Batal + banner peringatan putus koneksi.
- `resources/js/Pages/SmartOlt/OnuDetail.vue` — halaman Detail tervisualisasi: 4 hero stat card (Status, RX Power, Jarak, Online Duration), section Optical dengan gauge RX berzona warna + bar atenuasi up/down + chip metrik (temp/voltage/bias), kartu grup Identitas/Status/Last Event, accordion Semua Field & Raw output.
- `tests/Unit/ZteOnuConfigureTest.php` — 6 test: parse running-config, konversi mask, delta kosong saat tanpa perubahan, delta minimal saat name berubah, add/remove service-port, perubahan WAN/tr069/remote-ont.
- `tests/Unit/ZteOnuDetailTest.php` — 2 test: parse detail-info ke grup + suplemen atenuasi, dan pengisian last_event dari session history.

Changed:

- `app/Http/Controllers/SmartOltController.php` — import 3 service baru; method `onuDetail` (render `OnuDetail.vue`), `configureOnuForm` (render `ConfigureOnu.vue` + baseline + profileOptions), `configureOnuPreview` (JSON delta murni tanpa OLT), `configureOnuApply` (eksekusi delta via Telnet + audit row `reconfigured`/`reconfig_failed`); helper `validatedReconfigure`, `findCachedOnu`, `resolvePrimaryVlan`; `wan_mode` di-coerce ke pppoe/dhcp/static agar tak melanggar enum tabel audit.
- `routes/web.php` — 4 route baru: `smartolt.onu.detail`, `smartolt.onu.configure`, `smartolt.onu.configure.preview`, `smartolt.onu.configure.apply`.
- `resources/js/Pages/SmartOlt/PortOnus.vue` — tombol aksi Detail (ikon Info) & Configure (ikon Settings) di desktop + mobile, gated capability `supports_cli_onu_detail` / `supports_cli_onu_configure`.
- `docs/SMARTOLT_ZTE_C300_C320_GUIDE.md` — referensi parser/builder Section 5.4/6/7 diarahkan ke kelas nyata di repo ini (`ZteOnuDetailService`, `ZteOnuRunningConfigService`, `ZteOnuReconfigureScriptBuilder`) menggantikan `ZteCliSessionService` blueprint; tambah callout status implementasi + catatan nama/path route web yang sebenarnya.
- `README.md` — tambah fitur Detail ONU (CLI) & Configure ONU (CLI delta) ke daftar Fitur.

Notes:

- Capability `supports_cli_onu_detail` & `supports_cli_onu_configure` sudah tersedia (true untuk ZTE) di `SmartOltSupport`, jadi tinggal di-wire.
- Delta-live preview murni diff baseline↔target di backend (tanpa akses OLT), jadi aman dipanggil debounced tiap edit; hanya Apply yang membuka sesi Telnet.
- Guide Section 6/7 ternyata blueprint dari proyek lain (kelas `ZteCliSessionService` tidak pernah ada di repo ini); fitur dibangun ulang dengan pemecahan kelas `ZteOnu*`.
- 8 unit test baru hijau; `npm run build` & `./vendor/bin/pint` bersih. Belum diverifikasi ke OLT live (id=1 `OLT-C320-PATI`) — parsing real-firmware & delta perlu dicek langsung di OLT.

### Phase 21 - Search & Filter ONU + Deep-link dari Global Search

Changed:

- `resources/js/Pages/SmartOlt/PortOnus.vue` — tambah search lokal (cocokkan interface/serial/nama/deskripsi/type) + filter Phase (semua/online/offline) & Admin (semua/active/disabled) + tombol Reset; penghitung hasil `(X/Y)` di judul; empty-state "tidak ada ONU cocok"; daftar (tabel desktop & kartu mobile) kini iterasi `filteredOnus`. Baca prop `initial_search`/`focus_onu_id` untuk pre-fill search dan scroll + highlight ONU target; `scrollToFocus()` pilih elemen yang terlihat via `data-onu-id` + cek `offsetParent` agar tidak salah target di layout responsif.
- `app/Http/Controllers/SmartOltController.php` — `portOnus()` terima `Request`, teruskan query `q` → prop `initial_search` dan `focus` → prop `focus_onu_id`.
- `app/Http/Controllers/DashboardSearchController.php` — URL hasil ONU pada global search kini menyertakan `q=<serial/nama>` & `focus=<onu_id>` agar halaman port langsung terfilter dan menyorot ONU yang dicari.

Notes:

- Tujuan: hasil global search (⌘K) untuk ONU mendarat di halaman port dengan ONU spesifik langsung ter-scroll + ter-highlight, bukan sekadar membuka daftar port.
- Filter/search murni client-side atas snapshot ONU yang sudah ada (tanpa request tambahan ke OLT). Stat card (Total/Online) tetap menampilkan total, bukan hasil filter.
- `npm run build` & `pint` bersih; interaksi scroll/highlight belum dites di browser (perlu data ONU live).

### Phase 22 - Role User (RBAC), Halaman Report & Mode Demo

Created:

- `docs/PLANNING_NEXT_PHASE.md` — dokumen perencanaan fase ini (keputusan desain, matriks hak akses, urutan eksekusi).
- `app/Enums/UserRole.php` — enum `admin`/`operator`/`demo` + `label()`, `values()`, `options()`.
- `database/migrations/2026_05_28_145148_add_role_to_users_table.php` — kolom `role` string (default `operator`); user lama di-set `admin` agar tak terkunci. String (bukan enum native) demi kompatibilitas SQLite test.
- `app/Http/Middleware/EnsureUserRole.php` — middleware berparameter (`role:admin`, `role:admin,operator`).
- `app/Http/Middleware/BlockDemoWrites.php` — tolak semua request non-GET untuk role demo (kecuali logout).
- `app/Services/Report/ReportService.php` — builder laporan generik (columns/rows/summary) 5 jenis: inventaris ONU, status OLT, riwayat alarm, provisioning, RX power; filter range + per-OLT. Baca skema `last_test_result` (`port_onus.{slot}_{port}.onus`, `rx_power_dbm`).
- `app/Http/Controllers/ReportController.php` — `index` (Inertia), `exportCsv` (StreamedResponse + BOM UTF-8), `exportPdf` (dompdf landscape).
- `resources/views/reports/pdf.blade.php` — template PDF berbranding BMKV/KusumaVision.
- `resources/js/Pages/Reports/Index.vue` — halaman Report: filter jenis/range/OLT (auto-reload), kartu ringkasan, tabel desktop + kartu mobile, badge status berwarna, tombol export CSV/PDF.
- `database/seeders/DemoSeeder.php` — isi DB demo: user `admin@`/`demo@kusumavision.test`, 2 OLT (`OLT-DEMO-PATI` C320, `OLT-DEMO-JUWANA` C300) dengan `last_test_result` realistis (port up/down, ONU online/offline, RX bervariasi), ~200 polling event/OLT, alarm campuran severity, registrasi provisioning.
- `docs/DEMO_DEPLOYMENT.md` — panduan deploy instance/DB demo terpisah + peringatan jangan seed ke produksi.
- `tests/Feature/RoleAccessTest.php`, `tests/Feature/ReportTest.php`, `tests/Feature/DemoSeederTest.php` — 10 test (akses per-role, blokir tulis demo, guard admin terakhir, render report, export CSV/PDF, isi DemoSeeder).

Changed:

- `app/Models/User.php` — `role` di `$fillable` + cast `UserRole`; helper `isAdmin`/`isOperator`/`isDemo`/`canManageOlt`/`canManageUsers`.
- `bootstrap/app.php` — alias `role` + append `BlockDemoWrites` di grup web.
- `routes/web.php` — route users dibungkus `role:admin`; tambah 3 route `reports.*`.
- `app/Http/Middleware/HandleInertiaRequests.php` — share `auth.can` (`manage_users`, `manage_olt`, `is_demo`).
- `app/Http/Controllers/UserController.php` — validasi `role` (enum), sertakan role + `roleOptions` di index, guard admin terakhir (tak bisa dihapus/diturunkan).
- `resources/js/Pages/Users/Index.vue` — dropdown role di modal, badge role berwarna (admin=cyan, operator=emerald, demo=amber) di tabel & kartu mobile.
- `resources/js/Layouts/AuthenticatedLayout.vue` — nav `Report` (semua role) & `Users` (hanya admin via `auth.can`); banner "Mode Demo" read-only.
- `resources/js/Pages/SmartOlt/Index.vue` — tombol "Tambah OLT" digate `auth.can.manage_olt`.
- `database/factories/UserFactory.php` — default role operator + state `admin()` & `demo()`.
- `database/seeders/DatabaseSeeder.php` — user test default jadi admin.
- `composer.json` — tambah `barryvdh/laravel-dompdf` untuk export PDF.

Notes:

- Keputusan disepakati user: RBAC kolom enum sederhana (bukan paket), 3 role (admin/operator/demo), data demo via DB/deploy terpisah (bukan flag `is_demo`), report on-screen + CSV + PDF.
- Demo read-only diberlakukan 2 lapis: `BlockDemoWrites` (server, semua non-GET) + gating tombol/UI. Operator = semua operasi OLT/ONU kecuali kelola user.
- Keamanan SmartOLT write tak perlu `role:` tambahan: admin+operator boleh, demo sudah diblokir `BlockDemoWrites`.
- 91 test hijau (10 baru), `npm run build` & `pint` bersih.
- Migrasi `role` sudah dijalankan di DB produksi via `php artisan migrate --force`; 2 user existing otomatis jadi admin (terverifikasi). Demo: `php artisan db:seed --class=DemoSeeder` di instance demo terpisah.

Lanjutan — filter PON port di Report:

- `app/Services/Report/ReportService.php` — filter `pon_port` (format `{slot}_{port}`): laporan ONU & RX hanya iterasi key port yang dipilih; alarm & provisioning di-where `slot`+`port`.
- `app/Http/Controllers/ReportController.php` — baca/validasi `pon_port` (regex `\d+_\d+`, hanya berlaku bila ada `olt_id`); sediakan `ponPortOptions` dari `last_test_result.ports` OLT terpilih.
- `resources/js/Pages/Reports/Index.vue` — dropdown PON Port (disabled bila belum pilih OLT), grid filter jadi 4 kolom, auto-reset port saat OLT berganti; export CSV/PDF ikut membawa `pon_port`.
- `tests/Feature/ReportTest.php` — test filter PON port (port ada → 2 baris, port tak ada → 0 baris). Total 92 test hijau.

Revisi isolasi demo — flag `is_demo` (bukan DB terpisah):

- Alasan: user menjalankan satu instance, jadi role demo malah melihat data OLT produksi asli. Pendekatan diubah ke flag `is_demo` + global scope di DB yang sama.
- `database/migrations/2026_05_28_160000_add_is_demo_flags.php` — kolom `is_demo` (boolean, default false, indexed) di `snmp_olts`, `alarm_events`, `polling_events`, `smartolt_onu_registrations`.
- `app/Models/Scopes/DemoScope.php` — global scope: user role demo → hanya `is_demo=true`; selain itu (termasuk console/queue tanpa auth) → hanya `is_demo=false`. Diterapkan di 4 model tsb (+ `is_demo` di fillable/cast).
- `database/seeders/DemoSeeder.php` — semua data demo di-set `is_demo=true`; `SnmpOlt::withoutGlobalScopes()->updateOrCreate(...)` agar idempotent.
- `tests/Feature/DemoSeederTest.php` — tambah test isolasi: admin lihat 1 OLT nyata, user demo lihat 2 OLT demo. `tests/Feature/RoleAccessTest.php` — OLT uji blokir-tulis di-flag demo agar resolvable lalu 403.
- Dampak: scope otomatis berlaku di Dashboard, SmartOLT, Report, notifikasi alarm (semua query model tsb). Polling scheduler (console) hanya menyentuh OLT nyata; OLT demo statis.
- Dijalankan di prod: `migrate --force` (kolom is_demo) + `db:seed --class=DemoSeeder --force`. Terverifikasi: OLT nyata=2, OLT demo=2, polling demo=400, alarm demo=8. 93 test hijau, `pint` bersih.

Audit keamanan isolasi demo:

- Hasil audit: tidak ada `DB::table` langsung ke tabel sensitif, tidak ada `withoutGlobalScope` di kode app, semua baca lewat Eloquent yang ter-scope. Tabel `SmartOltCardStatus`/`SmartOltInterfaceStatus` (tanpa is_demo) hanya diakses lewat OLT yang sudah ter-scope via route-binding → user demo akses OLT nyata = 404, dan sebaliknya. `AlarmController::customerNamesFor` pakai `SmartOltOnuRegistration` (scoped). Scheduler `PollOltsCommand`/`PollOltJob` jalan tanpa auth → hanya OLT nyata.
- `tests/Feature/DemoIsolationTest.php` — kunci regresi: user nyata hanya lihat data nyata (dashboard/index/report/alarm) + 404 saat akses OLT demo; user demo hanya lihat data demo + 404 saat akses OLT nyata. 95 test hijau total.

### Landing page: hero full-desktop + animasi AOS, hapus config Nginx README, fix jsconfig

Changed:

- `resources/js/Pages/Welcome.vue` — hero jadi full-height desktop (`lg:min-h-[calc(100vh-57px)]` + `flex items-center`, konten ter-center vertikal; mobile tetap mengalir), ambient glow `animate-pulse`. Integrasi AOS (animate-on-scroll): init di `onMounted` (durasi 650ms, `ease-out-cubic`, `once`, `disable` saat `prefers-reduced-motion`) + atribut `data-aos` bertahap di hero, hardware strip, kartu fitur/modul (stagger per kolom), benefit pills & tech stack (`zoom-in` stagger), dan CTA akhir (`zoom-in-up`).
- `README.md` — hapus seluruh "Langkah 6 — Konfigurasi Nginx" (contoh server block + perintah aktivasi) karena tidak dijadikan acuan; penomoran langkah 7→6, 8→7, 9→8 disesuaikan.
- `jsconfig.json` — hapus `baseUrl` (deprecated di TS, akan dihapus TS 7.0) dan ubah `@/*` jadi relatif `["./resources/js/*"]`; warning editor hilang, alias build dari `laravel-vite-plugin` tak terpengaruh.
- `package.json` / `package-lock.json` — tambah dependency `aos`.

Notes:

- `npm run build` bersih (aos ter-bundle). Animasi belum diuji visual di browser dari sesi ini — perlu cek manual: hero penuh 1 layar desktop, reveal scroll halus, dan elemen tetap tampil saat reduce-motion aktif.
- Alias build `@` berasal dari `laravel-vite-plugin` (`"@": "/resources/js"`), `jsconfig.json` murni untuk intellisense editor.

### Background aurora + chrome glassmorphism, hapus grid statis & gambar

Created:

- `resources/js/Components/Shell/AuroraBackground.vue` — backdrop aurora: 4 gumpalan cahaya (cyan/sky/teal/blue) blur 90px, `mix-blend: screen`, mengambang via animasi `transform` (GPU), + vignette halus. `position: fixed` di belakang konten (`z-index: -1`), hormati `prefers-reduced-motion`.

Changed:

- `resources/css/app.css` — `.kv-grid-bg` disederhanakan jadi base gelap saja (hapus radial-gradient glow/efek light + grid garis statis); peran grid digantikan AuroraBackground.
- `resources/js/Layouts/AuthenticatedLayout.vue` — sisipkan `<AuroraBackground/>` di `<main>`; semua chrome jadi glass (opacity turun, tetap backdrop-blur): sidebar `/95→/35`, strip logo `/45→/20`, header desktop `/80→/35`, header slot `/70→/30`, top bar mobile `/90→/40`, footer `/80→/40`.
- `resources/js/Layouts/GuestLayout.vue` — sisipkan `<AuroraBackground/>` di container login.
- `resources/js/Pages/Welcome.vue` — sisipkan `<AuroraBackground/>` di hero; ganti gambar dashboard `dashboard.png → dashboard1.png`.
- `resources/js/Components/Dashboard/HeroBanner.vue` — hapus `<img>` hero + CSS-nya; background solid → kaca `rgba(15,23,42,0.32)` + `backdrop-blur(14px)` agar aurora tembus.
- `resources/js/Components/Shell/SidebarConstellation.vue` — hapus `<img>` starfield + CSS-nya; base solid `#020617` → transparan, tint shade diringankan agar aurora terlihat di sidebar.
- `public/img/*` — hapus gambar lama tak terpakai (c300/c320.jpg, dashboard/hero/landingpage/sidebar/template.png), tambah `dashboard1.png`.

Notes:

- Iterasi gaya: grid perspektif synthwave → grid ombak SVG `feTurbulence` (ditolak: berat & noisy) → final **aurora** (CSS transform, ringan & mulus), dipilih dari riset background dashboard dark-theme 2026.
- Stacking: AuroraBackground `fixed z-index:-1` di dalam `<main>` (isolation) tampil di belakang konten; chrome glass + backdrop-blur memburamkan aurora di belakangnya.
- `npm run build` bersih. Belum diuji visual di browser dari sesi ini — perlu cek manual: aurora bergerak halus, teks chrome tetap terbaca, dan performa lancar (tanpa lag).

## 2026-05-29

### Sidebar collapse persist + card Logs di Registration History + tweak input

Changed:

- `resources/js/Layouts/AuthenticatedLayout.vue` — state `sidebarCollapsed` kini dipersist ke `localStorage` (key `kv-sidebar-collapsed`): init dibaca sinkron saat setup (di-guard `typeof window` agar aman SSR & tanpa flash), lalu `watch` menyimpan tiap perubahan. Sebelumnya layout dipakai inline (bukan persistent layout Inertia) sehingga remount tiap pindah halaman mereset collapse ke `false`.
- `resources/js/Pages/SmartOlt/Registrations.vue` — pisah daftar registrasi jadi `pendingRegistrations` (status `generated`) & `loggedRegistrations` (status `executed`/`failed`). Card "Provisioning Scripts" sekarang `v-if` hanya muncul saat ada script pending dan hilang otomatis setelah dikerjakan; tambah card "Logs" baru (ikon `History`) untuk script yang sudah dikerjakan dengan status apa pun. Di Logs, preview CLI script + output eksekusi disembunyikan default di balik tombol toggle "Lihat script / Sembunyikan" (state per-entri via `expandedLogs`).
- `resources/js/Pages/SmartOlt/RegisterOnu.vue` — input Remote ONT ID `max` dinaikkan dari 16 → 4095 agar konsisten dengan form Configure.
- `app/Http/Controllers/SmartOltController.php` — validasi `remote_ont_id` saat register dinaikkan dari `between:1,16` → `between:1,4095` (sebelumnya tidak konsisten dengan reconfigure yang sudah `1,4095`).
- `resources/js/Pages/SmartOlt/ConfigureOnu.vue` — panel RAW RUNNING-CONFIG tampil penuh ke bawah: hapus `max-h-[420px] overflow-auto`, ganti dengan `whitespace-pre-wrap break-words` + `overflow-x-auto` agar tidak ada scroll vertikal.

Notes:

- `npm run build` bersih untuk semua perubahan frontend.
- Investigasi "halaman detail OLT C300 tidak bisa dibuka": ternyata bukan bug kode, melainkan sesi user kebawa state demo. `SnmpOlt` punya global scope `DemoScope` (user demo hanya lihat `is_demo=true`, non-demo hanya `is_demo=false`); OLT C300 (id=2) adalah data nyata sehingga route-model-binding gagal saat sesi demo. Teratasi setelah user login ulang — tidak ada perubahan kode.

### Halaman Pengaturan + Notifikasi Telegram untuk alarm

Created:

- `database/migrations/2026_05_29_120000_create_telegram_settings_table.php` — tabel `telegram_settings` (single-row): `enabled`, `bot_token` (text, dienkripsi via cast), `chat_id` (text, bisa banyak ID dipisah koma/spasi), `min_severity` (default `warning`), `notify_on_raise`/`notify_on_clear`, `last_sent_at`, `last_error`. Tipe kolom SQLite-compatible untuk test.
- `app/Models/TelegramSetting.php` — model singleton: `instance()` (firstOrNew), `chatIds()` (parse multi-ID), `isConfigured()`/`isReady()`, `minSeverityRank()` + const `SEVERITY_RANK`. `bot_token` cast `encrypted` & `$hidden`.
- `app/Services/Telegram/TelegramNotifier.php` — kirim pesan ke Telegram Bot API (`/sendMessage`, parse_mode HTML, timeout 10s, loop semua chat ID). `notify(olt, raised, cleared)` dipanggil dari evaluator (filter severity ≥ min, gate `notify_on_raise`/`notify_on_clear`, skip OLT demo, dibungkus try/catch agar tak memecah rekonsiliasi alarm, rekam `last_sent_at`/`last_error`); `sendTest()` untuk tombol uji. Pesan disusun ringkas berisi nama OLT, daftar alarm (emoji severity + tipe + pesan + nama pelanggan bila ada) + timestamp.
- `app/Http/Controllers/SettingsController.php` — `edit` (Inertia, token dikirim sebagai `bot_token_set` boolean, bukan nilai asli), `updateTelegram` (validasi; token kosong = pertahankan token lama, pola `withoutEmptySecrets`), `testTelegram` (kirim tes via notifier → flash success/error).
- `resources/js/Pages/Settings/Index.vue` — kartu glass "Notifikasi Telegram": toggle aktif, input bot token (password, placeholder menandai token tersimpan), chat ID (textarea multi), select severity minimum, checkbox kirim-saat-muncul / kirim-saat-pulih, tombol Simpan + Kirim Tes (disabled sampai token & chat ID tersimpan), panel status (terakhir terkirim / galat terakhir), teks bantuan @BotFather & @userinfobot.
- `tests/Feature/TelegramSettingsTest.php` — 7 test: akses admin vs operator (403), simpan setting + parse chatIds, token kosong dipertahankan, endpoint tes mengirim (Http::fake), alarm baru memicu kirim Telegram saat aktif, tidak mengirim saat nonaktif.

Changed:

- `app/Services/AlarmEvaluator.php` — `reconcile()` kini mengumpulkan model `AlarmEvent` yang baru raised & yang cleared lalu memanggil `TelegramNotifier::notify()`. Dependensi notifier opsional di konstruktor (`?TelegramNotifier`, di-resolve lazy via `app()`) agar `new AlarmEvaluator` di test lama tetap jalan.
- `routes/web.php` — 3 route di grup `role:admin`: `settings.edit`, `settings.telegram.update`, `settings.telegram.test`.
- `resources/js/Layouts/AuthenticatedLayout.vue` — nav "Pengaturan" (ikon Settings) hanya untuk admin (gate `can.manage_users`).

Notes:

- Hook notifikasi di titik raise/clear alarm (`AlarmEvaluator`), bukan di controller, sehingga semua sumber polling (scheduler `olts:poll` → `PollOltJob`, dan evaluasi manual) ikut memicu notifikasi.
- Demo aman: OLT demo statis (scheduler hanya menyentuh OLT nyata) + guard `is_demo` di notifier. Setting Telegram admin-only, demo sudah diblokir `BlockDemoWrites`.
- 102 test hijau (7 baru), `pint` & `npm run build` bersih.
- Saat menjalankan test ditemukan `bootstrap/cache/config.php` & `routes-v7.php` ter-cache (dari `config:cache`/`route:cache` produksi), membuat phpunit memakai koneksi pgsql + route lama. Test dijalankan dengan menyisihkan kedua file cache sementara (sqlite in-memory, terisolasi; data pgsql terverifikasi utuh), lalu cache dikembalikan persis. Data produksi tidak tersentuh (transaksi RefreshDatabase rollback).
- **Belum di-deploy ke instance produksi.** Agar live perlu: `php artisan migrate --force` (buat tabel `telegram_settings`) + rebuild cache `php artisan config:cache && php artisan route:cache` agar 3 route `settings.*` dikenali. Build frontend sudah ter-update.

Deploy + perbaikan akurasi alarm (lanjutan):

- Dideploy ke produksi: `migrate --force` (tabel `telegram_settings` dibuat), `config:cache` + `route:cache` (route `settings.*` dikenali), `queue:restart` (worker memuat kode notifikasi). Catatan penting: worker `queue:work` adalah daemon yang memuat kode sekali saat start — wajib `queue:restart` setiap deploy kode yang jalan di job/service, kalau tidak notifikasi/perbaikan tak berefek meski file sudah berubah.
- Bug akurasi ditemukan saat user uji coba: notifikasi "tidak sesuai" karena `AlarmEvaluator::onuStateAlarms()` menaikkan alarm `dying_gasp`/`los` berdasarkan `last_down_cause` — padahal itu riwayat penyebab turun terakhir yang tetap menempel walau ONU sudah online lagi. Akibatnya 1774 ONU yang sebenarnya Working/online ikut ber-alarm dying_gasp (total 1848 aktif). Fix: gerbang `if ($onu['online'] ?? false) return [];` di awal — ONU yang up tidak ber-alarm apa pun, `last_down_cause` hanya dipakai untuk mengklasifikasikan ONU yang memang offline.
- Bug kedua: alarm RX (`high_rx_attenuation`) flapping di sekitar ambang -28 dBm (mis. -28.2 → -27.9 tiap poll) → raise/clear bergantian, mengirim "CLEARED" walau RX masih marginal. Fix: hysteresis — raise saat rx ≤ -28 / ≥ -8, tapi baru clear setelah rx pulih melewati -27 / -9 (deadband 1 dB). `onuRxAlarm()` kini menerima koleksi alarm aktif untuk menentukan apakah tetap dipertahankan; `evaluate()` memuat alarm aktif sekali dan meneruskannya ke `onuRxAlarm()` + `reconcile()` (reconcile tak lagi query sendiri).
- `tests/Feature/AlarmEngineTest.php` — 2 test regresi: ONU online dengan `last_down_cause=DyingGasp` tidak ber-alarm; RX hysteresis (raise di -28.4, tetap aktif di deadband -27.5, baru clear di -26.0).
- Pembersihan data: setelah fix + `queue:restart`, dijalankan `evaluate()` pada 2 OLT nyata (Telegram dimatikan sementara agar tak spam ~1781 notifikasi clear) → alarm aktif **1984 → 203** (C320: clear 91 sisa 24; C300: clear 1690 sisa 173). dying_gasp 1848 → 72. Sisa 203 semuanya alarm asli (offline/los/port_down/high_rx). Telegram diaktifkan kembali.
- 27 test alarm/telegram/polling hijau, `pint` bersih.

### Seragamkan tampilan waktu ke WIB

Konteks: penyimpanan sudah UTC (`app.timezone=UTC`), tapi tampilan tidak konsisten — frontend ikut zona browser, pesan Telegram pakai UTC (mis. tampil 07:49 padahal 14:49 WIB), chart dashboard sudah `display_timezone=Asia/Jakarta`. User minta semua jam tampil WIB.

Created:

- `resources/js/lib/datetime.js` — helper terpusat tampilan waktu, semua pakai `timeZone: 'Asia/Jakarta'` + label `WIB`. Fungsi: `formatDateTime` ("29 Mei 2026, 16.42 WIB"), `formatDate` (tanggal saja), `formatClock` (jam header kompak), `formatTimeOfDay` (label sumbu chart live, tanpa suffix). Null-safe (`'—'`).

Changed (frontend — semua formatter `Intl.DateTimeFormat` lokal diarahkan ke helper):

- `resources/js/Pages/SmartOlt/{Alarms,Detail,PortOnus,Unconfigured,UnconfiguredGlobal,Registrations,Index,PortManager}.vue`, `Pages/Settings/Index.vue`, `Pages/Users/Index.vue`, `Components/Dashboard/{RecentAlarmsTable,OnuStatusDonut}.vue`, `Components/Shell/SystemInfoPanel.vue`. PortManager juga: label sumbu chart traffic live pakai `formatTimeOfDay`. Settings/OnuStatusDonut tetap mengembalikan `null` (bukan '—') agar `v-if` tetap benar.

Changed (backend):

- `app/Services/Telegram/TelegramNotifier.php` — timestamp pesan (alarm & tes) kini `Carbon::now()->timezone(config('app.display_timezone','Asia/Jakarta'))->translatedFormat('d M Y H:i').' WIB'` (sebelumnya UTC tanpa label — ini akar pesan tampil 07:49).
- `app/Services/Report/ReportService.php` — 3 timestamp report (last_polled_at, last_seen_at, created_at) dikonversi ke display_timezone sebelum `format('d/m/Y H:i')`.
- `app/Http/Controllers/ReportController.php` — `generatedAt` PDF jadi WIB + label.
- `DashboardStatsService` sudah konversi ke `display_timezone` (chart) — tak diubah.

Notes:

- Sumber kebenaran zona tampilan: backend `config('app.display_timezone')` (default `Asia/Jakarta`, bisa di-override env `APP_DISPLAY_TIMEZONE`); frontend konstanta `DISPLAY_TZ='Asia/Jakarta'` di helper. Storage tetap UTC.
- Awalnya user sempat minta "GMT", lalu mengoreksi ke WIB — dikonfirmasi WIB sebelum eksekusi.
- Deploy: `npm run build` (live), `queue:restart` (TelegramNotifier jalan di worker). Tak ada migrasi; tak perlu rebuild config/route cache (tak ubah .env/route). Report jalan di php-fpm (auto-reload opcache).
- 104 test hijau, `pint` & `npm run build` bersih.

### Alarm berbasis transisi (hanya online→fault), clean slate

Permintaan user: alarm hanya saat **pergantian status** dari sehat ke fault (ONU online→LOS/dying-gasp/offline, port up→down, RX sehat→menyentuh -28), sekali saja. Perangkat yang **sudah** dalam keadaan fault sejak awal (mis. ONU offline lama di OLT) **tidak** boleh masuk alarm. RX cleared baru pada -26 (kalau masih -27 jangan).

Changed:

- `app/Services/AlarmEvaluator.php`:
  - `evaluate(SnmpOlt $olt, array $previous = [])` — kini menerima snapshot poll sebelumnya untuk mendeteksi transisi.
  - `indexPrevious()` — bangun lookup status sebelumnya: online per-ONU, rx per-ONU, oper_status per-port.
  - Aturan raise: hanya jika kondisi fault adalah **transisi dari sehat** (`prevOnline===true` untuk ONU, `prevStatus==='up'` untuk port, `prev ok` untuk OLT, RX `prevHealthy` untuk high_rx) ATAU alarm tipe itu sudah aktif (persist). Fault yang sudah ada sejak awal (tak pernah terlihat sehat) → di-skip.
  - `onuHasStateAlarm()` — agar episode fault yang sudah beralarm tetap dipertahankan walau subtipe berganti (offline↔los↔dying_gasp).
  - RX: ambang clear dinaikkan ke **-26** (`RX_CLEAR_LOW_DBM`) / -10 (`RX_CLEAR_HIGH_DBM`); raise hanya saat melintas dari sehat (`prevHealthy`), persist sampai pulih ≥ -26.
  - `reconcile()` tetap: clear alarm aktif yang tak lagi terdeteksi (fault pulih), raise yang baru, keep yang persist.
- `app/Jobs/PollOltJob.php` — tangkap `$previousSnapshot = $olt->last_test_result` sebelum overwrite, teruskan ke `evaluate($olt, $previousSnapshot)`.

Tests:

- `tests/Feature/AlarmEngineTest.php` — diubah ke model transisi + test baru: `already_offline_onu_is_not_alarmed`, `rx_already_out_of_range_is_not_alarmed`, `port_down_raises_only_on_transition`; RX hysteresis clear di -26.
- `tests/Feature/TelegramSettingsTest.php` — evaluate dipanggil dengan snapshot sebelumnya (online/port-up) agar transisi memicu raise.

Deploy & clean slate:

- `queue:restart` (worker memuat evaluator baru), lalu **hapus semua alarm nyata** (`AlarmEvent::withoutGlobalScopes()->where('is_demo',false)->delete()` → 4133 baris terhapus, demo 8 utuh). Setelah ini hanya transisi baru yang memunculkan alarm. Tak ada migrasi/build frontend.
- 107 test hijau, `pint` bersih.

### Pesan CLEARED menampilkan status pulih (online + RX terbaru)

Masalah: notifikasi CLEARED menyalin pesan fault lama (mis. "RX 98.064 dBm di luar rentang sehat", "loss of signal (LOS)") — bukan kondisi saat pulih. User minta CLEARED menampilkan status online & redaman terbaru.

Changed:

- `app/Services/AlarmEvaluator.php`:
  - `indexCurrent()` — index snapshot saat ini (ONU per key + port) untuk dipakai saat clear.
  - `buildRecovery()` — bangun pesan pulih per scope/type dari snapshot terkini: ONU state→"ONU {iface} kembali online, RX {rx} dBm."; high_rx→"ONU {iface} RX {rx} dBm kembali normal."; port→"GPON port {name} kembali up."; OLT→"OLT kembali terhubung." (null bila ONU tak ada di snapshot → fallback ke pesan asli).
  - `reconcile()` — saat clear, simpan `meta.recovery` (message + rx_power_dbm + online); `message` asli (fault) tetap utuh untuk histori. Menerima param `$current`.
- `app/Services/Telegram/TelegramNotifier.php` — `formatAlarm()` untuk alarm cleared memakai `meta.recovery.message` (fallback ke message asli), jadi Telegram CLEARED menampilkan kondisi pulih.

Tests:

- `tests/Feature/AlarmEngineTest.php` — `onuSnapshot` online kini sertakan RX; test clear memverifikasi `meta.recovery.message` berisi "kembali online" + nilai RX terbaru.

Deploy: `queue:restart`. Tanpa migrasi/build. 30 test alarm/telegram/polling hijau, `pint` bersih.

Catatan: muncul nilai RX tidak wajar (+98.064 / -0.002 dBm) yang memicu high_rx (sisi terlalu kuat, rx ≥ -8). Itu kemungkinan pembacaan invalid; sisi "RX terlalu kuat" tak diminta user (hanya -28). Bisa jadi follow-up: batasi rentang RX valid atau matikan deteksi sisi tinggi.

### Chunking notifikasi Telegram untuk batch besar

Masalah: semua alarm dalam 1 siklus poll digabung jadi 1 pesan. Telegram membatasi 4096 karakter/pesan, jadi 20+ alarm bisa gagal kirim total.

Changed:

- `app/Services/Telegram/TelegramNotifier.php` — `notify()` memecah daftar section (raised + cleared) jadi beberapa pesan via `array_chunk`, maksimal `MAX_ITEMS_PER_MESSAGE = 10` alarm per pesan. Bila lebih dari satu pesan, header diberi penanda bagian "(i/n)". Tiap chunk dikirim terpisah ke semua chat ID.
- `tests/Feature/TelegramSettingsTest.php` — test `large_alarm_batch_is_split_into_multiple_messages`: 12 ONU online→offline → 12 alarm → 2 pesan (10 + 2), `Http::assertSentCount(2)`.

Deploy: `queue:restart`. 22 test telegram/alarm hijau, `pint` bersih.

### Bump versi aplikasi ke 2.0.0

Changed:

- `app/Http/Middleware/HandleInertiaRequests.php` — default `config('app.version')` `1.0.0` → `2.0.0` (ditampilkan di panel System Info).

### Halaman ONU Monitoring (lintas OLT & port)

Created:

- `resources/js/Pages/SmartOlt/OnuMonitor.vue` — halaman baru di sidebar. Filter dalam card terpisah: search, pilih OLT, pilih port, status (Online/LOS/Dying Gasp/Offline berbasis `phase_state`), admin (Active/Disabled). Default kosong → harus pilih OLT dulu (tidak render semua 2000+ baris di awal). Tabel mirip PortOnus + kolom OLT, tombol "buka di port" (focus ke ONU), tombol "Scan ONU OLT ini".

Changed:

- `app/Http/Controllers/SmartOltController.php` — `onuMonitor()` agregasi semua ONU ter-cache (`port_onus.*.onus`) dari semua OLT jadi satu list flat; `refreshOnuMonitor()` scan penuh 1 OLT dalam sekali walk (gponPorts + registeredOnus + RX) lalu tulis balik ke cache per-port agar konsisten dengan halaman PortOnus.
- `routes/web.php` — `monitoring.onu` (GET `/onu-monitoring`) + `monitoring.onu.refresh` (POST `/onu-monitoring/{olt}/refresh`).
- `resources/js/Layouts/AuthenticatedLayout.vue` — link sidebar "ONU Monitoring" (ikon Radar), match `monitoring.*`.

Notes:

- Nama route sengaja di luar prefix `smartolt.*` agar tidak ikut meng-highlight item SmartOLT di sidebar.

### Fix: global search bisa cari by Serial Number (SN)

Changed:

- `app/Http/Controllers/DashboardSearchController.php` — pencarian ONU sebelumnya membaca key `sn`/`serial` yang tak pernah ada; `OltSnmpClient` menyimpan serial sebagai `serial_number`. Diperbaiki baca `serial_number` (fallback `sn`/`serial`), tambah cocokkan via `interface`, label hasil = serial, sublabel = `OLT · slot/port · nama`.

Notes:

- Diuji terhadap cache OLT-C320-PATI: query `RTEGCA96` → 10 hasil. Tanpa perubahan frontend (GlobalSearch render hasil generik).

### Fitur Telnet di browser (xterm.js + WebSocket proxy)

Created:

- `config/telnet.php` — host/port daemon, `ws_url` publik, TTL ticket, connect timeout.
- `app/Support/Telnet/TelnetTicket.php` — ticket terenkripsi (Crypt/APP_KEY) berisi user+olt+exp, TTL pendek, **URL-safe (base64url)** agar lolos query string nginx/browser tanpa mangle.
- `app/Support/Telnet/TelnetIacFilter.php` — negosiasi/strip IAC telnet (accept ECHO/SGA, tolak lainnya), stateful tahan split antar-chunk.
- `app/Services/Telnet/TelnetProxyServer.php` — jembatan WS↔telnet (react/socket + ratchet/rfc6455 + guzzle/psr7 yang sudah dibawa Reverb, tanpa dependency baru): handshake → verifikasi ticket → dial telnet OLT → pipe 2 arah + auto-login pakai kredensial OLT.
- `app/Console/Commands/TelnetProxyCommand.php` — daemon `php artisan telnet:proxy`.
- `app/Http/Controllers/TelnetSessionController.php` — terbitkan ticket + `ws_url` (gated `canManageOlt`, tolak demo); dukung `ws_url` relatif → scheme/host otomatis dari request.
- `resources/js/Components/Shell/TelnetWindow.vue` — jendela terminal mengambang xterm: drag, minimize, maximize/restore, resize, status. Lazy-loaded.

Changed:

- `routes/web.php` — `smartolt.telnet.token` (POST `/smartolt/{olt}/telnet/token`).
- `resources/js/Pages/SmartOlt/Index.vue` — tombol aksi Telnet per OLT (admin/operator + transport telnet); host `TelnetWindow` (lazy via `defineAsyncComponent`).
- `package.json` / `package-lock.json` — tambah `@xterm/xterm` + `@xterm/addon-fit`.
- `.env.example` — entri `TELNET_PROXY_*`.

Notes:

- Setup server lokal (di luar repo): daemon di `127.0.0.1:6002` via supervisor `kusumavision-telnet-proxy`; nginx route `location /telnet-ws` → proxy ke daemon (pakai port 80 ber-ACL, firewall tak diubah); `.env` set `TELNET_PROXY_WS_URL=/telnet-ws`.
- Diverifikasi end-to-end lewat nginx:80 dengan fake telnet lokal: handshake 101, frame encoding benar, IAC ter-strip, auto-login berhasil. IAC filter & ticket round-trip lolos unit test.
- **Gotcha penting:** `.env` tidak terbaca www-data (root:root 640) → app hanya jalan dengan config ter-cache. `config:clear` saat setup sempat menjatuhkan situs (500 sqlite) + daemon 401; dipulihkan dengan `config:cache`. Selalu `config:cache` + restart daemon setelah ubah `.env`/config telnet; jangan tinggalkan config dalam keadaan ter-clear.

### Landing page tampilkan fitur baru + dokumentasi disesuaikan

Changed:

- `resources/js/Pages/Welcome.vue` — bagian Fitur tambah "Telnet via Browser" & "Global Search", copy "ONU Monitoring" diperbarui jadi lintas-OLT; Modul tambah "ONU Monitoring" (Radar) & "Telnet Console" (Terminal); hero pill tambah "Web Telnet".
- `CLAUDE.md` — koreksi klaim usang "No Go polling engine" (poller Go `bin/kv-snmp-poller`/`cmd/kv-snmp-poller` lewat `GoSnmpPoller`+`PollOltJob`, prod `SNMP_POLLER_DRIVER=go`, fallback PHP); Architecture tambah ONU Monitoring page, browser telnet (proxy+daemon), global search; Commands tambah `telnet:proxy` & build Go; Conventions tambah gotcha config-cache prod.
- `README.md` — Fitur tambah ONU Monitoring/Telnet browser/Global search; tabel stack tambah xterm.js & telnet browser; langkah deploy baru "Langkah 8 — Telnet Proxy Browser" (supervisor + nginx `/telnet-ws` + `.env`), akun jadi Langkah 9; ringkasan hardening tambah telnet-proxy & catatan config-cache.

Notes:

- Diverifikasi Golang BENAR dipakai sebagai engine polling terjadwal (bukan vision PRD) — dokumen lama yang menyatakan sebaliknya dikoreksi.
- Permission `.env` server diselaraskan ke `640 root:www-data` (sesuai README) agar www-data bisa baca; dibuktikan `config:clear` tak lagi menjatuhkan situs (tetap 200). Perubahan permission ini di sistem, di luar git.

### Go-live publik: nms.kusumavision.net via Cloudflare (TLS Full strict) + hardening

Notes (perubahan ini di tingkat sistem/server, di luar git — didokumentasikan di sini):

- **IP publik** `103.189.249.86` di-bind ke `eth0` sebagai alamat sekunder. Server adalah LXC di Proxmox (jaringan di-manage PVE via `/etc/systemd/network/eth0.network`). Agar tak ditimpa PVE, IP ditaruh di drop-in `/etc/systemd/network/eth0.network.d/10-public-ip.conf` (`Address = 103.189.249.86/32`). Catatan: kalau container di-recreate dari panel Proxmox, IP perlu didaftarkan ulang di config container pada host.
- **nginx** (`/etc/nginx/sites-available/kusumavision-nms` diganti, backup `.bak.*`): server `:80` redirect 301 ke HTTPS; server `:443 ssl http2` melayani app + WebSocket `/telnet-ws`. ACL `allow/deny` LAN lama **dihapus** karena setelah real-IP Cloudflare dipulihkan ACL itu akan memblokir semua pengunjung publik — penguncian origin dipindah ke firewall. Snippet `/etc/nginx/snippets/cloudflare-realip.conf` (`set_real_ip_from` semua rentang CF v4/v6 + `real_ip_header CF-Connecting-IP`) untuk memulihkan IP visitor asli di log/app/fail2ban. `fastcgi_param HTTPS on` + header HSTS ditambahkan.
- **TLS**: Cloudflare Origin Certificate (SAN `nms.kusumavision.net`, valid s/d 2041) di `/etc/nginx/ssl/origin.{pem,key}` (key `600`). SSL mode Cloudflare **Full (strict)**. Sebelumnya 526 saat masih self-signed; setelah Origin Cert dipasang → HTTP/2 200.
- **Firewall (UFW)**: 80/443 dibuka & dikunci hanya ke rentang IP resmi Cloudflare (v4+v6) + LAN privat + subnet admin `103.189.248.0/24`,`103.189.249.0/24`. SSH (22) tetap hanya LAN + subnet admin. SSH sudah hardened sebelumnya (`PasswordAuthentication no`, `PermitRootLogin without-password`, pubkey only).
- **fail2ban** dipasang (`jail.local`): jail `sshd` (efektif penuh, `/var/log/auth.log`, ban 2h), `nginx-http-auth`, `nginx-botsearch`; `ignoreip` mencakup LAN + subnet admin. Catatan: untuk trafik HTTP yang lewat Cloudflare, ban iptables atas IP visitor asli hanya efektif untuk akses langsung-ke-origin; untuk blokir abuse ber-proxy perlu action Cloudflare API / WAF.
- **App**: `APP_URL=https://nms.kusumavision.net` (backup `.env.bak.*`), `php artisan config:cache`, `queue:restart`, restart daemon telnet-proxy.
- **Verifikasi**: lokal `https://127.0.0.1` (Host header) → 200; via IP publik → 200; live `https://nms.kusumavision.net` → HTTP/2 **200** (Inertia + assets ke-render), `http://` → 301 ke https. fail2ban 3 jail aktif tanpa error.

### Ganti tagline "Unified FTTH Network Management Platform" → "ZTE OLT Management & Provisioning Platform"

Changed:

- `resources/js/Components/Dashboard/HeroBanner.vue`, `resources/js/Layouts/GuestLayout.vue`, `resources/js/Pages/Welcome.vue` — ganti tagline/subjudul/title tab jadi "ZTE OLT Management & Provisioning Platform" (lebih sesuai scope ZTE GPON). Frontend di-rebuild (`npm run build`).

### Hero/footer landing teks lengkap + matikan autofill PPPoE

Changed:

- `resources/js/Pages/Welcome.vue` — H1 hero (yang sebelumnya terpecah jadi span: "Unified" / "FTTH Network" / "Management Platform") & teks footer diganti ke "ZTE OLT Management & Provisioning Platform"; aksen gradient kini di "OLT Management".
- `resources/js/Pages/SmartOlt/ConfigureOnu.vue`, `resources/js/Pages/SmartOlt/RegisterOnu.vue` — input WAN PPPoE: tambah `autocomplete="off"` (username) & `autocomplete="new-password"` (password) + `data-1p-ignore`/`data-lpignore` agar browser/password-manager tidak auto-fill kredensial Google ke field PPPoE.
- `public/img/dashboard1.png` (diperbarui) + `public/img/{detail,login,oltinventory,unconfigured}.png` (baru) — aset screenshot landing disediakan user.

Notes:

- Tulisan di dalam mockup dashboard hero adalah gambar (`/img/dashboard1.png`), bukan teks HTML — diganti dengan mengganti file PNG, bukan kode.
- `TextInput.vue` meneruskan `$attrs` ke `<input>`, jadi atribut `autocomplete`/`data-*` cukup ditaruh di pemakaian komponen. Frontend di-rebuild (`npm run build`).

### Section galeri "Tampilan Aplikasi" di landing page

Changed:

- `resources/js/Pages/Welcome.vue` — tambah section `#tampilan` (galeri screenshot interaktif): daftar tab kiri (Dashboard, OLT Inventory, ONU Belum Terdaftar, Detail ONU, Login) + preview dalam frame browser-chrome dengan crossfade `<Transition name="kv-fade">`; pakai `computed currentShot`. Tambah link nav "Tampilan" (header + mobile). Tambah `<style scoped>` untuk transisi (hormati `prefers-reduced-motion`).

Notes:

- Pakai 5 screenshot user di `public/img/` (dashboard1, oltinventory, unconfigured, detail, login). Frame `aspect-[16/10]` + `object-cover object-top` agar konsisten & tanpa layout shift antar-tab; `loading="lazy"` + hanya gambar aktif yang dirender (sisanya dimuat saat tab diklik).
- A11y: `role="tablist"`/`tab` + `aria-selected`, `alt` deskriptif per gambar. Semua ikon sudah ada di import Lucide.
- Catatan optimasi: screenshot masih PNG (~0.9–1.4 MB/file); bisa dikonversi WebP untuk hemat bandwidth bila perlu. Frontend di-rebuild.

### Halaman Pengaturan: card full-width

Changed:

- `resources/js/Pages/Settings/Index.vue` — container konten dari `mx-auto w-full max-w-3xl` jadi `w-full` agar card membentang penuh kiri-kanan, konsisten dengan halaman lain (SmartOlt/Users/Reports). Frontend di-rebuild.

### Halaman Pengaturan: rapikan isi jadi grid 2 kolom

Changed:

- `resources/js/Pages/Settings/Index.vue` — body form Telegram dari `space-y-6` single-column jadi `grid lg:grid-cols-2` agar field tidak melebar setelah card full-width: toggle aktif (full), Bot Token | Chat ID, Severity minimum | Pemicu notifikasi (2 checkbox dibungkus panel berlabel), status & tombol aksi span penuh. Frontend di-rebuild.

### Fitur & halaman Audit Logs

Created:

- `database/migrations/2026_05_29_130000_create_audit_logs_table.php` — tabel `audit_logs` (immutable, hanya `created_at`): `user_id` (nullOnDelete) + `user_name` snapshot, `event`, `auditable_type`/`auditable_id` (morph), `description`, `properties` (json), `ip_address`, `user_agent`. Index pada user_id, event, created_at, (auditable_type, auditable_id). Sqlite-compatible.
- `app/Models/AuditLog.php` — model audit; konstanta event (created/updated/deleted/login/logout/login_failed/telnet_opened), `UPDATED_AT = null`, cast `properties` array, relasi `user()`.
- `app/Support/AuditLogger.php` — titik tunggal penulisan audit; `log()` menangkap aktor (auth), IP & user-agent dari request, `model()` membangun deskripsi Indonesia ("Menambahkan/Memperbarui/Menghapus <label> <judul>").
- `app/Models/Concerns/Auditable.php` — trait yang hook event created/updated/deleted model → audit otomatis. Atribut `$hidden` + password + `$auditExclude` per-model tidak pernah ikut tercatat; update kosong (setelah exclude) di-skip.
- `app/Http/Controllers/AuditLogController.php` — halaman index (admin only) dengan filter event/user/pencarian/rentang tanggal + paginasi 25.
- `resources/js/Pages/AuditLogs/Index.vue` — halaman glass-style (selaras Alarms): kartu filter, tabel desktop + kartu mobile, baris bisa di-expand untuk lihat diff lama→baru / atribut.
- `tests/Feature/AuditLogTest.php` — 4 test: akses admin vs operator (403), audit perubahan model tanpa secret, filter by event.

Changed:

- `app/Models/{SnmpOlt,User,SmartOltProfile,SmartOltOnuRegistration,TelegramSetting}.php` — pasang trait `Auditable` + `auditLabel()`/`auditTitle()` + `$auditExclude` (field volatil/sensitif: hasil polling OLT, last_notifications_read_at, cli_script/output, password PPPoE/ACS, bot_token).
- `app/Providers/AppServiceProvider.php` — listener event auth: `Login`/`Logout`/`Failed` → audit login/logout/login_failed (email percobaan dicatat di properties).
- `app/Http/Controllers/TelnetSessionController.php` — catat event `telnet_opened` saat tiket telnet diterbitkan.
- `routes/web.php` — route `audit-logs.index` di dalam grup `role:admin`.
- `resources/js/Layouts/AuthenticatedLayout.vue` — link sidebar "Audit Logs" (ikon ScrollText), hanya untuk admin.

Notes:

- Verifikasi: secret terenkripsi (mis. `snmp_read_community`) terbukti TIDAK ikut tercatat karena masuk `$hidden` → otomatis dikecualikan oleh trait. Diuji via tinker (rollback) + test feature.
- Semua 112 test lulus (`php artisan config:clear` dulu — config cache bikin test nyasar & error 419, sesuai catatan deploy). Frontend di-rebuild (`npm run build`).
- Audit log hanya bisa dilihat admin; baris bersifat append-only (tak ada UI edit/hapus).

### Fix penghitungan Status ONU "Warning" di Dashboard

Changed:

- `app/Services/Dashboard/DashboardStatsService.php` — penghitung warning sebelumnya membaca `$onu['rx_power']`/`$onu['rx']` yang tidak pernah ada di cache `port_onus`, sehingga warning **selalu 0**. Diperbaiki membaca `rx_power_dbm` (field RX power per-ONU yang sebenarnya tersimpan, lihat `PollOltJob`/`OltSnmpClient`), dengan fallback `rx_power`/`rx` untuk data lama. Warning kini hanya dihitung untuk ONU **online** dengan RX di luar zona aman `-25…-10 dBm` (guard `online` mencegah ONU offline dengan RX basi ikut terhitung).
- `resources/js/Components/Dashboard/OnuStatusDonut.vue` — slice donut dibuat mutually-exclusive: warning adalah subset ONU online, jadi slice **Online = online − warning** dan **Offline = offline asli** dari backend (sebelumnya offline keliru dikurangi warning lagi sehingga undercount).
- `tests/Feature/DashboardTest.php` — fixture diperluas (ONU sehat, RX rendah, RX terlalu kuat, offline-RX-basi) + assertion `cards.onu.warning` agar regresi tidak terulang.

Notes:

- Diverifikasi terhadap cache produksi nyata (2 OLT): total=2251, online=2143 (cocok dashboard); logika lama warning=0, logika baru warning=500 (472 RX ≤ -25 dBm + 28 RX ≥ -10 dBm). Distribusi online: 1599 zona aman, 388 di -25…-28, 84 kritis (< -28), 28 terlalu kuat.
- Threshold -25/-10 dBm konsisten dengan konvensi app (OnuDetail "Zona aman -25…-10", ReportService "Warning < -25").
- Test Dashboard lulus (49 assertions), Pint bersih. Perubahan donut perlu `npm run build` saat deploy.

### Update fitur landing page + perbaikan galeri screenshot tidak terpotong

Changed:

- `resources/js/Pages/Welcome.vue` — (1) Tambah 4 kartu fitur baru yang sudah dibangun tapi belum tampil di landing: **Reports & Analytics** (FileBarChart), **Notifikasi Telegram** (Send), **Audit Logs** (ScrollText), **Role-based Access** (ShieldCheck) — grid Fitur jadi 12 kartu (rapi 4×3 di `lg:grid-cols-3`). Import ikon `ScrollText` & `Send` ditambahkan. (2) Perbaiki galeri "Tampilan Aplikasi": frame sebelumnya dipaksa `aspect-[16/10]` + `object-cover object-top` sehingga screenshot ter-crop (rasio gambar beda-beda). Sekarang tiap screenshot diberi field `ratio` sesuai dimensi asli (dashboard 1920×1282, OLT/login/unconfigured 1920×911, detail 1920×1112), frame pakai `:style="{ aspectRatio: currentShot.ratio }"` + `object-contain` jadi gambar tampil utuh ke ukuran aslinya. Ditambah `transition-[aspect-ratio] duration-300` agar frame resize halus saat ganti tab; crossfade antar gambar tetap dipertahankan.

Notes:

- Dimensi asli gambar dicek via `getimagesize` di `public/img`; container dengan aspect-ratio == rasio asli + `object-contain` membuat gambar mengisi penuh tanpa crop maupun letterbox.
- `npm run build` sukses, assets di-rebuild. Build artifacts (`public/build`) di-gitignore, hanya source `Welcome.vue` yang ter-commit — perlu `npm run build` ulang saat deploy.

### README — sinkronkan daftar fitur dengan scope terbaru

Changed:

- `README.md` — bagian **Fitur** dirombak jadi 4 kelompok (Inventory & Monitoring, Provisioning & ONU, Polling/Alarm/Notifikasi, Administrasi & Pelaporan) karena daftar membengkak jadi 22 item; ditambahkan fitur yang belum tercatat: **Notifikasi Telegram**, **Bot Telegram (webhook perintah read-only)**, **RBAC** (admin/operator/demo), **Mode Demo** (`is_demo` + global scope), **Manajemen User**, **Report** (5 jenis, export CSV/PDF), **Audit Logs** (immutable), **Pengaturan** (branding + Telegram). Catatan Dashboard *Warning* dari RX power & hysteresis alarm RX ditambahkan ke deskripsi terkait.
- `README.md` — tabel **Stack Teknologi** tambah baris Telegram Bot API & export CSV/PDF (`barryvdh/laravel-dompdf`).
- `README.md` — section opsional baru **Notifikasi & Bot Telegram**: langkah setup notifikasi (BotFather/userinfobot) + registrasi webhook (`php artisan telegram:webhook set|info|delete`) dan syarat URL HTTPS publik + forwarding nginx `POST /telegram/webhook`.
- `README.md` — **Catatan & Batasan** tambah poin RBAC, Mode Demo (link `docs/DEMO_DEPLOYMENT.md`), dan syarat webhook Telegram (CSRF exempt).

Notes:

- Murni dokumentasi; tidak ada perubahan kode. Deskripsi fitur diverifikasi terhadap WORKLOG & `routes/web.php` (route `reports.*`, `users.*`, `audit-logs.*`, `settings.*`, `telegram.webhook`). Link `docs/DEMO_DEPLOYMENT.md` & `docs/LOCAL_PRODUCTION_HARDENING.md` dipastikan ada.

### Fix: tombol logout tidak ada di tampilan mobile

Changed:

- `resources/js/Layouts/AuthenticatedLayout.vue` — `UserMenu` (berisi tombol Keluar/logout) ternyata hanya dirender di header desktop (`lg:block`), sedangkan mobile top bar & sidebar drawer tak punya menu user sama sekali → user tidak bisa logout di mobile. Ditambahkan blok akun di bagian bawah sidebar drawer, **khusus mobile** (`lg:hidden`, desktop tetap pakai `UserMenu` di header): avatar inisial + nama + email, lalu tombol **Profile** (Inertia `Link` ke `profile.edit`, menutup drawer saat diklik) dan **Keluar** (`Link method="post"` ke `logout`). Import ikon `LogOut`/`User` + computed `user`/`userInitial` dari `auth.user`.

Notes:

- `npm run build` bersih. Build artifacts (`public/build`) di-gitignore — perlu `npm run build` ulang saat deploy.

### Optimasi halaman Welcome — konversi screenshot PNG → WebP

Changed:

- `resources/js/Pages/Welcome.vue` — 7 referensi gambar (galeri "Tampilan Aplikasi": dashboard/oltinventory/unconfigured/detail/login, hero dashboard, hardware c320) diarahkan dari `.png` → `.webp`.
- `resources/js/Pages/SmartOlt/Detail.vue` — gambar hardware OLT (`c300`/`c320`) → `.webp`.
- `public/img/*` — 7 PNG dikonversi ke WebP (`cwebp -q 80`) lalu PNG lama dihapus: `dashboard1`, `oltinventory`, `unconfigured`, `detail`, `login`, `c300`, `c320`.

Notes:

- Folder `public/img` turun **6.3 MB → 516 KB** (~92% lebih kecil). Penghematan per file: login 1387→22 KB, dashboard 1396→98 KB, oltinventory 1108→38 KB, unconfigured 908→38 KB, detail 1133→68 KB, c300 255→109 KB, c320 196→90 KB.
- Quality 80 cukup untuk screenshot UI (teks tetap tajam). WebP didukung semua browser modern (Chrome/Firefox/Edge, Safari 14+) — aman untuk dashboard NOC, tanpa fallback PNG.
- Hero dashboard tetap `loading="eager"` (gambar LCP) tapi kini ~98 KB sehingga first paint jauh lebih ringan. `npm run build` bersih.

## 2026-05-30

### Developer Handbook + bersih-bersih dokumen usang

Created:

- `docs/handbook/README.md` — indeks + cara pakai handbook + konvensi wajib.
- `docs/handbook/01-overview.md` … `14-panduan-tambah-fitur.md` — 14 bab dokumentasi teknis terbagi per-topik: overview, arsitektur, struktur folder, instalasi/deploy, skema DB & model, routing, modul & fitur, SNMP & polling, CLI & telnet, alarm & Telegram, keamanan/RBAC/audit, frontend, troubleshooting, panduan menambah fitur.

Changed:

- `README.md` — tambah pointer ke `docs/handbook/`, seksi "Cara Cepat (`install.sh`)", deskripsi Langkah 2 (cek requirement) diperbarui, daftar ekstensi PHP diselaraskan, dan seksi "Dokumentasi" baru.

Removed:

- `docs/IMPLEMENTATION_NEXT_STEPS.md`, `docs/PLANNING_NEXT_PHASE.md` — dokumen perencanaan awal yang seluruh langkahnya sudah diimplementasikan (usang). Dikonfirmasi user.
- `docs/KusumaVision_NMS_Dokumentasi_Fitur.pdf` — PDF deskripsi fitur awal, sudah digantikan README + handbook.

Notes:

- Handbook berbasis kode nyata (bukan PRD); bagian yang masih blueprint (C600 parsial, SSH, TimescaleDB) ditandai eksplisit. Setiap bab punya navigasi prev/next + link silang.
- File yang dihapus ter-track git → bisa dipulihkan dari history. Tidak ada link aktif (README/CLAUDE.md/handbook) yang rusak.
- `docs/INSTALLATION_STATUS.md`, `LOCAL_PRODUCTION_HARDENING.md`, `DEMO_DEPLOYMENT.md`, `SMARTOLT_ZTE_C300_C320_GUIDE.md`, `KusumaVision_NMS_PRD.md`, dan 6 PDF C600 dipertahankan.

### Skrip deploy `install.sh` + cek requirement

Created:

- `install.sh` — deploy satu-perintah untuk server Ubuntu kosong (22.04/24.04): pasang runtime (PHP 8.3 + ekstensi, Composer, Node 22, PostgreSQL, Redis, Nginx, Supervisor, Go, Net-SNMP), buat DB + `.env` production, build frontend & Go poller, migrasi, nginx site (+ proxy `/telnet-ws`), daftarkan daemon supervisor (`kusumavision-worker`/`-scheduler`/`-telnet-proxy`), opsional buat admin & UFW, lalu smoke test. Mendukung `--yes` (non-interaktif via env var) dan `--help`; idempotent.

Changed:

- `scripts/check-requirements.sh` — ditingkatkan: cek versi minimum tool (PHP≥8.2, Composer≥2, Node≥20, Go≥1.18, psql≥14), daftar ekstensi PHP, artefak runtime (binary poller/build/`.env`/`APP_KEY`), dan status service + daemon supervisor. `[MISS]` (wajib) memengaruhi exit code; `[WARN]` (info) tidak.

Notes:

- Langkah `install.sh` mengikuti baseline `INSTALLATION_STATUS.md`/`LOCAL_PRODUCTION_HARDENING.md`. `bash -n` lolos untuk kedua skrip; `check-requirements.sh` diverifikasi jalan di host dev (semua wajib OK, exit 0).
- `install.sh` belum diuji end-to-end di server Ubuntu kosong (tidak tersedia di lingkungan ini) — logika dirancang idempotent + aman.
- Artefak runtime (`bin/kv-snmp-poller`, `public/build`) di-gitignore — `install.sh` membangun ulang saat deploy.

### Lisensi proprietary + sinkron CLAUDE.md & skill /done

Created:

- `LICENSE` — lisensi proprietary BMKV ringkas (bilingual ID/EN): kepemilikan, larangan salin/ubah/distribusi/reverse-engineer/pakai tanpa izin, komponen pihak ketiga tetap di bawah lisensinya, disclaimer "as is".

Changed:

- `composer.json` — `license` `MIT` → `proprietary`; `name`/`description`/`keywords` diganti dari sisa skeleton Laravel ke identitas proyek.
- `CLAUDE.md` — tambah pointer ke `docs/handbook/`, perintah deploy (`install.sh`, `scripts/check-requirements.sh`), dan bullet konvensi deploy fresh-server + catatan lisensi proprietary.
- `.claude/commands/done.md` — `Co-Authored-By` `Sonnet 4.6` → `Opus 4.8`, tambah tipe commit `docs`, dan pengingat sinkronkan `CLAUDE.md`/`docs/handbook/` bila struktur/konvensi berubah.

Notes:

- JSON `composer.json` tervalidasi; `proprietary` adalah nilai lisensi yang dikenali Composer.
- Murni dokumentasi/tooling — tidak ada perubahan kode aplikasi atau migrasi.

### Atribusi pemilik permanen di footer (anti-ubah lewat UI)

Changed:

- `app/Models/GeneralSetting.php` — tambah konstanta permanen `OWNER` ("PT Berkah Media Kusuma Vision"), `OWNER_SHORT` ("BMKV"), `COPYRIGHT_YEAR`; `brandingPayload()` selalu menyertakan `owner`/`owner_short`/`copyright_year` dari konstanta (bukan dari DB), sehingga tidak bisa diubah lewat halaman Pengaturan.
- `resources/js/Layouts/AuthenticatedLayout.vue` — footer jadi `© {tahun} {appName} NMS · {owner}` (sebelumnya hardcode "Dibuat Oleh Masamune"); `owner`/`copyrightYear` dibaca dari `branding` dengan fallback.
- `resources/js/Layouts/GuestLayout.vue` — footer login memakai `branding` (appName + owner permanen) menggantikan teks statis.

Notes:

- Keputusan user: kunci **atribusi pemilik saja** — `app_name` tetap bisa di-white-label via Settings, tetapi pemilik/copyright permanen di level kode.
- Disclaimer jujur ke user: kode sumber tidak bisa dibuat benar-benar anti-ubah; proteksi nyata adalah `LICENSE` proprietary. Perubahan ini hanya memindah atribusi dari DB/UI ke konstanta kode, sehingga menghapusnya berarti edit source = pelanggaran lisensi.
- Fallback JS (`?? 'PT Berkah Media Kusuma Vision'`) menjaga footer benar walau cache branding lama; cache key `general_settings.branding` di-forget + `npm run build`. 121 test lolos.

## 2026-05-31

### Navbar transparan-saat-scroll, hero full layar, lebar kontainer, swap gambar OLT

Changed:

- `resources/js/Pages/Welcome.vue` — navbar: dari `sticky` + latar solid permanen menjadi `fixed` overlay yang **transparan di puncak** dan memunculkan latar semi-transparan (`bg-slate-950/60` + blur) saat di-scroll (>12px) atau drawer mobile terbuka (`navSolid` computed + listener `onWindowScroll`). Ukuran navbar dibesarkan sedikit (`py-3`→`py-4`, logo `h-8`→`h-9`, brand `text-[15px]`). Tombol Login/Dashboard dipercantik (padding lega, `rounded-xl`, inner ring, efek kilau/shine saat hover, ikon panah `ArrowRight`). Hero `lg:min-h-[calc(100vh-57px)]`→`min-h-screen` (full satu layar). Semua kontainer halaman `max-w-7xl`→`max-w-[1600px]` (12 lokasi) agar mengisi layar kanan-kiri.
- `public/img/c320(1).webp` — gambar strip hardware OLT baru (konversi dari `c320(1).png` via `cwebp -q 90`, 1600×444, alpha lossless); tinggi gambar di welcome dikecilkan `h-32/md:h-40` → `h-20/md:h-24/lg:h-28` agar tidak kebesaran (rasio gambar sangat lebar).

Notes:

- Navbar `fixed` membuat hero benar-benar tembus di belakangnya; padding atas hero (`py-16`/`lg:py-24`) menjaga konten tidak tertutup navbar.
- Gambar lama `public/img/c320.webp` dihapus (di-replace `c320(1).webp`).

### Upgrade interaktif semua section landing (Bold & interaktif)

Created:

- (helper CSS, bukan file baru — ditambah di `app.css`)

Changed:

- `resources/css/app.css` — helper landing reusable di `@layer components`: `.kv-spotlight` (sorotan radial ikut kursor via `--spot-x/--spot-y`), `.kv-ring` (border conic-gradient beranimasi saat hover, pakai `@property --ring-angle`), `.kv-marquee` (+ `.kv-marquee-wrap`, infinite scroll, pause on hover), `.kv-float` (idle float). Tambah `@keyframes kv-ring-spin/kv-marquee/kv-float` + guard `prefers-reduced-motion`.
- `resources/js/Pages/Welcome.vue` — directive baru `vSpotlight` (gaya sama `vTilt`/`vMagnetic`). **Stats**: ikon+aksen warna per angka, garis aksen atas saat hover, spotlight. **Hardware strip**: gradient ring + ambient glow + gambar `kv-float`. **Section baru**: capability marquee (12 kapabilitas berjalan, fade tepi). **Feature grid**: spotlight + ring + ikon/judul beranimasi. **Cara Kerja**: garis konektor digambar mengikuti scroll (GSAP `fromTo` scaleX + ScrollTrigger scrub, ref `stepsLineEl`) + kartu spotlight/ring. **Galeri Tampilan**: autoplay 5s (`startGallery/stopGallery/advanceShot/selectShot`, `GALLERY_MS`), pause saat hover (`galleryPaused`), progress bar `.kv-prog` (restart via `:key`). **Tech Stack**: ring + logo `kv-float` (delay desync per index). **Modul**: spotlight + ring + hover lift + chevron geser. **Final CTA**: ring + glow `animate-pulse`/`kv-float`.

Notes:

- Tanpa dependency baru — semua efek pakai stack terpasang (GSAP/ScrollTrigger/Lenis) + directive `v-spotlight` ringan + CSS murni; bundle tetap lean.
- Semua animasi dimatikan untuk pengguna `prefers-reduced-motion` (guard di `app.css` global + `<style scoped>` Welcome).
- `.kv-spotlight > *` diberi `z-index:1`; `position:absolute` anak (mis. nomor langkah, divider stats) tetap menang karena utilities layer Tailwind di atas components layer. `@property` graceful-degrade di browser lama (ring jadi statis, tidak rotasi). Build `npm run build` lolos.

### Feature grid: ikon & isi rata tengah

Changed:

- `resources/js/Pages/Welcome.vue` — kartu Feature grid dibuat rata tengah: `text-center` di kartu + `mx-auto` di span ikon (judul & deskripsi ikut center).

### Header atas & footer app dikunci (tidak ikut scroll), header per-halaman tetap ikut scroll

Changed:

- `resources/js/Layouts/AuthenticatedLayout.vue` — layout app login diubah dari *document scroll* (`min-h-screen` + header/footer `sticky`) menjadi **viewport tetap dengan area scroll di tengah**: root jadi `flex h-screen flex-col overflow-hidden`; kolom utama `flex-1 overflow-hidden`. Header atas (top bar mobile + header desktop search/notif/user) dan footer kini `flex-shrink-0` (benar-benar diam, tidak lagi `sticky`). Header per-halaman (slot `header`) + demo banner + `<main>` dibungkus satu kontainer `flex min-h-0 flex-1 flex-col overflow-y-auto` dengan atribut `scroll-region`, sehingga **header per-halaman ikut tergulir bersama konten** sesuai permintaan; header paling atas & footer tetap menempel.

Notes:

- Atribut `scroll-region` membuat Inertia 2 me-reset posisi scroll area tengah saat pindah halaman (sebelumnya scroll mengikuti `window`); pola sama dipakai `resources/js/Components/Modal.vue`.
- Footer dulu hanya `lg:sticky` (diam di desktop saja) — sekarang diam di mobile juga karena berada di luar area scroll.
- Pakai `h-screen` (100vh); di sebagian browser mobile address-bar bisa sedikit memotong — bila mengganggu nanti bisa diganti `h-[100dvh]`. Build `npm run build` lolos (20.01s).

### Animasi jaring partikel (ParticleNetwork) menyeluruh di app + login, fix gagal re-init saat navigasi

Created:

- `resources/js/lib/particles.js` — module singleton tsParticles: `ensureParticlesEngine()` cache promise `loadSlim()` (register plugin **sekali seumur tab**) dan `nextParticlesId(prefix)` untuk id unik per mount. State harus di module ini (bukan di `<script setup>`) supaya benar-benar persist lintas mount.

Changed:

- `resources/js/Components/Shell/ParticleNetwork.vue` — tidak lagi `import loadSlim` + `tsParticles.load` dengan id statis. Sekarang `import { ensureParticlesEngine, nextParticlesId, tsParticles } from '@/lib/particles'`; pakai `uid = nextParticlesId(props.id)` untuk DOM id + registry id; tambah flag `destroyed` (guard race kalau komponen unmount selama `await` saat navigasi cepat → bersihkan/ batalkan init).
- `resources/js/Layouts/AuthenticatedLayout.vue` — pasang `<ParticleNetwork id="kv-app-particles" class="!fixed inset-0" :quantity="64" />` di dalam `<main>` (di belakang konten) via `defineAsyncComponent`, jadi animasi tampil **menyeluruh di semua halaman app** (Dashboard, SmartOLT, Monitoring, Alarms, Report, Users, dst), bukan per-halaman. `<main>` diberi `relative`, slot konten diberi `relative` agar di atas partikel.
- `resources/js/Layouts/GuestLayout.vue` — pasang `<ParticleNetwork id="kv-login-particles" :quantity="48" />` di latar halaman login & semua halaman Auth (via `defineAsyncComponent`).

Notes:

- **Akar masalah utama** (error `Register plugins can only be done before calling tsParticles.load()`): isi `<script setup>` sebenarnya badan `setup()` yang dieksekusi ulang TIAP komponen mount. Karena layout app **non-persistent** (komponen partikel re-mount tiap navigasi Inertia), singleton `let enginePromise` yang ditaruh di dalam `<script setup>` ter-reset ke `null` tiap pindah halaman → `loadSlim()` terpanggil lagi setelah `load()` pertama → `pluginManager.register()` throw (lihat `node_modules/@tsparticles/engine/cjs/Core/Utils/PluginManager.js`, `#initialized`). Solusi: pindahkan singleton ke module eksternal (`lib/particles.js`) yang benar-benar di module scope.
- Pola `defineAsyncComponent` dipertahankan (gotcha Vite manifest page facade yang sudah tercatat) — diverifikasi chunk `AuthenticatedLayout`/`GuestLayout`/`Dashboard` tetap ada di manifest, `ParticleNetwork` jadi chunk async terpisah (~106 kB).
- Tiap instance pakai id unik → tidak bentrok registry tsParticles saat mount/unmount tumpang-tindih. `pointer-events-none` + hormati `prefers-reduced-motion` (latar statis). Build `npm run build` lolos (18.07s).

### Dokumentasi UI & tema dashboard + aturan halaman/komponen baru

Created:

- `docs/handbook/15-ui-tema-dashboard.md` — dokumen handbook baru: bahasa desain "dark glass cyber/NOC", palet & token warna (aksen/status + heks yang dipakai di kode), referensi lengkap kelas utilitas `kv-*` (chrome, permukaan kaca, lingkaran ikon, pill/badge, form, alert, tabel responsif desktop+mobile), anatomi shell `AuthenticatedLayout`, template halaman acuan, 13 aturan wajib + daftar "hindari", dan checklist pre-commit UI.

Changed:

- `docs/handbook/README.md` — tambah baris indeks #15 + petunjuk cepat "menambah/ubah halaman atau komponen UI".
- `docs/handbook/12-frontend.md` — callout look & feel yang merujuk doc 15.
- `docs/handbook/14-panduan-tambah-fitur.md` — Resep 1 tambah langkah "Tampilan" yang merujuk doc 15.
- `CLAUDE.md` — pointer singkat di bagian Conventions ke aturan tema (pakai `kv-*` dulu, kartu kaca, tabel responsif, gerbang `auth.can`, string Indonesia).

Notes:

- Sumber kebenaran token = `resources/css/app.css` (`@layer components`) + `Layouts/AuthenticatedLayout.vue`; dokumen mendeskripsikan kode yang benar-benar ada (bukan PRD) dan menegaskan "kode menang" bila ada beda.
- Hanya perubahan dokumentasi — tidak ada kode aplikasi/asset yang berubah, jadi tidak perlu rebuild/deploy.

## 2026-06-01

### Fix konversi RX power ONU (SNMP raw → dBm): nilai +98 & redaman -30/-40 tidak terbaca

Changed:

- `app/Services/Snmp/OltSnmpClient.php` — `convertOnuRxPowerToDbm()`: cabang `raw > 0` (encoding C300/C320 OID `3902.1012.3.50.12.1.1.10`) kini menafsirkan raw sebagai **signed 16-bit** sebelum rumus `dBm = signed16(raw) * 0.002 - 30`. Guard lama `raw >= 65000` dihapus (sempat membuang range -30 s/d -31 dBm); ganti sentinel `raw === 65535` (0xFFFF = N/A) + jendela kewajaran hasil `[-45, 0]` dBm untuk buang garbage.
- `cmd/kv-snmp-poller/main.go` — `convertOnuRXPowerToDBM()`: perbaikan identik pada jalur Go poller terjadwal (prod `SNMP_POLLER_DRIVER=go`). Binary `bin/kv-snmp-poller` sudah di-rebuild (`go build -mod=mod`).

Notes:

- **Diverifikasi di OLT live `OLT-C320-PATI` (id=1)**: encoding terbukti `raw * 0.002 - 30` (raw 207→-29.586, 5000→-20, 10805→-8.39). Bug: raw `64032` ditafsir unsigned → **+98.064 dBm** ("nilai sampai 90an"); sebenarnya signed `-1504` → **-33.008 dBm**. Sinyal lemah lain (mis. -40 dBm = raw 60536) salah hitung jadi positif besar lalu ter-skip ("ngga kebaca di -40").
- Setelah fix: poll ulang OLT 1 via binary baru → tidak ada lagi dBm > 0, raw 64032 = -33.008, dan nilai bagus lama tetap sama. Cabang negatif (`/1000`, `/10`) untuk firmware lain tidak diubah.
- Cara diagnosa untuk ke depan: dump `raw_rx_power` vs `rx_power_dbm` dari `last_test_result.port_onus`, cek nilai > 32767 sebagai two's-complement.
- Deploy: `opcache.validate_timestamps=On` (PHP terpungut otomatis); binary Go di-exec fresh tiap poll → poll terjadwal berikutnya otomatis pakai logika baru. Klik "Scan ONU OLT ini" untuk refresh cache seketika. Opsional `php artisan queue:restart` untuk fallback PHP di worker.

### Filter redaman ONU RX di halaman ONU Monitoring

Changed:

- `resources/js/Pages/SmartOlt/OnuMonitor.vue` — tambah dropdown filter **Redaman** (Semua/Normal/Peringatan/Kritis/Tanpa Data RX). Helper `rxLevel()` baru mengklasifikasikan `rx_power_dbm` dan dipakai bersama oleh `rxBadgeClass()` + filter `filteredOnus` agar ambang batas konsisten. Filter masuk ke `hasFilter`/`clearFilters`. Murni sisi klien (data `rx_power_dbm` sudah ada).

Notes:

- Stat cards tetap menghitung seluruh ONU (tidak ikut terfilter) — perilaku sama seperti filter lain di halaman ini.

### Filter Redaman RX, filter Status, & status berbasis phase di halaman Report

Changed:

- `app/Services/Report/ReportService.php` — (1) konstanta `RX_STATUSES`; `rxPower()` menerima filter `rx_status` (Normal/Warning/Critical) yang mempersempit baris tapi summary tetap penuh. (2) `build()` membungkus hasil dengan `applyStatusFilter()` baru: menempel `status_options` (+`status_column`) dan filter baris per status; RX dilewati (pakai redaman). (3) helper `onuPhaseLabel()`: kolom status laporan **Inventaris ONU** kini dari `phase_state` (Working→Online, LOS, DyingGasp→Dying Gasp, Offline) dengan 4 opsi tetap seperti ONU Monitoring; jenis lain (OLT pakai kolom `reachable`, Alarm, Provisioning) opsinya diturunkan dari data.
- `app/Http/Controllers/ReportController.php` — parse & validasi query `rx_status` + `status`, diteruskan ke view.
- `resources/js/Pages/Reports/Index.vue` — dropdown **Redaman RX** (hanya jenis `rx`) dan **Status** (jenis non-`rx` yang punya opsi); auto-reset saat ganti jenis laporan; `statusClass()` tambah warna LOS (merah) & Dying Gasp (kuning).

Notes:

- Semua filter **server-side** → export CSV/PDF ikut terfilter.
- Diverifikasi: ONU `status_options` = [Online, LOS, Dying Gasp, Offline]; distribusi cocok dengan `phase_state` cache (Online 2154, Dying Gasp 76, Offline 15, LOS 10); tiap filter konsisten 100%. Hanya PHP + frontend, tidak perlu restart daemon (`npm run build` lolos).

### Foto tampilan aplikasi di README + tooling snapshot

Created:

- `scripts/snapshot.mjs` — script Playwright (Chromium headless) untuk capture halaman **Welcome (hero+navbar)**, **Login**, dan **Dashboard** ke `public/img/*.webp`; screenshot PNG lalu dikonversi `.webp` via `cwebp`. Konfigurasi via env (`BASE_URL`, `OUT_DIR`, `WIDTH/HEIGHT`, `DSF`, `WEBP_QUALITY`, `ONLY`, `SNAP_USER/SNAP_PASS`). Default akses `https://127.0.0.1` + `ignoreHTTPSErrors` (hindari Cloudflare bot-challenge di domain publik); tunggu `networkidle` + 2.5 dtk agar animasi hero (tsParticles/typed.js/AOS/gsap) & chart ApexCharts selesai.
- `public/img/welcome.webp`, `public/img/dashboard.webp` — screenshot baru (landing hero+navbar; dashboard full-page dengan data live).

Changed:

- `README.md` — section baru **Tampilan Aplikasi** (sebelum Fitur): `welcome.webp` sebagai gambar utama + `dashboard.webp`, lalu grid 2 kolom `login`/`oltinventory`/`detail`/`unconfigured`.
- `public/img/login.webp` — di-capture ulang (retina/DSF=2, lebih tajam dari versi lama).
- `package.json` / `package-lock.json` — tambah script `snapshot`; `playwright` jadi devDependency.
- `.gitignore` — abaikan `.snap.env` (file kredensial sementara untuk capture dashboard).

Notes:

- **Server ini produksi.** Capture dashboard perlu login → kredensial disuplai user via `.snap.env`, di-`source` saat run tanpa dicetak, lalu dihapus (`shred`). Tidak pernah masuk kode/log/commit. Semua request hanya `GET` (read-only), tidak menyentuh data.
- Skill Claude Code `snapshot` (`.claude/skills/snapshot/SKILL.md`) dibuat **lokal saja** — tidak di-commit karena `.claude` di-gitignore. Pakai `npm run snapshot` untuk regenerasi.
- Tooling terpasang di server: `playwright` + Chromium + OS-deps (`npx playwright install-deps chromium`: libnss3, libcups2, libnspr4, dll).
- `public/img/dashboard1.webp` lama tidak lagi dirujuk README (dibiarkan di repo, tidak dihapus).
- Regenerasi kapan saja: `npm run snapshot` (welcome+login) atau `SNAP_USER=… SNAP_PASS=… npm run snapshot` (+dashboard); subset via `ONLY=welcome,login,dashboard`.

## 2026-06-08

### README: alur env setup→production + fix prompt Composer root di check-requirements

Changed:

- `README.md` — restrukturisasi alur environment pada panduan instalasi manual. **Langkah 4** kini set `APP_ENV=local` + `APP_DEBUG=true` + `LOG_LEVEL=debug` selama setup (sebelumnya langsung `production`/`APP_DEBUG=false`) supaya error saat migrasi/build terlihat jelas. **Langkah 5** — `php artisan optimize` dihapus dari blok permission (ditunda ke langkah harden) + catatan verifikasi via `php artisan serve`/`composer dev`. **Langkah 10 — Harden ke production** (baru): set `production`/`APP_DEBUG=false`/`LOG_LEVEL=warning`, lalu `php artisan optimize` + restart daemon Supervisor, plus peringatan permission `.env` `640 root:www-data` (kalau salah → fallback sqlite → 500).
- `scripts/check-requirements.sh` — `export COMPOSER_ALLOW_SUPERUSER=1` + `COMPOSER_NO_INTERACTION=1` di awal script. Saat dijalankan sebagai root, `composer --version` (dengan `2>/dev/null`) memunculkan prompt "Continue as root/super user [yes]?" yang teksnya kebuang ke stderr → script seolah berhenti menunggu Enter setelah pengecekan PHP. Kedua env var mematikan prompt root sepenuhnya.

Notes:

- Hanya dokumentasi + script utilitas; tidak menyentuh runtime aplikasi. `.env.example` (sudah `local`) dan `install.sh` (sudah set `production` di akhir deploy otomatis, baris 245-246) tidak diubah — alur manual baru kini konsisten dengan keduanya.
- Fix Composer diverifikasi: di lingkungan non-tty Composer otomatis non-interaktif sehingga tak reproduksi, tapi `COMPOSER_ALLOW_SUPERUSER=1` mematikan peringatan/prompt tanpa peduli tty. Script dijalankan ulang penuh → semua tool [OK] tanpa jeda.

### install.sh: pin PHP 8.3 konsisten + Go poller build statis & smoke test

Changed:

- `install.sh` — (1) **Pin versi PHP**: variabel baru `PHP_CLI="php${PHP_VERSION}"`; `run_artisan`, `PHP_BIN` (command daemon Supervisor), serta installer & `composer install` kini dipanggil eksplisit lewat `php8.3` (bukan `php` polos). Plus `update-alternatives --set php /usr/bin/php8.3` setelah pasang runtime agar default `php` sistem = 8.3. Mencegah split di mana FPM jalan 8.3 tapi artisan/worker nyangkut ke PHP lebih baru (mis. 8.4) bila sudah terpasang — persis mismatch yang ditemukan saat cek deploy manual di server lain. (2) **Go SNMP poller**: build jadi statis (`CGO_ENABLED=0 go build -mod=mod -trimpath -ldflags='-s -w'`) supaya binary self-contained (tak tergantung glibc) & aman dipindah antar server, lalu **smoke test** pasca-build (jalankan binary, pastikan emit JSON `"ok"`; kalau gagal → `[WARN]`, karena `PollOltJob` akan diam-diam fallback ke PHP).

Notes:

- Diverifikasi di server ini: build statis menghasilkan `statically linked` (binary 2.67 MB vs 3.96 MB dynamic), smoke test → `"ok":true`. Build uji dilakukan ke `/tmp` agar binary produksi yang sedang dipakai worker tidak terganggu; `bash -n install.sh` lolos.
- Hanya menyentuh `install.sh` (alur deploy fresh) — aplikasi yang sudah berjalan tidak terdampak.
- Bukti Go benar-benar terpakai saat runtime: `snmp_olts.last_test_result::jsonb ->> 'go_poller_error'` bernilai null pada OLT id=1 & id=2 (poll nyata via Go, bukan fallback).

## 2026-06-11

### Pilihan Service Mapping Mode (VLAN+Priority / Transparent) di provisioning & configure ONU

Changed:

- `app/Services/ZteProvisioningScriptBuilder.php` — helper baru `serviceLine()`; baca `service_mode` dari data form. Mode `transparent` emit `service NAME gemport 1` (tanpa cos/vlan), mode `vlanpri` (default) tetap `service NAME gemport 1 cos 0 vlan {vlan}`.
- `app/Services/ZteOnuRunningConfigService.php` — regex parser service kini menjadikan `cos X vlan Y` opsional; baris tanpa cos/vlan → `mode = transparent` (vlan null), dengan cos/vlan → `mode = vlanpri`. Mencegah service transparent terbaca sebagai "berubah" saat diff.
- `app/Services/ZteOnuReconfigureScriptBuilder.php` — `diffServices()` & `fmtService()` mendukung per-baris `mode`; saat transparent, `vlan` boleh kosong dan baris hanya emit `gemport N`.
- `app/Http/Controllers/SmartOltController.php` — default form `service_mode: 'vlanpri'` + aturan validasi `service_mode` (provisioning) dan `config.services.*.mode` (reconfigure), keduanya `in:vlanpri,transparent`.
- `resources/js/Pages/SmartOlt/RegisterOnu.vue` — toggle "Service Mapping Mode" di samping Service Name + preview CLI live.
- `resources/js/Pages/SmartOlt/ConfigureOnu.vue` — kolom **Mode** (dropdown) di tabel service; input COS/VLAN otomatis disabled saat transparent; `addService()` default `mode: 'vlanpri'`.
- `tests/Unit/ZteOnuConfigureTest.php` — 3 test baru (parser vlanpri/transparent, switch reconfigure ke transparent, builder provisioning transparent vs vlanpri).

Notes:

- Latar masalah: di sesama OLT C320, sebagian ONU tidak konek di mode VLAN+Priority (`service ... cos 0 vlan 125`) tapi konek di mode Transparent (`service ... gemport 1`). Disamakan dengan pilihan mode mapping di GUI ZTE NetNumen (VLAN+Priority vs Transparent).
- `service_mode` **tidak** dipersist ke tabel `smartolt_onu_registrations` (bukan di `$fillable`, jadi Laravel silently discard seperti `is_c600`) — modenya sudah terekam implisit di kolom `cli_script` audit. Tidak perlu migrasi.
- Verifikasi: `php artisan test tests/Unit/ZteOnuConfigureTest.php` → 9 passed (52 assertions); `npm run build` sukses; Pint passed. Feature test `SmartOltInventoryTest` yang gagal 419 adalah gotcha config cache produksi, bukan dari perubahan ini.

### Fix bug pemetaan slot/port ONU: tabrakan if-index ONU-prefix vs IF-MIB port

Created:

- `cmd/kv-snmp-poller/main_test.go` — test regresi: `buildPortMap` harus di-key oleh ONU-prefix index (bukan IF-MIB if-index), round-trip `onuPortPrefixIndex`↔`decodeIfIndex`, dan decode if-index ONU C320.

Changed:

- `cmd/kv-snmp-poller/main.go` — `buildPortMap()` kini di-key oleh **ONU-table prefix index** `0x10000000|slot<<16|port<<8` (helper baru `onuPortPrefixIndex()`), bukan `port.IfIndex` (IF-MIB). Sebabnya: dua sistem penomoran if-index ZTE tumpang tindih — prefix ONU slot 1 port P **sama persis** dengan if-index IF-MIB port `gpon_1/2/(P+1)` (mis. ONU 1/1 prefix `268501248` == if-index `gpon_1/2/2`), sehingga override `portMap[ifIndex]` salah mengikat semua ONU slot 1 ke port slot 2 (P→P+1).
- `app/Services/Snmp/OltSnmpClient.php` — `registeredOnus()`: untuk **non-C600** (C300/C320) slot/port ONU diambil langsung dari `decodeIfIndex()` (otoritatif untuk ONU-prefix); override portMap (IF-MIB) di-skip. **C600 dibiarkan di jalur lama** (belum bisa diuji untuk tabrakan ini).

Notes:

- Diagnosa dari OLT produksi teman (`OLT NOBLE NET`, C320 @192.168.2.10, lewat server `nms-kusuma`, **read-only**): raw `snmpbulkwalk` tabel ONU type mengembalikan **2060 ONU <1 detik**, slot 1 port 1–10 penuh (1/1=88 … 1/10=1, 1/16=60). Tapi cache NMS menaruh slot 1 port 1–15=0 dan menggelembungkan slot 2. **Total tetap 2060** — bukan ONU hilang, murni salah label. Pola pergeseran 1/P→2/(P+1) cocok 100% di 10 port (mis. 2/2: 93+88=181, 2/3: 128+47=175). Dikonfirmasi NetNumen GUI (Slot 1/GTGH Port 1 NGADIPIRO penuh) — decode `>>16/>>8` = kenyataan.
- Slot 2 & port 16 selamat karena prefix-nya **di atas** rentang if-index port tertinggi (gpon_1/2/16 = 268504832) → tak ada match di portMap → pakai decode (benar).
- Verifikasi lokal: `go vet`/`go test` (3 test) ok, `go build` statis ok (binary 2.67 MB), smoke test emit JSON valid; Pint passed; PHPUnit Unit 13 passed.
- **Penting buat deploy teman:** `bin/kv-snmp-poller` di-gitignore → setelah `git pull` WAJIB rebuild (`go build -o bin/kv-snmp-poller ./cmd/kv-snmp-poller` atau `install.sh`), lalu `php artisan queue:restart` + re-poll OLT agar cache slot 1 terisi benar. Perubahan PHP cukup `php artisan config:cache` bila perlu (kode otomatis terpakai untuk refresh on-demand).

## 2026-06-15

### Hapus kartu "Distribusi RX Power" di halaman ONU Monitoring

Changed:

- `resources/js/Pages/SmartOlt/OnuMonitor.vue` — buang pemakaian `<RxDistributionCard :onus="oltScopedOnus" />` beserta import-nya; histogram distribusi RX power dihilangkan dari halaman monitoring.

Notes:

- Atas permintaan user — kartu histogram dirasa kurang pas di layout halaman. Hanya histogram distribusi yang dihapus; fitur RX power time-series & trend gauge dari commit sebelumnya tetap ada.
- `oltScopedOnus` dibiarkan utuh (masih dipakai tabel ONU & statistik). File komponen `resources/js/Components/SmartOlt/RxDistributionCard.vue` ikut dihapus karena sudah tak ada referensi.

### Visualisasi chassis OLT + hapus Port Manager → halaman Detail Port per-interface

Created:

- `resources/js/Components/SmartOlt/OltChassis.vue` — visualisasi sasis OLT data-driven (di halaman Detail OLT). Render 1 modul per slot dari `cards`; LED port diwarnai live: GPON dari `oper_status` SNMP, uplink dari `link_status` tersimpan (hijau=up, merah=down, abu=belum dipoll). Slot kosong di antara min–max tetap tampil. Klik port GPON/uplink → halaman detail port. Dua orientasi: vertikal (C300, kolom ramping melar penuh kiri-kanan, port 1 kolom, pasangan slot 19/20 ditumpuk atas-bawah via `STACK_PAIRS`) & horizontal (C320, line-card span penuh + slot kontrol ≥3 berbagi 2 kolom kartu, port 1 baris, LED besar di tengah).
- `resources/js/Pages/SmartOlt/PortDetail.vue` — halaman detail per-interface: status link, trafik (chart live ApexCharts untuk uplink + counter), optical/SFP (redaman RX/TX + threshold warna), VLAN tagged + form tambah VLAN (uplink), ringkasan ONU + tombol ke daftar ONU (GPON).

Changed:

- `app/Services/ZteCardUplinkService.php` — tambah `refreshUplinkInterface()` (refresh 1 port uplink xgei/gei dari CLI: port-status + vlan + optical, mirror `refreshGponInterface`).
- `app/Http/Controllers/SmartOltController.php` — hapus method Port Manager (`dashboard`/`refreshDashboard`/`refreshDashboardInterface`/`dashboardTraffic`/`storeDashboardVlan`); tambah `portDetail`/`refreshPortDetail` (dispatch GPON vs uplink)/`portTraffic` (JSON)/`storePortVlan`. `detail()` kirim prop `interfaces` (link/admin per interface) ke chassis; `refreshHardware()` sekalian refresh detail interface uplink (non-fatal) agar status link per-port terisi.
- `routes/web.php` — hapus 5 route `smartolt.port-manager*`, tambah `smartolt.port.detail/refresh/traffic/vlan`.
- `resources/js/Pages/SmartOlt/Detail.vue` — ganti blok gambar/tabel hardware lama dengan komponen `OltChassis` (+ tombol Refresh Hardware via slot `#actions`); hapus tombol "Port Manager"; teruskan prop `interfaces`.
- `tests/Feature/SmartOltHardwareInterfaceTest.php` — 3 test diarahkan ke route/komponen baru (`smartolt.port.refresh`, `SmartOlt/PortDetail`).
- `docs/handbook/01,03,06,07,12-*.md` — sinkron Port Manager → Detail Port + visualisasi chassis.

Deleted:

- `resources/js/Pages/SmartOlt/PortManager.vue` (1013 baris) — digantikan navigasi via chassis + halaman Detail Port.

Notes:

- Nama interface dibentuk di chassis dari tipe kartu: GPON→`gpon-olt_1/{slot}/{port}`, HUVQ/HUVG/HUVX→`xgei_1/...`, SMXA/SMXB→`gei_1/...`; kartu kontrol (SCXN)/power (PRWG) tidak diklik. Prefix shelf dipakukan `1` (selaras `discoverUplinkInterfaces` & snapshot C300/C320 single-shelf).
- Status link uplink baru terisi setelah Refresh Hardware (butuh CLI per-interface) — sebelum itu port uplink tampil abu (bukan hijau palsu).
- Layout C320 (horizontal) dideteksi dari nama model mengandung `c320`. Slot 19/20 stacked di C300 lewat `STACK_PAIRS=[[19,20]]` (mudah ditambah pasangan lain mis. 10/11).
- Verifikasi: `php artisan test tests/Feature/SmartOltHardwareInterfaceTest.php` → 5 passed; full suite 129 passed; `npm run build` sukses; Pint passed. Gotcha test: route/config cache prod harus di-clear sebelum test lalu di-cache lagi.

### Tombol hapus provisioning script di Registration History

Changed:

- `routes/web.php` — tambah route `DELETE /smartolt/{olt}/registrations/{registration}` (`smartolt.registrations.destroy`).
- `app/Http/Controllers/SmartOltController.php` — tambah method `destroyRegistration()`: validasi registrasi milik OLT tsb (`abort_unless` 404), tolak hapus bila `status === 'executed'` (sudah teregister di OLT), selain itu `delete()` + flash sukses.
- `resources/js/Pages/SmartOlt/Registrations.vue` — import ikon `Trash2`, helper `canDelete()` (`status !== 'executed'`), fungsi `deleteRegistration()` dengan `ConfirmModal` varian `danger`; tombol delete (IconButton merah) muncul di section "Provisioning Scripts" (belum dieksekusi) & di "Logs" untuk yang `failed`.

Notes:

- Atas permintaan user — script yang belum dieksekusi bisa dihapus dari Registration History.
- Script yang sudah `executed`/teregister tidak bisa dihapus, baik dari UI (tombol disembunyikan) maupun server (ditolak dengan flash error), supaya log audit ONU yang sudah aktif di OLT tetap utuh.
- Verifikasi: `npm run build` sukses, Pint passed, route terdaftar (`route:list`). Route prod ter-cache → `route:cache` ulang setelah tambah route.

### Slot 0/1 (PRWG) ditumpuk atas-bawah di visualisasi chassis

Changed:

- `resources/js/Components/SmartOlt/OltChassis.vue` — tambah pasangan `[0, 1]` ke `STACK_PAIRS` (jadi `[[0, 1], [19, 20]]`) supaya kartu power/kontrol PRWG di slot 0 & 1 digabung jadi satu kolom (atas-bawah), bukan dua kolom penuh berdampingan.

Notes:

- Atas permintaan user — sesuai layout fisik chassis C300, slot 0/1 memang bertumpuk. Logika `chassisColumns` yang ada otomatis menangani pasangan baru ini; tak perlu perubahan lain.
- Verifikasi: `npm run build` sukses.

### Faceplate kartu power (PRWG) & kontrol (SCXN) di visualisasi chassis

Changed:

- `resources/js/Components/SmartOlt/OltChassis.vue` — kartu yang sebelumnya tampil "tanpa port" sekarang digambar faceplate sesuai fisiknya (orientasi vertikal C300 & horizontal C320):
  - Helper `isPowerCard()` (prefix `PRW`) & `isControlCard()` (prefix `SCX`).
  - Kartu power (PRWG): konektor daya **-48V** (kotak amber 2 pin) + **2 port LAN RJ45** (kotak dengan garis pin emas, menghadap kiri), tersusun vertikal ke bawah.
  - Kartu kontrol (SCXN): **3 port LAN manajemen** ditambahkan di bawah deretan port; kartu dibagi dua secara vertikal (4 port di tengah paruh atas, 3 LAN di tengah paruh bawah) via dua area `flex-1` yang masing-masing `justify-center`.

Notes:

- Atas permintaan user, beberapa iterasi tweak UI: arah hadap port LAN, ukuran, spacing, dan posisi (akhirnya port LAN power card & SCXN seragam — `h-5 w-7`, pin menghadap kiri).
- Faceplate murni dekoratif (kartu power/kontrol tak bisa di-poll/klik), ikut tema `kv-*` (slate gelap, aksen amber).
- Verifikasi: `npm run build` sukses (beberapa kali sepanjang iterasi).

### Perbaikan UI VLAN Tagged di halaman Detail Port (uplink)

Changed:

- `resources/js/Pages/SmartOlt/PortDetail.vue` — rapikan section "VLAN Tagged": header dapat badge ringkasan **jumlah total VLAN** (rentang dihitung penuh); chip dibedakan VLAN tunggal (cyan) vs rentang (violet + ikon `Network`, en-dash + `tabular-nums`); empty state jadi kotak dashed berikon; form tambah dipisah garis + label, input pakai kelas `.kv-input` standar (sebelumnya styling inline), tombol disable saat input kosong; notifikasi hasil diubah dari teks kecil jadi alert box (border + dot warna).

Notes:

- Atas permintaan user — UI lama terasa kosong/sparse. Header tetap pakai ikon polos (bukan `kv-circle`) supaya konsisten dengan kartu lain di halaman yang sama.
- Helper baru: `isVlanRange()`, `formatVlan()` (en-dash), computed `totalVlanCount`. Tak ada perubahan backend (route/endpoint VLAN tetap).
- Verifikasi: `npm run build` sukses.

## 2026-06-18

### Navigator pindah antar port di halaman Port ONUs

Changed:

- `resources/js/Pages/SmartOlt/PortOnus.vue` — tambah navigator port di header: tombol ◀/▶ (prev/next port) + dropdown pilih port, supaya bisa pindah-pindah antar port tanpa balik ke daftar GPON Port. Navigasi pakai `router.get(route('smartolt.port-onus', ...))` (Inertia visit, bukan full reload).

Notes:

- Sumber data port dari `olt.last_test_result.ports` (sama dengan yang dipakai fitur "Copy ke port lain"), diurutkan numerik per slot lalu port. Port saat ini selalu disisipkan ke daftar walau belum ter-refresh; navigator hanya muncul bila ada >1 port.
- Tombol prev/next otomatis disabled di port pertama/terakhir, tooltip menampilkan tujuan (mis. "Slot 1 / Port 3"). Responsif: dropdown mengisi lebar di mobile (grid 1 kolom), ringkas di desktop (`max-w-[12rem]`).
- Verifikasi: `npx vite build` sukses.

### Rombak halaman Register ONU: live raw CLI, layout 2 kolom, eksekusi langsung

Changed:

- `app/Http/Controllers/SmartOltController.php` — (1) `storeOnu` kini terima flag `execute`: bila true eksekusi script langsung ke OLT via Telnet (`ZteCliProvisioningExecutor`), simpan registrasi status `executed`/`failed` + output eksekusi; bila false tetap simpan `generated` (audit-only) seperti dulu. (2) Method baru `registerOnuPreview` (+ helper `previewProvisioningInput`) build script lenient tanpa validasi untuk live preview (read-only, tak sentuh OLT). (3) Default `service_name` form jadi `'ServiceName'` (lepas dari nama VLAN profile). (4) `hydrateProvisioningProfiles` tak lagi override `service_name` — VLAN tetap ikut profile, service name independen.
- `routes/web.php` — route baru `smartolt.register.preview` (POST).
- `resources/js/Pages/SmartOlt/RegisterOnu.vue` — layout 2 kolom (`w-full`, full-width seperti halaman lain): kiri panel "Live Raw CLI" sticky (debounce 400ms POST ke preview, tombol Salin), kanan form. Tombol submit jadi dua: "Eksekusi ke OLT" (utama, ada confirm, gated `supports_cli_onu_configure`) dan "Generate script saja" (sekunder). Watcher VLAN profile cuma set VLAN ID, tak lagi sentuh service_name.
- `tests/Feature/SmartOltInventoryTest.php` — `test_static_provisioning_...` diperbarui: kirim `service_name=ManualName` kini diharapkan jadi `service ManualName ... vlan 321` (service name independen), VLAN tetap 321 dari profile.

Notes:

- Atas permintaan user: (1) live view raw CLI langsung kelihatan, (2) UI 2 kolom kiri CLI kanan config, (3) Generate Script diganti eksekusi langsung ke OLT, (4) Service Name jangan ikut VLAN Profile.
- Backward compatible: tanpa flag `execute` (mis. test lama / pemakaian audit-only) perilaku `generated` tetap. Eksekusi langsung mengikuti pola `configureOnuApply` (sanitasi output, mask password CLI, catat audit `smartolt_onu_registrations`).
- Preview & script di Registrations menampilkan password PPPoE plaintext — data input user sendiri di sesi terautentikasi, konsisten dengan tampilan script existing.
- Verifikasi: `php artisan test` → 168 passed; `npx vite build` sukses; Pint passed.

## 2026-06-25

### Kurangi tinggi peta di halaman Peta ONU

Changed:

- `resources/js/Pages/Map/Index.vue` — area peta tak lagi memenuhi seluruh viewport. Container luar lepas pemaksaan tinggi penuh (`h-[calc(100vh-4rem)]` → `flex flex-col`), dan area peta dari `flex-1` jadi tinggi tetap `h-[78vh] min-h-[420px]`.

Notes:

- Atas permintaan user: peta terasa terlalu tinggi. Sempat dicoba `60vh` (dianggap terlalu pendek), lalu disepakati `78vh` — lebih pendek dari penuh tapi tetap lapang, min 420px untuk layar kecil.

### Fitur "Aktifkan TR069 Massal" per-OLT ZTE (dry-run + eksekusi)

Created:

- `database/migrations/2026_06_25_120000_create_tr069_bulk_tasks_table.php` — tabel `tr069_bulk_tasks` (progress batch: execute flag, total/processed/applied/skipped/failed, items json, status, error, started/finished).
- `app/Models/Tr069BulkTask.php` — model + casts + `progressPayload()` (dalam dry-run `applied` = "akan diaktifkan") + relasi olt/creator.
- `app/Services/ZteTr069BulkService.php` — inti fitur. Per port: baca running-config semua ONU (1 sesi telnet/port via `ZteOnuRunningConfigService::fetchMany`) → tentukan skip/apply → (mode eksekusi) tulis `tr069-mgmt 1 state unlock` + acs line (1 sesi tulis/port, satu blok `pon-onu-mng` per ONU). Slot/port diambil dari **key** cache `port_onus` ("{slot}_{port}"). `cachedOnuCount()` untuk denom progress.
- `app/Jobs/Tr069BulkConfigJob.php` — queued job (`$tries=1`, timeout 3600) yang menjalankan service + update progress (pola `CopyOnusToPortJob`).
- `resources/js/Components/SmartOlt/Tr069BulkModal.vue` — modal mandiri: intro (info ACS) → Pindai (Dry-run) → hasil pindai (akan diaktifkan/sudah aktif/gagal) → tombol Eksekusi ke OLT → selesai. Polling status tiap 1.5s.
- `tests/Feature/SmartOltTr069BulkTest.php` — 4 test: endpoint antrikan task (+total dari cache), dry-run lapor tanpa nulis, eksekusi skip yang sudah aktif & tulis sisanya, status endpoint.

Changed:

- `app/Http/Controllers/SmartOltController.php` — method `tr069Bulk` (POST, gate `supports_cli_onu_configure`, antrikan job, total = `cachedOnuCount`) + `tr069BulkStatus` (GET poll). Import job/model/service.
- `routes/web.php` — route `smartolt.tr069-bulk` (POST) + `smartolt.tr069-bulk.status` (GET).
- `config/services.php` — blok `acs` (`ACS_URL`/`ACS_USERNAME`/`ACS_PASSWORD`, di-set lewat `.env`; tanpa kredensial hardcoded).
- `resources/js/Pages/SmartOlt/GponPorts.vue` — tombol "TR069 Massal" di header (gate kapabilitas ZTE) + mount modal.
- `CLAUDE.md`, `docs/handbook/07-modul-fitur.md` — dokumentasi fitur (batch job baru, skip rule, alur 2 fase, default ACS).

Notes:

- Atas permintaan user: aktifkan TR069 di semua ONU OLT ZTE dengan ACS dari `.env` (`ACS_URL`/`ACS_USERNAME`/`ACS_PASSWORD`); yang sudah aktif di-skip. Nilai ACS mengikuti default di guide §5.3.
- **Skip rule**: ONU dilewati bila TR069 sudah `unlock` DAN acs url + username sudah mengarah ke target. Password sengaja TIDAK dipakai sebagai syarat skip (sebagian firmware memasking-nya di `show running-config`), tapi acs line yang ditulis tetap menyertakan password.
- Keputusan UX (dikonfirmasi user): **dry-run dulu lalu eksekusi**, dan **tombol per-OLT** (bukan halaman lintas-OLT). Eksekusi mem-pindai ulang sendiri (tidak bergantung hasil dry-run) agar aman bila state berubah.
- Deteksi sukses per-ONU = agregat per port (executor balas satu ok/error per sesi tulis); kalau script port error, semua ONU di port itu ditandai gagal dengan pesan error.
- **Belum diverifikasi ke OLT nyata.** Test pakai executor palsu (in-memory sqlite). Saat eksekusi nyata, isi cache `port_onus` harus lengkap (Refresh SNMP dulu) karena jadi sumber daftar ONU.
- **Langkah deploy**: `php artisan migrate` (tabel `tr069_bulk_tasks` masih Pending di DB prod), `php artisan config:cache` (blok `services.acs` baru), `php artisan queue:restart` (worker muat job baru `Tr069BulkConfigJob`), lalu `npm run build`.

### Fix TR069 Massal — baca tak lengkap salah diklasifikasi "akan diaktifkan"

Changed:

- `app/Services/ZteTr069BulkService.php` — tambah `readPortConfigs()` (baca per-port dipecah chunk `READ_CHUNK=40` + retry baca-satuan untuk ONU yang blok management-nya hilang) dan `mgmtRead()` (deteksi blok `show onu running config`/`pon-onu-mng` benar-benar terbaca). `run()` kini: ONU yang blok management-nya hilang ditandai **`failed`** ("baca tak lengkap, coba pindai ulang") — BUKAN "would-apply" — sehingga tak akan ditulisi TR069.
- `app/Jobs/Tr069BulkConfigJob.php` — timeout 3600s → **7200s** (retry baca menambah durasi run penuh).
- `tests/Feature/SmartOltTr069BulkTest.php` — fake executor dapat parameter `incompleteOnuIds` (simulasi blok management hilang) + test baru `test_incomplete_read_is_marked_failed_not_applied`.

Notes:

- **Diverifikasi langsung di C300 nyata (OLT id=2, OLT-C300-SEKARJALAK, 2136 ONU)**: parser & aturan skip BENAR (ONU aktif terdeteksi `tr069=1`, url+user cocok). Sampel port 2/1 = 23/26 aktif, port 2/3 = 108/120 aktif (~89%), tapi dry-run penuh user cuma melaporkan 409 aktif / 1727 "akan diaktifkan" (19%).
- **Akar masalah**: saat run penuh 48 port (~90 menit, kemungkinan bentrok telnet dgn RX-poll), banyak sesi baca terdegradasi → blok `show onu running config` (tempat baris `tr069`) hilang, tapi blok interface masih kebaca → lama-nya `ok=true` (`looksConfigured` cukup dari name/tcont) → tr069 dikira mati → ONU yang sebetulnya sudah aktif salah masuk "akan diaktifkan". 1727 itu membengkak palsu.
- **Diskriminator**: ONU yang memang belum-TR069 tetap punya blok management keparse (`pon-onu-mng`/`service`/`wan-ip`); baca terpotong kehilangan blok itu. `mgmtRead()` membedakan keduanya.
- **Deploy fix ini**: cukup pull code + `php artisan queue:restart` (worker muat ulang service/job). TIDAK perlu migrate / config:cache / rebuild (tak ada perubahan skema/config/frontend). Setelah itu **pindai ulang** dry-run — angka mestinya membalik mendekati ~1900 aktif / ~200 akan-diaktifkan.

## 2026-06-26

### Inventory ONU C-Data GPON V3 via SNMP penuh (CLI jadi enrichment)

Created:

- `docs/handbook/17-cdata-gpon-snmp-walk.md` — peta SNMP walk FD1608S (id=277) + cara driver baca inventory via SNMP; ditautkan di `docs/handbook/README.md`.

Changed:

- `app/Services/CData/CDataValue.php` — tambah `parseGponOnuName()` (parse `"gpon F/S/P onu N <label>"` dari tabel legacy 17409) dan `gponRxDbm()` (string dBm → float, buang `--`/garbage di luar jendela `[-60,5]`).
- `app/Services/CData/CDataGponSnmpService.php` — jalur V3 `getRegisteredOnus()` dibalik jadi **SNMP-first**: `snmpOnus()` membaca inventory penuh dari tabel nama legacy `17409.2.8.4.1.1.2` (master, beri slot/port/onuId+label) di-join MAC `17409.2.3.4.7.1.3` + status/Rx `34592…21.1.1.{2,3,5}` lewat onuIndex global. CLI (`show ont info all`) kini hanya **enrich** SN/admin/last-down + Rx andal via `mergeCliDetail()` (best-effort, gagal CLI tak menggugurkan inventory SNMP). `getPortRxMap()` kini balikkan Rx SNMP yang terisi. Buang `v3Onus()`/`gponIfMap()`/konstanta `V3_NAME`/`V3_DESC` (tabel `.18.12` cuma ~2 baris, tak dipakai).
- `tests/Unit/CDataValueTest.php`, `tests/Unit/CDataSnmpDriverTest.php` — test parser baru + test V3 ganti ke jalur SNMP penuh (online/offline, MAC, Rx, SN=null tanpa CLI).

Notes:

- **Koreksi asumsi lama** "FD1608S V3 SNMP cuma baca 1 ONU → inventory wajib CLI". Yang 1–2 baris hanya tabel atribut `34592…18.12`. Tabel legacy 17409 + optik `34592…21` membaca **34/34 ONU**.
- **Diverifikasi langsung ke OLT nyata #277** (`172.27.10.105`): jalur SNMP murni = 34 ONU, ~230 ms (online 30 / offline 4, 33 MAC). Jalur SNMP+enrich CLI = 34 ONU, ~900 ms, lengkap **SN (CLI) + MAC (SNMP, yang CLI tak punya) + Rx andal (CLI)**. Sebelumnya CLI-only tak punya MAC; fallback SNMP lama cuma ~2 ONU.
- **Rx per-ONU via SNMP tidak andal** (`34592…21.1.1.5` umumnya `--`, kadang nilai positif garbage) → CLI tetap sumber Rx utama; SNMP Rx hanya opportunistik.
- Unit test C-Data 12/12 PASS. Catatan: 6 Feature test C-Data (`CDataOltWriteTest`/`CDataOltInventoryTest`/`OltPollingTest`/`TelegramWebhookTest`) gagal **HTTP 419 (CSRF/Page Expired)** — **pre-existing** (terbukti gagal sama di clean main saat perubahan di-stash), tidak terkait perubahan read-only ini.
- **Deploy** (diedit langsung di server prod ini, bukan git pull): perubahan `.php` terbaca opcache otomatis (~2 dtk; reload `php8.3-fpm` opsional). Tak perlu migrate/config:cache/rebuild. Setelah itu **Refresh** OLT C-Data GPON akan mengisi cache `port_onus` (kini termasuk MAC) jauh lebih cepat & andal. Sinkron ke GitHub = push (`/done`).

### Fix C-Data EPON — serial ONU tampil sama dengan MAC

Changed:

- `app/Services/CData/CDataEponSnmpService.php` — ganti `normalizeSerial()` → `eponSerial($raw, $mac)`. Firmware C-Data EPON menaruh **MAC di kolom serial `.28`** (terverifikasi `.28 == .7` untuk **258/258** ONU live #276), jadi `serial_number` jadi MAC → di UI "Serial / MAC" nilainya kembar. Sekarang serial = **null** bila nilainya MAC ONU itu sendiri (ONU EPON identitasnya memang MAC, tak punya serial GPON-style); hanya dipertahankan bila benar-benar serial alfanumerik berbeda. Hapus fallback `?? $mac`.
- `app/Services/OnuInventoryService.php` — `normalize()` kini ikut bawa `mac` (sebelumnya tak ada) supaya MAC tersedia di ONU Monitoring lintas-OLT & search.
- `app/Http/Controllers/DashboardSearchController.php` — global search (⌘K) kini ikut cocokkan **MAC** + label fallback ke MAC, agar ONU EPON tetap ketemu via MAC sesudah serial di-null-kan.
- `resources/js/Pages/CDataOlt/PortOnus.vue`, `resources/js/Pages/SmartOlt/OnuMonitor.vue` — sel "Serial / MAC" tampilkan `serial || mac` (MAC sekali sebagai identitas EPON), baris MAC abu-abu hanya saat ada serial terpisah (GPON). OnuMonitor: haystack search + label "Serial / MAC".
- `tests/Unit/CDataSnmpDriverTest.php` — test baru `test_epon_serial_equal_to_mac_is_dropped`.

Notes:

- **Diverifikasi langsung ke OLT EPON nyata #276** (`172.27.10.103`, OLT-EPON-CDATA-TAYU): 258 ONU, `serial==mac` lama = **0** (sebelumnya 258), `serial=null` = 258, MAC utuh 258. GPON tak terdampak (punya serial sungguhan).
- **Akar masalah**: OID serial EPON `17409.2.3.4.1.1.28` di firmware ini mengembalikan Hex-STRING MAC yang sama persis dgn kolom MAC `.7`. Bukan bug parsing — memang firmware tak punya serial terpisah utk EPON.
- **Deploy** (kode diedit langsung di server prod ini — `/var/www/KusumaVisionNMS` dilayani nginx, daemon supervisor di sini; **bukan** edit-di-dev lalu git pull): perubahan `.php` otomatis terbaca opcache (`validate_timestamps=On`, `revalidate_freq=2`, ~2 dtk) — reload `php8.3-fpm` opsional bila ingin instan; `npm run build` untuk perubahan Vue (sudah dijalankan); `queue:restart` tak wajib (baca EPON on-demand via web). Tak perlu migrate/config:cache. Lalu **Refresh** tiap OLT C-Data EPON agar cache `port_onus` lama (serial==mac) ter-tulis ulang dgn serial=null. Sinkron ke GitHub = **push** (`/done`), bukan pull.

### Scheduled polling untuk OLT C-Data (sebelumnya di-skip)

Changed:

- `app/Jobs/PollOltJob.php` — C-Data tak lagi early-return. Branch family C-Data memanggil `pollCData()` baru: scan penuh via `CDataOltScanner` (driver EPON SNMP / GPON V3 SNMP+CLI menulis `last_test_result.port_onus`), lalu samakan housekeeping ZTE — set penanda `ok`/`error`/`poller='cdata'` top-level (dibutuhkan `AlarmEvaluator`; scanner tak menyetelnya), catat `onu_rx_samples` saat RX due (reuse `recordRxSamples`), evaluasi alarm, log `PollingEvent` (OLT_POLL + RX_POLL). `handle()` dapat param ke-4 `?CDataOltScanner $cdataScanner` (di-autowire queue, di-override di test).
- `app/Console/Commands/PollOltsCommand.php` — buang skip C-Data; kini dispatch SEMUA OLT `polling_enabled` yang due (ZTE & C-Data). Hapus import `SmartOltSupport` yang jadi tak terpakai.
- `tests/Feature/OltPollingTest.php` — dua test skip dibalik jadi positif (`test_poll_command_dispatches_cdata_olts_when_enabled`, `test_poll_job_polls_cdata_olt_via_scanner`) + helper `fakeCDataScanner()`.
- `CLAUDE.md` — dokumentasi arsitektur polling C-Data.

Notes:

- **Diverifikasi live di OLT EPON nyata #279** (OLT-EPON-CDATA-KELING, `172.27.10.112`): poll via jalur job = 253 ONU, **845 ms**, `ok=true poller=cdata`, `last_polled_at`+`last_rx_polled_at` ter-set, **232 rx samples** terekam, PollingEvent ter-log. Beberapa OLT EPON (#279/280/281/282) sudah `polling_enabled=Y` — selama ini di-skip, sekarang jalan. GPON #277 `polling_enabled=N` (scan via telnet ~ butuh diaktifkan manual bila mau).
- **`CDataOltScanner` tak set `ok` top-level** → `AlarmEvaluator` (yg pakai `snapshot['ok']` utk deteksi OLT-unreachable) butuh itu; di-set di `pollCData` (true saat scan sukses, false+error saat gagal). RX C-Data ikut tiap scan (EPON SNMP / GPON CLI), jadi sampel dicatat per-cadence RX (`isRxPollDue`).
- ⚠️ **Gotcha test→pgsql (hampir mewipe prod)**: `php artisan test` dgn config ter-cache menyambar pgsql produksi → `OltPollingTest` HANG ~90s (RefreshDatabase `migrate:fresh` di DB prod) & `assertDatabaseCount` lihat 5.9 jt baris asli. **Produksi terverifikasi utuh** (9 OLT, users, 5.9M rx samples — RefreshDatabase pakai transaksi). Pola aman: `php artisan config:clear` → test (sqlite `:memory:`) → `php artisan config:cache`. Semua 13 test polling PASS di sqlite. Lihat memori `prod-deploy-gotchas`.
- **Deploy fix ini**: karena `PollOltJob` jalan di dalam **queue worker** (long-lived), **wajib `php artisan queue:restart`** agar worker memuat kode baru (opcache web tak berlaku utk daemon). Tak perlu migrate/config:cache/rebuild.

### Munculkan kembali kontrol Auto-Poll di UI OLT C-Data

Saat C-Data masih di-skip dari polling, kontrol auto-poll dihapus dari form & tabel C-Data. Sekarang polling sudah aktif → dimunculkan lagi (backend `CDataOltController` sudah lama menerima field ini).

Changed:

- `resources/js/Pages/CDataOlt/Partials/CDataOltForm.vue` — tambah section **"Auto-Poll SNMP"** (checkbox `polling_enabled` + `poll_interval_minutes` + `rx_poll_interval_minutes`), meniru `SmartOlt/Partials/OltForm.vue`. Field ditambah ke `useForm` (default enabled=true, interval 5m). Catatan kecil: GPON V3 pakai telnet saat scan, interval terlalu pendek bisa membebani OLT.
- `resources/js/Pages/CDataOlt/Index.vue` — indikator **"Auto-poll: On · {interval}m / Off"** (titik hijau/abu) di sel Family (desktop) + field mobile, sama gaya tabel ZTE.

Notes:

- Tak ada perubahan backend — validasi & fillable `polling_enabled`/`poll_interval_minutes`/`rx_poll_interval_minutes` sudah ada di `CDataOltController::validated()` & model `SnmpOlt`. Edit form pre-fill dari `serializeOlt`.
- **Deploy**: hanya `npm run build` (perubahan Vue saja; sudah dijalankan). Tak perlu queue:restart/migrate/config:cache.

### TR069 Massal dipindah dari per-OLT ke per-port

Sebelumnya tombol "TR069 Massal" ada di halaman GPON Port dan menyapu **semua ONU satu OLT**. Atas permintaan user, fitur dipindah jadi **per PON port** (lebih aman & terarah, tak ada aksi sapu-seluruh-OLT). Engine baca/skip/tulis tidak berubah — hanya di-scope ke satu port.

Created:

- `database/migrations/2026_06_26_120000_add_port_scope_to_tr069_bulk_tasks_table.php` — kolom `slot`/`port` nullable di `tr069_bulk_tasks` (null = baris task lama bergaya seluruh-OLT). sqlite-compatible.

Changed:

- `app/Services/ZteTr069BulkService.php` — `run()`, `cachedOnuCount()`, `portsFromCache()` terima `?int $onlySlot`/`?int $onlyPort` (null = seluruh OLT). Filter port di `portsFromCache`. Docblock disesuaikan.
- `app/Http/Controllers/SmartOltController.php` — `tr069Bulk()` kini terima `int $slot, int $port`, simpan ke task + hitung total per-port (`cachedOnuCount($olt, $slot, $port)`).
- `app/Jobs/Tr069BulkConfigJob.php` — teruskan `$task->slot`/`$task->port` ke `service->run()`. Docblock per-port.
- `app/Models/Tr069BulkTask.php` — `slot`/`port` di fillable + cast integer + ikut `progressPayload()`.
- `routes/web.php` — route POST jadi `…/ports/{slot}/{port}/tr069-bulk` (nama `smartolt.tr069-bulk` tetap; status route tak berubah).
- `resources/js/Components/SmartOlt/Tr069BulkModal.vue` — prop `slot`/`port` (required), teks "semua ONU port X/Y", POST ke route per-port, pesan "Refresh ONU di halaman ini".
- `resources/js/Pages/SmartOlt/PortOnus.vue` — tombol "TR069 Massal" baru di header (gated `supports_cli_onu_configure`) + render `Tr069BulkModal` dengan slot/port aktif. Import `Cloud` + komponen modal.
- `resources/js/Pages/SmartOlt/GponPorts.vue` — hapus tombol/modal/refs/import TR069 massal (Cloud, canTr069, tr069ModalOpen, Tr069BulkModal).
- `tests/Feature/SmartOltTr069BulkTest.php` — test endpoint diubah ke per-port (route dgn slot/port, total=2, assert slot/port task), `makeTask()` terima slot/port opsional, + test baru `test_run_scoped_to_single_port_ignores_other_ports`.
- `CLAUDE.md` — paragraf TR069 massal diupdate jadi per-port.

Notes:

- Engine internal tetap mendukung scope null (seluruh OLT) untuk kompatibilitas baris task lama; controller sekarang selalu mengisi slot/port. Verifikasi: `SmartOltTr069BulkTest` **6 passed** (di sqlite via `config:clear`), Pint passed, `npm run build` sukses.
- **Deploy box ini**: migrasi sudah `php artisan migrate --force` (pgsql, tambah kolom nullable — aman), `config:cache` dikembalikan (sempat di-clear untuk test), `queue:restart` dijalankan (kode `Tr069BulkConfigJob`/`ZteTr069BulkService` berubah → worker long-lived harus muat ulang). Route tak ter-cache (cek `bootstrap/cache` hanya `config.php`).

### Polish UI/UX — toast flash terpusat, reduced-motion, tabular-nums, dedup kartu statistik

Hasil review UI/UX (skill ui-ux-pro-max). Empat pembenahan "quick win" berdampak besar, risiko kecil,
tanpa mengubah alur. Net −130 baris markup duplikat. `npm run build` hijau.

Created:

- `resources/js/Components/Shell/FlashMessages.vue` — toast terpusat untuk flash `success`/`error`
  Inertia. Non-blocking, auto-dismiss (sukses 5s, error 8s), bisa ditutup manual, dan **diumumkan ke
  screen reader** (`aria-live="polite"`; error pakai `role="alert"`). Membaca `page.props.flash` saat
  initial load + tiap `router.on('success')`. Di-mount sekali di `AuthenticatedLayout` (offset di bawah
  bilah atas: `top-16 sm:top-20`).
  - **Fix toast dobel**: layout non-persistent → instance baru tiap visit memanggil `pump()` di
    `onMounted` SEKALIGUS listener `router.on('success')`-nya ikut menyala untuk visit yang sama.
    Guard modul-level `lastFlashSeen` (identitas objek `page.props.flash`, stabil per visit)
    memastikan satu objek flash hanya ditoast sekali.

Changed:

- `resources/js/Layouts/AuthenticatedLayout.vue` — pasang `<FlashMessages />` (sekali, global).
- **17 halaman** — cabut blok flash inline yang diduplikasi (4 varian markup berbeda + sudah drift
  `bg-…/10` vs `/15`): CDataOlt {Detail,Index,PortOnus}, Map/Index, Settings/Index, Users/Index, dan
  SmartOlt {ConfigureOnu,Detail,GponPorts,Index,OnuMonitor,PortDetail,PortOnus,Profiles,Registrations,
  Unconfigured,UnconfiguredGlobal}. (Computed `flash` dibiarkan — inert, aman.)
- `resources/css/app.css` —
  - `@media (prefers-reduced-motion: reduce)` kini juga mematikan geser transisi halaman
    (`.page-enter/leave`). Partikel & aurora sudah dihormati di komponennya masing-masing.
  - Tambah kelas `.kv-stat` (surface kartu statistik ringkas) untuk dedup.
  - `.kv-mobile-value` dapat `tabular-nums` (angka data sejajar di kartu mobile).
- `resources/js/Components/Dashboard/StatCard.vue` — angka utama pakai `tabular-nums`.
- `resources/js/Pages/SmartOlt/{PortOnus,OnuMonitor}.vue` — tabel ONU pakai `tabular-nums`.
- `resources/js/Pages/SmartOlt/{Detail,GponPorts,OnuMonitor,PortOnus,Unconfigured,UnconfiguredGlobal}.vue`
  — surface kartu statistik inline (`rounded-lg … shadow-sm`) diganti kelas `.kv-stat` (21 occurrence).

Notes:

- **Sticky table header sengaja DITUNDA**: tabel berada di wrapper `overflow-x-auto` yang memaksa
  `overflow-y:auto`, membuat `position: sticky` jadi no-op kecuali tinggi tabel dibatasi — yang
  memunculkan scroll bersarang (justru dilarang pedoman skill `scroll-behavior`). Butuh keputusan
  scroll-region tersendiri.
- Kandidat lanjutan dari review (belum dikerjakan): skeleton loader saat Scan/Refresh SNMP lambat,
  paginasi/virtualisasi `OnuMonitor` (bisa 1000+ ONU), `aria-label`+autofocus pada `Modal`,
  standarisasi `FilterCard` di `PortOnus`, naikkan kontras teks sekunder `slate-500`→`slate-400`.
- Belum diverifikasi di browser/OLT live; verifikasi via `npm run build` (sukses).

### Skeleton loader + paginasi sisi-klien untuk daftar ONU

Tindak lanjut review: dua daftar ONU terbesar (`OnuMonitor` lintas-OLT & `PortOnus`) tadinya
me-render SEMUA baris (bisa 1000+ ONU) dan tak ada umpan-balik saat scan/refresh SNMP yang lambat.

Created:

- `resources/js/Composables/usePagination.js` — paginasi sisi-klien (tanpa request server) atas array
  yang sudah terfilter: `page`, `pageSize`, `pageCount`, `pageItems`, `rangeStart/End`, `next/prev`.
  Auto reset ke hal. 1 saat sumber/filter berubah & jaga page tetap valid saat data menyusut.
- `resources/js/Components/Shell/ClientPagination.vue` — kontrol paginasi responsif (info "X–Y dari Z",
  pemilih item/halaman 25/50/100 di desktop, tombol prev/next target sentuh ≥44px, `tabular-nums`).
- `resources/js/Components/Shell/ListSkeleton.vue` — placeholder shimmer meniru `kv-mobile-list` +
  `kv-table-desktop`; `animate-pulse` otomatis diam saat `prefers-reduced-motion`.

Changed:

- `resources/css/app.css` — `@media (prefers-reduced-motion: reduce)` kini juga mematikan
  `.animate-pulse` & `.animate-spin`.
- `resources/js/Pages/SmartOlt/OnuMonitor.vue` — `<ListSkeleton v-if="scanning">` selama Scan SNMP
  penuh; tabel & kartu mobile render `pagedOnus` (default 50/hal) + `<ClientPagination>` di kaki kartu.
- `resources/js/Pages/SmartOlt/PortOnus.vue` — sama: flag `refreshing` baru → skeleton saat Refresh ONU;
  `pagedOnus` + `<ClientPagination>`. Fitur lompat-ke-ONU (`?focus=`) kini **lompat ke halaman** yang
  memuat ONU itu dulu sebelum scroll (regresi paginasi ditangani).

Notes:

- Paginasi sisi-klien dipilih (bukan virtual scroll / server paginate) karena data ONU sudah dimuat
  penuh dari cache `port_onus` ke props — nol perubahan backend, nol risiko. Pola konsisten dgn
  Alarms/AuditLogs yang sudah paginated (itu server-side).
- "Pilih semua" di `PortOnus` tetap menyeleksi **seluruh hasil filter** (lintas halaman), bukan hanya
  halaman aktif — sesuai ekspektasi aksi massal.
- Belum diverifikasi di browser/OLT live; `npm run build` sukses. Kandidat lanjut: terapkan pola sama
  ke `CDataOlt/PortOnus.vue`.

### Panel SISTEM — monitor kesehatan server (CPU/RAM/disk)

Panel "SISTEM" di kaki sidebar sebelumnya hanya menampilkan Versi/Waktu/Uptime/Online. Ditambah
**metrik kesehatan server** agar resource ikut termonitor sekilas tanpa buka tool lain.

Changed:

- `app/Http/Middleware/HandleInertiaRequests.php` — `systemInfoPayload()` kini menyertakan `health`:
  - **CPU**: load average 1-menit (`sys_getloadavg`) dinormalkan jumlah core (hitung `^processor:` di
    `/proc/cpuinfo`) → persen (cap 100) + angka load + cores.
  - **RAM**: `/proc/meminfo` (`MemTotal` − `MemAvailable`) → persen + used/total (human-readable).
  - **Disk**: `disk_total_space`/`disk_free_space` di `base_path()` → persen + used/total.
  - Helper `serverHealth()` (cache 5s, hindari baca /proc tiap request), `cpuHealth/memoryHealth/`
    `diskHealth`, `cpuCores`, `humanBytes`. Tiap metrik **null bila tak terbaca** (non-Linux) → UI sembunyi.
- `resources/js/Components/Shell/SystemInfoPanel.vue` — render CPU/RAM/Disk sebagai **bar progress
  berwarna** (hijau <70% · amber 70–89% · merah ≥90%) + ikon Lucide (Cpu/MemoryStick/HardDrive) +
  baris detail (load·core / used·total). **Auto-refresh ringan tiap 20s** via
  `router.reload({ only: ['systemInfo'], preserveScroll, preserveState })` — partial visit, jam tetap
  jalan, tak memicu toast (flash tak ikut terkirim di partial reload).

Notes:

- Diverifikasi langsung di server ini: CPU load1=4.36/4core, RAM 2.6/8.0 GB (33%), Disk 12/31 GB (39%).
- CPU pakai **load average**, bukan %util sesaat (yang butuh 2 sampel /proc/stat berjarak → menambah
  latensi tiap request). Load average standar untuk panel kesehatan & cukup informatif.
- **Deploy box ini**: PHP terbaca opcache otomatis (~2 dtk), frontend sudah `npm run build`. Tak perlu
  migrate/config:cache/queue:restart (middleware jalan di request web, bukan daemon).

### Tweak — highlight nav saat sidebar collapse jadi kotak terpusat

Changed:

- `resources/js/Layouts/AuthenticatedLayout.vue` — kelas link nav dibuat kondisional: collapsed pakai
  `mx-auto h-11 w-11 justify-center` (tile kotak 44×44 terpusat di ikon) ganti `px-3 py-2.5` full-width
  yang dulu bikin highlight aktif jadi pill lebar & ikon menempel kiri. Expanded tak berubah.

## 2026-06-27

### Report — gabung Inventaris ONU + RX Power jadi satu, sembunyikan rentang hari

Atas permintaan user di halaman Report: dua jenis laporan terpisah ("Inventaris ONU" dan "RX Power
ONU") disatukan menjadi satu laporan, dan filter rentang hari dihilangkan untuk laporan yang
mencerminkan state cache "saat ini".

Changed:

- `app/Services/Report/ReportService.php` — type `rx` dihapus dari `TYPES`/`build()`/`title()`/
  `typeOptions()`; method `rxPower()` dibuang dan logikanya digabung ke `onuInventory()`. Laporan `onu`
  kini berjudul "Laporan Inventaris & RX Power ONU" dengan kolom baru **RX Power** (nilai dBm atau `-`
  bila tak ada pembacaan) plus field per-baris `rx_level` (normal/warning/critical, untuk pewarnaan,
  bukan kolom tampil). Filter redaman (`rx_status`) & ringkasan kini ikut: kartu jadi `Total ONU ·
  Online · Offline · RX Warning (< -25) · RX Critical (< -28)`. Ringkasan tetap hitung seluruh dataset
  meski baris dipersempit filter (perilaku konsisten dgn filter ONU monitoring). `applyStatusFilter`
  buang early-return `rx`.
- `resources/js/Pages/Reports/Index.vue` — dropdown **rentang hari** kini `v-if` hanya untuk type
  `alarm`/`provisioning` (Inventaris ONU & Status OLT baca cache "saat ini", rentang tak relevan).
  Filter **Redaman RX** dipindah dari type `rx` → `onu`. Helper `rxClass()` mewarnai sel RX Power
  (merah critical · amber warning · hijau normal) di tabel desktop & kartu mobile. `queryParams`
  menyesuaikan: `rx_status` ikut saat type `onu`.
- `tests/Feature/ReportTest.php` — test `rx` diganti `test_onu_report_flags_rx_critical`
  (`summary.4.value` = 1 critical) + test baru `test_onu_report_filters_by_redaman` (filter
  `rx_status=critical` → 1 baris, ringkasan tetap penuh).

Notes:

- **Rentang hari disembunyikan, bukan dihapus dari kode** — laporan Alarm & Provisioning masih butuh
  filter waktu (`startFor()`), jadi dropdown tetap muncul untuk keduanya. Inventaris ONU & Status OLT
  selalu baca `last_test_result` (state cache terkini) sehingga rentang memang tak berpengaruh.
- CSV/PDF otomatis ikut kolom baru (keduanya column-driven, tak perlu diubah).
- Verifikasi: `ReportTest` **6 passed**, Pint passed, `npm run build` sukses.
- ⚠️ **Gotcha test→pgsql lagi**: `php artisan test` dgn config ter-cache menyambar PostgreSQL prod
  (3762 baris vs ekspektasi 2). Pola aman dipakai: `config:clear` → test (sqlite) → `config:cache`.
  Lihat memori `prod-deploy-gotchas`.
- **Deploy box ini**: `.php` terbaca opcache otomatis (~2 dtk), Vue sudah `npm run build`, `config:cache`
  sudah dikembalikan. Tak perlu migrate/queue:restart (laporan dirender di request web).

## 2026-07-06

### Fix flapping port HiOSO (baris RX absen ≠ ONU offline)

Changed:

- `app/Services/Hioso/HiosoEponSnmpService.php` — `rxMap()` diganti `rxScan()` yang memisahkan
  `seen` (setiap baris RX yang MUNCUL di walk, apa pun nilainya) dari `valid` (dBm sah). Di
  `getRegisteredOnus()`: baris RX `na`/`0` yang **hadir** tetap offline (benar), tapi baris yang
  **absen** dari walk (link lossy memotong walk, bahkan setelah `robustWalk`) tak lagi dianggap
  offline — status terakhir dipertahankan via `previousOnuState()` (baca `last_test_result.port_onus`
  poll sebelumnya), RX carry-forward ditandai sumber `snmp_stale`. `getPortRxMap()` kini pakai
  `rxScan()['valid']`.
- `app/Jobs/PollOltJob.php` — `recordRxSamples()` melewati RX bersumber `snmp_stale` (carry-forward)
  agar time-series RX tak terisi titik palsu berulang.
- `tests/Unit/HiosoSnmpDriverTest.php` — +2 test regresi: baris RX absen → status terakhir
  dipertahankan (bukan offline, sumber `snmp_stale`); baris `na` yang hadir → tetap offline (pembeda,
  supaya fix tak menutupi ONU yang benar-benar mati).

Notes:

- **Akar masalah OLT-HIOSO-PATI (id 411) port 3**: HiOSO tak punya OID status ONU → "online"
  diturunkan dari ada/tidaknya bacaan RX; scanner menurunkan status port dari jumlah ONU online.
  Port 3 hanya 1 ONU (MAC `D0:5F:AF:84:99:4E` "Madun", RX -17.21 dBm, sehat) → satu bacaan RX meleset
  = seluruh port "down". Link WAN lossy membuat walk tabel RX sesekali terpotong sebelum sampai ke ONU
  itu; kode lama menyamakan "baris RX absen" dengan "offline". Bukti produksi: **39 episode**
  `port:1/3:port_down`, tiap episode ~6 menit (1 siklus refresh RX) lalu clear sendiri, berbarengan
  `onu_offline` ONU sehat tsb.
- Kunci pembeda dari guide: **ONU offline HiOSO tetap melapor `na`** di tabel RX (baris tetap ada),
  jadi baris yang benar-benar absen = walk tak sampai, bukan bukti ONU mati. `robustWalk` tetap
  mengulang sampai baris tiap ONU (termasuk `na`) terbaca; fallback carry-forward hanya dipakai saat
  walk gagal total sampai ke ONU itu → deteksi outage nyata tetap terjaga.
- Verifikasi: `HiosoSnmpDriverTest` **5 passed**; suite terkait (`Hioso|Alarm|Poll|CData`) **75 passed**
  (480 assertions); Pint bersih; 0 alarm aktif tersisa di OLT 411.
- **Deploy**: perubahan menyentuh service/job yang dijalankan worker `kusumavision-worker` →
  `php artisan queue:restart` agar worker memuat kode baru (fix belum aktif tanpa restart).

### Bot Telegram: tombol Reboot ONU di layar detail ONU

Changed:

- `app/Services/Telegram/TelegramKeyboard.php` — builder callback baru `onuReboot()` (`rb:`) dan
  `onuRebootExecute()` (`rbx:`), argumen identik `onuDetail()` (olt/slot/port/onu/src/scope/page)
  supaya layar konfirmasi bisa "Batal" kembali ke detail yang sama; tetap <64 byte.
- `app/Services/Telegram/TelegramCommandHandler.php` — layar detail ONU (navigasi menu, hasil
  /search tunggal, detail dari daftar hasil search) kini menampilkan tombol "🔄 Reboot ONU" bila
  driver OLT `supports_reboot` (`onuActionRows()` + `supportsReboot()`/`oltDriver()`). Alur dua
  langkah: `rb:` = layar konfirmasi (✅ Ya, Reboot Sekarang / ❌ Batal — tap nyasar tak langsung
  me-restart pelanggan), `rbx:` = eksekusi, cermin `OnuMapController::rebootPin`: ZTE via
  `ZteRemoteOnuService`, C-Data via `CDataCliWriteService` (iface epon/gpon dari driver), HiOSO via
  `HiosoCliWriteService`; hasil (sukses/error/exception) dilaporkan + tombol kembali ke detail.
  /help ditambah baris cara reboot; docblock kelas diperbarui (tak lagi murni read-only).
- `tests/Feature/TelegramWebhookTest.php` — +4 test: detail ONU menawarkan tombol `rb:`; callback
  `rb:` menampilkan konfirmasi TANPA mengeksekusi (mock `shouldNotReceive`); `rbx:` memanggil
  reboot ZTE sekali dengan slot/port/onu benar dan melaporkan sukses; OLT driver unknown → tanpa
  tombol dan `rbx:` paksa ditolak "tidak didukung".
- `tests/Unit/TelegramKeyboardTest.php` — builder `rb:`/`rbx:` mirror konteks `u:` + cek batas 64 byte.
- `docs/handbook/10-alarm-telegram.md` — blok "Reboot ONU dari bot"; klaim "/refresh satu-satunya
  non-read-only" dikoreksi jadi dua aksi.

Notes:

- Callback reboot hanya memuat argumen numerik → dari detail hasil pencarian (token cache), konteks
  token tidak terbawa; back setelah reboot jatuh ke Menu (`SRC_MENU`). Trade-off diterima demi skema
  callback tetap sederhana.
- Eksekusi sinkron di request webhook (telnet beberapa detik) — konsisten dengan /refresh yang sudah
  sinkron; gerbang keamanan = allow-list chat (dicek ulang di `handleCallback`) + konfirmasi 2 langkah
  + gating `supports_reboot` per driver.
- Verifikasi: suite penuh **252 passed** (1383 assertions), Pint bersih.
- **Deploy**: perubahan di service yang dipakai request web (webhook) → cukup opcache (php-fpm);
  tidak menyentuh worker/scheduler.

### Bot Telegram: perintah /uncfg — ONU ZTE belum dikonfigurasi, live dari CLI

Created:

- `app/Services/ZteUncfgOnuService.php` — service discovery ONU uncfg ZTE LANGSUNG dari CLI
  (`terminal length 0` + `show gpon onu uncfg` via `ZteCliProvisioningExecutor`), sengaja bukan
  dari cache polling/SNMP. Parser regex fleksibel (`gpon[-_]onu[-_]r/s/p[:seq]  SN  state`),
  skip echo perintah, dedup per-SN, urut slot/port.
- `tests/Unit/ZteUncfgOnuServiceTest.php` — 4 test parser memakai output CLI **nyata** dari
  OLT-C320-PATI (parse baris data, tabel kosong, dedup+sort, propagasi error CLI).

Changed:

- `app/Services/Telegram/TelegramKeyboard.php` — builder callback `uncfg()` (`uc:{scope}`,
  scope 0 = semua OLT ZTE) untuk tombol "🔄 Cek Ulang".
- `app/Services/Telegram/TelegramCommandHandler.php` — command baru `/uncfg` (alias
  `/unconfigured`) `[nama|id OLT]` + callback `uc:` → `uncfgScreen()`: filter OLT ber-driver ZTE
  (via `oltDriver()`), panggil `ZteUncfgOnuService::fetch()` per OLT (sinkron, telnet beberapa
  detik seperti /refresh), render per-OLT: daftar SN + PON slot/port + state (cap 15 ONU/OLT),
  ✅ bila kosong, ❌ + pesan bila CLI gagal/kredensial kosong (exception per-OLT ditangkap,
  OLT lain tetap dilaporkan). /help + docblock kelas diperbarui.
- `tests/Feature/TelegramWebhookTest.php` — +3 test: `/uncfg` menampilkan SN live dan TIDAK
  menampilkan ONU dari cache; tanpa OLT ZTE → "Belum ada OLT ZTE" tanpa memanggil service;
  callback `uc:{id}` re-run dan melaporkan error CLI.
- `docs/handbook/10-alarm-telegram.md` — `/uncfg` masuk daftar command + blok "Aksi di luar cache".

Notes:

- **Verifikasi OLT nyata (id=1, OLT-C320-PATI)**: `show gpon onu uncfg` live menghasilkan
  `gpon-onu_1/2/2:1  ZTEGCD7D2FD6  unknown`; `ZteUncfgOnuService::fetch()` end-to-end via tinker
  mem-parse persis → `{interface, slot 2, port 2, seq 1, SN, state unknown}`. Fixture unit test
  memakai output capture ini.
- Ini jalur read-only (perintah `show`) meski lewat telnet; berbeda dari halaman web
  `smartolt.unconfigured` yang berbasis SNMP walk + cache `last_test_result.unconfigured_onus` —
  bot tidak menulis cache sama sekali.
- Verifikasi: suite penuh **259 passed** (1403 assertions), Pint bersih.
- **Deploy**: hanya jalur request web (webhook) → cukup opcache php-fpm, tanpa restart worker.

## Aplikasi Android (Flutter) + ekstensi REST API v1 + FCM — 2026-07-06

Aplikasi Android pendamping di `mobile/` (Flutter 3.44, Riverpod v2, dio, go_router,
Material 3 dark-glass cyan) plus toolchain build di server & perluasan REST API v1
(baca + tulis) dan push notifikasi FCM. Referensi desain: NOC dashboard dark glassmorphism.

Created:

- **Toolchain server** (`/opt`): OpenJDK 17, Android SDK (cmdline-tools, platform-tools,
  platforms;android-35/36, build-tools;35/36), Flutter stable 3.44.4, `/etc/profile.d/flutter.sh`.
  `bin/build-apk.sh` (build+analyze+salin ke `public/downloads/kusumavision-nms.apk`).
- **API baca**: `app/Services/GlobalSearchService.php` (dipakai bersama web `DashboardSearchController`
  + `Api/V1/SearchController`), `Api/V1/{UnconfiguredOnuController,OnuRegistrationController}`,
  `OnuController@portIndex` (+ `OnuInventoryService::forPort`), `app/Services/Zte/OnuRegistrationFormDefaults.php`.
  `OltController@show` kini kirim `capabilities`. Throttle `throttle:api` (120/mnt) dipasang + login `10/1`.
- **API tulis** (grup `role:admin,operator` + `BlockDemoWrites`): `Api/V1/OnuActionController`
  (reboot/rename/refresh-port/refresh-unconfigured), `OnuRegistrationController@preview|store`,
  `app/Services/Zte/OnuRegistrationService.php` (build script → audit → optional execute).
- **FCM**: `fcm_device_tokens` (migration+model), `Api/V1/DeviceController` (POST/DELETE `/devices`),
  `app/Services/Fcm/FcmAlarmNotifier.php` + `app/Jobs/SendFcmAlarmNotifications.php` (kreait/laravel-firebase);
  hook di `AlarmEvaluator` di samping dispatch Telegram. `config/services.php` → `fcm` (dormant tanpa kredensial).
- **Flutter** `mobile/lib/`: auth (Sanctum+secure storage), dashboard, search, OLT list/detail,
  ONU per port/detail (RX berwarna), unconfigured (+discovery), alarm (filter severity), registrasi
  ONU (options→form→preview→eksekusi), aksi reboot/rename, FCM (channel `alarms`, deep-link tap).
  Shim ikon `core/icons.dart` (Material Icons; `lucide_icons` tak kompatibel IconData final).

Changed:

- `routes/api.php`: `$apiEnabled=true`, rute baca+tulis+devices, throttle.
- `DashboardSearchController` → delegasi `GlobalSearchService`. `docs/API.md` diperbarui (write + FCM).
- `mobile/android`: `applicationId net.kusumavision.nms`, minSdk 23, desugaring, google-services
  bersyarat (apply hanya bila `google-services.json` ada), signing key.properties opsional.
  `gradle.properties` dikonstrain server 8GB (heap 2g, daemon off, worker 2).

Notes:

- **APK release build sukses 54.2MB** (`flutter build apk --release`, dgn firebase deps + desugaring).
- **Verifikasi API live** (token nyata, OLT id=1 OLT-C320-PATI): olts/detail(+capabilities)/port-onus
  (RX -13.842 dBm, nama pelanggan)/unconfigured/search/register-options/**register preview** (script CLI
  ZTE valid dari profil nyata). Device register→delete→0 rows. Demo diblokir (403/404). Throttle header aktif.
- **Test**: suite penuh **275 passed**; +16 test API (`ApiV1ReadExtrasTest`, `ApiV1DeviceTest`,
  `ApiV1WriteTest`). `flutter analyze` bersih, unit test model lulus. Pint bersih.
- **Gotcha terkonfirmasi**: `php artisan config:cache` (prod) membuat test nyasar ke config prod
  (27 gagal) → `config:clear` sebelum test, `config:cache` sesudah. DB test ter-seed data demo →
  asersi registrasi pakai `registration_id`/`withoutGlobalScopes`, bukan `firstOrFail`.
- **Insiden**: build Gradle awal (`-Xmx8G` default template) memicu swap-thrash → guest reboot;
  diperbaiki dengan konstrain gradle.properties. Prod pulih penuh (php-fpm/nginx/postgres/redis active).
- **Deploy**: `migrate --force` (fcm_device_tokens), `config:cache` + reload php-fpm (rute API baru +
  config fcm), `queue:restart` (worker rujuk job FCM baru). API kini AKTIF (`$apiEnabled=true`).
- **FCM aktivasi**: taruh `mobile/android/app/google-services.json` + `storage/app/firebase/service-account.json`
  + `FIREBASE_CREDENTIALS` di `.env`, rebuild APK, `config:cache`+`queue:restart`. Tanpa itu app tetap jalan.

## 2026-07-07

### Halaman Akun mobile + pengaturan & kirim-manual notifikasi FCM dari web

Created:

- `app/Models/FcmSetting.php` — singleton pengaturan push mobile (enabled, min_severity,
  notify_on_raise/clear, notify_types) + default atribut (enabled/raise/major) agar aktif out-of-the-box.
- `database/migrations/2026_07_07_000000_create_fcm_settings_table.php` — tabel `fcm_settings`.
- `mobile/lib/features/account/account_screen.dart` — halaman Akun: info akun (nama/email/role/badge),
  info aplikasi (versi via package_info_plus), tombol **Tes Push Notifikasi**, tombol **Keluar**.
- `tests/Feature/SettingsFcmTest.php` — 5 test (default setting, update admin, non-admin 403,
  kirim manual tanpa device → error, validasi title/body).

Changed:

- `mobile/android/app/build.gradle.kts` — **fix crash**: `namespace` dikembalikan ke
  `net.kusumavision.kusumavision_nms` (cocok package `MainActivity.kt`) sedangkan `applicationId`
  tetap `net.kusumavision.nms`. Sebelumnya mismatch → `.MainActivity` me-resolve ke class tak ada →
  `ClassNotFoundException` → app close seketika saat icon dipencet (terverifikasi via `aapt dump badging`).
- `app/Services/Fcm/FcmAlarmNotifier.php` — `active()` (kredensial + saklar Settings), `broadcast()`
  (kirim manual ke semua device), `sendTest()`; `notify()` kini baca `FcmSetting` (severity/raise/clear/tipe)
  + catat `last_sent_at`/`last_error`.
- `app/Http/Controllers/Api/V1/DeviceController.php` — endpoint `POST /devices/test` (kirim tes ke
  perangkat user; lapor "belum terdaftar"/"FCM belum dikonfigurasi").
- `app/Services/AlarmEvaluator.php` — dispatch job FCM pakai `active()` (hormati saklar Settings).
- `app/Http/Controllers/SettingsController.php` — payload `fcm` (+device_count, credentials_ready),
  `updateFcm()`, `sendFcmManual()`.
- `resources/js/Pages/Settings/Index.vue` — tab baru **"Notifikasi Mobile"**: form pengaturan
  (severity/raise-clear/jenis alarm) + kartu **Kirim Notifikasi Manual** (judul+isi → broadcast).
- `routes/{web,api}.php` — `settings.fcm.update|send`, `api.devices.test`.
- `mobile/lib/{router,app,main}.dart` + `core/{fcm/fcm_service,api/nms_api,icons}.dart`,
  `features/shell/home_shell.dart` — tab **Akun**, `testPush()`, ikon user/info, `main()` bungkus
  `runZonedGuarded` + ErrorWidget (crash startup tampil di layar, bukan close diam).
- `mobile/pubspec.yaml` — +`package_info_plus`.

Notes:

- **Insiden crash startup**: penyebabnya namespace≠package MainActivity (bawaan dari perubahan
  applicationId di Fase 3), bukan Firebase. Firebase/`google-services.json` valid & konsisten.
  Setelah fix, `launchable-activity` = `net.kusumavision.kusumavision_nms.MainActivity` (class ada di dex).
- **Diagnosa tanpa device**: server ini tak ada `/dev/kvm` (emulator tak praktis) & HP tak tercolok;
  root cause ditemukan via inspeksi APK (`aapt`), bukan logcat.
- **Verifikasi live**: FCM `credentials=YA active=YA devices=1` (HP user sukses daftar token saat login).
  Endpoint `devices/test` & rute `settings.fcm.*` terdaftar; site 200. Push default: major+ saat raise, semua tipe.
- **Test**: suite terkait **91 passed** + `SettingsFcmTest` 5 passed; `flutter analyze` bersih. Pint bersih.
- **Deploy**: `migrate --force` (fcm_settings), `npm run build` (tab Settings), `config:cache` +
  reload php-fpm + `queue:restart`. APK di-rebuild (halaman Akun) → `public/downloads/kusumavision-nms.apk`.

### Rombak UI/UX aplikasi Android + tombol refresh live per-port

Changed:

- `mobile/lib/theme/app_theme.dart` — fondasi desain baru bergaya dark OLED: background diperdalam
  (`#070D18`), kartu jadi **surface solid ter-elevasi** (bukan lagi bergantung border), token
  `AppRadius`/`AppShadow` (+ glow aksen), input filled, nav bar filled/outline, dialog/snackbar/chip.
- `mobile/lib/core/widgets/glass_card.dart` — `GlassCard` jadi kartu ter-elevasi (gradient sheen +
  shadow lembut, **buang BackdropFilter per-kartu** → scroll daftar ribuan ONU mulus); + `SectionTitle`.
- `mobile/lib/core/widgets/{status_chip,rx_power_badge,async_view}.dart` — badge pill titik-glow +
  factory `reachable`; RX badge ber-ikon sinyal (tabular figures); `AsyncView` pakai **skeleton
  shimmer** (hormati reduce-motion) + empty/error state lebih rapi.
- `mobile/lib/core/icons.dart` — tambah varian filled untuk nav + ikon baru (signal/activity/zap/dll).
- `mobile/lib/features/shell/home_shell.dart` — bottom nav ikon **outline non-aktif, filled + cyan aktif**.
- `mobile/lib/features/dashboard/dashboard_screen.dart` — angka metrik besar & extra-bold (tabular),
  kartu stat dengan **watermark ikon**, progress "ONU online" tebal-membulat + glow, rincian alarm
  jadi **bar proporsi tersegmentasi**; skeleton khusus dashboard.
- `mobile/lib/features/olts/{olt_list,olt_detail}_screen.dart` — chip ikon, badge reachable titik-glow,
  mini-bar proporsi ONU/port.
- `mobile/lib/features/alarms/alarm_list_screen.dart` — ganti garis vertikal + teks caps polos jadi
  **ikon severity dalam chip + badge solid** transparan; filter chip dot-berwarna beranimasi.
- `mobile/lib/features/onus/port_onus_screen.dart` — **tombol refresh live per-port** di AppBar:
  panggil `POST …/ports/{slot}/{port}/refresh` (SNMP walk live, bukan cache polling) lalu
  `invalidate(portOnusProvider)`; digate `canWrite` + OLT **ZTE** (`driver=='zte'`, tampil optimistis
  selagi detail OLT belum termuat). Plus header hitung online + baris ONU direstyle.
- `mobile/lib/features/onus/onu_detail_screen.dart` — header ikon + info key-value berdivider (mono),
  tombol aksi direstyle.
- `mobile/lib/features/register/register_screen.dart` — field filled dikelompokkan `SectionTitle`
  (Identitas / Profil & Layanan / Koneksi WAN); preview script dalam kotak mono.
- `mobile/lib/features/{auth/login,account/account}_screen.dart` — logo & avatar dengan glow ring cyan.
- `mobile/pubspec.yaml` — bump versi `1.0.0+1` → `1.0.1+2`.

Notes:

- Backend & client Dart untuk refresh live per-port **sudah ada sebelumnya** (route
  `api.olts.port.refresh` → `OnuActionController::refreshPort` = `portOnusSnapshot`, gated
  admin/operator + BlockDemoWrites, ZTE-only; `NmsApi.refreshPort`) — sesi ini hanya menambah tombolnya.
- Palet brand cyan/sky dipertahankan (konsisten `kv-*` web); skill ui-ux-pro-max mengonfirmasi mode
  dark OLED cocok. `flutter analyze` **No issues found** di tiap iterasi.
- **Insiden "tombol belum muncul"**: dua penyebab diperbaiki — gate sempat bergantung `oltDetailProvider`
  yang belum termuat (diganti optimistis via `driver`), dan dua APK sebelumnya ber-versionCode sama `1`
  (bump versi supaya sideload dikenali sebagai update).
- APK di-rebuild via `bin/build-apk.sh` (server 8GB, heap 2g, tak swap-thrash) → 54,5 MB →
  `public/downloads/kusumavision-nms.apk`. Tidak build sebagai www-data.

### Dashboard: card Inventory OLT daftar semua OLT + scroll (tinggi tetap)

Changed:

- `app/Services/Dashboard/DashboardStatsService.php` — `oltInventoryByModel()` diganti
  `oltInventoryList()`: kembalikan **tiap OLT sebagai baris sendiri** (id/name/model/reachable +
  unit/up/down 0/1 untuk total footer), diurut per nama — tidak lagi dikelompokkan jadi bucket
  "Lainnya"/"ZTE C300"/"ZTE C320". `detectOltModel()` fallback non-ZTE bukan "Lainnya" lagi tapi
  `SmartOltSupport::capabilities()['vendor_family']` (C-Data EPON/GPON, HiOSO/V-Sol, ZTE GPON).
- `app/Http/Controllers/DashboardController.php` — panggil `oltInventoryList()`.
- `resources/js/Components/Dashboard/OltInventoryList.vue` — render per-OLT (nama tebal + family
  sub-teks + pill Up/Down per unit). List dibungkus `relative min-h-0 flex-1` dengan
  `ul absolute inset-0 overflow-y-auto` + card `overflow-hidden` → **card tak bertambah tinggi**
  berapa pun jumlah OLT, isinya di-scroll di ruang tersisa. Judul jadi "Inventory OLT".
- `tests/Feature/DashboardTest.php` — assert bentuk per-OLT baru (name/reachable + `->etc()`).

Notes:

- Permintaan user: dashboard tampilkan semua OLT (jangan collapse ke "Lainnya"), bikin scroll supaya
  tinggi card tidak nambah. Pola scroll: item flex `min-h-0` + child absolut inset-0 → card ikut
  tinggi kartu tetangga di baris (PollingTrend/OnuDonut), bukan mendorong baris jadi tinggi.
- Test sempat gagal karena config prod ter-cache (nyasar ke PostgreSQL) — di-`config:clear` untuk
  test lalu `config:cache` ulang. `php artisan test` DashboardTest hijau, `npm run build` sukses.

### Link GitHub di kontak halaman Welcome

Changed:

- `resources/js/Pages/Welcome.vue` — tambah baris kontak GitHub di footer (link ke
  `https://github.com/Masamune21-dev/KusumaVisionNMS`, `target=_blank` + `rel=noopener`).

Notes:

- Ikon `Github` dari `@lucide/vue` sudah dihapus di versi ini (brand icon di-drop) → build gagal
  saat mengimpornya. Diganti SVG inline mark GitHub (`fill=currentColor` supaya ikut warna cyan
  seperti ikon kontak lain). `npm run build` sukses.

### Hapus kredensial ACS hardcoded dari repo (repo publik)

Changed:

- `config/services.php` — default blok `acs` (`ACS_URL`/`ACS_USERNAME`/`ACS_PASSWORD`) jadi string
  kosong; nilai asli tidak lagi di-hardcode.
- `app/Http/Controllers/SmartOltController.php` — 2 blok form default (mode dasar & lanjutan)
  baca `config('services.acs.*')`, bukan literal.
- `app/Models/AcsSetting.php`, `app/Http/Controllers/SettingsController.php`,
  `app/Services/Zte/OnuRegistrationFormDefaults.php` — fallback ACS jadi kosong.
- `resources/js/Pages/Settings/Index.vue` — contoh URL hint → `acs.example.net`.
- `.env.docker.example` — placeholder generik (host contoh, user/pass kosong).
- `CLAUDE.md`, `docs/handbook/07-modul-fitur.md`, `docs/SMARTOLT_ZTE_C300_C320_GUIDE.md`,
  `WORKLOG.md` — redaksi URL/user/password ACS asli jadi referensi `.env`.
- `tests/Feature/SmartOltInventoryTest.php`, `tests/Feature/SmartOltTr069BulkTest.php`,
  `tests/Unit/ZteOnuConfigureTest.php` — fixture kredensial ganti nilai palsu
  (`acs.example.net`/`acsuser`/`acspass123!`); `SmartOltTr069BulkTest::test_execute_skips…`
  kini set `AcsSetting` eksplisit (karena default sudah tak ada) supaya skip-rule tetap tervalidasi.

### Rombak total UI/UX aplikasi mobile (Flutter) + fix 500 tombol refresh Port ONU

Created:

- `mobile/assets/fonts/{Sora,Inter,JetBrainsMono}.ttf` — font variable di-bundle (offline-first, dideklarasi di `pubspec.yaml`).
- `mobile/lib/core/widgets/aurora_background.dart` — latar hidup: aurora mesh + jala node-fiber (CustomPainter, RepaintBoundary, hormati reduced-motion, flag `animate` untuk daftar panjang).
- `mobile/lib/core/widgets/pulse_logo.dart` — lambang menara memancar sinyal (cincin konsentris) untuk Splash/Login.
- `mobile/lib/core/widgets/pulse_dot.dart` — titik status berdenyut (online pulse / offline diam).
- `mobile/lib/core/widgets/signal_ring.dart` — gauge melingkar (busur gradient + glow) untuk % kesehatan.
- `mobile/lib/core/widgets/count_up_text.dart` — angka menghitung naik (TweenAnimationBuilder, hormati reduced-motion).
- `mobile/lib/core/widgets/stagger.dart` — helper `staggeredItem()` via flutter_staggered_animations.
- `mobile/DESIGN_REVAMP_PLAN.md` — rencana rombak 6 fase (arah desain, library, font).

Changed:

- `mobile/pubspec.yaml` — + `flutter_animate`, `flutter_staggered_animations`, `shimmer`; deklarasi 3 font bundle; versi `1.0.2+3` → `1.1.4+8`.
- `mobile/lib/theme/app_theme.dart` — `AppFont` (Sora/Inter/JetBrainsMono), `AppMotion` (durasi/easing/stagger), `AppGradient` (accent/aurora), `AppText.mono`, `TextTheme` M3 3-keluarga.
- `mobile/lib/core/widgets/glass_card.dart` — GlassCard v2: opsi `blur` frosted + press-scale. **Fix penting:** buang bungkus `Stack`+sheen yang menyusutkan isi & menempelkannya ke kiri-atas → isi kartu kembali lebar penuh (center/start bekerja benar; memperbaiki kartu profil Akun, stat OLT detail, dsb).
- `mobile/lib/core/widgets/async_view.dart` — Skeleton → shimmer (+`SkeletonShimmer`); `EmptyState` animatif (badge melayang + reveal fade/scale, hormati reduced-motion).
- `mobile/lib/features/**` — 12 layar dirombak: Splash, Login (aurora + kartu frosted), Dashboard (hero SignalRing + stat count-up center + stagger), Home shell (floating glass nav + immersive), OLT list (family badge + PulseDot + stagger), OLT detail (hero ring + port PulseDot), ONU detail (status pulse header), Port ONU (aurora statis + stagger), Alarm/Cari/Unconfigured/Register/Akun.
- `mobile/lib/features/account/account_screen.dart` — kartu profil hero (avatar cincin-gradient, chip peran tunggal — buang badge "Admin" redundan), Info Aplikasi mono + tombol salin.
- `mobile/lib/features/dashboard/dashboard_screen.dart` — `_StatCard` isi center via `Stack(alignment: topCenter)` (watermark pojok tetap).
- `mobile/lib/core/icons.dart` — + `shieldCheck`, `copy`, `smartphone`.
- `app/Http/Controllers/Api/V1/OnuActionController.php` — **fix 500 tombol refresh Port ONU** (semua OLT): `refreshPort` salah panggil `$resolver->isNonZte()` (method tak ada) → `SmartOltSupport::isNonZte($this->driver($olt))`.
- `CLAUDE.md` — sinkron: catatan design system mobile baru (font bundle, aurora, deps animasi, rencana).

Notes:

- Font di-bundle sebagai aset (bukan `google_fonts` runtime) demi offline-first di lapangan; font variable → `fontWeight` dipetakan ke axis `wght` otomatis oleh engine.
- Aurora & node-fiber di-hand-roll (CustomPainter) alih-alih paket `mesh_gradient`/`particles_network` demi kendali performa & reduced-motion; Rive ditunda ke iterasi lanjut.
- Tiap fase diverifikasi `flutter analyze` (No issues) + `flutter build bundle` (exit 0); APK rilis `flutter build apk --release` sukses (55,2 MB, `1.1.4+8`).
- **Fix refresh backend sudah live** via `systemctl reload php8.3-fpm` (opcache) — tak perlu update APK. Diagnosa dari `storage/logs/laravel.log` (`Call to undefined method …::isNonZte()` @ `OnuActionController.php:98`); `php -l` bersih; jalur non-ZTE (`getRegisteredOnusByPort`) ada di interface + implementasi C-Data/HiOSO.
- `versionCode` di-bump tiap iterasi (hingga `+8`) karena versionCode identik membuat Android menolak update; APK juga disalin ber-nama versi (`kusumavision-nms-v{N}.apk`) untuk cache-bust unduhan.

### Tombol unduh APK Android di Settings web

Changed:

- `app/Http/Controllers/SettingsController.php` — payload `mobileApk` di `edit()` + helper `mobileApkPayload()` (cek `public/downloads/kusumavision-nms.apk`, URL, ukuran, mtime), `mobileAppVersion()` (baca `version:` dari `mobile/pubspec.yaml`), dan `humanFilesize()`.
- `resources/js/Pages/Settings/Index.vue` — prop `mobileApk` + kartu "Aplikasi Android" di tab Umum (bawah Informasi Sistem): tombol **Unduh APK** (link `/downloads/kusumavision-nms.apk`) dengan versi/ukuran/waktu-diperbarui, atau peringatan + petunjuk build bila APK belum ada; import ikon `Download`.

Notes:

- Link "latest" tetap `/downloads/kusumavision-nms.apk` (kopi terbaru dari `bin/build-apk.sh`, saat ini `1.1.4+8`); file ber-versi `-v{N}.apk` hanya arsip.
- Versi dibaca dari `mobile/pubspec.yaml` saat render (fallback `null` bila repo mobile tak ada di server); waktu & ukuran dari mtime/filesize file jadi selalu mengikuti build terakhir tanpa perlu di-hardcode.
- Diverifikasi: `php -l` bersih, `npm run build` sukses (template Vue kompilasi), ikon `Download` ada di `@lucide/vue`; `php8.3-fpm` di-reload agar opcache mengambil payload baru.

## 2026-07-08

### Mode Bridge registrasi ONU ZTE (OLT gaya bridge / Bulumanis Lor) + fix copy/parser + VEIP

Changed:

- `app/Services/ZteProvisioningScriptBuilder.php` — mode `wan_mode = bridge`: emit `switchport mode hybrid vport 1` (sebelum `service-port`) + `service {name} type internet …`, dan **hilangkan** seluruh baris `wan-ip …`/`ping-response`. `serviceLine()` dapat param `withType`. Mode pppoe/dhcp/static tak berubah.
- `app/Services/Zte/OnuRegistrationService.php` — validasi `wan_mode` terima `bridge`; `hydrateProfiles()` di-guard: mode bridge memakai VLAN numerik apa adanya (tak ditimpa vlan-profile).
- `app/Http/Controllers/SmartOltController.php` — `validatedProvisioning()` `wan_mode` terima `bridge`.
- `app/Services/ZteOnuReconfigureScriptBuilder.php` — copy/reconfigure **pertahankan token `type internet`** (warisi dari baseline bila form tak membawanya → tak terhapus diam-diam); `uniToken()` dukung VEIP (`veip_{N}` tanpa `0/`); helper `serviceDesc()` dipakai bersama build+diff.
- `app/Services/ZteOnuRunningConfigService.php` — parser gemport terima bentuk panjang `gemport 1 name 1 unicast tcont 1 dir both`; `splitUniPort()` kenali token `veip_{N}`; `isNoise()` diperluas menangkap semua baris prompt/echo (`\S*[#>]`, mis. `ZXAN#exit`, `> show …`) + pesan sesi `(the )configuration is changed`.
- `app/Services/ZteOnuCopyService.php` — audit ONU tanpa wan-ip dicatat `wan_mode=bridge` (label akurat).
- `resources/js/Pages/SmartOlt/RegisterOnu.vue` — tombol mode **BRIDGE** + panel info; watcher mengosongkan `vlan_profile` saat bridge (VLAN ID numerik tetap otoritatif).
- `resources/js/Components/SmartOlt/OnuConfigEditor.vue` — opsi Port Type **VEIP** di editor UNI VLAN.
- `docs/SMARTOLT_ZTE_C300_C320_GUIDE.md` — dokumentasi template `bridge` + `type internet` + catatan normalisasi gemport long-form.
- `mobile/lib/features/register/register_screen.dart` — mobile: opsi Mode WAN **bridge** + panel info `_wanHint` (senada web).
- `mobile/pubspec.yaml` — bump versi `1.1.4+8` → `1.1.5+9` (versionCode wajib naik untuk rilis APK).

Notes:

- **Diagnosa dari OLT nyata:** fetch running-config lintas vendor (ZTEG/FHTT/HWTC/GPON/ALCL/ELWG) di OLT-C320-BULUMANIS-LOR (id 564), OLT-C320-PATI (1), OLT-C300-SEKARJALAK (2). Semua vendor di Bulumanis dapat CLI identik — bedanya **model layanan** (bridge VLAN 100 vs routed PPPoE), bukan per-vendor.
- Output builder mode bridge terbukti **sama persis** dengan ONU Bulumanis live (`switchport mode hybrid vport 1`, `service … type internet gemport 1 cos 0 vlan 100`, tanpa wan-ip).
- **Bug lama** yang ikut ketemu & diperbaiki: (a) parser gemport gagal pada bentuk panjang → copy ONU bridge dulu kehilangan gemport; (b) `vlan port veip_1` salah jadi `eth_0/1`; (c) baris `ZXAN#exit` menempel ke direktif terakhir (`mode hybrid` → `mode hybridZXAN#exit`) bikin dropdown mode kosong saat load.
- Registrasi di Bulumanis lolos validasi via fallback profil GLOBAL `SERVER`/`ALL-ONT` (tcont/ip OLT 564 kosong) — tak perlu re-sync katalog.
- Diverifikasi: `pint` bersih; unit `ZteOnuConfigureTest`/`ZteOnuDetailTest` PASS; `npm run build` sukses; mobile `flutter analyze` No issues. (Kegagalan test HTTP lain = 419-CSRF pre-existing, dikonfirmasi via `git stash`.)
- Mobile perlu **build ulang APK** (`bash bin/build-apk.sh`) agar opsi bridge muncul di HP — belum di-build sesi ini.

## 2026-07-09

### Audit keamanan + optimasi ukuran APK mobile (tanpa ubah UI)

Changed:

- `mobile/android/app/src/main/AndroidManifest.xml` — set `android:allowBackup="false"` + `android:fullBackupContent="false"` pada `<application>`; mencegah file token terenkripsi (secure storage) ikut ke Google Auto-Backup / ADB backup.
- `mobile/lib/core/providers.dart` — `secureStoreProvider` kini memakai `FlutterSecureStorage(aOptions: AndroidOptions(encryptedSharedPreferences: true))` → EncryptedSharedPreferences (AES-256, Jetpack Security), lebih kuat dari default RSA-wrapped prefs.
- `bin/build-apk.sh` — build memakai `--split-per-abi --target-platform android-arm,android-arm64` (buang x86_64 emulator-only); menyalin `app-arm64-v8a-release.apk` → `public/downloads/kusumavision-nms.apk` (download utama) + `app-armeabi-v7a-release.apk` → `kusumavision-nms-arm32.apk` (fallback HP 32-bit).
- `mobile/pubspec.yaml` — bump versi `1.1.5+9` → `1.1.6+10` untuk rilis APK. (Sempat hapus `cupertino_icons` lalu **dikembalikan** — lihat Notes.)

Notes:

- **Audit ukuran:** APK universal 53MB karena membundel 3 ABI (arm64 17.4MB + armeabi-v7a 15.0MB + x86_64 18.7MB uncompressed) — aplikasi terkompilasi 3×. `--split-per-abi` + buang x86_64 memangkas hasil jadi **arm64 19.9MB + arm32 17.4MB** (~64% lebih kecil) tanpa ubah kode/UI. Font (Inter/Sora/JetBrainsMono, total 1.2MB) sengaja tidak disentuh (offline-first by design).
- **`cupertino_icons` dikembalikan:** sempat dihapus (0 penggunaan di lib kita), tapi build memunculkan warning `Expected to find fonts for (packages/cupertino_icons/CupertinoIcons…)` — ada referensi `CupertinoIcons` reachable dari framework/dependency, risiko glyph kosong (tofu) di UI. Karena font ikon **selalu di-tree-shake** (terbukti: `CupertinoIcons.ttf` 257KB → **848 byte**), menghapusnya tak menghemat apa pun; dikembalikan demi aman.
- **Audit keamanan — sudah baik:** token di Keystore, tak ada `http://` cleartext, tak ada secret hardcoded (`google-services.json`/`key.properties` gitignored), 401 membersihkan sesi, permission minimal (INTERNET + POST_NOTIFICATIONS), tak ada logging sensitif.
- **Build & verifikasi:** `bash bin/build-apk.sh` sukses → `public/downloads/kusumavision-nms.apk` (arm64, 19.9MB) + `kusumavision-nms-arm32.apk` (17.4MB, fallback). apksigner: **verified (v2 scheme, Android Debug key** — sama seperti sideload sebelumnya). `versionCode=2010` (arm64; `--split-per-abi` beri offset ABI 2000+10) & `1010` (arm32) — keduanya > 9 lama, update mulus. Hanya ABI arm (tanpa x86_64) di tiap APK. `flutter analyze` → No issues.
- **Belum dikerjakan (opsional):** R8 `minifyEnabled`/`shrinkResources` — dipisah karena perlu 1× uji build Firebase (refleksi).

## 2026-07-10

### Layout shell scroll-dokumen: screenshot full-page utuh + panel SISTEM desktop-only

Changed:

- `resources/js/Layouts/AuthenticatedLayout.vue` — rombak shell: scroll pindah ke **level dokumen** (root `min-h-screen`, container `overflow-y-auto`/`scroll-region` dihapus); sidebar desktop tak lagi `position: fixed` melainkan ikut alur halaman setinggi konten — blok logo+nav sticky-top (dibungkus wrapper `flex-1` pembatas jangkauan + clamp `lg:max-h-[calc(100vh-19rem)]` supaya tak pernah menabrak panel di viewport pendek), blok akun+`SystemInfoPanel` sticky-bottom dengan posisi natural di dasar sidebar; header desktop & top bar mobile jadi sticky; footer ikut alur di dasar halaman (sengaja TIDAK sticky). `SystemInfoPanel` kini `v-if="isDesktop"` — di HP tak di-mount (drawer lebih lega, timer jam/detik + polling health 20 dtk tidak jalan sia-sia).
- `resources/js/Pages/SmartOlt/RegisterOnu.vue` — offset panel Live Raw CLI `xl:top-6` → `xl:top-24` (header kini sticky 72px, panel jangan nyelip di bawahnya).
- `docs/handbook/15-ui-tema-dashboard.md` — sinkron anatomi shell (§4): scroll dokumen, aturan jangan menambah elemen `fixed`/sticky-bottom di kolom konten.

Notes:

- **Akar masalah** screenshot full-page "sobek": sidebar `fixed` + scroll di container dalam membuat tool capture men-stitch per segmen — panel SISTEM tertinggal di posisi viewport, area sidebar bawah bolong hitam, baris detail Disk ("39.3 GB / 98.1 GB") nyasar ke dasar gambar.
- **Trade-off dipilih user** (perilaku "build pertama"): panel SISTEM tetap sticky-bottom (selalu terlihat saat scroll); konsekuensinya di capture full-page panel dirender di posisi bawah-layar (±3/4 tinggi gambar) dengan latar sidebar tetap menyatu — bukan di dasar mutlak halaman. Footer TIDAK dikembalikan sticky karena di kolom konten sticky-bottom menimpa kartu/tabel di tengah gambar capture.
- **Diverifikasi live** via Playwright (Chromium `fullPage: true`) ke https://127.0.0.1 memakai user sementara (dibuat lalu dihapus): full-page utuh ✔, scroll tengah & mentok bawah tanpa tumpang-tindih nav/panel (clamp bekerja) ✔, drawer mobile tanpa panel ✔. `npm run build` sukses, langsung tersaji di prod.

### Role Partner: OLT privat milik sendiri (self-service, tersembunyi dari admin/operator)

Created:

- `database/migrations/2026_07_10_100000_add_owner_user_id_to_snmp_olts_table.php` — kolom `owner_user_id` (nullable, indexed, tanpa FK constraint demi kompat SQLite test) di `snmp_olts`. `null` = OLT global; terisi = OLT privat milik partner.
- `database/migrations/2026_07_10_100100_backfill_partner_owned_olts.php` — konversi OLT lama yang di-assign ke TEPAT SATU partner (dan NOL operator) menjadi privat milik partner tsb. Di prod: OLT #564 → milik Alaik (#486).
- `app/Http/Controllers/Concerns/ManagesOltOwnership.php` — trait bersama 3 controller inventori: `claimOltForPartner()` (set `owner_user_id` via `forceFill` + buat baris pivot `olt_user`) & `authorizeOltDeletion()` (partner hanya boleh hapus OLT miliknya).

Changed:

- `app/Models/SnmpOlt.php` — cast `owner_user_id`, relasi `owner()`, helper `isPrivatelyOwned()`. `owner_user_id` sengaja BUKAN `$fillable` (anti-spoof mass-assignment).
- `app/Models/User.php` — `allowedOltIds()` kini gabung pivot + `snmp_olts.owner_user_id`; tambah `canAddOlt()` (admin/operator/partner) & `ownsOlt(SnmpOlt)`.
- `app/Models/Scopes/PartnerOltScope.php` — cabang baru: user tak-ter-scope (admin/operator/demo) hanya lihat OLT global (`owner_user_id` NULL); OLT privat partner disembunyikan total termasuk dari admin.
- `app/Http/Controllers/{SmartOlt,CDataOlt,Hioso}Controller.php` — `store` memanggil `claimOltForPartner`; `destroy` memanggil `authorizeOltDeletion` (butuh `Request`).
- `app/Http/Controllers/UserController.php` — `syncPartnerOlts` MEMPERTAHANKAN pivot OLT milik privat (tak lepas kepemilikan saat admin edit user); `destroy` me-null-kan `owner_user_id` OLT milik user yang dihapus (kembali ke global, tak yatim).
- `app/Services/Fcm/FcmAlarmNotifier.php` & `app/Services/Telegram/TelegramNotifier.php` — blok admin/operator (FCM recipients + bot Telegram global) di-gate `owner_user_id === null`; OLT privat partner hanya memberi tahu partner pemiliknya.
- `app/Http/Middleware/HandleInertiaRequests.php` — expose `auth.can.add_olt`.
- `routes/web.php` — create/store/destroy 3 family: `role:admin,operator` → `role:admin,operator,partner` (hapus tetap di-guard kepemilikan di controller).
- `resources/js/Pages/SmartOlt/Index.vue` — tombol Tambah pakai `canAddOlt`; tombol Hapus pakai `canDeleteOlt(olt)` (= inventory ATAU `olt.owned`); badge "Privat" saat `olt.is_private`. `serializeOlt` menambah flag `is_private`/`owned`.
- `docs/handbook/11-keamanan-rbac-audit.md` — sinkron model kepemilikan OLT partner, dua cabang scope, gate tambah/hapus, routing alarm privat, flag frontend.
- `tests/Feature/PartnerRoleTest.php` & `tests/Feature/PartnerTelegramBotTest.php` — ganti test "partner tak boleh buat OLT" jadi "partner buat OLT privat"; tambah test tersembunyi-dari-admin/operator, hapus OLT sendiri, tolak hapus OLT global ter-assign, alarm OLT privat hanya ke bot partner.

Notes:

- **Keputusan user (Option B):** OLT privat partner tersembunyi TOTAL — bahkan admin tak melihatnya (di daftar, peta, dashboard, alarm). Alternatif "admin tetap oversight" ditolak. Konsekuensi: dashboard/peta/search admin otomatis mengecualikan OLT partner (via satu `PartnerOltScope`).
- **Kenapa tetap pakai pivot `olt_user`:** OLT privat partner tetap dapat baris pivot supaya seluruh mesin scope/alarm/Telegram/FCM (yang sudah keyed ke pivot) jalan tanpa diubah. `owner_user_id` hanya menandai kepemilikan + menyembunyikan dari non-pemilik.
- **Migrasi tanpa FK constraint** (SQLite test tak dukung ADD CONSTRAINT); integritas user-delete ditangani di `UserController::destroy`.
- **Diverifikasi:** 49 test partner/operator/inventori/telegram lolos (termasuk test baru); full suite 316 lolos, 1 gagal PRE-EXISTING (`ApiV1WriteTest::test_refresh_port_non_zte`, dikonfirmasi via `git stash` — bukan dari perubahan ini). DB nyata sesudah migrate: admin tak lihat #564 (13→12 OLT), partner Alaik hanya lihat #564. `npm run build` sukses; `config:cache`+`route:cache` di-rebuild, `queue:restart` dikirim.

### Refresh galeri Welcome: 11 tab screenshot baru + dedup gambar c320

Created:

- `public/img/portdetail.webp`, `portonus.webp`, `onumonitoring.webp`, `map.webp`, `alarms.webp`, `reports.webp` — screenshot halaman untuk tab galeri baru (Detail Port PON, ONU per Port, ONU Monitoring, Peta ONU, Alarms, Report).

Changed:

- `resources/js/Pages/Welcome.vue` — galeri "Tampilan Aplikasi" diperluas 5 → **11 tab**; tab "Detail ONU" diganti "Detail OLT" (set screenshot baru tak berisi detail ONU); hero memakai dashboard baru; src file yang ditimpa diberi cache-bust `?v=20260711`; referensi `c320(1).webp` → `c320.webp`; +ikon `WifiOff`.
- `public/img/dashboard.webp`, `dashboard1.webp`, `detail.webp`, `login.webp`, `oltinventory.webp`, `unconfigured.webp` — ditimpa screenshot full-page baru dengan **nama sama** supaya README ikut segar otomatis (`detail.webp` kini Detail OLT C300 dengan visualisasi chassis 9 card).
- `public/img/c320(1).webp` — **dihapus**: terbukti duplikat byte-identik (md5 sama) dari `c320.webp`; satu-satunya referensi (Welcome) diarahkan ke `c320.webp`.

Notes:

- Sumber: 14 PNG full-page yang disiapkan user di `public/img/new/` (hasil layout fix sesi sebelumnya — sidebar utuh sampai bawah), dikonversi `cwebp -q 82` (1–1,7 MB → 26–186 KB), lalu folder sumber dihapus atas persetujuan user (16 MB, tersaji publik oleh nginx & tak perlu masuk git).
- 3 file sengaja TIDAK dipakai: `settings` (halaman admin, kurang pas dipajang publik), `smartolt-1-detail` (redundan — versi C300 lebih impresif), `port-detail` uplink `gei_1/4/1` (redundan dengan port GPON).
- Diverifikasi via Playwright: semua request `/img/*` 200; hero + galeri render benar termasuk klik tab Detail OLT/Peta ONU/Alarms; `npm run build` sukses.
- Heads-up ke user (sudah disampaikan, user OK): screenshot menampilkan data asli — IP internal RFC1918 di Detail OLT, sebaran pin pelanggan level desa (tanpa nama) di peta — kini tampil publik di landing page.
