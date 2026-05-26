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
        legend: { position: 'bottom', labels: { colors: '#94a3b8' } },
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
        legend: { position: 'bottom', labels: { colors: '#94a3b8' } },
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
            labels: { style: { fontSize: '11px', colors: '#94a3b8' }, rotate: -30, trim: true, maxHeight: 60 },
        },
        yaxis: { labels: { formatter: (v) => Math.round(v), style: { colors: '#94a3b8' } } },
        legend: { position: 'top', labels: { colors: '#94a3b8' } },
        dataLabels: { enabled: false },
        grid: { borderColor: 'rgba(255,255,255,0.06)' },
    },
}));

const cards = computed(() => [
    { label: 'OLT Online', value: `${props.stats.olts_online}/${props.stats.olts_total}`, icon: Server, class: 'text-sky-600' },
    { label: 'ONU Online', value: props.stats.onu_online, icon: Wifi, class: 'text-emerald-600' },
    { label: 'ONU Offline', value: props.stats.onu_offline, icon: Wifi, class: 'text-gray-500' },
    { label: 'Alarm Critical', value: props.alarms.critical, icon: BellRing, class: 'text-red-600' },
]);

const severityClass = (severity) => ({
    critical: 'bg-red-500/15 text-red-300 ring-1 ring-red-500/25',
    major: 'bg-orange-500/15 text-orange-300 ring-1 ring-orange-500/25',
    minor: 'bg-amber-500/15 text-amber-300 ring-1 ring-amber-500/25',
    warning: 'bg-yellow-500/15 text-yellow-300 ring-1 ring-yellow-500/25',
}[severity] ?? 'bg-slate-500/15 text-slate-400 ring-1 ring-slate-500/25');

const formatDate = (value) => {
    if (!value) return '-';

    return new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
};
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Dashboard</h2>
        </template>

        <div class="bg-gradient-to-br from-slate-900 via-slate-800 to-indigo-950 py-8 pb-16 min-h-[60vh]">
            <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div v-for="card in cards" :key="card.label" class="rounded-xl border border-white/10 bg-white/[0.06] p-5 backdrop-blur-xl">
                        <p class="text-xs font-medium uppercase tracking-wider text-slate-400">{{ card.label }}</p>
                        <p class="mt-3 text-2xl font-bold text-white">{{ card.value }}</p>
                    </div>
                </div>

                <div class="grid gap-6 lg:grid-cols-3">
                    <div class="overflow-hidden rounded-2xl border border-white/10 bg-white/[0.06] p-6 shadow-2xl backdrop-blur-xl">
                        <h3 class="mb-2 flex items-center gap-2 text-base font-semibold text-white">
                            <Wifi class="h-5 w-5 text-slate-400" /> ONU Online / Offline
                        </h3>
                        <VueApexCharts v-if="stats.onu_total > 0" type="donut" height="280" :options="onuChart.options" :series="onuChart.series" />
                        <p v-else class="py-16 text-center text-sm text-slate-400">Belum ada data ONU. Jalankan poll.</p>
                    </div>

                    <div class="overflow-hidden rounded-2xl border border-white/10 bg-white/[0.06] p-6 shadow-2xl backdrop-blur-xl">
                        <h3 class="mb-2 flex items-center gap-2 text-base font-semibold text-white">
                            <BellRing class="h-5 w-5 text-slate-400" /> Alarm Aktif
                        </h3>
                        <VueApexCharts v-if="alarms.total > 0" type="donut" height="280" :options="alarmChart.options" :series="alarmChart.series" />
                        <p v-else class="py-16 text-center text-sm text-slate-400">Tidak ada alarm aktif.</p>
                    </div>

                    <div class="overflow-hidden rounded-2xl border border-white/10 bg-white/[0.06] p-6 shadow-2xl backdrop-blur-xl">
                        <h3 class="mb-2 flex items-center gap-2 text-base font-semibold text-white">
                            <Activity class="h-5 w-5 text-slate-400" /> ONU per OLT
                        </h3>
                        <VueApexCharts v-if="olts.length > 0" type="bar" height="280" :options="oltOnuChart.options" :series="oltOnuChart.series" />
                        <p v-else class="py-16 text-center text-sm text-slate-400">Belum ada OLT.</p>
                    </div>
                </div>

                <div class="grid gap-6 lg:grid-cols-5">
                    <div class="overflow-hidden rounded-2xl border border-white/10 bg-white/[0.06] shadow-2xl backdrop-blur-xl lg:col-span-3">
                        <div class="flex items-center gap-3 border-b border-white/10 px-6 py-5">
                            <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-sky-500/20 ring-1 ring-sky-500/30">
                                <Cable class="h-5 w-5 text-sky-400" />
                            </div>
                            <div>
                                <h3 class="text-base font-semibold text-white">Status per OLT</h3>
                                <p class="text-xs text-slate-400">Ringkasan kondisi setiap OLT</p>
                            </div>
                        </div>
                        <div v-if="olts.length === 0" class="px-6 py-10 text-center text-sm text-slate-400">Belum ada OLT.</div>
                        <div v-else class="overflow-x-auto">
                            <table class="min-w-full">
                                <thead>
                                    <tr class="border-b border-white/[0.06] bg-white/[0.03]">
                                        <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">OLT</th>
                                        <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">Port</th>
                                        <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">ONU</th>
                                        <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">Poll</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-white/[0.05]">
                                    <tr v-for="olt in olts" :key="olt.id" class="transition-colors duration-150 hover:bg-white/[0.04]">
                                        <td class="px-6 py-4">
                                            <Link :href="route('smartolt.detail', olt.id)" class="font-medium text-indigo-400 hover:text-indigo-300">{{ olt.name }}</Link>
                                            <div class="mt-1">
                                                <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium" :class="olt.reachable ? 'bg-emerald-500/15 text-emerald-300 ring-1 ring-emerald-500/25' : 'bg-slate-500/15 text-slate-400 ring-1 ring-slate-500/25'">
                                                    {{ olt.reachable ? 'Online' : 'Unknown' }}
                                                </span>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-slate-300">
                                            <span class="text-emerald-400">{{ olt.ports_up }} up</span>
                                            <span v-if="olt.ports_down" class="text-red-400"> · {{ olt.ports_down }} down</span>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-slate-300">
                                            <span class="font-medium text-emerald-400">{{ olt.onu_online }}</span> / {{ olt.onu_total }} online
                                        </td>
                                        <td class="px-6 py-4 text-xs text-slate-500">{{ formatDate(olt.last_polled_at) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="overflow-hidden rounded-2xl border border-white/10 bg-white/[0.06] shadow-2xl backdrop-blur-xl lg:col-span-2">
                        <div class="flex items-center justify-between border-b border-white/10 px-6 py-5">
                            <div class="flex items-center gap-3">
                                <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-red-500/20 ring-1 ring-red-500/30">
                                    <BellRing class="h-5 w-5 text-red-400" />
                                </div>
                                <div>
                                    <h3 class="text-base font-semibold text-white">Alarm Terbaru</h3>
                                    <p class="text-xs text-slate-400">Alarm aktif terkini</p>
                                </div>
                            </div>
                            <Link :href="route('alarms.index')" class="text-sm font-medium text-indigo-400 hover:text-indigo-300">Lihat semua</Link>
                        </div>
                        <div v-if="recent_alarms.length === 0" class="px-6 py-10 text-center text-sm text-slate-400">Tidak ada alarm aktif.</div>
                        <ul v-else class="divide-y divide-white/[0.05]">
                            <li v-for="alarm in recent_alarms" :key="alarm.id" class="flex items-start gap-3 px-6 py-3 transition-colors duration-150 hover:bg-white/[0.04]">
                                <span class="mt-0.5 inline-flex flex-none rounded-full px-2.5 py-0.5 text-xs font-medium uppercase" :class="severityClass(alarm.severity)">
                                    {{ alarm.severity }}
                                </span>
                                <div class="min-w-0">
                                    <div class="truncate text-sm text-slate-200">{{ alarm.message }}</div>
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
