<script setup>
import IconButton from '@/Components/IconButton.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import FilterCard from '@/Components/Shell/FilterCard.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { formatDateTime } from '@/lib/datetime';
import { Head, router, usePage } from '@inertiajs/vue3';
import { ExternalLink, Radar, RefreshCw, Search, Wifi, X } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';

const props = defineProps({
    olts: {
        type: Array,
        default: () => [],
    },
    onus: {
        type: Array,
        default: () => [],
    },
    refreshed_at: {
        type: Object,
        default: () => ({}),
    },
});

const page = usePage();
const flash = computed(() => page.props.flash ?? {});

const search = ref('');
const oltFilter = ref('');
const portFilter = ref('all');
const statusFilter = ref('all');
const adminFilter = ref('all');
const rxFilter = ref('all');
const scanning = ref(false);

const hasOlt = computed(() => oltFilter.value !== '');

onMounted(() => {
    const param = new URLSearchParams(window.location.search).get('olt_id');
    if (param !== null) {
        const id = Number(param);
        if (props.olts.some((o) => o.id === id)) {
            oltFilter.value = id;
        }
    }
});

// Reset port filter whenever the OLT selection changes, since port labels are OLT-specific.
const onOltChange = () => {
    portFilter.value = 'all';
};

const oltScopedOnus = computed(() =>
    hasOlt.value ? props.onus.filter((onu) => onu.olt_id === oltFilter.value) : [],
);

const portOptions = computed(() => {
    const set = new Map();
    for (const onu of oltScopedOnus.value) {
        const key = `${onu.slot}/${onu.port}`;
        set.set(key, { slot: onu.slot, port: onu.port });
    }
    return [...set.values()].sort((a, b) => a.slot - b.slot || a.port - b.port);
});

// Klasifikasi level redaman ONU RX (dipakai bersama badge & filter agar ambang batas konsisten).
const rxLevel = (value) => {
    if (value === null || value === undefined) return 'none';
    if (value <= -28 || value >= -8) return 'critical';
    if (value <= -25 || value >= -10) return 'warning';
    return 'good';
};

const matchStatus = (onu) => {
    switch (statusFilter.value) {
        case 'online':
            return onu.online;
        case 'los':
            return onu.phase_state === 'LOS';
        case 'dying_gasp':
            return onu.phase_state === 'DyingGasp';
        case 'offline':
            return onu.phase_state === 'Offline';
        default:
            return true;
    }
};

const filteredOnus = computed(() => {
    const term = search.value.trim().toLowerCase();
    return oltScopedOnus.value.filter((onu) => {
        if (portFilter.value !== 'all' && `${onu.slot}/${onu.port}` !== portFilter.value) return false;
        if (!matchStatus(onu)) return false;
        if (adminFilter.value === 'active' && onu.admin_state !== 'active') return false;
        if (adminFilter.value === 'disabled' && onu.admin_state === 'active') return false;
        if (rxFilter.value !== 'all' && rxLevel(onu.rx_power_dbm) !== rxFilter.value) return false;
        if (!term) return true;
        const hay = [onu.interface, onu.serial_number, onu.name, onu.description, onu.type_name, onu.olt_name]
            .filter(Boolean)
            .join(' ')
            .toLowerCase();
        return hay.includes(term);
    });
});

const stats = computed(() => {
    const rows = oltScopedOnus.value;
    return {
        total: rows.length,
        online: rows.filter((o) => o.online).length,
        problem: rows.filter((o) => o.phase_state === 'LOS' || o.phase_state === 'DyingGasp').length,
        offline: rows.filter((o) => o.phase_state === 'Offline').length,
    };
});

const latestRefreshed = computed(() => (hasOlt.value ? props.refreshed_at?.[oltFilter.value] ?? null : null));

const hasFilter = computed(
    () =>
        search.value.trim() !== '' ||
        portFilter.value !== 'all' ||
        statusFilter.value !== 'all' ||
        adminFilter.value !== 'all' ||
        rxFilter.value !== 'all',
);

const clearFilters = () => {
    search.value = '';
    portFilter.value = 'all';
    statusFilter.value = 'all';
    adminFilter.value = 'all';
    rxFilter.value = 'all';
};

const scanOlt = () => {
    if (!hasOlt.value || scanning.value) return;
    scanning.value = true;
    router.post(
        route('monitoring.onu.refresh', oltFilter.value),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                scanning.value = false;
            },
        },
    );
};

const portOnuHref = (onu) => {
    const name = onu.olt_cdata ? 'cdata-olt.port-onus' : 'smartolt.port-onus';
    return `${route(name, [onu.olt_id, onu.slot, onu.port])}?focus=${onu.onu_id}`;
};

const formatDate = (value) => formatDateTime(value);

const rxBadgeClass = (value) => {
    switch (rxLevel(value)) {
        case 'critical':
            return 'bg-red-500/15 text-red-300 ring-1 ring-red-500/30';
        case 'warning':
            return 'bg-amber-500/15 text-amber-300 ring-1 ring-amber-500/30';
        case 'good':
            return 'bg-emerald-500/15 text-emerald-300 ring-1 ring-emerald-500/30';
        default:
            return 'bg-slate-800/60 text-slate-500 ring-1 ring-slate-500/30';
    }
};

const phaseClass = (onu) => {
    if (onu.online) return 'text-emerald-400';
    if (onu.phase_state === 'LOS') return 'text-red-300';
    if (onu.phase_state === 'DyingGasp') return 'text-amber-300';
    return 'text-slate-500';
};

const phaseDotClass = (onu) => {
    if (onu.online) return 'bg-emerald-500';
    if (onu.phase_state === 'LOS') return 'bg-red-500';
    if (onu.phase_state === 'DyingGasp') return 'bg-amber-500';
    return 'bg-slate-400';
};
</script>

<template>
    <Head title="ONU Monitoring" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="text-lg font-semibold leading-tight sm:text-xl text-white">ONU Monitoring</h2>
                    <p class="mt-1 text-sm text-slate-500">
                        Pantau seluruh ONU lintas OLT &amp; port dalam satu halaman.
                    </p>
                </div>
                <div class="grid gap-2 [&>button]:w-full sm:flex sm:flex-wrap sm:[&>button]:w-auto">
                    <PrimaryButton
                        type="button"
                        :disabled="!hasOlt || scanning"
                        :title="!hasOlt ? 'Pilih satu OLT untuk scan' : 'Scan ulang seluruh ONU di OLT ini'"
                        @click="scanOlt"
                    >
                        <RefreshCw class="mr-2 h-4 w-4" :class="scanning ? 'animate-spin' : ''" />
                        {{ scanning ? 'Memindai...' : 'Scan ONU OLT ini' }}
                    </PrimaryButton>
                </div>
            </div>
        </template>

        <div class="min-h-[60vh] pt-5 pb-16 sm:pt-8">
            <div class="w-full space-y-5 px-4 sm:px-6 lg:px-8">
                <!-- Flash messages -->
                <div
                    v-if="flash.success"
                    class="flex items-center gap-3 rounded-lg border border-emerald-500/30 bg-emerald-500/15 px-4 py-3 text-sm text-emerald-300"
                >
                    <span class="h-2 w-2 flex-shrink-0 rounded-full bg-emerald-500"></span>
                    {{ flash.success }}
                </div>
                <div
                    v-if="flash.error"
                    class="flex items-center gap-3 rounded-lg border border-red-500/30 bg-red-500/15 px-4 py-3 text-sm text-red-300"
                >
                    <span class="h-2 w-2 flex-shrink-0 rounded-full bg-red-500"></span>
                    {{ flash.error }}
                </div>

                <!-- Filter card -->
                <FilterCard title="Filter ONU" subtitle="Pilih OLT untuk mulai menampilkan ONU." :icon="Search">
                    <template #actions>
                        <button v-if="hasFilter" type="button" class="kv-filter-reset" @click="clearFilters">
                            <X class="h-4 w-4" />
                            Reset
                        </button>
                    </template>

                    <div class="flex flex-wrap items-center gap-2">
                        <div class="relative w-full lg:flex-1 lg:min-w-[16rem]">
                            <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" />
                            <input
                                v-model="search"
                                type="text"
                                placeholder="Cari interface, serial, nama, type, atau OLT..."
                                class="kv-filter-control !pl-9 !pr-9"
                            />
                            <button v-if="search" type="button" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-500 hover:text-white" title="Hapus" @click="search = ''">
                                <X class="h-4 w-4" />
                            </button>
                        </div>
                        <select v-model="oltFilter" class="kv-filter-control w-full sm:w-auto" :class="hasOlt ? '' : '!border-cyan-500/50 !bg-cyan-500/10'" @change="onOltChange">
                            <option value="" disabled>Pilih OLT…</option>
                            <option v-for="olt in olts" :key="olt.id" :value="olt.id">{{ olt.name }}</option>
                        </select>
                        <select v-model="portFilter" :disabled="!hasOlt" class="kv-filter-control w-full sm:w-auto">
                            <option value="all">Semua Port</option>
                            <option v-for="opt in portOptions" :key="`${opt.slot}/${opt.port}`" :value="`${opt.slot}/${opt.port}`">
                                Port {{ opt.slot }}/{{ opt.port }}
                            </option>
                        </select>
                        <select v-model="statusFilter" :disabled="!hasOlt" class="kv-filter-control w-full sm:w-auto">
                            <option value="all">Semua Status</option>
                            <option value="online">Online</option>
                            <option value="los">LOS</option>
                            <option value="dying_gasp">Dying Gasp</option>
                            <option value="offline">Offline</option>
                        </select>
                        <select v-model="adminFilter" :disabled="!hasOlt" class="kv-filter-control w-full sm:w-auto">
                            <option value="all">Semua Admin</option>
                            <option value="active">Active</option>
                            <option value="disabled">Disabled</option>
                        </select>
                        <select v-model="rxFilter" :disabled="!hasOlt" title="Filter berdasarkan level redaman ONU RX" class="kv-filter-control w-full sm:w-auto">
                            <option value="all">Semua Redaman</option>
                            <option value="good">Redaman Normal</option>
                            <option value="warning">Redaman Peringatan</option>
                            <option value="critical">Redaman Kritis</option>
                            <option value="none">Tanpa Data RX</option>
                        </select>
                    </div>
                </FilterCard>

                <!-- Prompt: pick an OLT first -->
                <div v-if="!hasOlt" class="rounded-lg border border-white/10 bg-slate-900/40 px-6 py-16 text-center shadow-lg shadow-black/30 backdrop-blur-xl">
                    <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-sky-500/15 ring-1 ring-cyan-500/30">
                        <Radar class="h-7 w-7 text-cyan-400" />
                    </div>
                    <h3 class="text-sm font-semibold text-slate-200">Pilih OLT terlebih dahulu</h3>
                    <p class="mt-1 text-sm text-slate-500">
                        Gunakan filter <span class="text-slate-300">Pilih OLT</span> di atas untuk menampilkan daftar ONU.
                    </p>
                </div>

                <template v-else>
                <!-- Stat cards -->
                <div class="grid gap-4 sm:grid-cols-2 md:grid-cols-4">
                    <div class="rounded-lg border border-white/10 bg-slate-900/40 backdrop-blur-xl p-5 shadow-sm shadow-black/30">
                        <p class="text-xs font-medium uppercase tracking-wider text-slate-500">Total ONU</p>
                        <p class="mt-3 text-2xl font-bold text-white">{{ stats.total }}</p>
                    </div>
                    <div class="rounded-lg border border-white/10 bg-slate-900/40 backdrop-blur-xl p-5 shadow-sm shadow-black/30">
                        <p class="text-xs font-medium uppercase tracking-wider text-slate-500">Online</p>
                        <div class="mt-3 flex items-end gap-2">
                            <p class="text-2xl font-bold text-emerald-400">{{ stats.online }}</p>
                            <p class="mb-0.5 text-sm text-slate-400">/ {{ stats.total }}</p>
                        </div>
                    </div>
                    <div class="rounded-lg border border-white/10 bg-slate-900/40 backdrop-blur-xl p-5 shadow-sm shadow-black/30">
                        <p class="text-xs font-medium uppercase tracking-wider text-slate-500">LOS / Dying Gasp</p>
                        <p class="mt-3 text-2xl font-bold text-amber-300">{{ stats.problem }}</p>
                    </div>
                    <div class="rounded-lg border border-white/10 bg-slate-900/40 backdrop-blur-xl p-5 shadow-sm shadow-black/30">
                        <p class="text-xs font-medium uppercase tracking-wider text-slate-500">Offline</p>
                        <p class="mt-3 text-2xl font-bold text-slate-300">{{ stats.offline }}</p>
                    </div>
                </div>

                <!-- ONU table card -->
                <div class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
                    <div class="flex flex-col gap-3 border-b border-white/10 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                        <div class="flex items-center gap-3">
                            <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-sky-500/15 ring-1 ring-cyan-500/30">
                                <Radar class="h-5 w-5 text-cyan-400" />
                            </div>
                            <div>
                                <h3 class="text-base font-semibold text-white">
                                    Daftar ONU
                                    <span v-if="oltScopedOnus.length" class="ml-1 text-sm font-normal text-slate-500">
                                        ({{ filteredOnus.length }}/{{ oltScopedOnus.length }})
                                    </span>
                                </h3>
                                <p class="mt-0.5 text-xs text-slate-500">
                                    Refresh terakhir: {{ formatDate(latestRefreshed) }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Empty state: OLT selected but no cached data yet -->
                    <div v-if="oltScopedOnus.length === 0" class="px-6 py-14 text-center">
                        <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-slate-800/60 ring-1 ring-slate-500/30">
                            <Wifi class="h-7 w-7 text-slate-400" />
                        </div>
                        <h3 class="text-sm font-semibold text-slate-200">Belum ada data ONU</h3>
                        <p class="mt-1 text-sm text-slate-500">
                            Jalankan <span class="text-slate-300">Scan ONU OLT ini</span> untuk membaca seluruh ONU dari OLT ini.
                        </p>
                    </div>

                    <template v-else>
                        <!-- No match state -->
                        <div v-if="filteredOnus.length === 0" class="px-6 py-14 text-center">
                            <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-slate-800/60 ring-1 ring-slate-500/30">
                                <Search class="h-7 w-7 text-slate-400" />
                            </div>
                            <h3 class="text-sm font-semibold text-slate-200">Tidak ada ONU yang cocok</h3>
                            <p class="mt-1 text-sm text-slate-500">Coba ubah kata kunci atau reset filter.</p>
                            <button type="button" class="mt-4 rounded-lg border border-white/10 px-4 py-2 text-sm text-slate-300 transition-colors hover:bg-white/5" @click="clearFilters">Reset filter</button>
                        </div>

                        <template v-else>
                            <!-- Mobile cards -->
                            <div class="kv-mobile-list">
                                <article
                                    v-for="onu in filteredOnus"
                                    :key="`${onu.olt_id}-${onu.slot}-${onu.port}-${onu.onu_id}`"
                                    class="kv-mobile-card"
                                >
                                    <div class="kv-mobile-card-header">
                                        <div class="min-w-0">
                                            <h4 class="kv-mobile-card-title">{{ onu.interface }}</h4>
                                            <p class="kv-mobile-card-subtitle">{{ onu.olt_name }} · {{ onu.name || onu.description || '—' }}</p>
                                        </div>
                                        <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="rxBadgeClass(onu.rx_power_dbm)">
                                            {{ onu.rx_power_label || '—' }}
                                        </span>
                                    </div>

                                    <div class="kv-mobile-fields">
                                        <div class="kv-mobile-field">
                                            <span class="kv-mobile-label">Serial</span>
                                            <span class="kv-mobile-value font-mono text-xs">{{ onu.serial_number || '—' }}</span>
                                        </div>
                                        <div class="kv-mobile-field">
                                            <span class="kv-mobile-label">Type</span>
                                            <span class="kv-mobile-value">{{ onu.type_name || '—' }}</span>
                                        </div>
                                        <div class="kv-mobile-field">
                                            <span class="kv-mobile-label">Phase</span>
                                            <span class="kv-mobile-value" :class="phaseClass(onu)">{{ onu.phase_state }}</span>
                                        </div>
                                        <div class="kv-mobile-field">
                                            <span class="kv-mobile-label">Admin</span>
                                            <span
                                                class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium ring-1"
                                                :class="onu.admin_state === 'active'
                                                    ? 'bg-sky-500/15 text-cyan-300 ring-cyan-500/30'
                                                    : 'bg-slate-800/60 text-slate-500 ring-slate-500/30'"
                                            >
                                                {{ onu.admin_state }}
                                            </span>
                                        </div>
                                        <div class="kv-mobile-field">
                                            <span class="kv-mobile-label">Last Down</span>
                                            <span class="kv-mobile-value">{{ onu.last_down_cause || '—' }}</span>
                                        </div>
                                    </div>

                                    <div class="mt-4 flex flex-wrap gap-2">
                                        <IconButton :href="portOnuHref(onu)" variant="primary" title="Buka di port (kelola ONU)">
                                            <ExternalLink class="h-4 w-4" />
                                        </IconButton>
                                    </div>
                                </article>
                            </div>

                            <!-- Desktop table -->
                            <div class="kv-table-desktop">
                                <table class="min-w-[820px] w-full">
                                    <thead>
                                        <tr class="border-b border-white/10 bg-slate-950/40">
                                            <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">OLT</th>
                                            <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">ONU</th>
                                            <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Serial</th>
                                            <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Type</th>
                                            <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">ONU RX</th>
                                            <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Phase</th>
                                            <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Admin</th>
                                            <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Last Down</th>
                                            <th class="px-6 py-3.5 text-center text-xs font-semibold uppercase tracking-wider text-slate-500">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-white/5">
                                        <tr
                                            v-for="onu in filteredOnus"
                                            :key="`${onu.olt_id}-${onu.slot}-${onu.port}-${onu.onu_id}`"
                                            class="transition-colors duration-150 hover:bg-white/[0.03]"
                                        >
                                            <td class="px-6 py-4 text-sm text-slate-300">{{ onu.olt_name }}</td>
                                            <td class="px-6 py-4">
                                                <div class="font-semibold text-white">{{ onu.interface }}</div>
                                                <div class="mt-0.5 text-xs text-slate-500">{{ onu.name || onu.description || '—' }}</div>
                                            </td>
                                            <td class="px-6 py-4">
                                                <span class="font-mono text-sm text-slate-200">{{ onu.serial_number || '—' }}</span>
                                            </td>
                                            <td class="px-6 py-4 text-sm text-slate-200">{{ onu.type_name || '—' }}</td>
                                            <td class="px-6 py-4">
                                                <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="rxBadgeClass(onu.rx_power_dbm)">
                                                    {{ onu.rx_power_label || '—' }}
                                                </span>
                                            </td>
                                            <td class="px-6 py-4">
                                                <div class="flex items-center gap-1.5">
                                                    <span class="h-1.5 w-1.5 flex-shrink-0 rounded-full" :class="phaseDotClass(onu)"></span>
                                                    <span class="text-sm" :class="phaseClass(onu)">{{ onu.phase_state }}</span>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4">
                                                <span
                                                    class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium ring-1"
                                                    :class="onu.admin_state === 'active'
                                                        ? 'bg-sky-500/15 text-cyan-300 ring-cyan-500/30'
                                                        : 'bg-slate-800/60 text-slate-500 ring-slate-500/30'"
                                                >
                                                    {{ onu.admin_state }}
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 text-sm text-slate-500">{{ onu.last_down_cause || '—' }}</td>
                                            <td class="px-6 py-4">
                                                <div class="flex items-center justify-center gap-1.5">
                                                    <IconButton :href="portOnuHref(onu)" variant="primary" title="Buka di port (kelola ONU)">
                                                        <ExternalLink class="h-4 w-4" />
                                                    </IconButton>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </template>
                    </template>
                </div>
                </template>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
