/**
 * Chart.js (MIT) untuk seluruh grafik NMS.
 *
 * Modul ini HANYA boleh masuk lewat `import()` dinamis — satu-satunya pintu
 * adalah Components/Charts/ChartCanvas.vue. Jangan mengimpornya (atau
 * 'chart.js') secara statis, dan jangan menyebut keduanya di `manualChunks`
 * vite.config.js: Rollup mengangkat chunk bernama menjadi impor statis entry,
 * dan grafik ikut terunduh di setiap halaman (pernah terjadi dengan ApexCharts;
 * dijaga tests/Feature/BundelAsetTest.php).
 *
 * Hanya bagian yang dipakai yang didaftarkan, supaya chunk-nya tetap kecil.
 * Pengganti ApexCharts sejak 2 Okt 2026: ApexCharts 5 tidak lagi open source
 * (lisensi komunitas dibatasi omzet), tidak cocok dengan repo MIT.
 */
import {
    ArcElement,
    CategoryScale,
    Chart,
    DoughnutController,
    Filler,
    Legend,
    LinearScale,
    LineController,
    LineElement,
    PointElement,
    Tooltip,
} from 'chart.js';

/**
 * Pita zona & garis ambang horizontal di sumbu Y — pengganti
 * `annotations.yaxis` ApexCharts (Tren RX Power: hijau/kuning/merah).
 * Ditulis sendiri karena yang dibutuhkan cuma dua bentuk ini.
 *
 * options.plugins.kvZones = {
 *     bands: [{ from, to, color }],
 *     lines: [{ value, color, dash? }],
 * }
 */
const zones = {
    id: 'kvZones',
    beforeDatasetsDraw(chart, _args, opts) {
        const { ctx, chartArea, scales } = chart;
        const y = scales?.y;
        if (!chartArea || !y || (!opts?.bands?.length && !opts?.lines?.length)) return;

        ctx.save();
        ctx.beginPath();
        ctx.rect(chartArea.left, chartArea.top, chartArea.width, chartArea.height);
        ctx.clip();

        for (const band of opts.bands ?? []) {
            const top = y.getPixelForValue(band.to);
            const bottom = y.getPixelForValue(band.from);
            ctx.fillStyle = band.color;
            ctx.fillRect(chartArea.left, Math.min(top, bottom), chartArea.width, Math.abs(bottom - top));
        }

        for (const line of opts.lines ?? []) {
            const py = Math.round(y.getPixelForValue(line.value)) + 0.5;
            ctx.strokeStyle = line.color;
            ctx.lineWidth = 1;
            ctx.setLineDash(line.dash ?? [4, 4]);
            ctx.beginPath();
            ctx.moveTo(chartArea.left, py);
            ctx.lineTo(chartArea.right, py);
            ctx.stroke();
        }

        ctx.restore();
    },
};

Chart.register(
    ArcElement,
    CategoryScale,
    DoughnutController,
    Filler,
    Legend,
    LinearScale,
    LineController,
    LineElement,
    PointElement,
    Tooltip,
    zones,
);

// Ikut font aplikasi (Manrope), bukan Helvetica bawaan Chart.js.
if (typeof document !== 'undefined') {
    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily || Chart.defaults.font.family;
}
Chart.defaults.font.size = 11;

export { Chart };
