<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import IconButton from '@/Components/IconButton.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import OltPortLabel from '@/Components/OltPortLabel.vue';
import OnuOdpCell from '@/Components/OnuOdpCell.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import PortOnuStats from '@/Components/CDataOlt/PortOnuStats.vue';
import PortSwitcher from '@/Components/CDataOlt/PortSwitcher.vue';
import AcsPinCell from '@/Components/Genieacs/AcsPinCell.vue';
import ConnectedDevicesModal from '@/Components/Genieacs/ConnectedDevicesModal.vue';
import PinDeviceModal from '@/Components/Genieacs/PinDeviceModal.vue';
import WifiSettingsModal from '@/Components/Genieacs/WifiSettingsModal.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Laptop, Link2, MapPin, MapPinned, Pencil, Power, RefreshCw, Router, Save, Search, TriangleAlert, Wifi, WifiOff } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n({ useScope: 'global' });
const onuLabel = (onu) => `${onu.interface}${onu.name ? ` (${onu.name})` : ''}`;

const props = defineProps({
    olt: { type: Object, required: true },
    slot: { type: Number, required: true },
    port: { type: Number, required: true },
    snapshot: { type: Object, default: null },
    port_row: { type: Object, default: null },
    focus: { type: [String, Number], default: null },
    q: { type: String, default: '' },
    pinned_onu_ids: { type: Array, default: () => [] },
    odps: { type: Array, default: () => [] },
    odp_links: { type: Object, default: () => ({}) },
    // Penanda ter-ACS per ONU (ber-key onu_id), dari tabel lokal genieacs_device_map.
    genieacs_map: { type: Object, default: () => ({}) },
    port_labels: { type: Object, default: () => ({}) },
});

const page = usePage();
const caps = computed(() => props.olt.capabilities ?? {});
const portName = computed(() => `PON${String(props.port).padStart(2, '0')}`);

const odpIdFor = (onu) => props.odp_links?.[onu.onu_id]?.odp_id ?? null;
const acsFor = (onu) => props.genieacs_map?.[onu.onu_id] ?? null;

const clientsOnu = ref(null);
const openClients = (onu) => { clientsOnu.value = onu; };
const wifiOnu = ref(null);
const openWifi = (onu) => { wifiOnu.value = onu; };
const pinOnu = ref(null);
const openPin = (onu) => { pinOnu.value = onu; };

// Label port sisi-NMS (tabel olt_port_labels) — tak pernah ditulis ke OLT.
const canManage = computed(() => Boolean(page.props.auth?.can?.manage_olt));
const canEditPortLabel = computed(() => canManage.value && Boolean(caps.value.supports_port_label));
const portLabel = computed(() => props.port_labels?.[`${props.slot}_${props.port}`] ?? null);
// Aksi web HSGQ butuh port web OLT; tanpa itu tombolnya disembunyikan (server juga menolak).
const webReady = computed(() => Boolean(props.olt.web_port));
const canReboot = computed(() => canManage.value && webReady.value && Boolean(caps.value.supports_reboot));
const canRename = computed(() => canManage.value && webReady.value && Boolean(caps.value.supports_onu_info_write));
const canSaveConfig = computed(() => canManage.value && webReady.value && Boolean(caps.value.supports_config_save));

const search = ref(props.q ?? '');
const phaseFilter = ref('all'); // 'all' | 'online' | 'offline'
const odpFilter = ref('all'); // 'all' | 'none' | <odp id>

const onus = computed(() => props.snapshot?.onus ?? []);
const statusUnverified = computed(() => onus.value.some((o) => o.status_verified === false));
const filtered = computed(() => {
    const needle = search.value.trim().toLowerCase();
    return onus.value.filter((o) => {
        if (phaseFilter.value === 'online' && !o.online) return false;
        if (phaseFilter.value === 'offline' && o.online) return false;
        if (odpFilter.value === 'none' && odpIdFor(o) !== null) return false;
        if (odpFilter.value !== 'all' && odpFilter.value !== 'none' && odpIdFor(o) !== Number(odpFilter.value)) return false;
        if (!needle) return true;
        return [o.serial_number, o.name, o.description, o.interface, o.mac].some((v) => String(v ?? '').toLowerCase().includes(needle));
    });
});

const hasFilter = computed(() => search.value.trim() !== '' || phaseFilter.value !== 'all' || odpFilter.value !== 'all');
const clearFilters = () => { search.value = ''; phaseFilter.value = 'all'; odpFilter.value = 'all'; };

const rxClass = (dbm) => {
    if (dbm === null || dbm === undefined) return 'text-slate-500';
    if (dbm >= -25) return 'text-emerald-300';
    if (dbm >= -28) return 'text-amber-300';
    return 'text-rose-300';
};
const isFocus = (o) => props.focus != null && String(o.onu_id) === String(props.focus);
const typeLine = (o) => [o.vendor_id, o.type_name].filter(Boolean).join(' · ') || '—';

const refresh = () => router.post(route('hsgq-olt.port-onus.refresh', [props.olt.id, props.slot, props.port]), {}, { preserveScroll: true });

const { confirmState, confirm, handleConfirm, handleCancel } = useConfirm();

const rebootOnu = async (onu) => {
    const ok = await confirm({
        title: t('portonus.act_reboot'),
        message: `${t('cdataportonus.reboot_msg', { onu: onuLabel(onu) })} ${t('hsgq.web_action_note')}`,
        confirmLabel: 'Reboot',
        variant: 'danger',
    });
    if (!ok) return;
    router.post(route('hsgq-olt.onu.reboot', [props.olt.id, props.slot, props.port, onu.onu_id]), {}, { preserveScroll: true });
};

const saving = ref(false);
const saveConfig = async () => {
    const ok = await confirm({
        title: t('smartolt.confirm_save_title'),
        message: t('hsgq.confirm_save_msg', { name: props.olt.name }),
        confirmLabel: t('common.save'),
        variant: 'info',
    });
    if (!ok) return;
    router.post(route('hsgq-olt.config.save', props.olt.id), {}, {
        preserveScroll: true,
        onStart: () => { saving.value = true; },
        onFinish: () => { saving.value = false; },
    });
};

// --- Ubah nama + deskripsi (dua field terpisah di web HSGQ) ---
// `open` terpisah dari `onu` supaya isi modal tetap ada selama transisi tutup.
const rename = reactive({ open: false, onu: null, name: '', description: '', errors: {}, processing: false });
const openRename = (onu) => {
    rename.onu = onu;
    rename.open = true;
    rename.name = onu.name ?? '';
    rename.description = onu.description ?? '';
    rename.errors = {};
};
const closeRename = () => { rename.open = false; };
const submitRename = () => {
    const onu = rename.onu;
    rename.processing = true;
    router.post(
        route('hsgq-olt.onu.info', [props.olt.id, props.slot, props.port, onu.onu_id]),
        { name: rename.name, description: rename.description === '' ? null : rename.description },
        {
            preserveScroll: true,
            onSuccess: () => closeRename(),
            onError: (errors) => { rename.errors = errors; },
            onFinish: () => { rename.processing = false; },
        },
    );
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
    router.get(route('map.index', {
        place_olt: props.olt.id,
        place_slot: props.slot,
        place_port: props.port,
        place_onu: addMap.onu.onu_id,
    }));
};

// ONU yang sudah punya pin di peta → tombol berubah jadi "Lihat di Peta".
const pinnedSet = computed(() => new Set(props.pinned_onu_ids));
const isPinned = (onu) => pinnedSet.value.has(onu.onu_id);
const viewOnMap = (onu) => {
    router.get(route('map.index', {
        focus_olt: props.olt.id,
        focus_slot: props.slot,
        focus_port: props.port,
        focus_onu: onu.onu_id,
    }));
};
</script>

<template>
    <Head :title="`${portName} · ${olt.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex items-center gap-3">
                    <Link :href="route('hsgq-olt.detail', olt.id)" :title="$t('common.back')" :aria-label="$t('common.back')" class="text-slate-400 hover:text-white">
                        <ArrowLeft class="h-5 w-5" />
                    </Link>
                    <div>
                        <h2 class="flex flex-wrap items-center gap-2 text-lg font-semibold leading-tight text-white sm:text-xl">
                            {{ olt.name }} · {{ caps.pon_label }} {{ portName }}
                            <span v-if="caps.hsgq_unverified" class="kv-pill-warning" :title="$t('hsgq.unverified_hint')">{{ $t('hsgq.unverified_badge') }}</span>
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
                <div class="grid gap-2 sm:flex sm:flex-wrap sm:items-center">
                    <PortSwitcher
                        :olt-id="olt.id"
                        :slot="slot"
                        :port="port"
                        :ports="olt.last_test_result?.ports ?? []"
                        :labels="port_labels"
                        :pon-label="caps.pon_label"
                        route-name="hsgq-olt.port-onus"
                    />
                    <SecondaryButton v-if="canSaveConfig" type="button" class="w-full justify-center sm:w-auto" :disabled="saving" @click="saveConfig">
                        <Save class="mr-2 h-4 w-4" /> {{ $t('hsgq.save_config') }}
                    </SecondaryButton>
                    <SecondaryButton type="button" class="w-full justify-center sm:w-auto" @click="refresh">
                        <RefreshCw class="mr-2 h-4 w-4" /> {{ $t('common.refresh') }}
                    </SecondaryButton>
                </div>
            </div>
        </template>

        <div class="min-h-[60vh] pt-5 pb-16 sm:pt-8">
            <div class="w-full space-y-5 px-4 sm:px-6 lg:px-8">

                <div
                    v-if="statusUnverified || (canManage && !webReady)"
                    class="flex items-start gap-3 rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-xs text-amber-100/90"
                >
                    <TriangleAlert class="mt-0.5 h-4 w-4 flex-shrink-0 text-amber-300" />
                    <div class="space-y-1">
                        <p v-if="statusUnverified" class="font-semibold text-amber-200">{{ $t('hsgq.status_unverified') }}</p>
                        <p v-if="canManage && !webReady">{{ $t('hsgq.web_not_configured') }}</p>
                    </div>
                </div>

                <PortOnuStats :snapshot="snapshot" />

                <div class="kv-table-card">
                    <div class="flex items-center gap-3 border-b border-white/10 px-4 py-4 sm:px-6">
                        <span class="kv-circle-cyan !h-9 !w-9"><Router class="h-5 w-5" /></span>
                        <div>
                            <h3 class="text-base font-semibold text-white">
                                {{ $t('hsgq.onu_on_port', { port: portName }) }}
                                <span v-if="onus.length" class="ml-1 text-sm font-normal tabular-nums text-slate-500">({{ filtered.length }}/{{ onus.length }})</span>
                            </h3>
                            <p v-if="snapshot?.error" class="mt-0.5 text-xs text-rose-400">{{ snapshot.error }}</p>
                            <p v-else class="mt-0.5 text-xs text-slate-500">{{ $t('hsgq.rx_resolution_hint') }}</p>
                        </div>
                    </div>

                    <div v-if="onus.length > 0" class="flex flex-col gap-3 border-b border-white/10 px-4 py-3 sm:flex-row sm:items-center sm:px-6">
                        <div class="relative flex-1">
                            <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" />
                            <input
                                v-model="search"
                                type="text"
                                :placeholder="$t('cdataportonus.search_placeholder')"
                                :aria-label="$t('cdataportonus.search_placeholder')"
                                class="kv-filter-control !pl-9"
                            />
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <select v-model="phaseFilter" :aria-label="$t('portonus.filter_all_phase')" class="min-h-11 min-w-0 flex-1 rounded-lg border border-white/10 bg-slate-900/60 pl-3 pr-8 text-sm text-slate-100 shadow-inner shadow-black/20 focus:border-cyan-500 focus:ring-cyan-500 sm:flex-none">
                                <option value="all">{{ $t('portonus.filter_all_phase') }}</option>
                                <option value="online">{{ $t('portonus.filter_online') }}</option>
                                <option value="offline">{{ $t('portonus.filter_offline') }}</option>
                            </select>
                            <select v-if="odps.length" v-model="odpFilter" :title="$t('portonus.filter_odp')" :aria-label="$t('portonus.filter_odp')" class="min-h-11 min-w-0 flex-1 rounded-lg border border-white/10 bg-slate-900/60 pl-3 pr-8 text-sm text-slate-100 shadow-inner shadow-black/20 focus:border-cyan-500 focus:ring-cyan-500 sm:flex-none">
                                <option value="all">{{ $t('portonus.odp_all') }}</option>
                                <option value="none">{{ $t('portonus.odp_unassigned') }}</option>
                                <option v-for="odp in odps" :key="odp.id" :value="odp.id">{{ odp.name }}</option>
                            </select>
                            <SecondaryButton v-if="hasFilter" type="button" class="w-full justify-center sm:w-auto" @click="clearFilters">{{ $t('common.reset') }}</SecondaryButton>
                        </div>
                    </div>

                    <div v-if="onus.length === 0" class="px-6 py-16 text-center">
                        <p class="text-sm font-semibold text-slate-200">{{ $t('cdataportonus.empty_title') }}</p>
                        <p class="mt-1 text-sm text-slate-500">{{ $t('cdataportonus.empty_hint') }}</p>
                    </div>

                    <div v-else-if="filtered.length === 0" class="px-6 py-14 text-center">
                        <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-slate-800/60 ring-1 ring-slate-500/30">
                            <Search class="h-7 w-7 text-slate-400" />
                        </div>
                        <h3 class="text-sm font-semibold text-slate-200">{{ $t('portonus.nomatch_title') }}</h3>
                        <p class="mt-1 text-sm text-slate-500">{{ $t('portonus.nomatch_sub') }}</p>
                        <SecondaryButton type="button" class="mt-4" @click="clearFilters">{{ $t('portonus.reset_filter') }}</SecondaryButton>
                    </div>

                    <template v-else>
                        <div class="kv-table-desktop">
                            <table class="w-full min-w-[900px] text-xs">
                                <thead>
                                    <tr class="border-b border-white/10 bg-canvas-3/40">
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">ONU</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $t('cdataportonus.col_serial_mac') }}</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $t('portonus.col_acs') }}</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $t('cdataportonus.col_name') }}</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $t('portonus.col_odp') }}</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $t('common.status') }}</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $t('cdataportonus.col_rx') }}</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $t('common.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-white/5">
                                    <tr v-for="o in filtered" :key="o.onu_key" class="transition-colors hover:bg-white/[0.03]" :class="{ 'bg-cyan-500/10': isFocus(o) }">
                                        <td class="px-4 py-3">
                                            <div class="font-mono text-xs text-white">{{ o.interface }}</div>
                                            <div class="mt-0.5 text-xs text-slate-500">{{ typeLine(o) }}</div>
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="font-mono text-xs text-slate-200">{{ o.serial_number || '—' }}</div>
                                            <div v-if="o.firmware" class="mt-0.5 font-mono text-xs text-slate-500">{{ o.firmware }}</div>
                                        </td>
                                        <td class="px-4 py-3">
                                            <AcsPinCell :acs="acsFor(o)" :can-manage="canManage" @pin="openPin(o)" />
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="text-xs text-slate-200">{{ o.name || '—' }}</div>
                                            <div v-if="o.description" class="mt-0.5 text-xs text-slate-500">{{ o.description }}</div>
                                        </td>
                                        <td class="px-4 py-3">
                                            <OnuOdpCell :onu="o" :odps="odps" :current-odp-id="odpIdFor(o)" :olt-id="olt.id" :slot="slot" :port="port" />
                                        </td>
                                        <td class="px-4 py-3">
                                            <span class="inline-flex items-center gap-1.5 text-xs font-semibold" :class="o.online ? 'text-emerald-300' : 'text-rose-300'">
                                                <component :is="o.online ? Wifi : WifiOff" class="h-3.5 w-3.5" />
                                                {{ o.online ? $t('common.online') : (o.phase_state || $t('common.offline')) }}
                                            </span>
                                            <div v-if="o.online && o.uptime" class="mt-0.5 text-xs text-slate-500">{{ o.uptime }}</div>
                                            <div v-else-if="!o.online && o.last_down_cause" class="mt-0.5 text-xs text-slate-500">{{ o.last_down_cause }}</div>
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="font-mono text-xs" :class="rxClass(o.rx_power_dbm)">{{ o.rx_power_label || '—' }}</div>
                                            <div v-if="o.distance_m" class="mt-0.5 text-xs tabular-nums text-slate-500">{{ $t('hsgq.distance_m', { m: o.distance_m }) }}</div>
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="grid w-max grid-flow-col grid-rows-2 gap-1.5">
                                                <IconButton v-if="acsFor(o) && canManage" variant="primary" class="order-last" :title="$t('acswifi.action')" @click="openWifi(o)">
                                                    <Wifi class="h-4 w-4" />
                                                </IconButton>
                                                <IconButton v-if="acsFor(o)" class="order-last" :title="$t('acsclients.action')" @click="openClients(o)">
                                                    <Laptop class="h-4 w-4" />
                                                </IconButton>
                                                <IconButton v-if="canRename" :title="$t('cdataportonus.rename_title')" @click="openRename(o)">
                                                    <Pencil class="h-4 w-4" />
                                                </IconButton>
                                                <IconButton v-if="canReboot" variant="danger" :title="$t('portonus.act_reboot')" @click="rebootOnu(o)">
                                                    <Power class="h-4 w-4" />
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
                                    <span class="inline-flex items-center gap-1 text-xs font-semibold" :class="o.online ? 'text-emerald-300' : 'text-rose-300'">
                                        <component :is="o.online ? Wifi : WifiOff" class="h-3.5 w-3.5" />
                                        {{ o.online ? $t('common.online') : (o.phase_state || $t('common.offline')) }}
                                    </span>
                                </div>
                                <p class="mt-1 text-sm text-slate-200">{{ o.name || '—' }}</p>
                                <p v-if="o.description" class="text-xs text-slate-500">{{ o.description }}</p>
                                <div class="mt-2 flex items-center justify-between gap-2 text-xs">
                                    <span class="font-mono text-slate-400">{{ o.serial_number || '—' }}</span>
                                    <AcsPinCell :acs="acsFor(o)" :can-manage="canManage" @pin="openPin(o)" />
                                    <span class="font-mono" :class="rxClass(o.rx_power_dbm)">{{ o.rx_power_label || '—' }}</span>
                                </div>
                                <div class="mt-2 flex items-center gap-2 text-xs">
                                    <span class="shrink-0 text-slate-500">{{ $t('portonus.col_odp') }}</span>
                                    <OnuOdpCell :onu="o" :odps="odps" :current-odp-id="odpIdFor(o)" :olt-id="olt.id" :slot="slot" :port="port" />
                                </div>
                                <div class="mt-3 flex gap-2">
                                    <IconButton v-if="acsFor(o) && canManage" variant="primary" class="order-last" :title="$t('acswifi.action')" @click="openWifi(o)">
                                        <Wifi class="h-4 w-4" />
                                    </IconButton>
                                    <IconButton v-if="acsFor(o)" class="order-last" :title="$t('acsclients.action')" @click="openClients(o)">
                                        <Laptop class="h-4 w-4" />
                                    </IconButton>
                                    <IconButton v-if="canRename" :title="$t('cdataportonus.rename_title')" @click="openRename(o)">
                                        <Pencil class="h-4 w-4" />
                                    </IconButton>
                                    <IconButton v-if="canReboot" variant="danger" :title="$t('portonus.act_reboot')" @click="rebootOnu(o)">
                                        <Power class="h-4 w-4" />
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

        <WifiSettingsModal
            :show="wifiOnu !== null"
            :olt-id="olt.id"
            :slot="slot"
            :port="port"
            :onu-id="wifiOnu?.onu_id ?? 0"
            :onu-label="wifiOnu ? `${wifiOnu.interface || ''}` : ''"
            @close="wifiOnu = null"
        />

        <ConnectedDevicesModal
            :show="clientsOnu !== null"
            :olt-id="olt.id"
            :slot="slot"
            :port="port"
            :onu-id="clientsOnu?.onu_id ?? 0"
            :onu-label="clientsOnu ? `${clientsOnu.interface || ''} · ${clientsOnu.serial_number || ''}` : ''"
            @close="clientsOnu = null"
        />

        <PinDeviceModal
            :show="pinOnu !== null"
            :olt-id="olt.id"
            :slot="slot"
            :port="port"
            :onu-id="pinOnu?.onu_id ?? 0"
            :onu-label="pinOnu ? `${pinOnu.interface || ''} · ${pinOnu.serial_number || ''}` : ''"
            :acs="pinOnu ? acsFor(pinOnu) : null"
            @close="pinOnu = null"
            @saved="router.reload({ only: ['genieacs_map', 'flash'] })"
        />

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
                    <p v-if="addMap.error" class="mt-2 text-xs text-rose-300">{{ addMap.error }}</p>
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

        <!-- Ubah nama & deskripsi ONU (API web HSGQ) -->
        <Modal :show="rename.open" max-width="md" @close="closeRename">
            <form class="p-6" @submit.prevent="submitRename">
                <h3 class="text-base font-semibold text-white">{{ $t('cdataportonus.rename_modal_title') }}</h3>
                <p class="mt-1 font-mono text-xs text-slate-400">{{ rename.onu?.interface }} · {{ rename.onu?.serial_number }}</p>

                <div class="mt-4 space-y-4">
                    <div>
                        <InputLabel for="hsgq-rename-name" :value="$t('hsgq.rename_name')" />
                        <TextInput id="hsgq-rename-name" v-model="rename.name" class="mt-1 block w-full" maxlength="32" autocomplete="off" required />
                        <InputError class="mt-2" :message="rename.errors.name" />
                    </div>
                    <div>
                        <InputLabel for="hsgq-rename-desc" :value="$t('hsgq.rename_description')" />
                        <TextInput id="hsgq-rename-desc" v-model="rename.description" class="mt-1 block w-full" maxlength="32" autocomplete="off" />
                        <InputError class="mt-2" :message="rename.errors.description" />
                    </div>
                    <p class="text-xs text-slate-500">{{ $t('hsgq.rename_hint') }}</p>
                </div>

                <div class="mt-6 flex justify-end gap-3 border-t border-white/10 pt-4">
                    <SecondaryButton type="button" size="sm" @click="closeRename">{{ $t('common.cancel') }}</SecondaryButton>
                    <PrimaryButton type="submit" size="sm" :disabled="rename.processing">{{ $t('common.save') }}</PrimaryButton>
                </div>
            </form>
        </Modal>
    </AuthenticatedLayout>
</template>
