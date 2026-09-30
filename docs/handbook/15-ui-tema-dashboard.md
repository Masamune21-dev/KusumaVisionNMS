# 15 — UI & Tema Dashboard

[← Indeks](README.md) · [← 14 Panduan Menambah Fitur](14-panduan-tambah-fitur.md) · [12 Frontend →](12-frontend.md)

Panduan **tampilan (look & feel)** KusumaVision NMS: design token, kelas utilitas `kv-*`, anatomi
shell, dan **aturan wajib** saat membuat halaman/komponen baru agar konsisten dengan tema.

> Dokumen ini fokus ke **konvensi visual**. Untuk mekanisme Inertia/Vite/props lihat
> [12 — Frontend](12-frontend.md); untuk langkah teknis menambah halaman lihat
> [14 — Panduan Menambah Fitur](14-panduan-tambah-fitur.md).
>
> Sumber kebenaran token = **`resources/css/app.css`** (`@layer components`) dan
> **`Layouts/AuthenticatedLayout.vue`**. Bila ada beda antara dokumen ini dan kode, **kode menang** —
> lalu perbarui dokumen ini.

---

## 1. Bahasa desain (identitas tema)

Tema bernuansa **"dark glass cyber/NOC"** — pas untuk dashboard monitoring jaringan — dan sejak
**23 Sep 2026 punya dua tema: Gelap (bawaan), Terang, dan Ikuti Sistem**, dipilih per pengguna
(menu pengguna di desktop, drawer navigasi di HP, tombol tema di Welcome untuk tamu).

- **Dua tema lewat token, bukan varian `dark:`.** Seluruh ramp Tailwind menunjuk ke CSS custom
  property (`rgb(var(--kv-x) / <alpha>)`) yang nilainya dideklarasikan per `[data-theme]` di
  `app.css`. Kelas biasa (`bg-slate-900/95`,
  `text-cyan-400`, `border-white/10`) otomatis berganti tema. Aturan menulis kelas (`text-onaccent`,
  `bg-canvas-3`, `kv-terminal`, `chartTheme()`, dll.) ada di **`UI_DESIGN_SYSTEM.md` §3a**.
- **Base.** `bg-canvas` di `<body>`, kanvas `kv-grid-stage` (navy `#0b1329` di gelap, biru-abu
  `#F5F7FB` di terang). `color-scheme` mengikuti `data-theme`.
- **Aksen utama cyan→sky.** Gradient `from-cyan-500 to-sky-600`, glow `shadow-cyan-500/30`.
  Cyan = warna brand (logo, nav aktif, fokus, link, progress bar Inertia `#06b6d4`).
- **Permukaan kaca (glassmorphism).** Kartu/panel = `bg-slate-900/40` + `border-white/10` +
  `backdrop-blur-xl` + soft shadow biru. Selalu **semi-transparan** supaya latar (aurora + jaring
  partikel) menembus.
- **Latar statis.** Grid mesh 32 px + spotlight atas (`kv-grid-pattern`, `kv-top-light`,
  `kv-ambient-glow`) — hanya di tema gelap; di tema terang kanvasnya datar. `AuroraBackground` /
  `ParticleNetwork` sudah tidak dipasang di shell (beban GPU) dan tidak boleh dipasang lagi.
- **Teks.** Judul `text-white`, body `text-slate-100/200`, sekunder `text-slate-400`, redup
  `text-slate-500`. Label kecil sering `uppercase tracking-wider`.
- **Sudut & ruang.** Kartu `rounded-2xl`, tombol/elemen kecil `rounded-lg`/`rounded-xl`, badge
  `rounded-md`. Padding kartu `p-5`, padding konten halaman `px-4 sm:px-6 lg:px-8`.
- **Gerak halus.** `transition` 150–200ms, transisi antar-halaman `name="page"` (fade + geser),
  hormati `prefers-reduced-motion` (sudah di-handle di `app.css`).
- **Font.** `Manrope` 400–800 (fallback sans), di-set di `tailwind.config.js` dan dimuat dari
  fonts.bunny.net.

> **Mental model:** setiap layar = kartu kaca melayang di atas latar grid statis (gelap) atau kanvas
> datar (terang), dengan satu warna aksen cyan/sky untuk aksi & status netral, ditambah warna
> semantik untuk status lain.

---

## 2. Palet & token warna

### Aksen / status (dipakai di `kv-circle-*`, `kv-pill-*`, ikon, teks)

| Token | Warna | Makna umum |
|-------|-------|-----------|
| `sky` | `#0ea5e9` | Default/netral-informatif, ikon judul |
| `cyan` | `#22d3ee` | Brand, aksi utama, fokus, link |
| `emerald` | `#10b981` | Sukses, online, sehat |
| `red` | `#ef4444` | Gagal, offline, kritis, aksi destruktif |
| `amber` | `#f59e0b` | Peringatan, minor, auto-poll off |
| `orange` | `#f97316` | Alarm major |
| `purple` | `#a855f7` | Kategori/aksen tambahan |
| `slate` | `#64748b` | Netral/disabled/"belum ada data" |

(Heks di atas hanya **nilai cadangan**: `StatCard.vue` `accentHex` membaca warna aktif lewat
`tokenHex('sky-500', '#0ea5e9')` dkk. supaya ikut tema. Untuk warna chart/dot di JS pakai
`tokenHex()`/`chartTheme()`, jangan heks literal. `orange` tidak ada di `accentHex` — hanya untuk pill
severity major.)

### Peran warna semantik (pakai konsisten)

- **Online / OK / sukses →** emerald. **Offline / gagal / kritis →** red.
- **Peringatan / perlu perhatian →** amber. **Severity alarm:** `critical`=red, `major`=orange,
  `minor`=amber (lihat `kv-pill-critical/major/minor`).
- **Aksi primer →** gradient cyan→sky. **Aksi destruktif →** red. **Netral/info →** sky/slate.

---

## 3. Referensi kelas utilitas `kv-*`

Semua didefinisikan di [`resources/css/app.css`](../../resources/css/app.css) (`@layer components`).
**Gunakan kelas ini lebih dulu** sebelum menulis tumpukan Tailwind mentah — biar tema seragam.

### Chrome halaman & kontainer
| Kelas | Fungsi |
|-------|--------|
| `kv-page` | Pembungkus konten halaman (`py-8 pb-16`, `min-h-60vh`) |
| `kv-page-compact` | Versi padat (`py-6`) |
| `kv-container` | Lebar penuh + padding responsif (`px-4 sm:px-6 lg:px-8`) |
| `kv-container-narrow` | Kontainer ter-pusat `max-w-5xl` (form/halaman fokus) |
| `kv-grid-stage` / `kv-grid-pattern` / `kv-top-light` / `kv-ambient-glow` | Latar shell (kanvas + grid 32 px + spotlight) — **sudah dipasang layout**, jangan diulang di halaman |
| `kv-app-shell` / `kv-app-scroll` | Kerangka setinggi layar desktop & area gulir konten (lihat §4) |
| `kv-grid-bg` | Pembungkus relatif transparan — kini hanya dipakai `GuestLayout`/`Welcome.vue` |

### Permukaan (kartu/panel)
| Kelas | Fungsi |
|-------|--------|
| `kv-glass-panel` | Panel kaca besar (tabel/section), `overflow-hidden rounded-2xl` |
| `kv-glass-card` | Kartu kaca dengan padding `p-5` (stat/widget) |
| `kv-glass-hover` | Tambahan: hover terangkat + glow cyan (gabungkan dgn card/panel) |
| `kv-stat` | Kartu statistik kecil (label uppercase + angka besar), mis. ringkasan di halaman Port PON |
| `kv-table-card` | Kartu pembungkus tabel/grid data yang lebih opak (`bg-slate-900/95`) |
| `kv-glass-header` / `kv-panel-header` / `kv-panel-header-compact` | Header kartu (ikon + judul, border bawah) |
| `kv-panel`, `kv-card` | **Alias legacy** → identik glass (pakai `kv-glass-*` untuk kode baru) |

### Ikon ber-lingkaran / tile
| Kelas | Fungsi |
|-------|--------|
| `kv-circle` + `kv-circle-{sky,cyan,emerald,purple,red,amber,slate}` | Lingkaran ikon 48px berwarna (bg/teks/ring senada) |
| `kv-icon-tile` / `kv-icon-tile-sm` | Tile ikon kotak `rounded-lg` (36/32px), aksen sky |

> Override ukuran dengan `!h-10 !w-10` bila perlu (pola di `Index.vue`).

### Pill / badge status
| Kelas | Status |
|-------|--------|
| `kv-pill-success` | Sukses / online |
| `kv-pill-danger` | Gagal / offline |
| `kv-pill-warning` | Peringatan |
| `kv-pill-info` | Info / netral biru |
| `kv-pill-muted` | Netral abu / tak aktif |
| `kv-pill-critical` / `kv-pill-major` / `kv-pill-minor` | Severity alarm |

### Form & teks
| Kelas | Fungsi |
|-------|--------|
| `kv-input` | Input dark (border tipis, fokus cyan). `<select>`/`<textarea>` sudah di-style global |
| `kv-link` / `kv-link-muted` | Tautan teks (cyan / slate) |
| `kv-alert-success` / `kv-alert-danger` | Banner flash (lihat pola flash di §5) |

### Kartu filter (pola wajib untuk halaman dengan filter)
**Selalu** pakai komponen `Components/Shell/FilterCard.vue` agar bentuk kartu filter seragam di
semua halaman (Alarms, Audit Logs, ONU Monitoring, Reports, dst). Jangan bikin kartu filter ad-hoc.

Bentuk standar = **toolbar satu baris**: input cari `lg:flex-1` (melebar) + kontrol `kv-filter-control`
berukuran `w-full sm:w-auto` (HP menumpuk, desktop sejajar satu baris) dalam wadah
`flex flex-wrap items-center gap-2`. **Tanpa label** di atas tiap kontrol — pakai opsi pertama yang
self-describing (mis. `Semua Severity`, `Semua OLT`) atau atribut `title` untuk input tanggal, supaya
ringkas tapi tetap jelas.

```vue
<FilterCard title="Filter" :icon="Filter">          <!-- filter server-side (router.get) -->
  <form class="flex flex-wrap items-center gap-2" @submit.prevent="applyFilters">
    <div class="relative w-full lg:flex-1 lg:min-w-[16rem]">
      <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" />
      <input v-model="form.q" type="search" class="kv-filter-control !pl-9" placeholder="Cari…">
    </div>
    <select v-model="form.severity" class="kv-filter-control w-full sm:w-auto">
      <option value="all">Semua Severity</option> …
    </select>
    <button type="button" class="kv-filter-reset w-full sm:w-auto" :disabled="!hasFilters" @click="resetFilters">Reset</button>
    <button type="submit" class="kv-filter-apply w-full sm:w-auto">Terapkan</button>
  </form>
</FilterCard>

<FilterCard title="Filter ONU" subtitle="…" :icon="Search">   <!-- filter live/client-side -->
  <template #actions>
    <button v-if="hasFilter" class="kv-filter-reset" @click="clearFilters">Reset</button>
  </template>
  <div class="flex flex-wrap items-center gap-2"> input cari + select … </div>
</FilterCard>
```

| Kelas | Fungsi |
|-------|--------|
| `kv-filter` | Shell kartu (dipakai internal `FilterCard`; sama dengan panel tabel: `rounded-lg` kaca) |
| `kv-filter-head` / `kv-filter-body` | Header (ikon-tile + judul + slot `actions`) / body |
| `kv-filter-control` | Input/select seragam (tinggi 44px, fokus cyan, ada state `disabled`). Toolbar: `w-full sm:w-auto` |
| `kv-filter-reset` / `kv-filter-apply` | Tombol Reset (sekunder) / Terapkan (cyan) |
| `kv-filter-grid` / `kv-filter-label` / `kv-filter-actions` | Alternatif **grid berlabel** (kalau field sangat banyak); jarang dipakai sejak standar = toolbar |

Aturan: header dengan ikon-tile + judul; **filter live** → tombol Reset di slot `#actions`;
**filter server-side** → tombol Reset+Terapkan ikut di akhir baris toolbar. Kontrol selalu
`kv-filter-control` agar tinggi & gaya sama. Toolbar inline di header tabel (mis. PortOnus, PonPorts)
juga memakai `kv-filter-control`/`kv-filter-reset` agar seragam.

### Tabel responsif (pola wajib untuk data tabular)
| Kelas | Fungsi |
|-------|--------|
| `kv-table-desktop` | Pembungkus tabel — **hanya tampil ≥ md** (`hidden md:block`, scroll-x) |
| `kv-mobile-list` | Daftar kartu — **hanya tampil < md** (`md:hidden`) |
| `kv-mobile-card` / `-header` / `-title` / `-subtitle` | Struktur kartu mobile |
| `kv-mobile-fields` / `-field` / `-label` / `-value` | Pasangan label–nilai di kartu mobile |

### Efek landing (khusus `Welcome.vue`)
`kv-spotlight`, `kv-ring`, `kv-marquee`/`kv-marquee-wrap`, `kv-float`. **Jangan** dipakai di halaman
app biasa — ini untuk landing publik saja.

---

## 4. Anatomi shell (AuthenticatedLayout)

Semua halaman setelah login dibungkus `Layouts/AuthenticatedLayout.vue`. Yang sudah disediakan
layout (jangan dibuat ulang di halaman):

- **Latar** — root `kv-grid-stage kv-app-shell` + lapisan `kv-grid-pattern`, `kv-top-light`,
  `kv-ambient-glow`. `AuroraBackground`/`ParticleNetwork` **tidak** lagi dipasang di shell (hanya
  `Welcome`/`GuestLayout`).
- **Sidebar kiri** — logo, menu berkelompok `navGroups` (label kelompok saat lebar, garis pemisah
  saat diciutkan; kelompok kosong disembunyikan), collapse (persist di `localStorage`
  `kv-sidebar-collapsed`), drawer di mobile, `SidebarConstellation` + `SystemInfoPanel`
  (desktop-only, `v-if="isDesktop"` — di HP tak di-mount). Di HP dasar drawer memuat kartu akun,
  `ThemeSegmented` (pilih tema), Profil, Keluar.
- **Header atas (desktop)** — trigger search (⌘K), `LanguageSwitcher`, `NotificationBell`, `UserMenu`
  (berisi pilihan tema Gelap/Terang/Sistem).
- **Top bar mobile** — tombol menu, logo, search, `LanguageSwitcher`, bell.
- **Slot `#header`** — header per-halaman (judul + tombol aksi). Ikut scroll.
- **Banner demo** — otomatis muncul bila `auth.can.is_demo`.
- **`<main>`** — hanya `<Transition name="page">` di dalam `.kv-app-scroll`; tak ada latar tambahan.
- **`FlashMessages`** dan `GlobalSearch` dipasang sekali oleh layout.
- **Footer** — copyright/atribusi pemilik (dari `branding`, bukan Settings), diam di dasar layar
  (desktop) / di akhir halaman (HP).

**Desktop (≥ lg) = kerangka setinggi layar** (sejak 25 Sep 2026): `.kv-app-shell` = `100vh` + `overflow: hidden`, sidebar/header/footer diam, dan hanya
`.kv-app-scroll` (atribut `scroll-region` → preserveScroll Inertia tetap jalan) yang menggulir; menu
sidebar yang panjang menggulir di dalam `<nav>`. **HP** tetap menggulir di level dokumen dengan top
bar sticky. Akibatnya: elemen `sticky` di halaman kini berpatokan ke area gulir konten, jadi
offsetnya kecil (`top-3`/`top-6`), **bukan** tinggi header 72 px lagi; dan screenshot full-page
harus membuka kerangka dulu — `scripts/snapshot.mjs` menyuntik CSS
`.kv-app-shell{height:auto;overflow:visible} .kv-app-scroll{overflow:visible}` sebelum memotret.

---

## 5. Pola halaman standar (template acuan)

Struktur kanonik sebuah Page (lihat [`SmartOlt/Index.vue`](../../resources/js/Pages/SmartOlt/Index.vue)):

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Cable, Plus } from '@lucide/vue';
import { computed } from 'vue';

defineProps({ items: { type: Array, required: true } });
const page = usePage();
const flash = computed(() => page.props.flash ?? {});
</script>

<template>
  <Head title="Judul Halaman" />
  <AuthenticatedLayout>
    <!-- Header halaman: judul kiri, aksi kanan -->
    <template #header>
      <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <h2 class="text-lg font-semibold leading-tight text-white sm:text-xl">Judul Halaman</h2>
        <Link v-if="$page.props.auth.can.manage_olt" :href="route('fitur.create')">
          <PrimaryButton><Plus class="mr-2 h-4 w-4" /> Tambah</PrimaryButton>
        </Link>
      </div>
    </template>

    <!-- Konten -->
    <div class="min-h-[60vh] pt-5 pb-16 sm:pt-8">
      <div class="w-full px-4 sm:px-6 lg:px-8">
        <!-- Flash -->
        <div v-if="flash.success" class="kv-alert-success">
          <span class="h-2 w-2 flex-shrink-0 rounded-full bg-emerald-400"></span>{{ flash.success }}
        </div>
        <div v-if="flash.error" class="kv-alert-danger">
          <span class="h-2 w-2 flex-shrink-0 rounded-full bg-red-400"></span>{{ flash.error }}
        </div>

        <!-- Kartu utama -->
        <div class="kv-glass-panel">
          <div class="kv-glass-header">
            <span class="kv-circle-sky !h-10 !w-10"><Cable class="h-5 w-5" /></span>
            <div>
              <h3 class="text-base font-semibold text-white">Judul Kartu</h3>
              <p class="text-xs text-slate-400">Deskripsi singkat</p>
            </div>
          </div>
          <!-- ...isi: tabel responsif / form / empty state... -->
        </div>
      </div>
    </div>
  </AuthenticatedLayout>
</template>
```

**Empty state** (pola `Index.vue`): lingkaran ikon redup + judul + 1 kalimat + (opsional) tombol
aksi — bukan tabel kosong.

**Tabel data**: WAJIB dua mode — `kv-table-desktop` (tabel) **dan** `kv-mobile-list` (kartu).
Jangan kirim tabel lebar tanpa varian mobile.

**Modal/konfirmasi**: aksi destruktif pakai `useConfirm` + `<ConfirmModal>` (varian `danger` —
bawaan —, `warning`, `info`; contoh: matikan port = `danger`, nyalakan = `warning`). **Dilarang
`window.confirm()`**. Modal kustom pakai `Components/Modal.vue` (sudah dark glass, `rounded-2xl`) dan
**render terus** dengan `:show` yang berubah — jangan `v-if` + `:show="true"` (dialog tak pernah
terbuka, lihat [13](13-troubleshooting-maintenance.md)). Prop `max-width` menerima
`sm|md|lg|xl|2xl|3xl|4xl|5xl` — pakai `md`/`lg` untuk form pendek, `4xl` ke atas hanya untuk isi
dua kolom (mis. modal "Kelola ONU" di halaman ODP) supaya nama panjang tak perlu dipotong.

**Teks yang bisa panjang** (nama pelanggan/ONU pola `#<id> <nama> (ODP <x>)`, sering 40+ karakter):
jangan refleks `truncate`. Di ruang lega pakai `break-words` + `leading-snug`; di ruang sempit
(popup peta) `line-clamp-2 break-words`. Selalu sertakan atribut `title` berisi teks penuh, dan beri
elemen sebelah (ikon/tombol) `shrink-0` + baris `items-start` supaya tetap rapi saat teks jadi 2
baris. `truncate` tetap wajar untuk nilai berpanjang seragam (interface, serial).

---

## 6. Aturan wajib menambah halaman/komponen UI

Checklist ini **mengikat** untuk setiap PR yang menyentuh tampilan dashboard:

1. **Selalu pakai `AuthenticatedLayout`** untuk halaman pasca-login (kecuali auth → `GuestLayout`).
   Judul tab via `<Head title="…">`. Letakkan judul + aksi di slot `#header`.
2. **Pakai token & kelas `kv-*` dulu.** Jangan menulis ulang permukaan kaca / pill / lingkaran ikon
   dengan Tailwind mentah bila sudah ada kelasnya. Butuh varian baru? **Tambah kelas di `app.css`**,
   jangan sebar gaya ad-hoc di banyak file.
3. **Patuhi palet.** Aksi primer = gradient cyan→sky (`PrimaryButton`). Destruktif = red
   (`DangerButton`/`IconButton variant="danger"`). Status pakai warna semantik di §2. **Jangan**
   memperkenalkan warna brand/hue baru tanpa alasan.
4. **Permukaan tetap kaca semi-transparan.** Tidak ada background putih/solid full-bleed yang
   menutup latar grid shell. Konten duduk di `kv-glass-panel`/`kv-glass-card` (atau `kv-table-card`
   untuk tabel padat).
5. **Responsif & mobile-first.** Padding `px-4 sm:px-6 lg:px-8`. Data tabular = pasangan
   `kv-table-desktop` + `kv-mobile-list`. Target sentuh ≥ 44px (tombol sudah `min-h-11`).
6. **Pakai komponen yang ada**: `PrimaryButton/SecondaryButton/DangerButton`, `IconButton`
   (varian `default|primary|info|success|warning|danger`), `TextInput`/`InputLabel`/`InputError`,
   `Modal`/`ConfirmModal`, `Pagination`, `Dropdown`. Ikon **hanya** dari `@lucide/vue`.
7. **Izin di UI + backend.** Sembunyikan tombol via `auth.can.*` (`manage_users`, `manage_olt`,
   `manage_olt_inventory`, `add_olt`, `is_partner`, `is_demo`) atau prop izin per-OLT dari controller
   (mis. `can_set_admin_state`), tapi **backend tetap menegakkan** (lihat
   [11](11-keamanan-rbac-audit.md)). Pertimbangkan mode demo (read-only).
8. **String UI dwibahasa ID/EN** (judul, tombol, flash, empty state, `title`/`aria-label`) lewat
   `$t('ns.kunci')`; kunci wajib ada di `resources/js/lang/id.json` **dan** `en.json` (bahasa Indonesia
   = default). Label enum backend (jenis/status alarm) diterjemahkan di frontend by-key (`lib/alarm.js`).
9. **Aksesibilitas dasar.** Tombol ikon punya `title` + `aria-label`. Pakai `focus:ring-*` bawaan
   komponen. Jangan andalkan warna saja untuk status (sertakan teks/ikon).
10. **Menu sidebar** (bila halaman top-level): tambah item ke kelompok yang tepat di `navGroups`
    [`AuthenticatedLayout.vue`](../../resources/js/Layouts/AuthenticatedLayout.vue) dengan `name`
    (`t('nav.…')`), `icon` Lucide, `href: route(...)`, `match` (+ `except` bila perlu), dan gerbang
    `can.value.*` (pola `cond && { … }` lalu `.filter(Boolean)`) untuk item terbatas.
11. **Hati-hati gotcha Vite manifest.** Library banyak-`dynamic-import` (tsParticles, xterm) yang
    dipakai di sebuah Page **harus** dibungkus `defineAsyncComponent`, lalu rebuild (lihat
    [12](12-frontend.md) §gotcha & [13](13-troubleshooting-maintenance.md)).
12. **Hormati gerak.** Animasi baru harus mati di `prefers-reduced-motion` (pola sudah ada di
    `app.css`).
13. **Bila menambah aset font/warna global** → lewat `tailwind.config.js` / `app.css`, bukan style
    inline berulang.

### Yang harus dihindari
- ❌ `text-white` di atas tombol/gradien aksen (pakai `text-onaccent`). ❌ `bg-slate-950` sebagai
  permukaan (tetap gelap di tema terang; pakai `bg-canvas-3/<α>`). ❌ Heks literal untuk warna
  teks/grid di JS atau `<style>` (pakai token). ❌ Warna aksen baru di luar palet.
- ❌ Tailwind mentah meniru `kv-glass-card`/pill padahal kelasnya ada.
- ❌ Tabel desktop tanpa varian mobile. ❌ Ikon dari pustaka selain Lucide.
- ❌ Tombol aksi tanpa cek `auth.can` (atau backend tanpa enforcement).
- ❌ Membungkus halaman dengan `h-screen`/`overflow-hidden` (bentrok dgn shell).

---

## 7. Checklist cepat pre-commit (UI)

- [ ] Halaman pakai `AuthenticatedLayout` + `<Head>` + slot `#header`.
- [ ] Permukaan pakai `kv-glass-*`; warna sesuai palet/semantik.
- [ ] Dicek di **tema gelap dan terang** (menu pengguna → Tampilan), desktop & HP.
- [ ] Data tabular punya mode desktop **dan** mobile.
- [ ] Tombol/aksi pakai komponen standar; ikon Lucide; destruktif → konfirmasi.
- [ ] Tombol di-gerbang `auth.can.*` + backend menegakkan + cek demo mode.
- [ ] String lewat `$t()` dengan kunci di `id.json` **dan** `en.json`; ikon punya `title`/`aria-label`.
- [ ] Tidak ada `window.confirm()`; konfirmasi lewat `useConfirm`.
- [ ] Item sidebar ditambah (bila top-level) ke `navGroups` dengan `match` benar.
- [ ] Rute baru → `php artisan route:cache` sebelum build (Ziggy `route()` gagal diam tanpanya).
- [ ] `npm run build` sukses, tidak ada Page 500 (manifest); cek di mobile & desktop.
- [ ] Checklist [`UI_DESIGN_SYSTEM.md`](../../UI_DESIGN_SYSTEM.md) §5 terpenuhi sebelum commit frontend.
- [ ] Entri `WORKLOG.md` ditambah; selesai → `/done`.

## Selanjutnya
- [12 — Frontend](12-frontend.md) — mekanisme Inertia/Vite/komponen.
- [14 — Panduan Menambah Fitur](14-panduan-tambah-fitur.md) — Resep 1 (halaman + route).
- [11 — Keamanan, RBAC & Audit](11-keamanan-rbac-audit.md) — gerbang izin & demo mode.
