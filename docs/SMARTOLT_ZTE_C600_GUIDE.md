# Panduan SmartOLT ZTE C600 (Titan) — KusumaVision NMS

> **Status: terverifikasi live.** Seluruh OID di dokumen ini dipetakan kolom-per-kolom terhadap OLT
> C600 sungguhan (`sysDescr` = `ZXA10 C600, ZTE ZXA10 Software Version: V1.2.2`) pada **15 Juli 2026**,
> lewat `snmpwalk`/`snmpget` v2c. Bagian CLI (penamaan interface §3.1, kartu §10, provisioning §11)
> diturunkan dari `show card` + `show running-config` C600 yang sama. Yang **belum** terbukti ditandai
> eksplisit — jangan diisi dengan tebakan.
>
> **Status per 1 Okt 2026.** Bagian lama dokumen ini tertinggal dari kode dan sudah dikoreksi: nama,
> deskripsi, admin-state, phase kaya, dan unconfigured C600 **sudah terbaca via SNMP** (§4.1, §5);
> **provisioning C600 AKTIF** (Model B / SmartOLT TR069, §11) dan sudah dipakai registrasi live; CLI C600 yang
> dipakai kode dirangkum di §11a. Yang masih tertutup: tulis SNMP (rename/enable-disable), Configure/Copy/TR069
> Massal, Bind ONU, dan matikan/nyalakan port (§6).

Dokumen ini menggantikan bagian C600 di [`SMARTOLT_ZTE_C300_C320_C600_GUIDE.md`](SMARTOLT_ZTE_C300_C320_C600_GUIDE.md),
yang OID C600-nya **salah total** (lihat §7). Untuk C300/C320 guide tersebut tetap berlaku.

---

## 1. Identifikasi

| Objek | Nilai di perangkat asli |
|---|---|
| `sysDescr` (`1.3.6.1.2.1.1.1.0`) | `ZXA10 C600, ZTE ZXA10 Software Version: V1.2.2` |
| `sysObjectID` (`1.3.6.1.2.1.1.2.0`) | `.1.3.6.1.4.1.3902.1082.1001.600.1.1` |
| `sysName` (`1.3.6.1.2.1.1.5.0`) | `ZXAN` (generik — **jangan** dipakai untuk deteksi family) |

`SmartOltSupport::driverKey()` → `DRIVER_ZTE` (C600 berbagi seluruh jalur ZTE).
`SmartOltSupport::isC600()` menyalakan cabang C600 di dalam driver, dan mengenali C600 dari **dua** sumber:

1. substring `c600` pada `vendor` + `name` + `last_test_result.system.sys_descr`, **atau**
2. `sysObjectID` mengandung `3902.1082.1001.600`.

Sumber kedua penting: sebelum tombol **Test SNMP** dijalankan, `last_test_result.system.sys_descr` masih
kosong, sehingga C600 yang baru ditambahkan (dan tidak diberi nama mengandung "C600") akan diperlakukan
sebagai C300/C320 — encoding ifIndex dan OID-nya beda total.

> **Subtree `.1012` tidak ada di C600.** `snmpwalk .1.3.6.1.4.1.3902.1012` → *No Such Object*. Tidak ada
> jalur fallback ke OID C300/C320; kalau cabang C600 tidak aktif, hasilnya nol ONU.

---

## 2. Encoding ifIndex (terverifikasi)

```
ifIndex = (1<<28) | (1<<24) | (1<<16) | (slot << 8) | port    # type | rack | shelf | slot | port
slot = (ifIndex >> 8) & 0xFF ;  port = ifIndex & 0xFF
```

Bukti live — `gpon_olt-1/3/1` → `285278977` = `0x11010301` (byte: `0x11` type, `01` rack, `03` slot, `01` port),
persis sama dengan keluaran `OltSnmpClient::zteEncodeIfIndex()`. Uplink memakai byte type yang sama:
`xgei-1/10/1` = `0x11010A01`, jadi **byte `0x11` bukan penanda PON** — jangan dipakai memfilter port PON.

Implementasi: [`OltSnmpClient::zteEncodeIfIndex()` / `decodeIfIndex()`](../app/Services/Snmp/OltSnmpClient.php).

---

## 3. Penamaan port PON — `ifName`, bukan `ifDescr`

Ini beda penting dari C300/C320:

| OID | Isi di C600 | Contoh |
|---|---|---|
| `ifName` (`1.3.6.1.2.1.31.1.1.1.1`) | **nama interface** | `gpon_olt-1/3/1`, `xgei-1/10/1` |
| `ifDescr` (`1.3.6.1.2.1.2.2.1.2`) | **deskripsi bebas** (nama area/pelanggan) | `NAMA AREA`, `` (kosong) |

Di C320 sebaliknya: `ifName` = `gpon_1/2/1`. Jadi tiap family mengeja port yang sama dengan cara berbeda:

| Family | `ifName` mentah | Nama CLI | Dinormalisasi jadi |
|---|---|---|---|
| C320 | `gpon_1/2/1` | `gpon-olt_1/2/1` | `gpon-olt_1/2/1` |
| C600 | `gpon_olt-1/3/1` | `gpon_olt-1/3/1` (**identik ifName**) | `gpon_olt-1/3/1` |

`OltSnmpClient::resolvePortLabel()` menangkap ekor numerik lalu memancarkan ejaan CLI milik family-nya
masing-masing. **Jangan** kembali ke pola `preg_replace('/^gpon_/', 'gpon-olt_')` — di C600 itu
menghasilkan `gpon-olt_olt-1/3/1` (bug yang sudah ditambal).

### 3.1 Penamaan CLI C600 = 3-tier (terverifikasi, koreksi asumsi lama)

Running-config C600 asli:

```
interface gpon_olt-1/3/13
 onu 8 type F620IBV9.3.11 sn ZTEGDC480F1C
interface gpon_onu-1/3/13:8
pon-onu-mng gpon_onu-1/3/13:8
interface vport-1/3/13.8:1
```

Jadi C600 memakai **3-tier `1/{slot}/{port}`** dengan eja `gpon_olt-` / `gpon_onu-` — **bukan** 4-tier
`gpon-olt_1/1/{slot}/{port}` seperti yang dulu diklaim CLAUDE.md & `SmartOltSupport`. Shelf tak muncul di
nama CLI walau ada di ifIndex. Uplink mengikuti pola sama: `xgei-1/10/1` (3-tier, eja pakai `-`),
sedangkan C300/C320 memakai `xgei_1/{slot}/{port}`.

| | C300 / C320 | C600 |
|---|---|---|
| Port PON | `gpon-olt_1/{slot}/{port}` | `gpon_olt-1/{slot}/{port}` |
| ONU | `gpon-onu_1/{slot}/{port}:{id}` | `gpon_onu-1/{slot}/{port}:{id}` |
| Uplink | `xgei_1/{slot}/{port}` | `xgei-1/{slot}/{port}` |
| vport | — | `vport-1/{slot}/{port}.{id}:{vport}` |

---

## 4. Tabel ONU C600 (terverifikasi)

Basis: **`1.3.6.1.4.1.3902.1082.500.20.2.1.2.1.{kolom}`**, index **`{ifIndex}.{onuId}`**, dengan `ifIndex`
= ifIndex IF-MIB port PON yang sesungguhnya (§2).

| Kolom | OID | Isi | Contoh |
|---|---|---|---|
| `.1` | `…20.2.1.2.1.1` | vendor id ONU | `ZTEG`, `HWTC`, `ZKXX` |
| `.2` | `…20.2.1.2.1.2` | versi firmware ONU | `V2.4F`, `26AD.A` |
| `.3` | `…20.2.1.2.1.3` | **serial number** (octet 8 byte: 4 ASCII vendor + 4 byte biner) | `5A 54 45 47 00 8E EB 08` → `ZTEG008EEB08` |
| **`.7`** | `…20.2.1.2.1.7` | **flag online biner**: `1` = Working, `2` = Offline — kini **tidak dibaca kode**; status diganti phase `…10.2.3.8.1.4` (§4.1) | — |
| `.8` | `…20.2.1.2.1.8` | **model ONU** (ada untuk semua vendor) | `F641`, `HG8145V5`, `HG8145X6-10` |
| `.15` | `…20.2.1.2.1.15` | model ONU (duplikat `.8` di perangkat uji) | `F641` |
| `.18` | `…20.2.1.2.1.18` | Timeticks; **jangan dipakai** — 0 untuk banyak ONU yang jelas online | — |

### 4.0 Rx power ONU — `…500.20.2.2.2.1.10` (terverifikasi)

**`1.3.6.1.4.1.3902.1082.500.20.2.2.2.1.10`**, index **`{ifIndex}.{onuId}.{onuPort}`** — kembaran
langsung OID C300 (`.1012.3.50.12.1.1.10`): kolom akhir `.10` sama, index 3-level sama, skala raw sama
(`raw*0.002-30`, ditangani `convertOnuRxPowerToDbm()`). Karena itu C600 **berbagi jalur parsing C300** —
tak ada cabang khusus di `onuRxPowers()`.

| ONU | raw | dBm | status (`.7`) |
|---|---|---|---|
| 1 | 6227 | −17,55 | online |
| 2 | 5340 | −19,32 | online |
| **3** | **65535** | **N/A (sentinel)** | **offline** |
| 4 | 6901 | −16,20 | online |
| 8 | 65535 | N/A | offline |

Cocok **8/8** dengan kolom status: setiap ONU online punya raw wajar, setiap ONU offline mengembalikan
sentinel `65535` (sudah ditangani `convertOnuRxPowerToDbm()`).

**Ada dua metrik Rx berbeda di C600 — jangan tertukar:**

| | OID | Arti | Index | Skala | Sentinel |
|---|---|---|---|---|---|
| **Rx ONU** (dipakai app) | `…500.20.2.2.2.1.10` | daya **downstream** yang diterima ONU | `{ifIndex}.{onuId}.{onuPort}` | `raw*0.002-30` | `65535` |
| Rx OLT | `…500.1.2.4.2.1.2` (`zxAnPonRxOpticalPower`) | daya **upstream** yang diterima OLT dari ONU | `{ifIndex}.{onuId}` | `raw/1000` (milli-dBm) | `-80000` |

Keduanya hidup dan sama-sama cocok 8/8 dengan status. Fisikanya konsisten: downstream (−16..−19 dBm)
lebih kuat dari upstream (−23..−26 dBm) karena laser OLT lebih besar dari laser ONU. App memakai
**Rx ONU** supaya artinya seragam dengan C300 (yang dulu diverifikasi vs CLI `show pon power onu-rx`).
Rx OLT belum diekspos di UI — kalau nanti dipasang, beri label berbeda, jangan campur.

> Semantik `raw/1000` + `-80000` di kode C600 lama sebenarnya **benar** — itu deskripsi Rx OLT. Yang
> salah cuma OID-nya (`…500.10.2.11.1.2`, tak ada di perangkat).

Cakupan kolom `.1`/`.3`/`.7`/`.8` = 18/18 ONU di port uji (penuh), jadi `.8` aman dipakai sebagai gerbang
walk (`$types`). `onuId` **tidak kontigu** — di satu port ditemukan id `1..15, 80, 81, 87`.

### 4.1 Status online: bukti kolom `.7`, lalu phase `.10.2.3.8.1.4` (dipakai kode)

Tanpa akses CLI, semantik kolom state dibuktikan lewat **korelasi counter trafik**: tabel counter per-ONU
`1.3.6.1.4.1.3902.1082.500.10.2.3.2.2.1.1.{ifIndex}.{onuId}` (Counter64) di-snapshot dua kali, lalu ONU yang
counter-nya naik pasti online.

| Port | Hasil |
|---|---|
| `1/3/1` (18 ONU) | 18/18 cocok — `.7=1` ⇔ counter naik, `.7=2` ⇔ counter diam |
| `1/3/2` (33 ONU) | 32/33 cocok; 1 ONU `.7=1` tapi counter diam = ONU online yang sedang sepi (arah error yang wajar) |

Yang menentukan: **nol kasus** counter naik padahal `.7=2` — itu arah yang akan menggugurkan pemetaan.
Kolom `.7` hanya pernah bernilai 1 atau 2; ia **flag online biner**, bukan enum fase ZTE.

**Sejak Juli 2026 kode tidak lagi membaca `.7`.** Status & phase C600 diambil dari tabel state
**`1.3.6.1.4.1.3902.1082.500.10.2.3.8.1.4`** (index `{ifIndex}.{onuId}` yang sama), diverifikasi live terhadap CLI
`show gpon onu detail-info` (Phase state): `2` = LOS, `4` = Working, `5` = DyingGasp, `7` = OffLine. `4` cocok
persis dengan `.7 = 1` di **1343/1343 ONU** (0 selisih), jadi deteksi online tak berubah — bedanya ONU offline kini
membawa alasannya. `decodePhaseState()` cabang C600 (PHP) dan `decodePhaseStateC600()` (Go) memetakan keempat nilai
itu; lainnya `Unknown`. Online = `phase == 4` (`OltSnmpClient::C600_PHASE_WORKING`). Ejaan `OffLine` (L besar)
sengaja dipertahankan dari CLI — frontend menanganinya (`phaseStateLabel()`, filter Offline Monitoring ONU).

> **Jangan** mengarang kode lain di sini. Enum 7-nilai yang dulu ada di kode (`1=Logging … 7=Offline`) tidak
> berasal dari perangkat mana pun (efeknya seluruh ONU C600 terbaca offline); enum di atas berasal dari tabel state
> `.10.2.3.8.1.4` yang dicocokkan ke CLI, bukan dari `.7`.

### 4.2 Kolom yang menyesatkan

Kolom `.4` (nilai `0`/`2`/`65535`) **bukan** state: nilainya berkorelasi sempurna dengan **vendor**
(ZTEG/ZKXX → `2`, HWTC → `0`), bukan dengan online/offline. Sempat jadi kandidat state dan gugur saat diuji.

---

## 5. Status pemetaan C600 (per 1 Okt 2026)

Dulu bagian ini berjudul "Yang BELUM terpetakan" — sebagian besar kini sudah terbaca dari perangkat asli (Juli 2026)
dan dipakai kode PHP (`OltSnmpClient`) **dan** poller Go (`cmd/kv-snmp-poller/main.go`):

| Kebutuhan | Status | Sumber / dampak di aplikasi |
|---|---|---|
| Nama ONU via SNMP | ✅ **baca** | `…1082.500.10.2.3.3.1.2` — nama pelanggan asli walau CLI menyensor `********` ([ZTE_C600_Configured_ONU_Name_SNMP_Discovery.md](ZTE_C600_Configured_ONU_Name_SNMP_Discovery.md)) |
| Deskripsi ONU via SNMP | ✅ **baca** (mentah) | `…10.2.3.3.1.3` — metadata SmartOLT `zone_…_authd_…`, disimpan apa adanya |
| Admin-state | ✅ **baca** | `…10.2.3.8.1.1` — `1` enable / `2` disable (27 ONU `2` dicocokkan ke CLI) |
| Phase / status | ✅ | `…10.2.3.8.1.4` (§4.1) |
| Last-down cause | tak ada tabel terpisah | 12 kolom tabel state di-probe, tak ada kolom penyebab. Saat ONU **offline**, `last_down_cause` diisi dari phase (LOS/DyingGasp/OffLine); ONU online tetap `Unknown` |
| Rx power ONU | ✅ | §4.0 (`…500.20.2.2.2.1.10`) |
| ONU unconfigured | ✅ | `…1082.500.2.2.11.2.1.2` (serial) + `.8` model + `.10` firmware ([ZTE_C600_Unconfigured_ONU_SNMP_Discovery.md](ZTE_C600_Unconfigured_ONU_SNMP_Discovery.md)) |
| Kartu / chassis | ✅ | SNMP `zxAnCardTable` ([ZTE_C600_Card_PON_Uplink_SNMP_Inventory.md](ZTE_C600_Card_PON_Uplink_SNMP_Inventory.md)) |
| Provisioning | ✅ **aktif** | Model B / SmartOLT TR069 (§11); WAN pppoe/dhcp/static/bridge ditolak |
| **Tulis** nama / admin-state via SNMP SET | ❌ belum diuji | `ZteRemoteOnuService` sengaja memakai konstanta tulis `null` untuk C600 → `supports_onu_info_write` & `supports_onu_toggle` = `false`. OID baca di atas **jangan** dipakai untuk SET sebelum diuji ke ONU uji |
| Reconfigure ONU (Configure/Copy/TR069 Massal) | ❌ | builder delta gaya C300; C600 model vport → `supports_onu_config_write=false` (Configure = baca-saja) |
| Bind ONU (`registration-method sn`) | ❌ belum dicek | `supports_onu_replace=false` |
| Matikan/nyalakan port PON | ❌ belum dicek | `supports_port_admin_write=false` |

Prinsipnya tetap: lebih baik field kosong / capability mati yang jujur daripada OID atau perintah tebakan. Membuka tulis
SNMP atau perintah CLI baru di C600 butuh uji di perangkat asli (context-help `?` polos — lihat peringatan executor
di guide ZTE §5.6b — atau MIB resmi ZTE `zxAccessNode`, bukan PDF pihak ketiga, lihat §7).

---

## 6. Matriks capability C600

Dari [`SmartOltSupport::capabilities(DRIVER_ZTE, $olt)`](../app/Support/SmartOltSupport.php) saat `isC600()` true:

| Flag | C300/C320 | C600 | Alasan |
|---|---|---|---|
| `vendor_family` | `ZTE GPON` | `ZTE GPON (C600)` | — |
| `port_name_prefix` | `gpon-olt_1` | `gpon_olt-1` | eja CLI beda per-family (§3.1) |
| `onu_interface_pattern` | `gpon-onu_1/%d/%d:%d` | `gpon_onu-1/%d/%d:%d` | **3-tier**, bukan 4-tier |
| `supports_snmp_rx` / `supports_cli_rx` | `true` | `true` | Rx ONU terpetakan (§4.0) |
| `supports_cli_onu_detail` | `true` | `true` | `show gpon onu detail-info gpon_onu-…` terverifikasi live |
| `supports_cli_onu_configure` | `true` | `true` | Configure dibuka **baca-saja** (§11a) |
| `supports_onu_config_write` | `true` | **`false`** | builder delta gaya C300; C600 pakai model vport |
| `supports_provisioning` | `true` | **`true`** | Model B / SmartOLT TR069 (§11) |
| `supports_reboot` (`reboot_mode=cli`) | `true` | `true` | `pon-onu-mng gpon_onu-…` → `reboot` (belum tercatat diuji di C600) |
| `supports_onu_delete` | `true` | `true` | `no onu {id}` di `interface gpon_olt-…` (belum tercatat diuji di C600) |
| `supports_onu_replace` | `true` | **`false`** | `registration-method` belum dicek di C600 |
| `supports_separate_description` | `true` | `false` | jalur tulis SNMP deskripsi tak ada |
| `supports_onu_info_write` (`description_mode=snmp`) | `true` | **`false`** | OID tulis nama belum diuji |
| `supports_onu_toggle` | `true` | **`false`** | OID tulis admin-state belum diuji |
| `supports_config_save` | `true` | `true` | `write` di `ZXAN#` (terbukti di registrasi live pertama) |
| `supports_port_description_write` | `true` | `true` | CLI `interface gpon_olt-…` → `description …` → `end` → `write` (belum tercatat diuji di C600) |
| `supports_port_admin_write` | `true` | **`false`** | `shutdown` belum dicek di C600 |
| `rx_source_label` | `Rx ONU (SNMP)` | `Rx ONU (SNMP)` | — |

---

## 7. Kenapa OID C600 yang lama salah (jangan diulang)

Sebelum 15 Juli 2026 kode memakai OID ini — **semuanya dijawab *No Such Object* oleh C600 asli**:

| Konstanta lama | OID lama | Kenyataan |
|---|---|---|
| `C600_ONU_TYPE` | `…1082.500.10.2.3.1.1` | cabang `10.2.3.1` tak ada (yang ada `10.2.3.2.2` = counter) |
| `C600_ONU_NAME` | `…1082.500.10.2.3.1.2` | idem (yang benar kini: `…10.2.3.3.1.2`, §5) |
| `C600_ONU_SN` | `…1082.500.10.2.3.1.6` | idem |
| `C600_ONU_ADMIN_STATE` | `…1082.500.10.2.8.1.1` | cabang `10.2.8` **tak ada sama sekali** (yang benar kini: `…10.2.3.8.1.1`) |
| `C600_ONU_PHASE_STATE` | `…1082.500.10.2.8.1.4` | idem (yang benar kini: `…10.2.3.8.1.4`, §4.1) |
| `C600_ONU_RX_POWER` | `…1082.500.10.2.11.1.2` | cabang `10.2.11` **tak ada sama sekali** (yang benar: `…500.20.2.2.2.1.10`, §4.0) |
| `C600_UNCFG_OIDS` | `…1082.500.10.2.2.1.2` | tak ada (yang benar kini: `…500.2.2.11.2.1.2`, §5) |

Akibatnya C600 mana pun terbaca **0 ONU** — diam-diam, tanpa error.

Pola OID lama tampak seperti versi **ter-geser** dari yang asli (`500.10.2.8.1.1` vs nyata `500.20.2.8.2.1.1`),
ciri khas dokumen turunan, bukan pembacaan perangkat. Beberapa PDF di `docs/` (`ZTE ZXA10 C600 SNMP OID
Management Guide.pdf`, `…SNMP OIDs for ONU Optical Power.pdf`, `SNMP Discovery Guide for ZTE C600
Unconfigured ONUs.pdf`) memuat klaim OID sejenis — **perlakukan sebagai tak terverifikasi**, bukan referensi.
Aturannya sama seperti Section 6/7 guide C300: dokumen ≠ perangkat.

**Prinsip:** OID vendor hanya masuk kode setelah dibaca dari perangkat asli. Kalau tak bisa diverifikasi,
biarkan `null` dan matikan capability-nya — bukan diisi nilai yang "sepertinya benar".

### 7.1 Cara yang benar memakai dokumen MIB

Dokumen MIB **berguna sebagai sumber hipotesis, bukan sumber kebenaran**. Rx ONU (§4.0) ketemu persis
lewat alur ini:

1. **Riset** — [`ZTE-AN-PON-BASE-MIB` di mibbrowser.online](https://mibbrowser.online/mibdb_search.php?mib=ZTE-AN-PON-BASE-MIB)
   dan [oid-base.com](https://oid-base.com/get/1.3.6.1.4.1.3902.1082.500) memberi nama & struktur cabang
   (`zxAnPon(500)`, `zxAnPonRxOpticalPower`, `zxAnOpticalModuleMib`) → jadi daftar **kandidat**.
2. **Uji ke perangkat** — `snmpwalk` tiap kandidat. Yang hidup dipakai, yang *No Such Object* dibuang.
3. **Falsifikasi** — cocokkan dgn fakta independen: ONU yang **sudah terbukti offline** harus balas
   sentinel, yang online harus balas nilai wajar. Cocok 8/8 → baru masuk kode.

Langkah 3 yang membedakan riset dari tebakan. OID C600 lama juga "berasal dari dokumen" — bedanya tak
pernah diuji ke perangkat, dan itulah kenapa salah semua.

---

## 8. Performa: walk ONU C600 wajib scoped

Tabel ONU C600 di-index ifIndex IF-MIB asli, jadi **tak ada** tabrakan prefix seperti C320 (ONU slot 1
nyasar ke slot 2) — walk per-port aman dan `zteEncodeIfIndex()` mereproduksi prefix-nya persis.

`portOnusSnapshot()` kini selalu scoped untuk semua family ZTE. Terukur live di port `1/3/1` (18 ONU):

| | Latensi |
|---|---|
| full-OLT walk (perilaku C600 lama) | **~151.000 ms** |
| scoped walk (sekarang) | **~1.900 ms** |

---

## 9. Hasil akhir di perangkat asli

```
port 3/1 : 18 ONU, 13 online, 1934 ms
port 3/2 : 33 ONU, 27 online, 3866 ms

onu 1   ZTEG008EEB08   F641          Working  online=true
onu 2   HWTC0AE69DAE   HG8145V5      Working  online=true
onu 3   HWTC2390A3AF   HG8145X6-10   Offline  online=false
onu 4   ZTEGC1F9D600   F660V5.2      Working  online=true
```

Jumlah online (13/18, 27/33) cocok dengan hasil korelasi counter di §4.1 — dua metode independen, angka sama.
(Snapshot 15 Jul 2026, sebelum phase kaya dipakai: kini ONU offline berlabel `LOS`/`DyingGasp`/`OffLine` dan kolom
nama terisi dari SNMP.)

---

## 10. Kartu & slot C600 (`show card`)

```
Shelf Slot CfgType CardName        Port HardVer Status
1     3    GFGL   GFGL            16   V1.8.0  INSERVICE
1     4    GFGL   GFGL            16   V1.8.0  INSERVICE
1     5    GFGM   GFGM            16   V1.8.0  INSERVICE
1    10    SFUB   C0ISFUB         4    V1.8.0  INSERVICE
1    11    SFUB   SFUB            4              OFFLINE
1    17    GFGN   GFGN            16             OFFLINE
1    18    PRVR   PRVR            0    V1.8.0  INSERVICE
1    20    PRVR   PRVR            0    V1.8.0  INSERVICE
1    21    FCVDE-I FCVDE-I        0    V1.8.0  INSERVICE
```

| CfgType | Peran | Dipetakan di `ZteCardUplinkService` |
|---|---|---|
| `GFGL`, `GFGM`, `GFGN` | kartu GPON 16 port (slot 3/4/5/17) | `C600_GPON_CARDS` |
| `SFUB` | switch/uplink, 4× `xgei-1/{slot}/{port}` (slot 10/11) | `C600_XGEI_CARDS` |
| `PRVR`, `FCVDE-I` | power/service card, 0 port | — (diabaikan) |

Konfirmasi silang: SNMP menemukan **64 port PON** di slot 3/4/5/17 (16 masing-masing) — persis cocok
dengan `show card`. Kode C600 lama hanya mengenal `GFGH/GFXH/GFXL` (GPON) dan `XGEI/SFUL/SFUM` (uplink) —
**tak satu pun muncul di C600 asli**; kode-kode itu dipertahankan tapi kini bersama yang terverifikasi.

> Tambahkan kode kartu baru **hanya** setelah terlihat di `show card` perangkat nyata.

Catatan implementasi: output `show card` C600 **tidak ter-parse** oleh `ZteCardUplinkService::parseCards()` (format beda
C300/C320), jadi tombol Refresh Hardware C600 membaca daftar kartu dari SNMP `zxAnCardTable` `.1082.10.1.2.4.1`
(`OltSnmpClient::cardInventory()`, detail di [ZTE_C600_Card_PON_Uplink_SNMP_Inventory.md](ZTE_C600_Card_PON_Uplink_SNMP_Inventory.md));
CPU/memori per kartu dari CLI `show processor` (§11a). Tabel `show card` di atas tetap jadi rujukan kode kartu.

---

## 11. Provisioning C600 — Model B / SmartOLT TR069 (AKTIF)

> **Status: AKTIF sejak Juli 2026** (`supports_provisioning=true` untuk C600). Builder
> [`ZteC600ProvisioningScriptBuilder`](../app/Services/ZteC600ProvisioningScriptBuilder.php) mereproduksi **persis**
> running-config ONU yang sudah jalan di C600 lapangan (config-mode `show this` atas tiga ONU asli). Registrasi live
> pertama: semua perintah config diterima; hanya `write` yang gagal karena dikirim dari mode config →
> diperbaiki dengan `end` sebelum `write` (§11a).

C600 **bukan** C300 dengan nama interface lain. Bedanya struktural:

| | C300 / C320 | C600 (Model B) |
|---|---|---|
| T-CONT | `tcont 1 name 1 profile P` | `tcont 1 profile P` + `tcont 2 profile P` (**tanpa** `name`) |
| GEM port | `gemport 1 name 1 tcont 1` | `gemport 1 name internet tcont 1` + `gemport 2 name mgmt tcont 2` |
| Mode vport | — | default (**tanpa** `vport-mode manual` / `vport-map` — itu Model A dokumen lama, tak terlihat di lapangan) |
| `service-port` | di `interface gpon-onu_…` | di **`interface vport-1/{slot}/{port}.{id}:1`** tersendiri, **tanpa** ingress/egress inline; laju downstream opsional lewat `qos traffic-policy {P} direction egress` |
| `service` (pon-onu-mng) | `service N gemport 1 cos 0 vlan V` | `service vlan{V} gemport {1\|2} vlan {V}` (**tanpa** `cos`), dua layanan: internet + manajemen |
| Manajemen | — | `mgmt-ip {ip} {mask} vlan {mgmt} priority {p} route 0.0.0.0 0.0.0.0 {gw} host {h}` + `veip 1 port 1232 ipv4 host {h}` |
| TR069 | 2 baris (`state unlock`, lalu `acs …`) | **1 baris** `tr069-mgmt 1 state unlock acs … validate basic username … password … tag pri {p} vlan {mgmt}` (`tag pri`, bukan `pr1`) |
| WAN | `wan-ip 1 mode pppoe/dhcp/static …` | hanya `wan 2 service tr069`; pppoe/dhcp/static/bridge **ditolak** (`RuntimeException`) |
| Simpan | tidak otomatis | skrip diakhiri `end` → `write` |

Script yang dihasilkan builder (nilai contoh, bukan data produksi):

```
configure terminal

interface gpon_olt-1/{slot}/{port}
onu {id} type {ONU_TYPE} sn {SN}
exit

interface gpon_onu-1/{slot}/{port}:{id}
name {NAMA}
description {DESKRIPSI}
tcont 1 profile {TCONT_INTERNET}
tcont 2 profile {TCONT_MGMT}
gemport 1 name internet tcont 1
gemport 2 name mgmt tcont 2
exit

pon-onu-mng gpon_onu-1/{slot}/{port}:{id}
mgmt-ip {MGMT_IP} {MASK} vlan {VLAN_MGMT} priority {P} route 0.0.0.0 0.0.0.0 {GATEWAY} host {H}
[security-mgmt 1 state enable mode forward protocol web https]   # opsional, Remote ONT
[security-mgmt 5 state enable mode forward protocol web https]
service vlan{VLAN_DATA} gemport 1 vlan {VLAN_DATA}
service vlan{VLAN_MGMT} gemport 2 vlan {VLAN_MGMT}
veip 1 port 1232 ipv4 host {H}
wan 2 service tr069
tr069-mgmt 1 state unlock acs {ACS_URL} validate basic username {U} password {P} tag pri {P} vlan {VLAN_MGMT}
exit

interface vport-1/{slot}/{port}.{id}:1
service-port 1 user-vlan {VLAN_DATA} vlan {VLAN_DATA}
[qos traffic-policy {POLICY} direction egress]
exit
end

write
```

- **Field wajib** (`REQUIRED` di builder + `OnuRegistrationService::c600Rules()`): slot, port, onu_id, SN, nama, ONU type,
  VLAN & T-CONT internet, VLAN & T-CONT manajemen (VLAN mgmt harus beda dari VLAN data), mgmt IP/mask/gateway, ACS
  URL/username/password. `priority` & `host` default `2`.
- **Deskripsi**: kolom Deskripsi eksplisit menang; kosong → `zone_{zona}_authd_{YYYYMMDD}` (zona dari pilihan Zone);
  tanpa zona → nama. Maks 191 karakter, tanpa karakter kontrol. Semua nilai teks disanitasi (CR/LF/kontrol dibuang).
- **mgmt-IP otomatis** ([`C600MgmtPoolService`](../app/Services/Zte/C600MgmtPoolService.php), rute
  `smartolt.register.mgmt-pool`): `terminal length 0` + `show running-config | include mgmt-ip` dibaca sampai prompt
  kembali, baris yang terpotong line-wrap di-un-wrap, pool (mask/gateway/vlan/priority/host) diturunkan dari config OLT,
  lalu dipilih IP bebas terendah (kecuali network/gateway/broadcast, IP terpakai di OLT, dan registrasi NMS terbaru).
  Cache 10 menit.
- **Preset ACS dari OLT**: tabel SNMP `…1082.500.20.2.14.2.1` (`.2` URL, `.4` username, `.5` password — terbaca polos
  lewat read community), baris pertama yang terisi, supaya ONU baru memakai ACS yang sama dengan ONU yang sudah ada.
- Dropdown ONU Type & T-CONT dari katalog profil OLT; model hasil discovery unconfigured ter-preselect.

---

## 11a. CLI C600 yang dipakai kode

Semua baris diverifikasi live di C600 (Juli 2026) kecuali yang ditandai.

| Kebutuhan | Perintah C600 | Catatan |
|---|---|---|
| Detail ONU | `show gpon onu detail-info gpon_onu-1/{s}/{p}:{id}` | jalan; `Name`/`Description` disensor `********` oleh firmware (nama dibaca via SNMP) |
| Running-config ONU (Configure, baca-saja) | `show running-config xpon \| begin interface gpon_onu-…` | `show running-config interface …` → `%Error 140303 Invalid input`; `show onu running config …` → `%Error 140301 Ambiguous` |
| Running-config cepat | `configure terminal` → `interface {iface}` → `show this` → `exit` → `pon-onu-mng {iface}` → `show this` → `exit` → `exit` | **hanya** bila ONU sudah ada di cache — `interface gpon_onu-…` untuk ONU yang tak ada bisa membuat entri baru |
| Detail port PON / uplink | `show interface gpon_olt-1/{s}/{p}` · `show interface xgei-1/{s}/{p}` | `port-status` tak ada di C600 |
| Optik port | `show optical-module-info {iface}` | **tanpa** kata `interface` (beda C300/C320) |
| VLAN uplink | `show vlan port {iface}` | format identik C300/C320; form tulis ADD & TAG VLAN disembunyikan di C600 |
| CPU/memori kartu | `show processor` | baris `PFU-1/{slot}/0` / `MPU-1/{slot}/0` (SNMP `.1015` tak ada di C600) |
| Rx per port (CLI) | `show pon power onu-rx gpon_olt-1/{s}/{p}` | parser punya cabang C600 (ejaan `gpon_onu-`); **belum tercatat diuji live** — Rx C600 utama via SNMP (§4.0) |
| Scan mgmt-IP | `show running-config \| include mgmt-ip` | §11 |
| Simpan | `write` | **hanya di `ZXAN#`**; dari mode config → `%Error 140303 Invalid input` → skrip selalu `end` dulu |

Telnet ke C600 bisa diblok ACL manajemen perangkat (port 22/23 menerima TCP tapi tanpa banner) walau SNMP lancar — itu
setelan OLT, bukan bug aplikasi.

---

## 12. Cara mengulang verifikasi

```bash
# identifikasi
snmpwalk -v2c -c <community> udp:<ip>:<port> 1.3.6.1.2.1.1

# nama port PON (ifName, BUKAN ifDescr)
snmpwalk -v2c -c <community> udp:<ip>:<port> 1.3.6.1.2.1.31.1.1.1.1 | grep gpon

# tabel ONU satu port (ifIndex 285278977 = slot 3 port 1)
snmpbulkwalk -v2c -c <community> udp:<ip>:<port> -On 1.3.6.1.4.1.3902.1082.500.20.2.1.2.1.3.285278977   # SN
snmpbulkwalk -v2c -c <community> udp:<ip>:<port> -On 1.3.6.1.4.1.3902.1082.500.20.2.1.2.1.7.285278977   # flag online lama
snmpbulkwalk -v2c -c <community> udp:<ip>:<port> -On 1.3.6.1.4.1.3902.1082.500.10.2.3.8.1.4.285278977   # phase (dipakai kode)
snmpbulkwalk -v2c -c <community> udp:<ip>:<port> -On 1.3.6.1.4.1.3902.1082.500.10.2.3.3.1.2.285278977   # nama ONU
snmpbulkwalk -v2c -c <community> udp:<ip>:<port> -On 1.3.6.1.4.1.3902.1082.500.20.2.2.2.1.10.285278977  # Rx ONU
snmpbulkwalk -v2c -c <community> udp:<ip>:<port> -On 1.3.6.1.4.1.3902.1082.500.1.2.4.2.1.2.285278977     # Rx OLT

# ground truth state: snapshot counter 2x, yang naik = online
snmpwalk -v2c -c <community> udp:<ip>:<port> -On 1.3.6.1.4.1.3902.1082.500.10.2.3.2.2.1.1.285278977
```

> **Pakai `snmpbulkwalk`, bukan `snmpwalk`.** `snmpwalk` mengirim GETNEXT satu-satu dan selalu timeout di
> link ber-latensi; bulkwalk (GETBULK) ~18 baris/detik pada perangkat uji. Walk penuh `3902.1082` tetap
> **mati di tengah** (`Timeout: No Response`) walau dgn `-t 8 -r 4` — jadi **selalu scope ke satu port**
> dan petakan struktur cabang dengan `snmpgetnext` bertahap.
>
> ⚠️ **Walk yang mati ≠ cabang kosong.** `snmpbulkwalk` bisa exit sukses tapi berhenti di tengah; kalau
> hasilnya dipakai untuk menyimpulkan "OID X tidak ada", kesimpulannya salah. Selalu cek apakah output
> memuat `Timeout|No Response` dan sampai OID mana ia berjalan.
>
> ⚠️ **Saat enumerasi cabang, jangan meloncat.** Rx OLT (`500.1.2.4.2.1.2`) & `zxAnPonRemoteOnuMib`
> (`500.3`) sempat terlewat karena loop enumerasi memakai langkah `1, 2, 5, 10, 15, …` — `getnext(500.5)`
> melompati `500.3`/`500.4`. Iterasi berurutan.
