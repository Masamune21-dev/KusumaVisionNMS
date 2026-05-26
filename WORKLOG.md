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
- Telnet reader now auto-continues pager prompts such as `--More--` by sending Enter, so RX output above one CLI page can be read fully.
- Verified long RX output against OLT `id=2` slot 2 port 3: parser read 112 RX values and reached the final CLI prompt without leftover pager markers.

### Phase 9 - Provisioning TR069 and Remote ONT

Created:

- `database/migrations/2026_05_25_123000_add_remote_ont_tr069_to_smartolt_onu_registrations_table.php`

Changed:

- `app/Http/Controllers/SmartOltController.php` - added provisioning defaults and validation for TR069 ACS and Remote ONT security management fields.
- `app/Models/SmartOltOnuRegistration.php` - added encrypted ACS password and casts for TR069/Remote ONT options.
- `app/Services/ZteProvisioningScriptBuilder.php` - emits `tr069-mgmt` and `security-mgmt` lines inside `pon-onu-mng` when enabled.
- `resources/js/Pages/SmartOlt/RegisterOnu.vue` - added TR069 and Remote ONT controls to the provisioning form.
- `tests/Feature/SmartOltInventoryTest.php` - added provisioning script coverage for TR069 and Remote ONT commands.

Notes:

- TR069 default follows the SmartOLT guide: ACS URL `http://acs.bmkv.net:7547`, user `cms`, password `kusuma123!`.

### Phase 10 - Profile Delete Scope and Live ONU ID Suggestion

Changed:

- `app/Http/Controllers/SmartOltProfileController.php` - profile management page now lists only profiles owned by the selected OLT, avoiding delete/update 404s from global fallback rows.
- `resources/js/Pages/SmartOlt/Profiles.vue` - hides edit/delete actions for any fallback profile row if present.
- `app/Http/Controllers/SmartOltController.php` - register form now reads `show gpon onu state gpon-olt_1/{slot}/{port}` to find the next free ONU ID, with cache and unconfigured suggested ID as fallback.
- `resources/js/Pages/SmartOlt/Unconfigured.vue` - passes the unconfigured ONU suggested ID into the register form.
- `tests/Feature/SmartOltInventoryTest.php` - added coverage for live CLI ONU ID suggestion and unconfigured suggested ID fallback.

Notes:

- Provisioning ONU ID is automatic from CLI state output. If CLI is unavailable, it falls back to cached port data or the unconfigured suggested ID; gaps such as used IDs `1,2,4` suggest `3`.

### Phase 12 - Remote ONU Management

Created:

- `app/Services/ZteRemoteOnuService.php`

Changed:

- `app/Services/Snmp/OltSnmpClient.php` - added `set()` for SNMP write (admin state, name, description) using the write community; rejects v3 and missing write community.
- `app/Services/ZteCliProvisioningExecutor.php` - extracted shared `run()` and added `executeConfirmable()` that auto-answers `y` to reboot confirmation prompts; `execute()` keeps its signature so existing test fakes are unaffected.
- `app/Http/Controllers/SmartOltController.php` - added `rebootOnu`, `setOnuState`, `updateOnuInfo`; capability-gated via `assertCapability`; updates the cached `port_onus` row so admin/name reflect immediately; resolves the ONU-table ifIndex from cache (falls back to request value, then encoded port ifIndex).
- `routes/web.php` - added `smartolt.onu.reboot`, `smartolt.onu.state`, `smartolt.onu.info` POST routes scoped under `onus/{onuId}`.
- `resources/js/Pages/SmartOlt/PortOnus.vue` - added per-row Reboot (confirm), Enable/Disable (label from admin_state), and Edit Info modal (name/description); buttons gated by OLT capabilities.
- `tests/Feature/SmartOltInventoryTest.php` - added coverage for reboot CLI script, SNMP SET on toggle and info, and capability gate (403 for non-ZTE driver).

Notes:

- Reboot uses CLI (`pon-onu-mng gpon-onu_1/{slot}/{port}:{onuId}` then `reboot`), per guide §5.5/§12.5. Enable/disable and edit name/description use SNMP SET (guide §5.6/§5.7), which is faster than CLI but requires the OLT write community to be set.
- The ONU admin-state/name/description OIDs are indexed by the ONU-table ifIndex (`.28.1.1.x.{ifIndex}.{onuId}`), which on `OLT-C320-PATI` differs from the IF-MIB port ifIndex; the frontend passes the row's `if_index` and the controller prefers it.
- Tests use fakes for both the CLI executor and SNMP client. Verified working against a live OLT on 2026-05-25: edit info, enable, disable, and reboot all function from the UI.

### UI Consistency Pass

Created:

- `resources/js/Components/ConfirmModal.vue`
- `resources/js/Components/IconButton.vue`
- `resources/js/Composables/useConfirm.js`

Changed:

- Replaced all native `window.confirm()` calls with the reusable `ConfirmModal` + `useConfirm()` promise flow (Index delete, Profiles delete x2, Registrations execute, PortOnus reboot/toggle).
- Row "Aksi" buttons across Index, Detail, Unconfigured, Registrations, Profiles, PortOnus are now icon-only (`IconButton`) with tooltips, standardized icon vocabulary, and variant colors. Header/toolbar buttons keep their labels.
- PortOnus icons: Reboot uses `Power`; Enable/Disable uses a dynamic toggle switch (`ToggleRight` active/amber, `ToggleLeft` disabled/green); Edit uses `Pencil`.

### Phase 13 - Background Polling Foundation

Created:

- `database/migrations/2026_05_25_140000_add_polling_fields_to_snmp_olts_table.php`
- `app/Jobs/PollOltJob.php`
- `app/Console/Commands/PollOltsCommand.php`
- `tests/Feature/OltPollingTest.php`

Changed:

- `app/Models/SnmpOlt.php` - added `polling_enabled` (bool, default true) and `last_polled_at` to fillable/casts.
- `routes/console.php` - schedules `olts:poll` every five minutes with `withoutOverlapping()`.
- `app/Http/Controllers/SmartOltController.php` - validates and serializes `polling_enabled` + `last_polled_at`.
- `resources/js/Pages/SmartOlt/Partials/OltForm.vue` - added auto-poll enable checkbox.
- `resources/js/Pages/SmartOlt/Index.vue` and `Detail.vue` - show auto-poll On/Off status and last poll time.

Notes:

- Poll depth is "standard": SNMP only (system info + GPON ports + full ONU table walk bucketed into `port_onus.{slot}_{port}` for online/offline). No CLI/Telnet RX during background poll to keep OLT load low; manual port refresh still adds RX.
- `PollOltJob` merges into the existing `last_test_result` instead of overwriting, preserving previously fetched RX power and unconfigured ONU data; RX is carried over per ONU by `onu_id`.
- `WithoutOverlapping($oltId)->dontRelease()` prevents stacked polls for the same OLT. Errors (OLT unreachable, SNMP v3) are stored in the snapshot, not thrown, so one bad OLT does not block the others.
- Requires a running scheduler (`php artisan schedule:work` or cron) and a queue worker/Horizon to actually execute.
- Verified end-to-end against live OLTs on 2026-05-25 (command -> redis -> worker): OLT-C320-PATI 8 ports / 156 ONU / 132 online (~1s); OLT-C300-SEKARJALAK 48 ports / 2084 ONU / 1400 online (~32s). The large C300 poll is heavy (~32s) but well within the 5-minute cycle, and `WithoutOverlapping` prevents stacking.

### Phase 14 - Alarm Engine (basic)

Created:

- `database/migrations/2026_05_25_150000_create_alarm_events_table.php`
- `app/Models/AlarmEvent.php`
- `app/Services/AlarmEvaluator.php`
- `app/Http/Controllers/AlarmController.php`
- `resources/js/Pages/SmartOlt/Alarms.vue`
- `tests/Feature/AlarmEngineTest.php`

Changed:

- `app/Jobs/PollOltJob.php` - calls `AlarmEvaluator::evaluate()` after each snapshot save.
- `routes/web.php` - added `alarms.index` (`GET /alarms`).
- `resources/js/Layouts/AuthenticatedLayout.vue` - added Alarms nav link (desktop + responsive).

Notes:

- Stateful raise/clear lifecycle: an active alarm is keyed by a `signature` (e.g. `onu:{serial}:los`, `port:{slot}/{port}:port_down`, `olt:unreachable`). Each evaluation updates `last_seen_at` on still-present conditions, raises new ones, and clears (status=cleared, cleared_at) conditions no longer present. No duplicate spam.
- Types & severity (tuned 2026-05-25 so `critical` stays actionable): `olt_unreachable` critical (skips ONU/port eval when OLT down), `port_down` critical, `los` major, `onu_offline` minor, `dying_gasp` minor, `high_rx_attenuation` warning (only when RX present, outside -28..-8 dBm). Admin-disabled ONUs are skipped.
- Rationale: live poll first produced 2079 active alarms dominated by 1948 `dying_gasp` (each customer ONT powered off reports dying gasp). Subscriber-side down events were downgraded to minor/major so `critical` is reserved for network-side faults. After tuning, live distribution = critical 10 (all `port_down`), major 70 (`los`), minor 1956, warning 43.
- Flapping and PON-port correlation (one alarm when most ONUs on a port drop together) are intentionally deferred.

### Phase 15 - Dashboard

Created:

- `app/Http/Controllers/DashboardController.php`
- `resources/js/Components/Pagination.vue`
- `tests/Feature/DashboardTest.php`

Changed:

- `routes/web.php` - `/dashboard` now uses `DashboardController` instead of a static Inertia render.
- `resources/js/Pages/Dashboard.vue` - rebuilt from the Breeze placeholder into a real dashboard: 4 stat cards (OLT online/total, ONU online, ONU offline, critical alarms), three ApexCharts (ONU online/offline donut, alarm severity donut, ONU-per-OLT stacked bar), per-OLT status table, and a recent-active-alarms panel.
- `app/Http/Controllers/AlarmController.php` - alarms list now `paginate(20)->withQueryString()->through()` instead of a flat 300-row list.
- `resources/js/Pages/SmartOlt/Alarms.vue` - renders `alarms.data`, a "showing X-Y of N" line, and the `Pagination` component.
- `tests/Feature/AlarmEngineTest.php` - added pagination assertion (25 alarms -> 20 per page).

Notes:

- Charts use `vue3-apexcharts` imported locally in `Dashboard.vue` (not registered globally). ApexCharts is heavy (~570KB) but the Dashboard chunk is code-split, so it only loads on that page.
- Dashboard aggregates entirely from the cached `last_test_result` snapshots + `alarm_events` (no extra live SNMP), so it renders instantly and reflects the latest background poll.
- Verified aggregation against live data on 2026-05-25: 2 OLTs online, 56 ports (10 down), 2240 ONU (1501 online / 739 offline), 2079 active alarms (10 critical / 70 major / 1956 minor / 43 warning).

### Phase 16 - Configurable Poll Intervals, SNMP RX Power, dan Go Poller

Created:

- `database/migrations/2026_05_25_151500_add_poll_intervals_to_snmp_olts_table.php` — kolom `poll_interval_minutes`, `rx_poll_interval_minutes` (default 5), dan `last_rx_polled_at` di tabel `snmp_olts`.
- `app/Services/Snmp/GoSnmpPoller.php` — wrapper opsional untuk binary Go SNMP poller (`bin/kv-snmp-poller`); diaktifkan dengan `SNMP_POLLER_DRIVER=go` dan binary yang ada.

Changed:

- `app/Models/SnmpOlt.php` — tambah `poll_interval_minutes`, `rx_poll_interval_minutes`, `last_rx_polled_at` ke fillable/casts; tambah method `isPollDue()`, `isRxPollDue()`, `pollIntervalMinutes()`, `rxPollIntervalMinutes()`.
- `app/Services/Snmp/OltSnmpClient.php` — tambah OID `ZTE_ONU_RX_POWER` (`1.3.6.1.4.1.3902.1012.3.50.12.1.1.10`); method baru `onuRxPowers()` (SNMP walk seluruh ONU RX power), `mergeOnuRxPowers()`, dan helper internal `extractOnuPortIndex()`, `convertOnuRxPowerToDbm()`, `onuRxPowerKey()`, `countSnmpRxPowers()`, `intFromValue()`; `portOnusSnapshot()` sekarang mengambil RX via SNMP.
- `app/Jobs/PollOltJob.php` — refactor besar: RX power kini via SNMP walk bukan CLI; RX hanya di-poll saat `isRxPollDue()` berlaku; nilai RX lama dipertahankan saat interval belum lewat; `last_rx_polled_at` di-update setelah RX berhasil; data per-ONU diperkaya dengan `rx_power_source`, `rx_power_port`, `raw_rx_power`; Go poller dicoba lebih dulu jika dikonfigurasi, jatuh balik ke PHP.
- `app/Console/Commands/PollOltsCommand.php` — hanya dispatch job untuk OLT yang `isPollDue()` (skip OLT yang belum waktunya); laporan dispatched vs skipped.
- `routes/console.php` — scheduler `olts:poll` diubah dari `everyFiveMinutes()` ke `everyMinute()` karena setiap OLT kini menjaga interval sendiri.
- `app/Http/Controllers/SmartOltController.php` — `refreshPortOnus()` dihapus ketergantungan `ZteOnuRxPowerService` (RX sudah di dalam `OltSnmpClient`); validasi dan serialisasi ditambah `poll_interval_minutes`, `rx_poll_interval_minutes`, `last_rx_polled_at`; `serializeSnapshot()` memperkaya tiap port dengan `onu_count`, `online_onu_count`, `onu_search_items`, dan `search_text` untuk pencarian frontend.
- `app/Support/SmartOltSupport.php` — tambah kapabilitas `supports_snmp_rx`; `rx_source_label` diperbarui ke `Rx ONU (SNMP)`.
- `config/services.php` dan `.env.example` — tambah blok konfigurasi `snmp_poller` (driver, binary, timeout, retries, walk_mode, max_repetitions).
- `.gitignore` — tambah `/bin/kv-snmp-poller`.
- `resources/js/Pages/SmartOlt/Detail.vue` — tabel port diganti dengan grid kartu; tiap kartu menampilkan nama port, status, jumlah ONU online/total; tambah search bar untuk filter port/ONU berdasarkan SN, nama, atau deskripsi; hasil pencarian menampilkan preview ONU yang cocok.
- `resources/js/Pages/SmartOlt/Index.vue` — tampilkan interval polling (`Xm · RX Xm`) di kolom auto-poll.
- `resources/js/Pages/SmartOlt/Partials/OltForm.vue` — tambah input `poll_interval_minutes` dan `rx_poll_interval_minutes`.
- `resources/js/Pages/SmartOlt/PortOnus.vue` — label UX diperbaiki: "Status Cache" → "Data", "OK/Empty" → "Tersedia/Kosong"; hapus `ifIndex` dari subtitle.
- `resources/js/Pages/SmartOlt/Unconfigured.vue` — label UX diperbaiki: "Detected ONU" → "ONU Terdeteksi", hapus kolom "Source OID".
- `tests/Feature/OltPollingTest.php` — tambah coverage: poll interval due/not-due, RX dari SNMP, preservasi RX saat interval belum lewat, pembersihan RX lama saat SNMP kosong.
- `tests/Feature/SmartOltInventoryTest.php` — tambah coverage `onuRxPowers()` via SNMP dengan multi-format raw value (`INTEGER:`, signed decimal, signed short, -32768 sentinel invalid).

Notes:

- RX power kini full SNMP (tidak perlu CLI/Telnet untuk background poll). Nilai raw dari ZTE ONU RX OID dikodekan dalam tiga format berbeda tergantung firmware: milli-dBm (`-18500`), deci-dBm (`-185`), dan linear 14-bit (`5635` → `(raw * 0.002) - 30`). Fungsi `convertOnuRxPowerToDbm()` mendeteksi dan mengkonversi ketiganya.
- Scheduler kini jalan tiap menit, tapi masing-masing OLT hanya benar-benar di-poll sesuai `poll_interval_minutes`-nya — lebih fleksibel dari sebelumnya yang fixed 5 menit untuk semua.
- Go poller adalah opsional akselerasi; default tetap PHP. Binary `bin/kv-snmp-poller` di-gitignore.

### Phase 17 - UI Consistency Pass

Changed:

- `resources/js/Components/Modal.vue` — container diubah ke `flex min-h-full items-center justify-center` sehingga modal muncul di tengah viewport secara vertikal maupun horizontal; `mb-6` dihapus dari panel modal.
- `resources/js/Layouts/AuthenticatedLayout.vue` — header wrapper ditambah `min-h-[68px] flex items-center` untuk konsistensi tinggi antar halaman; import `usePage` dari Inertia; slot `<main>` dibungkus `<Transition name="page" mode="out-in">` dengan key `page.component` sehingga setiap navigasi antar halaman memiliki efek animasi fade + slide.
- `resources/css/app.css` — tambah CSS kelas `.page-enter-active`, `.page-leave-active`, `.page-enter-from`, `.page-leave-to` untuk animasi transisi halaman (fade 180ms masuk, 120ms keluar, dengan geseran vertikal 4–6px).
- `resources/js/Pages/SmartOlt/Index.vue` — header kolom Aksi diubah dari `text-right` ke `text-center`; action cell menggunakan `flex justify-center`.
- `resources/js/Pages/SmartOlt/PortOnus.vue` — header kolom Aksi diubah dari `text-right` ke `text-center`; action cell menggunakan `flex justify-center`.
- `resources/js/Pages/SmartOlt/Profiles.vue` — header kolom Aksi diubah dari `text-right` ke `text-center`; action cell (mode view & mode edit) menggunakan `flex justify-center`.
- `resources/js/Pages/SmartOlt/Unconfigured.vue` — header kolom Aksi diubah dari `text-right` ke `text-center`; action cell menggunakan `flex justify-center`.

Notes:

- Transisi `out-in` memastikan halaman lama selesai fade-out sebelum halaman baru fade-in — menghilangkan kesan header "melompat" saat navigasi.
- Modal center fix berlaku untuk semua modal di seluruh aplikasi (ConfirmModal, edit info ONU, dsb.) karena semuanya memakai komponen `Modal.vue`.

## 2026-05-25 (lanjutan)

### Phase 18 - Manajemen User

Created:

- `app/Http/Controllers/UserController.php` — CRUD user: index, store, update, destroy; proteksi self-delete; password opsional saat edit.
- `resources/js/Pages/Users/Index.vue` — halaman tabel user dengan modal tambah/edit (name, email, password) dan konfirmasi hapus; label "(Anda)" untuk user aktif; avatar inisial.
- `.claude/commands/done.md` — skill `/done` untuk update WORKLOG dan push GitHub setiap selesai task.

Changed:

- `routes/web.php` — tambah 4 route user: `GET /users`, `POST /users`, `PUT /users/{user}`, `DELETE /users/{user}`.
- `resources/js/Layouts/AuthenticatedLayout.vue` — tambah nav link **Users** (desktop + responsive).

Notes:

- Registrasi publik sudah dinonaktifkan sejak commit sebelumnya; halaman ini menjadi satu-satunya cara menambah user baru selain `php artisan user:create`.
- Self-delete diblokir di controller (kembalikan error flash jika `$user->id === $request->user()->id`).

### UI Consistency Pass (lanjutan)

Changed:

- `resources/js/Pages/Profile/Edit.vue` — `py-12` → `py-8`, `shadow sm:rounded-lg` → `rounded-lg shadow-sm`, `p-4 sm:p-8` → `p-6`, tambah `px-4` pada container; sekarang konsisten dengan semua halaman lain.
- `resources/js/Pages/Users/Index.vue` — `max-w-4xl` → `max-w-7xl` (konsisten dengan halaman tabel lainnya).

### Detail OLT - Gambar Hardware dan Info Tambahan

Changed:

- `resources/js/Pages/SmartOlt/Detail.vue` — card Capability diganti card gambar OLT (`/img/c320.jpg` atau `/img/c300.jpg` sesuai nama OLT, fallback icon Router); tambah computed `oltImage`, `onuTotal`, `onuOnline`; card Latency diganti card **Total ONU** (online / total); sysUptime diformat dari timeticks ke `Xh Xj Xm Xd`.
- `resources/js/Pages/Dashboard.vue` — chart ONU per OLT diubah dari horizontal bar ke vertical column chart (`horizontal: false`, `columnWidth: 50%`, label X rotasi -30°).

---

## 2026-05-26

### Dashboard OLT — Status Card, Trafik Uplink, VLAN Mapping, Form Add VLAN

Created:

- `app/Services/ZteCardUplinkService.php` — service baru untuk operasi CLI terkait hardware dan uplink: `getCardStatus()` (parse `show card`), `discoverUplinkInterfaces()` (deteksi otomatis dari tipe card), `getUplinkInfo()` (parse `show interface`, status + traffic Bps), `getVlanMapping()` (parse `show vlan port`, support range notation multi-baris), `addAndTagVlan()` (eksekusi script `configure terminal → vlan → switchport vlan tag → write`). Card status dan VLAN di-cache 5 menit; traffic selalu fresh.
- `resources/js/Pages/SmartOlt/Dashboard.vue` — halaman dashboard baru: tabel status card hardware (INSERVICE/STANDBY/OFFLINE), indikator UP/DOWN interface uplink, grafik trafik real-time ApexCharts (polling 10 detik), badge VLAN tagged per range, form tambah & tag VLAN dengan toast notification.

Changed:

- `app/Http/Controllers/SmartOltController.php` — tambah 4 method: `dashboard()` (render halaman Inertia), `refreshDashboard()` (POST, paksa reload dari CLI dan invalidate cache), `dashboardTraffic()` (GET JSON, live traffic polling tiap 10 detik), `storeDashboardVlan()` (POST JSON, eksekusi CLI tambah VLAN + invalidate cache).
- `routes/web.php` — daftarkan 4 route baru: `smartolt.dashboard`, `smartolt.dashboard.refresh`, `smartolt.dashboard.traffic`, `smartolt.dashboard.vlan`.
- `resources/js/Pages/SmartOlt/Detail.vue` — tambah tombol **Dashboard** di action bar header yang link ke halaman dashboard baru.

Notes:

- Diverifikasi langsung di OLT-1 (C320-PATI) dan OLT-2 (C300-SEKARJALAK). Format CLI berbeda dari dokumen PRD: interval traffic `20 seconds` (bukan 300), satuan `Bps` bytes/sec (bukan bits/sec), VLAN list pakai range notation (`20-120`) dan bisa multi-baris.
- Interface naming berbeda per chassis: C300 HUVQ → `xgei_1/{slot}/1-2`; C320 SMXA → `gei_1/{slot}/1-N`. SCXN juga punya `gei_` tapi bukan uplink traffic, sengaja di-skip dari discovery.
- Parser VLAN (`parseTaggedVlans`) handle `\r\n` dan akumulasi multi-baris sampai bertemu baris non-VLAN (prompt CLI).
- Grafik trafik: Y-axis dan tooltip otomatis format B/s → KB/s → MB/s → GB/s. Polling berhenti saat komponen di-unmount (`onBeforeUnmount`).

### Perbaikan Navigasi dan ONU ID dari Cache

Changed:

- `resources/js/Pages/SmartOlt/PortOnus.vue` — tombol kembali diubah dari `smartolt.detail` ke `smartolt.gpon-ports` dengan label "GPON Port & ONU".
- `resources/js/Pages/SmartOlt/Registrations.vue` — tombol kembali diubah dari `smartolt.detail` ke `smartolt.unconfigured-all?olt_id={id}` agar kembali ke halaman Unconfigured dengan OLT tetap terpilih.
- `app/Http/Controllers/SmartOltController.php` — `refreshUnconfigured()` redirect ke `smartolt.unconfigured-all` (bukan `smartolt.unconfigured` lama) agar flash message muncul di halaman yang benar. Hapus CLI call dari `suggestNextOnuId()` — sekarang hanya pakai data cache `last_test_result.port_onus.{slot}_{port}.onus`; hapus helper `canUseCliForOnuState()` dan `extractUsedOnuIdsFromStateOutput()` yang tidak lagi dipakai; hapus injeksi `ZteCliProvisioningExecutor` dari `registerOnuForm()`.
- `README.md` — tambah seksi instalasi Go (step 2) dan cara build binary `bin/kv-snmp-poller`; tambah Go ke tabel stack teknologi dan daftar persyaratan; renumber step instalasi 1–10.

Notes:

- Suggest ONU ID via cache sudah cukup karena data port ONU di-refresh setiap kali SNMP Refresh dijalankan. Telnet call saat buka form registrasi memperlambat halaman tanpa manfaat signifikan.
- Binary Go poller sudah ada di `cmd/kv-snmp-poller/main.go` dan `go.mod`; diaktifkan via `SNMP_POLLER_DRIVER=go` di `.env`.

### Port Manager — Chart Area Gradient dan Refactor Axios

Changed:

- `resources/js/Pages/SmartOlt/PortManager.vue` — chart trafik uplink diubah dari `line` ke `area` dengan gradient fill (opasitas 35% → 5%); urutan warna dibalik (hijau = RX/In, biru = TX/Out); formatter Y-axis ditingkatkan: tampilkan suffix `G` untuk nilai ≥ 1000 Mbps; nama seri disederhanakan menjadi `In (Mbps)` / `Out (Mbps)`; `dataLabels` dimatikan; border X-axis disembunyikan. `submitVlan` direfactor dari raw `fetch` API ke `axios.post` agar konsisten dengan seluruh codebase; error message kini ambil dari `e.response?.data?.message` sehingga pesan error dari server tertampil dengan benar; hapus dead code (manual VLAN fetch + komentar usang).
- `resources/js/Pages/SmartOlt/RegisterOnu.vue` — tombol Batal diubah route-nya dari `smartolt.unconfigured` ke `smartolt.unconfigured-all` dengan parameter `{ olt_id: olt.id }` agar navigasi kembali ke halaman Unconfigured global yang benar.

Notes:

- Chart `area` lebih informatif secara visual dibanding `line` karena area terisi menunjukkan volume trafik secara intuitif.
- `fetch` diganti `axios` karena axios sudah di-import global via Inertia dan menangani CSRF token secara otomatis; error response juga lebih mudah di-parse melalui `e.response?.data`.

### SmartOLT — Hardware dan Detail Interface Persisten

Created:

- `smartolt_card_statuses` dan `smartolt_interface_statuses` migrations — tabel cache persisten untuk `show card`, port-status, VLAN tagged, dan data optical-module-info bila nanti direfresh per interface.
- `2026_05_26_102000_add_gpon_metrics_to_smartolt_interface_statuses_table.php` — tambah kolom GPON metrics: ONU capacity/registered, rate Bps/pps, throughput %, peak rate, dan counters JSON.
- `App\Models\SmartOltCardStatus` dan `App\Models\SmartOltInterfaceStatus` — model Eloquent untuk data hardware dan detail interface.
- `tests/Feature/SmartOltHardwareInterfaceTest.php` — coverage agar halaman detail membaca hardware dari DB tanpa CLI, refresh hardware menyimpan `show card`, dan refresh Port Manager menyimpan detail interface.

Changed:

- `app/Services/ZteCardUplinkService.php` — source-of-truth card/VLAN/interface dipindah dari Laravel cache ke database; refresh CLI default sekarang parse `show card`, `show interface port-status`, dan `show vlan port` lalu persist ke tabel baru.
- `app/Http/Controllers/SmartOltController.php` — halaman Detail OLT dan Port Manager tidak lagi fallback ke CLI saat GET; tambah `refreshHardware()` dan perluas `refreshDashboard()` untuk update hardware + detail interface.
- `resources/js/Pages/SmartOlt/Detail.vue` — panel Status Card / Hardware selalu tampil dari DB dan punya tombol **Refresh Hardware**.
- `resources/js/Pages/SmartOlt/PortManager.vue` — tambah tabel **Detail Interface** dari DB; tombol **Refresh Data** memuat ulang isi tabel; live traffic tidak auto-start saat halaman dibuka.
- `resources/js/Pages/SmartOlt/PortManager.vue` — tabel interface dipisah menjadi **Port Uplink** dan **GPON Port**. GPON port punya tombol refresh per row.
- `routes/web.php` — tambah route `smartolt.hardware.refresh`.
- `routes/web.php` — tambah route `smartolt.dashboard.interface.refresh` untuk refresh satu GPON port.

Notes:

- Output C300 live menunjukkan `show interface port-status` wajib memakai interface leaf seperti `xgei_1/20/1`; command sampai slot saja (`xgei_1/20`) invalid.
- Parser optical mendukung format dua kolom ZTE seperti `Vendor-Name`/`Vendor-Pn`, `RxPower`/`TxPower`, dan `Temperature`/`Supply-Vol`.
- Refresh optical massal sengaja tidak dimasukkan ke tombol **Refresh Data** karena C300 dengan banyak PON/uplink bisa melewati timeout HTTP; optical sebaiknya dibuat per-interface atau background job.
- Refresh GPON per row menjalankan dua command: `show interface gpon-olt_1/{slot}/{port}` dan `show interface optical-module-info gpon-olt_1/{slot}/{port}`.
- Verifikasi: `php artisan test --filter=SmartOltHardwareInterfaceTest`, partial `SmartOltInventoryTest` render detail/index, `npm run build -- --mode=development`; real OLT id=2 refresh read-only turun dari 33.28s menjadi 17.66s; refresh per-port `gpon-olt_1/2/1` berhasil membaca status `activate/up`, ONU `25/128`, traffic, vendor optic, Tx power, dan temperature dalam 10.39s.

### Alarm — Multi-Filter, Customer Name, dan Footer Global

Changed:

- `app/Http/Controllers/AlarmController.php` — tambah filter multi-dimensi: severity, scope, type, OLT (`olt_id`), dan full-text search (`q`); setiap alarm kini menyertakan field `customer_name` yang di-lookup dari `smartolt_onu_registrations` dan fallback ke data `last_test_result` snapshot.
- `app/Services/AlarmEvaluator.php` — method baru `onuMeta()` mengekstrak `customer_name`, `onu_name`, `onu_description` dari data ONU dan menyertakannya ke field `meta` alarm (dipakai di `onuScopeFields()` dan `onuRxAlarm()`).
- `app/Support/SmartOltSupport.php` — tambah static helper `customerNameFromOnu()` dan `cleanCustomerName()`; handle format `$$...$$` ZTE, strip nilai junk (`-`, `n/a`, `gpon-onu_*`), dan skip jika nama sama dengan serial.
- `resources/js/Pages/SmartOlt/Alarms.vue` — panel filter baru (Cari, Severity, OLT, Scope, Tipe); severity summary card kini clickable untuk filter langsung; tombol status tambah opsi "Selesai" (`cleared`); reset filter satu klik.
- `resources/js/Layouts/AuthenticatedLayout.vue` — tambah global footer fixed-bottom dengan teks copyright; body diberi `pb-10` agar konten tidak tertutup footer.
- `app/Jobs/PollOltJob.php` — tambah `tries=1`, `timeout=600`, `failOnTimeout=true`; `WithoutOverlapping` middleware kini memakai `expireAfter(timeout+300)` agar lock tidak tersangkut selamanya bila job timeout.
- `config/queue.php` dan `.env.example` — tambah `REDIS_QUEUE_RETRY_AFTER=900` agar Redis queue tidak retry job panjang sebelum timeout.
- `app/Services/Snmp/OltSnmpClient.php` — normalisasi prefix port `gpon_` → `gpon-olt_` pada SNMP label agar nama port dari SNMP selalu cocok dengan nama CLI (C300 melaporkan `gpon_1/2/1` via SNMP tapi CLI-nya pakai `gpon-olt_1/2/1`).
- `app/Http/Controllers/SmartOltController.php` — `refresh()` kini merge snapshot baru ke `last_test_result` yang ada (bukan overwrite), sehingga data `port_onus` dan `unconfigured_onus` yang di-cache tidak terhapus saat SNMP refresh biasa.
- `app/Models/SnmpOlt.php` — tambah relasi `cardStatuses()` dan `interfaceStatuses()` ke model baru.
- `routes/web.php` — rename route grup dari `smartolt.dashboard.*` ke `smartolt.port-manager.*`; URL `/smartolt/{olt}/dashboard` → `/smartolt/{olt}/port-manager`.
- `tests/Feature/AlarmEngineTest.php` — tambah test filter multi-param, test customer_name dari snapshot, dan assert meta `customer_name` pada alarm RX attenuation.
- `tests/Feature/OltPollingTest.php` — tambah test bahwa `PollOltJob` skip jika OLT belum waktunya di-poll.

Notes:

- Customer name di-lookup dua lapis: pertama dari `smartolt_onu_registrations` (data provisioning), fallback ke snapshot `last_test_result.port_onus.*.onus` (data SNMP live). Ini memastikan nama pelanggan tampil meski ONU belum pernah diregistrasi lewat sistem.
- Route rename dari `dashboard` ke `port-manager` lebih deskriptif dan menghindari konflik bila ke depan ada halaman dashboard terpisah.
- `expireAfter(900)` pada `WithoutOverlapping` penting: tanpa ini, lock Redis tidak pernah expire bila job mati mendadak (OOM, kill), dan OLT berikutnya tidak akan di-poll.
