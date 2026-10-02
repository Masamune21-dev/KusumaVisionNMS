<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
// Chart.js dimuat malas di dalam ChartCanvas — lihat komentar di sana.
import ChartCanvas from '@/Components/Charts/ChartCanvas.vue';
import { areaFill, axisX, axisY, hairline, lineChartOptions, TIME_STEP_DAY, timeTicks, withAlpha } from '@/lib/chartOptions';
import { formatAxisTime, formatClock } from '@/lib/datetime';
import { tokenHex } from '@/lib/theme';
import { TrendingDown } from '@lucide/vue';

const props = defineProps({
    // [{ polled_at: ISO string, rx_power_dbm: number }]
    history: { type: Array, default: () => [] },
    range: { type: String, default: '7d' },
});

const RANGES = [
    { key: '24h', labelKey: 'shell.rx_range_24h' },
    { key: '7d', labelKey: 'shell.rx_range_7d' },
    { key: '30d', labelKey: 'shell.rx_range_30d' },
];

const points = computed(() =>
    props.history
        .map((row) => ({ x: new Date(row.polled_at).getTime(), y: Number(row.rx_power_dbm) }))
        .filter((p) => Number.isFinite(p.x) && Number.isFinite(p.y)),
);

const hasData = computed(() => points.value.length > 0);

const stats = computed(() => {
    if (!hasData.value) return null;
    const ys = points.value.map((p) => p.y);
    const last = ys[ys.length - 1];
    const min = Math.min(...ys);
    const max = Math.max(...ys);
    const avg = ys.reduce((a, b) => a + b, 0) / ys.length;
    return { last, min, max, avg };
});

// Batas sumbu Y supaya pita zona terisi penuh (-28/-25 = ambang warning/kritis sisi rendah),
// dibulatkan ke kelipatan 5 supaya centangnya bulat (-30, -25, -20, …).
const yMin = computed(() => Math.floor(((hasData.value ? Math.min(-30, ...points.value.map((p) => p.y)) : -30) - 1) / 5) * 5);
const yMax = computed(() => Math.ceil(((hasData.value ? Math.max(-8, ...points.value.map((p) => p.y)) : -8) + 1) / 5) * 5);

const chartData = computed(() => {
    const cyan = tokenHex('cyan-400', '#22d3ee');

    return {
        datasets: [{
            label: 'RX power',
            data: points.value,
            borderColor: cyan,
            backgroundColor: areaFill(cyan, 0.35, 0.05),
            pointBackgroundColor: cyan,
            borderWidth: 2,
            fill: 'start',
            cubicInterpolationMode: 'monotone',
            pointRadius: points.value.length <= 60 ? 3 : 0,
            pointBorderWidth: 0,
            pointHoverRadius: 5,
        }],
    };
});

const chartOptions = computed(() => {
    const xs = points.value.map((p) => p.x);
    const xMin = xs.length ? Math.min(...xs) : 0;
    const xMax = xs.length ? Math.max(...xs) : 0;
    const { step } = timeTicks(xMin, xMax, 6);
    const tick = { color: tokenHex('slate-400', '#94a3b8'), font: { size: 10 } };

    return lineChartOptions({
        tooltipCallbacks: {
            title: (items) => (items.length ? formatClock(items[0].parsed.x) : ''),
            label: (ctx) => ` ${ctx.parsed.y.toFixed(2)} dBm`,
        },
        plugins: {
            kvZones: {
                bands: [
                    { from: -25, to: yMax.value, color: withAlpha('#10b981', 0.06) },
                    { from: -28, to: -25, color: withAlpha('#f59e0b', 0.08) },
                    { from: yMin.value, to: -28, color: withAlpha('#ef4444', 0.08) },
                ],
                lines: [
                    { value: -25, color: withAlpha('#f59e0b', 0.4) },
                    { value: -28, color: withAlpha('#ef4444', 0.4) },
                ],
            },
        },
        scales: {
            x: {
                ...axisX({ ...tick, callback: (v) => formatAxisTime(v, { date: step >= TIME_STEP_DAY }) }),
                type: 'linear',
                min: xMin,
                max: xMax,
                border: { color: hairline(0.2) },
                // Centang di jam/hari bulat zona tampilan (pengganti sumbu datetime ApexCharts).
                afterBuildTicks: (scale) => {
                    scale.ticks = timeTicks(scale.min, scale.max, 6).values.map((value) => ({ value }));
                },
            },
            y: axisY({ ...tick, stepSize: 5, callback: (v) => `${Number(v).toFixed(0)}` }, {
                min: yMin.value,
                max: yMax.value,
                grid: { color: withAlpha(tokenHex('slate-400', '#94a3b8'), 0.12) },
                title: { display: true, text: 'dBm', color: tokenHex('slate-500', '#64748b'), font: { size: 10 } },
            }),
        },
    });
});

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
                    {{ $t(r.labelKey) }}
                </button>
            </div>
        </div>

        <div class="flex flex-1 flex-col px-4 py-4">
            <template v-if="hasData">
                <div class="mb-2 grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <div class="rounded-lg bg-slate-900/40 px-3 py-2">
                        <p class="text-[10px] uppercase tracking-wider text-slate-500">{{ $t('shell.rx_last') }}</p>
                        <p class="text-sm font-semibold text-white tabular-nums">{{ fmt(stats.last) }}</p>
                    </div>
                    <div class="rounded-lg bg-slate-900/40 px-3 py-2">
                        <p class="text-[10px] uppercase tracking-wider text-slate-500">{{ $t('shell.rx_avg') }}</p>
                        <p class="text-sm font-semibold text-slate-200 tabular-nums">{{ fmt(stats.avg) }}</p>
                    </div>
                    <div class="rounded-lg bg-slate-900/40 px-3 py-2">
                        <p class="text-[10px] uppercase tracking-wider text-slate-500">{{ $t('shell.rx_max') }}</p>
                        <p class="text-sm font-semibold text-emerald-300 tabular-nums">{{ fmt(stats.max) }}</p>
                    </div>
                    <div class="rounded-lg bg-slate-900/40 px-3 py-2">
                        <p class="text-[10px] uppercase tracking-wider text-slate-500">{{ $t('shell.rx_min') }}</p>
                        <p class="text-sm font-semibold text-amber-300 tabular-nums">{{ fmt(stats.min) }}</p>
                    </div>
                </div>
                <ChartCanvas type="line" :height="240" :data="chartData" :options="chartOptions" label="RX power (dBm)" />
            </template>
            <div v-else class="flex flex-1 items-center justify-center py-12 text-center text-sm text-slate-500">
                {{ $t('shell.rx_empty') }}
            </div>
        </div>
    </div>
</template>
