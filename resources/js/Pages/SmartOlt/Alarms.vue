<script setup>
import Pagination from '@/Components/Pagination.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { BellRing, Filter, RotateCcw, Search, ShieldCheck } from '@lucide/vue';
import { computed, reactive, watch } from 'vue';

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
    filterOptions: {
        type: Object,
        required: true,
    },
});

const rows = computed(() => props.alarms.data ?? []);

const cards = computed(() => [
    { key: 'critical', label: 'Critical', value: props.summary.critical, class: 'text-red-600' },
    { key: 'major', label: 'Major', value: props.summary.major, class: 'text-orange-600' },
    { key: 'minor', label: 'Minor', value: props.summary.minor, class: 'text-amber-600' },
    { key: 'warning', label: 'Warning', value: props.summary.warning, class: 'text-yellow-600' },
]);

const form = reactive({
    status: props.filter.status ?? 'active',
    severity: props.filter.severity ?? 'all',
    olt_id: props.filter.olt_id ?? '',
    scope: props.filter.scope ?? 'all',
    type: props.filter.type ?? 'all',
    q: props.filter.q ?? '',
});

watch(() => props.filter, (filter) => {
    form.status = filter.status ?? 'active';
    form.severity = filter.severity ?? 'all';
    form.olt_id = filter.olt_id ?? '';
    form.scope = filter.scope ?? 'all';
    form.type = filter.type ?? 'all';
    form.q = filter.q ?? '';
}, { deep: true });

const statusTitle = computed(() => ({
    active: 'Alarm Aktif',
    cleared: 'Alarm Selesai',
    all: 'Semua Alarm',
}[props.filter.status] ?? 'Alarm Aktif'));

const hasFilters = computed(() => (
    form.status !== 'active'
    || form.severity !== 'all'
    || form.olt_id !== ''
    || form.scope !== 'all'
    || form.type !== 'all'
    || form.q !== ''
));

const cleanFilters = () => {
    const filters = {};

    if (form.status !== 'active') filters.status = form.status;
    if (form.severity !== 'all') filters.severity = form.severity;
    if (form.olt_id !== '') filters.olt_id = form.olt_id;
    if (form.scope !== 'all') filters.scope = form.scope;
    if (form.type !== 'all') filters.type = form.type;
    if (form.q.trim() !== '') filters.q = form.q.trim();

    return filters;
};

const applyFilters = () => {
    router.get(route('alarms.index'), cleanFilters(), { preserveScroll: true, preserveState: true });
};

const resetFilters = () => {
    form.status = 'active';
    form.severity = 'all';
    form.olt_id = '';
    form.scope = 'all';
    form.type = 'all';
    form.q = '';

    router.get(route('alarms.index'), {}, { preserveScroll: true, preserveState: true });
};

const setStatus = (status) => {
    form.status = status;
    applyFilters();
};

const setSeverity = (severity) => {
    form.severity = form.severity === severity ? 'all' : severity;
    applyFilters();
};

const severityClass = (severity) => ({
    critical: 'bg-red-50 text-red-700 ring-1 ring-red-200',
    major: 'bg-orange-50 text-orange-700 ring-1 ring-orange-200',
    minor: 'bg-amber-50 text-amber-700 ring-1 ring-amber-200',
    warning: 'bg-yellow-50 text-yellow-700 ring-1 ring-yellow-200',
}[severity] ?? 'bg-slate-100 text-slate-600 ring-1 ring-slate-200');

const statusClass = (status) => status === 'active'
    ? 'bg-red-50 text-red-700 ring-1 ring-red-200'
    : 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200';

const scopeLabel = (alarm) => {
    if (alarm.scope === 'onu') {
        return alarm.serial_number || `gpon-onu_1/${alarm.slot}/${alarm.port}:${alarm.onu_id}`;
    }
    if (alarm.scope === 'port') {
        return `gpon-olt_1/${alarm.slot}/${alarm.port}`;
    }
    return 'OLT';
};

const scopeOptionLabel = (scope) => ({
    olt: 'OLT',
    port: 'Port',
    onu: 'ONU',
}[scope] ?? scope);

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
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <h2 class="text-lg font-semibold leading-tight sm:text-xl text-slate-800">Alarms</h2>
                <div class="grid grid-cols-3 overflow-hidden rounded-lg border border-sky-200 bg-white shadow-sm shadow-sky-100/60 sm:inline-flex sm:w-auto">
                    <button
                        type="button"
                        class="min-h-11 px-4 py-2 text-sm font-medium"
                        :class="form.status === 'active' ? 'bg-sky-600 text-white' : 'text-slate-500 hover:text-slate-700'"
                        @click="setStatus('active')"
                    >
                        Aktif
                    </button>
                    <button
                        type="button"
                        class="min-h-11 px-4 py-2 text-sm font-medium"
                        :class="form.status === 'cleared' ? 'bg-sky-600 text-white' : 'text-slate-500 hover:text-slate-700'"
                        @click="setStatus('cleared')"
                    >
                        Selesai
                    </button>
                    <button
                        type="button"
                        class="min-h-11 px-4 py-2 text-sm font-medium"
                        :class="form.status === 'all' ? 'bg-sky-600 text-white' : 'text-slate-500 hover:text-slate-700'"
                        @click="setStatus('all')"
                    >
                        Semua
                    </button>
                </div>
            </div>
        </template>

        <div class="min-h-[60vh] pt-5 pb-16 sm:pt-8">
            <div class="w-full space-y-6 px-4 sm:px-6 lg:px-8">
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <button
                        v-for="card in cards"
                        :key="card.key"
                        type="button"
                        class="rounded-lg border border-sky-200 bg-white p-5 text-left shadow-sm shadow-sky-100/60 transition hover:-translate-y-0.5"
                        :class="form.severity === card.key ? 'ring-2 ring-sky-500' : ''"
                        @click="setSeverity(card.key)"
                    >
                        <div class="text-sm font-medium text-slate-500">{{ card.label }}</div>
                        <div class="mt-2 text-3xl font-semibold" :class="card.class">{{ card.value }}</div>
                    </button>
                </div>

                <form class="overflow-hidden rounded-lg border border-sky-200 bg-white p-5 shadow-sm shadow-sky-100/60" @submit.prevent="applyFilters">
                    <div class="flex items-center gap-2 text-sm font-semibold text-slate-900">
                        <Filter class="h-4 w-4 text-slate-400" />
                        Filter
                    </div>

                    <div class="mt-4 grid gap-4 md:grid-cols-6">
                        <label class="block md:col-span-2">
                            <span class="text-xs font-medium uppercase text-slate-500">Cari</span>
                            <div class="mt-1 flex rounded-md border border-slate-300 bg-white focus-within:border-sky-500 focus-within:ring-1 focus-within:ring-sky-500">
                                <span class="flex items-center px-3 text-slate-400">
                                    <Search class="h-4 w-4" />
                                </span>
                                <input
                                    v-model="form.q"
                                    type="search"
                                    class="block min-h-11 w-full border-0 bg-transparent py-2.5 pr-3 text-sm text-slate-900 placeholder:text-slate-400 focus:ring-0"
                                    placeholder="Serial, pesan, tipe, OLT"
                                >
                            </div>
                        </label>

                        <label class="block">
                            <span class="text-xs font-medium uppercase text-slate-500">Severity</span>
                            <select v-model="form.severity" class="mt-1 block min-h-11 w-full rounded-md border border-slate-300 bg-white py-2.5 px-3 text-sm text-slate-900 shadow-sm focus:border-sky-500 focus:ring-sky-500">
                                <option value="all">Semua</option>
                                <option v-for="severity in filterOptions.severities" :key="severity" :value="severity">
                                    {{ severity }}
                                </option>
                            </select>
                        </label>

                        <label class="block">
                            <span class="text-xs font-medium uppercase text-slate-500">OLT</span>
                            <select v-model="form.olt_id" class="mt-1 block min-h-11 w-full rounded-md border border-slate-300 bg-white py-2.5 px-3 text-sm text-slate-900 shadow-sm focus:border-sky-500 focus:ring-sky-500">
                                <option value="">Semua</option>
                                <option v-for="olt in filterOptions.olts" :key="olt.id" :value="olt.id">
                                    {{ olt.name }}
                                </option>
                            </select>
                        </label>

                        <label class="block">
                            <span class="text-xs font-medium uppercase text-slate-500">Scope</span>
                            <select v-model="form.scope" class="mt-1 block min-h-11 w-full rounded-md border border-slate-300 bg-white py-2.5 px-3 text-sm text-slate-900 shadow-sm focus:border-sky-500 focus:ring-sky-500">
                                <option value="all">Semua</option>
                                <option v-for="scope in filterOptions.scopes" :key="scope" :value="scope">
                                    {{ scopeOptionLabel(scope) }}
                                </option>
                            </select>
                        </label>

                        <label class="block">
                            <span class="text-xs font-medium uppercase text-slate-500">Tipe</span>
                            <select v-model="form.type" class="mt-1 block min-h-11 w-full rounded-md border border-slate-300 bg-white py-2.5 px-3 text-sm text-slate-900 shadow-sm focus:border-sky-500 focus:ring-sky-500">
                                <option value="all">Semua</option>
                                <option v-for="type in filterOptions.types" :key="type" :value="type">
                                    {{ type }}
                                </option>
                            </select>
                        </label>
                    </div>

                    <div class="mt-4 grid gap-2 sm:flex sm:flex-wrap sm:items-center sm:justify-end">
                        <button
                            type="button"
                            class="inline-flex min-h-11 items-center justify-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="!hasFilters"
                            @click="resetFilters"
                        >
                            <RotateCcw class="h-4 w-4" />
                            Reset
                        </button>
                        <button type="submit" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-md bg-sky-600 px-3 py-2.5 text-sm font-medium text-white hover:bg-sky-700">
                            <Search class="h-4 w-4" />
                            Terapkan
                        </button>
                    </div>
                </form>

                <div class="overflow-hidden rounded-lg border border-sky-200 bg-white shadow-sm shadow-sky-100/60">
                    <div class="flex items-center gap-3 border-b border-slate-100 px-4 py-4 sm:px-6">
                        <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-red-100 ring-1 ring-red-200">
                            <BellRing class="h-5 w-5 text-red-600" />
                        </div>
                        <div>
                            <h3 class="text-base font-semibold text-slate-900">
                                {{ statusTitle }}
                            </h3>
                            <p class="mt-0.5 text-xs text-slate-500">Hasil evaluasi otomatis dari background poll.</p>
                        </div>
                    </div>

                    <div v-if="rows.length === 0" class="px-6 py-12 text-center">
                        <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-slate-100 ring-1 ring-slate-200">
                            <ShieldCheck class="h-7 w-7 text-slate-400" />
                        </div>
                        <h3 class="text-sm font-semibold text-slate-900">Tidak ada alarm</h3>
                        <p class="mt-1 text-sm text-slate-500">
                            {{ hasFilters ? 'Tidak ada alarm yang cocok dengan filter.' : 'Semua kondisi normal pada poll terakhir.' }}
                        </p>
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="min-w-[720px] w-full">
                            <thead>
                                <tr class="border-b border-slate-100 bg-slate-50">
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Severity</th>
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Tipe</th>
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">OLT / Target</th>
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Pesan</th>
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Status</th>
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Terakhir</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="alarm in rows" :key="alarm.id" class="transition-colors duration-150 hover:bg-slate-50">
                                    <td class="px-4 py-4">
                                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium uppercase" :class="severityClass(alarm.severity)">
                                            {{ alarm.severity }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-4 text-sm font-medium text-slate-900">{{ alarm.type }}</td>
                                    <td class="px-4 py-4 text-sm text-slate-700">
                                        <Link :href="route('smartolt.detail', alarm.olt.id)" class="font-medium text-sky-600 hover:text-sky-500">
                                            {{ alarm.olt.name }}
                                        </Link>
                                        <div v-if="alarm.customer_name" class="mt-1 text-sm font-medium text-slate-900">
                                            {{ alarm.customer_name }}
                                        </div>
                                        <div class="text-xs text-slate-500">{{ scopeLabel(alarm) }}</div>
                                    </td>
                                    <td class="px-4 py-4 text-sm text-slate-700">{{ alarm.message }}</td>
                                    <td class="px-4 py-4">
                                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium" :class="statusClass(alarm.status)">
                                            {{ alarm.status }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-4 text-sm text-slate-700">
                                        <div>{{ formatDate(alarm.last_seen_at) }}</div>
                                        <div class="text-xs text-slate-500">sejak {{ formatDate(alarm.first_seen_at) }}</div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="rows.length > 0" class="flex flex-col items-center justify-between gap-3 border-t border-slate-100 px-6 py-4 sm:flex-row">
                        <p class="text-sm text-slate-500">
                            Menampilkan {{ alarms.from }}–{{ alarms.to }} dari {{ alarms.total }} alarm
                        </p>
                        <Pagination :links="alarms.links" />
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
