# 02 — Arsitektur

[← Indeks](README.md) · [← 01 Overview](01-overview.md) · [03 Struktur Folder →](03-struktur-folder.md)

> Diperbarui 30 Sep 2026.

## Diagram komponen (high level)

```
 Browser (Vue 3 + Inertia 2, xterm.js)        Aplikasi Android (Flutter, mobile/)
   │ HTTPS Inertia        │ WSS /telnet-ws           │ HTTPS /api/v1 (Bearer Sanctum)
   ▼                      ▼                          ▼
 nginx ──► PHP-FPM: Laravel 12                 telnet:proxy (daemon PHP, 127.0.0.1:6002)
           ├─ web  : Controllers → Services    TelnetProxyServer: WS ↔ telnet :23 ke OLT
           └─ api  : Api/V1/* → Services       (tiket sekali pakai, cek izin ulang saat connect)
             │ SNMP & CLI telnet SINKRON (aksi user)
             │ dispatch job ─► Redis (cache · sesi · queue)
             ▼
 kusumavision-scheduler  (schedule:work)  ── olts:poll tiap menit, backup 02:30, RX agg/prune …
 kusumavision-worker     (queue:work redis --tries=1)
   PollOltJob ─┬─ ZTE     : bin/kv-snmp-poller (Go) ─gagal→ OltSnmpClient (PHP)
               └─ non-ZTE : CDataOltScanner (C-Data EPON/GPON, HiOSO) via SmartOltSnmpServiceResolver
            → last_test_result (JSON) + onu_rx_samples → AlarmEvaluator
            → TelegramNotifier (langsung) + SendFcmAlarmNotifications (job) → push FCM
   BackupOltConfigJob · CopyOnusToPortJob · Tr069BulkConfigJob · SendFcmAlarmNotifications
             │ SNMP v1/v2c (UDP 161) + telnet (TCP 23)
             ▼
 OLT: ZTE C300/C320/C600 · C-Data EPON/GPON (FD1xxx/FD16xx) · HiOSO EPON (HA7304/HA7302)

 (opsional) Laravel ──HTTP NBI :7557 (jaringan privat)──► GenieACS ◄──CWMP :7547── ONU
            genieacs:match-onu (15 mnt) · perangkat terhubung · ubah WiFi

Penyimpanan: PostgreSQL `kusumavision_nms` (inventory, registrasi, alarm, audit, ODP/peta, setelan)
             Redis (cache, sesi, queue, tiket telnet) · snmp_olts.last_test_result = cache live-state
```

## Proses yang berjalan

| Proses | Perintah | Peran |
|--------|----------|-------|
| PHP-FPM (vhost nginx) | — | Web Inertia + REST API v1 |
| `kusumavision-worker` | `php artisan queue:work redis --tries=1` | Semua job antrean (polling, backup, salin ONU, TR069 massal, push FCM) |
| `kusumavision-scheduler` | `php artisan schedule:work` | Menjalankan jadwal `routes/console.php` |
| `kusumavision-telnet-proxy` | `php artisan telnet:proxy` | Jembatan WebSocket ↔ telnet untuk terminal browser |

Nama program sama di supervisor `install.sh` dan `docker/supervisord.conf`. Horizon terpasang
sebagai paket, tetapi worker produksi memakai `queue:work` biasa. Poller Go **bukan daemon** —
binary dipanggil sekali per job lewat Symfony `Process`. Ketiga daemon **long-lived**: kode baru
baru terpakai setelah `queue:restart` / restart program supervisor.

## Tiga jalur eksekusi (ingat baik-baik)

### 1. On-demand / sinkron (di dalam request HTTP)
Aksi yang dipicu user dijalankan langsung di controller/service, **tidak lewat queue**:

- Test koneksi, refresh snapshot, refresh port ONU, Refresh Discovery unconfigured →
  SNMP langsung dari PHP (`OltSnmpClient` untuk ZTE; driver resolver + `CDataOltScanner` untuk
  C-Data/HiOSO).
- Provisioning/registrasi ONU, Configure ONU, Bind ONU, sync profil, refresh kartu/uplink,
  RX per-port, reboot/hapus ONU, deskripsi & matikan/nyalakan port PON, Save Config, backup
  config manual → CLI telnet (`ZteCliProvisioningExecutor`, `CDataCliWriteService`,
  `HiosoCliWriteService`, `CDataGponPortService`) atau SNMP SET (rename/enable-disable ZTE).
- **(opsional) GenieACS**: panel perangkat terhubung, ubah WiFi, dan tarik ulang katalog →
  HTTP ke NBI GenieACS (`GenieacsCredential::client()`). Daftar ONU **tidak** pernah memanggil ACS.

Request bisa lama (beberapa detik; `write` C300 bisa ~30 detik) — **disengaja**, hasilnya
ditulis ke cache dan langsung dipakai render. Aksi on-demand yang mahal diberi
`throttle:olt-refresh` (30/menit per user).

### 2. Job antrean on-demand (dipicu user, dipantau progresnya)
- **Salin ONU ke port lain** → `CopyOnusToPortJob` + tabel `copy_onu_tasks`.
- **TR069 massal per port** → `Tr069BulkConfigJob` + tabel `tr069_bulk_tasks`.
- **Push FCM** → `SendFcmAlarmNotifications` (di-dispatch `AlarmEvaluator` supaya tak menahan poll).

Frontend mem-poll endpoint status task sampai selesai.

### 3. Terjadwal (scheduler → queue worker)

| Jadwal (`routes/console.php`) | Command | Isi |
|------|---------|-----|
| tiap menit, `withoutOverlapping` | `olts:poll` | Dispatch `PollOltJob` untuk tiap OLT `polling_enabled` yang due (`isPollDue()`), ZTE **dan** non-ZTE |
| tiap jam menit ke-5 | `optical:aggregate-rx` | Ringkas `onu_rx_samples` → `onu_rx_hourly` |
| harian 03:15 | `optical:prune-rx` | Retensi RX (mentah 3 hari, per jam 45 hari) |
| harian 03:40 | `sanctum:prune-expired --hours=24` | Buang token API kedaluwarsa (token FCM terkait ikut terhapus lewat FK cascade) |
| harian 02:30 | `olts:backup-config` | `BackupOltConfigJob` untuk OLT ZTE ber-`config_backup_enabled` |
| tiap 15 menit, `withoutOverlapping(10)`, `runInBackground` | `genieacs:match-onu` | Cocokkan device GenieACS ↔ posisi ONU ke `genieacs_device_map`; tanpa NBI di Pengaturan selesai diam-diam |

Unconfigured discovery **tidak** ikut polling terjadwal — hanya diperbarui saat Refresh Discovery.

## Polling terjadwal — `PollOltJob`

`app/Jobs/PollOltJob.php` (`tries=1`, `timeout=600`, `WithoutOverlapping($oltId)`), memilih jalur
berdasarkan `SmartOltSupport::driverKey()`:

- **ZTE (C300/C320/C600)** — pakai **Go poller** (`GoSnmpPoller` → `bin/kv-snmp-poller`) bila
  `SNMP_POLLER_DRIVER=go` + binary ada (produksi: `go`; default config `php`). Bila Go gagal →
  `go_poller_error` diisi dan **fallback** ke `OltSnmpClient::snapshot()` + `registeredOnus()`.
  Go memetakan tabel ONU C600 (subtree `.1082`) secara native; bila hasil ONU C600 kosong, fallback
  PHP tetap dipakai untuk daftar ONU. RX punya jadwal sendiri (`rx_poll_interval_minutes`); saat
  tidak due nilai RX lama dipertahankan. `poller` = `go` | `php`.
- **Non-ZTE (C-Data EPON/GPON & HiOSO)** — `pollViaScanner()`: scan penuh lewat
  `CDataOltScanner` (driver dari `SmartOltSnmpServiceResolver`: `CDataEponSnmpService`,
  `CDataGponSnmpService` + enrich CLI `CDataGponCliService`, `HiosoEponSnmpService`), yang menulis
  `port_onus` berbentuk sama dengan ZTE. Job menambahkan penanda `ok`/`error` top-level (dibutuhkan
  `AlarmEvaluator`), `poller` = `cdata`.
- Keduanya lalu: simpan sampel RX ke `onu_rx_samples` bila RX due & sukses, panggil
  `AlarmEvaluator::evaluate($olt, $previousSnapshot)`, dan catat `PollingEvent`
  (`olt_poll`, + `rx_poll` bila due dan OLT terjangkau).

Detail OID, flag Go, dan retensi RX di [08 — SNMP & Polling](08-snmp-polling.md).

## Cache live-state: `snmp_olts.last_test_result`

Tidak ada tabel ONU. State terkini OLT disimpan sebagai **satu blob JSON** per OLT (cast
`array`). Kunci yang dipakai kode:

```jsonc
{
  "ok": true, "error": null,
  "driver": "zte", "latency_ms": 42,
  "system": { "sys_descr": "...", "sys_object_id": "...", "sys_name": "...", ... },
  "ports": [ { "slot": 1, "port": 1, "if_index": 285278465, "oper_status": "up", ... } ],
  "poller": "go",                 // "go" | "php" (ZTE) | "cdata" (non-ZTE)
  "go_poller_error": null,
  "polled_at": "2026-09-30T...",
  "onu_poll_error": null,
  "port_onus": {                  // di-bucket per "slot_port"
    "1_1": {
      "ok": true, "slot": 1, "port": 1, "port_row": { ... },
      "onus": [ { "onu_id": 1, "serial_number": "ZTEG...", "rx_power_dbm": -22.1, ... } ],
      "count": 12,
      "rx_power": { "ok": true, "source": "snmp", "count": 10, "polled_at": "..." },
      "refreshed_at": "..."
    }
  },
  "unconfigured_onus": { "onus": [ { "serial_number": "...", "first_seen_at": "...", ... } ] },
  "unconfigured_seen": { "ZTEG...": { "at": "...", "baseline": false } },
  // non-ZTE saja:
  "onu_scanned_at": "...", "panel": { ... },   // faceplate panel depan
  "cdata": { "firmware_v3": true }
}
```

- ONU Monitoring, global search (`GlobalSearchService`), Peta (`OnuInventoryService`) dan REST API
  membaca `port_onus` lintas OLT.
- Cast `array` men-decode ulang JSON **tiap akses atribut** — layanan lintas-OLT
  (`OnuInventoryService`) memo snapshot per OLT; jangan membaca atribut itu di dalam loop.
- Aksi yang menulis cache harus `array_merge`, **bukan menimpa** (menimpa = ports/ONU hasil poll
  hilang).

### Side-store di luar cache
Data yang harus selamat dari scan/poll disimpan di tabel sendiri yang menunjuk ONU/port lewat
**referensi posisi** `(snmp_olt_id, slot, port[, onu_id])`: `onu_map_pins`, `odps` +
`onu_odp_links`, `olt_port_labels`, dan `genieacs_device_map` (pasangan ONU↔device GenieACS).
Riwayat RX ada di
`onu_rx_samples`/`onu_rx_hourly`; kartu & interface ZTE hasil CLI di
`smartolt_card_statuses`/`smartolt_interface_statuses`. Lihat [05](05-database-model.md).

## Alarm engine & notifikasi

`AlarmEvaluator::evaluate()` membandingkan snapshot lama vs baru dan **selalu** berjalan (event
tetap tercatat walau saklar alarm OLT off). Ringkasan perilaku — detail di
[10 — Alarm & Telegram](10-alarm-telegram.md):

- **Transisi saja**: alarm hanya naik pada sehat → fault.
- **Debounce 2 poll** (`alarm_settings.confirm_before_notify`): deteksi pertama disimpan
  `STATUS_PENDING` (tak tampil di UI); baru `ACTIVE` + dikirim bila masih ada di poll berikutnya;
  pulih lebih dulu → baris pending dihapus diam-diam.
- **Korelasi root-cause** (`suppress_child_alarms`): OLT unreachable menahan port & ONU; port PON
  down menahan ONU di port itu; semua ONU satu ODP mati → satu `odp_down`
  (`App\Services\Alarm\OdpAlarmGrouper`). Port yang terbaca utuh **tanpa ONU terdaftar** dianggap
  tak dipakai (down-nya tak dialarmkan).
- **`port_disabled`**: dinaikkan langsung oleh aksi "Matikan Port" (tanpa debounce) dan selama
  terbuka menahan alarm port/ONU/ODP di port itu; "Nyalakan Port" menutupnya + satu notifikasi.
- **Kebijakan terpusat** di `alarm_settings` (severity minimum, raise/clear, jenis, korelasi,
  pengelompokan ODP). `telegram_settings`/`fcm_settings` hanya saklar kanal.
- **Pengiriman**: `TelegramNotifier` dipanggil langsung oleh `AlarmEvaluator` (di worker saat poll,
  atau di request web untuk `port_disabled`); push FCM lewat job
  `SendFcmAlarmNotifications` (hanya bila kredensial Firebase ada & diaktifkan). Gerbang per
  penerima: `snmp_olts.alarms_enabled` (admin+operator) dan `olt_user.alarms_enabled` (partner).
- **Bel notifikasi**: target klik ditentukan server (`AlarmNotificationTargetResolver`, membaca
  cache saja), status baca per user di `alarm_notification_reads`.

## Driver & capability gating

`App\Support\SmartOltSupport` adalah gerbang multi-vendor:

- `driverKey($olt, $sysDescr, $sysObjectId)` → `zte` (needle `zte|3902|c300|c320|c600`, diperiksa
  lebih dulu), `hioso-epon-25355` (`hioso|ha7304|25355`), `cdata-gpon-34592` (mis. `34592`,
  `fd1601/fd1602/fd1604/fd1608`, `cdata gpon`), `cdata-epon-17409` (`17409|nscrtv|…|epon`, atau
  `cdata` polos), selain itu `unknown`. **V-Sol tidak dikenali** (firmware beda) dan **HsAirPo/HSGQ
  sudah dihapus** (29 Sep 2026) — OLT semacam itu jatuh ke deteksi generik.
- `isNonZte()` = C-Data atau HiOSO → jalur scanner, controller & halaman sendiri
  (`CDataOltController`/`cdata-olt.*`, `HiosoOltController`/`hioso-olt.*`);
  `inventoryRoutePrefix()` memilih prefix rute lintas halaman (search, monitoring, peta).
- `capabilities($driver, $olt)` → array boolean fitur (`supports_provisioning`,
  `supports_onu_toggle`, `supports_onu_replace`, `supports_port_admin_write`,
  `supports_port_label`, `supports_cli_vlan`, …). `unknown` = semua false. C600 mematikan beberapa
  fitur tulis gaya C300 (`isC600()`); C-Data V3 membuka fitur CLI lewat `hasCDataV3Cli()`.
- Controller memanggil `assertCapability($olt, 'supports_xxx')` → 403 bila tidak didukung; UI
  membaca `olt.capabilities`. **Capability hanya dinyalakan setelah perintah/OID terverifikasi di
  perangkat asli** — yang belum terbukti dibiarkan `false`.

## REST API v1 & aplikasi Android

- `routes/api.php` (prefix `/api/v1`, `$apiEnabled = true`), controller `app/Http/Controllers/Api/V1/*`,
  token **Sanctum** (Bearer), throttle `api` 120/menit. Baca: summary, OLT + capabilities, ONU,
  port-onus, search, unconfigured, alarm (+ blok `target` dari resolver yang sama dengan bel web),
  ODP, peta; token perangkat FCM (`devices`) didaftarkan di grup bertoken ini juga. Tulis (grup
  `role:admin,operator,partner` + `BlockDemoWrites`): register & refresh ONU (ZTE),
  reboot/rename/hapus ONU per family, warna & foto ODP. Opsional GenieACS: `acs-clients` (baca) &
  `acs-wifi` (tulis), keduanya hanya admin/operator di controller; ONU di API membawa field `acs`.
- Aplikasi `mobile/` (Flutter) memakai API ini + push FCM. Dokumentasi endpoint: `docs/API.md`.
- `POST auth/login` (throttle 10/menit) memeriksa password lokal (`Hash::check`) lalu menerbitkan
  token Sanctum; grup bertoken memakai `['auth:sanctum', 'throttle:api']`. Token berakses penuh
  (baca + tulis sesuai role); masa berlakunya satu untuk semua token, `SANCTUM_EXPIRATION`.
- **Rute (web & API) ikut di-cache di produksi** → rute baru 404/405 sampai `php artisan route:cache`.

## Autentikasi web

Login lokal **Laravel Breeze** (`routes/auth.php`): `login`, lupa/reset password, verifikasi email,
konfirmasi password, ganti password, `logout`. Rute registrasi **tidak** didaftarkan — akun dibuat
admin di `/users` atau lewat `php artisan user:create`. Percobaan login dibatasi lajunya
(`App\Http\Requests\Auth\LoginRequest`).

## Middleware stack (request masuk)

`bootstrap/app.php`:

- Grup `web` (append, berurutan): `ContentSecurityPolicy` (nonce CSP) → `SetLocale` →
  `HandleInertiaRequests` → `BlockDemoWrites` → `AddLinkHeadersForPreloadedAssets`.
- Grup `api` (append): `BlockDemoWrites` (demo read-only juga di API).
- Alias: `role` → `EnsureUserRole` (`->middleware('role:admin,operator')`).
- CSRF dikecualikan untuk `telegram/webhook` dan `telegram/webhook/*` (bot partner) — gerbangnya
  header secret token.
- Cookie tema `kv_theme` sengaja tidak dienkripsi (ditulis juga oleh JS).
- Proxy tepercaya dari `config/trustedproxy.php` (`TRUSTED_PROXIES`, bawaan `127.0.0.1,::1`).
  Di belakang Cloudflare "Flexible" / load balancer di host lain, isi alamatnya — kalau tidak,
  URL jadi `http://` dan form berakhir 419.
- Exception `/api/*` selalu dirender JSON.

`HandleInertiaRequests` men-share `auth` (+ `auth.can.*`), `flash`, `notifications`, `systemInfo`,
`branding`, `locale`, `locales`, dan `theme`. Detail RBAC, kepemilikan OLT partner
(`PartnerOltScope`), dan demo di [11 — Keamanan, RBAC & Audit](11-keamanan-rbac-audit.md).

## Audit trail otomatis

Trait `App\Models\Concerns\Auditable` mengaitkan event `created/updated/deleted` ke `audit_logs`
via `AuditLogger`. Aksi perangkat (bind ONU, matikan port, telnet dibuka, dll.) memanggil
`AuditLogger::log()` eksplisit. Event login/logout/failed dicatat di `AppServiceProvider::boot()`.
Atribut sensitif (`$hidden`, password, dan `$auditExclude` per-model) tidak ikut tersimpan.
Catatan: hapus ODP **tidak** tercatat di `audit_logs`.

## Integrasi GenieACS / TR-069 (opsional)

- NMS **tidak** menjalankan GenieACS; ia memakai server GenieACS yang sudah ada dan membaca **NBI**
  (port 7557) yang alamatnya diisi admin di Pengaturan → tab ACS / TR069 (`genieacs_credentials`).
  ONU tetap berbicara CWMP (7547) langsung ke ACS.
- **NBI GenieACS tanpa autentikasi** dan bisa mengedit *provision script* — jangan pernah dibuka ke
  internet atau di-proxy ke browser; jangkau lewat jaringan privat/VPN/reverse proxy berautentikasi.
- Hasil pencocokan (serial persis, MAC ±1, atau semat manual) disimpan di `genieacs_device_map`;
  halaman ONU hanya membaca tabel itu. Yang memanggil NBI hanyalah `genieacs:match-onu`, tombol tarik
  ulang katalog, panel perangkat terhubung, ubah WiFi, dan Uji koneksi di Pengaturan.
- Hanya OLT global non-demo yang dipasangkan; katalog ACS hanya untuk staf Pusat (admin/operator,
  `User::canManageAcs()`). Detail di [20 — GenieACS / TR-069](20-genieacs-tr069.md).

## Selanjutnya

→ [03 — Struktur Folder](03-struktur-folder.md) · [08 — SNMP & Polling](08-snmp-polling.md) ·
[10 — Alarm & Telegram](10-alarm-telegram.md)
