# 01 — Gambaran Umum

[← Kembali ke indeks](README.md)

## Apa ini?

**KusumaVision NMS** adalah Network Management System untuk jaringan FTTH/GPON. Fungsinya
menggantikan SmartOLT / ZTE NetNumen untuk ISP Indonesia: mengelola inventory OLT (ZTE GPON
C300/C320/C600, C-Data EPON/GPON, HiOSO EPON), memantau port PON & ONU, melakukan provisioning
ONU (ZTE), mengelola profil layanan, memunculkan alarm, mengirim notifikasi (Telegram + push
Android), memetakan ONU/ODP, dan menyediakan terminal telnet di browser serta REST API untuk
aplikasi Android.

Pemilik: **PT BERKAH MEDIA KUSUMA VISION (BMKV)**. UI dwibahasa ID/EN, locale bawaan `id`.
Lisensi open-source MIT (lihat `LICENSE`). Autentikasi memakai login lokal **Laravel Breeze**
(tanpa registrasi mandiri — akun dibuat admin atau lewat `php artisan user:create`).

## Tech stack

| Lapisan | Teknologi |
|---------|-----------|
| Backend | **Laravel 12**, PHP 8.2+ |
| Frontend | **Vue 3** + **Inertia.js 2** + **Tailwind CSS 3** (SPA-like, server-routed), dua tema (gelap/terang/ikut sistem) |
| i18n | **vue-i18n 11** (`resources/js/lang/{id,en}.json`) + `__()` Laravel (`lang/{id,en}/*.php`) |
| Build FE | **Vite 7**, `@vitejs/plugin-vue`, `laravel-vite-plugin`; test komponen **Vitest** (`tests/js`) |
| DB (app) | **PostgreSQL** (`kusumavision_nms`) |
| DB (test) | **SQLite in-memory** (lihat `phpunit.xml`) |
| Cache / session / queue | **Redis** (predis/phpredis) |
| Queue | Redis; worker supervisor `queue:work` (paket **Laravel Horizon** terpasang, tidak dipakai sebagai worker produksi) |
| API | **REST API v1** + **Laravel Sanctum** (Bearer token) — dipakai aplikasi Android |
| Realtime | **Laravel Reverb** (WebSocket) — dipakai oleh proxy telnet (react/socket + ratchet) |
| SNMP poller | **Go** (`bin/kv-snmp-poller`, sumber `cmd/kv-snmp-poller/main.go`, lib `gosnmp`) |
| PDF report | `barryvdh/laravel-dompdf` |
| Routing JS | `tightenco/ziggy` (route() di Vue) |
| CLI ke OLT | **Telnet** (raw TCP via phpseclib hanya untuk util; SSH belum diwire) |
| Charts | Chart.js (MIT, dimuat malas lewat `Components/Charts/ChartCanvas.vue`) |
| Peta | **Leaflet** (tile Google keyless + fallback OSM) |
| Terminal browser | `@xterm/xterm` + addon-fit |
| Notifikasi | Bot **Telegram** (push alarm + inbound command, bot global + bot per partner) + **FCM** (`kreait/laravel-firebase`) |
| Mobile | Aplikasi **Android Flutter** di `mobile/` |
| Konversi foto | Biner `cwebp` (PHP server ini tanpa GD/Imagick) |
| Integrasi ACS (opsional) | **GenieACS** lewat NBI (HTTP, port 7557) — dipanggil server, tak pernah di-proxy ke browser |

## Modul utama (yang benar-benar ada)

- **Dashboard** — kartu statistik, tren polling, inventory OLT per model, ringkasan provisioning,
  daftar OLT, alarm terbaru, aksi cepat ONU. (`DashboardController` + `DashboardStatsService`)
- **SmartOLT (ZTE)** — CRUD OLT, test koneksi SNMP, refresh snapshot, detail hardware (visualisasi
  chassis card/port), halaman PON Port, detail port per-interface (trafik/SFP/VLAN, deskripsi port,
  **matikan/nyalakan port PON** C300/C320), daftar ONU per port, ONU detail, reboot/enable/disable/
  hapus ONU, edit nama/deskripsi ONU, Configure ONU (diff CLI), salin ONU ke port lain, TR069
  massal per port. (`SmartOltController`)
- **Provisioning ONU (ZTE)** — form register ONU (Sederhana / Lanjutan / C600) → generate script CLI
  → eksekusi telnet (opsional); **Bind ONU** (ganti ONU rusak tanpa register ulang, C300/C320).
  Audit row di `smartolt_onu_registrations`. (`SmartOltController` + `ZteProvisioningScriptBuilder`
  / `ZteC600ProvisioningScriptBuilder` / `ZteOnuReconfigureScriptBuilder`)
- **C-Data EPON/GPON & HiOSO EPON** — controller, rute & halaman sendiri (`CDataOltController`,
  `HiosoOltController`): inventory ONU via SNMP (+ enrich CLI C-Data GPON), faceplate panel depan,
  rename/reboot/enable-disable/hapus ONU via CLI, label port sisi-NMS, VLAN & detail port C-Data V3.
- **Profil** — katalog profil per-OLT (onu_type/tcont/vlan/ip), sync dari OLT via CLI.
  (`SmartOltProfileController` + `ZteProfileCatalogService`)
- **ONU Monitoring** — agregasi ONU lintas semua OLT dari cache `port_onus`. (route `monitoring.onu`)
- **Unconfigured** — daftar ONU yang terdeteksi tapi belum di-provision (global), dengan kolom
  "Pertama Terlihat".
- **Peta & ODP** — pin ONU/ODP lintas OLT (Leaflet), relasi ONU↔ODP, warna & foto ODP, halaman ODP.
- **Save Config & Backup config** — simpan running-config ke memori OLT (semua family); backup
  running-config ZTE ke DB (manual + harian) dengan diff versi.
- **Alarms** — daftar & riwayat alarm (OLT unreachable, port down/disabled, ODP down, ONU
  offline/LOS/dying-gasp, RX), debounce 2 poll & korelasi root-cause; bel notifikasi.
- **Reports** — laporan (inventory ONU, RX power, status OLT, riwayat alarm, provisioning) +
  export CSV/PDF.
- **Users & RBAC** — admin/operator/partner/demo, audit logs, pengaturan umum, alarm, Telegram,
  notifikasi mobile, token API.
- **Browser Telnet** — terminal xterm.js ke OLT via WebSocket proxy.
- **GenieACS / TR-069 (opsional)** — tautkan ONU ke device TR-069 di server GenieACS yang sudah ada
  (serial / MAC ±1 / semat manual), lencana ter-ACS di tabel ONU, panel perangkat terhubung, ubah SSID
  & kata sandi WiFi. Diatur admin di Pengaturan → tab ACS / TR069. Lihat [20](20-genieacs-tr069.md).
- **REST API v1 + aplikasi Android** — lihat `docs/API.md` & `mobile/`.

## Scope nyata vs PRD (PENTING)

PRD (`docs/KusumaVision_NMS_PRD.md`) menggambarkan visi luas. **Yang dibangun lebih sempit.**
Jangan asumsikan fitur PRD ada di kode:

| Topik | Realita kode |
|-------|--------------|
| Vendor | **ZTE C300/C320/C600** (C600 cakupannya lebih sempit), **C-Data EPON/GPON**, **HiOSO EPON** (HA7304/HA7302). `SmartOltSupport::driverKey()` → `unknown` untuk vendor lain dan semua capability dimatikan. V-Sol tidak didukung; HsAirPo/HSGQ dihapus 29 Sep 2026. |
| SNMP | **read v1/v2c saja**. v3 → throw (`OltSnmpClient` & Go poller). |
| CLI | **Telnet saja**. `ZteCliProvisioningExecutor` (dan service CLI C-Data/HiOSO) menolak SSH. Telnet browser juga telnet-only. |
| Data store | **Tidak ada** TimescaleDB atau tabel `onus`/`optical_metrics`. Riwayat RX memang disimpan (`onu_rx_samples` + ringkasan `onu_rx_hourly`). Fitur AI juga belum ada (masih visi PRD). |
| Live state ONU | Disimpan sebagai **JSON di kolom `snmp_olts.last_test_result`** (bukan tabel ONU). |
| Polling terjadwal | ZTE **pakai engine Go** (`bin/kv-snmp-poller`) via `PollOltJob` (fallback PHP); C-Data/HiOSO lewat `CDataOltScanner`. Aksi on-demand (refresh manual, provisioning, telnet, RX per-port) = **PHP sinkron** di controller/service, kecuali batch salin ONU & TR069 massal (job antrean). |

> Catatan: `SMARTOLT_ZTE_C300_C320_C600_GUIDE.md` Section 6/7 (parseOnuDetailInfo, parseRunningConfig,
> ZteCliSessionService) adalah **blueprint dari proyek lain**, belum tentu 1:1 dengan kode di sini.

## Konteks bahasa

User berkomunikasi dalam **Bahasa Indonesia**. Seluruh string yang tampil ke pengguna
**dwibahasa ID/EN** (bawaan `id`): frontend lewat vue-i18n, backend lewat `__()`. Yang sengaja tetap
Indonesia: data yang disimpan/diantrekan (pesan alarm, push FCM, deskripsi audit, progres task)
dan log. Banyak komentar kode juga berbahasa Indonesia. Aturan lengkapnya di
[14](14-panduan-tambah-fitur.md).

## Selanjutnya

→ [02 — Arsitektur](02-arsitektur.md)
