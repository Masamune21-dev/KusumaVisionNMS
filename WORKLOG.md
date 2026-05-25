# Worklog

## 2026-05-25

### Baseline

- Initialized local git repository on branch `main`.
- Committed Laravel 12 scaffold as `8c5f836 chore: scaffold Laravel application`.

### Phase 1 - OLT Inventory

Created:

- `database/migrations/2026_05_25_073700_create_snmp_olts_table.php`
- `app/Models/SnmpOlt.php`
- `app/Support/SmartOltSupport.php`
- `app/Services/Snmp/OltSnmpClient.php`
- `app/Http/Controllers/SmartOltController.php`
- `resources/js/Pages/SmartOlt/Index.vue`
- `resources/js/Pages/SmartOlt/Create.vue`
- `resources/js/Pages/SmartOlt/Edit.vue`
- `resources/js/Pages/SmartOlt/Partials/OltForm.vue`
- `tests/Feature/SmartOltInventoryTest.php`

Changed:

- `app/Http/Middleware/HandleInertiaRequests.php` - shared flash messages with Inertia pages.
- `routes/web.php` - added authenticated SmartOLT inventory routes.
- `resources/js/Layouts/AuthenticatedLayout.vue` - added SmartOLT navigation link.
- `app/Services/Snmp/OltSnmpClient.php` - rejects SNMP v3 in initial tester until v3 credentials are implemented.

Notes:

- OLT secrets use Laravel encrypted casts.
- Empty secret fields on edit preserve existing encrypted values.
- SNMP test currently reads system OIDs and stores result in `last_test_result`.

### Phase 2 - OLT Detail SNMP Read-Only

Created:

- `resources/js/Pages/SmartOlt/Detail.vue`

Changed:

- `app/Services/Snmp/OltSnmpClient.php` - added SNMP snapshot, IF-MIB walk, GPON port parser, and IF oper status decoder.
- `app/Http/Controllers/SmartOltController.php` - added detail and refresh actions.
- `routes/web.php` - added SmartOLT detail and refresh routes.
- `resources/js/Pages/SmartOlt/Index.vue` - added Detail action.
- `tests/Feature/SmartOltInventoryTest.php` - added detail page coverage.

Notes:

- Detail page reads cached `last_test_result` so it remains accessible when OLT is unreachable.
- Refresh SNMP updates system info and GPON ports in `last_test_result`.

### Phase 3 - ONU Per-Port Monitoring

Created:

- `resources/js/Pages/SmartOlt/PortOnus.vue`

Changed:

- `app/Services/Snmp/OltSnmpClient.php` - added ZTE registered ONU walks, SN decoder, admin/phase/last-down decoders, and per-port ONU snapshots.
- `app/Http/Controllers/SmartOltController.php` - added port ONU page and refresh action.
- `routes/web.php` - added port ONU read and refresh routes.
- `resources/js/Pages/SmartOlt/Detail.vue` - linked each GPON port to its ONU page.
- `tests/Feature/SmartOltInventoryTest.php` - added port ONU page coverage.

Notes:

- Per-port ONU cache is stored in `last_test_result.port_onus.{slot}_{port}`.
- Current implementation uses the ZTE modern ONU management table; legacy fallback remains a later task.
- Firmware `OLT-C320-PATI` exposes GPON port names via IF-MIB `ifName` (`gpon_1/2/1`), so GPON port detection now checks `ifName` before `ifDescr`.
- Firmware `OLT-C320-PATI` uses different IF-MIB port ifIndex values than ZTE ONU table ifIndex values, so per-port ONU filtering now matches decoded `slot/port` instead of raw ifIndex equality.

### Phase 4 - Unconfigured ONU and Provisioning Preview

Created:

- `database/migrations/2026_05_25_081500_create_smartolt_onu_registrations_table.php`
- `app/Models/SmartOltOnuRegistration.php`
- `app/Services/ZteProvisioningScriptBuilder.php`
- `resources/js/Pages/SmartOlt/Unconfigured.vue`
- `resources/js/Pages/SmartOlt/RegisterOnu.vue`
- `resources/js/Pages/SmartOlt/Registrations.vue`

Changed:

- `app/Services/Snmp/OltSnmpClient.php` - added ZTE unconfigured ONU discovery across documented OID candidates.
- `app/Services/Snmp/OltSnmpClient.php` - skips unavailable unconfigured ONU OID candidates and continues probing remaining candidates.
- `app/Http/Controllers/SmartOltController.php` - added unconfigured discovery, register form, generated script storage, and registration history.
- `app/Models/SmartOltOnuRegistration.php` - set explicit database table name for Laravel model resolution.
- `routes/web.php` - added unconfigured, register, and registration history routes.
- `resources/js/Pages/SmartOlt/Detail.vue` - added Unconfigured and Registration navigation.
- `tests/Feature/SmartOltInventoryTest.php` - added unconfigured and provisioning preview coverage.

Notes:

- Provisioning currently generates and stores the CLI script only; CLI execution will be implemented after Telnet/SSH session handling is added.
- Verified against OLT `id=1`: unconfigured ONU discovery returned SN `ZTEGCD7D2FD6` on slot 2 port 2 with suggested ONU ID 1.

### Phase 5 - Provisioning Profile Management

Created:

- `database/migrations/2026_05_25_093000_create_smartolt_profiles_table.php`
- `app/Models/SmartOltProfile.php`
- `app/Http/Controllers/SmartOltProfileController.php`
- `resources/js/Pages/SmartOlt/Profiles.vue`

Changed:

- `routes/web.php` - added SmartOLT profile management routes.
- `app/Http/Controllers/SmartOltController.php` - loads active ONU Type, T-CONT, VLAN, and IP profiles into provisioning defaults and validates selected profile values.
- `app/Services/ZteProvisioningScriptBuilder.php` - static WAN provisioning now accepts prefix subnet values such as `24`.
- `resources/js/Pages/SmartOlt/RegisterOnu.vue` - replaced profile text inputs with dropdowns and changed static netmask input to prefix subnet.
- `resources/js/Pages/SmartOlt/Index.vue` - added navigation to profile management.
- `tests/Feature/SmartOltInventoryTest.php` - added coverage for profile management and static provisioning profile loading.

Notes:

- Default profiles are inserted by migration: `ALL-ONT`, `SERVER`, `ServiceName` VLAN 100, and `INTERNET`.
- VLAN profile selection overwrites the submitted VLAN and service name with the active profile's configured values.

### Phase 6 - Provisioning Execution Audit

Created:

- `database/migrations/2026_05_25_101500_add_execution_fields_to_smartolt_onu_registrations_table.php`
- `app/Services/ZteCliProvisioningExecutor.php`

Changed:

- `app/Models/SmartOltOnuRegistration.php` - added execution output/error metadata and executor relation.
- `app/Http/Controllers/SmartOltController.php` - added provisioning execution action with status updates.
- `routes/web.php` - added registration execution route.
- `resources/js/Pages/SmartOlt/Registrations.vue` - added Execute action and execution output display.
- `tests/Feature/SmartOltInventoryTest.php` - added execution status coverage with a fake executor.

Notes:

- Automatic execution currently supports Telnet only. SSH is intentionally rejected with a clear message until an SSH driver is selected.
- Execution output is stored for audit and CLI password values are masked from captured output.

### Phase 7 - OLT-Scoped CLI Profile Sync

Created:

- `database/migrations/2026_05_25_112000_scope_smartolt_profiles_to_olt.php`
- `app/Services/ZteProfileCatalogService.php`

Changed:

- `app/Models/SmartOltProfile.php` - added OLT scope, CLI source metadata, params JSON, and sync timestamp.
- `app/Http/Controllers/SmartOltProfileController.php` - changed profile management to per-OLT, added sync-from-OLT, and optional CLI execution for add/edit/delete.
- `app/Http/Controllers/SmartOltController.php` - provisioning profile dropdowns now load profiles scoped to the selected OLT with global defaults as fallback.
- `routes/web.php` - moved profile routes to `/smartolt/{olt}/profiles` and added sync route.
- `resources/js/Pages/SmartOlt/Index.vue` - profile action now links to the selected OLT.
- `resources/js/Pages/SmartOlt/Profiles.vue` - added OLT header, Sync Dari OLT action, CLI execution checkboxes, and type-specific profile parameters.
- `tests/Feature/SmartOltInventoryTest.php` - added CLI profile sync coverage.

Notes:

- Verified read-only CLI against OLT `id=1`: `show gpon profile tcont`, `show gpon onu profile vlan`, `show gpon onu profile ip`, and `show onu-type` returned live profiles from `OLT-C320-PATI`.
- Profile add/delete CLI scripts follow the SmartOLT guide commands for `pon` and `gpon` config modes.

### Phase 8 - ONU RX Power in Port Table

Created:

- `app/Services/ZteOnuRxPowerService.php`

Changed:

- `app/Http/Controllers/SmartOltController.php` - refresh ONU per port now reads `show pon power onu-rx gpon-olt_1/{slot}/{port}` via CLI and merges RX values into cached ONU rows.
- `resources/js/Pages/SmartOlt/PortOnus.vue` - added ONU RX column with simple signal health coloring and RX error display.
- `tests/Feature/SmartOltInventoryTest.php` - added RX parser coverage and table fixture field.

Notes:

- If CLI RX read fails, SNMP ONU table still refreshes and the RX error is stored under `last_test_result.port_onus.{slot}_{port}.rx_power.error`.
- Verified against OLT `id=1` slot 2 port 1: `gpon-onu_1/2/1:3` returned ONU RX `-14.260 dBm`.
