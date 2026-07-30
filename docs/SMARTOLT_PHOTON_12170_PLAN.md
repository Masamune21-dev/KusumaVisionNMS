# Rencana Dukungan OLT HsAirPo / HSGQ (4PON EPON) — KusumaVision NMS

> **Brand: HsAirPo (HSGQ).** Manufaktur OEM = Shenzhen Photon Broadband (enterprise SNMP **12170**).
> Family baru di NMS, kandidat nama `DRIVER_HSAIRPO_EPON`.
>
> Status: **PLAN** (belum diimplementasikan). Dibuat 2026-07-30 dari survei OLT live.
> Dikerjakan di **session terpisah**. Kredensial login OLT uji ada di memori proyek
> `project_hsairpo_hsgq_olt` (tidak ditaruh di sini agar aman bila doc ter-commit).
>
> **Relevansi armada:** brand HSGQ/HsAirPo = prospek **PAKLINK** (19 unit HSGQ EPON, prioritas mereka —
> lihat memori `project_paklink_prospect`). Jadi ada alasan armada nyata untuk menggarap Fase B, bukan
> sekadar 1 unit.

## 0. Ringkasan keputusan

OLT ini **vendor baru** (belum didukung NMS: bukan ZTE / C-Data / HiOSO). Data ONU per-unit
**HANYA tersedia via CLI** — SNMP-nya tidak mengekspos tabel ONU (sudah diverifikasi full-walk).
Karena itu drivernya harus **CLI-first** (beda dari HiOSO/C-Data yang SNMP-first).

Rencana **2 fase**: **A** = inventaris + status (murah, 1 perintah/scan), **B** = Rx per-ONU (mahal,
per-ONU). Fase **C** (opsional) = aksi tulis. Kelayakan Fase B tergantung jumlah unit vendor ini
(**tanyakan owner** — kalau cuma 1–2 unit, LibreNMS + Fase A sudah cukup).

## 1. Identitas perangkat

- **Brand jual: HsAirPo / HSGQ.** Manufaktur OEM: **Shenzhen Photon Broadband Technology Co.,Ltd** —
  enterprise **12170** (identitas SNMP tetap "Photon Broadband"; brand HsAirPo/HSGQ tak muncul di SNMP,
  jadi deteksi paling andal via **sysObjectID `1.3.6.1.4.1.12170`** + field vendor/name yang di-set operator).
- Produk: **"4PON EPON-OLT"**, sysObjectID **`1.3.6.1.4.1.12170.2.3`**.
- Firmware: `Version 1.1.2.20210408_release (build-145001)` (2021-04-08). CLI gaya **Cisco-IOS / BDCOM**
  (`% Invalid input detected at '^' marker`, `EPON-OLT#`, "FOS version").
- Port: **pon1–pon4** (EPON, ifIndex 5002–5005), ge1–ge6 (5006–5011), xge1–xge2 (5012–5013).
- ONU per PON: **maks 64** (LLID). Saat survei: 116 ONU total, 107 online (PON1/2/4 terpakai).

### OLT uji
- IP publik `103.189.249.143`. **Telnet port 12167** (login `admin` / password → **lihat memori**),
  internal `10.10.12.44`. **SNMP UDP 2224** → internal `192.168.64.2:161`, community **`public`** (ro) /
  `private` (rw). MAC OLT `90:C6:82:1C:CD:AB`.
- ⚠️ Di IP publik yang sama ada OLT LAIN: **HiOSO AVIANA3 (HA7304VX, 25355)** di telnet **1217** /
  SNMP via 64.3 — itu family HiOSO (sudah didukung), JANGAN tertukar dengan Photon 12170 ini.

## 2. Temuan SNMP (terbatas — full-walk tersimpan)

Full walk ada di `storage/app/photon_12170_snmp_fullwalk.txt` (4641 baris).

**Yang BISA dibaca via SNMP (community `public`):**
- MIB-2 system (`.1.3.6.1.2.1.1`) + interfaces (`.2` ifTable, `.31` ifXTable): status up/down + counter
  trafik pon/ge/xge. → cukup untuk LibreNMS/Cacti level perangkat & port.
- BRIDGE-MIB FDB (`.1.3.6.1.2.1.17.*`, ~2600 baris): **MAC yang dipelajari L2** — BUKAN inventaris ONU
  (tak ada ONU-ID/PON/status/Rx; ONU offline tak muncul; MAC pelanggan & uplink tercampur; sebagian
  cocok dengan MAC ONU, sebagian beda 1 digit = interface lain ONU/perangkat pelanggan).
- Skalar vendor `.1.3.6.1.4.1.12170.2.3.1.1.*` (IP, MAC, nama vendor, sysName).
- Tabel port vendor: `.2.3.2` = port GE; `.2.3.3` = statistik per-PON — kolom 8
  (`.12170.2.3.3.1.1.8.1.0.{pon}`) = **jumlah ONU online per-PON** (PON1=29, PON2=53, PON3=0, PON4=25 →
  total 107, cocok dengan CLI). Kolom 7 = maks ONU (64).

**Yang TIDAK ADA / rusak:**
- **Tidak ada tabel ONU per-unit** (MAC/Rx/online-offline individual) di SNMP mana pun.
- Cabang `.1.3.6.1.4.1.12170.2.3.1.2` **BUG**: GETNEXT-nya loop (non-increasing OID) → tak bisa di-walk;
  isinya ternyata info-device (`"EPON-OLT"`/`"Box OLT"`), bukan ONU.
- `snmp-server ?` di config hanya knob standar (community/contact/enable/group/host/location/user/view)
  — tak ada knob EPON/ONU. Vendor memang tak implement tabel ONU di SNMP.

**Kesimpulan:** SNMP hanya untuk device + port + jumlah-online-per-PON. **Inventaris ONU & Rx = CLI-only.**

## 3. Temuan CLI (sumber data ONU) — REFERENSI PERINTAH

Login: `Username:` → password → `EPON-OLT>` → `enable` → `EPON-OLT#` (1 tingkat; enable tanpa password
di unit uji). Ada negosiasi **IAC telnet** → pakai ulang `App\Support\Telnet\TelnetIacFilter`.
`terminal length 0` mematikan pager.

### 3.1 Inventaris ONU — `show epon onu all info`  ✅ (1 perintah, murah)
```
-----------------------------------------------------------------------------
        PON   ONU       MAC                     Control  Run      Config   Match
              ID                                flag     state    state    state
-----------------------------------------------------------------------------
        1      1        0C:37:47:78:E9:27       Active   Online   Success  Match
        1      27       EC:23:7B:AF:B6:38       Active   Offline  Initial  Initial
        2      1        74:B5:7E:9D:19:6F       Active   Online   Success  Match
        4      27       84:93:B2:A0:8B:B6       Active   Online   Success  Match
-----------------------------------------------------------------------------
  Total: 116, online 107
```
Parse: kolom **PON**, **ONU ID** (1–64/PON), **MAC**, **Run state** (`Online`/`Offline`). → record
bentuk-ZTE: `slot=1`, `port=PON`, `onu_id=ONU ID`, `mac`/`serial_number=MAC`, `online = RunState==Online`.

### 3.2 Rx/redaman per-ONU — `show epon port {pon} onu {onuId} optical-info`  ✅ (per-ONU)
```
  Voltage(V)              : 3.23
  Tx optical power(dBm)   : 1.93
  Rx optical power(dBm)   : -21.43      <-- Rx ONU
  Laser bias current(mA)  : 18.25
  Temperature(C)          : 49.99
```
⚠️ **JANGAN** pakai varian `all` (`show epon port {n} onu all optical-info`) — **MACET / hang CLI**.
Harus per-ONU. 116 ONU = 116 perintah/poll → berat, wajib throttle + timeout anti-hang.

### 3.3 Lain-lain
- OLT-side DDM: `show epon port {n} ddm-info` (Temp/Voltage/Tx/Rx modul + vendor/serial modul).
- Unconfigured: `show epon port {n} onu-autofind-list` / `show epon port all onu-autofind-list`.
- Pohon help: `show epon onu ?` = all|autoauth|autofind; `show epon onu all ?` = info|loadstate;
  `show epon port {n} ?` = anti-rogueont|ddm-info|linkStatus|onu|onu-autofind-list|onu-black-list|vlan-pool;
  `show epon port {n} onu {id} ?` = info|loadstate|optical-info|version.
- Aksi tulis: node config `epon` punya grup `onu` (kemungkinan reboot/deactivate/delete) — **belum
  diverifikasi live** (Fase C).

## 4. Rencana implementasi

### FASE A — OLT masuk + inventaris ONU + status (read-only)
1. **Deteksi** — `App\Support\SmartOltSupport::driverKey()`: tambah `DRIVER_HSAIRPO_EPON` +
   needle `hsairpo`/`hsgq`/`photon`/`12170` + cek sysObjectID `1.3.6.1.4.1.12170`; helper `isHsAirPo()`.
   (Urutkan sebelum needle `epon` generik C-Data, seperti HiOSO.)
2. **Capabilities** — `hsAirPoEponCapabilities()`: `pon_label=EPON`, `port_name_prefix='epon 0'`,
   `onu_interface_pattern='epon 0/%d/%d:%d'`, `supports_snmp_rx=false`, semua write **OFF** dulu.
3. **CLI service** — `app/Services/HsAirPo/HsAirPoCliService.php`: telnet, **reuse `TelnetIacFilter`**,
   login IOS (`admin`→`enable`→`#`), `terminal length 0`, auto-`--More--`, **timeout anti-hang wajib**,
   mask password. `cli_port` = port telnet (12167 di unit uji, via NAT).
4. **Driver** — `app/Services/HsAirPo/HsAirPoEponService.php implements App\Contracts\SmartOltSnmpDriver`:
   - `getSystemInfo`: SNMP MIB-2 (sysName/descr/objectID) + firmware via `show version`.
   - `getPorts`: 4 PON dari SNMP ifDescr (`pon1..4`) atau `show epon port all`.
   - `getRegisteredOnus`: **CLI `show epon onu all info`** → parser tabel (§3.1) → record bentuk-ZTE.
   - `getRegisteredOnusByPort`, `countRegisteredOnus`, `getUnconfiguredOnus` (autofind, opsional).
5. **Integrasi jalur non-ZTE** — daftarkan di `App\Services\SmartOltSnmpServiceResolver::resolve()`;
   scan lewat `App\Services\CData\CDataOltScanner` (branch/registrasi driver); poll otomatis lewat
   `App\Jobs\PollOltJob::pollViaScanner()` (sudah menangani semua non-ZTE).
6. **UI** — controller `HsAirPoOltController` + rute `hsairpo-olt.*` + `Pages/HsAirPo/*`
   (Create/Edit/Detail/PortOnus), atau berbagi body tabel non-ZTE seperti cdata/hioso di `Index.vue`
   (tab baru "OLT HsAirPo", partisi di `SmartOltController::index()`). String dwibahasa ID/EN.
   `inventoryRoutePrefix()` tambah cabang `hsairpo-olt`.
7. **Test** — unit parser `show epon onu all info` (fixture teks nyata dari §3.1) + feature controller.

**Hasil Fase A:** OLT muncul, daftar ONU + online/offline, alarm OLT/PON/ONU-offline, global search,
peta. Biaya CLI 1 perintah/scan (ringan). Rx belum.

### FASE B — Rx/redaman per-ONU
1. `app/Services/HsAirPo/HsAirPoOnuRxService.php`: loop **per-ONU** `show epon port {pon} onu {id}
   optical-info` (§3.2) → parse Rx dBm (+Tx/Voltage/Temp). **Larangan `optical-info all` (hang).**
2. **Throttle**: 116 perintah/poll berat → RX-poll interval lebih jarang, jeda antar-perintah, guard
   anti-hang; catat ke tabel `onu_rx_samples` saat RX-due (pola ZTE `PollOltJob`).
3. Capability `supports_cli_rx=true`, `rx_source_label='Rx ONU (CLI)'`; chart pakai `RxTrendCard` +
   `PortDetail`/`OnuDetail` yang sudah ada.
4. (opsional) DDM sisi OLT (`show epon port {n} ddm-info`) untuk faceplate/uplink.

### FASE C (opsional) — aksi tulis
- Verifikasi live command config `epon onu` (reboot/deactivate/delete). Kalau ada & aman → tambah
  reboot/disable/delete (gated capability), pola sama HiOSO. Rename kalau didukung.

## 5. Reuse (menekan effort)
- `App\Support\Telnet\TelnetIacFilter` (sudah dibuat untuk HA7302) — login telnet IAC-aware.
- `CDataOltScanner` + `SmartOltSnmpServiceResolver` + `PollOltJob::pollViaScanner` (jalur non-ZTE).
- Kontrak `App\Contracts\SmartOltSnmpDriver` (record ONU bentuk-ZTE `last_test_result.port_onus`).
- `onu_rx_samples` + `RxTrendCard` (histori Rx).
- Pola UI tab non-ZTE (`Pages/Hioso/*`, `Pages/CDataOlt/*`) sebagai contoh.

## 6. Risiko & catatan
- **CLI rewel**: `optical-info all` **hang** → semua interaksi telnet wajib timeout + guard, dan Rx
  di-ambil per-ONU (bukan sekaligus). CPU OLT ini normal (beda dari HiOSO AVIANA3 yang 100%).
- **Effort besar** = family vendor baru (detection+caps+CLI+driver+scanner+controller+routes+pages+test).
  Fase A ≈ setara menambah 1 family read-only; Fase B menyusul (engineering throttle/anti-hang).
- **Kelayakan**: brand HSGQ/HsAirPo = prospek **PAKLINK (19 unit HSGQ EPON)** → armada nyata, Fase A+B
  sepadan bila prospek jalan. Konfirmasi status PAKLINK ke owner.
- Rename file/doc ini ke `docs/SMARTOLT_HSAIRPO_GUIDE.md` saat implementasi (samakan gaya guide lain).
- Lihat memori: [[project_hsairpo_hsgq_olt]] (kredensial + akses + temuan), [[project_paklink_prospect]]
  (armada 19 HSGQ), [[feedback_vendor_fatigue]] (jangan dorong vendor baru proaktif — ini atas permintaan
  owner), [[project_prod_deploy_gotchas]] (restart worker tiap ubah kode driver/job).
