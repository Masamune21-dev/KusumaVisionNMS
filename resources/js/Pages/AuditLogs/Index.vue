<script setup>
import Pagination from '@/Components/Pagination.vue';
import FilterCard from '@/Components/Shell/FilterCard.vue';
import { formatDateTime } from '@/lib/datetime';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { ChevronDown, Filter, RotateCcw, ScrollText, Search } from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps({
    logs: { type: Object, required: true },
    filter: { type: Object, required: true },
    filterOptions: { type: Object, required: true },
});

const rows = computed(() => props.logs.data ?? []);
const expanded = ref(new Set());

const toggle = (id) => {
    const next = new Set(expanded.value);
    next.has(id) ? next.delete(id) : next.add(id);
    expanded.value = next;
};

const form = reactive({
    event: props.filter.event ?? 'all',
    user_id: props.filter.user_id ?? '',
    q: props.filter.q ?? '',
    from: props.filter.from ?? '',
    to: props.filter.to ?? '',
});

watch(() => props.filter, (filter) => {
    form.event = filter.event ?? 'all';
    form.user_id = filter.user_id ?? '';
    form.q = filter.q ?? '';
    form.from = filter.from ?? '';
    form.to = filter.to ?? '';
}, { deep: true });

const hasFilters = computed(() => (
    form.event !== 'all'
    || form.user_id !== ''
    || form.q !== ''
    || form.from !== ''
    || form.to !== ''
));

const cleanFilters = () => {
    const filters = {};
    if (form.event !== 'all') filters.event = form.event;
    if (form.user_id !== '') filters.user_id = form.user_id;
    if (form.q.trim() !== '') filters.q = form.q.trim();
    if (form.from !== '') filters.from = form.from;
    if (form.to !== '') filters.to = form.to;
    return filters;
};

const applyFilters = () => {
    router.get(route('audit-logs.index'), cleanFilters(), { preserveScroll: true, preserveState: true });
};

const resetFilters = () => {
    form.event = 'all';
    form.user_id = '';
    form.q = '';
    form.from = '';
    form.to = '';
    router.get(route('audit-logs.index'), {}, { preserveScroll: true, preserveState: true });
};

const EVENT_META = {
    created: { label: 'Dibuat', class: 'bg-emerald-500/15 text-emerald-300 ring-1 ring-emerald-500/30' },
    updated: { label: 'Diperbarui', class: 'bg-cyan-500/15 text-cyan-300 ring-1 ring-cyan-500/30' },
    deleted: { label: 'Dihapus', class: 'bg-red-500/15 text-red-300 ring-1 ring-red-500/30' },
    login: { label: 'Login', class: 'bg-sky-500/15 text-sky-300 ring-1 ring-sky-500/30' },
    logout: { label: 'Logout', class: 'bg-slate-500/15 text-slate-300 ring-1 ring-slate-500/30' },
    login_failed: { label: 'Login Gagal', class: 'bg-orange-500/15 text-orange-300 ring-1 ring-orange-500/30' },
    telnet_opened: { label: 'Telnet', class: 'bg-violet-500/15 text-violet-300 ring-1 ring-violet-500/30' },
};

const eventMeta = (event) => EVENT_META[event] ?? { label: event, class: 'bg-slate-800/60 text-slate-300 ring-1 ring-slate-500/30' };

const initials = (name) => (name || '?')
    .split(' ')
    .map((part) => part.charAt(0))
    .slice(0, 2)
    .join('')
    .toUpperCase();

const formatValue = (value) => {
    if (value === null || value === undefined || value === '') return '—';
    if (typeof value === 'boolean') return value ? 'ya' : 'tidak';
    if (typeof value === 'object') return JSON.stringify(value);
    return String(value);
};

// Bangun representasi properti yang ramah dibaca: diff lama→baru untuk update,
// atau daftar atribut untuk create/delete.
const detailRows = (log) => {
    const props = log.properties;
    if (!props) return [];

    if (props.old || props.new) {
        const keys = Array.from(new Set([...Object.keys(props.old ?? {}), ...Object.keys(props.new ?? {})]));
        return keys.map((key) => ({
            key,
            old: formatValue(props.old?.[key]),
            next: formatValue(props.new?.[key]),
            diff: true,
        }));
    }

    const attrs = props.attributes ?? props;
    return Object.keys(attrs).map((key) => ({ key, next: formatValue(attrs[key]), diff: false }));
};

const formatDate = (value) => (value ? formatDateTime(value) : '—');
</script>

<template>
    <Head title="Audit Logs" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-lg font-semibold leading-tight sm:text-xl text-white">Audit Logs</h2>
        </template>

        <div class="min-h-[60vh] pt-5 pb-16 sm:pt-8">
            <div class="w-full space-y-6 px-4 sm:px-6 lg:px-8">
                <FilterCard title="Filter" :icon="Filter">
                    <form class="flex flex-wrap items-center gap-2" @submit.prevent="applyFilters">
                        <div class="relative w-full lg:flex-1 lg:min-w-[16rem]">
                            <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" />
                            <input
                                v-model="form.q"
                                type="search"
                                placeholder="Cari deskripsi, user, IP…"
                                class="kv-filter-control !pl-9"
                            >
                        </div>
                        <select v-model="form.event" class="kv-filter-control w-full sm:w-auto">
                            <option value="all">Semua Event</option>
                            <option v-for="event in filterOptions.events" :key="event" :value="event">{{ eventMeta(event).label }}</option>
                        </select>
                        <select v-model="form.user_id" class="kv-filter-control w-full sm:w-auto">
                            <option value="">Semua User</option>
                            <option v-for="user in filterOptions.users" :key="user.id" :value="user.id">{{ user.name }}</option>
                        </select>
                        <div class="flex w-full items-center gap-1.5 sm:w-auto">
                            <input v-model="form.from" type="date" title="Dari tanggal" class="kv-filter-control w-full sm:w-auto">
                            <span class="text-slate-500">–</span>
                            <input v-model="form.to" type="date" title="Sampai tanggal" class="kv-filter-control w-full sm:w-auto">
                        </div>
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
                        <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-cyan-500/20 ring-1 ring-cyan-500/30">
                            <ScrollText class="h-5 w-5 text-cyan-400" />
                        </div>
                        <div>
                            <h3 class="text-base font-semibold text-white">Jejak Aktivitas</h3>
                            <p class="mt-0.5 text-xs text-slate-500">Rekam jejak aksi pengguna &amp; perubahan data di sistem.</p>
                        </div>
                    </div>

                    <div v-if="rows.length === 0" class="px-6 py-12 text-center">
                        <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-slate-800/60 ring-1 ring-slate-500/30">
                            <ScrollText class="h-7 w-7 text-slate-400" />
                        </div>
                        <h3 class="text-sm font-semibold text-white">Tidak ada catatan</h3>
                        <p class="mt-1 text-sm text-slate-500">
                            {{ hasFilters ? 'Tidak ada audit log yang cocok dengan filter.' : 'Belum ada aktivitas yang tercatat.' }}
                        </p>
                    </div>

                    <template v-else>
                        <!-- Mobile -->
                        <div class="kv-mobile-list">
                            <article v-for="log in rows" :key="log.id" class="kv-mobile-card">
                                <div class="kv-mobile-card-header">
                                    <div class="min-w-0">
                                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium" :class="eventMeta(log.event).class">
                                            {{ eventMeta(log.event).label }}
                                        </span>
                                        <h4 class="mt-3 kv-mobile-card-title">{{ log.description || '—' }}</h4>
                                        <p class="kv-mobile-card-subtitle">{{ formatDate(log.created_at) }}</p>
                                    </div>
                                </div>
                                <div class="kv-mobile-fields">
                                    <div class="kv-mobile-field">
                                        <span class="kv-mobile-label">User</span>
                                        <span class="kv-mobile-value">{{ log.user_name || 'Sistem' }}</span>
                                    </div>
                                    <div v-if="log.subject" class="kv-mobile-field">
                                        <span class="kv-mobile-label">Objek</span>
                                        <span class="kv-mobile-value">{{ log.subject }}</span>
                                    </div>
                                    <div class="kv-mobile-field">
                                        <span class="kv-mobile-label">IP</span>
                                        <span class="kv-mobile-value">{{ log.ip_address || '—' }}</span>
                                    </div>
                                </div>
                                <button
                                    v-if="detailRows(log).length"
                                    type="button"
                                    class="mt-3 inline-flex items-center gap-1 text-xs font-medium text-cyan-400 hover:text-cyan-300"
                                    @click="toggle(log.id)"
                                >
                                    <ChevronDown class="h-3.5 w-3.5 transition-transform" :class="expanded.has(log.id) ? 'rotate-180' : ''" />
                                    {{ expanded.has(log.id) ? 'Sembunyikan detail' : 'Lihat detail' }}
                                </button>
                                <dl v-if="expanded.has(log.id)" class="mt-2 space-y-1 rounded-md bg-slate-950/40 p-3 text-xs">
                                    <div v-for="d in detailRows(log)" :key="d.key" class="flex flex-col gap-0.5">
                                        <dt class="font-medium text-slate-400">{{ d.key }}</dt>
                                        <dd v-if="d.diff" class="text-slate-200">
                                            <span class="text-red-300 line-through">{{ d.old }}</span>
                                            <span class="mx-1 text-slate-500">→</span>
                                            <span class="text-emerald-300">{{ d.next }}</span>
                                        </dd>
                                        <dd v-else class="text-slate-200">{{ d.next }}</dd>
                                    </div>
                                </dl>
                            </article>
                        </div>

                        <!-- Desktop -->
                        <div class="kv-table-desktop">
                            <table class="min-w-[820px] w-full">
                                <thead>
                                    <tr class="border-b border-white/10 bg-slate-950/40">
                                        <th class="w-10 px-4 py-3.5"></th>
                                        <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Waktu</th>
                                        <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">User</th>
                                        <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Event</th>
                                        <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Deskripsi</th>
                                        <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">IP</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-white/5">
                                    <template v-for="log in rows" :key="log.id">
                                        <tr
                                            class="cursor-pointer transition-colors duration-150 hover:bg-white/[0.03]"
                                            @click="toggle(log.id)"
                                        >
                                            <td class="px-4 py-4 text-slate-500">
                                                <ChevronDown
                                                    v-if="detailRows(log).length"
                                                    class="h-4 w-4 transition-transform"
                                                    :class="expanded.has(log.id) ? 'rotate-180' : ''"
                                                />
                                            </td>
                                            <td class="whitespace-nowrap px-4 py-4 text-sm text-slate-200">{{ formatDate(log.created_at) }}</td>
                                            <td class="px-4 py-4">
                                                <div class="flex items-center gap-2">
                                                    <span class="flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-full bg-slate-700/60 text-[10px] font-semibold text-slate-200 ring-1 ring-white/10">
                                                        {{ initials(log.user_name) }}
                                                    </span>
                                                    <span class="text-sm font-medium text-white">{{ log.user_name || 'Sistem' }}</span>
                                                </div>
                                            </td>
                                            <td class="px-4 py-4">
                                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium" :class="eventMeta(log.event).class">
                                                    {{ eventMeta(log.event).label }}
                                                </span>
                                            </td>
                                            <td class="px-4 py-4 text-sm text-slate-200">
                                                {{ log.description || '—' }}
                                                <div v-if="log.subject" class="text-xs text-slate-500">{{ log.subject }}</div>
                                            </td>
                                            <td class="whitespace-nowrap px-4 py-4 text-sm text-slate-400">{{ log.ip_address || '—' }}</td>
                                        </tr>
                                        <tr v-if="expanded.has(log.id) && detailRows(log).length" :key="`${log.id}-detail`" class="bg-slate-950/40">
                                            <td></td>
                                            <td colspan="5" class="px-4 py-4">
                                                <dl class="grid gap-2 sm:grid-cols-2">
                                                    <div v-for="d in detailRows(log)" :key="d.key" class="rounded-md bg-slate-900/60 px-3 py-2 text-xs">
                                                        <dt class="font-medium text-slate-400">{{ d.key }}</dt>
                                                        <dd v-if="d.diff" class="mt-0.5">
                                                            <span class="text-red-300 line-through">{{ d.old }}</span>
                                                            <span class="mx-1 text-slate-500">→</span>
                                                            <span class="text-emerald-300">{{ d.next }}</span>
                                                        </dd>
                                                        <dd v-else class="mt-0.5 text-slate-200">{{ d.next }}</dd>
                                                    </div>
                                                </dl>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </template>

                    <div v-if="rows.length > 0" class="flex flex-col items-center justify-between gap-3 border-t border-white/10 px-6 py-4 sm:flex-row">
                        <p class="text-sm text-slate-500">
                            Menampilkan {{ logs.from }}–{{ logs.to }} dari {{ logs.total }} catatan
                        </p>
                        <Pagination :links="logs.links" />
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
