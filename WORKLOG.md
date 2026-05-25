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
