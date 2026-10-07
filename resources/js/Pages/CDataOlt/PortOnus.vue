<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import IconButton from '@/Components/IconButton.vue';
import OltPortLabel from '@/Components/OltPortLabel.vue';
import PortOnuStats from '@/Components/CDataOlt/PortOnuStats.vue';
import PortSwitcher from '@/Components/CDataOlt/PortSwitcher.vue';
import ConnectedDevicesModal from '@/Components/Genieacs/ConnectedDevicesModal.vue';
import WifiSettingsModal from '@/Components/Genieacs/WifiSettingsModal.vue';
import AcsPinCell from '@/Components/Genieacs/AcsPinCell.vue';
import PinDeviceModal from '@/Components/Genieacs/PinDeviceModal.vue';
import OnuOdpCell from '@/Components/OnuOdpCell.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Modal from '@/Components/Modal.vue';
import { ArrowLeft, Globe, Laptop, Link2, MapPin, MapPinned, Pencil, Power, RefreshCw, Router, Search, ToggleLeft, ToggleRight, Trash2, Wifi, WifiOff, X } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';

const { t } = useI18n({ useScope: 'global' });
const onuLabel = (onu) => `${onu.interface}${onu.name ? ` (${onu.name})` : ''}`;

const props = defineProps({
    olt: { type: Object, required: true },
    slot: { type: Number, required: true },
    port: { type: Number, required: true },
    snapshot: { type: Object, default: null },
    focus: { type: [String, Number], default: null },
    q: { type: String, default: '' },
    pinned_onu_ids: { type: Array, default: () => [] },
    odps: { type: Array, default: () => [] },
    odp_links: { type: Object, default: () => ({}) },
    // Penanda ter-ACS per ONU (ber-key onu_id), dari tabel lokal genieacs_device_map.
    genieacs_map: { type: Object, default: () => ({}) },
    port_labels: { type: Object, default: () => ({}) },
});

const odpIdFor = (onu) => props.odp_links?.[onu.onu_id]?.odp_id ?? null;
const acsFor = (onu) => props.genieacs_map?.[onu.onu_id] ?? null;

// Panel "perangkat terhubung" — hanya untuk ONU yang sudah berpasangan di ACS.
const clientsOnu = ref(null);
const openClients = (onu) => { clientsOnu.value = onu; };

const wifiOnu = ref(null);
const openWifi = (onu) => { wifiOnu.value = onu; };

// Penyematan manual pasangan ONU↔device ACS. Dibuka dari kolom ACS pada baris
// mana pun — termasuk yang sudah berpasangan, karena pasangan yang meleset
// justru yang perlu dibetulkan.
const pinOnu = ref(null);
const openPin = (onu) => { pinOnu.value = onu; };

const page = usePage();

// Label port sisi-NMS (tabel olt_port_labels) — tak pernah ditulis ke OLT.
const canEditPortLabel = computed(
    () => Boolean(page.props.auth?.can?.manage_olt) && Boolean(props.olt.capabilities?.supports_port_label),
);
const portLabel = computed(() => props.port_labels?.[`${props.slot}_${props.port}`] ?? null);
const flash = computed(() => page.props.flash ?? {});
const search = ref(props.q ?? '');

const phaseFilter = ref('all'); // 'all' | 'online' | 'offline'
const odpFilter = ref('all'); // 'all' | 'none' | <odp id>

const onus = computed(() => props.snapshot?.onus ?? []);
const filtered = computed(() => {
    const needle = search.value.trim().toLowerCase();
    return onus.value.filter((o) => {
        if (phaseFilter.value === 'online' && !o.online) return false;
        if (phaseFilter.value === 'offline' && o.online) return false;
        if (odpFilter.value === 'none' && odpIdFor(o) !== null) return false;
        if (odpFilter.value !== 'all' && odpFilter.value !== 'none' && odpIdFor(o) !== Number(odpFilter.value)) return false;
        if (!needle) return true;
        return [o.serial_number, o.name, o.interface, o.mac].some((v) => String(v ?? '').toLowerCase().includes(needle));
    });
});

const hasFilter = computed(() => search.value.trim() !== '' || phaseFilter.value !== 'all' || odpFilter.value !== 'all');
const clearFilters = () => { search.value = ''; phaseFilter.value = 'all'; odpFilter.value = 'all'; };

const rxClass = (dbm) => {
    if (dbm === null || dbm === undefined) return 'text-slate-500';
    if (dbm >= -25) return 'text-emerald-300';
    if (dbm >= -28) return 'text-amber-300';
    return 'text-red-300';
};
const isFocus = (o) => props.focus != null && String(o.onu_id) === String(props.focus);

const refresh = () => router.post(route('cdata-olt.port-onus.refresh', [props.olt.id, props.slot, props.port]), {}, { preserveScroll: true });

const caps = computed(() => props.olt.capabilities ?? {});
const canManage = computed(() => Boolean(page.props.auth?.can?.manage_olt));
// Katalog ACS (WiFi, perangkat terhubung, pin) milik staf Pusat — partner yang
// di-assign OLT ini tetap melihat penanda ter-ACS, tapi tanpa tombolnya.
const canUseAcs = computed(() => Boolean(page.props.auth?.can?.manage_acs));
const canReboot = computed(() => canManage.value && caps.value.supports_reboot);
const canRename = computed(() => canManage.value && caps.value.supports_onu_info_write);
const canToggle = computed(() => canManage.value && caps.value.supports_onu_toggle);
const canDelete = computed(() => canManage.value && caps.value.supports_onu_delete);
const canRemoteAccess = computed(() => canManage.value && caps.value.supports_onu_remote_access);

// ONU dianggap aktif selama admin_state bukan 'disable' ('enable'/'unknown' → aktif).
const isEnabled = (onu) => onu.admin_state !== 'disable';
// Pin peta tersedia untuk semua user terautentikasi (anotasi lokasi, bukan tulis ke OLT).
const hasActions = computed(() => true);

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
    router.post(route('cdata-olt.onu.info', [props.olt.id, props.slot, props.port, onu.onu_id]), { name: renameValue.value }, { preserveScroll: true });
};

const rebootOnu = async (onu) => {
    const ok = await confirm({
        title: t('portonus.act_reboot'),
        message: t('cdataportonus.reboot_msg', { onu: onuLabel(onu) }),
        confirmLabel: 'Reboot',
    });
    if (!ok) return;
    router.post(route('cdata-olt.onu.reboot', [props.olt.id, props.slot, props.port, onu.onu_id]), {}, { preserveScroll: true });
};

const toggleOnu = async (onu) => {
    const active = !isEnabled(onu);
    const ok = await confirm({
        title: active ? t('portonus.act_enable') : t('portonus.act_disable'),
        message: active
            ? t('cdataportonus.toggle_enable_msg', { onu: onuLabel(onu) })
            : t('cdataportonus.toggle_disable_msg', { onu: onuLabel(onu) }),
        confirmLabel: active ? 'Enable' : 'Disable',
        variant: active ? 'primary' : 'danger',
    });
    if (!ok) return;
    router.post(route('cdata-olt.onu.state', [props.olt.id, props.slot, props.port, onu.onu_id]), { active }, { preserveScroll: true });
};

// Remote ONT: buka/tutup akses web ONT dari sisi WAN via `ont security-mgmt` (OMCI push, efek instan).
const remoteOnu = ref(null);
const openRemote = (onu) => { remoteOnu.value = onu; };
const submitRemote = (enable) => {
    const onu = remoteOnu.value;
    remoteOnu.value = null;
    router.post(route('cdata-olt.onu.remote-access', [props.olt.id, props.slot, props.port, onu.onu_id]), { enable }, { preserveScroll: true });
};

const deleteOnu = async (onu) => {
    const ok = await confirm({
        title: t('portonus.act_delete'),
        message: t('cdataportonus.delete_msg', { onu: onuLabel(onu) }),
        confirmLabel: t('common.delete'),
    });
    if (!ok) return;
    router.delete(route('cdata-olt.onu.delete', [props.olt.id, props.slot, props.port, onu.onu_id]), { preserveScroll: true });
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
    <Head :title="`Port ${slot}/${port} · ${olt.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex items-center gap-3">
                    <Link :href="route('cdata-olt.detail', olt.id)" :title="$t('common.back')" :aria-label="$t('common.back')" class="text-slate-400 hover:text-white">
                        <ArrowLeft class="h-5 w-5" />
                    </Link>
                    <div>
                        <h2 class="text-lg font-semibold leading-tight text-white sm:text-xl">
                            {{ olt.name }} · {{ olt.capabilities.pon_label }} 0/{{ slot }}/{{ port }}
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
                        :pon-label="olt.capabilities.pon_label"
                        route-name="cdata-olt.port-onus"
                    />
                    <SecondaryButton type="button" class="w-full justify-center sm:w-auto" @click="refresh">
                        <RefreshCw class="mr-2 h-4 w-4" /> {{ $t('common.refresh') }}
                    </SecondaryButton>
                </div>
            </div>
        </template>

        <div class="min-h-[60vh] pt-5 pb-16 sm:pt-8">
            <div class="w-full space-y-5 px-4 sm:px-6 lg:px-8">

                <PortOnuStats :snapshot="snapshot" />

                <div class="kv-table-card">
                    <div class="flex items-center gap-3 border-b border-white/10 px-4 py-4 sm:px-6">
                        <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-sky-500/15 ring-1 ring-cyan-500/30">
                            <Router class="h-5 w-5 text-cyan-400" />
                        </div>
                        <div>
                            <h3 class="text-base font-semibold text-white">
                                {{ $t('cdataportonus.onu_on_port', { slot, port }) }}
                                <span v-if="onus.length" class="ml-1 text-sm font-normal tabular-nums text-slate-500">({{ filtered.length }}/{{ onus.length }})</span>
                            </h3>
                            <p v-if="snapshot?.error" class="mt-0.5 text-xs text-rose-400">{{ snapshot.error }}</p>
                        </div>
                    </div>

                    <!-- Cari & filter — tata letak sama dengan halaman ONU per port ZTE -->
                    <div v-if="onus.length > 0" class="flex flex-col gap-3 border-b border-white/10 px-4 py-3 sm:flex-row sm:items-center sm:px-6">
                        <div class="relative flex-1">
                            <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" />
                            <input
                                v-model="search"
                                type="text"
                                :placeholder="$t('cdataportonus.search_placeholder')"
                                :aria-label="$t('cdataportonus.search_placeholder')"
                                class="kv-filter-control !pl-9 !pr-9"
                            />
                            <button
                                v-if="search"
                                type="button"
                                class="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-500 hover:text-white"
                                :title="$t('common.clear')"
                                :aria-label="$t('common.clear')"
                                @click="search = ''"
                            >
                                <X class="h-4 w-4" />
                            </button>
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
                            <table class="w-full min-w-[820px] text-xs">
                                <thead>
                                    <tr class="border-b border-white/10 bg-canvas-3/40">
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">ONU</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $t('cdataportonus.col_serial_mac') }}</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $t('portonus.col_acs') }}</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $t('cdataportonus.col_name') }}</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $t('portonus.col_odp') }}</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $t('common.status') }}</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $t('cdataportonus.col_rx') }}</th>
                                        <th v-if="hasActions" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $t('common.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-white/5">
                                    <tr v-for="o in filtered" :key="o.onu_key" class="transition-colors hover:bg-white/[0.03]" :class="{ 'bg-cyan-500/10': isFocus(o) }">
                                        <td class="px-4 py-3">
                                            <div class="font-mono text-xs text-white">{{ o.interface }}</div>
                                            <div class="mt-0.5 text-xs text-slate-500">{{ o.type_name || o.vendor_id || '—' }}</div>
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="font-mono text-xs text-slate-200">{{ o.serial_number || o.mac || '—' }}</div>
                                            <div v-if="o.mac && o.serial_number" class="mt-0.5 font-mono text-xs text-slate-500">{{ o.mac }}</div>
                                        </td>
                                        <td class="px-4 py-3">
                                            <AcsPinCell :acs="acsFor(o)" :can-manage="canUseAcs" @pin="openPin(o)" />
                                        </td>
                                        <td class="px-4 py-3 text-xs text-slate-200">{{ o.name || '—' }}</td>
                                        <td class="px-4 py-3">
                                            <OnuOdpCell
                                                :onu="o"
                                                :odps="odps"
                                                :current-odp-id="odpIdFor(o)"
                                                :olt-id="olt.id"
                                                :slot="slot"
                                                :port="port"
                                            />
                                        </td>
                                        <td class="px-4 py-3">
                                            <span class="inline-flex items-center gap-1.5 text-xs font-semibold" :class="o.online ? 'text-emerald-300' : 'text-red-300'">
                                                <component :is="o.online ? Wifi : WifiOff" class="h-3.5 w-3.5" />
                                                {{ o.phase_state || (o.online ? $t('common.online') : $t('common.offline')) }}
                                            </span>
                                            <div v-if="o.last_down_cause" class="mt-0.5 text-xs text-slate-500">{{ o.last_down_cause }}</div>
                                        </td>
                                        <td class="px-4 py-3 font-mono text-xs" :class="rxClass(o.rx_power_dbm)">
                                            {{ o.rx_power_label || (o.rx_power_dbm != null ? o.rx_power_dbm + ' dBm' : '—') }}
                                        </td>
                                        <td v-if="hasActions" class="px-4 py-3">
                                            <div class="grid w-max grid-flow-col grid-rows-2 gap-1.5">
                                                <IconButton v-if="acsFor(o) && canUseAcs" variant="primary" class="order-last" :title="$t('acswifi.action')" @click="openWifi(o)">
                                                    <Wifi class="h-4 w-4" />
                                                </IconButton>
                                                <IconButton v-if="acsFor(o) && canUseAcs" class="order-last" :title="$t('acsclients.action')" @click="openClients(o)">
                                                    <Laptop class="h-4 w-4" />
                                                </IconButton>
                                                <IconButton v-if="canRename" :title="$t('cdataportonus.rename_title')" @click="openRename(o)">
                                                    <Pencil class="h-4 w-4" />
                                                </IconButton>
                                                <IconButton v-if="canToggle" :variant="isEnabled(o) ? 'warning' : 'success'" :title="isEnabled(o) ? $t('portonus.act_disable') : $t('portonus.act_enable')" @click="toggleOnu(o)">
                                                    <ToggleRight v-if="isEnabled(o)" class="h-4 w-4" />
                                                    <ToggleLeft v-else class="h-4 w-4" />
                                                </IconButton>
                                                <IconButton v-if="canRemoteAccess" :variant="o.remote_web ? 'success' : 'primary'" :title="$t('cdataportonus.remote_title')" @click="openRemote(o)">
                                                    <Globe class="h-4 w-4" />
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
                                    <span class="font-mono text-slate-400">{{ o.serial_number || o.mac || '—' }}</span>
                                    <AcsPinCell :acs="acsFor(o)" :can-manage="canUseAcs" @pin="openPin(o)" />
                                    <span class="font-mono" :class="rxClass(o.rx_power_dbm)">{{ o.rx_power_label || '—' }}</span>
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
                                <div v-if="hasActions" class="mt-3 flex gap-2">
                                    <IconButton v-if="acsFor(o) && canUseAcs" variant="primary" class="order-last" :title="$t('acswifi.action')" @click="openWifi(o)">
                                        <Wifi class="h-4 w-4" />
                                    </IconButton>
                                    <IconButton v-if="acsFor(o) && canUseAcs" class="order-last" :title="$t('acsclients.action')" @click="openClients(o)">
                                        <Laptop class="h-4 w-4" />
                                    </IconButton>
                                    <IconButton v-if="canRename" :title="$t('cdataportonus.rename_title')" @click="openRename(o)">
                                        <Pencil class="h-4 w-4" />
                                    </IconButton>
                                    <IconButton v-if="canToggle" :variant="isEnabled(o) ? 'warning' : 'success'" :title="isEnabled(o) ? $t('portonus.act_disable') : $t('portonus.act_enable')" @click="toggleOnu(o)">
                                        <ToggleRight v-if="isEnabled(o)" class="h-4 w-4" />
                                        <ToggleLeft v-else class="h-4 w-4" />
                                    </IconButton>
                                    <IconButton v-if="canRemoteAccess" :variant="o.remote_web ? 'success' : 'primary'" :title="$t('cdataportonus.remote_title')" @click="openRemote(o)">
                                        <Globe class="h-4 w-4" />
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
            :onu-label="clientsOnu ? `${clientsOnu.interface || ''} · ${clientsOnu.serial_number || clientsOnu.mac || ''}` : ''"
            @close="clientsOnu = null"
        />

        <PinDeviceModal
            :show="pinOnu !== null"
            :olt-id="olt.id"
            :slot="slot"
            :port="port"
            :onu-id="pinOnu?.onu_id ?? 0"
            :onu-label="pinOnu ? `${pinOnu.interface || ''} · ${pinOnu.serial_number || pinOnu.mac || ''}` : ''"
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

        <!-- Modal Remote ONT (buka/tutup akses web ONT via security-mgmt) -->
        <Modal :show="remoteOnu !== null" max-width="md" @close="remoteOnu = null">
            <div class="p-6">
                <div class="flex items-center gap-2">
                    <Globe class="h-5 w-5 text-cyan-400" />
                    <h3 class="text-base font-semibold text-white">{{ $t('cdataportonus.remote_modal_title') }}</h3>
                </div>
                <p v-if="remoteOnu" class="mt-1 font-mono text-xs text-slate-400">{{ onuLabel(remoteOnu) }}</p>
                <p class="mt-4 text-sm text-slate-300">{{ $t('cdataportonus.remote_modal_desc') }}</p>
                <p class="mt-2 text-xs text-slate-500">{{ $t('cdataportonus.remote_modal_hint') }}</p>
                <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <SecondaryButton type="button" @click="remoteOnu = null">{{ $t('common.cancel') }}</SecondaryButton>
                    <SecondaryButton type="button" class="justify-center !border-amber-500/40 !text-amber-300 hover:!bg-amber-500/10" @click="submitRemote(false)">
                        {{ $t('cdataportonus.remote_disable') }}
                    </SecondaryButton>
                    <PrimaryButton type="button" class="justify-center" @click="submitRemote(true)">
                        <Globe class="mr-2 h-4 w-4" /> {{ $t('cdataportonus.remote_enable') }}
                    </PrimaryButton>
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
                    <TextInput id="rename" v-model="renameValue" class="mt-1 block w-full" maxlength="128" autocomplete="off" />
                    <div class="mt-5 flex justify-end gap-3">
                        <SecondaryButton type="button" @click="renameOnu = null">{{ $t('common.cancel') }}</SecondaryButton>
                        <PrimaryButton type="submit">{{ $t('common.save') }}</PrimaryButton>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
