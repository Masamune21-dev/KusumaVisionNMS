<script setup>
import { computed } from 'vue';
// Chart.js dimuat malas di dalam ChartCanvas — lihat komentar di sana.
import ChartCanvas from '@/Components/Charts/ChartCanvas.vue';
import { useI18n } from 'vue-i18n';
import { CircleDot } from '@lucide/vue';
import { tooltip } from '@/lib/chartOptions';
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

const STATUS_HEX = ['#10b981', '#f59e0b', '#ef4444'];

const onlinePct = computed(() => total.value > 0 ? Math.round((online.value / total.value) * 1000) / 10 : 0);
const warningPct = computed(() => total.value > 0 ? Math.round((warning.value / total.value) * 1000) / 10 : 0);
const offlinePct = computed(() => total.value > 0 ? Math.round((offline.value / total.value) * 1000) / 10 : 0);

const chartData = computed(() => ({
    labels: [t('dashboard.status_online'), t('dashboard.status_warning'), t('dashboard.status_offline')],
    datasets: [{
        data: [online.value, warning.value, offline.value],
        backgroundColor: STATUS_HEX,
        borderWidth: 0,
        hoverOffset: 4,
    }],
}));

// Angka total di tengah digambar sebagai HTML di atas kanvas (lihat template).
const chartOptions = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    animation: false,
    cutout: '74%',
    layout: { padding: 4 },
    plugins: {
        legend: { display: false },
        tooltip: tooltip({
            label: (ctx) => ` ${ctx.label}: ${Number(ctx.parsed).toLocaleString('id-ID')}`,
        }),
    },
}));

const legend = computed(() => [
    { label: t('dashboard.status_online'), value: online.value, pct: onlinePct.value, dot: 'bg-emerald-400' },
    { label: t('dashboard.status_warning'), value: warning.value, pct: warningPct.value, dot: 'bg-amber-400' },
    { label: t('dashboard.status_offline'), value: offline.value, pct: offlinePct.value, dot: 'bg-red-400' },
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
            <div v-if="total > 0" class="relative h-[180px] w-[180px] flex-shrink-0">
                <ChartCanvas type="doughnut" :height="180" :data="chartData" :options="chartOptions" :label="t('dashboard.onu_status')" />
                <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                    <span class="text-3xl font-bold tabular-nums text-slate-100">{{ total.toLocaleString('id-ID') }}</span>
                    <span class="mt-0.5 text-xs text-slate-400">{{ t('dashboard.donut_total') }}</span>
                </div>
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
