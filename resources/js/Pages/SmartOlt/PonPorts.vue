<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { formatDateTime } from '@/lib/datetime';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Cable, RefreshCw, Search } from '@lucide/vue';
import { computed, ref } from 'vue';

/**
 * Halaman port PON untuk SEMUA family OLT — ZTE (GPON), C-Data, HiOSO. Rute dipilih lewat
 * `route_prefix` (smartolt / cdata-olt / hioso-olt); data kartu port disusun server
 * (SmartOltController::serializeSnapshot untuk ZTE, App\Support\PonPortCards untuk non-ZTE).
 */
const props = defineProps({
    olt: { type: Object, required: true },
    route_prefix: { type: String, required: true },
    ports: { type: Array, default: () => [] },
    scanned_at: { type: String, default: null },
});

const ponLabel = computed(() => props.olt.capabilities?.pon_label ?? 'PON');
const isZte = computed(() => props.route_prefix === 'smartolt');

const search = ref('');
const term = computed(() => search.value.trim().toLowerCase());
const norm = (value) => String(value ?? '').toLowerCase();

const filteredPorts = computed(() => {
    if (!term.value) return props.ports.map((port) => ({ ...port, matching_onus: [] }));

    return props.ports
        .map((port) => {
            const matching = (port.onu_search_items ?? []).filter((onu) => norm(onu.search_text).includes(term.value));
            const portMatches = norm(`${port.name} ${port.slot}/${port.port} ${port.description ?? ''}`).includes(term.value);
            return { ...port, matching_onus: matching, port_matches: portMatches };
        })
        .filter((port) => port.port_matches || port.matching_onus.length > 0);
});

const onuTotal = computed(() => props.ports.reduce((sum, p) => sum + (p.onu_count ?? 0), 0));
const onuOnline = computed(() => props.ports.reduce((sum, p) => sum + (p.online_onu_count ?? 0), 0));
const portsUp = computed(() => props.ports.filter((p) => p.oper_status === 'up').length);

const refreshing = ref(false);
const scan = () => {
    refreshing.value = true;
    router.post(route(`${props.route_prefix}.refresh`, props.olt.id), {}, {
        preserveScroll: true,
        onFinish: () => { refreshing.value = false; },
    });
};

const detailRoute = computed(() => route(`${props.route_prefix}.detail`, props.olt.id));
const onusRoute = (port) => route(`${props.route_prefix}.port-onus`, [props.olt.id, port.slot, port.port]);
// Port yang tidak `up` (down, lowerlayerdown, notpresent, …) ditandai merah.
const isDown = (port) => String(port.oper_status ?? '').toLowerCase() !== 'up';
const onuLabel = (onu) => onu.name || onu.description || onu.serial_number || onu.interface || '—';
// Isi bar: proporsi ONU online. Port tanpa ONU = bar kosong.
const onlinePct = (port) => (port.onu_count ? Math.round(((port.online_onu_count ?? 0) / port.onu_count) * 100) : 0);
</script>

<template>
    <Head :title="`${$t('ponports.title', { label: ponLabel })} — ${olt.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="text-lg font-semibold leading-tight text-white sm:text-xl">{{ $t('ponports.title', { label: ponLabel }) }}</h2>
                    <p class="mt-1 text-sm text-slate-500">
                        {{ olt.name }} · {{ olt.ip }} ·
                        <span class="font-medium text-emerald-400">{{ onuOnline }}</span>
                        <span class="text-slate-400">{{ $t('gponports.onu_online_of', { total: onuTotal }) }}</span>
                    </p>
                </div>

                <div class="grid gap-2 [&>a>button]:w-full [&>button]:w-full sm:flex sm:flex-wrap sm:[&>a>button]:w-auto sm:[&>button]:w-auto">
                    <Link :href="detailRoute">
                        <SecondaryButton type="button">
                            <ArrowLeft class="mr-2 h-4 w-4" />
                            {{ $t('common.detail_olt') }}
                        </SecondaryButton>
                    </Link>
                    <PrimaryButton type="button" :disabled="refreshing" @click="scan">
                        <RefreshCw class="mr-2 h-4 w-4" :class="{ 'animate-spin': refreshing }" />
                        {{ isZte ? $t('gponports.refresh_snmp') : $t('cdatadetail.scan_onu') }}
                    </PrimaryButton>
                </div>
            </div>
        </template>

        <div class="min-h-[60vh] pt-5 pb-16 sm:pt-8">
            <div class="w-full space-y-6 px-4 sm:px-6 lg:px-8">
                <!-- Ringkasan -->
                <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <div class="kv-stat">
                        <p class="text-xs font-medium uppercase tracking-wider text-slate-500">{{ $t('ponports.stat_ports', { label: ponLabel }) }}</p>
                        <p class="mt-3 text-2xl font-semibold tabular-nums text-white">{{ portsUp }}<span class="text-sm font-normal text-slate-500"> / {{ ports.length }} up</span></p>
                        <p v-if="ports.length - portsUp > 0" class="mt-1 text-xs font-medium text-rose-300">{{ $t('ponports.ports_down', { count: ports.length - portsUp }) }}</p>
                    </div>
                    <div class="kv-stat">
                        <p class="text-xs font-medium uppercase tracking-wider text-slate-500">{{ $t('gponports.stat_onu_online') }}</p>
                        <p class="mt-3 text-2xl font-semibold tabular-nums text-emerald-300">{{ onuOnline }}<span class="text-sm font-normal text-slate-500"> / {{ onuTotal }}</span></p>
                    </div>
                    <div class="kv-stat">
                        <p class="text-xs font-medium uppercase tracking-wider text-slate-500">{{ $t('common.offline') }}</p>
                        <p class="mt-3 text-2xl font-semibold tabular-nums" :class="onuTotal - onuOnline > 0 ? 'text-rose-300' : 'text-slate-300'">{{ onuTotal - onuOnline }}</p>
                    </div>
                    <div class="kv-stat">
                        <p class="text-xs font-medium uppercase tracking-wider text-slate-500">{{ $t('common.last_refresh') }}</p>
                        <p class="mt-3 text-sm font-semibold text-white">{{ scanned_at ? formatDateTime(scanned_at) : '—' }}</p>
                    </div>
                </div>

                <!-- Grid port -->
                <section class="kv-table-card">
                    <div class="flex flex-col gap-4 border-b border-white/10 px-4 py-4 sm:px-6 md:flex-row md:items-center md:justify-between">
                        <div class="flex items-center gap-3">
                            <div class="kv-icon-tile"><Cable class="h-5 w-5" /></div>
                            <div>
                                <h3 class="text-base font-semibold text-white">{{ $t('ponports.title', { label: ponLabel }) }}</h3>
                                <p class="text-xs text-slate-500">{{ $t('ponports.subtitle') }}</p>
                            </div>
                        </div>
                        <div class="relative md:w-80">
                            <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" />
                            <input
                                v-model="search"
                                type="search"
                                class="kv-filter-control w-full pl-9"
                                :placeholder="$t('ponports.search_placeholder')"
                                :aria-label="$t('ponports.search_placeholder')"
                            />
                        </div>
                    </div>

                    <div v-if="ports.length === 0" class="px-6 py-12 text-center text-sm text-slate-500" v-html="$t('cdatadetail.empty_ports')"></div>
                    <div v-else-if="filteredPorts.length === 0" class="px-6 py-12 text-center text-sm text-slate-500">{{ $t('gponports.no_match') }}</div>
                    <div v-else class="grid gap-3 p-3 sm:grid-cols-2 sm:p-4 lg:grid-cols-3 xl:grid-cols-4">
                        <Link
                            v-for="port in filteredPorts"
                            :key="port.if_index ?? port.name"
                            :href="onusRoute(port)"
                            class="group block rounded-lg border p-4 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cyan-400/60"
                            :class="isDown(port)
                                ? 'border-rose-500/40 bg-rose-500/10 hover:border-rose-400/60 hover:bg-rose-500/15'
                                : 'border-white/10 bg-canvas-3/40 hover:border-cyan-500/40 hover:bg-cyan-500/5'"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <div class="font-mono text-sm font-semibold" :class="isDown(port) ? 'text-rose-200' : 'text-white'">{{ port.name }}</div>
                                    <div v-if="port.description" class="mt-0.5 truncate text-xs text-cyan-300" :title="port.description">{{ port.description }}</div>
                                </div>
                                <span class="kv-pill" :class="isDown(port) ? 'kv-pill-danger' : 'kv-pill-success'">{{ String(port.oper_status || 'unknown').toUpperCase() }}</span>
                            </div>

                            <div class="mt-4">
                                <div class="flex items-center justify-between text-xs">
                                    <span class="text-slate-500">ONU</span>
                                    <span class="tabular-nums text-slate-300">
                                        <span class="font-semibold text-emerald-300">{{ port.online_onu_count ?? 0 }}</span> / {{ port.onu_count ?? 0 }}
                                    </span>
                                </div>
                                <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-700/40">
                                    <div class="h-full rounded-full bg-emerald-400 transition-all" :style="{ width: `${onlinePct(port)}%` }"></div>
                                </div>
                            </div>

                            <div v-if="term && port.matching_onus.length" class="mt-3 space-y-1 border-t border-white/10 pt-3">
                                <div v-for="onu in port.matching_onus.slice(0, 3)" :key="`${port.name}-${onu.onu_id}`" class="flex items-center justify-between gap-2 text-xs">
                                    <span class="truncate font-medium text-slate-200">{{ onuLabel(onu) }}</span>
                                    <span class="shrink-0 font-semibold" :class="onu.online ? 'text-emerald-400' : 'text-slate-400'">{{ onu.online ? 'ON' : 'OFF' }}</span>
                                </div>
                                <div v-if="port.matching_onus.length > 3" class="text-xs font-medium text-slate-500">+{{ port.matching_onus.length - 3 }} ONU</div>
                            </div>
                        </Link>
                    </div>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
