# 13 — Troubleshooting & Maintenance

[← Indeks](README.md) · [← 12 Frontend](12-frontend.md) · [14 Panduan Menambah Fitur →](14-panduan-tambah-fitur.md)

Format: **Gejala → Penyebab umum → Solusi**. Untuk hardening host & perintah deploy lihat
[04 — Instalasi & Deploy](04-instalasi-deploy.md) dan
[`docs/LOCAL_PRODUCTION_HARDENING.md`](../LOCAL_PRODUCTION_HARDENING.md).

---

## Produksi & konfigurasi

### Site error 500 setelah ubah `.env`/config
- **Penyebab**: prod memuat config ter-cache (`bootstrap/cache/config.php`); perubahan belum
  ter-cache, atau `.env` jadi `root:root` sehingga `www-data` tak bisa baca → fallback ke SQLite.
- **Solusi**:
  ```bash
  ls -l .env                       # harus -rw-r----- root www-data (640)
  chown root:www-data .env && chmod 640 .env
  php artisan config:cache
  php artisan queue:restart
  supervisorctl restart kusumavision-telnet-proxy
  ```

### Perubahan kode job/service tidak berefek di prod
- **Penyebab**: daemon supervisor long-lived (`kusumavision-worker`, telnet proxy) masih
  memakai kode lama.
- **Solusi**: `php artisan queue:restart` dan/atau
  `supervisorctl restart kusumavision-telnet-proxy`.

### Tombol baru "mati diam" / rute baru 404 padahal test hijau
- **Penyebab**: produksi memakai **route cache** (`bootstrap/cache/routes-v7.php`, mencakup web **dan**
  API). Rute yang baru ditambahkan belum ada di cache → endpoint 404/405, dan `route('nama.baru')` di
  Vue melempar galat Ziggy (daftar rute `@routes` dirender dari koleksi ter-cache) sehingga tombolnya
  tak bereaksi. Test tetap hijau karena `scripts/test.sh` mengalihkan cache rute.
- **Solusi**: `php artisan route:cache` (jalankan sebagai `www-data`) **sebelum** `npm run build`,
  supaya bundel baru tak pernah tayang mendahului rutenya. Cek: `php artisan route:list --name=<nama>`.

### Pesan error tampil bahasa lain / kunci mentah (`olt.telnet_forbidden`)
- **Latar**: sejak 30 Sep 2026 teks error/status backend lewat `__('grup.kunci')` di
  `lang/{id,en}/{olt,zte,cdata,hioso,odp,common,reports,system}.php` (+ `flash.php`), frontend
  lewat `resources/js/lang/{id,en}.json`.
- **Kunci tampil mentah** → kuncinya belum ada di grup itu (atau salah nama grup). **Tampil Inggris
  padahal locale `id`** → kunci lupa ditaruh di `lang/id`: `APP_FALLBACK_LOCALE=en` diam-diam jatuh ke
  Inggris. Penjaga permanen: `tests/Unit/LangParityTest` (kunci & placeholder id = en, setiap
  `__('g.k')` literal di `app/` ada di kedua bahasa) — jalankan lewat `scripts/test.sh`.
- **Sengaja tetap Indonesia** (bukan bug): pesan alarm & recovery `AlarmEvaluator`, push FCM, item
  progres Salin ONU/TR069 Massal, error backup config, deskripsi audit — semuanya disimpan sebagai data
  oleh worker tanpa locale pengguna. Rute `api` (aplikasi) tak memasang `SetLocale` → selalu `id`.
  Daemon `telnet-proxy` memakai `users.locale` pemilik tiket.
- Perubahan `lang/*.php` langsung berlaku di web, tapi worker/daemon yang menampilkan pesan perlu
  `queue:restart` / restart `kusumavision-telnet-proxy`; perubahan JSON frontend perlu `npm run build`.

### Test "nyasar" ke PostgreSQL / DB produksi
- **Penyebab**: config ter-cache dimuat lebih dulu saat boot dan **menang** atas `<env>` di
  `phpunit.xml`, jadi koneksi resolve ke pgsql produksi walau `DB_CONNECTION=sqlite` sudah diset.
- **Bahaya**: ±60 file test memakai `RefreshDatabase`, yang memanggil `migrate:fresh` pada koneksi
  default = **drop seluruh tabel produksi**.
- **Solusi**:
  ```bash
  bash scripts/test.sh        # mengalihkan cache config+route, abort bila DB bukan sqlite
  ```
  **Jangan** pakai `php artisan config:clear` untuk ini — DB memang jadi aman, tapi cache config
  produksi ikut terhapus dan tidak pernah dipulihkan.

### Migrasi gagal di test tapi sukses di prod (atau sebaliknya)
- **Penyebab**: memakai fitur PostgreSQL-only; test pakai SQLite in-memory.
- **Solusi**: jaga migrasi tetap **SQLite-compatible** (hindari tipe/constraint pgsql-only).

### 419 Page Expired terus-menerus saat login (di belakang Cloudflare)
- **Gejala**: login SELALU 419 di semua browser (incognito juga), padahal server sehat —
  cookie ter-set, jam sinkron, Redis `PONG`. Di log nginx, `POST /login` datang **dengan**
  cookie lengkap tapi **tanpa** header `X-XSRF-TOKEN` (log format debug: `xsrf="-"`).
- **Penyebab**: Cloudflare mode **Flexible** → origin dilayani HTTP port 80 → PHP tidak
  melihat TLS → Laravel/Ziggy men-generate URL `http://` (cek: `curl -s https://situs/login |
  grep -o '"url":"[^"]*"'`). Halaman `https://` mem-POST ke `route('login')` yang `http://`
  → axios menganggap **cross-origin** (beda scheme) dan men-skip header `X-XSRF-TOKEN` → 419.
- **Solusi**: percayai proxy di depan origin lewat **`TRUSTED_PROXIES`** di `.env` (IP/CIDR proxy,
  atau `*` bila origin memang tak bisa diakses langsung), lalu `php artisan config:cache` + reload
  php-fpm. Sejak Sep 2026 bawaannya hanya localhost (`config/trustedproxy.php`) — dulu `at: '*'`, yang
  membuat `X-Forwarded-For` bisa dipalsukan siapa pun (throttle login & IP audit log bisa diakali).
  Alternatif yang lebih baik: pasang certbot di origin dan naikkan Cloudflare ke **Full (strict)**
  (origin melihat TLS sendiri, tak bergantung header proxy).
- **Catatan diagnosa**: bedakan dengan 419 biasa (tab lama/cookie basi — cukup hard refresh).
  Simulasi handshake dari server lain lolos (422) karena curl memasang header manual — hanya
  browser yang kena, itu ciri khas kasus ini. Origin `521/522` selang-seling = masalah
  terpisah (SSL mode Full tanpa cert origin, atau firewall memblok sebagian IP Cloudflare).

---

## Frontend / Vite

### Halaman tertentu 500 setelah deploy, page lain normal
- **Penyebab (gotcha terdokumentasi)**: `import` statis library banyak-dynamic-import (mis.
  tsParticles) di sebuah Inertia Page menghilangkan key manifest page.
- **Solusi**: ganti ke `defineAsyncComponent`, `npm run build`, lalu reload php-fpm
  (`systemctl reload php8.3-fpm`).

### White-screen / "Failed to fetch dynamically imported module" pasca-deploy
- **Penyebab**: browser memegang HTML lama yang mereferensi chunk hash lama.
- **Solusi**: sudah ditangani handler `vite:preloadError` di `app.js` (reload sekali). Pastikan
  `vite.config.js` tetap `emptyOutDir:false` agar chunk lama tidak terhapus. Hard refresh bila perlu.

### Kunci i18n mentah tampil di layar (mis. `portdetail.title`)
Kunci belum ada di `resources/js/lang/{id,en}.json`, **atau** ada di sumber tapi bundelnya belum
di-build ulang. Cek cepat kunci yang dipakai di komponen:

```bash
grep -rhoE "t\('[a-z0-9_]+\.[a-z0-9_]+'" resources/js --include=*.vue | sort -u
```

lalu bandingkan dengan isi kedua berkas lang. Fitur baru **wajib** menambah kunci ke dua bahasa,
dan `npm run build` setelahnya. Hati-hati `git checkout` pada berkas lang — kunci yang belum
di-commit ikut hilang, dan gejalanya baru terlihat setelah build berikutnya.

### Aset 503 saat deploy
- **Penyebab**: prefetch eager Vite (sudah dinonaktifkan di `AppServiceProvider`) atau CDN cache
  dingin. Lihat komentar di `AppServiceProvider::boot()`.

---

## Polling & SNMP

### Data live OLT/ONU tidak ter-refresh otomatis
- **Cek berurutan**:
  1. Scheduler jalan? `supervisorctl status kusumavision-scheduler` (atau cron `schedule:run`).
  2. Worker jalan? `supervisorctl status kusumavision-worker`.
  3. OLT `polling_enabled = true`? `last_polled_at` bergerak?
  4. Lihat `polling_events` (kind `olt_poll`/`rx_poll`, kolom `success`/`message`).
- Refresh manual dari UI tetap bekerja (sinkron), itu bukti SNMP path OK walau scheduler mati.

### `go_poller_error` terisi di `last_test_result`
- **Penyebab**: binary hilang/tak executable, timeout, community salah, atau OLT tak balas.
- **Solusi**:
  ```bash
  ls -l bin/kv-snmp-poller          # ada & executable?
  CGO_ENABLED=0 go build -mod=mod -trimpath -ldflags='-s -w' -o bin/kv-snmp-poller ./cmd/kv-snmp-poller && chmod +x bin/kv-snmp-poller
  # tes manual:
  KV_SNMP_COMMUNITY=public bin/kv-snmp-poller --host <ip> --version v2c --timeout 10s
  ```
  Sistem otomatis **fallback ke PHP** (`OltSnmpClient`) bila Go gagal — fungsi tetap jalan, hanya
  lebih lambat. Cek `SNMP_POLLER_DRIVER=go` di `.env`.

### Test koneksi OLT gagal
- **Penyebab**: IP/port salah, community salah, firewall/UFW, OLT tak izinkan host NMS.
- **Solusi**: tes dari host:
  ```bash
  snmpget -v2c -c <community> <ip>:<port> 1.3.6.1.2.1.1.1.0   # sysDescr
  snmpwalk -v2c -c <community> <ip> 1.3.6.1.2.1.2.2.1.2       # ifDescr
  ```
  Pastikan SNMP **v1/v2c** (v3 tidak didukung → error).

### RX power kosong / tidak update
- **Penyebab**: belum due (`rx_poll_interval_minutes`), atau firmware butuh CLI.
- **Solusi**: tunggu interval / paksa refresh; sebagian OLT butuh `ZteOnuRxPowerService` (CLI
  `show pon power onu-rx`) — pastikan kredensial CLI terisi.

### ONU tidak muncul / serial salah
- **Penyebab**: parsing di-tuning untuk firmware tertentu; OLT C600 punya OID berbeda.
- **Solusi**: cek `SmartOltSupport::isC600()` terdeteksi benar (nama/vendor/sysDescr mengandung
  `c600`). Verifikasi OID via `snmpwalk`. Catat temuan di `WORKLOG.md`.

### HiOSO: Rx `na` padahal ONU online
- **Gejala**: di OLT HiOSO sebagian ONU tak punya angka redaman (kolom Rx kosong), padahal di OLT
  statusnya Up dan pelanggan jalan.
- **Penyebab**: OLT memang **tidak melaporkan DDM** untuk sebagian ONU (tipe ONU tertentu) — OID Rx
  `25355.3.2.6.14.2.1.8.1` berisi `"na"` permanen. Itu **bukan** tanda offline (terverifikasi live
  Agu 2026).
- **Yang benar**: status online HiOSO dari **link-state** `25355.3.2.6.3.2.1.39.1` (1 Up / 2 Down) —
  `HiosoEponSnmpService`; Rx valid hanya bukti pendukung. Rx lama dibawa `snmp_stale` maks
  `MAX_RX_NA_STRIKES` (2) poll lalu dikosongkan supaya tak menampilkan angka beku. Firmware tanpa kolom
  `.39` jatuh ke perilaku lama (berbasis Rx).
- **Jangan** "memperbaiki" dengan menganggap `na` = offline (dulu memicu alarm palsu). Acuan
  pembanding: CLI `show onu info epon 0/{PON} all` (kolom `Status`, bukan `Activate`). Rincian:
  `docs/SMARTOLT_HIOSO_GUIDE.md` quirk #10.

---

## CLI / Telnet

### Provisioning/eksekusi script gagal
- **Penyebab**: `cli_transport ≠ telnet`, username/password CLI kosong/salah, OLT minta
  konfirmasi tak terjawab, atau error sintaks CLI.
- **Solusi**: pastikan transport telnet + kredensial; lihat `execution_output`/`execution_error`
  di baris `smartolt_onu_registrations`. Untuk perintah yang minta konfirmasi gunakan jalur
  `executeConfirmable`. Verifikasi sintaks ke
  [`SMARTOLT_ZTE_C300_C320_C600_GUIDE.md`](../SMARTOLT_ZTE_C300_C320_C600_GUIDE.md).

### Terminal telnet browser tak konek
- **Cek**:
  1. Daemon hidup? `supervisorctl status kusumavision-telnet-proxy`.
  2. `TELNET_PROXY_WS_URL` benar (prod: `wss://domain/telnet-ws`; dev kosong → `ws://host:6002`).
  3. nginx mem-proxy `/telnet-ws` ke `127.0.0.1:6002` (Upgrade/Connection headers).
  4. OLT `cli_transport=telnet` + kredensial terisi (kalau tidak: token 422, proxy 403).
  5. Pengguna berhak? Telnet hanya untuk admin/operator atau pemilik OLT privat
     (`canAccessOltSecrets`) — partner pada OLT yang sekadar di-assign mendapat 403.
  6. Tiket TTL hanya ~30 dtk dan **sekali pakai** untuk **membuka** WS — buka segera setelah klik.
     Daemon wajib memakai `APP_KEY` dan cache store (Redis) yang sama dengan web.
- **Output terpotong/aneh**: cek `TelnetIacFilter` (negotiation) — biasanya OK, tapi firmware
  unik bisa beda.

### Sync profil kosong/gagal
- **Penyebab**: kredensial CLI, atau format output `show ...` beda dari yang di-parse.
- **Solusi**: jalankan perintah `show` manual via terminal telnet, bandingkan dengan parser di
  `ZteProfileCatalogService::parse*`.

---

## Alarm & port PON

### Port PON dimatikan dari NMS & Save Config
- **Latar**: "Matikan Port" (ZTE C300/C320) menjalankan `shutdown` **tanpa `write`** — sengaja, supaya
  port menyala lagi bila OLT reboot. Penanda di NMS adalah alarm `port_disabled` yang terbuka.
- **Jebakan**: setiap `write` berikutnya ke OLT yang sama menyimpan **seluruh** running-config,
  termasuk `shutdown` itu. Jalur yang menulis `write`: tombol **Save Config**, edit deskripsi port
  (`smartolt.port.description`), tag VLAN uplink (`smartolt.port.vlan`), **Bind ONU** dengan centang
  simpan (bawaan nyala), registrasi C600, dan `write` manual di terminal telnet. Setelah itu port
  **tetap mati** walau OLT reboot.
- **Gejala sebaliknya** (tanpa `write`, OLT reboot): port hidup lagi, NMS masih menandainya
  dimatikan; 10 menit setelah `disabled_at` poll membaca oper UP → penanda dilepas dengan notifikasi
  "… terbaca menyala lagi (dinyalakan di luar NMS)". Hal yang sama terjadi bila seseorang mengetik
  `no shutdown` langsung di CLI.
- **Solusi**: nyalakan port dari tombol NMS **sebelum** menekan Save Config (kecuali memang ingin port
  mati permanen). Cek port yang sedang dimatikan: Alarms → filter jenis "Port PON dimatikan admin",
  atau banner amber di Detail Port. Admin status CLI setelah `shutdown` belum pernah diamati teksnya —
  UI memakai penanda alarm, bukan teks itu.
- ⚠️ Saat meneliti sintaks di terminal: executor mengirim per baris + Enter, jadi `shutdown ?`
  **menjalankan** shutdown. Pakai `?` polos.

### Alarm port/ONU tidak muncul di port tertentu
- Port sedang ditandai `port_disabled` → `port_down`, alarm ONU, dan `odp_down` di port itu ditahan
  apa pun saklar korelasi ([10 §A](10-alarm-telegram.md#port-yang-dimatikan-dari-nms-port_disabled)).
- Port down **tanpa ONU terdaftar** dianggap tak dipakai → tidak dialarmkan (episode lama ditutup diam).
- Debounce 2 poll aktif → fault baru berstatus `pending` (tak tampil di UI) sampai terkonfirmasi di
  poll berikutnya (~10 menit pada interval 5 menit).
- Alarm non-persisten (`onu_offline`, `dying_gasp`) hilang dari bel dan filter **Aktif** begitu pengguna
  membacanya — lihat status **Semua** di halaman Alarms.

---

## Telegram

### Notifikasi tidak terkirim
- **Kebijakan alarm** kini di **Pengaturan → Alarm** (`alarm_settings`), bukan di tab Bot Telegram:
  `min_severity`, `notify_on_raise`/`notify_on_clear` (bawaan clear **mati** — notifikasi pemulihan tak
  terkirim), `notify_types` (daftar eksplisit harus memuat jenisnya, mis. `port_disabled`; `[]` = tak
  ada yang dikirim). Bot partner memakai filter miliknya sendiri.
- **Saklar per-OLT**: tombol alarm On/Off di daftar OLT (`snmp_olts.alarms_enabled` untuk admin/operator,
  `olt_user.alarms_enabled` per partner) menghentikan pengiriman ke penerima itu walau alarm tetap
  tercatat. OLT privat partner tak pernah dikirim ke admin/operator.
- **Koneksi**: `telegram_settings.enabled` + `isReady()` (token + chat_id). Lihat
  `last_error`/`last_sent_at`. Tombol **Test** di tab Bot Telegram memanggil `sendTest()`.

### Command bot tidak dibalas
- **Cek**: `commands_enabled` + `webhook_secret` set (`commandsReady()`), webhook terdaftar
  (`php artisan telegram:webhook info`), chat ada di allow-list (`chat_id`). Webhook route publik
  butuh akses internet dari Telegram + header secret cocok.

---

## Audit & data

### Audit log membengkak
- **Catatan**: `audit_logs` immutable & bertambah terus. Pertimbangkan job pruning periodik bila
  perlu (belum ada di kode). Jangan hapus manual tanpa kebijakan retensi.

### Data demo bocor ke produksi (atau sebaliknya)
- **Penyebab**: lupa `is_demo`/`DemoScope`, atau `DemoSeeder` dijalankan di DB prod.
- **Solusi**: jangan jalankan `DemoSeeder` di prod; entitas baru yang perlu dipisah harus punya
  `is_demo` + `DemoScope`. Lihat [11](11-keamanan-rbac-audit.md).

---

## Peta & ODP

### ODP terhapus tidak sengaja
Hapus ODP bersifat permanen (tanpa soft delete), kaitan `onu_odp_links`-nya ikut cascade, fotonya
ikut dibuang, dan aksinya **tidak tercatat di `audit_logs`**. Pemulihan hanya dari cadangan database:

1. Cari ID + jam hapus di log akses web server (mis. `grep "DELETE /map/odps/" access.log`).
2. Ambil barisnya dari dump cadangan terbaru sebelum jam itu, mis.
   `pg_restore --data-only -t odps -f - <dump>` (juga `-t onu_odp_links`).
3. `INSERT` ulang dengan ID asli, dalam transaksi.
4. **Jangan langsung memulihkan kaitan ONU dari dump**: cek dulu apakah posisi (OLT/slot/port/onu_id)
   masih ONU yang sama — posisi bisa sudah dipakai pelanggan lain.

### Tombol di halaman (warna ODP, dll.) ditekan, tak ada yang muncul
Modal di-mount lewat `v-if` dengan `:show="true"`. `Modal.vue` membuka dialog di dalam watcher `show`,
jadi komponen yang baru di-mount saat `show` sudah `true` tak pernah terbuka. Render modal terus dan
ikat `:show` ke keadaan terpilih.

### Peta berat / tersendat dengan ribuan pin
Periksa `DOM_LIMIT` di `OnuMap.vue` dan bahwa garis diam tetap di kanvas — lihat
[16 §Kinerja](16-peta-onu.md#kinerja-halaman-peta-aturan-yang-harus-dijaga).

## Perintah maintenance berguna

```bash
php artisan route:list                 # semua route + nama
php artisan queue:work / queue:restart # worker
supervisorctl status                   # daemon prod
php artisan pail                        # tail log realtime (dev)
php artisan tinker                      # REPL (cek model/cache)
php artisan optimize                    # cache config+route+view (prod; sebagai www-data)
php artisan route:cache                 # WAJIB setelah menambah rute (lihat di atas)
# optimize:clear / config:clear di prod menghapus cache produksi — jangan dipakai sebagai jalan pintas
./vendor/bin/pint                      # code style
bash scripts/test.sh                   # PHPUnit — SELALU lewat skrip ini (lihat bagian troubleshooting)
php artisan horizon                    # dashboard queue (dev)
```

## Selanjutnya

→ [14 — Panduan Menambah Fitur](14-panduan-tambah-fitur.md)
