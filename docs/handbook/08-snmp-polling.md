# 08 — SNMP & Polling

[← Indeks](README.md) · [← 07 Modul & Fitur](07-modul-fitur.md) · [09 CLI & Telnet →](09-cli-telnet.md)

Untuk **ZTE** ada **dua jalur SNMP**: client PHP (`OltSnmpClient`, dipakai aksi on-demand +
fallback) dan poller Go (`bin/kv-snmp-poller`, dipakai polling terjadwal). **C-Data & HiOSO** punya
jalur driver sendiri (bagian E). Semuanya **read v1/v2c** (v3 → error). SNMP **set** hanya dipakai di
sedikit tempat: ZTE C300/C320 enable/disable & set nama/deskripsi ONU (`OltSnmpClient::set()` via
`ZteRemoteOnuService`, butuh write community) dan rename ONU HiOSO HA7302 (`HiosoSnmp::set()`).
Aksi tulis C-Data & HiOSO lainnya lewat CLI ([09](09-cli-telnet.md)).

## A. `OltSnmpClient` (`app/Services/Snmp/OltSnmpClient.php`)

Wrapper PHP `ext-snmp`. API utama:

| Method | Fungsi |
|--------|--------|
| `test($olt)` | Ambil system info ringkas (uji koneksi) |
| `snapshot($olt)` | system + GPON ports (untuk refresh penuh) |
| `systemInfo($olt)` | sysDescr/sysObjectId/uptime/name |
| `gponPorts($olt)` | Daftar port PON (IF-MIB) |
| `registeredOnus($olt, $ports?, $scope?)` | Tabel ONU ZTE (type/name/sn/admin/phase/last-down); `$scope` = prefix index satu port (`zteEncodeIfIndex`) agar walk per-port ringan |
| `portOnusSnapshot($olt,$slot,$port)` | ONU untuk satu port (C300/C320: hanya subtree port itu) |
| `onuRxPowers($olt, $scope?)` | Walk RX power ONU |
| `mergeOnuRxPowers($onus,$powers)` | Gabung RX ke daftar ONU |
| `unconfiguredOnus($olt)` / `unconfiguredOnusSnapshot()` | ONU belum terdaftar (dipakai `Zte\UnconfiguredOnuDiscovery`) |
| `cardProcessors($olt)` / `cardInventory($olt)` | CPU/memori kartu; inventory kartu C600 via SNMP |
| `set($olt,$oid,$type,$value)` | SNMP set (write community) |
| `walk($olt,$oid)` / `get($olt,$oid)` | Primitif walk/get |

Banyak helper privat untuk decode: `decodeOnuSn()`, `decodeAdminState()`, `decodePhaseState()`
(beda C600), `decodeLastDownCause()`, `convertOnuRxPowerToDbm()`, encode/decode ifIndex ZTE
(`zteEncodeIfIndex` / `decodeIfIndex`), parse slot/port dari ifDescr.

> Parsing di-tuning terhadap firmware OLT ZTE C320 nyata. Hati-hati saat mengubah —
> verifikasi ke OLT live dan catat di `WORKLOG.md`.

### OID yang dipakai (ringkas)

**Standar (IF-MIB / SNMPv2-MIB):**
```
sysDescr      .1.3.6.1.2.1.1.1.0
sysObjectID   .1.3.6.1.2.1.1.2.0
sysUpTime     .1.3.6.1.2.1.1.3.0
sysName       .1.3.6.1.2.1.1.5.0
ifDescr       .1.3.6.1.2.1.2.2.1.2
ifOperStatus  .1.3.6.1.2.1.2.2.1.8
ifName        .1.3.6.1.2.1.31.1.1.1.1
```

**ZTE C300/C320 (enterprise 3902.1012):**
```
onuType        .1.3.6.1.4.1.3902.1012.3.28.1.1.1
onuName        .1.3.6.1.4.1.3902.1012.3.28.1.1.2
onuDescription .1.3.6.1.4.1.3902.1012.3.28.1.1.3
onuSN          .1.3.6.1.4.1.3902.1012.3.28.1.1.5
onuAdminState  .1.3.6.1.4.1.3902.1012.3.28.1.1.17
onuPhaseState  .1.3.6.1.4.1.3902.1012.3.28.2.1.4
onuLastDown    .1.3.6.1.4.1.3902.1012.3.28.2.1.7
onuRxPower     .1.3.6.1.4.1.3902.1012.3.50.12.1.1.10
unconfigured   .1.3.6.1.4.1.3902.1012.3.13.3.1.2 (+ varian 1082.500...)
```

**ZTE C600 (enterprise 3902.1082.500):** padanan terpisah (`C600_ONU_*`, `C600_UNCFG_OIDS`,
`C600_CARD_*`) — diaktifkan saat `SmartOltSupport::isC600()` true (sysDescr **atau** sysObjectID
`3902.1082.1001.600`). Ringkas:
```
tabel ONU      .1.3.6.1.4.1.3902.1082.500.20.2.1.2.1   (SN .3, type .8)
nama/deskripsi .1.3.6.1.4.1.3902.1082.500.10.2.3.3.1.2 / .3
admin/phase    .1.3.6.1.4.1.3902.1082.500.10.2.3.8.1.1 / .4   (phase 4 = Working)
Rx ONU         .1.3.6.1.4.1.3902.1082.500.20.2.2.2.1.10  (sentinel 65535)
unconfigured   .1.3.6.1.4.1.3902.1082.500.2.2.11.2.1.2   (serial)
```
Referensi yang terverifikasi: [`docs/SMARTOLT_ZTE_C600_GUIDE.md`](../SMARTOLT_ZTE_C600_GUIDE.md) dan
`docs/ZTE_C600_*.md`. **PDF C600 di `docs/*.pdf` tidak terverifikasi — jangan dipakai sebagai sumber
OID.** OID vendor baru hanya masuk kode setelah dibaca dari perangkat asli.

## B. Go SNMP poller (`cmd/kv-snmp-poller/main.go`)

Binary CLI yang melakukan walk SNMP cepat (lib `gosnmp`, bulk walk) dan mencetak **JSON** ke stdout.
Mengenali C300/C320 **dan C600** (subtree `.1082`, deteksi dari system info). Khusus ZTE — OLT
non-ZTE tidak pernah dikirim ke poller ini.

### Flag
```
--host --port (=161) --version (v1|v2c, =v2c) --include-rx
--timeout (=10s) --retries (=2) --walk-mode (auto|bulk|walk, =bulk) --max-repetitions (=10)
```
Community dibaca dari **env** `KV_SNMP_COMMUNITY` (tidak lewat argumen → tidak bocor ke proses list).

### Output JSON (ringkas)
```jsonc
{ "ok": true, "driver": "zte", "latency_ms": 40,
  "system": {...}, "ports": [...], "onus": [...],
  "rx_power": { "ok": true, "error": null },
  "error": null }
```

### Build
```bash
CGO_ENABLED=0 go build -mod=mod -trimpath -ldflags='-s -w' -o bin/kv-snmp-poller ./cmd/kv-snmp-poller
```
Sama dengan `install.sh`: `-mod=mod` wajib karena root repo punya folder `vendor/` milik PHP
(tanpanya Go mencoba vendor mode); `CGO_ENABLED=0` = binary statis. Setelah rebuild, `queue:restart`
tidak wajib (binary dipanggil per job), tapi uji satu poll.

### `GoSnmpPoller` (PHP bridge — `app/Services/Snmp/GoSnmpPoller.php`)
- `enabled()` → true bila `services.snmp_poller.driver === 'go'` **dan** binary ada+executable.
- `poll($olt,$includeRx)` → susun argumen dari config, jalankan via Symfony `Process` (timeout
  `process_timeout`), set env `KV_SNMP_COMMUNITY`, decode JSON, lempar `RuntimeException` bila
  gagal/JSON invalid/`ok!=true`. v3 → throw.
- Config di `config/services.php` blok `snmp_poller` (env `SNMP_POLLER_*`).

## C. Polling terjadwal

```
Scheduler (cron/schedule:work)  →  olts:poll (PollOltsCommand)  →  dispatch PollOltJob per OLT due
                                                                    └→ queue Redis (worker supervisor)
```

### `PollOltsCommand` (`olts:poll`)
Loop semua OLT `polling_enabled=true` (ZTE **dan** non-ZTE); dispatch `PollOltJob($olt->id)` hanya
bila `isPollDue()`. Lapor jumlah dispatched/skipped.

### `PollOltJob` (`app/Jobs/PollOltJob.php`)
- `tries=1`, `timeout=600`, `failOnTimeout`, `WithoutOverlapping($oltId)` (`expireAfter(timeout+300)`,
  `dontRelease`) — tidak dobel poll OLT sama.
- Guard ulang: skip bila OLT hilang / polling off / belum due.
- **Non-ZTE** (`SmartOltSupport::isNonZte()`) → `pollViaScanner()` (bagian E), lalu selesai.
- **ZTE: pakai Go poller bila `enabled()`**; bila throw → simpan `go_poller_error` & **fallback**
  `OltSnmpClient::snapshot()` + `registeredOnus()`. C600: bila Go mengembalikan daftar ONU kosong,
  daftar ONU diambil ulang lewat `OltSnmpClient` (port/system tetap dari Go).
- RX power punya jadwal sendiri (`isRxPollDue()` / `rx_poll_interval_minutes`). Saat tidak due,
  nilai RX lama dipertahankan (`bucketOnusIntoPorts` + `rxPowerMeta` + `existingRxByOnuId`).
- Hasil di-merge ke `last_test_result` (+ `poller` = `go`|`php`, `polled_at`, `onu_poll_error`),
  update `last_tested_at/last_polled_at` (dan `last_rx_polled_at` bila RX sukses).
- Panggil `AlarmEvaluator::evaluate($olt, $previousSnapshot)` → raise/clear alarm.
- Catat `PollingEvent` (`olt_poll`, dan `rx_poll` bila due **dan** OLT terjangkau — supaya OLT mati
  tak menghasilkan baris gagal ganda). `failed()` mencatat kegagalan.
- **Time-series RX**: bila RX poll sukses, `recordRxSamples()` bulk-insert satu titik per ONU
  ber-nilai RX numerik ke tabel `onu_rx_samples` (`polled_at = now`). Hanya saat sukses → tidak
  menulis nilai yang di-preserve. Dipakai histogram distribusi (ONU Monitoring) & grafik tren
  (ONU Detail, `OnuRxSample::seriesFor`).

### Ringkasan per jam (`optical:aggregate-rx`)

`Schedule::hourlyAt(5)`. Merangkum `onu_rx_samples` menjadi satu baris per ONU per jam di
`onu_rx_hourly` (min/avg/max + `sample_count`). Idempoten lewat `upsert` pada kunci unik.

Dua perilaku yang disengaja dan mudah disalahpahami:

- **Jam terakhir diproses ulang**, bukan dilewati. Saat sebuah jam pertama kali dirangkum,
  sebagian sampelnya bisa jadi belum masuk.
- **Jam yang sedang berjalan sengaja dilewati** — datanya belum lengkap.

Default 48 jam per eksekusi supaya backfill panjang tidak menahan scheduler; `--hours`
eksplisit menaikkan batas itu sendiri.

### Retensi RX (`optical:prune-rx`)

`PruneOnuRxSamplesCommand` memangkas **dua** tabel sekaligus, dijadwalkan harian 03:15.

| Tabel | Retensi | Env |
|---|---|---|
| `onu_rx_samples` (mentah) | **3 hari** | `SNMP_POLLER_RX_RETENTION_DAYS` |
| `onu_rx_hourly` (ringkasan) | **45 hari** | `SNMP_POLLER_RX_HOURLY_RETENTION_DAYS` |

Override per jalan lewat `--days=`. Hapus bertahap (pilih id → `whereIn`, portabel
sqlite/pgsql).

**3 hari aman dipersingkat karena prune menolak jalan melewati jam yang belum terangkum.**
Jadi agregasi yang macet membuat tabel mentah *tumbuh*, bukan membuat riwayat *hilang* —
mode kegagalan yang jauh lebih mudah diperbaiki. 3 hari, bukan 1, sebagai margin.

### Dari mana grafik membaca datanya

`OnuRxSample::seriesFor()` **memilih sumbernya sendiri**: rentang yang menjangkau lebih jauh
dari umur sampel mentah dilayani `OnuRxHourly`, dengan bentuk keluaran identik (`polled_at` +
`rx_power_dbm`, plus min/max).

Artinya `SmartOltController` dan `ZteOnuDetailService` tidak
perlu tahu tabel mana yang sedang dibaca. **Jangan query `onu_rx_samples` langsung** untuk
rentang panjang — itu akan memulangkan riwayat kosong untuk permintaan 30 hari.

### Struktur `port_onus` di cache
Lihat [02 — Arsitektur](02-arsitektur.md#cache-live-state-snmp_oltslast_test_result). ONU dibucket
per `"slot_port"` agar halaman per-port & ONU Monitoring cepat membacanya.

## D. Interval polling
- Per OLT: `poll_interval_minutes` (default 5) & `rx_poll_interval_minutes` (default 5), minimal 1.
- Diset di form OLT. `isPollDue()`/`isRxPollDue()` membandingkan dengan `last_*_polled_at`.
- Dengan interval 5 menit + debounce alarm 2 poll, alarm sungguhan datang ±10 menit setelah gangguan.
- **Unconfigured ONU tidak dipoll terjadwal.** Daftarnya hanya diperbarui saat Refresh Discovery
  (web `smartolt.unconfigured.refresh` / API `api.olts.unconfigured.refresh`) lewat
  `App\Services\Zte\UnconfiguredOnuDiscovery`, yang juga mencatat "Pertama Terlihat"
  (`last_test_result.unconfigured_seen`; OLT sendiri tidak menyimpan waktu kemunculan).

## E. Jalur non-ZTE (C-Data EPON/GPON & HiOSO EPON)

- `App\Services\SmartOltSnmpServiceResolver` memetakan OLT → driver `App\Contracts\SmartOltSnmpDriver`
  (`ping`, `getSystemInfo`, `getPorts`, `getRegisteredOnus[ByPort]`, `getPortRxMap`,
  `countRegisteredOnus`, `getUnconfiguredOnus`): `CDataEponSnmpService` (17409),
  `CDataGponSnmpService` (tabel NSCRTV `17409.2.8.4`, + enrich CLI `CDataGponCliService` bila ada
  telnet), `HiosoEponSnmpService` (25355). ZTE sengaja **tidak** lewat resolver ini.
- `CDataOltScanner::scan()` (dipakai refresh manual **dan** `PollOltJob::pollViaScanner()`) menulis
  `system`, `ports`, `port_onus` (bentuk sama dengan ZTE), `onu_scanned_at`, dan faceplate `panel`
  (best-effort). Status port HiOSO yang tak terbaca SNMP diturunkan dari jumlah ONU online.
- Job menambahkan `ok`/`error`/`poller = cdata` (penanda yang dibutuhkan `AlarmEvaluator`), mencatat
  sampel RX saat RX due, lalu mengevaluasi alarm.
- Aturan status per vendor (jangan diubah tanpa verifikasi live): C-Data GPON online dari
  `onuOperationStatus` `.7` (bukan tabel optik `34592…21`); HiOSO online dari link-state `.39.1`
  (bukan disimpulkan dari Rx). Detail OID: [17](17-cdata-gpon-snmp-walk.md),
  [`SMARTOLT_CDATA_GUIDE.md`](../SMARTOLT_CDATA_GUIDE.md), [`SMARTOLT_HIOSO_GUIDE.md`](../SMARTOLT_HIOSO_GUIDE.md).

## Troubleshooting cepat
- **Data tidak ter-refresh otomatis** → cek `schedule:work` & `queue:work` jalan; `polling_enabled`
  true; `last_polled_at` bergerak; lihat `polling_events`.
- **`go_poller_error` terisi** → binary hilang/tak executable/timeout; sistem fallback ke PHP.
  Cek `SNMP_POLLER_DRIVER`, path binary, dan `KV_SNMP_COMMUNITY` (community benar).
- **RX power kosong** → cek `rx_poll_interval`; sebagian firmware butuh CLI (lihat
  `ZteOnuRxPowerService` di [09](09-cli-telnet.md)).
- **OLT C-Data/HiOSO tak ter-update** → `last_test_result.poller` harus `cdata`; lihat `error`
  top-level (timeout SNMP menggagalkan scan dan cache lama dipertahankan, bukan jadi 0 ONU).
Lebih lengkap di [13 — Troubleshooting](13-troubleshooting-maintenance.md).

## Selanjutnya

→ [09 — CLI & Telnet](09-cli-telnet.md)
