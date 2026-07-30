# Panduan OLT HsAirPo / HSGQ (4PON EPON) — KusumaVision NMS

> **Brand: HsAirPo (HSGQ).** Manufaktur OEM = Shenzhen Photon Broadband (enterprise SNMP **12170**).
> Family driver di NMS: `SmartOltSupport::DRIVER_HSAIRPO_EPON` (`hsairpo-epon-12170`).
>
> Status: **Fase A SELESAI** (30 Jul 2026) — OLT masuk inventori, ONU + status online/offline terbaca,
> ikut polling terjadwal, alarm, global search, ONU Monitoring, peta & ODP. **Read-only**: Rx per-ONU
> (Fase B) dan aksi tulis ONU (Fase C) belum ada.
> Kredensial OLT uji ada di memori proyek `project_hsairpo_hsgq_olt` (sengaja tidak ditaruh di sini).
>
> **Relevansi armada:** brand HSGQ/HsAirPo = prospek **PAKLINK** (19 unit HSGQ EPON — memori
> `project_paklink_prospect`).

## 0. Ringkasan

OLT ini vendor baru (bukan ZTE / C-Data / HiOSO). Data ONU per-unit **HANYA tersedia via CLI** —
SNMP-nya tidak mengekspos tabel ONU (diverifikasi full-walk). Karena itu drivernya **CLI-first**
(beda dari HiOSO/C-Data yang SNMP-first): SNMP dipakai untuk sistem + port PON, CLI untuk inventori ONU.

| Fase | Isi | Status |
|------|-----|--------|
| **A** | Inventori + status ONU (1 perintah CLI/scan) | **Selesai** (Jul 2026) |
| **B** | Rx/redaman per-ONU (CLI per-ONU + throttle) | Belum |
| **C** | Aksi tulis (reboot / rename / enable-disable / delete) | Belum (sintaks belum diverifikasi) |

## 1. Identitas perangkat

- **Brand jual: HsAirPo / HSGQ.** Manufaktur OEM: **Shenzhen Photon Broadband Technology Co.,Ltd** —
  enterprise **12170**. Brand HsAirPo/HSGQ tak muncul di SNMP, jadi deteksi paling andal via
  **sysObjectID `.1.3.6.1.4.1.12170.2.3`** (needle `12170`), dilengkapi needle `hsairpo`/`hsgq`/`photon`
  pada field vendor/nama yang diisi operator.
- ⚠️ **sysDescr perangkat ini KOSONG** (hanya spasi) — jangan mengandalkannya untuk deteksi family.
- Produk: **"4PON EPON-OLT"**. Firmware unit uji `1.1.2.20210408_release (build-145001)`.
  CLI gaya **Cisco-IOS / BDCOM** (`% Invalid input detected at '^' marker`, prompt `EPON-OLT#`).
- Port: **pon1–pon4** (EPON, ifIndex 5002–5005), ge1–ge6 (5006–5011), xge1–xge2 (5012–5013).
- ONU per PON: **maks 64** (LLID). Unit uji saat verifikasi: 116 ONU, 107 online (PON1/2/4 terpakai).

## 2. SNMP (terbatas — full-walk tersimpan)

Full walk ada di `storage/app/photon_12170_snmp_fullwalk.txt` (4641 baris).

**Yang BISA dibaca (community read):**
- MIB-2 system (`.1.3.6.1.2.1.1`) + interfaces (`.2` ifTable, `.31` ifXTable): status up/down +
  counter trafik pon/ge/xge. → dipakai driver untuk daftar & status port PON.
- BRIDGE-MIB FDB (`.1.3.6.1.2.1.17.*`): MAC yang dipelajari L2 — **BUKAN inventaris ONU** (tak ada
  ONU-ID/PON/status/Rx; ONU offline tak muncul; MAC pelanggan & uplink tercampur). Tidak dipakai.
- Skalar vendor `.1.3.6.1.4.1.12170.2.3.1.1.*` — dipakai driver:

  | OID | Isi |
  |-----|-----|
  | `.1.1.12.0` | Nama manufaktur ("Shenzhen Photon Broadband Technology Co.,Ltd") |
  | `.1.1.17.0` | Nama perangkat ("EPON-OLT") |
  | `.1.1.6.0` | MAC OLT (Hex-STRING) |

- Tabel per-PON `.1.3.6.1.4.1.12170.2.3.3.1.1.{kolom}.1.0.{pon}`:

  | Kolom | Isi | Verifikasi live |
  |-------|-----|-----------------|
  | 7 | Kapasitas ONU per PON | 64 di keempat PON |
  | 8 | **Jumlah ONU online per PON** | 29 / 53 / 0 / 25 = 107 — persis sama dengan CLI |

**Yang TIDAK ADA / rusak:**
- **Tidak ada tabel ONU per-unit** (MAC/Rx/online-offline individual) di SNMP mana pun.
- Cabang `.1.3.6.1.4.1.12170.2.3.1.2` **BUG**: GETNEXT-nya loop (OID tak naik) → tak bisa di-walk;
  isinya info-device, bukan ONU. Cabang `.2.3.2.4` juga mengulang.
  → **JANGAN walk subtree enterprise 12170**; ambil skalar vendor dengan `get()` OID persis
  (aturan ini diterapkan di `HsAirPoSnmp`).
- `snmp-server ?` di config hanya knob standar — vendor memang tak implement tabel ONU di SNMP.

## 3. CLI (sumber data ONU) — referensi perintah

Login: `Username:` → password → `EPON-OLT>` → `enable` → `EPON-OLT#` (unit uji tanpa password enable;
driver tetap menjawab bila prompt password muncul). Ada negosiasi **IAC telnet** → dipakai ulang
`App\Support\Telnet\TelnetIacFilter`. `terminal length 0` mematikan pager.

### 3.1 Inventori ONU — `show epon onu all info` ✅ (1 perintah, murah — dipakai Fase A)
```
-----------------------------------------------------------------------------
        PON   ONU       MAC                     Control  Run      Config   Match
              ID                                flag     state    state    state
-----------------------------------------------------------------------------
        1      1        0C:37:47:78:E9:27       Active   Online   Success  Match
        1      27       EC:23:7B:AF:B6:38       Active   Offline  Initial  Initial
        2      1        74:B5:7E:9D:19:6F       Active   Online   Success  Match
        4      27       84:93:B2:A0:8B:B6       Active   Online   Success  Match
-----------------------------------------------------------------------------
  Total: 116, online 107
```
Pemetaan ke record bentuk-ZTE (`HsAirPoCliService::parseOnuAllInfo`): `slot=1` (chassis tunggal),
`port` = PON, `onu_id` = ONU ID, `serial_number`/`mac` = MAC, `online` = `Run state == Online`,
`admin_state` = `Control flag` (Active → enable), plus `config_state`/`match_state` sebagai info.
Interface ditulis `pon{PON}:{ONU}`. Kolom dipisah campuran tab & spasi → parser pakai regex toleran.

### 3.2 Rx/redaman per-ONU — `show epon port {pon} onu {onuId} optical-info` (Fase B)
```
  Voltage(V)              : 3.23
  Tx optical power(dBm)   : 1.93
  Rx optical power(dBm)   : -21.43      <-- Rx ONU
  Laser bias current(mA)  : 18.25
  Temperature(C)          : 49.99
```
⚠️ **JANGAN** pakai varian `all` (`show epon port {n} onu all optical-info`) — **MACET / hang CLI**.
Harus per-ONU. 116 ONU = 116 perintah/poll → wajib throttle + guard anti-hang saat Fase B digarap.

### 3.3 Perintah lain (terverifikasi live)
- Detail 1 ONU: `show epon port {n} onu {id} info` — memuat `ONU distance(m)`, `Description`
  (kosong di unit uji), SLA, queue, port-type. Mahal (1 perintah/ONU) → tidak dipakai saat scan.
- Unconfigured: `show epon port {n} onu-autofind-list all` — **`all` wajib** (tanpa itu
  "% Incomplete command"). Saat kosong menjawab `Error: There is no ONU does not exist in autofind list.`
  (typo milik vendor). Format tabel saat berisi belum pernah terlihat → parser mengambil MAC dari
  baris mana pun secara toleran.
- OLT-side DDM: `show epon port {n} ddm-info`.
- `show version`: Version / Product Name / Product OID / MAC Address / Running Time — dipakai driver
  untuk firmware & produk (`HsAirPoCliService::parseVersion`).
- Pohon help: `show epon onu ?` = all|autoauth|autofind; `show epon onu all ?` = info|loadstate;
  `show epon port {n} ?` = anti-rogueont|ddm-info|linkStatus|onu|onu-autofind-list|onu-black-list|vlan-pool;
  `show epon port {n} onu {id} ?` = info|loadstate|optical-info|version.
- Aksi tulis: node config `epon` punya grup `onu` (kemungkinan reboot/deactivate/delete) — **belum
  diverifikasi live** (Fase C). Selama belum diverifikasi, semua capability write tetap `false`.

## 4. Implementasi di NMS (Fase A)

| Berkas | Peran |
|--------|-------|
| `app/Support/SmartOltSupport.php` | `DRIVER_HSAIRPO_EPON`, deteksi (needle 12170/hsairpo/hsgq/photon, **sebelum** needle `epon` C-Data), `isHsAirPo()`, `isNonZte()`, `inventoryRoutePrefix()` → `hsairpo-olt`, `hsAirPoEponCapabilities()` |
| `app/Services/HsAirPo/HsAirPoSnmp.php` | Transport SNMP read v1/v2c (get + walk MIB-2). Tak boleh walk subtree enterprise |
| `app/Services/HsAirPo/HsAirPoCliService.php` | Sesi telnet (IAC, login IOS, `terminal length 0`, `--More--`, **timeout keras anti-hang**) + parser `parseOnuAllInfo`/`parseVersion`/`parseFooterCount`/`parseAutofindList` |
| `app/Services/HsAirPo/HsAirPoEponService.php` | Driver `SmartOltSnmpDriver`: sistem & port dari SNMP, ONU dari CLI; hasil sesi CLI **di-memo per-OLT** agar 1 scan = 1 sesi telnet |
| `app/Services/SmartOltSnmpServiceResolver.php` | Memetakan family → driver |
| `app/Services/CData/CDataOltScanner.php` | Scan penuh bersama (faceplate di-skip untuk family ini) |
| `app/Http/Controllers/HsAirPoOltController.php` + rute `hsairpo-olt.*` | Halaman & aksi read-only (create/edit/test/detail/port-onus/refresh) |
| `resources/js/Pages/HsAirPo/*` | Create / Edit / Detail / PortOnus + `Partials/HsAirPoOltForm` |
| `resources/js/Pages/SmartOlt/Index.vue` | Tab **"OLT HsAirPo"** (partisi `hsairpoOlts` dari `SmartOltController::index`) |
| `tests/Unit/HsAirPoCliParseTest.php`, `tests/Feature/HsAirPoOltTest.php` | Parser (fixture output asli) + halaman/rute/deteksi family |

**Capability Fase A** (`hsAirPoEponCapabilities()`): `pon_label=EPON`, `read_only=true`,
`supports_snmp_rx=false`, `supports_cli_rx=false`, dan **seluruh** capability write (`supports_reboot`,
`supports_onu_toggle`, `supports_onu_info_write`, `supports_onu_delete`, `supports_provisioning`,
`supports_config_save`) `false`. Karena semua jalur tulis lintas-halaman (peta, REST API, bot Telegram)
sudah di-gate capability, family ini otomatis menolak aksi tulis tanpa cabang kode tambahan.

**Yang ikut jalan otomatis** karena memakai jalur non-ZTE bersama: polling terjadwal
(`PollOltJob::pollViaScanner`), alarm (OLT unreachable / port down / ONU offline), ONU Monitoring,
global search, peta ONU + ODP, dan REST API baca (mobile).

### Verifikasi live (30 Jul 2026, OLT lab 4PON EPON-OLT)
- `driverKey` dari sysObjectID `.1.3.6.1.4.1.12170.2.3` → `hsairpo-epon-12170`; route prefix `hsairpo-olt`.
- `getPorts()` → pon1–pon4, semua `up`, `onu_online_snmp` 29/53/0/25.
- `getRegisteredOnus()` → **116 ONU, 107 online**; per-PON 29/53/25 online — **cocok persis** dengan
  penghitung SNMP vendor dan footer CLI (`Total: 116, online 107`).
- `CDataOltScanner::scan()` → 116 ONU dalam ~5,5 detik (1 sesi telnet + beberapa GET SNMP), cache
  `port_onus` terbentuk untuk keempat PON (termasuk PON3 kosong).
- Global search MAC ONU → `route_prefix: hsairpo-olt`.

## 5. Risiko & catatan operasional

- **CLI rewel**: `optical-info all` **hang**. Semua interaksi telnet driver ini punya batas waktu keras
  dan melempar exception bila prompt tak kembali — sesi buntu tak boleh menahan worker polling.
- **Telnet wajib**: tanpa kredensial CLI yang benar, OLT tetap tampil (sistem + port dari SNMP) tapi
  daftar ONU kosong. Form menandai CLI sebagai wajib; `cli_transport` hanya menerima `telnet`.
- Satu scan = 2 perintah CLI (`show version` + `show epon onu all info`) + beberapa GET SNMP → ringan,
  aman untuk interval poll normal (5 menit).
- `sysDescr` kosong: bila operator mengosongkan vendor/nama, deteksi tetap jalan lewat sysObjectID
  setelah tombol **Test** sekali dijalankan.
- ⚠️ Di IP publik OLT uji ada OLT LAIN (HiOSO HA7304VX, enterprise 25355) di port telnet berbeda —
  jangan tertukar; lihat memori `project_shared_ip_telnet_port_collision`.
- Lihat memori: `project_hsairpo_hsgq_olt` (akses & kredensial lab), `project_paklink_prospect`
  (armada 19 HSGQ), `feedback_vendor_fatigue` (family baru dikerjakan atas permintaan owner),
  `project_prod_deploy_gotchas` (restart worker supervisor setelah ubah kode driver/job).
