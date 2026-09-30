# 12 — Frontend

[← Indeks](README.md) · [← 11 Keamanan, RBAC & Audit](11-keamanan-rbac-audit.md) · [13 Troubleshooting →](13-troubleshooting-maintenance.md)

Frontend = **Vue 3 + Inertia.js 2 + Tailwind CSS 3**, dibundel **Vite**, dwibahasa lewat
**vue-i18n** dan dua tema (gelap/terang/ikut sistem). Bukan SPA penuh: tiap navigasi tetap
memanggil controller Laravel yang mengembalikan **props JSON** untuk komponen Page. Routing JS pakai
**Ziggy** (`route('nama')`).

## Entry & bootstrap

- `resources/views/app.blade.php` — root HTML Inertia (`@inertia`, `@vite`, `@routes` Ziggy), semua
  skrip memakai **nonce CSP** (`ContentSecurityPolicy`); atribut `data-theme` di `<html>` sudah
  diputuskan server pada render pertama (tanpa kedipan tema).
- `resources/js/app.js` — `createInertiaApp`, daftar `Pages/**/*.vue` (glob), pasang plugin Inertia
  + ZiggyVue + **i18n**, progress bar. Locale i18n disinkronkan dari prop `locale` saat boot dan tiap
  navigasi. Punya handler **`vite:preloadError`** yang reload sekali bila chunk hash berubah
  (anti white-screen pasca-deploy).
- `resources/js/i18n.js` — setup vue-i18n (lihat bagian *i18n* di bawah).
- `resources/js/bootstrap.js` — axios global (`X-Requested-With`).
- **Ziggy membaca daftar rute server** (`@routes`) — di produksi daftar itu berasal dari **route
  cache**. Rute baru yang dipanggil `route('…')` di Vue melempar error sampai `php artisan
  route:cache` dijalankan ulang (lihat [14](14-panduan-tambah-fitur.md)).

## Layout

| Layout | Dipakai |
|--------|---------|
| `Layouts/AuthenticatedLayout.vue` | Semua halaman setelah login (sidebar, topbar, search, bell, user menu) |
| `Layouts/GuestLayout.vue` | Halaman auth (login, reset, dll) |

`AuthenticatedLayout` mendefinisikan **menu sidebar** berkelompok (item `{ name: t('nav.…'), icon,
href: route(), match, except }`, pola route aktif). Grup admin: **Users** muncul bila
`auth.can.manage_users` (admin), **Audit Logs** & **Pengaturan** bila `manage_users && !is_partner`,
dan **Bot Telegram** (`partner.telegram.edit`) hanya untuk partner. Grup bantuan berisi **Panduan**
(`/panduan`). Ikon dari `@lucide/vue`. Header memuat pencarian (`GlobalSearch`), `NotificationBell`,
`LanguageSwitcher`, dan `UserMenu`. Pemilih tema ada di `UserMenu` (desktop, radiogroup bawaan) dan
`ThemeSegmented` di blok akun sidebar (mobile). `FlashMessages` menampilkan `flash`.

## Pages (`resources/js/Pages/`)

1 file Page ≈ 1 controller render. Props datang dari controller (lihat [07 — Modul](07-modul-fitur.md)).

```
Dashboard.vue                  ← DashboardController@index
Welcome.vue                    ← landing publik (route '/', punya ThemeToggle sendiri)
SmartOlt/
  Index, Create, Edit          ← daftar OLT (tab ZTE / C-Data / HiOSO) + CRUD ZTE (+ Partials/OltForm.vue)
  Detail                       ← hardware card/uplink + visualisasi chassis (OltChassis.vue)
  PonPorts                     ← halaman "PON Port" BERSAMA ketiga family (prop route_prefix;
                                 dulu GponPorts.vue) — kartu per port + cari ONU
  PortDetail                   ← detail port ZTE (trafik/SFP/VLAN, deskripsi, matikan/nyalakan port)
  PortOnus, OnuDetail, ConfigureOnu   ← ONU per port + detail + Configure ONU
  OnuMonitor                   ← ONU Monitoring lintas OLT
  RegisterOnu, Registrations   ← provisioning (Sederhana / Lanjutan / C600)
  Profiles                     ← katalog profil
  UnconfiguredGlobal (+ Unconfigured per-OLT, tak ditautkan UI) ← ONU belum terdaftar + Bind ONU
  ConfigBackups                ← riwayat & diff backup running-config ZTE
  Alarms                       ← daftar alarm (route alarms.index)
CDataOlt/  Create, Edit (+ Partials/CDataOltForm), Detail (faceplate), PortOnus, PortDetail, Vlans
Hioso/     Create, Edit (+ Partials/HiosoOltForm), Detail (faceplate), PortOnus
Map/Index   Odp/Index   Reports/Index   Settings/Index   Users/Index   AuditLogs/Index
Panduan/Index   Profile/Edit (+ Partials/*)   Partner/TelegramBot   Auth/* (Breeze)
```

Halaman C-Data/HiOSO **berbagi** `PonPorts.vue` dan komponen presentasi
`Components/CDataOlt/OltFaceplate.vue`; jangan menyalin halaman untuk family baru — tambahkan
`route_prefix`/capability.

## Komponen (`resources/js/Components/`)

**Form/UI dasar (Breeze + kustom):** `PrimaryButton`, `SecondaryButton`, `DangerButton`,
`IconButton`, `TextInput`, `InputLabel`, `InputError`, `Checkbox`, `Modal`, `ConfirmModal`,
`Dropdown`, `DropdownLink`, `NavLink`, `ResponsiveNavLink`, `Pagination`, `ApplicationLogo`,
`OltImage` (gambar produk per model, `lib/oltImage.js`), `OltPortLabel` (edit label port non-ZTE),
`OnuOdpCell` (kolom ODP di tabel ONU).

**Dashboard (`Components/Dashboard/`):** `StatCard`, `PollingTrendCard`, `OltInventoryList`,
`OnuStatusDonut`, `RecentAlarmsTable`, `ProvisioningTimeline`, `RemoteActionsGrid`,
`OnuQuickActionModal`, `HeroBanner`.

**SmartOlt (`Components/SmartOlt/`):** `RxTrendCard` (grafik tren RX power 24h/7d/30d + pita zona —
props `rx_history`/`range`, toggle via `router.reload` partial; dipakai ONU Detail; ambang zona dari
`Composables/useRxLevel.js`), `OltChassis` (visualisasi kartu), `OnuConfigEditor` + `OnuConfigTree`
(editor ONU per bagian gaya NetNumen, skema `lib/onuConfigSections.js`; dipakai Configure ONU &
register Lanjutan), `BindOnuModal` (Bind ONU), `Tr069BulkModal` (TR069 massal). Histogram
`RxDistributionCard` lama sudah dihapus.

**Peta (`Components/Map/`):** `OnuMap` (Leaflet, lazy-loaded, diff marker), `AddPinModal`,
`PinDetailCard`, `OdpDetailCard`, `OdpColorModal`, `OdpPhotoField`. Lihat [16](16-peta-onu.md).

**Non-ZTE (`Components/CDataOlt/`):** `OltFaceplate` (panel depan C-Data & HiOSO).

**Shell (`Components/Shell/`):** `GlobalSearch` (⌘K → `dashboard.search`), `NotificationBell`
(pakai props `notifications` global; klik → `notifications.alarms.open`, target diputuskan server),
`UserMenu`, `SystemInfoPanel` (versi/uptime/users online), `LanguageSwitcher`, `ThemeSegmented`
(aplikasi) / `ThemeToggle` (landing `Welcome.vue`), `FlashMessages`, `FilterCard`, `ClientPagination`,
`ListSkeleton`, `AuroraBackground`, `ParticleNetwork`, `SidebarConstellation` (efek visual),
**`TelnetWindow`** (terminal xterm.js, draggable/min/max — lihat [09](09-cli-telnet.md)).

> ⚠️ **Modal dirender terus, jangan dibungkus `v-if` pada keadaan terbuka.** `Modal.vue` memanggil
> `dialog.showModal()` di dalam watcher `show`; kalau komponennya baru di-mount saat `show` sudah
> bernilai `true`, watcher itu tak pernah berjalan dan modal tak muncul sama sekali — tombolnya
> terlihat "mati". Pola yang benar: `<TheModal :show="target !== null" … @close="target = null" />`.

**Composables:** `useConfirm` (modal konfirmasi reusable, varian `danger`/`warning`/`info` — **jangan
pernah `window.confirm()`**), `useLocale` (ganti bahasa seketika + persist ke server),
`usePagination` (paginasi sisi klien untuk daftar dari cache), `useRxLevel` (ambang redaman RX
bersama tabel & peta).

**lib:** `theme` (tema + `chartTheme()`/`tokenHex()` untuk ApexCharts), `datetime` (zona tampilan
tetap, `formatRelative()` via `Intl.RelativeTimeFormat`), `alarm` & `audit` (label enum/audit
dwibahasa by-key), `onu` (sebab down terakhir), `onuConfigSections`, `odpColors`, `oltImage`,
`cdataPorts`, `linediff` (diff backup config), `particles`/`perf` (efek landing).

## Data global yang selalu tersedia (`HandleInertiaRequests::share`)

Setiap Page bisa akses lewat `usePage().props`:
- `auth.user` + `auth.can` (`manage_users`, `manage_olt`, `manage_olt_inventory`, `add_olt`,
  `is_partner`, `is_demo`).
- `flash.success` / `flash.error` (dari `session()->flash`). Partial reload `only: [...]` ikut
  menyaring shared prop — sertakan `'flash'` bila aksi menampilkan toast.
- `notifications` (`items[]`, `unread_count`) — untuk bell.
- `systemInfo` (`version`, `uptime`, `users_online`, `health` — CPU/memori/disk).
- `branding` (`name`, `version`, `logo_url`) — dari `general_settings`, di-cache 1 jam.
- `locale` (`id`/`en`), `locales` (opsi), `theme` (`dark`/`light`/`system`).

## Tema (gelap / terang / ikut sistem)

- Seluruh warna = CSS custom property `--kv-*` per `[data-theme]` di `resources/css/app.css`; ganti
  tema = ganti atribut `data-theme` di `<html>`, tanpa tukar kelas/stylesheet.
- Server memutuskan tema awal (`App\Support\Theme` dari `users.theme` / cookie `kv_theme` yang
  sengaja tidak dienkripsi). `lib/theme.js`: `useTheme()`, `setTheme()` menyimpan lewat
  `axios.patch(route('profile.theme'))` → 204 — **bukan** kunjungan Inertia (yang akan menghapus
  token API sekali-tampil di halaman Pengaturan).
- Menulis kelas: ikuti `UI_DESIGN_SYSTEM.md` §3a (`text-onaccent`, `bg-canvas-3` bukan
  `bg-slate-950`, `kv-terminal` dikunci gelap; chassis & faceplate ikut tema). Grafik ApexCharts
  memakai `chartTheme()`/`tokenHex()` dan `watch(theme)` supaya ikut berganti.

## i18n (ID/EN)

- `resources/js/i18n.js`: vue-i18n 11, `legacy: false`, `globalInjection` (`$t` di template,
  `useI18n().t` di script), `locale` bawaan `id`, `fallbackLocale: 'en'`, warning kunci hilang
  dimatikan.
- **Compiler pesan kustom (`literalMessageCompiler`)**: pesan diperlakukan sebagai teks literal
  dengan interpolasi `{param}` saja — **tanpa** sintaks linked `@:`, plural `|`, atau `{'literal'}`.
  Karakter `@` (email) dan `|` jadi aman, dan tidak ada `eval` sehingga lolos CSP. Jangan menulis
  pesan yang mengandalkan plural/linked vue-i18n.
- String di `resources/js/lang/{id,en}.json` (±45 namespace). **Setiap kunci wajib ada di kedua
  berkas** — tidak ada test otomatis untuk JSON frontend; karena fallback `en`, kunci yang lupa
  di `id.json` diam-diam tampil Inggris. Opsi ber-enum stabil (jenis alarm, status) diterjemahkan
  by-key di frontend (`lib/alarm.js`), backend mengirim nilai mentah.
- Ganti bahasa: `LanguageSwitcher` → `useLocale().change()` → `POST locale.update`
  (`LocaleController`) → sesi + `users.locale`; middleware `SetLocale` memakai pilihan itu untuk
  `__()` backend. Aturan string backend di [14](14-panduan-tambah-fitur.md).

## Pustaka frontend penting
- **vue-i18n** — dwibahasa (lihat di atas).
- **Leaflet** — peta ONU/ODP (komponen dimuat malas).
- **ApexCharts** (`vue3-apexcharts`) — grafik dashboard/report (sengaja **tidak** dijadikan
  manual chunk supaya tetap dimuat malas).
- **@xterm/xterm** + `addon-fit` — terminal telnet.
- **@number-flow/vue** — animasi angka stat card.
- **gsap, aos, lenis, typed.js, tsparticles** — animasi/efek landing & shell.
- **@lucide/vue** — ikon.

## Build & deploy frontend
- Dev: `npm run dev` (HMR). Prod: `npm run build` → `public/build`.
- `vite.config.js`: `emptyOutDir: false` → chunk hash lama disimpan agar sesi aktif tidak rusak
  saat deploy. Dikombinasikan dengan handler `vite:preloadError` di `app.js`. `manualChunks`
  menggabungkan ikon Lucide ke `vendor-icons` (banyak berkas mungil → satu).
- Test komponen: `npm test` (Vitest + jsdom, `tests/js/*.spec.js`, config `vitest.config.js`).
- Build di produksi dijalankan **setelah** `route:cache` bila ada rute baru (Ziggy), dan
  `public/build` harus tetap milik `www-data`.

### ⚠️ Gotcha Vite manifest (dari memori proyek)
Meng-`import` statis library yang melakukan **banyak dynamic-import** (mis. tsParticles) langsung
di sebuah Inertia **Page** bisa membuat key manifest page hilang → **500**. Solusi: bungkus dengan
`defineAsyncComponent`, lalu rebuild + reload php-fpm. Lihat
[13 — Troubleshooting](13-troubleshooting-maintenance.md).

## Pola umum saat menambah halaman
```js
// di controller
return Inertia::render('SmartOlt/Foo', ['bar' => $data]);

// route (Ziggy) di Vue
import { router } from '@inertiajs/vue3';
router.post(route('smartolt.foo', olt.id), form);
```
- Pakai `AuthenticatedLayout` (set `defineOptions({ layout: AuthenticatedLayout })` atau bungkus
  manual sesuai pola page lain).
- Gunakan `useConfirm` untuk aksi destruktif (dilarang `window.confirm()`); tampilkan `flash` via layout.
- Semua teks lewat `$t('ns.key')` dengan kunci di **kedua** `lang/{id,en}.json`.
- Sembunyikan tombol berdasarkan `auth.can` / `olt.capabilities`, tetapi **backend tetap menegakkan
  izin**.
- Modal: render terus dengan `:show`, jangan dibungkus `v-if` (lihat peringatan di atas).

> 🎨 **Look & feel.** Untuk design token, kelas `kv-*`, palet, dan **aturan wajib** tampilan
> (kartu kaca, tabel responsif, warna status, sidebar), ikuti
> [15 — UI & Tema Dashboard](15-ui-tema-dashboard.md) dan [`UI_DESIGN_SYSTEM.md`](../../UI_DESIGN_SYSTEM.md).

## Selanjutnya

→ [13 — Troubleshooting & Maintenance](13-troubleshooting-maintenance.md)
