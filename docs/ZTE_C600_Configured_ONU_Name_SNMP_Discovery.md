# ZTE TITAN C600 — Configured ONU Names, State & Metadata via SNMP

Confirmed SNMP objects for reading configured ONU names, descriptions, admin/phase state, and
serials from a ZTE TITAN C600. OIDs identified by capturing SmartOLT SNMP traffic + reproducing
from an Ubuntu server, then re-verified live against LAS GALERAS (ZXA10 C600 V1.2.2). Wired up in
`app/Services/Snmp/OltSnmpClient.php` (`C600_ONU_NAME`, `C600_ONU_ADMIN_STATE`, `C600_ONU_PHASE_STATE`).

## Environment

- OLT: ZTE TITAN C600, mgmt `10.100.2.2`, SNMPv2c/UDP 161.
- Index convention across these tables: `<column>.<PON-ifIndex>.<ONU-ID>` (same `{ifIndex}.{onuId}`
  as the ONU table `1082.500.20.2.1.2.1`).

## Configured ONU name/description table — `1082.500.10.2.3.3.1`

| Column | Meaning | Example |
|---:|---|---|
| `.2` | Configured ONU / customer name | `JOSE DE LA ROSA LAUREAN`, `MARIA ESMIRNA LIZARDO` |
| `.3` | Description with SmartOLT metadata | `zone_MANUEL CHIQUITO EL CRUSE_extid_2177_authd_20260716` |
| `.5` | Authentication mode code | `1` (consistent with SN auth; keep raw until MIB-confirmed) |
| `.6` | ONU serial (8-byte octet: 4 ASCII vendor + 4 raw) | `48 57 54 43 89 E6 4C A6` → `HWTC89E64CA6` |

**The name is readable over SNMP even though this firmware masks `Name`/`Description` as `********`
in CLI `show gpon onu detail-info`.** So the app reads C600 ONU names via SNMP, not CLI.

Description parsing (fixed delimiters, do not split zone on spaces):
`^zone_(.*?)(?:_descr_(.*?))?(?:_extid_([0-9]+))?_authd_([0-9]{8})$` → zone / description / SmartOLT
external id / authorization date (`YYYYMMDD`). Formats vary (`zone_X_authd_Z`, `zone_X_extid_N_authd_Z`,
`zone_X_descr_Y_authd_Z`); always keep the raw description too. (Description not surfaced yet.)

## ONU state table — `1082.500.10.2.3.8.1`

Verified live by comparing SNMP against CLI `show gpon onu detail-info` on online, offline, LOS and
admin-disabled ONUs:

| Column | Meaning | Values (verified) |
|---:|---|---|
| `.1` | **Admin state** | `1`=enable, `2`=disable (2 → CLI "Admin state: disable" on 27 ONUs) |
| `.4` | **Phase / operational state** | `2`=LOS, `4`=Working, `5`=DyingGasp, `7`=OffLine |

`.4 == 4 (Working)` matches the binary ONU-table online flag `.20.2.1.2.1.7 == 1` on **all 1343 ONUs**
(0 disagreements), so it is used as the C600 phase source: online detection is unchanged, but offline
ONUs now carry the real reason (LOS / DyingGasp / OffLine). There is no separate last-down-cause table;
the offline reason is the phase value, exactly how the C600 CLI reports it.

## Related operational OIDs (observed, not all wired)

| OID | Value | Interpretation |
|---|---:|---|
| `1082.500.1.2.4.2.1.2.<if>.<onu>` | `-28544` | OLT-side Rx power (milli-dBm → -28.544 dBm) |
| `1082.500.10.2.3.10.1.2.<if>.<onu>` | `6974` | ONU distance (m) |
| `1082.500.20.2.2.2.1.10.<if>.<onu>.1` | `3740` | ONU-side Rx (already used for RX) |

## Serial decode

8 octets: first 4 = ASCII vendor, last 4 = uppercase hex, concatenated
(`48 57 54 43 89 E6 4C A6` → `HWTC` + `89E64CA6` → `HWTC89E64CA6`). See `OltSnmpClient::decodeOnuSn`.

## Security notes

SNMPv2c communities and Telnet creds are clear-text; treat captures as sensitive, never publish
captures/screenshots with credentials, rotate anything exposed, prefer SNMPv3/SSH where supported.
