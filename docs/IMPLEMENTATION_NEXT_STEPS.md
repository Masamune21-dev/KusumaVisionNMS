# Implementation Next Steps

This project is now scaffolded and ready for feature implementation.

## Step 1 - OLT Inventory

Create:

- `snmp_olts` migration
- `SnmpOlt` model
- encrypted casts for SNMP community and CLI password
- OLT CRUD controller
- `/smartolt` route and Vue page

Minimum fields:

- name
- vendor
- ip
- snmp_port
- snmp_version
- snmp_read_community
- snmp_write_community
- cli_transport
- cli_host/port
- cli_username
- cli_password

## Step 2 - ZTE Capability Detection

Create:

- `app/Support/SmartOltSupport.php`

Driver detection strings:

- `zte`
- `3902`
- `c300`
- `c320`

Default ZTE capabilities:

- provisioning supported
- CLI ONU detail supported
- CLI ONU configure supported
- reboot via CLI
- enable/disable via SNMP SET

## Step 3 - ZTE SNMP Service

Create:

- `app/Services/ZteSnmpService.php`

Initial methods:

- `getSystemInfo()`
- `getGponPorts()`
- `getRegisteredOnus()`
- `getUnconfiguredOnus()`
- `setOnuName()`
- `setOnuDescription()`
- `setOnuActiveState()`

Implementation rules:

- prefer PHP `SNMP` class
- fallback to `snmp2_real_walk`
- disable `oid_increasing_check`
- parse GPON slot/port from `ifDescr`
- decode ZTE SN from string or hex string
- convert optical power using magnitude-based scale

## Step 4 - Dashboard MVP

Create pages:

- `resources/js/Pages/SmartOlt/Index.vue`
- `resources/js/Pages/SmartOlt/Detail.vue`
- `resources/js/Pages/SmartOlt/PortOnus.vue`
- `resources/js/Pages/SmartOlt/Unconfigured.vue`

Create routes:

- `GET /smartolt`
- `GET /smartolt/{id}/detail`
- `GET /smartolt/{id}/ports/{slot}/{port}/onus`
- `GET /smartolt/{id}/unconfigured`

## Step 5 - ZTE CLI Session

Create:

- `app/Services/ZteCliSessionService.php`

Initial read-only commands:

- `show gpon onu detail-info`
- `show pon power attenuation`
- `show running-config interface gpon-onu_...`
- `show onu running config gpon-onu_...`
- `show gpon onu state gpon-olt_...`

Keep write commands disabled until read-only parsing is stable.

## Step 6 - Provisioning

Create:

- `smartolt_cli_profiles` migration
- `smartolt_onu_registrations` migration
- `ZteCliProvisionService`

Start with:

- generate script
- preview script
- save audit row

Execute-to-OLT should be added only after preview and CLI session handling are reliable.

