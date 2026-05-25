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
        chart: { type: 'donut' },
        labels: ['Online', 'Offline'],
        colors: ['#10b981', '#9ca3af'],
        legend: { position: 'bottom' },
        dataLabels: { enabled: true, formatter: (v) => Math.round(v) + '%' },
        plotOptions: { pie: { donut: { labels: { show: true, total: { show: true, label: 'Total ONU', formatter: () => props.stats.onu_total } } } } },
        stroke: { width: 0 },
    },
}));

const alarmChart = computed(() => ({
    series: [props.alarms.critical, props.alarms.major, props.alarms.minor, props.alarms.warning],
    options: {
        chart: { type: 'donut' },
        labels: ['Critical', 'Major', 'Minor', 'Warning'],
        colors: ['#dc2626', '#ea580c', '#d97706', '#ca8a04'],
        legend: { position: 'bottom' },
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
        chart: { type: 'bar', stacked: true, toolbar: { show: false } },
        plotOptions: { bar: { horizontal: true, borderRadius: 4 } },
        colors: ['#10b981', '#9ca3af'],
        xaxis: { categories: props.olts.map((o) => o.name) },
        legend: { position: 'top' },
        dataLabels: { enabled: false },
    },
}));

const cards = computed(() => [
    { label: 'OLT Online', value: `${props.stats.olts_online}/${props.stats.olts_total}`, icon: Server, class: 'text-sky-600' },
    { label: 'ONU Online', value: props.stats.onu_online, icon: Wifi, class: 'text-emerald-600' },
    { label: 'ONU Offline', value: props.stats.onu_offline, icon: Wifi, class: 'text-gray-500' },
    { label: 'Alarm Critical', value: props.alarms.critical, icon: BellRing, class: 'text-red-600' },
]);

const severityClass = (severity) => ({
    critical: 'bg-red-100 text-red-800',
    major: 'bg-orange-100 text-orange-800',
    minor: 'bg-amber-100 text-amber-800',
    warning: 'bg-yellow-100 text-yellow-800',
}[severity] ?? 'bg-gray-100 text-gray-700');

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

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div v-for="card in cards" :key="card.label" class="flex items-center gap-4 rounded-lg bg-white p-5 shadow-sm">
                        <div class="flex h-12 w-12 flex-none items-center justify-center rounded-full bg-gray-50" :class="card.class">
                            <component :is="card.icon" class="h-6 w-6" />
                        </div>
                        <div>
                            <div class="text-sm font-medium text-gray-500">{{ card.label }}</div>
                            <div class="mt-0.5 text-2xl font-semibold text-gray-900">{{ card.value }}</div>
                        </div>
                    </div>
                </div>

                <div class="grid gap-6 lg:grid-cols-3">
                    <div class="rounded-lg bg-white p-6 shadow-sm">
                        <h3 class="mb-2 flex items-center gap-2 text-base font-semibold text-gray-900">
                            <Wifi class="h-5 w-5 text-gray-400" /> ONU Online / Offline
                        </h3>
                        <VueApexCharts v-if="stats.onu_total > 0" type="donut" height="280" :options="onuChart.options" :series="onuChart.series" />
                        <p v-else class="py-16 text-center text-sm text-gray-400">Belum ada data ONU. Jalankan poll.</p>
                    </div>

                    <div class="rounded-lg bg-white p-6 shadow-sm">
                        <h3 class="mb-2 flex items-center gap-2 text-base font-semibold text-gray-900">
                            <BellRing class="h-5 w-5 text-gray-400" /> Alarm Aktif
                        </h3>
                        <VueApexCharts v-if="alarms.total > 0" type="donut" height="280" :options="alarmChart.options" :series="alarmChart.series" />
                        <p v-else class="py-16 text-center text-sm text-gray-400">Tidak ada alarm aktif.</p>
                    </div>

                    <div class="rounded-lg bg-white p-6 shadow-sm">
                        <h3 class="mb-2 flex items-center gap-2 text-base font-semibold text-gray-900">
                            <Activity class="h-5 w-5 text-gray-400" /> ONU per OLT
                        </h3>
                        <VueApexCharts v-if="olts.length > 0" type="bar" height="280" :options="oltOnuChart.options" :series="oltOnuChart.series" />
                        <p v-else class="py-16 text-center text-sm text-gray-400">Belum ada OLT.</p>
                    </div>
                </div>

                <div class="grid gap-6 lg:grid-cols-5">
                    <div class="rounded-lg bg-white shadow-sm lg:col-span-3">
                        <div class="flex items-center gap-3 border-b border-gray-200 px-6 py-4">
                            <Cable class="h-5 w-5 text-gray-500" />
                            <h3 class="text-base font-semibold text-gray-900">Status per OLT</h3>
                        </div>
                        <div v-if="olts.length === 0" class="px-6 py-10 text-center text-sm text-gray-500">Belum ada OLT.</div>
                        <div v-else class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">OLT</th>
                                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Port</th>
                                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">ONU</th>
                                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Poll</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    <tr v-for="olt in olts" :key="olt.id">
                                        <td class="px-6 py-4">
                                            <Link :href="route('smartolt.detail', olt.id)" class="font-medium text-indigo-600 hover:underline">{{ olt.name }}</Link>
                                            <div class="mt-1">
                                                <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium" :class="olt.reachable ? 'bg-emerald-100 text-emerald-800' : 'bg-gray-100 text-gray-600'">
                                                    {{ olt.reachable ? 'Online' : 'Unknown' }}
                                                </span>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-700">
                                            <span class="text-emerald-700">{{ olt.ports_up }} up</span>
                                            <span v-if="olt.ports_down" class="text-red-700"> · {{ olt.ports_down }} down</span>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-700">
                                            <span class="font-medium text-emerald-700">{{ olt.onu_online }}</span> / {{ olt.onu_total }} online
                                        </td>
                                        <td class="px-6 py-4 text-xs text-gray-500">{{ formatDate(olt.last_polled_at) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="rounded-lg bg-white shadow-sm lg:col-span-2">
                        <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4">
                            <div class="flex items-center gap-3">
                                <BellRing class="h-5 w-5 text-gray-500" />
                                <h3 class="text-base font-semibold text-gray-900">Alarm Terbaru</h3>
                            </div>
                            <Link :href="route('alarms.index')" class="text-sm font-medium text-indigo-600 hover:underline">Lihat semua</Link>
                        </div>
                        <div v-if="recent_alarms.length === 0" class="px-6 py-10 text-center text-sm text-gray-500">Tidak ada alarm aktif.</div>
                        <ul v-else class="divide-y divide-gray-100">
                            <li v-for="alarm in recent_alarms" :key="alarm.id" class="flex items-start gap-3 px-6 py-3">
                                <span class="mt-0.5 inline-flex flex-none rounded-full px-2 py-0.5 text-xs font-medium uppercase" :class="severityClass(alarm.severity)">
                                    {{ alarm.severity }}
                                </span>
                                <div class="min-w-0">
                                    <div class="truncate text-sm text-gray-800">{{ alarm.message }}</div>
                                    <div class="text-xs text-gray-500">{{ alarm.olt_name }} · {{ formatDate(alarm.last_seen_at) }}</div>
                                </div>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
