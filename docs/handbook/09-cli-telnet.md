# 09 — CLI & Telnet

[← Indeks](README.md) · [← 08 SNMP & Polling](08-snmp-polling.md) · [10 Alarm & Telegram →](10-alarm-telegram.md)

Semua interaksi CLI ke OLT memakai **telnet** (raw TCP). SSH **belum diwire**
(`ZteCliProvisioningExecutor` dan service CLI C-Data/HiOSO menolak transport selain telnet). Ada dua
pemakaian telnet:
1. **Programatik** — executor menjalankan script dan menangkap output (provisioning, profil,
   detail ONU, RX power, card/uplink, reconfigure, bind, port, backup/save config). ZTE lewat
   `ZteCliProvisioningExecutor`; C-Data & HiOSO punya plumbing sendiri (bagian E).
2. **Interaktif** — terminal xterm.js di browser via daemon proxy WebSocket.

> ⚠️ Sumber kebenaran sintaks perintah: [`docs/SMARTOLT_ZTE_C300_C320_C600_GUIDE.md`](../SMARTOLT_ZTE_C300_C320_C600_GUIDE.md).
> Jangan menebak perintah CLI.

---

## A. Executor programatik — `ZteCliProvisioningExecutor`

`app/Services/ZteCliProvisioningExecutor.php`. Membuka sesi telnet, login dengan kredensial CLI
tersimpan, kirim baris-baris script, dan kumpulkan output.

| Method | Fungsi |
|--------|--------|
| `execute($olt,$script,$largeOutput=false)` | Jalankan script (tanpa auto-konfirmasi). `largeOutput` = toleransi jeda 4 dtk / batas 240 dtk per perintah (running-config penuh) |
| `executeConfirmable($olt,$script)` | Jalankan script + auto-jawab prompt konfirmasi `y` (maks 3×; mis. reboot ONU) |
| `executeScan($olt,$script)` | Seperti `largeOutput`, tapi tiap perintah dibaca **sampai prompt kembali** (bukan patokan jeda) — untuk `show running-config \| include …` |
| `saveConfig($olt)` | Simpan running-config ke memori OLT (`write`) — tunggu prompt (bukan patokan jeda) karena write C300 config besar bisa hening ~30 detik |
| `run(...)` (privat) | Loop kirim perintah + baca sampai idle |
| `login(...)` / `enterPrivileged(...)` | Auto-login username/password; `enable` best-effort bila mendarat di prompt `>` |
| `readUntilIdle(...)` | Baca sampai prompt CLI / sunyi, auto-continue `--More--` pager |
| `detectError($output)` | Deteksi pesan error CLI per perintah |
| `maskSecrets($output,$olt)` | Sensor password CLI dari output yang disimpan |

### Cara executor mengirim perintah (WAJIB dipahami sebelum menulis script)
- Script dipecah **per baris** (`trim`, baris kosong dibuang). Tiap baris dikirim **utuh diikuti
  Enter** (`$command."\n"`), lalu output dibaca sampai prompt privileged `…#` terlihat atau output
  sunyi 1,25 dtk (batas keras 45 dtk per perintah). Tidak ada "ketik lalu tunggu" per karakter.
- Pager (`--More--`, `---- More ----`, "press any key") dijawab Enter otomatis dan penandanya dibuang
  dari output.
- Prompt konfirmasi (`(y/n)`, `[y/n]`, `yes/no`, `are you sure`, `confirm to reboot`, `continue?`)
  **hanya** dijawab `y` di mode `executeConfirmable`/`saveConfig`.
- Di akhir sesi dikirim `exit`; bila OLT bertanya "confirm to logout without saving"/yes-no, executor
  menjawab `no`. Executor **tidak pernah menambahkan `write` sendiri** — hanya script yang memuatnya.
- `detectError()` menandai `ok=false` bila ada baris `%Code …`, `%Error …`, `invalid input`,
  `unknown command`, `incomplete command`, `already exist`, dll. setelah sebuah perintah, dan
  menyebut perintah yang ditolak. `%Info …` dan peringatan "password is not strong" diabaikan;
  penolakan `enable`/`terminal length 0` (BEST_EFFORT_COMMANDS) tidak menggagalkan sesi.
- Output yang disimpan disensor (`maskSecrets`) agar password CLI tidak bocor ke DB/log. Pesan
  error memakai `__('olt.*')` (ikut bahasa pengguna).

> ⚠️ **Jangan pernah mengirim context-help berargumen lewat executor** (termasuk saat riset
> sintaks). Karena baris dikirim utuh lalu Enter, `shutdown ?` akan **menjalankan** `shutdown` (port
> PON benar-benar mati) — anggap perintah tulis lain + `?` sama berbahayanya. Riset sintaks cukup
> dengan `?` polos di mode yang tepat, atau lewat terminal interaktif dengan hati-hati. Lihat guide
> ZTE §5.6b.

### Kapan konfigurasi tersimpan (`write`)
Perubahan di running-config hilang saat OLT reboot kecuali di-`write`. Perilaku per fitur:

| Aksi | `write` otomatis? | Di mana |
|------|-------------------|---------|
| Registrasi ONU C300/C320 (Sederhana/Lanjutan) | **Tidak** | `ZteProvisioningScriptBuilder`, `ZteOnuReconfigureScriptBuilder::buildForRegistration()` |
| Registrasi ONU C600 | **Ya** (`end` lalu `write` — `write` tidak valid di config mode C600) | `ZteC600ProvisioningScriptBuilder` |
| Bind ONU | **Ya, opsional** (checkbox `save_config`, bawaan nyala → `saveConfig()`) | `SmartOltController::replaceOnu` |
| Deskripsi port PON ZTE | **Ya** | `ZteCardUplinkService::setGponPortDescription()` |
| Tambah & tag VLAN uplink ZTE | **Ya** | `ZteCardUplinkService::addAndTagVlan()` |
| Matikan/nyalakan port PON | **Tidak** (sengaja — port menyala lagi bila OLT reboot) | `ZteCardUplinkService::setGponPortAdminState()` |
| Configure ONU, salin ONU, TR069 massal, hapus/reboot ONU, profil via CLI | **Tidak** | builder/service masing-masing |
| C-Data: VLAN, tag port, Remote ONT, rename/hapus ONU; HiOSO: aksi ONU | **Tidak** (pakai tombol Save Config) | `CDataGponPortService`, `CDataCliWriteService`, `HiosoCliWriteService` |
| Tombol **Save Config** | **Ya** (itulah fungsinya) | bagian C-bis |

> ⚠️ Karena beberapa fitur menulis `write` otomatis, **port yang sedang sengaja dimatikan ikut
> tersimpan permanen** bila setelahnya ada yang menekan Save Config, mengedit deskripsi port, atau
> menambah VLAN uplink di OLT yang sama. Nyalakan port dulu sebelum menyimpan konfigurasi.

## B. Builder script

### `ZteProvisioningScriptBuilder` — register ONU baru (C300/C320, mode Sederhana)
`build($data)` menghasilkan script registrasi: masuk config, register ONU (type — nama onu-type
**peka huruf**, jangan di-uppercase), T-CONT/GEMport, VLAN/service-port, WAN (PPPoE/DHCP/static),
TR069 (`tr069Lines`), Remote ONT (`remoteOntLine`), deskripsi (`description <teks>` atau konvensi
`{id}$$nama$$`). Dipakai `SmartOltController::storeOnu()`/`registerOnuPreview()` (web) dan
`App\Services\Zte\OnuRegistrationService` (API mobile; jalur C600 web juga lewat service ini). Hasil
disimpan ke `cli_script`. **Tanpa `write`.**

### `ZteC600ProvisioningScriptBuilder` — register ONU C600
Model B / SmartOLT TR069 (vport, dua service internet+mgmt, mgmt-ip in-band dari
`C600MgmtPoolService`, VEIP/ACS), diakhiri `end` + `write`. WAN pppoe/dhcp/static ditolak.

### `ZteOnuReconfigureScriptBuilder` — ubah ONU existing
`build($baseline, $target, $context)` mem-**diff** running-config (baseline) vs target form, lalu
keluarkan **hanya perintah perubahan** (tcont, gemport, service-port, service, vlan-port, WAN,
WAN-IP, TR069, Remote ONT) + ringkasan perubahan. Dipakai `configureOnuPreview` (tampilkan diff)
dan `configureOnuApply` (eksekusi). Varian `buildForRegistration()` (register mode Lanjutan) dan
`buildForCopy()` (salin ONU ke port lain) menyusun registrasi penuh dari config. Gated
`supports_onu_config_write` (mati di C600).

## C. Service CLI lain (semua via executor)

| Service | Fungsi | Method kunci |
|---------|--------|--------------|
| `ZteProfileCatalogService` | Sync & parse profil dari OLT (`show ...`) | `syncFromOlt`, `parse`, `parseTcont/Vlan/Ip/OnuTypes` |
| `ZteOnuDetailService` | Detail satu ONU (info, optik, atenuasi, history) | `fetch`, `parse` |
| `ZteOnuRunningConfigService` | Ambil running-config ONU → baseline reconfigure | `fetch`, `parse`, `applyLine`, `derivePrimaryVlan` |
| `ZteOnuRxPowerService` | RX power per-port via `show pon power onu-rx` | `portRxPower`, `parse`, `merge` |
| `ZteRemoteOnuService` | Reboot, hapus, Bind ONU (CLI) + enable/disable & set info (SNMP) | `reboot`, `delete`, `deleteMany`, `replaceSerial` (`registration-method sn`), `setActiveState`, `setInfo` |
| `ZteCardUplinkService` | Card status, uplink iface, GPON iface, VLAN, optik, deskripsi & admin-state port | `refreshCardStatus`, `refreshInterfaceDetails`, `refreshGponInterface`, `addAndTagVlan`, `setGponPortDescription`, `setGponPortAdminState`, banyak `parse*` |
| `ZteOnuCopyService` / `ZteTr069BulkService` | Batch salin ONU & TR069 massal (dijalankan job antrean) | `copy`, `run` |
| `ZteUncfgOnuService` | Discovery ONU unconfigured via CLI | `fetch` |
| `Zte\OltConfigBackupService` | Backup `show running-config` ke `olt_config_backups` (terenkripsi, dedup sha256) | `capture` |

`App\Support\CliOutputSanitizer` membersihkan output CLI mentah saat perlu.

## C-bis. Save Config (simpan running-config ke memori OLT) — semua family

Tombol ikon `Save` di daftar OLT (`Pages/SmartOlt/Index.vue`, tab ZTE & non-ZTE) memicu **write** running-config ke memori OLT (persist; beda dari **backup** ke DB — lihat `OltConfigBackupService`). Sinkron, gated capability `supports_config_save` + `throttle:olt-refresh`.

| Family | Service / method | Sekuens CLI | Route |
|--------|------------------|-------------|-------|
| ZTE | `ZteCliProvisioningExecutor::saveConfig` | login → `write` | `smartolt.config.save` |
| C-Data EPON/GPON | `CDataCliWriteService::saveConfig` | `enable` → `config` → `save` (identik EPON/GPON) | `cdata-olt.config.save` |
| HiOSO | `HiosoCliWriteService::saveConfig` | `enable` → `write` (HA7302: login 3 lapis + IAC) | `hioso-olt.config.save` |

- **ZTE C300 config besar:** perintah `write` bisa **hening ~30 detik** sebelum prompt kembali. `saveConfig` membaca via `readUntilIdle(quiet=75s, cap=120s)` → hanya prompt CLI yang menghentikan pembacaan (bukan patokan output sunyi), jadi tak berhenti prematur di tengah write.
- **Akun CLI ber-privilege rendah (prompt `ZXAN>`):** sesi yang mendarat di user-mode ditolak saat `terminal length 0` (`%Error 20200: Invalid input detected at '^' marker`) — perintah itu hanya ada di privileged mode, padahal hampir semua service CLI ZTE mendahului `show`-nya dengan pager-off tersebut. `login()` karena itu memanggil `enterPrivileged()`: bila output login berakhir prompt `>` (`hasUserModePrompt`), kirim `enable` sekali (password enable = password CLI bila diminta; prompt password dideteksi di **ekor** output, bukan `str_contains`, karena banner login memuat kata "password"). Sifatnya **best-effort** — `enable` dan `terminal length 0` masuk `BEST_EFFORT_COMMANDS`, jadi penolakannya **tidak** lagi menggagalkan seluruh sesi (dulu satu baris `%Error` dari pager-off membuat `detectError()` menandai `ok=false` → banner merah "gagal baca running-config" padahal `show`-nya berhasil).
- Konfirmasi CLI (bila muncul) dijawab otomatis; password CLI tetap di-mask dari output tersimpan.

## C-ter. Matikan/Nyalakan port PON (ZTE C300/C320)

Tombol "Matikan Port" / "Nyalakan Port" di `Pages/SmartOlt/PortDetail.vue` →
`POST smartolt.port.admin-state` (JSON, `throttle:olt-refresh`) → `SmartOltController::storePortAdminState()`
→ `ZteCardUplinkService::setGponPortAdminState()`:

```
configure terminal
interface gpon-olt_1/{slot}/{port}
shutdown            | no shutdown
exit
end
```

- **Tanpa `write`** (port menyala lagi bila OLT reboot); status port dibaca ulang setelahnya.
- Gerbang: capability `supports_port_admin_write` (C300/C320; **C600 mati**, service juga menolak
  interface selain `gpon-olt_N/N/N`) + `User::canSetPonPortAdminState()` — admin, atau partner
  **pemilik** OLT privat; operator dan partner yang sekadar di-assign → 403. Frontend menerima
  `can_set_admin_state` dari controller.
- Konfirmasi `useConfirm` (danger saat mematikan, menyebut jumlah ONU yang akan terputus).
- Audit `port.disabled|enabled|disable_failed|enable_failed`.
- Alarm `port_disabled` (major): dinaikkan langsung saat dimatikan (satu notifikasi, tanpa debounce)
  dan menahan alarm port/ONU/ODP di port itu; menyalakan menutupnya dengan satu notifikasi pulih.
  Lihat [10](10-alarm-telegram.md) dan guide ZTE §5.6b.

## D. Browser telnet (xterm.js)

Memungkinkan operator membuka terminal telnet OLT langsung di browser, tanpa membuka port telnet
OLT ke internet.

### Komponen
- **Daemon**: `php artisan telnet:proxy` (`TelnetProxyCommand` → `TelnetProxyServer`).
  Bind default `127.0.0.1:6002` (`config/telnet.php`). Di prod dijalankan supervisor
  (`kusumavision-telnet-proxy`) dan diekspos nginx sebagai `wss://domain/telnet-ws`.
- **Tiket**: `TelnetSessionController@token` (route `smartolt.telnet.token`) menerbitkan tiket
  terenkripsi (`TelnetTicket::issue(userId, oltId)`), TTL pendek (`telnet.ticket_ttl`, default
  **30 dtk**), URL-safe, **sekali pakai**. Mengembalikan `{ token, ws_url, expires_in }` dan mencatat
  audit `telnet_opened`.
- **Izin**: `User::canAccessOltSecrets($olt)` — **bukan sekadar `canManageOlt()`**. Hanya
  admin/operator (`isCentralStaff()`) atau **pemilik** OLT privat (`ownsOlt`). Partner pada OLT
  global yang sekadar di-assign mendapat 403 (`olt.telnet_forbidden`) — CLI penuh = akses ke rahasia
  perangkat. Izin yang sama
  menjaga isi backup running-config. Catatan: tombol terminal di daftar OLT tampil untuk
  `canManageOlt && cli_transport==='telnet'`, jadi penegakannya ada di server.
- **Frontend**: `Components/Shell/TelnetWindow.vue` (jendela draggable/minimize/maximize), xterm.js.

### Alur koneksi (`TelnetProxyServer`)
```
Browser ─WS upgrade(?token=…)→ proxy
  proxy: TelnetTicket::consume (exp + decrypt APP_KEY + jti di cache Redis, lalu dihanguskan)
        → gagal → 401
        → SnmpOlt::find(oltId); pastikan cli_transport=telnet + username/password ada → gagal 403
        → User::find(u)->canAccessOltSecrets(olt) DICEK ULANG saat connect → gagal 403
        → 101 Switching Protocols (locale pesan [proxy] = users.locale pemilik tiket)
        → dial telnet OLT (ip:cli_port, timeout config telnet.connect_timeout)
        → pipe dua arah; TelnetIacFilter strip/jawab IAC negotiation
        → auto-login: ketik username saat prompt login, password saat prompt password
  byte OLT → (filter IAC) → frame WS → terminal
  ketikan terminal → frame WS → telnet OLT
```
- **`TelnetTicket`** (`app/Support/Telnet/TelnetTicket.php`): enkripsi `{u,o,j,exp}` dengan
  `Crypt` (APP_KEY) → base64 URL-safe; `j` (jti acak) juga dicatat di cache bersama selama TTL.
  `verify()` cek decrypt + kedaluwarsa + jti; `consume()` sekaligus menghanguskannya, jadi tiket yang
  bocor lewat access log tak bisa dipakai ulang. Daemon tak perlu sesi DB bersama,
  tapi **wajib APP_KEY dan cache store (Redis) yang sama** dengan web.
- **`TelnetIacFilter`** (`app/Support/Telnet/TelnetIacFilter.php`): memisahkan byte data bersih
  dari perintah IAC (negotiation), dan menyusun balasan negotiation otomatis.

### Konfigurasi (`config/telnet.php` / env)
```
TELNET_PROXY_HOST=127.0.0.1
TELNET_PROXY_PORT=6002
TELNET_PROXY_WS_URL=            # prod: wss://domain/telnet-ws ; kosong di dev → ws://host:6002
TELNET_PROXY_TICKET_TTL=30
TELNET_PROXY_CONNECT_TIMEOUT=10
```

### Catatan operasional
- Daemon **long-lived** → setelah ubah kode proxy: `supervisorctl restart kusumavision-telnet-proxy`.
- OLT harus `cli_transport=telnet` + `cli_username`/`cli_password` terisi, kalau tidak token
  endpoint balas 422 dan proxy balas 403.
- Tiket habis dalam ~30 detik dan hanya berlaku untuk **satu** koneksi WS; setelah terhubung sesi
  tetap hidup. Restart daemon memutus sesi yang sedang berjalan — lakukan saat 0 sesi aktif.
- Terminal memberi CLI penuh: `no onu`, `shutdown`, `write` langsung berlaku di perangkat produksi.

## E. CLI C-Data & HiOSO (non-ZTE)

- **C-Data** — trait `App\Services\CData\Concerns\InteractsWithCDataCli` (baca **berbasis prompt**,
  login `User name:`/`Password:`, masuk `enable`/`config`/`interface {epon|gpon} 0/{slot}`), dipakai:
  `CDataCliWriteService` (rename `ont description`, reboot, enable/disable — verb beda EPON
  `ont enable|disable` vs GPON `ont activate|deactivate`, hapus `ont delete`, Remote ONT
  `ont security-mgmt` GPON V3, save), `CDataGponPortService` (VLAN & detail port firmware V3: `show vlan
  all`, buat VLAN, tag port Trunk/Hybrid dengan daftar **gabungan** lalu verifikasi `show port vlan`),
  `CDataGponCliService` (enrich inventory GPON: `show ont info all`, optical-info). Tidak ada `save`
  otomatis.
- **HiOSO** — `HiosoCliWriteService` (berdiri sendiri): `conf t` → `interface epon 0/{port}` →
  `onu {id} name|reboot|activate|deactivate`, hapus `delete onu {id}`; varian HA7302 memakai dialek
  lain (`set onu …`, login 3 lapis, IAC telnet wajib) dan rename lewat SNMP SET.
- Referensi sintaks: [`SMARTOLT_CDATA_GUIDE.md`](../SMARTOLT_CDATA_GUIDE.md) (§14 VLAN/port),
  [`SMARTOLT_HIOSO_GUIDE.md`](../SMARTOLT_HIOSO_GUIDE.md), [17](17-cdata-gpon-snmp-walk.md). Izin tulis
  VLAN/port C-Data = `canEditOltConnection` (admin/operator atau pemilik OLT privat).

## Selanjutnya

→ [10 — Alarm & Telegram](10-alarm-telegram.md)
