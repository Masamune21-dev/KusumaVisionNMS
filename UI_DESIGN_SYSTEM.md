# PEDOMAN KONSISTENSI UI & DESIGN SYSTEM
**KusumaVision (BMKV) — Standar Antarmuka Pengguna**

Dokumen ini adalah aturan baku yang **WAJIB dipatuhi** oleh seluruh pengembang saat membuat atau memodifikasi komponen antarmuka pengguna (Vue 3, Inertia, Tailwind CSS) di lingkungan **KusumaVision**.

---

## 1. ATURAN BAKU MODAL KONFIRMASI (CONFIRM MODAL)

### ⛔ LARANGAN KERAS:
**JANGAN PERNAH** menggunakan fungsi bawaan browser seperti `window.confirm()` atau `if (confirm(...))` di seluruh halaman aplikasi! Dialog browser merusak estetika dark-glass dan tidak ramah pengguna mobile.

### ✅ STANDAR WAJIB:
Gunakan komponen `@/Components/Shell/ConfirmModal.vue` untuk seluruh tindakan destruktif, pembatalan, pembersihan log, atau pengiriman massal.

```vue
<script setup>
import { ref } from 'vue';
import ConfirmModal from '@/Components/Shell/ConfirmModal.vue';

const confirmState = ref({
    show: false,
    title: '',
    message: '',
    confirmText: 'Ya, Lanjutkan',
    variant: 'danger', // 'danger' | 'warning' | 'info'
    action: null,
});

// Contoh 1: Aksi Hapus / Destruktif
const handleDelete = (item) => {
    confirmState.value = {
        show: true,
        title: 'Hapus Data',
        message: `Apakah Anda yakin ingin menghapus "${item.name}" secara permanen?`,
        confirmText: 'Ya, Hapus',
        variant: 'danger',
        action: () => {
            router.delete(route('items.destroy', item.id), {
                onSuccess: () => confirmState.value.show = false,
            });
        },
    };
};

// Contoh 2: Aksi Pembatalan / Warning
const handleRevert = (inv) => {
    confirmState.value = {
        show: true,
        title: 'Batalkan Status Lunas',
        message: `Kembalikan status invoice #${inv.invoice_no} ke Belum Lunas?`,
        confirmText: 'Ya, Batalkan',
        variant: 'warning',
        action: () => {
            router.put(route('invoices.revert', inv.id), {
                onSuccess: () => confirmState.value.show = false,
            });
        },
    };
};

// Contoh 3: Aksi Kirim Notifikasi / Info
const handleSendWa = (user) => {
    confirmState.value = {
        show: true,
        title: 'Kirim Notifikasi WhatsApp',
        message: `Kirim tagihan ke nomor WhatsApp ${user.phone}?`,
        confirmText: 'Kirim Sekarang',
        variant: 'info',
        action: () => {
            router.post(route('wa.send', user.id), {
                onSuccess: () => confirmState.value.show = false,
            });
        },
    };
};
</script>

<template>
    <!-- Di bagian paling bawah template -->
    <ConfirmModal 
        :show="confirmState.show"
        :title="confirmState.title"
        :message="confirmState.message"
        :confirm-text="confirmState.confirmText"
        cancel-text="Batal"
        :variant="confirmState.variant"
        @cancel="confirmState.show = false"
        @confirm="confirmState.action"
    />
</template>
```

---

## 2. ATURAN BAKU TOAST & FLASH NOTIFICATIONS

### Desain & Perilaku:
- Notifikasi flash server (`$page.props.flash.success` / `$page.props.flash.error`) ditangani secara terpusat oleh shell `AuthenticatedLayout.vue`.
- Ditampilkan dalam bentuk **Floating Glass Card** di pojok kanan atas (`fixed top-5 right-5 z-50`).
- **Auto-Dismiss:** Menghilang otomatis setelah **4.5 detik**.
- **Manual Dismiss:** Tombol silang `X` untuk menutup seketika.
- **Pewarnaan:**
  - `success`: Border hijau neon (`border-emerald-500/40`), badge `BERHASIL`, background dark glass.
  - `error`: Border merah neon (`border-rose-500/40`), badge `PEMBERITAHUAN / GAGAL`, background dark glass.

---

## 3. PALET WARNA & DESIGN TOKENS (DARK GLASS CYAN)

| Token Nama | Kelas Tailwind / Nilai | Penggunaan |
| :--- | :--- | :--- |
| **Canvas Background** | `kv-grid-stage` (`#0b1329`) | Latar belakang seluruh halaman |
| **Grid Pattern** | `kv-grid-pattern` (32px x 32px) | Grid mesh neon semi-transparan |
| **Top Light Beam** | `kv-top-light` & `kv-ambient-glow` | Efek spotlight ambient cyan di atas layar |
| **Glass Panel** | `kv-glass-panel` | Wadah tabel, card statistik, dan form |
| **Aksen Utama** | `from-cyan-500 to-sky-500` | Tombol CTA primer, header branding, dan link aktif |
| **Aksen Sukses / Lunas**| `emerald-400` / `bg-emerald-500/20` | Status Aktif, Lunas, Online, Rx Power Normal |
| **Aksen Peringatan** | `amber-400` / `bg-amber-500/20` | Status Unpaid, Jatuh Tempo, Rx Power Waspada |
| **Aksen Bahaya / Error** | `rose-400` / `bg-rose-500/20` | Status Terisolir, Offline, Rx Power Kritis, Tombol Hapus |

---

## 4. FORM CONTROLS & FILTER BAR

- Selalu gunakan kelas `kv-filter-control` untuk `<input>`, `<select>`, dan `<textarea>`.
- Tombol Filter & Submit Form:
  - Tombol Primer: `kv-filter-apply` atau `kv-btn-cyan`
  - Tombol Batal / Reset: `kv-filter-reset`
  - Tombol Destruktif: `kv-btn-danger` / `bg-rose-600`
- Tanggal dan Format Waktu:
  - Gunakan helper `@/utils/format`:
    - `formatDate(dateStr)` &rarr; `25 Agu 2026`
    - `formatDateTime(dateStr)` &rarr; `25 Agu 2026, 14:08 WIB`
    - `formatPeriod(month, year)` &rarr; `Agustus 2026`
    - `formatRupiah(number)` &rarr; `Rp 150.000`

---

## 5. CHECKLIST SEBELUM PENGEMBANGAN SELESAI

- [ ] Tidak ada dialog bawaan browser `alert()`, `confirm()`, atau `prompt()`.
- [ ] Semua tombol hapus/batal terhubung ke `ConfirmModal`.
- [ ] Pesan flash sukses/gagal dari controller muncul otomatis via Floating Toast.
- [ ] Tidak ada format tanggal ISO mentah `2026-08-25T...Z` yang terlihat oleh pengguna.
- [ ] Dropdown bulan menampilkan nama bulan bahasa Indonesia (`Januari` s/d `Desember`).
- [ ] Seluruh unit & feature test lulus 100% (`php artisan test`).
- [ ] Aset frontend berhasil di-compile tanpa error (`npm run build`).
