/**
 * Tema antarmuka: gelap, terang, atau ikut setelan sistem.
 *
 * Warna aplikasi ini seluruhnya CSS custom property yang dideklarasikan per
 * tema di resources/css/app.css. Mengganti tema = mengganti atribut
 * `data-theme` di <html>; tidak ada kelas yang perlu ditukar dan tidak ada
 * stylesheet yang perlu dimuat ulang.
 *
 * State-nya `ref` di level modul supaya
 * seluruh komponen berbagi satu nilai tanpa perlu store terpisah, dan supaya
 * grafik cukup `watch(theme)` untuk ikut berganti warna.
 *
 * Nilai awal dibaca dari DOM, BUKAN dari localStorage: server sudah memutuskan
 * tema pada render pertama (App\Support\Theme). Membacanya ulang dari
 * penyimpanan lokal hanya akan menimbulkan kedipan kedua kalau keduanya beda.
 */
import { computed, ref } from 'vue';
import axios from 'axios';
import { usePage } from '@inertiajs/vue3';

export const THEMES = ['dark', 'light', 'system'];

const COOKIE = 'kv_theme';
const COOKIE_MAX_AGE = 60 * 60 * 24 * 365;
const STORAGE_KEY = 'kv-nms-theme';

const hasDom = typeof document !== 'undefined';

const systemQuery =
    hasDom && typeof window.matchMedia === 'function'
        ? window.matchMedia('(prefers-color-scheme: light)')
        : null;

const systemTheme = () => (systemQuery?.matches ? 'light' : 'dark');

const resolve = (pref) => (pref === 'system' ? systemTheme() : pref);

/** Pilihan pengguna: 'dark' | 'light' | 'system'. */
const preference = ref(readInitialPreference());

/** Tema yang sedang berlaku: 'dark' | 'light'. Inilah yang dipakai grafik. */
const theme = ref(hasDom ? document.documentElement.dataset.theme || resolve(preference.value) : 'dark');

function readInitialPreference() {
    if (!hasDom) return 'dark';

    // Prop Inertia adalah sumber yang sama dengan yang dipakai server saat
    // merender atribut data-theme, jadi keduanya tidak akan bertentangan.
    const shared = usePage()?.props?.theme;
    if (THEMES.includes(shared)) return shared;

    try {
        const stored = window.localStorage.getItem(STORAGE_KEY);
        if (THEMES.includes(stored)) return stored;
    } catch (e) {
        // Mode penyamaran / penyimpanan diblokir — bukan alasan gagal.
    }

    return document.documentElement.dataset.theme === 'light' ? 'light' : 'dark';
}

function paint(next) {
    if (!hasDom) return;

    document.documentElement.setAttribute('data-theme', next);
    document.documentElement.style.colorScheme = next;
    theme.value = next;
}

function remember(pref) {
    const secure = hasDom && window.location.protocol === 'https:' ? '; Secure' : '';
    if (hasDom) {
        document.cookie = `${COOKIE}=${pref}; Path=/; Max-Age=${COOKIE_MAX_AGE}; SameSite=Lax${secure}`;
    }

    try {
        window.localStorage.setItem(STORAGE_KEY, pref);
    } catch (e) {
        // Cookie di atas sudah cukup untuk render server; ini hanya cadangan.
    }
}

/**
 * Ganti tema.
 *
 * Urutannya disengaja: gambar dulu (pengguna melihat hasilnya seketika),
 * simpan ke cookie & localStorage (muat ulang penuh tetap benar walau
 * permintaan di bawah gagal), baru kirim ke server.
 */
export function setTheme(pref) {
    if (!THEMES.includes(pref)) return;

    preference.value = pref;
    paint(resolve(pref));
    remember(pref);

    // Tamu (Welcome, login) tidak punya baris pengguna untuk disimpani;
    // cookie sudah cukup baginya.
    if (!usePage()?.props?.auth?.user) return;

    // Permintaan biasa (axios), BUKAN kunjungan Inertia. Kunjungan + back()
    // memuat ulang halaman — dan itu menghapus konten yang hanya tampil sekali
    // (token API baru di Pengaturan), menutup modal yang terbuka, serta membuang
    // isian form yang belum disimpan. Server menjawab 204; tema sudah dilukis
    // di atas, jadi tidak ada yang perlu dirender ulang. Gagal simpan tidak
    // fatal: cookie sudah menyimpan pilihannya untuk perangkat ini.
    axios.patch(route('profile.theme'), { theme: pref }).catch(() => {});
}

/** Berputar dark -> light -> system -> dark. */
export function cycleTheme() {
    setTheme(THEMES[(THEMES.indexOf(preference.value) + 1) % THEMES.length]);
}

// Saat pilihannya 'system', ikuti perubahan setelan OS tanpa perlu muat ulang.
systemQuery?.addEventListener?.('change', () => {
    if (preference.value === 'system') paint(systemTheme());
});

export function useTheme() {
    return {
        theme,
        preference,
        isDark: computed(() => theme.value === 'dark'),
        isLight: computed(() => theme.value === 'light'),
        setTheme,
        cycleTheme,
        THEMES,
    };
}

/**
 * Baca token warna tema sebagai warna CSS yang siap pakai.
 *
 * Dibutuhkan oleh yang menggambar di luar jangkauan Tailwind — ApexCharts
 * menerima warna sebagai string JavaScript, bukan kelas. Variabelnya berisi
 * channel RGB telanjang ("148 163 184"), jadi harus dibungkus di sini.
 *
 * Selalu sertakan `fallback`: saat dipanggil sebelum stylesheet terpasang,
 * nilainya kosong.
 */
/**
 * Warna token sebagai `rgb(r g b)` — bentuk untuk konsumen CSS (style inline,
 * kanvas, apa pun yang menyerahkannya langsung ke browser).
 *
 * JANGAN dipakai untuk ApexCharts: parser warnanya menolak sintaks ini dan
 * membuang seluruh opsi yang memuatnya tanpa bersuara. Pakai themeHex().
 */
export function themeRgb(name, fallback) {
    if (!hasDom) return fallback;

    const value = getComputedStyle(document.documentElement).getPropertyValue(name).trim();

    return value ? `rgb(${value})` : fallback;
}

/**
 * Warna token sebagai heks `#rrggbb`.
 *
 * Ada satu konsumen yang tidak menerima bentuk `rgb(8 145 178)` milik themeRgb():
 * ApexCharts. Util warnanya (shadeColor/hexToRgba, dipakai untuk irisan pie &
 * donut beserta keadaan hover-nya) mem-parse heks saja; diberi sintaks rgb
 * ruang-terpisah ia gagal TANPA melempar error dan seluruh grafik jatuh ke
 * abu-abu bawaannya. Kartu Distribusi Paket sempat tampil kelabu seluruhnya
 * karena ini, sementara legendanya — yang memakai nilai yang sama persis
 * langsung sebagai CSS — berwarna normal.
 *
 * Tetap dibaca dari token, jadi ia ikut berganti tema; yang berubah hanya
 * bentuk penulisannya.
 */
export function themeHex(name, fallback) {
    if (!hasDom) return fallback;

    const value = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    if (!value) return fallback;

    const channels = value.split(/[\s,/]+/).slice(0, 3).map(Number);
    if (channels.length < 3 || channels.some((n) => !Number.isFinite(n))) return fallback;

    return '#'+channels
        .map((n) => Math.max(0, Math.min(255, Math.round(n))).toString(16).padStart(2, '0'))
        .join('');
}

/**
 * Warna token sebagai heks 8 digit `#rrggbbaa` — versi themeHex() yang membawa
 * opasitas, untuk konsumen yang sama (ApexCharts) dan alasan yang sama.
 */
export function themeHexA(name, alpha, fallback) {
    const hex = themeHex(name, null);
    if (!hex) return fallback;

    const a = Math.max(0, Math.min(1, Number(alpha)));

    return hex + Math.round(a * 255).toString(16).padStart(2, '0');
}

export function themeRgba(name, alpha, fallback) {
    if (!hasDom) return fallback;

    const value = getComputedStyle(document.documentElement).getPropertyValue(name).trim();

    return value ? `rgb(${value} / ${alpha})` : fallback;
}

/**
 * Warna dasar grafik (ApexCharts) untuk tema yang sedang berlaku.
 *
 * Panggil di DALAM `computed()` opsi grafik: fungsi ini membaca `theme.value`,
 * jadi opsi grafik ikut dihitung ulang saat tema berganti. Pasang juga
 * `:key="theme"` pada <VueApexCharts> — ApexCharts meng-cache sebagian opsi
 * (tooltip.theme, gradien isi) dan `updateOptions()` tidak selalu
 * menyegarkannya.
 *
 * Warna seri status (emerald/amber/red/sky 500) tidak perlu lewat sini: stop
 * 500/600 dipatok sama di kedua tema. Yang wajib lewat sini adalah warna
 * teks, garis grid, dan stop 300/400 yang di tema terang menggelap.
 */
export function chartTheme() {
    const mode = theme.value;

    return {
        mode,
        text: themeHex('--kv-slate-100', '#f1f5f9'),
        label: themeHex('--kv-slate-400', '#94a3b8'),
        muted: themeHex('--kv-slate-500', '#64748b'),
        grid: themeHexA('--kv-slate-700', 0.5, '#33415580'),
        border: themeHex('--kv-slate-700', '#334155'),
        tooltip: mode === 'light' ? 'light' : 'dark',
    };
}

/** Heks token warna untuk konsumen JS (ApexCharts, Leaflet, kanvas), ikut tema. */
export function tokenHex(name, fallback) {
    // Sentuh theme.value supaya pemanggil di dalam computed() ikut reaktif.
    void theme.value;

    return themeHex(`--kv-${name}`, fallback);
}
