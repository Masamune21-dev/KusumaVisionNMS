# 17 — C-Data GPON: peta SNMP walk & inventory via SNMP

Referensi OID hasil **full SNMP walk** dua OLT C-Data GPON dan cara driver memakainya untuk membaca inventory ONU **tanpa telnet**:

| OLT | Model / firmware | Port | Catatan |
| --- | --- | --- | --- |
| OLT uji A | **FD1608S-B1-NDA0** `V3.3.86_260113` | 8 PON · 4 GE combo · 2 XGE | 168 ONU, punya tabel `34592…18.12` (penanda `is_v3`) |
| OLT uji B | **FD1601S-B1** `V3.2.5_251111` | 1 PON · 2 GE RJ45 · 1 XGE | 24 ONU, **tidak** punya tabel `34592…18.12` / legacy |

Terkait: [08-snmp-polling.md](08-snmp-polling.md), [16-peta-onu.md](16-peta-onu.md), driver [`CDataGponSnmpService`](../../app/Services/CData/CDataGponSnmpService.php).

> **Status (Sep 2026).** Isi masih sesuai kode. FD1602S (2 PON) belum pernah diuji langsung oleh pengembang — dukungannya
> berdasar firmware yang sama dengan FD1601S dan laporan pengguna repo ini (dulu terbaca 0 ONU, kini lewat NSCRTV).
> Halaman **VLAN & detail port** (CLI, firmware V3 GPON & EPON) tidak bergantung pada tabel SNMP di sini — gerbangnya
> `SmartOltSupport::hasCDataV3Cli()` (versi software faceplate `V3.`), lihat [`SMARTOLT_CDATA_GUIDE.md` §14](../SMARTOLT_CDATA_GUIDE.md).

> **Koreksi 28 Sep 2026.** Driver lama memilih jalur dari probe `34592…18.12.1.1` dan membaca status dari tabel optik `34592…21`. Keduanya ternyata salah untuk sebagian perangkat: FD1601S tak punya tabel `.18.12` → terbaca **0 ONU**; di FD1608S tabel `.21` melaporkan **3 ONU online sebagai offline**. Kini jalur utama = tabel **NSCRTV-FTTX-GPON-MIB `17409.2.8.4`**, dicocokkan 1:1 dengan CLI `show ont info all` di FD1608S (4 offline = 4 offline). Selain itu satu ONU yang namanya dikirim sebagai Hex-STRING (padding NUL) dulu gagal diparse dan tak pernah tampil — kini di-decode.

## Identitas perangkat

| Item | FD1608S-B1 | FD1601S-B1 |
| --- | --- | --- |
| `sysObjectID` | `.1.3.6.1.4.1.17409` | `.1.3.6.1.4.1.17409` |
| `sysDescr` | `C-Data System Software Device Model: FD1608S-B1-NDA0` | `olt` |
| Model `17409.2.3.1.2.1.1.3.1` | `FD1608S-B1-NDA0` | `FD1601S-B1` |
| Nama device `17409.2.3.1.2.1.1.2.1` | `FD1608S-B1-NDA0` (hostname bawaan) | hostname operator — **bukan model** |

Karena `sysObjectID = 17409` di GPON maupun EPON, **klasifikasi family wajib dari string `vendor`** (dropdown "C-Data GPON" di form) atau kata kunci model (`fd1601`/`fd1602`/`fd1604`/`fd1608`/…), bukan sysObjectID.

## Encoding index

**ifIndex interface fisik (IF-MIB), sama di kedua model:**

```
ge   0/0/N = 524288 + N    (0x080000 + N)
xge  0/0/N = 786432 + N    (0x0C0000 + N)
gpon 0/0/N = 1310720 + N   (0x140000 + N)
```

**onuIndex global** (tabel `17409.2.8.*` / `17409.2.3.4.7.*`): `0x480000 + seq` → `4718593` = ONU pertama. Nomor ONU asli diambil dari **nama**, bukan dari index.

## OID yang dipakai driver

### Jalur utama — NSCRTV `gponOnuInfoEntry` `17409.2.8.4.1.1.<col>.<onuIndex>`

| Col | Objek (MIB) | Isi | Catatan |
| --- | --- | --- | --- |
| `.2` | `onuName` | `"gpon F/S/P onu N <label>"` | master: slot/port/onuId + label. Bisa datang sbg Hex-STRING ber-NUL → di-decode (`CDataValue::parseGponOnuName`) |
| `.3` | `onuSerialNum` | 8 byte: 4 ASCII + 4 hex | `CDataValue::gponSerial` → `ZTEG1A2B3C4D` (cocok CLI) |
| `.5` | `onuVendorID` | `ZTEG`, `CDTC`, … | `vendor_id` |
| `.6` | `onuEquipmentID` | `F609V5.3`, … | `type_name` |
| `.7` | `onuOperationStatus` | `1` up / `2` down | **sumber status online** |
| `.103` | ekstensi vendor (di luar MIB) | `LOS`, `dying-gasp`, `--` | `last_down_cause` (sama dgn kolom LAST_DOWN CLI) |

Kolom lain yang terlihat tapi **belum dipakai**: `.8` admin, `.9` jarak (m), `.11` activate/deactivate, `.14` versi HW, `.102` waktu down terakhir.

### Rx ONU — `17409.2.8.4.4.1.4.<onuIndex>.<card>.<port>` (centi-dBm)

`-2495` → -24,95 dBm. Segmen setelah onuIndex **beda antar model** (FD1608S `.0.0`, FD1601S `.0.1310721`) → driver hanya memakai segmen pertama. ONU offline dilaporkan `-1` → dibuang (`CDataValue::gponCentiRxDbm`). Kolom `.5` Tx, `.6` bias, `.7` tegangan, `.8` suhu (semua centi-unit) belum dipakai.

### Pelengkap & cadangan

| Fungsi | OID | Catatan |
| --- | --- | --- |
| MAC ONU | `17409.2.3.4.7.1.3.<onuIndex>.1` | ada di FD1608S, **kosong** di FD1601S |
| Status/Rx cadangan | `34592.1.5.1.1.2.21.1.1.<col>` | hanya bila kolom NSCRTV kosong. **Tidak andal**: FD1608S 3 ONU online = `-1`; FD1601S semua `-1`/`--` dan col3 berisi nomor ONU, bukan onuIndex |
| Penanda `is_v3` | `34592.1.5.1.1.2.18.12.1.1` | ada di FD1608S saja; hanya membuka fitur CLI yang terverifikasi (Rx CLI sebagai sumber utama, Remote ONT FlashV3 `ont security-mgmt`). FD1601S tak punya tabel ini → Rx dari SNMP NSCRTV, Remote ONT tertutup |
| Legacy FD-ONU-MIB | `34592.1.3.4.1.1.*` | tidak ada di kedua model; dipertahankan untuk firmware lama |

Tabel yang **absen** (`noSuchObject`, errno `SNMP::ERRNO_ERROR_IN_REPLY`) dianggap kosong lewat `CDataSnmpMissingOid`; **timeout** tetap dilempar supaya scan gagal dan cache lama bertahan, bukan tiba-tiba 0 ONU.

## Peran CLI

Bila kredensial telnet ada, `getRegisteredOnus()` meng-enrich hasil SNMP dengan CLI `show ont info all` + `show ont optical-info` (`CDataGponCliService`): admin-state dan Rx CLI (diutamakan). Daftar ONU dan status online **tetap dari SNMP**. Tanpa telnet (mis. FD1601S tanpa forward telnet), inventory tetap lengkap.

| Jalur | FD1608S (168 ONU) | FD1601S (24 ONU) |
| --- | --- | --- |
| SNMP murni | nama, SN, model, status, Rx, sebab down | idem, ~80 ms |
| SNMP + enrich CLI | + admin-state, Rx CLI (~2,8 s) | — (telnet tak di-forward) |

## Faceplate (`CDataFaceplateService`)

- Model dari `17409.2.3.1.2.1.1.3.1`, cadangan `.2.1` (di EPON berisi Hex-STRING nama → dibuang).
- GPON ≤ 2 PON (FD1601S/FD1602S, datasheet + foto): PON · **GE RJ45 sebaris** · 10GE · **CONSOLE saja**. FD1608S tetap COMBO GE SFP + RJ45 bertumpuk + CONSOLE/MGMT.
- Gambar produk: `public/img/olt/cdata-gpon-fd1601s.webp`, `cdata-gpon-fd1602s.webp` (dipilih `resources/js/lib/oltImage.js` dari model).
- Versi software faceplate (`panel.device.sw_version`, mis. `V3.3.86_260113`, `V3.2.5_251111`) menjadi gerbang halaman VLAN & detail port.
- **Label port PON disimpan di NMS** (tabel `olt_port_labels`, `supports_port_label`), bukan ke perangkat: probe `ifAlias` hanya
  mengembalikan cerminan nama port bawaan agent, dan tak ada perintah deskripsi port yang terverifikasi.

## Re-walk cepat

```bash
# nama, status, Rx (ganti HOST/community; OLT di balik NAT: udp:IP:PORT — SNMP wajib UDP)
snmpwalk -v2c -c public -On HOST .1.3.6.1.4.1.17409.2.8.4.1.1.2
snmpwalk -v2c -c public -On HOST .1.3.6.1.4.1.17409.2.8.4.1.1.7
snmpwalk -v2c -c public -On HOST .1.3.6.1.4.1.17409.2.8.4.4.1.4
# full walk dua enterprise (±2 detik)
snmpbulkwalk -v2c -c public -On HOST .1.3.6.1.4.1.17409
snmpbulkwalk -v2c -c public -On HOST .1.3.6.1.4.1.34592
```

> ⚠️ Kolom `17409.2.8.4.3.1.13/14` (tabel IP-host ONU, di luar MIB) tampak memuat kredensial WAN ONU yang diprovisikan dari OLT. Jangan dipakai, dan batasi akses SNMP ke IP NMS saja.

## Branch lain di walk (referensi, belum dipakai)

| OID | Isi |
| --- | --- |
| `.34592.1.5.1.1.2.17.2.1.<col>.1.0.<ifIndexPON>` | SFP/optik port PON OLT (col1=temp, 2=voltage, 3=bias, 4=tx dBm, 5=rx dBm) |
| `.34592.1.5.1.1.2.18.2.1.5` | ONU description (write OID) |
| `.34592.1.5.1.1.2.18.4.1.1` / `.2` | ONU reset / deactivate (write/SET) |
| `.17409.2.3.2.1.1.4` | tabel nama port (`ge 0/0/1`…) |
| `.17409.2.8.5.1.1.4.<onuIdx>.0.N` | service/port config per ONU |
| `.17409.2.8.10.6.1.3.<onuIdx>.1` | traffic counter per ONU (Counter64) |
| `.17409.2.8.11.2.1.1.2.<idx>` | line-profile name |
