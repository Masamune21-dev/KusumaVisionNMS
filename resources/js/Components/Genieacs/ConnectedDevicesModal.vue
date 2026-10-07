<script setup>
import Modal from '@/Components/Modal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { formatDateTime } from '@/lib/datetime';
import { Laptop, RefreshCw, TriangleAlert, Wifi, Cable } from '@lucide/vue';
import axios from 'axios';
import { computed, ref, watch } from 'vue';

/**
 * Daftar perangkat yang terhubung ke satu ONU, dibaca dari GenieACS.
 *
 * Data diambil HANYA saat modal dibuka (dan saat tombol segarkan ditekan),
 * tidak pernah ikut saat tabel ONU dirender.
 */
// Komponen ini dirender TERUS (tanpa v-if pada keadaan terbuka), karena
// Modal.vue memanggil dialog.showModal() di dalam watcher `show`. Kalau
// komponennya baru dibuat saat `show` sudah true, watcher itu tidak pernah
// terpicu dan dialognya tak pernah terbuka. Maka identitas ONU boleh kosong
// selama panel tertutup.
const props = defineProps({
    show: { type: Boolean, default: false },
    oltId: { type: Number, default: 0 },
    slot: { type: Number, default: 0 },
    port: { type: Number, default: 0 },
    onuId: { type: Number, default: 0 },
    onuLabel: { type: String, default: '' },
});

const emit = defineEmits(['close']);

const loading = ref(false);
const error = ref('');
const data = ref(null);

const load = async (fresh = false) => {
    loading.value = true;
    error.value = '';

    try {
        const url = route('genieacs.onu.clients', {
            olt: props.oltId, slot: props.slot, port: props.port, onuId: props.onuId,
        });
        const { data: body } = await axios.get(url, { params: fresh ? { fresh: 1 } : {} });
        data.value = body;
    } catch (e) {
        const code = e?.response?.data?.error;
        // Pesan dibedakan: "belum berpasangan" itu keadaan normal, bukan kegagalan sistem.
        error.value = code === 'not_linked'
            ? 'acsclients.err_not_linked'
            : code === 'not_configured'
                ? 'acsclients.err_not_configured'
                : 'acsclients.err_unreachable';
        data.value = null;
    } finally {
        loading.value = false;
    }
};

watch(() => props.show, (open) => {
    if (open && props.onuId) {
        data.value = null;
        showAll.value = false;
        load(false);
    }
});

const hosts = computed(() => data.value?.hosts ?? []);
const wifiNetworks = computed(() => data.value?.wifi_networks ?? []);
const lastInform = computed(() =>
    data.value?.last_inform_at ? formatDateTime(data.value.last_inform_at) : null,
);

// Default: HANYA yang terbukti tersambung. Tabel host ONU adalah tabel sewa
// DHCP yang menumpuk — menampilkan semuanya membuat satu pelanggan tampak punya
// puluhan perangkat padahal cuma beberapa yang hidup.
const showAll = ref(false);

const visibleHosts = computed(() =>
    showAll.value ? hosts.value : hosts.value.filter((h) => h.active === true),
);

const activeCount = computed(() => data.value?.active_count ?? 0);
const totalCount = computed(() => data.value?.total_count ?? hosts.value.length);
const unknownCount = computed(() => data.value?.unknown_count ?? 0);

const activeWifiCount = computed(() =>
    hosts.value.filter((h) => h.active === true && /wifi|wlan|802\.11/i.test(h.interface_type ?? '')).length,
);
</script>

<template>
    <Modal :show="show" max-width="3xl" @close="emit('close')">
        <div class="p-6">
            <div class="flex items-start gap-3 border-b border-white/10 pb-4">
                <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-cyan-500/20 ring-1 ring-cyan-500/30">
                    <Laptop class="h-5 w-5 text-cyan-300" />
                </div>
                <div class="min-w-0 flex-1">
                    <h3 class="text-base font-semibold text-white">{{ $t('acsclients.title') }}</h3>
                    <p class="truncate text-sm text-slate-400">{{ onuLabel }}</p>
                </div>
                <SecondaryButton type="button" :disabled="loading" @click="load(true)">
                    <RefreshCw class="h-4 w-4" :class="{ 'animate-spin': loading }" />
                    <span class="ml-2 hidden sm:inline">{{ $t('common.refresh') }}</span>
                </SecondaryButton>
            </div>

            <div v-if="loading && !data" class="py-12 text-center text-sm text-slate-400">
                {{ $t('acsclients.loading') }}
            </div>

            <div v-else-if="error" class="mt-4 flex items-start gap-3 rounded-lg border border-amber-500/20 bg-amber-500/5 px-4 py-3 text-sm text-amber-200">
                <TriangleAlert class="mt-0.5 h-4 w-4 flex-shrink-0" />
                <span>{{ $t(error) }}</span>
            </div>

            <template v-else-if="data">
                <div class="mt-4 grid grid-cols-3 gap-3">
                    <div class="rounded-lg border border-emerald-500/20 bg-emerald-500/5 px-4 py-3">
                        <div class="text-2xl font-semibold text-emerald-300">{{ activeCount }}</div>
                        <div class="text-xs text-slate-400">{{ $t('acsclients.active_now') }}</div>
                    </div>
                    <div class="rounded-lg border border-white/10 bg-canvas-3/40 px-4 py-3">
                        <div class="text-2xl font-semibold text-cyan-300">{{ activeWifiCount }}</div>
                        <div class="text-xs text-slate-400">{{ $t('acsclients.via_wifi') }}</div>
                    </div>
                    <div class="rounded-lg border border-white/10 bg-canvas-3/40 px-4 py-3">
                        <div class="text-2xl font-semibold text-slate-300">{{ totalCount }}</div>
                        <div class="text-xs text-slate-400">{{ $t('acsclients.registered') }}</div>
                    </div>
                </div>

                <p v-if="lastInform" class="mt-3 text-xs text-slate-500">
                    {{ $t('acsclients.last_inform', { time: lastInform }) }}
                    <span v-if="data.product_class"> · {{ data.product_class }}</span>
                </p>

                <div v-if="wifiNetworks.length" class="mt-5">
                    <h4 class="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $t('acsclients.wifi_networks') }}</h4>
                    <div class="flex flex-wrap gap-2">
                        <span
                            v-for="(net, i) in wifiNetworks"
                            :key="i"
                            class="inline-flex items-center gap-1.5 rounded-full bg-slate-500/10 px-2.5 py-1 text-xs text-slate-300 ring-1 ring-white/10"
                        >
                            <Wifi class="h-3.5 w-3.5" :class="net.enabled === false ? 'text-slate-500' : 'text-cyan-300'" />
                            {{ net.ssid || '—' }}
                            <span v-if="net.channel" class="text-slate-500">· ch {{ net.channel }}</span>
                        </span>
                    </div>
                </div>

                <div class="mt-5">
                    <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                        <h4 class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                            {{ showAll ? $t('acsclients.host_list_all') : $t('acsclients.host_list_active') }}
                        </h4>
                        <button
                            type="button"
                            class="text-xs font-medium text-cyan-300 underline-offset-2 hover:underline"
                            @click="showAll = !showAll"
                        >
                            {{ showAll ? $t('acsclients.show_active_only') : $t('acsclients.show_all', { n: totalCount }) }}
                        </button>
                    </div>

                    <p
                        v-if="!showAll && unknownCount > 0"
                        class="mb-2 text-xs text-slate-500"
                    >
                        {{ $t('acsclients.unknown_note', { n: unknownCount }) }}
                    </p>

                    <p v-if="!visibleHosts.length" class="rounded-lg border border-white/10 bg-canvas-3/40 px-4 py-6 text-center text-sm text-slate-400">
                        {{ showAll ? $t('acsclients.empty') : $t('acsclients.empty_active') }}
                    </p>

                    <div v-else class="max-h-80 overflow-y-auto rounded-lg border border-white/10">
                        <table class="min-w-full divide-y divide-white/5 text-xs">
                            <thead class="sticky top-0 bg-slate-900">
                                <tr>
                                    <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $t('acsclients.col_host') }}</th>
                                    <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $t('acsclients.col_ip') }}</th>
                                    <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">MAC</th>
                                    <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $t('acsclients.col_via') }}</th>
                                    <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $t('common.status') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/5">
                                <tr v-for="(h, i) in visibleHosts" :key="i" class="hover:bg-white/[0.03]">
                                    <td class="px-4 py-2.5 text-slate-200">{{ h.hostname || '—' }}</td>
                                    <td class="px-4 py-2.5 font-mono text-xs text-slate-300">{{ h.ip_address || '—' }}</td>
                                    <td class="px-4 py-2.5 font-mono text-xs text-slate-400">{{ h.mac_address || '—' }}</td>
                                    <td class="px-4 py-2.5">
                                        <span class="inline-flex items-center gap-1.5 text-xs text-slate-300">
                                            <Wifi v-if="/wifi|wlan/i.test(h.interface_type || '')" class="h-3.5 w-3.5 text-cyan-300" />
                                            <Cable v-else class="h-3.5 w-3.5 text-slate-400" />
                                            {{ h.interface_type || '—' }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-2.5">
                                        <span
                                            class="inline-flex items-center rounded-full px-2 py-0.5 text-xs ring-1"
                                            :class="h.active === true
                                                ? 'bg-emerald-500/15 text-emerald-300 ring-emerald-500/30'
                                                : h.active === false
                                                    ? 'bg-slate-500/10 text-slate-400 ring-slate-500/20'
                                                    : 'bg-amber-500/10 text-amber-300/80 ring-amber-500/20'"
                                        >
                                            {{ h.active === true ? $t('acsclients.st_active') : h.active === false ? $t('acsclients.st_inactive') : $t('acsclients.st_unknown') }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </template>

            <div class="mt-6 flex justify-end border-t border-white/10 pt-4">
                <SecondaryButton type="button" @click="emit('close')">{{ $t('common.close') }}</SecondaryButton>
            </div>
        </div>
    </Modal>
</template>
