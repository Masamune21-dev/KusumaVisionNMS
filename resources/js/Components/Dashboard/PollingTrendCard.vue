<script setup>
import { computed, ref } from 'vue';
// Chart.js dimuat malas di dalam ChartCanvas — lihat komentar di sana.
import ChartCanvas from '@/Components/Charts/ChartCanvas.vue';
import { areaFill, axisX, axisY, lineChartOptions } from '@/lib/chartOptions';
import { tokenHex } from '@/lib/theme';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ChevronDown, TrendingUp } from '@lucide/vue';

const { t } = useI18n({ useScope: 'global' });

const props = defineProps({
    trend: { type: Object, required: true },
    range: { type: String, default: '24h' },
});

const ranges = computed(() => [
    { value: '24h', label: t('dashboard.range_24h') },
    { value: '7d', label: t('dashboard.range_7d') },
    { value: '30d', label: t('dashboard.range_30d') },
]);

const rangeOpen = ref(false);
const currentLabel = computed(() => ranges.value.find((r) => r.value === props.range)?.label ?? t('dashboard.range_24h'));

const setRange = (value) => {
    rangeOpen.value = false;
    router.get(route('dashboard'), { range: value }, { preserveScroll: true, preserveState: true });
};

const chartData = computed(() => {
    const success = tokenHex('cyan-400', '#22d3ee');
    const failed = tokenHex('rose-500', '#f43f5e');
    const line = { fill: 'start', cubicInterpolationMode: 'monotone', pointRadius: 0, pointHoverRadius: 4 };

    return {
        labels: props.trend.labels ?? [],
        datasets: [
            {
                ...line,
                label: t('dashboard.polling_success'),
                data: props.trend.success ?? [],
                borderColor: success,
                backgroundColor: areaFill(success, 0.25, 0),
                pointBackgroundColor: success,
                borderWidth: 2.5,
            },
            {
                ...line,
                label: t('dashboard.polling_failed'),
                data: props.trend.failed ?? [],
                borderColor: failed,
                backgroundColor: areaFill(failed, 0.25, 0),
                pointBackgroundColor: failed,
                borderWidth: 2,
            },
        ],
    };
});

// Jarak antar-centang dalam jumlah bucket: 4 jam, 1 hari (4 × 6 jam), 5 hari.
const TICK_STEP = { '24h': 4, '7d': 4, '30d': 5 };

const chartOptions = computed(() => {
    const labels = props.trend.labels ?? [];
    const last = labels.length - 1;
    const step = TICK_STEP[props.range] ?? 4;
    // Label 7 hari "03 Oct 18:00": jam di tiap centang harian selalu sama, cukup tanggalnya.
    const tickText = (index) => (props.range === '7d' ? String(labels[index] ?? '').replace(/\s\d{2}:\d{2}$/, '') : labels[index]);

    return lineChartOptions({
        // Legenda = titik warna di baris total di atas grafik.
        scales: {
            x: {
                ...axisX({ callback: (value) => tickText(value) }),
                // Centang dihitung mundur dari bucket terbaru supaya ujung kanan selalu
                // berlabel; autoSkip bawaan menghitung dari kiri dan membiarkan
                // beberapa bucket terakhir tanpa label.
                afterBuildTicks: (scale) => {
                    scale.ticks = scale.ticks.filter((tick) => (last - tick.value) % step === 0);
                },
            },
            y: axisY({ precision: 0, maxTicksLimit: 6, callback: (v) => Math.round(v) }, { beginAtZero: true }),
        },
    });
});

const totalSuccess = computed(() => props.trend.totals?.success ?? 0);
const totalFailed = computed(() => props.trend.totals?.failed ?? 0);
const total = computed(() => totalSuccess.value + totalFailed.value);
const successRate = computed(() => total.value > 0 ? Math.round((totalSuccess.value / total.value) * 1000) / 10 : 0);
const failureRate = computed(() => total.value > 0 ? Math.round((totalFailed.value / total.value) * 1000) / 10 : 0);
</script>

<template>
    <div class="kv-glass-panel flex h-full flex-col">
        <div class="flex flex-col gap-3 border-b border-white/5 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
            <div class="flex items-center gap-3">
                <span class="kv-circle-cyan">
                    <TrendingUp class="h-5 w-5" />
                </span>
                <h3 class="text-base font-semibold text-white">{{ t('dashboard.polling_trend_title') }}</h3>
            </div>
            <div class="relative w-full sm:w-auto">
                <button
                    type="button"
                    class="flex min-h-10 w-full items-center justify-center gap-2 rounded-lg border border-white/10 bg-slate-900/60 px-3 py-1.5 text-xs font-medium text-slate-300 transition-colors hover:border-cyan-500/30 hover:text-white sm:w-auto"
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
                        class="absolute right-0 z-20 mt-1 w-full overflow-hidden rounded-lg border border-white/10 bg-slate-900/95 py-1 shadow-xl shadow-black/40 backdrop-blur-xl sm:w-44"
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

        <!-- Total di atas grafik (titik warnanya sekaligus legenda), supaya grafik memakai
             lebar penuh kartu; dulu kolom total di kanan memakan ±200 px yang separuhnya kosong. -->
        <div class="flex flex-1 flex-col gap-4 px-5 py-4 sm:px-6">
            <div class="flex flex-wrap gap-x-10 gap-y-3">
                <div>
                    <p class="flex items-center gap-2 text-xs text-slate-400">
                        <span class="h-2 w-2 rounded-full bg-cyan-400" aria-hidden="true" />
                        {{ t('dashboard.total_success') }}
                    </p>
                    <p class="mt-1 flex items-baseline gap-2">
                        <span class="text-2xl font-semibold tabular-nums text-white">{{ totalSuccess.toLocaleString('id-ID') }}</span>
                        <span v-if="total > 0" class="text-xs font-medium tabular-nums text-emerald-400">{{ successRate }}%</span>
                        <span v-else class="text-xs text-slate-500">{{ t('dashboard.no_data_yet') }}</span>
                    </p>
                </div>
                <div>
                    <p class="flex items-center gap-2 text-xs text-slate-400">
                        <span class="h-2 w-2 rounded-full bg-rose-500" aria-hidden="true" />
                        {{ t('dashboard.total_failed') }}
                    </p>
                    <p class="mt-1 flex items-baseline gap-2">
                        <span class="text-2xl font-semibold tabular-nums text-white">{{ totalFailed.toLocaleString('id-ID') }}</span>
                        <span v-if="total > 0" class="text-xs font-medium tabular-nums text-rose-400">{{ failureRate }}%</span>
                    </p>
                </div>
            </div>
            <!-- Mengisi sisa tinggi kartu (baris dashboard setinggi kartu ONU Status di
                 sebelahnya). Kanvas diletakkan absolut supaya ukurannya tidak ikut
                 mendorong tinggi baris — kalau tidak, Chart.js bisa membesar terus. -->
            <div class="relative min-h-56 flex-1">
                <div class="absolute inset-0">
                    <ChartCanvas type="line" height="100%" :data="chartData" :options="chartOptions" :label="t('dashboard.polling_trend_title')" />
                </div>
            </div>
        </div>
    </div>
</template>
