<script setup>
import Pagination from '@/Components/Pagination.vue';
import FilterCard from '@/Components/Shell/FilterCard.vue';
import { formatDateTime } from '@/lib/datetime';
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
    { key: 'critical', label: 'Critical', value: props.summary.critical, class: 'text-red-400' },
    { key: 'major', label: 'Major', value: props.summary.major, class: 'text-orange-400' },
    { key: 'minor', label: 'Minor', value: props.summary.minor, class: 'text-amber-400' },
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
    critical: 'bg-red-500/15 text-red-300 ring-1 ring-red-500/30',
    major: 'bg-orange-500/15 text-orange-300 ring-1 ring-orange-500/30',
    minor: 'bg-amber-500/15 text-amber-300 ring-1 ring-amber-500/30',
    warning: 'bg-yellow-500/15 text-yellow-300 ring-1 ring-yellow-200',
}[severity] ?? 'bg-slate-800/60 text-slate-300 ring-1 ring-slate-500/30');

const statusClass = (status) => status === 'active'
    ? 'bg-red-500/15 text-red-300 ring-1 ring-red-500/30'
    : 'bg-emerald-500/15 text-emerald-300 ring-1 ring-emerald-500/30';

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

    return formatDateTime(value);
};
</script>

<template>
    <Head title="Alarms" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <h2 class="text-lg font-semibold leading-tight sm:text-xl text-white">Alarms</h2>
                <div class="grid grid-cols-3 overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl sm:inline-flex sm:w-auto">
                    <button
                        type="button"
                        class="min-h-11 px-4 py-2 text-sm font-medium"
                        :class="form.status === 'active' ? 'bg-cyan-500 text-white' : 'text-slate-500 hover:text-slate-200'"
                        @click="setStatus('active')"
                    >
                        Aktif
                    </button>
                    <button
                        type="button"
                        class="min-h-11 px-4 py-2 text-sm font-medium"
                        :class="form.status === 'cleared' ? 'bg-cyan-500 text-white' : 'text-slate-500 hover:text-slate-200'"
                        @click="setStatus('cleared')"
                    >
                        Selesai
                    </button>
                    <button
                        type="button"
                        class="min-h-11 px-4 py-2 text-sm font-medium"
                        :class="form.status === 'all' ? 'bg-cyan-500 text-white' : 'text-slate-500 hover:text-slate-200'"
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
                        class="rounded-lg border border-white/10 bg-slate-900/40 backdrop-blur-xl p-5 text-left shadow-sm shadow-black/30 transition hover:-translate-y-0.5"
                        :class="form.severity === card.key ? 'ring-2 ring-cyan-500' : ''"
                        @click="setSeverity(card.key)"
                    >
                        <div class="text-sm font-medium text-slate-500">{{ card.label }}</div>
                        <div class="mt-2 text-3xl font-semibold" :class="card.class">{{ card.value }}</div>
                    </button>
                </div>

                <FilterCard title="Filter" :icon="Filter">
                    <form class="flex flex-wrap items-center gap-2" @submit.prevent="applyFilters">
                        <div class="relative w-full lg:flex-1 lg:min-w-[16rem]">
                            <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" />
                            <input
                                v-model="form.q"
                                type="search"
                                placeholder="Cari serial, pesan, tipe, OLT…"
                                class="kv-filter-control !pl-9"
                            >
                        </div>
                        <select v-model="form.severity" class="kv-filter-control w-full sm:w-auto">
                            <option value="all">Semua Severity</option>
                            <option v-for="severity in filterOptions.severities" :key="severity" :value="severity">{{ severity }}</option>
                        </select>
                        <select v-model="form.olt_id" class="kv-filter-control w-full sm:w-auto">
                            <option value="">Semua OLT</option>
                            <option v-for="olt in filterOptions.olts" :key="olt.id" :value="olt.id">{{ olt.name }}</option>
                        </select>
                        <select v-model="form.scope" class="kv-filter-control w-full sm:w-auto">
                            <option value="all">Semua Scope</option>
                            <option v-for="scope in filterOptions.scopes" :key="scope" :value="scope">{{ scopeOptionLabel(scope) }}</option>
                        </select>
                        <select v-model="form.type" class="kv-filter-control w-full sm:w-auto">
                            <option value="all">Semua Tipe</option>
                            <option v-for="type in filterOptions.types" :key="type" :value="type">{{ type }}</option>
                        </select>
                        <button type="button" class="kv-filter-reset w-full sm:w-auto" :disabled="!hasFilters" @click="resetFilters">
                            <RotateCcw class="h-4 w-4" />
                            Reset
                        </button>
                        <button type="submit" class="kv-filter-apply w-full sm:w-auto">
                            <Search class="h-4 w-4" />
                            Terapkan
                        </button>
                    </form>
                </FilterCard>

                <div class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
                    <div class="flex items-center gap-3 border-b border-white/10 px-4 py-4 sm:px-6">
                        <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-red-500/20 ring-1 ring-red-500/30">
                            <BellRing class="h-5 w-5 text-red-400" />
                        </div>
                        <div>
                            <h3 class="text-base font-semibold text-white">
                                {{ statusTitle }}
                            </h3>
                            <p class="mt-0.5 text-xs text-slate-500">Hasil evaluasi otomatis dari background poll.</p>
                        </div>
                    </div>

                    <div v-if="rows.length === 0" class="px-6 py-12 text-center">
                        <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-slate-800/60 ring-1 ring-slate-500/30">
                            <ShieldCheck class="h-7 w-7 text-slate-400" />
                        </div>
                        <h3 class="text-sm font-semibold text-white">Tidak ada alarm</h3>
                        <p class="mt-1 text-sm text-slate-500">
                            {{ hasFilters ? 'Tidak ada alarm yang cocok dengan filter.' : 'Semua kondisi normal pada poll terakhir.' }}
                        </p>
                    </div>

                    <template v-else>
                        <div class="kv-mobile-list">
                            <article v-for="alarm in rows" :key="alarm.id" class="kv-mobile-card">
                                <div class="kv-mobile-card-header">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium uppercase" :class="severityClass(alarm.severity)">
                                                {{ alarm.severity }}
                                            </span>
                                            <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium" :class="statusClass(alarm.status)">
                                                {{ alarm.status }}
                                            </span>
                                        </div>
                                        <h4 class="mt-3 kv-mobile-card-title">{{ alarm.type }}</h4>
                                        <p class="kv-mobile-card-subtitle">{{ alarm.message }}</p>
                                    </div>
                                </div>
                                <div class="kv-mobile-fields">
                                    <div class="kv-mobile-field">
                                        <span class="kv-mobile-label">OLT</span>
                                        <Link :href="route('smartolt.detail', alarm.olt.id)" class="kv-mobile-value font-medium text-cyan-400 hover:text-cyan-300">
                                            {{ alarm.olt.name }}
                                        </Link>
                                    </div>
                                    <div v-if="alarm.customer_name" class="kv-mobile-field">
                                        <span class="kv-mobile-label">Customer</span>
                                        <span class="kv-mobile-value">{{ alarm.customer_name }}</span>
                                    </div>
                                    <div class="kv-mobile-field">
                                        <span class="kv-mobile-label">Target</span>
                                        <span class="kv-mobile-value">{{ scopeLabel(alarm) }}</span>
                                    </div>
                                    <div class="kv-mobile-field">
                                        <span class="kv-mobile-label">Terakhir</span>
                                        <span class="kv-mobile-value">{{ formatDate(alarm.last_seen_at) }}</span>
                                    </div>
                                    <div class="kv-mobile-field">
                                        <span class="kv-mobile-label">Sejak</span>
                                        <span class="kv-mobile-value">{{ formatDate(alarm.first_seen_at) }}</span>
                                    </div>
                                </div>
                            </article>
                        </div>

                        <div class="kv-table-desktop">
                        <table class="min-w-[720px] w-full">
                            <thead>
                                <tr class="border-b border-white/10 bg-slate-950/40">
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Severity</th>
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Tipe</th>
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">OLT / Target</th>
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Pesan</th>
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Status</th>
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Terakhir</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/5">
                                <tr v-for="alarm in rows" :key="alarm.id" class="transition-colors duration-150 hover:bg-white/[0.03]">
                                    <td class="px-4 py-4">
                                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium uppercase" :class="severityClass(alarm.severity)">
                                            {{ alarm.severity }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-4 text-sm font-medium text-white">{{ alarm.type }}</td>
                                    <td class="px-4 py-4 text-sm text-slate-200">
                                        <Link :href="route('smartolt.detail', alarm.olt.id)" class="font-medium text-cyan-400 hover:text-cyan-400">
                                            {{ alarm.olt.name }}
                                        </Link>
                                        <div v-if="alarm.customer_name" class="mt-1 text-sm font-medium text-white">
                                            {{ alarm.customer_name }}
                                        </div>
                                        <div class="text-xs text-slate-500">{{ scopeLabel(alarm) }}</div>
                                    </td>
                                    <td class="px-4 py-4 text-sm text-slate-200">{{ alarm.message }}</td>
                                    <td class="px-4 py-4">
                                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium" :class="statusClass(alarm.status)">
                                            {{ alarm.status }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-4 text-sm text-slate-200">
                                        <div>{{ formatDate(alarm.last_seen_at) }}</div>
                                        <div class="text-xs text-slate-500">sejak {{ formatDate(alarm.first_seen_at) }}</div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        </div>
                    </template>

                    <div v-if="rows.length > 0" class="flex flex-col items-center justify-between gap-3 border-t border-white/10 px-6 py-4 sm:flex-row">
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
