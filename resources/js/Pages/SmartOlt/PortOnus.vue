<script setup>
import ConfirmModal from '@/Components/ConfirmModal.vue';
import IconButton from '@/Components/IconButton.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { formatDateTime } from '@/lib/datetime';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Info, Pencil, Power, RefreshCw, Router, Search, Settings, ToggleLeft, ToggleRight, Wifi, X } from '@lucide/vue';
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue';

const props = defineProps({
    olt: {
        type: Object,
        required: true,
    },
    slot: {
        type: Number,
        required: true,
    },
    port: {
        type: Number,
        required: true,
    },
    snapshot: {
        type: Object,
        required: true,
    },
    initial_search: {
        type: String,
        default: '',
    },
    focus_onu_id: {
        type: Number,
        default: null,
    },
});

const page = usePage();
const flash = computed(() => page.props.flash ?? {});
const caps = computed(() => props.olt.capabilities ?? {});
const { confirmState, confirm, handleConfirm, handleCancel } = useConfirm();

// --- search & filter ---
const search = ref(props.initial_search ?? '');
const phaseFilter = ref('all');
const adminFilter = ref('all');
const focusId = ref(props.focus_onu_id);

const filteredOnus = computed(() => {
    const term = search.value.trim().toLowerCase();
    return props.snapshot.onus.filter((onu) => {
        if (phaseFilter.value === 'online' && !onu.online) return false;
        if (phaseFilter.value === 'offline' && onu.online) return false;
        if (adminFilter.value === 'active' && onu.admin_state !== 'active') return false;
        if (adminFilter.value === 'disabled' && onu.admin_state === 'active') return false;
        if (!term) return true;
        const hay = [onu.interface, onu.serial_number, onu.name, onu.description, onu.type_name]
            .filter(Boolean).join(' ').toLowerCase();
        return hay.includes(term);
    });
});

const hasFilter = computed(() => search.value.trim() !== '' || phaseFilter.value !== 'all' || adminFilter.value !== 'all');
const clearFilters = () => { search.value = ''; phaseFilter.value = 'all'; adminFilter.value = 'all'; };

const scrollToFocus = () => {
    if (!focusId.value) return;
    const els = document.querySelectorAll(`[data-onu-id="${focusId.value}"]`);
    for (const el of els) {
        if (el.offsetParent !== null) {
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }
    }
};

watch(() => props.initial_search, (v) => { search.value = v ?? ''; });
watch(() => props.focus_onu_id, (v) => { focusId.value = v; nextTick(scrollToFocus); });
onMounted(() => nextTick(scrollToFocus));

const refresh = () => {
    router.post(route('smartolt.port-onus.refresh', [props.olt.id, props.slot, props.port]), {}, {
        preserveScroll: true,
    });
};

const busy = reactive({});
const actionKey = (onu) => `${onu.if_index}-${onu.onu_id}`;

const rebootOnu = async (onu) => {
    const ok = await confirm({
        title: 'Reboot ONU',
        message: `Reboot ${onu.interface}? ONU akan restart 30-60 detik.`,
        confirmLabel: 'Reboot',
    });

    if (!ok) {
        return;
    }

    const key = actionKey(onu);
    busy[key] = true;
    router.post(route('smartolt.onu.reboot', [props.olt.id, props.slot, props.port, onu.onu_id]), {
        if_index: onu.if_index,
    }, {
        preserveScroll: true,
        onFinish: () => { busy[key] = false; },
    });
};

const toggleOnu = async (onu) => {
    const active = onu.admin_state !== 'active';
    const verb = active ? 'enable' : 'disable';
    const ok = await confirm({
        title: active ? 'Enable ONU' : 'Disable ONU',
        message: `Yakin ${verb} ${onu.interface}?`,
        confirmLabel: active ? 'Enable' : 'Disable',
        variant: active ? 'primary' : 'danger',
    });

    if (!ok) {
        return;
    }

    const key = actionKey(onu);
    busy[key] = true;
    router.post(route('smartolt.onu.state', [props.olt.id, props.slot, props.port, onu.onu_id]), {
        active,
        if_index: onu.if_index,
    }, {
        preserveScroll: true,
        onFinish: () => { busy[key] = false; },
    });
};

const editForm = useForm({
    onu_id: null,
    if_index: null,
    name: '',
    description: '',
});
const editing = reactive({ open: false, interface: '' });

const openEdit = (onu) => {
    editForm.clearErrors();
    editForm.onu_id = onu.onu_id;
    editForm.if_index = onu.if_index;
    editForm.name = onu.name ?? '';
    editForm.description = onu.description ?? '';
    editing.interface = onu.interface;
    editing.open = true;
};

const submitEdit = () => {
    editForm.post(route('smartolt.onu.info', [props.olt.id, props.slot, props.port, editForm.onu_id]), {
        preserveScroll: true,
        onSuccess: () => { editing.open = false; },
    });
};

const formatDate = (value) => formatDateTime(value);

const rxClass = (value) => {
    if (value === null || value === undefined) {
        return 'text-slate-400';
    }

    if (value <= -28 || value >= -8) {
        return 'text-red-300';
    }

    if (value <= -25 || value >= -10) {
        return 'text-amber-300';
    }

    return 'text-emerald-300';
};

const rxBadgeClass = (value) => {
    if (value === null || value === undefined) return 'bg-slate-800/60 text-slate-500 ring-1 ring-slate-500/30';
    if (value <= -28 || value >= -8) return 'bg-red-500/15 text-red-300 ring-1 ring-red-500/30';
    if (value <= -25 || value >= -10) return 'bg-amber-500/15 text-amber-300 ring-1 ring-amber-500/30';
    return 'bg-emerald-500/15 text-emerald-300 ring-1 ring-emerald-500/30';
};
</script>

<template>
    <Head :title="`ONU ${olt.name} ${slot}/${port}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="text-lg font-semibold leading-tight sm:text-xl text-white">
                        ONU Slot {{ slot }} Port {{ port }}
                    </h2>
                    <p class="mt-1 text-sm text-slate-500">
                        {{ olt.name }} · {{ olt.ip }}
                    </p>
                </div>
                <div class="grid gap-2 [&>a>button]:w-full [&>button]:w-full sm:flex sm:flex-wrap sm:[&>a>button]:w-auto sm:[&>button]:w-auto">
                    <Link :href="route('smartolt.gpon-ports', olt.id)">
                        <SecondaryButton type="button">
                            <ArrowLeft class="mr-2 h-4 w-4" />
                            GPON Port & ONU
                        </SecondaryButton>
                    </Link>
                    <PrimaryButton type="button" @click="refresh">
                        <RefreshCw class="mr-2 h-4 w-4" />
                        Refresh ONU
                    </PrimaryButton>
                </div>
            </div>
        </template>

        <div class="min-h-[60vh] pt-5 pb-16 sm:pt-8">
            <div class="w-full space-y-5 px-4 sm:px-6 lg:px-8">
                <!-- Flash messages -->
                <div
                    v-if="flash.success"
                    class="mb-5 flex items-center gap-3 rounded-lg border border-emerald-500/30 bg-emerald-500/15 px-4 py-3 text-sm text-emerald-300"
                >
                    <span class="h-2 w-2 flex-shrink-0 rounded-full bg-emerald-500"></span>
                    {{ flash.success }}
                </div>
                <div
                    v-if="flash.error"
                    class="mb-5 flex items-center gap-3 rounded-lg border border-red-500/30 bg-red-500/15 px-4 py-3 text-sm text-red-300"
                >
                    <span class="h-2 w-2 flex-shrink-0 rounded-full bg-red-500"></span>
                    {{ flash.error }}
                </div>

                <!-- Stat cards -->
                <div class="grid gap-4 sm:grid-cols-2 md:grid-cols-4">
                    <!-- Data status -->
                    <div class="rounded-lg border border-white/10 bg-slate-900/40 backdrop-blur-xl p-5 shadow-sm shadow-black/30">
                        <div class="flex items-center justify-between">
                            <p class="text-xs font-medium uppercase tracking-wider text-slate-500">Status</p>
                            <span
                                class="h-2 w-2 rounded-full"
                                :class="snapshot.ok ? 'bg-emerald-500' : 'bg-slate-300'"
                            ></span>
                        </div>
                        <p
                            class="mt-3 text-2xl font-bold"
                            :class="snapshot.ok ? 'text-emerald-400' : 'text-slate-400'"
                        >
                            {{ snapshot.ok ? 'Tersedia' : 'Kosong' }}
                        </p>
                    </div>
                    <!-- Total ONU -->
                    <div class="rounded-lg border border-white/10 bg-slate-900/40 backdrop-blur-xl p-5 shadow-sm shadow-black/30">
                        <p class="text-xs font-medium uppercase tracking-wider text-slate-500">Total ONU</p>
                        <p class="mt-3 text-2xl font-bold text-white">{{ snapshot.count }}</p>
                    </div>
                    <!-- Online -->
                    <div class="rounded-lg border border-white/10 bg-slate-900/40 backdrop-blur-xl p-5 shadow-sm shadow-black/30">
                        <p class="text-xs font-medium uppercase tracking-wider text-slate-500">Online</p>
                        <div class="mt-3 flex items-end gap-2">
                            <p class="text-2xl font-bold text-emerald-400">
                                {{ snapshot.onus.filter((o) => o.online).length }}
                            </p>
                            <p class="mb-0.5 text-sm text-slate-400">/ {{ snapshot.count }}</p>
                        </div>
                    </div>
                    <!-- Refresh terakhir -->
                    <div class="rounded-lg border border-white/10 bg-slate-900/40 backdrop-blur-xl p-5 shadow-sm shadow-black/30">
                        <p class="text-xs font-medium uppercase tracking-wider text-slate-500">Refresh Terakhir</p>
                        <p class="mt-3 text-sm font-semibold text-white">{{ formatDate(snapshot.refreshed_at) }}</p>
                    </div>
                </div>

                <!-- ONU table card -->
                <div class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
                    <div class="flex flex-col gap-3 border-b border-white/10 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                        <div class="flex items-center gap-3">
                            <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-sky-500/15 ring-1 ring-cyan-500/30">
                                <Router class="h-5 w-5 text-cyan-400" />
                            </div>
                            <div>
                                <h3 class="text-base font-semibold text-white">
                                    Registered ONU
                                    <span v-if="snapshot.onus.length" class="ml-1 text-sm font-normal text-slate-500">({{ filteredOnus.length }}/{{ snapshot.onus.length }})</span>
                                </h3>
                                <p v-if="snapshot.rx_power?.error" class="mt-0.5 text-xs text-red-400">
                                    RX gagal dibaca: {{ snapshot.rx_power.error }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Search & filter toolbar -->
                    <div v-if="snapshot.onus.length > 0" class="flex flex-col gap-3 border-b border-white/10 px-4 py-3 sm:flex-row sm:items-center sm:px-6">
                        <div class="relative flex-1">
                            <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" />
                            <input
                                v-model="search"
                                type="text"
                                placeholder="Cari interface, serial, nama, atau type..."
                                class="w-full rounded-lg border border-white/10 bg-slate-950/40 py-2 pl-9 pr-9 text-sm text-slate-100 placeholder:text-slate-500 focus:border-cyan-500 focus:ring-cyan-500"
                            />
                            <button v-if="search" type="button" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-500 hover:text-white" title="Hapus" @click="search = ''">
                                <X class="h-4 w-4" />
                            </button>
                        </div>
                        <div class="flex items-center gap-2">
                            <select v-model="phaseFilter" class="rounded-lg border border-white/10 bg-slate-950/40 py-2 pl-3 pr-8 text-sm text-slate-100 focus:border-cyan-500 focus:ring-cyan-500">
                                <option value="all">Semua Phase</option>
                                <option value="online">Online</option>
                                <option value="offline">Offline</option>
                            </select>
                            <select v-model="adminFilter" class="rounded-lg border border-white/10 bg-slate-950/40 py-2 pl-3 pr-8 text-sm text-slate-100 focus:border-cyan-500 focus:ring-cyan-500">
                                <option value="all">Semua Admin</option>
                                <option value="active">Active</option>
                                <option value="disabled">Disabled</option>
                            </select>
                            <button v-if="hasFilter" type="button" class="rounded-lg border border-white/10 px-3 py-2 text-sm text-slate-300 transition-colors hover:bg-white/5" @click="clearFilters">Reset</button>
                        </div>
                    </div>

                    <!-- Empty state -->
                    <div v-if="snapshot.onus.length === 0" class="px-6 py-14 text-center">
                        <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-slate-800/60 ring-1 ring-slate-500/30">
                            <Wifi class="h-7 w-7 text-slate-400" />
                        </div>
                        <h3 class="text-sm font-semibold text-slate-200">Belum ada data ONU</h3>
                        <p class="mt-1 text-sm text-slate-500">
                            Jalankan Refresh ONU untuk membaca ONU terdaftar di port ini.
                        </p>
                    </div>

                    <!-- Table / mobile cards -->
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
                        <div class="kv-mobile-list">
                            <article
                                v-for="onu in filteredOnus"
                                :key="`${onu.if_index}-${onu.onu_id}`"
                                :data-onu-id="onu.onu_id"
                                class="kv-mobile-card transition-shadow"
                                :class="onu.onu_id === focusId ? 'ring-2 ring-cyan-500/60' : ''"
                            >
                                <div class="kv-mobile-card-header">
                                    <div class="min-w-0">
                                        <h4 class="kv-mobile-card-title">{{ onu.interface }}</h4>
                                        <p class="kv-mobile-card-subtitle">{{ onu.name || onu.description || '—' }}</p>
                                    </div>
                                    <span
                                        class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                        :class="rxBadgeClass(onu.rx_power_dbm)"
                                    >
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
                                        <span class="kv-mobile-value" :class="onu.online ? 'text-emerald-400' : 'text-slate-500'">
                                            {{ onu.phase_state }}
                                        </span>
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
                                    <IconButton
                                        v-if="caps.supports_onu_info_write"
                                        title="Edit info ONU"
                                        @click="openEdit(onu)"
                                    >
                                        <Pencil class="h-4 w-4" />
                                    </IconButton>
                                    <IconButton
                                        v-if="caps.supports_cli_onu_detail"
                                        :href="route('smartolt.onu.detail', [olt.id, slot, port, onu.onu_id])"
                                        title="Detail ONU (CLI)"
                                    >
                                        <Info class="h-4 w-4" />
                                    </IconButton>
                                    <IconButton
                                        v-if="caps.supports_cli_onu_configure"
                                        variant="primary"
                                        :href="route('smartolt.onu.configure', [olt.id, slot, port, onu.onu_id])"
                                        title="Configure ONU (CLI)"
                                    >
                                        <Settings class="h-4 w-4" />
                                    </IconButton>
                                    <IconButton
                                        v-if="caps.supports_onu_toggle"
                                        :variant="onu.admin_state === 'active' ? 'warning' : 'success'"
                                        :disabled="busy[actionKey(onu)]"
                                        :title="onu.admin_state === 'active' ? 'Disable ONU' : 'Enable ONU'"
                                        @click="toggleOnu(onu)"
                                    >
                                        <ToggleRight v-if="onu.admin_state === 'active'" class="h-4 w-4" />
                                        <ToggleLeft v-else class="h-4 w-4" />
                                    </IconButton>
                                    <IconButton
                                        v-if="caps.supports_reboot"
                                        variant="danger"
                                        :disabled="busy[actionKey(onu)]"
                                        title="Reboot ONU"
                                        @click="rebootOnu(onu)"
                                    >
                                        <Power class="h-4 w-4" />
                                    </IconButton>
                                </div>
                            </article>
                        </div>

                        <div class="kv-table-desktop">
                        <table class="min-w-[720px] w-full">
                            <thead>
                                <tr class="border-b border-white/10 bg-slate-950/40">
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
                                    :key="`${onu.if_index}-${onu.onu_id}`"
                                    :data-onu-id="onu.onu_id"
                                    class="transition-colors duration-150 hover:bg-white/[0.03]"
                                    :class="onu.onu_id === focusId ? 'bg-cyan-500/10' : ''"
                                >
                                    <td class="px-6 py-4">
                                        <div class="font-semibold text-white">{{ onu.interface }}</div>
                                        <div class="mt-0.5 text-xs text-slate-500">{{ onu.name || onu.description || '—' }}</div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <span class="font-mono text-sm text-slate-200">{{ onu.serial_number || '—' }}</span>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-slate-200">
                                        {{ onu.type_name || '—' }}
                                    </td>
                                    <td class="px-6 py-4">
                                        <span
                                            class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                            :class="rxBadgeClass(onu.rx_power_dbm)"
                                        >
                                            {{ onu.rx_power_label || '—' }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="flex items-center gap-1.5">
                                            <span
                                                class="h-1.5 w-1.5 flex-shrink-0 rounded-full"
                                                :class="onu.online ? 'bg-emerald-500' : 'bg-slate-300'"
                                            ></span>
                                            <span
                                                class="text-sm"
                                                :class="onu.online ? 'text-emerald-400' : 'text-slate-500'"
                                            >
                                                {{ onu.phase_state }}
                                            </span>
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
                                    <td class="px-6 py-4 text-sm text-slate-500">
                                        {{ onu.last_down_cause || '—' }}
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="flex items-center justify-center gap-1.5">
                                            <IconButton
                                                v-if="caps.supports_onu_info_write"
                                                title="Edit info ONU"
                                                @click="openEdit(onu)"
                                            >
                                                <Pencil class="h-4 w-4" />
                                            </IconButton>
                                            <IconButton
                                                v-if="caps.supports_cli_onu_detail"
                                                :href="route('smartolt.onu.detail', [olt.id, slot, port, onu.onu_id])"
                                                title="Detail ONU (CLI)"
                                            >
                                                <Info class="h-4 w-4" />
                                            </IconButton>
                                            <IconButton
                                                v-if="caps.supports_cli_onu_configure"
                                                variant="primary"
                                                :href="route('smartolt.onu.configure', [olt.id, slot, port, onu.onu_id])"
                                                title="Configure ONU (CLI)"
                                            >
                                                <Settings class="h-4 w-4" />
                                            </IconButton>
                                            <IconButton
                                                v-if="caps.supports_onu_toggle"
                                                :variant="onu.admin_state === 'active' ? 'warning' : 'success'"
                                                :disabled="busy[actionKey(onu)]"
                                                :title="onu.admin_state === 'active' ? 'Disable ONU' : 'Enable ONU'"
                                                @click="toggleOnu(onu)"
                                            >
                                                <ToggleRight v-if="onu.admin_state === 'active'" class="h-4 w-4" />
                                                <ToggleLeft v-else class="h-4 w-4" />
                                            </IconButton>
                                            <IconButton
                                                v-if="caps.supports_reboot"
                                                variant="danger"
                                                :disabled="busy[actionKey(onu)]"
                                                title="Reboot ONU"
                                                @click="rebootOnu(onu)"
                                            >
                                                <Power class="h-4 w-4" />
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
            </div>
        </div>

        <Modal :show="editing.open" @close="editing.open = false">
            <form class="p-6" @submit.prevent="submitEdit">
                <h3 class="text-base font-semibold text-white">Edit Info ONU</h3>
                <p class="mt-1 text-sm text-slate-500">{{ editing.interface }} · ditulis via SNMP SET.</p>
                <div class="mt-4 space-y-4">
                    <div>
                        <InputLabel for="onu_name" value="Nama ONU" />
                        <TextInput id="onu_name" v-model="editForm.name" type="text" class="mt-1 block w-full" maxlength="191" />
                        <InputError :message="editForm.errors.name" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel for="onu_description" value="Deskripsi" />
                        <TextInput id="onu_description" v-model="editForm.description" type="text" class="mt-1 block w-full" maxlength="191" />
                        <InputError :message="editForm.errors.description" class="mt-1" />
                    </div>
                </div>
                <div class="mt-6 grid gap-2 sm:flex sm:justify-end">
                    <SecondaryButton type="button" @click="editing.open = false">Batal</SecondaryButton>
                    <PrimaryButton type="submit" :disabled="editForm.processing">Simpan</PrimaryButton>
                </div>
            </form>
        </Modal>

        <ConfirmModal :state="confirmState" @confirm="handleConfirm" @cancel="handleCancel" />
    </AuthenticatedLayout>
</template>
