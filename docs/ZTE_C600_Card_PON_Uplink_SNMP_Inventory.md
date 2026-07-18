# ZTE C600 — Inventaris Kartu (Chassis) via SNMP `zxAnCardTable`

Sumber data untuk **Visualisasi Chassis / Refresh Hardware** pada OLT ZTE C600. CLI `show card`
di C600 tidak ter-parse oleh `ZteCardUplinkService::parseCards()` (format berbeda dari C300/C320),
sehingga daftar kartu dibaca langsung dari SNMP.

Semua OID di bawah **diverifikasi live** ke `ZXA10 C600 V1.2.2` (LAS GALERAS, `10.100.2.2`, SNMPv2c)
pada 18 Jul 2026 — cocok 100% dengan hasil `snmpbulkwalk`.

## Tabel

`zxAnCardTable` = `1.3.6.1.4.1.3902.1082.10.1.2.4.1`

Index = `{rack}.{shelf}.{slot}` (di C600 rack & shelf selalu `1`, jadi suffix = `1.1.{slot}`).

### Kolom yang dipakai

| Kolom | OID (relatif) | Isi | Contoh live |
|------:|---------------|-----|-------------|
| `.2`  | `…4.1.2`  | Kode tipe kartu yang **dikonfigurasi** (INTEGER) | `659974` |
| `.4`  | `…4.1.4`  | Model kartu yang **terdeteksi** (STRING; kosong bila board offline) | `"GFGL"` / `""` |
| `.5`  | `…4.1.5`  | Oper-status (enum INTEGER) | `1` / `4` |
| `.7`  | `…4.1.7`  | Jumlah port | `16` |
| `.26` | `…4.1.26` | Versi board → `hard_ver` | `"V2.5.0"` |
| `.31` | `…4.1.31` | Versi software → `soft_ver` | `"V1.0.9"` / `"N/A"` |

Kolom lain yang ada tapi **belum dipakai**: `.3` kode tipe terdeteksi, `.14` serial, `.23`/`.32` versi
tambahan, `.9` CPU & `.11` memori (keduanya `0` di semua kartu live → cpu_load/mem_load dibiarkan `null`).

### Peta kode tipe (`.2`) → model

Diverifikasi live (semua slot di LAS GALERAS):

| Kode | Model | Jenis |
|-----:|-------|-------|
| 656131 | SFUB | Uplink (xgei) |
| 659973 | GFGM | GPON 16-port |
| 659974 | GFGL | GPON 16-port |
| 659979 | GFGN | GPON 16-port |
| 663810 | PRVR | Kartu daya/kontrol (0 port) |
| 665602 | FCVDE-I | Kartu daya/kontrol (0 port) |

Untuk slot yang board-nya offline (`.4` kosong), `cfg_type` diambil dari peta kode ini supaya tetap terisi.

### Oper-status (`.5`) → token status UI

Hanya `1` (inService) & `4` (hwOffline) yang terlihat live; sisanya mengikuti enum vendor.
Token status memakai kosakata parser CLI lama supaya `INACTIVE_CARD_STATUSES`
(`OFFLINE`/`EMPTY`/`PWROFF`) tetap menggerbang hitungan port aktif/nonaktif & pewarnaan.

| Enum | Arti | Token |
|-----:|------|-------|
| 1 | inService | INSERVICE |
| 3 | hwOnline | INSERVICE |
| 34 | powerSaving | INSERVICE |
| 5 | configuring | PROV |
| 2 | notInService | OFFLINE |
| 4 | hwOffline | OFFLINE |
| 6 | configFailed | OFFLINE |
| 7 | typeMismatch | OFFLINE |
| 8 | deactivated | OFFLINE |
| 9 | faulty | OFFLINE |
| 10 | invalid | OFFLINE |
| 12 | unauthorized | OFFLINE |
| 13 | adminDown | OFFLINE |
| 11 | noPower | PWROFF |
| lain | — | OFFLINE (default) |

## Inventaris live LAS GALERAS (18 Jul 2026)

| Slot | Kode | Model | Status | Port | Board | Software |
|-----:|-----:|-------|--------|-----:|-------|----------|
| 3  | 659974 | GFGL | inService  | 16 | V2.5.0 | V1.0.9 |
| 4  | 659974 | GFGL | inService  | 16 | —      | —      |
| 5  | 659973 | GFGM | inService  | 16 | V3.0.0 | V1.5.0 |
| 10 | 656131 | SFUB | inService  | 4  | V1.6.0 | N/A    |
| 11 | 656131 | SFUB | **hwOffline** | 4  | —   | —      |
| 17 | 659979 | GFGN | **hwOffline** | 16 | —   | —      |
| 18 | 663810 | PRVR | inService  | 0  | V1.0.0 | N/A    |
| 20 | 663810 | PRVR | inService  | 0  | —      | —      |
| 21 | 665602 | FCVDE-I | inService | 0 | —     | —      |

## Implementasi

- `App\Services\Snmp\OltSnmpClient::cardInventory($olt)` — walk 6 kolom di atas → baris kartu.
- `App\Services\ZteCardUplinkService::refreshCardStatus($olt)` — bercabang: C600 → `cardInventory()`,
  C300/C320 → CLI `parseCards(show card)`. Persist ke `smartolt_card_status` & visualisasi identik.
- Test: `tests/Unit/C600CardInventoryTest.php`.
