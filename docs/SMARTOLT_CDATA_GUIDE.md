# Panduan OLT C-Data EPON & GPON — KusumaVision NMS

> Referensi integrasi OLT **C-Data** (EPON dan GPON) di **KusumaVision NMS**. Fokus: **OID** yang dipakai dan **fitur/kapabilitas** per family, lengkap dengan command CLI untuk operasi yang tidak bisa lewat SNMP. Isi sudah dipetakan ke kelas/route/halaman nyata repo ini (bukan draf blueprint dari project lain).
>
> Companion: [SMARTOLT_ZTE_C300_C320_C600_GUIDE.md](SMARTOLT_ZTE_C300_C320_C600_GUIDE.md), [SMARTOLT_HIOSO_GUIDE.md](SMARTOLT_HIOSO_GUIDE.md), [handbook/17-cdata-gpon-snmp-walk.md](handbook/17-cdata-gpon-snmp-walk.md), [handbook/08-snmp-polling.md](handbook/08-snmp-polling.md).
>
> Terakhir diperbarui: 13 Juli 2026.

C-Data dipasarkan dengan dua keluarga enterprise OID yang **berbeda dan tidak boleh dicampur**. Di repo ini keduanya driver **non-ZTE** yang di-resolve [`SmartOltSnmpServiceResolver`](../app/Services/SmartOltSnmpServiceResolver.php) dan ikut **polling terjadwal** via [`PollOltJob::pollViaScanner`](../app/Jobs/PollOltJob.php) + [`CDataOltScanner`](../app/Services/CData/CDataOltScanner.php):

| Family | Enterprise root | MIB publik | Contoh perangkat | Driver di repo ini |
| --- | --- | --- | --- | --- |
| **C-Data / ODM EPON** | `1.3.6.1.4.1.17409` | `NSCRTV-EPON-*` | FD1108S, FD1208S, FD1504, OLT EPON OEM/ODM | SNMP [`CDataEponSnmpService`](../app/Services/CData/CDataEponSnmpService.php) · CLI write [`CDataCliWriteService`](../app/Services/CData/CDataCliWriteService.php) |
| **C-Data native GPON** | `1.3.6.1.4.1.34592` | `FD-ONU-MIB`, `FD-OLT-MIB`, `CDATA-GPON-MIB` | FD1608S, FD1216S, FD1616GS (FlashV2.x / FlashV3.x) | SNMP [`CDataGponSnmpService`](../app/Services/CData/CDataGponSnmpService.php) · CLI read [`CDataGponCliService`](../app/Services/CData/CDataGponCliService.php) · CLI write `CDataCliWriteService` |

> **Penting:** `sysObjectID` adalah penentu family. EPON OEM C-Data mengembalikan `iso.3.6.1.4.1.17409`, GPON native C-Data mengembalikan `iso.3.6.1.4.1.34592`. Jangan asumsikan driver `17409` kompatibel dengan `34592` — index, naming, dan write path-nya beda total.
>
> ⚠️ **KOREKSI dari verifikasi lapangan BMKV (lihat §13):** asumsi di atas **tidak selalu benar**. OLT GPON **FD1608S** firmware **FlashV3.x** yang diuji justru mengembalikan `sysObjectID = .1.3.6.1.4.1.17409` (sama dengan EPON!), bukan `34592`. Karena itu BMKV **tidak** mengandalkan `sysObjectID` untuk menentukan family — dipakai string `vendor` yang diset operator (`SmartOltSupport::driverKey()` mencocokkan substring `17409`/`34592`/`cdata`/`epon`/`fd16…`), dan deteksi V3 dari keberadaan tabel `34592…18.12.1.1`. Tabel ONU family masih dibedakan dgn benar (EPON `17409.2.3.4.*` vs GPON `34592.*`), hanya identifier `sysObjectID`-nya yang tidak bisa dipercaya.

---

## 1. Identifikasi Device

Langkah pertama integrasi: probe family.

```bash
# 1. sysObjectID — penentu family
snmpget -v2c -c COMMUNITY -On HOST 1.3.6.1.2.1.1.2.0
#   iso.3.6.1.4.1.17409  -> C-Data/ODM EPON
#   iso.3.6.1.4.1.34592  -> C-Data native GPON

# 2. sysDescr — info firmware
snmpget -v2c -c COMMUNITY HOST 1.3.6.1.2.1.1.1.0

# 3. sentinel walk per family (yang return data = family-nya)
snmpwalk -v2c -c COMMUNITY -On -OQn HOST 1.3.6.1.4.1.17409.2.3.4.1.1.2   # EPON ONU name
snmpwalk -v2c -c COMMUNITY -On -OQn HOST 1.3.6.1.4.1.34592.1.5.1.1.2.18.12.1.1  # GPON v3 ONU status
```

Rekomendasi: simpan hasil probe sebagai `vendor` string di DB. Resolver BMKV (`SmartOltSupport::driverKey()`) memetakan substring vendor → driver:

| Substring vendor (lowercase) | Driver |
| --- | --- |
| `17409`, `nscrtv`, `odm`, `cdata`/`c-data`, `epon` (tanpa `zte`) | EPON 17409 |
| `34592`, `cdata native`, `c-data native`, `fd-onu`, `fd-olt`, `cdata gpon`, `c-data gpon` | GPON 34592 |

---

## 2. Spesifikasi Koneksi

### 2.1 SNMP

| Parameter | EPON 17409 | GPON 34592 |
| --- | --- | --- |
| Versi | SNMP v2c | SNMP v2c |
| Port | 161 (default) | 161 (default) |
| Read community | per device | per device |
| Write community | per device (sering ditolak utk ONU row) | per device |
| Timeout disarankan | ~2.000.000 µs (2 s) | ~2.000.000 µs (2 s) |
| Retries | 2 | 2 |

> PHP `snmp2_real_walk()` mengembalikan key dengan prefix MIB textual (`iso.3.6.1...`). Untuk GPON 34592 yang melakukan OID-suffix matching, set `snmp_set_oid_output_format(SNMP_OID_OUTPUT_NUMERIC)` sekali sebelum walk, atau pakai `snmpwalk -On -OQn` di CLI.

### 2.2 CLI (telnet / SSH)

CLI diperlukan untuk operasi yang SNMP-nya tidak tersedia/ditolak (lihat capability matrix §7). Shell C-Data mirip Cisco IOS.

| Parameter | Catatan |
| --- | --- |
| Transport | telnet (umum) atau SSH |
| Username prompt | `User name:` (ada spasi) / `login:` / `Username:` |
| Password prompt | `Password:` / `passwd:` |
| Line ending | `\r\n` (CRLF) — firmware C-Data echo `User name:` dan butuh CRLF strict |
| Privilege | `enable` → `config` → `interface ...` |

Sesi CLI C-Data (login CRLF + banner + navigasi enable) di-share lewat concern [`CData\Concerns\InteractsWithCDataCli`](../app/Services/CData/Concerns/InteractsWithCDataCli.php) (`openCliSession()`), dipakai baik oleh [`CDataGponCliService`](../app/Services/CData/CDataGponCliService.php) (read inventory/optical GPON V3) maupun [`CDataCliWriteService`](../app/Services/CData/CDataCliWriteService.php) (write ONU). Prompt username C-Data adalah `User name:` (ada spasi); line ending **wajib CRLF**.

---

## 3. Struktur Indeks ONU (kritikal)

Tiga skema indeks berbeda muncul di C-Data — wajib paham sebelum parsing.

### 3.1 EPON 17409 — encoded 32-bit device-index

Tabel ONU `17409.2.3.4.1.1.<col>.<deviceIndex>` di-index oleh **device-index 32-bit** yang meng-encode slot/port/onu:

```
deviceIndex (uint32):
  slot      = (deviceIndex >> 24) & 0xFF
  encPort   = (deviceIndex >> 8)  & 0xFF
  port      = (encPort / 0x10) + 1
  onuId     =  deviceIndex        & 0xFF
```

Namun cara paling andal: **parse dari string `onuName`** (kolom `.2`), karena formatnya `epon 0/<slot>/<port> onu <onuId> <deskripsi>`. Decode bitwise hanya dipakai sebagai fallback bila nama tak terparse.

- Optical Rx (`2.1.4`) di-index `.<deviceIndex>.<x>.<y>` — ambil segmen `deviceIndex` lewat regex `\.([0-9]+)\.\d+\.\d+$`.

### 3.2 GPON 34592 legacy — `slot.port.onuId` (3 segmen terakhir)

Tabel `34592.1.5.1.1.2.18.2.1.5` (description) dan `34592.1.3.4.1.1.<col>` di-index dengan **3 segmen numerik terakhir** = `slot.port.onuId`. Helper: ambil `lastThreeSegments(oid)`.

### 3.3 GPON 34592 v3 (FlashV3.x) — `.1.0.<ifIndex>.<flow>.<onuId>`

Firmware modern FD1608S/FD1216S V3.x mengekspos tabel ONU v3 di `34592.1.5.1.1.2.18.12.1.<col>` dengan index `.1.0.<ifIndex>.<flow>.<onuId>`:

- `onuId` = segmen terakhir
- `ifIndex` = segmen ke-3 dari belakang → map ke `slot/port` via `ifDescr` (`gpon X/Y/Z`)

> **Quirk berat firmware V3:** tabel v3 SNMP sering hanya mengembalikan **1 baris ONU** (baris pertama), bukan seluruh inventory. Optical/MAC/SN/description asli juga **tidak ada di SNMP**. Untuk V3 wajib ambil inventory dari **CLI `show ont info all`** dan enrich optical/MAC per-port via CLI. Deteksi V3: walk `34592.1.5.1.1.2.18.12.1.1` (status col) — kalau ada isinya, device V3.

---

## 4. OID Reference — C-Data EPON (`17409.*`)

Subtree utama ONU: `1.3.6.1.4.1.17409.2.3.4.*`.

### 4.1 System & Port (standar MIB-II)

| Objek | OID | Access | Fungsi |
| --- | --- | --- | --- |
| `sysDescr` | `1.3.6.1.2.1.1.1.0` | RO | identitas perangkat |
| `sysUptime` | `1.3.6.1.2.1.1.3.0` | RO | uptime |
| `sysName` | `1.3.6.1.2.1.1.5.0` | RW (MIB) | nama node |
| `sysLocation` | `1.3.6.1.2.1.1.6.0` | RW (MIB) | lokasi |
| `ifDescr` | `1.3.6.1.2.1.2.2.1.2` | RO | enumerasi port EPON (`epon 0/x/y`) |
| `ifOperStatus` | `1.3.6.1.2.1.2.2.1.8` | RO | status port (`1=up`, `2=down`) |

### 4.2 Tabel Info ONU — `17409.2.3.4.1.1.<col>`

| Col | Objek | OID lengkap | Access | Dipakai BMKV | Fungsi |
| --- | --- | --- | --- | --- | --- |
| `.2` | `onuName` | `…2.3.4.1.1.2` | RW* | **Ya** | label ONU = interface + deskripsi (`epon 0/1/1 onu 1 pelanggan`) |
| `.3` | `onuType` | `…2.3.4.1.1.3` | RO | — | tipe ONU |
| `.4` | `onuIpAddress` | `…2.3.4.1.1.4` | RO | — | IP manajemen ONU |
| `.5` | `onuIpSubnetMask` | `…2.3.4.1.1.5` | RO | — | subnet mask |
| `.6` | `onuIpGateway` | `…2.3.4.1.1.6` | RO | — | gateway |
| `.7` | `onuMacAddress` | `…2.3.4.1.1.7` | RO | **Ya** | MAC ONU (Hex-STRING) |
| `.8` | `onuOperationStatus` | `…2.3.4.1.1.8` | RO | **Ya** | `1=online`, `2=offline` |
| `.9` | `onuAdminStatus` | `…2.3.4.1.1.9` | enum | kandidat | enable/disable ONU (perlu uji write) |
| `.10` | `onuChipVendor` | `…2.3.4.1.1.10` | RO | — | vendor chip |
| `.11` | `onuChipType` | `…2.3.4.1.1.11` | RO | — | tipe chip |
| `.12` | `onuChipVersion` | `…2.3.4.1.1.12` | RO | — | versi chip |
| `.13` | `onuSoftwareVersion` | `…2.3.4.1.1.13` | RO | — | versi software |
| `.14` | `onuFirmwareVersion` | `…2.3.4.1.1.14` | RO | — | versi firmware |
| `.15` | `onuTestDistance` | `…2.3.4.1.1.15` | RO | — | jarak (meter) |
| `.16` | `onuLlidId` | `…2.3.4.1.1.16` | RO | — | LLID EPON |
| `.17` | `resetONU` | `…2.3.4.1.1.17` | enum | kandidat | reset/reboot via SNMP (perlu uji write) |
| `.18` | `onuTimeSinceLastRegister` | `…2.3.4.1.1.18` | RO | — | lama sejak register |
| `.19` | `onuMgmtCvlan` | `…2.3.4.1.1.19` | RW | kandidat | manajemen CVLAN |
| `.20` | `onuMgmtSvlan` | `…2.3.4.1.1.20` | RW | kandidat | manajemen SVLAN |
| `.21` | `onuMgmtPriority` | `…2.3.4.1.1.21` | RW | kandidat | priority |
| `.22` | `onuMgmtSnmpTrapHost` | `…2.3.4.1.1.22` | RW | sensitif | trap host (admin only) |
| `.23` | `onuMgmtSnmpCommunityForRead` | `…2.3.4.1.1.23` | RW | sensitif | jangan expose ke operator |
| `.24` | `onuMgmtSnmpCommunityForWrite` | `…2.3.4.1.1.24` | RW | sensitif | jangan expose ke operator |
| `.25` | `onuVendorId` | `…2.3.4.1.1.25` | RO | **Ya** | vendor ONU (`CDTC`, `ZTE`, …) |
| `.26` | `onuModelId` | `…2.3.4.1.1.26` | RO | **Ya** | model ONU (`25AR`, `F477`, …) |
| `.27` | `onuHwVersion` | `…2.3.4.1.1.27` | RO | **Ya** | hardware version |
| `.28` | `onuSerial` | `…2.3.4.1.1.28` | RO | **Ya** | serial; **catatan**: di sebagian device identik dengan MAC (`.7`) |

> *`onuName` (`.2`) bertipe display-string yang seharusnya writable, **tetapi pada firmware aktif lapangan SNMP SET ditolak `genError`** (write community valid — set ke `sysName.0` berhasil, set ke ONU row gagal). Karena itu BMKV menulis nama/deskripsi/reboot via **CLI** (lihat §6). Begitu juga `.9` (admin) dan `.17` (reset) dibalas `genError` saat diuji.

### 4.3 Optical Metrics ONU — `17409.2.3.4.2.1.<col>`

| Col | Objek | OID lengkap | Dipakai BMKV | Unit raw | Konversi |
| --- | --- | --- | --- | --- | --- |
| `.4` | `onuReceivedOpticalPower` (Rx) | `…2.3.4.2.1.4` | **Ya** | centi-dBm | `dbm = raw / 100` (mis. `-1697 → -16.97 dBm`) |
| `.5` | `onuTransmittedOpticalPower` (Tx) | `…2.3.4.2.1.5` | kandidat | centi-dBm | `raw / 100` |
| `.6` | `onuBiasCurrent` | `…2.3.4.2.1.6` | kandidat | centi-mA | `raw / 100` |
| `.7` | `onuWorkingVoltage` | `…2.3.4.2.1.7` | kandidat | centi-mV | sesuaikan skala live |
| `.8` | `onuWorkingTemperature` | `…2.3.4.2.1.8` | kandidat | centi-°C | `raw / 100` |

`raw = 0` pada Rx → **no signal** (ONU offline / fiber putus).

### 4.4 Capability, SLA, Auth (kandidat fitur lanjutan)

| Family | Root OID | Potensi fitur |
| --- | --- | --- |
| Capability port | `17409.2.3.4.3.1.*` | GE/FE port count, queue uplink/downlink, FEC (`.10`), encrypt mode (`.11`) |
| SLA bandwidth | `17409.2.3.4.4.1.*` | DS fixed/peak/committed (`.2..4`), US fixed/peak/committed (`.5..7`) |
| Authentication | `17409.2.3.4.5.*` | auth policy, pre-bind MAC (`.5.2.1.2`), allow/block action, block list |
| VLAN | `17409.2.3.7.*` | QinQ, VLAN map, uplink policy |
| QoS | `17409.2.3.8.*` | queue map, egress shaping, ingress policing |
| STP | `17409.2.3.9.*` | enable STP/RSTP, path cost, priority |
| Performance | `17409.2.3.10.*` | statistik ONU/port untuk analytics |

---

## 5. OID Reference — C-Data GPON (`34592.*`)

Dua subtree relevan: `FD-ONU-MIB` di `34592.1.3.4.*` dan `FD-OLT-MIB`/`CDATA-GPON-MIB` di `34592.1.5.1.1.2.*`.

### 5.1 System & Port (standar MIB-II)

Sama dengan §4.1 (`sysDescr`, `sysUptime`, `ifDescr`, `ifOperStatus`). `ifDescr` GPON memuat `gpon X/Y/Z` → dipakai untuk map `ifIndex → slot/port`.

### 5.2 Tabel ONU — FD-ONU-MIB `34592.1.3.4.1.1.<col>`

| Col | Objek | OID lengkap | Access | Dipakai BMKV | Fungsi |
| --- | --- | --- | --- | --- | --- |
| `.4` | `onuUserInfo` | `…1.3.4.1.1.4` | RW | **Ya** (fallback) | label/deskripsi ONU |
| `.11` | `onuOnLineStatus` | `…1.3.4.1.1.11` | RO | **Ya** | status online ONU |
| `.12` | `onuUserTrafficEnable` | `…1.3.4.1.1.12` | RW | kandidat | isolate/restore traffic |
| `.13` | `onuRangeValue` | `…1.3.4.1.1.13` | RO | kandidat | distance |
| `.32` | `onuOperation` | `…1.3.4.1.1.32` | RW | kandidat | delete offline ONU |

QoS / shaping (FD-ONU-MIB):

| Objek | OID | Access | Fungsi |
| --- | --- | --- | --- |
| `onuPortVlanData` | `34592.1.3.4.6.1.2` | RW | VLAN per UNI |
| `scheduleAlgorithm` | `34592.1.3.4.7.1.1.1` | RW | queue algorithm |
| `maxTrafficOutputRate` | `34592.1.3.4.7.1.1.2` | RW | shaping egress |
| `outputModule` | `34592.1.3.4.7.1.1.3` | RW | enable shaping |
| `policingTrafficType` | `34592.1.3.4.7.2.1.1` | RW | ingress policing |
| `maxTrafficInputRate` | `34592.1.3.4.7.2.1.2` | RW | ingress rate limit |
| `inputModule` | `34592.1.3.4.7.2.1.3` | RW | enable policing |
| `igmpSnoopParaData` | `34592.1.3.4.8.1.1.1` | RW | IGMP snooping |

### 5.3 OLT PON Port — FD-OLT-MIB `34592.1.5.1.1.2.17.*`

| Objek | OID | Access | Fungsi |
| --- | --- | --- | --- |
| `gponOltPortAutoFindSwitch` | `34592.1.5.1.1.2.17.1.1.4` | RW | toggle autofind ONU |
| `gponOltPortSwitch` | `34592.1.5.1.1.2.17.1.1.5` | RW | enable/disable PON port |
| `gponOltPortStatus` | `34592.1.5.1.1.2.17.1.1.6` | RO | status port |
| `gponOltPortDdmTemperature` | `34592.1.5.1.1.2.17.2.1.1` | RO | DDM suhu port |
| `gponOltPortDdmVoltage` | `34592.1.5.1.1.2.17.2.1.2` | RO | DDM voltage port |
| `gponOltPortDdmTxBiasCurrent` | `34592.1.5.1.1.2.17.2.1.3` | RO | DDM bias current |
| `gponOltPortDdmTxPower` | `34592.1.5.1.1.2.17.2.1.4` | RO | DDM Tx power port |
| `gponOltPortDdmRxPower` | `34592.1.5.1.1.2.17.2.1.5` | RO | DDM Rx power port |

### 5.4 ONU GPON — `34592.1.5.1.1.2.18.*` (legacy)

Index: 3 segmen terakhir = `slot.port.onuId`.

| Objek | OID | Access | Dipakai BMKV | Fungsi |
| --- | --- | --- | --- | --- |
| `gponOnuDistance` | `34592.1.5.1.1.2.18.2.1.4` | RO | kandidat | distance |
| `gponOnuInfoDescription` | `34592.1.5.1.1.2.18.2.1.5` | RW | **Ya** (non-V3) | deskripsi ONU |
| `gponOnuReset` | `34592.1.5.1.1.2.18.4.1.1` | RW | **Ya** (non-V3) | reset/reboot ONU (`1=reset`) |
| `gponOnuDeactive` | `34592.1.5.1.1.2.18.4.1.2` | RW | **Ya** (non-V3) | enable/disable (`0=active`, `1=deactive`) |

### 5.5 ONU GPON v3 — `34592.1.5.1.1.2.18.12.1.<col>` (FlashV3.x)

Index: `.1.0.<ifIndex>.<flow>.<onuId>`.

| Col | Isi | Access | Catatan |
| --- | --- | --- | --- |
| `.1` | status | RO | `1=active`. Walk col ini = **probe deteksi firmware V3** |
| `.10` | name | RW | nama ONU |
| `.11` | description | RW | deskripsi (kosmetik — OLT V3 tidak baca-balik; lihat catatan §6.2) |

> Pada V3, SNMP umumnya hanya mengembalikan 1 baris. **Inventory penuh, SN, MAC, optical, dan deskripsi yang benar-benar dipakai OLT hanya ada di CLI.** Treat SNMP V3 sebagai read terbatas; gunakan CLI sebagai sumber utama.

**Tabel enumerasi `34592.1.5.1.1.2.18.26.1.<col>` (temuan lapangan, lihat §13).** Berbeda dengan tabel atribut `.18.12` yang hanya 1 baris, tabel `.18.26.1` di-walk **mengembalikan satu baris per ONU** (mis. 31 ONU = 31 baris pada FD1608S #277), tapi **nilai kolomnya `-1`** (tampaknya tabel statistik kosong). Gunanya: **hitung jumlah ONU V3 yang benar** lewat SNMP (`countRegisteredOnus` BMKV pakai col `.2`), karena `.18.12` selalu lapor 1. **Atribut (SN/nama/status/optical) tetap WAJIB dari CLI.** Tabel ONU legacy `34592.1.3.4.1.1.*` dan `…18.2.1.*` **tidak ada** di firmware V3 ini (walk gagal).

### 5.6 Voice / IAD (opsional, hanya ONU voice-capable)

`onuIADMode`, `onuIADIpAddr`, `onuIADNetMask`, `onuIADDefaultGw`, `onuIADPppoeMode`, `onuIADPppoeUsrnm`, `onuIADPppoePw`, `onuIADVoiceCVlan`, `onuIADVoiceSVlan`, `onuIADVoicePriority` (subtree `FD-ONU-MIB` voice). Tampilkan hanya bila capability ONU mendukung voice.

---

## 5b. Identitas device & faceplate (terverifikasi live, 28 Jun 2026)

Dipakai oleh `CDataFaceplateService` → cache `last_test_result.panel` → `Components/CDataOlt/OltFaceplate.vue` (visualisasi panel-depan: port PON/GE/XGE + status, LED, legend). **Kedua family melapor enterprise `17409`** (EPON & GPON V3), jadi OID di bawah berlaku untuk keduanya.

### 5b.1 Enumerasi port fisik — IF-MIB (`1.3.6.1.2.1.2.2.1.*`)

`ifDescr` (`.2`) memuat semua port; klasifikasi dari prefix nama, status dari `ifOperStatus` (`.8`, `1=up`/`2=down`) + `ifAdminStatus` (`.7`, `2=shutdown`):

| Prefix `ifDescr` | Grup | Jenis | Contoh |
| --- | --- | --- | --- |
| `epon`/`gpon 0/<frame>/<port>` | PON (subgrup per frame/slot) | fiber | `gpon 0/0/1`, `epon 0/2/4` |
| `ge 0/<f>/<n>` | GE uplink | copper | `ge 0/0/1` (ifType 117) |
| `xge 0/<f>/<n>` | XGE uplink | fiber | `xge 0/0/1` (ifType 1/6) |

Live: **EPON-TAYU** = EPON 0/1 (4) + 0/2 (4) + GE 0/0 (4) + XGE 0/0 (4); **FD1608S** = GPON 0/0 (8) + GE 0/0 (4) + XGE 0/0 (2). Indeks ifIndex C-Data ber-encoding besar (mis. `1310721` utk gpon, `524289` utk ge) — jangan diandalkan; pakai nama.

### 5b.2 Tabel device/card — `17409.2.3.1.*`

Identitas perangkat. Get langsung per-leaf (walk subtree `2.3.1` kadang gagal getnext di EPON, tapi get leaf tetap jalan):

| OID | Field | EPON-TAYU | FD1608S |
| --- | --- | --- | --- |
| `17409.2.3.1.2.1.1.2.1` | model / nama | `OLT-CDA…` (Hex-STRING null-padded → **bukan model**, di-drop) | `FD1608S-B1-NDA0` |
| `17409.2.3.1.2.1.1.10.1` | vendor | — | `C-Data` |
| `17409.2.3.1.3.1.1.7.1.0` | versi HW | `V1.1` | `V1.1` |
| `17409.2.3.1.3.1.1.8.1.0` | versi SW | `V3.4.53_260130` | `V3.3.86_260113` |
| `17409.2.3.1.3.1.1.12.1.0` | serial | `AF2802-2503000082` | `DA22-2411000162` |
| `17409.2.3.1.3.1.1.14.1.0` | device type | `EPON OLT` | `GPON OLT` |

> Kolom `.2.1.1.2.1` = field nama fixed-width: di GPON berisi model produk bersih, di EPON berisi sysName ter-truncate (balik sbg Hex-STRING). `CDataFaceplateService::productModel()` membuang nilai berbentuk hex-string; headline EPON fallback ke `device_type`.

> **Health (CPU/suhu/memori) TIDAK tersedia via SNMP** di kedua OLT — host-resources (`25.*`), ENTITY-SENSOR (`99.*`), `entPhysical` (`47.*`) semua kosong/`No Such Object`. LED ALM tidak dikarang (tetap `off`) sampai disambung ke alarm engine.

---

## 6. CLI Reference

> **Semua write ONU C-Data (EPON & GPON) lewat satu service** [`CDataCliWriteService`](../app/Services/CData/CDataCliWriteService.php) — masuk `interface {epon|gpon} 0/{slot}` lalu `ont <action> {port} {onuId}`. Sintaks **rename/reboot/delete identik** EPON & GPON; hanya **enable/disable yang beda verb** per family (terverifikasi help CLI live). Route: `cdata-olt.onu.{info,reboot,state,delete}` + `cdata-olt.config.save`.

### 6.1 EPON (FD1108S / FD1208S / FD1304E)

SNMP write ONU ditolak `genError`, jadi rename/deskripsi/reboot/enable-disable lewat CLI. Masuk `interface epon 0/{slot}`, argumen command = `{port} {onuId}`:

```
> enable
# config
(config)# interface epon 0/{slot}
(config-epon-0/{slot})# ont description {port} {onuId} <text>   # rename
(config-epon-0/{slot})# no ont description {port} {onuId}       # clear deskripsi
(config-epon-0/{slot})# ont reboot {port} {onuId}
(config-epon-0/{slot})# ont enable {port} {onuId}              # enable  (EPON verb)
(config-epon-0/{slot})# ont disable {port} {onuId}             # disable (EPON verb)
(config-epon-0/{slot})# ont delete {port} {onuId}             # hapus/deregister ONU (destruktif, konfirmasi y/n auto)
(config-epon-0/{slot})# end
```

- Deskripsi control char dibersihkan, spasi berurutan dikolaps; kosong → `no ont description` (CLI tolak whitespace-only).
- **Enable/disable EPON** = `ont enable|disable` (terverifikasi help CLI live FD1304E) — beda dari GPON yang memakai `ont activate|deactivate` (§6.2).

### 6.2 GPON (FD1608S / FD1216S V3.x) — read `CDataGponCliService`, write `CDataCliWriteService`

CLI GPON memakai interface `gpon 0/<slot>`, argumen command = `<port> <onuId>`.

**Write (di submode `config-gpon-0/{slot}`):**

```
> enable
# config
(config)# interface gpon 0/{slot}
(config-gpon-0/{slot})# ont description {port} {onuId} <text>
(config-gpon-0/{slot})# no ont description {port} {onuId}      # clear
(config-gpon-0/{slot})# ont reboot {port} {onuId}
(config-gpon-0/{slot})# ont activate {port} {onuId}            # enable
(config-gpon-0/{slot})# ont deactivate {port} {onuId}          # disable
(config-gpon-0/{slot})# ont delete {port} {onuId}             # hapus/deregister ONU (destruktif, konfirmasi y/n auto)
(config-gpon-0/{slot})# ont security-mgmt {port} {onuId} 1 state enable mode forward protocol web   # buka akses remote web ONT
(config-gpon-0/{slot})# ont security-mgmt {port} {onuId} 1 state disable                            # tutup kembali
(config-gpon-0/{slot})# show ont optical-info {port} all
(config-gpon-0/{slot})# end
```

> **`ont security-mgmt` (Remote ONT) — GPON FlashV3 saja, TIDAK ada di manual resmi C-Data** (ketemu via context-help live FD1608S-B1, Jul 2026). Klon sintaks ZTE: rule index `1-16`, `mode {forward|discard}`, `protocol {web|https|telnet|ssh|ftp|snmp|tr069}`, `ingress-type {wan|lan|iphost0|iphost1}`, opsional `start-src-ip A.B.C.D end-src-ip A.B.C.D` (tanpa filter = semua source). Push via OMCI, **efek instan tanpa reboot, dipatuhi juga ONT merk ZTE** (diverifikasi live: F660 di OLT 277 — web WAN dari timeout jadi HTTP 200). Dipakai tombol **Remote ONT** di halaman Port ONU (`CDataCliWriteService::setRemoteAccess`, route `cdata-olt.onu.remote-access`, gated `supports_onu_remote_access` = V3 only). Lihat config existing: `show current-config` di level enable (**bukan** `show running-config` — Unknown command di V3).

> **`ont delete` terverifikasi live (27 Jun 2026)** via context-help di submode interface (read-only `?`), pada FD1608S (GPON, OLT 277) **dan** FD1108S (EPON, OLT 276) — sintaks **identik**:
> `ont delete <PORTID>` lalu arg ke-2 `<1-128>` (ONT ID) | `all` | `offline-list`.
> Grup `no ont` **tidak** punya bentuk delete (hanya `no ont description|gemport|tcont|…`), jadi gunakan `ont delete`, **bukan** `no ont`.

**Read (di level `enable`):**

| Command | Output | Parsing |
| --- | --- | --- |
| `show ont info all` | inventory semua ONT | kolom: `F/S  P  ONT_ID  SN  CONTROL  RUN  CFG  MATCH  LAST_DOWN  DESC` (DESC boleh berisi spasi) |
| `show ont optical-info {port} all` | DDM per port | kolom: `ONT_ID  Rx(dBm)  Tx(dBm)  OLT_Rx(dBm)  Temp(C)  Voltage(V)  Current(mA)` (`--` = N/A) |
| `show mac-address all` | tabel MAC | `MAC  …  gpon0/{slot}/{port}  {onuId}  {gemid}  dynamic` |
| `show ont version 0/{slot} {port} {onuId}` | versi ONT | `Vendor-ID:`, `Equipment-ID:`, `Main Software Version:` |

- Deskripsi GPON max **128 karakter**.
- **Catatan V3 penting:** SNMP SET ke `.18.2.1.5` (legacy desc) balas `notWritable`; tulis ke kolom v3 `.18.12.1.11` hanya update *salinan kosmetik* yang **tidak dibaca-balik** OLT. Jadi deskripsi yang benar-benar nempel **harus** lewat CLI `ont description`.

### 6.3 Error patterns CLI (deteksi gagal)

Anggap command gagal bila output mengandung (case-insensitive): `invalid input`, `unknown command`, `ambiguous command`, `incomplete command`, `command rejected`, `permission denied`, `authorization failed`, `not support`, `operation failed`, `failure:`, `error:`, `% bad`, `% invalid`, `% command`, `% there is no`.

### 6.4 Simpan Konfigurasi OLT (persist running-config) — EPON & GPON identik

Aksi **OLT-level** (bukan per-ONU): simpan running-config ke memori OLT. Dipakai tombol **"Save Config"** di daftar OLT ([`CDataCliWriteService::saveConfig`](../app/Services/CData/CDataCliWriteService.php), route `cdata-olt.config.save`).

```
> enable
# config
(config)# save            # simpan running-config ke memori OLT
(config)# end
```

- Sesi CLI ([`InteractsWithCDataCli::openCliSession`](../app/Services/CData/Concerns/InteractsWithCDataCli.php)) sudah masuk level `enable`, jadi service hanya perlu `config` → `save` → `end`.
- Konfirmasi (bila muncul) dijawab otomatis. Batas baca `save` longgar (~40 detik) untuk config besar.
- Gated capability `supports_config_save` (EPON 17409 & GPON 34592, termasuk V3.x).

---

## 7. Capability Matrix C-Data

| Kapabilitas | EPON 17409 | GPON 34592 (legacy/V2) | GPON 34592 (V3.x) |
| --- | --- | --- | --- |
| Read inventory ONU | SNMP | SNMP | **CLI** (`show ont info all`) |
| Read Rx/optical | SNMP `2.1.4` | DDM SNMP `17.2.1.*` (port) | **CLI** `show ont optical-info` (Rx/Tx/OLT-Rx/temp/volt/bias) |
| Read MAC | SNMP `1.1.7` | SNMP | **CLI** `show mac-address all` |
| Read SN | SNMP `1.1.28` | — | **CLI** (`show ont info all`) |
| Status online | SNMP `1.1.8` | SNMP `1.1.11` | CLI run-state |
| Rename / deskripsi | **CLI** `ont description` | SNMP `.18.2.1.5` *atau* CLI | **CLI** `ont description` |
| Reboot ONU | **CLI** `ont reboot` | SNMP `.18.4.1.1` | **CLI** `ont reboot` |
| Enable / disable ONU | **CLI** `ont enable/disable` | **CLI** `ont activate/deactivate` | **CLI** `ont activate/deactivate` |
| Delete / deregister ONU | **CLI** `ont delete` | **CLI** `ont delete` | **CLI** `ont delete` |
| Provisioning ONU baru | belum | belum | belum |
| PON port autofind/switch | — | SNMP `17.1.1.4/5` (kandidat) | SNMP/CLI |

Nilai capability nyata (dari [`SmartOltSupport::cdataEponCapabilities()`](../app/Support/SmartOltSupport.php#L225) / [`cdataGponCapabilities()`](../app/Support/SmartOltSupport.php#L259)): `reboot_mode` & `description_mode` = **`cli_cdata`** (EPON & GPON). `supports_onu_toggle` = **`true`** untuk **keduanya** (EPON `ont enable/disable`, GPON `ont activate/deactivate` — route `cdata-olt.onu.state` bercabang verb per family). `supports_onu_delete` = `true` (CLI `ont delete {port} {onuId}`, route `cdata-olt.onu.delete`). `supports_config_save` = `true` (§6.4). GPON: `supports_snmp_rx` mati & `supports_cli_rx` nyala saat V3 terdeteksi ([`isCDataGponV3`](../app/Support/SmartOltSupport.php#L109)).

---

## 8. Fitur yang Disarankan untuk Aplikasi SmartOLT-mu

Urutan implementasi dari paling aman:

**Tahap 1 — Monitoring (read-only):**
1. List OLT + status (ping `sysDescr`).
2. List PON/EPON port + status (`ifDescr` + `ifOperStatus`, atau derive dari ONU count).
3. List ONU per port: interface, nama/deskripsi, SN, MAC, vendor/model, status online, Rx power.
4. Klasifikasi redaman Rx (Good / Warning / Critical) untuk highlight ONU bermasalah.

**Tahap 2 — Aksi ONU (write, butuh guard + audit):**
5. Edit nama/deskripsi ONU (EPON & GPON V3 → CLI; GPON legacy → SNMP).
6. Reboot ONU.
7. Enable/disable ONU (GPON; EPON masih kandidat).

**Tahap 3 — Lanjutan (perlu validasi lab):**
8. Isolate/restore traffic (`onuUserTrafficEnable`).
9. SLA bandwidth & VLAN per UNI.
10. PON port autofind/switch, FEC, IGMP, STP.
11. Provisioning ONU unconfigured.

Setiap fitur write wajib: role guard (write-only role), konfirmasi modal (bukan `confirm()`), dan audit log (user, OLT, ONU, aksi, hasil). Objek sensitif (`onuMgmtSnmpCommunity*`, trap host) tidak boleh diekspos ke role read-only.

---

## 9. Konversi Value Penting

| Metric | Family | Raw | Rumus | Contoh |
| --- | --- | --- | --- | --- |
| Rx ONU | EPON 17409 | centi-dBm (int) | `raw / 100` | `-1697 → -16.97 dBm` |
| Rx/Tx/OLT-Rx/Temp/Volt/Bias | GPON V3 (CLI) | sudah desimal di output | langsung | `-19.03`, `49.70`, `3.26` |
| MAC | EPON 17409 | Hex-STRING (`D0 5F AF …`) | join `:` + uppercase | `D0:5F:AF:63:0F:2F` |
| MAC | GPON (CLI) | `D0:5F:AF:D2:96:DD` | normalisasi separator | `D0:5F:AF:D2:96:DD` |
| Status | EPON/GPON | int | `1=online`, `2=offline` | — |
| No-signal | EPON Rx | `raw == 0` | flag offline | — |

---

## 10. Verifikasi Lab Sebelum Production

```bash
# 1. Identifikasi family
snmpget  -v2c -c COMMUNITY -On HOST 1.3.6.1.2.1.1.2.0   # sysObjectID
snmpget  -v2c -c COMMUNITY     HOST 1.3.6.1.2.1.1.1.0   # sysDescr

# 2A. EPON 17409 — inventory + optical
snmpwalk -v2c -c COMMUNITY -On -OQn HOST 1.3.6.1.4.1.17409.2.3.4.1.1.2   # onuName
snmpwalk -v2c -c COMMUNITY -On -OQn HOST 1.3.6.1.4.1.17409.2.3.4.1.1.8   # status
snmpwalk -v2c -c COMMUNITY -On -OQn HOST 1.3.6.1.4.1.17409.2.3.4.2.1.4   # Rx
snmpwalk -v2c -c COMMUNITY -On -OQn HOST 1.3.6.1.2.1.2.2.1.2             # ifDescr

# 2B. GPON 34592 — deteksi V3 + tabel
snmpwalk -v2c -c COMMUNITY -On -OQn HOST 1.3.6.1.4.1.34592.1.5.1.1.2.18.12.1.1   # status (probe V3)
snmpwalk -v2c -c COMMUNITY -On -OQn HOST 1.3.6.1.4.1.34592.1.5.1.1.2.18.2.1.5    # desc legacy
snmpwalk -v2c -c COMMUNITY -On -OQn HOST 1.3.6.1.4.1.34592.1.3.4.1.1.11          # online status
snmpwalk -v2c -c COMMUNITY -On -OQn HOST 1.3.6.1.2.1.2.2.1.2                     # ifDescr (gpon X/Y/Z)

# 3. CLI probe (LAB ONLY, gunakan ONU dummy)
telnet HOST 23
# > username\r\n
# Password: password\r\n
# > enable
# # show ont info all                       (GPON V3)
# # config
# (config)# interface gpon 0/0              (GPON)  / interface epon 0/1 (EPON)
# (config-gpon-0/0)# ont description 1 99 test_dummy
# (config-gpon-0/0)# show ont optical-info 1 all
# (config-gpon-0/0)# end
# # rollback: ont description 1 99 {original}  atau  no ont description 1 99
```

Uji write terkontrol: rename ke string dummy → reboot 1 ONU non-produksi → enable/disable sekali → rollback ke kondisi awal.

---

## 11. File Driver di Repo (referensi implementasi)

> **Status implementasi.** Monitoring read (inventory/Rx/faceplate) **dan** aksi tulis ONU (rename, reboot, enable/disable, delete) **plus** Save Config sudah dibangun. Provisioning ONU baru belum ada.

| File | Peran |
| --- | --- |
| [app/Contracts/SmartOltSnmpDriver.php](../app/Contracts/SmartOltSnmpDriver.php) | kontrak read driver non-ZTE (dipakai resolver C-Data & HiOSO) |
| [app/Services/SmartOltSnmpServiceResolver.php](../app/Services/SmartOltSnmpServiceResolver.php) | resolver family (`vendor`) → driver konkret (`CDataEponSnmpService` / `CDataGponSnmpService` / `HiosoEponSnmpService`) |
| [app/Services/CData/CDataSnmp.php](../app/Services/CData/CDataSnmp.php) | koneksi SNMP low-level v1/v2c (output OID numerik), `get`/`walk` |
| [app/Services/CData/CDataValue.php](../app/Services/CData/CDataValue.php) | helper parsing murni (MAC, Rx centi-dBm, decode device-index EPON, parse onuName, segmen OID) |
| [app/Services/CData/CDataEponSnmpService.php](../app/Services/CData/CDataEponSnmpService.php) | driver SNMP EPON `17409` (inventory + Rx `2.3.4.2.1.4`) |
| [app/Services/CData/CDataGponSnmpService.php](../app/Services/CData/CDataGponSnmpService.php) | driver SNMP GPON `34592` (legacy `slot.port.onuId` + deteksi V3 + count via `.18.26`) |
| [app/Services/CData/CDataGponCliService.php](../app/Services/CData/CDataGponCliService.php) | CLI **read** GPON V3 (inventory `show ont info all` + Rx `show ont optical-info`, baca berbasis prompt) |
| [app/Services/CData/CDataCliWriteService.php](../app/Services/CData/CDataCliWriteService.php) | CLI **write** ONU EPON & GPON (rename/reboot/enable-disable/delete) + `saveConfig` |
| [app/Services/CData/Concerns/InteractsWithCDataCli.php](../app/Services/CData/Concerns/InteractsWithCDataCli.php) | sesi telnet C-Data bersama (login CRLF, banner, navigasi `enable`) |
| [app/Services/CData/CDataOltScanner.php](../app/Services/CData/CDataOltScanner.php) | scan penuh (dipakai polling terjadwal `PollOltJob` **dan** refresh manual) → tulis `last_test_result.port_onus` bentuk-ZTE |
| [app/Services/CData/CDataFaceplateService.php](../app/Services/CData/CDataFaceplateService.php) | faceplate panel-depan (IF-MIB + tabel device `17409.2.3.1.*`) → cache `last_test_result.panel` |
| [app/Support/SmartOltSupport.php](../app/Support/SmartOltSupport.php) | `driverKey()` + capability matrix + helper interface + `isCDataGponV3()` |
| [app/Http/Controllers/CDataOltController.php](../app/Http/Controllers/CDataOltController.php) | halaman + aksi OLT C-Data (index/detail/portOnus/test/refresh/save-config + onu info/reboot/state/delete) |
| `resources/js/Pages/CDataOlt/*.vue` + `resources/js/Components/CDataOlt/OltFaceplate.vue` | UI Inertia (Create/Edit/Detail/PortOnus + faceplate) |

Kontrak `SmartOltSnmpDriver` (read): `ping`, `getSystemInfo`, `getPorts`, `getRegisteredOnus`, `getRegisteredOnusByPort`, `getPortRxMap`, `countRegisteredOnus`, `getUnconfiguredOnus`. Dipakai C-Data **dan** HiOSO. ZTE **tidak** memakai kontrak ini (punya `OltSnmpClient` sendiri).

Rute C-Data (`routes/web.php`, prefix `cdata-olt`): `cdata-olt.{index,create,store,edit,update,destroy,test,detail,refresh,config.save,port-onus,port-onus.refresh}` + aksi ONU `cdata-olt.onu.{reboot,state,info,delete}`. Pemilihan rute lintas halaman (search/monitoring/peta) lewat [`SmartOltSupport::inventoryRoutePrefix()`](../app/Support/SmartOltSupport.php#L93) → `cdata-olt`.

---

## 12. Referensi

### Lokal
- [SMARTOLT_ZTE_C300_C320_C600_GUIDE.md](SMARTOLT_ZTE_C300_C320_C600_GUIDE.md) — referensi ZTE (data model, route, UI)
- [SMARTOLT_HIOSO_GUIDE.md](SMARTOLT_HIOSO_GUIDE.md) — HiOSO/V-Sol EPON
- [handbook/17-cdata-gpon-snmp-walk.md](handbook/17-cdata-gpon-snmp-walk.md) — catatan walk SNMP GPON C-Data (V3)
- [handbook/07-modul-fitur.md](handbook/07-modul-fitur.md) — overview modul SmartOLT di dashboard

### MIB publik
- `NSCRTV-EPON-ONU-MIB`, `NSCRTV-EPON-STP-MGM-MIB` (family `17409`)
- `FD-ONU-MIB`, `FD-OLT-MIB`, `CDATA-GPON-MIB`, `CDATA-EPON-MIB` (family `34592`)

### Standar
- RFC 3418 SNMPv2-MIB — `https://datatracker.ietf.org/doc/html/rfc3418`
- RFC 854 Telnet (CRLF strict) — `https://datatracker.ietf.org/doc/html/rfc854`

---

## 13. Verifikasi lapangan BMKV (OLT live, 21 Juni 2026)

Diuji terhadap dua OLT produksi. **Yang tertulis di bawah adalah perilaku nyata**, mengoreksi beberapa asumsi blueprint.

| OLT | Model / firmware | sysObjectID | Inventory | Jumlah | Waktu |
| --- | --- | --- | --- | --- | --- |
| EPON | OEM 17409 (sysName `OLT-CDATA-TAYU`) | `.1.3.6.1.4.1.17409` | **SNMP** `17409.2.3.4.*` | 258 ONU | ~0,7 s |
| GPON | **FD1608S** `V3.3.86_260113` | `.1.3.6.1.4.1.17409` (**bukan 34592!**) | **CLI** `show ont info all` | 31 ONU | ~0,4 s |

**Temuan kunci:**

1. **sysObjectID tidak andal.** Kedua device melaporkan `17409`. Family ditentukan dari string `vendor` (operator set saat tambah OLT) + probe tabel V3 `34592…18.12.1.1`, **bukan** sysObjectID.
2. **GPON V3 + SNMP = 1 ONU saja.** Tabel atribut `34592…18.12.1.*` hanya 1 baris; tabel legacy `34592.1.3.4.1.1.*` & `…18.2.1.*` **tidak ada**. Tabel `…18.26.1.*` punya 1 baris/ONU (enumerasi, nilai `-1`) → dipakai hanya untuk **hitung jumlah**. Maka inventory GPON V3 **wajib CLI**.
3. **EPON serial ≈ MAC**, dikembalikan sbg Hex-STRING (`D0 5F AF …`) → dinormalisasi ke `D0:5F:AF:…`. Kolom model (`.26`) kadang ber-anotasi `25AR(0x32354152)` → ambil bagian ASCII saja.
4. **CLI baca berbasis prompt, bukan jeda diam.** Berhenti begitu prompt (`#`/`>`) muncul → inventory+optical 31 ONU GPON ~0,4 s (kalau menunggu idle bisa ~10 s).

**Format CLI terverifikasi (FD1608S V3):**

```
# show ont info all                       (di level enable)
  F/S P  ONT_ID  SN            CONTROL  RUN     CONFIG   MATCH  LAST_DOWN   DESC
  0/0 1  1       CDTCAFD296DB  Active   Online  success  match  --          SERVER-PENJAWI
  ...
  Total: 31,  online: 31, ...
```
- `F/S` = frame/slot (`0/0` → slot=0); `P` = port; index ONU di BMKV = `slot.port.ONT_ID`; interface = `gpon 0/{slot}/{port}:{ONT_ID}`.
- `LAST_DOWN` `--` = null; DESC boleh mengandung spasi/slash.

```
# config
(config)# interface gpon 0/0
(config-if-gpon-0/0)# show ont optical-info 1 all     (arg = nomor PORT/P)
  ONT_ID  Rx(dBm)  Tx(dBm)  OLT_Rx(dBm)  Temp(C)  Voltage(V)  Current(mA)
  1       -18.83   1.56     -26.02       41.55    3.26        11.55
  ...
```
- Rx ONU = kolom ke-2; `--` = N/A. BMKV mengambil optical **dalam sesi telnet yang sama** dgn `show ont info all` (grup per port) lalu enrich `rx_power_dbm`.
- Perintah `show ont optical-info {port}` **gagal di level enable** (`% Unknown command`) — harus di submode `interface gpon 0/{slot}`.

> Tindak lanjut: bila menemui firmware C-Data GPON **legacy/non-V3**, tabel SNMP `34592.1.3.4.1.1.*` semestinya terisi (jalur `legacyOnus` di `CDataGponSnmpService`) — uji ulang saat perangkat tersedia.
