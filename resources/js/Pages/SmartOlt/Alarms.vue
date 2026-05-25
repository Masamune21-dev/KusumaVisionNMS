<script setup>
import Pagination from '@/Components/Pagination.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { BellRing, ShieldCheck } from '@lucide/vue';
import { computed } from 'vue';

const props = defineProps({
    alarms: {
        type: Object,
        required: true,
    },
    summary: {
        type: Object,
        required: true,
    },
    filter: {
        type: Object,
        required: true,
    },
});

const rows = computed(() => props.alarms.data ?? []);

const cards = computed(() => [
    { key: 'critical', label: 'Critical', value: props.summary.critical, class: 'text-red-700' },
    { key: 'major', label: 'Major', value: props.summary.major, class: 'text-orange-700' },
    { key: 'minor', label: 'Minor', value: props.summary.minor, class: 'text-amber-700' },
    { key: 'warning', label: 'Warning', value: props.summary.warning, class: 'text-yellow-700' },
]);

const setStatus = (status) => {
    router.get(route('alarms.index'), { status }, { preserveScroll: true, preserveState: true });
};

const severityClass = (severity) => ({
    critical: 'bg-red-100 text-red-800',
    major: 'bg-orange-100 text-orange-800',
    minor: 'bg-amber-100 text-amber-800',
    warning: 'bg-yellow-100 text-yellow-800',
}[severity] ?? 'bg-gray-100 text-gray-700');

const statusClass = (status) => status === 'active'
    ? 'bg-red-100 text-red-800'
    : 'bg-emerald-100 text-emerald-800';

const scopeLabel = (alarm) => {
    if (alarm.scope === 'onu') {
        return alarm.serial_number || `gpon-onu_1/${alarm.slot}/${alarm.port}:${alarm.onu_id}`;
    }
    if (alarm.scope === 'port') {
        return `gpon-olt_1/${alarm.slot}/${alarm.port}`;
    }
    return 'OLT';
};

const formatDate = (value) => {
    if (!value) return '-';

    return new Intl.DateTimeFormat('id-ID', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
};
</script>

<template>
    <Head title="Alarms" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">Alarms</h2>
                <div class="inline-flex overflow-hidden rounded-md border border-gray-300">
                    <button
                        type="button"
                        class="px-4 py-2 text-sm font-medium"
                        :class="filter.status === 'active' ? 'bg-indigo-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-50'"
                        @click="setStatus('active')"
                    >
                        Aktif
                    </button>
                    <button
                        type="button"
                        class="px-4 py-2 text-sm font-medium"
                        :class="filter.status === 'all' ? 'bg-indigo-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-50'"
                        @click="setStatus('all')"
                    >
                        Semua
                    </button>
                </div>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                <div class="grid gap-4 sm:grid-cols-4">
                    <div v-for="card in cards" :key="card.key" class="rounded-lg bg-white p-5 shadow-sm">
                        <div class="text-sm font-medium text-gray-500">{{ card.label }}</div>
                        <div class="mt-2 text-3xl font-semibold" :class="card.class">{{ card.value }}</div>
                    </div>
                </div>

                <div class="rounded-lg bg-white shadow-sm">
                    <div class="flex items-center gap-3 border-b border-gray-200 px-6 py-4">
                        <BellRing class="h-5 w-5 text-gray-500" />
                        <div>
                            <h3 class="text-base font-semibold text-gray-900">
                                {{ filter.status === 'active' ? 'Alarm Aktif' : 'Semua Alarm' }}
                            </h3>
                            <p class="text-sm text-gray-500">Hasil evaluasi otomatis dari background poll.</p>
                        </div>
                    </div>

                    <div v-if="rows.length === 0" class="px-6 py-12 text-center">
                        <ShieldCheck class="mx-auto h-10 w-10 text-emerald-300" />
                        <h3 class="mt-3 text-sm font-semibold text-gray-900">Tidak ada alarm</h3>
                        <p class="mt-1 text-sm text-gray-500">
                            {{ filter.status === 'active' ? 'Semua kondisi normal pada poll terakhir.' : 'Belum ada riwayat alarm.' }}
                        </p>
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Severity</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Tipe</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">OLT / Target</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Pesan</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Terakhir</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <tr v-for="alarm in rows" :key="alarm.id">
                                    <td class="px-6 py-4">
                                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium uppercase" :class="severityClass(alarm.severity)">
                                            {{ alarm.severity }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ alarm.type }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-700">
                                        <Link :href="route('smartolt.detail', alarm.olt.id)" class="font-medium text-indigo-600 hover:underline">
                                            {{ alarm.olt.name }}
                                        </Link>
                                        <div class="text-xs text-gray-500">{{ scopeLabel(alarm) }}</div>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-700">{{ alarm.message }}</td>
                                    <td class="px-6 py-4">
                                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium" :class="statusClass(alarm.status)">
                                            {{ alarm.status }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-700">
                                        <div>{{ formatDate(alarm.last_seen_at) }}</div>
                                        <div class="text-xs text-gray-500">sejak {{ formatDate(alarm.first_seen_at) }}</div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="rows.length > 0" class="flex flex-col items-center justify-between gap-3 border-t border-gray-200 px-6 py-4 sm:flex-row">
                        <p class="text-sm text-gray-500">
                            Menampilkan {{ alarms.from }}–{{ alarms.to }} dari {{ alarms.total }} alarm
                        </p>
                        <Pagination :links="alarms.links" />
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
