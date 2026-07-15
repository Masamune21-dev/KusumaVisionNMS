# Panduan SmartOLT ZTE C300 / C320 / C600 (ZXA10) — KusumaVision NMS

Terakhir diperbarui: 13 Juli 2026

Dokumen ini adalah **referensi otoritatif** integrasi OLT ZTE GPON ZXA10 (C300, C320, **dan C600 Titan**) di dalam **KusumaVision NMS** (Laravel 12 + Vue 3/Inertia). Berisi spec SNMP, OID map, CLI command, encoding ifIndex composite, parser value, transport telnet, flow provisioning/reconfigure/reboot/profile, capability matrix, data model, route, dan halaman UI — **sesuai kode yang benar-benar berjalan di repo ini**.

> **Catatan sumber.** Versi lama dokumen ini disalin dari project web BMKV lain yang berbasis Blade + kelas `ZteSnmpService`/`ZteCliSessionService`/`ZteCliProvisionService`. **Kelas-kelas itu tidak ada di repo ini.** Isi sekarang sudah dipetakan ulang ke arsitektur nyata KusumaVision NMS: SNMP read `OltSnmpClient` (+ poller Go `GoSnmpPoller`), CLI executor `ZteCliProvisioningExecutor`, script builder `ZteProvisioningScriptBuilder`, aksi ONU `ZteRemoteOnuService`, registrasi `Zte\OnuRegistrationService`, dan halaman **Vue/Inertia** (`resources/js/Pages/SmartOlt/*.vue`).

Companion docs:
- [SMARTOLT_CDATA_GUIDE.md](SMARTOLT_CDATA_GUIDE.md) — OLT non-ZTE C-Data EPON/GPON
- [SMARTOLT_HIOSO_GUIDE.md](SMARTOLT_HIOSO_GUIDE.md) — OLT non-ZTE HiOSO / V-Sol EPON
- [handbook/08-snmp-polling.md](handbook/08-snmp-polling.md) — SNMP + engine polling (Go)
- [handbook/09-cli-telnet.md](handbook/09-cli-telnet.md) — CLI/telnet & terminal browser
- [handbook/07-modul-fitur.md](handbook/07-modul-fitur.md) — overview modul SmartOLT

---

## 1. Identifikasi Device

OLT ZXA10 diidentifikasi dari kombinasi:

- `sysObjectID` (`1.3.6.1.2.1.1.2.0`) → mengandung `iso.3.6.1.4.1.3902`
- `sysDescr` (`1.3.6.1.2.1.1.1.0`) → contoh: `"ZXA10 C320 V2.x"`, `"ZXA10 C300"`, `"ZXA10 C600"`, `"ZXAN..."`
- CLI prompt setelah login: `ZXAN>` (user) → `ZXAN#` (enable/privileged) → `ZXAN(config)#` → `ZXAN(config-if)#` → `ZXAN(gpon-onu-mng)#`
- ifTable `ifDescr` mengandung `gpon-olt_1/…` / `gpon_olt-1/…` untuk port PON, `gpon-onu_1/…:z` untuk ONU

Deteksi family memakai [`SmartOltSupport::driverKey()`](../app/Support/SmartOltSupport.php) — substring case-insensitive terhadap gabungan `vendor` + `name` + `sysDescr` + `sysObjectID`:

```
zte | 3902 | c300 | c320 | c600      → DRIVER_ZTE
```

ZTE diperiksa **paling dulu** (sebelum family non-ZTE) supaya needle `epon` milik C-Data tidak salah menangkap OLT ZTE. Bila tidak ada family lain yang match, driver = `DRIVER_UNKNOWN` (semua capability mati).

### 1.1 Deteksi C600 (Titan)

C600 adalah platform Titan dengan **subtree SNMP modern `.1082` (zxAccessNode)**. Deteksinya terpisah dari `driverKey()`, memakai [`SmartOltSupport::isC600()`](../app/Support/SmartOltSupport.php) — substring `c600` pada `vendor` + `name` + `last_test_result.system.sys_descr`, **atau** `sysObjectID` mengandung `3902.1082.1001.600` (jalur kedua ini yang menyelamatkan C600 baru yang belum di-Test dan namanya tak mengandung "C600"). Jadi:

- driver tetap `DRIVER_ZTE` (C600 berbagi seluruh jalur ZTE),
- `isC600()` menyalakan cabang khusus C600 di dalam driver (OID `.1082`, ifIndex 4-komponen, tanpa OID deskripsi terpisah).

> **Baca [`SMARTOLT_ZTE_C600_GUIDE.md`](SMARTOLT_ZTE_C600_GUIDE.md) untuk apa pun yang menyangkut C600.** Dokumen ini otoritatif untuk C300/C320; bagian C600-nya dulu berisi OID yang tak pernah diuji ke perangkat dan sudah dikoreksi.

Ringkas perbedaan interface (lihat [`gponOltInterface()`](../app/Support/SmartOltSupport.php#L153) / [`onuInterfaceId()`](../app/Support/SmartOltSupport.php#L146)):

| | C300 / C320 | C600 |
|---|---|---|
| Port PON | `gpon-olt_1/{slot}/{port}` | `gpon_olt-1/{slot}/{port}` |
| ONU | `gpon-onu_1/{slot}/{port}:{onuId}` | `gpon_onu-1/{slot}/{port}:{onuId}` |
| Uplink | `xgei_1/{slot}/{port}` | `xgei-1/{slot}/{port}` |
| `port_name_prefix` capability | `gpon-olt_1` | `gpon_olt-1` |

**Keduanya 3-tier** — yang beda hanya ejaannya. C600 **tidak** memakai 4-tier `gpon-olt_1/1/…`; klaim itu (versi lama dokumen ini) terbantah oleh running-config C600 asli.

---

## 2. Spesifikasi Koneksi

### 2.1 SNMP (read + write)

| Parameter | Value | Catatan |
|---|---|---|
| Version | **v1 / v2c saja** | `snmp_version` di `snmp_olts`; **v3 melempar exception** di `OltSnmpClient` |
| Community read | per-device (`public` default operator) | terenkripsi at-rest di `snmp_olts.snmp_read_community` (`encrypted` cast + `$hidden`) |
| Community write | beda dari read (`private`/custom) | dipakai `setActiveState`, `setInfo` (nama/deskripsi) |
| UDP port | 161 default; non-standar via `snmp_port` | host call: `IP` atau `IP:PORT` (`SnmpOlt::getHostAddress()`) |
| Timeout | detik-an per call; override untuk walk besar | |
| Enterprise root | `1.3.6.1.4.1.3902` | semua tabel ZTE-specific |

Implementasi SNMP read: [`app/Services/Snmp/OltSnmpClient.php`](../app/Services/Snmp/OltSnmpClient.php) — `systemInfo()`, `snapshot()` (system + boards + ports + ONU + Rx), `getRegisteredOnusByPort()`, `getPortRxMap()`, `getUnconfiguredOnus()`. SNMP write per-ONU: [`app/Services/ZteRemoteOnuService.php`](../app/Services/ZteRemoteOnuService.php) (`setActiveState`, `setInfo`).

**Engine polling terjadwal = Go.** `bin/kv-snmp-poller` (source [`cmd/kv-snmp-poller/main.go`](../cmd/kv-snmp-poller/main.go)) di-shell-out lewat [`GoSnmpPoller`](../app/Services/Snmp/GoSnmpPoller.php) (Symfony Process → JSON). Aktif bila `SNMP_POLLER_DRIVER=go` (`config/services.php`) dan binary ada. [`PollOltJob`](../app/Jobs/PollOltJob.php) memakai poller Go dan **fallback ke `OltSnmpClient`** bila gagal. Refresh on-demand (tombol UI, per-port RX, provisioning) tetap PHP sinkron via `OltSnmpClient`/executor. Detail: [handbook/08-snmp-polling.md](handbook/08-snmp-polling.md).

### 2.2 CLI Telnet

| Parameter | Value |
|---|---|
| Transport | **Telnet saja** — `ZteCliProvisioningExecutor` **menolak SSH** (phpseclib terpasang tapi driver SSH belum diwire) |
| TCP port | `cli_port` (default 23) |
| Line ending | `\n` (ZTE toleran; beda dengan HiOSO yang wajib CRLF) |
| Login | landing **langsung di privileged EXEC `#`** pada banyak firmware ZXA10 |
| Prompt | `ZXAN>` (user) · `ZXAN#` (enable) · `ZXAN(config)#` · `ZXAN(config-if)#` · `ZXAN(gpon-onu-mng)#` |

Implementasi CLI: [`app/Services/ZteCliProvisioningExecutor.php`](../app/Services/ZteCliProvisioningExecutor.php) — sesi telnet, auto-continue pager `--More--`, masking password CLI di output tertangkap, `readUntilIdle()` untuk command yang bisa hening lama (mis. `write`). Terminal browser interaktif (xterm.js) juga **telnet-only**, lewat proxy WebSocket↔telnet [`TelnetProxyServer`](../app/Services/Telnet/TelnetProxyServer.php) (`php artisan telnet:proxy`) + tiket terenkripsi [`TelnetSessionController`](../app/Http/Controllers/TelnetSessionController.php). Lihat [handbook/09-cli-telnet.md](handbook/09-cli-telnet.md).

---

## 3. Encoding ifIndex Composite ZTE (kritikal)

ONU-table ZTE di-index `{ifIndex}.{onuId}` dengan ifIndex composite. Encoding **beda antara C300/C320 dan C600** — lihat [`OltSnmpClient::zteEncodeIfIndex()`](../app/Services/Snmp/OltSnmpClient.php#L902) / [`decodeIfIndex()`](../app/Services/Snmp/OltSnmpClient.php#L916).

### 3.1 C300 / C320 — Type-1 composite

```
ifIndex = 0x10000000 | (slot << 16) | (port << 8)
slot = (ifIndex >> 16) & 0xFF ;  port = (ifIndex >> 8) & 0xFF
```

| Slot | Port | ifIndex (hex) | ifIndex (dec) |
|---|---|---|---|
| 1 | 1 | `0x10010100` | `268501760` |
| 1 | 2 | `0x10010200` | `268501952` |
| 2 | 1 | `0x10020100` | `268567296` |

### 3.2 C600 — composite 4-komponen (type|rack|shelf|slot|port)

> Ini soal **ifIndex**, bukan nama CLI. ifIndex C600 memang berkomponen 4, tapi nama interface-nya tetap **3-tier** (`gpon_olt-1/{slot}/{port}`) — shelf tak muncul di nama. Jangan campur keduanya.

```
ifIndex = (1<<28) | (1<<24) | (1<<16) | (slot << 8) | port     # type|rack|shelf|slot|port
slot = (ifIndex >> 8) & 0xFF ;  port = ifIndex & 0xFF
```

> **Gotcha yang sudah ditambal (C320).** Prefix-index tabel ONU C320 bertabrakan dengan ifIndex IF-MIB port di slot+1 → ONU slot 1 bisa "nyasar" ke slot 2. Fix ada di `buildPortMap`/`decodeIfIndex` + walk ONU **per-port** (scoped by prefix ifIndex). C600 **tidak** punya collision ini (tabel ONU-nya di-index ifIndex IF-MIB asli), dan sejak Juli 2026 ikut jalur scoped — full-walk C600 sempat terukur ~151 detik per port.

Parse slot/port dari nama port lebih andal daripada bit-decode murni; parser IF-MIB mencocokkan pola **4-tier lebih dulu** baru 3-tier ([`OltSnmpClient::parseSlotPort()`](../app/Services/Snmp/OltSnmpClient.php)). Perhatikan sumber namanya beda per-family: C320 memakai `ifName` = `gpon_1/2/1`, C600 memakai `ifName` = `gpon_olt-1/3/1` (**3-tier**, sedangkan `ifDescr` C600 berisi nama area pelanggan, bukan nama interface) — detail di [`SMARTOLT_ZTE_C600_GUIDE.md` §3](SMARTOLT_ZTE_C600_GUIDE.md).

---

## 4. OID Reference (ZTE 3902.*)

### 4.1 System & Chassis

| Objek | OID | Catatan |
|---|---|---|
| `sysDescr` / `sysUptime` / `sysName` | `1.3.6.1.2.1.1.{1,3,5}.0` | identitas standar |
| Board name/status | `1.3.6.1.4.1.3902.1015.2.1.1.3.1.{4,5}` | index `rack.shelf.slot` |
| Board CPU / Mem / PhyMem | `…1015.2.1.1.3.1.{9,11,19}` | per-board (cache `smartolt_card_statuses`), cermin CLI `show processor` |
| `ifDescr` / `ifOperStatus` | `1.3.6.1.2.1.2.2.1.{2,8}` | port PON (`1=up`, `2=down`) |

### 4.2 Tabel ONU — C300 / C320 (`.1012.3.28.*` & `.1012.3.50.*`)

| Kolom | OID | Akses | Catatan |
|---|---|---|---|
| ONU type | `…3902.1012.3.28.1.1.1` | RO | tipe (mis. `F660`, `ALL-ONT`) |
| ONU name | `…3902.1012.3.28.1.1.2` | **RW** | nama tampilan (SNMP write) |
| ONU description | `…3902.1012.3.28.1.1.3` | **RW** | deskripsi (SNMP write) |
| ONU SN | `…3902.1012.3.28.1.1.5` | RO | 8-byte, format `ZTEG12345678` |
| ONU admin state | `…3902.1012.3.28.1.1.17` | **RW** | `1=active`, `2=disabled` |
| ONU phase state | `…3902.1012.3.28.2.1.4` | RO | `3=Working` = online |
| ONU last-down cause | `…3902.1012.3.28.2.1.7` | RO | penyebab down terakhir |
| ONU Rx (per-port) | `…3902.1012.3.50.12.1.1.10` | RO | index `{ifIndex}.{onuId}` |

Phase state enum ([`decodePhaseState`](../app/Services/Snmp/OltSnmpClient.php#L1047)):

```
0 Logging · 1 LOS · 2 Sync MIB · 3 Working(online) · 4 DyingGasp · 5 Auth Failed · 6 Offline
```

Last-down-cause enum ([`decodeLastDownCause`](../app/Services/Snmp/OltSnmpClient.php#L1075)):

```
0 Normal · 1 LOS · 2 LOSi · 3 LOFi · 4 SFi · 5 LOAi · 6 LOAMi · 7 Deactivated · 8 Manual · 9 DyingGasp
```

Interpretasi `last_down_cause` ke bahasa Indonesia di UI: [`resources/js/lib/onu.js`](../resources/js/lib/onu.js) (`lastDownCauseLabel`).

### 4.3 Tabel ONU — C600 → lihat guide terpisah

> **Seluruh OID C600 yang pernah ada di section ini SALAH** — dijawab *No Such Object* oleh C600 asli
> (cabang `1082.500.10.2.3/.2.8/.2.11` tidak ada di perangkat), sehingga C600 mana pun terbaca **0 ONU**.
> Sudah ditambal 15 Juli 2026 setelah pemetaan live.

Tabel ONU C600 yang benar ada di basis **`.1082.500.20.2.1.2.1.*`** (index `{ifIndex}.{onuId}`), dengan
`.3` = SN, `.7` = status online (`1`=Working, `2`=Offline), `.8` = model.

**Referensi lengkap + apa yang belum terpetakan (nama ONU, admin-state, Rx, unconfigured):**
[`SMARTOLT_ZTE_C600_GUIDE.md`](SMARTOLT_ZTE_C600_GUIDE.md). Section ini hanya berlaku untuk C300/C320.

### 4.4 Konversi Rx Power (multi-scale)

`OltSnmpClient::convertOnuRxPowerToDbm()` auto-detect scale berdasarkan magnitude raw (C300/C320): sentinel no-signal → `null`; milli-dBm (`-50000..-3000` → `/1000`); 0.1-dBm (`-500..-5` → `/10`); legacy positif (`raw>0 → raw*0.002-30`). Scale dipilih dari magnitude, bukan flag firmware — terbukti benar di C320 live (`5635 → -18.73 dBm`, cocok CLI `-18.762 dBm`).

**C600 tak punya Rx via SNMP** — tak ada tabel optik di seluruh cabang `1082.500` pada perangkat asli, jadi `supports_snmp_rx=false` dan `onuRxPowers()` mengembalikan `[]`.

### 4.5 ONU Unconfigured / Auto Discovery

OID kandidat (`OltSnmpClient::ZTE_UNCFG_OIDS` untuk C300/C320, `C600_UNCFG_OIDS` untuk C600):

| Firmware | OID |
|---|---|
| C320 V1.2.x | `…3902.1012.3.13.3.1.2` |
| v2.x SN/MAC/alt | `…3902.1082.500.10.2.1.1` · `…10.2.1.2` · `…10.1.1.1` |
| **C600** | **belum terpetakan** (`C600_UNCFG_OIDS = []`) — OID lama `…10.2.2.1.2` tak ada di perangkat asli; halaman Unconfigured kosong untuk C600 |

Halaman Unconfigured (`Pages/SmartOlt/Unconfigured.vue`) + service on-demand [`ZteUncfgOnuService`](../app/Services/ZteUncfgOnuService.php); ada juga cross-OLT `Pages/SmartOlt/UnconfiguredGlobal.vue`.

---

## 5. CLI Reference ZTE

### 5.1 Mode Navigation

```text
> [telnet cli_port]
ZXAN#                              # ZXA10 sering langsung mendarat di privileged EXEC
ZXAN# configure terminal           # → ZXAN(config)#  (alias: conf t)
ZXAN(config)# interface gpon-olt_1/{slot}/{port}          # C300/C320
ZXAN(config)# interface gpon_olt-1/{slot}/{port}          # C600 (3-tier, eja `gpon_olt-`)
ZXAN(config)# interface gpon-onu_1/{slot}/{port}:{onuId}  # (C600: gpon_onu-1/{slot}/{port}:{onuId})
ZXAN(config)# pon-onu-mng gpon-onu_1/…:{onuId}
ZXAN(config)# end
```

Interface string di-generate lewat helper `SmartOltSupport::gponOltInterface()` / `onuInterfaceId()` yang memilih ejaan menurut `isC600()` — **keduanya 3-tier**; C600 hanya beda eja (`gpon_olt-` / `gpon_onu-` vs `gpon-olt_` / `gpon-onu_`). Klaim "C600 = 4-tier" di versi lama dokumen ini **salah**, lihat [`SMARTOLT_ZTE_C600_GUIDE.md` §3.1](SMARTOLT_ZTE_C600_GUIDE.md). Executor mengirim `terminal length 0` bila perlu dan meng-handle pager `--More--` otomatis.

### 5.2 Show Commands (read-only)

| Kebutuhan | Command |
|---|---|
| State ONU per port | `show gpon onu state gpon-olt_1/…/{port}` |
| Rx per ONU | `show pon power onu-rx gpon-olt_1/…/{port}` — dipakai [`ZteOnuRxPowerService`](../app/Services/ZteOnuRxPowerService.php) |
| Detail per ONU | `show gpon onu detail-info gpon-onu_1/…:{onuId}` — [`ZteOnuDetailService`](../app/Services/ZteOnuDetailService.php) (§6) |
| Atenuasi up/down | `show pon power attenuation gpon-onu_1/…:{onuId}` |
| Running-config ONU | `show running-config interface gpon-onu_1/…:{onuId}` — [`ZteOnuRunningConfigService`](../app/Services/ZteOnuRunningConfigService.php) (§7) |
| Profile TCONT/VLAN/IP/ONU-type | `show gpon profile tcont` · `show gpon onu profile vlan` · `show gpon onu profile ip` · `show onu-type` — [`ZteProfileCatalogService`](../app/Services/ZteProfileCatalogService.php) |
| Backup running-config | `show running-config` — [`Zte\OltConfigBackupService`](../app/Services/Zte/OltConfigBackupService.php) (§14) |

> `SMARTOLT_ZTE_C300_C320_C600_GUIDE.md` (dokumen ini) adalah **referensi CLI otoritatif** — konsultasikan sebelum menebak sintaks command.

### 5.3 Provisioning ONU Baru

Script dibangun [`ZteProvisioningScriptBuilder`](../app/Services/ZteProvisioningScriptBuilder.php), dijalankan via [`Zte\OnuRegistrationService`](../app/Services/Zte/OnuRegistrationService.php) → `ZteCliProvisioningExecutor`. Setiap registrasi menulis baris audit ke `smartolt_onu_registrations` (script dibuat dulu, dieksekusi belakangan/opsional).

```
conf t
interface gpon-olt_1/…/{port}
  onu {onuId} type {ONU_TYPE} sn {SN}
  exit
interface gpon-onu_1/…:{onuId}
  name {NAME}
  description {onuId}$${NAME}$$          # C600: dilewati (tak ada deskripsi terpisah)
  tcont 1 name 1 profile {TCONT_PROFILE}
  gemport 1 name 1 tcont 1
  encrypt 1 enable downstream
  service-port 1 vport 1 user-vlan {VLAN} vlan {VLAN}
  exit
pon-onu-mng gpon-onu_1/…:{onuId}
  service {SERVICE_NAME} gemport 1 cos 0 vlan {VLAN}
  [tr069-mgmt 1 state unlock] [tr069-mgmt 1 acs {ACS_URL} validate basic username {U} password {P}]
  wan-ip 1 mode {pppoe|dhcp|static} …
  [security-mgmt {ID} state enable mode {…} protocol {…}]   # Remote ONT
  wan-ip 1 ping-response enable traceroute-response enable
  exit
```

| WAN mode | Command |
|---|---|
| PPPoE | `wan-ip 1 mode pppoe username {U} password {P}` (username auto dari `name` bila kosong) |
| DHCP | `wan-ip 1 mode dhcp` |
| Static | `wan-ip 1 mode static ip-profile {P} ip-address {IP} mask {255.255.255.0}` |
| Bridge | tanpa baris `wan-ip`; tambah `switchport mode hybrid vport 1` + `service … type internet …` |

`description` di-encode `{onuId}$${name}$$` agar onuId bisa di-parse balik dari running-config; ACS default dari `config('services.acs')` (`ACS_URL`/`ACS_USERNAME`/`ACS_PASSWORD` di `.env`, tak ada kredensial di repo). Form register lanjutan (`Pages/SmartOlt/RegisterOnu.vue`) punya jalur preview+store (`smartolt.register.*`, `…register.advanced.*`) dengan default dari [`Zte\OnuRegistrationFormDefaults`](../app/Services/Zte/OnuRegistrationFormDefaults.php).

### 5.4 Reconfigure ONU Existing (delta)

[`ZteOnuReconfigureScriptBuilder::build()`](../app/Services/ZteOnuReconfigureScriptBuilder.php): baca running-config live (`ZteOnuRunningConfigService`), bandingkan dengan payload form, emit hanya baris yang berubah (name/tcont/gemport/service-port, tr069 lock/unlock, security-mgmt enable/disable, wan-ip per-index). `buildForCopy()` dipakai fitur **copy ONU ke port lain**. C600: cabang `is_c600` melewati baris deskripsi. Bila tak ada perubahan → script kosong → "Tidak ada perubahan config untuk di-apply".

### 5.5 Reboot ONU (CLI)

[`ZteRemoteOnuService::reboot()`](../app/Services/ZteRemoteOnuService.php) → `pon-onu-mng gpon-onu_1/…:{onuId}` → `reboot`, auto-konfirmasi bila muncul prompt `yes/no|are you sure|confirm|…`. Route `smartolt.onu.reboot`.

### 5.6 Enable / Disable & Edit Nama-Deskripsi ONU (SNMP)

Toggle & edit info ZTE lewat **SNMP SET** (lebih cepat dari CLI) di [`ZteRemoteOnuService`](../app/Services/ZteRemoteOnuService.php):

```
# enable/disable  (admin-state OID)
SET .1012.3.28.1.1.17.{ifIndex}.{onuId}  i  1|2      # C300/C320
# nama / deskripsi
SET .1012.3.28.1.1.2/.3.{ifIndex}.{onuId}  s  "…"    # C300/C320
```

> **C600: kedua write ini TERTUTUP.** OID C600 yang dulu tertulis di sini (`.1082.500.10.2.8.1.1` untuk
> admin-state, `.1082.500.10.2.3.1.2` untuk nama) **tidak ada di perangkat asli** — SET ke sana berarti
> menulis ke OID sembarang di OLT. `supports_onu_toggle` & `supports_onu_info_write` = `false` untuk C600,
> dan `ZteRemoteOnuService` melempar `RuntimeException` bila tetap dipanggil. Jangan dibuka dengan OID
> tebakan; lihat [`SMARTOLT_ZTE_C600_GUIDE.md` §5](SMARTOLT_ZTE_C600_GUIDE.md).

Route: `smartolt.onu.state` (setState), `smartolt.onu.info` (updateOnuInfo). Delete ONU (`no onu {id}`, gated `supports_onu_delete`) via route `smartolt.onu.delete`.

### 5.7 Simpan Konfigurasi OLT (`write`)

Aksi OLT-level (bukan per-ONU): persist running-config ke memori. Tombol **"Save Config"** di daftar OLT → [`ZteCliProvisioningExecutor::saveConfig`](../app/Services/ZteCliProvisioningExecutor.php), route `smartolt.config.save`, gated `supports_config_save` + `throttle:olt-refresh`.

```
> write
```

ZXA10 landing di `#` sehingga `write` langsung jalan. **C300 config besar bisa hening ~30 detik**; `saveConfig` membaca `readUntilIdle(quiet=75s, cap=120s)` — berhenti hanya saat prompt CLI kembali, bukan patokan output sunyi. Berbeda dari **backup config** (§14) yang menyalin `show running-config` terenkripsi ke DB.

### 5.8 Profile Management

Katalog profil (ONU type, TCONT, VLAN, IP) di [`SmartOltProfileController`](../app/Http/Controllers/SmartOltProfileController.php) + [`ZteProfileCatalogService`](../app/Services/ZteProfileCatalogService.php); disimpan ke tabel **`smartolt_profiles`** (unique `snmp_olt_id + profile_type + name`, fallback global `snmp_olt_id = null`). Sinkron dari OLT jalankan 4 command `show …` lalu parse & upsert. Route: `smartolt.profiles.{index,store,sync,update,destroy}`, halaman `Pages/SmartOlt/Profiles.vue`.

### 5.9 Error Patterns

`ZteCliProvisioningExecutor::extractCliError()` menandai command gagal bila output mengandung (case-insensitive): `invalid input`, `unknown command`, `ambiguous command`, `incomplete command`, `command rejected`, `permission denied`, `authorization failed`, `not support`, `operation failed`, `failure:`, `error:`, `% bad`, `% invalid`. Baris yang berakhir prompt `(config-…)#` di-skip.

---

## 6. Parsing `show gpon onu detail-info`

[`ZteOnuDetailService`](../app/Services/ZteOnuDetailService.php) — `parse()`, `applyAttenuation()`, `applySessionHistory()`. Dual-pass: (1) split tiap `Key: Value` → `snake_case`, skip echo command & prompt; (2) bucket ke grup **identity / state / optical / last_event / all** dengan exact-key-match dulu lalu substring fallback (toleran beda spelling firmware). Suplemen `show pon power attenuation` mengisi Rx/Tx bila `detail-info` tak mencantumkannya (sering di C320 V2.x). Halaman: `Pages/SmartOlt/OnuDetail.vue` (route `smartolt.onu.detail`, gated `supports_cli_onu_detail`).

---

## 7. Parsing Running-Config (pre-fill Configure)

[`ZteOnuRunningConfigService`](../app/Services/ZteOnuRunningConfigService.php) — `parse()`, `normalizeLines()`. Jalankan `show running-config interface gpon-onu_1/…:{onuId}` (+ `show onu running config …` bila ada). `normalizeLines` merapikan line-wrap ZTE (repair token split khas firmware), lalu recognize `name`, `description`, `tcont`, `gemport` (bentuk pendek **dan** panjang `gemport 1 name 1 unicast tcont 1 dir both`), `service-port`, `service … type …`, `vlan port`, `wan-ip`, `wan N service …`, `tr069-mgmt`, `security-mgmt`. Field mask (`static_mask_length` ↔ `static_netmask`) saling konversi. Halaman: `Pages/SmartOlt/ConfigureOnu.vue` + komponen editor `Components/SmartOlt/OnuConfigEditor.vue` (route `smartolt.onu.configure`, gated `supports_cli_onu_configure`).

> **Gotcha line-wrap.** Running-config ZTE char-wrap bisa memotong nilai di tengah token; `normalizeLines` harus menggabungkan verbatim (tanpa spasi).

---

## 8. Data Model Lokal

### 8.1 `snmp_olts` (inventory OLT)

Model [`SnmpOlt`](../app/Models/SnmpOlt.php). Kolom sensitif (`snmp_read_community`, `snmp_write_community`, `cli_password`) pakai `encrypted` cast + `$hidden`; saat edit, field secret kosong **mempertahankan nilai lama** (`withoutEmptySecrets`). Kolom penting: `vendor`, `ip`, `snmp_port`, `snmp_version` (v1/v2c/v3), `cli_transport` (telnet/ssh), `cli_username`, `cli_port`, `polling_enabled`, `alarms_enabled`, `config_backup_enabled`, `owner_user_id`, `last_test_result` (JSON cache snapshot — system/ports/`port_onus`). Per-OLT live state di-cache di `last_test_result` (tidak ada tabel `onus`).

### 8.2 `smartolt_profiles`

`id`, `snmp_olt_id` (FK cascade, nullable = global), `profile_type` (`onu_type|tcont|vlan|ip`), `name`, `source` (default `manual`), `vlan`, `params` (JSON), `is_active`, `notes`, `last_synced_at`, timestamps; unique `(snmp_olt_id, profile_type, name)`.

### 8.3 `smartolt_onu_registrations`

Audit trail provisioning + reconfigure: `snmp_olt_id`, `serial_number`, `slot`, `port`, `onu_id`, `pon_port`, `oid_index`, `customer_name`, `onu_type`, `tcont_profile`, `vlan`, `vlan_profile`, `service_name`, `wan_mode` (enum pppoe/dhcp/static), `pppoe_username`, `pppoe_password` (⚠ text/plaintext — batasi akses), `ip_profile`, `static_ip`, `static_netmask`, `cli_script` (longtext), `status` (`generated|executed|failed|reconfigured|…`), `created_by`; + eksekusi (`execution_output`, `execution_error`, `executed_at`, `executed_by`); + TR069/Remote ONT (`tr069_enabled`, `acs_url/username/password`, `remote_ont_enabled/id/mode/protocol`).

### 8.4 Tabel pendukung

`onu_rx_samples` (history RX per-ONU, disampel `PollOltJob`, di-chart `RxTrendCard`/`PortDetail`/`OnuDetail`), `alarm_events` (+ `alarm_settings`), `polling_events`, `smartolt_card_statuses` / `smartolt_interface_statuses` (hardware cache), `copy_onu_tasks` (§14), `tr069_bulk_tasks` (§14), `olt_config_backups` (§14), `onu_map_pins` (peta ONU), `olt_user` (assignment partner + `alarms_enabled` pivot).

---

## 9. HTTP Routes & Controllers

Controller utama: [`SmartOltController`](../app/Http/Controllers/SmartOltController.php) + [`SmartOltProfileController`](../app/Http/Controllers/SmartOltProfileController.php) + [`OltConfigBackupController`](../app/Http/Controllers/OltConfigBackupController.php). Semua di `routes/web.php`, auth-protected; write digated `role:admin,operator,partner` + kepemilikan (`PartnerOltScope`).

### 9.1 Web Routes (Inertia)

| Method | URI | Route name |
|---|---|---|
| GET | `/smartolt` | `smartolt.index` |
| GET/POST | `/smartolt/create` · `/smartolt` | `smartolt.create` · `smartolt.store` |
| GET/PUT/DELETE | `/smartolt/{olt}/edit` · `/smartolt/{olt}` | `smartolt.edit/update/destroy` |
| POST | `/smartolt/{olt}/test` · `/smartolt/{olt}/refresh` | `smartolt.test` · `smartolt.refresh` |
| GET | `/smartolt/{olt}/detail` · `/gpon-ports` · `/port-detail` | `smartolt.detail` · `smartolt.gpon-ports` · `smartolt.port.detail` |
| GET | `/smartolt/{olt}/ports/{slot}/{port}/onus` | `smartolt.port-onus` |
| POST | `…/ports/{slot}/{port}/onus/refresh` · `…/copy` | `smartolt.port-onus.refresh` · `smartolt.port-onus.copy` |
| GET | `/smartolt/{olt}/unconfigured` · `/smartolt/unconfigured` | `smartolt.unconfigured` · `smartolt.unconfigured-all` |
| GET/POST | `/smartolt/{olt}/register` (+ `/preview`, `/advanced…`) | `smartolt.register*` |
| GET/POST/DELETE | `/smartolt/{olt}/registrations…` | `smartolt.registrations*` |
| GET…DELETE | `/smartolt/{olt}/profiles…` | `smartolt.profiles*` |
| POST | `…/onus/{onuId}/{reboot,delete,state,info}` | `smartolt.onu.{reboot,delete,state,info}` |
| GET | `…/onus/{onuId}/detail` · `/configure` | `smartolt.onu.detail` · `smartolt.onu.configure` |
| POST | `…/onus/{onuId}/configure/preview` · `/configure` | `smartolt.onu.configure.preview` · `.apply` |
| POST | `/smartolt/{olt}/config/save` | `smartolt.config.save` |
| GET…GET | `/smartolt/{olt}/config-backups…` | `smartolt.config-backups.{index,store,toggle,content,download}` |
| POST | `…/tr069-bulk` · `…/copy-tasks/{task}` · `…/tr069-bulk/{task}` | `smartolt.tr069-bulk*` · `smartolt.copy-task.status` |
| POST | `/smartolt/{olt}/alarms/toggle` · `/telnet/token` | `smartolt.alarms.toggle` · `smartolt.telnet.token` |
| GET/POST | `/onu-monitoring` · `/onu-monitoring/{olt}/refresh` | `monitoring.onu` · `monitoring.onu.refresh` |

### 9.2 REST API v1 (mobile / programmatic)

`routes/api.php`, prefix `v1`, Sanctum bearer + `throttle:api`. Read: `api.summary`, `api.olts.index/show`, `api.olts.port-onus`, `api.olts.onu.show`, `api.olts.unconfigured`, `api.olts.register.options`, `api.onus.index`, `api.alarms.index`, `api.search`. Write (`role:admin,operator,partner` + `BlockDemoWrites`): `api.olts.register.preview/store`, `api.olts.unconfigured.refresh`, `api.olts.port.refresh`, `api.olts.onu.reboot/name`. Controller di [`app/Http/Controllers/Api/V1/`](../app/Http/Controllers/Api/V1/). Docs: [API.md](API.md).

---

## 10. Flow Lengkap (User Journey)

```
/smartolt (Index.vue)  → daftar OLT (scoped assignment), badge vendor+capability, tombol per family
  → Detail.vue         → chassis + grid PON port (Up/Down + online/total ONU)
  → PortOnus.vue       → tabel ONU (SN, type, phase, admin, Rx, Tx, model, last-down, LAN)
       per-row: Edit Info · Reboot · Enable/Disable · Delete · Detail (CLI) · Configure (CLI)
       tools: Copy ONU ke port · TR069 Massal · Refresh per-port · Add Map
  → OnuDetail.vue / ConfigureOnu.vue
Unconfigured.vue       → discovery SN → Register
RegisterOnu.vue        → provisioning (preview → store, opsional execute)
Registrations.vue      → riwayat audit (execute/hapus baris)
Profiles.vue           → katalog ONU type/TCONT/VLAN/IP (+ sync dari OLT)
ConfigBackups.vue      → riwayat backup, toggle harian, backup manual, diff versi
```

---

## 11. Capabilities Matrix (ZTE)

Dari [`SmartOltSupport::capabilities(DRIVER_ZTE, $olt)`](../app/Support/SmartOltSupport.php#L192) — beberapa nilai bergantung `isC600()`:

```json
{
  "driver": "zte",
  "vendor_family": "ZTE GPON",            // C600 → "ZTE GPON (C600)"
  "pon_label": "GPON", "port_label": "GPON Port",
  "port_name_prefix": "gpon-olt_1",       // C600 → "gpon_olt-1"
  "onu_interface_pattern": "gpon-onu_1/%d/%d:%d",   // C600 → "gpon_onu-1/%d/%d:%d" (3-tier)
  "is_c600": false,
  "supports_snmp_rx": true, "supports_cli_rx": true,   // C600 → supports_snmp_rx: false (tak ada tabel Rx SNMP)
  "supports_cli_onu_detail": true, "supports_cli_onu_configure": true,
  "supports_reboot": true, "reboot_mode": "cli",
  "supports_provisioning": true,
  "supports_onu_delete": true,
  "supports_separate_description": true,  // C600 → false
  "supports_onu_info_write": true, "description_mode": "snmp",   // C600 → false (OID nama tak terpetakan)
  "supports_onu_toggle": true,            // C600 → false (OID admin-state tak terpetakan)
  "supports_config_save": true,
  "rx_source_label": "Rx ONU (SNMP)"      // C600 → "Rx ONU (CLI)"
}
```

ZTE adalah **satu-satunya driver dengan `supports_provisioning`, `supports_cli_onu_detail`, `supports_cli_onu_configure`** — itulah kenapa tombol Register/Configure/Detail-CLI hanya muncul untuk OLT ZTE (bandingkan dengan C-Data/HiOSO di companion guide).

---

## 12. Perbedaan C600 (ringkasan)

| Aspek | C300 / C320 | C600 |
|---|---|---|
| Deteksi | `isC600()` false | `isC600()` true (substring `c600` **atau** sysObjectID `3902.1082.1001.600`) |
| Interface | `gpon-olt_1/{s}/{p}` | `gpon_olt-1/{s}/{p}` (**3-tier**, beda eja saja) |
| ifIndex ONU | `0x10000000\|slot<<16\|port<<8` | `1<<28\|1<<24\|1<<16\|slot<<8\|port` |
| Subtree ONU | `.1012.3.28.*` / `.50.*` | `.1082.500.20.2.1.2.1.*` (zxAccessNode) |
| Provisioning | ✅ | ❌ (`supports_provisioning=false`, sintaks beda struktur) |
| Phase enum | mulai 0 (`3=Working`) | mulai 1 (`4=Working`) |
| Rx | multi-scale auto | OLT-side `raw/1000 = dBm` |
| Deskripsi ONU | ada (`.28.1.1.3`) | **tidak ada** OID terpisah |
| Unconfigured | 4 OID kandidat | `.1082.500.10.2.2.1.2` |
| Kartu/uplink | 3-tier `xgei_1/{slot}` | 4-tier `xgei-1/1/{slot}` ([`ZteCardUplinkService`](../app/Services/ZteCardUplinkService.php)) |
| Provisioning / reboot / toggle / rename | ✅ | ✅ (interface & OID otomatis dipilih via `isC600`) |

Referensi vendor C600 (PDF di `docs/`): *C600 SNMP OID Management Guide*, *SNMP ifIndex Structure and Calculation*, *SNMP OIDs for ONU Optical Power*, *ONU Admin Status SNMP Configuration*, *Line Card Identification and CLI Codes*, *C600 vs C300 CLI Command Migration Guide*, *SNMP Discovery Guide for C600 Unconfigured ONUs*.

---

## 13. Fitur Modul Khas Repo Ini

- **Copy ONU ke port** (batch) — baca running-config tiap ONU sumber (1 sesi telnet), rebuild registrasi penuh via `ZteOnuReconfigureScriptBuilder::buildForCopy()`, dijalankan queued [`CopyOnusToPortJob`](../app/Jobs/CopyOnusToPortJob.php) + tabel `copy_onu_tasks`, progress modal (route `smartolt.port-onus.copy`, status `smartolt.copy-task.status`).
- **TR069 Massal per-port** — aktifkan TR069/ACS di **semua ONU satu PON port** ([`ZteTr069BulkService`](../app/Services/ZteTr069BulkService.php), queued [`Tr069BulkConfigJob`](../app/Jobs/Tr069BulkConfigJob.php) + `tr069_bulk_tasks`), dua fase dry-run→execute, skip ONU yang TR069+ACS-nya sudah benar. Modal `Components/SmartOlt/Tr069BulkModal.vue`, gated `supports_cli_onu_configure`.
- **Backup konfigurasi OLT** — [`Zte\OltConfigBackupService`](../app/Services/Zte/OltConfigBackupService.php) ambil `show running-config`, simpan ke `olt_config_backups` (`content` encrypted + `$hidden`, `sha256` dedup, `trigger` manual/scheduled). Terjadwal harian (`config_backup_enabled` → `BackupOltConfigsCommand` `olts:backup-config` di `routes/console.php`, `dailyAt 02:30` → `BackupOltConfigJob`); manual sinkron. Halaman `Pages/SmartOlt/ConfigBackups.vue` (diff via `resources/js/lib/linediff.js`). Scope v1 = ZTE.
- **Delete ONU** — `no onu {onuId}`, gated `supports_onu_delete`, route `smartolt.onu.delete`.
- **Terminal telnet browser** — xterm.js via `TelnetProxyServer` + tiket (§2.2).
- **Peta ONU** — pin lintas OLT ([`OnuMapController`](../app/Http/Controllers/OnuMapController.php), `Pages/Map/Index.vue`), reboot/rename pin punya cabang per family.
- **Alarm** — [`AlarmEvaluator`](../app/Services/AlarmEvaluator.php) selalu evaluasi (debounce 2-poll, korelasi root-cause); pengiriman notif Telegram/FCM digated saklar per-OLT (`alarms_enabled`) & per-partner (pivot `olt_user.alarms_enabled`).

---

## 14. Quirk & Known Issues

| # | Quirk | Solusi di repo |
|---|---|---|
| 1 | Board status firmware lama vs C320 V2.x (`0` vs `1`) | terima keduanya sebagai "Online" |
| 2 | `ifDescr` GPON kadang kosong | fallback synthesize port dari tabel ONU |
| 3 | Rx scale beda per firmware | auto-detect by magnitude (`convertOnuRxPowerToDbm`) |
| 4 | Pager `--More--` memotong `show` | executor auto-continue + `terminal length 0` |
| 5 | Running-config char-wrap potong token | `normalizeLines` gabung verbatim (tanpa spasi) |
| 6 | if-index tabel ONU C320 tabrakan slot+1 | fix `buildPortMap`/`decodeIfIndex` + walk per-port |
| 7 | `write` C300 config besar hening ~30 s | `readUntilIdle(quiet=75s, cap=120s)` — berhenti saat prompt kembali |
| 8 | Reboot kadang minta konfirmasi | scan output → auto-`y` |
| 9 | `Test SNMP` menimpa `last_test_result` → ONU jadi 0 | **wajib `array_merge`** ke `last_test_result` |

---

## 15. Sample Data Live

OLT live untuk regression: `id=1` (`OLT-C320-PATI`, ZXAN V2.x) & `id=2`. ONU sample `gpon-onu_1/2/2:1`: SN `ZTEG…`, Type `F660`, phase `3 (Working)`, admin `1 (Active)`, Rx raw `5635 → -18.73 dBm` (cocok CLI `-18.762`).

---

## 16. Verifikasi Lab Sebelum Production

```bash
snmpget  -v2c -c public HOST 1.3.6.1.2.1.1.2.0    # expect iso.3.6.1.4.1.3902
snmpget  -v2c -c public HOST 1.3.6.1.2.1.1.1.0    # expect ZXA10 C300/C320/C600
snmpwalk -v2c -c public HOST 1.3.6.1.4.1.3902.1012.3.28.1.1.1     # ONU table (C300/C320)
snmpwalk -v2c -c public HOST 1.3.6.1.4.1.3902.1082.500.10.2.3.1.1 # ONU table (C600)
snmpwalk -v2c -c public HOST 1.3.6.1.2.1.2.2.1.2 | grep -i gpon   # ifDescr port
# CLI: telnet HOST → (langsung #) → show gpon onu state gpon-olt_1/… → show pon power onu-rx …
```

Di dashboard: **Tambah OLT → Test SNMP** (menyimpan `driver` + `sys_descr` ke `last_test_result`, memicu deteksi C600), lalu **Refresh** untuk isi `port_onus`.

---

## 17. File Driver di Repo (referensi cepat)

| File | Peran |
|---|---|
| [`app/Services/Snmp/OltSnmpClient.php`](../app/Services/Snmp/OltSnmpClient.php) | SNMP read + helper ifIndex/decode (C300/C320 **& C600**) |
| [`app/Services/Snmp/GoSnmpPoller.php`](../app/Services/Snmp/GoSnmpPoller.php) | shell-out ke `bin/kv-snmp-poller` (polling terjadwal) |
| [`cmd/kv-snmp-poller/main.go`](../cmd/kv-snmp-poller/main.go) | engine SNMP Go |
| [`app/Services/ZteCliProvisioningExecutor.php`](../app/Services/ZteCliProvisioningExecutor.php) | sesi telnet, pager, `saveConfig`, error patterns |
| [`app/Services/ZteProvisioningScriptBuilder.php`](../app/Services/ZteProvisioningScriptBuilder.php) | builder script provisioning |
| [`app/Services/ZteOnuReconfigureScriptBuilder.php`](../app/Services/ZteOnuReconfigureScriptBuilder.php) | delta reconfigure + `buildForCopy` |
| [`app/Services/ZteRemoteOnuService.php`](../app/Services/ZteRemoteOnuService.php) | reboot (CLI) + enable/disable & nama/deskripsi (SNMP) |
| [`app/Services/ZteOnuDetailService.php`](../app/Services/ZteOnuDetailService.php) | parse `detail-info` |
| [`app/Services/ZteOnuRunningConfigService.php`](../app/Services/ZteOnuRunningConfigService.php) | parse running-config (pre-fill Configure) |
| [`app/Services/ZteOnuRxPowerService.php`](../app/Services/ZteOnuRxPowerService.php) | Rx per-port via CLI |
| [`app/Services/ZteProfileCatalogService.php`](../app/Services/ZteProfileCatalogService.php) | sync/parse profil |
| [`app/Services/ZteTr069BulkService.php`](../app/Services/ZteTr069BulkService.php) | TR069 massal per-port |
| [`app/Services/ZteCardUplinkService.php`](../app/Services/ZteCardUplinkService.php) | kartu + uplink (3-tier C300/C320, 4-tier C600) |
| [`app/Services/Zte/OnuRegistrationService.php`](../app/Services/Zte/OnuRegistrationService.php) · [`OltConfigBackupService.php`](../app/Services/Zte/OltConfigBackupService.php) · [`OnuRegistrationFormDefaults.php`](../app/Services/Zte/OnuRegistrationFormDefaults.php) | registrasi, backup, default form |
| [`app/Support/SmartOltSupport.php`](../app/Support/SmartOltSupport.php) | deteksi family + `isC600()` + capability matrix + helper interface |
| [`app/Http/Controllers/SmartOltController.php`](../app/Http/Controllers/SmartOltController.php) · [`SmartOltProfileController.php`](../app/Http/Controllers/SmartOltProfileController.php) · [`OltConfigBackupController.php`](../app/Http/Controllers/OltConfigBackupController.php) | HTTP handler |
| `resources/js/Pages/SmartOlt/*.vue` | UI Inertia (Index/Detail/PortOnus/OnuDetail/ConfigureOnu/RegisterOnu/Registrations/Profiles/Unconfigured/ConfigBackups/OnuMonitor/PortDetail/GponPorts) |
| `resources/js/Components/SmartOlt/*` | OltChassis · OnuConfigEditor · RxTrendCard · Tr069BulkModal |

---

## 18. Security & Guard

1. **Encrypt at-rest**: SNMP communities + CLI password (`encrypted` cast + `$hidden`); `.env` `640 root:www-data`.
2. **Confirm ONU ID** di apply Configure (`confirm_onu_id` harus match URL).
3. **Re-read live config** sebelum build delta (jangan trust snapshot client).
4. **Role + kepemilikan**: write digated `role:admin,operator,partner` + `PartnerOltScope` (partner hanya OLT miliknya).
5. **Tiket terminal short-lived** (encrypted, URL-safe).
6. **Audit**: registrasi/reconfigure → `smartolt_onu_registrations`; aksi write → `AuditLogger`.
7. **PPPoE password plaintext** di `smartolt_onu_registrations.pppoe_password` — batasi akses.

---

## 19. Referensi

- [handbook/07-modul-fitur.md](handbook/07-modul-fitur.md) · [handbook/08-snmp-polling.md](handbook/08-snmp-polling.md) · [handbook/09-cli-telnet.md](handbook/09-cli-telnet.md) · [handbook/05-database-model.md](handbook/05-database-model.md) · [handbook/06-routing.md](handbook/06-routing.md)
- [API.md](API.md) — REST API v1
- [SMARTOLT_CDATA_GUIDE.md](SMARTOLT_CDATA_GUIDE.md) · [SMARTOLT_HIOSO_GUIDE.md](SMARTOLT_HIOSO_GUIDE.md)
- Vendor: ZTE ZXA10 C300/C320/C600 manual + MIB (restricted); enterprise OID `1.3.6.1.4.1.3902`; PDF C600 di `docs/`
- Standar: RFC 4133 Entity-MIB · RFC 2863 IF-MIB · RFC 854 Telnet · ITU-T G.984/G.988
