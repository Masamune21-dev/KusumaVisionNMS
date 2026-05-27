<script setup>
import { computed, ref } from 'vue';
import VueApexCharts from 'vue3-apexcharts';
import { router } from '@inertiajs/vue3';
import { ChevronDown, TrendingUp } from '@lucide/vue';

const props = defineProps({
    trend: { type: Object, required: true },
    range: { type: String, default: '24h' },
});

const ranges = [
    { value: '24h', label: '24 Jam Terakhir' },
    { value: '7d', label: '7 Hari Terakhir' },
    { value: '30d', label: '30 Hari Terakhir' },
];

const rangeOpen = ref(false);
const currentLabel = computed(() => ranges.find((r) => r.value === props.range)?.label ?? '24 Jam Terakhir');

const setRange = (value) => {
    rangeOpen.value = false;
    router.get(route('dashboard'), { range: value }, { preserveScroll: true, preserveState: true });
};

const chartOptions = computed(() => ({
    chart: {
        type: 'line',
        toolbar: { show: false },
        background: 'transparent',
        animations: { enabled: false },
    },
    stroke: { curve: 'smooth', width: [2.5, 2] },
    colors: ['#22d3ee', '#ef4444'],
    grid: {
        borderColor: 'rgba(255,255,255,0.05)',
        strokeDashArray: 4,
        padding: { top: 0, right: 8, bottom: 0, left: 8 },
    },
    xaxis: {
        categories: props.trend.labels ?? [],
        labels: {
            style: { colors: '#64748b', fontSize: '11px' },
            rotate: 0,
            hideOverlappingLabels: true,
            showDuplicates: false,
        },
        tickAmount: Math.min(6, (props.trend.labels?.length ?? 1) - 1),
        axisBorder: { color: 'rgba(255,255,255,0.05)' },
        axisTicks: { color: 'rgba(255,255,255,0.05)' },
    },
    yaxis: {
        labels: { style: { colors: '#64748b', fontSize: '11px' }, formatter: (v) => Math.round(v) },
    },
    legend: {
        position: 'top',
        horizontalAlign: 'left',
        labels: { colors: '#cbd5e1' },
        markers: { width: 10, height: 10, radius: 10 },
        itemMargin: { horizontal: 12 },
    },
    tooltip: { theme: 'dark' },
    dataLabels: { enabled: false },
    fill: {
        type: 'gradient',
        gradient: {
            shadeIntensity: 1,
            opacityFrom: 0.25,
            opacityTo: 0,
            stops: [0, 100],
        },
    },
}));

const series = computed(() => [
    { name: 'Polling Success', data: props.trend.success ?? [] },
    { name: 'Polling Failed', data: props.trend.failed ?? [] },
]);

const totalSuccess = computed(() => props.trend.totals?.success ?? 0);
const totalFailed = computed(() => props.trend.totals?.failed ?? 0);
const total = computed(() => totalSuccess.value + totalFailed.value);
const successRate = computed(() => total.value > 0 ? Math.round((totalSuccess.value / total.value) * 1000) / 10 : 0);
const failureRate = computed(() => total.value > 0 ? Math.round((totalFailed.value / total.value) * 1000) / 10 : 0);
</script>

<template>
    <div class="kv-glass-panel flex h-full flex-col">
        <div class="flex items-center justify-between border-b border-white/5 px-5 py-4 sm:px-6">
            <div class="flex items-center gap-3">
                <span class="kv-circle-cyan">
                    <TrendingUp class="h-5 w-5" />
                </span>
                <h3 class="text-base font-semibold text-white">Tren Aktivitas Jaringan (Polling)</h3>
            </div>
            <div class="relative">
                <button
                    type="button"
                    class="flex items-center gap-2 rounded-lg border border-white/10 bg-slate-900/60 px-3 py-1.5 text-xs font-medium text-slate-300 transition-colors hover:border-cyan-500/30 hover:text-white"
                    @click="rangeOpen = !rangeOpen"
                >
                    {{ currentLabel }}
                    <ChevronDown class="h-3.5 w-3.5 transition-transform" :class="{ 'rotate-180': rangeOpen }" />
                </button>
                <Transition
                    enter-active-class="transition duration-100"
                    enter-from-class="opacity-0 translate-y-1"
                    enter-to-class="opacity-100 translate-y-0"
                    leave-active-class="transition duration-75"
                    leave-from-class="opacity-100"
                    leave-to-class="opacity-0"
                >
                    <ul
                        v-if="rangeOpen"
                        class="absolute right-0 z-20 mt-1 w-44 overflow-hidden rounded-lg border border-white/10 bg-slate-900/95 py-1 shadow-xl shadow-black/40 backdrop-blur-xl"
                    >
                        <li v-for="r in ranges" :key="r.value">
                            <button
                                type="button"
                                class="block w-full px-3 py-2 text-left text-xs transition-colors"
                                :class="r.value === range ? 'bg-cyan-500/10 text-cyan-300' : 'text-slate-300 hover:bg-white/5 hover:text-white'"
                                @click="setRange(r.value)"
                            >
                                {{ r.label }}
                            </button>
                        </li>
                    </ul>
                </Transition>
            </div>
        </div>

        <div class="grid gap-4 px-2 py-4 sm:grid-cols-[1fr_180px] sm:gap-2 sm:px-3">
            <div class="min-h-[240px]">
                <VueApexCharts type="area" height="260" :options="chartOptions" :series="series" />
            </div>
            <div class="flex flex-col justify-center gap-4 border-t border-white/5 px-4 pt-4 sm:border-l sm:border-t-0 sm:pl-5 sm:pt-0">
                <div>
                    <p class="text-xs uppercase tracking-wider text-slate-500">Total Success</p>
                    <p class="mt-1 text-2xl font-bold text-white">{{ totalSuccess.toLocaleString('id-ID') }}</p>
                    <p v-if="total > 0" class="mt-0.5 text-xs font-medium text-emerald-400">{{ successRate }}%</p>
                    <p v-else class="mt-0.5 text-xs text-slate-500">belum ada data</p>
                </div>
                <div>
                    <p class="text-xs uppercase tracking-wider text-slate-500">Total Failed</p>
                    <p class="mt-1 text-2xl font-bold text-white">{{ totalFailed.toLocaleString('id-ID') }}</p>
                    <p v-if="total > 0" class="mt-0.5 text-xs font-medium text-red-400">{{ failureRate }}%</p>
                    <p v-else class="mt-0.5 text-xs text-slate-500">&mdash;</p>
                </div>
            </div>
        </div>
    </div>
</template>
