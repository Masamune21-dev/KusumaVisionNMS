<script setup>
import { computed } from 'vue';
import VueApexCharts from 'vue3-apexcharts';
import { BarChart3 } from '@lucide/vue';

const props = defineProps({
    // Daftar ONU (tiap item punya rx_power_dbm) — biasanya hasil filter halaman.
    onus: { type: Array, default: () => [] },
});

const ZONE_COLOR = { good: '#10b981', warning: '#f59e0b', critical: '#ef4444' };

// Klasifikasi level redaman ONU RX — ambang batas SAMA dengan OnuMonitor.vue (badge & filter).
const rxLevel = (value) => {
    if (value === null || value === undefined || Number.isNaN(value)) return 'none';
    if (value <= -28 || value >= -8) return 'critical';
    if (value <= -25 || value >= -10) return 'warning';
    return 'good';
};

// Nilai RX numerik dari ONU yang dipilih.
const values = computed(() =>
    props.onus
        .map((onu) => (typeof onu.rx_power_dbm === 'number' ? onu.rx_power_dbm : Number(onu.rx_power_dbm)))
        .filter((v) => Number.isFinite(v)),
);

const naCount = computed(() => props.onus.length - values.value.length);

// Bin distribusi dBm (kiri = sinyal kuat, kanan = lemah). Warna mengikuti zona bin.
const BINS = [
    { label: '≥ -8', color: ZONE_COLOR.critical, match: (v) => v >= -8 },
    { label: '-10…-8', color: ZONE_COLOR.warning, match: (v) => v < -8 && v >= -10 },
    { label: '-13…-10', color: ZONE_COLOR.good, match: (v) => v < -10 && v >= -13 },
    { label: '-16…-13', color: ZONE_COLOR.good, match: (v) => v < -13 && v >= -16 },
    { label: '-19…-16', color: ZONE_COLOR.good, match: (v) => v < -16 && v >= -19 },
    { label: '-22…-19', color: ZONE_COLOR.good, match: (v) => v < -19 && v >= -22 },
    { label: '-25…-22', color: ZONE_COLOR.good, match: (v) => v < -22 && v > -25 },
    { label: '-28…-25', color: ZONE_COLOR.warning, match: (v) => v <= -25 && v > -28 },
    { label: '< -28', color: ZONE_COLOR.critical, match: (v) => v <= -28 },
];

const binCounts = computed(() => {
    const counts = BINS.map(() => 0);
    for (const v of values.value) {
        const idx = BINS.findIndex((b) => b.match(v));
        if (idx >= 0) counts[idx]++;
    }
    return counts;
});

const series = computed(() => [{ name: 'ONU', data: binCounts.value }]);

const chartOptions = computed(() => ({
    chart: { type: 'bar', background: 'transparent', toolbar: { show: false }, animations: { enabled: false } },
    plotOptions: { bar: { columnWidth: '70%', distributed: true, borderRadius: 3 } },
    colors: BINS.map((b) => b.color),
    dataLabels: { enabled: false },
    legend: { show: false },
    grid: { borderColor: 'rgba(148,163,184,0.12)', strokeDashArray: 4 },
    xaxis: {
        categories: BINS.map((b) => b.label),
        labels: { style: { colors: '#94a3b8', fontSize: '10px' }, rotate: -35, trim: true },
        axisBorder: { color: 'rgba(148,163,184,0.2)' },
        axisTicks: { color: 'rgba(148,163,184,0.2)' },
        title: { text: 'RX power (dBm)', style: { color: '#64748b', fontSize: '10px', fontWeight: 400 } },
    },
    yaxis: {
        labels: { style: { colors: '#94a3b8', fontSize: '10px' }, formatter: (v) => Math.round(v) },
    },
    tooltip: { theme: 'dark', y: { formatter: (v) => `${v.toLocaleString('id-ID')} ONU` } },
}));

// Ringkasan per zona — pakai rxLevel langsung agar cocok dengan filter RX di tabel.
const summary = computed(() => {
    const tally = { good: 0, warning: 0, critical: 0 };
    for (const v of values.value) {
        const level = rxLevel(v);
        if (level in tally) tally[level]++;
    }
    const total = values.value.length;
    const pct = (n) => (total > 0 ? Math.round((n / total) * 1000) / 10 : 0);
    return [
        { label: 'Sehat', value: tally.good, pct: pct(tally.good), dot: 'bg-emerald-400' },
        { label: 'Warning', value: tally.warning, pct: pct(tally.warning), dot: 'bg-amber-400' },
        { label: 'Kritis', value: tally.critical, pct: pct(tally.critical), dot: 'bg-red-400' },
    ];
});

const hasData = computed(() => values.value.length > 0);
</script>

<template>
    <div class="kv-glass-panel flex h-full flex-col">
        <div class="kv-glass-header">
            <span class="kv-circle-cyan">
                <BarChart3 class="h-5 w-5" />
            </span>
            <div class="min-w-0">
                <h3 class="text-base font-semibold text-white">Distribusi RX Power</h3>
                <p class="truncate text-xs text-slate-500">
                    {{ values.length.toLocaleString('id-ID') }} ONU terukur
                    <span v-if="naCount > 0"> · N/A: {{ naCount.toLocaleString('id-ID') }}</span>
                </p>
            </div>
        </div>

        <div class="flex flex-1 flex-col gap-3 px-4 py-4">
            <div v-if="hasData">
                <VueApexCharts type="bar" height="200" :options="chartOptions" :series="series" />
            </div>
            <div v-else class="flex flex-1 items-center justify-center py-10 text-center text-sm text-slate-500">
                Belum ada data RX power. Jalankan refresh / tunggu polling RX.
            </div>

            <ul v-if="hasData" class="flex flex-wrap items-center justify-center gap-x-5 gap-y-1 text-sm">
                <li v-for="item in summary" :key="item.label" class="flex items-center gap-2">
                    <span class="h-2.5 w-2.5 flex-shrink-0 rounded-full" :class="item.dot" />
                    <span class="text-slate-300">{{ item.label }}</span>
                    <span class="font-semibold text-white tabular-nums">{{ item.value.toLocaleString('id-ID') }}</span>
                    <span class="text-xs text-slate-500 tabular-nums">{{ item.pct }}%</span>
                </li>
            </ul>
        </div>
    </div>
</template>
