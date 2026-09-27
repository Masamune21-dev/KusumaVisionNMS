# 08 — SNMP & Polling

[← Indeks](README.md) · [← 07 Modul & Fitur](07-modul-fitur.md) · [09 CLI & Telnet →](09-cli-telnet.md)

Ada **dua jalur SNMP**: client PHP (`OltSnmpClient`, dipakai aksi on-demand + fallback) dan
poller Go (`bin/kv-snmp-poller`, dipakai polling terjadwal). Keduanya **read-only v1/v2c** (v3 →
error). SNMP **set** (enable/disable ONU, set nama) tetap lewat `OltSnmpClient::set()` /
`ZteRemoteOnuService`.

## A. `OltSnmpClient` (`app/Services/Snmp/OltSnmpClient.php`)

Wrapper PHP `ext-snmp`. API utama:

| Method | Fungsi |
|--------|--------|
| `test($olt)` | Ambil system info ringkas (uji koneksi) |
| `snapshot($olt)` | system + GPON ports (untuk refresh penuh) |
| `systemInfo($olt)` | sysDescr/sysObjectId/uptime/name |
| `gponPorts($olt)` | Daftar port PON (IF-MIB) |
| `registeredOnus($olt, $ports?)` | Tabel ONU ZTE (type/name/sn/admin/phase/last-down) |
| `portOnusSnapshot($olt,$slot,$port)` | ONU untuk satu port |
| `onuRxPowers($olt)` | Walk RX power semua ONU |
| `mergeOnuRxPowers($onus,$powers)` | Gabung RX ke daftar ONU |
| `unconfiguredOnus($olt)` / `unconfiguredOnusSnapshot()` | ONU belum terdaftar |
| `set($olt,$oid,$type,$value)` | SNMP set (write community) |
| `walk($olt,$oid)` / `get($olt,$oid)` | Primitif walk/get |

Banyak helper privat untuk decode: `decodeOnuSn()`, `decodeAdminState()`, `decodePhaseState()`
(beda C600), `decodeLastDownCause()`, `convertOnuRxPowerToDbm()`, encode/decode ifIndex ZTE
(`zteEncodeIfIndex` / `decodeIfIndex`), parse slot/port dari ifDescr.

> Parsing di-tuning terhadap firmware OLT nyata (`OLT-C320-PATI`). Hati-hati saat mengubah —
> verifikasi ke OLT live (id=1 / id=2) dan catat di `WORKLOG.md`.

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

**ZTE C600 (enterprise 3902.1082.500):** ada padanan terpisah (`C600_ONU_*`,
`C600_UNCFG_OIDS`) — diaktifkan saat `SmartOltSupport::isC600()` true. Referensi lengkap OID C600
ada di PDF folder `docs/`.

## B. Go SNMP poller (`cmd/kv-snmp-poller/main.go`)

Binary CLI yang melakukan walk SNMP cepat (lib `gosnmp`, bulk walk) dan mencetak **JSON** ke stdout.

### Flag
```
--host --port --version (v1|v2c) --include-rx
--timeout (mis. 10s) --retries --walk-mode (auto|bulk|walk) --max-repetitions
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
go build -o bin/kv-snmp-poller ./cmd/kv-snmp-poller
```

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
Loop semua OLT `polling_enabled=true`; dispatch `PollOltJob($olt->id)` hanya bila `isPollDue()`.
Lapor jumlah dispatched/skipped.

### `PollOltJob` (`app/Jobs/PollOltJob.php`)
- `tries=1`, `timeout=600`, `WithoutOverlapping($oltId)` (tidak dobel poll OLT sama).
- Guard ulang: skip bila OLT hilang / polling off / belum due.
- **Pakai Go poller bila `enabled()`**; bila throw → simpan `go_poller_error` & **fallback**
  `OltSnmpClient::snapshot()` + `registeredOnus()`.
- RX power punya jadwal sendiri (`isRxPollDue()` / `rx_poll_interval_minutes`). Saat tidak due,
  nilai RX lama dipertahankan (`bucketOnusIntoPorts` + `rxPowerMeta` + `existingRxByOnuId`).
- Hasil di-merge ke `last_test_result` (+ `poller`, `polled_at`, `onu_poll_error`), update
  `last_tested_at/last_polled_at` (dan `last_rx_polled_at` bila RX sukses).
- Panggil `AlarmEvaluator::evaluate($olt, $previousSnapshot)` → raise/clear alarm.
- Catat `PollingEvent` (`olt_poll`, dan `rx_poll` bila due). `failed()` mencatat kegagalan.
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

## Troubleshooting cepat
- **Data tidak ter-refresh otomatis** → cek `schedule:work` & `queue:work` jalan; `polling_enabled`
  true; `last_polled_at` bergerak; lihat `polling_events`.
- **`go_poller_error` terisi** → binary hilang/tak executable/timeout; sistem fallback ke PHP.
  Cek `SNMP_POLLER_DRIVER`, path binary, dan `KV_SNMP_COMMUNITY` (community benar).
- **RX power kosong** → cek `rx_poll_interval`; sebagian firmware butuh CLI (lihat
  `ZteOnuRxPowerService` di [09](09-cli-telnet.md)).
Lebih lengkap di [13 — Troubleshooting](13-troubleshooting-maintenance.md).

## Selanjutnya

→ [09 — CLI & Telnet](09-cli-telnet.md)
