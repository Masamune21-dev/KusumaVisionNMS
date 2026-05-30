# 02 — Arsitektur

[← Indeks](README.md) · [← 01 Overview](01-overview.md) · [03 Struktur Folder →](03-struktur-folder.md)

## Diagram komponen (high level)

```
┌─────────────────────────────────────────────────────────────────────────┐
│  Browser (Vue 3 + Inertia + xterm.js)                                     │
│  - SPA-like; setiap navigasi minta JSON props ke controller Laravel       │
└───────────┬───────────────────────────────────────────┬──────────────────┘
            │ HTTP (Inertia)                              │ WebSocket (telnet)
            ▼                                             ▼
┌───────────────────────────────┐          ┌──────────────────────────────────┐
│  Laravel 12 (PHP-FPM/nginx)    │          │  telnet:proxy daemon (PHP)         │
│  Controllers → Services        │          │  TelnetProxyServer (react/socket)  │
│  - SmartOltController (besar)  │          │  WS ↔ raw telnet TCP ke OLT        │
│  - Dashboard/Report/Settings…  │          └──────────────┬─────────────────────┘
└───┬───────────┬───────────┬────┘                         │ telnet :23
    │SNMP       │CLI telnet  │                              ▼
    │(sinkron)  │(sinkron)   │                       ┌──────────────┐
    ▼           ▼            │                       │  OLT ZTE GPON │
┌─────────┐ ┌──────────────┐│                       │  C300 / C320  │
│OltSnmp  │ │ZteCli…Executor││                       └──────┬───────┘
│Client   │ │(+ ScriptBldr) │                              ▲
└─────────┘ └──────────────┘│                              │ SNMP v1/v2c + telnet
                            │                               │
   ┌────────────────────────┴───────────────┐              │
   │  Scheduler (every minute) → olts:poll   │              │
   │  → PollOltJob (queue: Redis, supervisor)│              │
   │  → GoSnmpPoller → bin/kv-snmp-poller ────┼──────────────┘
   │  → fallback OltSnmpClient bila gagal     │
   │  → AlarmEvaluator → AlarmEvent + Telegram│
   └──────────────────────────────────────────┘

Penyimpanan: PostgreSQL (inventory, registrasi, alarm, audit, settings)
             Redis (cache, session, queue)
             snmp_olts.last_test_result (JSON) = cache live-state OLT/ONU
```

## Dua jalur eksekusi (ingat baik-baik)

### 1. On-demand / sinkron (di dalam request HTTP)
Semua aksi yang dipicu user dari UI dijalankan langsung di controller/service, **tidak lewat queue**:

- Test koneksi OLT, refresh snapshot manual, refresh port ONU, refresh unconfigured →
  `OltSnmpClient` (SNMP langsung dari PHP).
- Provisioning ONU, reconfigure ONU, sync profil, refresh card/uplink, RX power per-port,
  reboot/enable/disable ONU → `ZteCliProvisioningExecutor` (telnet langsung) atau SNMP set.

Implikasi: request bisa lama (beberapa detik) karena menunggu jaringan OLT. Itu **disengaja** —
hasilnya ditulis ke cache dan langsung dipakai render.

### 2. Scheduled / async (queue worker)
Polling periodik berjalan terpisah:

- `routes/console.php`: `Schedule::command('olts:poll')->everyMinute()->withoutOverlapping();`
- `olts:poll` (`PollOltsCommand`) men-dispatch `PollOltJob` untuk tiap OLT yang `polling_enabled`
  dan sudah due (`isPollDue()`).
- `PollOltJob` jalan di worker supervisor (`kusumavision-worker`), pakai **Go poller** bila
  `SNMP_POLLER_DRIVER=go` + binary ada; **fallback** ke `OltSnmpClient` bila Go gagal.
- Hasil poll → di-merge ke `last_test_result`, lalu `AlarmEvaluator->evaluate()` membandingkan
  snapshot lama vs baru untuk raise/clear alarm + push Telegram.

> Scheduler perlu cron `* * * * * php artisan schedule:run` (atau supervisor) di host. Worker perlu
> `php artisan queue:work` via supervisor.

## Cache live-state: `snmp_olts.last_test_result`

Bukan ada tabel ONU. State terkini OLT disimpan sebagai **satu blob JSON** per OLT di kolom
`last_test_result` (cast `array`). Strukturnya kira-kira:

```jsonc
{
  "ok": true,
  "driver": "zte",
  "latency_ms": 42,
  "system": { "sys_descr": "...", "sys_name": "OLT-C320-PATI", ... },
  "ports": [ { "slot": 1, "port": 1, "if_index": 285278465, "oper_status": "up", ... } ],
  "poller": "go",                 // "go" | "php"
  "go_poller_error": null,
  "polled_at": "2026-05-30T...",
  "onu_poll_error": null,
  "port_onus": {                  // di-bucket per "slot_port"
    "1_1": {
      "ok": true, "slot": 1, "port": 1, "if_index": ...,
      "port_row": { ... },
      "onus": [ { "onu_id": 1, "serial_number": "ZTEG...", "rx_power_dbm": -22.1, ... } ],
      "count": 12,
      "rx_power": { "ok": true, "source": "snmp", "count": 10, "polled_at": "..." },
      "refreshed_at": "..."
    }
  }
}
```

- Halaman **ONU Monitoring** & **global search** membaca `port_onus` lintas semua OLT.
- RX power punya interval terpisah (`rx_poll_interval_minutes`) — saat tidak due, nilai RX lama
  dipertahankan (lihat `PollOltJob::bucketOnusIntoPorts`/`rxPowerMeta`).

## Driver & capability gating

`App\Support\SmartOltSupport` adalah gerbang multi-vendor:

- `driverKey($olt, $sysDescr, $sysObjectId)` → `"zte"` bila nama/vendor/sysDescr mengandung
  `zte|3902|c300|c320|c600`, selain itu `"unknown"`.
- `capabilities($driver, $olt)` → array boolean fitur (`supports_provisioning`, `supports_reboot`,
  `supports_snmp_rx`, dll). Untuk driver `unknown` **semua false**.
- `isC600()` mengubah pola interface (`gpon-olt_1/1/...` vs `gpon-olt_1/...`).
- Controller memanggil `assertCapability($olt, 'supports_xxx')` sebelum aksi sensitif → 403 bila
  tidak didukung. **Tambahkan capability di sini kalau menambah vendor/fitur.**

## Middleware stack (request masuk)

`bootstrap/app.php` mendaftarkan, pada grup `web`:
1. `HandleInertiaRequests` — share `auth`, `flash`, `notifications`, `systemInfo`, `branding` ke semua page.
2. `BlockDemoWrites` — user role `demo` ditolak untuk semua non-GET (kecuali logout).
3. `AddLinkHeadersForPreloadedAssets`.

Alias: `role` → `EnsureUserRole` (dipakai `->middleware('role:admin')`).
CSRF dikecualikan untuk `telegram/webhook` (gerbangnya secret token header).

Detail RBAC & audit di [11 — Keamanan, RBAC & Audit](11-keamanan-rbac-audit.md).

## Audit trail otomatis

Trait `App\Models\Concerns\Auditable` mengaitkan event `created/updated/deleted` ke `audit_logs`
via `AuditLogger`. Event login/logout/failed dicatat di `AppServiceProvider::boot()`. Atribut
sensitif (`$hidden`, password, dan `$auditExclude` per-model) tidak ikut tersimpan.

## Selanjutnya

→ [03 — Struktur Folder](03-struktur-folder.md) · [08 — SNMP & Polling](08-snmp-polling.md)
