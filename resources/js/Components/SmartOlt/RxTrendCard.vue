<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import VueApexCharts from 'vue3-apexcharts';
import { TrendingDown } from '@lucide/vue';

const props = defineProps({
    // [{ polled_at: ISO string, rx_power_dbm: number }]
    history: { type: Array, default: () => [] },
    range: { type: String, default: '7d' },
});

const RANGES = [
    { key: '24h', label: '24 jam' },
    { key: '7d', label: '7 hari' },
    { key: '30d', label: '30 hari' },
];

const points = computed(() =>
    props.history
        .map((row) => ({ x: new Date(row.polled_at).getTime(), y: Number(row.rx_power_dbm) }))
        .filter((p) => Number.isFinite(p.x) && Number.isFinite(p.y)),
);

const hasData = computed(() => points.value.length > 0);

const series = computed(() => [{ name: 'RX power', data: points.value }]);

const stats = computed(() => {
    if (!hasData.value) return null;
    const ys = points.value.map((p) => p.y);
    const last = ys[ys.length - 1];
    const min = Math.min(...ys);
    const max = Math.max(...ys);
    const avg = ys.reduce((a, b) => a + b, 0) / ys.length;
    return { last, min, max, avg };
});

// Batas sumbu Y supaya pita zona terisi penuh (-28/-25 = ambang warning/kritis sisi rendah).
const yMin = computed(() => (hasData.value ? Math.min(-30, ...points.value.map((p) => p.y)) - 1 : -30));
const yMax = computed(() => (hasData.value ? Math.max(-8, ...points.value.map((p) => p.y)) + 1 : -8));

const chartOptions = computed(() => ({
    chart: { type: 'area', background: 'transparent', toolbar: { show: false }, zoom: { enabled: false }, animations: { enabled: false } },
    colors: ['#22d3ee'],
    dataLabels: { enabled: false },
    stroke: { curve: 'smooth', width: 2 },
    fill: { type: 'gradient', gradient: { shadeIntensity: 0.3, opacityFrom: 0.35, opacityTo: 0.05 } },
    markers: { size: points.value.length <= 60 ? 3 : 0, strokeWidth: 0, hover: { size: 5 } },
    grid: { borderColor: 'rgba(148,163,184,0.12)', strokeDashArray: 4 },
    xaxis: {
        type: 'datetime',
        labels: { style: { colors: '#94a3b8', fontSize: '10px' }, datetimeUTC: false },
        axisBorder: { color: 'rgba(148,163,184,0.2)' },
        axisTicks: { color: 'rgba(148,163,184,0.2)' },
    },
    yaxis: {
        min: yMin.value,
        max: yMax.value,
        tickAmount: 5,
        labels: { style: { colors: '#94a3b8', fontSize: '10px' }, formatter: (v) => `${v.toFixed(0)}` },
        title: { text: 'dBm', style: { color: '#64748b', fontSize: '10px', fontWeight: 400 } },
    },
    tooltip: {
        theme: 'dark',
        x: { format: 'dd MMM HH:mm' },
        y: { formatter: (v) => `${v.toFixed(2)} dBm` },
    },
    annotations: {
        yaxis: [
            { y: -25, y2: yMax.value, fillColor: '#10b981', opacity: 0.06, borderColor: 'transparent' },
            { y: -28, y2: -25, fillColor: '#f59e0b', opacity: 0.08, borderColor: 'transparent' },
            { y: yMin.value, y2: -28, fillColor: '#ef4444', opacity: 0.08, borderColor: 'transparent' },
            { y: -25, borderColor: '#f59e0b', strokeDashArray: 4, opacity: 0.4 },
            { y: -28, borderColor: '#ef4444', strokeDashArray: 4, opacity: 0.4 },
        ],
    },
}));

const setRange = (key) => {
    if (key === props.range) return;
    router.reload({
        data: { range: key },
        only: ['rx_history', 'range'],
        preserveState: true,
        preserveScroll: true,
    });
};

const fmt = (v) => (v === null || v === undefined ? '—' : `${v.toFixed(2)} dBm`);
</script>

<template>
    <div class="kv-glass-panel flex h-full flex-col">
        <div class="kv-glass-header flex-wrap gap-y-2">
            <span class="kv-circle-cyan">
                <TrendingDown class="h-5 w-5" />
            </span>
            <h3 class="text-base font-semibold text-white">Tren RX Power</h3>
            <div class="ml-auto inline-flex rounded-lg border border-white/10 bg-slate-900/50 p-0.5">
                <button
                    v-for="r in RANGES"
                    :key="r.key"
                    type="button"
                    class="rounded-md px-2.5 py-1 text-xs font-medium transition"
                    :class="r.key === range ? 'bg-cyan-500/20 text-cyan-300' : 'text-slate-400 hover:text-white'"
                    @click="setRange(r.key)"
                >
                    {{ r.label }}
                </button>
            </div>
        </div>

        <div class="flex flex-1 flex-col px-4 py-4">
            <template v-if="hasData">
                <div class="mb-2 grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <div class="rounded-lg bg-slate-900/40 px-3 py-2">
                        <p class="text-[10px] uppercase tracking-wider text-slate-500">Terakhir</p>
                        <p class="text-sm font-semibold text-white tabular-nums">{{ fmt(stats.last) }}</p>
                    </div>
                    <div class="rounded-lg bg-slate-900/40 px-3 py-2">
                        <p class="text-[10px] uppercase tracking-wider text-slate-500">Rata-rata</p>
                        <p class="text-sm font-semibold text-slate-200 tabular-nums">{{ fmt(stats.avg) }}</p>
                    </div>
                    <div class="rounded-lg bg-slate-900/40 px-3 py-2">
                        <p class="text-[10px] uppercase tracking-wider text-slate-500">Tertinggi</p>
                        <p class="text-sm font-semibold text-emerald-300 tabular-nums">{{ fmt(stats.max) }}</p>
                    </div>
                    <div class="rounded-lg bg-slate-900/40 px-3 py-2">
                        <p class="text-[10px] uppercase tracking-wider text-slate-500">Terendah</p>
                        <p class="text-sm font-semibold text-amber-300 tabular-nums">{{ fmt(stats.min) }}</p>
                    </div>
                </div>
                <VueApexCharts type="area" height="240" :options="chartOptions" :series="series" />
            </template>
            <div v-else class="flex flex-1 items-center justify-center py-12 text-center text-sm text-slate-500">
                Belum ada riwayat RX pada rentang ini. Tunggu siklus polling RX berikutnya.
            </div>
        </div>
    </div>
</template>
