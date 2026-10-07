<script setup>
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { formatDateTime } from '@/lib/datetime';
import { CheckCircle2, Link2, Link2Off, RefreshCw, Search, TriangleAlert } from '@lucide/vue';
import axios from 'axios';
import { computed, ref, watch } from 'vue';

/**
 * Menyematkan pasangan ONU ↔ device GenieACS secara manual.
 *
 * Dipakai untuk ONU yang tak bisa dicocokkan otomatis (serial GPON-nya tidak
 * dilaporkan lewat TR-069) dan untuk membetulkan pasangan yang meleset.
 *
 * Yang tersimpan adalah IDENTITAS ONU — serialnya — bukan posisi slot/port.
 * Jadi ONU yang kelak dipindah ke port lain ikut membawa pasangannya sendiri,
 * dan ONU yang diganti unit baru pasangannya dilepas, bukan diwariskan ke
 * pelanggan berikutnya yang menempati posisi itu.
 *
 * Dirender terus (tanpa v-if pada keadaan terbuka) karena Modal.vue membuka
 * dialog di dalam watcher `show`.
 */
const props = defineProps({
    show: { type: Boolean, default: false },
    oltId: { type: Number, default: 0 },
    slot: { type: Number, default: 0 },
    port: { type: Number, default: 0 },
    onuId: { type: Number, default: 0 },
    onuLabel: { type: String, default: '' },
    // Pasangan yang berlaku sekarang (dari prop halaman), null bila belum ada.
    acs: { type: Object, default: null },
});

const emit = defineEmits(['close', 'saved']);

const term = ref('');
const devices = ref([]);
const loading = ref(false);
const busy = ref(false);
const error = ref('');
const notice = ref('');
const selected = ref(null);
const refreshing = ref(false);

let searchTimer = null;

const search = async () => {
    loading.value = true;
    error.value = '';

    try {
        const { data } = await axios.get(route('genieacs.devices.search'), {
            params: { q: term.value || undefined, limit: 25 },
        });
        devices.value = data.devices ?? [];
    } catch (e) {
        error.value = e?.response?.data?.message || 'error';
        devices.value = [];
    } finally {
        loading.value = false;
    }
};

// Pencarian hanya menyentuh tabel lokal, tapi tetap ditahan sebentar supaya
// tiap ketikan tidak jadi satu permintaan.
watch(term, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(search, 300);
});

watch(
    () => props.show,
    (open) => {
        if (!open) return;

        term.value = '';
        selected.value = null;
        error.value = '';
        notice.value = '';
        devices.value = [];
        search();
    },
);

// ONU yang baru di-set TR069 sudah tampak di web GenieACS tapi belum ada di
// tabel lokal (disegarkan tiap 15 menit) — tombol ini menarik katalognya sekarang.
const refreshFromAcs = async () => {
    if (refreshing.value) return;

    refreshing.value = true;
    error.value = '';
    notice.value = '';

    try {
        await axios.post(route('genieacs.devices.refresh'));
        notice.value = 'refreshed';
        await search();
    } catch (e) {
        error.value = e?.response?.data?.error || 'refresh_failed';
    } finally {
        refreshing.value = false;
    }
};

const currentDeviceId = computed(() => props.acs?.device_id ?? null);

const pin = async () => {
    if (!selected.value || busy.value) return;

    busy.value = true;
    error.value = '';
    notice.value = '';

    try {
        const { data } = await axios.post(
            route('genieacs.onu.pin', {
                olt: props.oltId, slot: props.slot, port: props.port, onuId: props.onuId,
            }),
            { device_id: selected.value },
        );

        notice.value = data.ok ? 'pinned' : '';
        emit('saved');
        emit('close');
    } catch (e) {
        error.value = e?.response?.data?.error || e?.response?.data?.message || 'error';
    } finally {
        busy.value = false;
    }
};

const unpin = async () => {
    if (busy.value) return;

    busy.value = true;
    error.value = '';
    notice.value = '';

    try {
        const { data } = await axios.delete(
            route('genieacs.onu.unpin', {
                olt: props.oltId, slot: props.slot, port: props.port, onuId: props.onuId,
            }),
        );

        // Pencocokan otomatis bisa langsung memasangkannya kembali ke ONU yang
        // sama — itu bukan kegagalan, tapi operator perlu tahu supaya tidak
        // mengira tombolnya tidak bekerja.
        notice.value = data.rematch_method ? 'rematched' : 'unpinned';
        emit('saved');
    } catch (e) {
        error.value = e?.response?.data?.error || e?.response?.data?.message || 'error';
    } finally {
        busy.value = false;
    }
};

// Pesan galat dipetakan eksplisit; kode yang tak dikenal jatuh ke pesan umum
// supaya tidak ada kunci i18n mentah yang bocor ke layar.
const KNOWN_ERRORS = ['device_not_found', 'onu_not_found', 'not_linked', 'olt_not_eligible', 'sync_busy', 'sync_failed', 'refresh_failed'];

const errorText = computed(() =>
    KNOWN_ERRORS.includes(error.value) ? `acspin.error_${error.value}` : 'acspin.error_generic',
);

const NOTICES = { pinned: 'acspin.notice_pinned', unpinned: 'acspin.notice_unpinned', rematched: 'acspin.notice_rematched', refreshed: 'acspin.notice_refreshed' };

const noticeText = computed(() => NOTICES[notice.value] ?? null);

const methodKey = computed(() => {
    const method = props.acs?.match_method;

    return ['serial', 'mac', 'manual'].includes(method) ? `acspin.method_${method}` : 'acspin.method_unknown';
});

const primaryLabel = (d) => d.pppoe_username || d.serial_number || d.device_id;

const secondaryLabel = (d) =>
    [d.serial_number, d.pon_mac, d.tr069_ip, d.product_class].filter(Boolean).join(' · ');
</script>

<template>
    <Modal :show="show" max-width="2xl" @close="emit('close')">
        <div class="p-6">
            <div class="flex items-start gap-3">
                <div class="rounded-lg bg-cyan-500/15 p-2 text-cyan-300 ring-1 ring-cyan-500/30">
                    <Link2 class="h-5 w-5" />
                </div>
                <div class="min-w-0">
                    <h2 class="text-lg font-semibold text-white">{{ $t('acspin.title') }}</h2>
                    <p class="mt-0.5 truncate text-sm text-slate-400">{{ onuLabel }}</p>
                </div>
            </div>

            <p class="mt-4 rounded-lg bg-slate-800/60 px-3 py-2 text-xs leading-relaxed text-slate-400 ring-1 ring-white/5">
                {{ $t('acspin.hint') }}
            </p>

            <!-- Pasangan yang berlaku sekarang -->
            <div v-if="acs" class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-lg bg-emerald-500/10 px-3 py-2.5 ring-1 ring-emerald-500/25">
                <div class="min-w-0">
                    <div class="flex items-center gap-1.5 text-sm font-medium text-emerald-200">
                        <CheckCircle2 class="h-4 w-4 shrink-0" />
                        <span class="truncate">{{ acs.device_id }}</span>
                    </div>
                    <div class="mt-0.5 text-xs text-slate-400">
                        {{ $t(methodKey) }}
                        <span v-if="acs.last_inform_at"> · {{ formatDateTime(acs.last_inform_at) }}</span>
                    </div>
                </div>
                <SecondaryButton :disabled="busy" @click="unpin">
                    <Link2Off class="mr-1.5 h-4 w-4" />
                    {{ $t('acspin.action_unpin') }}
                </SecondaryButton>
            </div>

            <div v-else class="mt-4 rounded-lg bg-slate-800/60 px-3 py-2.5 text-sm text-slate-400 ring-1 ring-white/5">
                {{ $t('acspin.current_none') }}
            </div>

            <!-- Pencarian device ACS -->
            <div class="mt-5 flex flex-col gap-2 sm:flex-row">
                <div class="relative flex-1">
                    <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" />
                    <TextInput
                        v-model="term"
                        type="search"
                        class="w-full pl-9"
                        :placeholder="$t('acspin.search_placeholder')"
                    />
                </div>
                <SecondaryButton :disabled="refreshing" :title="$t('acspin.refresh_hint')" @click="refreshFromAcs">
                    <RefreshCw class="mr-1.5 h-4 w-4" :class="{ 'animate-spin': refreshing }" />
                    {{ refreshing ? $t('acspin.refreshing') : $t('acspin.refresh') }}
                </SecondaryButton>
            </div>

            <div class="mt-3 max-h-72 overflow-y-auto rounded-lg ring-1 ring-white/5">
                <p v-if="loading" class="px-3 py-6 text-center text-sm text-slate-500">{{ $t('acspin.searching') }}</p>
                <p v-else-if="devices.length === 0" class="px-3 py-6 text-center text-sm text-slate-500">
                    {{ $t('acspin.no_results') }}
                    <span class="mt-1 block text-xs">{{ $t('acspin.no_results_hint') }}</span>
                </p>

                <button
                    v-for="d in devices"
                    :key="d.device_id"
                    type="button"
                    class="flex w-full items-start gap-3 border-b border-white/5 px-3 py-2.5 text-left transition last:border-b-0 hover:bg-white/5"
                    :class="{ 'bg-cyan-500/10': selected === d.device_id }"
                    @click="selected = d.device_id"
                >
                    <span
                        class="mt-1.5 h-2 w-2 shrink-0 rounded-full"
                        :class="d.online ? 'bg-emerald-400' : 'bg-slate-600'"
                        :title="d.last_inform_at ? formatDateTime(d.last_inform_at) : ''"
                    />
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium text-slate-100">{{ primaryLabel(d) }}</span>
                        <span class="block truncate font-mono text-xs text-slate-500">{{ secondaryLabel(d) }}</span>

                        <span
                            v-if="d.device_id === currentDeviceId"
                            class="mt-1 inline-flex items-center gap-1 rounded bg-emerald-500/15 px-1.5 py-0.5 text-[11px] text-emerald-300"
                        >
                            {{ $t('acspin.badge_current') }}
                        </span>
                        <span
                            v-else-if="d.linked_to"
                            class="mt-1 inline-flex items-center gap-1 rounded bg-amber-500/15 px-1.5 py-0.5 text-[11px] text-amber-300"
                        >
                            <TriangleAlert class="h-3 w-3" />
                            {{ $t('acspin.badge_linked', {
                                olt: d.linked_to.olt_name || d.linked_to.olt_id,
                                slot: d.linked_to.slot,
                                port: d.linked_to.port,
                                onu: d.linked_to.onu_id,
                            }) }}
                        </span>
                        <span
                            v-else-if="d.manual_stale"
                            class="mt-1 inline-flex items-center gap-1 rounded bg-amber-500/15 px-1.5 py-0.5 text-[11px] text-amber-300"
                        >
                            <TriangleAlert class="h-3 w-3" />
                            {{ $t('acspin.badge_stale') }}
                        </span>
                    </span>
                </button>
            </div>

            <p v-if="error" class="mt-3 flex items-center gap-1.5 text-sm text-rose-300">
                <TriangleAlert class="h-4 w-4 shrink-0" />
                {{ $t(errorText) }}
            </p>
            <p v-else-if="noticeText" class="mt-3 flex items-center gap-1.5 text-sm text-emerald-300">
                <CheckCircle2 class="h-4 w-4 shrink-0" />
                {{ $t(noticeText) }}
            </p>

            <div class="mt-6 flex justify-end gap-3">
                <SecondaryButton @click="emit('close')">{{ $t('common.close') }}</SecondaryButton>
                <PrimaryButton :disabled="!selected || busy || selected === currentDeviceId" @click="pin">
                    <Link2 class="mr-1.5 h-4 w-4" />
                    {{ busy ? $t('acspin.pinning') : $t('acspin.action_pin') }}
                </PrimaryButton>
            </div>
        </div>
    </Modal>
</template>
