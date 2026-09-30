# PRD — KusumaVision NMS

## FTTH Access Network Management Platform

Versi: 1.0  
Status: Draft Awal  
Owner: PT BERKAH MEDIA KUSUMA VISION (BMKV)  
Product Name: KusumaVision NMS

> ## Status dokumen (ditinjau terhadap kode, 30 Sep 2026)
>
> PRD ini (Mei 2026) adalah **visi produk**, bukan deskripsi sistem yang berjalan. Isi di bawah
> sengaja tidak diubah. Cakupan yang benar-benar dibangun lebih sempit — jangan menganggap fitur PRD
> sudah ada sebelum memeriksa kode atau [`docs/handbook/`](handbook/README.md).
>
> | Butir PRD | Keadaan kode |
> | :--- | :--- |
> | Multi-vendor | ✅ Sebagian: ZTE C300/C320/C600, C-Data EPON/GPON, HiOSO HA7304/HA7302. Vendor lain = `unknown` (semua kapabilitas mati). HsAirPo dihapus 29 Sep 2026. |
> | Dashboard (ONU online/offline, alarm, kesehatan optik, OLT) | ✅ Ada. Grafik trafik hanya **live** uplink/port di Detail Port — tidak ada histori trafik. |
> | OLT Management (tambah/ubah/hapus, deteksi vendor, chassis) | ✅ Ada; kartu/uplink & faceplate. Ubah koneksi OLT bersama dibatasi admin/operator; partner hanya OLT miliknya. |
> | ONU Monitoring (RX/TX, jarak, suhu, tegangan, dying gasp, LOS, sebab putus) | ✅ Rx lintas vendor dari poller; TX/jarak/suhu/tegangan di Detail ONU ZTE (CLI). |
> | ONU Provisioning (autofind, register, VLAN, T-CONT, PPPoE, TR069) | ✅ ZTE saja (C600: tanpa WAN PPPoE/DHCP/static). Plus TR069 massal per port & salin ONU antar-port. |
> | Remote ONU (reboot, enable/disable, WiFi, remote management) | ⚠️ Reboot/rename/hapus lintas family, enable/disable (C300/C320, C-Data, HiOSO), Remote ONT. **Pengaturan WiFi tidak dikelola NMS** — TR-069 sebatas menulis ACS URL ke ONU (registrasi & TR069 massal per port). |
> | Alarm engine | ✅ OLT unreachable, port down/disabled, ODP down, LOS, dying gasp, ONU offline, redaman tinggi. **Tidak ada** alarm "ONU flapping" — yang ada debounce 2 poll anti-flap. "Uplink down" tidak dievaluasi terpisah. |
> | Frontend Vue 3 + Inertia + Tailwind + ApexCharts | ✅ Sesuai (plus Leaflet untuk peta, xterm.js untuk terminal). |
> | Backend Laravel 12 + Redis | ✅ Sesuai. **Horizon & Reverb** terpasang sebagai paket, tetapi worker produksi memakai `queue:work` (supervisor) dan tidak ada event broadcast realtime; Reverb hanya menyumbang dependensi `react/socket` untuk proxy telnet. |
> | Polling engine GoLang + gosnmp | ✅ `bin/kv-snmp-poller` untuk polling terjadwal (jatuh balik ke PHP). SNMP hanya v1/v2c. |
> | SSH/Telnet client | ⚠️ **Telnet saja** — SSH belum tersambung ke eksekutor mana pun. |
> | Database PostgreSQL + Redis | ✅ Sesuai. |
> | TimescaleDB | ❌ Tidak dipakai. Histori Rx di tabel biasa `onu_rx_samples` + ringkasan per jam `onu_rx_hourly`. |
> | Tabel `olts`, `pon_ports`, `onus`, `optical_metrics`, `traffic_metrics`, `provisioning_logs`, `roles`, `customers` | ❌ Tidak ada dengan nama/bentuk itu. OLT = `snmp_olts`; **ONU & port tidak punya tabel** (status per-OLT di JSON `snmp_olts.last_test_result`); optik = `onu_rx_samples`/`onu_rx_hourly`; tanpa `traffic_metrics`; provisioning = `smartolt_onu_registrations`; role = enum `App\Enums\UserRole` di kolom `users.role`; tanpa tabel pelanggan (nama pelanggan hanya sebagai nama/deskripsi ONU & `customer_name` registrasi). `users` & `alarm_events` ada. |
> | Deployment Ubuntu + Nginx + Supervisor, Docker-ready | ✅ `install.sh`, supervisor (`kusumavision-worker/-scheduler/-telnet-proxy`), dan mode Docker "appliance" (`docs/DOCKER.md`). |
> | Fase 2: integrasi MikroTik | ❌ Belum ada. |
> | Fase 2: Radius | ❌ Belum ada. |
> | Fase 2: topologi | ✅ Peta ONU/ODP (Leaflet) + relasi ODP→ONU. |
> | Fase 2: notifikasi WhatsApp | ❌ Tidak di NMS — notifikasi lewat Telegram (bot global & bot partner) dan push FCM Android. |
> | Fase 3 / tujuan sekunder: AI analytics | ❌ Belum ada — hanya grafik tren Rx per ONU/port, bukan analitik prediktif. |
> | Predictive maintenance, auto healing | ❌ Belum ada. |
> | Integrasi Billing | ❌ Belum ada (REST API v1 tersedia untuk integrasi pihak lain). |
> | Integrasi ticketing | ❌ Belum ada di NMS. |
>
> Di luar PRD tetapi sudah dibangun: REST API v1 + aplikasi Android, ODP & foto dokumentasi, backup
> config OLT harian, terminal telnet di browser, multi-tenant partner (`PartnerOltScope`, OLT privat,
> bot Telegram per partner), tema gelap/terang, dan antarmuka dwibahasa (Indonesia/Inggris).

---

# 1. Executive Summary

KusumaVision NMS adalah platform modern FTTH Network Management System untuk ISP FTTH sebagai alternatif SmartOLT dan NetNumen.

Fokus utama:
- GPON OLT Management
- ONU Provisioning
- Optical Monitoring
- Alarm Monitoring
- Multi Vendor Support
- Realtime Dashboard
- FTTH Automation

---

# 2. Product Vision

Menjadi platform NMS FTTH modern, realtime, modular, scalable, dan mudah digunakan untuk ISP Indonesia.

---

# 3. Product Goals

## Primary Goals
- Multi OLT management
- ONU provisioning
- Optical monitoring
- Alarm monitoring
- Remote ONU management
- Topology visualization

## Secondary Goals
- AI analytics
- Auto healing
- Billing integration
- Ticketing integration

---

# 4. Core Features

## Dashboard
- ONU online/offline
- Alarm summary
- Optical health
- Traffic graph
- OLT health

## OLT Management
- Add/edit/delete OLT
- Vendor detection
- Chassis monitoring

## ONU Monitoring
- RX/TX optical
- Distance
- Temperature
- Voltage
- Dying Gasp
- LOS
- Last down cause

## ONU Provisioning
- Auto detect ONU
- Register ONU
- VLAN assignment
- TCONT assignment
- PPPoE configuration
- TR069 configuration

## Remote ONU Management
- Reboot ONU
- Enable/disable ONU
- WiFi config
- Remote management

## Alarm Engine
- ONU offline
- LOS
- Dying Gasp
- Uplink down
- High attenuation
- ONU flapping

---

# 5. Technical Architecture

## Frontend
- Vue 3
- Inertia.js
- TailwindCSS
- ApexCharts

## Backend
- Laravel 12
- Redis
- Horizon
- Reverb

## Polling Engine
- GoLang
- gosnmp
- SSH/Telnet client

## Database
- PostgreSQL
- TimescaleDB
- Redis

---

# 6. Database Design

Main tables:
- users
- roles
- olts
- pon_ports
- onus
- customers
- optical_metrics
- traffic_metrics
- alarm_events
- provisioning_logs

---

# 7. Deployment

## Infrastructure
- Ubuntu Server
- Nginx
- Docker-ready
- Supervisor/Systemd

---

# 8. Roadmap

## Phase 1
- OLT monitoring
- ONU monitoring
- Provisioning
- Alarm basic

## Phase 2
- MikroTik integration
- Radius integration
- Topology
- WhatsApp notification

## Phase 3
- AI analytics
- Predictive maintenance
- Auto healing

---

# 9. Branding

## Product Name
KusumaVision NMS

## Subtitle
Unified FTTH Network Management Platform
