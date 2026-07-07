# Panduan Lengkap OLT HiOSO / V-Sol (HA7304) untuk SmartOLT

Terakhir diperbarui: 20 Mei 2026

Dokumen ini adalah **referensi mandiri** untuk integrasi OLT EPON HiOSO / V-Sol berbasis chipset HA7304 ke dashboard apapun (BMKV, project terpisah, atau migrasi platform baru). Berisi spec SNMP, OID map, CLI command, quirk transport, parser value, contoh data live, dan template implementasi driver.

Devices target: HiOSO HA7304, V-Sol / V-Solution EPON OLT, dan rebrand lain dengan enterprise SNMP `1.3.6.1.4.1.25355`.

> **Status implementasi di KusumaVision NMS (per 2026-06-30):** §1–§11 (OID/CLI vendor) sudah
> diverifikasi live & dipakai. **§12 (struktur file `HiosoSnmpService`/`SmartOltSnmpDriver` resolver/
> Blade view) dari project BMKV lama — BUKAN arsitektur repo ini.** Implementasi nyata fase v1
> (read-only): `app/Services/Hioso/HiosoEponSnmpService.php` (implements `App\Contracts\SmartOltSnmpDriver`),
> di-resolve `SmartOltSnmpServiceResolver`, di-scan `CDataOltScanner`, di-poll `PollOltJob::pollViaScanner`,
> dikenali `SmartOltSupport::driverKey()`/`isNonZte()`, tampil di tab "OLT C-Data / HiOSO" via
> `cdata-olt.*`. Aksi tulis (rename/reboot CLI, §5.5) menyusul di fase berikutnya. Detail: `WORKLOG.md` 2026-06-30.

---

## 1. Identifikasi Device

Tanda OLT termasuk family ini:

- `sysObjectID` (`1.3.6.1.2.1.1.2.0`) → mengandung `iso.3.6.1.4.1.25355`
- `sysDescr` (`1.3.6.1.2.1.1.1.0`) → contoh: kosong / generic, tapi vendor string biasanya `HA7304` atau `V-SOL`
- Vendor signature firmware (`1.3.6.1.4.1.25355.3.1.8.1.1.2.1`) → contoh value: `1.0.0.1/HA7304/SN2018-03-00007`
- CLI prompt setelah login: `EPON>` (user mode) lalu `EPON#` (enable)
- ifTable mengandung `Pon-Nni1..4` dan `G1..G4` (kalau 4-PON unit)

Detection string yang dipakai BMKV (case-insensitive substring match):

```
hioso | ha7304 | 25355 | v-sol | vsol | v-solution
```

---

## 2. Spesifikasi Koneksi

### 2.1 SNMP

| Parameter | Value |
|---|---|
| Version | v2c |
| Community read default | `SNMPREAD` (vendor default, sering diganti operator) |
| Community write | jarang dibuka untuk write (vendor punya CLI-only write policy) |
| UDP port | 161 (default) atau non-standar (operator suka tunneling, contoh `2238`) |
| Timeout minimum aman | **5 detik** (5_000_000 µs) — walk besar 50+ ONU perlu waktu |
| Retries minimum aman | **2** |
| Enterprise root | `1.3.6.1.4.1.25355` |

**Penting:** Default PHP `snmp2_real_walk()` di-set ke 1s timeout / 5 retries. Untuk HiOSO **WAJIB** override ke minimum 5s/2 — kalau tidak, walk pecah di tengah dan total ONU per PON tampak berubah-ubah antar request.

### 2.2 CLI Telnet/SSH

| Parameter | Value |
|---|---|
| Transport | Telnet (umum), SSH (jarang di vendor default) |
| TCP port | 23 (default) atau non-standar |
| Line ending | **`\r\n` WAJIB** (RFC 854 strict) — `\n` saja diterima sebagai karakter, tapi tidak dianggap submit Enter |
| Banner | ~225 byte ASCII art `"System Command Line / Welcome"` lalu `"Access Verification ../"` lalu IAC telnet negotiation lalu `"Username:"` |
| Timeout login prompt | 15 detik (banner agak panjang) |
| Timeout password prompt | 20 detik |
| Prompt user mode | `EPON>` |
| Prompt enable mode | `EPON#` |
| Prompt config mode | `EPON(config)#` |
| Prompt interface mode | `EPON(epon_0/{N})#` |

**Quirk transport telnet penting:**

1. **CRLF wajib**: kirim username, password, dan setiap command harus diakhiri `\r\n`. Kalau `\n` saja: OLT echo karakter tapi tidak fire Enter → readUntil time-out.
2. **Telnet option negotiation**: OLT mengirim IAC sequence `FF FB 01 FF FB 03 FF FE 22 FF FD 1F` sebelum prompt Username — strip negotiation bytes saat baca buffer agar match prompt tidak rusak.
3. **Banner panjang**: tunggu sampai 15 detik untuk prompt `Username:`. Default 8 detik kadang kurang.

---

## 3. Struktur Indeks ONU HiOSO

Berbeda dengan ZTE (`{ifIndex}.{onuId}` composite 32-bit) atau CData ODM (encoded device-index), HiOSO pakai **dua segmen terakhir** OID sebagai `{PON}.{ONU}`:

```
.1.3.6.1.4.1.25355.3.2.6.3.2.1.37.1.{PON}.{ONU}
                                    ^^^^^^^^^^^
                                    contoh:
                                      1.1   = PON 1 ONU 1
                                      1.27  = PON 1 ONU 27
                                      4.7   = PON 4 ONU 7
```

Saat normalisasi ke framework `(slot, port, onu_id)`:

| Framework field | HiOSO mapping |
|---|---|
| `slot` | always `1` (HA7304 single shelf, tidak ada slot fisik) |
| `port` | nomor PON (1..N, biasanya 4 untuk HA7304) |
| `onu_id` | nomor ONU dalam PON tersebut |

CLI HiOSO pakai 2-level path: `interface epon 0/{port}` → `onu {onuId} <action>`. Bukan 3-level seperti CData EPON (`epon 0/{slot}/{port}`).

---

## 4. OID Reference

### 4.1 System & Identity

| Objek | OID | Tipe | Dipakai | Catatan |
|---|---|---|---|---|
| `sysDescr` | `1.3.6.1.2.1.1.1.0` | STRING | Ya | identitas perangkat |
| `sysUptime` | `1.3.6.1.2.1.1.3.0` | TimeTicks | Ya | uptime |
| `sysContact` | `1.3.6.1.2.1.1.4.0` | STRING | Read | kontak admin |
| `sysName` | `1.3.6.1.2.1.1.5.0` | STRING | Read | nama node |
| `sysLocation` | `1.3.6.1.2.1.1.6.0` | STRING | Read | lokasi |
| `sysObjectID` | `1.3.6.1.2.1.1.2.0` | OID | Probe | untuk detect family |
| OLT IP | `1.3.6.1.4.1.25355.3.1.1.0` | IpAddress | Kandidat | IP manajemen OLT |
| OLT Subnet | `1.3.6.1.4.1.25355.3.1.2.0` | IpAddress | Kandidat | |
| OLT Gateway | `1.3.6.1.4.1.25355.3.1.3.0` | IpAddress | Kandidat | |
| Firmware signature | `1.3.6.1.4.1.25355.3.1.8.1.1.2.1` | STRING | Identifikasi | contoh `1.0.0.1/HA7304/SN2018-03-00007` |
| Firmware version | `1.3.6.1.4.1.25355.3.1.13.1.1.2.1` | STRING | Kandidat | contoh `"1.0.0.1"` |
| Firmware build date | `1.3.6.1.4.1.25355.3.1.13.1.1.3.1` | STRING | Kandidat | contoh `"20191122"` |

### 4.2 PON Port (HINDARI ifTable)

| Objek | OID | Dipakai | Catatan |
|---|---|---|---|
| `ifDescr` | `1.3.6.1.2.1.2.2.1.2` | **TIDAK** | HA7304 expose `Pon-Nni1..4` di sini, tapi itu **NNI uplink internal** (Network-Node Interface), bukan PON downstream physical |
| `ifOperStatus` | `1.3.6.1.2.1.2.2.1.8` | **TIDAK** | nilai status untuk `Pon-Nni*` tidak nyambung ke PON physical |

**Verifikasi lapangan OLT-HIOSO-NDOKATON:**

| ifIndex | ifDescr | ifOperStatus | PON physical | ONU online | Mismatch? |
|---|---|---|---|---|---|
| 1 | Pon-Nni1 | Up | PON 1 (27 ONU) | 26 online | konsisten |
| 2 | Pon-Nni2 | **Up** | PON 2 (21 ONU) | **0 online** | **KONTRADIKSI** |
| 3 | Pon-Nni3 | **Down** | PON 3 (9 ONU) | **9 online** | **KONTRADIKSI** |
| 4 | Pon-Nni4 | Up | PON 4 (15 ONU) | 13 online | konsisten |

Bukti tambahan: OLT-side `show epon 0/N optical-ddm` confirms semua 4 PON physical TxPower normal (~10.8-10.9 dBm) → laser OLT semua hidup, "down" Pon-Nni3 jelas bukan state PON downstream.

**Pendekatan yang benar untuk daftar PON + status:**

1. Walk OID name ONU (`.37.1`) → kumpulkan PON unik dari segmen pertama indeks
2. Walk OID Rx ONU (`.8.1`) → tentukan ONU online (Rx valid) vs offline (Rx `na` atau 0)
3. Status PON = Up bila ada ≥1 ONU online di PON itu; Down bila 0 ONU online tapi ada ONU registered

### 4.3 ONU Table (canonical)

| Objek | OID | Tipe | Dipakai | Catatan |
|---|---|---|---|---|
| **ONU name** | `1.3.6.1.4.1.25355.3.2.6.3.2.1.37.1.{PON}.{ONU}` | STRING | Ya | label ONU (CLI `onu N name X`). Read via SNMP, write **via CLI** |
| **ONU MAC** | `1.3.6.1.4.1.25355.3.2.6.3.2.1.11.1.{PON}.{ONU}` | STRING | Ya | hex 12-char tanpa separator, mis. `"ec237bd78071"` → format ke `EC:23:7B:D7:80:71` |
| **ONU Rx power** | `1.3.6.1.4.1.25355.3.2.6.14.2.1.8.1.{PON}.{ONU}` | STRING | Ya | dBm sebagai string, mis. `"-20.36"`. Value `"na"` = ONU offline |
| ONU LLID/ID | `1.3.6.1.4.1.25355.3.2.6.3.2.1.1.1.{PON}.{ONU}` | INTEGER | Kandidat | LLID logical identifier |
| ONU MAC subtree | `1.3.6.1.4.1.25355.3.2.6.2.1.*` | various | **JANGAN walk** | walk full subtree = N kolom × N ONU × M port-index OID → puluhan ribu entry, sangat lambat. Pakai `.3.2.1.11.1` (STRING hex) sebagai gantinya |

### 4.4 Optical Metrics (kandidat, belum di-map lengkap)

PDF vendor menyebutkan beberapa metric optical untuk ONU yang belum dipetakan di BMKV:

| Kandidat objek | Estimasi OID | Catatan |
|---|---|---|
| ONU Tx power | `.3.2.6.14.x` (subtree optical) | belum confirm kolom exact |
| ONU temperature | `.3.2.6.14.x` | belum confirm |
| ONU voltage | `.3.2.6.14.x` | belum confirm |
| ONU bias current | `.3.2.6.14.x` | belum confirm |
| ONU distance | `.3.2.6.14.x` atau `.3.2.6.3.x` | PDF menyebut satuan meter, OID belum diidentifikasi |

OLT side optical (yang sudah confirm via CLI):

- `show epon 0/{PON} optical-ddm` → Temperature, Voltage, TxBias, TxPower (OLT laser side)
- output format:
  ```
  Temperature  : 34.00 C
  Voltage      : 3.00  V
  TxBias       : 14.00 mA
  TxPower      : 10.80 dBm
  ```

### 4.5 Format Value Parsing

#### Rx Power (string dBm)

```
"-20.36"  → -20.36 dBm (online, signal good)
"-25.53"  → -25.53 dBm (Warning per klasifikasi vendor)
"-30.10"  → -30.10 dBm (Very Critical)
"na"      → ONU offline / no signal
""        → empty (treat as offline)
"0"       → 0 (treat as no signal / offline)
```

#### MAC Address (STRING hex 12-char)

```
"ec237bd78071"  → EC:23:7B:D7:80:71
"94bf805a4c43"  → 94:BF:80:5A:4C:43
"d05fafd2a10d"  → D0:5F:AF:D2:A1:0D
```

Parser: strip semua karakter non-hex, validasi panjang === 12, split per 2 karakter, join dengan `:`.

#### Klasifikasi Rx (per PDF vendor)

| Range Rx (dBm) | Klasifikasi |
|---|---|
| `>= -24` | Good |
| `-24` s/d `-27` | Warning |
| `-27` s/d `-30` | Critical |
| `<= -30` | Very Critical |
| `na` / empty / 0 | Offline |

---

## 5. CLI Reference

### 5.1 Login Sequence

```text
> [tunggu banner ~2-5 detik]
> [tunggu "Username:" sampai 15 detik]
> kirim: nockusuma\r\n          # username
> [tunggu "Password:" sampai 20 detik]
> kirim: kajen000\r\n            # password
> [prompt: EPON>]                # user mode
```

### 5.2 Mode Navigation

```text
EPON> enable                     # privileged
EPON#

EPON# conf t                     # global config
EPON(config)#

EPON(config)# interface epon 0/1 # interface PON 1
EPON(epon_0/1)#

EPON(epon_0/1)# end              # kembali ke privileged
EPON#

EPON# exit                       # keluar (close session)
```

### 5.3 Command Tree (top-level `show ?`)

```
show 
  cpu             -- CPU information
  epon-nni        -- Epon nni config
  epon-onu        -- Epon onu config
  epon            -- Epon config
  olt             -- Olt config
  alarm_ctrl      -- Alarm contrl
  clock           -- Show system clock
  cloud           -- Cloud config
  ddr             -- DDR config information
  history         -- Display the session command history
  log             -- System log information
  loop-detect     -- loop-detect configuration
  memory          -- Memory statistics
  mgmt-switch     -- Inner Mgmt Switch
  network         -- System Network Information
  nni             -- Network interface
  onu             -- Onu config
  onu-loop        -- Onu loop config
  polling         -- Polling config
  pon             -- Pon
  running-config  -- Show current running system unsaved configuration info
  service         -- System service
  system          -- System information
  timezone-list
  users           -- System users
  version         -- Displays version
```

Sub-command yang sudah divalidasi:

```
show epon ?
  INTERFACE    -- Port example: 0/1
  optical-ddm  -- Epon optical module ddm information
  p2p-enable   -- P2P information
  sfp-info     -- Epon optical module information

show epon 0/1 ?
  optical-ddm  -- Epon optical module ddm information
  rate         -- Epon interface rate information

show pon ?
  <1-4>  -- Pon Id

show pon 1 ?
  statistic  -- Statistic

show epon-nni ?
  INTERFACE  -- Port example: 0/1
```

### 5.4 Read Command yang Berguna

| Kebutuhan | Command | Output |
|---|---|---|
| OLT laser TxPower per PON | `show epon 0/{PON} optical-ddm` | Temperature, Voltage, TxBias, TxPower |
| Statistik PON | `show pon {1-4} statistic` | counter PON |
| Versi firmware | `show version` | |
| Uptime + system info | `show system` | |
| Running config | `show running-config` | |

**TIDAK ADA** command:
- `show interface epon ...` → `Unknown command`
- `show onu summary` → `Unknown command`
- Per-ONU detail via CLI → tidak ada equivalent `show gpon onu detail-info` seperti ZTE

Akibatnya: per-ONU detail (Tx, voltage, temperature, distance) tidak bisa diambil via CLI HA7304 ini. Kalau dibutuhkan, harus probe OID SNMP lanjutan.

### 5.5 Write Command (sudah divalidasi)

#### Rename ONU

```
enable
conf t
interface epon 0/{PON}
onu {ONU} name {label}
end
```

Constraint label:
- alfanumerik + `_` `-` `.`
- spasi tidak diterima → diganti `_` sebelum kirim
- max 32 karakter
- contoh valid: `idabendokaton`, `cust_001`, `home.07A`

#### Reboot ONU

```
enable
conf t
interface epon 0/{PON}
onu {ONU} reboot
end
```

Tidak ada konfirmasi interaktif. ONU langsung restart, biasanya kembali online 30-60 detik.

### 5.6 Delete / De-register ONU (TERVERIFIKASI LIVE)

Diverifikasi di HA7304 (OLT-HIOSO-NDOKATON) via help CLI. Verb delete/dereg ada di **level interface**
`EPON(epon_0/1)#`, **bukan** di bawah `onu {ONU}`. Sub-command `onu {ONU}` hanya:
`activate`, `deactivate`, `admin`, `bandwidth`, `catv`, `factory`, `multicast-*`, `name`, `port-isolation`,
`reboot`, `rstp`, `upgrade`, `vlan` — **tidak ada** delete.

```
enable
conf t
interface epon 0/{PON}
delete onu {ONU}       ← hapus konfigurasi ONU (dipakai tombol "Hapus ONU"; terverifikasi ok)
end
```

| Aksi | Command | Status |
|---|---|---|
| **Delete ONU** | `delete onu {ONU}` (level interface) | ✅ terverifikasi (`HiosoCliWriteService::delete`) |
| De-register ONU | `dereg onu {ONU}` atau `dereg {MACADDR}` | ✅ ada di CLI (belum dipakai; hanya de-register, bisa muncul lagi bila auto-auth) |
| Disable / Enable ONU | `onu {ONU} deactivate` / `onu {ONU} activate` | ✅ ada di CLI (belum dibuat) |
| ❌ salah (ditolak) | `no onu {ONU}`, `onu {ONU} delete` | `% [DEFAULT] Unknown command` |

**Belum diuji / belum dibuat:** Provisioning ONU baru (flow autofind belum diketahui), bandwidth profile.

### 5.7 Error Patterns

CLI menolak command dengan output yang mengandung salah satu pattern berikut (case-insensitive):

```
invalid input
unknown command
ambiguous command
incomplete command
command rejected
permission denied
authorization failed
not support
operation failed
failure:
error:
% bad
% invalid
```

Driver `HiosoCliSessionService::extractCliError()` scan output line-by-line dan return error message bila pattern match.

---

## 6. Status Derivation Logic

Karena `ifOperStatus` SNMP HA7304 tidak reliable untuk PON physical state, status diturunkan dari **ONU online count**:

```
status PON = Up    bila online_count > 0
status PON = Down  bila total > 0 AND online_count == 0
status PON = N/A   bila tidak ada ONU registered di PON itu

status Board = Online   bila ada ≥1 PON Up
status Board = Offline  bila semua PON Down atau tidak ada port aktif
```

Label informatif yang dipakai BMKV:

| Kondisi | Label |
|---|---|
| Semua ONU PON online | `"Up"` |
| Sebagian ONU offline | `"Up (X/Y ONU online)"` |
| Semua ONU PON offline | `"Down"` |
| Sebagian PON down | `"Online (X/Y PON Up)"` (untuk board) |

Contoh akhir di OLT-HIOSO-NDOKATON (snapshot 20 Mei 2026):

```
PON 1 | Up (26/27 ONU online)
PON 2 | Down                      ← fiber/distribution trouble
PON 3 | Up
PON 4 | Up (13/15 ONU online)
Board: Online (3/4 PON Up)
```

---

## 7. Implementation Pattern (Template Driver)

Untuk replicate ke project lain, ikuti pattern berikut. Bahasa contoh: PHP, tapi konsep portable.

### 7.1 Walk Cache Per-Instance

```php
private array $walkCache = [];

protected function walk(string $oid): array|false
{
    if (array_key_exists($oid, $this->walkCache)) {
        return $this->walkCache[$oid];
    }
    $result = @snmp2_real_walk($host, $community, $oid, $this->timeout, $this->retries);
    return $this->walkCache[$oid] = $result;
}
```

Tanpa cache: `getGponPorts()` dan `getRegisteredOnus()` di satu request bisa walk OID name 2× — buang waktu.

### 7.2 Timeout Floor

```php
private const MIN_TIMEOUT_US = 5_000_000;  // 5 detik
private const MIN_RETRIES = 2;

public function setConfig(int $timeout, int $retries): void
{
    $this->timeout = max($timeout, self::MIN_TIMEOUT_US);
    $this->retries = max($retries, self::MIN_RETRIES);
}
```

Tanpa floor: caller yang set timeout 1-2 detik akan menyebabkan walk partial, dan total ONU per PON tampak berubah-ubah.

### 7.3 Parse Indeks `{PON}.{ONU}`

```php
private function parseHiosoIndex(string $oid): ?array
{
    if (! preg_match('/\.(\d+)\.(\d+)$/', $oid, $m)) return null;
    $pon = (int) $m[1];
    $onu = (int) $m[2];
    if ($pon <= 0 || $onu <= 0) return null;
    return ['pon' => $pon, 'onu' => $onu];
}
```

### 7.4 Parse Rx Power (handle `na`)

```php
$clean = strtolower(trim($rawValue));

if ($clean === '' || $clean === 'na' || $clean === 'n/a') {
    return ['no_signal' => true, 'dbm' => null];
}

if (is_numeric($clean)) {
    $num = (float) $clean;
    // HA7304 normal mengembalikan dBm langsung; antisipasi varian centi-dBm:
    $dbm = abs($num) >= 100 ? $num / 100 : $num;
    return ['dbm' => round($dbm, 3), 'no_signal' => $dbm === 0.0];
}
```

### 7.5 Format MAC dari STRING hex

```php
private function formatHexMac(string $value): ?string
{
    $hex = strtolower(preg_replace('/[^0-9a-f]/i', '', $value) ?? '');
    if (strlen($hex) !== 12) return null;
    return strtoupper(implode(':', str_split($hex, 2)));
}
```

### 7.6 Telnet CLI dengan CRLF + Login Override

```php
// Pakai transport yang sudah kita punya, override 4 hal kunci:
$cli->configureLoginPrompts(
    loginPromptTimeoutSeconds: 15,
    passwordPromptTimeoutSeconds: 20,
    usernamePromptNeedles: ['login:', 'username:', 'user name:', 'user:'],
    passwordPromptNeedles: ['password:', 'passwd:', 'password for', 'enter password'],
    loginLineEnding: "\r\n",  // ← CRUCIAL
);
```

### 7.7 CLI Command Builder

```php
public function setOnuName(int $port, int $onuId, string $name): array
{
    $label = $this->sanitizeName($name);
    return $this->run([
        'enable',
        'conf t',
        "interface epon 0/{$port}",
        "onu {$onuId} name {$label}",
        'end',
    ]);
}

private function sanitizeName(string $value): string
{
    $value = preg_replace('/[\x00-\x1F\x7F]/', ' ', $value) ?? '';
    $value = preg_replace('/\s+/', '_', $value) ?? '';
    $value = preg_replace('/[^A-Za-z0-9_\-\.]/', '', $value) ?? '';
    return mb_strimwidth(trim($value, '_-.'), 0, 32, '');
}
```

### 7.8 Mapping ke Framework SmartOLT

| Framework field | Source | Catatan |
|---|---|---|
| `slot` | hardcoded `1` | HA7304 single shelf |
| `port` | first segment indeks SNMP | nomor PON |
| `onu_id` | second segment indeks SNMP | nomor ONU |
| `name` / `description` | OID `.37.1.{PON}.{ONU}` | single label, tidak ada description terpisah |
| `mac_address` | OID `.11.1.{PON}.{ONU}` | format dari hex 12-char |
| `sn` | sama dgn `mac_address` | EPON tidak punya SN tradisional, MAC = identifier |
| `rx_power_dbm` | OID `.8.1.{PON}.{ONU}` parsed float | `null` bila `na` |
| `is_online` | derived: Rx valid AND ≠ 0 | tidak ada OID status eksplisit |
| `port_alias` | `epon 0/1/{PON}` | UI label EPON style |
| `interface_label` | `epon 0/1/{PON}:{ONU}` | UI label ONU style |

---

## 8. Capabilities Profile

Untuk driver/dashboard yang pakai capability matrix:

```json
{
    "driver": "hioso_epon",
    "vendor_family": "HiOSO / V-Sol EPON (25355)",
    "pon_label": "EPON",
    "port_label": "EPON Port",
    "port_and_onu_label": "EPON Port & ONU",
    "port_name_prefix": "epon 0",
    "onu_interface_pattern": "epon 0/%d/%d:%d",

    "supports_cli_rx": false,
    "supports_cli_onu_detail": false,
    "supports_cli_onu_configure": false,

    "supports_reboot": true,
    "reboot_mode": "cli_hioso_epon",

    "supports_provisioning": false,
    "supports_separate_description": false,
    "supports_onu_info_write": true,
    "description_mode": "cli_hioso_epon",

    "supports_onu_toggle": false,

    "onu_name_label": "Label ONU",
    "onu_description_label": "Deskripsi",
    "edit_name_label": "Label ONU",
    "edit_description_label": "Deskripsi ONU",
    "edit_name_placeholder": "pelanggan_namanya",
    "edit_description_placeholder": "Label pelanggan (alfanumerik, underscore, max 32)...",
    "rx_source_label": "Rx ONU"
}
```

---

## 9. Sample Data Live (OLT-HIOSO-NDOKATON)

Untuk regression test / unit test parser.

### 9.1 Identifikasi

```
sysObjectID:           iso.3.6.1.4.1.25355
Firmware signature:    1.0.0.1/HA7304/SN2018-03-00007
OLT IP:                192.168.0.88
Total PON:             4
Total ONU registered:  72
```

### 9.2 ONU Name Sample (PON 1)

```
.37.1.1.1  = "serlybendokaton"
.37.1.1.2  = "netandokaton"
.37.1.1.3  = "aufandokaton"
.37.1.1.27 = "idabendokaton"
```

### 9.3 MAC Sample (PON 1)

```
.11.1.1.1  = "ec237bd78071"  → EC:23:7B:D7:80:71
.11.1.1.2  = "1c2704a5bd7c"  → 1C:27:04:A5:BD:7C
.11.1.1.27 = "d05fafd2a10d"  → D0:5F:AF:D2:A1:0D
```

### 9.4 Rx Power Sample (PON 1)

```
.8.1.1.1  = "-20.36"  → -20.36 dBm  (Good)
.8.1.1.4  = "-25.53"  → -25.53 dBm  (Warning)
.8.1.1.7  = "na"      → offline
.8.1.1.27 = "-19.14"  → -19.14 dBm  (Good)
```

### 9.5 Walk Performance Live

| Walk OID | Entry count | Roundtrip time (avg) |
|---|---|---|
| `.37.1` (name) | 72 | ~50ms |
| `.11.1` (MAC) | 72 | ~50ms |
| `.8.1` (Rx) | 72 | ~50ms |
| Total `getRegisteredOnus()` | 72 ONU lengkap | ~180ms |

Dengan timeout < 5s atau retries < 2, ~30% kasus walk pecah di PON 1 (urut alphabetical OID).

---

## 10. Known Issues & Quirks

Ringkasan semua quirk yang sudah ditemukan, ditulis biar tidak terulang:

| # | Quirk | Penyebab | Solusi |
|---|---|---|---|
| 1 | Telnet `\n` tidak submit | RFC 854 strict, HiOSO tidak toleran LF saja | kirim semua line dengan `\r\n` |
| 2 | Walk SNMP partial / total berubah | timeout < 5s atau retries < 2 | clamp `MIN_TIMEOUT_US = 5_000_000`, `MIN_RETRIES = 2` |
| 3 | `ifOperStatus` PON tidak akurat | `Pon-Nni*` di ifTable = NNI uplink, bukan PON physical | derive status dari ONU online count, jangan dari ifTable |
| 4 | MAC subtree `.3.2.6.2.1` lambat | walk full subtree = puluhan ribu entry | pakai OID singular `.3.2.6.3.2.1.11.1` (STRING hex) |
| 5 | Banner login panjang | ~225 byte + IAC negotiation | timeout login prompt 15s, password 20s |
| 6 | Nama ONU tidak boleh spasi | CLI HiOSO sanitasi alfanumerik+`_-.` | replace spasi dengan `_`, strip char invalid |
| 7 | `show interface epon` tidak ada | firmware HA7304 tidak punya command itu | jangan asumsikan ZTE-style CLI; pakai `show epon 0/{N} optical-ddm` untuk OLT-side metric |
| 8 | Per-ONU detail tidak bisa via CLI | tidak ada equivalent `show gpon onu detail-info` | seluruh info ONU ambil via SNMP saja |
| 9 | Walk seluruh tabel ONU terpotong di link WAN lossy → total ONU/PON melompat-lompat antar poll (kadang cuma nama/Rx sebagian) | tabel besar pada PON padat + link via port-forward drop paket di tengah walk; timeout/retry cukup tapi burst loss tetap memutus | walk **per-PON** (`{base}.{PON}`, mis. `.11.1.{PON}`) lalu gabung — walk kecil hampir selalu utuh (terverifikasi: full walk truncate, per-PON 27/27 6×). PLUS **carry-forward roster**: poll terpotong hanya menambah/update, tak pernah menghapus ONU dikenal (registrasi EPON stabil); lepas ONU setelah absen `MAX_MISSED_POLLS` (12) poll beruntun |

---

## 11. Verifikasi Lab Sebelum Production

Checklist minimal sebelum hubungkan OLT HiOSO baru ke dashboard:

```bash
# 1. Identifikasi family
snmpget  -v2c -c SNMPREAD HOST 1.3.6.1.2.1.1.2.0
snmpget  -v2c -c SNMPREAD HOST 1.3.6.1.4.1.25355.3.1.8.1.1.2.1

# 2. Walk inventory ONU
snmpwalk -v2c -c SNMPREAD HOST 1.3.6.1.4.1.25355.3.2.6.3.2.1.37.1
snmpwalk -v2c -c SNMPREAD HOST 1.3.6.1.4.1.25355.3.2.6.3.2.1.11.1
snmpwalk -v2c -c SNMPREAD HOST 1.3.6.1.4.1.25355.3.2.6.14.2.1.8.1

# 3. Test CLI login (manual atau via socket)
telnet HOST PORT
# > username\r\n
# Password: password\r\n
# EPON> enable
# EPON# show version
# EPON# show epon 0/1 optical-ddm
# EPON# exit

# 4. CLI write probe (LAB ONLY, gunakan ONU dummy)
# EPON# conf t
# EPON(config)# interface epon 0/1
# EPON(epon_0/1)# onu 1 name test_dummy
# EPON(epon_0/1)# end
# # rollback:
# EPON# conf t
# EPON(config)# interface epon 0/1
# EPON(epon_0/1)# onu 1 name {original_name}
```

---

## 12. File Driver di BMKV Repo (untuk migrasi)

Bila migrate ke project lain, struktur file:

| File | Fungsi |
|---|---|
| `app/Services/HiosoSnmpService.php` | driver SNMP read-only (list ONU, MAC, Rx, status) |
| `app/Services/HiosoCliSessionService.php` | driver CLI (rename + reboot ONU) |
| `app/Services/ZteCliSessionService.php` | transport telnet/SSH bersama, punya `configureLoginPrompts()` untuk override per-driver |
| `app/Contracts/SmartOltSnmpDriver.php` | interface yang harus di-implement |
| `app/Services/SmartOltSnmpServiceResolver.php` | resolver match vendor → driver class |
| `app/Support/SmartOltSupport.php` | capability matrix + vendor detection |
| `app/Http/Controllers/SmartOltController.php` | branch `cli_hioso_epon` di handler write + reboot |
| `resources/views/smartolt/index.blade.php` | UI vendor list + placeholder |

---

## 13. Roadmap Kandidat Pengembangan

Belum diimplementasi, dicatat untuk masa depan:

- **Enable/disable ONU**: probe `onu {N} deactive` / `onu {N} active` di config-interface mode
- **Provisioning ONU unconfigured**: flow autofind belum diketahui, perlu lihat output `show onu-loop` atau alarm OLT saat ONU baru di-power on
- **Optical detail per ONU**: walk subtree `.3.2.6.14.*` lengkap di lab untuk identifikasi kolom Tx, voltage, temperature, distance
- **Distance ONU**: PDF vendor menyebut "satuan meter" tapi OID belum diidentifikasi
- **Bandwidth profile / VLAN config**: lihat command tree `show olt`, `show service`, `show running-config`
- **Capability probe otomatis**: deteksi family by SNMP probe ke `25355.3.2.6.3.2.1.37.1` (Get-Next), bukan sekadar string match vendor
- **Statistik traffic per PON**: `show pon {N} statistic` — parse output ke struktur counter
- **OLT-side optical monitoring**: render `show epon 0/{N} optical-ddm` di card PON port

---

## 14. Referensi

### Lokal
- `docs/SMARTOLT_OID_MAP.md` (section 6A) — peta OID ringkas dalam konteks multi-vendor BMKV
- `docs/Dokumentasi_SNMP_OLT_HA7304_v2.pdf` — referensi vendor singkat (RX, name, status enum)
- `docs/epon.txt` — full SNMP walk OLT-HIOSO-NDOKATON sebagai sample data
- `docs/MODULE_GUIDE.md` (section 6) — SmartOLT module overview
- `docs/WORKLOG.md` — entry 2026-05-20 perubahan implementasi

### Web
- RFC 854 Telnet Protocol Specification: `https://datatracker.ietf.org/doc/html/rfc854` (sumber line ending CRLF)
- RFC 3418 SNMPv2-MIB: `https://datatracker.ietf.org/doc/html/rfc3418`

### Vendor
- HiOSO chipset HA7304 datasheet — tidak public, harus minta ke vendor
- V-Sol / V-Solution OLT documentation portal — tidak ada link stabil public
