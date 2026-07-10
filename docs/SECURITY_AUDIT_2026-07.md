# Audit Keamanan & Checklist Hardening — Juli 2026

Target: `nms.kusumavision.net` (KusumaVision NMS produksi).
Lingkup: review kode attack-surface + patch dependency + hardening infra.
Referensi terkait: [`LOCAL_PRODUCTION_HARDENING.md`](LOCAL_PRODUCTION_HARDENING.md),
[handbook 11 — Keamanan/RBAC/Audit](handbook/11-keamanan-rbac-audit.md).

## 1. Temuan & Remediasi

| # | Temuan | Severity | Status |
|---|--------|----------|--------|
| 1 | **Injeksi perintah CLI** lewat field teks-bebas registrasi ONU ZTE (`customer_name`, `serial_number`, `pppoe_username/password`, `acs_username/password`) — newline di field menyisipkan perintah config-mode ke sesi telnet OLT | 🔴 Tinggi | ✅ Diperbaiki |
| 2 | Dependency dengan CVE: `guzzlehttp/guzzle`, `guzzlehttp/psr7` (CRLF injection), `laravel/framework` (Signed URL path confusion), `phpseclib` (X.509) | 🟡 Medium | ✅ Di-update |
| 3 | Endpoint refresh/test full-OLT (SNMP walk / telnet sinkron) tanpa rate-limit → DoS oleh user terautentikasi | 🟡 Medium | ✅ Throttle ditambah |
| 4 | Header `Strict-Transport-Security` (HSTS) belum ada di nginx | 🟢 Rendah | ✅ Sudah ada di nginx 443 live; template repo diselaraskan |
| 5 | `APP_DEBUG` produksi | — | ✅ Terverifikasi `false` |
| 6 | **SSRF** di resolver link Peta ONU — gate host tak ter-anchor + Guzzle follow-redirect otomatis → bisa menembak metadata cloud/host internal | 🟡 Medium | ✅ Diperbaiki |
| 7 | Password admin contoh `P@ssw0rd123` terdokumentasi di README/INSTALL | 🟢 Rendah | ✅ Jadi placeholder — **rotasi password admin live bila pernah dipakai** |
| 8 | `user:create` tak set role → jatuh ke default DB `operator` (writer) | 🟢 Rendah | ✅ Tambah opsi `--role` tervalidasi enum |

### 1.1 Injeksi CLI (temuan utama)
- **Titik sink:** skrip provisioning dieksekusi baris-per-baris ke telnet OLT
  ([`ZteCliProvisioningExecutor`](../app/Services/ZteCliProvisioningExecutor.php) memecah dengan `"\n"`).
  Field teks-bebas disisipkan mentah oleh
  [`ZteProvisioningScriptBuilder`](../app/Services/ZteProvisioningScriptBuilder.php).
- **Perbaikan (2 lapis):**
  1. **Sanitasi di builder** — helper `cli()` membuang semua karakter kontrol (CR/LF, `\x00-\x1F`, `\x7F`)
     jadi spasi sebelum interpolasi. Diterapkan ke serial, nama, kredensial PPPoE & ACS. Juga di
     [`ZteOnuReconfigureScriptBuilder::str()`](../app/Services/ZteOnuReconfigureScriptBuilder.php) (jalur copy-ONU).
  2. **Validasi** di [`OnuRegistrationService::rules()`](../app/Services/Zte/OnuRegistrationService.php) —
     regex anchor `\z` (bukan `$`, mencegah bypass trailing-newline): serial `^[A-Za-z0-9:_.-]+\z`,
     kredensial `^\S+\z`, nama `not_regex` blokir karakter kontrol.
- **Catatan:** jalur write yang lain sudah aman — rename ONU ZTE = SNMP SET (bukan CLI);
  C-Data (`sanitizeDescription`) & HiOSO (`sanitizeName`) sudah menyanitasi.
- **Uji:** `tests/Unit/ZteOnuConfigureTest.php::test_provisioning_builder_neutralizes_cli_injection_in_free_text_fields`.

### 1.2 Dependency
`composer update` terarah → Laravel `12.63.0`, guzzle/psr7/phpseclib ter-patch.
`composer audit` = **bersih**. `npm audit` = 0.

### 1.3 Rate-limit refresh
Limiter `olt-refresh` (30 req/mnt/user) di [`AppServiceProvider`](../app/Providers/AppServiceProvider.php),
dipasang ke `smartolt.test|refresh`, `cdata-olt.test|refresh`, `hioso-olt.test|refresh`,
`monitoring.onu.refresh`.

### 1.4 SSRF resolver link Peta ONU (ronde 2)
- **Titik:** [`OnuMapController::resolveLink`](../app/Http/Controllers/OnuMapController.php) memanggil
  `expandShortLink()` (`Http::get`) untuk link pendek Google Maps. Gate lama `preg_match` **tak ter-anchor**
  → `http://169.254.169.254/#https://goo.gl/maps` lolos (substring cocok), lalu di-fetch = SSRF. Guzzle
  juga follow-redirect otomatis → short-link/open-redirect bisa membelok ke IP internal. Rute
  `map.resolve-link` di grup `auth` (semua user login, termasuk partner).
- **Perbaikan:** gate host via `parse_url` + allowlist persis (`maps.app.goo.gl`, `goo.gl`) — bukan substring;
  `expandShortLink` matikan redirect otomatis & validasi **tiap hop** (`hostResolvesPublic`) menolak scheme
  non-http(s) dan IP privat/loopback/link-local/reserved (mis. `169.254.169.254`, `127.0.0.1`, `10/8`,
  `192.168/16`, IP OLT internal). Uji: `tests/Unit/OnuMapLinkResolverTest.php`.

### 1.5 Password admin contoh & default role (ronde 2)
- README/INSTALL sebelumnya menampilkan `ADMIN_PASSWORD='P@ssw0rd123'` → diganti `GANTI_DENGAN_PASSWORD_KUAT`.
  `install.sh` sendiri **tak** hardcode password (default kosong). **Aksi wajib:** bila admin live pernah
  dibuat dengan password itu, **rotasi sekarang** (`php artisan user:create` untuk akun baru, atau reset via
  Settings/DB).
- `user:create` kini punya `--role` (tervalidasi `UserRole::values()`, default `operator`) dan meng-set role
  eksplisit — tak lagi bergantung diam-diam ke default kolom DB. Pertimbangan lanjutan (opsional): turunkan
  default kolom `users.role` ke role paling rendah via migrasi baru.

## 2. Postur yang sudah AMAN (terverifikasi review)
- **Isolasi partner (IDOR):** global scope `PartnerOltScope` di `SnmpOlt` → OLT partner lain = 404.
- **Brute-force login:** throttle `6/mnt` + `RateLimiter` 5 percobaan (web); login API `10/mnt`.
- **Tiket telnet:** AES terenkripsi (APP_KEY), TTL 60 dtk, `exp` divalidasi.
- **Secret OLT:** cast `encrypted` + `$hidden`; `.env` `640 root:www-data`.
- **Surface tereduksi:** SNMP v3 & SSH sengaja dinonaktifkan; API punya saklar `$apiEnabled`.

## 3. Checklist deploy perubahan ini (di server produksi)
```bash
cd /var/www/KusumaVisionNMS
git pull
composer install --no-dev --optimize-autoloader   # ambil composer.lock baru (patch CVE)
php artisan config:cache
sudo systemctl reload php8.3-fpm                    # muat kode PHP baru (builder/validasi)
php artisan queue:restart                           # worker pakai builder → wajib restart
```

## 4. Checklist hardening ops (manual, belum bisa dari repo)
- [ ] **Apply HSTS ke nginx 443 live.** Tambahkan pada blok `server { listen 443 ssl; ... }`
      (biasanya dibuat certbot) — template `install.sh`/`docker/nginx.conf` sudah punya barisnya:
      `add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;`
      lalu `sudo nginx -t && sudo systemctl reload nginx`.
- [ ] **Verifikasi header** setelah reload:
      `curl -sI https://nms.kusumavision.net | grep -iE "strict-transport|x-frame|x-content|referrer"`
- [ ] **Audit berkala** (mis. bulanan / sebelum rilis): `composer audit` + `npm audit`.
- [ ] Pastikan `.env` tetap `640 root:www-data` (kalau `root:root`, config clear → jatuh ke sqlite → 500).
- [ ] Backup DB `kusumavision_nms` terjadwal (di luar server).
- [ ] Tinjau token Sanctum lama yang tak terpakai (`personal_access_tokens`) secara berkala.
- [ ] **Rotasi password admin** bila pernah dibuat dengan contoh lama `P@ssw0rd123` (lihat §1.5).
- [ ] **SNMP community OLT:** docs menyebut OLT live (mis. id=277) memakai community `public`. Bila itu
      community asli di OLT produksi, ganti ke nilai non-default di perangkat + batasi UDP/161 via ACL/firewall
      (isu konfig OLT, bukan bocoran repo). Community tersimpan terenkripsi di `snmp_olts`.
- [ ] (Opsional) Pertimbangkan Content-Security-Policy setelah audit inline script/style Inertia.

## 5. Ringkasan verifikasi
- 80/80 unit test lolos; `route:list` boot OK; Pint bersih.
- `composer audit` bersih; `npm audit` 0.
- Test injeksi membuktikan payload `\nno onu 5` menyatu jadi satu baris `name`, bukan perintah terpisah.
- Test SSRF membuktikan gate host menolak metadata cloud/IP internal & scheme non-http(s).
