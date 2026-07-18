# ZTE TITAN C600 — Unconfigured ONU Discovery via SNMP

## Purpose

This document explains how SmartOLT discovers unconfigured ONUs on a ZTE TITAN C600 OLT. The procedure was verified by capturing the SNMP traffic between SmartOLT and the OLT and then reproducing the same query from an Ubuntu server. Re-verified live against LAS GALERAS (ZXA10 C600 V1.2.2) — see `app/Services/Snmp/OltSnmpClient.php` (`C600_UNCFG_OIDS`).

## Confirmed Communication Method

SmartOLT uses **SNMPv2c over UDP port 161** to retrieve unconfigured ONUs. It does not use Telnet for this particular operation (the C600 CLI does not expose an equivalent `show ... uncfg`).

- OLT management address: `10.100.2.2`
- Protocol: SNMPv2c, Transport: UDP/161

## Confirmed ZTE SNMP Table

Base OID for the unconfigured ONU table:

```text
1.3.6.1.4.1.3902.1082.500.2.2.11.2.1
```

Index structure: `<PON-ifIndex>.<discovery-entry>` — e.g. `285279504.1` (`285279504` = PON interface index, `1` = discovery entry on that PON).

## Confirmed Columns

| Column | Meaning | Example |
|---:|---|---|
| `.2` | ONU serial number encoded as octets | `48 57 54 43 C6 2B 52 AF` |
| `.3` | Registration password / auth data | all-zero in the observed entry |
| `.4` | Optional text field | empty |
| `.5`–`.7` | Binary registration data | all-zero |
| `.8` | ONU model / equipment identifier | `HG8145X6-10` |
| `.9` | Optional text field | empty |
| `.10` | ONU firmware / software version | `V5R022C00S266` |
| `.11` | Optional text field | empty |
| `.12` | First observed discovery timestamp | `2026-07-17 16:05:46` |
| `.13` | Latest observed discovery timestamp | `2026-07-17 17:37:36` |
| `.14`–`.16` | Internal state/flag values | semantics unverified |

The app currently surfaces only the serial (`.2`) + PON port — same shape as the ZTE C300/C320 unconfigured path. Do not assign business meaning to `.14`–`.16` without verifying against multiple ONU states or the ZTE MIB.

## Serial Number Decoding

The serial is an SNMP `Hex-STRING` of 8 octets: first 4 octets are the ASCII vendor ID, the remaining 4 are the hexadecimal serial suffix.

```text
48 57 54 43 C6 2B 52 AF
= "HWTC" + "C62B52AF"
= HWTCC62B52AF
```

Parser: decode the first 4 bytes as ASCII, format the remaining 4 bytes as uppercase hex without spaces, concatenate (`OltSnmpClient::decodeOnuSn`).

## PON Interface Index Decoding

```text
285279504 = 0x11010510 → gpon_olt-1/5/16
```

For a C600 the app decodes the IF-MIB index directly: slot = `(ifIndex >> 8) & 0xFF`, port = `ifIndex & 0xFF` (`OltSnmpClient::decodeIfIndex`). The IF-MIB `ifName` object (`1.3.6.1.2.1.31.1.1.1.1.<ifIndex>`) can be used to cross-check.

## Timestamp Decoding

Eight-byte binary value, e.g. `07 EA 07 11 10 05 2E 00`:

- `07 EA` → year `2026` (big-endian) · `07` → month · `11` → day 17 · `10` → hour 16 · `05` → minute 5 · `2E` → second 46 · `00` → trailing.

Result: `2026-07-17 16:05:46`.

## Verified Example (live)

```json
{
  "pon_ifindex": 285279504,
  "pon_interface": "gpon_olt-1/5/16",
  "discovery_entry": 1,
  "serial_number": "HWTCC62B52AF",
  "model": "HG8145X6-10",
  "firmware_version": "V5R022C00S266",
  "first_detected_at": "2026-07-17T16:05:46",
  "last_detected_at": "2026-07-17T17:37:36"
}
```

## Security Notes

- SNMPv2c community strings and Telnet credentials are transmitted in clear text; treat packet captures as sensitive and never publish captures/screenshots containing credentials.
- Rotate any SNMP community or Telnet password exposed during testing. Prefer SNMPv3/SSH where supported.

## Scope

This solves **unconfigured ONU discovery** only. It does not make configured ONU customer names/descriptions available over SNMP — the C600 CLI masks configured ONU `Name`/`Description` as `********` (their value equals the OLT CLI password; the real customer names live in SmartOLT's own database).
