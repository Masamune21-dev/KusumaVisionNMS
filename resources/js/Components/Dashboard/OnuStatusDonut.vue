<script setup>
import { computed } from 'vue';
import VueApexCharts from 'vue3-apexcharts';
import { useI18n } from 'vue-i18n';
import { CircleDot } from '@lucide/vue';
import { formatDateTime } from '@/lib/datetime';

const { t } = useI18n({ useScope: 'global' });

const props = defineProps({
    onu: { type: Object, required: true },
    lastUpdated: { type: String, default: null },
});

const total = computed(() => Math.max(0, props.onu.total ?? 0));
const onlineCount = computed(() => props.onu.online ?? 0);
const warning = computed(() => props.onu.warning ?? 0);
// Slice mutually-exclusive: warning adalah subset dari ONU online (link terdegradasi),
// jadi slice "Online" hanya yang sehat dan "Offline" pakai angka offline asli dari backend.
const online = computed(() => Math.max(0, onlineCount.value - warning.value));
const offline = computed(() => Math.max(0, props.onu.offline ?? total.value - onlineCount.value));

const series = computed(() => [online.value, warning.value, offline.value]);

const onlinePct = computed(() => total.value > 0 ? Math.round((online.value / total.value) * 1000) / 10 : 0);
const warningPct = computed(() => total.value > 0 ? Math.round((warning.value / total.value) * 1000) / 10 : 0);
const offlinePct = computed(() => total.value > 0 ? Math.round((offline.value / total.value) * 1000) / 10 : 0);

const chartOptions = computed(() => ({
    chart: { type: 'donut', background: 'transparent', animations: { enabled: false } },
    labels: [t('dashboard.status_online'), t('dashboard.status_warning'), t('dashboard.status_offline')],
    colors: ['#10b981', '#f59e0b', '#ef4444'],
    legend: { show: false },
    stroke: { width: 0 },
    plotOptions: {
        pie: {
            donut: {
                size: '74%',
                labels: {
                    show: true,
                    name: { show: true, color: '#94a3b8', fontSize: '11px', offsetY: 22 },
                    value: { show: true, color: '#ffffff', fontSize: '28px', fontWeight: 700, offsetY: -10 },
                    total: {
                        show: true,
                        label: t('dashboard.donut_total'),
                        color: '#94a3b8',
                        fontSize: '11px',
                        formatter: () => total.value.toLocaleString('id-ID'),
                    },
                },
            },
        },
    },
    tooltip: { theme: 'dark', y: { formatter: (v) => v.toLocaleString('id-ID') } },
    dataLabels: { enabled: false },
}));

const legend = computed(() => [
    { label: t('dashboard.status_online'), value: online.value, pct: onlinePct.value, color: '#10b981', dot: 'bg-emerald-400' },
    { label: t('dashboard.status_warning'), value: warning.value, pct: warningPct.value, color: '#f59e0b', dot: 'bg-amber-400' },
    { label: t('dashboard.status_offline'), value: offline.value, pct: offlinePct.value, color: '#ef4444', dot: 'bg-red-400' },
]);

const formattedUpdated = computed(() =>
    props.lastUpdated ? formatDateTime(props.lastUpdated) : null,
);
</script>

<template>
    <div class="kv-glass-panel flex h-full flex-col">
        <div class="kv-glass-header">
            <span class="kv-circle-emerald">
                <CircleDot class="h-5 w-5" />
            </span>
            <h3 class="text-base font-semibold text-white">{{ t('dashboard.onu_status') }}</h3>
        </div>

        <div class="flex flex-1 flex-col items-center gap-2 px-4 py-4">
            <div v-if="total > 0" class="flex-shrink-0">
                <VueApexCharts type="donut" height="180" width="180" :options="chartOptions" :series="series" />
            </div>
            <div v-else class="flex flex-1 items-center justify-center py-12 text-center text-sm text-slate-500">
                {{ t('dashboard.no_onu_data') }}
            </div>

            <ul v-if="total > 0" class="flex w-full flex-col gap-2 px-1 text-sm">
                <li v-for="item in legend" :key="item.label" class="flex items-center justify-between gap-2">
                    <span class="flex items-center gap-2 min-w-0">
                        <span class="h-2.5 w-2.5 flex-shrink-0 rounded-full" :class="item.dot" />
                        <span class="truncate text-slate-300">{{ item.label }}</span>
                    </span>
                    <span class="flex flex-shrink-0 items-baseline gap-1.5">
                        <span class="font-semibold text-white tabular-nums">{{ item.value.toLocaleString('id-ID') }}</span>
                        <span class="text-xs text-slate-500 tabular-nums">{{ item.pct }}%</span>
                    </span>
                </li>
            </ul>
        </div>

        <p v-if="formattedUpdated" class="border-t border-white/5 px-5 py-2 text-center text-xs text-slate-500">
            {{ t('dashboard.last_updated', { time: formattedUpdated }) }}
        </p>
    </div>
</template>
