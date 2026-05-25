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
