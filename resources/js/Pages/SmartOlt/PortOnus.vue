<script setup>
import ConfirmModal from '@/Components/ConfirmModal.vue';
import IconButton from '@/Components/IconButton.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import OnuOdpCell from '@/Components/OnuOdpCell.vue';
import Tr069BulkModal from '@/Components/SmartOlt/Tr069BulkModal.vue';
import ClientPagination from '@/Components/Shell/ClientPagination.vue';
import ListSkeleton from '@/Components/Shell/ListSkeleton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { usePagination } from '@/Composables/usePagination';
import { formatDateTime } from '@/lib/datetime';
import { lastDownCauseLabel } from '@/lib/onu';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ArrowLeft, ChevronLeft, ChevronRight, Cloud, Copy, Info, Link2, MapPin, MapPinned, Pencil, Power, RefreshCw, Router, Search, Settings, ToggleLeft, ToggleRight, Trash2, Wifi, X } from '@lucide/vue';
import { computed, nextTick, onMounted, onUnmounted, reactive, ref, watch } from 'vue';

const { t } = useI18n({ useScope: 'global' });

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
    pinned_onu_ids: {
        type: Array,
        default: () => [],
    },
    acs: {
        type: Object,
        default: () => ({ url: '', username: '' }),
    },
    odps: {
        type: Array,
        default: () => [],
    },
    odp_links: {
        type: Object,
        default: () => ({}),
    },
});

const odpIdFor = (onu) => props.odp_links?.[onu.onu_id]?.odp_id ?? null;

const page = usePage();
const flash = computed(() => page.props.flash ?? {});
const caps = computed(() => props.olt.capabilities ?? {});
const { confirmState, confirm, handleConfirm, handleCancel } = useConfirm();

// --- TR069 massal (semua ONU port ini) ---
// Gate = supports_onu_config_write: penulis config gaya C300 (mati di C600 yang bermodel vport).
const canTr069 = computed(() => !!caps.value.supports_onu_config_write);
const tr069ModalOpen = ref(false);

// --- navigasi cepat antar port (OLT sama) ---
const navPorts = computed(() => {
    const list = (props.olt.last_test_result?.ports ?? [])
        .map((p) => ({ slot: Number(p.slot), port: Number(p.port), name: p.name }));
    if (!list.some((p) => p.slot === props.slot && p.port === props.port)) {
        list.push({ slot: props.slot, port: props.port, name: null });
    }
    return list.sort((a, b) => a.slot - b.slot || a.port - b.port);
});
const currentPortIndex = computed(() =>
    navPorts.value.findIndex((p) => p.slot === props.slot && p.port === props.port),
);
const currentPortKey = computed(() => `${props.slot}_${props.port}`);
const prevPort = computed(() => (currentPortIndex.value > 0 ? navPorts.value[currentPortIndex.value - 1] : null));
const nextPort = computed(() =>
    currentPortIndex.value >= 0 && currentPortIndex.value < navPorts.value.length - 1
        ? navPorts.value[currentPortIndex.value + 1]
        : null,
);
const goToPort = (p) => {
    if (!p || (p.slot === props.slot && p.port === props.port)) return;
    router.get(route('smartolt.port-onus', [props.olt.id, p.slot, p.port]));
};
const onPortSelect = (event) => {
    const [slot, port] = event.target.value.split('_').map(Number);
    goToPort({ slot, port });
};

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

// Paginasi sisi-klien daftar ONU terfilter (data sudah dimuat penuh ke props).
const { page: onuPage, pageSize, total: pageTotal, pageCount, pageItems: pagedOnus, rangeStart, rangeEnd } = usePagination(filteredOnus);

// --- batch copy konfigurasi ke port lain (OLT sama) ---
// Gate = supports_onu_config_write: rebuild registrasi gaya C300 (mati di C600 yang bermodel vport).
const canCopy = computed(() => !!caps.value.supports_onu_config_write);
const selected = ref(new Set());
const isSelected = (onu) => selected.value.has(onu.onu_id);
const toggleSelect = (onu) => {
    const next = new Set(selected.value);
    next.has(onu.onu_id) ? next.delete(onu.onu_id) : next.add(onu.onu_id);
    selected.value = next;
};
const allFilteredSelected = computed(() =>
    filteredOnus.value.length > 0 && filteredOnus.value.every((o) => selected.value.has(o.onu_id)),
);
const toggleSelectAll = () => {
    const next = new Set(selected.value);
    if (allFilteredSelected.value) {
        filteredOnus.value.forEach((o) => next.delete(o.onu_id));
    } else {
        filteredOnus.value.forEach((o) => next.add(o.onu_id));
    }
    selected.value = next;
};
const selectedCount = computed(() => selected.value.size);
const clearSelection = () => { selected.value = new Set(); };

const availablePorts = computed(() =>
    (props.olt.last_test_result?.ports ?? [])
        .filter((p) => !(Number(p.slot) === props.slot && Number(p.port) === props.port))
        .map((p) => ({ slot: Number(p.slot), port: Number(p.port), name: p.name })),
);

const copyModal = reactive({ open: false });
const copyExecute = ref(false);
const targetKey = ref('');
const manualSlot = ref(props.slot);
const manualPort = ref(props.port + 1);

// Batch berjalan di background job → tampilkan progress (form → running → done).
const copyPhase = ref('form');
const copySubmitting = ref(false);
const copyError = ref('');
const copyStatusUrl = ref('');
const copyRegistrationsUrl = ref(route('smartolt.registrations', props.olt.id));
const blankProgress = () => ({ status: 'queued', execute: false, total: 0, processed: 0, created: 0, executed: 0, failed: 0, finished: false, items: [], error: null });
const copyProgress = ref(blankProgress());
let pollTimer = null;

const copyPercent = computed(() => {
    const total = copyProgress.value.total || 0;
    return total > 0 ? Math.round((copyProgress.value.processed / total) * 100) : 0;
});
const copyFailedItems = computed(() => (copyProgress.value.items ?? []).filter((i) => !i.ok));

const stopPolling = () => { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } };

const closeCopy = () => {
    stopPolling();
    copyModal.open = false;
    copyPhase.value = 'form';
    copyError.value = '';
};

const openCopy = () => {
    if (selectedCount.value === 0) return;
    stopPolling();
    copyPhase.value = 'form';
    copyError.value = '';
    copyExecute.value = false;
    manualSlot.value = props.slot;
    manualPort.value = props.port + 1;
    targetKey.value = availablePorts.value.length ? `${availablePorts.value[0].slot}_${availablePorts.value[0].port}` : '';
    copyModal.open = true;
};

const pollStatus = async () => {
    try {
        const { data } = await window.axios.get(copyStatusUrl.value);
        copyProgress.value = data;
        if (data.finished) {
            stopPolling();
            copyPhase.value = 'done';
        }
    } catch (e) {
        // transient (worker belum sempat update) — biarkan polling lanjut
    }
};

const submitCopy = async () => {
    let dstSlot;
    let dstPort;
    if (availablePorts.value.length) {
        [dstSlot, dstPort] = targetKey.value.split('_').map(Number);
    } else {
        dstSlot = Number(manualSlot.value);
        dstPort = Number(manualPort.value);
    }

    copyError.value = '';
    copySubmitting.value = true;
    const total = selected.value.size;
    try {
        const { data } = await window.axios.post(route('smartolt.port-onus.copy', [props.olt.id, props.slot, props.port]), {
            onu_ids: [...selected.value],
            dst_slot: dstSlot,
            dst_port: dstPort,
            execute: copyExecute.value,
        });
        copyStatusUrl.value = data.status_url;
        copyRegistrationsUrl.value = data.registrations_url;
        copyProgress.value = { ...blankProgress(), total, execute: copyExecute.value };
        clearSelection();
        copyPhase.value = 'running';
        await pollStatus();
        pollTimer = setInterval(pollStatus, 1500);
    } catch (e) {
        const errors = e.response?.data?.errors;
        copyError.value = errors ? Object.values(errors).flat()[0] : (e.response?.data?.message ?? e.message ?? t('portonus.request_failed'));
    } finally {
        copySubmitting.value = false;
    }
};

onUnmounted(stopPolling);

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

// ONU yang difokus (dari ?focus=) mungkin ada di halaman paginasi lain → lompat dulu.
const focusFocusedOnu = () => {
    if (focusId.value) {
        const idx = filteredOnus.value.findIndex((o) => o.onu_id === focusId.value);
        if (idx >= 0) onuPage.value = Math.floor(idx / pageSize.value) + 1;
    }
    nextTick(scrollToFocus);
};

watch(() => props.initial_search, (v) => { search.value = v ?? ''; });
watch(() => props.focus_onu_id, (v) => { focusId.value = v; focusFocusedOnu(); });
onMounted(focusFocusedOnu);

const refreshing = ref(false);
const refresh = () => {
    refreshing.value = true;
    router.post(route('smartolt.port-onus.refresh', [props.olt.id, props.slot, props.port]), {}, {
        preserveScroll: true,
        onFinish: () => { refreshing.value = false; },
    });
};

const busy = reactive({});
const actionKey = (onu) => `${onu.if_index}-${onu.onu_id}`;

const rebootOnu = async (onu) => {
    const ok = await confirm({
        title: t('portonus.act_reboot'),
        message: t('portonus.reboot_msg', { interface: onu.interface }),
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
    const ok = await confirm({
        title: active ? t('portonus.act_enable') : t('portonus.act_disable'),
        message: active
            ? t('portonus.toggle_enable_msg', { interface: onu.interface })
            : t('portonus.toggle_disable_msg', { interface: onu.interface }),
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

const deleteOnu = async (onu) => {
    const ok = await confirm({
        title: t('portonus.act_delete'),
        message: t('portonus.delete_msg', { interface: onu.interface, onuId: onu.onu_id }),
        confirmLabel: t('common.delete'),
        variant: 'danger',
    });

    if (!ok) {
        return;
    }

    const key = actionKey(onu);
    busy[key] = true;
    router.post(route('smartolt.onu.delete', [props.olt.id, props.slot, props.port, onu.onu_id]), {}, {
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

// --- Add Map (tempel ONU sebagai pin di Peta) ---
const addMap = reactive({ open: false, onu: null, url: '', loading: false, error: '' });

const openAddMap = (onu) => {
    addMap.onu = onu;
    addMap.url = '';
    addMap.error = '';
    addMap.loading = false;
    addMap.open = true;
};

// Opsi 1: paste link Google Maps → resolve koordinat → langsung pasang pin.
const pinFromLink = async () => {
    if (!addMap.url.trim() || addMap.loading) return;
    addMap.loading = true;
    addMap.error = '';
    try {
        const { data } = await window.axios.post(route('map.resolve-link'), { url: addMap.url.trim() });
        if (!data.ok) {
            addMap.error = data.error ?? t('portonus.coord_not_found');
            addMap.loading = false;
            return;
        }
        router.post(
            route('map.pins.store'),
            {
                snmp_olt_id: props.olt.id,
                slot: props.slot,
                port: props.port,
                onu_id: addMap.onu.onu_id,
                serial_number: addMap.onu.serial_number ?? null,
                latitude: data.latitude,
                longitude: data.longitude,
            },
            {
                preserveScroll: true,
                onSuccess: () => { addMap.open = false; },
                onFinish: () => { addMap.loading = false; },
            },
        );
    } catch (e) {
        addMap.error = e?.response?.data?.error ?? t('portonus.gmaps_failed');
        addMap.loading = false;
    }
};

// Opsi 2: buka Peta dalam mode placement, ONU ini sudah pra-terpilih.
const placeOnMap = () => {
    router.get(
        route('map.index', {
            place_olt: props.olt.id,
            place_slot: props.slot,
            place_port: props.port,
            place_onu: addMap.onu.onu_id,
        }),
    );
};

// ONU yang sudah punya pin di peta → tombol berubah jadi "Lihat di Peta".
const pinnedSet = computed(() => new Set(props.pinned_onu_ids));
const isPinned = (onu) => pinnedSet.value.has(onu.onu_id);

const viewOnMap = (onu) => {
    router.get(
        route('map.index', {
            focus_olt: props.olt.id,
            focus_slot: props.slot,
            focus_port: props.port,
            focus_onu: onu.onu_id,
        }),
    );
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
                <div class="grid gap-2 [&>a>button]:w-full [&>button]:w-full sm:flex sm:flex-wrap sm:items-center sm:[&>a>button]:w-auto sm:[&>button]:w-auto">
                    <div v-if="navPorts.length > 1" class="flex items-center gap-1 rounded-lg border border-white/10 bg-slate-900/40 p-1 backdrop-blur-xl">
                        <button
                            type="button"
                            class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-md text-slate-300 transition-colors enabled:hover:bg-white/5 enabled:hover:text-white disabled:cursor-not-allowed disabled:opacity-30"
                            :disabled="!prevPort"
                            :title="prevPort ? `Slot ${prevPort.slot} / Port ${prevPort.port}` : $t('portonus.port_first')"
                            @click="goToPort(prevPort)"
                        >
                            <ChevronLeft class="h-4 w-4" />
                        </button>
                        <select
                            :value="currentPortKey"
                            class="min-h-9 min-w-0 flex-1 rounded-md border-0 bg-transparent py-1.5 pl-2 pr-7 text-sm font-medium text-slate-100 focus:ring-1 focus:ring-cyan-500 sm:flex-none sm:max-w-[12rem]"
                            :title="$t('portonus.switch_port')"
                            @change="onPortSelect"
                        >
                            <option v-for="p in navPorts" :key="`${p.slot}_${p.port}`" :value="`${p.slot}_${p.port}`" class="bg-slate-900">
                                Slot {{ p.slot }} / Port {{ p.port }}<template v-if="p.name"> — {{ p.name }}</template>
                            </option>
                        </select>
                        <button
                            type="button"
                            class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-md text-slate-300 transition-colors enabled:hover:bg-white/5 enabled:hover:text-white disabled:cursor-not-allowed disabled:opacity-30"
                            :disabled="!nextPort"
                            :title="nextPort ? `Slot ${nextPort.slot} / Port ${nextPort.port}` : $t('portonus.port_last')"
                            @click="goToPort(nextPort)"
                        >
                            <ChevronRight class="h-4 w-4" />
                        </button>
                    </div>
                    <Link :href="route('smartolt.gpon-ports', olt.id)">
                        <SecondaryButton type="button">
                            <ArrowLeft class="mr-2 h-4 w-4" />
                            GPON Port & ONU
                        </SecondaryButton>
                    </Link>
                    <SecondaryButton v-if="canTr069" type="button" @click="tr069ModalOpen = true">
                        <Cloud class="mr-2 h-4 w-4" />
                        {{ $t('portonus.tr069_bulk') }}
                    </SecondaryButton>
                    <PrimaryButton type="button" @click="refresh">
                        <RefreshCw class="mr-2 h-4 w-4" />
                        {{ $t('portonus.refresh_onu') }}
                    </PrimaryButton>
                </div>
            </div>
        </template>

        <div class="min-h-[60vh] pt-5 pb-16 sm:pt-8">
            <div class="w-full space-y-5 px-4 sm:px-6 lg:px-8">

                <!-- Stat cards -->
                <div class="grid gap-4 sm:grid-cols-2 md:grid-cols-4">
                    <!-- Data status -->
                    <div class="kv-stat">
                        <div class="flex items-center justify-between">
                            <p class="text-xs font-medium uppercase tracking-wider text-slate-500">{{ $t('portonus.stat_status') }}</p>
                            <span
                                class="h-2 w-2 rounded-full"
                                :class="snapshot.ok ? 'bg-emerald-500' : 'bg-slate-300'"
                            ></span>
                        </div>
                        <p
                            class="mt-3 text-2xl font-bold"
                            :class="snapshot.ok ? 'text-emerald-400' : 'text-slate-400'"
                        >
                            {{ snapshot.ok ? $t('portonus.stat_available') : $t('portonus.stat_empty') }}
                        </p>
                    </div>
                    <!-- Total ONU -->
                    <div class="kv-stat">
                        <p class="text-xs font-medium uppercase tracking-wider text-slate-500">{{ $t('portonus.stat_total_onu') }}</p>
                        <p class="mt-3 text-2xl font-bold text-white">{{ snapshot.count }}</p>
                    </div>
                    <!-- Online -->
                    <div class="kv-stat">
                        <p class="text-xs font-medium uppercase tracking-wider text-slate-500">{{ $t('portonus.stat_online') }}</p>
                        <div class="mt-3 flex items-end gap-2">
                            <p class="text-2xl font-bold text-emerald-400">
                                {{ snapshot.onus.filter((o) => o.online).length }}
                            </p>
                            <p class="mb-0.5 text-sm text-slate-400">/ {{ snapshot.count }}</p>
                        </div>
                    </div>
                    <!-- Refresh terakhir -->
                    <div class="kv-stat">
                        <p class="text-xs font-medium uppercase tracking-wider text-slate-500">{{ $t('portonus.stat_last_refresh') }}</p>
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
                                    {{ $t('portonus.registered_onu') }}
                                    <span v-if="snapshot.onus.length" class="ml-1 text-sm font-normal text-slate-500">({{ filteredOnus.length }}/{{ snapshot.onus.length }})</span>
                                </h3>
                                <p v-if="snapshot.rx_power?.error" class="mt-0.5 text-xs text-red-400">
                                    {{ $t('portonus.rx_read_failed', { error: snapshot.rx_power.error }) }}
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
                                :placeholder="$t('portonus.search_placeholder')"
                                class="kv-filter-control !pl-9 !pr-9"
                            />
                            <button v-if="search" type="button" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-500 hover:text-white" :title="$t('common.clear')" @click="search = ''">
                                <X class="h-4 w-4" />
                            </button>
                        </div>
                        <div class="flex items-center gap-2">
                            <select v-model="phaseFilter" class="min-h-11 rounded-lg border border-white/10 bg-slate-900/60 pl-3 pr-8 text-sm text-slate-100 shadow-inner shadow-black/20 focus:border-cyan-500 focus:ring-cyan-500">
                                <option value="all">{{ $t('portonus.filter_all_phase') }}</option>
                                <option value="online">{{ $t('portonus.filter_online') }}</option>
                                <option value="offline">{{ $t('portonus.filter_offline') }}</option>
                            </select>
                            <select v-model="adminFilter" class="min-h-11 rounded-lg border border-white/10 bg-slate-900/60 pl-3 pr-8 text-sm text-slate-100 shadow-inner shadow-black/20 focus:border-cyan-500 focus:ring-cyan-500">
                                <option value="all">{{ $t('portonus.filter_all_admin') }}</option>
                                <option value="active">{{ $t('portonus.filter_active') }}</option>
                                <option value="disabled">{{ $t('portonus.filter_disabled') }}</option>
                            </select>
                            <button v-if="hasFilter" type="button" class="kv-filter-reset" @click="clearFilters">{{ $t('common.reset') }}</button>
                        </div>
                    </div>

                    <!-- Selection / copy toolbar -->
                    <div v-if="canCopy && snapshot.onus.length > 0" class="flex flex-col gap-3 border-b border-white/10 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                        <label class="inline-flex items-center gap-2 text-sm text-slate-300">
                            <input
                                type="checkbox"
                                :checked="allFilteredSelected"
                                class="h-4 w-4 rounded border-white/10 text-cyan-400 focus:ring-cyan-500"
                                @change="toggleSelectAll"
                            />
                            {{ $t('portonus.select_all', { count: filteredOnus.length }) }}
                        </label>
                        <div class="flex items-center gap-2">
                            <span v-if="selectedCount" class="text-sm text-slate-400">{{ $t('portonus.n_selected', { count: selectedCount }) }}</span>
                            <button v-if="selectedCount" type="button" class="kv-filter-reset" @click="clearSelection">{{ $t('portonus.clear_selection') }}</button>
                            <PrimaryButton type="button" :disabled="selectedCount === 0" @click="openCopy">
                                <Copy class="mr-2 h-4 w-4" />
                                {{ $t('portonus.copy_to_port') }}
                            </PrimaryButton>
                        </div>
                    </div>

                    <!-- Skeleton saat Refresh ONU (baca SNMP) berjalan -->
                    <ListSkeleton v-if="refreshing" :rows="10" />

                    <!-- Empty state -->
                    <div v-else-if="snapshot.onus.length === 0" class="px-6 py-14 text-center">
                        <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-slate-800/60 ring-1 ring-slate-500/30">
                            <Wifi class="h-7 w-7 text-slate-400" />
                        </div>
                        <h3 class="text-sm font-semibold text-slate-200">{{ $t('portonus.empty_title') }}</h3>
                        <p class="mt-1 text-sm text-slate-500">
                            {{ $t('portonus.empty_sub') }}
                        </p>
                    </div>

                    <!-- Table / mobile cards -->
                    <template v-else>
                        <!-- No match state -->
                        <div v-if="filteredOnus.length === 0" class="px-6 py-14 text-center">
                            <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-slate-800/60 ring-1 ring-slate-500/30">
                                <Search class="h-7 w-7 text-slate-400" />
                            </div>
                            <h3 class="text-sm font-semibold text-slate-200">{{ $t('portonus.nomatch_title') }}</h3>
                            <p class="mt-1 text-sm text-slate-500">{{ $t('portonus.nomatch_sub') }}</p>
                            <button type="button" class="mt-4 rounded-lg border border-white/10 px-4 py-2 text-sm text-slate-300 transition-colors hover:bg-white/5" @click="clearFilters">{{ $t('portonus.reset_filter') }}</button>
                        </div>

                        <template v-else>
                        <div class="kv-mobile-list">
                            <article
                                v-for="onu in pagedOnus"
                                :key="`${onu.if_index}-${onu.onu_id}`"
                                :data-onu-id="onu.onu_id"
                                class="kv-mobile-card transition-shadow"
                                :class="onu.onu_id === focusId ? 'ring-2 ring-cyan-500/60' : ''"
                            >
                                <div class="kv-mobile-card-header">
                                    <div class="flex min-w-0 items-start gap-2.5">
                                        <input
                                            v-if="canCopy"
                                            type="checkbox"
                                            :checked="isSelected(onu)"
                                            class="mt-0.5 h-4 w-4 flex-shrink-0 rounded border-white/10 text-cyan-400 focus:ring-cyan-500"
                                            @change="toggleSelect(onu)"
                                        />
                                        <div class="min-w-0">
                                            <h4 class="kv-mobile-card-title">{{ onu.interface }}</h4>
                                            <p class="kv-mobile-card-subtitle">{{ onu.name || onu.description || '—' }}</p>
                                        </div>
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
                                        <span class="kv-mobile-label">{{ $t('portonus.col_serial') }}</span>
                                        <span class="kv-mobile-value font-mono text-xs">{{ onu.serial_number || '—' }}</span>
                                    </div>
                                    <div class="kv-mobile-field">
                                        <span class="kv-mobile-label">{{ $t('portonus.col_odp') }}</span>
                                        <OnuOdpCell
                                            :onu="onu"
                                            :odps="odps"
                                            :current-odp-id="odpIdFor(onu)"
                                            :olt-id="olt.id"
                                            :slot="slot"
                                            :port="port"
                                        />
                                    </div>
                                    <div class="kv-mobile-field">
                                        <span class="kv-mobile-label">{{ $t('portonus.col_type') }}</span>
                                        <span class="kv-mobile-value">{{ onu.type_name || '—' }}</span>
                                    </div>
                                    <div class="kv-mobile-field">
                                        <span class="kv-mobile-label">{{ $t('portonus.col_phase') }}</span>
                                        <span class="kv-mobile-value" :class="onu.online ? 'text-emerald-400' : 'text-slate-500'">
                                            {{ onu.phase_state }}
                                        </span>
                                    </div>
                                    <div class="kv-mobile-field">
                                        <span class="kv-mobile-label">{{ $t('portonus.col_admin') }}</span>
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
                                        <span class="kv-mobile-label">{{ $t('portonus.col_last_down') }}</span>
                                        <span class="kv-mobile-value" :title="onu.last_down_cause || ''">{{ lastDownCauseLabel(onu.last_down_cause) }}</span>
                                    </div>
                                </div>

                                <div class="mt-4 flex flex-wrap gap-2">
                                    <IconButton
                                        v-if="caps.supports_onu_info_write"
                                        :title="$t('portonus.act_edit_info')"
                                        @click="openEdit(onu)"
                                    >
                                        <Pencil class="h-4 w-4" />
                                    </IconButton>
                                    <IconButton
                                        v-if="caps.supports_cli_onu_detail"
                                        :href="route('smartolt.onu.detail', [olt.id, slot, port, onu.onu_id])"
                                        :title="$t('portonus.act_detail')"
                                    >
                                        <Info class="h-4 w-4" />
                                    </IconButton>
                                    <IconButton
                                        v-if="caps.supports_cli_onu_configure"
                                        variant="primary"
                                        :href="route('smartolt.onu.configure', [olt.id, slot, port, onu.onu_id])"
                                        :title="$t('portonus.act_configure')"
                                    >
                                        <Settings class="h-4 w-4" />
                                    </IconButton>
                                    <IconButton
                                        :variant="isPinned(onu) ? 'success' : 'primary'"
                                        :title="isPinned(onu) ? $t('portonus.act_view_map') : $t('portonus.act_add_map')"
                                        @click="isPinned(onu) ? viewOnMap(onu) : openAddMap(onu)"
                                    >
                                        <MapPinned v-if="isPinned(onu)" class="h-4 w-4" />
                                        <MapPin v-else class="h-4 w-4" />
                                    </IconButton>
                                    <IconButton
                                        v-if="caps.supports_onu_toggle"
                                        :variant="onu.admin_state === 'active' ? 'warning' : 'success'"
                                        :disabled="busy[actionKey(onu)]"
                                        :title="onu.admin_state === 'active' ? $t('portonus.act_disable') : $t('portonus.act_enable')"
                                        @click="toggleOnu(onu)"
                                    >
                                        <ToggleRight v-if="onu.admin_state === 'active'" class="h-4 w-4" />
                                        <ToggleLeft v-else class="h-4 w-4" />
                                    </IconButton>
                                    <IconButton
                                        v-if="caps.supports_reboot"
                                        variant="danger"
                                        :disabled="busy[actionKey(onu)]"
                                        :title="$t('portonus.act_reboot')"
                                        @click="rebootOnu(onu)"
                                    >
                                        <Power class="h-4 w-4" />
                                    </IconButton>
                                    <IconButton
                                        v-if="caps.supports_onu_delete"
                                        variant="danger"
                                        :disabled="busy[actionKey(onu)]"
                                        :title="$t('portonus.act_delete')"
                                        @click="deleteOnu(onu)"
                                    >
                                        <Trash2 class="h-4 w-4" />
                                    </IconButton>
                                </div>
                            </article>
                        </div>

                        <div class="kv-table-desktop">
                        <table class="min-w-[720px] w-full tabular-nums">
                            <thead>
                                <tr class="border-b border-white/10 bg-slate-950/40">
                                    <th v-if="canCopy" class="w-px px-4 py-3.5 text-left">
                                        <input
                                            type="checkbox"
                                            :checked="allFilteredSelected"
                                            class="h-4 w-4 rounded border-white/10 text-cyan-400 focus:ring-cyan-500"
                                            @change="toggleSelectAll"
                                        />
                                    </th>
                                    <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $t('portonus.col_onu') }}</th>
                                    <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $t('portonus.col_serial') }}</th>
                                    <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $t('portonus.col_odp') }}</th>
                                    <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $t('portonus.col_type') }}</th>
                                    <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $t('portonus.col_onu_rx') }}</th>
                                    <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $t('portonus.col_phase') }}</th>
                                    <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $t('portonus.col_admin') }}</th>
                                    <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $t('portonus.col_last_down') }}</th>
                                    <th class="px-6 py-3.5 text-center text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $t('portonus.col_actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/5">
                                <tr
                                    v-for="onu in pagedOnus"
                                    :key="`${onu.if_index}-${onu.onu_id}`"
                                    :data-onu-id="onu.onu_id"
                                    class="transition-colors duration-150 hover:bg-white/[0.03]"
                                    :class="onu.onu_id === focusId ? 'bg-cyan-500/10' : ''"
                                >
                                    <td v-if="canCopy" class="px-4 py-4">
                                        <input
                                            type="checkbox"
                                            :checked="isSelected(onu)"
                                            class="h-4 w-4 rounded border-white/10 text-cyan-400 focus:ring-cyan-500"
                                            @change="toggleSelect(onu)"
                                        />
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="font-semibold text-white">{{ onu.interface }}</div>
                                        <div class="mt-0.5 text-xs text-slate-500">{{ onu.name || onu.description || '—' }}</div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <span class="font-mono text-sm text-slate-200">{{ onu.serial_number || '—' }}</span>
                                    </td>
                                    <td class="px-6 py-4">
                                        <OnuOdpCell
                                            :onu="onu"
                                            :odps="odps"
                                            :current-odp-id="odpIdFor(onu)"
                                            :olt-id="olt.id"
                                            :slot="slot"
                                            :port="port"
                                        />
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
                                        <span :title="onu.last_down_cause || ''">{{ lastDownCauseLabel(onu.last_down_cause) }}</span>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="flex items-center justify-center gap-1.5">
                                            <IconButton
                                                v-if="caps.supports_onu_info_write"
                                                :title="$t('portonus.act_edit_info')"
                                                @click="openEdit(onu)"
                                            >
                                                <Pencil class="h-4 w-4" />
                                            </IconButton>
                                            <IconButton
                                                v-if="caps.supports_cli_onu_detail"
                                                :href="route('smartolt.onu.detail', [olt.id, slot, port, onu.onu_id])"
                                                :title="$t('portonus.act_detail')"
                                            >
                                                <Info class="h-4 w-4" />
                                            </IconButton>
                                            <IconButton
                                                v-if="caps.supports_cli_onu_configure"
                                                variant="primary"
                                                :href="route('smartolt.onu.configure', [olt.id, slot, port, onu.onu_id])"
                                                :title="$t('portonus.act_configure')"
                                            >
                                                <Settings class="h-4 w-4" />
                                            </IconButton>
                                            <IconButton
                                                :variant="isPinned(onu) ? 'success' : 'primary'"
                                                :title="isPinned(onu) ? $t('portonus.act_view_map') : $t('portonus.act_add_map')"
                                                @click="isPinned(onu) ? viewOnMap(onu) : openAddMap(onu)"
                                            >
                                                <MapPinned v-if="isPinned(onu)" class="h-4 w-4" />
                                                <MapPin v-else class="h-4 w-4" />
                                            </IconButton>
                                            <IconButton
                                                v-if="caps.supports_onu_toggle"
                                                :variant="onu.admin_state === 'active' ? 'warning' : 'success'"
                                                :disabled="busy[actionKey(onu)]"
                                                :title="onu.admin_state === 'active' ? $t('portonus.act_disable') : $t('portonus.act_enable')"
                                                @click="toggleOnu(onu)"
                                            >
                                                <ToggleRight v-if="onu.admin_state === 'active'" class="h-4 w-4" />
                                                <ToggleLeft v-else class="h-4 w-4" />
                                            </IconButton>
                                            <IconButton
                                                v-if="caps.supports_reboot"
                                                variant="danger"
                                                :disabled="busy[actionKey(onu)]"
                                                :title="$t('portonus.act_reboot')"
                                                @click="rebootOnu(onu)"
                                            >
                                                <Power class="h-4 w-4" />
                                            </IconButton>
                                            <IconButton
                                                v-if="caps.supports_onu_delete"
                                                variant="danger"
                                                :disabled="busy[actionKey(onu)]"
                                                :title="$t('portonus.act_delete')"
                                                @click="deleteOnu(onu)"
                                            >
                                                <Trash2 class="h-4 w-4" />
                                            </IconButton>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        </div>

                        <ClientPagination
                            v-if="pageCount > 1"
                            v-model:page="onuPage"
                            v-model:page-size="pageSize"
                            :page-count="pageCount"
                            :total="pageTotal"
                            :range-start="rangeStart"
                            :range-end="rangeEnd"
                            label="ONU"
                        />
                        </template>
                    </template>
                </div>
            </div>
        </div>

        <Modal :show="editing.open" @close="editing.open = false">
            <form class="p-6" @submit.prevent="submitEdit">
                <h3 class="text-base font-semibold text-white">{{ $t('portonus.edit_modal_title') }}</h3>
                <p class="mt-1 text-sm text-slate-500">{{ $t('portonus.edit_modal_sub', { interface: editing.interface }) }}</p>
                <div class="mt-4 space-y-4">
                    <div>
                        <InputLabel for="onu_name" :value="$t('portonus.onu_name')" />
                        <TextInput id="onu_name" v-model="editForm.name" type="text" class="mt-1 block w-full" maxlength="191" />
                        <InputError :message="editForm.errors.name" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel for="onu_description" :value="$t('portonus.description')" />
                        <TextInput id="onu_description" v-model="editForm.description" type="text" class="mt-1 block w-full" maxlength="191" />
                        <InputError :message="editForm.errors.description" class="mt-1" />
                    </div>
                </div>
                <div class="mt-6 grid gap-2 sm:flex sm:justify-end">
                    <SecondaryButton type="button" @click="editing.open = false">{{ $t('common.cancel') }}</SecondaryButton>
                    <PrimaryButton type="submit" :disabled="editForm.processing">{{ $t('common.save') }}</PrimaryButton>
                </div>
            </form>
        </Modal>

        <!-- Add Map: pasang ONU sebagai pin di Peta -->
        <Modal :show="addMap.open" max-width="md" @close="addMap.open = false">
            <div class="p-6">
                <div class="flex items-center gap-2">
                    <MapPin class="h-5 w-5 text-cyan-400" />
                    <h3 class="text-base font-semibold text-white">{{ $t('portonus.addmap_title') }}</h3>
                </div>
                <p v-if="addMap.onu" class="mt-1 text-sm text-slate-500">{{ addMap.onu.interface }} · {{ addMap.onu.name || addMap.onu.serial_number || 'ONU' }}</p>

                <!-- Opsi 1: paste link Google Maps -->
                <div class="mt-5 rounded-lg border border-white/10 bg-white/5 p-4">
                    <div class="flex items-center gap-2 text-sm font-medium text-slate-200">
                        <Link2 class="h-4 w-4 text-cyan-400" /> {{ $t('portonus.addmap_paste_gmaps') }}
                    </div>
                    <p class="mt-1 text-xs text-slate-500">{{ $t('portonus.addmap_paste_hint') }}</p>
                    <div class="mt-3 flex gap-2">
                        <TextInput
                            v-model="addMap.url"
                            type="text"
                            class="block w-full"
                            placeholder="https://maps.app.goo.gl/... atau https://www.google.com/maps/@-6.7,111.0,17z"
                            @keyup.enter="pinFromLink"
                        />
                        <PrimaryButton type="button" :disabled="!addMap.url.trim() || addMap.loading" @click="pinFromLink">
                            {{ addMap.loading ? '...' : $t('portonus.addmap_place') }}
                        </PrimaryButton>
                    </div>
                    <p v-if="addMap.error" class="mt-2 text-xs text-red-300">{{ addMap.error }}</p>
                </div>

                <!-- Opsi 2: klik langsung di peta -->
                <div class="mt-3 rounded-lg border border-white/10 bg-white/5 p-4">
                    <div class="flex items-center gap-2 text-sm font-medium text-slate-200">
                        <MapPin class="h-4 w-4 text-cyan-400" /> {{ $t('portonus.addmap_click_map') }}
                    </div>
                    <p class="mt-1 text-xs text-slate-500">{{ $t('portonus.addmap_click_hint') }}</p>
                    <SecondaryButton type="button" class="mt-3" @click="placeOnMap">{{ $t('portonus.addmap_open_map') }}</SecondaryButton>
                </div>

                <div class="mt-6 flex justify-end">
                    <SecondaryButton type="button" @click="addMap.open = false">{{ $t('common.close') }}</SecondaryButton>
                </div>
            </div>
        </Modal>

        <Modal :show="copyModal.open" max-width="lg" @close="closeCopy">
            <!-- Fase 1: form -->
            <div v-if="copyPhase === 'form'" class="p-6">
                <h3 class="text-base font-semibold text-white">{{ $t('portonus.copy_modal_title') }}</h3>
                <p class="mt-1 text-sm text-slate-500">
                    {{ $t('portonus.copy_modal_sub', { count: selectedCount, slot, port }) }}
                </p>

                <div class="mt-4 space-y-4">
                    <div>
                        <InputLabel :value="$t('portonus.dst_port')" />
                        <select
                            v-if="availablePorts.length"
                            v-model="targetKey"
                            class="mt-1 block w-full rounded-lg border border-white/10 bg-slate-900/60 px-3 py-2.5 text-sm text-slate-100 shadow-inner shadow-black/20 focus:border-cyan-500 focus:ring-cyan-500"
                        >
                            <option v-for="p in availablePorts" :key="`${p.slot}_${p.port}`" :value="`${p.slot}_${p.port}`">
                                Slot {{ p.slot }} / Port {{ p.port }}<template v-if="p.name"> — {{ p.name }}</template>
                            </option>
                        </select>
                        <div v-else class="mt-1 grid grid-cols-2 gap-3">
                            <div>
                                <InputLabel for="dst_slot" :value="$t('portonus.slot')" />
                                <TextInput id="dst_slot" v-model="manualSlot" type="number" min="1" class="mt-1 block w-full" />
                            </div>
                            <div>
                                <InputLabel for="dst_port" :value="$t('portonus.port')" />
                                <TextInput id="dst_port" v-model="manualPort" type="number" min="1" class="mt-1 block w-full" />
                            </div>
                        </div>
                        <p v-if="!availablePorts.length" class="mt-1 text-xs text-amber-300">
                            {{ $t('portonus.ports_not_refreshed') }}
                        </p>
                    </div>

                    <label class="flex items-start gap-2.5 text-sm text-slate-300">
                        <input v-model="copyExecute" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-white/10 text-cyan-400 focus:ring-cyan-500" />
                        <span>
                            {{ $t('portonus.execute_after') }}
                            <span class="text-slate-500">{{ $t('portonus.execute_hint') }}</span>
                        </span>
                    </label>

                    <div class="rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2.5 text-xs text-amber-200">
                        {{ $t('portonus.copy_warning') }}
                    </div>

                    <p v-if="copyError" class="rounded-lg border border-red-500/30 bg-red-500/10 px-3 py-2.5 text-xs text-red-300">{{ copyError }}</p>
                </div>

                <div class="mt-6 grid gap-2 sm:flex sm:justify-end">
                    <SecondaryButton type="button" @click="closeCopy">{{ $t('common.cancel') }}</SecondaryButton>
                    <PrimaryButton
                        type="button"
                        :disabled="copySubmitting || (!availablePorts.length && (!manualSlot || !manualPort))"
                        @click="submitCopy"
                    >
                        <Copy class="mr-2 h-4 w-4" />
                        {{ copySubmitting ? $t('portonus.processing') : (copyExecute ? $t('portonus.copy_and_execute') : $t('portonus.generate_script')) }}
                    </PrimaryButton>
                </div>
            </div>

            <!-- Fase 2: berjalan -->
            <div v-else-if="copyPhase === 'running'" class="p-6">
                <div class="flex items-center gap-3">
                    <RefreshCw class="h-5 w-5 animate-spin text-cyan-400" />
                    <h3 class="text-base font-semibold text-white">
                        {{ copyProgress.execute ? $t('portonus.running_execute') : $t('portonus.running_generate') }}
                    </h3>
                </div>
                <p class="mt-1 text-sm text-slate-500">
                    {{ $t('portonus.running_sub', { count: copyProgress.total }) }}
                </p>

                <div class="mt-4">
                    <div class="mb-1.5 flex items-center justify-between text-xs text-slate-400">
                        <span>{{ $t('portonus.n_processed', { processed: copyProgress.processed, total: copyProgress.total }) }}</span>
                        <span>{{ copyPercent }}%</span>
                    </div>
                    <div class="h-2.5 w-full overflow-hidden rounded-full bg-slate-800">
                        <div class="h-full rounded-full bg-cyan-500 transition-all duration-300" :style="{ width: `${copyPercent}%` }"></div>
                    </div>
                    <div class="mt-3 grid grid-cols-3 gap-2 text-center text-xs">
                        <div class="rounded-lg bg-slate-800/60 py-2"><div class="text-base font-semibold text-white">{{ copyProgress.created }}</div>{{ $t('portonus.stat_created') }}</div>
                        <div class="rounded-lg bg-slate-800/60 py-2"><div class="text-base font-semibold text-emerald-400">{{ copyProgress.executed }}</div>{{ $t('portonus.stat_executed') }}</div>
                        <div class="rounded-lg bg-slate-800/60 py-2"><div class="text-base font-semibold text-red-300">{{ copyProgress.failed }}</div>{{ $t('portonus.stat_failed') }}</div>
                    </div>
                </div>

                <div class="mt-6 flex justify-end">
                    <SecondaryButton type="button" @click="closeCopy">{{ $t('portonus.close_keep_running') }}</SecondaryButton>
                </div>
            </div>

            <!-- Fase 3: selesai -->
            <div v-else class="p-6">
                <div class="flex items-center gap-3">
                    <span class="flex h-9 w-9 items-center justify-center rounded-full" :class="copyProgress.status === 'failed' || copyProgress.failed > 0 ? 'bg-amber-500/15' : 'bg-emerald-500/15'">
                        <Copy class="h-5 w-5" :class="copyProgress.status === 'failed' || copyProgress.failed > 0 ? 'text-amber-300' : 'text-emerald-400'" />
                    </span>
                    <h3 class="text-base font-semibold text-white">
                        {{ copyProgress.status === 'failed' ? $t('portonus.batch_failed') : $t('portonus.done') }}
                    </h3>
                </div>
                <p class="mt-2 text-sm text-slate-300">
                    {{ $t('portonus.done_created', { count: copyProgress.created }) }}<span v-if="copyProgress.execute"> · {{ $t('portonus.done_executed', { count: copyProgress.executed }) }}</span> · {{ $t('portonus.done_failed', { count: copyProgress.failed }) }}
                    <span class="text-slate-500">{{ $t('portonus.done_from_total', { total: copyProgress.total }) }}</span>
                </p>
                <p v-if="copyProgress.error" class="mt-2 rounded-lg border border-red-500/30 bg-red-500/10 px-3 py-2.5 text-xs text-red-300">{{ copyProgress.error }}</p>

                <div v-if="copyFailedItems.length" class="mt-3 max-h-40 space-y-1.5 overflow-y-auto rounded-lg border border-white/10 bg-slate-950/40 p-3">
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $t('portonus.failed_header') }}</p>
                    <div v-for="(item, idx) in copyFailedItems" :key="idx" class="text-xs text-slate-400">
                        <span class="font-mono text-slate-300">ONU {{ item.onu_id }}</span><span v-if="item.serial_number"> · {{ item.serial_number }}</span> — {{ item.message }}
                    </div>
                </div>

                <div class="mt-6 grid gap-2 sm:flex sm:justify-end">
                    <SecondaryButton type="button" @click="closeCopy">{{ $t('common.close') }}</SecondaryButton>
                    <Link :href="copyRegistrationsUrl">
                        <PrimaryButton type="button" class="w-full sm:w-auto">{{ $t('portonus.view_registrations') }}</PrimaryButton>
                    </Link>
                </div>
            </div>
        </Modal>

        <Tr069BulkModal v-if="canTr069" :show="tr069ModalOpen" :olt="olt" :slot="slot" :port="port" :acs="acs" @close="tr069ModalOpen = false" />

        <ConfirmModal :state="confirmState" @confirm="handleConfirm" @cancel="handleCancel" />
    </AuthenticatedLayout>
</template>
