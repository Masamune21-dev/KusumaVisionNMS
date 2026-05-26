<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { Activity, BellRing, Cable, Server, Wifi } from '@lucide/vue';
import { computed } from 'vue';
import VueApexCharts from 'vue3-apexcharts';

const props = defineProps({
    stats: { type: Object, required: true },
    alarms: { type: Object, required: true },
    olts: { type: Array, required: true },
    recent_alarms: { type: Array, required: true },
});

const onuChart = computed(() => ({
    series: [props.stats.onu_online, props.stats.onu_offline],
    options: {
        chart: { type: 'donut', background: 'transparent' },
        labels: ['Online', 'Offline'],
        colors: ['#10b981', '#9ca3af'],
        legend: { position: 'bottom', labels: { colors: '#6b7280' } },
        dataLabels: { enabled: true, formatter: (v) => Math.round(v) + '%' },
        plotOptions: { pie: { donut: { labels: { show: true, total: { show: true, label: 'Total ONU', formatter: () => props.stats.onu_total } } } } },
        stroke: { width: 0 },
    },
}));

const alarmChart = computed(() => ({
    series: [props.alarms.critical, props.alarms.major, props.alarms.minor, props.alarms.warning],
    options: {
        chart: { type: 'donut', background: 'transparent' },
        labels: ['Critical', 'Major', 'Minor', 'Warning'],
        colors: ['#dc2626', '#ea580c', '#d97706', '#ca8a04'],
        legend: { position: 'bottom', labels: { colors: '#6b7280' } },
        plotOptions: { pie: { donut: { labels: { show: true, total: { show: true, label: 'Alarm Aktif', formatter: () => props.alarms.total } } } } },
        stroke: { width: 0 },
    },
}));

const oltOnuChart = computed(() => ({
    series: [
        { name: 'Online', data: props.olts.map((o) => o.onu_online) },
        { name: 'Offline', data: props.olts.map((o) => o.onu_offline) },
    ],
    options: {
        chart: { type: 'bar', stacked: true, toolbar: { show: false }, background: 'transparent' },
        plotOptions: { bar: { horizontal: false, borderRadius: 4, columnWidth: '50%' } },
        colors: ['#10b981', '#9ca3af'],
        xaxis: {
            categories: props.olts.map((o) => o.name),
            labels: { style: { fontSize: '11px', colors: '#6b7280' }, rotate: -30, trim: true, maxHeight: 60 },
        },
        yaxis: { labels: { formatter: (v) => Math.round(v), style: { colors: '#6b7280' } } },
        legend: { position: 'top', labels: { colors: '#6b7280' } },
        dataLabels: { enabled: false },
        grid: { borderColor: 'rgba(0,0,0,0.08)' },
    },
}));

const cards = computed(() => [
    { label: 'OLT Online', value: `${props.stats.olts_online}/${props.stats.olts_total}`, icon: Server, class: 'text-sky-600' },
    { label: 'ONU Online', value: props.stats.onu_online, icon: Wifi, class: 'text-emerald-600' },
    { label: 'ONU Offline', value: props.stats.onu_offline, icon: Wifi, class: 'text-slate-500' },
    { label: 'Alarm Critical', value: props.alarms.critical, icon: BellRing, class: 'text-red-600' },
]);

const severityClass = (severity) => ({
    critical: 'bg-red-50 text-red-700 ring-1 ring-red-200',
    major: 'bg-orange-50 text-orange-700 ring-1 ring-orange-200',
    minor: 'bg-amber-50 text-amber-700 ring-1 ring-amber-200',
    warning: 'bg-yellow-50 text-yellow-700 ring-1 ring-yellow-200',
}[severity] ?? 'bg-slate-100 text-slate-600 ring-1 ring-slate-200');

const formatDate = (value) => {
    if (!value) return '-';

    return new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
};
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-lg font-semibold leading-tight sm:text-xl text-slate-800">Dashboard</h2>
        </template>

        <div class="min-h-[60vh] pt-5 pb-16 sm:pt-8">
            <div class="w-full space-y-6 px-4 sm:px-6 lg:px-8">
                <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <div v-for="card in cards" :key="card.label" class="rounded-lg border border-sky-200 bg-white p-5 shadow-sm shadow-sky-100/60">
                        <p class="text-xs font-medium uppercase tracking-wider text-slate-500">{{ card.label }}</p>
                        <p class="mt-3 text-2xl font-bold text-slate-900">{{ card.value }}</p>
                    </div>
                </div>

                <div class="grid gap-6 lg:grid-cols-3">
                    <div class="overflow-hidden rounded-lg border border-sky-200 bg-white p-6 shadow-sm shadow-sky-100/60">
                        <h3 class="mb-2 flex items-center gap-2 text-base font-semibold text-slate-900">
                            <Wifi class="h-5 w-5 text-slate-400" /> ONU Online / Offline
                        </h3>
                        <VueApexCharts v-if="stats.onu_total > 0" type="donut" height="280" :options="onuChart.options" :series="onuChart.series" />
                        <p v-else class="py-16 text-center text-sm text-slate-400">Belum ada data ONU. Jalankan poll.</p>
                    </div>

                    <div class="overflow-hidden rounded-lg border border-sky-200 bg-white p-6 shadow-sm shadow-sky-100/60">
                        <h3 class="mb-2 flex items-center gap-2 text-base font-semibold text-slate-900">
                            <BellRing class="h-5 w-5 text-slate-400" /> Alarm Aktif
                        </h3>
                        <VueApexCharts v-if="alarms.total > 0" type="donut" height="280" :options="alarmChart.options" :series="alarmChart.series" />
                        <p v-else class="py-16 text-center text-sm text-slate-400">Tidak ada alarm aktif.</p>
                    </div>

                    <div class="overflow-hidden rounded-lg border border-sky-200 bg-white p-6 shadow-sm shadow-sky-100/60">
                        <h3 class="mb-2 flex items-center gap-2 text-base font-semibold text-slate-900">
                            <Activity class="h-5 w-5 text-slate-400" /> ONU per OLT
                        </h3>
                        <VueApexCharts v-if="olts.length > 0" type="bar" height="280" :options="oltOnuChart.options" :series="oltOnuChart.series" />
                        <p v-else class="py-16 text-center text-sm text-slate-400">Belum ada OLT.</p>
                    </div>
                </div>

                <div class="grid gap-6 lg:grid-cols-5">
                    <div class="overflow-hidden rounded-lg border border-sky-200 bg-white shadow-sm shadow-sky-100/60 lg:col-span-3">
                        <div class="flex items-center gap-3 border-b border-slate-100 px-4 py-4 sm:px-6">
                            <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-sky-100 ring-1 ring-sky-200">
                                <Cable class="h-5 w-5 text-sky-600" />
                            </div>
                            <div>
                                <h3 class="text-base font-semibold text-slate-900">Status per OLT</h3>
                                <p class="mt-0.5 text-xs text-slate-500">Ringkasan kondisi setiap OLT</p>
                            </div>
                        </div>
                        <div v-if="olts.length === 0" class="px-6 py-10 text-center text-sm text-slate-400">Belum ada OLT.</div>
                        <div v-else class="overflow-x-auto">
                            <table class="min-w-[720px] w-full">
                                <thead>
                                    <tr class="border-b border-slate-100 bg-slate-50">
                                        <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">OLT</th>
                                        <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Port</th>
                                        <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">ONU</th>
                                        <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Poll</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <tr v-for="olt in olts" :key="olt.id" class="transition-colors duration-150 hover:bg-slate-50">
                                        <td class="px-4 py-4">
                                            <Link :href="route('smartolt.detail', olt.id)" class="font-medium text-sky-600 hover:text-sky-500">{{ olt.name }}</Link>
                                            <div class="mt-1">
                                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium" :class="olt.reachable ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200' : 'bg-slate-100 text-slate-600 ring-1 ring-slate-200'">
                                                    {{ olt.reachable ? 'Online' : 'Unknown' }}
                                                </span>
                                            </div>
                                        </td>
                                        <td class="px-4 py-4 text-sm text-slate-700">
                                            <span class="text-emerald-600">{{ olt.ports_up }} up</span>
                                            <span v-if="olt.ports_down" class="text-red-600"> · {{ olt.ports_down }} down</span>
                                        </td>
                                        <td class="px-4 py-4 text-sm text-slate-700">
                                            <span class="font-medium text-emerald-600">{{ olt.onu_online }}</span> / {{ olt.onu_total }} online
                                        </td>
                                        <td class="px-4 py-4 text-sm text-slate-500">{{ formatDate(olt.last_polled_at) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="overflow-hidden rounded-lg border border-sky-200 bg-white shadow-sm shadow-sky-100/60 lg:col-span-2">
                        <div class="flex flex-col gap-3 border-b border-slate-100 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                            <div class="flex items-center gap-3">
                                <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-red-100 ring-1 ring-red-200">
                                    <BellRing class="h-5 w-5 text-red-600" />
                                </div>
                                <div>
                                    <h3 class="text-base font-semibold text-slate-900">Alarm Terbaru</h3>
                                    <p class="mt-0.5 text-xs text-slate-500">Alarm aktif terkini</p>
                                </div>
                            </div>
                            <Link :href="route('alarms.index')" class="text-sm font-medium text-sky-600 hover:text-sky-500">Lihat semua</Link>
                        </div>
                        <div v-if="recent_alarms.length === 0" class="px-6 py-10 text-center text-sm text-slate-400">Tidak ada alarm aktif.</div>
                        <ul v-else class="divide-y divide-slate-100">
                            <li v-for="alarm in recent_alarms" :key="alarm.id" class="flex items-start gap-3 px-6 py-3 transition-colors duration-150 hover:bg-slate-50">
                                <span class="mt-0.5 inline-flex flex-none rounded-full px-2.5 py-1 text-xs font-medium uppercase" :class="severityClass(alarm.severity)">
                                    {{ alarm.severity }}
                                </span>
                                <div class="min-w-0">
                                    <div class="truncate text-sm text-slate-900">{{ alarm.message }}</div>
                                    <div class="text-xs text-slate-500">{{ alarm.olt_name }} · {{ formatDate(alarm.last_seen_at) }}</div>
                                </div>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
