<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import IconButton from '@/Components/IconButton.vue';
import OltPortLabel from '@/Components/OltPortLabel.vue';
import OnuOdpCell from '@/Components/OnuOdpCell.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { formatDateTime } from '@/lib/datetime';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Modal from '@/Components/Modal.vue';
import { ArrowLeft, Gauge, Link2, MapPin, MapPinned, Pencil, Power, RefreshCw, Search, Trash2, Wifi, WifiOff } from '@lucide/vue';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';

const { t } = useI18n({ useScope: 'global' });
const onuLabel = (onu) => `${onu.interface}${onu.name ? ` (${onu.name})` : ''}`;

const props = defineProps({
    olt: { type: Object, required: true },
    slot: { type: Number, required: true },
    port: { type: Number, required: true },
    snapshot: { type: Object, default: null },
    rx_status: { type: Object, default: null },
    focus: { type: [String, Number], default: null },
    q: { type: String, default: '' },
    pinned_onu_ids: { type: Array, default: () => [] },
    odps: { type: Array, default: () => [] },
    odp_links: { type: Object, default: () => ({}) },
    port_labels: { type: Object, default: () => ({}) },
});

const odpIdFor = (onu) => props.odp_links?.[onu.onu_id]?.odp_id ?? null;

const page = usePage();

// Label port sisi-NMS (tabel olt_port_labels) — tak pernah ditulis ke OLT.
const canEditPortLabel = computed(
    () => Boolean(page.props.auth?.can?.manage_olt) && Boolean(props.olt.capabilities?.supports_port_label),
);
const portLabel = computed(() => props.port_labels?.[`${props.slot}_${props.port}`] ?? null);
const search = ref(props.q ?? '');
const odpFilter = ref('all'); // 'all' | 'none' | <odp id>

const onus = computed(() => props.snapshot?.onus ?? []);
const filtered = computed(() => {
    const needle = search.value.trim().toLowerCase();
    return onus.value.filter((o) => {
        if (odpFilter.value === 'none' && odpIdFor(o) !== null) return false;
        if (odpFilter.value !== 'all' && odpFilter.value !== 'none' && odpIdFor(o) !== Number(odpFilter.value)) return false;
        if (!needle) return true;
        return [o.serial_number, o.name, o.interface, o.mac].some((v) => String(v ?? '').toLowerCase().includes(needle));
    });
});

const rxClass = (dbm) => {
    if (dbm === null || dbm === undefined) return 'text-slate-500';
    if (dbm >= -25) return 'text-emerald-300';
    if (dbm >= -28) return 'text-amber-300';
    return 'text-red-300';
};
const isFocus = (o) => props.focus != null && String(o.onu_id) === String(props.focus);

const refresh = () => router.post(route('hsairpo-olt.port-onus.refresh', [props.olt.id, props.slot, props.port]), {}, { preserveScroll: true });
const fmt = (v) => formatDateTime(v);

const caps = computed(() => props.olt.capabilities ?? {});
const canManage = computed(() => Boolean(page.props.auth?.can?.manage_olt));
const canReboot = computed(() => canManage.value && caps.value.supports_reboot);
const canRename = computed(() => canManage.value && caps.value.supports_onu_info_write);
const canDelete = computed(() => canManage.value && caps.value.supports_onu_delete);
const canRx = computed(() => Boolean(caps.value.supports_cli_rx));

// Rx per-ONU on-demand (CLI ~2 dtk/ONU). Ambil massal se-port kena 504, jadi tombol per-ONU.
const rxLoading = ref(new Set());
const rxBusy = (onu) => rxLoading.value.has(onu.onu_id);
const refreshRx = (onu) => {
    if (rxBusy(onu)) return;
    const on = new Set(rxLoading.value); on.add(onu.onu_id); rxLoading.value = on;
    router.post(route('hsairpo-olt.onu.rx', [props.olt.id, props.slot, props.port, onu.onu_id]), {}, {
        preserveScroll: true,
        onFinish: () => { const off = new Set(rxLoading.value); off.delete(onu.onu_id); rxLoading.value = off; },
    });
};

// Rx SEMUA ONU se-port di background (job antrean) — port padat > 100 dtk kalau sinkron (504).
const rxRunning = computed(() => props.rx_status?.status === 'running');
const refreshAllRx = () => {
    if (rxRunning.value) return;
    router.post(route('hsairpo-olt.port-rx', [props.olt.id, props.slot, props.port]), {}, { preserveScroll: true });
};
// Selama job jalan, poll ringan (hanya snapshot+rx_status; scan penuh dilewati dlm 5 mnt TTL) agar Rx
// terisi bertahap & progres ter-update tanpa reload manual.
let rxPoll = null;
const stopRxPoll = () => { if (rxPoll) { clearInterval(rxPoll); rxPoll = null; } };
watch(rxRunning, (running) => {
    if (running && !rxPoll) {
        rxPoll = setInterval(() => router.reload({ only: ['snapshot', 'rx_status'], preserveScroll: true, preserveState: true }), 5000);
    } else if (!running) {
        stopRxPoll();
    }
}, { immediate: true });
onBeforeUnmount(stopRxPoll);

const { confirmState, confirm, handleConfirm, handleCancel } = useConfirm();

const renameOnu = ref(null);
const renameValue = ref('');
const openRename = (onu) => {
    renameOnu.value = onu;
    renameValue.value = onu.name ?? '';
};
const submitRename = () => {
    const onu = renameOnu.value;
    renameOnu.value = null;
    router.post(route('hsairpo-olt.onu.info', [props.olt.id, props.slot, props.port, onu.onu_id]), { name: renameValue.value }, { preserveScroll: true });
};

const rebootOnu = async (onu) => {
    const ok = await confirm({
        title: t('portonus.act_reboot'),
        message: t('cdataportonus.reboot_msg', { onu: onuLabel(onu) }),
        confirmLabel: 'Reboot',
    });
    if (!ok) return;
    router.post(route('hsairpo-olt.onu.reboot', [props.olt.id, props.slot, props.port, onu.onu_id]), {}, { preserveScroll: true });
};

const deleteOnu = async (onu) => {
    const ok = await confirm({
        title: t('portonus.act_delete'),
        message: t('cdataportonus.delete_msg', { onu: onuLabel(onu), onuId: onu.onu_id }),
        confirmLabel: t('common.delete'),
    });
    if (!ok) return;
    router.delete(route('hsairpo-olt.onu.delete', [props.olt.id, props.slot, props.port, onu.onu_id]), { preserveScroll: true });
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
</script>

<template>
    <Head :title="`pon${port} · ${olt.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-3">
                    <Link :href="route('hsairpo-olt.detail', olt.id)" class="text-slate-400 hover:text-white">
                        <ArrowLeft class="h-5 w-5" />
                    </Link>
                    <div>
                        <h2 class="text-lg font-semibold leading-tight text-white sm:text-xl">
                            {{ olt.name }} · {{ olt.capabilities.pon_label }} pon{{ port }}
                        </h2>
                        <div class="mt-1 flex items-center gap-2">
                            <span class="text-xs uppercase tracking-wide text-slate-500">{{ $t('portlabel.column') }}</span>
                            <OltPortLabel
                                :olt-id="olt.id"
                                :slot="slot"
                                :port="port"
                                :label="portLabel"
                                :editable="canEditPortLabel"
                                variant="header"
                            />
                        </div>
                    </div>
                </div>
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <SecondaryButton
                        v-if="canRx"
                        type="button"
                        class="w-full justify-center sm:w-auto"
                        :disabled="rxRunning"
                        :title="$t('hsairpo.rx_refresh')"
                        @click="refreshAllRx"
                    >
                        <Gauge class="mr-2 h-4 w-4" :class="{ 'animate-pulse text-cyan-300': rxRunning }" />
                        {{ rxRunning ? $t('hsairpo.rx_progress', { done: rx_status?.done ?? 0, total: rx_status?.total ?? 0 }) : $t('hsairpo.rx_fetch_all') }}
                    </SecondaryButton>
                    <SecondaryButton type="button" class="w-full justify-center sm:w-auto" @click="refresh">
                        <RefreshCw class="mr-2 h-4 w-4" /> {{ $t('common.refresh') }}
                    </SecondaryButton>
                </div>
            </div>
        </template>

        <div class="min-h-[60vh] pt-5 pb-16 sm:pt-8">
            <div class="w-full px-4 sm:px-6 lg:px-8">

                <div class="kv-glass-panel">
                    <div class="flex flex-col gap-3 border-b border-white/10 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                        <div>
                            <h3 class="text-base font-semibold text-white">{{ $t('cdataportonus.onu_on_port', { slot, port }) }}</h3>
                            <p class="text-xs text-slate-400">
                                {{ $t('cdataportonus.onu_count', { count: onus.length }) }}
                                <span v-if="snapshot?.refreshed_at">{{ $t('cdataportonus.updated_suffix', { date: fmt(snapshot.refreshed_at) }) }}</span>
                            </p>
                        </div>
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                            <select
                                v-if="odps.length"
                                v-model="odpFilter"
                                :title="$t('portonus.filter_odp')"
                                class="w-full rounded-lg border-white/10 bg-slate-950/40 text-sm text-slate-200 focus:border-cyan-500 focus:ring-cyan-500 sm:w-auto"
                            >
                                <option value="all">{{ $t('portonus.odp_all') }}</option>
                                <option value="none">{{ $t('portonus.odp_unassigned') }}</option>
                                <option v-for="odp in odps" :key="odp.id" :value="odp.id">{{ odp.name }}</option>
                            </select>
                            <div class="relative sm:w-64">
                                <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" />
                                <input
                                    v-model="search"
                                    type="text"
                                    :placeholder="$t('hsairpo.search_placeholder')"
                                    class="w-full rounded-lg border-white/10 bg-slate-950/40 pl-9 text-sm text-slate-200 placeholder:text-slate-600 focus:border-cyan-500 focus:ring-cyan-500"
                                />
                            </div>
                        </div>
                    </div>

                    <div v-if="onus.length === 0" class="px-6 py-16 text-center">
                        <p class="text-sm font-semibold text-slate-200">{{ $t('cdataportonus.empty_title') }}</p>
                        <p class="mt-1 text-sm text-slate-500">{{ $t('cdataportonus.empty_hint') }}</p>
                    </div>

                    <template v-else>
                        <div class="kv-table-desktop">
                            <table class="w-full min-w-[900px]">
                                <thead>
                                    <tr class="border-b border-white/10 bg-slate-950/40">
                                        <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">ONU</th>
                                        <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">MAC</th>
                                        <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $t('cdataportonus.col_name') }}</th>
                                        <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $t('portonus.col_odp') }}</th>
                                        <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $t('common.status') }}</th>
                                        <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $t('cdataportonus.col_rx') }}</th>
                                        <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $t('hsairpo.col_config') }}</th>
                                        <th class="px-4 py-3.5 text-center text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $t('common.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-white/5">
                                    <tr v-for="o in filtered" :key="o.onu_key" class="transition-colors hover:bg-white/[0.03]" :class="{ 'bg-cyan-500/10': isFocus(o) }">
                                        <td class="px-4 py-4">
                                            <div class="font-mono text-xs text-white">{{ o.interface }}</div>
                                            <div class="mt-0.5 text-xs text-slate-500">ONU {{ o.onu_id }}</div>
                                        </td>
                                        <td class="px-4 py-4 font-mono text-xs text-slate-200">{{ o.mac || o.serial_number || '—' }}</td>
                                        <td class="px-4 py-4 text-sm text-slate-200">{{ o.name || '—' }}</td>
                                        <td class="px-4 py-4">
                                            <OnuOdpCell
                                                :onu="o"
                                                :odps="odps"
                                                :current-odp-id="odpIdFor(o)"
                                                :olt-id="olt.id"
                                                :slot="slot"
                                                :port="port"
                                            />
                                        </td>
                                        <td class="px-4 py-4">
                                            <span class="inline-flex items-center gap-1.5 text-xs font-semibold" :class="o.online ? 'text-emerald-300' : 'text-red-300'">
                                                <component :is="o.online ? Wifi : WifiOff" class="h-3.5 w-3.5" />
                                                {{ o.phase_state || (o.online ? $t('common.online') : $t('common.offline')) }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-4">
                                            <div class="flex items-center gap-2">
                                                <span class="font-mono text-sm" :class="rxClass(o.rx_power_dbm)">
                                                    {{ o.rx_power_label || (o.rx_power_dbm != null ? o.rx_power_dbm + ' dBm' : '—') }}
                                                </span>
                                                <button
                                                    v-if="canRx && o.online"
                                                    type="button"
                                                    :title="$t('hsairpo.rx_refresh')"
                                                    :disabled="rxBusy(o)"
                                                    class="text-slate-500 transition-colors hover:text-cyan-300 disabled:opacity-40"
                                                    @click="refreshRx(o)"
                                                >
                                                    <RefreshCw class="h-3.5 w-3.5" :class="{ 'animate-spin': rxBusy(o) }" />
                                                </button>
                                            </div>
                                        </td>
                                        <td class="px-4 py-4 text-xs text-slate-400">
                                            {{ o.config_state || '—' }}<span v-if="o.match_state"> · {{ o.match_state }}</span>
                                        </td>
                                        <td class="px-4 py-4">
                                            <div class="flex justify-center gap-1.5">
                                                <IconButton v-if="canRename" :title="$t('cdataportonus.rename_title')" @click="openRename(o)">
                                                    <Pencil class="h-4 w-4" />
                                                </IconButton>
                                                <IconButton v-if="canReboot" variant="danger" :title="$t('portonus.act_reboot')" @click="rebootOnu(o)">
                                                    <Power class="h-4 w-4" />
                                                </IconButton>
                                                <IconButton v-if="canDelete" variant="danger" :title="$t('portonus.act_delete')" @click="deleteOnu(o)">
                                                    <Trash2 class="h-4 w-4" />
                                                </IconButton>
                                                <IconButton :variant="isPinned(o) ? 'success' : 'primary'" :title="isPinned(o) ? $t('portonus.act_view_map') : $t('portonus.act_add_map')" @click="isPinned(o) ? viewOnMap(o) : openAddMap(o)">
                                                    <MapPinned v-if="isPinned(o)" class="h-4 w-4" />
                                                    <MapPin v-else class="h-4 w-4" />
                                                </IconButton>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="kv-mobile-list">
                            <article v-for="o in filtered" :key="o.onu_key" class="kv-mobile-card" :class="{ 'ring-1 ring-cyan-500/50': isFocus(o) }">
                                <div class="flex items-center justify-between">
                                    <span class="font-mono text-xs text-white">{{ o.interface }}</span>
                                    <span class="inline-flex items-center gap-1 text-xs font-semibold" :class="o.online ? 'text-emerald-300' : 'text-red-300'">
                                        <component :is="o.online ? Wifi : WifiOff" class="h-3.5 w-3.5" />
                                        {{ o.online ? $t('common.online') : $t('common.offline') }}
                                    </span>
                                </div>
                                <p class="mt-1 text-sm text-slate-200">{{ o.name || '—' }}</p>
                                <div class="mt-2 flex items-center justify-between text-xs">
                                    <span class="font-mono text-slate-400">{{ o.mac || o.serial_number || '—' }}</span>
                                    <span class="flex items-center gap-1.5">
                                        <span class="font-mono" :class="rxClass(o.rx_power_dbm)">{{ o.rx_power_label || '—' }}</span>
                                        <button
                                            v-if="canRx && o.online"
                                            type="button"
                                            :title="$t('hsairpo.rx_refresh')"
                                            :disabled="rxBusy(o)"
                                            class="text-slate-500 hover:text-cyan-300 disabled:opacity-40"
                                            @click="refreshRx(o)"
                                        >
                                            <RefreshCw class="h-3.5 w-3.5" :class="{ 'animate-spin': rxBusy(o) }" />
                                        </button>
                                    </span>
                                </div>
                                <div class="mt-2 flex items-center gap-2 text-xs">
                                    <span class="shrink-0 text-slate-500">{{ $t('portonus.col_odp') }}</span>
                                    <OnuOdpCell
                                        :onu="o"
                                        :odps="odps"
                                        :current-odp-id="odpIdFor(o)"
                                        :olt-id="olt.id"
                                        :slot="slot"
                                        :port="port"
                                    />
                                </div>
                                <div class="mt-3 flex gap-2">
                                    <IconButton v-if="canRename" :title="$t('cdataportonus.rename_title')" @click="openRename(o)">
                                        <Pencil class="h-4 w-4" />
                                    </IconButton>
                                    <IconButton v-if="canReboot" variant="danger" :title="$t('portonus.act_reboot')" @click="rebootOnu(o)">
                                        <Power class="h-4 w-4" />
                                    </IconButton>
                                    <IconButton v-if="canDelete" variant="danger" :title="$t('portonus.act_delete')" @click="deleteOnu(o)">
                                        <Trash2 class="h-4 w-4" />
                                    </IconButton>
                                    <IconButton :variant="isPinned(o) ? 'success' : 'primary'" :title="isPinned(o) ? $t('portonus.act_view_map') : $t('portonus.act_add_map')" @click="isPinned(o) ? viewOnMap(o) : openAddMap(o)">
                                        <MapPinned v-if="isPinned(o)" class="h-4 w-4" />
                                        <MapPin v-else class="h-4 w-4" />
                                    </IconButton>
                                </div>
                            </article>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <ConfirmModal :state="confirmState" @confirm="handleConfirm" @cancel="handleCancel" />

        <!-- Add Map: pasang ONU sebagai pin di Peta -->
        <Modal :show="addMap.open" max-width="md" @close="addMap.open = false">
            <div class="p-6">
                <div class="flex items-center gap-2">
                    <MapPin class="h-5 w-5 text-cyan-400" />
                    <h3 class="text-base font-semibold text-white">{{ $t('portonus.addmap_title') }}</h3>
                </div>
                <p v-if="addMap.onu" class="mt-1 text-sm text-slate-500">{{ addMap.onu.interface }} · {{ addMap.onu.name || addMap.onu.serial_number || 'ONU' }}</p>

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

        <!-- Modal ubah nama -->
        <div v-if="renameOnu" class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="renameOnu = null"></div>
            <div class="relative w-full max-w-md rounded-xl border border-white/10 bg-slate-900/90 p-6 shadow-2xl backdrop-blur-xl">
                <h3 class="text-base font-semibold text-white">{{ $t('cdataportonus.rename_modal_title') }}</h3>
                <p class="mt-1 font-mono text-xs text-slate-400">{{ renameOnu.interface }}</p>
                <form class="mt-4" @submit.prevent="submitRename">
                    <InputLabel for="rename" :value="$t('cdataportonus.rename_label')" />
                    <TextInput id="rename" v-model="renameValue" class="mt-1 block w-full" maxlength="64" autocomplete="off" />
                    <div class="mt-5 flex justify-end gap-3">
                        <SecondaryButton type="button" @click="renameOnu = null">{{ $t('common.cancel') }}</SecondaryButton>
                        <PrimaryButton type="submit">{{ $t('common.save') }}</PrimaryButton>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
