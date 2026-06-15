# Worklog

## 2026-06-14

### Histori RX Power (time-series) + visualisasi distribusi & tren

NMS sebelumnya tidak menyimpan data historis apa pun — polling hanya menulis snapshot terakhir ke
`snmp_olts.last_test_result` + log event. Padahal riwayat **RX power** adalah indikator dini
degradasi fiber/splitter (RX turun perlahan sebelum ONU mati). Slice pertama roadmap: simpan
time-series RX per ONU dari job polling yang sudah ada, lalu tampilkan **histogram distribusi RX**
lintas-ONU di ONU Monitoring dan **grafik tren RX 24h/7d/30d** per-ONU di ONU Detail. Ambang zona
konsisten dengan yang sudah dipakai (`kritis < -28`, `warning -28..-25/-10..-8`, `sehat`, overload
`≥ -8`) — sama dengan `rxLevel()` di OnuMonitor & `ReportService`.

Created:

- `database/migrations/2026_06_14_000000_create_onu_rx_samples_table.php` — tabel time-series
  ringan (`snmp_olt_id`, `slot`, `port`, `onu_id`, `serial_number`, `rx_power_dbm`, `polled_at`)
  + composite index `onu_rx_samples_lookup_idx` untuk query tren. Sqlite-compatible.
- `app/Models/OnuRxSample.php` — model (`$timestamps = false`) + static `seriesFor()` (riwayat satu
  ONU sejak `$since`, urut waktu menaik).
- `app/Console/Commands/PruneOnuRxSamplesCommand.php` — `optical:prune-rx {--days=}` (default
  `config services.snmp_poller.rx_sample_retention_days` = 90), hapus bertahap (pilih id → whereIn,
  portabel sqlite/pgsql).
- `resources/js/Components/SmartOlt/RxDistributionCard.vue` — histogram bin dBm (ApexCharts bar)
  diwarnai per zona + legend ringkas (Sehat/Warning/Kritis). Dihitung client-side, tanpa ubah backend.
- `resources/js/Components/SmartOlt/RxTrendCard.vue` — area chart RX vs waktu + pita zona +
  toggle rentang (router.reload partial), ringkasan terakhir/rata-rata/tertinggi/terendah.
- `tests/Feature/OnuRxHistoryTest.php` — `seriesFor` (filter range+urutan), prune command,
  prop `rx_history` di route onu.detail (stub `ZteOnuDetailService` agar tak buka telnet).

Changed:

- `app/Jobs/PollOltJob.php` — `recordRxSamples()` bulk-insert sample RX **hanya saat RX poll
  sukses** (pakai list `$onus` yang sudah di-merge, baik jalur Go poller maupun PHP).
- `app/Http/Controllers/SmartOltController.php` — `onuDetail()` baca `range` (default 7d) →
  `OnuRxSample::seriesFor`, kirim props `rx_history`+`range`. Props live CLI fetch dijadikan
  **lazy closure** + memoized supaya partial reload (ganti rentang grafik) tidak memicu sesi telnet.
- `config/services.php` — tambah `snmp_poller.rx_sample_retention_days` (env `SNMP_POLLER_RX_RETENTION_DAYS`).
- `routes/console.php` — jadwal `optical:prune-rx` harian 03:15.
- `resources/js/Pages/SmartOlt/OnuMonitor.vue` — render `RxDistributionCard` (data `oltScopedOnus`)
  di bawah stat cards.
- `resources/js/Pages/SmartOlt/OnuDetail.vue` — props `rx_history`/`range`. Panel **Optical**
  diubah: RX power jadi **speedometer** (ApexCharts radialBar, busur diwarnai per zona + nilai dBm
  di tengah), Optical & **Tren RX Power** disusun **2 kolom** (`xl:grid-cols-2`), kartu
  Temperature/Voltage/Bias Current dihapus (selalu kosong di firmware ini).
- `tests/Feature/OltPollingTest.php` — 2 test baru: sample tercatat saat RX sukses; tidak tercatat
  saat RX walk gagal.

Notes:

- Diverifikasi: `./vendor/bin/pint` bersih, `php artisan test` **129 passed**, `npm run build` sukses.
- Gotcha terulang: `php artisan test` nyasar ke **pgsql** karena config ter-cache override
  `phpunit.xml` → `config:clear` dulu (test pakai sqlite :memory:), lalu `config:cache` ulang.
  Tidak ada kerusakan data (RefreshDatabase pakai transaksi yang di-rollback).
- Deploy diterapkan di server: `config:cache`, `php artisan migrate --force` (tabel onu_rx_samples
  dibuat di pgsql), `npm run build`, `queue:restart` (worker pakai PollOltJob baru). Sample akan
  mulai terisi pada siklus RX poll berikutnya.
- Di luar scope (slice berikutnya): sparkline per-baris di OnuMonitor, tren TX/suhu & trafik per-PON,
  peta geografis OLT, live push Reverb, bulk operations.

## 2026-06-02

### Kartu filter seragam — komponen FilterCard + toolbar satu baris lintas halaman

Tampilan kartu filter sebelumnya beda-beda di tiap halaman (3 pola: header inline+grid+tombol,
header ikon-tile+flex select, grid polos tanpa header). Diseragamkan jadi satu bentuk via komponen
`FilterCard` + kelas `kv-filter-*`, lalu dipadatkan jadi **toolbar satu baris** (cari `lg:flex-1` +
kontrol `w-full sm:w-auto`) agar tidak memanjang ke 2 baris. Tanpa label di atas tiap kontrol —
opsi pertama dibuat self-describing ("Semua Severity", "Semua OLT", dst), input tanggal pakai `title`.

Created:

- `resources/js/Components/Shell/FilterCard.vue` — shell kartu filter standar (shell kaca +
  header ikon-tile + judul/subjudul + slot `#actions` + body). Dipakai semua halaman ber-filter.

Changed:

- `resources/css/app.css` — kelas `kv-filter`, `kv-filter-head/body`, `kv-filter-grid`,
  `kv-filter-label`, `kv-filter-control` (kontrol seragam 44px), `kv-filter-actions`,
  `kv-filter-reset`/`kv-filter-apply`.
- `resources/js/Pages/SmartOlt/Alarms.vue`, `resources/js/Pages/AuditLogs/Index.vue`,
  `resources/js/Pages/Reports/Index.vue`, `resources/js/Pages/SmartOlt/OnuMonitor.vue` — filter
  diubah ke `FilterCard` + toolbar satu baris (Alarms/AuditLogs server-side dgn tombol Terapkan;
  Reports/OnuMonitor live).
- `resources/js/Pages/SmartOlt/PortOnus.vue`, `resources/js/Pages/SmartOlt/GponPorts.vue` —
  toolbar inline di header tabel diselaraskan ke `kv-filter-control`/`kv-filter-reset` (tetap inline,
  bukan kartu terpisah).
- `docs/handbook/15-ui-tema-dashboard.md` — bagian "Kartu filter (pola wajib)": standar = toolbar
  satu baris via `FilterCard`, grid berlabel jadi alternatif.

Notes:

- Konsistensi di level **shell kartu + kontrol**; layout internal menyesuaikan jumlah field. Label
  dilepas hanya jika opsi self-describing (Alarms/AuditLogs/OnuMonitor/Reports semua aman).
- PortManager tidak diubah (select-nya kontrol kontekstual di dalam panel, bukan kartu filter).
- Diverifikasi `npm run build` (sukses) + reload php-fpm.

### Perbaiki gambar halaman detail OLT (C320 rusak, tambah C600)

Halaman detail OLT (`Detail.vue`) menampilkan foto hardware per model. Referensi `/img/c320.webp`
**tidak ada** (file asli `c320(1).webp`) → gambar C320 patah/404, termasuk OLT utama `OLT-C320-PATI`.

Created:

- `public/img/c320.webp` — disalin dari `c320(1).webp` agar referensi `/img/c320.webp` valid.
- `public/img/c600.webp` — konversi `c600.png` via `cwebp -q 82` (≈34 KB).

Changed:

- `resources/js/Pages/SmartOlt/Detail.vue` — `oltImage` tambah mapping `c600` → `/img/c600.webp`
  (selain c320 & c300).

Notes:

- C300 tetap pakai `c300.webp` resolusi tinggi; `c300(1).png` yang diunggah hanya thumbnail low-res
  jadi **tidak** dipakai (akan pecah di `max-h-96`).
- Diverifikasi via curl lokal: `/img/c300.webp`, `/img/c320.webp`, `/img/c600.webp` → semua
  `200 image/webp` (c320 sebelumnya 404).
- File unggahan mentah `c300(1).png`/`c600.png` dibiarkan untracked (tidak ikut commit).

## 2026-06-01

### Filter per-jenis alarm Telegram — pilih jenis alert yang dikirim di Pengaturan

Sebelumnya semua jenis alarm yang lolos `min_severity` selalu dikirim ke Telegram. Sekarang admin
bisa memilih jenis alarm mana yang masuk Telegram (mis. hanya **LOS, Dying Gasp, Redaman RX tinggi,
Port GPON down**) lewat checkbox di **Pengaturan → Bot Telegram**. Filter berlaku untuk notifikasi
raise maupun clear.

Created:

- `database/migrations/2026_06_01_000000_add_notify_types_to_telegram_settings_table.php` — kolom
  `notify_types` (json, nullable) di `telegram_settings`. `null` = semua jenis (kompat lama);
  array eksplisit (termasuk kosong) dihormati apa adanya. Sqlite-compatible.

Changed:

- `app/Models/AlarmEvent.php` — tambah konstanta `TYPE_*` (6 jenis) + `TYPE_LABELS` (label ID) +
  `types()` sebagai **sumber tunggal** daftar jenis alarm.
- `app/Services/AlarmEvaluator.php` — literal string jenis (`'los'`, `'port_down'`, dll) diganti
  konstanta `AlarmEvent::TYPE_*` agar tidak drift dengan daftar di filter.
- `app/Models/TelegramSetting.php` — `notify_types` fillable + cast `array`; helper
  `notifyTypes()` (null → semua) & `shouldNotifyType($type)`.
- `app/Services/Telegram/TelegramNotifier.php` — `notify()` lewati alarm yang jenisnya tak dicentang
  (`shouldNotifyType()`), berlaku untuk raise & clear; filter severity tetap.
- `app/Http/Controllers/SettingsController.php` — payload `telegram.notify_types` +
  `alarmTypeOptions`; validasi `notify_types` (array of `AlarmEvent::types()`), hanya overwrite bila
  field dikirim (absen → pertahankan set lama), disimpan ternormalisasi & berurutan kanonis.
- `resources/js/Pages/Settings/Index.vue` — grup checkbox "Jenis alarm yang dikirim" (Pilih semua /
  Kosongkan semua, peringatan saat kosong) di tab Telegram.

Notes:

- Default & kompat lama: instalasi yang sudah ada (`notify_types = null`) tetap menerima semua jenis
  alarm sampai admin mengubah pilihan. Mengosongkan semua centang = membisukan semua notifikasi alarm
  (perintah bot tetap jalan).
- Diverifikasi: `php artisan test` (TelegramSettings/TelegramWebhook/AlarmEngine — 31 passed),
  `./vendor/bin/pint` (passed), `npm run build` (sukses), `php artisan migrate --force` (DONE).
- Catatan: saat menjalankan test, `bootstrap/cache/config.php` harus di-`config:clear` dulu (kalau
  ter-cache, test nyasar ke pgsql & gagal palsu); sudah di-`config:cache` ulang setelahnya.

## 2026-05-30

### Overhaul halaman Welcome — animatif, interaktif & premium (GSAP + Lenis + tsParticles)

Merombak total landing page (`Welcome.vue`) agar lebih profesional, modern, dan interaktif. Stack animasi di-upgrade dari AOS ke GSAP + ScrollTrigger (scroll reveal), Lenis (smooth scroll), tsParticles (latar jaringan), typed.js (CLI typewriter), dan NumberFlow (statistik beranimasi). Mengikuti rekomendasi skill `ui-ux-pro-max` (pola "Real-Time / Operations Landing", efek hemat & bermakna, hormati `prefers-reduced-motion`).

Created:

- `resources/js/Components/Shell/ParticleNetwork.vue` — latar partikel saling terhubung garis (topologi fiber/GPON) berbasis tsParticles (slim bundle), reaktif kursor (mode grab). Skip total saat reduced-motion.

Changed:

- `resources/js/Pages/Welcome.vue` — overhaul struktur & animasi:
  - **Hero baru**: latar `ParticleNetwork`, preview dashboard dengan **tilt 3D + parallax** (direktif `v-tilt`, anak `data-depth`), kartu **terminal CLI hidup** yang mengetik command ZTE (`show gpon onu state ...`) via typed.js, chip status ONU "live".
  - **Stats band baru**: 4 statistik dengan **NumberFlow** (count-up saat masuk viewport via IntersectionObserver).
  - **Section "Cara Kerja" baru**: 4 langkah (Hubungkan OLT → Discovery/Polling → Provisioning → Monitor/Alarm).
  - **Scroll reveal**: AOS dihapus, diganti `ScrollTrigger.batch` (stagger) pada elemen `[data-reveal]`. Intro hero pakai animasi **CSS murni** (`.reveal-hero`) agar selalu tampil walau JS belum siap.
  - **Magnetic button** (direktif `v-magnetic`) pada CTA utama; **tilt** pada kartu fitur & langkah.
  - Smooth scroll **Lenis** disinkronkan ke ScrollTrigger via `gsap.ticker`; anchor nav pakai `lenis.scrollTo` (offset header). Cleanup di `onBeforeUnmount`.
  - `ParticleNetwork` dimuat via **`defineAsyncComponent`** → tsParticles jadi chunk terpisah `ParticleNetwork-*.js` (~31 KB gzip, lazy). GSAP/ScrollTrigger/Lenis di-import statis. Welcome chunk ~70 KB gzip.
- `app/Providers/AppServiceProvider.php` — **menonaktifkan `Vite::prefetch(concurrency: 3)`**. Prefetch eager memuat SELURUH chunk app (~60) di setiap halaman termasuk landing publik; tiap deploy (hash berubah) + cache CDN dingin → badai request **503** di console (script prefetcher `(index)` menembak hash lama yang sudah terhapus build baru). Dimatikan; Inertia tetap memuat chunk halaman tujuan saat dibuka. Mudah dikembalikan (1 baris).
- `resources/js/app.js` — handler `vite:preloadError`: auto-reload sekali (throttle 10 dtk via sessionStorage) saat preload chunk gagal (mis. setelah deploy hash berubah) agar browser memuat HTML + asset map terbaru.
- `vite.config.js` — `build.emptyOutDir: false`: pertahankan chunk hash lama saat rebuild agar tab/sesi aktif tidak patah ketika deploy. Konsekuensi: `public/build/assets/` menumpuk seiring waktu — perlu dibersihkan berkala saat deploy.

Notes:

- **PENTING — penyebab 500 saat pertama deploy & fix-nya:** meng-import statis komponen yang menarik library dengan banyak `import()` dinamis internal (di sini `@tsparticles/slim`) ke dalam sebuah Inertia page membuat Rollup **menggabungkan facade chunk page** sehingga key `resources/js/Pages/Welcome.vue` **hilang dari Vite manifest**. `app.blade.php` mem-preload `@vite([... "resources/js/Pages/{$page['component']}.vue"])` → `Unable to locate file in Vite manifest` → **HTTP 500**. Solusi: bungkus komponen tsParticles dengan `defineAsyncComponent` (chunk terpisah, facade page tetap utuh). Setelah build, **reload php-fpm** (`systemctl reload php8.3-fpm`) karena Laravel meng-cache manifest Vite di memori per-worker. Diverifikasi: `https://nms.kusumavision.net/` → **HTTP 200**.
- Dependency baru: `gsap`, `lenis`, `@tsparticles/engine`, `@tsparticles/slim`, `@number-flow/vue`, `typed.js`. AOS belum di-uninstall (masih di `package.json`) tetapi tidak lagi dipakai di Welcome — bisa dibersihkan terpisah bila tak dipakai halaman lain.
- Aksesibilitas: semua animasi (partikel, tilt, magnetic, reveal, typed, NumberFlow) dimatikan/diabaikan saat `prefers-reduced-motion: reduce`. Tema dark dipertahankan (sesuai konteks ops/NOC console).
- Diverifikasi dengan `npm run build` (sukses) + cek key manifest + HTTP 200. Verifikasi visual mendetail (partikel, typed, count-up, tilt bergerak mulus) sebaiknya dicek manual di browser.

### Bot Telegram — webhook perintah (inbound, read-only)

Sebelumnya bot Telegram hanya outbound (notifikasi alarm + tes). Sekarang bot bisa menerima perintah via webhook dan membalas data jaringan. Akses perintah data dibatasi hanya untuk `chat_id` terdaftar; chat lain hanya bisa `/start /help /id /ping`. Semua perintah **read-only** (tidak ada aksi tulis ke OLT).

Created:

- `database/migrations/2026_05_30_000000_add_webhook_to_telegram_settings_table.php` - tambah kolom `webhook_secret` (encrypted, nullable) & `commands_enabled` (boolean, default false) ke `telegram_settings`. Sqlite-compatible.
- `app/Services/Telegram/TelegramCommandHandler.php` - builder jawaban semua perintah (`/status /olt [nama|id] /alarm /onu <serial|nama> /prov /id /ping /help`). Baca sumber data yang sama dengan UI (`DashboardStatsService`, `last_test_result.port_onus`, `alarm_events`, `smartolt_onu_registrations`) → jawaban konsisten dengan dashboard. Otorisasi via `TelegramSetting::isChatAuthorized()`. Output HTML (parse_mode), escaping konsisten dengan notifier.
- `app/Services/Telegram/TelegramWebhookManager.php` - register/info/delete webhook ke Telegram (`setWebhook`/`getWebhookInfo`/`deleteWebhook`); generate `webhook_secret` (`Str::random(48)`) saat register. Dipakai bersama artisan command & SettingsController.
- `app/Http/Controllers/TelegramWebhookController.php` - endpoint publik `POST /telegram/webhook`; validasi header `X-Telegram-Bot-Api-Secret-Token` (`hash_equals`) → 403 bila salah; abaikan update non-pesan; selalu balas 200 agar Telegram tidak retry.
- `app/Console/Commands/TelegramWebhookCommand.php` - `php artisan telegram:webhook {set|info|delete}`.
- `tests/Feature/TelegramWebhookTest.php` - 9 test (secret salah→403, commands off→tak balas, chat tak terdaftar→ditolak tanpa bocor data, /id & /ping publik, /status, /onu found/not-found, update non-pesan diabaikan, route register memanggil setWebhook).

Changed:

- `app/Models/TelegramSetting.php` - tambah `webhook_secret`/`commands_enabled` (fillable, hidden, cast); helper `commandsReady()` & `isChatAuthorized()`.
- `app/Services/Telegram/TelegramNotifier.php` - method publik `sendTo($chatId, $text)` untuk balas ke satu chat (tanpa menyentuh `last_sent_at`); konstanta `SEVERITY_EMOJI` dijadikan public agar dipakai handler.
- `app/Http/Controllers/SettingsController.php` - payload `telegram` tambah `commands_enabled`+`webhook_set`; validasi/fill `commands_enabled`; method `registerWebhook()`/`deleteWebhook()`.
- `routes/web.php` - route publik `telegram.webhook`; route admin `settings.telegram.webhook.register`/`.delete`.
- `bootstrap/app.php` - `validateCsrfTokens(except: ['telegram/webhook'])`.
- `resources/js/Pages/Settings/Index.vue` - tab Telegram: toggle "Aktifkan perintah bot", badge status webhook, tombol Daftarkan/Hapus Webhook, daftar perintah.

Notes:

- Diverifikasi: `php artisan test` (121 passed), `./vendor/bin/pint` (file baru bersih; temuan `bootstrap/app.php` pre-existing, tidak disentuh), `npm run build`.
- **Deploy:** jalankan `php artisan migrate --force`, lalu setup webhook — isi bot token + chat ID di Pengaturan, centang "Aktifkan perintah bot", klik **Daftarkan Webhook** (atau `php artisan telegram:webhook set`). Webhook butuh URL HTTPS publik valid (`APP_URL`); pastikan nginx meneruskan `POST /telegram/webhook`. Cek dengan `php artisan telegram:webhook info`.
- Batasan: murni read-only; aksi (reboot/refresh) belum ada — bisa ditambah kemudian dengan konfirmasi ekstra.

### Configure ONU — perbaikan tampilan mobile

Changed:

- `resources/js/Pages/SmartOlt/ConfigureOnu.vue` - enam tabel baris-berulang (T-CONT, GEM Port, Service-port, Service, UNI VLAN, WAN Service Binding) sebelumnya memaksa scroll horizontal di mobile (`min-w-[600px]`–`820px`). Sekarang responsif: tetap grid tabel di ≥768px, dan menjadi kartu ber-label (label per-field) di layar kecil. Karena `grid-template-columns` dipasang via inline style, pemilihan layout dikendalikan reaktif lewat `isWide` (matchMedia 768px) + listener resize. Tombol hapus baris jadi tombol full-width "Hapus baris" di mobile; action bar bawah (Batal/Apply) full-width di mobile. Tambah CSS scoped `kv-rowcard`/`kv-cell`/`kv-action-cell`/`kv-flabel`/`kv-del-mobile`.

Notes:

- Tata letak desktop tidak berubah (jumlah kolom grid tetap cocok dengan `cols.*`).
- Diverifikasi dengan `npm run build`.

### Pengaturan: Tab Umum (branding aplikasi)

Created:

- `database/migrations/2026_05_29_140000_create_general_settings_table.php` - tabel singleton `general_settings` (`app_name`, `app_version`, `logo_path`).
- `app/Models/GeneralSetting.php` - model singleton (pola `instance()` seperti `TelegramSetting`), `logoUrl()`, dan `brandingPayload()` yang ter-cache + defensif (fallback default bila tabel belum ada); cache di-bust pada event `saved`/`deleted`.

Changed:

- `app/Http/Controllers/SettingsController.php` - `edit()` kini mengirim payload `general` + `appInfo` (tech stack); tambah `updateGeneral()` (validasi nama/versi, unggah/hapus logo ke disk `public/branding`).
- `routes/web.php` - route `POST /settings/general` (`settings.general.update`, admin-only).
- `app/Http/Middleware/HandleInertiaRequests.php` - share `branding` (nama/versi/logo) global; `systemInfo.version` kini ambil dari `GeneralSetting`.
- `resources/js/Components/ApplicationLogo.vue` - render logo unggahan bila ada, fallback ke SVG bawaan.
- `resources/js/Layouts/AuthenticatedLayout.vue` - nama aplikasi (mobile bar, sidebar, footer) ikut `branding.name`.
- `resources/js/Pages/Settings/Index.vue` - dibuat 2 tab: **Umum** (identitas aplikasi: nama, versi, logo + kartu Informasi Sistem/tech stack) dan **Bot Telegram** (form lama dipindah tanpa perubahan).

Notes:

- Diverifikasi dengan `php artisan migrate`, `npm run build`, `./vendor/bin/pint`, dan `php artisan test` (112 passed).
- Symlink `public/storage` sudah ada; logo unggahan disajikan via `/storage/branding/...`.
- Deploy prod: jalankan `php artisan migrate --force` (kolom sqlite-compatible untuk test).

## 2026-05-28

### Local Production Hardening

Changed:

- `composer.lock` - patched Symfony security advisories affecting `symfony/http-foundation`, `symfony/polyfill-intl-idn`, and `symfony/routing`.
- `routes/web.php` and `resources/js/Pages/Welcome.vue` - removed public Laravel/PHP version exposure from the landing page payload/UI.
- `tests/Feature/Auth/RegistrationTest.php` - aligned coverage with the intended security posture: public self-registration is not available.
- `tests/Feature/SmartOltInventoryTest.php` - aligned ONU ID suggestion coverage with cached port snapshot behavior.
- `README.md` - documented production local setup, Nginx hardening, Supervisor scheduler, audit commands, and firewall/SSH baseline.
- `docs/INSTALLATION_STATUS.md` - updated runtime, hardening, verification, and production-local status.
- `docs/LOCAL_PRODUCTION_HARDENING.md` - added operational hardening guide for Laravel, Nginx, PHP-FPM, SSH, UFW, Supervisor, dependency audits, and smoke tests.

Notes:

- Server is configured for local production at `http://192.168.99.61`.
- UFW default incoming policy is deny; SSH/HTTP are allowed from private LAN ranges plus `103.189.248.0/24` and `103.189.249.0/24`.
- SSH password authentication is disabled; access is key-only.
- Verified with `npm run build`, `php artisan test`, `composer audit`, `npm audit --omit=dev`, and HTTP smoke tests.

### Mobile UI Pass

Changed:

- `resources/js/Layouts/AuthenticatedLayout.vue` - added mobile search/notification actions, safer sidebar behavior after desktop collapse, and overflow guards for the app shell.
- `resources/css/app.css` - added reusable mobile data-card utilities and mobile-friendly background handling.
- `resources/js/Pages/SmartOlt/*.vue`, `resources/js/Pages/Users/Index.vue`, and dashboard table components - added mobile card views for wide operational tables while keeping desktop tables intact.

Notes:

- Mobile views now avoid forcing horizontal table scrolling for OLT inventory, alarms, users, profiles, hardware cards, ONU lists, unconfigured ONU lists, and Port Manager summaries.
- Verified with `npm run build`.

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

### ZTE C600 Support — SNMP + CLI + Provisioning

Created:

- `docs/ZTE ZXA10 C600 SNMP ifIndex Structure and Calculation.pdf` — dokumentasi formula ifIndex C600 (4-tier: rack/shelf/slot/port).
- `docs/ZTE ZXA10 C600 SNMP OID Management Guide.pdf` — OID tabel ONU config dan status C600 (.1082 subtree).
- `docs/ZTE ZXA10 C600 SNMP OIDs for ONU Optical Power.pdf` — OID RX power C600 dan formula konversi.
- `docs/SNMP Discovery Guide for ZTE C600 Unconfigured ONUs.pdf` — OID discovery ONU belum terkonfigurasi.
- `docs/ZXA10 C600 vs C300 CLI Command Migration Guide.pdf` — perbandingan CLI C300/C320 vs C600.
- `docs/ZTE ZXA10 C600 Line Card Identification and CLI Codes.pdf` — kode card type C600 (GFGH, GFXH, XGEI, dll).
- `docs/ZTE Titan C600 ONU Admin Status SNMP Configuration.pdf` — OID admin state ONU C600.

Changed:

- `app/Support/SmartOltSupport.php` — tambah `'c600'` ke keyword deteksi; tambah helper statis `isC600()`, `onuInterfaceId()`, `gponOltInterface()`; update `capabilities()` terima parameter opsional `$olt` untuk metadata C600 (vendor_family, port_name_prefix, is_c600, supports_separate_description).
- `app/Services/Snmp/OltSnmpClient.php` — tambah konstanta OID C600 (.1082 subtree): `C600_ONU_TYPE/NAME/SN/ADMIN_STATE/PHASE_STATE/LAST_DOWN_CAUSE/RX_POWER` dan `C600_UNCFG_OIDS`; tambah `onuOids()` helper per model; refactor `registeredOnus()`, `onuRxPowers()`, `unconfiguredOnus()` jadi model-aware; update `zteEncodeIfIndex()` dan `decodeIfIndex()` terima `$olt` untuk encoding 4-tier C600; update `parseSlotPort()` kenali interface 4-tier; update `resolvePortLabel()` kenali pola 3 dan 4 angka; update `decodePhaseState()` untuk phase code C600 (mulai dari 1, bukan 0).
- `app/Services/ZteRemoteOnuService.php` — tambah konstanta OID C600 untuk admin state dan name; `reboot()` kini pakai `SmartOltSupport::onuInterfaceId()` sehingga generate interface 4-tier untuk C600; `setActiveState()` dan `setInfo()` pilih OID berdasarkan model; description SNMP SET di-skip untuk C600 (tidak ada OID terpisah).
- `app/Services/ZteProvisioningScriptBuilder.php` — baca `is_c600` dari `$data`; gunakan `SmartOltSupport::gponOltInterface()` dan `onuInterfaceId()` untuk generate interface 3-tier/4-tier; perintah `description` dihilangkan untuk C600.
- `app/Services/ZteOnuRxPowerService.php` — `portRxPower()` pakai `SmartOltSupport::gponOltInterface()` untuk CLI command 4-tier; `parse()` gunakan regex berbeda untuk C600 (4 angka di nama interface).
- `app/Services/ZteCardUplinkService.php` — tambah konstanta card type C600: `C600_XGEI_CARDS` (`XGEI`, `SFUL`, `SFUM`), `C600_GEI_CARDS` (`GEI`), `C600_GPON_CARDS` (`GFGH`, `GFXH`, `GFXL`); `discoverUplinkInterfaces()` generate interface 4-tier (`xgei-1/1/slot/port`) untuk card C600.
- `app/Http/Controllers/SmartOltController.php` — inject `is_c600` ke data provisioning sebelum dikirim ke builder; `pon_port` audit record pakai `SmartOltSupport::onuInterfaceId()`; reboot flash message pakai interface dinamis; `capabilities()` dipanggil dengan `$olt` agar metadata C600 tersedia di frontend.

Notes:

- Deteksi C600 berdasarkan substring `'c600'` di `$olt->name`, `$olt->vendor`, atau `sysDescr` dari `last_test_result`. Cukup set nama OLT mengandung "C600" saat input data.
- ifIndex C600 berbeda total: C600 pakai 4-tier `(1<<28)|(1<<24)|(1<<16)|(slot<<8)|port` vs C300/C320 `0x10000000|(slot<<16)|(port<<8)`. Tanpa fix ini semua SNMP lookup ONU akan salah slot/port.
- Semua OID C600 ada di subtree `.1082.500` (zxAccessNode/zxAnPon), berbeda dari C300/C320 yang pakai `.1012` (ZTE-GPON-MIB legacy).
- Phase state C600 mulai dari 1 (bukan 0): `4=Working` (bukan `3`). `online` check diupdate sesuai.
- C600 tidak punya OID deskripsi ONU terpisah; field description bernilai `null` dan perintah `description` tidak dimasukkan ke provisioning script.
- Belum diverifikasi di real C600 device — perlu test langsung untuk konfirmasi ifIndex encode dan OID walks.

### Konsistensi Design System — Dark & Light Glassmorphism

Changed:

- `resources/js/Pages/SmartOlt/Profiles.vue` — terapkan DARK glassmorphism: content wrapper `from-slate-900 via-slate-800 to-indigo-950`, setiap section jadi dark glass card (`bg-white/[0.06] border-white/10 backdrop-blur-xl`), flash messages dark glass style, table header `text-slate-400 bg-white/[0.03]`, badge aktif/nonaktif pakai `ring-1` dark variant, checkbox label `text-slate-300`.
- `resources/js/Pages/SmartOlt/Create.vue` — content wrapper ganti ke LIGHT gradient `from-slate-50 via-blue-50/80 to-indigo-100/60`; white card wrapper dihapus karena form sudah dihandle OltForm.vue.
- `resources/js/Pages/SmartOlt/Edit.vue` — sama seperti Create.vue, content wrapper light gradient.
- `resources/js/Pages/SmartOlt/Partials/OltForm.vue` — refactor dari satu flat form menjadi 4 LIGHT glass section terpisah (Identitas OLT, Konfigurasi SNMP, Konfigurasi CLI, Auto-Poll); setiap section punya header icon (`Cpu`, `Network`, `KeyRound`, `Activity` dari Lucide); submit bar jadi light glass floating bar di bawah; import ikon ditambahkan.
- `resources/js/Pages/SmartOlt/PortManager.vue` — terapkan DARK glassmorphism menyeluruh: content wrapper dark gradient, flash messages dark glass, 3 section card (Trafik Uplink, Port Uplink, GPON Port) pakai dark glass card dengan header icon; table header/rows dark styling; `vlanBadgeColor()` dan `linkBadgeColor()` diganti ke dark variant (`*/15 text-*/300 ring-1 ring-*/25`); status indicator pill (UP/DOWN/loading) dark style; VLAN inline panel dark; tombol VLAN aksi dark; refresh button per GPON row dark.

Notes:

- `<script setup>` tidak diubah di Profiles.vue dan PortManager.vue — hanya template dan dua helper function badge di PortManager yang diupdate return value class-nya.
- OltForm.vue kini import `{ Activity, Cpu, KeyRound, Network }` dari `@lucide/vue` untuk ikon section header.
- Form komponen (`TextInput`, `InputLabel`, `InputError`) tetap light-styled karena didesain untuk background putih — LIGHT glassmorphism menjaga kontras agar tetap terbaca.

### Sidebar Navigation + Konsistensi Semua Halaman

Changed:

- `resources/js/Layouts/AuthenticatedLayout.vue` — total rewrite: navbar atas → sidebar kiri tetap `w-64` dark (`bg-slate-900`); mobile overlay + hamburger dengan Transition fade; logo + nav link dengan active state `bg-white/10`; user section di bawah sidebar (avatar initials + link Profil & Keluar); main content `lg:pl-64 flex flex-col min-h-screen`; footer in-flow (bukan `fixed bottom-0`) untuk hindari content overlap.
- `resources/js/Pages/Dashboard.vue` — dark glassmorphism: stat cards, chart containers, OLT table, alarm list; ApexCharts options `background: 'transparent'`, label/axis color `#94a3b8`, grid `rgba(255,255,255,0.06)`; `severityClass` dark variant.
- `resources/js/Pages/SmartOlt/Alarms.vue` — dark glassmorphism: severity cards clickable dengan `ring-2 ring-indigo-500/50` saat aktif; filter panel input `bg-white/[0.08]` dan select `bg-slate-800`; status toggle (Aktif/Selesai/Semua) masuk ke wrapper dark glass; `severityClass` dan `statusClass` dark variant.
- `resources/js/Pages/Users/Index.vue` — dark glassmorphism: table, avatar `bg-indigo-500/20 text-indigo-300 ring-1 ring-indigo-500/30`, flash messages dark glass.
- `resources/js/Pages/SmartOlt/Index.vue` — dark glassmorphism: OLT cards, status dot glowing `shadow-[0_0_8px_...]`, ZTE badge `bg-sky-500/15`, action buttons dark.
- `resources/js/Pages/SmartOlt/GponPorts.vue` — dark glassmorphism: 3 stat cards, port cards `border-emerald-500/20 bg-white/[0.06]` untuk port up; search input dark; badge "Selesai" `bg-emerald-500/20 ring-emerald-500/30`.
- `resources/js/Pages/SmartOlt/Detail.vue` — dark glassmorphism: 4 stat cards, System Info card dengan icon badge sky, hardware table dark; `cardStatusColor()` return dark badge class.
- `resources/js/Pages/SmartOlt/Registrations.vue` — dark glassmorphism: registration items `divide-white/[0.06]`; `<pre>` script dan execution output `rounded-xl bg-slate-950 border border-white/[0.06]`; `statusClass()` dark variant.
- `resources/js/Pages/SmartOlt/Unconfigured.vue` — dark glassmorphism: stat cards, tabel ONU unconfigured, flash messages.
- `resources/js/Pages/SmartOlt/UnconfiguredGlobal.vue` — dark glassmorphism: OLT selector cards dengan selected state indigo, summary cards, tabel ONU.
- `resources/js/Pages/SmartOlt/PortOnus.vue` — dark glassmorphism: 4 stat cards, ONU table, `rxBadgeClass()` dengan threshold warna (-28/-8 merah, -25/-10 amber, hijau untuk normal), phase dot glowing.
- `resources/js/Pages/SmartOlt/RegisterOnu.vue` — LIGHT glassmorphism: 4 section kartu (Identitas/GPON/WAN/Fitur) masing-masing dengan icon header; WAN Mode jadi visual button selector (PPPOE/DHCP/STATIC); submit bar light glass floating.

Notes:

- Design token konsisten di semua halaman — DARK: `bg-white/[0.06] border-white/10 backdrop-blur-xl` di atas `bg-gradient-to-br from-slate-900 via-slate-800 to-indigo-950`; LIGHT: `bg-white/70 border-white/70 backdrop-blur-xl` di atas `from-slate-50 via-blue-50/80 to-indigo-100/60`.
- Halaman form (RegisterOnu, Create, Edit, OltForm) pakai LIGHT karena komponen `TextInput`/`InputLabel` hard-coded untuk background terang.
- Sidebar mobile menggunakan Transition Vue bawaan untuk overlay fade — tidak butuh library animasi tambahan.
- Build Vite `npm run build` berhasil 13.84s tanpa error setelah semua perubahan digabung.

### Fix Layout: Header Putih, Footer Sticky, Background Gap

Changed:

- `resources/js/Layouts/AuthenticatedLayout.vue` — tiga perbaikan: (1) outer wrapper `bg-gray-100` → `bg-slate-950` menghilangkan celah putih di bawah konten halaman pendek; (2) header `bg-white shadow-sm` → `bg-slate-900/95 backdrop-blur-sm border-white/10` dengan `[&_h2]:!text-white [&_p]:!text-slate-400` agar judul/subtitle di semua slot header otomatis jadi warna gelap tanpa ubah tiap halaman; (3) footer `bg-white` → `bg-slate-900/95 backdrop-blur-sm` + `sticky bottom-0 z-10` agar footer selalu terlihat di bawah viewport.
- `resources/js/Pages/Profile/Edit.vue` — content wrapper dari `py-8` polos ke LIGHT glassmorphism `from-slate-50 via-blue-50/80`; card `bg-white p-6 shadow-sm` → `bg-white/70 backdrop-blur-xl border-white/70 rounded-2xl shadow-xl`.

Notes:

- `[&_h2]:!text-white` di header layout menggunakan arbitrary variant Tailwind + `!important` modifier untuk override `text-gray-800` di slot konten tiap halaman — satu tempat, berlaku global.
- `sticky bottom-0` pada footer bekerja karena flex container punya `min-h-screen`: saat konten pendek, footer natural di bawah (via `flex-1` pada main); saat konten panjang, footer sticky ke bawah viewport saat scroll.
- Outer `bg-slate-950` adalah fallback: semua area transparent di dalam stack meneruskan ke bg ini, menghilangkan `bg-gray-100` yang bocor ke area kosong.

### Phase 19 - CLI Output Sanitization dan Glasmorphism Design Refinement

Created:

- `app/Support/CliOutputSanitizer.php` — utility class untuk membersihkan CLI output dari telnet control sequences, normalize UTF-8, dan hapus invalid control characters. Method statis `clean()` memproses output melalui tiga tahap: (1) strip Telnet protocol sequences (0xFF commands), (2) normalize UTF-8 dengan fallback iconv, (3) hapus ANSI escape sequences dan unprintable chars. Method privat `normalizeUtf8()` detect dan repair UTF-8 breaks; `stripTelnetControlSequences()` iterate byte-by-byte skip 0xFF protocol blocks.
- `tests/Unit/CliOutputSanitizerTest.php` — coverage lengkap: test clean output pass-through, strip telnet 0xFF IAC+ECHO, strip ANSI color `\x1B[...m`, normalize UTF-8 invalid bytes, remove null bytes dan control chars, preserve newlines/tabs.
- `tests/Feature/SmartOltRegistrationExecutionTest.php` — feature test provisioning execution: tester fakes `ZteCliProvisioningExecutor` dan `OltSnmpClient`, assert execution output di-sanitize sebelum disimpan, check flash message dan status `executed`/`failed`, test double-execute guard (status=`executed` render error flash bukan rerun).

Changed:

- `app/Http/Controllers/SmartOltController.php` — import `CliOutputSanitizer`; method `executeRegistration()` sanitize `$result['output']` dan `$result['error']` sebelum save ke DB; guard double-execute jika registration sudah status `executed`; error message di flash juga sanitize `$error`.
- `app/Services/ZteCliProvisioningExecutor.php` — import `CliOutputSanitizer`; method privat `run()` sanitize output sebelum `detectError()` parse (mencegah false positive dari ANSI sequences).
- `resources/css/app.css` — expand custom @layer components: tambah `.kv-panel`, `.kv-card`, `.kv-section`, `.kv-table`, `.kv-badge`, `.kv-input`, `.kv-button`, `.kv-text-*` untuk design consistency. Tambah `.kv-glass-dark` dan `.kv-glass-light` untuk glasmorphism base styles. Kelas layout `.kv-page`, `.kv-page-compact`, `.kv-container`, `.kv-container-narrow` sudah ada dari phase sebelumnya.
- Vue components (Checkbox, Modal, ConfirmModal, DangerButton, DropdownLink, IconButton, InputLabel, NavLink, Pagination, PrimaryButton, ResponsiveNavLink, SecondaryButton, TextInput) — update untuk align dengan design system: class binding sesuaikan ke dark/light context, shadow consistency, border radius 2xl, text color match glasmorphism palette.
- `resources/js/Layouts/AuthenticatedLayout.vue` — sidebar mobile transition dibuat smooth dengan fade + scale; header positioning refinement `sticky top-0 z-20` untuk tetap terlihat saat scroll; main content jadi `flex-1` agar panjang viewport minimum tercapai.
- `resources/js/Layouts/GuestLayout.vue` — background gradient update ke slate-950 base; card container `.kv-card` dengan light glass style.
- `resources/js/Pages/Auth/*` (Login, Register, ConfirmPassword, ForgotPassword, VerifyEmail) — terapkan LIGHT glassmorphism: background gradient `from-slate-50 via-blue-50/80 to-indigo-100/60`, form card glass, button consistency.
- `resources/js/Pages/Dashboard.vue` — rebuild dengan dark glassmorphism: content wrapper gradient dark, stat card 4x grid dark glass, chart container dark glass, alarms panel dark glass.
- `resources/js/Pages/Profile/Edit.vue` — terapkan design system: form section grid dark glass untuk security info, delete account button danger variant.
- `resources/js/Pages/SmartOlt/Detail.vue` — port card grid layout sesuaikan ukuran responsif, search bar dark glass style, card typography refinement.
- `resources/js/Pages/SmartOlt/GponPorts.vue` — table port list dark glass header/rows, status indicator pill styling.
- `resources/js/Pages/SmartOlt/Index.vue` — table header dark glass background, action column center align, pagination component integrate.
- `resources/js/Pages/SmartOlt/Unconfigured.vue` — ONU list table dark glass, unconfigured badge color scheme, register button primary.
- `resources/js/Pages/SmartOlt/UnconfiguredGlobal.vue` — global unconfigured view dark theme, OLT filter dropdown dark style.
- `resources/js/Pages/SmartOlt/RegisterOnu.vue` — form wrapper light gradient background, input section grid layout, profile dropdown konsisten styling.
- `resources/js/Pages/SmartOlt/Registrations.vue` — registration table dark glass, status badge (pending/executed/failed) color scheme, execute action button danger variant untuk confirm.
- `resources/js/Pages/SmartOlt/Alarms.vue` — alarm filter panel dark glass, severity chip clickable dengan hover effect, table dark styling, pagination component.
- `resources/js/Pages/Users/Index.vue` — user table dark glass, user avatar circle size, modal form light glass background.
- `resources/js/Pages/Welcome.vue` — landing page refresh dark theme, feature grid glasmorphism card, CTA button primary variant.
- `.gitignore` — tambah `skills-lock.json` ke ignore list (generated file dari tool).

Notes:

- CliOutputSanitizer penting untuk provisioning audit: Telnet stream mengandung 0xFF protocol bytes dan ANSI escape sequences warna; output mentah tidak bisa disimpan langsung ke DB tanpa corruption atau field overflow.
- Glasmorphism design refinement mencakup konsistensi color palette (slate-50 light / slate-900-950 dark), backdrop blur (xl untuk main card, sm untuk subtle backgrounds), border colors (white/10 dark / white/70 light), shadow consistency (shadow-lg + ring-1).
- Build `npm run build` selesai 14.29s tanpa error; codebase siap production.
- Verifikasi: test `php artisan test` mencakup unit test CliOutputSanitizer (10 test case) dan feature test execution (3 scenarios); real OLT provisioning execution capture output, sanitize, store, dan display di UI tanpa corruption.

## 2026-05-28

### Phase 20 - Detail ONU & Configure ONU (CLI)

Created:

- `app/Services/ZteOnuRunningConfigService.php` — baca live running-config (`show running-config interface …` + `show onu running config …`) lalu parse ke struktur form Configure (guide Section 7). `normalizeLines()` repair line-wrap khas ZTE (`vlan-profi le` → `vlan-profile`, dst.) + gabung continuation token; `parse()` kenali pattern name/description, tcont, gemport, service-port, service (pon-onu-mng, `type` opsional), vlan port (UNI), wan binding, wan-ip (pppoe/dhcp/static + vlan-profile), tr069-mgmt, security-mgmt; konversi mask dotted→length.
- `app/Services/ZteOnuReconfigureScriptBuilder.php` — `build(baseline, target, context)` hasilkan **delta script** (guide Section 5.4): hanya emit baris CLI yang berubah, dibungkus blok `interface`/`pon-onu-mng`, plus daftar `changes` (label, from, to) untuk panel "What Will Change". Tanpa perubahan → script kosong. Diff per-section by id/name, `no …` untuk row yang dihapus, re-emit penuh `wan-ip 1 …` bila mode/credential/profile berubah, toggle tr069 (unlock/lock) & security-mgmt (enable/disable).
- `app/Services/ZteOnuDetailService.php` — baca `show gpon onu detail-info` + `show pon power attenuation` (guide Section 6). `parse()` dual-pass: build all-map (normalize key snake_case, skip echo/prompt/attenuation/session rows) → bucket ke grup identity/state/optical/last_event via `pick()` (exact dulu, lalu substring). `applyAttenuation()` isi onu_rx/tx + att up/down (dan optical Rx/Tx bila kosong); `applySessionHistory()` isi last_event dari tabel session (row OfflineTime `0000-` = sesi current).
- `resources/js/Pages/SmartOlt/ConfigureOnu.vue` — halaman Configure sesuai desain: panel kiri CURRENT CONFIG + Raw terminal; kanan form semua section multi-row (T-CONT, GEM Port, Service-port, PON-ONU-MNG/Service, UNI VLAN, WAN binding) dengan header kolom + scroll horizontal, WAN mode selector, TR069 & Remote-ONT toggle; bawah GENERATED SCRIPT (delta-live, debounce 400ms ke endpoint preview) + WHAT WILL CHANGE + Apply/Batal + banner peringatan putus koneksi.
- `resources/js/Pages/SmartOlt/OnuDetail.vue` — halaman Detail tervisualisasi: 4 hero stat card (Status, RX Power, Jarak, Online Duration), section Optical dengan gauge RX berzona warna + bar atenuasi up/down + chip metrik (temp/voltage/bias), kartu grup Identitas/Status/Last Event, accordion Semua Field & Raw output.
- `tests/Unit/ZteOnuConfigureTest.php` — 6 test: parse running-config, konversi mask, delta kosong saat tanpa perubahan, delta minimal saat name berubah, add/remove service-port, perubahan WAN/tr069/remote-ont.
- `tests/Unit/ZteOnuDetailTest.php` — 2 test: parse detail-info ke grup + suplemen atenuasi, dan pengisian last_event dari session history.

Changed:

- `app/Http/Controllers/SmartOltController.php` — import 3 service baru; method `onuDetail` (render `OnuDetail.vue`), `configureOnuForm` (render `ConfigureOnu.vue` + baseline + profileOptions), `configureOnuPreview` (JSON delta murni tanpa OLT), `configureOnuApply` (eksekusi delta via Telnet + audit row `reconfigured`/`reconfig_failed`); helper `validatedReconfigure`, `findCachedOnu`, `resolvePrimaryVlan`; `wan_mode` di-coerce ke pppoe/dhcp/static agar tak melanggar enum tabel audit.
- `routes/web.php` — 4 route baru: `smartolt.onu.detail`, `smartolt.onu.configure`, `smartolt.onu.configure.preview`, `smartolt.onu.configure.apply`.
- `resources/js/Pages/SmartOlt/PortOnus.vue` — tombol aksi Detail (ikon Info) & Configure (ikon Settings) di desktop + mobile, gated capability `supports_cli_onu_detail` / `supports_cli_onu_configure`.
- `docs/SMARTOLT_ZTE_C300_C320_GUIDE.md` — referensi parser/builder Section 5.4/6/7 diarahkan ke kelas nyata di repo ini (`ZteOnuDetailService`, `ZteOnuRunningConfigService`, `ZteOnuReconfigureScriptBuilder`) menggantikan `ZteCliSessionService` blueprint; tambah callout status implementasi + catatan nama/path route web yang sebenarnya.
- `README.md` — tambah fitur Detail ONU (CLI) & Configure ONU (CLI delta) ke daftar Fitur.

Notes:

- Capability `supports_cli_onu_detail` & `supports_cli_onu_configure` sudah tersedia (true untuk ZTE) di `SmartOltSupport`, jadi tinggal di-wire.
- Delta-live preview murni diff baseline↔target di backend (tanpa akses OLT), jadi aman dipanggil debounced tiap edit; hanya Apply yang membuka sesi Telnet.
- Guide Section 6/7 ternyata blueprint dari proyek lain (kelas `ZteCliSessionService` tidak pernah ada di repo ini); fitur dibangun ulang dengan pemecahan kelas `ZteOnu*`.
- 8 unit test baru hijau; `npm run build` & `./vendor/bin/pint` bersih. Belum diverifikasi ke OLT live (id=1 `OLT-C320-PATI`) — parsing real-firmware & delta perlu dicek langsung di OLT.

### Phase 21 - Search & Filter ONU + Deep-link dari Global Search

Changed:

- `resources/js/Pages/SmartOlt/PortOnus.vue` — tambah search lokal (cocokkan interface/serial/nama/deskripsi/type) + filter Phase (semua/online/offline) & Admin (semua/active/disabled) + tombol Reset; penghitung hasil `(X/Y)` di judul; empty-state "tidak ada ONU cocok"; daftar (tabel desktop & kartu mobile) kini iterasi `filteredOnus`. Baca prop `initial_search`/`focus_onu_id` untuk pre-fill search dan scroll + highlight ONU target; `scrollToFocus()` pilih elemen yang terlihat via `data-onu-id` + cek `offsetParent` agar tidak salah target di layout responsif.
- `app/Http/Controllers/SmartOltController.php` — `portOnus()` terima `Request`, teruskan query `q` → prop `initial_search` dan `focus` → prop `focus_onu_id`.
- `app/Http/Controllers/DashboardSearchController.php` — URL hasil ONU pada global search kini menyertakan `q=<serial/nama>` & `focus=<onu_id>` agar halaman port langsung terfilter dan menyorot ONU yang dicari.

Notes:

- Tujuan: hasil global search (⌘K) untuk ONU mendarat di halaman port dengan ONU spesifik langsung ter-scroll + ter-highlight, bukan sekadar membuka daftar port.
- Filter/search murni client-side atas snapshot ONU yang sudah ada (tanpa request tambahan ke OLT). Stat card (Total/Online) tetap menampilkan total, bukan hasil filter.
- `npm run build` & `pint` bersih; interaksi scroll/highlight belum dites di browser (perlu data ONU live).

### Phase 22 - Role User (RBAC), Halaman Report & Mode Demo

Created:

- `docs/PLANNING_NEXT_PHASE.md` — dokumen perencanaan fase ini (keputusan desain, matriks hak akses, urutan eksekusi).
- `app/Enums/UserRole.php` — enum `admin`/`operator`/`demo` + `label()`, `values()`, `options()`.
- `database/migrations/2026_05_28_145148_add_role_to_users_table.php` — kolom `role` string (default `operator`); user lama di-set `admin` agar tak terkunci. String (bukan enum native) demi kompatibilitas SQLite test.
- `app/Http/Middleware/EnsureUserRole.php` — middleware berparameter (`role:admin`, `role:admin,operator`).
- `app/Http/Middleware/BlockDemoWrites.php` — tolak semua request non-GET untuk role demo (kecuali logout).
- `app/Services/Report/ReportService.php` — builder laporan generik (columns/rows/summary) 5 jenis: inventaris ONU, status OLT, riwayat alarm, provisioning, RX power; filter range + per-OLT. Baca skema `last_test_result` (`port_onus.{slot}_{port}.onus`, `rx_power_dbm`).
- `app/Http/Controllers/ReportController.php` — `index` (Inertia), `exportCsv` (StreamedResponse + BOM UTF-8), `exportPdf` (dompdf landscape).
- `resources/views/reports/pdf.blade.php` — template PDF berbranding BMKV/KusumaVision.
- `resources/js/Pages/Reports/Index.vue` — halaman Report: filter jenis/range/OLT (auto-reload), kartu ringkasan, tabel desktop + kartu mobile, badge status berwarna, tombol export CSV/PDF.
- `database/seeders/DemoSeeder.php` — isi DB demo: user `admin@`/`demo@kusumavision.test`, 2 OLT (`OLT-DEMO-PATI` C320, `OLT-DEMO-JUWANA` C300) dengan `last_test_result` realistis (port up/down, ONU online/offline, RX bervariasi), ~200 polling event/OLT, alarm campuran severity, registrasi provisioning.
- `docs/DEMO_DEPLOYMENT.md` — panduan deploy instance/DB demo terpisah + peringatan jangan seed ke produksi.
- `tests/Feature/RoleAccessTest.php`, `tests/Feature/ReportTest.php`, `tests/Feature/DemoSeederTest.php` — 10 test (akses per-role, blokir tulis demo, guard admin terakhir, render report, export CSV/PDF, isi DemoSeeder).

Changed:

- `app/Models/User.php` — `role` di `$fillable` + cast `UserRole`; helper `isAdmin`/`isOperator`/`isDemo`/`canManageOlt`/`canManageUsers`.
- `bootstrap/app.php` — alias `role` + append `BlockDemoWrites` di grup web.
- `routes/web.php` — route users dibungkus `role:admin`; tambah 3 route `reports.*`.
- `app/Http/Middleware/HandleInertiaRequests.php` — share `auth.can` (`manage_users`, `manage_olt`, `is_demo`).
- `app/Http/Controllers/UserController.php` — validasi `role` (enum), sertakan role + `roleOptions` di index, guard admin terakhir (tak bisa dihapus/diturunkan).
- `resources/js/Pages/Users/Index.vue` — dropdown role di modal, badge role berwarna (admin=cyan, operator=emerald, demo=amber) di tabel & kartu mobile.
- `resources/js/Layouts/AuthenticatedLayout.vue` — nav `Report` (semua role) & `Users` (hanya admin via `auth.can`); banner "Mode Demo" read-only.
- `resources/js/Pages/SmartOlt/Index.vue` — tombol "Tambah OLT" digate `auth.can.manage_olt`.
- `database/factories/UserFactory.php` — default role operator + state `admin()` & `demo()`.
- `database/seeders/DatabaseSeeder.php` — user test default jadi admin.
- `composer.json` — tambah `barryvdh/laravel-dompdf` untuk export PDF.

Notes:

- Keputusan disepakati user: RBAC kolom enum sederhana (bukan paket), 3 role (admin/operator/demo), data demo via DB/deploy terpisah (bukan flag `is_demo`), report on-screen + CSV + PDF.
- Demo read-only diberlakukan 2 lapis: `BlockDemoWrites` (server, semua non-GET) + gating tombol/UI. Operator = semua operasi OLT/ONU kecuali kelola user.
- Keamanan SmartOLT write tak perlu `role:` tambahan: admin+operator boleh, demo sudah diblokir `BlockDemoWrites`.
- 91 test hijau (10 baru), `npm run build` & `pint` bersih.
- Migrasi `role` sudah dijalankan di DB produksi via `php artisan migrate --force`; 2 user existing otomatis jadi admin (terverifikasi). Demo: `php artisan db:seed --class=DemoSeeder` di instance demo terpisah.

Lanjutan — filter PON port di Report:

- `app/Services/Report/ReportService.php` — filter `pon_port` (format `{slot}_{port}`): laporan ONU & RX hanya iterasi key port yang dipilih; alarm & provisioning di-where `slot`+`port`.
- `app/Http/Controllers/ReportController.php` — baca/validasi `pon_port` (regex `\d+_\d+`, hanya berlaku bila ada `olt_id`); sediakan `ponPortOptions` dari `last_test_result.ports` OLT terpilih.
- `resources/js/Pages/Reports/Index.vue` — dropdown PON Port (disabled bila belum pilih OLT), grid filter jadi 4 kolom, auto-reset port saat OLT berganti; export CSV/PDF ikut membawa `pon_port`.
- `tests/Feature/ReportTest.php` — test filter PON port (port ada → 2 baris, port tak ada → 0 baris). Total 92 test hijau.

Revisi isolasi demo — flag `is_demo` (bukan DB terpisah):

- Alasan: user menjalankan satu instance, jadi role demo malah melihat data OLT produksi asli. Pendekatan diubah ke flag `is_demo` + global scope di DB yang sama.
- `database/migrations/2026_05_28_160000_add_is_demo_flags.php` — kolom `is_demo` (boolean, default false, indexed) di `snmp_olts`, `alarm_events`, `polling_events`, `smartolt_onu_registrations`.
- `app/Models/Scopes/DemoScope.php` — global scope: user role demo → hanya `is_demo=true`; selain itu (termasuk console/queue tanpa auth) → hanya `is_demo=false`. Diterapkan di 4 model tsb (+ `is_demo` di fillable/cast).
- `database/seeders/DemoSeeder.php` — semua data demo di-set `is_demo=true`; `SnmpOlt::withoutGlobalScopes()->updateOrCreate(...)` agar idempotent.
- `tests/Feature/DemoSeederTest.php` — tambah test isolasi: admin lihat 1 OLT nyata, user demo lihat 2 OLT demo. `tests/Feature/RoleAccessTest.php` — OLT uji blokir-tulis di-flag demo agar resolvable lalu 403.
- Dampak: scope otomatis berlaku di Dashboard, SmartOLT, Report, notifikasi alarm (semua query model tsb). Polling scheduler (console) hanya menyentuh OLT nyata; OLT demo statis.
- Dijalankan di prod: `migrate --force` (kolom is_demo) + `db:seed --class=DemoSeeder --force`. Terverifikasi: OLT nyata=2, OLT demo=2, polling demo=400, alarm demo=8. 93 test hijau, `pint` bersih.

Audit keamanan isolasi demo:

- Hasil audit: tidak ada `DB::table` langsung ke tabel sensitif, tidak ada `withoutGlobalScope` di kode app, semua baca lewat Eloquent yang ter-scope. Tabel `SmartOltCardStatus`/`SmartOltInterfaceStatus` (tanpa is_demo) hanya diakses lewat OLT yang sudah ter-scope via route-binding → user demo akses OLT nyata = 404, dan sebaliknya. `AlarmController::customerNamesFor` pakai `SmartOltOnuRegistration` (scoped). Scheduler `PollOltsCommand`/`PollOltJob` jalan tanpa auth → hanya OLT nyata.
- `tests/Feature/DemoIsolationTest.php` — kunci regresi: user nyata hanya lihat data nyata (dashboard/index/report/alarm) + 404 saat akses OLT demo; user demo hanya lihat data demo + 404 saat akses OLT nyata. 95 test hijau total.

### Landing page: hero full-desktop + animasi AOS, hapus config Nginx README, fix jsconfig

Changed:

- `resources/js/Pages/Welcome.vue` — hero jadi full-height desktop (`lg:min-h-[calc(100vh-57px)]` + `flex items-center`, konten ter-center vertikal; mobile tetap mengalir), ambient glow `animate-pulse`. Integrasi AOS (animate-on-scroll): init di `onMounted` (durasi 650ms, `ease-out-cubic`, `once`, `disable` saat `prefers-reduced-motion`) + atribut `data-aos` bertahap di hero, hardware strip, kartu fitur/modul (stagger per kolom), benefit pills & tech stack (`zoom-in` stagger), dan CTA akhir (`zoom-in-up`).
- `README.md` — hapus seluruh "Langkah 6 — Konfigurasi Nginx" (contoh server block + perintah aktivasi) karena tidak dijadikan acuan; penomoran langkah 7→6, 8→7, 9→8 disesuaikan.
- `jsconfig.json` — hapus `baseUrl` (deprecated di TS, akan dihapus TS 7.0) dan ubah `@/*` jadi relatif `["./resources/js/*"]`; warning editor hilang, alias build dari `laravel-vite-plugin` tak terpengaruh.
- `package.json` / `package-lock.json` — tambah dependency `aos`.

Notes:

- `npm run build` bersih (aos ter-bundle). Animasi belum diuji visual di browser dari sesi ini — perlu cek manual: hero penuh 1 layar desktop, reveal scroll halus, dan elemen tetap tampil saat reduce-motion aktif.
- Alias build `@` berasal dari `laravel-vite-plugin` (`"@": "/resources/js"`), `jsconfig.json` murni untuk intellisense editor.

### Background aurora + chrome glassmorphism, hapus grid statis & gambar

Created:

- `resources/js/Components/Shell/AuroraBackground.vue` — backdrop aurora: 4 gumpalan cahaya (cyan/sky/teal/blue) blur 90px, `mix-blend: screen`, mengambang via animasi `transform` (GPU), + vignette halus. `position: fixed` di belakang konten (`z-index: -1`), hormati `prefers-reduced-motion`.

Changed:

- `resources/css/app.css` — `.kv-grid-bg` disederhanakan jadi base gelap saja (hapus radial-gradient glow/efek light + grid garis statis); peran grid digantikan AuroraBackground.
- `resources/js/Layouts/AuthenticatedLayout.vue` — sisipkan `<AuroraBackground/>` di `<main>`; semua chrome jadi glass (opacity turun, tetap backdrop-blur): sidebar `/95→/35`, strip logo `/45→/20`, header desktop `/80→/35`, header slot `/70→/30`, top bar mobile `/90→/40`, footer `/80→/40`.
- `resources/js/Layouts/GuestLayout.vue` — sisipkan `<AuroraBackground/>` di container login.
- `resources/js/Pages/Welcome.vue` — sisipkan `<AuroraBackground/>` di hero; ganti gambar dashboard `dashboard.png → dashboard1.png`.
- `resources/js/Components/Dashboard/HeroBanner.vue` — hapus `<img>` hero + CSS-nya; background solid → kaca `rgba(15,23,42,0.32)` + `backdrop-blur(14px)` agar aurora tembus.
- `resources/js/Components/Shell/SidebarConstellation.vue` — hapus `<img>` starfield + CSS-nya; base solid `#020617` → transparan, tint shade diringankan agar aurora terlihat di sidebar.
- `public/img/*` — hapus gambar lama tak terpakai (c300/c320.jpg, dashboard/hero/landingpage/sidebar/template.png), tambah `dashboard1.png`.

Notes:

- Iterasi gaya: grid perspektif synthwave → grid ombak SVG `feTurbulence` (ditolak: berat & noisy) → final **aurora** (CSS transform, ringan & mulus), dipilih dari riset background dashboard dark-theme 2026.
- Stacking: AuroraBackground `fixed z-index:-1` di dalam `<main>` (isolation) tampil di belakang konten; chrome glass + backdrop-blur memburamkan aurora di belakangnya.
- `npm run build` bersih. Belum diuji visual di browser dari sesi ini — perlu cek manual: aurora bergerak halus, teks chrome tetap terbaca, dan performa lancar (tanpa lag).

## 2026-05-29

### Sidebar collapse persist + card Logs di Registration History + tweak input

Changed:

- `resources/js/Layouts/AuthenticatedLayout.vue` — state `sidebarCollapsed` kini dipersist ke `localStorage` (key `kv-sidebar-collapsed`): init dibaca sinkron saat setup (di-guard `typeof window` agar aman SSR & tanpa flash), lalu `watch` menyimpan tiap perubahan. Sebelumnya layout dipakai inline (bukan persistent layout Inertia) sehingga remount tiap pindah halaman mereset collapse ke `false`.
- `resources/js/Pages/SmartOlt/Registrations.vue` — pisah daftar registrasi jadi `pendingRegistrations` (status `generated`) & `loggedRegistrations` (status `executed`/`failed`). Card "Provisioning Scripts" sekarang `v-if` hanya muncul saat ada script pending dan hilang otomatis setelah dikerjakan; tambah card "Logs" baru (ikon `History`) untuk script yang sudah dikerjakan dengan status apa pun. Di Logs, preview CLI script + output eksekusi disembunyikan default di balik tombol toggle "Lihat script / Sembunyikan" (state per-entri via `expandedLogs`).
- `resources/js/Pages/SmartOlt/RegisterOnu.vue` — input Remote ONT ID `max` dinaikkan dari 16 → 4095 agar konsisten dengan form Configure.
- `app/Http/Controllers/SmartOltController.php` — validasi `remote_ont_id` saat register dinaikkan dari `between:1,16` → `between:1,4095` (sebelumnya tidak konsisten dengan reconfigure yang sudah `1,4095`).
- `resources/js/Pages/SmartOlt/ConfigureOnu.vue` — panel RAW RUNNING-CONFIG tampil penuh ke bawah: hapus `max-h-[420px] overflow-auto`, ganti dengan `whitespace-pre-wrap break-words` + `overflow-x-auto` agar tidak ada scroll vertikal.

Notes:

- `npm run build` bersih untuk semua perubahan frontend.
- Investigasi "halaman detail OLT C300 tidak bisa dibuka": ternyata bukan bug kode, melainkan sesi user kebawa state demo. `SnmpOlt` punya global scope `DemoScope` (user demo hanya lihat `is_demo=true`, non-demo hanya `is_demo=false`); OLT C300 (id=2) adalah data nyata sehingga route-model-binding gagal saat sesi demo. Teratasi setelah user login ulang — tidak ada perubahan kode.

### Halaman Pengaturan + Notifikasi Telegram untuk alarm

Created:

- `database/migrations/2026_05_29_120000_create_telegram_settings_table.php` — tabel `telegram_settings` (single-row): `enabled`, `bot_token` (text, dienkripsi via cast), `chat_id` (text, bisa banyak ID dipisah koma/spasi), `min_severity` (default `warning`), `notify_on_raise`/`notify_on_clear`, `last_sent_at`, `last_error`. Tipe kolom SQLite-compatible untuk test.
- `app/Models/TelegramSetting.php` — model singleton: `instance()` (firstOrNew), `chatIds()` (parse multi-ID), `isConfigured()`/`isReady()`, `minSeverityRank()` + const `SEVERITY_RANK`. `bot_token` cast `encrypted` & `$hidden`.
- `app/Services/Telegram/TelegramNotifier.php` — kirim pesan ke Telegram Bot API (`/sendMessage`, parse_mode HTML, timeout 10s, loop semua chat ID). `notify(olt, raised, cleared)` dipanggil dari evaluator (filter severity ≥ min, gate `notify_on_raise`/`notify_on_clear`, skip OLT demo, dibungkus try/catch agar tak memecah rekonsiliasi alarm, rekam `last_sent_at`/`last_error`); `sendTest()` untuk tombol uji. Pesan disusun ringkas berisi nama OLT, daftar alarm (emoji severity + tipe + pesan + nama pelanggan bila ada) + timestamp.
- `app/Http/Controllers/SettingsController.php` — `edit` (Inertia, token dikirim sebagai `bot_token_set` boolean, bukan nilai asli), `updateTelegram` (validasi; token kosong = pertahankan token lama, pola `withoutEmptySecrets`), `testTelegram` (kirim tes via notifier → flash success/error).
- `resources/js/Pages/Settings/Index.vue` — kartu glass "Notifikasi Telegram": toggle aktif, input bot token (password, placeholder menandai token tersimpan), chat ID (textarea multi), select severity minimum, checkbox kirim-saat-muncul / kirim-saat-pulih, tombol Simpan + Kirim Tes (disabled sampai token & chat ID tersimpan), panel status (terakhir terkirim / galat terakhir), teks bantuan @BotFather & @userinfobot.
- `tests/Feature/TelegramSettingsTest.php` — 7 test: akses admin vs operator (403), simpan setting + parse chatIds, token kosong dipertahankan, endpoint tes mengirim (Http::fake), alarm baru memicu kirim Telegram saat aktif, tidak mengirim saat nonaktif.

Changed:

- `app/Services/AlarmEvaluator.php` — `reconcile()` kini mengumpulkan model `AlarmEvent` yang baru raised & yang cleared lalu memanggil `TelegramNotifier::notify()`. Dependensi notifier opsional di konstruktor (`?TelegramNotifier`, di-resolve lazy via `app()`) agar `new AlarmEvaluator` di test lama tetap jalan.
- `routes/web.php` — 3 route di grup `role:admin`: `settings.edit`, `settings.telegram.update`, `settings.telegram.test`.
- `resources/js/Layouts/AuthenticatedLayout.vue` — nav "Pengaturan" (ikon Settings) hanya untuk admin (gate `can.manage_users`).

Notes:

- Hook notifikasi di titik raise/clear alarm (`AlarmEvaluator`), bukan di controller, sehingga semua sumber polling (scheduler `olts:poll` → `PollOltJob`, dan evaluasi manual) ikut memicu notifikasi.
- Demo aman: OLT demo statis (scheduler hanya menyentuh OLT nyata) + guard `is_demo` di notifier. Setting Telegram admin-only, demo sudah diblokir `BlockDemoWrites`.
- 102 test hijau (7 baru), `pint` & `npm run build` bersih.
- Saat menjalankan test ditemukan `bootstrap/cache/config.php` & `routes-v7.php` ter-cache (dari `config:cache`/`route:cache` produksi), membuat phpunit memakai koneksi pgsql + route lama. Test dijalankan dengan menyisihkan kedua file cache sementara (sqlite in-memory, terisolasi; data pgsql terverifikasi utuh), lalu cache dikembalikan persis. Data produksi tidak tersentuh (transaksi RefreshDatabase rollback).
- **Belum di-deploy ke instance produksi.** Agar live perlu: `php artisan migrate --force` (buat tabel `telegram_settings`) + rebuild cache `php artisan config:cache && php artisan route:cache` agar 3 route `settings.*` dikenali. Build frontend sudah ter-update.

Deploy + perbaikan akurasi alarm (lanjutan):

- Dideploy ke produksi: `migrate --force` (tabel `telegram_settings` dibuat), `config:cache` + `route:cache` (route `settings.*` dikenali), `queue:restart` (worker memuat kode notifikasi). Catatan penting: worker `queue:work` adalah daemon yang memuat kode sekali saat start — wajib `queue:restart` setiap deploy kode yang jalan di job/service, kalau tidak notifikasi/perbaikan tak berefek meski file sudah berubah.
- Bug akurasi ditemukan saat user uji coba: notifikasi "tidak sesuai" karena `AlarmEvaluator::onuStateAlarms()` menaikkan alarm `dying_gasp`/`los` berdasarkan `last_down_cause` — padahal itu riwayat penyebab turun terakhir yang tetap menempel walau ONU sudah online lagi. Akibatnya 1774 ONU yang sebenarnya Working/online ikut ber-alarm dying_gasp (total 1848 aktif). Fix: gerbang `if ($onu['online'] ?? false) return [];` di awal — ONU yang up tidak ber-alarm apa pun, `last_down_cause` hanya dipakai untuk mengklasifikasikan ONU yang memang offline.
- Bug kedua: alarm RX (`high_rx_attenuation`) flapping di sekitar ambang -28 dBm (mis. -28.2 → -27.9 tiap poll) → raise/clear bergantian, mengirim "CLEARED" walau RX masih marginal. Fix: hysteresis — raise saat rx ≤ -28 / ≥ -8, tapi baru clear setelah rx pulih melewati -27 / -9 (deadband 1 dB). `onuRxAlarm()` kini menerima koleksi alarm aktif untuk menentukan apakah tetap dipertahankan; `evaluate()` memuat alarm aktif sekali dan meneruskannya ke `onuRxAlarm()` + `reconcile()` (reconcile tak lagi query sendiri).
- `tests/Feature/AlarmEngineTest.php` — 2 test regresi: ONU online dengan `last_down_cause=DyingGasp` tidak ber-alarm; RX hysteresis (raise di -28.4, tetap aktif di deadband -27.5, baru clear di -26.0).
- Pembersihan data: setelah fix + `queue:restart`, dijalankan `evaluate()` pada 2 OLT nyata (Telegram dimatikan sementara agar tak spam ~1781 notifikasi clear) → alarm aktif **1984 → 203** (C320: clear 91 sisa 24; C300: clear 1690 sisa 173). dying_gasp 1848 → 72. Sisa 203 semuanya alarm asli (offline/los/port_down/high_rx). Telegram diaktifkan kembali.
- 27 test alarm/telegram/polling hijau, `pint` bersih.

### Seragamkan tampilan waktu ke WIB

Konteks: penyimpanan sudah UTC (`app.timezone=UTC`), tapi tampilan tidak konsisten — frontend ikut zona browser, pesan Telegram pakai UTC (mis. tampil 07:49 padahal 14:49 WIB), chart dashboard sudah `display_timezone=Asia/Jakarta`. User minta semua jam tampil WIB.

Created:

- `resources/js/lib/datetime.js` — helper terpusat tampilan waktu, semua pakai `timeZone: 'Asia/Jakarta'` + label `WIB`. Fungsi: `formatDateTime` ("29 Mei 2026, 16.42 WIB"), `formatDate` (tanggal saja), `formatClock` (jam header kompak), `formatTimeOfDay` (label sumbu chart live, tanpa suffix). Null-safe (`'—'`).

Changed (frontend — semua formatter `Intl.DateTimeFormat` lokal diarahkan ke helper):

- `resources/js/Pages/SmartOlt/{Alarms,Detail,PortOnus,Unconfigured,UnconfiguredGlobal,Registrations,Index,PortManager}.vue`, `Pages/Settings/Index.vue`, `Pages/Users/Index.vue`, `Components/Dashboard/{RecentAlarmsTable,OnuStatusDonut}.vue`, `Components/Shell/SystemInfoPanel.vue`. PortManager juga: label sumbu chart traffic live pakai `formatTimeOfDay`. Settings/OnuStatusDonut tetap mengembalikan `null` (bukan '—') agar `v-if` tetap benar.

Changed (backend):

- `app/Services/Telegram/TelegramNotifier.php` — timestamp pesan (alarm & tes) kini `Carbon::now()->timezone(config('app.display_timezone','Asia/Jakarta'))->translatedFormat('d M Y H:i').' WIB'` (sebelumnya UTC tanpa label — ini akar pesan tampil 07:49).
- `app/Services/Report/ReportService.php` — 3 timestamp report (last_polled_at, last_seen_at, created_at) dikonversi ke display_timezone sebelum `format('d/m/Y H:i')`.
- `app/Http/Controllers/ReportController.php` — `generatedAt` PDF jadi WIB + label.
- `DashboardStatsService` sudah konversi ke `display_timezone` (chart) — tak diubah.

Notes:

- Sumber kebenaran zona tampilan: backend `config('app.display_timezone')` (default `Asia/Jakarta`, bisa di-override env `APP_DISPLAY_TIMEZONE`); frontend konstanta `DISPLAY_TZ='Asia/Jakarta'` di helper. Storage tetap UTC.
- Awalnya user sempat minta "GMT", lalu mengoreksi ke WIB — dikonfirmasi WIB sebelum eksekusi.
- Deploy: `npm run build` (live), `queue:restart` (TelegramNotifier jalan di worker). Tak ada migrasi; tak perlu rebuild config/route cache (tak ubah .env/route). Report jalan di php-fpm (auto-reload opcache).
- 104 test hijau, `pint` & `npm run build` bersih.

### Alarm berbasis transisi (hanya online→fault), clean slate

Permintaan user: alarm hanya saat **pergantian status** dari sehat ke fault (ONU online→LOS/dying-gasp/offline, port up→down, RX sehat→menyentuh -28), sekali saja. Perangkat yang **sudah** dalam keadaan fault sejak awal (mis. ONU offline lama di OLT) **tidak** boleh masuk alarm. RX cleared baru pada -26 (kalau masih -27 jangan).

Changed:

- `app/Services/AlarmEvaluator.php`:
  - `evaluate(SnmpOlt $olt, array $previous = [])` — kini menerima snapshot poll sebelumnya untuk mendeteksi transisi.
  - `indexPrevious()` — bangun lookup status sebelumnya: online per-ONU, rx per-ONU, oper_status per-port.
  - Aturan raise: hanya jika kondisi fault adalah **transisi dari sehat** (`prevOnline===true` untuk ONU, `prevStatus==='up'` untuk port, `prev ok` untuk OLT, RX `prevHealthy` untuk high_rx) ATAU alarm tipe itu sudah aktif (persist). Fault yang sudah ada sejak awal (tak pernah terlihat sehat) → di-skip.
  - `onuHasStateAlarm()` — agar episode fault yang sudah beralarm tetap dipertahankan walau subtipe berganti (offline↔los↔dying_gasp).
  - RX: ambang clear dinaikkan ke **-26** (`RX_CLEAR_LOW_DBM`) / -10 (`RX_CLEAR_HIGH_DBM`); raise hanya saat melintas dari sehat (`prevHealthy`), persist sampai pulih ≥ -26.
  - `reconcile()` tetap: clear alarm aktif yang tak lagi terdeteksi (fault pulih), raise yang baru, keep yang persist.
- `app/Jobs/PollOltJob.php` — tangkap `$previousSnapshot = $olt->last_test_result` sebelum overwrite, teruskan ke `evaluate($olt, $previousSnapshot)`.

Tests:

- `tests/Feature/AlarmEngineTest.php` — diubah ke model transisi + test baru: `already_offline_onu_is_not_alarmed`, `rx_already_out_of_range_is_not_alarmed`, `port_down_raises_only_on_transition`; RX hysteresis clear di -26.
- `tests/Feature/TelegramSettingsTest.php` — evaluate dipanggil dengan snapshot sebelumnya (online/port-up) agar transisi memicu raise.

Deploy & clean slate:

- `queue:restart` (worker memuat evaluator baru), lalu **hapus semua alarm nyata** (`AlarmEvent::withoutGlobalScopes()->where('is_demo',false)->delete()` → 4133 baris terhapus, demo 8 utuh). Setelah ini hanya transisi baru yang memunculkan alarm. Tak ada migrasi/build frontend.
- 107 test hijau, `pint` bersih.

### Pesan CLEARED menampilkan status pulih (online + RX terbaru)

Masalah: notifikasi CLEARED menyalin pesan fault lama (mis. "RX 98.064 dBm di luar rentang sehat", "loss of signal (LOS)") — bukan kondisi saat pulih. User minta CLEARED menampilkan status online & redaman terbaru.

Changed:

- `app/Services/AlarmEvaluator.php`:
  - `indexCurrent()` — index snapshot saat ini (ONU per key + port) untuk dipakai saat clear.
  - `buildRecovery()` — bangun pesan pulih per scope/type dari snapshot terkini: ONU state→"ONU {iface} kembali online, RX {rx} dBm."; high_rx→"ONU {iface} RX {rx} dBm kembali normal."; port→"GPON port {name} kembali up."; OLT→"OLT kembali terhubung." (null bila ONU tak ada di snapshot → fallback ke pesan asli).
  - `reconcile()` — saat clear, simpan `meta.recovery` (message + rx_power_dbm + online); `message` asli (fault) tetap utuh untuk histori. Menerima param `$current`.
- `app/Services/Telegram/TelegramNotifier.php` — `formatAlarm()` untuk alarm cleared memakai `meta.recovery.message` (fallback ke message asli), jadi Telegram CLEARED menampilkan kondisi pulih.

Tests:

- `tests/Feature/AlarmEngineTest.php` — `onuSnapshot` online kini sertakan RX; test clear memverifikasi `meta.recovery.message` berisi "kembali online" + nilai RX terbaru.

Deploy: `queue:restart`. Tanpa migrasi/build. 30 test alarm/telegram/polling hijau, `pint` bersih.

Catatan: muncul nilai RX tidak wajar (+98.064 / -0.002 dBm) yang memicu high_rx (sisi terlalu kuat, rx ≥ -8). Itu kemungkinan pembacaan invalid; sisi "RX terlalu kuat" tak diminta user (hanya -28). Bisa jadi follow-up: batasi rentang RX valid atau matikan deteksi sisi tinggi.

### Chunking notifikasi Telegram untuk batch besar

Masalah: semua alarm dalam 1 siklus poll digabung jadi 1 pesan. Telegram membatasi 4096 karakter/pesan, jadi 20+ alarm bisa gagal kirim total.

Changed:

- `app/Services/Telegram/TelegramNotifier.php` — `notify()` memecah daftar section (raised + cleared) jadi beberapa pesan via `array_chunk`, maksimal `MAX_ITEMS_PER_MESSAGE = 10` alarm per pesan. Bila lebih dari satu pesan, header diberi penanda bagian "(i/n)". Tiap chunk dikirim terpisah ke semua chat ID.
- `tests/Feature/TelegramSettingsTest.php` — test `large_alarm_batch_is_split_into_multiple_messages`: 12 ONU online→offline → 12 alarm → 2 pesan (10 + 2), `Http::assertSentCount(2)`.

Deploy: `queue:restart`. 22 test telegram/alarm hijau, `pint` bersih.

### Bump versi aplikasi ke 2.0.0

Changed:

- `app/Http/Middleware/HandleInertiaRequests.php` — default `config('app.version')` `1.0.0` → `2.0.0` (ditampilkan di panel System Info).

### Halaman ONU Monitoring (lintas OLT & port)

Created:

- `resources/js/Pages/SmartOlt/OnuMonitor.vue` — halaman baru di sidebar. Filter dalam card terpisah: search, pilih OLT, pilih port, status (Online/LOS/Dying Gasp/Offline berbasis `phase_state`), admin (Active/Disabled). Default kosong → harus pilih OLT dulu (tidak render semua 2000+ baris di awal). Tabel mirip PortOnus + kolom OLT, tombol "buka di port" (focus ke ONU), tombol "Scan ONU OLT ini".

Changed:

- `app/Http/Controllers/SmartOltController.php` — `onuMonitor()` agregasi semua ONU ter-cache (`port_onus.*.onus`) dari semua OLT jadi satu list flat; `refreshOnuMonitor()` scan penuh 1 OLT dalam sekali walk (gponPorts + registeredOnus + RX) lalu tulis balik ke cache per-port agar konsisten dengan halaman PortOnus.
- `routes/web.php` — `monitoring.onu` (GET `/onu-monitoring`) + `monitoring.onu.refresh` (POST `/onu-monitoring/{olt}/refresh`).
- `resources/js/Layouts/AuthenticatedLayout.vue` — link sidebar "ONU Monitoring" (ikon Radar), match `monitoring.*`.

Notes:

- Nama route sengaja di luar prefix `smartolt.*` agar tidak ikut meng-highlight item SmartOLT di sidebar.

### Fix: global search bisa cari by Serial Number (SN)

Changed:

- `app/Http/Controllers/DashboardSearchController.php` — pencarian ONU sebelumnya membaca key `sn`/`serial` yang tak pernah ada; `OltSnmpClient` menyimpan serial sebagai `serial_number`. Diperbaiki baca `serial_number` (fallback `sn`/`serial`), tambah cocokkan via `interface`, label hasil = serial, sublabel = `OLT · slot/port · nama`.

Notes:

- Diuji terhadap cache OLT-C320-PATI: query `RTEGCA96` → 10 hasil. Tanpa perubahan frontend (GlobalSearch render hasil generik).

### Fitur Telnet di browser (xterm.js + WebSocket proxy)

Created:

- `config/telnet.php` — host/port daemon, `ws_url` publik, TTL ticket, connect timeout.
- `app/Support/Telnet/TelnetTicket.php` — ticket terenkripsi (Crypt/APP_KEY) berisi user+olt+exp, TTL pendek, **URL-safe (base64url)** agar lolos query string nginx/browser tanpa mangle.
- `app/Support/Telnet/TelnetIacFilter.php` — negosiasi/strip IAC telnet (accept ECHO/SGA, tolak lainnya), stateful tahan split antar-chunk.
- `app/Services/Telnet/TelnetProxyServer.php` — jembatan WS↔telnet (react/socket + ratchet/rfc6455 + guzzle/psr7 yang sudah dibawa Reverb, tanpa dependency baru): handshake → verifikasi ticket → dial telnet OLT → pipe 2 arah + auto-login pakai kredensial OLT.
- `app/Console/Commands/TelnetProxyCommand.php` — daemon `php artisan telnet:proxy`.
- `app/Http/Controllers/TelnetSessionController.php` — terbitkan ticket + `ws_url` (gated `canManageOlt`, tolak demo); dukung `ws_url` relatif → scheme/host otomatis dari request.
- `resources/js/Components/Shell/TelnetWindow.vue` — jendela terminal mengambang xterm: drag, minimize, maximize/restore, resize, status. Lazy-loaded.

Changed:

- `routes/web.php` — `smartolt.telnet.token` (POST `/smartolt/{olt}/telnet/token`).
- `resources/js/Pages/SmartOlt/Index.vue` — tombol aksi Telnet per OLT (admin/operator + transport telnet); host `TelnetWindow` (lazy via `defineAsyncComponent`).
- `package.json` / `package-lock.json` — tambah `@xterm/xterm` + `@xterm/addon-fit`.
- `.env.example` — entri `TELNET_PROXY_*`.

Notes:

- Setup server lokal (di luar repo): daemon di `127.0.0.1:6002` via supervisor `kusumavision-telnet-proxy`; nginx route `location /telnet-ws` → proxy ke daemon (pakai port 80 ber-ACL, firewall tak diubah); `.env` set `TELNET_PROXY_WS_URL=/telnet-ws`.
- Diverifikasi end-to-end lewat nginx:80 dengan fake telnet lokal: handshake 101, frame encoding benar, IAC ter-strip, auto-login berhasil. IAC filter & ticket round-trip lolos unit test.
- **Gotcha penting:** `.env` tidak terbaca www-data (root:root 640) → app hanya jalan dengan config ter-cache. `config:clear` saat setup sempat menjatuhkan situs (500 sqlite) + daemon 401; dipulihkan dengan `config:cache`. Selalu `config:cache` + restart daemon setelah ubah `.env`/config telnet; jangan tinggalkan config dalam keadaan ter-clear.

### Landing page tampilkan fitur baru + dokumentasi disesuaikan

Changed:

- `resources/js/Pages/Welcome.vue` — bagian Fitur tambah "Telnet via Browser" & "Global Search", copy "ONU Monitoring" diperbarui jadi lintas-OLT; Modul tambah "ONU Monitoring" (Radar) & "Telnet Console" (Terminal); hero pill tambah "Web Telnet".
- `CLAUDE.md` — koreksi klaim usang "No Go polling engine" (poller Go `bin/kv-snmp-poller`/`cmd/kv-snmp-poller` lewat `GoSnmpPoller`+`PollOltJob`, prod `SNMP_POLLER_DRIVER=go`, fallback PHP); Architecture tambah ONU Monitoring page, browser telnet (proxy+daemon), global search; Commands tambah `telnet:proxy` & build Go; Conventions tambah gotcha config-cache prod.
- `README.md` — Fitur tambah ONU Monitoring/Telnet browser/Global search; tabel stack tambah xterm.js & telnet browser; langkah deploy baru "Langkah 8 — Telnet Proxy Browser" (supervisor + nginx `/telnet-ws` + `.env`), akun jadi Langkah 9; ringkasan hardening tambah telnet-proxy & catatan config-cache.

Notes:

- Diverifikasi Golang BENAR dipakai sebagai engine polling terjadwal (bukan vision PRD) — dokumen lama yang menyatakan sebaliknya dikoreksi.
- Permission `.env` server diselaraskan ke `640 root:www-data` (sesuai README) agar www-data bisa baca; dibuktikan `config:clear` tak lagi menjatuhkan situs (tetap 200). Perubahan permission ini di sistem, di luar git.

### Go-live publik: nms.kusumavision.net via Cloudflare (TLS Full strict) + hardening

Notes (perubahan ini di tingkat sistem/server, di luar git — didokumentasikan di sini):

- **IP publik** `103.189.249.86` di-bind ke `eth0` sebagai alamat sekunder. Server adalah LXC di Proxmox (jaringan di-manage PVE via `/etc/systemd/network/eth0.network`). Agar tak ditimpa PVE, IP ditaruh di drop-in `/etc/systemd/network/eth0.network.d/10-public-ip.conf` (`Address = 103.189.249.86/32`). Catatan: kalau container di-recreate dari panel Proxmox, IP perlu didaftarkan ulang di config container pada host.
- **nginx** (`/etc/nginx/sites-available/kusumavision-nms` diganti, backup `.bak.*`): server `:80` redirect 301 ke HTTPS; server `:443 ssl http2` melayani app + WebSocket `/telnet-ws`. ACL `allow/deny` LAN lama **dihapus** karena setelah real-IP Cloudflare dipulihkan ACL itu akan memblokir semua pengunjung publik — penguncian origin dipindah ke firewall. Snippet `/etc/nginx/snippets/cloudflare-realip.conf` (`set_real_ip_from` semua rentang CF v4/v6 + `real_ip_header CF-Connecting-IP`) untuk memulihkan IP visitor asli di log/app/fail2ban. `fastcgi_param HTTPS on` + header HSTS ditambahkan.
- **TLS**: Cloudflare Origin Certificate (SAN `nms.kusumavision.net`, valid s/d 2041) di `/etc/nginx/ssl/origin.{pem,key}` (key `600`). SSL mode Cloudflare **Full (strict)**. Sebelumnya 526 saat masih self-signed; setelah Origin Cert dipasang → HTTP/2 200.
- **Firewall (UFW)**: 80/443 dibuka & dikunci hanya ke rentang IP resmi Cloudflare (v4+v6) + LAN privat + subnet admin `103.189.248.0/24`,`103.189.249.0/24`. SSH (22) tetap hanya LAN + subnet admin. SSH sudah hardened sebelumnya (`PasswordAuthentication no`, `PermitRootLogin without-password`, pubkey only).
- **fail2ban** dipasang (`jail.local`): jail `sshd` (efektif penuh, `/var/log/auth.log`, ban 2h), `nginx-http-auth`, `nginx-botsearch`; `ignoreip` mencakup LAN + subnet admin. Catatan: untuk trafik HTTP yang lewat Cloudflare, ban iptables atas IP visitor asli hanya efektif untuk akses langsung-ke-origin; untuk blokir abuse ber-proxy perlu action Cloudflare API / WAF.
- **App**: `APP_URL=https://nms.kusumavision.net` (backup `.env.bak.*`), `php artisan config:cache`, `queue:restart`, restart daemon telnet-proxy.
- **Verifikasi**: lokal `https://127.0.0.1` (Host header) → 200; via IP publik → 200; live `https://nms.kusumavision.net` → HTTP/2 **200** (Inertia + assets ke-render), `http://` → 301 ke https. fail2ban 3 jail aktif tanpa error.

### Ganti tagline "Unified FTTH Network Management Platform" → "ZTE OLT Management & Provisioning Platform"

Changed:

- `resources/js/Components/Dashboard/HeroBanner.vue`, `resources/js/Layouts/GuestLayout.vue`, `resources/js/Pages/Welcome.vue` — ganti tagline/subjudul/title tab jadi "ZTE OLT Management & Provisioning Platform" (lebih sesuai scope ZTE GPON). Frontend di-rebuild (`npm run build`).

### Hero/footer landing teks lengkap + matikan autofill PPPoE

Changed:

- `resources/js/Pages/Welcome.vue` — H1 hero (yang sebelumnya terpecah jadi span: "Unified" / "FTTH Network" / "Management Platform") & teks footer diganti ke "ZTE OLT Management & Provisioning Platform"; aksen gradient kini di "OLT Management".
- `resources/js/Pages/SmartOlt/ConfigureOnu.vue`, `resources/js/Pages/SmartOlt/RegisterOnu.vue` — input WAN PPPoE: tambah `autocomplete="off"` (username) & `autocomplete="new-password"` (password) + `data-1p-ignore`/`data-lpignore` agar browser/password-manager tidak auto-fill kredensial Google ke field PPPoE.
- `public/img/dashboard1.png` (diperbarui) + `public/img/{detail,login,oltinventory,unconfigured}.png` (baru) — aset screenshot landing disediakan user.

Notes:

- Tulisan di dalam mockup dashboard hero adalah gambar (`/img/dashboard1.png`), bukan teks HTML — diganti dengan mengganti file PNG, bukan kode.
- `TextInput.vue` meneruskan `$attrs` ke `<input>`, jadi atribut `autocomplete`/`data-*` cukup ditaruh di pemakaian komponen. Frontend di-rebuild (`npm run build`).

### Section galeri "Tampilan Aplikasi" di landing page

Changed:

- `resources/js/Pages/Welcome.vue` — tambah section `#tampilan` (galeri screenshot interaktif): daftar tab kiri (Dashboard, OLT Inventory, ONU Belum Terdaftar, Detail ONU, Login) + preview dalam frame browser-chrome dengan crossfade `<Transition name="kv-fade">`; pakai `computed currentShot`. Tambah link nav "Tampilan" (header + mobile). Tambah `<style scoped>` untuk transisi (hormati `prefers-reduced-motion`).

Notes:

- Pakai 5 screenshot user di `public/img/` (dashboard1, oltinventory, unconfigured, detail, login). Frame `aspect-[16/10]` + `object-cover object-top` agar konsisten & tanpa layout shift antar-tab; `loading="lazy"` + hanya gambar aktif yang dirender (sisanya dimuat saat tab diklik).
- A11y: `role="tablist"`/`tab` + `aria-selected`, `alt` deskriptif per gambar. Semua ikon sudah ada di import Lucide.
- Catatan optimasi: screenshot masih PNG (~0.9–1.4 MB/file); bisa dikonversi WebP untuk hemat bandwidth bila perlu. Frontend di-rebuild.

### Halaman Pengaturan: card full-width

Changed:

- `resources/js/Pages/Settings/Index.vue` — container konten dari `mx-auto w-full max-w-3xl` jadi `w-full` agar card membentang penuh kiri-kanan, konsisten dengan halaman lain (SmartOlt/Users/Reports). Frontend di-rebuild.

### Halaman Pengaturan: rapikan isi jadi grid 2 kolom

Changed:

- `resources/js/Pages/Settings/Index.vue` — body form Telegram dari `space-y-6` single-column jadi `grid lg:grid-cols-2` agar field tidak melebar setelah card full-width: toggle aktif (full), Bot Token | Chat ID, Severity minimum | Pemicu notifikasi (2 checkbox dibungkus panel berlabel), status & tombol aksi span penuh. Frontend di-rebuild.

### Fitur & halaman Audit Logs

Created:

- `database/migrations/2026_05_29_130000_create_audit_logs_table.php` — tabel `audit_logs` (immutable, hanya `created_at`): `user_id` (nullOnDelete) + `user_name` snapshot, `event`, `auditable_type`/`auditable_id` (morph), `description`, `properties` (json), `ip_address`, `user_agent`. Index pada user_id, event, created_at, (auditable_type, auditable_id). Sqlite-compatible.
- `app/Models/AuditLog.php` — model audit; konstanta event (created/updated/deleted/login/logout/login_failed/telnet_opened), `UPDATED_AT = null`, cast `properties` array, relasi `user()`.
- `app/Support/AuditLogger.php` — titik tunggal penulisan audit; `log()` menangkap aktor (auth), IP & user-agent dari request, `model()` membangun deskripsi Indonesia ("Menambahkan/Memperbarui/Menghapus <label> <judul>").
- `app/Models/Concerns/Auditable.php` — trait yang hook event created/updated/deleted model → audit otomatis. Atribut `$hidden` + password + `$auditExclude` per-model tidak pernah ikut tercatat; update kosong (setelah exclude) di-skip.
- `app/Http/Controllers/AuditLogController.php` — halaman index (admin only) dengan filter event/user/pencarian/rentang tanggal + paginasi 25.
- `resources/js/Pages/AuditLogs/Index.vue` — halaman glass-style (selaras Alarms): kartu filter, tabel desktop + kartu mobile, baris bisa di-expand untuk lihat diff lama→baru / atribut.
- `tests/Feature/AuditLogTest.php` — 4 test: akses admin vs operator (403), audit perubahan model tanpa secret, filter by event.

Changed:

- `app/Models/{SnmpOlt,User,SmartOltProfile,SmartOltOnuRegistration,TelegramSetting}.php` — pasang trait `Auditable` + `auditLabel()`/`auditTitle()` + `$auditExclude` (field volatil/sensitif: hasil polling OLT, last_notifications_read_at, cli_script/output, password PPPoE/ACS, bot_token).
- `app/Providers/AppServiceProvider.php` — listener event auth: `Login`/`Logout`/`Failed` → audit login/logout/login_failed (email percobaan dicatat di properties).
- `app/Http/Controllers/TelnetSessionController.php` — catat event `telnet_opened` saat tiket telnet diterbitkan.
- `routes/web.php` — route `audit-logs.index` di dalam grup `role:admin`.
- `resources/js/Layouts/AuthenticatedLayout.vue` — link sidebar "Audit Logs" (ikon ScrollText), hanya untuk admin.

Notes:

- Verifikasi: secret terenkripsi (mis. `snmp_read_community`) terbukti TIDAK ikut tercatat karena masuk `$hidden` → otomatis dikecualikan oleh trait. Diuji via tinker (rollback) + test feature.
- Semua 112 test lulus (`php artisan config:clear` dulu — config cache bikin test nyasar & error 419, sesuai catatan deploy). Frontend di-rebuild (`npm run build`).
- Audit log hanya bisa dilihat admin; baris bersifat append-only (tak ada UI edit/hapus).

### Fix penghitungan Status ONU "Warning" di Dashboard

Changed:

- `app/Services/Dashboard/DashboardStatsService.php` — penghitung warning sebelumnya membaca `$onu['rx_power']`/`$onu['rx']` yang tidak pernah ada di cache `port_onus`, sehingga warning **selalu 0**. Diperbaiki membaca `rx_power_dbm` (field RX power per-ONU yang sebenarnya tersimpan, lihat `PollOltJob`/`OltSnmpClient`), dengan fallback `rx_power`/`rx` untuk data lama. Warning kini hanya dihitung untuk ONU **online** dengan RX di luar zona aman `-25…-10 dBm` (guard `online` mencegah ONU offline dengan RX basi ikut terhitung).
- `resources/js/Components/Dashboard/OnuStatusDonut.vue` — slice donut dibuat mutually-exclusive: warning adalah subset ONU online, jadi slice **Online = online − warning** dan **Offline = offline asli** dari backend (sebelumnya offline keliru dikurangi warning lagi sehingga undercount).
- `tests/Feature/DashboardTest.php` — fixture diperluas (ONU sehat, RX rendah, RX terlalu kuat, offline-RX-basi) + assertion `cards.onu.warning` agar regresi tidak terulang.

Notes:

- Diverifikasi terhadap cache produksi nyata (2 OLT): total=2251, online=2143 (cocok dashboard); logika lama warning=0, logika baru warning=500 (472 RX ≤ -25 dBm + 28 RX ≥ -10 dBm). Distribusi online: 1599 zona aman, 388 di -25…-28, 84 kritis (< -28), 28 terlalu kuat.
- Threshold -25/-10 dBm konsisten dengan konvensi app (OnuDetail "Zona aman -25…-10", ReportService "Warning < -25").
- Test Dashboard lulus (49 assertions), Pint bersih. Perubahan donut perlu `npm run build` saat deploy.

### Update fitur landing page + perbaikan galeri screenshot tidak terpotong

Changed:

- `resources/js/Pages/Welcome.vue` — (1) Tambah 4 kartu fitur baru yang sudah dibangun tapi belum tampil di landing: **Reports & Analytics** (FileBarChart), **Notifikasi Telegram** (Send), **Audit Logs** (ScrollText), **Role-based Access** (ShieldCheck) — grid Fitur jadi 12 kartu (rapi 4×3 di `lg:grid-cols-3`). Import ikon `ScrollText` & `Send` ditambahkan. (2) Perbaiki galeri "Tampilan Aplikasi": frame sebelumnya dipaksa `aspect-[16/10]` + `object-cover object-top` sehingga screenshot ter-crop (rasio gambar beda-beda). Sekarang tiap screenshot diberi field `ratio` sesuai dimensi asli (dashboard 1920×1282, OLT/login/unconfigured 1920×911, detail 1920×1112), frame pakai `:style="{ aspectRatio: currentShot.ratio }"` + `object-contain` jadi gambar tampil utuh ke ukuran aslinya. Ditambah `transition-[aspect-ratio] duration-300` agar frame resize halus saat ganti tab; crossfade antar gambar tetap dipertahankan.

Notes:

- Dimensi asli gambar dicek via `getimagesize` di `public/img`; container dengan aspect-ratio == rasio asli + `object-contain` membuat gambar mengisi penuh tanpa crop maupun letterbox.
- `npm run build` sukses, assets di-rebuild. Build artifacts (`public/build`) di-gitignore, hanya source `Welcome.vue` yang ter-commit — perlu `npm run build` ulang saat deploy.

### README — sinkronkan daftar fitur dengan scope terbaru

Changed:

- `README.md` — bagian **Fitur** dirombak jadi 4 kelompok (Inventory & Monitoring, Provisioning & ONU, Polling/Alarm/Notifikasi, Administrasi & Pelaporan) karena daftar membengkak jadi 22 item; ditambahkan fitur yang belum tercatat: **Notifikasi Telegram**, **Bot Telegram (webhook perintah read-only)**, **RBAC** (admin/operator/demo), **Mode Demo** (`is_demo` + global scope), **Manajemen User**, **Report** (5 jenis, export CSV/PDF), **Audit Logs** (immutable), **Pengaturan** (branding + Telegram). Catatan Dashboard *Warning* dari RX power & hysteresis alarm RX ditambahkan ke deskripsi terkait.
- `README.md` — tabel **Stack Teknologi** tambah baris Telegram Bot API & export CSV/PDF (`barryvdh/laravel-dompdf`).
- `README.md` — section opsional baru **Notifikasi & Bot Telegram**: langkah setup notifikasi (BotFather/userinfobot) + registrasi webhook (`php artisan telegram:webhook set|info|delete`) dan syarat URL HTTPS publik + forwarding nginx `POST /telegram/webhook`.
- `README.md` — **Catatan & Batasan** tambah poin RBAC, Mode Demo (link `docs/DEMO_DEPLOYMENT.md`), dan syarat webhook Telegram (CSRF exempt).

Notes:

- Murni dokumentasi; tidak ada perubahan kode. Deskripsi fitur diverifikasi terhadap WORKLOG & `routes/web.php` (route `reports.*`, `users.*`, `audit-logs.*`, `settings.*`, `telegram.webhook`). Link `docs/DEMO_DEPLOYMENT.md` & `docs/LOCAL_PRODUCTION_HARDENING.md` dipastikan ada.

### Fix: tombol logout tidak ada di tampilan mobile

Changed:

- `resources/js/Layouts/AuthenticatedLayout.vue` — `UserMenu` (berisi tombol Keluar/logout) ternyata hanya dirender di header desktop (`lg:block`), sedangkan mobile top bar & sidebar drawer tak punya menu user sama sekali → user tidak bisa logout di mobile. Ditambahkan blok akun di bagian bawah sidebar drawer, **khusus mobile** (`lg:hidden`, desktop tetap pakai `UserMenu` di header): avatar inisial + nama + email, lalu tombol **Profile** (Inertia `Link` ke `profile.edit`, menutup drawer saat diklik) dan **Keluar** (`Link method="post"` ke `logout`). Import ikon `LogOut`/`User` + computed `user`/`userInitial` dari `auth.user`.

Notes:

- `npm run build` bersih. Build artifacts (`public/build`) di-gitignore — perlu `npm run build` ulang saat deploy.

### Optimasi halaman Welcome — konversi screenshot PNG → WebP

Changed:

- `resources/js/Pages/Welcome.vue` — 7 referensi gambar (galeri "Tampilan Aplikasi": dashboard/oltinventory/unconfigured/detail/login, hero dashboard, hardware c320) diarahkan dari `.png` → `.webp`.
- `resources/js/Pages/SmartOlt/Detail.vue` — gambar hardware OLT (`c300`/`c320`) → `.webp`.
- `public/img/*` — 7 PNG dikonversi ke WebP (`cwebp -q 80`) lalu PNG lama dihapus: `dashboard1`, `oltinventory`, `unconfigured`, `detail`, `login`, `c300`, `c320`.

Notes:

- Folder `public/img` turun **6.3 MB → 516 KB** (~92% lebih kecil). Penghematan per file: login 1387→22 KB, dashboard 1396→98 KB, oltinventory 1108→38 KB, unconfigured 908→38 KB, detail 1133→68 KB, c300 255→109 KB, c320 196→90 KB.
- Quality 80 cukup untuk screenshot UI (teks tetap tajam). WebP didukung semua browser modern (Chrome/Firefox/Edge, Safari 14+) — aman untuk dashboard NOC, tanpa fallback PNG.
- Hero dashboard tetap `loading="eager"` (gambar LCP) tapi kini ~98 KB sehingga first paint jauh lebih ringan. `npm run build` bersih.

## 2026-05-30

### Developer Handbook + bersih-bersih dokumen usang

Created:

- `docs/handbook/README.md` — indeks + cara pakai handbook + konvensi wajib.
- `docs/handbook/01-overview.md` … `14-panduan-tambah-fitur.md` — 14 bab dokumentasi teknis terbagi per-topik: overview, arsitektur, struktur folder, instalasi/deploy, skema DB & model, routing, modul & fitur, SNMP & polling, CLI & telnet, alarm & Telegram, keamanan/RBAC/audit, frontend, troubleshooting, panduan menambah fitur.

Changed:

- `README.md` — tambah pointer ke `docs/handbook/`, seksi "Cara Cepat (`install.sh`)", deskripsi Langkah 2 (cek requirement) diperbarui, daftar ekstensi PHP diselaraskan, dan seksi "Dokumentasi" baru.

Removed:

- `docs/IMPLEMENTATION_NEXT_STEPS.md`, `docs/PLANNING_NEXT_PHASE.md` — dokumen perencanaan awal yang seluruh langkahnya sudah diimplementasikan (usang). Dikonfirmasi user.
- `docs/KusumaVision_NMS_Dokumentasi_Fitur.pdf` — PDF deskripsi fitur awal, sudah digantikan README + handbook.

Notes:

- Handbook berbasis kode nyata (bukan PRD); bagian yang masih blueprint (C600 parsial, SSH, TimescaleDB) ditandai eksplisit. Setiap bab punya navigasi prev/next + link silang.
- File yang dihapus ter-track git → bisa dipulihkan dari history. Tidak ada link aktif (README/CLAUDE.md/handbook) yang rusak.
- `docs/INSTALLATION_STATUS.md`, `LOCAL_PRODUCTION_HARDENING.md`, `DEMO_DEPLOYMENT.md`, `SMARTOLT_ZTE_C300_C320_GUIDE.md`, `KusumaVision_NMS_PRD.md`, dan 6 PDF C600 dipertahankan.

### Skrip deploy `install.sh` + cek requirement

Created:

- `install.sh` — deploy satu-perintah untuk server Ubuntu kosong (22.04/24.04): pasang runtime (PHP 8.3 + ekstensi, Composer, Node 22, PostgreSQL, Redis, Nginx, Supervisor, Go, Net-SNMP), buat DB + `.env` production, build frontend & Go poller, migrasi, nginx site (+ proxy `/telnet-ws`), daftarkan daemon supervisor (`kusumavision-worker`/`-scheduler`/`-telnet-proxy`), opsional buat admin & UFW, lalu smoke test. Mendukung `--yes` (non-interaktif via env var) dan `--help`; idempotent.

Changed:

- `scripts/check-requirements.sh` — ditingkatkan: cek versi minimum tool (PHP≥8.2, Composer≥2, Node≥20, Go≥1.18, psql≥14), daftar ekstensi PHP, artefak runtime (binary poller/build/`.env`/`APP_KEY`), dan status service + daemon supervisor. `[MISS]` (wajib) memengaruhi exit code; `[WARN]` (info) tidak.

Notes:

- Langkah `install.sh` mengikuti baseline `INSTALLATION_STATUS.md`/`LOCAL_PRODUCTION_HARDENING.md`. `bash -n` lolos untuk kedua skrip; `check-requirements.sh` diverifikasi jalan di host dev (semua wajib OK, exit 0).
- `install.sh` belum diuji end-to-end di server Ubuntu kosong (tidak tersedia di lingkungan ini) — logika dirancang idempotent + aman.
- Artefak runtime (`bin/kv-snmp-poller`, `public/build`) di-gitignore — `install.sh` membangun ulang saat deploy.

### Lisensi proprietary + sinkron CLAUDE.md & skill /done

Created:

- `LICENSE` — lisensi proprietary BMKV ringkas (bilingual ID/EN): kepemilikan, larangan salin/ubah/distribusi/reverse-engineer/pakai tanpa izin, komponen pihak ketiga tetap di bawah lisensinya, disclaimer "as is".

Changed:

- `composer.json` — `license` `MIT` → `proprietary`; `name`/`description`/`keywords` diganti dari sisa skeleton Laravel ke identitas proyek.
- `CLAUDE.md` — tambah pointer ke `docs/handbook/`, perintah deploy (`install.sh`, `scripts/check-requirements.sh`), dan bullet konvensi deploy fresh-server + catatan lisensi proprietary.
- `.claude/commands/done.md` — `Co-Authored-By` `Sonnet 4.6` → `Opus 4.8`, tambah tipe commit `docs`, dan pengingat sinkronkan `CLAUDE.md`/`docs/handbook/` bila struktur/konvensi berubah.

Notes:

- JSON `composer.json` tervalidasi; `proprietary` adalah nilai lisensi yang dikenali Composer.
- Murni dokumentasi/tooling — tidak ada perubahan kode aplikasi atau migrasi.

### Atribusi pemilik permanen di footer (anti-ubah lewat UI)

Changed:

- `app/Models/GeneralSetting.php` — tambah konstanta permanen `OWNER` ("PT Berkah Media Kusuma Vision"), `OWNER_SHORT` ("BMKV"), `COPYRIGHT_YEAR`; `brandingPayload()` selalu menyertakan `owner`/`owner_short`/`copyright_year` dari konstanta (bukan dari DB), sehingga tidak bisa diubah lewat halaman Pengaturan.
- `resources/js/Layouts/AuthenticatedLayout.vue` — footer jadi `© {tahun} {appName} NMS · {owner}` (sebelumnya hardcode "Dibuat Oleh Masamune"); `owner`/`copyrightYear` dibaca dari `branding` dengan fallback.
- `resources/js/Layouts/GuestLayout.vue` — footer login memakai `branding` (appName + owner permanen) menggantikan teks statis.

Notes:

- Keputusan user: kunci **atribusi pemilik saja** — `app_name` tetap bisa di-white-label via Settings, tetapi pemilik/copyright permanen di level kode.
- Disclaimer jujur ke user: kode sumber tidak bisa dibuat benar-benar anti-ubah; proteksi nyata adalah `LICENSE` proprietary. Perubahan ini hanya memindah atribusi dari DB/UI ke konstanta kode, sehingga menghapusnya berarti edit source = pelanggaran lisensi.
- Fallback JS (`?? 'PT Berkah Media Kusuma Vision'`) menjaga footer benar walau cache branding lama; cache key `general_settings.branding` di-forget + `npm run build`. 121 test lolos.

## 2026-05-31

### Navbar transparan-saat-scroll, hero full layar, lebar kontainer, swap gambar OLT

Changed:

- `resources/js/Pages/Welcome.vue` — navbar: dari `sticky` + latar solid permanen menjadi `fixed` overlay yang **transparan di puncak** dan memunculkan latar semi-transparan (`bg-slate-950/60` + blur) saat di-scroll (>12px) atau drawer mobile terbuka (`navSolid` computed + listener `onWindowScroll`). Ukuran navbar dibesarkan sedikit (`py-3`→`py-4`, logo `h-8`→`h-9`, brand `text-[15px]`). Tombol Login/Dashboard dipercantik (padding lega, `rounded-xl`, inner ring, efek kilau/shine saat hover, ikon panah `ArrowRight`). Hero `lg:min-h-[calc(100vh-57px)]`→`min-h-screen` (full satu layar). Semua kontainer halaman `max-w-7xl`→`max-w-[1600px]` (12 lokasi) agar mengisi layar kanan-kiri.
- `public/img/c320(1).webp` — gambar strip hardware OLT baru (konversi dari `c320(1).png` via `cwebp -q 90`, 1600×444, alpha lossless); tinggi gambar di welcome dikecilkan `h-32/md:h-40` → `h-20/md:h-24/lg:h-28` agar tidak kebesaran (rasio gambar sangat lebar).

Notes:

- Navbar `fixed` membuat hero benar-benar tembus di belakangnya; padding atas hero (`py-16`/`lg:py-24`) menjaga konten tidak tertutup navbar.
- Gambar lama `public/img/c320.webp` dihapus (di-replace `c320(1).webp`).

### Upgrade interaktif semua section landing (Bold & interaktif)

Created:

- (helper CSS, bukan file baru — ditambah di `app.css`)

Changed:

- `resources/css/app.css` — helper landing reusable di `@layer components`: `.kv-spotlight` (sorotan radial ikut kursor via `--spot-x/--spot-y`), `.kv-ring` (border conic-gradient beranimasi saat hover, pakai `@property --ring-angle`), `.kv-marquee` (+ `.kv-marquee-wrap`, infinite scroll, pause on hover), `.kv-float` (idle float). Tambah `@keyframes kv-ring-spin/kv-marquee/kv-float` + guard `prefers-reduced-motion`.
- `resources/js/Pages/Welcome.vue` — directive baru `vSpotlight` (gaya sama `vTilt`/`vMagnetic`). **Stats**: ikon+aksen warna per angka, garis aksen atas saat hover, spotlight. **Hardware strip**: gradient ring + ambient glow + gambar `kv-float`. **Section baru**: capability marquee (12 kapabilitas berjalan, fade tepi). **Feature grid**: spotlight + ring + ikon/judul beranimasi. **Cara Kerja**: garis konektor digambar mengikuti scroll (GSAP `fromTo` scaleX + ScrollTrigger scrub, ref `stepsLineEl`) + kartu spotlight/ring. **Galeri Tampilan**: autoplay 5s (`startGallery/stopGallery/advanceShot/selectShot`, `GALLERY_MS`), pause saat hover (`galleryPaused`), progress bar `.kv-prog` (restart via `:key`). **Tech Stack**: ring + logo `kv-float` (delay desync per index). **Modul**: spotlight + ring + hover lift + chevron geser. **Final CTA**: ring + glow `animate-pulse`/`kv-float`.

Notes:

- Tanpa dependency baru — semua efek pakai stack terpasang (GSAP/ScrollTrigger/Lenis) + directive `v-spotlight` ringan + CSS murni; bundle tetap lean.
- Semua animasi dimatikan untuk pengguna `prefers-reduced-motion` (guard di `app.css` global + `<style scoped>` Welcome).
- `.kv-spotlight > *` diberi `z-index:1`; `position:absolute` anak (mis. nomor langkah, divider stats) tetap menang karena utilities layer Tailwind di atas components layer. `@property` graceful-degrade di browser lama (ring jadi statis, tidak rotasi). Build `npm run build` lolos.

### Feature grid: ikon & isi rata tengah

Changed:

- `resources/js/Pages/Welcome.vue` — kartu Feature grid dibuat rata tengah: `text-center` di kartu + `mx-auto` di span ikon (judul & deskripsi ikut center).

### Header atas & footer app dikunci (tidak ikut scroll), header per-halaman tetap ikut scroll

Changed:

- `resources/js/Layouts/AuthenticatedLayout.vue` — layout app login diubah dari *document scroll* (`min-h-screen` + header/footer `sticky`) menjadi **viewport tetap dengan area scroll di tengah**: root jadi `flex h-screen flex-col overflow-hidden`; kolom utama `flex-1 overflow-hidden`. Header atas (top bar mobile + header desktop search/notif/user) dan footer kini `flex-shrink-0` (benar-benar diam, tidak lagi `sticky`). Header per-halaman (slot `header`) + demo banner + `<main>` dibungkus satu kontainer `flex min-h-0 flex-1 flex-col overflow-y-auto` dengan atribut `scroll-region`, sehingga **header per-halaman ikut tergulir bersama konten** sesuai permintaan; header paling atas & footer tetap menempel.

Notes:

- Atribut `scroll-region` membuat Inertia 2 me-reset posisi scroll area tengah saat pindah halaman (sebelumnya scroll mengikuti `window`); pola sama dipakai `resources/js/Components/Modal.vue`.
- Footer dulu hanya `lg:sticky` (diam di desktop saja) — sekarang diam di mobile juga karena berada di luar area scroll.
- Pakai `h-screen` (100vh); di sebagian browser mobile address-bar bisa sedikit memotong — bila mengganggu nanti bisa diganti `h-[100dvh]`. Build `npm run build` lolos (20.01s).

### Animasi jaring partikel (ParticleNetwork) menyeluruh di app + login, fix gagal re-init saat navigasi

Created:

- `resources/js/lib/particles.js` — module singleton tsParticles: `ensureParticlesEngine()` cache promise `loadSlim()` (register plugin **sekali seumur tab**) dan `nextParticlesId(prefix)` untuk id unik per mount. State harus di module ini (bukan di `<script setup>`) supaya benar-benar persist lintas mount.

Changed:

- `resources/js/Components/Shell/ParticleNetwork.vue` — tidak lagi `import loadSlim` + `tsParticles.load` dengan id statis. Sekarang `import { ensureParticlesEngine, nextParticlesId, tsParticles } from '@/lib/particles'`; pakai `uid = nextParticlesId(props.id)` untuk DOM id + registry id; tambah flag `destroyed` (guard race kalau komponen unmount selama `await` saat navigasi cepat → bersihkan/ batalkan init).
- `resources/js/Layouts/AuthenticatedLayout.vue` — pasang `<ParticleNetwork id="kv-app-particles" class="!fixed inset-0" :quantity="64" />` di dalam `<main>` (di belakang konten) via `defineAsyncComponent`, jadi animasi tampil **menyeluruh di semua halaman app** (Dashboard, SmartOLT, Monitoring, Alarms, Report, Users, dst), bukan per-halaman. `<main>` diberi `relative`, slot konten diberi `relative` agar di atas partikel.
- `resources/js/Layouts/GuestLayout.vue` — pasang `<ParticleNetwork id="kv-login-particles" :quantity="48" />` di latar halaman login & semua halaman Auth (via `defineAsyncComponent`).

Notes:

- **Akar masalah utama** (error `Register plugins can only be done before calling tsParticles.load()`): isi `<script setup>` sebenarnya badan `setup()` yang dieksekusi ulang TIAP komponen mount. Karena layout app **non-persistent** (komponen partikel re-mount tiap navigasi Inertia), singleton `let enginePromise` yang ditaruh di dalam `<script setup>` ter-reset ke `null` tiap pindah halaman → `loadSlim()` terpanggil lagi setelah `load()` pertama → `pluginManager.register()` throw (lihat `node_modules/@tsparticles/engine/cjs/Core/Utils/PluginManager.js`, `#initialized`). Solusi: pindahkan singleton ke module eksternal (`lib/particles.js`) yang benar-benar di module scope.
- Pola `defineAsyncComponent` dipertahankan (gotcha Vite manifest page facade yang sudah tercatat) — diverifikasi chunk `AuthenticatedLayout`/`GuestLayout`/`Dashboard` tetap ada di manifest, `ParticleNetwork` jadi chunk async terpisah (~106 kB).
- Tiap instance pakai id unik → tidak bentrok registry tsParticles saat mount/unmount tumpang-tindih. `pointer-events-none` + hormati `prefers-reduced-motion` (latar statis). Build `npm run build` lolos (18.07s).

### Dokumentasi UI & tema dashboard + aturan halaman/komponen baru

Created:

- `docs/handbook/15-ui-tema-dashboard.md` — dokumen handbook baru: bahasa desain "dark glass cyber/NOC", palet & token warna (aksen/status + heks yang dipakai di kode), referensi lengkap kelas utilitas `kv-*` (chrome, permukaan kaca, lingkaran ikon, pill/badge, form, alert, tabel responsif desktop+mobile), anatomi shell `AuthenticatedLayout`, template halaman acuan, 13 aturan wajib + daftar "hindari", dan checklist pre-commit UI.

Changed:

- `docs/handbook/README.md` — tambah baris indeks #15 + petunjuk cepat "menambah/ubah halaman atau komponen UI".
- `docs/handbook/12-frontend.md` — callout look & feel yang merujuk doc 15.
- `docs/handbook/14-panduan-tambah-fitur.md` — Resep 1 tambah langkah "Tampilan" yang merujuk doc 15.
- `CLAUDE.md` — pointer singkat di bagian Conventions ke aturan tema (pakai `kv-*` dulu, kartu kaca, tabel responsif, gerbang `auth.can`, string Indonesia).

Notes:

- Sumber kebenaran token = `resources/css/app.css` (`@layer components`) + `Layouts/AuthenticatedLayout.vue`; dokumen mendeskripsikan kode yang benar-benar ada (bukan PRD) dan menegaskan "kode menang" bila ada beda.
- Hanya perubahan dokumentasi — tidak ada kode aplikasi/asset yang berubah, jadi tidak perlu rebuild/deploy.

## 2026-06-01

### Fix konversi RX power ONU (SNMP raw → dBm): nilai +98 & redaman -30/-40 tidak terbaca

Changed:

- `app/Services/Snmp/OltSnmpClient.php` — `convertOnuRxPowerToDbm()`: cabang `raw > 0` (encoding C300/C320 OID `3902.1012.3.50.12.1.1.10`) kini menafsirkan raw sebagai **signed 16-bit** sebelum rumus `dBm = signed16(raw) * 0.002 - 30`. Guard lama `raw >= 65000` dihapus (sempat membuang range -30 s/d -31 dBm); ganti sentinel `raw === 65535` (0xFFFF = N/A) + jendela kewajaran hasil `[-45, 0]` dBm untuk buang garbage.
- `cmd/kv-snmp-poller/main.go` — `convertOnuRXPowerToDBM()`: perbaikan identik pada jalur Go poller terjadwal (prod `SNMP_POLLER_DRIVER=go`). Binary `bin/kv-snmp-poller` sudah di-rebuild (`go build -mod=mod`).

Notes:

- **Diverifikasi di OLT live `OLT-C320-PATI` (id=1)**: encoding terbukti `raw * 0.002 - 30` (raw 207→-29.586, 5000→-20, 10805→-8.39). Bug: raw `64032` ditafsir unsigned → **+98.064 dBm** ("nilai sampai 90an"); sebenarnya signed `-1504` → **-33.008 dBm**. Sinyal lemah lain (mis. -40 dBm = raw 60536) salah hitung jadi positif besar lalu ter-skip ("ngga kebaca di -40").
- Setelah fix: poll ulang OLT 1 via binary baru → tidak ada lagi dBm > 0, raw 64032 = -33.008, dan nilai bagus lama tetap sama. Cabang negatif (`/1000`, `/10`) untuk firmware lain tidak diubah.
- Cara diagnosa untuk ke depan: dump `raw_rx_power` vs `rx_power_dbm` dari `last_test_result.port_onus`, cek nilai > 32767 sebagai two's-complement.
- Deploy: `opcache.validate_timestamps=On` (PHP terpungut otomatis); binary Go di-exec fresh tiap poll → poll terjadwal berikutnya otomatis pakai logika baru. Klik "Scan ONU OLT ini" untuk refresh cache seketika. Opsional `php artisan queue:restart` untuk fallback PHP di worker.

### Filter redaman ONU RX di halaman ONU Monitoring

Changed:

- `resources/js/Pages/SmartOlt/OnuMonitor.vue` — tambah dropdown filter **Redaman** (Semua/Normal/Peringatan/Kritis/Tanpa Data RX). Helper `rxLevel()` baru mengklasifikasikan `rx_power_dbm` dan dipakai bersama oleh `rxBadgeClass()` + filter `filteredOnus` agar ambang batas konsisten. Filter masuk ke `hasFilter`/`clearFilters`. Murni sisi klien (data `rx_power_dbm` sudah ada).

Notes:

- Stat cards tetap menghitung seluruh ONU (tidak ikut terfilter) — perilaku sama seperti filter lain di halaman ini.

### Filter Redaman RX, filter Status, & status berbasis phase di halaman Report

Changed:

- `app/Services/Report/ReportService.php` — (1) konstanta `RX_STATUSES`; `rxPower()` menerima filter `rx_status` (Normal/Warning/Critical) yang mempersempit baris tapi summary tetap penuh. (2) `build()` membungkus hasil dengan `applyStatusFilter()` baru: menempel `status_options` (+`status_column`) dan filter baris per status; RX dilewati (pakai redaman). (3) helper `onuPhaseLabel()`: kolom status laporan **Inventaris ONU** kini dari `phase_state` (Working→Online, LOS, DyingGasp→Dying Gasp, Offline) dengan 4 opsi tetap seperti ONU Monitoring; jenis lain (OLT pakai kolom `reachable`, Alarm, Provisioning) opsinya diturunkan dari data.
- `app/Http/Controllers/ReportController.php` — parse & validasi query `rx_status` + `status`, diteruskan ke view.
- `resources/js/Pages/Reports/Index.vue` — dropdown **Redaman RX** (hanya jenis `rx`) dan **Status** (jenis non-`rx` yang punya opsi); auto-reset saat ganti jenis laporan; `statusClass()` tambah warna LOS (merah) & Dying Gasp (kuning).

Notes:

- Semua filter **server-side** → export CSV/PDF ikut terfilter.
- Diverifikasi: ONU `status_options` = [Online, LOS, Dying Gasp, Offline]; distribusi cocok dengan `phase_state` cache (Online 2154, Dying Gasp 76, Offline 15, LOS 10); tiap filter konsisten 100%. Hanya PHP + frontend, tidak perlu restart daemon (`npm run build` lolos).

### Foto tampilan aplikasi di README + tooling snapshot

Created:

- `scripts/snapshot.mjs` — script Playwright (Chromium headless) untuk capture halaman **Welcome (hero+navbar)**, **Login**, dan **Dashboard** ke `public/img/*.webp`; screenshot PNG lalu dikonversi `.webp` via `cwebp`. Konfigurasi via env (`BASE_URL`, `OUT_DIR`, `WIDTH/HEIGHT`, `DSF`, `WEBP_QUALITY`, `ONLY`, `SNAP_USER/SNAP_PASS`). Default akses `https://127.0.0.1` + `ignoreHTTPSErrors` (hindari Cloudflare bot-challenge di domain publik); tunggu `networkidle` + 2.5 dtk agar animasi hero (tsParticles/typed.js/AOS/gsap) & chart ApexCharts selesai.
- `public/img/welcome.webp`, `public/img/dashboard.webp` — screenshot baru (landing hero+navbar; dashboard full-page dengan data live).

Changed:

- `README.md` — section baru **Tampilan Aplikasi** (sebelum Fitur): `welcome.webp` sebagai gambar utama + `dashboard.webp`, lalu grid 2 kolom `login`/`oltinventory`/`detail`/`unconfigured`.
- `public/img/login.webp` — di-capture ulang (retina/DSF=2, lebih tajam dari versi lama).
- `package.json` / `package-lock.json` — tambah script `snapshot`; `playwright` jadi devDependency.
- `.gitignore` — abaikan `.snap.env` (file kredensial sementara untuk capture dashboard).

Notes:

- **Server ini produksi.** Capture dashboard perlu login → kredensial disuplai user via `.snap.env`, di-`source` saat run tanpa dicetak, lalu dihapus (`shred`). Tidak pernah masuk kode/log/commit. Semua request hanya `GET` (read-only), tidak menyentuh data.
- Skill Claude Code `snapshot` (`.claude/skills/snapshot/SKILL.md`) dibuat **lokal saja** — tidak di-commit karena `.claude` di-gitignore. Pakai `npm run snapshot` untuk regenerasi.
- Tooling terpasang di server: `playwright` + Chromium + OS-deps (`npx playwright install-deps chromium`: libnss3, libcups2, libnspr4, dll).
- `public/img/dashboard1.webp` lama tidak lagi dirujuk README (dibiarkan di repo, tidak dihapus).
- Regenerasi kapan saja: `npm run snapshot` (welcome+login) atau `SNAP_USER=… SNAP_PASS=… npm run snapshot` (+dashboard); subset via `ONLY=welcome,login,dashboard`.

## 2026-06-08

### README: alur env setup→production + fix prompt Composer root di check-requirements

Changed:

- `README.md` — restrukturisasi alur environment pada panduan instalasi manual. **Langkah 4** kini set `APP_ENV=local` + `APP_DEBUG=true` + `LOG_LEVEL=debug` selama setup (sebelumnya langsung `production`/`APP_DEBUG=false`) supaya error saat migrasi/build terlihat jelas. **Langkah 5** — `php artisan optimize` dihapus dari blok permission (ditunda ke langkah harden) + catatan verifikasi via `php artisan serve`/`composer dev`. **Langkah 10 — Harden ke production** (baru): set `production`/`APP_DEBUG=false`/`LOG_LEVEL=warning`, lalu `php artisan optimize` + restart daemon Supervisor, plus peringatan permission `.env` `640 root:www-data` (kalau salah → fallback sqlite → 500).
- `scripts/check-requirements.sh` — `export COMPOSER_ALLOW_SUPERUSER=1` + `COMPOSER_NO_INTERACTION=1` di awal script. Saat dijalankan sebagai root, `composer --version` (dengan `2>/dev/null`) memunculkan prompt "Continue as root/super user [yes]?" yang teksnya kebuang ke stderr → script seolah berhenti menunggu Enter setelah pengecekan PHP. Kedua env var mematikan prompt root sepenuhnya.

Notes:

- Hanya dokumentasi + script utilitas; tidak menyentuh runtime aplikasi. `.env.example` (sudah `local`) dan `install.sh` (sudah set `production` di akhir deploy otomatis, baris 245-246) tidak diubah — alur manual baru kini konsisten dengan keduanya.
- Fix Composer diverifikasi: di lingkungan non-tty Composer otomatis non-interaktif sehingga tak reproduksi, tapi `COMPOSER_ALLOW_SUPERUSER=1` mematikan peringatan/prompt tanpa peduli tty. Script dijalankan ulang penuh → semua tool [OK] tanpa jeda.

### install.sh: pin PHP 8.3 konsisten + Go poller build statis & smoke test

Changed:

- `install.sh` — (1) **Pin versi PHP**: variabel baru `PHP_CLI="php${PHP_VERSION}"`; `run_artisan`, `PHP_BIN` (command daemon Supervisor), serta installer & `composer install` kini dipanggil eksplisit lewat `php8.3` (bukan `php` polos). Plus `update-alternatives --set php /usr/bin/php8.3` setelah pasang runtime agar default `php` sistem = 8.3. Mencegah split di mana FPM jalan 8.3 tapi artisan/worker nyangkut ke PHP lebih baru (mis. 8.4) bila sudah terpasang — persis mismatch yang ditemukan saat cek deploy manual di server lain. (2) **Go SNMP poller**: build jadi statis (`CGO_ENABLED=0 go build -mod=mod -trimpath -ldflags='-s -w'`) supaya binary self-contained (tak tergantung glibc) & aman dipindah antar server, lalu **smoke test** pasca-build (jalankan binary, pastikan emit JSON `"ok"`; kalau gagal → `[WARN]`, karena `PollOltJob` akan diam-diam fallback ke PHP).

Notes:

- Diverifikasi di server ini: build statis menghasilkan `statically linked` (binary 2.67 MB vs 3.96 MB dynamic), smoke test → `"ok":true`. Build uji dilakukan ke `/tmp` agar binary produksi yang sedang dipakai worker tidak terganggu; `bash -n install.sh` lolos.
- Hanya menyentuh `install.sh` (alur deploy fresh) — aplikasi yang sudah berjalan tidak terdampak.
- Bukti Go benar-benar terpakai saat runtime: `snmp_olts.last_test_result::jsonb ->> 'go_poller_error'` bernilai null pada OLT id=1 & id=2 (poll nyata via Go, bukan fallback).

## 2026-06-11

### Pilihan Service Mapping Mode (VLAN+Priority / Transparent) di provisioning & configure ONU

Changed:

- `app/Services/ZteProvisioningScriptBuilder.php` — helper baru `serviceLine()`; baca `service_mode` dari data form. Mode `transparent` emit `service NAME gemport 1` (tanpa cos/vlan), mode `vlanpri` (default) tetap `service NAME gemport 1 cos 0 vlan {vlan}`.
- `app/Services/ZteOnuRunningConfigService.php` — regex parser service kini menjadikan `cos X vlan Y` opsional; baris tanpa cos/vlan → `mode = transparent` (vlan null), dengan cos/vlan → `mode = vlanpri`. Mencegah service transparent terbaca sebagai "berubah" saat diff.
- `app/Services/ZteOnuReconfigureScriptBuilder.php` — `diffServices()` & `fmtService()` mendukung per-baris `mode`; saat transparent, `vlan` boleh kosong dan baris hanya emit `gemport N`.
- `app/Http/Controllers/SmartOltController.php` — default form `service_mode: 'vlanpri'` + aturan validasi `service_mode` (provisioning) dan `config.services.*.mode` (reconfigure), keduanya `in:vlanpri,transparent`.
- `resources/js/Pages/SmartOlt/RegisterOnu.vue` — toggle "Service Mapping Mode" di samping Service Name + preview CLI live.
- `resources/js/Pages/SmartOlt/ConfigureOnu.vue` — kolom **Mode** (dropdown) di tabel service; input COS/VLAN otomatis disabled saat transparent; `addService()` default `mode: 'vlanpri'`.
- `tests/Unit/ZteOnuConfigureTest.php` — 3 test baru (parser vlanpri/transparent, switch reconfigure ke transparent, builder provisioning transparent vs vlanpri).

Notes:

- Latar masalah: di sesama OLT C320, sebagian ONU tidak konek di mode VLAN+Priority (`service ... cos 0 vlan 125`) tapi konek di mode Transparent (`service ... gemport 1`). Disamakan dengan pilihan mode mapping di GUI ZTE NetNumen (VLAN+Priority vs Transparent).
- `service_mode` **tidak** dipersist ke tabel `smartolt_onu_registrations` (bukan di `$fillable`, jadi Laravel silently discard seperti `is_c600`) — modenya sudah terekam implisit di kolom `cli_script` audit. Tidak perlu migrasi.
- Verifikasi: `php artisan test tests/Unit/ZteOnuConfigureTest.php` → 9 passed (52 assertions); `npm run build` sukses; Pint passed. Feature test `SmartOltInventoryTest` yang gagal 419 adalah gotcha config cache produksi, bukan dari perubahan ini.

### Fix bug pemetaan slot/port ONU: tabrakan if-index ONU-prefix vs IF-MIB port

Created:

- `cmd/kv-snmp-poller/main_test.go` — test regresi: `buildPortMap` harus di-key oleh ONU-prefix index (bukan IF-MIB if-index), round-trip `onuPortPrefixIndex`↔`decodeIfIndex`, dan decode if-index ONU C320.

Changed:

- `cmd/kv-snmp-poller/main.go` — `buildPortMap()` kini di-key oleh **ONU-table prefix index** `0x10000000|slot<<16|port<<8` (helper baru `onuPortPrefixIndex()`), bukan `port.IfIndex` (IF-MIB). Sebabnya: dua sistem penomoran if-index ZTE tumpang tindih — prefix ONU slot 1 port P **sama persis** dengan if-index IF-MIB port `gpon_1/2/(P+1)` (mis. ONU 1/1 prefix `268501248` == if-index `gpon_1/2/2`), sehingga override `portMap[ifIndex]` salah mengikat semua ONU slot 1 ke port slot 2 (P→P+1).
- `app/Services/Snmp/OltSnmpClient.php` — `registeredOnus()`: untuk **non-C600** (C300/C320) slot/port ONU diambil langsung dari `decodeIfIndex()` (otoritatif untuk ONU-prefix); override portMap (IF-MIB) di-skip. **C600 dibiarkan di jalur lama** (belum bisa diuji untuk tabrakan ini).

Notes:

- Diagnosa dari OLT produksi teman (`OLT NOBLE NET`, C320 @192.168.2.10, lewat server `nms-kusuma`, **read-only**): raw `snmpbulkwalk` tabel ONU type mengembalikan **2060 ONU <1 detik**, slot 1 port 1–10 penuh (1/1=88 … 1/10=1, 1/16=60). Tapi cache NMS menaruh slot 1 port 1–15=0 dan menggelembungkan slot 2. **Total tetap 2060** — bukan ONU hilang, murni salah label. Pola pergeseran 1/P→2/(P+1) cocok 100% di 10 port (mis. 2/2: 93+88=181, 2/3: 128+47=175). Dikonfirmasi NetNumen GUI (Slot 1/GTGH Port 1 NGADIPIRO penuh) — decode `>>16/>>8` = kenyataan.
- Slot 2 & port 16 selamat karena prefix-nya **di atas** rentang if-index port tertinggi (gpon_1/2/16 = 268504832) → tak ada match di portMap → pakai decode (benar).
- Verifikasi lokal: `go vet`/`go test` (3 test) ok, `go build` statis ok (binary 2.67 MB), smoke test emit JSON valid; Pint passed; PHPUnit Unit 13 passed.
- **Penting buat deploy teman:** `bin/kv-snmp-poller` di-gitignore → setelah `git pull` WAJIB rebuild (`go build -o bin/kv-snmp-poller ./cmd/kv-snmp-poller` atau `install.sh`), lalu `php artisan queue:restart` + re-poll OLT agar cache slot 1 terisi benar. Perubahan PHP cukup `php artisan config:cache` bila perlu (kode otomatis terpakai untuk refresh on-demand).

## 2026-06-15

### Hapus kartu "Distribusi RX Power" di halaman ONU Monitoring

Changed:

- `resources/js/Pages/SmartOlt/OnuMonitor.vue` — buang pemakaian `<RxDistributionCard :onus="oltScopedOnus" />` beserta import-nya; histogram distribusi RX power dihilangkan dari halaman monitoring.

Notes:

- Atas permintaan user — kartu histogram dirasa kurang pas di layout halaman. Hanya histogram distribusi yang dihapus; fitur RX power time-series & trend gauge dari commit sebelumnya tetap ada.
- `oltScopedOnus` dibiarkan utuh (masih dipakai tabel ONU & statistik). File komponen `resources/js/Components/SmartOlt/RxDistributionCard.vue` ikut dihapus karena sudah tak ada referensi.

### Visualisasi chassis OLT + hapus Port Manager → halaman Detail Port per-interface

Created:

- `resources/js/Components/SmartOlt/OltChassis.vue` — visualisasi sasis OLT data-driven (di halaman Detail OLT). Render 1 modul per slot dari `cards`; LED port diwarnai live: GPON dari `oper_status` SNMP, uplink dari `link_status` tersimpan (hijau=up, merah=down, abu=belum dipoll). Slot kosong di antara min–max tetap tampil. Klik port GPON/uplink → halaman detail port. Dua orientasi: vertikal (C300, kolom ramping melar penuh kiri-kanan, port 1 kolom, pasangan slot 19/20 ditumpuk atas-bawah via `STACK_PAIRS`) & horizontal (C320, line-card span penuh + slot kontrol ≥3 berbagi 2 kolom kartu, port 1 baris, LED besar di tengah).
- `resources/js/Pages/SmartOlt/PortDetail.vue` — halaman detail per-interface: status link, trafik (chart live ApexCharts untuk uplink + counter), optical/SFP (redaman RX/TX + threshold warna), VLAN tagged + form tambah VLAN (uplink), ringkasan ONU + tombol ke daftar ONU (GPON).

Changed:

- `app/Services/ZteCardUplinkService.php` — tambah `refreshUplinkInterface()` (refresh 1 port uplink xgei/gei dari CLI: port-status + vlan + optical, mirror `refreshGponInterface`).
- `app/Http/Controllers/SmartOltController.php` — hapus method Port Manager (`dashboard`/`refreshDashboard`/`refreshDashboardInterface`/`dashboardTraffic`/`storeDashboardVlan`); tambah `portDetail`/`refreshPortDetail` (dispatch GPON vs uplink)/`portTraffic` (JSON)/`storePortVlan`. `detail()` kirim prop `interfaces` (link/admin per interface) ke chassis; `refreshHardware()` sekalian refresh detail interface uplink (non-fatal) agar status link per-port terisi.
- `routes/web.php` — hapus 5 route `smartolt.port-manager*`, tambah `smartolt.port.detail/refresh/traffic/vlan`.
- `resources/js/Pages/SmartOlt/Detail.vue` — ganti blok gambar/tabel hardware lama dengan komponen `OltChassis` (+ tombol Refresh Hardware via slot `#actions`); hapus tombol "Port Manager"; teruskan prop `interfaces`.
- `tests/Feature/SmartOltHardwareInterfaceTest.php` — 3 test diarahkan ke route/komponen baru (`smartolt.port.refresh`, `SmartOlt/PortDetail`).
- `docs/handbook/01,03,06,07,12-*.md` — sinkron Port Manager → Detail Port + visualisasi chassis.

Deleted:

- `resources/js/Pages/SmartOlt/PortManager.vue` (1013 baris) — digantikan navigasi via chassis + halaman Detail Port.

Notes:

- Nama interface dibentuk di chassis dari tipe kartu: GPON→`gpon-olt_1/{slot}/{port}`, HUVQ/HUVG/HUVX→`xgei_1/...`, SMXA/SMXB→`gei_1/...`; kartu kontrol (SCXN)/power (PRWG) tidak diklik. Prefix shelf dipakukan `1` (selaras `discoverUplinkInterfaces` & snapshot C300/C320 single-shelf).
- Status link uplink baru terisi setelah Refresh Hardware (butuh CLI per-interface) — sebelum itu port uplink tampil abu (bukan hijau palsu).
- Layout C320 (horizontal) dideteksi dari nama model mengandung `c320`. Slot 19/20 stacked di C300 lewat `STACK_PAIRS=[[19,20]]` (mudah ditambah pasangan lain mis. 10/11).
- Verifikasi: `php artisan test tests/Feature/SmartOltHardwareInterfaceTest.php` → 5 passed; full suite 129 passed; `npm run build` sukses; Pint passed. Gotcha test: route/config cache prod harus di-clear sebelum test lalu di-cache lagi.
