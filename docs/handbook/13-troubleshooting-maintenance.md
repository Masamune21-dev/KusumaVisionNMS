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

### Test "nyasar" ke PostgreSQL / DB produksi
- **Penyebab**: config ter-cache membuat `phpunit.xml` (SQLite testing) terabaikan.
- **Solusi**:
  ```bash
  php artisan config:clear
  php artisan test
  php artisan optimize        # cache lagi setelah selesai
  ```

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
- **Solusi**: sudah dibereskan di `bootstrap/app.php` (`$middleware->trustProxies(at: '*')`)
  sejak Jul 2026 — `git pull`, lalu `php artisan optimize` + reload php-fpm. Kalau belum bisa
  pull: pasang certbot di origin dan naikkan Cloudflare ke **Full (strict)** (origin melihat
  TLS sendiri). Disarankan tetap Full (strict) untuk produksi; Flexible kini juga jalan.
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
  go build -o bin/kv-snmp-poller ./cmd/kv-snmp-poller && chmod +x bin/kv-snmp-poller
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
  5. Tiket TTL hanya ~60s untuk **membuka** WS — buka segera setelah klik.
- **Output terpotong/aneh**: cek `TelnetIacFilter` (negotiation) — biasanya OK, tapi firmware
  unik bisa beda.

### Sync profil kosong/gagal
- **Penyebab**: kredensial CLI, atau format output `show ...` beda dari yang di-parse.
- **Solusi**: jalankan perintah `show` manual via terminal telnet, bandingkan dengan parser di
  `ZteProfileCatalogService::parse*`.

---

## Telegram

### Notifikasi tidak terkirim
- **Cek**: `telegram_settings.enabled` + `isReady()` (token + chat_id), `min_severity` tidak
  menyaring semua, `notify_on_raise/clear` sesuai. Lihat `last_error`/`last_sent_at`.
- Tombol **Test** di Settings memanggil `sendTest()` — pakai untuk verifikasi cepat.

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

## Perintah maintenance berguna

```bash
php artisan route:list                 # semua route + nama
php artisan queue:work / queue:restart # worker
supervisorctl status                   # daemon prod
php artisan pail                        # tail log realtime (dev)
php artisan tinker                      # REPL (cek model/cache)
php artisan optimize / optimize:clear   # cache config+route+view
./vendor/bin/pint                      # code style
php artisan test                       # PHPUnit (clear config dulu bila ter-cache)
php artisan horizon                    # dashboard queue (dev)
```

## Selanjutnya

→ [14 — Panduan Menambah Fitur](14-panduan-tambah-fitur.md)
