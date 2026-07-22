<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { formatDateTime, formatTimeOfDay } from '@/lib/datetime';
import { parseOnuDescription } from '@/lib/onu';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Activity, ArrowLeft, Cable, Gauge, Network, Plus, RefreshCw, Tag, Users, Zap } from '@lucide/vue';
import { computed, onBeforeUnmount, reactive, ref } from 'vue';
import VueApexCharts from 'vue3-apexcharts';

const { t } = useI18n({ useScope: 'global' });

const props = defineProps({
    olt: { type: Object, required: true },
    interface: { type: String, required: true },
    type: { type: String, required: true }, // 'gpon' | 'uplink'
    slot: { type: Number, default: null },
    port: { type: Number, default: null },
    card_type: { type: String, default: null },
    detail: { type: Object, default: null },
    onu_summary: { type: Object, default: null },
});

const page = usePage();
const flash = computed(() => page.props.flash ?? {});

const isUplink = computed(() => props.type === 'uplink');
const isGpon = computed(() => props.type === 'gpon');
const d = computed(() => props.detail ?? {});
const hasData = computed(() => props.detail !== null && props.detail !== undefined);
// SmartOLT-style description → structured zone / external-id / authorization date.
const descParsed = computed(() => parseOnuDescription(d.value.description));

// ── Refresh dari OLT ───────────────────────────────────────────────────
const refreshing = ref(false);
const doRefresh = () => {
    refreshing.value = true;
    router.post(route('smartolt.port.refresh', props.olt.id), { interface: props.interface }, {
        preserveScroll: true,
        onFinish: () => { refreshing.value = false; },
    });
};

// ── Format helpers ─────────────────────────────────────────────────────
const toMbps = (bps) => (Number(bps || 0) * 8) / 1_000_000;

const formatMbps = (mbps) => {
    const v = Number(mbps || 0);
    if (v >= 1000) return `${(v / 1000).toFixed(2)} Gbps`;
    if (v >= 100) return `${v.toFixed(0)} Mbps`;
    if (v >= 10) return `${v.toFixed(1)} Mbps`;
    return `${v.toFixed(2)} Mbps`;
};

const formatBps = (bps) => (bps === null || bps === undefined || bps === '' ? '-' : formatMbps(toMbps(bps)));

const formatNumber = (value, suffix = '') =>
    value === null || value === undefined || value === '' ? '-' : `${Number(value).toLocaleString('id-ID')}${suffix}`;

const formatPercent = (value) =>
    value === null || value === undefined || value === '' ? '-' : `${Number(value).toLocaleString('id-ID')}%`;

const formatDate = (value) => formatDateTime(value);

const statusBadge = (status) => {
    const s = String(status ?? '').toLowerCase();
    if (['up', 'enable', 'inservice'].includes(s)) return 'bg-emerald-500/15 text-emerald-300 ring-1 ring-emerald-500/30';
    if (['down', 'disable', 'loopback'].includes(s)) return 'bg-red-500/15 text-red-300 ring-1 ring-red-500/30';
    return 'bg-slate-500/15 text-slate-300 ring-1 ring-white/10';
};

// Warna nilai optical berdasar threshold (merah bila di luar batas).
const opticalColor = (value, lowerKey, upperKey) => {
    if (value === null || value === undefined) return 'text-slate-300';
    const t = d.value.optical_thresholds ?? {};
    const lower = t[lowerKey];
    const upper = t[upperKey];
    if ((lower !== undefined && lower !== null && value < lower) || (upper !== undefined && upper !== null && value > upper)) {
        return 'text-red-300';
    }
    return 'text-emerald-300';
};

// ── Trafik live (uplink) ───────────────────────────────────────────────
const MAX_POINTS = 30;
const AXIS_TICKS = 5;
const RX_COLOR = '#0284c7';
const TX_COLOR = '#10b981';

const trafficHistory = reactive({ labels: [], input: [], output: [] });
const uplinkInfo = reactive({ line_status: null, input_bps: 0, output_bps: 0, input_pps: 0, output_pps: 0 });
const trafficError = ref(null);
const liveTrafficEnabled = ref(false);
let pollTimer = null;

const niceAxisMax = (value) => {
    if (!Number.isFinite(value) || value <= 0) return 10;
    const rawStep = (value * 1.1) / AXIS_TICKS;
    const magnitude = 10 ** Math.floor(Math.log10(rawStep));
    const normalized = rawStep / magnitude;
    const multiplier = [1, 1.25, 2, 2.5, 5, 10].find((item) => normalized <= item) ?? 10;
    return multiplier * magnitude * AXIS_TICKS;
};

const chartMaxMbps = computed(() =>
    niceAxisMax(Math.max(0, ...[...trafficHistory.input, ...trafficHistory.output].filter(Number.isFinite))),
);

const chartOptions = computed(() => ({
    chart: {
        type: 'area',
        background: 'transparent',
        foreColor: '#64748b',
        animations: { enabled: true, easing: 'linear', dynamicAnimation: { speed: 800 } },
        toolbar: { show: false },
        zoom: { enabled: false },
    },
    stroke: { curve: 'smooth', width: 2 },
    colors: [RX_COLOR, TX_COLOR],
    fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.35, opacityTo: 0.05, stops: [0, 95, 100] } },
    dataLabels: { enabled: false },
    markers: { size: 0, hover: { size: 4 } },
    xaxis: {
        categories: trafficHistory.labels,
        labels: { show: false },
        axisTicks: { show: false },
        axisBorder: { show: false },
    },
    yaxis: {
        labels: { minWidth: 76, formatter: (v) => formatMbps(v), style: { colors: '#64748b' } },
        min: 0,
        max: chartMaxMbps.value,
        tickAmount: AXIS_TICKS,
        forceNiceScale: true,
    },
    tooltip: { theme: 'light', y: { formatter: (v) => formatMbps(v) } },
    legend: { position: 'top', horizontalAlign: 'left' },
    grid: { strokeDashArray: 3, borderColor: 'rgba(0,0,0,0.08)' },
}));

const chartSeries = computed(() => [
    { name: 'RX / In', data: [...trafficHistory.input] },
    { name: 'TX / Out', data: [...trafficHistory.output] },
]);

const fetchTraffic = async () => {
    try {
        const res = await fetch(
            `${route('smartolt.port.traffic', props.olt.id)}?interface=${encodeURIComponent(props.interface)}`,
            { headers: { 'X-Requested-With': 'XMLHttpRequest' } },
        );
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const data = await res.json();
        if (data.error) throw new Error(data.error);

        uplinkInfo.line_status = data.line_status;
        uplinkInfo.input_bps = data.input_bps;
        uplinkInfo.output_bps = data.output_bps;
        uplinkInfo.input_pps = data.input_pps;
        uplinkInfo.output_pps = data.output_pps;
        trafficError.value = null;

        trafficHistory.labels.push(formatTimeOfDay(new Date()));
        trafficHistory.input.push(parseFloat(toMbps(data.input_bps).toFixed(3)));
        trafficHistory.output.push(parseFloat(toMbps(data.output_bps).toFixed(3)));

        if (trafficHistory.labels.length > MAX_POINTS) {
            trafficHistory.labels.shift();
            trafficHistory.input.shift();
            trafficHistory.output.shift();
        }
    } catch (e) {
        trafficError.value = e.message;
    }
};

const toggleLiveTraffic = () => {
    if (liveTrafficEnabled.value) {
        clearInterval(pollTimer);
        pollTimer = null;
        liveTrafficEnabled.value = false;
        return;
    }
    liveTrafficEnabled.value = true;
    trafficHistory.labels = [];
    trafficHistory.input = [];
    trafficHistory.output = [];
    trafficError.value = null;
    fetchTraffic();
    pollTimer = setInterval(fetchTraffic, 10000);
};

onBeforeUnmount(() => clearInterval(pollTimer));

// ── VLAN (uplink) ──────────────────────────────────────────────────────
const taggedVlans = computed(() => d.value.tagged_vlans ?? []);
const vlanForm = reactive({ vlan_id: '', submitting: false });
const vlanToast = reactive({ show: false, ok: true, message: '' });

// Entri VLAN bisa berupa angka tunggal ("122") atau rentang ("20-120").
const isVlanRange = (v) => /^\d+-\d+$/.test(String(v));
// Tampilkan rentang dengan en-dash agar lebih rapi (20–120).
const formatVlan = (v) => String(v).replace('-', '–');
// Jumlah total VLAN individual (rentang dihitung penuh) untuk ringkasan di header.
const totalVlanCount = computed(() =>
    taggedVlans.value.reduce((sum, v) => {
        const m = String(v).match(/^(\d+)-(\d+)$/);
        return sum + (m ? Number(m[2]) - Number(m[1]) + 1 : 1);
    }, 0),
);

const submitVlan = async () => {
    const vlanId = parseInt(vlanForm.vlan_id, 10);
    if (Number.isNaN(vlanId) || vlanId < 1 || vlanId > 4094) return;

    vlanForm.submitting = true;
    vlanToast.show = false;
    try {
        const { data } = await window.axios.post(route('smartolt.port.vlan', props.olt.id), {
            interface: props.interface,
            vlan_id: vlanId,
        });
        vlanToast.ok = data.ok;
        vlanToast.message = data.message;
        vlanToast.show = true;
        if (data.ok) {
            vlanForm.vlan_id = '';
            router.reload({ only: ['detail'] });
        }
        setTimeout(() => { vlanToast.show = false; }, 5000);
    } catch (e) {
        vlanToast.ok = false;
        vlanToast.message = t('portdetail.request_failed_prefix', { msg: e.response?.data?.message ?? e.message });
        vlanToast.show = true;
    } finally {
        vlanForm.submitting = false;
    }
};

// ── Deskripsi port PON (GPON, CLI) ─────────────────────────────────────
const canManageOlt = computed(() => Boolean(page.props.auth?.can?.manage_olt));
const canEditPortDesc = computed(() =>
    isGpon.value
    && canManageOlt.value
    && props.olt.cli_transport === 'telnet'
    && Boolean(props.olt.capabilities?.supports_port_description_write),
);
const descForm = reactive({ value: '', editing: false, submitting: false });
const descToast = reactive({ show: false, ok: true, message: '' });

const startEditDesc = () => {
    descForm.value = d.value.description ?? '';
    descToast.show = false;
    descForm.editing = true;
};

const submitDesc = async () => {
    descForm.submitting = true;
    descToast.show = false;
    try {
        const { data } = await window.axios.post(route('smartolt.port.description', props.olt.id), {
            slot: props.slot,
            port: props.port,
            description: descForm.value.trim(),
        });
        descToast.ok = data.ok;
        descToast.message = data.message;
        descToast.show = true;
        if (data.ok) {
            descForm.editing = false;
            router.reload({ only: ['detail'] });
        }
        setTimeout(() => { descToast.show = false; }, 5000);
    } catch (e) {
        descToast.ok = false;
        descToast.message = t('portdetail.request_failed_prefix', { msg: e.response?.data?.message ?? e.message });
        descToast.show = true;
    } finally {
        descForm.submitting = false;
    }
};
</script>

<template>
    <Head :title="`Port ${interface}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-sky-500/15 ring-1 ring-cyan-500/30">
                        <Cable class="h-5 w-5 text-cyan-400" />
                    </div>
                    <div>
                        <h2 class="font-mono text-lg font-semibold leading-tight text-white sm:text-xl">{{ interface }}</h2>
                        <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-500">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium"
                                  :class="isGpon ? 'bg-cyan-500/15 text-cyan-300 ring-1 ring-cyan-500/30' : 'bg-violet-500/15 text-violet-300 ring-1 ring-violet-500/30'">
                                {{ isGpon ? 'GPON' : 'Uplink' }}
                            </span>
                            <span>{{ olt.name }}</span>
                            <span v-if="card_type">· {{ card_type }}</span>
                            <span v-if="slot !== null">· Slot {{ slot }}/Port {{ port }}</span>
                        </p>
                    </div>
                </div>

                <div class="grid gap-2 [&>a>button]:w-full [&>button]:w-full sm:flex sm:flex-wrap sm:[&>a>button]:w-auto sm:[&>button]:w-auto">
                    <Link :href="route('smartolt.detail', olt.id)">
                        <SecondaryButton type="button">
                            <ArrowLeft class="mr-2 h-4 w-4" />
                            {{ $t('common.back') }}
                        </SecondaryButton>
                    </Link>
                    <PrimaryButton type="button" :disabled="refreshing" @click="doRefresh">
                        <RefreshCw class="mr-2 h-4 w-4" :class="{ 'animate-spin': refreshing }" />
                        {{ $t('portdetail.refresh_from_olt') }}
                    </PrimaryButton>
                </div>
            </div>
        </template>

        <div class="min-h-[60vh] pt-5 pb-16 sm:pt-8">
            <div class="w-full space-y-6 px-4 sm:px-6 lg:px-8">

                <div v-if="!hasData" class="rounded-lg border border-amber-500/30 bg-amber-500/10 px-5 py-8 text-center text-sm text-amber-200" v-html="$t('portdetail.no_data')"></div>

                <div class="grid gap-6 lg:grid-cols-2">
                    <!-- Status -->
                    <div class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
                        <div class="flex items-center gap-3 border-b border-white/10 px-4 py-4 sm:px-6">
                            <Network class="h-5 w-5 text-cyan-400" />
                            <h3 class="text-base font-semibold text-white">{{ $t('portdetail.port_status') }}</h3>
                        </div>
                        <dl class="grid grid-cols-2 gap-px bg-white/5">
                            <div class="bg-slate-900/40 px-4 py-3">
                                <dt class="text-xs uppercase tracking-wide text-slate-500">Admin</dt>
                                <dd class="mt-1"><span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium" :class="statusBadge(d.admin_status)">{{ d.admin_status || '-' }}</span></dd>
                            </div>
                            <div class="bg-slate-900/40 px-4 py-3">
                                <dt class="text-xs uppercase tracking-wide text-slate-500">Link</dt>
                                <dd class="mt-1"><span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium" :class="statusBadge(d.link_status)">{{ d.link_status || '-' }}</span></dd>
                            </div>
                            <template v-if="isUplink">
                                <div class="bg-slate-900/40 px-4 py-3">
                                    <dt class="text-xs uppercase tracking-wide text-slate-500">Speed</dt>
                                    <dd class="mt-1 text-sm text-white">{{ d.speed_mbps ? `${d.speed_mbps} Mbps` : '-' }} · {{ d.duplex || '-' }}</dd>
                                </div>
                                <div class="bg-slate-900/40 px-4 py-3">
                                    <dt class="text-xs uppercase tracking-wide text-slate-500">{{ $t('portdetail.negotiation') }}</dt>
                                    <dd class="mt-1 text-sm text-white">{{ d.negotiation || '-' }}</dd>
                                </div>
                                <div class="bg-slate-900/40 px-4 py-3">
                                    <dt class="text-xs uppercase tracking-wide text-slate-500">Native VLAN</dt>
                                    <dd class="mt-1 text-sm text-white">{{ d.native_vlan ?? '-' }}</dd>
                                </div>
                                <div class="bg-slate-900/40 px-4 py-3">
                                    <dt class="text-xs uppercase tracking-wide text-slate-500">Flow Ctrl</dt>
                                    <dd class="mt-1 text-sm text-white">{{ d.flow_ctrl || '-' }}</dd>
                                </div>
                            </template>
                            <template v-else>
                                <div class="bg-slate-900/40 px-4 py-3">
                                    <dt class="text-xs uppercase tracking-wide text-slate-500">{{ $t('portdetail.registered_onu') }}</dt>
                                    <dd class="mt-1 text-sm text-white">{{ d.registered_onu_count ?? onu_summary?.total ?? '-' }}</dd>
                                </div>
                                <div class="bg-slate-900/40 px-4 py-3">
                                    <dt class="text-xs uppercase tracking-wide text-slate-500">{{ $t('portdetail.capacity') }}</dt>
                                    <dd class="mt-1 text-sm text-white">{{ d.onu_capacity ?? '-' }}</dd>
                                </div>
                                <div class="col-span-2 bg-slate-900/40 px-4 py-3">
                                    <div class="flex items-start justify-between gap-2">
                                        <dt class="text-xs uppercase tracking-wide text-slate-500">{{ $t('portonus.description') }}</dt>
                                        <button v-if="canEditPortDesc && !descForm.editing" type="button"
                                                class="shrink-0 text-xs font-medium text-cyan-400 transition hover:text-cyan-300"
                                                @click="startEditDesc">
                                            {{ $t('common.edit') }}
                                        </button>
                                    </div>

                                    <!-- Editor deskripsi (CLI) -->
                                    <div v-if="descForm.editing" class="mt-2 space-y-2">
                                        <input
                                            v-model="descForm.value"
                                            type="text"
                                            maxlength="64"
                                            class="kv-filter-control w-full"
                                            :placeholder="$t('portdetail.description_placeholder')"
                                            @keyup.enter="submitDesc"
                                        />
                                        <div class="flex items-center gap-2">
                                            <PrimaryButton type="button" :disabled="descForm.submitting" @click="submitDesc">
                                                {{ descForm.submitting ? $t('portdetail.saving') : $t('common.save') }}
                                            </PrimaryButton>
                                            <SecondaryButton type="button" :disabled="descForm.submitting" @click="descForm.editing = false">
                                                {{ $t('common.cancel') }}
                                            </SecondaryButton>
                                        </div>
                                        <p class="text-xs text-slate-500">{{ $t('portdetail.description_hint') }}</p>
                                    </div>

                                    <!-- Tampilan deskripsi -->
                                    <template v-else>
                                        <dd v-if="descParsed" class="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-white" :title="descParsed.raw">
                                            <span v-if="descParsed.zone"><span class="text-slate-500">{{ $t('portdetail.zone') }}:</span> {{ descParsed.zone }}</span>
                                            <span v-if="descParsed.description" class="break-words">{{ descParsed.description }}</span>
                                            <span v-if="descParsed.externalId" class="text-slate-400">SmartOLT #{{ descParsed.externalId }}</span>
                                            <span v-if="descParsed.authDate" class="text-slate-400">{{ $t('portdetail.authorized') }} {{ descParsed.authDate }}</span>
                                        </dd>
                                        <dd v-else class="mt-1 break-words text-sm text-white">{{ d.description || '-' }}</dd>
                                    </template>

                                    <p v-if="descToast.show" class="mt-2 text-xs" :class="descToast.ok ? 'text-emerald-300' : 'text-red-300'">
                                        {{ descToast.message }}
                                    </p>
                                </div>
                            </template>
                            <div class="col-span-2 bg-slate-900/40 px-4 py-3">
                                <dt class="text-xs uppercase tracking-wide text-slate-500">{{ $t('portdetail.updated') }}</dt>
                                <dd class="mt-1 text-sm text-slate-300">{{ formatDate(d.status_refreshed_at || d.refreshed_at) }}</dd>
                            </div>
                        </dl>
                    </div>

                    <!-- Optical / SFP -->
                    <div class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
                        <div class="flex items-center gap-3 border-b border-white/10 px-4 py-4 sm:px-6">
                            <Zap class="h-5 w-5 text-cyan-400" />
                            <h3 class="text-base font-semibold text-white">{{ $t('portdetail.optical_title') }}</h3>
                        </div>
                        <div v-if="d.optical_vendor_name || d.rx_power_dbm !== null && d.rx_power_dbm !== undefined" class="p-4 sm:p-6">
                            <div class="grid grid-cols-2 gap-4">
                                <div class="rounded-lg border border-white/10 bg-slate-950/40 p-4 text-center">
                                    <p class="text-xs uppercase tracking-wide text-slate-500">RX Power</p>
                                    <p class="mt-1 text-2xl font-bold" :class="opticalColor(d.rx_power_dbm, 'RxPower-Lower', 'RxPower-Upper')">
                                        {{ formatNumber(d.rx_power_dbm) }}<span class="text-sm font-normal text-slate-500"> dBm</span>
                                    </p>
                                </div>
                                <div class="rounded-lg border border-white/10 bg-slate-950/40 p-4 text-center">
                                    <p class="text-xs uppercase tracking-wide text-slate-500">TX Power</p>
                                    <p class="mt-1 text-2xl font-bold" :class="opticalColor(d.tx_power_dbm, 'TxPower-Lower', 'TxPower-Upper')">
                                        {{ formatNumber(d.tx_power_dbm) }}<span class="text-sm font-normal text-slate-500"> dBm</span>
                                    </p>
                                </div>
                            </div>
                            <dl class="mt-4 divide-y divide-white/5 text-sm">
                                <div class="flex justify-between py-2"><dt class="text-slate-500">Vendor</dt><dd class="text-white">{{ d.optical_vendor_name || '-' }}</dd></div>
                                <div class="flex justify-between py-2"><dt class="text-slate-500">PN / SN</dt><dd class="font-mono text-xs text-slate-300">{{ d.optical_vendor_pn || '-' }} / {{ d.optical_vendor_sn || '-' }}</dd></div>
                                <div class="flex justify-between py-2"><dt class="text-slate-500">{{ $t('portdetail.type_wavelength') }}</dt><dd class="text-slate-300">{{ d.optical_module_type || '-' }} · {{ formatNumber(d.optical_wavelength_nm, ' nm') }}</dd></div>
                                <div class="flex justify-between py-2"><dt class="text-slate-500">Bias / Temp / Volt</dt><dd class="text-slate-300">{{ formatNumber(d.tx_bias_current_ma, ' mA') }} · {{ formatNumber(d.temperature_c, '°C') }} · {{ formatNumber(d.supply_voltage_v, ' V') }}</dd></div>
                                <div class="flex justify-between py-2"><dt class="text-slate-500">{{ $t('portdetail.updated') }}</dt><dd class="text-slate-300">{{ formatDate(d.optical_refreshed_at) }}</dd></div>
                            </dl>
                        </div>
                        <div v-else class="px-5 py-10 text-center text-sm text-slate-500" v-html="$t('portdetail.sfp_empty')"></div>
                    </div>
                </div>

                <!-- Trafik -->
                <div class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
                    <div class="flex flex-col gap-3 border-b border-white/10 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                        <div class="flex items-center gap-3">
                            <Activity class="h-5 w-5 text-cyan-400" />
                            <h3 class="text-base font-semibold text-white">{{ $t('portdetail.traffic') }}</h3>
                        </div>
                        <SecondaryButton v-if="isUplink" type="button" @click="toggleLiveTraffic">
                            <Gauge class="mr-2 h-4 w-4" :class="{ 'animate-pulse text-emerald-400': liveTrafficEnabled }" />
                            {{ liveTrafficEnabled ? $t('portdetail.stop_live') : $t('portdetail.live_traffic') }}
                        </SecondaryButton>
                    </div>
                    <div class="p-4 sm:p-6">
                        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                            <div class="rounded-lg border border-white/10 bg-slate-950/40 p-4">
                                <p class="text-xs uppercase tracking-wide text-slate-500">Input</p>
                                <p class="mt-1 text-lg font-bold text-sky-300">{{ formatBps(isUplink && liveTrafficEnabled ? uplinkInfo.input_bps : d.input_bps) }}</p>
                            </div>
                            <div class="rounded-lg border border-white/10 bg-slate-950/40 p-4">
                                <p class="text-xs uppercase tracking-wide text-slate-500">Output</p>
                                <p class="mt-1 text-lg font-bold text-emerald-300">{{ formatBps(isUplink && liveTrafficEnabled ? uplinkInfo.output_bps : d.output_bps) }}</p>
                            </div>
                            <div class="rounded-lg border border-white/10 bg-slate-950/40 p-4">
                                <p class="text-xs uppercase tracking-wide text-slate-500">Throughput In/Out</p>
                                <p class="mt-1 text-lg font-bold text-white">{{ formatPercent(d.input_throughput_percent) }} / {{ formatPercent(d.output_throughput_percent) }}</p>
                            </div>
                            <div class="rounded-lg border border-white/10 bg-slate-950/40 p-4">
                                <p class="text-xs uppercase tracking-wide text-slate-500">Peak In/Out</p>
                                <p class="mt-1 text-sm font-bold text-white">{{ formatBps(d.input_peak_bps) }} / {{ formatBps(d.output_peak_bps) }}</p>
                            </div>
                        </div>

                        <div v-if="isUplink" class="mt-4">
                            <p v-if="trafficError" class="mb-2 text-xs text-red-300">{{ $t('portdetail.traffic_error', { error: trafficError }) }}</p>
                            <div v-if="liveTrafficEnabled" class="rounded-lg border border-white/10 bg-slate-950/40 p-2">
                                <VueApexCharts type="area" height="260" :options="chartOptions" :series="chartSeries" />
                            </div>
                            <p v-else class="rounded-lg border border-dashed border-white/10 bg-slate-950/30 px-4 py-8 text-center text-sm text-slate-500" v-html="$t('portdetail.live_hint')"></p>
                        </div>
                    </div>
                </div>

                <!-- VLAN (uplink) -->
                <div v-if="isUplink" class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
                    <div class="flex items-center justify-between gap-3 border-b border-white/10 px-4 py-4 sm:px-6">
                        <div class="flex items-center gap-3">
                            <Tag class="h-5 w-5 text-cyan-400" />
                            <h3 class="text-base font-semibold text-white">VLAN Tagged</h3>
                        </div>
                        <span v-if="taggedVlans.length" class="kv-pill-info">{{ totalVlanCount }} VLAN</span>
                    </div>
                    <div class="p-4 sm:p-6">
                        <div v-if="taggedVlans.length" class="flex flex-wrap gap-1.5">
                            <span
                                v-for="v in taggedVlans"
                                :key="v"
                                class="inline-flex items-center gap-1 rounded-md px-2.5 py-1 text-xs font-medium tabular-nums ring-1 transition-colors"
                                :class="isVlanRange(v)
                                    ? 'bg-violet-500/15 text-violet-200 ring-violet-500/30'
                                    : 'bg-sky-500/15 text-cyan-300 ring-cyan-500/30'"
                                :title="isVlanRange(v) ? $t('portdetail.vlan_range') : 'VLAN'"
                            >
                                <Network v-if="isVlanRange(v)" class="h-3 w-3 opacity-70" />
                                {{ formatVlan(v) }}
                            </span>
                        </div>
                        <div v-else class="flex items-center gap-2 rounded-lg border border-dashed border-white/10 bg-slate-950/30 px-4 py-3 text-sm text-slate-500">
                            <Tag class="h-4 w-4 flex-shrink-0 text-slate-600" />
                            {{ $t('portdetail.no_vlan') }}
                        </div>

                        <div class="mt-5 border-t border-white/10 pt-5">
                            <label for="vlan-add" class="mb-2 block text-xs font-medium uppercase tracking-wide text-slate-500">{{ $t('portdetail.add_vlan_label') }}</label>
                            <form class="flex flex-col gap-2 sm:flex-row sm:items-center" @submit.prevent="submitVlan">
                                <input
                                    id="vlan-add"
                                    v-model="vlanForm.vlan_id"
                                    type="number" min="1" max="4094" placeholder="VLAN ID (1-4094)"
                                    class="kv-input w-full text-sm sm:w-48"
                                />
                                <PrimaryButton type="submit" :disabled="vlanForm.submitting || !vlanForm.vlan_id">
                                    <Plus class="mr-2 h-4 w-4" />
                                    {{ vlanForm.submitting ? $t('portdetail.saving') : $t('portdetail.add_tag_vlan') }}
                                </PrimaryButton>
                            </form>
                            <div
                                v-if="vlanToast.show"
                                class="mt-3 flex items-center gap-2 rounded-lg border px-3 py-2 text-xs"
                                :class="vlanToast.ok
                                    ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300'
                                    : 'border-red-500/30 bg-red-500/10 text-red-300'"
                            >
                                <span class="h-1.5 w-1.5 flex-shrink-0 rounded-full" :class="vlanToast.ok ? 'bg-emerald-400' : 'bg-red-400'"></span>
                                {{ vlanToast.message }}
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ONU (gpon) -->
                <div v-if="isGpon" class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
                    <div class="flex flex-col gap-3 border-b border-white/10 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                        <div class="flex items-center gap-3">
                            <Users class="h-5 w-5 text-cyan-400" />
                            <h3 class="text-base font-semibold text-white">{{ $t('portdetail.onu_on_port') }}</h3>
                        </div>
                        <Link v-if="slot !== null" :href="route('smartolt.port-onus', [olt.id, slot, port])">
                            <SecondaryButton type="button">
                                <Users class="mr-2 h-4 w-4" />
                                {{ $t('portdetail.view_onu_list') }}
                            </SecondaryButton>
                        </Link>
                    </div>
                    <div class="grid grid-cols-2 gap-4 p-4 sm:p-6">
                        <div class="rounded-lg border border-white/10 bg-slate-950/40 p-4 text-center">
                            <p class="text-xs uppercase tracking-wide text-slate-500">{{ $t('portonus.stat_total_onu') }}</p>
                            <p class="mt-1 text-2xl font-bold text-white">{{ onu_summary?.total ?? d.registered_onu_count ?? 0 }}</p>
                        </div>
                        <div class="rounded-lg border border-white/10 bg-slate-950/40 p-4 text-center">
                            <p class="text-xs uppercase tracking-wide text-slate-500">{{ $t('common.online') }}</p>
                            <p class="mt-1 text-2xl font-bold text-emerald-400">{{ onu_summary?.online ?? '-' }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
