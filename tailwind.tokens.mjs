/**
 * DAFTAR NAMA token warna — sumber kebenaran tunggal.
 *
 * Berkas ini hanya menyebut token APA SAJA yang ada; NILAINYA per tema
 * ditetapkan di resources/css/app.css pada blok `[data-theme="..."]`.
 * Pemisahan itu disengaja: nama adalah kontrak, nilai adalah temanya.
 *
 * Dibaca oleh tailwind.config.js (untuk membangun palet) dan oleh
 * scripts/theme-tokens.mjs (untuk menjaga tiap nama punya deklarasi di
 * SETIAP blok tema). Sengaja tanpa impor apa pun supaya bisa dimuat Node
 * polos maupun Vite.
 */

export const RAMPS = [
    'slate', 'gray', 'zinc', 'neutral', 'stone',
    'red', 'orange', 'amber', 'yellow', 'lime', 'green', 'emerald',
    'teal', 'cyan', 'sky', 'blue', 'indigo', 'violet', 'purple',
    'fuchsia', 'pink', 'rose',
];

export const STOPS = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950];

/** Warna tunggal di luar ramp. */
export const SINGLES = [
    'white',
    'black',
    /* Putih yang TIDAK pernah berbalik: knob toggle, kartu logo, pratinjau
       invoice — permukaan yang memang kertas. */
    'paper',
    /* Teks di atas gradien & tombol aksen; latarnya tidak berganti tema. */
    'onaccent',
];

/** Permukaan opak untuk kolom tabel menempel — tidak boleh semi-transparan. */
export const SURFACES = ['surface-1', 'surface-2', 'surface-3', 'surface-hover'];

/** Kanvas halaman dan lapisan dekorasinya. */
export const CANVASES = ['canvas', 'canvas-2', 'canvas-3'];

/** Seluruh nama variabel `--kv-*` yang harus punya deklarasi di app.css. */
export function tokenNames() {
    return [
        ...RAMPS.flatMap((ramp) => STOPS.map((stop) => `${ramp}-${stop}`)),
        ...SINGLES,
        ...SURFACES,
        ...CANVASES,
    ];
}
