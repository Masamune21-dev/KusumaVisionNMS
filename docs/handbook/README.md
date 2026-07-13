# KusumaVision NMS — Developer Handbook

Dokumentasi teknis lengkap untuk **KusumaVision NMS** — FTTH/GPON Network Management System
(SmartOLT/NetNumen alternative) milik **PT BERKAH MEDIA KUSUMA VISION (BMKV)** untuk mengelola
OLT ZTE GPON dan provisioning ONU.

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
| 10 | [Alarm & Telegram](10-alarm-telegram.md) | AlarmEvaluator, notifikasi + command bot |
| 11 | [Keamanan, RBAC & Audit](11-keamanan-rbac-audit.md) | Role, demo mode, audit trail, secret handling |
| 12 | [Frontend](12-frontend.md) | Vue 3 + Inertia, layout, komponen, build Vite |
| 13 | [Troubleshooting & Maintenance](13-troubleshooting-maintenance.md) | Gejala → penyebab → solusi |
| 14 | [Panduan Menambah Fitur](14-panduan-tambah-fitur.md) | Resep langkah demi langkah |
| 15 | [UI & Tema Dashboard](15-ui-tema-dashboard.md) | Design token, kelas `kv-*`, aturan halaman/komponen baru |
| 16 | [Peta ONU](16-peta-onu.md) | Peta Leaflet pin ONU lintas-OLT, tile Google keyless, tambah pin & aksi |
| 17 | [C-Data GPON: SNMP walk & inventory](17-cdata-gpon-snmp-walk.md) | Peta OID FD1608S, inventory ONU V3 via SNMP penuh, CLI enrich SN/Rx |
| 18 | [Docker Appliance](18-docker-appliance.md) | Kemas seluruh stack jadi container, install lengkap di 1 PC (seperti NetNumen), bagikan ke banyak lokasi |

---

## Cara cepat memakai handbook

- **Mau menambah fitur baru?** Mulai dari [14 — Panduan Menambah Fitur](14-panduan-tambah-fitur.md),
  lalu lihat bagian terkait (mis. [06 Routing](06-routing.md), [05 Model](05-database-model.md)).
- **Mau menambah/ubah halaman atau komponen UI?** Ikuti aturan tema di
  [15 — UI & Tema Dashboard](15-ui-tema-dashboard.md).
- **Site error / daemon mati / data aneh?** Buka [13 — Troubleshooting](13-troubleshooting-maintenance.md).
- **Mau paham alur data SNMP/polling?** Baca [02 Arsitektur](02-arsitektur.md) lalu [08 SNMP & Polling](08-snmp-polling.md).
- **Setup mesin baru?** [04 — Instalasi & Deploy](04-instalasi-deploy.md).

## Konvensi penting (wajib diingat)

1. **`SMARTOLT_ZTE_C300_C320_C600_GUIDE.md`** adalah referensi otoritatif sintaks CLI ZTE — jangan
   menebak perintah, konsultasikan dulu.
2. Setiap perubahan berarti dicatat di **`WORKLOG.md`** (format Created/Changed/Notes + verifikasi
   OLT nyata bila ada). Lihat [WORKLOG](../../WORKLOG.md).
3. **Tests jalan di SQLite in-memory** → semua migrasi harus tetap kompatibel SQLite walau app
   produksi pakai PostgreSQL.
4. **Produksi memakai config ter-cache.** Sehabis ubah `.env`/config jalankan `php artisan config:cache`
   lalu restart daemon supervisor. Detail di [04](04-instalasi-deploy.md) & [13](13-troubleshooting-maintenance.md).
5. **String UI & flash message dalam Bahasa Indonesia.**

## Dokumen referensi lain di repo

- [`CLAUDE.md`](../../CLAUDE.md) — instruksi ringkas untuk asisten/agent.
- [`README.md`](../../README.md) — README publik proyek.
- [`WORKLOG.md`](../../WORKLOG.md) — riwayat pekerjaan fase per fase.
- [`docs/SMARTOLT_ZTE_C300_C320_C600_GUIDE.md`](../SMARTOLT_ZTE_C300_C320_C600_GUIDE.md) — referensi CLI ZTE.
- [`docs/KusumaVision_NMS_PRD.md`](../KusumaVision_NMS_PRD.md) — visi/PRD (bukan scope nyata).
- PDF-PDF ZTE C600 di folder `docs/` — referensi OID/CLI vendor.
