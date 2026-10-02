/**
 * Potongan opsi Chart.js yang dipakai bersama semua grafik NMS.
 *
 * Modul ini sengaja TIDAK mengimpor chart.js — ia hanya merakit objek opsi,
 * jadi aman diimpor statis oleh komponen mana pun. Chart.js sendiri dimuat
 * malas oleh Components/Charts/ChartCanvas.vue.
 *
 * Panggil di DALAM `computed()`: fungsi-fungsi ini membaca token tema, jadi
 * opsi ikut dihitung ulang (dan grafik ikut berganti warna) saat tema berganti.
 * Token slate berbalik di tema terang (slate-900 = putih, slate-100 = gelap),
 * jadi tooltip & label cukup memakai nama token yang sama di kedua tema.
 * Warna dibaca lewat tokenHex() (bukan themeHex()), karena hanya tokenHex()
 * yang menyentuh `theme.value` — tanpa itu computed pemanggil tidak reaktif.
 */
import { displayTzOffsetMs } from '@/lib/datetime';
import { tokenHex } from '@/lib/theme';

/** '#rrggbb' + opasitas → '#rrggbbaa' (Chart.js menerima heks 8 digit). */
export function withAlpha(hex, alpha) {
    const base = String(hex).slice(0, 7);
    const a = Math.max(0, Math.min(1, Number(alpha)));

    return base + Math.round(a * 255).toString(16).padStart(2, '0');
}

/**
 * Isi gradien vertikal di bawah garis (pengganti `fill.type: 'gradient'`
 * ApexCharts). Dipakai sebagai `backgroundColor` dataset ber-`fill`.
 */
export function areaFill(hex, from = 0.35, to = 0) {
    return (context) => {
        const { ctx, chartArea } = context.chart;
        // Gambar pertama terjadi sebelum tata letak selesai — belum ada area.
        if (!chartArea) return withAlpha(hex, from);

        const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
        gradient.addColorStop(0, withAlpha(hex, from));
        gradient.addColorStop(1, withAlpha(hex, to));

        return gradient;
    };
}

/** Tampilan tooltip yang ikut tema. `callbacks` diteruskan apa adanya. */
export function tooltip(callbacks = {}) {
    return {
        backgroundColor: withAlpha(tokenHex('slate-900', '#0f172a'), 0.96),
        borderColor: withAlpha(tokenHex('white', '#ffffff'), 0.1),
        borderWidth: 1,
        titleColor: tokenHex('slate-100', '#f1f5f9'),
        bodyColor: tokenHex('slate-300', '#cbd5e1'),
        titleFont: { weight: '600' },
        padding: 10,
        cornerRadius: 8,
        boxPadding: 4,
        usePointStyle: true,
        callbacks,
    };
}

/** Legenda kiri-atas berpenanda bulat (gaya legenda ApexCharts yang lama). */
export function legendTop() {
    return {
        display: true,
        position: 'top',
        align: 'start',
        labels: {
            color: tokenHex('slate-300', '#cbd5e1'),
            usePointStyle: true,
            pointStyle: 'circle',
            boxWidth: 8,
            boxHeight: 8,
            padding: 16,
        },
    };
}

/** Garis rambut grid/sumbu yang ikut tema. */
export function hairline(alpha = 0.05) {
    return withAlpha(tokenHex('white', '#ffffff'), alpha);
}

/** Sumbu X tanpa garis grid (kategori/waktu). */
export function axisX(ticks = {}) {
    return {
        grid: { display: false },
        border: { color: hairline() },
        ticks: { color: tokenHex('slate-500', '#64748b'), maxRotation: 0, autoSkipPadding: 12, ...ticks },
    };
}

/** Sumbu Y dengan garis grid putus-putus tipis. */
export function axisY(ticks = {}, extra = {}) {
    return {
        grid: { color: hairline() },
        border: { display: false, dash: [4, 4] },
        ticks: { color: tokenHex('slate-500', '#64748b'), ...ticks },
        ...extra,
    };
}

/** Kerangka bersama grafik garis/area: responsif, tanpa animasi, legenda mati. */
export function lineChartOptions({ legend = false, tooltipCallbacks = {}, scales = {}, plugins = {} } = {}) {
    return {
        responsive: true,
        maintainAspectRatio: false,
        animation: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: legend ? legendTop() : { display: false },
            tooltip: tooltip(tooltipCallbacks),
            ...plugins,
        },
        scales,
    };
}

const MINUTE = 60_000;
const HOUR = 60 * MINUTE;
const DAY = 24 * HOUR;
const STEPS = [15 * MINUTE, 30 * MINUTE, HOUR, 2 * HOUR, 3 * HOUR, 6 * HOUR, 12 * HOUR, DAY, 2 * DAY, 7 * DAY];

/**
 * Titik centang sumbu waktu di batas yang bulat MENURUT ZONA TAMPILAN
 * (00.00/06.00/12.00 WIB, tengah malam WIB), pengganti sumbu `datetime`
 * ApexCharts tanpa perlu adaptor tanggal Chart.js.
 *
 * @returns {{ step: number, values: number[] }}
 */
export function timeTicks(min, max, maxTicks = 6) {
    const span = max - min;
    if (!Number.isFinite(span) || span <= 0) return { step: HOUR, values: Number.isFinite(min) ? [min] : [] };

    const step = STEPS.find((s) => span / s <= maxTicks) ?? Math.ceil(span / maxTicks / DAY) * DAY;
    const offset = displayTzOffsetMs(new Date(min));
    const values = [];
    for (let v = Math.ceil((min + offset) / step) * step - offset; v <= max; v += step) {
        values.push(v);
    }

    return { step, values };
}

export const TIME_STEP_DAY = DAY;
