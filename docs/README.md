# Peta Dokumentasi — KusumaVision NMS

*Documentation map — every guide in this repository, grouped by who it is for.*

Mulai dari kelompok yang sesuai peran Anda. Tiap entri diberi satu baris ringkasan dalam bahasa
Inggris (*miring*).

> Ringkasan fitur, cara pasang singkat, dan tangkapan layar ada di [README.md](../README.md)
> ([Bahasa Indonesia](../README.id.md)).

---

## 1. Pengguna aplikasi — operator, admin, teknisi

Cara memakai setiap menu, langkah demi langkah (dashboard, OLT per vendor, registrasi ONU, peta & ODP,
alarm, laporan, pengguna & hak akses, pengaturan, aplikasi Android, praktik aman).

- [Panduan-NMS-public-id.pdf](panduan/Panduan-NMS-public-id.pdf) — panduan pengguna lengkap, Bahasa Indonesia.
  *Complete user guide, Indonesian.*
- [NMS-Guide-public-en.pdf](panduan/NMS-Guide-public-en.pdf) — panduan pengguna lengkap, bahasa Inggris.
  *Complete user guide, English.*
- [mobile/README.md](../mobile/README.md) — fitur aplikasi Android. *What the Android app does.*
- [BUILD_APK.md §7](BUILD_APK.md#7-install-apk-di-hp) — memasang APK di HP. *Installing the APK on a phone.*

## 2. Pemasang & pengelola server

- [INSTALL.md](INSTALL.md) — **titik awal**: pilih jalur (Docker / `install.sh` / manual), spek minimum,
  langkah setelah instalasi, update ke versi baru. *Start here: install paths, requirements, upgrades.*
- [DOCKER.md](DOCKER.md) — appliance Docker satu PC (Windows/Linux/macOS): backup, update, distribusi image.
  *Docker appliance: backup, updates, image distribution.*
- [handbook/04-instalasi-deploy.md](handbook/04-instalasi-deploy.md) — detail teknis instalasi manual,
  variabel `.env`, daemon, gotcha config cache. *Manual install, .env variables, daemons, config-cache pitfalls.*
- [handbook/18-docker-appliance.md](handbook/18-docker-appliance.md) — arsitektur kemasan Docker.
  *How the Docker packaging is built.*
- [BUILD_APK.md](BUILD_APK.md) — build APK Android dari nol (toolchain, spek, signing, Firebase).
  *Building the Android APK from scratch.*
- [LOCAL_PRODUCTION_HARDENING.md](LOCAL_PRODUCTION_HARDENING.md) — contoh hardening nginx, PHP-FPM, SSH, UFW.
  *Server hardening baseline (May 2026, with an update note).*
- [DEMO_DEPLOYMENT.md](DEMO_DEPLOYMENT.md) — mode demo (data contoh, akun read-only).
  *Demo mode with sample data and read-only accounts.*
- [INSTALLATION_STATUS.md](INSTALLATION_STATUS.md) — catatan historis instalasi pertama (Mei 2026).
  *Historical record of the first installation.*
- [SECURITY_AUDIT_2026-07.md](SECURITY_AUDIT_2026-07.md) — audit keamanan & checklist hardening Juli 2026.
  *July 2026 security audit and hardening checklist.*

## 3. Integrator — aplikasi lain yang membaca/menulis data NMS

- [API.md](API.md) — REST API v1: token, endpoint baca & tulis, format error, contoh kode.
  *REST API v1: tokens, endpoints, errors, code samples.*

## 4. Referensi teknis per vendor OLT

Peta OID SNMP & perintah CLI yang **sudah diverifikasi di perangkat asli** — dipakai saat menambah
dukungan atau memecahkan masalah. Keluarga yang didukung: ZTE C300/C320/C600, C-Data GPON (FD1608S FlashV3,
FD1601S, FD1602S) & EPON (FD1304E), HiOSO HA7304/HA7302. V-Sol tidak didukung; HsAirPo/HSGQ dihapus
29 Sep 2026.

- [SMARTOLT_ZTE_C300_C320_C600_GUIDE.md](SMARTOLT_ZTE_C300_C320_C600_GUIDE.md) — referensi CLI ZTE C300/C320
  (otoritatif; bagian C600-nya digantikan dokumen berikut). *Authoritative ZTE C300/C320 CLI reference.*
- [SMARTOLT_ZTE_C600_GUIDE.md](SMARTOLT_ZTE_C600_GUIDE.md) — ZTE C600 (Titan): OID & CLI terverifikasi.
  *ZTE C600 OIDs and CLI, verified live.*
- [ZTE_C600_Card_PON_Uplink_SNMP_Inventory.md](ZTE_C600_Card_PON_Uplink_SNMP_Inventory.md),
  [ZTE_C600_Configured_ONU_Name_SNMP_Discovery.md](ZTE_C600_Configured_ONU_Name_SNMP_Discovery.md),
  [ZTE_C600_Unconfigured_ONU_SNMP_Discovery.md](ZTE_C600_Unconfigured_ONU_SNMP_Discovery.md) — catatan SNMP C600
  (kartu, nama ONU, ONU unconfigured). *C600 SNMP notes: cards, ONU names, unconfigured ONUs.*
- PDF vendor ZTE C600 di folder ini (`ZTE ZXA10 C600 …`, `SNMP Discovery Guide …`, `ZXA10 C600 vs C300 …`) —
  bacaan latar, **belum terverifikasi**; jangan dijadikan sumber OID. *Background vendor PDFs, unverified.*
- [SMARTOLT_CDATA_GUIDE.md](SMARTOLT_CDATA_GUIDE.md) — C-Data EPON & GPON: OID, CLI, kapabilitas, VLAN & port (§14).
  *C-Data EPON/GPON reference, including VLAN and port pages.*
- [handbook/17-cdata-gpon-snmp-walk.md](handbook/17-cdata-gpon-snmp-walk.md) — peta OID C-Data GPON (NSCRTV)
  hasil walk FD1608S & FD1601S. *C-Data GPON SNMP walk and OID map.*
- [SMARTOLT_HIOSO_GUIDE.md](SMARTOLT_HIOSO_GUIDE.md) — HiOSO EPON HA7304 & HA7302. *HiOSO EPON reference.*

## 5. Developer & maintainer

- [handbook/README.md](handbook/README.md) — **Developer Handbook**: arsitektur, struktur folder, database,
  routing, modul, SNMP/polling, CLI/telnet, alarm, keamanan/RBAC, frontend, troubleshooting, cara menambah
  fitur. *Developer handbook index.*
- [CLAUDE.md](../CLAUDE.md) — ringkasan arsitektur & konvensi wajib (test lewat `scripts/test.sh`, config cache,
  i18n). *Architecture summary and mandatory conventions.*
- [UI_DESIGN_SYSTEM.md](../UI_DESIGN_SYSTEM.md) — design system Dark-Glass Cyan, dua tema, kelas `kv-*`.
  *UI design system and theme rules.*
- [handbook/15-ui-tema-dashboard.md](handbook/15-ui-tema-dashboard.md) — aturan membuat halaman/komponen baru.
  *Rules for new pages and components.*
- [mobile/DESIGN_REVAMP_PLAN.md](../mobile/DESIGN_REVAMP_PLAN.md) — rencana & prinsip desain aplikasi Android.
  *Android app design plan.*
- [WORKLOG.md](../WORKLOG.md) — riwayat pekerjaan, entri terbaru di atas. *Change history, newest first.*
- [KusumaVision_NMS_PRD.md](KusumaVision_NMS_PRD.md) — visi produk awal; **bukan** cakupan nyata kode.
  *Original product vision, not the built scope.*
