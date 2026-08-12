<script setup>
/**
 * Halaman pengelolaan ODP (Optical Distribution Point).
 *
 * Pusat kelola ODP di luar peta: daftar + filter OLT/port, CRUD, dan modal
 * "Kelola ONU" untuk menambah/melepas anggota ODP. Aksi tulis memakai endpoint
 * yang sudah ada (`map.odps.*` dan `onu-odp.assign`) — tak ada rute tulis baru.
 */
import ConfirmModal from '@/Components/ConfirmModal.vue';
import IconButton from '@/Components/IconButton.vue';
import OdpColorModal from '@/Components/Map/OdpColorModal.vue';
import OdpPhotoField from '@/Components/Map/OdpPhotoField.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import ClientPagination from '@/Components/Shell/ClientPagination.vue';
import FilterCard from '@/Components/Shell/FilterCard.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { usePagination } from '@/Composables/usePagination';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { odpColor } from '@/lib/odpColors';
import { Camera, MapPin, Palette, Pencil, Plus, Search, Trash2, Waypoints, Wifi, WifiOff, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n({ useScope: 'global' });

const props = defineProps({
    odps: { type: Array, default: () => [] },
    olts: { type: Array, default: () => [] },
    // Palet warna pin ODP (sumber: App\Support\OdpColors di server).
    odp_color_palette: { type: Array, default: () => [] },
});

const page = usePage();
const flash = computed(() => page.props.flash ?? {});
const { confirmState, confirm, handleConfirm, handleCancel } = useConfirm();

// --- filter (sisi klien; jumlah ODP kecil) ---
const search = ref('');
const oltFilter = ref('all');
const portFilter = ref('all');

const portKey = (odp) => (odp.slot === null || odp.port === null ? 'none' : `${odp.slot}/${odp.port}`);

const oltScoped = computed(() =>
    oltFilter.value === 'all'
        ? props.odps
        : props.odps.filter((o) => o.snmp_olt_id === Number(oltFilter.value)),
);

// Opsi port diturunkan dari ODP yang ada (per OLT terpilih) — bukan daftar port OLT penuh.
const portOptions = computed(() => {
    const set = new Map();
    for (const odp of oltScoped.value) {
        if (odp.slot === null || odp.port === null) continue;
        set.set(`${odp.slot}/${odp.port}`, { slot: odp.slot, port: odp.port });
    }
    return [...set.values()].sort((a, b) => a.slot - b.slot || a.port - b.port);
});

const onOltChange = () => {
    portFilter.value = 'all';
};

const filtered = computed(() => {
    const term = search.value.trim().toLowerCase();
    return oltScoped.value.filter((odp) => {
        if (portFilter.value !== 'all' && portKey(odp) !== portFilter.value) return false;
        if (!term) return true;
        return [odp.name, odp.olt_name, odp.notes]
            .filter(Boolean)
            .join(' ')
            .toLowerCase()
            .includes(term);
    });
});

const hasFilter = computed(
    () => search.value.trim() !== '' || oltFilter.value !== 'all' || portFilter.value !== 'all',
);

const clearFilters = () => {
    search.value = '';
    oltFilter.value = 'all';
    portFilter.value = 'all';
};

const { page: odpPage, pageSize, total: pageTotal, pageCount, pageItems: pagedOdps, rangeStart, rangeEnd } =
    usePagination(filtered);

const portLabel = (odp) => (odp.slot === null || odp.port === null ? '—' : `${odp.slot}/${odp.port}`);

// --- warna pin ODP (modal yang sama dipakai kartu detail ODP di peta) ---
// Simpan id lalu cari ulang dari prop: `odps` diganti utuh oleh partial reload sesudah
// warna disimpan, jadi menyimpan objeknya langsung akan basi.
const colorOdpId = ref(null);
const colorOdp = computed(() => props.odps.find((o) => o.id === colorOdpId.value) ?? null);

// --- foto ODP (komponen yang sama dipakai kartu detail ODP di peta) ---
const photoOdpId = ref(null);
const photoOdp = computed(() => props.odps.find((o) => o.id === photoOdpId.value) ?? null);

// Jumlah ODP se-PON-port — label saklar "terapkan ke satu port" di modal warna.
const colorPortCount = computed(() => {
    const odp = colorOdp.value;
    if (!odp || odp.slot === null || odp.port === null) return 1;

    return props.odps.filter(
        (o) => o.snmp_olt_id === odp.snmp_olt_id && o.slot === odp.slot && o.port === odp.port,
    ).length;
});

// --- tambah / edit (satu modal, satu form) ---
const formOpen = ref(false);
const editing = ref(null);
const form = useForm({
    snmp_olt_id: '',
    name: '',
    slot: '',
    port: '',
    latitude: '',
    longitude: '',
    notes: '',
});

// Tempel link Google Maps → koordinat (endpoint yang sama dipakai tombol "Add Map" di Port ONU).
const linkUrl = ref('');
const linkBusy = ref(false);
const linkError = ref('');

const resolveLink = async () => {
    if (!linkUrl.value.trim() || linkBusy.value) return;
    linkBusy.value = true;
    linkError.value = '';
    try {
        const { data } = await window.axios.post(route('map.resolve-link'), { url: linkUrl.value.trim() });
        if (!data.ok) {
            linkError.value = data.error ?? t('odp.coord_not_found');
            return;
        }
        form.latitude = data.latitude;
        form.longitude = data.longitude;
    } catch (e) {
        linkError.value = e?.response?.data?.error ?? t('odp.gmaps_failed');
    } finally {
        linkBusy.value = false;
    }
};

const resetLink = () => {
    linkUrl.value = '';
    linkError.value = '';
    linkBusy.value = false;
};

const openCreate = () => {
    editing.value = null;
    form.reset();
    form.clearErrors();
    form.snmp_olt_id = oltFilter.value !== 'all' ? Number(oltFilter.value) : (props.olts[0]?.id ?? '');
    resetLink();
    formOpen.value = true;
};

const openEdit = (odp) => {
    editing.value = odp;
    form.clearErrors();
    form.snmp_olt_id = odp.snmp_olt_id;
    form.name = odp.name;
    form.slot = odp.slot ?? '';
    form.port = odp.port ?? '';
    form.latitude = odp.latitude;
    form.longitude = odp.longitude;
    form.notes = odp.notes ?? '';
    resetLink();
    formOpen.value = true;
};

const closeForm = () => {
    formOpen.value = false;
    editing.value = null;
    form.reset();
    form.clearErrors();
    resetLink();
};

const submitForm = () => {
    const options = {
        preserveScroll: true,
        onSuccess: () => closeForm(),
    };
    // Kosong = "tanpa port"; kirim null supaya tervalidasi sebagai nullable, bukan string kosong.
    form.transform((data) => ({
        ...data,
        slot: data.slot === '' ? null : Number(data.slot),
        port: data.port === '' ? null : Number(data.port),
    }));

    if (editing.value) {
        form.put(route('map.odps.update', editing.value.id), options);
    } else {
        form.post(route('map.odps.store'), options);
    }
};

const deleteOdp = async (odp) => {
    const ok = await confirm({
        title: t('odp.delete_title'),
        message: t('odp.delete_msg', { name: odp.name, count: odp.onu_count }),
        confirmLabel: t('common.delete'),
        variant: 'danger',
    });
    if (!ok) return;
    router.delete(route('map.odps.destroy', odp.id), { preserveScroll: true });
};

// --- kelola ONU ---
const manageOpen = ref(false);
const manageOdp = ref(null);
const manageLoading = ref(false);
const manageError = ref('');
const connected = ref([]);
const available = ref([]);
const candidateSearch = ref('');
const assigning = ref(false);

const candidates = computed(() => {
    const term = candidateSearch.value.trim().toLowerCase();
    if (!term) return available.value;
    return available.value.filter((o) =>
        [o.interface, o.serial_number, o.name].filter(Boolean).join(' ').toLowerCase().includes(term),
    );
});

const loadOnus = async () => {
    if (!manageOdp.value) return;
    manageLoading.value = true;
    manageError.value = '';
    try {
        const { data } = await window.axios.get(route('odp.onus', manageOdp.value.id));
        connected.value = data.connected ?? [];
        available.value = data.available ?? [];
    } catch (e) {
        manageError.value = e?.response?.data?.message ?? t('odp.load_failed');
    } finally {
        manageLoading.value = false;
    }
};

const openManage = (odp) => {
    manageOdp.value = odp;
    connected.value = [];
    available.value = [];
    candidateSearch.value = '';
    manageOpen.value = true;
    loadOnus();
};

const closeManage = () => {
    manageOpen.value = false;
    manageOdp.value = null;
};

// Pasang/lepas memakai endpoint bersama `onu-odp.assign` (odp_id null = lepas).
const assignOnu = (onu, odpId) => {
    if (assigning.value) return;
    assigning.value = true;
    router.post(
        route('onu-odp.assign'),
        {
            snmp_olt_id: manageOdp.value.snmp_olt_id,
            slot: onu.slot,
            port: onu.port,
            onu_id: onu.onu_id,
            serial_number: onu.serial_number ?? null,
            odp_id: odpId,
        },
        {
            preserveScroll: true,
            preserveState: true,
            // Muat ulang daftar modal + prop odps supaya kolom "Jumlah ONU" ikut segar.
            onSuccess: () => {
                loadOnus();
                router.reload({ only: ['odps'] });
            },
            onFinish: () => (assigning.value = false),
        },
    );
};

const mapHref = (odp) =>
    `${route('map.index')}?focus_odp=${odp.id}`;
</script>

<template>
    <Head :title="$t('odp.title')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-lg font-semibold leading-tight text-white sm:text-xl">{{ $t('odp.title') }}</h2>
                    <p class="mt-0.5 text-xs text-slate-400">{{ $t('odp.subtitle', { count: odps.length }) }}</p>
                </div>
                <PrimaryButton class="w-full sm:w-auto" :disabled="!olts.length" @click="openCreate">
                    <Plus class="mr-2 h-4 w-4" />
                    {{ $t('odp.add') }}
                </PrimaryButton>
            </div>
        </template>

        <div class="min-h-[60vh] pt-5 pb-16 sm:pt-8">
            <div class="w-full space-y-4 px-4 sm:px-6 lg:px-8">
                <div v-if="flash.success" class="rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200">
                    {{ flash.success }}
                </div>
                <div v-if="flash.error" class="rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-200">
                    {{ flash.error }}
                </div>

                <FilterCard :title="$t('odp.filter_title')" :subtitle="$t('odp.filter_subtitle')" :icon="Search">
                    <template #actions>
                        <button v-if="hasFilter" type="button" class="kv-filter-reset" @click="clearFilters">
                            <X class="h-4 w-4" /> {{ $t('common.reset') }}
                        </button>
                    </template>
                    <div class="flex flex-wrap items-center gap-2">
                        <div class="relative w-full lg:flex-1 lg:min-w-[16rem]">
                            <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" />
                            <input
                                v-model="search"
                                type="text"
                                :placeholder="$t('odp.search_placeholder')"
                                class="kv-filter-control !pl-9 !pr-9"
                            />
                            <button
                                v-if="search"
                                type="button"
                                class="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-500 hover:text-slate-300"
                                @click="search = ''"
                            >
                                <X class="h-4 w-4" />
                            </button>
                        </div>
                        <select v-model="oltFilter" class="kv-filter-control w-full sm:w-auto" @change="onOltChange">
                            <option value="all">{{ $t('odp.all_olts') }}</option>
                            <option v-for="olt in olts" :key="olt.id" :value="olt.id">{{ olt.name }}</option>
                        </select>
                        <select v-model="portFilter" class="kv-filter-control w-full sm:w-auto">
                            <option value="all">{{ $t('odp.all_ports') }}</option>
                            <option value="none">{{ $t('odp.no_port') }}</option>
                            <option v-for="p in portOptions" :key="`${p.slot}/${p.port}`" :value="`${p.slot}/${p.port}`">
                                {{ p.slot }}/{{ p.port }}
                            </option>
                        </select>
                    </div>
                </FilterCard>

                <div class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
                    <div class="flex items-center gap-3 border-b border-white/10 px-4 py-4 sm:px-6">
                        <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-sky-500/15 ring-1 ring-cyan-500/30">
                            <Waypoints class="h-5 w-5 text-cyan-400" />
                        </div>
                        <div>
                            <h3 class="text-base font-semibold text-white">{{ $t('odp.list_title') }}</h3>
                            <p class="mt-0.5 text-xs text-slate-500">{{ $t('odp.list_sub', { count: filtered.length }) }}</p>
                        </div>
                    </div>

                    <div v-if="!odps.length" class="px-6 py-12 text-center">
                        <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-slate-800/60 ring-1 ring-slate-500/30">
                            <Waypoints class="h-7 w-7 text-slate-400" />
                        </div>
                        <h3 class="text-sm font-semibold text-white">{{ $t('odp.empty_title') }}</h3>
                        <p class="mt-1 text-sm text-slate-500">{{ $t('odp.empty_sub') }}</p>
                        <div class="mt-5">
                            <PrimaryButton :disabled="!olts.length" @click="openCreate">
                                <Plus class="mr-2 h-4 w-4" />
                                {{ $t('odp.add') }}
                            </PrimaryButton>
                        </div>
                    </div>

                    <div v-else-if="!filtered.length" class="px-6 py-12 text-center">
                        <p class="text-sm font-semibold text-slate-200">{{ $t('odp.no_match_title') }}</p>
                        <p class="mt-1 text-sm text-slate-500">{{ $t('odp.no_match_sub') }}</p>
                        <button type="button" class="mt-4 rounded-lg border border-white/10 px-4 py-2 text-sm text-slate-300 transition-colors hover:bg-white/5" @click="clearFilters">
                            {{ $t('common.reset') }}
                        </button>
                    </div>

                    <template v-else>
                        <!-- Mobile -->
                        <div class="kv-mobile-list">
                            <article v-for="odp in pagedOdps" :key="odp.id" class="kv-mobile-card">
                                <div class="kv-mobile-card-header">
                                    <div class="min-w-0">
                                        <h4 class="kv-mobile-card-title flex items-center gap-1.5">
                                            <span class="inline-block h-2.5 w-2.5 shrink-0 rounded-sm ring-1 ring-white/40" :style="{ background: odpColor(odp) }"></span>
                                            {{ odp.name }}
                                        </h4>
                                        <p class="kv-mobile-card-subtitle">{{ odp.olt_name }} · {{ $t('odp.col_port') }} {{ portLabel(odp) }}</p>
                                    </div>
                                    <div class="flex flex-shrink-0 gap-2">
                                        <IconButton :title="$t('odp.manage_onus')" @click="openManage(odp)">
                                            <Wifi class="h-4 w-4" />
                                        </IconButton>
                                        <IconButton :title="$t('map.odp_photo_title')" @click="photoOdpId = odp.id">
                                            <Camera class="h-4 w-4" :class="odp.photo_url ? 'text-cyan-300' : ''" />
                                        </IconButton>
                                        <IconButton :title="$t('map.odp_color')" @click="colorOdpId = odp.id">
                                            <Palette class="h-4 w-4" :style="{ color: odpColor(odp) }" />
                                        </IconButton>
                                        <IconButton :title="$t('common.edit')" @click="openEdit(odp)">
                                            <Pencil class="h-4 w-4" />
                                        </IconButton>
                                        <IconButton variant="danger" :title="$t('common.delete')" @click="deleteOdp(odp)">
                                            <Trash2 class="h-4 w-4" />
                                        </IconButton>
                                    </div>
                                </div>
                                <div class="kv-mobile-field">
                                    <span>{{ $t('odp.col_onu_count') }}</span>
                                    <span class="tabular-nums text-slate-200">{{ odp.onu_count }}</span>
                                </div>
                                <div class="kv-mobile-field">
                                    <span>{{ $t('odp.col_coords') }}</span>
                                    <a :href="mapHref(odp)" class="text-cyan-300 hover:underline">
                                        {{ Number(odp.latitude).toFixed(5) }}, {{ Number(odp.longitude).toFixed(5) }}
                                    </a>
                                </div>
                            </article>
                        </div>

                        <!-- Desktop -->
                        <div class="kv-table-desktop">
                            <table class="w-full min-w-[820px]">
                                <thead>
                                    <tr class="border-b border-white/10 bg-slate-950/40">
                                        <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $t('odp.col_name') }}</th>
                                        <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $t('odp.col_olt') }}</th>
                                        <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $t('odp.col_port') }}</th>
                                        <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $t('odp.col_onu_count') }}</th>
                                        <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $t('odp.col_coords') }}</th>
                                        <th class="px-4 py-3.5 text-center text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $t('common.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-white/5">
                                    <tr v-for="odp in pagedOdps" :key="odp.id" class="transition-colors duration-150 hover:bg-white/[0.03]">
                                        <td class="px-4 py-4 font-medium text-white">
                                            <span class="flex items-center gap-2">
                                                <!-- Titik warna = warna pin ODP ini di peta. -->
                                                <span class="inline-block h-2.5 w-2.5 shrink-0 rounded-sm ring-1 ring-white/40" :style="{ background: odpColor(odp) }"></span>
                                                <img
                                                    v-if="odp.photo_url"
                                                    :src="odp.photo_url"
                                                    :alt="odp.name"
                                                    class="h-8 w-8 shrink-0 cursor-pointer rounded-md object-cover ring-1 ring-white/15"
                                                    loading="lazy"
                                                    @click="photoOdpId = odp.id"
                                                />
                                                {{ odp.name }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-4 text-sm text-slate-300">{{ odp.olt_name }}</td>
                                        <td class="px-4 py-4 text-sm tabular-nums text-slate-300">{{ portLabel(odp) }}</td>
                                        <td class="px-4 py-4 text-sm tabular-nums text-slate-300">{{ odp.onu_count }}</td>
                                        <td class="px-4 py-4 text-xs">
                                            <a :href="mapHref(odp)" class="text-cyan-300 hover:underline">
                                                {{ Number(odp.latitude).toFixed(5) }}, {{ Number(odp.longitude).toFixed(5) }}
                                            </a>
                                        </td>
                                        <td class="px-4 py-4">
                                            <div class="flex justify-center gap-1.5">
                                                <IconButton :title="$t('odp.manage_onus')" @click="openManage(odp)">
                                                    <Wifi class="h-4 w-4" />
                                                </IconButton>
                                                <IconButton :title="$t('map.odp_photo_title')" @click="photoOdpId = odp.id">
                                                    <Camera class="h-4 w-4" :class="odp.photo_url ? 'text-cyan-300' : ''" />
                                                </IconButton>
                                                <IconButton :title="$t('map.odp_color')" @click="colorOdpId = odp.id">
                                                    <Palette class="h-4 w-4" :style="{ color: odpColor(odp) }" />
                                                </IconButton>
                                                <IconButton :title="$t('common.edit')" @click="openEdit(odp)">
                                                    <Pencil class="h-4 w-4" />
                                                </IconButton>
                                                <IconButton variant="danger" :title="$t('common.delete')" @click="deleteOdp(odp)">
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
                            v-model:page="odpPage"
                            v-model:page-size="pageSize"
                            :page-count="pageCount"
                            :total="pageTotal"
                            :range-start="rangeStart"
                            :range-end="rangeEnd"
                            label="ODP"
                        />
                    </template>
                </div>
            </div>
        </div>

        <!-- Modal tambah / edit -->
        <Modal :show="formOpen" max-width="lg" @close="closeForm">
            <form class="p-6" @submit.prevent="submitForm">
                <h3 class="text-base font-semibold text-white">{{ editing ? $t('odp.edit_title') : $t('odp.add_title') }}</h3>
                <p class="mt-1 text-sm text-slate-500">{{ editing ? $t('odp.edit_sub') : $t('odp.add_sub') }}</p>

                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <InputLabel for="odp_name" :value="$t('odp.col_name')" />
                        <TextInput id="odp_name" v-model="form.name" type="text" class="mt-1 block w-full" maxlength="128" autofocus />
                        <InputError class="mt-1" :message="form.errors.name" />
                    </div>
                    <div v-if="!editing" class="sm:col-span-2">
                        <InputLabel for="odp_olt" :value="$t('odp.col_olt')" />
                        <select id="odp_olt" v-model="form.snmp_olt_id" class="kv-input mt-1 block min-h-11 w-full">
                            <option v-for="olt in olts" :key="olt.id" :value="olt.id">{{ olt.name }}</option>
                        </select>
                        <InputError class="mt-1" :message="form.errors.snmp_olt_id" />
                    </div>
                    <div>
                        <InputLabel for="odp_slot" :value="$t('odp.slot_label')" />
                        <TextInput id="odp_slot" v-model="form.slot" type="number" min="0" class="mt-1 block w-full" />
                        <InputError class="mt-1" :message="form.errors.slot" />
                    </div>
                    <div>
                        <InputLabel for="odp_port" :value="$t('odp.port_label')" />
                        <TextInput id="odp_port" v-model="form.port" type="number" min="0" class="mt-1 block w-full" />
                        <InputError class="mt-1" :message="form.errors.port" />
                    </div>
                    <p class="text-xs text-slate-500 sm:col-span-2">{{ $t('odp.port_hint') }}</p>

                    <div class="sm:col-span-2">
                        <InputLabel :value="$t('odp.gmaps_label')" />
                        <div class="mt-1 flex gap-2">
                            <TextInput v-model="linkUrl" type="url" class="block w-full" :placeholder="$t('odp.gmaps_placeholder')" />
                            <SecondaryButton type="button" :disabled="linkBusy || !linkUrl.trim()" @click="resolveLink">
                                {{ linkBusy ? $t('common.loading') : $t('odp.gmaps_apply') }}
                            </SecondaryButton>
                        </div>
                        <p v-if="linkError" class="mt-1 text-xs text-red-300">{{ linkError }}</p>
                        <p v-else class="mt-1 text-xs text-slate-500">{{ $t('odp.gmaps_hint') }}</p>
                    </div>
                    <div>
                        <InputLabel for="odp_lat" :value="$t('odp.latitude')" />
                        <TextInput id="odp_lat" v-model="form.latitude" type="text" class="mt-1 block w-full font-mono" />
                        <InputError class="mt-1" :message="form.errors.latitude" />
                    </div>
                    <div>
                        <InputLabel for="odp_lng" :value="$t('odp.longitude')" />
                        <TextInput id="odp_lng" v-model="form.longitude" type="text" class="mt-1 block w-full font-mono" />
                        <InputError class="mt-1" :message="form.errors.longitude" />
                    </div>
                    <div class="sm:col-span-2">
                        <InputLabel for="odp_notes" :value="$t('odp.notes_label')" />
                        <textarea id="odp_notes" v-model="form.notes" rows="2" class="kv-input mt-1 block w-full"></textarea>
                        <InputError class="mt-1" :message="form.errors.notes" />
                    </div>
                </div>

                <div class="mt-6 grid gap-2 sm:flex sm:justify-end">
                    <SecondaryButton type="button" @click="closeForm">{{ $t('common.cancel') }}</SecondaryButton>
                    <PrimaryButton type="submit" :disabled="form.processing">
                        {{ editing ? $t('common.save') : $t('odp.create') }}
                    </PrimaryButton>
                </div>
            </form>
        </Modal>

        <!-- Modal kelola ONU -->
        <Modal :show="manageOpen" max-width="4xl" @close="closeManage">
            <div v-if="manageOdp" class="p-6">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 class="text-base font-semibold text-white">{{ $t('odp.manage_title', { name: manageOdp.name }) }}</h3>
                        <p class="mt-1 text-sm text-slate-500">
                            {{ manageOdp.olt_name }} · {{ $t('odp.col_port') }} {{ portLabel(manageOdp) }}
                        </p>
                    </div>
                    <IconButton :title="$t('common.close')" @click="closeManage">
                        <X class="h-4 w-4" />
                    </IconButton>
                </div>

                <p v-if="manageError" class="mt-4 rounded-lg border border-red-500/30 bg-red-500/10 px-3 py-2 text-sm text-red-200">
                    {{ manageError }}
                </p>
                <p v-else-if="manageLoading" class="mt-4 text-sm text-slate-400">{{ $t('common.loading') }}</p>

                <div v-else class="mt-5 grid gap-5 md:grid-cols-2">
                    <!-- ONU di ODP ini -->
                    <div>
                        <h4 class="text-sm font-semibold text-slate-200">
                            {{ $t('odp.connected_title', { count: connected.length }) }}
                        </h4>
                        <p v-if="!connected.length" class="mt-2 text-sm text-slate-500">{{ $t('odp.connected_empty') }}</p>
                        <ul v-else class="mt-2 max-h-[22rem] space-y-1.5 overflow-y-auto pr-1">
                            <li
                                v-for="onu in connected"
                                :key="`c-${onu.slot}-${onu.port}-${onu.onu_id}`"
                                class="flex items-center gap-2 rounded-lg border border-white/10 bg-slate-900/60 px-3 py-2"
                            >
                                <component :is="onu.online ? Wifi : WifiOff" class="h-4 w-4 flex-shrink-0" :class="onu.online ? 'text-emerald-400' : 'text-red-400'" />
                                <div class="min-w-0 flex-1">
                                    <p
                                        class="break-words text-sm leading-snug text-slate-100"
                                        :title="onu.name || $t('odp.onu_unnamed')"
                                    >
                                        {{ onu.name || $t('odp.onu_unnamed') }}
                                    </p>
                                    <p class="truncate text-[11px] text-slate-500" :title="`${onu.interface} · ${onu.serial_number}`">
                                        {{ onu.interface }} · {{ onu.serial_number }}
                                    </p>
                                </div>
                                <IconButton class="flex-shrink-0" variant="danger" :title="$t('odp.remove_onu')" :disabled="assigning" @click="assignOnu(onu, null)">
                                    <X class="h-4 w-4" />
                                </IconButton>
                            </li>
                        </ul>
                    </div>

                    <!-- Kandidat ONU -->
                    <div>
                        <h4 class="text-sm font-semibold text-slate-200">{{ $t('odp.add_onu_title') }}</h4>
                        <div class="relative mt-2">
                            <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" />
                            <input
                                v-model="candidateSearch"
                                type="text"
                                :placeholder="$t('odp.add_onu_search')"
                                class="kv-input block w-full !pl-9"
                            />
                        </div>
                        <p v-if="!candidates.length" class="mt-2 text-sm text-slate-500">{{ $t('odp.add_onu_empty') }}</p>
                        <ul v-else class="mt-2 max-h-[19rem] space-y-1.5 overflow-y-auto pr-1">
                            <li
                                v-for="onu in candidates"
                                :key="`a-${onu.slot}-${onu.port}-${onu.onu_id}`"
                                class="flex items-center gap-2 rounded-lg border border-white/10 bg-slate-900/60 px-3 py-2"
                            >
                                <component :is="onu.online ? Wifi : WifiOff" class="h-4 w-4 flex-shrink-0" :class="onu.online ? 'text-emerald-400' : 'text-red-400'" />
                                <div class="min-w-0 flex-1">
                                    <p
                                        class="break-words text-sm leading-snug text-slate-100"
                                        :title="onu.name || $t('odp.onu_unnamed')"
                                    >
                                        {{ onu.name || $t('odp.onu_unnamed') }}
                                    </p>
                                    <p class="text-[11px] text-slate-500">
                                        {{ onu.interface }}
                                        <span v-if="onu.current_odp_name" class="text-amber-300">· {{ $t('odp.currently_in', { name: onu.current_odp_name }) }}</span>
                                    </p>
                                </div>
                                <IconButton class="flex-shrink-0" :title="$t('odp.add_onu')" :disabled="assigning" @click="assignOnu(onu, manageOdp.id)">
                                    <Plus class="h-4 w-4" />
                                </IconButton>
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="mt-6 flex justify-between gap-2">
                    <Link :href="route('map.index')" class="inline-flex items-center gap-1.5 text-sm text-cyan-300 hover:underline">
                        <MapPin class="h-4 w-4" /> {{ $t('odp.open_map') }}
                    </Link>
                    <SecondaryButton type="button" @click="closeManage">{{ $t('common.close') }}</SecondaryButton>
                </div>
            </div>
        </Modal>

        <!-- Foto ODP — komponen bersama dgn kartu detail ODP di peta. -->
        <Modal :show="photoOdp !== null" max-width="lg" @close="photoOdpId = null">
            <div v-if="photoOdp" class="p-6">
                <h3 class="mb-1 text-lg font-semibold text-white">{{ photoOdp.name }}</h3>
                <p class="mb-4 text-xs text-slate-400">{{ photoOdp.olt_name }} · {{ $t('odp.col_port') }} {{ portLabel(photoOdp) }}</p>
                <OdpPhotoField :odp="photoOdp" />
                <div class="mt-6 flex justify-end">
                    <SecondaryButton @click="photoOdpId = null">{{ $t('common.close') }}</SecondaryButton>
                </div>
            </div>
        </Modal>

        <!-- Warna pin ODP — komponen bersama dgn kartu detail ODP di peta. -->
        <OdpColorModal
            v-if="colorOdp"
            :show="true"
            :odp="colorOdp"
            :palette="odp_color_palette"
            :port-count="colorPortCount"
            @close="colorOdpId = null"
        />

        <ConfirmModal :state="confirmState" @confirm="handleConfirm" @cancel="handleCancel" />
    </AuthenticatedLayout>
</template>
