# 09 — CLI & Telnet

[← Indeks](README.md) · [← 08 SNMP & Polling](08-snmp-polling.md) · [10 Alarm & Telegram →](10-alarm-telegram.md)

Semua interaksi CLI ke OLT memakai **telnet** (raw TCP). SSH **belum diwire**
(`ZteCliProvisioningExecutor` menolak transport ssh). Ada dua pemakaian telnet:
1. **Programatik** — executor menjalankan script dan menangkap output (provisioning, profil,
   detail ONU, RX power, card/uplink, reconfigure).
2. **Interaktif** — terminal xterm.js di browser via daemon proxy WebSocket.

> ⚠️ Sumber kebenaran sintaks perintah: [`docs/SMARTOLT_ZTE_C300_C320_C600_GUIDE.md`](../SMARTOLT_ZTE_C300_C320_C600_GUIDE.md).
> Jangan menebak perintah CLI.

---

## A. Executor programatik — `ZteCliProvisioningExecutor`

`app/Services/ZteCliProvisioningExecutor.php`. Membuka sesi telnet, login dengan kredensial CLI
tersimpan, kirim baris-baris script, dan kumpulkan output.

| Method | Fungsi |
|--------|--------|
| `execute($olt,$script)` | Jalankan script (tanpa auto-konfirmasi) |
| `executeConfirmable($olt,$script)` | Jalankan script + auto-jawab prompt konfirmasi `y` |
| `saveConfig($olt)` | Simpan running-config ke memori OLT (`write`) — tunggu prompt (bukan patokan jeda) karena write C300 config besar bisa hening ~30 detik |
| `run(...)` (privat) | Loop kirim perintah + baca sampai idle |
| `login(...)` | Auto-login username/password |
| `readUntilIdle(...)` | Baca sampai prompt CLI, auto-continue `--More--` pager |
| `detectError($output)` | Deteksi pesan error CLI |
| `maskSecrets($output,$olt)` | Sensor password CLI dari output yang disimpan |

Karakteristik:
- Menolak transport selain `telnet`.
- Auto-menjawab pager `--More--`/`--more--` agar output tidak terpotong.
- Auto-konfirmasi (`y`) untuk perintah yang minta konfirmasi (mode confirmable).
- Output yang disimpan disensor agar password CLI tidak bocor ke DB/log.

## B. Builder script

### `ZteProvisioningScriptBuilder` — register ONU baru
`build($data)` menghasilkan script registrasi: masuk config, register ONU (type),
T-CONT/GEMport, VLAN/service-port, WAN (PPPoE/DHCP/static), TR069 (`tr069Lines`), Remote ONT
(`remoteOntLine`). Dipakai `SmartOltController::storeOnu()`. Hasil disimpan ke `cli_script`.

### `ZteOnuReconfigureScriptBuilder` — ubah ONU existing
`build($baseline, $target, $context)` mem-**diff** running-config (baseline) vs target form, lalu
keluarkan **hanya perintah perubahan** (tcont, gemport, service-port, service, vlan-port, WAN,
WAN-IP, TR069, Remote ONT) + ringkasan perubahan. Dipakai `configureOnuPreview` (tampilkan diff)
dan `configureOnuApply` (eksekusi).

## C. Service CLI lain (semua via executor)

| Service | Fungsi | Method kunci |
|---------|--------|--------------|
| `ZteProfileCatalogService` | Sync & parse profil dari OLT (`show ...`) | `syncFromOlt`, `parse`, `parseTcont/Vlan/Ip/OnuTypes` |
| `ZteOnuDetailService` | Detail satu ONU (info, optik, atenuasi, history) | `fetch`, `parse` |
| `ZteOnuRunningConfigService` | Ambil running-config ONU → baseline reconfigure | `fetch`, `parse`, `applyLine`, `derivePrimaryVlan` |
| `ZteOnuRxPowerService` | RX power per-port via `show pon power onu-rx` | `portRxPower`, `parse`, `merge` |
| `ZteRemoteOnuService` | Reboot (CLI) + enable/disable & set info (SNMP) | `reboot`, `setActiveState`, `setInfo` |
| `ZteCardUplinkService` | Card status, uplink iface, GPON iface, VLAN, optik, tambah VLAN | `refreshCardStatus`, `refreshInterfaceDetails`, `refreshGponInterface`, `addAndTagVlan`, banyak `parse*` |

`App\Support\CliOutputSanitizer` membersihkan output CLI mentah saat perlu.

## C-bis. Save Config (simpan running-config ke memori OLT) — semua family

Tombol ikon `Save` di daftar OLT (`Pages/SmartOlt/Index.vue`, tab ZTE & non-ZTE) memicu **write** running-config ke memori OLT (persist; beda dari **backup** ke DB — lihat `OltConfigBackupService`). Sinkron, gated capability `supports_config_save` + `throttle:olt-refresh`.

| Family | Service / method | Sekuens CLI | Route |
|--------|------------------|-------------|-------|
| ZTE | `ZteCliProvisioningExecutor::saveConfig` | login → `write` | `smartolt.config.save` |
| C-Data EPON/GPON | `CDataCliWriteService::saveConfig` | `enable` → `config` → `save` (identik EPON/GPON) | `cdata-olt.config.save` |
| HiOSO / V-Sol | `HiosoCliWriteService::saveConfig` | `enable` → `write` | `hioso-olt.config.save` |

- **ZTE C300 config besar:** perintah `write` bisa **hening ~30 detik** sebelum prompt kembali. `saveConfig` membaca via `readUntilIdle(quiet=75s, cap=120s)` → hanya prompt CLI yang menghentikan pembacaan (bukan patokan output sunyi), jadi tak berhenti prematur di tengah write.
- Konfirmasi CLI (bila muncul) dijawab otomatis; password CLI tetap di-mask dari output tersimpan.

## D. Browser telnet (xterm.js)

Memungkinkan operator membuka terminal telnet OLT langsung di browser, tanpa membuka port telnet
OLT ke internet.

### Komponen
- **Daemon**: `php artisan telnet:proxy` (`TelnetProxyCommand` → `TelnetProxyServer`).
  Bind default `127.0.0.1:6002` (`config/telnet.php`). Di prod dijalankan supervisor
  (`kusumavision-telnet-proxy`) dan diekspos nginx sebagai `wss://domain/telnet-ws`.
- **Tiket**: `TelnetSessionController@token` (route `smartolt.telnet.token`, butuh `canManageOlt()`)
  menerbitkan tiket terenkripsi (`TelnetTicket::issue(userId, oltId)`), TTL pendek
  (`telnet.ticket_ttl`, default 60s), URL-safe. Mengembalikan `{ token, ws_url, expires_in }`
  dan mencatat audit `telnet_opened`.
- **Frontend**: `Components/Shell/TelnetWindow.vue` (jendela draggable/minimize/maximize), xterm.js.

### Alur koneksi (`TelnetProxyServer`)
```
Browser ─WS upgrade(?token=…)→ proxy
  proxy: verify TelnetTicket (exp + decrypt APP_KEY)
        → SnmpOlt::find(oltId); pastikan cli_transport=telnet + username/password ada
        → 101 Switching Protocols
        → dial telnet OLT (ip:cli_port, timeout config telnet.connect_timeout)
        → pipe dua arah; TelnetIacFilter strip/jawab IAC negotiation
        → auto-login: ketik username saat prompt login, password saat prompt password
  byte OLT → (filter IAC) → frame WS → terminal
  ketikan terminal → frame WS → telnet OLT
```
- **`TelnetTicket`** (`app/Support/Telnet/TelnetTicket.php`): enkripsi `{u,o,exp}` dengan
  `Crypt` (APP_KEY) → base64 URL-safe. `verify()` cek decrypt + kadaluarsa. Daemon tak perlu DB
  session bersama — cukup APP_KEY yang sama.
- **`TelnetIacFilter`** (`app/Support/Telnet/TelnetIacFilter.php`): memisahkan byte data bersih
  dari perintah IAC (negotiation), dan menyusun balasan negotiation otomatis.

### Konfigurasi (`config/telnet.php` / env)
```
TELNET_PROXY_HOST=127.0.0.1
TELNET_PROXY_PORT=6002
TELNET_PROXY_WS_URL=            # prod: wss://domain/telnet-ws ; kosong di dev → ws://host:6002
TELNET_PROXY_TICKET_TTL=60
TELNET_PROXY_CONNECT_TIMEOUT=10
```

### Catatan operasional
- Daemon **long-lived** → setelah ubah kode proxy: `supervisorctl restart kusumavision-telnet-proxy`.
- OLT harus `cli_transport=telnet` + `cli_username`/`cli_password` terisi, kalau tidak token
  endpoint balas 422 dan proxy balas 403.
- Tiket habis dalam ~60 detik untuk **membuka** WS; setelah terhubung sesi tetap hidup.

## Selanjutnya

→ [10 — Alarm & Telegram](10-alarm-telegram.md)
