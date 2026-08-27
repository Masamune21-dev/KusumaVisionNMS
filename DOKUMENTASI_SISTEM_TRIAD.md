# DOKUMENTASI LENGKAP SISTEM TRIAD KUSUMAVISION
**PT BERKAH MEDIA KUSUMA VISION (BMKV)**
*Dokumentasi Arsitektur, Integrasi Lintas Sistem, Alur Otomasi, dan Panduan Operasional ISP*

---

## 1. PENDAHULUAN & RINGKASAN EKSEKUTIF

Sistem **KusumaVision Triad** adalah ekosistem perangkat lunak terintegrasi yang dibangun khusus untuk operasional penyedia jasa internet (ISP / FTTH). Sistem ini terdiri dari 3 pilar aplikasi independen yang saling berkomunikasi secara *real-time*:

1. **KusumaVision NMS (`nms.kusumavision.net`)** — Mengelola lapisan fisik optik GPON/EPON OLT, pemetaan ODP, redaman kabel (Rx Power), monitoring status ONU, registrasi perangkat ONT, dan penanganan alarm jaringan.
2. **KusumaVision MikroTik (`mikrotik.kusumavision.net`)** — Mengelola lapisan *routing* & *session* (L3/L4) pada router core MikroTik: PPPoE secrets/profiles/active session, manajemen voucher hotspot & cetak PDF, serta aturan firewall.
3. **KusumaVision Billing (`billing.kusumavision.net`)** — Mengelola data pelanggan, tagihan bulanan otomatis, loket kasir & multi-rekening bank transfer, upload bukti transfer, *payment gateway* (QRIS/VA), notifikasi WhatsApp Gateway, serta eksekusi **Auto-Isolir** dan **Auto-Restore** live ke router MikroTik.

---

## 2. DIAGRAM ARSITEKTUR & TOPOLOGI SISTEM

```
                                [ PELANGGAN ISP / CABANG ]
                                             │
                       ┌─────────────────────┴─────────────────────┐
                       ▼                                           ▼
             [ Jaringan Fisik GPON ]                    [ Sesi Koneksi Internet ]
          (Kabel Drop FO, ODP, Splice)                    (PPPoE Dial-In / WAN)
                       │                                           │
                       ▼                                           ▼
            ┌──────────────────────┐                    ┌──────────────────────┐
            │   GPON / EPON OLT    │                    │  Router Core MikroTik│
            │ (ZTE, C-Data, HiOSO) │                    │      (CCR / RB)      │
            └──────────┬───────────┘                    └──────────┬───────────┘
                       │ SNMP v1/v2c (Port 161)                    │ RouterOS Binary API
                       │ Telnet CLI (Port 23)                      │ (Port 8728 / 8729 TLS)
                       ▼                                           ▼
            ┌──────────────────────┐                    ┌──────────────────────┐
            │   KusumaVision NMS   │                    │ KusumaVision MIKROTIK│
            │  (Port 8000 / PHP)   │                    │  (Port 8001 / PHP)   │
            │  - DB: pgsql_nms     │                    │  - DB: pgsql_mik     │
            │  - Redis DB 0 & 1    │                    │  - Redis DB 2 & 3    │
            └──────────┬───────────┘                    └──────────┬───────────┘
                       │                                           │
                       │ Read-Only Database Bridge                 │ Read-Only DB + Live API
                       │ (Koneksi: pgsql_nms)                      │ (Koneksi: pgsql_mikrotik)
                       └─────────────────────┬─────────────────────┘
                                             ▼
                              ┌─────────────────────────────┐
                              │    KusumaVision BILLING     │
                              │     (Pusat Kendali ISP)     │
                              │     (Port 8002 / PHP)       │
                              │  - DB: pgsql_billing        │
                              │  - Redis DB 4 & 5           │
                              │  - CRM Pelanggan & Paket    │
                              │  - Otomasi Tagihan (14:00)  │
                              │  - Auto-Isolir (14:00)      │
                              │  - Kasir & Rekening Bank    │
                              │  - WhatsApp Gateway (Fonnte)│
                              │  - Multi-Payment Gateway    │
                              └─────────────────────────────┘
```

---

## 3. SPESIFIKASI LINGKUNGAN & ALOKASI RESOURCE

Setiap aplikasi berjalan di server yang sama dengan isolasi penuh pada level database, partisi cache Redis, dan upstream Nginx:

| Komponen | KusumaVision NMS | KusumaVision MikroTik | KusumaVision Billing |
| :--- | :--- | :--- | :--- |
| **Domain Publik** | `https://nms.kusumavision.net` | `https://mikrotik.kusumavision.net` | `https://billing.kusumavision.net` |
| **Direktori Root** | `/var/www/KusumaVisionNMS` | `/var/www/KusumaVisionMikrotik` | `/var/www/KusumaVisionBilling` |
| **Port Nginx Upstream** | `8000` | `8001` | `8002` |
| **Database PostgreSQL** | `kusumavision_nms` | `kusumavision_mikrotik` | `kusumavision_billing` |
| **User Database** | `masamune21r` | `masamune21r` | `masamune21r` |
| **Redis Queue** | Redis DB `0` | Redis DB `2` | Redis DB `4` |
| **Redis Cache & Session** | Redis DB `1` | Redis DB `3` | Redis DB `5` |
| **Timezone Aplikasi** | `Asia/Jakarta` (WIB) | `Asia/Jakarta` (WIB) | `Asia/Jakarta` (WIB) |
| **Supervisor Worker** | `kusumavision-worker` | `kusumavision-mikrotik-worker` | `kusumavision-billing-worker` |
| **Supervisor Scheduler** | `kusumavision-scheduler` | `kusumavision-mikrotik-scheduler` | `kusumavision-billing-scheduler` |
| **Daemon Tambahan** | `kusumavision-telnet-proxy` | — | — |

---

## 4. MODUL 1: KUSUMAVISION NMS (`nms.kusumavision.net`)

### A. Peran & Cakupan
- Bertanggung jawab atas manajemen fisik OLT FTTH.
- Mendukung OLT ZTE C300, C320, C600 (ZTE CLI + SNMP), C-Data EPON/GPON, HiOSO (HA7304/HA7302), dan HsAirPo.
- Membaca status live ONU, serial number/MAC, redaman optik (Rx Power dBm), slot/port, dan penyebab putus (*down cause*).

### B. Fitur Unggulan
1. **Go SNMP Poller (`bin/kv-snmp-poller`):** Polling cepat berkala ke seluruh OLT untuk menyimpan snapshot data ke field JSON `snmp_olts.last_test_result`.
2. **Manajemen ODP (Optical Distribution Point):** Penataan splitter lapangan per-OLT dan per-port, relasi ODP &rarr; ONU (`onu_odp_links`), serta upload dokumentasi foto ODP.
3. **Peta Interaktif (Leaflet Map):** Visualisasi sebaran pin ONU dan ODP dengan garis topologi kabel drop FO dinamis (hijau = online, merah = offline/LOS/dying gasp).
4. **Browser Telnet Terminal:** Web terminal xterm.js via WebSocket daemon proxy (`TelnetProxyServer`).
5. **Pusat Notifikasi Alarm:** Debounce 2-poll anti-flap dan supresi alarm anak (*root-cause correlation*) ke Telegram & FCM Android.

---

## 5. MODUL 2: KUSUMAVISION MIKROTIK (`mikrotik.kusumavision.net`)

### A. Peran & Cakupan
- Bertanggung jawab atas kontrol sesi router MikroTik pelanggan.
- Terhubung langsung ke MikroTik RouterOS v6 dan v7 via Binary API (port `8728` plain / `8729` TLS).
- **Stateless Router Design:** State PPPoE/Hotspot tidak di-mirror statis ke database lokal, melainkan dibaca-tulis langsung ke perangkat MikroTik secara sinkron.

### B. Fitur Unggulan
1. **PPPoE Manager:** Tambah, edit, hapus secret, pemantauan sesi aktif live di `/ppp/active`, dan pemutusan koneksi ad-hoc (*kick*).
2. **Hotspot & Voucher Generator:** Pembuatan batch voucher massal (mode user=password atau user+pass), cetak template thermal & ekspor PDF Dompdf.
3. **Firewall Manager:** Pengaturan Filter Rules, NAT Rules, dan Address Lists live.
4. **Router Health Poller:** Pemantauan CPU load, temperatur board, memory, dan uptime router secara berkala.

---

## 6. MODUL 3: KUSUMAVISION BILLING (`billing.kusumavision.net`)

### A. Peran & Cakupan
KusumaVision Billing adalah **pusat komando ISP** yang menjembatani data fisik NMS, kontrol sesi MikroTik, manajemen pelanggan, transaksi keuangan, dan otomasi penagihan.

### B. Integrasi Bridge Multi-Database
Billing memiliki koneksi read-only ke database NMS dan MikroTik melalui konfigurasi `config/database.php`:
- `pgsql_nms` &rarr; Membaca tabel `snmp_olts`, `odps`, dan data snapshot status ONU/Rx Power.
- `pgsql_mikrotik` &rarr; Membaca tabel `routers` beserta password API terenkripsi.

### C. Alur Kerja Otomasi & Fitur Lengkap Billing

#### 1. Manajemen Pelanggan Terpadu (`/customers`)
- Mendaftarkan pelanggan baru dengan binding ke:
  - **Paket Internet:** Kecepatan Down/Up, tarif per bulan, dan nama profile MikroTik.
  - **Router MikroTik:** Pilihan router dan username/password PPPoE.
  - **GPON OLT:** Pilihan OLT, Slot, Port, ONU ID, Serial Number / MAC, dan ODP.
- **Monitoring Live di Detail Pelanggan:**
  - Status MikroTik PPPoE: `ONLINE` / `OFFLINE`, IP Address aktif, Uptime, MAC Caller-ID, Profil Secret aktif, dan Waktu Logout terakhir.
  - Status GPON OLT: Status `Working`/`Online`, Interface port, dan **Rx Power dBm** terkalibrasi warna (Hijau: $\ge -24\text{ dBm}$, Kuning: $-24\text{ s/d }-27\text{ dBm}$, Merah: $< -27\text{ dBm}$).

#### 2. Ganti Paket Langganan Seketika (Instant Speed Upgrade/Downgrade)
- Saat admin/partner mengganti paket pelanggan di form edit pelanggan:
  1. Data paket di database Billing terupdate.
  2. Sistem otomatis menghubungi router MikroTik via API untuk mengubah `profile` di `/ppp/secret`.
  3. Sistem langsung menendang (*kick*) sesi aktif pelanggan di `/ppp/active`.
  4. Router pelanggan otomatis melakukan *re-dial* dalam 1–2 detik dan mendapatkan alokasi bandwidth baru tanpa perlu restart router pelanggan secara manual.
  5. Perubahan paket tercatat di log audit pelanggan (`BillingLog`).

#### 3. Siklus Tagihan Harian & WhatsApp Notifikasi (Pukul 14:00)
- Cron scheduler `billing:generate-invoices --delay=3` berjalan setiap hari pukul **14:00 WIB**:
  - Mengevaluasi seluruh pelanggan aktif yang belum memiliki invoice di bulan berjalan.
  - Membuat invoice baru berstatus `unpaid`.
  - Otomatis mengirim pesan rincian tagihan via WhatsApp Gateway (Fonnte).
  - Dilengkapi jeda **delay 3 detik** per nomor agar nomor WhatsApp pengirim aman dari risiko blokir/banned provider.
- Cron scheduler `billing:send-reminders --days=0,3 --delay=3` berjalan setiap hari pukul **14:00 WIB**:
  - Mengirim pesan pengingat otomatis ke pelanggan yang tagihannya jatuh tempo hari ini (H-0) dan H-3 yang belum bayar.
- Tombol **"Generate Tagihan Hari Ini"** di web `/invoices` untuk memicu generate manual kapan saja.

#### 4. Otomasi Isolir Tunggakan (Pukul 14:00 Siang)
- Cron scheduler `billing:auto-isolate` berjalan setiap hari pukul **14:00 WIB**:
  - Mengevaluasi semua invoice yang berstatus `unpaid`.
  - Jika `Hari Ini > (Jatuh Tempo + Grace Period)`:
    - Status pelanggan diubah menjadi `isolated`.
    - Profile secret PPPoE di MikroTik diubah menjadi profile `ISOLIR`.
    - Sesi aktif di `/ppp/active` di-kick agar pelanggan dial ulang masuk ke IP Pool isolir / landing page isolir.
    - Mengirim notifikasi WhatsApp bahwa layanan terisolir beserta link pembayaran instan.

#### 4.1 Script Konfigurasi Router MikroTik untuk Profil ISOLIR (Copy-Paste Terminal):
```routeros
# 1. Buat IP Pool Isolir
/ip pool add name=pool-isolir ranges=10.99.99.2-10.99.99.254

# 2. Buat PPP Profile ISOLIR (Wajib bernama "ISOLIR" sesuai Pengaturan Billing)
/ppp profile add name=ISOLIR local-address=10.99.99.1 remote-address=pool-isolir rate-limit=128k/128k dns-server=10.99.99.1 comment="Profile Otomasi Isolir KusumaVision Billing"

# 3. Buat Firewall Filter (Izinkan DNS Port 53, Blokir Traffic Internet Lain)
/ip firewall filter
add chain=forward src-address=10.99.99.0/24 protocol=udp dst-port=53 action=accept comment="Allow DNS Isolir"
add chain=forward src-address=10.99.99.0/24 protocol=tcp dst-port=53 action=accept comment="Allow DNS TCP Isolir"
add chain=forward src-address=10.99.99.0/24 action=drop comment="Drop Internet Pelanggan Isolir"

# 4. (Opsional) NAT Redirect Browser ke Web Isolir / Portal Pembayaran
/ip firewall nat
add chain=dstnat src-address=10.99.99.0/24 protocol=tcp dst-port=80 action=redirect to-ports=80 comment="Redirect Web Isolir"

# 5. Aktifkan Service API MikroTik (Port 8728) & Buat User API
/ip service set api port=8728 disabled=no
/user add name=billing-api group=full password="PASSWORD_AMAN_ANDA" comment="API User KusumaVision Billing"
```

#### 5. Kasir Loket, Multi-Rekening Bank, & Upload Bukti Transfer (`/cashier`)
- Kasir dapat mencari invoice belum lunas dan menerima pembayaran secara tunai atau transfer.
- **Pilihan Rekening Bank Dinamis:** Opsi metode pembayaran otomatis membaca rekening bank yang didaftarkan di Pengaturan (contoh: `BRI - 1234567890 (a/n PT BMKV)` dan `Mandiri - 9876543210 (a/n PT BMKV)`).
- **Upload Bukti Pembayaran:** Kasir dapat melampirkan foto struk/bukti transfer (JPG, PNG, WEBP maks 5MB).
- **Auto-Restore Seketika:** Begitu pembayaran kasir dikonfirmasi lunas:
  - Jika pelanggan terisolir, sistem langsung mencabut isolir di MikroTik (mengembalikan profil ke paket normal & kick sesi aktif).
  - Mengirim notifikasi WhatsApp bukti kwitansi lunas ke pelanggan.
  - Tercatat nama user petugas kasir yang memproses transaksi (`cashier_user_id`).

#### 6. Manajemen Tagihan Dua Tab (`/invoices`)
- **Tab Belum Lunas (Unpaid):** Daftar tagihan tertunda, tanggal jatuh tempo, tombol bayar kasir, tombol kirim WA manual, dan tombol hapus tagihan.
- **Tab Sudah Lunas (Paid):** Daftar tagihan lunas, tanggal bayar, kolom **Metode Bayar**, kolom **Petugas Kasir**, tombol edit metode bayar, tombol batalkan pelunasan (*revert to unpaid*), dan tombol cetak PDF A4 / Struk Thermal 58mm/80mm.

#### 7. Multi-Payment Gateway Online & Portal Pelanggan
- Mendukung integrasi Tripay, Midtrans, Duitku, dan Xendit.
- Pelanggan dapat membuka link tagihan mandiri: `https://billing.kusumavision.net/portal/invoice/{invoice_no}` untuk membayar via QRIS otomatis atau Virtual Account.
- Callback webhook memvalidasi signature & otomatis melunasi tagihan + mencabut status isolir secara instan.

---

## 7. MODEL MULTI-TENANCY & ISOLASI ROLE PARTNER

Sistem menerapkan isolasi data ketat (*Scoped Multi-Tenancy*) untuk mitra/partner:

```
                  ┌─────────────────────────────────────┐
                  │          USER LOGIN                 │
                  └──────────────────┬──────────────────┘
                                     │
                    ┌────────────────┴────────────────┐
                    ▼                                 ▼
             [ Role: ADMIN ]                  [ Role: PARTNER ]
                    │                                 │
         ┌──────────┴──────────┐           ┌──────────┴──────────┐
         ▼                     ▼           ▼                     ▼
  Data Global ISP       Router/OLT Global   HANYA Pelanggan        HANYA Router & OLT
  (Semua Pelanggan)     (owner_user_id      Milik Partner          Milik Partner
                        is NULL)            (owner_user_id         (Sinkron via Email
                                            = user->id)            Remote ID)
```

1. **`PartnerCustomerScope` (Billing):**
   - Partner hanya melihat pelanggan miliknya (`customers.owner_user_id = user.id`), tagihan miliknya, kasir miliknya, dan laporan keuangan miliknya.
   - Pendaftaran pelanggan baru oleh Partner otomatis terikat ke `owner_user_id` miliknya.
2. **`RouterScope` (MikroTik):**
   - Partner hanya melihat dan mengelola router MikroTik miliknya.
3. **`PartnerOltScope` (NMS):**
   - Partner hanya melihat dan mengelola OLT GPON miliknya.
4. **Sinkronisasi Bridge:**
   - Dropdown Router dan OLT pada aplikasi Billing saat dibuka oleh akun Partner otomatis mencocokkan email user partner dan membatasi pilihan hanya pada perangkat milik partner tersebut.

---

## 8. PANDUAN PENYELESAIAN MASALAH (TROUBLESHOOTING)

| Gejala Masalah | Penyebab Utama | Solusi Penanganan |
| :--- | :--- | :--- |
| **Rx Power ONU Tampil "N/A"** | Serial Number / MAC ONU belum diisi pada data pelanggan, atau poller NMS belum selesai memindai OLT. | Buka detail pelanggan &rarr; Edit &rarr; pastikan Serial Number sesuai dengan fisik ONT & OLT terhubung. |
| **PPPoE Offline padahal ONT Nyala** | Username/Password PPPoE di router MikroTik tidak cocok dengan konfigurasi WAN di ONT pelanggan. | Periksa kecocokan username PPPoE di detail pelanggan dengan konfigurasi di router MikroTik. |
| **Pelanggan Lunas tapi Masih Terisolir** | Koneksi API ke router MikroTik sempat timeout saat pelunasan diproses. | Buka detail pelanggan &rarr; klik **Edit / Ganti Paket** &rarr; klik **Simpan** untuk memicu sinkronisasi ulang profile MikroTik. |
| **Pesan WhatsApp Pending / Gagal** | API Token Fonnte belum diisi di Pengaturan atau device WhatsApp di Fonnte berstatus *Disconnected*. | Buka **Pengaturan &rarr; WhatsApp Gateway**, periksa token dan pastikan status koneksi Fonnte aktif. |
| **Error 503 / Asset JS Aborted** | Fitur prefetch agresif browser memicu limit Cloudflare. | Pastikan `Vite::prefetch` dinonaktifkan di `AppServiceProvider.php` dan static asset dilayani langsung via Nginx. |

---

## 9. PERINTAH OPERASIONAL & PERAWATAN SISTEM

### A. Eksekusi Perintah Artisan
```bash
# === KUSUMAVISION BILLING ===
cd /var/www/KusumaVisionBilling
php artisan test                            # Jalankan test suite PHPUnit
php artisan billing:generate-invoices       # Manual trigger generate invoice
php artisan billing:send-reminders          # Manual trigger pengingat jatuh tempo
php artisan billing:auto-isolate            # Manual trigger evaluasi isolir
php artisan optimize:clear                  # Bersihkan seluruh cache aplikasi
npm run build                               # Compile ulang asset frontend

# === KUSUMAVISION MIKROTIK ===
cd /var/www/KusumaVisionMikrotik
php artisan test                            # Jalankan test suite MikroTik
php artisan routers:poll                    # Manual trigger poll router health
npm run build                               # Compile asset frontend

# === KUSUMAVISION NMS ===
cd /var/www/KusumaVisionNMS
bash scripts/test.sh                        # Jalankan test suite NMS
php artisan olts:poll                       # Manual trigger poll OLT
npm run build                               # Compile asset frontend
```

### B. Pengelolaan Service Daemon Supervisor & Nginx
```bash
# Restart Worker Antrean
supervisorctl restart kusumavision-billing-worker
supervisorctl restart kusumavision-mikrotik-worker
supervisorctl restart kusumavision-worker

# Cek Status Seluruh Daemon
supervisorctl status

# Reload Nginx Web Server
nginx -t && systemctl reload nginx
```

---

*Dokumentasi ini disusun sebagai standar operasional resmi PT BERKAH MEDIA KUSUMA VISION (BMKV) — Versi Agustus 2026.*
