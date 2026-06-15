# 01 — Gambaran Umum

[← Kembali ke indeks](README.md)

## Apa ini?

**KusumaVision NMS** adalah Network Management System untuk jaringan FTTH/GPON. Fungsinya
menggantikan SmartOLT / ZTE NetNumen untuk ISP Indonesia: mengelola inventory OLT ZTE GPON,
memantau port PON & ONU, melakukan provisioning ONU, mengelola profil layanan, memunculkan
alarm, mengirim notifikasi, dan menyediakan terminal telnet di browser.

Pemilik: **PT BERKAH MEDIA KUSUMA VISION (BMKV)**. Locale aplikasi `id` (Bahasa Indonesia).

## Tech stack

| Lapisan | Teknologi |
|---------|-----------|
| Backend | **Laravel 12**, PHP 8.2+ |
| Frontend | **Vue 3** + **Inertia.js 2** + **Tailwind CSS 3** (SPA-like, server-routed) |
| Build FE | **Vite 7**, `@vitejs/plugin-vue`, `laravel-vite-plugin` |
| DB (app) | **PostgreSQL** (`kusumavision_nms`) |
| DB (test) | **SQLite in-memory** (lihat `phpunit.xml`) |
| Cache / session / queue | **Redis** (predis/phpredis) |
| Queue dashboard | **Laravel Horizon** |
| Realtime | **Laravel Reverb** (WebSocket) — dipakai oleh proxy telnet (react/socket + ratchet) |
| SNMP poller | **Go** (`bin/kv-snmp-poller`, sumber `cmd/kv-snmp-poller/main.go`, lib `gosnmp`) |
| PDF report | `barryvdh/laravel-dompdf` |
| Routing JS | `tightenco/ziggy` (route() di Vue) |
| CLI ke OLT | **Telnet** (raw TCP via phpseclib hanya untuk util; SSH belum diwire) |
| Charts | ApexCharts (`vue3-apexcharts`) |
| Terminal browser | `@xterm/xterm` + addon-fit |
| Notifikasi | Bot **Telegram** (push alarm + inbound command) |

## Modul utama (yang benar-benar ada)

- **Dashboard** — kartu statistik, tren polling, inventory OLT per model, ringkasan provisioning,
  daftar OLT, alarm terbaru, aksi cepat ONU. (`DashboardController` + `DashboardStatsService`)
- **SmartOLT** — CRUD OLT, test koneksi SNMP, refresh snapshot, detail hardware (visualisasi chassis card/port),
  GPON ports, detail port per-interface (trafik/SFP/VLAN), daftar ONU per port, ONU detail, reboot/enable/disable ONU,
  edit nama/deskripsi ONU, reconfigure ONU (diff CLI). (`SmartOltController`)
- **Provisioning ONU** — form register ONU → generate script CLI → eksekusi telnet (opsional).
  Audit row di `smartolt_onu_registrations`. (`SmartOltController` + `ZteProvisioningScriptBuilder`)
- **Profil** — katalog profil per-OLT (onu_type/tcont/vlan/ip), sync dari OLT via CLI.
  (`SmartOltProfileController` + `ZteProfileCatalogService`)
- **ONU Monitoring** — agregasi ONU lintas semua OLT dari cache `port_onus`. (route `monitoring.onu`)
- **Unconfigured** — daftar ONU yang terdeteksi tapi belum di-provision (per-OLT & global).
- **Alarms** — daftar & riwayat alarm (port down, ONU offline, RX rendah, dll).
- **Reports** — laporan (inventory ONU, RX power, status OLT, riwayat alarm, provisioning) +
  export CSV/PDF.
- **Users & RBAC** — admin/operator/demo, audit logs, pengaturan umum + Telegram.
- **Browser Telnet** — terminal xterm.js ke OLT via WebSocket proxy.

## Scope nyata vs PRD (PENTING)

PRD (`docs/KusumaVision_NMS_PRD.md`) menggambarkan visi luas. **Yang dibangun lebih sempit.**
Jangan asumsikan fitur PRD ada di kode:

| Topik | Realita kode |
|-------|--------------|
| Vendor | **ZTE C300/C320 saja** (dan jalur C600 sebagian). `SmartOltSupport::driverKey()` → `unknown` untuk vendor lain dan semua capability dimatikan. |
| SNMP | **read v1/v2c saja**. v3 → throw (`OltSnmpClient` & Go poller). |
| CLI | **Telnet saja**. `ZteCliProvisioningExecutor` menolak SSH. Telnet browser juga telnet-only. |
| Data store | **Tidak ada** TimescaleDB, tabel `onus`/`optical_metrics`, multi-vendor, atau AI. Itu masih visi PRD. |
| Live state ONU | Disimpan sebagai **JSON di kolom `snmp_olts.last_test_result`** (bukan tabel ONU). |
| Polling terjadwal | **Pakai engine Go** (`bin/kv-snmp-poller`) via `PollOltJob`. Aksi on-demand (refresh manual, provisioning, telnet, RX per-port) = **PHP sinkron** di controller/service. |

> Catatan: `SMARTOLT_ZTE_C300_C320_GUIDE.md` Section 6/7 (parseOnuDetailInfo, parseRunningConfig,
> ZteCliSessionService) adalah **blueprint dari proyek lain**, belum tentu 1:1 dengan kode di sini.

## Konteks bahasa

User berkomunikasi dalam **Bahasa Indonesia**. String UI, flash message, pesan error, label audit,
dan banyak komentar kode memakai Bahasa Indonesia (bilingual untuk istilah teknis/Inggris).

## Selanjutnya

→ [02 — Arsitektur](02-arsitektur.md)
