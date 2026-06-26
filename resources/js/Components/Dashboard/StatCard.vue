<script setup>
import { computed } from 'vue';
import VueApexCharts from 'vue3-apexcharts';

const props = defineProps({
    label: { type: String, required: true },
    value: { type: [String, Number], required: true },
    icon: { type: [Object, Function], required: true },
    accent: { type: String, default: 'sky' },
    sublabels: { type: Array, default: () => [] },
    sparkline: { type: Array, default: () => [] },
    sparklineLabel: { type: String, default: 'Trend' },
});

const circleClass = computed(() => ({
    sky: 'kv-circle-sky',
    cyan: 'kv-circle-cyan',
    emerald: 'kv-circle-emerald',
    purple: 'kv-circle-purple',
    red: 'kv-circle-red',
    amber: 'kv-circle-amber',
    slate: 'kv-circle-slate',
}[props.accent] ?? 'kv-circle-sky'));

const accentHex = computed(() => ({
    sky: '#0ea5e9',
    cyan: '#22d3ee',
    emerald: '#10b981',
    purple: '#a855f7',
    red: '#ef4444',
    amber: '#f59e0b',
    slate: '#64748b',
}[props.accent] ?? '#0ea5e9'));

const sparkOptions = computed(() => ({
    chart: {
        type: 'area',
        sparkline: { enabled: true },
        animations: { enabled: false },
        parentHeightOffset: 0,
        toolbar: { show: false },
    },
    stroke: { curve: 'smooth', width: 2 },
    fill: {
        type: 'gradient',
        gradient: {
            shadeIntensity: 1,
            opacityFrom: 0.45,
            opacityTo: 0,
            stops: [0, 100],
        },
    },
    colors: [accentHex.value],
    grid: { padding: { top: 0, right: 0, bottom: 0, left: 0 } },
    tooltip: {
        theme: 'dark',
        x: { show: false },
        y: { formatter: (v) => v, title: { formatter: () => props.sparklineLabel } },
        marker: { show: false },
    },
}));

const hasVariance = computed(() => {
    const arr = props.sparkline ?? [];
    if (arr.length < 2) return false;
    const min = Math.min(...arr);
    const max = Math.max(...arr);
    return max - min > 0;
});

const sparkSeries = computed(() => [{ name: props.sparklineLabel, data: props.sparkline.length > 0 ? props.sparkline : [0, 0, 0, 0, 0, 0, 0] }]);
</script>

<template>
    <div class="kv-glass-card kv-glass-hover relative overflow-hidden">
        <!-- Sparkline floats top-right, isolated from text flow -->
        <div class="pointer-events-none absolute right-4 top-4 hidden h-16 w-36 sm:block lg:w-40">
            <VueApexCharts
                v-if="hasVariance"
                type="area"
                height="64"
                width="100%"
                :options="sparkOptions"
                :series="sparkSeries"
            />
            <span
                v-else
                class="mt-8 block h-px w-full rounded-full opacity-60"
                :style="{ background: `linear-gradient(to right, transparent, ${accentHex}, transparent)` }"
                aria-hidden="true"
            />
        </div>

        <div class="flex items-start gap-4 sm:pr-40 lg:pr-44">
            <span :class="circleClass">
                <component :is="icon" class="h-6 w-6" />
            </span>
            <div class="min-w-0 flex-1">
                <p class="text-xs font-medium uppercase tracking-wider text-slate-400">{{ label }}</p>
                <p class="mt-1 text-3xl font-bold tabular-nums text-white sm:text-[2rem]">{{ value }}</p>
            </div>
        </div>

        <div v-if="sublabels.length > 0" class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-1.5 border-t border-white/5 pt-3 text-xs">
            <div v-for="sub in sublabels" :key="sub.label" class="flex items-center gap-1.5">
                <span
                    class="h-2 w-2 rounded-full"
                    :style="{ backgroundColor: sub.color ?? '#64748b' }"
                />
                <span class="text-slate-400">{{ sub.label }}</span>
                <span class="font-semibold text-slate-200">{{ sub.value }}</span>
            </div>
        </div>
    </div>
</template>
