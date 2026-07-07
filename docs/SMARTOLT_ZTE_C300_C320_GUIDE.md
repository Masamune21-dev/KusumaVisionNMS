# Panduan Lengkap SmartOLT ZTE C300 / C320 (ZXA10)

Terakhir diperbarui: 25 Mei 2026

Dokumen ini adalah **referensi mandiri** untuk integrasi OLT ZTE GPON ZXA10 C300 / C320 ke dashboard apa pun (BMKV, project terpisah, atau web tools baru). Berisi spec SNMP, OID map, CLI command, encoding ifIndex composite, parser value, login transport (telnet/SSH), full flow provisioning ONU + reconfigure + reboot + profile management, dan template implementasi.

Pembaca target: developer yang akan membuat web baru untuk OLT ZTE C300/C320 dan ingin tahu **apa yang dipakai BMKV sekarang, kenapa, dan bagaimana mengulanginya**.

Companion docs:
- [SMARTOLT_OID_MAP.md](SMARTOLT_OID_MAP.md) — peta OID multi-vendor (ZTE + CData + HiOSO)
- [SMARTOLT_HIOSO_GUIDE.md](SMARTOLT_HIOSO_GUIDE.md) — referensi vendor lain (HA7304)
- [MODULE_GUIDE.md](MODULE_GUIDE.md) §12 — overview modul SmartOLT
- [pages/internal/smartolt-*.md](pages/internal/) — referensi per halaman dashboard

---

## 1. Identifikasi Device

OLT ZXA10 C300 / C320 diidentifikasi dari kombinasi:

- `sysObjectID` (`1.3.6.1.2.1.1.2.0`) → mengandung `iso.3.6.1.4.1.3902`
- `sysDescr` (`1.3.6.1.2.1.1.1.0`) → contoh: `"ZXA10 C320 V2.x"`, `"ZXAN..."`
- CLI prompt setelah login: `ZXAN>` (user mode) → `ZXAN#` (enable) → `ZXAN(config)#` (global config) → `ZXAN(config-if)#` (interface) → `ZXAN(gpon-onu-mng)#` (pon-onu-mng)
- ifTable `ifDescr` mengandung `gpon-olt_1/x/y` atau `gpon_olt-1/x/y` untuk port PON, `gpon-onu_1/x/y:z` untuk ONU

Detection string yang dipakai BMKV ([`SmartOltSupport::driverKey`](../app/Support/SmartOltSupport.php#L154-L206)) untuk match family ZTE (case-insensitive substring):

```
zte | 3902 | c300 | c320
```

Default fallback driver = `DRIVER_ZTE` bila vendor kosong atau tidak match keluarga lain.

---

## 2. Spesifikasi Koneksi

### 2.1 SNMP

| Parameter | Value | Catatan |
|---|---|---|
| Version | v1 / v2c (default v2c) | dipilih dari kolom `snmp_version` di tabel `snmp_olts` |
| Community read default | `public` (sangat sering diganti operator) | enkripsi at-rest di `snmp_olts.snmp_read_community` |
| Community write | beda dari read (`private`/custom) | dipakai untuk `setOnuName`, `setOnuDescription`, `setOnuActiveState` |
| UDP port | 161 default; non-standar via `snmp_port` | format host saat call: `IP[:PORT]`, lihat `SnmpOlt::getHostAddress()` |
| Timeout default | 2 detik (`2_000_000` µs) | BMKV override per call: 3–6 detik untuk walk besar |
| Retries default | 2 | dijaga minimum 1 |
| Enterprise root | `1.3.6.1.4.1.3902` | semua tabel ZTE-specific ada di sini |
| Walk max-oids | 10 | dipakai PHP `\SNMP` session untuk batching efisien |

Implementasi walk ([`ZteSnmpService::walk`](../app/Services/ZteSnmpService.php#L104-L154)):
1. Prefer ext-`SNMP` (PECL extension) yang support batch `max_oids` → jauh lebih cepat untuk OLT ribuan ONU
2. Fallback `snmp2_real_walk()` procedural bila ext-`SNMP` tidak ada
3. `oid_increasing_check = false` → toleran terhadap firmware yang return OID tidak strictly increasing

### 2.2 CLI Telnet / SSH

| Parameter | Value |
|---|---|
| Transport | Telnet (paling umum) atau SSH (lebih aman) |
| TCP port telnet | 23 default; override via `cli_port` |
| TCP port SSH | 22 default |
| Line ending | `\n` (LF saja) — ZTE toleran, **beda dengan HiOSO yang strict CRLF** |
| Banner | `"Username:"` atau `"login:"` muncul ~1-3 detik setelah connect |
| Timeout login prompt | 8 detik (configurable via `configureLoginPrompts()`) |
| Timeout password prompt | 8 detik |
| Prompt user mode | `ZXAN>` (akhiran `>`) |
| Prompt enable | `ZXAN#` (akhiran `#`) |
| Prompt global config | `ZXAN(config)#` |
| Prompt interface | `ZXAN(config-if)#` (setelah `interface gpon-onu_…`) |
| Prompt pon-onu-mng | `ZXAN(gpon-onu-mng)#` (setelah `pon-onu-mng gpon-onu_…`) |

Implementasi shell ([`ZteCliSessionService::openShell`](../app/Services/ZteCliSessionService.php#L167-L188)):

- **Telnet**: pakai `fsockopen()` biasa, set `stream_set_blocking(false)` + `stream_set_write_buffer(0)` agar single-char keystroke (mis. space untuk pager) langsung terkirim
- **SSH**: prioritas ext-`ssh2`; fallback `phpseclib/phpseclib3` murni PHP (lebih lambat tapi tidak butuh extension)
- Sebelum jalankan command apa pun, kirim `terminal length 0` agar pager `--More--` mati dan output `show` tidak terpotong

**Pager handling**: kalau OLT tetap kirim `--More--` (firmware lama), driver pakai [`stripMorePrompts`](../app/Services/ZteCliSessionService.php#L458) untuk strip dari buffer dan kirim spasi otomatis lewat read loop.

---

## 3. Encoding ifIndex Composite ZTE (kritikal)

ZTE C300/C320 pakai **PON Type-1 composite ifIndex** untuk semua tabel ONU:

```
ifIndex = 0x10000000 | (slot << 16) | (port << 8)
```

Contoh:

| Slot | Port | Bit composition | ifIndex (hex) | ifIndex (dec) |
|---|---|---|---|---|
| 1 | 1 | `0x10000000 \| 0x00010000 \| 0x00000100` | `0x10010100` | `268501760` |
| 1 | 2 | `0x10000000 \| 0x00010000 \| 0x00000200` | `0x10010200` | `268501952` |
| 2 | 1 | `0x10000000 \| 0x00020000 \| 0x00000100` | `0x10020100` | `268567296` |
| 2 | 12 | `0x10000000 \| 0x00020000 \| 0x00000C00` | `0x10020C00` | `268569600` |

Decode terbalik (dari ifIndex ke slot/port):

```php
$slot = ($ifIndex >> 16) & 0xFF;
$port = ($ifIndex >> 8)  & 0xFF;
```

Tabel ONU pakai index `{ifIndex}.{onuId}`:

```
.1.3.6.1.4.1.3902.1012.3.28.1.1.1.268501760.1
                                  ^^^^^^^^^ ^
                                  ifIndex   onuId
```

**Penting**: di banyak firmware C320, ifDescr untuk port PON expose nama `gpon-olt_1/1/1` atau `gpon_olt-1/1/1`. Parse slot/port dari nama lebih reliable daripada bit-decode `ifIndex` murni (lihat [`getGponPorts`](../app/Services/ZteSnmpService.php#L495-L554)).

---

## 4. OID Reference (ZTE 3902.*)

### 4.1 System & Chassis

| Objek | OID | Akses | Catatan |
|---|---|---|---|
| `sysDescr` | `1.3.6.1.2.1.1.1.0` | RO | identitas |
| `sysUptime` | `1.3.6.1.2.1.1.3.0` | RO | uptime chassis |
| `sysContact` | `1.3.6.1.2.1.1.4.0` | RW standar | kontak |
| `sysName` | `1.3.6.1.2.1.1.5.0` | RW standar | nama node |
| `sysLocation` | `1.3.6.1.2.1.1.6.0` | RW standar | lokasi |
| `zxAnCardActType` | `1.3.6.1.4.1.3902.1015.2.1.1.3.1.4` | RO | nama board (DisplayString), index `rack.shelf.slot` |
| `zxAnCardOperStatus` | `1.3.6.1.4.1.3902.1015.2.1.1.3.1.5` | RO | status board, mapping di §4.2 |
| `entPhysicalClass` | `1.3.6.1.2.1.47.1.1.1.1.5` | RO | fallback Entity-MIB; `9` = module/line-card |
| `entPhysicalName` | `1.3.6.1.2.1.47.1.1.1.1.7` | RO | fallback nama board |

### 4.2 Board Status Enum (zxAnCardOperStatus)

Per ZTE C3xx MIB Specifications, ditemukan **C320 V2.x aktif memetakan `1` ke "normal/online"** padahal MIB lama menyebut `0=online`. Driver BMKV [`decodeBoardStatus`](../app/Services/ZteSnmpService.php#L465-L481) menerima keduanya:

| Code | Label dipakai | Sumber |
|---|---|---|
| 0 | Online | MIB asli (older firmware) |
| 1 | Online | observasi C320 V2.x live |
| 3 | Online | hw on-line |
| 4 | Offline | |
| 2 | Fail | |
| 5 | Configuring | |
| 6 | Config Failed | |
| 7 | Type Mismatch | |
| 8 | De-activated | |
| 9 | Fault | |
| 10 | Failure | |

### 4.3 Port GPON

| Objek | OID | Akses | Catatan |
|---|---|---|---|
| `ifDescr` | `1.3.6.1.2.1.2.2.1.2` | RO | filter regex `gpon.{0,2}olt` (cover `gpon-olt_…` dan `gpon_olt-…`) |
| `ifOperStatus` | `1.3.6.1.2.1.2.2.1.8` | RO | `1=up`, `2=down` |

Parsing slot/port dari `ifDescr` lebih dipercaya daripada bit-decode `ifIndex` karena firmware tertentu beda encoding. Pattern regex: `/(\d+)\/(\d+)\/(\d+)/i`.

### 4.4 Tabel ONU Modern (zxGponOntDevMgmtTable) — DIPAKAI UTAMA

Strategi 1: tabel modern, dipakai bila walk `.28.1.1.1` mengembalikan ≥1 row.

| Kolom | OID | Akses | Catatan |
|---|---|---|---|
| `zxGponOntDevMgmtTypeName` | `1.3.6.1.4.1.3902.1012.3.28.1.1.1` | RO | tipe ONU (mis. `F660V7.0`, `ALL-ONT`) |
| `zxGponOntDevMgmtName` | `1.3.6.1.4.1.3902.1012.3.28.1.1.2` | **RW** | nama tampilan ONU (write via SNMP) |
| `zxGponOntDevMgmtDescription` | `1.3.6.1.4.1.3902.1012.3.28.1.1.3` | **RW** | deskripsi ONU (write via SNMP) |
| `zxGponOntDevMgmtProvisionSn` | `1.3.6.1.4.1.3902.1012.3.28.1.1.5` | RO | SN provisioned (8 byte hex, format `ZTEG12345678`) |
| `zxGponOntDevMgmtAdminState` | `1.3.6.1.4.1.3902.1012.3.28.1.1.17` | **RW** | `1=active`, `2=disabled` |
| `zxGponOntPhaseState` | `1.3.6.1.4.1.3902.1012.3.28.2.1.4` | RO | state ranging; `3=working` = online |
| `zxGponOntLastDownCause` | `1.3.6.1.4.1.3902.1012.3.28.2.1.7` | RO | penyebab down terakhir |

Phase state enum ([`decodeOnuPhaseState`](../app/Services/ZteSnmpService.php#L1415-L1428)):

```
0 = Logging
1 = LOS
2 = Sync MIB
3 = Working    ← online
4 = DyingGasp
5 = Auth Failed
6 = Offline
```

Last-down-cause enum ([`decodeOnuLastDownCause`](../app/Services/ZteSnmpService.php#L1440-L1456)):

```
0 = Normal       5 = LOAi
1 = LOS          6 = LOAMi
2 = LOSi         7 = Deactivated
3 = LOFi         8 = Manual
4 = SFi          9 = DyingGasp
```

### 4.5 Tabel ONU Legacy / Fallback

Dipakai bila tabel modern empty (firmware C300 lama):

| Fungsi | OID | Catatan |
|---|---|---|
| Legacy ONU desc | `1.3.6.1.4.1.3902.1012.3.13.2.1.2` | base table |
| Legacy ONU SN | `1.3.6.1.4.1.3902.1012.3.13.2.1.3` | base table |
| Legacy oper status | `1.3.6.1.4.1.3902.1012.3.13.2.1.12` | base table; `1=online` |
| Auth table desc | `1.3.6.1.4.1.3902.1012.3.13.1.1.4` | secondary fallback |
| Auth table SN | `1.3.6.1.4.1.3902.1012.3.13.1.1.9` | secondary fallback |

Strategi fallback ([`getRegisteredOnus`](../app/Services/ZteSnmpService.php#L576-L610)):

```
Strategy 1: zxGponOntDevMgmtTable (modern)         ← preferred
Strategy 2a: ONU base table (.13.2.1)              ← fallback firmware lama
Strategy 2b: ONU auth table (.13.1.1)              ← fallback firmware lebih tua
Strategy 3: IF-MIB ifDescr filter "gpon-onu_…"     ← universal last resort
```

### 4.6 Optical Metrics ONU (per-port detail)

Subtree `.3902.1012.3.50.*` dipakai untuk halaman per-port (lebih lengkap, tapi cuma untuk satu PON port sekaligus):

| Objek | OID | Index | Konversi |
|---|---|---|---|
| ONU Rx downstream | `1.3.6.1.4.1.3902.1012.3.50.12.1.1.10` | `{ifIndex}.{onuId}.{port}` | lihat §4.7 |
| ONU Tx upstream | `1.3.6.1.4.1.3902.1012.3.50.12.1.1.14` | `{ifIndex}.{onuId}.{port}` | sama dgn Rx |
| ONU voltage | `1.3.6.1.4.1.3902.1012.3.50.12.1.1.17` | `{ifIndex}.{onuId}.{port}` | C320 aktif: `160 → 3.20 V` (`raw / 50`) atau `raw / 1000` bila ≥1000 |
| ONU temperature | `1.3.6.1.4.1.3902.1012.3.50.12.1.1.19` | `{ifIndex}.{onuId}.{port}` | C320 aktif: `11040 → 43.1 °C` (`raw / 256` bila ≥1000) |
| ONU distance | `1.3.6.1.4.1.3902.1012.3.11.4.1.2` | `{ifIndex}.{onuId}` | meter (langsung) |
| ONU vendor ID | `1.3.6.1.4.1.3902.1012.3.50.11.2.1.1` | `{ifIndex}.{onuId}.{port}` | contoh `ZTEG` |
| ONU software version | `1.3.6.1.4.1.3902.1012.3.50.11.2.1.2` | `{ifIndex}.{onuId}.{port}` | contoh `V7.0` |
| ONU equipment/model | `1.3.6.1.4.1.3902.1012.3.50.11.2.1.9` | `{ifIndex}.{onuId}.{port}` | contoh `F660V7.0` |
| ONU ETH admin | `1.3.6.1.4.1.3902.1012.3.50.14.1.1.5` | `{ifIndex}.{onuId}.{ethPort}` | `1=enable`, `2=disable` |
| ONU ETH oper | `1.3.6.1.4.1.3902.1012.3.50.14.1.1.6` | `{ifIndex}.{onuId}.{ethPort}` | `1=up`, `2=down` |
| ONU ETH speed | `1.3.6.1.4.1.3902.1012.3.50.14.1.1.7` | `{ifIndex}.{onuId}.{ethPort}` | `2=10M, 3=100M, 4=1G, 5=10G` |

Untuk **overview full-table** (semua port sekaligus) dipakai OPM table yang lebih murah:

| Objek | OID | Catatan |
|---|---|---|
| OLT Rx upstream | `1.3.6.1.4.1.3902.1015.1010.11.2.1.2` | milli-dBm, index `{ifIndex}.{onuId}` |
| ONU Tx upstream (OPM) | `1.3.6.1.4.1.3902.1015.1010.11.2.1.3` | duplikat kolom .2 di firmware tertentu |
| Sentinel (skip) | `1.3.6.1.4.1.3902.1015.1010.11.2.1.4` | selalu `-3000` (not-supported) |
| Sentinel (skip) | `1.3.6.1.4.1.3902.1015.1010.11.2.1.5` | selalu `-34000` (not-supported) |

### 4.7 Konversi Rx Power (multi-scale)

`ZteSnmpService::convertOnuRxPowerToDbm()` handle 3 scale yang muncul beda-beda di firmware C300/C320:

```
1. No-signal sentinel:
     raw <= -80000      → null
     raw >= 2147480000  → null  (INT max)
     raw >= 65000       → null
     raw == -32768      → null  (INT16 min sentinel)

2. Milli-dBm scale (typical OLT-side OPM):
     -50000 ≤ raw ≤ -3000  → raw / 1000  (mis. -28930 → -28.93 dBm)

3. 0.1-dBm scale (typical ONU-side per-port):
     -500 ≤ raw ≤ -5  → raw / 10  (mis. -280 → -28.0 dBm)

4. Legacy positive scale (fallback):
     raw > 0  → raw * 0.002 - 30  (mis. 5635 → -18.73 dBm)
```

**Penting**: scale dipilih berdasarkan magnitude raw, bukan flag firmware. Pendekatan ini terbukti benar pada C320 aktif PATI-ZTE-C320 (SNMP `5635 → -18.730 dBm` cocok dengan CLI `show pon power onu-rx → -18.762 dBm`).

### 4.8 ONU Serial Number (decoding)

ZTE SN selalu 8 byte hex. Format SNMP umum:

```
Raw STRING:    "ZTEG12345678"          → "ZTEG12345678"
Raw Hex-STRING: "5A 54 45 47 12 34 56 78"
                vendor=5A 54 45 47 (ASCII "ZTEG")
                serial=12 34 56 78  → "ZTEG12345678"
```

Decoder ([`decodeOnuSn`](../app/Services/ZteSnmpService.php#L1564-L1582)):

1. Strip prefix `STRING:` / `Hex-STRING:`
2. Bila format hex 8-byte space-separated:
   - 4 byte pertama → ASCII vendor (validate `^[A-Z]{4}$`)
   - 4 byte berikut → hex serial uppercase
   - join `vendor.serial`
3. Strip semua karakter non-alphanumeric, uppercase

### 4.9 ONU Unconfigured / Auto Discovery

| Objek | OID | Firmware target |
|---|---|---|
| `zxAnPonUncfgOnuSn` (older C320 V1.2.x) | `1.3.6.1.4.1.3902.1012.3.13.3.1.2` | confirmed working |
| v2.x SN table | `1.3.6.1.4.1.3902.1082.500.10.2.1.1` | firmware v2 |
| v2.x MAC table | `1.3.6.1.4.1.3902.1082.500.10.2.1.2` | firmware v2 |
| Alternate uncfg | `1.3.6.1.4.1.3902.1082.500.10.1.1.1` | varian lain |

Strategi cycle ([`getUnconfiguredOnus`](../app/Services/ZteSnmpService.php#L160-L267)):

1. Walk masing-masing OID berurutan, ambil yang **pertama** punya value `STRING:` atau `Hex-STRING:` (skip yang cuma INTEGER)
2. Decode SN (lihat §4.8)
3. Validasi panjang `8 ≤ len(SN) ≤ 16`
4. Decode `port_alias` dari ifIndex-ONU di OID: `gpon-onu_1/{slot}/{port}:{onuId}`
5. Deduplicate by SN

---

## 5. CLI Reference ZTE

### 5.1 Mode Navigation

```text
> [connect telnet 23 / ssh 22]
> "Username:" → kirim "operator\n"
> "Password:" → kirim "secret\n"
ZXAN>                              # user mode
ZXAN> enable                       # → ZXAN#
ZXAN# configure terminal           # → ZXAN(config)#  (alias: "conf t")
ZXAN(config)# interface gpon-olt_1/1/1
ZXAN(config-if)# exit              # → ZXAN(config)#
ZXAN(config)# interface gpon-onu_1/1/1:5
ZXAN(config-if)# exit              # → ZXAN(config)#
ZXAN(config)# pon-onu-mng gpon-onu_1/1/1:5
ZXAN(gpon-onu-mng)# exit           # → ZXAN(config)#
ZXAN(config)# end                  # → ZXAN#
ZXAN# exit                         # close session
```

**Best practice setiap connect baru**: kirim `terminal length 0` segera setelah login agar pager `--More--` mati di sesi tersebut.

### 5.2 Show Commands (read-only, sudah divalidasi)

| Kebutuhan | Command | Catatan output |
|---|---|---|
| System info | `show clock`, `show version`, `show system` | uptime + firmware |
| Daftar board/card | `show card` | nama + slot + status |
| Daftar GPON port | `show interface gpon-olt_1/{slot}/{port}` | up/down + counter |
| State semua ONU per port | `show gpon onu state gpon-olt_1/{slot}/{port}` | admin, OMCC, phase, jumlah ONU; dipakai BMKV untuk cari ONU ID kosong |
| Rx downstream per ONU | `show pon power onu-rx gpon-olt_1/{slot}/{port}` | format `gpon-onu_1/{s}/{p}:{id}    -13.820(dbm)` |
| Atenuasi up/down | `show pon power attenuation gpon-onu_1/{slot}/{port}:{onuId}` | tabel OLT/ONU TxRx + dB redaman |
| Detail per ONU | `show gpon onu detail-info gpon-onu_1/{slot}/{port}:{onuId}` | type, state, serial, distance, online duration, session history |
| Remote equip ONU | `show gpon remote-onu equip gpon-onu_1/{slot}/{port}:{onuId}` | vendor-id, equipment-id, software version |
| Config interface ONU | `show running-config interface gpon-onu_1/{slot}/{port}:{onuId}` | `name`, `tcont`, `gemport`, `service-port` |
| Config OMCI/management ONU | `show onu running config gpon-onu_1/{slot}/{port}:{onuId}` | `service`, `wan-ip`, `security-mgmt`, `tr069-mgmt` |
| Profile TCONT | `show gpon profile tcont` | source dropdown TCONT |
| Profile VLAN | `show gpon onu profile vlan` | source dropdown VLAN |
| Profile IP | `show gpon onu profile ip` | source dropdown WAN static |
| ONU type catalog | `show onu-type` | source dropdown ONU type |

### 5.3 Write Commands — Provisioning ONU Baru

Flow lengkap registrasi ONU baru ([`ZteCliProvisionService::buildProvisioningScript`](../app/Services/ZteCliProvisionService.php#L124-L251)):

```
conf t

interface gpon-olt_{slot}/{port}
  onu {onuId} type {ONU_TYPE} sn {SN}
  exit

interface gpon-onu_1/{slot}/{port}:{onuId}
  name {NAME}
  description {onuId}$${NAME}$$
  tcont 1 name 1 profile {TCONT_PROFILE}
  gemport 1 name 1 tcont 1
  encrypt 1 enable downstream
  service-port 1 vport 1 user-vlan {VLAN} vlan {VLAN}
  exit

pon-onu-mng gpon-onu_1/{slot}/{port}:{onuId}
  service {SERVICE_NAME} gemport 1 cos 0 vlan {VLAN}
  [tr069-mgmt 1 state unlock]
  [tr069-mgmt 1 acs {ACS_URL} validate basic username {ACS_USER} password {ACS_PASS}]
  wan-ip 1 mode {pppoe|dhcp|static} ... [vlan-profile {VLAN_PROFILE}] host 1
  [security-mgmt {ID} state enable mode {forward|...} protocol {web|...}]
  wan-ip 1 ping-response enable traceroute-response enable
  exit
```

Detail varian `wan-ip 1`:

| WAN mode | Command |
|---|---|
| PPPoE | `wan-ip 1 mode pppoe username {U} password {P}` |
| DHCP | `wan-ip 1 mode dhcp` |
| Static | `wan-ip 1 mode static ip-profile {IP_PROFILE} ip-address {IP} mask {255.255.255.0}` |

**Catatan implementasi**:

- `description` di-encode `{onuId}$${name}$$` agar BMKV bisa parse balik onuId dari config running
- PPPoE default: kalau `pppoe_username` kosong → di-normalize dari `name` (`[a-z0-9]+`, max 32 char)
- PPPoE default: kalau `pppoe_password` kosong → sama dengan username
- Mask static: input form bisa angka (mask length 1-32) atau dotted IP; converter [`subnetLengthToNetmask`](../app/Services/ZteCliProvisionService.php#L1246-L1252) ubah ke dotted untuk command CLI
- ACS default: di-set lewat `ACS_URL` / `ACS_USERNAME` / `ACS_PASSWORD` di `.env` (override per call). Kredensial asli tidak ditulis di repo.

### 5.4 Write Commands — Reconfigure ONU Existing

Untuk ONU yang sudah terdaftar dan butuh ubah config, dipakai **delta script** ([`ZteOnuReconfigureScriptBuilder::build`](../app/Services/ZteOnuReconfigureScriptBuilder.php)):

1. Baca live config dengan `getOnuRunningConfig` (CLI: 2 command show)
2. Bandingkan dengan form payload baru
3. Emit hanya baris yang **berubah** (name, tcont row baru, gemport row baru, dst.)
4. Untuk TR069: emit `state lock` bila dimatikan, `state unlock` + acs line bila dihidupkan
5. Untuk Remote ONT (security-mgmt): emit `state enable` atau `state disable` sesuai toggle
6. Untuk WAN-IP (multi-index): per `wan-ip {id}` emit ulang full `wan-ip {id} mode …` bila mode/credential/profile berubah, baris `wan-ip {id} ping-response {enable|disable} traceroute-response {enable|disable}` hanya bila toggle probe berubah, dan `no wan-ip {id}` bila WAN-IP dihapus

Output script delta selalu dibungkus:

```
conf t
[interface gpon-onu_1/{slot}/{port}:{onuId}]
  [name, description, tcont, gemport, service-port yang berubah]
  exit
[pon-onu-mng gpon-onu_1/{slot}/{port}:{onuId}]
  [service, vlan port, tr069-mgmt, wan-ip, wan N, security-mgmt yang berubah]
  exit
```

Bila tidak ada perubahan → return `''`, controller balas "Tidak ada perubahan config untuk di-apply".

### 5.5 Write Commands — Reboot ONU

Flow reboot ([`ZteCliSessionService::rebootOnu`](../app/Services/ZteCliSessionService.php#L508-L590)):

```
configure terminal
pon-onu-mng gpon-onu_1/{slot}/{port}:{onuId}
reboot
[bila prompt "yes/no" / "are you sure" → kirim "y"]
```

Driver scan output untuk pattern konfirmasi via [`looksLikeRebootConfirmation`](../app/Services/ZteCliSessionService.php#L668-L681):

```
yes/no | y/n | are you sure | confirm | continue | proceed
```

Bila match → auto-kirim `y\n`. Bila timeout tanpa output → return error "Tidak ada respon dari OLT".

### 5.6 Write Commands — Enable / Disable ONU

ZTE C300/C320 mendukung enable/disable ONU **via SNMP SET** ([`setOnuActiveState`](../app/Services/ZteSnmpService.php#L962-L968)) — lebih cepat daripada CLI:

```
SNMP SET .1.3.6.1.4.1.3902.1012.3.28.1.1.17.{ifIndex}.{onuId}
  Type: INTEGER (i)
  Value: 1 (active) | 2 (disabled)
```

Tidak ada equivalent CLI di driver BMKV; semua toggle pakai SNMP write community.

### 5.7 Write Commands — Edit Nama / Deskripsi ONU

Juga via SNMP SET (jauh lebih cepat dari CLI):

```
SNMP SET .1.3.6.1.4.1.3902.1012.3.28.1.1.2.{ifIndex}.{onuId}
  Type: STRING (s)
  Value: "Nama ONU"

SNMP SET .1.3.6.1.4.1.3902.1012.3.28.1.1.3.{ifIndex}.{onuId}
  Type: STRING (s)
  Value: "Deskripsi pelanggan"
```

### 5.8 Profile Management

ZTE C300/C320 punya 4 jenis "profile" yang dibutuhkan untuk provisioning ONU. Semua via CLI ([`buildProfileScript`](../app/Services/ZteCliProvisionService.php#L1113-L1231)):

#### ONU Type (di mode `pon`)

```
conf t
pon
onu-type gpon {NAME} [description "{DESC}"]
exit

# delete:
conf t
pon
no onu-type {NAME}
exit
```

Constraint: `{NAME}` di-uppercase otomatis, regex `^[A-Za-z0-9._-]+$`, max 120 char.

#### T-CONT Profile (di mode `gpon`)

```
conf t
gpon
profile tcont {NAME} type {1-5} maximum {64-9953280}
exit

# delete:
conf t
gpon
no profile tcont {NAME}
exit
```

Default values: `type=4` (best-effort), `maximum=1024000` kbps. Range hard-bounded di service.

#### VLAN Profile (di mode `gpon`)

```
conf t
gpon
onu profile vlan {NAME} tag-mode {tag|untag|translate} cvlan {1-4094} pri {0-7}
exit

# delete:
conf t
gpon
no onu profile vlan {NAME}
exit
```

CVLAN wajib diisi (validation error bila kosong).

#### IP Profile (di mode `gpon`)

```
conf t
gpon
onu profile ip {NAME} gateway {IP} [primary-dns {IP}] [secondary-dns {IP}]
exit

# delete:
conf t
gpon
no onu profile ip {NAME}
exit
```

Gateway wajib (validation error bila kosong).

### 5.9 Sync Profile dari OLT

BMKV punya flow sinkronisasi profile dari OLT ke DB lokal ([`syncProfilesFromOlt`](../app/Http/Controllers/SmartOltController.php#L2164-L2205)):

1. Jalankan 4 command show profile (lihat tabel di §5.2)
2. Parse output dengan [`extractProfiles`](../app/Services/ZteCliProvisionService.php#L1257-L1292) per profile type
3. Upsert ke tabel `smartolt_cli_profiles` (unique key: `snmp_olt_id + profile_type + profile_name`)

Parser pakai 3 strategi token extraction per line ([`extractTokensByType`](../app/Services/ZteCliProvisionService.php#L1323-L1365)):

- Pattern `profile name : VALUE` / `tcont profile : VALUE` / `vlan profile : VALUE`
- Pattern tabular `^\d+\s+VALUE\s+`
- Pattern standalone `^VALUE$`

Token di-filter dengan blacklist common keyword (`show`, `running`, `gpon`, `tcont`, `vlan`, `cos`, dst.) agar tidak masuk DB.

### 5.10 Cari ONU ID Kosong di Port

Untuk auto-assign `onu_id` saat provisioning baru ([`resolveNextAvailableOnuId`](../app/Http/Controllers/SmartOltController.php#L2093-L2116)):

```
1. CLI: show gpon onu state gpon-olt_1/{slot}/{port}
2. Parse output, ambil semua ONU ID yang dipakai (regex `(\d+\/\d+\/\d+):(\d+)`)
3. Loop 1..4096, return ID pertama yang tidak dipakai
```

### 5.11 Error Patterns

CLI menolak command dengan output yang mengandung salah satu pattern (case-insensitive, lihat [`findCliErrorLine`](../app/Services/ZteCliSessionService.php#L693-L730)):

```
invalid input
unknown command
ambiguous command
incomplete command
command rejected
permission denied
authorization failed
not support
operation failed
reboot failed
failure:
error:
%error
% bad
% invalid
\b(error|denied)\b   ← word-boundary catch-all
```

Pengecualian: line yang berakhir `(config-…)#` di-skip (itu prompt, bukan error).

---

## 6. Parsing Output `show gpon onu detail-info`

> **Status implementasi di repo ini:** Detail & Configure ONU sudah dibangun di KusumaVision NMS dengan pembagian kelas berikut (bukan satu `ZteCliSessionService` seperti blueprint asli):
> - Detail-info → [`ZteOnuDetailService`](../app/Services/ZteOnuDetailService.php) (`parse()`, `applyAttenuation()`, `applySessionHistory()`)
> - Running-config pre-fill → [`ZteOnuRunningConfigService`](../app/Services/ZteOnuRunningConfigService.php) (`parse()`, `normalizeLines()`)
> - Delta reconfigure → [`ZteOnuReconfigureScriptBuilder`](../app/Services/ZteOnuReconfigureScriptBuilder.php) (`build()`)
> - Halaman Inertia: `resources/js/Pages/SmartOlt/OnuDetail.vue` & `ConfigureOnu.vue`; tombol aksi di `PortOnus.vue` (gated capability `supports_cli_onu_detail` / `supports_cli_onu_configure`).
> - Route web (auth): `GET …/onus/{onuId}/detail`, `GET …/onus/{onuId}/configure`, `POST …/configure/preview`, `POST …/configure`.

Output bervariasi antar firmware C300/C320. Parser [`ZteOnuDetailService::parse`](../app/Services/ZteOnuDetailService.php) pakai pendekatan dual-pass:

### 6.1 Pass 1 — Build "all" map

Semua line yang ada `:` di-split jadi `Key: Value`, normalize key ke `snake_case` lower:

```
"Online Duration(s) :  3600"
→ key=online_duration_s, value=3600

"Vendor-Id  : ZTEG"
→ key=vendor_id, value=ZTEG
```

Skip line yang:
- key kosong setelah normalize
- key mengandung `show gpon` (echo command)
- key match `^(zxan|olt)[#>]` (prompt)

### 6.2 Pass 2 — Bucket grouping

Mapping ke 5 grup ([`parseOnuDetailInfo` lanjutan](../app/Services/ZteCliSessionService.php#L1112-L1226)):

| Grup | Field | Fallback substring match |
|---|---|---|
| `identity` | `sn`, `name`, `type`, `auth_mode`, `vendor_id`, `equipment_id`, `model_id`, `hardware_version`, `software_version` | `serial`, `vendor`, `equipment`, `model`, `hw version`, `sw version` |
| `state` | `state`, `admin_state`, `phase_state`, `channel`, `online_duration` | `ranging state`, `oper state`, `online time`, `uptime` |
| `optical` | `rx_power_dbm`, `tx_power_dbm`, `distance_m`, `temperature_c`, `voltage_v`, `bias_current_ma` | `rx power`, `tx opt`, `temperature`, `voltage` |
| `last_event` | `last_down_cause`, `last_down_time`, `last_up_time` | `offline cause`, `offline time`, `authpass` |
| `all` | semua key:value (verbatim) | — |

Strategi `pickAny()` selalu coba **exact key match** dulu (cepat), baru fallback ke **substring match** semua needle harus ada di key (toleran beda spelling antar firmware).

### 6.3 Pass 3 — Session history fallback

Beberapa firmware ZTE append tabel session history di akhir `detail-info`:

```
idx  Authpass Time          OfflineTime           Cause
1    2026-05-20 10:23:45    2026-05-22 03:11:00   LOS
2    2026-05-22 04:15:30    0000-00-00 00:00:00   -
```

Parser [`ZteOnuDetailService::applySessionHistory`](../app/Services/ZteOnuDetailService.php) detect:
- Row dengan `OfflineTime` mulai `0000-` = sesi current (ONU masih up)
- Row sebelumnya = last down event

Bila grup `last_event` kosong dari pass 2 → diisi dari hasil session history.

### 6.4 Suplemen `show pon power attenuation`

Driver juga jalankan parallel command `show pon power attenuation` dan parse tabel:

```
            OLT                  ONU              Attenuation
 -------------------------------------------------------------
 up      Rx :-20.850(dbm)      Tx:2.868(dbm)        23.718(dB)
 down    Tx :4.230(dbm)        Rx:-18.210(dbm)      22.440(dB)
```

→ `onu_rx_dbm = -18.21`, `onu_tx_dbm = 2.868`, `att_up_db = 23.718`, `att_down_db = 22.44`.

Suplemen ini dipakai untuk **mengisi optical Rx/Tx kalau `detail-info` tidak mencantumkannya** (firmware C320 V2.x sering begitu).

---

## 7. Parsing Running Config Pre-fill Form Configure

Halaman Configure ONU pre-fill form dengan baca live running-config ([`ZteOnuRunningConfigService::parse`](../app/Services/ZteOnuRunningConfigService.php)):

### 7.1 Command yang dijalankan

```
show running-config interface gpon-onu_1/{slot}/{port}:{onuId}
show onu running config gpon-onu_1/{slot}/{port}:{onuId}
```

Bila firmware tidak punya command kedua, output `%Error` di-skip silent.

### 7.2 Pre-processing line

[`ZteOnuRunningConfigService::normalizeLines`](../app/Services/ZteOnuRunningConfigService.php) handle line-wrap ZTE:

- Buang line noise (`!`, `end`, `Building configuration`, header `interface gpon-onu_…`)
- Bila line bukan command awal (mis. continuation token dari line sebelumnya), append ke line terakhir
- Repair token wrap khas ZTE: `vlan-profi le PROFILE` → `vlan-profile PROFILE`, sama dgn `ip-profi le` dan `mask- length`

### 7.3 Pattern command yang di-recognize

| Pattern | Captured field |
|---|---|
| `^name (.+)` | `name` |
| `^description (.+)` | `description` (encoded `id$$name$$`) |
| `^tcont N name X profile Y` | `tconts[]`, `tcont_profile` |
| `^tcont N gap MODE` | `tconts[].gap_mode` |
| `^gemport N name X tcont T` | `gemports[]` |
| `^gemport N traffic-limit upstream X downstream Y` | `gemports[].traffic_*` |
| `^service-port N vport V user-vlan UV vlan V` | `service_ports[]`, `vlan` |
| `^service NAME type T gemport G cos C vlan V` | `services[]`, `service_name`, `vlan` |
| `^vlan port PORT mode MODE ...` | `vlan_ports[]` (eth_0/N atau wifi_0/N) |
| `^wan-ip N mode {pppoe\|dhcp\|static} ...` | `wan_mode`, `pppoe_*`, `static_*`, `ip_profile`, `vlan_profile` |
| `^wan N service {internet\|tr069\|voip\|other}+ [mvlan M] [ethuni X] [ssid Y] [host H]` | `wan_services[]` (token opsional & longgar urutannya, mis. `wan 2 service other mvlan 1001`; `services[]` multi-tipe, `mvlan` khusus `other`) |
| `^tr069-mgmt N state {unlock\|lock}` | `tr069` (bool) |
| `^tr069-mgmt N acs URL validate basic username U password P` | `acs_url`, `acs_username`, `acs_password` |
| `^security-mgmt ID state {enable\|disable} mode MODE protocol PROTO` | `remote_ont`, `remote_ont_id/mode/protocol` |

### 7.4 Mask conversion

Field `static_mask_length` dan `static_netmask` saling konversi:

- Input dotted `255.255.255.0` → bit count `24` (validasi: harus contiguous `1` lalu `0`, tidak boleh `255.0.255.0`)
- Input length `24` → dotted `255.255.255.0`

---

## 8. Data Model Lokal

### 8.1 Tabel `snmp_olts`

OLT dasar (lihat [`SnmpOlt` model](../app/Models/SnmpOlt.php)):

| Kolom | Tipe | Catatan |
|---|---|---|
| `id` | bigInt PK | |
| `name` | string(100) | label friendly |
| `vendor` | string(100) | contoh `ZTE C320`, `ZTE C300` — dipakai untuk detection family |
| `ip` | ipv4 unique | |
| `snmp_port` | int | default 161 |
| `snmp_read_community` | string encrypted | enkripsi at-rest via `EncryptsSensitiveAttributes` trait |
| `snmp_write_community` | string encrypted | |
| `snmp_version` | enum(`v1`,`v2c`,`v3`) | default `v2c` |
| `cli_transport` | enum(`telnet`,`ssh`) nullable | wajib bila mau provisioning/reboot |
| `cli_username` | string(100) | |
| `cli_password` | string encrypted | |
| `cli_port` | int nullable | default 23 telnet / 22 ssh |

Method penting:
- `getHostAddress()` → `"IP"` atau `"IP:PORT"` untuk SNMP call
- `cliProfiles()` → relasi `hasMany` ke `smartolt_cli_profiles`
- `onuRegistrations()` → relasi `hasMany` ke `smartolt_onu_registrations`

### 8.2 Tabel `smartolt_cli_profiles`

Cache profile per OLT ([migration](../database/migrations/2026_02_24_101000_create_smartolt_cli_profiles_table.php)):

```sql
CREATE TABLE smartolt_cli_profiles (
  id              BIGINT PK,
  snmp_olt_id     BIGINT INDEX,
  profile_type    VARCHAR(32),     -- onu_type|tcont_profile|vlan_profile|ip_profile
  profile_name    VARCHAR(120),
  source          VARCHAR(120) DEFAULT 'cli_import',  -- cli_import|manage_form|register_form|<cmd>
  params          JSON NULL,        -- {tcont:{type,maximum}}, {vlan:{cvlan,pri,tag_mode}}, {ip:{gateway,...}}
  created_at, updated_at,
  UNIQUE (snmp_olt_id, profile_type, profile_name)
);
```

Dipakai sebagai source dropdown form Register/Configure ONU.

### 8.3 Tabel `smartolt_onu_registrations`

Audit trail provisioning + reconfigure ([migration](../database/migrations/2026_02_24_101500_create_smartolt_onu_registrations_table.php)):

```sql
CREATE TABLE smartolt_onu_registrations (
  id              BIGINT PK,
  snmp_olt_id     BIGINT,
  serial_number   VARCHAR(64),
  pon_port        VARCHAR(120),  -- "gpon-onu_1/1/1:5"
  oid_index       VARCHAR(191) NULL,
  onu_id          INT UNSIGNED,
  customer_name   VARCHAR(191),
  onu_type        VARCHAR(120),
  tcont_profile   VARCHAR(120),
  vlan            SMALLINT UNSIGNED,
  vlan_profile    VARCHAR(120) NULL,
  service_name    VARCHAR(120) DEFAULT 'ServiceName',
  pppoe_username  VARCHAR(120) NULL,
  pppoe_password  VARCHAR(120) NULL,   -- ⚠ stored plaintext, batasi akses
  cli_script      LONGTEXT,             -- full script yang dijalankan / disimpan
  status          VARCHAR(32) DEFAULT 'generated',
                  -- generated | executed | failed | reconfigured | reconfig_failed
  created_by      VARCHAR(100) NULL,
  created_at, updated_at,
  INDEX (snmp_olt_id, status),
  INDEX (serial_number, created_at)
);
```

Setiap call `storeOnu` (provisioning) dan `configureOnuApply` (reconfigure) **selalu** insert row baru — sukses maupun gagal. Status `failed` / `reconfig_failed` tetap simpan `cli_script` untuk debugging.

---

## 9. HTTP Routes & Controllers

### 9.1 Web Routes (untuk dashboard internal)

Dari `routes/web.php` — semua di middleware `role.permission` + `viewer.workspace` + `role:<list>`:

#### Read (semua role dengan akses SmartOLT)

| Method | URI | Controller method | View |
|---|---|---|---|
| GET | `/smartolt` | `index` | `smartolt.index` |
| GET | `/smartolt/{id}/detail` | `detail` | `smartolt.detail` |
| GET | `/smartolt/{id}/ports/{slot}/{port}/onus` | `portOnus` | `smartolt.port_onus` |
| GET | `/smartolt/{id}/unconfigured` | `unconfiguredOnus` | `smartolt.unconfigured` |
| GET | `/smartolt/{id}/registrations` | `registrationHistory` | `smartolt.history` |
| GET | `/smartolt/{id}/api/chassis` | `apiChassis` | JSON |
| GET | `/smartolt/{id}/api/gpon-onus` | `apiGponOnus` | JSON |
| GET | `/smartolt/{id}/api/ports/{slot}/{port}/onus` | `apiPortOnus` | JSON |
| GET | `/smartolt/{id}/api/cli/onu-rx/{slot}/{port}` | `apiCliOnuRxPower` | JSON |
| GET | `/smartolt/{id}/api/cli/onu-detail/{slot}/{port}/{onuId}` | `apiOnuDetailInfo` | JSON |
| GET | `/smartolt/{id}/api/cli/onu-current-config/{slot}/{port}/{onuId}` | `apiOnuCurrentConfig` | JSON |

#### Write (role `administrator,admin,noc,operator,partner-teknisi`)

| Method | URI | Method | Catatan |
|---|---|---|---|
| POST | `/smartolt` | `store` | tambah OLT baru |
| PUT | `/smartolt/{id}` | `update` | edit OLT |
| DELETE | `/smartolt/{id}` | `destroy` | hapus OLT (cascade `cli_profiles`+`onu_registrations`) |
| POST | `/smartolt/{id}/test` | `testConnection` | ping SNMP `sysDescr.0` |
| POST | `/smartolt/{id}/api/onu/{slot}/{port}/{onuId}/info` | `apiSetOnuInfo` | set name + description (SNMP) |
| POST | `/smartolt/{id}/api/onu/{slot}/{port}/{onuId}/reboot` | `apiRebootOnu` | reboot via CLI |
| POST | `/smartolt/{id}/api/onu/{slot}/{port}/{onuId}/state` | `apiSetOnuState` | enable/disable (SNMP) |
| GET | `/smartolt/{id}/cli-profiles` | `cliProfilesIndex` | view profile catalog |
| POST | `/smartolt/{id}/cli-profiles` | `cliProfilesStore` | tambah profile + optional `execute_cli` |
| PUT | `/smartolt/{id}/cli-profiles/{profile}` | `cliProfilesUpdate` | edit; bila nama berubah & execute → `no <old>` lalu add |
| DELETE | `/smartolt/{id}/cli-profiles/{profile}` | `cliProfilesDestroy` | hapus + optional `no <name>` |
| POST | `/smartolt/{id}/cli-profiles/sync` | `syncCliProfiles` | paste output CLI manual, parse |
| POST | `/smartolt/{id}/cli-profiles/sync-from-olt` | `syncCliProfilesFromOlt` | auto-sync via CLI |
| GET | `/smartolt/{id}/register` | `registerOnuForm` | form provisioning ONU |
| POST | `/smartolt/{id}/register` | `storeOnu` | provisioning ONU baru (generate + optional execute) |
| GET | `/smartolt/{id}/onu/{slot}/{port}/{onuId}/configure` | `configureOnuForm` | form reconfigure ONU |
| POST | `/smartolt/{id}/onu/{slot}/{port}/{onuId}/configure/preview` | `configureOnuPreview` | preview delta script |
| POST | `/smartolt/{id}/onu/{slot}/{port}/{onuId}/configure` | `configureOnuApply` | apply (butuh `confirm_onu_id`) |

> **Catatan repo ini:** Detail & Configure ONU di KusumaVision NMS dipasang sebagai halaman web (Inertia), bukan endpoint JSON terpisah, dengan path & nama route berikut:
> - `GET  …/ports/{slot}/{port}/onus/{onuId}/detail` → `onuDetail` → `smartolt.onu.detail` (render `OnuDetail.vue`)
> - `GET  …/ports/{slot}/{port}/onus/{onuId}/configure` → `configureOnuForm` → `smartolt.onu.configure` (render `ConfigureOnu.vue`)
> - `POST …/ports/{slot}/{port}/onus/{onuId}/configure/preview` → `configureOnuPreview` → `smartolt.onu.configure.preview` (JSON delta-live)
> - `POST …/ports/{slot}/{port}/onus/{onuId}/configure` → `configureOnuApply` → `smartolt.onu.configure.apply` (eksekusi delta + audit `reconfigured`)

#### Remote console (role `administrator,admin,noc`)

| Method | URI | Method | Catatan |
|---|---|---|---|
| POST | `/smartolt/{id}/remote/token` | `remoteToken` | terbitkan token WS terminal untuk SSH/Telnet langsung ke OLT |

### 9.2 API Routes (untuk mobile / programmatic)

Dari `routes/api.php` — middleware `auth:sanctum`:

| Method | URI | Controller |
|---|---|---|
| GET | `/api/smartolt/olts` | `SmartOltApiController@index` |
| GET | `/api/smartolt/{id}/chassis` | `chassis` |
| GET | `/api/smartolt/{id}/gpon-onus` | `gponOnus` |
| GET | `/api/smartolt/{id}/capabilities` | `capabilities` |
| GET | `/api/smartolt/{id}/unconfigured` | `unconfigured` |
| GET | `/api/smartolt/{id}/registrations` | `registrations` |
| GET | `/api/smartolt/{id}/cli/onu-rx/{slot}/{port}` | `cliOnuRx` |
| POST | `/api/smartolt/{id}/onu/{slot}/{port}/{onuId}/info` | `setOnuInfo` |
| POST | `/api/smartolt/{id}/onu/{slot}/{port}/{onuId}/reboot` | `rebootOnu` |
| POST | `/api/smartolt/{id}/onu/{slot}/{port}/{onuId}/state` | `setOnuState` |
| GET | `/api/smartolt/{id}/profiles` | `profiles` |
| GET | `/api/smartolt/{id}/suggest-onu-id` | `suggestOnuId` |
| POST | `/api/smartolt/{id}/register/preview` | `registerPreview` |
| POST | `/api/smartolt/{id}/register` | `registerStore` |

---

## 10. Flow Lengkap (User Journey)

### 10.1 Daftar OLT → Detail → Per-Port → Detail ONU

```
1. /smartolt
   - list OLT (scoped per user assignment + workspace demo flag)
   - stat: total ONU (dari kolom cache snmp_olts.onu_total_cached)
   - vendor badge + capability badge (Provisioning, Detail SNMP ONU, dst.)

2. Click OLT name → /smartolt/{id}/detail
   - Halaman skeleton; data via fetch async:
     • GET /api/chassis  → system + boards
     • GET /api/gpon-onus → ports + per-port ONU summary
   - Render chassis card + grid PON port (status Up/Down + count online/total ONU)

3. Click PON port → /smartolt/{id}/ports/{slot}/{port}/onus
   - Halaman skeleton + async GET /api/ports/{slot}/{port}/onus
   - Render tabel ONU dengan kolom:
     ONU ID | SN | Type | Phase state | Admin state | Rx (dBm) | Tx | Voltage | Temp | Distance (m)
     Model | SW Version | Vendor | Last down cause | LAN ports
   - Per-row action: Edit Info, Reboot, Enable/Disable, Detail (CLI), Configure (CLI)

4. Click "Detail (CLI)" → modal open
   - POST GET /api/cli/onu-detail/{slot}/{port}/{onuId}
   - Render grouped sections: Identity, State, Optical, Last Event, Raw CLI (collapsible)
```

### 10.2 Provisioning ONU Baru

```
1. /smartolt/{id}/unconfigured
   - List ONU SN yang belum dikonfigurasi (SNMP walk 4 OID kandidat)
   - Setiap row punya tombol "Register" → /smartolt/{id}/register?sn=...&port=...&oid=...

2. /smartolt/{id}/register?sn=...&port=...
   Backend (registerOnuForm):
   a. Parse port_alias → ambil path "{s}/{p}/{port}"
   b. Bila CLI tersedia, jalankan "show gpon onu state gpon-olt_{path}" untuk cari ONU ID kosong
   c. Bila DB cli_profiles kosong, auto-sync 4 command profile dari OLT
   d. Load dropdown ONU Type / TCONT / VLAN Profile / IP Profile dari DB

   UI:
   - Form field: Name, ONU Type (dropdown), T-CONT (dropdown), VLAN, VLAN Profile (dropdown)
   - WAN mode radio: PPPoE | DHCP | Static
     • PPPoE: username + password (auto-fill dari name kalau kosong)
     • DHCP: tidak ada field tambahan
     • Static: IP Profile (dropdown), IPv4 Address, IP Subnet Mask Length
   - TR069 toggle + ACS URL/user/pass
   - Remote ONT toggle + security-mgmt id/mode/protocol
   - Checkbox "Execute ke CLI sekarang"
   - Button "Submit"

3. POST /smartolt/{id}/register (storeOnu)
   a. Validate full payload (sn, port, onu_id, name, wan_mode, ...)
   b. Build script via ZteCliProvisionService::buildProvisioningScript
   c. Bila execute_cli=true → ZteCliSessionService::runScript
      • status=executed bila sukses, status=failed bila error
   d. Insert row smartolt_onu_registrations (script + status + created_by)
   e. Upsert profile yg dipakai (ONU type / tcont / vlan / ip) ke cli_profiles
   f. Redirect ke /smartolt dengan flash success/error
```

### 10.3 Reconfigure ONU Existing

```
1. /smartolt/{id}/ports/{slot}/{port}/onus
   - Row ONU yg sudah terdaftar punya tombol "Configure (CLI)"
   - Click → /smartolt/{id}/onu/{slot}/{port}/{onuId}/configure

2. Backend (configureOnuForm):
   a. resolveOnuPrefill():
      1) Coba CLI live: getOnuRunningConfig() → parse → fields
      2) Bila SN kosong, coba SNMP getRegisteredOnusByPort → ambil SN ONU
      3) Fallback DB: latest row smartolt_onu_registrations untuk port+onuId
      4) Fallback empty default

   UI:
   - Sama dengan Register form, plus multi-row blok:
     • TCONT (id, name, profile, gap_mode)
     • Gemport (id, name, tcont, traffic-limit upstream/downstream)
     • Service Port (id, vport, user_vlan, vlan)
     • Service (name, type, gemport, cos, vlan)
     • UNI VLAN (Port Type {Ethernet|WiFi} + Port {1-4} → token eth_0/N atau wifi_0/N)
     • WAN service (id, services[] {internet/tr069/voip/other}, mvlan, ethuni, ssid, host)
   - Live preview button → POST /configure/preview
   - Apply button → POST /configure (butuh ketik ulang onu_id di field confirm)

3. POST /configure/preview (configureOnuPreview):
   - Validate
   - Baca live running-config via CLI
   - buildReconfigureDeltaScript(payload, current)
   - Return JSON: { script: "...", changes_count: N }

4. POST /configure (configureOnuApply):
   - Validate
   - Cek confirm_onu_id == onuId (safety)
   - Baca live running-config sekali lagi (re-fetch to avoid race)
   - Build delta script
   - Bila script empty → 422 "Tidak ada perubahan"
   - Jalankan runScript() di OLT
   - Insert row smartolt_onu_registrations dengan status reconfigured / reconfig_failed
   - Return JSON { ok, script, output, error }
```

### 10.4 Reboot ONU

```
1. /smartolt/{id}/ports/{slot}/{port}/onus → row "Reboot"
2. Frontend: gacsConfirm("Reboot ONU?") → POST /api/onu/{slot}/{port}/{onuId}/reboot
3. Backend (apiRebootOnu):
   - Cek capability: supports_reboot=true, reboot_mode='cli' (untuk ZTE)
   - new ZteCliSessionService → rebootOnu()
   - Return { ok, error }
4. ONU restart 30-60 detik; tabel akan refresh status pada next poll
```

### 10.5 Enable/Disable ONU

```
1. /smartolt/{id}/ports/{slot}/{port}/onus → row "Disable"/"Enable"
2. POST /api/onu/{slot}/{port}/{onuId}/state {active: false|true}
3. Backend (apiSetOnuState):
   - Cek capability: supports_onu_toggle=true (ZTE: true)
   - SNMP SET .28.1.1.17.{ifIndex}.{onuId} → 1 (active) atau 2 (disabled)
4. Return { ok, data: { active, admin_state, status_label } }
```

### 10.6 Manage Profile CLI

```
1. /smartolt/{id}/cli-profiles
   - 4 card tabel: ONU Type | T-CONT | VLAN Profile | IP Profile
   - Toolbar: "Tambah Profile", "Sinkron dari OLT"

2. Tambah Profile → modal
   - Pilih profile_type
   - Isi nama + parameter spesifik tipe
   - Checkbox "Eksekusi ke CLI"
   - Submit → POST /cli-profiles
     a. Validate
     b. Build script: buildProfileScript('add', type, name, params)
     c. Bila execute_cli=true → runScript()
     d. UpsertOrCreate DB row

3. Edit Profile → modal sama tapi pre-filled
   - PUT /cli-profiles/{id}
   - Bila nama berubah dan execute → emit "no <old>" dulu, baru add baru

4. Hapus Profile → modal confirm
   - DELETE /cli-profiles/{id}
   - Bila execute → emit "no <name>"

5. Sinkron dari OLT → tombol toolbar
   - POST /cli-profiles/sync-from-olt
   - Jalankan 4 command show profile sekaligus
   - Parse output → upsert ke DB
   - Redirect dengan flash summary
```

---

## 11. Capabilities & Driver Matrix

Dari [`SmartOltSupport::capabilities`](../app/Support/SmartOltSupport.php#L44-L152), capability ZTE C300/C320 (default driver):

```json
{
    "driver": "zte",
    "vendor_family": "ZTE GPON",
    "pon_label": "GPON",
    "port_label": "GPON Port",
    "port_and_onu_label": "GPON Port & ONU",
    "port_name_prefix": "gpon-olt_1",
    "onu_interface_pattern": "gpon-onu_1/%d/%d:%d",

    "supports_cli_rx": true,
    "supports_cli_onu_detail": true,
    "supports_cli_onu_configure": true,

    "supports_reboot": true,
    "reboot_mode": "cli",

    "supports_provisioning": true,

    "supports_separate_description": true,
    "supports_onu_info_write": true,
    "description_mode": "snmp",

    "supports_onu_toggle": true,

    "onu_name_label": "Nama",
    "onu_description_label": "Deskripsi",
    "edit_name_label": "Nama ONU",
    "edit_description_label": "Deskripsi",
    "edit_name_placeholder": "Nama pelanggan...",
    "edit_description_placeholder": "Deskripsi / alamat...",
    "rx_source_label": "Rx ONU"
}
```

**Bandingkan dengan vendor lain**: ZTE adalah satu-satunya driver dengan `supports_provisioning=true`, `supports_cli_onu_detail=true`, dan `supports_cli_onu_configure=true`. Itulah kenapa fitur Register / Configure / Detail CLI hanya muncul di tombol UI untuk OLT ZTE.

---

## 12. Implementation Pattern Standalone

Untuk replicate ke project lain (tanpa Laravel), berikut template minimal.

### 12.1 SNMP Walk dengan Multi-Strategy Fallback

```php
<?php

class OltSnmpClient
{
    private string $host;
    private string $community;
    private int $timeoutUs = 3_000_000;  // 3s
    private int $retries = 2;

    public function __construct(string $host, string $community)
    {
        $this->host = $host;
        $this->community = $community;
    }

    public function walk(string $oid): array
    {
        // Prefer ext-SNMP (fast batch)
        if (class_exists(\SNMP::class)) {
            $session = new \SNMP(\SNMP::VERSION_2c, $this->host, $this->community, $this->timeoutUs, $this->retries);
            $session->valueretrieval = SNMP_VALUE_LIBRARY;
            $session->oid_output_format = SNMP_OID_OUTPUT_NUMERIC;
            $session->oid_increasing_check = false;
            $session->max_oids = 10;

            try {
                $result = @$session->walk($oid);
                if (is_array($result)) return $result;
            } finally {
                $session->close();
            }
        }

        // Fallback procedural
        return @snmp2_real_walk($this->host, $this->community, $oid, $this->timeoutUs, $this->retries) ?: [];
    }

    public function set(string $writeCommunity, string $oid, string $type, string $value): bool
    {
        return @snmp2_set($this->host, $writeCommunity, $oid, $type, $value, $this->timeoutUs, $this->retries) !== false;
    }
}
```

### 12.2 Encoding/Decoding ifIndex ZTE

```php
function zteEncodeIfIndex(int $slot, int $port): int
{
    return 0x10000000 | ($slot << 16) | ($port << 8);
}

function zteDecodeIfIndex(int $ifIndex): array
{
    return [
        'slot' => ($ifIndex >> 16) & 0xFF,
        'port' => ($ifIndex >> 8) & 0xFF,
    ];
}
```

### 12.3 SN Decoder

```php
function decodeOnuSn(string $raw): string
{
    $raw = trim(preg_replace('/^[A-Za-z0-9\-]+:\s+/', '', $raw));  // strip "STRING: "
    $raw = trim($raw, '"');

    // Hex-STRING 8-byte
    if (preg_match('/^([0-9A-Fa-f]{2}\s+){3}[0-9A-Fa-f]{2}/', $raw)) {
        $bytes = explode(' ', $raw);
        if (count($bytes) === 8) {
            $vendor = chr(hexdec($bytes[0])).chr(hexdec($bytes[1])).chr(hexdec($bytes[2])).chr(hexdec($bytes[3]));
            $serial = strtoupper($bytes[4].$bytes[5].$bytes[6].$bytes[7]);
            if (preg_match('/^[A-Z]{4}$/', $vendor)) {
                return $vendor.$serial;
            }
        }
        $raw = strtoupper(str_replace(' ', '', $raw));
    }

    return strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $raw));
}
```

### 12.4 Telnet CLI Session

```php
function openZteTelnet(string $host, int $port, string $user, string $pass)
{
    $stream = @fsockopen($host, $port, $errno, $errstr, 8);
    if (! is_resource($stream)) throw new RuntimeException("Telnet fail: $errstr");

    stream_set_timeout($stream, 10);
    stream_set_blocking($stream, false);
    stream_set_write_buffer($stream, 0);

    // Wait for "Username:" / "login:"
    readUntilAny($stream, ['login:', 'username:'], 8);
    fwrite($stream, "$user\n");

    readUntilAny($stream, ['password:'], 8);
    fwrite($stream, "$pass\n");

    // Drain to prompt
    readUntilPrompt($stream, 10);

    // Disable pager
    fwrite($stream, "terminal length 0\n");
    readUntilPrompt($stream, 10);

    return $stream;
}

function readUntilAny($stream, array $needles, int $timeoutSec): string
{
    $buffer = '';
    $deadline = microtime(true) + $timeoutSec;
    while (microtime(true) < $deadline) {
        $chunk = stream_get_contents($stream);
        if (is_string($chunk) && $chunk !== '') {
            $buffer .= stripTelnetIAC($chunk);  // strip 0xFF IAC negotiation
            $lower = strtolower($buffer);
            foreach ($needles as $n) {
                if (str_contains($lower, strtolower($n))) return $buffer;
            }
        }
        if (feof($stream)) return $buffer;
        usleep(120000);
    }
    return $buffer;
}

function readUntilPrompt($stream, int $timeoutSec): string
{
    $buffer = '';
    $deadline = microtime(true) + $timeoutSec;
    while (microtime(true) < $deadline) {
        $chunk = stream_get_contents($stream);
        if (is_string($chunk) && $chunk !== '') {
            $buffer .= stripTelnetIAC($chunk);
            // Match ZTE prompts ending in > or # (with optional (config)/(config-if)/(gpon-onu-mng))
            if (preg_match('/[\r\n][^\r\n]{0,120}[>#]\s?$/', $buffer)) return $buffer;
        }
        if (feof($stream)) return $buffer;
        usleep(120000);
    }
    return $buffer;
}

function stripTelnetIAC(string $value): string
{
    // Telnet IAC = 0xFF; commands like FF FB 01, FF FE 03, etc.
    return preg_replace('/\xff[\xfb-\xfe]./', '', $value) ?? $value;
}
```

### 12.5 Reboot ONU Skeleton

```php
function rebootZteOnu($stream, int $slot, int $port, int $onuId): bool
{
    foreach ([
        'configure terminal',
        "pon-onu-mng gpon-onu_1/{$slot}/{$port}:{$onuId}",
        'reboot',
    ] as $cmd) {
        fwrite($stream, "$cmd\n");
        $out = readUntilPromptOrConfirm($stream, 12);
        if (containsError($out)) return false;

        if (preg_match('/(yes\/no|y\/n|are you sure|confirm|continue|proceed)/i', $out)) {
            fwrite($stream, "y\n");
            readUntilPrompt($stream, 12);
            break;
        }
    }
    return true;
}

function containsError(string $output): bool
{
    return preg_match('/invalid input|unknown command|ambiguous command|incomplete command|command rejected|permission denied|operation failed|% ?error|\berror\b/i', $output) === 1;
}
```

### 12.6 Provisioning Script Generator (template)

```php
function buildZteProvisioningScript(array $d): string
{
    $slot = (int) $d['slot'];
    $port = (int) $d['port'];
    $onuId = (int) $d['onu_id'];
    $sn = strtoupper($d['sn']);
    $name = $d['name'];
    $type = $d['onu_type'] ?? 'ALL-ONT';
    $tcontProfile = $d['tcont_profile'] ?? 'SERVER';
    $vlan = (int) ($d['vlan'] ?? 1);
    $vlanProfile = $d['vlan_profile'] ?? '';
    $serviceName = $d['service_name'] ?? 'ServiceName';
    $wanMode = $d['wan_mode'] ?? 'pppoe';

    $description = $onuId.'$$'.$name.'$$';

    // WAN line
    if ($wanMode === 'dhcp') {
        $wanLine = 'wan-ip 1 mode dhcp';
    } elseif ($wanMode === 'static') {
        $wanLine = sprintf(
            'wan-ip 1 mode static ip-profile %s ip-address %s mask %s',
            $d['ip_profile'], $d['static_ip'], $d['static_netmask']
        );
    } else {
        $u = $d['pppoe_username'] ?: strtolower(preg_replace('/[^a-z0-9]/i', '', $name));
        $p = $d['pppoe_password'] ?: $u;
        $wanLine = "wan-ip 1 mode pppoe username $u password $p";
    }
    if ($vlanProfile) $wanLine .= " vlan-profile $vlanProfile";
    $wanLine .= ' host 1';

    return implode("\n", [
        'conf t',
        '',
        "interface gpon-olt_1/{$slot}/{$port}",
        "onu {$onuId} type {$type} sn {$sn}",
        'exit',
        '',
        "interface gpon-onu_1/{$slot}/{$port}:{$onuId}",
        "name {$name}",
        "description {$description}",
        "tcont 1 name 1 profile {$tcontProfile}",
        'gemport 1 name 1 tcont 1',
        'encrypt 1 enable downstream',
        "service-port 1 vport 1 user-vlan {$vlan} vlan {$vlan}",
        'exit',
        '',
        "pon-onu-mng gpon-onu_1/{$slot}/{$port}:{$onuId}",
        "service {$serviceName} gemport 1 cos 0 vlan {$vlan}",
        $wanLine,
        'wan-ip 1 ping-response enable traceroute-response enable',
        'exit',
    ]);
}
```

---

## 13. UI Structure (Reference dari BMKV)

### 13.1 Halaman Utama `/smartolt`

File: [`resources/views/smartolt/index.blade.php`](../resources/views/smartolt/index.blade.php) (578 line)

Struktur:
- Header card (judul + tombol Tambah OLT)
- 4 stat-card (Total OLT, Total ONU, ONU Online, ONU Offline)
- Card filter (search by vendor/name, filter by status)
- Card tabel OLT — kolom: Name, Vendor (badge), IP, SNMP, CLI status, Total ONU, Capability badges, Actions
- Modal: Tambah OLT, Edit OLT, Hapus OLT, Test Connection

### 13.2 Halaman Detail `/smartolt/{id}/detail`

File: [`resources/views/smartolt/detail.blade.php`](../resources/views/smartolt/detail.blade.php) (626 line)

Async load via 2 endpoint:
1. `GET /api/chassis` → render System Info card + Board grid card
2. `GET /api/gpon-onus` → render GPON Port grid (per port: status, ONU count, link ke port_onus)

### 13.3 Halaman Port ONU `/smartolt/{id}/ports/{slot}/{port}/onus`

File: [`resources/views/smartolt/port_onus.blade.php`](../resources/views/smartolt/port_onus.blade.php) (1461 line, paling kompleks)

Async load via `GET /api/ports/{slot}/{port}/onus` + endpoint suplemen:
- Stat card: Total ONU, Online, Offline, Rx Highest/Lowest
- Tabel ONU lengkap dengan semua field optical/state/identity
- Per-row action button: Edit Info, Reboot, Enable/Disable, Detail CLI (modal), Configure CLI (full page)
- Auto-refresh Rx setiap 30 detik via `GET /api/cli/onu-rx/…`

### 13.4 Halaman Register `/smartolt/{id}/register`

File: [`resources/views/smartolt/register.blade.php`](../resources/views/smartolt/register.blade.php) (788 line)

Form lengkap provisioning ONU. Lihat §10.2 untuk flow.

### 13.5 Halaman Configure `/smartolt/{id}/onu/{slot}/{port}/{onuId}/configure`

File: [`resources/views/smartolt/configure_onu.blade.php`](../resources/views/smartolt/configure_onu.blade.php) (761 line)

Form reconfigure ONU dengan blok multi-row. Lihat §10.3 untuk flow.

### 13.6 Halaman Manage Profile `/smartolt/{id}/cli-profiles`

File: [`resources/views/smartolt/cli_profiles.blade.php`](../resources/views/smartolt/cli_profiles.blade.php) (611 line)

4 stat-card + 4 card tabel per profile type. Lihat §10.6 untuk flow.

### 13.7 Halaman Unconfigured `/smartolt/{id}/unconfigured`

File: [`resources/views/smartolt/unconfigured.blade.php`](../resources/views/smartolt/unconfigured.blade.php) (168 line)

Tabel ONU SN yang ditemukan via SNMP walk OID kandidat. Per-row tombol "Register" → redirect ke `/register?sn=...&port=...&oid=...`.

### 13.8 Halaman Registration History `/smartolt/{id}/registrations`

File: [`resources/views/smartolt/history.blade.php`](../resources/views/smartolt/history.blade.php) (202 line)

Tabel `smartolt_onu_registrations` paginated, filter status (`generated|executed|failed|reconfigured|reconfig_failed`), search keyword.

---

## 14. Quirk & Known Issues

| # | Quirk | Penyebab | Solusi BMKV |
|---|---|---|---|
| 1 | `zxAnCardOperStatus` mapping firmware lama vs C320 V2.x | MIB lama `0=on-line`, C320 V2.x aktif pakai `1=normal` | terima keduanya sebagai "Online" |
| 2 | `ifDescr` GPON kadang kosong di firmware tertentu | tidak konsisten antar firmware | fallback: synthesize port list dari tabel ONU (lihat `apiGponOnus`) |
| 3 | OPM table `.11.2.1.4` dan `.11.2.1.5` selalu sentinel | not-supported di hardware lokal | skip OID ini, pakai `.11.2.1.2` (OLT Rx) untuk overview |
| 4 | Rx scale berbeda per firmware (0.1-dBm vs milli-dBm vs legacy positive) | tidak dokumentasi resmi konsisten | auto-detect by magnitude di `convertOnuRxPowerToDbm()` |
| 5 | `terminal length 0` wajib setiap session | tanpa itu output `show` terpotong `--More--` | kirim segera setelah login + handle pager di buffer reader |
| 6 | `reboot` di pon-onu-mng kadang minta konfirmasi `yes/no`, kadang tidak | beda firmware | scan output → bila prompt, auto-kirim `y\n`; bila tidak, lanjut |
| 7 | `show running-config interface` kadang split token (`vlan-profi le NAME`) | line wrap CLI di firmware tertentu | repair token wrap di `repairRunningConfigWrappedTokens()` |
| 8 | `show onu running config` tidak ada di firmware lama | command unknown | tolerate `%Error` output dan skip silent (`isInvalidCommandOutput`) |
| 9 | `show pon power onu-rx` outputnya bisa partial di sesi pertama | timing / pager buffer | retry sampai 3× dengan merge map (`getPortOnuRxPower` loop) |
| 10 | Hex-STRING SN kadang 8-byte rapi, kadang campur ASCII | beda firmware | decoder dual-mode: 4-byte vendor ASCII + 4-byte serial hex, fallback strip non-alnum |
| 11 | OLT Rx (`.11.2.1.2`) di overview vs ONU Rx (`.50.12.1.1.10`) per-port | dua scope berbeda | overview pakai OLT-side (murah), per-port pakai ONU-side (lebih detail) |
| 12 | ifIndex composite Type-1 (`0x10000000 \| slot<<16 \| port<<8`) | encoding khas ZTE, bukan standar | dokumentasikan + fungsi encode/decode konsisten |

---

## 15. Sample Data Live (PATI-ZTE-C320)

Untuk regression test parser:

```
sysObjectID:    iso.3.6.1.4.1.3902
Firmware:       ZXAN V2.x
OLT IP:         (operator-specific)
Total slot:     2 line cards (slot 1-2)
Total PON:     4 per card (port 1-4)
Total ONU:     ~150 registered
```

ONU sample `gpon-onu_1/2/2:1`:

```
ifIndex     = 268567552  (0x10020200)
SN          = ZTEG12345678
Type        = F660V7.0
Phase state = 3 (Working)
Admin state = 1 (Active)
Rx raw      = 5635       → -18.730 dBm (legacy positive scale)
Tx raw      = ~5700      → -18.x dBm
Voltage raw = 160        → 3.20 V
Temp raw    = 11040      → 43.1 °C
Distance    = ~1500 m
Vendor ID   = ZTEG
SW version  = V7.0
Model       = F660V7.0
LAN1        = up, 1G
```

Cross-check CLI: `show pon power onu-rx gpon-olt_1/2/2` → row `gpon-onu_1/2/2:1    -18.762(dbm)` (cocok dengan SNMP, beda 0.03 dB karena momentary).

---

## 16. Verifikasi Lab Sebelum Production

Checklist minimal sebelum hubungkan OLT ZTE C300/C320 baru ke dashboard:

```bash
# 1. Identifikasi family
snmpget -v2c -c public HOST 1.3.6.1.2.1.1.2.0
# expect: iso.3.6.1.4.1.3902

snmpget -v2c -c public HOST 1.3.6.1.2.1.1.1.0
# expect: contain "ZXA10 C300" / "ZXA10 C320" / "ZXAN"

# 2. Walk inventory boards
snmpwalk -v2c -c public HOST 1.3.6.1.4.1.3902.1015.2.1.1.3.1.4

# 3. Walk GPON ports
snmpwalk -v2c -c public HOST 1.3.6.1.2.1.2.2.1.2 | grep -i gpon

# 4. Walk ONU modern table
snmpwalk -v2c -c public HOST 1.3.6.1.4.1.3902.1012.3.28.1.1.1
snmpwalk -v2c -c public HOST 1.3.6.1.4.1.3902.1012.3.28.1.1.5

# 5. Walk OPM Rx (full table) — pastikan return milli-dBm / 0.1-dBm values, bukan -3000 semua
snmpwalk -v2c -c public HOST 1.3.6.1.4.1.3902.1015.1010.11.2.1.2

# 6. Walk unconfigured ONU (test 4 OID kandidat)
for oid in \
  1.3.6.1.4.1.3902.1012.3.13.3.1.2 \
  1.3.6.1.4.1.3902.1082.500.10.2.1.1 \
  1.3.6.1.4.1.3902.1082.500.10.2.1.2 \
  1.3.6.1.4.1.3902.1082.500.10.1.1.1
do
  echo "=== $oid ==="
  snmpwalk -v2c -c public HOST $oid | head -5
done

# 7. CLI login test
telnet HOST 23
# > username\n
# Password: password\n
# ZXAN> enable
# ZXAN# terminal length 0
# ZXAN# show clock
# ZXAN# show card
# ZXAN# show gpon onu state gpon-olt_1/1/1
# ZXAN# show gpon profile tcont
# ZXAN# show onu-type
# ZXAN# show gpon onu profile vlan
# ZXAN# show gpon onu profile ip
# ZXAN# show pon power onu-rx gpon-olt_1/1/1
# ZXAN# exit

# 8. CLI write probe (LAB ONLY — pakai ONU dummy!)
# ZXAN# conf t
# ZXAN(config)# interface gpon-olt_1/1/1
# ZXAN(config-if)# onu 99 type ALL-ONT sn ZTEG99999999
# ZXAN(config-if)# exit
# ZXAN(config)# interface gpon-onu_1/1/1:99
# ZXAN(config-if)# name test_dummy
# ZXAN(config-if)# tcont 1 name 1 profile SERVER
# ZXAN(config-if)# gemport 1 name 1 tcont 1
# ZXAN(config-if)# service-port 1 vport 1 user-vlan 100 vlan 100
# ZXAN(config-if)# exit
# # rollback:
# ZXAN(config)# interface gpon-olt_1/1/1
# ZXAN(config-if)# no onu 99
# ZXAN(config-if)# exit

# 9. SNMP write test (LAB ONLY)
# ifIndex untuk slot=1 port=1: 0x10010100 = 268501760
snmpset -v2c -c private HOST 1.3.6.1.4.1.3902.1012.3.28.1.1.2.268501760.99 s "test-dummy"
snmpset -v2c -c private HOST 1.3.6.1.4.1.3902.1012.3.28.1.1.17.268501760.99 i 2  # disable
snmpset -v2c -c private HOST 1.3.6.1.4.1.3902.1012.3.28.1.1.17.268501760.99 i 1  # enable
```

---

## 17. File Driver di BMKV Repo (referensi cepat)

| File | Fungsi | Line count |
|---|---|---|
| [`app/Services/ZteSnmpService.php`](../app/Services/ZteSnmpService.php) | SNMP read + SNMP write (name/desc/state) | 1614 |
| [`app/Services/ZteCliSessionService.php`](../app/Services/ZteCliSessionService.php) | CLI transport telnet/SSH, reboot, getOnuDetailInfo, getOnuRunningConfig, parser | 1726 |
| [`app/Services/ZteCliProvisionService.php`](../app/Services/ZteCliProvisionService.php) | Script generator (provisioning, reconfigure, profile add/delete), parser profile | 1392 |
| [`app/Contracts/SmartOltSnmpDriver.php`](../app/Contracts/SmartOltSnmpDriver.php) | Interface yang harus di-implement driver | 35 |
| [`app/Services/SmartOltSnmpServiceResolver.php`](../app/Services/SmartOltSnmpServiceResolver.php) | Resolver vendor → driver class | 21 |
| [`app/Support/SmartOltSupport.php`](../app/Support/SmartOltSupport.php) | Capability matrix + vendor detection | 317 |
| [`app/Http/Controllers/SmartOltController.php`](../app/Http/Controllers/SmartOltController.php) | HTTP handlers full (read + write + provisioning + profile) | 2500+ |
| [`app/Http/Controllers/Api/SmartOltApiController.php`](../app/Http/Controllers/Api/SmartOltApiController.php) | API mobile/programmatic | — |
| [`app/Models/SnmpOlt.php`](../app/Models/SnmpOlt.php) | Model OLT dengan encrypted attributes | 84 |
| [`app/Models/SmartOltCliProfile.php`](../app/Models/SmartOltCliProfile.php) | Model profile catalog | 31 |
| [`app/Models/SmartOltOnuRegistration.php`](../app/Models/SmartOltOnuRegistration.php) | Model audit trail registrasi | 43 |
| [`resources/views/smartolt/index.blade.php`](../resources/views/smartolt/index.blade.php) | UI list OLT | 578 |
| [`resources/views/smartolt/detail.blade.php`](../resources/views/smartolt/detail.blade.php) | UI detail chassis + port | 626 |
| [`resources/views/smartolt/port_onus.blade.php`](../resources/views/smartolt/port_onus.blade.php) | UI tabel ONU per port (komponen kompleks) | 1461 |
| [`resources/views/smartolt/register.blade.php`](../resources/views/smartolt/register.blade.php) | UI form provisioning | 788 |
| [`resources/views/smartolt/configure_onu.blade.php`](../resources/views/smartolt/configure_onu.blade.php) | UI form reconfigure | 761 |
| [`resources/views/smartolt/cli_profiles.blade.php`](../resources/views/smartolt/cli_profiles.blade.php) | UI manage profile | 611 |
| [`resources/views/smartolt/unconfigured.blade.php`](../resources/views/smartolt/unconfigured.blade.php) | UI ONU discovery | 168 |
| [`resources/views/smartolt/history.blade.php`](../resources/views/smartolt/history.blade.php) | UI history registrasi | 202 |

---

## 18. Security & Guard yang Wajib Direplicate

1. **Encrypt at-rest credential**: SNMP communities + CLI password tidak boleh plaintext di DB. BMKV pakai trait `EncryptsSensitiveAttributes` dengan Laravel `Crypt::encryptString()`.
2. **Confirm ONU ID di apply Configure**: payload harus include `confirm_onu_id` yang match `onuId` di URL — guard agar tidak salah ONU.
3. **Re-read live config sebelum apply**: jangan trust snapshot client-side. Selalu baca ulang via CLI sebelum build delta script.
4. **Role check write**: `RoleAccess::canWriteModule(user, 'smartolt')` di setiap write endpoint.
5. **Device scope assignment**: user restricted hanya bisa akses OLT yang assigned (lihat `DeviceAccessService::canAccessDevice`).
6. **Remote console token short-lived**: terminal WebSocket token TTL default 60 detik, HMAC-signed payload, audit-logged.
7. **Audit log semua reboot / provisioning / reconfigure**: insert ke `smartolt_onu_registrations` + opsional `audit_logs`.
8. **PPPoE password warning**: stored plaintext di `smartolt_onu_registrations.pppoe_password`. Batasi akses tabel ini ke role administrator only bila migrasi ke project lain.

---

## 19. Roadmap Kandidat Pengembangan

Belum diimplementasi di BMKV, dicatat untuk masa depan:

- **WiFi config ONU (SSID/password) via CLI**: `pon-onu-mng` punya command `ssid`/`wifi` (lihat tree help `show ?` di `pon-onu-mng`). Belum di-expose di form Register/Configure.
- **Firmware upgrade ONU**: ZTE punya command `restore` + `upgrade-image` per-ONU. Destructive, butuh confirmation 2 step.
- **Bandwidth profile (DBA)**: `gpon profile dba` sudah ada di CLI tapi belum dibuka di Manage Profile (saat ini cuma TCONT).
- **VLAN switchport-bind**: `switchport-bind` per UNI port belum di-expose, padahal sering dipakai untuk ISP multi-service.
- **Bulk operation**: bulk reboot, bulk enable/disable, bulk reconfigure per port belum ada.
- **Provisioning ONU via SNMP SET**: `zxAnPonOltOnuAuthTable` (`.13.1.1.*`) bisa create row via `createAndGo(4)`. Lebih cepat daripada CLI tapi butuh test panjang per firmware. Saat ini `registerOnu()` di `ZteSnmpService` throw exception "belum aktif" sebagai placeholder.
- **Last online time / online duration** parsing dari `show gpon onu detail-info` (parser sudah tangkap, tapi UI belum tampilkan secara prominent).
- **Optical alarm trap listener**: ZTE kirim trap untuk LOS/SF/SD; saat ini BMKV cuma poll, belum subscribe trap.

---

## 20. Referensi

### Lokal
- [docs/SMARTOLT_OID_MAP.md](SMARTOLT_OID_MAP.md) §4 — peta OID ZTE multi-vendor
- [docs/MODULE_GUIDE.md](MODULE_GUIDE.md) §12 — overview modul SmartOLT
- [docs/FLOWS.md](FLOWS.md) — flow user journey SmartOLT
- [docs/pages/internal/smartolt-*.md](pages/internal/) — referensi per halaman
- [docs/features/smartolt-and-genieacs.md](features/smartolt-and-genieacs.md) — feature summary
- [docs/API.md](API.md) — referensi endpoint API mobile

### Vendor (ZTE)
- ZTE ZXA10 C300 Configuration Manual (vendor PDF, restricted)
- ZTE ZXA10 C320 V2.x SNMP MIB Specifications (vendor PDF, restricted)
- ZTE Enterprise OID base: `1.3.6.1.4.1.3902` (terdaftar di IANA Private Enterprise Numbers)

### Web standar
- RFC 4133 Entity-MIB — fallback discovery board
- RFC 2863 IF-MIB — `ifDescr`/`ifOperStatus`
- RFC 854 Telnet Protocol — IAC negotiation byte stripping
- ITU-T G.984 / G.988 (GPON / OMCI) — semantik tcont/gemport/UNI
