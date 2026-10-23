# Panduan OLT HSGQ (EPON & GPON) — KusumaVision NMS

> **Status: EKSPERIMENTAL (Okt 2026), BELUM TERVERIFIKASI DI PERANGKAT.** Driver ini disusun dari dokumentasi
> komunitas tanpa OLT uji dan sengaja disediakan langsung di aplikasi agar pemilik OLT HSGQ bisa mencoba dan melapor
> (GitHub Issues: lampirkan **laporan diagnostik** dari tombol «Test & diagnostik», §4.5). Pengecualian sadar atas aturan
> "OID/CLI hanya dari perangkat asli" (handbook 14 §E), sama seperti EPON ZTE (`App\Support\ZteEpon`). Setiap OID,
> perintah, dan endpoint di bawah diberi tingkat keyakinan; yang belum A tetap ditandai **belum terverifikasi** di kode
> (lencana "Belum terverifikasi" di UI, `capabilities.hsgq_unverified`).
>
> Tingkat keyakinan: **A** = keluaran perangkat asli terlihat (walk/CLI/JSON nyata, dicocokkan ke UI) · **B** =
> dokumen atau kode UI resmi vendor · **C** = kode pihak ketiga yang dipakai nyata, tanpa dump respons · **D** = dugaan.

## 0. Cakupan

| Hal | Keputusan |
|---|---|
| Teknologi | EPON **dan** GPON (satu driver, satu halaman). |
| Aksi tulis tanpa uji | Hanya yang punya sumber: reboot, ganti nama/deskripsi, simpan config — langsung aktif, tanpa saklar. Hapus, enable/disable, registrasi mati (tak ada sumber). Pengaman: lencana belum terverifikasi, konfirmasi, audit log, target ONU lewat SN/MAC. |
| OEM 12170 berlabel HSGQ (modul HsAirPo lama) | Tidak didukung. Kodenya ada di riwayat git (`f38741d^`). |
| Akses web | Kolom `web_port` (migrasi `2026_10_23_000002`); akun web = `cli_username`/`cli_password`. |

## 1. Tiga garis firmware di balik merek "HSGQ"

Label "HSGQ" di casing **tidak menentukan firmware**. Riset menemukan minimal tiga garis:

| Garis | Enterprise (IANA) | Model | Jalur kelola | Bukti di NMS |
|---|---|---|---|---|
| **HSGQ asli, GPON** | **50224** (Shenzhen HS Fiber Communication Equipment) | G01, G02, G04L/G08L/G16L, varian "ID" G02ID/G04ID | SNMP `.50224.3.12.*` + web JSON | Belum ada (bukti A dari pihak ketiga) |
| **HSGQ asli, EPON** | **50224** | E04R/E04T/E08R, uplink 10G XE04S/XE08R; E04I/E08I diduga sama (D) | SNMP `.50224.3.3.*` + web JSON + CLI `OLT#` | Belum ada (bukti B/C) |
| OEM Photon/SZCOM | 12170 | "4PON EPON-OLT" | CLI `EPON-OLT#` (SNMP tanpa tabel ONU per unit) | Pernah dibangun dan diuji Jul 2026, dihapus 29 Sep |

- 50224 terkonfirmasi di registri IANA (A): `Shenzhen HS Fiber Communication Equipment CO.,LTD`, kontak `@hsgq.com`.
- **Tak ada PON 10G-EPON di lini HSGQ** (B, datasheet/marketplace). "10G" pada XE04/XE08R = uplink SFP+, PON tetap
  EPON 1G (1:64, ≤512 ONU). Jadi unit "10G EPON" tetap memakai driver EPON yang sama.
- Kode lama yang memakai sintaks ZTE untuk "HSGQ GPON" (repo `alijayanet/billing-rtrw`) tidak kredibel dan **tidak dipakai**.
- MIB `Kim-puL/MIB-Vendor-HSGQ` dibuat AI dari snmpwalk (diakui README-nya) dan strukturnya bertentangan dengan
  walk nyata → **D, jangan dipakai**. MIB resmi HSGQ tidak ditemukan di mana pun.

### 1.1 Deteksi

1. `sysObjectID` diawali `.1.3.6.1.4.1.50224` → HSGQ asli. G02ID melapor `.1.3.6.1.4.1.50224.3.1.1` (A).
   Diawali `.1.3.6.1.4.1.12170` → OEM Photon (di luar fase awal, lihat K3).
2. `sysDescr` = model di UI, mis. `HSGQ-G02ID` (A). Pola `G\d` → GPON; `E\d`/`XE\d` → EPON (D untuk EPON: belum ada
   sysDescr EPON asli).
3. Bila model tak jelas: probe subtree mana yang berisi baris (`.3.12.2` → GPON, `.3.3.2` → EPON), simpan hasilnya di
   `last_test_result.hsgq.pon_type`.
4. Web (bila kredensial ada): `GET /board?info=system` → `device_type` 1 = EPON, 2 = GPON (C; pemetaan di kode klien ambigu, wajib dicek).

Needle `hsgq`, `50224`, `hs fiber` **harus diperiksa sebelum** needle `epon` milik C-Data di `SmartOltSupport::driverKey()`.
Tanpa itu OLT HSGQ EPON jatuh ke C-Data EPON (pelajaran modul lama). **Sebelum upgrade:** OLT terdaftar yang nama/vendornya
memuat `hsgq` akan pindah dari tab C-Data ke tab HSGQ.

## 2. Bukti per jalur

### 2.1 SNMP enterprise 50224 (baca, untuk polling)

**Rumus indeks (A, sama di GPON & EPON):** `index = 16777216 + PON×256 + ONU` (= `0x01000000 | PON<<8 | ONU`).
PON01 = `16777472`, PON02 = `16777728`. Indeks ONU = indeks PON + nomor ONU di UI. Nomor boleh bolong; indeks mengikuti
ONU, bukan nama. Indeks port enterprise = `16777216 + (ifIndex−256)×256` (PON01 ifIndex 257, GE01 259, XGE01 263).
**Nomor ONU GPON mulai dari 0** (C, Go-SNMP-HSGQ). NMS harus menerima `onu_id = 0`.

Sistem (A, kecuali ditandai):

| OID | Isi | Skala |
|---|---|---|
| `1.3.6.1.2.1.1.1.0` | Model (sysDescr), mis. `HSGQ-G02ID` | teks |
| `1.3.6.1.2.1.1.5.0` | Nama perangkat | teks |
| `1.3.6.1.2.1.1.3.0` | Uptime | centidetik |
| `.50224.3.1.1.1.0` | MAC OLT | hex |
| `.50224.3.1.1.6.0` | Firmware | hex→teks, mis. `IGC_V1.2.9ID_Rel` |
| `.50224.3.1.1.20.0` | Serial OLT | teks |
| `.50224.3.1.1.17.0` / `.18.0` | CPU % / memori % | int |
| `.50224.3.1.1.8/9/10/11.0` | jumlah PON / GE / XGE / total port | int (D) |

Port: **IF-MIB standar** (`ifDescr` = `PON01`, `GE01`, `XGE01`; `ifOperStatus` 1 = up). **GET ke `ifTable` dijawab
`No Such Instance`**, jadi harus WALK/GETBULK. `ifXTable` bisa di-GET (A). Kolom status di tabel port enterprise
**terbalik** (2 = up), jangan dipakai.

Per PON (indeks = indeks PON):

| OID | Isi | Keyakinan |
|---|---|---|
| `.50224.3.2.3.1.3.{idx}` / `.4` / `.5` | ONU terdaftar / online / offline | OID A, arti kolom 4/5 C (dipakai bot produksi; belum terlihat saat ada ONU offline) |
| `.50224.3.2.4.1.8/9/10/11.{idx}` | SFP PON OLT: suhu °C / tegangan V / bias mA / Tx dBm, semua ÷100 | A (4450 = 44,50 °C; UI 44,70) |
| `.50224.3.2.4.1.12.{idx}` | Rx modul PON (≈ −33 dBm, janggal) | D, jangan ditampilkan |

**Tabel ONU GPON** `.50224.3.12.2.1.{kolom}.{idx}` (A kecuali ditandai):

| Kolom | Isi |
|---|---|
| 2 | nama ONU (berubah saat di-rename) |
| 3 | status 0/1 (C; Zabbix: kolom 3/4/5/24 semua 1 saat online, arti belum terbukti) |
| 8 / 9 / 13 | vendor / model / firmware ONU (B/C) |
| 15 | serial ONU (hex: 4 byte vendor ASCII + 4 byte → `HWTCA9089620`) |
| 19 | jarak (m) — 13/13 cocok UI |
| 20 / 23 | last up time / lama online (centidetik) |
| 22 | penyebab down terakhir (`Power down`, `Laser out`) |

Optik ONU GPON `.50224.3.12.3.1.{kolom}.{idx}.{suffix}`: kolom 4 Rx, 5 Tx, 6 bias, 7 tegangan, semua ÷100. Suffix `.0.0`
= dibaca ONU, `.65535.65535` = **Rx di sisi OLT** (A/C). **Resolusi Rx SNMP 1 dB, dibulatkan ke bawah** (−20 di SNMP =
−19,36 di UI). Pernah terbaca +1,49 dBm sesaat (glitch) → nilai Rx ≥ 0 dianggap tidak valid.

**Tabel ONU EPON** `.50224.3.3.2.1.{kolom}.{idx}` (C, dua bot independen `tkjw92/bot_hsgq` & `zakmizank/tele-bot-olt`):
1 indeks (Gauge32, titik awal BulkWalk), 2 nama, 7 MAC (OctetString), 9 status (1 = up), 15 jarak, 25 vendor. Optik
`.50224.3.3.3.1.4/5.{idx}.0.0` = Rx/Tx ÷100.

**Larangan:** subtree sistem `.3.1.1` dan tabel akun `.3.1.5` **memuat kredensial (termasuk community tulis) yang
terbaca lewat community baca** (A). Jangan pernah walk subtree enterprise utuh; ambil skalar dengan GET OID persis dan
walk hanya kolom tabel yang disebut di atas. Hasil walk mentah tak boleh disimpan, di-log, atau ditempel ke issue.

Walk penuh G02ID ±2.286 baris. Tabel ONU 52 ONU × 25 kolom = 1.300 baris. G16L bisa ±2.000 ONU, jadi walk per kolom
dengan GETBULK, bukan seluruh tabel.

Trap: enterprise 50224 mengirim teks seperti `ONU 1/3 <mac> Laser out`, `dying gasp`, `link up` (A). NMS belum
menerima trap; di luar cakupan.

### 2.2 Web JSON API (aksi tulis + daftar ONU lengkap)

Mekanisme login identik di 5+ repo independen (A untuk mekanisme, C untuk payload tiap endpoint):

```
POST http://<olt>:<web_port>/userlogin?form=login
{"method":"set","param":{"name":"<user>","key":"<md5(user:pass) hex>","value":"<base64(pass)>","captcha_v":"","captcha_f":""}}
→ body {"code":1,"message":"success",...} + header respons X-Token: <token>
```

- Semua request berikut mengirim header `X-Token`. `code`: 1 = sukses, 0 = login ilegal, −1 = token/login habis.
  Token basi → `message: "Token Check Failed"` → login ulang sekali lalu ulangi.
- Bawaan `root`/`admin`; IP pabrik 192.168.100.1 (out-band) / 192.168.99.1 (in-band) (B). **HTTP polos, tanpa TLS**:
  sandi (base64) dan md5 lewat jaringan apa adanya → hanya lewat VPN/WireGuard.
- Logout otomatis setelah ±5 menit idle (B). Captcha bisa aktif (`GET /system_captcha`); bila aktif, driver menyerah
  dengan pesan jelas, tidak mencoba menembusnya.
- **Belum diketahui:** apakah login NMS menendang sesi web admin yang sedang dipakai. Karena itu polling terjadwal
  **tidak** login web.

Baca:

| Endpoint (GET) | Isi | Keyakinan |
|---|---|---|
| `/board?info=system` | vendor, product_name, fw_ver, macaddr, sn, ponports, device_type | C |
| `/board?info=pon` | `[{port_id, online, offline}]` | A |
| GPON `/ontinfo_table` | `ont_sn, ont_name, ont_description, port_id, ont_id, identifier, rstate` (0 initial/1 online/2 offline), `receive_power, last_u_time, last_d_time, last_d_cause` | C |
| GPON `/gponont_mgmt?form=base\|ont_optical\|ont_version&port_id=&ont_id=` | detail, optik presisi 0,01 dB, model ONU | C |
| EPON `/onutable` | `port_id, onu_id, onu_name, onu_desc, macaddr, status` (Initial/Offline/Register/Auth-Fail/Unauth/Auth Limit/Online), `receive_power` ("-19.36 dBm"), `dev_type` HGU/SFU, `auth_state, register_time, last_down_time, last_down_reason, distance` | A untuk daftar, B untuk nilai status (mock bawaan UI vendor) |
| EPON `/onumgmt?form=base-info\|optical-diagnose&port_id=&onu_id=` | detail & optik | A/C |
| GPON `/gponont_mgmt?form=auth&port_id=0`, EPON `/onu_allow_list?port_id=` | ONU belum terdaftar | **bentuk respons tak diketahui** |

Tulis (C, dari `plugnowplay/HSGQtelegram` `src/olt.js`; bot lain mengonfirmasi EPON):

| Aksi | Request |
|---|---|
| GPON reboot | `POST /gponont_mgmt?form=info` `{"method":"set","param":{"identifier":ID,"flags":4,"ont_name":"","ont_description":""}}` |
| GPON ganti nama/deskripsi | sama, `flags: 8`, `ont_name`, `ont_description` |
| EPON reboot | `POST /onumgmt?form=config` `{"method":"set","param":{"port_id":P,"onu_id":O,"flags":1,"fec_mode":1}}` |
| EPON ganti nama/deskripsi | sama, `flags: 8`, `onu_name`, `onu_desc` |
| Simpan konfigurasi | `POST /system_save` `{"method":"set","param":{}}`; tanpa ini perubahan hilang saat OLT reboot |

`flags` adalah bitmask. Yang diketahui hanya 1/4/8; UI vendor juga punya "Deregister ONU", "Change Fec Mode", "Delete
ONU", dan "Reject ONU to blacklist", tetapi payload-nya tidak ditemukan. **Risiko `fec_mode`:** bot selalu mengirim
`fec_mode: 1`, sehingga kemungkinan aksi itu ikut **mengubah** mode FEC ONU. Driver membaca `base-info` dulu dan
mengirim ulang nilai `fec_mode` yang sedang berlaku bila ada. Bila nilainya tak ada, aksi EPON tetap jalan seperti bot
tetapi diberi peringatan di diagnostik.

### 2.3 CLI telnet

- HSGQ asli EPON (A, satu cuplikan saja, `Ilmuanbotak/EPON-HSGQ`): `OLT>` → `enable` → `OLT#` → `configure` →
  `show onu-info all`. Contoh di bawah memakai nama fiktif:
  ```
   PON/ONU     Mac-Address    Status   Auth  Cfg     Reg-time            ONU-Name     ONU-Desc
   1/1     00:00:5e:00:53:01 Online    TRUE  TRUE  2024/05/20 13:02:20      Pelanggan-01 NO-DESCRIPTI
  ```
  Nama dan deskripsi terpotong 12 karakter. Perintah lain (otorisasi, VLAN, simpan) **tak ditemukan**, jadi CLI tidak
  dipakai di fase awal.
- HSGQ GPON: **tak ada satu pun transkrip CLI atau manual**. Jangan membangun jalur CLI GPON.
- OEM 12170: fixture keluaran asli ada di `git show f38741d^:tests/Unit/HsAirPoCliParseTest.php`, panduan di
  `git show f38741d^:docs/SMARTOLT_HSAIRPO_GUIDE.md`. **Catatan:** full-walk SNMP unit lab saat itu terhenti di cabang
  yang loop (`.12170.2.3.2.4`), jadi subtree sesudahnya (`.2.3.3` dst.) tak pernah dibaca utuh. Kesimpulan "SNMP 12170
  tak punya tabel ONU" belum final.

### 2.4 Yang dicari tapi tidak ditemukan

MIB resmi; manual CLI/API resmi (hsgq.com/download hanya datasheet; firmware lewat `hsgqcloud.com`, perlu akun); payload
otorisasi ONU (web maupun CLI); bentuk respons daftar ONU belum terdaftar; payload hapus/deregister/enable-disable; arti
kolom status 4/5/24 GPON; sysDescr/sysObjectID EPON 50224 asli; template LibreNMS/Observium.

## 3. Peta fitur: HSGQ dibanding family lain

| Fitur NMS | ZTE | C-Data | HiOSO | **HSGQ fase awal** |
|---|---|---|---|---|
| Tambah/edit/hapus OLT, tombol Test, deteksi family | ✓ | ✓ | ✓ | ✓ (+ laporan diagnostik, §4.5) |
| Info sistem (model, firmware, serial, uptime, CPU/mem) | ✓ | ✓ | ✓ | ✓ SNMP (A) |
| Daftar port PON + status + jumlah ONU | ✓ | ✓ | ✓ | ✓ IF-MIB walk + `.3.2.3` |
| DDM modul SFP PON | – | ✓ (V3 CLI) | – | ✓ SNMP `.3.2.4` (A) |
| ONU per port: nama, SN/MAC, status, jarak, Rx | ✓ | ✓ | ✓ | ✓ GPON A / EPON C; Rx SNMP 1 dB |
| Vendor/model/firmware ONU, penyebab down terakhir | sebagian | – | – | ✓ GPON |
| Polling terjadwal, alarm, ONU Monitoring, pencarian global, peta & ODP, label port | ✓ | ✓ | ✓ | ✓ otomatis lewat jalur non-ZTE (`isNonZte`) |
| REST API v1 baca (aplikasi Android) | ✓ | ✓ | ✓ | ✓ otomatis |
| Reboot ONU | ✓ | ✓ | ✓ | ⚠ eksperimental, web |
| Ganti nama (+ deskripsi) ONU | ✓ | ✓ | ✓ | ⚠ eksperimental, web (nama & deskripsi terpisah) |
| Simpan konfigurasi | ✓ | ✓ | ✓ | ⚠ eksperimental, `/system_save` |
| Hapus / enable-disable ONU | ✓ | ✓ | ✓ | ✗ payload tak diketahui |
| ONU belum terdaftar, registrasi/otorisasi ONU | ✓ | – | – | ✗ respons & payload tak diketahui |
| VLAN, detail port CLI, backup config, Bind/Pre-Register | sebagian | sebagian | – | ✗ |

## 4. Implementasi

### 4.1 Driver & varian

- Satu driver `SmartOltSupport::DRIVER_HSGQ = 'hsgq-50224'`; needle `hsgq`, `hs fiber`, `.4.1.50224`, `enterprises.50224`
  diperiksa **setelah ZTE, sebelum** needle `gpon`/`epon` C-Data. Haystack yang memuat `12170` (OEM lama) dikecualikan.
- Varian EPON/GPON = `system.pon_type` hasil scan (`SmartOltSupport::hsgqPonType()`): dari kode model sysDescr
  (`hsgqPonTypeFromText`: `HSGQ-G04L` → gpon, `HSGQ-XE08R`/`E04I` → epon) atau jenis identitas ONU di tabel SNMP
  (skema `.3.3` = MAC → epon). Sebelum scan pertama ditebak dari vendor/nama; bawaan GPON.
- Baris ONU berbentuk sama dengan family lain: `slot = 1`, `port` = PON, `onu_id` = nomor ONU (**GPON boleh 0**),
  `onu_key = "{pon}.{onu}"`, `interface = PON01/5`, `serial_number` = SN GPON atau MAC EPON (`AA:BB:…`).
  Tambahan HSGQ: `distance_m`, `uptime`, `olt_rx_power_dbm`, `tx_power_dbm`, `firmware`, `status_source`,
  `status_verified`, `status_raw`. Penyebab down `Power down` → phase `DyingGasp`, `Laser out` → `LOS`.

### 4.2 Berkas

| Berkas | Peran |
|---|---|
| `app/Services/Hsgq/HsgqSnmp.php` | Transport SNMP v1/v2c; tabel selalu walk (GET `ifTable` gagal di perangkat), `oid_increasing_check` tetap aktif, subtree tak ada → `[]` |
| `app/Services/Hsgq/HsgqValue.php` | Helper murni: indeks `0x01000000\|PON<<8\|ONU`, SN/MAC, teks hex firmware, ÷100, durasi, masker diagnostik |
| `app/Services/Hsgq/HsgqSnmpService.php` | `SmartOltSnmpDriver`: sistem (GET OID persis), port (IF-MIB + penghitung `.3.2.3` + SFP `.3.2.4`), ONU (skema `.3.12` lalu `.3.3`), pemilihan status, `diagnose()` |
| `app/Services/Hsgq/HsgqWebClient.php` | Login `X-Token` (md5 `user:pass` + base64 sandi), retry sekali saat "Token Check Failed"/`code -1`, penjaga SSRF generik, tanpa redirect, timeout 5/15 dtk, batas 8 MB |
| `app/Services/Hsgq/HsgqWebService.php` | Reboot, ganti nama/deskripsi, `system_save`, `onuTable()`, `diagnose()`; target ONU via SN/MAC; audit `onu.rebooted/renamed`, `olt.config_saved` (+ `_failed`) |
| `app/Http/Controllers/HsgqOltController.php` + rute `hsgq-olt.*` | index/create/store/edit/update/destroy/test/detail/refresh/config.save/pon-ports/port-onus/port-onus.refresh/onu.reboot/onu.info |
| `resources/js/Pages/Hsgq/{Create,Edit,Detail,PortOnus}.vue` + `Partials/HsgqOltForm.vue` | Form (bagian "Akses web & CLI" + peringatan HTTP polos), Detail (tabel port + SFP, panel laporan diagnostik yang bisa disalin), ONU per port (nama + deskripsi, jarak, penyebab down) |
| `lang/{id,en}/hsgq.php`, namespace `hsgq` di `resources/js/lang/{id,en}.json` | Teks dwibahasa |
| `public/img/olt/hsgq-*.webp` | Foto resmi HSGQ per model (`lib/oltImage.js` memilih dari kode model) |
| `tests/Unit/HsgqSnmpDriverTest.php`, `tests/Feature/HsgqOltTest.php`, `tests/js/HsgqPages.spec.js` | Fixture fiktif berbentuk peta OID §2; Http::fake untuk web |

Titik sambung: `SmartOltSnmpServiceResolver`, `CDataOltScanner` (HSGQ tanpa faceplate), `PollOltJob` (otomatis via
`isNonZte`), `AlarmEvaluator` (penahan `status_verified=false`), `SmartOltController` (tab + `hasCapability` menolak
HSGQ di rute `smartolt.*`), `OnuMapController`, `Api/V1/OnuActionController`, `TelegramCommandHandler`,
`OnuQuickActionModal` (enable/disable ditolak bila rute `onu.state` tak ada), `ManagesOltOwnership` (`web_port` =
kolom koneksi terkunci).

### 4.3 Sumber data

- **Polling & scan = SNMP saja**, tanpa login web (belum diketahui apakah login NMS menendang sesi admin di web OLT).
- Aksi web memanggil `/ontinfo_table` (GPON) atau `/onutable` (EPON) dulu untuk mencari ONU lewat SN/MAC, karena
  basis penomoran `port_id`/`ont_id` web vs indeks SNMP belum diketahui.
- Rx di tabel = SNMP (resolusi 1 dB). Rx presisi dari web belum dipakai.

### 4.4 Pengaman karena belum teruji

- **Pemilihan status:** kandidat `status_column` → `online_time` (GPON) → `rx`; yang dipakai adalah yang jumlah online
  per PON-nya sama dengan penghitung OLT `.3.2.3.1.4`. Tak ada yang cocok (atau penghitung tak ada) → semua baris
  `status_verified=false`, `AlarmEvaluator` tidak menaikkan alarm ONU offline/LOS/dying-gasp maupun ODP untuk OLT
  itu (alarm Rx tetap jalan), dan halaman menampilkan peringatan.
- Rx ≥ 0 atau ≤ −60 dBm → `null` (glitch +1,49 dBm tercatat di peta OID G02ID).
- Tak pernah walk `.3.1.1`/`.3.1.5` (kredensial); test `test_never_walks_credential_subtrees` menjaganya.
- Reboot mengirim ulang nama & deskripsi yang berlaku; EPON mengirim ulang `fec_mode` dari `base-info` (bot pihak
  ketiga selalu `1`). Nama/deskripsi disanitasi `[A-Za-z0-9 _.-@#()/]`, maks 32.
- Klien web menolak alamat loopback, link-local (metadata cloud), multicast, dan cadangan; host = IP, tanpa redirect.
  Batasi jaringan internal Anda sendiri lewat firewall server bila perlu.
- Rute `smartolt.*` menolak OLT HSGQ; API v1, peta, Telegram bercabang ke `HsgqWebService` — tak satu pun jalur
  mengirim perintah ZTE ke OLT HSGQ (dijaga `test_zte_routes_and_other_channels_never_run_zte_code_on_hsgq`).
- Rename tidak otomatis `system_save` (konvensi proyek); pesan sukses mengingatkan menekan **Simpan Config**.

### 4.5 Laporan diagnostik (alat verifikasi)

Tombol **Test & diagnostik** (daftar OLT / halaman Detail) menyimpan `last_test_result.hsgq_diag`, ditampilkan di
Detail dengan tombol **Salin laporan**:
- SNMP: sysObjectID, sysDescr, firmware, `pon_type`, skema, jumlah baris tabel `.3.12`/`.3.3`, port (ifDescr, status,
  penghitung), hasil pemilihan status (kandidat & angka per PON), satu ONU contoh dengan **SN/MAC disamarkan** dan
  panjang nama saja.
- Web (bila `web_port` terisi): login berhasil/gagal/captcha (tanpa sandi), field `board?info=system`, endpoint tabel
  ONU + nama field baris pertama, dan untuk EPON field `base-info` (ada tidaknya `fec_mode`).

## 5. Status per bagian

| Bagian | Status |
|---|---|
| Deteksi, kapabilitas, tab, halaman, rute, i18n, foto | Tayang (23 Okt 2026) |
| SNMP baca GPON skema `.3.12` | Tayang — OID keyakinan A, belum diuji di OLT milik kita |
| SNMP baca EPON skema `.3.3` | Tayang — keyakinan C |
| Reboot, ganti nama/deskripsi, simpan config (web) | Tayang — keyakinan C, aktif tanpa saklar (K2) |
| Hapus, enable/disable, registrasi/otorisasi, ONU unconfigured | Belum — payload/respons tak dikenal (§2.4) |
| Faceplate panel depan | Belum — tata letak port per model belum dipetakan |
| Label family di aplikasi Android | Belum — aplikasi menampilkan kolom vendor |

## 6. Risiko

| Risiko | Mitigasi |
|---|---|
| Skema SNMP beda antar firmware (sudah terlihat `.3.3` vs `.3.12`) | Probe kedua skema; diagnostik menunjukkan mana yang hidup |
| Arti kolom status belum pasti | Pilih sinyal yang cocok dengan penghitung `.3.2.3`; tak cocok → `status_verified=false` + alarm offline ditahan |
| Login web menendang sesi admin / batas sesi | Polling tidak login web; login hanya saat aksi manual |
| `fec_mode` ikut berubah saat reboot/rename EPON | Kirim ulang nilai dari `base-info`; tampilkan di diagnostik |
| SSRF lewat klien HTTP | Penjaga alamat generik, host IP, tanpa redirect, timeout, batas ukuran |
| Kredensial lewat HTTP polos / OID kredensial terbaca | Wajib VPN; GET OID persis, tak pernah walk subtree sistem |
| Fitur tampil "jalan" padahal salah karena tak diuji | Lencana "Belum terverifikasi", konfirmasi + audit log, laporan diagnostik, checklist §7 |

## 7. Checklist saat OLT HSGQ pertama tersedia

1. Test → salin laporan diagnostik. Cocokkan sysObjectID/sysDescr EPON dengan §1.1, lalu catat model & firmware.
2. Bandingkan 3 ONU (nama, SN/MAC, jarak, Rx) di NMS dengan web UI OLT.
3. Tunggu ONU offline alami: pastikan `status_check` di laporan memilih sumber yang benar (`status_column` ideal) dan
   `.3.2.3.1.4/5` = online/offline. Selama tak cocok, alarm ONU offline OLT itu memang ditahan (§4.4).
4. Aksi tulis di satu ONU uji milik sendiri: ganti nama → cek UI → simpan → reboot ONU itu. Untuk EPON, cek `fec_mode`
   sebelum dan sesudah.
5. Catat bukti (OLT, firmware, tanggal) di WORKLOG dan panduan, turunkan tanda "belum terverifikasi" per bagian.

Punya OLT HSGQ? Buka GitHub Issue berisi laporan diagnostik (sudah tersamar) dan catatan apa yang cocok/tidak cocok
dengan web UI OLT Anda — itu sumber verifikasi utama driver ini.

## 8. Sumber

- IANA Private Enterprise Numbers — https://www.iana.org/assignments/enterprise-numbers.txt (50224, 12170, 25355)
- `Ogydump-mile/hsgq-g02id-olt-zabbix` (`OID_MAP.md`, G02ID `IGC_V1.2.9ID_Rel`, tiap OID dicocokkan ke UI) — A
- `fajriyandi/Go-SNMP-HSGQ` (README: OID `.3.12`, Rx OLT `.65535.65535`, contoh JSON, ONU mulai 0) — A/C
- `plugnowplay/HSGQtelegram` (`src/olt.js`: login, endpoint GPON & EPON, reboot/rename/`system_save`) — C
- `radigi2207/HSGQ-Telegram-Bot` (`hsgq.js` = bundel UI vendor: daftar endpoint, mock `/onutable`) — B
- `zakmizank/tele-bot-olt`, `tkjw92/bot_hsgq` (skema EPON `.3.3`), `nixon42/wa-bot_snmp_monitor` (`.3.2.3`) — C
- `Ilmuanbotak/EPON-HSGQ` (satu-satunya keluaran CLI asli `show onu-info all`) — A
- hsgq.com/download, hsgq.com/epon (datasheet E04R/E04T/E08R, G01/G02/G04L/G08L/G16L, XE08R) — B
- AdminOLT, artikel "agregar OLT HSGQ" (form tambah OLT: SSH/Telnet/SNMP, bawaan `root`/`admin`) — B
- Ditolak: `Kim-puL/MIB-Vendor-HSGQ` (MIB buatan AI), `alijayanet/billing-rtrw` (sintaks ZTE)
