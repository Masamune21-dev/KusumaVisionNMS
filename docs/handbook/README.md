# KusumaVision NMS — Developer Handbook

Dokumentasi teknis lengkap untuk **KusumaVision NMS** — FTTH/GPON Network Management System
(SmartOLT/NetNumen alternative) milik **PT BERKAH MEDIA KUSUMA VISION (BMKV)** untuk mengelola
OLT ZTE GPON (C300/C320/C600), C-Data EPON/GPON, dan HiOSO EPON, provisioning ONU, peta ODP,
alarm, REST API, dan aplikasi Android.

Handbook ini ditujukan untuk developer/maintainer agar mudah **menambah fitur**, melakukan
**maintenance**, dan **troubleshooting** tanpa harus membaca seluruh kode lebih dulu.

> ⚠️ **Tentang scope.** Dokumen ini mendeskripsikan **kode yang benar-benar ada** di repo, bukan
> visi di `KusumaVision_NMS_PRD.md`. Bagian yang masih berupa blueprint/PRD ditandai eksplisit.
> Sumber kebenaran sintaks CLI ZTE tetap `SMARTOLT_ZTE_C300_C320_C600_GUIDE.md` di root `docs/`.

---

## Daftar Isi

| # | Bagian | Isi singkat |
|---|--------|-------------|
| 00 | [README (file ini)](README.md) | Indeks & cara memakai handbook |
| 01 | [Gambaran Umum](01-overview.md) | Apa itu sistem ini, tech stack, scope nyata vs PRD |
| 02 | [Arsitektur](02-arsitektur.md) | Komponen, alur data, sinkron vs scheduled |
| 03 | [Struktur Folder](03-struktur-folder.md) | Peta direktori backend + frontend |
| 04 | [Instalasi & Deploy](04-instalasi-deploy.md) | Setup lokal, env, produksi, supervisor, nginx |
| 05 | [Database & Model](05-database-model.md) | Skema tabel, model Eloquent, relasi, cache JSON |
| 06 | [Routing](06-routing.md) | Semua route web/auth + controller + middleware |
| 07 | [Modul & Fitur](07-modul-fitur.md) | Dashboard, SmartOLT, ONU Monitoring, Reports, dll |
| 08 | [SNMP & Polling](08-snmp-polling.md) | OltSnmpClient, Go poller, PollOltJob, scheduler |
| 09 | [CLI & Telnet](09-cli-telnet.md) | Provisioning CLI, executor, browser telnet proxy |
| 10 | [Alarm & Telegram](10-alarm-telegram.md) | AlarmEvaluator (debounce, root-cause), notifikasi Telegram/FCM + command bot |
| 11 | [Keamanan, RBAC & Audit](11-keamanan-rbac-audit.md) | Role, demo mode, audit trail, secret handling |
| 12 | [Frontend](12-frontend.md) | Vue 3 + Inertia, layout, komponen, tema gelap/terang, i18n ID/EN, build Vite |
| 13 | [Troubleshooting & Maintenance](13-troubleshooting-maintenance.md) | Gejala → penyebab → solusi |
| 14 | [Panduan Menambah Fitur](14-panduan-tambah-fitur.md) | Resep langkah demi langkah |
| 15 | [UI & Tema Dashboard](15-ui-tema-dashboard.md) | Design token, kelas `kv-*`, aturan halaman/komponen baru |
| 16 | [Peta ONU & ODP](16-peta-onu.md) | Peta Leaflet pin ONU/ODP lintas-OLT, tile Google keyless, warna/foto ODP, tambah pin & aksi |
| 17 | [C-Data GPON: SNMP walk & inventory](17-cdata-gpon-snmp-walk.md) | Peta OID NSCRTV (FD1608S & FD1601S/FD1602S), inventory ONU via SNMP, CLI enrich, faceplate |
| 18 | [Docker Appliance](18-docker-appliance.md) | Kemas seluruh stack jadi container, install lengkap di 1 PC (seperti NetNumen), bagikan ke banyak lokasi |
| 20 | [GenieACS / TR-069](20-genieacs-tr069.md) | Jembatan opsional ke ACS TR-069: pencocokan ONU↔device, penyematan manual, perangkat terhubung, ubah WiFi, izin & keamanan NBI |

---

## Cara cepat memakai handbook

- **Mau menambah fitur baru?** Mulai dari [14 — Panduan Menambah Fitur](14-panduan-tambah-fitur.md),
  lalu lihat bagian terkait (mis. [06 Routing](06-routing.md), [05 Model](05-database-model.md)).
- **Mau menambah/ubah halaman atau komponen UI?** Ikuti aturan tema di
  [15 — UI & Tema Dashboard](15-ui-tema-dashboard.md).
- **Site error / daemon mati / data aneh?** Buka [13 — Troubleshooting](13-troubleshooting-maintenance.md).
- **Mau paham alur data SNMP/polling?** Baca [02 Arsitektur](02-arsitektur.md) lalu [08 SNMP & Polling](08-snmp-polling.md).
- **Mau menambah dukungan vendor/fitur OLT?** [02 §Driver & capability](02-arsitektur.md#driver--capability-gating),
  [08](08-snmp-polling.md), [09](09-cli-telnet.md), lalu guide vendor di `docs/`.
- **Urusan TR-069 / GenieACS?** [20 — GenieACS / TR-069](20-genieacs-tr069.md).
- **Butuh panduan untuk pengguna/operator (bukan developer)?** PDF di [`docs/panduan/`](../panduan/).
- **Setup mesin baru?** [04 — Instalasi & Deploy](04-instalasi-deploy.md).

## Konvensi penting (wajib diingat)

1. **`SMARTOLT_ZTE_C300_C320_C600_GUIDE.md`** adalah referensi otoritatif sintaks CLI ZTE — jangan
   menebak perintah, konsultasikan dulu (C600: `SMARTOLT_ZTE_C600_GUIDE.md`; C-Data/HiOSO: guide
   masing-masing). OID/perintah vendor baru hanya masuk kode setelah terverifikasi di perangkat asli.
2. Setiap perubahan berarti dicatat di **`WORKLOG.md`** (format Created/Changed/Fixed/Notes +
   verifikasi OLT nyata bila ada), entri terbaru di atas. Lihat [WORKLOG](../../WORKLOG.md).
3. **Tests jalan di SQLite in-memory** → semua migrasi harus tetap kompatibel SQLite walau app
   produksi pakai PostgreSQL. Jalankan **hanya** lewat `bash scripts/test.sh` (+ `npm test`), jangan
   `php artisan test` polos — di checkout ber-config ter-cache test bisa menyasar database produksi.
4. **Produksi memakai config & route ter-cache.** Sehabis ubah `.env`/config jalankan
   `php artisan config:cache`; sehabis menambah rute `php artisan route:cache`; lalu restart daemon
   supervisor bila perlu. Migrasi dijalankan **sebelum** kode yang membacanya tayang. Detail di
   [04](04-instalasi-deploy.md), [13](13-troubleshooting-maintenance.md), [14](14-panduan-tambah-fitur.md).
5. **String yang tampil ke pengguna dwibahasa ID/EN** (frontend `$t()`, backend `__()`), bawaan `id`.

## Dokumen referensi lain di repo

- [`docs/README.md`](../README.md) — indeks seluruh dokumen di folder `docs/`.
- [`CLAUDE.md`](../../CLAUDE.md) — instruksi ringkas untuk asisten/agent.
- [`README.md`](../../README.md) — README proyek.
- [`WORKLOG.md`](../../WORKLOG.md) — riwayat pekerjaan fase per fase.
- [`UI_DESIGN_SYSTEM.md`](../../UI_DESIGN_SYSTEM.md) — design system UI (tema, token, komponen).
- **Panduan pengguna (PDF)** di [`docs/panduan/`](../panduan/):
  [`Panduan-NMS-public-id.pdf`](../panduan/Panduan-NMS-public-id.pdf) (Bahasa Indonesia) dan
  [`NMS-Guide-public-en.pdf`](../panduan/NMS-Guide-public-en.pdf) (English).
- [`docs/API.md`](../API.md) — REST API v1; [`docs/INSTALL.md`](../INSTALL.md),
  [`docs/DOCKER.md`](../DOCKER.md), [`docs/BUILD_APK.md`](../BUILD_APK.md) — instalasi, appliance, APK.
- [`docs/SMARTOLT_ZTE_C300_C320_C600_GUIDE.md`](../SMARTOLT_ZTE_C300_C320_C600_GUIDE.md) — referensi CLI ZTE;
  [`docs/SMARTOLT_ZTE_C600_GUIDE.md`](../SMARTOLT_ZTE_C600_GUIDE.md) + `docs/ZTE_C600_*.md` — C600 (terverifikasi).
- [`docs/SMARTOLT_CDATA_GUIDE.md`](../SMARTOLT_CDATA_GUIDE.md), [`docs/SMARTOLT_HIOSO_GUIDE.md`](../SMARTOLT_HIOSO_GUIDE.md) — C-Data & HiOSO.
- [`docs/KusumaVision_NMS_PRD.md`](../KusumaVision_NMS_PRD.md) — visi/PRD (bukan scope nyata).
- PDF-PDF ZTE C600 di folder `docs/` — bahan riset vendor, **tidak terverifikasi**; jangan dipakai
  sebagai sumber OID.
