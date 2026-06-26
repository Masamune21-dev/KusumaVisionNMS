# 17 — C-Data GPON: peta SNMP walk & inventory via SNMP

Referensi OID hasil **full SNMP walk** OLT C-Data GPON **FD1608S-B1-NDA0** (live `OLT-GPON-CDATA-PATI`, id=277, `172.27.10.105`, SNMP v2c community `public`) dan cara driver memakainya untuk membaca inventory ONU **tanpa telnet**.

Terkait: [08-snmp-polling.md](08-snmp-polling.md), [16-peta-onu.md](16-peta-onu.md), driver [`CDataGponSnmpService`](../../app/Services/CData/CDataGponSnmpService.php).

> **Koreksi catatan lama.** Sempat diasumsikan "FD1608S V3 cuma bisa baca 1 ONU via SNMP". Itu **salah** — yang hanya 1 baris adalah tabel atribut V3 `34592…18.12`. Tabel **legacy `17409`** (nama + MAC) + tabel **optik `34592…21`** (status) membaca **seluruh ONU** (34/34 terverifikasi 2026-06-26, ~230 ms). Inventory penuh kini diambil via SNMP; CLI hanya meng-enrich serial-number & Rx.

## Identitas perangkat

| Item | Nilai |
| --- | --- |
| Model | C-Data FD1608S-B1-NDA0 (8× GPON + 4× GE + 2× XGE uplink) |
| `sysObjectID` | `.1.3.6.1.4.1.17409` (enterprise EPON — **bukan** 34592, walau ini GPON) |
| Total ONU terdaftar | 34 (semua di `gpon 0/0/1`) |

Karena `sysObjectID = 17409` di GPON maupun EPON, **klasifikasi family wajib dari string `vendor`**, bukan sysObjectID (lihat `SmartOltSnmpServiceResolver`).

## Encoding index (penting buat decode)

**ifIndex interface fisik (IF-MIB):**

```
ge   0/0/1..4  = 524289..524292   (0x080001..)
xge  0/0/1..2  = 786433..786434   (0x0C0001..)
gpon 0/0/1..8  = 1310721..1310728 (0x140001..)
```

**onuIndex global** (tabel legacy `17409.2.8.*` / `17409.2.3.4.7.*`):

```
base 0x480000 + onu_seq   →  4718593..4718626 = onu 1..34 (gpon 0/0/1)
```

## OID yang dipakai driver (jalur inventory V3 andal)

| Fungsi | OID base | Index | Catatan |
| --- | --- | --- | --- |
| **Nama ONU** (master) | `.1.3.6.1.4.1.17409.2.8.4.1.1.2` | `<onuIndex>` | string `"gpon F/S/P onu N <label>"` → beri slot/port/onuId + label |
| **MAC ONU** | `.1.3.6.1.4.1.17409.2.3.4.7.1.3` | `<onuIndex>.1` | Hex-STRING |
| **Status ONU** | `.1.3.6.1.4.1.34592.1.5.1.1.2.21.1.1.2` | `.1.0.<port>.<seq>.1` | `1` = online, `-1` = offline |
| **Penghubung onuIndex** | `.1.3.6.1.4.1.34592.1.5.1.1.2.21.1.1.3` | `.1.0.<port>.<seq>.1` | nilai = `<onuIndex>` → join tabel optik ↔ tabel nama/MAC |
| **Rx ONU (dBm)** | `.1.3.6.1.4.1.34592.1.5.1.1.2.21.1.1.5` | `.1.0.<port>.<seq>.1` | string dBm; **sering `--`** (lihat keterbatasan) |
| Ports PON | `.1.3.6.1.2.1.2.2.1.2` (ifDescr) + `…1.8` (ifOperStatus) | ifIndex | `gpon 0/<slot>/<port>` |

Algoritma `snmpOnus()`: walk tabel **nama** (master) → parse slot/port/onuId/label; join **MAC** by `onuIndex`; join **status & Rx** lewat kolom penghubung `…21.1.1.3` (suffix index identik antar kolom `.2/.3/.5`).

## Keterbatasan SNMP (dan kenapa CLI tetap perlu)

- **Tabel atribut V3 `34592…18.12`** hanya membalas ~2 baris di firmware ini → **tidak dipakai**.
- **Serial-number tidak tersedia via SNMP** di firmware V3 → diisi via CLI `show ont info all` (`CDataGponCliService`) sebagai enrichment best-effort.
- **Rx per-ONU via SNMP tidak andal** — `…21.1.1.5` umumnya `--` dan kadang fluktuatif/garbage (nilai positif besar di-buang oleh `CDataValue::gponRxDbm`, jendela `[-60, 5]` dBm). Sumber Rx andal tetap CLI `show ont optical-info`.
- Tabel `34592…3.4.1.1.11` (online-status di peta lama) **No Such Object** di firmware ini.

Jadi `getRegisteredOnus()` V3 = **SNMP dulu (selalu lengkap 34 ONU, ringan)**, lalu *enrich* SN/Rx via CLI bila kredensial telnet ada (`mergeCliDetail`, gagal CLI tidak menggugurkan inventory).

| Jalur | ONU | Atribut | Durasi (live #277) |
| --- | --- | --- | --- |
| SNMP murni | 34/34 | nama, MAC, online | ~230 ms |
| SNMP + enrich CLI | 34/34 | + serial-number, Rx andal | ~900 ms |

## Re-walk cepat

```bash
# full walk
snmpbulkwalk -v2c -c public -On 172.27.10.105 .1
# daftar nama ONU (master inventory)
snmpwalk -v2c -c public 172.27.10.105 .1.3.6.1.4.1.17409.2.8.4.1.1.2
# MAC per ONU
snmpwalk -v2c -c public 172.27.10.105 .1.3.6.1.4.1.17409.2.3.4.7.1.3
# status + penghubung onuIndex + Rx (kolom .2/.3/.5)
snmpwalk -v2c -c public 172.27.10.105 .1.3.6.1.4.1.34592.1.5.1.1.2.21.1.1
```

## Branch lain di walk (referensi, belum dipakai)

| OID | Isi |
| --- | --- |
| `.34592.1.5.1.1.2.17.2.1.<col>.1.0.<ifIndexPON>` | SFP/optik uplink OLT (col1=temp, 2=voltage, 3=bias, 4=tx dBm, 5=rx dBm) |
| `.34592.1.5.1.1.2.18.2.1.5` | ONU description (write OID) |
| `.34592.1.5.1.1.2.18.4.1.1` / `.2` | ONU reset / deactivate (write/SET) |
| `.17409.2.3.2.1.1.4` | tabel nama port (`ge 0/0/1`…) |
| `.17409.2.8.5.1.1.4.<onuIdx>.0.N` | service/port config per ONU |
| `.17409.2.8.10.6.1.3.<onuIdx>.1` | traffic counter per ONU (Counter64) |
| `.17409.2.8.11.2.1.1.2.<idx>` | line-profile name |
