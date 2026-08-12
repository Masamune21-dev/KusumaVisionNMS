// Helper warna pin ODP di peta.
//
// Palet-nya sendiri TIDAK didefinisikan di sini — dikirim server dari App\Support\OdpColors
// (prop `odp_color_palette`) supaya web, API, dan aplikasi Android memakai daftar yang sama.
// Yang tinggal di klien hanya nilai bawaan + hitungan kontras.

/** Warna pin ODP bila kolom `color` masih null (harus sama dengan OdpColors::DEFAULT). */
export const DEFAULT_ODP_COLOR = '#f59e0b';

/** Warna efektif sebuah ODP (null/invalid → default). */
export function odpColor(odp) {
    const hex = typeof odp?.color === 'string' ? odp.color.trim() : '';

    return /^#[0-9a-fA-F]{6}$/.test(hex) ? hex : DEFAULT_ODP_COLOR;
}

/**
 * Warna teks yang terbaca di atas `hex` (badge jumlah ONU di pin ODP) — palet punya warna
 * terang (#e2e8f0) maupun gelap (#a16207), jadi teksnya tak bisa dipatok satu warna.
 */
export function textOn(hex) {
    const value = /^#[0-9a-fA-F]{6}$/.test(hex ?? '') ? hex : DEFAULT_ODP_COLOR;
    const [r, g, b] = [1, 3, 5].map((i) => parseInt(value.slice(i, i + 2), 16) / 255);
    // Luminansi relatif (WCAG) — cukup sebagai ambang terang/gelap.
    const channel = (c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4);
    const luminance = 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);

    return luminance > 0.45 ? '#0f172a' : '#ffffff';
}
