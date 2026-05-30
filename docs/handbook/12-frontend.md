# 12 — Frontend

[← Indeks](README.md) · [← 11 Keamanan, RBAC & Audit](11-keamanan-rbac-audit.md) · [13 Troubleshooting →](13-troubleshooting-maintenance.md)

Frontend = **Vue 3 + Inertia.js 2 + Tailwind CSS 3**, dibundel **Vite**. Bukan SPA penuh:
tiap navigasi tetap memanggil controller Laravel yang mengembalikan **props JSON** untuk komponen
Page. Routing JS pakai **Ziggy** (`route('nama')`).

## Entry & bootstrap

- `resources/views/app.blade.php` — root HTML Inertia (`@inertia`, `@vite`).
- `resources/js/app.js` — `createInertiaApp`, daftar `Pages/**/*.vue` (glob), pasang plugin Inertia
  + ZiggyVue, progress bar (`#06b6d4`). Punya handler **`vite:preloadError`** yang reload sekali
  bila chunk hash berubah (anti white-screen pasca-deploy).
- `resources/js/bootstrap.js` — axios global (`X-Requested-With`).

## Layout

| Layout | Dipakai |
|--------|---------|
| `Layouts/AuthenticatedLayout.vue` | Semua halaman setelah login (sidebar, topbar, search, bell, user menu) |
| `Layouts/GuestLayout.vue` | Halaman auth (login, reset, dll) |

`AuthenticatedLayout` mendefinisikan **menu sidebar** (array `links`) dengan `route()` + `match`
(pola route aktif). Item admin (Users/Audit Logs/Pengaturan) hanya muncul bila `auth.can.manage_users`.
Ikon dari `@lucide/vue`. Menyatu dengan komponen `Shell/*` (lihat di bawah).

## Pages (`resources/js/Pages/`)

1 file Page ≈ 1 controller render. Props datang dari controller (lihat [07 — Modul](07-modul-fitur.md)).

```
Dashboard.vue                  ← DashboardController@index
Welcome.vue                    ← landing publik (route '/')
SmartOlt/
  Index, Create, Edit          ← CRUD OLT (+ Partials/OltForm.vue)
  Detail                       ← hardware card/uplink
  GponPorts, PortManager       ← port PON & manajemen port
  PortOnus, OnuDetail, ConfigureOnu   ← ONU per port + detail + reconfigure
  OnuMonitor                   ← ONU Monitoring lintas OLT
  RegisterOnu, Registrations   ← provisioning
  Profiles                     ← katalog profil
  Unconfigured, UnconfiguredGlobal    ← ONU belum terdaftar
Reports/Index   Settings/Index   Users/Index   AuditLogs/Index
Profile/Edit (+ Partials/*)    Auth/* (Breeze)
```

## Komponen (`resources/js/Components/`)

**Form/UI dasar (Breeze + kustom):** `PrimaryButton`, `SecondaryButton`, `DangerButton`,
`IconButton`, `TextInput`, `InputLabel`, `InputError`, `Checkbox`, `Modal`, `ConfirmModal`,
`Dropdown`, `DropdownLink`, `NavLink`, `ResponsiveNavLink`, `Pagination`, `ApplicationLogo`.

**Dashboard (`Components/Dashboard/`):** `StatCard`, `PollingTrendCard`, `OltInventoryList`,
`OnuStatusDonut`, `RecentAlarmsTable`, `ProvisioningTimeline`, `RemoteActionsGrid`,
`OnuQuickActionModal`, `HeroBanner`.

**Shell (`Components/Shell/`):** `GlobalSearch` (⌘K → `dashboard.search`), `NotificationBell`
(pakai props `notifications` global), `UserMenu`, `SystemInfoPanel` (versi/uptime/users online),
`AuroraBackground`, `ParticleNetwork`, `SidebarConstellation` (efek visual), **`TelnetWindow`**
(terminal xterm.js, draggable/min/max — lihat [09](09-cli-telnet.md)).

**Composables / lib:** `Composables/useConfirm.js` (modal konfirmasi reusable),
`lib/datetime.js` (format tanggal/relatif).

## Data global yang selalu tersedia (`HandleInertiaRequests::share`)

Setiap Page bisa akses lewat `usePage().props`:
- `auth.user` + `auth.can` (`manage_users`, `manage_olt`, `is_demo`).
- `flash.success` / `flash.error` (dari `session()->flash`).
- `notifications` (`items[]`, `unread_count`) — untuk bell.
- `systemInfo` (`version`, `uptime`, `users_online`).
- `branding` (`name`, `version`, `logo_url`) — dari `general_settings`, di-cache 1 jam.

## Pustaka frontend penting
- **ApexCharts** (`vue3-apexcharts`) — grafik dashboard/report.
- **@xterm/xterm** + `addon-fit` — terminal telnet.
- **@number-flow/vue** — animasi angka stat card.
- **gsap, aos, lenis, typed.js, tsparticles** — animasi/efek landing & shell.
- **@lucide/vue** — ikon.

## Build & deploy frontend
- Dev: `npm run dev` (HMR). Prod: `npm run build` → `public/build`.
- `vite.config.js`: `emptyOutDir: false` → chunk hash lama disimpan agar sesi aktif tidak rusak
  saat deploy. Dikombinasikan dengan handler `vite:preloadError` di `app.js`.

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
- Gunakan `useConfirm` untuk aksi destruktif; tampilkan `flash` via layout.
- Sembunyikan tombol berdasarkan `auth.can`, tetapi **backend tetap menegakkan izin**.

## Selanjutnya

→ [13 — Troubleshooting & Maintenance](13-troubleshooting-maintenance.md)
