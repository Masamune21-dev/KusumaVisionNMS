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

Tema bernuansa **"dark glass cyber/NOC"** — pas untuk dashboard monitoring jaringan:

- **Base gelap.** `bg-slate-950` (`#020617`) di `<body>`, area konten pakai `kv-grid-bg`
  (`#060f1c` + gradient vertikal). `color-scheme: dark` aktif global.
- **Aksen utama cyan→sky.** Gradient `from-cyan-500 to-sky-600`, glow `shadow-cyan-500/30`.
  Cyan = warna brand (logo, nav aktif, fokus, link, progress bar Inertia `#06b6d4`).
- **Permukaan kaca (glassmorphism).** Kartu/panel = `bg-slate-900/40` + `border-white/10` +
  `backdrop-blur-xl` + soft shadow biru. Selalu **semi-transparan** supaya latar (aurora + jaring
  partikel) menembus.
- **Latar hidup.** `AuroraBackground` + `ParticleNetwork` (`id="kv-app-particles"`, fixed) ada di
  belakang **semua** halaman app. Jangan menutupnya dengan background solid full-bleed.
- **Teks.** Judul `text-white`, body `text-slate-100/200`, sekunder `text-slate-400`, redup
  `text-slate-500`. Label kecil sering `uppercase tracking-wider`.
- **Sudut & ruang.** Kartu `rounded-2xl`, tombol/elemen kecil `rounded-lg`/`rounded-xl`, badge
  `rounded-md`. Padding kartu `p-5`, padding konten halaman `px-4 sm:px-6 lg:px-8`.
- **Gerak halus.** `transition` 150–200ms, transisi antar-halaman `name="page"` (fade + geser),
  hormati `prefers-reduced-motion` (sudah di-handle di `app.css`).
- **Font.** `Figtree` (fallback sans), di-set di `tailwind.config.js`.

> **Mental model:** setiap layar = kartu kaca melayang di atas latar gelap berpartikel, dengan satu
> warna aksen cyan/sky untuk aksi & status netral, ditambah warna semantik untuk status lain.

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

(Heks di atas dipakai konsisten di `StatCard.vue` `accentHex` & sublabel dot — pakai nilai yang
sama bila perlu warna inline untuk chart/dot.)

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
| `kv-grid-bg` | Latar gelap + gradient (sudah dipasang `<main>` oleh layout) |

### Permukaan (kartu/panel)
| Kelas | Fungsi |
|-------|--------|
| `kv-glass-panel` | Panel kaca besar (tabel/section), `overflow-hidden rounded-2xl` |
| `kv-glass-card` | Kartu kaca dengan padding `p-5` (stat/widget) |
| `kv-glass-hover` | Tambahan: hover terangkat + glow cyan (gabungkan dgn card/panel) |
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
`kv-filter-control` agar tinggi & gaya sama. Toolbar inline di header tabel (mis. PortOnus, GponPorts)
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

- **Sidebar kiri** — logo, daftar `navLinks`, collapse (persist di `localStorage`
  `kv-sidebar-collapsed`), drawer di mobile, `SidebarConstellation` + `SystemInfoPanel`
  (desktop-only, `v-if="isDesktop"` — di HP tak di-mount).
- **Header atas (desktop)** — trigger search (⌘K), `NotificationBell`, `UserMenu`.
- **Top bar mobile** — tombol menu, logo, search, bell.
- **Slot `#header`** — header per-halaman (judul + tombol aksi). Ikut scroll.
- **Banner demo** — otomatis muncul bila `auth.can.is_demo`.
- **`<main>`** — `kv-grid-bg` + `AuroraBackground` + `ParticleNetwork` + transisi `page`.
- **Footer** — copyright/atribusi pemilik (dari `branding`, bukan Settings), ikut alur di
  dasar halaman.

Scroll terjadi di **level dokumen** (bukan container dalam) supaya screenshot full-page merekam
halaman utuh: sidebar desktop ikut alur setinggi konten (blok logo+nav sticky-top, panel sistem
sticky-bottom), header desktop & top bar mobile sticky. **Jangan** bikin halaman jadi
`h-screen`/`overflow-hidden`, dan hindari menambah elemen `fixed`/sticky-bottom di kolom konten
(dirender nempel viewport di tengah gambar oleh tool capture full-page).

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

**Modal/konfirmasi**: aksi destruktif pakai `useConfirm` + `<ConfirmModal>`; modal kustom pakai
`Components/Modal.vue` (sudah dark glass, `rounded-2xl`).

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
   menutup aurora + jaring partikel. Konten duduk di `kv-glass-panel`/`kv-glass-card`.
5. **Responsif & mobile-first.** Padding `px-4 sm:px-6 lg:px-8`. Data tabular = pasangan
   `kv-table-desktop` + `kv-mobile-list`. Target sentuh ≥ 44px (tombol sudah `min-h-11`).
6. **Pakai komponen yang ada**: `PrimaryButton/SecondaryButton/DangerButton`, `IconButton`
   (varian `default|primary|danger|success|warning`), `TextInput`/`InputLabel`/`InputError`,
   `Modal`/`ConfirmModal`, `Pagination`, `Dropdown`. Ikon **hanya** dari `@lucide/vue`.
7. **Izin di UI + backend.** Sembunyikan tombol via `auth.can.*` (`manage_users`, `manage_olt`,
   `is_demo`), tapi **backend tetap menegakkan** (lihat [11](11-keamanan-rbac-audit.md)). Pertimbangkan
   mode demo (read-only).
8. **String UI Bahasa Indonesia** (judul, tombol, flash, empty state, `title`/`aria-label`).
9. **Aksesibilitas dasar.** Tombol ikon punya `title` + `aria-label`. Pakai `focus:ring-*` bawaan
   komponen. Jangan andalkan warna saja untuk status (sertakan teks/ikon).
10. **Menu sidebar** (bila halaman top-level): tambah item di array `navLinks`
    [`AuthenticatedLayout.vue`](../../resources/js/Layouts/AuthenticatedLayout.vue) dengan `icon`
    Lucide, `href: route(...)`, `match` (+ `except` bila perlu), dan gerbang `can` untuk item admin.
11. **Hati-hati gotcha Vite manifest.** Library banyak-`dynamic-import` (tsParticles, xterm) yang
    dipakai di sebuah Page **harus** dibungkus `defineAsyncComponent`, lalu rebuild (lihat
    [12](12-frontend.md) §gotcha & [13](13-troubleshooting-maintenance.md)).
12. **Hormati gerak.** Animasi baru harus mati di `prefers-reduced-motion` (pola sudah ada di
    `app.css`).
13. **Bila menambah aset font/warna global** → lewat `tailwind.config.js` / `app.css`, bukan style
    inline berulang.

### Yang harus dihindari
- ❌ Tema terang / kartu putih solid. ❌ Warna aksen baru di luar palet.
- ❌ Tailwind mentah meniru `kv-glass-card`/pill padahal kelasnya ada.
- ❌ Tabel desktop tanpa varian mobile. ❌ Ikon dari pustaka selain Lucide.
- ❌ Tombol aksi tanpa cek `auth.can` (atau backend tanpa enforcement).
- ❌ Membungkus halaman dengan `h-screen`/`overflow-hidden` (bentrok dgn shell).

---

## 7. Checklist cepat pre-commit (UI)

- [ ] Halaman pakai `AuthenticatedLayout` + `<Head>` + slot `#header`.
- [ ] Permukaan pakai `kv-glass-*`; warna sesuai palet/semantik.
- [ ] Data tabular punya mode desktop **dan** mobile.
- [ ] Tombol/aksi pakai komponen standar; ikon Lucide; destruktif → konfirmasi.
- [ ] Tombol di-gerbang `auth.can.*` + backend menegakkan + cek demo mode.
- [ ] String Indonesia; ikon punya `title`/`aria-label`.
- [ ] Item sidebar ditambah (bila top-level) dengan `match` benar.
- [ ] `npm run build` sukses, tidak ada Page 500 (manifest); cek di mobile & desktop.
- [ ] Entri `WORKLOG.md` ditambah; selesai → `/done`.

## Selanjutnya
- [12 — Frontend](12-frontend.md) — mekanisme Inertia/Vite/komponen.
- [14 — Panduan Menambah Fitur](14-panduan-tambah-fitur.md) — Resep 1 (halaman + route).
- [11 — Keamanan, RBAC & Audit](11-keamanan-rbac-audit.md) — gerbang izin & demo mode.
