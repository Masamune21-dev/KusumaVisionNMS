# Fan & Suhu OLT ZTE (C300 / C320 / C600) — Riset dan Rencana Implementasi

Status: **Fase 1 (baca-saja) sudah diimplementasikan** — tabel + service + kartu UI. Verifikasi
awal riset dilakukan baca-saja pada perangkat produksi. Kontrol fan (fase 2) belum dibuat.
Tanggal riset: 12 November 2026; implementasi fase 1: 13 November 2026.

Dokumen ini menjawab dua kebutuhan:

1. Menampilkan kecepatan fan (per unit), mode kontrol, level, dan suhu lingkungan/chassis.
2. Mengendalikan fan dari NMS (mode otomatis vs kecepatan tetap, level/percent, ambang suhu).

Kesimpulan singkat:

- **C300/C320**: fan **bisa dibaca dan dikendalikan lewat SNMP** (subtree `zxAnEnvFan`, `3902.1015.2.1.3.10.10`), dengan CLI `show fan` sebagai verifikasi/pelengkap.
- **C600**: firmware yang dipakai (`V1.2.2`) **tidak mengekspos tabel fan ZTE lewat SNMP** (subtree `3902.1082.10.10.2.4` → `No Such Object`). Kecepatan fan hanya terbaca dari CLI `show fan`; kontrol harus lewat CLI.
- Kontrol fan menurunkan pendinginan, jadi implementasi wajib memakai guardrail: default auto, konfirmasi eksplisit, izin `olt.write-config`, audit log, dan alarm suhu.

---

## 1. Hasil verifikasi perangkat (12 Nov 2026)

Semua pengujian di bawah **baca-saja** (`snmpget`/`snmpbulkwalk`, dan CLI `show fan`). Tidak ada
SNMP SET atau perintah konfigurasi yang dijalankan.

| ID | Nama | Alamat | SNMP | CLI | Hasil |
|--:|:--|:--|:--|:--|:--|
| 1 | OLT-C320-CONTOH-A | `192.0.2.10:161` | timeout | `No route to host` | **Tidak terjangkau dari server NMS** (`last_test_result` juga gagal). Bukan masalah OID. |
| 2 | OLT-C300-CONTOH-B | `192.0.2.11:161` | v2c OK | telnet OK | Fan via SNMP + CLI terverifikasi. |
| 3 | OLT-C320-CONTOH-C | `198.51.100.20:1161` | v2c OK | telnet `1162` OK | Fan via SNMP + CLI terverifikasi. |
| 4 | OLT-C320-CONTOH-D | `198.51.100.21:16161` | v2c OK | telnet `16162` OK | Fan via SNMP + CLI terverifikasi. |
| 5 | OLT-C600-CONTOH-E | `203.0.113.30:1161` | v2c OK | telnet `1162` OK | **Fan tidak ada di SNMP**; CLI `show fan` OK (6 fan, persen). |

Catatan operasional: `snmpbulkwalk` Net-SNMP 5.9 **tidak menerima `-p`**; port non-161 harus
ditulis sebagai `HOST:PORT`. Timeout yang pernah terlihat pada C320/C600 berasal dari kesalahan
cara memanggil ini, bukan dari perangkat.

---

## 2. Peta OID C300 / C320 (terverifikasi)

Subtree: `1.3.6.1.4.1.3902.1015.2.1.3.10.10` (`zxAnEnvFan`).

### 2.1 Skalar mode & ambang

| OID (relatif `.1015.2.1.3.10.10`) | Arti | Nilai terlihat |
|:--|:--|:--|
| `.1.0` | `zxAnEnvFanSpeedControlMode` | `1` = `temperatureBasedAutoCtrl`, `2` = `fixSpeed` |
| `.2.0` … `.5.0` | level fan aktual/konfigurasi tambahan | `0` pada kedua C320 |
| `.6.0` | `lowSpeedShiftTemp` | C300 `10`, C320 `20` |
| `.7.0` | `stdSpeedShiftTemp` | `36` |
| `.8.0` | `highSpeedShiftTemp` | `44` |
| `.9.0` | `superSpeedShiftTemp` | `46` (C300) / `51` (C320) |
| `.11.0` | percent level 1 | `20` |
| `.12.0` | percent level 2 | `35` |
| `.13.0` | percent level 3 | `50` |
| `.14.0` | percent level 4 | `75` |
| `.1015.2.1.3.2.0` | suhu lingkungan (`zxAnEnvTemperature`) | C300 `25`, C320 `39`/`45` °C |

Ambang `.6`–`.9` adalah batas suhu antar tingkat: di bawah `.6` level 1, lalu naik ke level 2/3/4
saat suhu melewati `.7`/`.8`/`.9`. Percent `.11`–`.14` adalah duty cycle target tiap level.

### 2.2 Tabel fan `zxAnEnvFanTable`

Base: `1.3.6.1.4.1.3902.1015.2.1.3.10.10.10.1`, indeks = nomor fan (mulai 1).

| Kolom | Arti | Contoh |
|:--|:--|:--|
| `.2.<idx>` | `confSpeedLevel` (1–4) | C300 `3`, C320-564 `2`, C320-1432 `3` |
| `.3.<idx>` | `actualSpeedLevel` | sama dengan conf saat normal |
| `.4.<idx>` | `adminStatus` (1 = up/enable) | `1` |
| `.5.<idx>` | `operStatus` (1 = normal) | `1` |
| `.6.<idx>` | `onlineStatus` (1 = online) | `1` |
| `.7.<idx>` | `actualSpeed` **RPM** | C300 `2500`, C320 `4500`, C320 `3246`/`3156` |

Jumlah fan yang diekspos:

- C300: 3 fan.
- C320: 2 fan.
- C320 (OLT lain): 2 fan.

Catatan penting: **`zxAnFanExMgmtTable` (`.1015.2.1.7.3.1.1`) tidak ada di C300** yang diuji
(`No Such Object`). Jangan memakai tabel itu sebagai sumber utama. `zxAnEnvFanTable` sudah cukup.

### 2.3 Kontrol lewat SNMP

Urutan aman untuk mode tetap:

1. `SET .1015.2.1.3.10.10.1.0` = `2` (`fixSpeed`).
2. `SET .1015.2.1.3.10.10.10.1.2.<fanIndex>` = `1..4` (per fan), atau set semua fan.

Kembali ke otomatis:

1. `SET .1015.2.1.3.10.10.1.0` = `1` (`temperatureBasedAutoCtrl`).

Yang **tidak** boleh dilakukan: menurunkan level saat suhu lingkungan sudah dekat ambang tinggi,
atau menetapkan `fixSpeed` lalu meninggalkan level rendah tanpa pemantauan.

`OltSnmpClient::set()` sudah tersedia (memakai `snmpset` / `snmp2_set`), sehingga kontrol C300/C320
tidak perlu jalur baru.

**Penting:** jalur SET di atas baru disimpulkan dari MIB (objek bertanda read-write/read-create)
dan **belum pernah dijalankan** ke perangkat produksi. Uji SET pertama harus memakai nilai yang
sama dengan kondisi berjalan (mode `fixSpeed` + level aktual saat itu), lalu segera dikembalikan
ke auto — lihat §6.2.

---

## 3. C600 — kondisi nyata dan batasannya

Perangkat: `ZXA10 C600, ZTE ZXA10 Software Version: V1.2.2`.

Hasil walk pada `3902.1082.10.10`:

- `2.4`, `2.4.10`, `2.4.11` → **`No Such Object`**. Tabel fan (`zxAnFanTable` /
  `zxAnFanTrayTable`) yang ada di dokumen MIB **tidak diimplementasikan/diekspos** pada firmware ini.
- `2.5.2` hanya berisi monitor lingkungan bernama: `2nd-Power-Monitor`, `Input-Fan-Monitor`,
  `Heat-Exchager`, `Distribution-Frame`, `Gate-Sensor`, `Smoke-Sensor` beserta status hidup/mati.
  **Tidak ada kecepatan fan, level, atau RPM.**
- `3902.1015…` (subtree C300) juga `No Such Object`.

Yang berfungsi: **CLI `show fan`**.

```
[shelf 1 LCC]:
Fan speed mini level: 1
Group 0: online
Fan speed mode: auto
Fan       name: FAN
Index      Status      Speed
  0    work normally   44%
  1    work normally   45%
  2    work normally   44%
  3    work normally   45%
  4    work normally   44%
  5    work normally   45%
```

Implikasi desain:

- C600 **wajib** memakai jalur CLI (`ZteCliProvisioningExecutor`) untuk membaca fan; jangan
  berharap SNMP.
- Kecepatan dalam **persen**, bukan RPM. Jangan menormalkan ke RPM.
- Kontrol C600 di manual: `fan speed auto`; mode fixed/level belum terdokumentasi jelas untuk
  firmware ini, sehingga **kontrol C600 harus ditandai belum didukung** sampai diuji dengan izin
  pemilik. Fase 1 C600 = baca-saja.

---

## 4. Referensi CLI (C300 / C320)

Terverifikasi `show fan` (C300 & C320) memuat:

- `FanControlType` (`temperature-control`)
- `TemperatureThreshold` (4 ambang)
- `FanSpeedLevelPercent` (4 percent)
- `HighTemperatureThreshold`, `CriticalTemperatureThreshold`
- `Environment Temperature`
- `Upper Fanboard Status`, `Fan SN`, `Fan FW Version`, `Fan Temperature` (sering `N/A.`)
- Tabel `FanUnitId / SpeedLevel / ActualSpeed(RPM)`

Perintah kontrol dari manual ZTE (belum dijalankan di produksi):

| Perintah | Arti |
|:--|:--|
| `fan control fixed-speed` | mode kecepatan tetap |
| `fan control temp_level <t1> <t2> <t3> <t4>` | ambang suhu bertingkat (10–70 °C) |
| `fan speed <0–4> [fanid <1–10>] [rackno 1] [shelfno 1]` | level per fan / semua fan |
| `fan speed-percent-set <l1> <l2> <l3> <l4>` | percent tiap level (10–97, 11–98, 12–99, 13–100) |
| `fan high-threshold <60–80>` | ambang suhu tinggi |
| `show card-temperature`, `show temperature`, `show card-power` | pembacaan suhu/daya |

Catatan: `fan speed 0` berarti berhenti/level terendah; jangan pernah dipakai sebagai “default”.
Level aman minimum yang disarankan untuk mode tetap: **2** (35% pada konfigurasi sekarang).

---

## 5. Desain implementasi yang diusulkan

### 5.1 Data & service

Tabel baru `smartolt_fan_statuses` (mengikuti pola `smartolt_card_statuses`):

```
id, snmp_olt_id, source (snmp|cli), rack, shelf,
mode (auto|fixed|unknown), mode_code,
temp_c, high_threshold_c,
conf_level, actual_level,
fan_index, fan_name, admin_status, oper_status, online_status,
speed_rpm, speed_percent, raw_line, refreshed_at, timestamps
unique(snmp_olt_id, rack, shelf, fan_index)
```

Service baru `App\Services\Olt\OltFanStatusService`:

- `read(SnmpOlt $olt): array` — C300/C320 lewat `OltSnmpClient::walk()` + `get()`; C600 lewat
  `ZteCliProvisioningExecutor::execute($olt, 'show fan')` dan parser khusus.
- `persist()` — upsert hasil baca, kembalikan ringkasan untuk UI.
- `setMode(SnmpOlt $olt, string $mode)` — C300/C320 SNMP SET; C600 ditolak dengan pesan jelas.
- `setLevel(SnmpOlt $olt, int $level, ?int $fanIndex)` — validasi 1–4, guardrail suhu, SET.

Parser CLI C600 harus tahan terhadap `--More--`, spasi ganda, dan baris `Password:` yang
disamarkan (`CliOutputSanitizer`).

### 5.2 Route & controller

Tambahkan di `routes/web.php` (pola yang sama dengan port admin-state):

```php
Route::post('/smartolt/{olt}/fan/refresh', [SmartOltController::class, 'refreshFan'])
    ->middleware(['throttle:olt-refresh']);
Route::post('/smartolt/{olt}/fan/mode', [SmartOltController::class, 'setFanMode'])
    ->middleware(['throttle:olt-refresh', 'can:olt.write-config,olt']);
Route::post('/smartolt/{olt}/fan/level', [SmartOltController::class, 'setFanLevel'])
    ->middleware(['throttle:olt-refresh', 'can:olt.write-config,olt']);
```

`refreshFan` boleh dijalankan siapa pun yang bisa melihat OLT. Dua route tulis memakai
`can:olt.write-config,olt` dan wajib menulis `AuditLogger` (event `olt.fan_mode`,
`olt.fan_level`) berisi mode/level lama → baru, suhu saat aksi, dan aktor.

### 5.3 Capability

Tambahkan di `SmartOltSupport::capabilities()`:

- `supports_fan_read` — true untuk ZTE C300/C320 (SNMP) dan C600 (CLI).
- `supports_fan_write` — **hanya** C300/C320 saat ini.
- `fan_speed_unit` — `rpm` (C300/C320) / `percent` (C600).

UI memakai flag ini untuk menyembunyikan kontrol, bukan menebak dari nama model.

### 5.4 UI

Tempat: kartu baru **“Fan & Suhu”** di `Pages/SmartOlt/Detail.vue`, **tepat setelah komponen
`<OltChassis>` ditutup** (saat ini baris ~192), sebagai kartu selebar kontainer — bukan di dalam
`OltChassis`. Alasannya: `OltChassis` hanya menggambar chassis dan slot `#actions`-nya sudah dipakai
tombol “Refresh Hardware”; fan punya aksi sendiri (refresh fan, ubah mode/level) dan konteks datanya
berbeda (suhu/mode, bukan kartu/slot).

Susunan kartu:

```
┌─ [ikon Fan] Fan & Suhu            [Auto ▾/badge]  [Refresh] [Ubah mode]* ─┐
│ Suhu 39 °C · Ambang tinggi 75 °C · 2/2 fan online · Mode: Auto            │
│ ┌──────────────────────────────────────────────────────────────────────┐ │
│ │ #  Status   Level   Kecepatan                                        │ │
│ │ 1  normal   3       3246 RPM                                         │ │
│ │ 2  normal   3       3156 RPM                                         │ │
│ └──────────────────────────────────────────────────────────────────────┘ │
└───────────────────────────────────────────────────────────────────────────┘
* tombol hanya tampil bila capabilities.supports_fan_write
```

- `threshold`/`mode` di baris ringkasan; tabel fan di bawahnya (di layar sempit tabel tetap
  satu kolom, tanpa scroll horizontal).
- Badge mode: `Auto` (netral/cyan) vs `Fixed` (kuning) agar mode tetap terlihat menonjol.
- Tombol **Refresh** memanggil route `smartolt.fan.refresh`; tombol tulis memakai modal
  konfirmasi proyek (bukan `window.confirm()`), berisi peringatan overheat. Setelah aksi,
  tampilkan hasil baca ulang (bukan asumsi sukses).

Warna/komponen mengikuti `kv-ui` (Dark-Glass Cyan): badge status memakai varian yang sudah ada,
tanpa warna literal baru.

### 5.5 Guardrail wajib

1. Default dan fallback selalu **auto**; jangan menyimpan state “fixed” lintas restart tanpa
   peringatan.
2. Tolak level < 2 bila `temp_c >= high_threshold_c - 5`.
3. Tolak semua aksi tulis bila salah satu fan `operStatus != 1` atau `onlineStatus != 1`
   (kecuali aksi justru menaikkan level).
4. Tolak aksi bila suhu tidak terbaca (jangan mengendalikan “buta”).
5. Maksimal satu aksi per OLT per 30 detik (throttle sudah ada, tambahkan validasi di service).
6. Audit log + notifikasi/alarm bila setelah aksi suhu naik melewati ambang tinggi.
7. Untuk C600: tidak ada tombol kontrol sampai perintah fixed/level terbukti di perangkat.

---

## 6. Rencana uji

### 6.1 Unit / feature (sqlite in-memory, lewat `bash scripts/test.sh`)

- Parser `show fan` C300, C320, C600 (fixture teks asli dari dokumen ini).
- Parser walk SNMP C300/C320 (fixture OID → nilai).
- `OltFanStatusService::read()` memilih jalur SNMP vs CLI berdasarkan capability.
- Validasi level (0/5 ditolak), guardrail suhu, penolakan C600 untuk tulis.
- Route tulis menolak user tanpa `olt.write-config` (403) dan menulis audit.

### 6.2 Uji perangkat (butuh izin pemilik, dijadwalkan di luar jam sibuk)

1. Baca fan ketiga keluarga (sudah terbukti).
2. C300/C320: SET mode `fixSpeed` + level **sama dengan level aktual** (bukan menurunkan),
   verifikasi `actualSpeedLevel` dan RPM, lalu **kembalikan ke auto**.
3. C300/C320: uji penurunan satu level pada satu fan, pantau suhu 5 menit, kembalikan.
4. C600: hanya `show fan`; uji `fan speed auto` (idempoten) bila diizinkan.
5. Pastikan tidak ada `write`/simpan konfigurasi kecuali diminta.

Rollback: SET mode `1` (auto) dan/atau `fan control fixed-speed` → `fan control` auto sesuai
sintaks yang terbukti; dokumentasikan perintah persisnya setelah uji.

---

## 7. Tahapan

| Fase | Isi | Risiko |
|:--|:--|:--|
| 1 | Tabel + service baca + kartu UI baca-saja (C300/C320 SNMP, C600 CLI) | rendah |
| 2 | Route/aksi mode & level C300/C320 + audit + modal konfirmasi | sedang |
| 3 | Kontrol C600 setelah sintaks fixed/level terbukti | sedang-tinggi |
| 4 | Alarm suhu/fan failure + riwayat | rendah |

Fase 1 sudah cukup untuk menjawab “tampilkan kecepatan fan”. Fase 2 menunggu izin eksplisit
pemilik karena mengubah perangkat produksi.

---

## 8. Referensi

- MIB ZTE `zxAnEnvFan` (subtree `3902.1015.2.1.3.10.10`) — terverifikasi pada C300/C320.
- MIB ZTE C600 (`3902.1082.10.10.2.4`) — ada di dokumen, **tidak ada** di firmware V1.2.2.
- Manual CLI ZTE C300/C320: `show fan`, `fan control`, `fan speed`, `fan speed-percent-set`.
- Manual CLI ZTE C600: `show fan`, `fan speed auto`.
- `docs/SMARTOLT_ZTE_C300_C320_C600_GUIDE.md`, `docs/SMARTOLT_ZTE_C600_GUIDE.md`.
- Kode terkait: `OltSnmpClient`, `ZteCardUplinkService`, `ZteCliProvisioningExecutor`,
  `PonPortAdminStateService`, `SmartOltSupport::capabilities()`, `OltChassis.vue`, `Detail.vue`.
