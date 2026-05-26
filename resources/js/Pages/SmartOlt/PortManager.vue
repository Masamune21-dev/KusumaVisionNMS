<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, CheckCircle2, Eye, Network, Plus, RefreshCw, Signal, Tag, Wifi, XCircle } from '@lucide/vue';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import VueApexCharts from 'vue3-apexcharts';

const props = defineProps({
    olt: { type: Object, required: true },
    uplink_interfaces: { type: Array, default: () => [] },
    vlans_by_interface: { type: Object, default: () => ({}) },
    interface_details: { type: Array, default: () => [] },
});

const page = usePage();
const flash = computed(() => page.props.flash ?? {});
const toast = reactive({ show: false, ok: true, message: '' });

// Auto-dismiss flash messages after 4 seconds
const flashVisible = reactive({ success: !!flash.value.success, error: !!flash.value.error });
let flashSuccessTimer = null;
let flashErrorTimer = null;

watch(() => flash.value.success, (val) => {
    if (val) {
        flashVisible.success = true;
        clearTimeout(flashSuccessTimer);
        flashSuccessTimer = setTimeout(() => { flashVisible.success = false; }, 4000);
    }
}, { immediate: true });

watch(() => flash.value.error, (val) => {
    if (val) {
        flashVisible.error = true;
        clearTimeout(flashErrorTimer);
        flashErrorTimer = setTimeout(() => { flashVisible.error = false; }, 4000);
    }
}, { immediate: true });

// ── Interface selector (traffic chart) ─────────────────────────────────
const selectedInterface = ref(props.uplink_interfaces[0]?.interface ?? '');

// ── Traffic chart ───────────────────────────────────────────────────────
const MAX_POINTS = 30;
const AXIS_TICKS = 5;
const trafficHistory = reactive({ labels: [], input: [], output: [] });
const uplinkInfo = reactive({ line_status: null, input_bps: 0, output_bps: 0, input_pps: 0, output_pps: 0 });
const trafficError = ref(null);
const liveTrafficEnabled = ref(false);
let pollTimer = null;

const RX_COLOR = '#0284c7';
const TX_COLOR = '#10b981';

const toMbps = (bytesPerSecond) => (Number(bytesPerSecond || 0) * 8) / 1_000_000;

const formatMbps = (mbps, compact = false) => {
    const value = Number(mbps || 0);

    if (value >= 1000) {
        const gbps = value / 1000;
        return compact ? `${gbps.toFixed(gbps >= 10 ? 0 : 1)}G` : `${gbps.toFixed(2)} Gbps`;
    }

    if (compact) {
        if (value >= 10) return `${value.toFixed(0)}M`;
        if (value >= 1) return `${value.toFixed(1)}M`;
        return `${value.toFixed(2)}M`;
    }

    if (value >= 100) return `${value.toFixed(0)} Mbps`;
    if (value >= 10) return `${value.toFixed(1)} Mbps`;
    return `${value.toFixed(2)} Mbps`;
};

const niceAxisMax = (value) => {
    if (!Number.isFinite(value) || value <= 0) return 10;

    const rawStep = (value * 1.1) / AXIS_TICKS;
    const magnitude = 10 ** Math.floor(Math.log10(rawStep));
    const normalized = rawStep / magnitude;
    const multiplier = [1, 1.25, 2, 2.5, 5, 10].find((item) => normalized <= item) ?? 10;

    return multiplier * magnitude * AXIS_TICKS;
};

const chartMaxMbps = computed(() => {
    const values = [
        ...trafficHistory.input,
        ...trafficHistory.output,
        toMbps(uplinkInfo.input_bps),
        toMbps(uplinkInfo.output_bps),
    ].filter((value) => Number.isFinite(value));

    return niceAxisMax(Math.max(0, ...values));
});

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
    fill: {
        type: 'gradient',
        gradient: {
            shadeIntensity: 1,
            opacityFrom: 0.35,
            opacityTo: 0.05,
            stops: [0, 95, 100],
        },
    },
    dataLabels: { enabled: false },
    markers: { size: 0, hover: { size: 4 } },
    xaxis: {
        categories: trafficHistory.labels,
        labels: { show: false, style: { colors: '#64748b' } },
        axisTicks: { show: false },
        axisBorder: { show: false },
    },
    yaxis: {
        labels: {
            minWidth: 76,
            formatter: (v) => formatMbps(v),
            style: { colors: '#64748b' },
        },
        min: 0,
        max: chartMaxMbps.value,
        tickAmount: AXIS_TICKS,
        forceNiceScale: true,
    },
    tooltip: {
        theme: 'light',
        y: { formatter: (v) => formatMbps(v) },
    },
    legend: { position: 'top', horizontalAlign: 'left' },
    grid: { strokeDashArray: 3, borderColor: 'rgba(0,0,0,0.08)' },
}));

const chartSeries = computed(() => [
    { name: 'RX / In', data: [...trafficHistory.input] },
    { name: 'TX / Out', data: [...trafficHistory.output] },
]);

const fetchTraffic = async () => {
    if (!selectedInterface.value) return;

    try {
        const res = await fetch(route('smartolt.port-manager.traffic', props.olt.id) + '?interface=' + encodeURIComponent(selectedInterface.value), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });

        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const data = await res.json();

        if (data.error) throw new Error(data.error);

        uplinkInfo.line_status = data.line_status;
        uplinkInfo.input_bps = data.input_bps;
        uplinkInfo.output_bps = data.output_bps;
        uplinkInfo.input_pps = data.input_pps;
        uplinkInfo.output_pps = data.output_pps;
        trafficError.value = null;

        const time = new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        trafficHistory.labels.push(time);
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

const resetTrafficState = () => {
    trafficHistory.labels = [];
    trafficHistory.input = [];
    trafficHistory.output = [];
    uplinkInfo.line_status = null;
    uplinkInfo.input_bps = 0;
    uplinkInfo.output_bps = 0;
    uplinkInfo.input_pps = 0;
    uplinkInfo.output_pps = 0;
    trafficError.value = null;
};

const startPolling = () => {
    if (!selectedInterface.value) return;

    clearInterval(pollTimer);
    liveTrafficEnabled.value = true;
    resetTrafficState();
    fetchTraffic();
    pollTimer = setInterval(fetchTraffic, 10000);
};

const stopPolling = () => {
    clearInterval(pollTimer);
    pollTimer = null;
    liveTrafficEnabled.value = false;
};

const toggleLiveTraffic = () => {
    if (liveTrafficEnabled.value) {
        stopPolling();
        return;
    }

    startPolling();
};

watch(selectedInterface, () => {
    if (liveTrafficEnabled.value) {
        startPolling();
    } else {
        resetTrafficState();
    }
});

onBeforeUnmount(() => clearInterval(pollTimer));

// ── Stored interface detail table ──────────────────────────────────────
const interfaceDetails = computed(() => props.interface_details ?? []);
const uplinkDetails = computed(() => interfaceDetails.value.filter((row) => row.interface_type === 'uplink'));
const gponDetails = computed(() => interfaceDetails.value.filter((row) => row.interface_type === 'gpon'));

// ── GPON card/slot filter ───────────────────────────────────────────────
const gponSlots = computed(() => {
    return [...new Set(gponDetails.value.map((r) => r.slot))].sort((a, b) => a - b);
});

const selectedGponSlot = ref(null);

watch(gponSlots, (slots) => {
    if (selectedGponSlot.value === null && slots.length > 0) {
        selectedGponSlot.value = slots[0];
    }
}, { immediate: true });

const filteredGponDetails = computed(() => {
    if (selectedGponSlot.value === null) return gponDetails.value;
    return gponDetails.value.filter((r) => r.slot === selectedGponSlot.value);
});

const gponCardLabel = (slot) => {
    // Derive prefix from actual interface name (supports gpon_1/2/x and gpon-olt_1/2/x)
    const sample = gponDetails.value.find((r) => r.slot === slot);
    if (sample) {
        return sample.interface.replace(/\/\d+$/, '');
    }
    return `gpon_1/${slot}`;
};

// ── VLAN state (reactive, updated after add) ────────────────────────────
const vlansByInterface = reactive({ ...props.vlans_by_interface });

// ── VLAN inline panel per uplink row ───────────────────────────────────
const vlanPanelInterface = ref(null);
const vlanPanelMode = ref('view'); // 'view' | 'add'

const panelVlans = computed(() => vlansByInterface[vlanPanelInterface.value] ?? []);

const openVlanPanel = (iface, mode) => {
    if (vlanPanelInterface.value === iface && vlanPanelMode.value === mode) {
        vlanPanelInterface.value = null;
        return;
    }

    vlanPanelInterface.value = iface;
    vlanPanelMode.value = mode;
    vlanForm.interface = iface;
    vlanForm.vlan_id = '';
    toast.show = false;
};

// ── Add VLAN form ───────────────────────────────────────────────────────
const vlanForm = reactive({ interface: '', vlan_id: '', submitting: false });

watch(vlanPanelInterface, (val) => { vlanForm.interface = val ?? ''; });

const submitVlan = async () => {
    const vlanId = parseInt(vlanForm.vlan_id);
    if (!vlanForm.interface || isNaN(vlanId) || vlanId < 1 || vlanId > 4094) return;

    vlanForm.submitting = true;
    toast.show = false;

    try {
        const { data } = await axios.post(
            route('smartolt.port-manager.vlan', props.olt.id),
            { interface: vlanForm.interface, vlan_id: vlanId },
        );

        toast.ok = data.ok;
        toast.message = data.message;
        toast.show = true;

        if (data.ok) {
            vlanForm.vlan_id = '';
            router.reload({ only: ['vlans_by_interface', 'interface_details'], onSuccess: (page) => {
                Object.assign(vlansByInterface, page.props.vlans_by_interface ?? {});
            }});
        }

        setTimeout(() => { toast.show = false; }, 5000);
    } catch (e) {
        toast.ok = false;
        toast.message = 'Request gagal: ' + (e.response?.data?.message ?? e.message);
        toast.show = true;
    } finally {
        vlanForm.submitting = false;
    }
};

// ── Refresh page ────────────────────────────────────────────────────────
const refreshing = ref(false);
const refreshingInterface = ref(null);
const doRefresh = () => {
    refreshing.value = true;
    router.post(route('smartolt.port-manager.refresh', props.olt.id), {}, {
        onFinish: () => { refreshing.value = false; },
    });
};

const refreshInterface = (row) => {
    if (row.interface_type !== 'gpon') return;

    refreshingInterface.value = row.interface;
    router.post(route('smartolt.port-manager.interface.refresh', props.olt.id), { interface: row.interface }, {
        preserveScroll: true,
        onFinish: () => { refreshingInterface.value = null; },
    });
};

// ── Helpers ─────────────────────────────────────────────────────────────
const formatBps = (bps) => {
    if (bps === null || bps === undefined || bps === '') return '-';

    return formatMbps(toMbps(bps));
};

const vlanBadgeColor = () => 'bg-sky-50 text-sky-700 ring-1 ring-sky-200';

const formatDate = (value) => {
    if (!value) return '-';

    return new Intl.DateTimeFormat('id-ID', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
};

const formatNumber = (value, suffix = '') => {
    if (value === null || value === undefined || value === '') return '-';

    return `${Number(value).toLocaleString('id-ID')}${suffix}`;
};

const formatPercent = (value) => {
    if (value === null || value === undefined || value === '') return '-';

    return `${Number(value).toLocaleString('id-ID')}%`;
};

const typeLabel = (type) => type === 'uplink' ? 'Uplink' : (type === 'gpon' ? 'GPON' : 'Interface');

const linkBadgeColor = (status) => {
    const s = String(status ?? '').toLowerCase();
    if (s === 'up') return 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200';
    if (s === 'down') return 'bg-red-50 text-red-700 ring-1 ring-red-200';
    return 'bg-slate-100 text-slate-600 ring-1 ring-slate-200';
};

const compactVlans = (vlans) => {
    if (!Array.isArray(vlans) || vlans.length === 0) return '-';

    return vlans.slice(0, 5).join(', ') + (vlans.length > 5 ? ` +${vlans.length - 5}` : '');
};

const onuSummary = (row) => {
    if (row.registered_onu_count === null || row.registered_onu_count === undefined) return '-';
    if (row.onu_capacity === null || row.onu_capacity === undefined) return `${row.registered_onu_count} ONU`;

    return `${row.registered_onu_count}/${row.onu_capacity} ONU`;
};
</script>

<template>
    <Head :title="`Port Manager — ${olt.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="text-lg font-semibold leading-tight sm:text-xl text-slate-800">
                        Port Manager — {{ olt.name }}
                    </h2>
                    <p class="mt-1 text-sm text-slate-500">
                        {{ olt.ip }}:{{ olt.snmp_port }} · {{ olt.capabilities.vendor_family }}
                    </p>
                </div>
                <div class="grid gap-2 [&>a>button]:w-full [&>button]:w-full sm:flex sm:flex-wrap sm:[&>a>button]:w-auto sm:[&>button]:w-auto">
                    <Link :href="route('smartolt.detail', olt.id)">
                        <SecondaryButton type="button">
                            <ArrowLeft class="mr-2 h-4 w-4" />
                            Detail OLT
                        </SecondaryButton>
                    </Link>

                    <PrimaryButton type="button" :disabled="refreshing" @click="doRefresh">
                        <RefreshCw class="mr-2 h-4 w-4" :class="{ 'animate-spin': refreshing }" />
                        Refresh Data
                    </PrimaryButton>
                </div>
            </div>
        </template>

        <div class="min-h-[60vh] pt-5 pb-16 sm:pt-6">
            <div class="w-full space-y-6 px-4 sm:px-6 lg:px-8">

                <!-- Flash messages (auto-dismiss after 4s) -->
                <Transition enter-active-class="transition duration-300 ease-out" enter-from-class="opacity-0 -translate-y-1"
                            leave-active-class="transition duration-500 ease-in" leave-to-class="opacity-0 -translate-y-1">
                    <div v-if="flash.success && flashVisible.success"
                         class="mb-5 flex items-center gap-3 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                        <span class="h-2 w-2 flex-shrink-0 rounded-full bg-emerald-500"></span>{{ flash.success }}
                    </div>
                </Transition>
                <Transition enter-active-class="transition duration-300 ease-out" enter-from-class="opacity-0 -translate-y-1"
                            leave-active-class="transition duration-500 ease-in" leave-to-class="opacity-0 -translate-y-1">
                    <div v-if="flash.error && flashVisible.error"
                         class="mb-5 flex items-center gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                        <span class="h-2 w-2 flex-shrink-0 rounded-full bg-red-500"></span>{{ flash.error }}
                    </div>
                </Transition>

                <!-- ══════════════════════════════════════
                     SECTION 1: Uplink Interface & Trafik
                     ══════════════════════════════════════ -->
                <div class="overflow-hidden rounded-lg border border-sky-200 bg-white shadow-sm shadow-sky-100/60">
                    <div class="flex flex-col gap-3 border-b border-slate-100 px-4 py-4 sm:px-5 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex items-center gap-3">
                            <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-sky-100 ring-1 ring-sky-200">
                                <Signal class="h-5 w-5 text-sky-600" />
                            </div>
                            <h3 class="font-semibold text-slate-900">Trafik Interface Uplink</h3>
                        </div>

                        <div v-if="uplink_interfaces.length > 0" class="flex flex-wrap items-center gap-2">
                            <label class="text-xs font-medium text-slate-500">Interface:</label>
                            <select v-model="selectedInterface"
                                    class="min-h-11 rounded-lg border border-slate-300 bg-white py-2.5 pl-3 pr-8 text-sm text-slate-700 shadow-sm focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-500/30 sm:min-h-0 sm:py-1.5">
                                <option v-for="iface in uplink_interfaces" :key="iface.interface" :value="iface.interface">
                                    {{ iface.interface }} ({{ iface.card_type }})
                                </option>
                            </select>
                            <SecondaryButton type="button" :disabled="!selectedInterface" @click="toggleLiveTraffic">
                                <RefreshCw class="mr-2 h-4 w-4" :class="{ 'animate-spin': liveTrafficEnabled }" />
                                {{ liveTrafficEnabled ? 'Stop Live' : 'Live Traffic' }}
                            </SecondaryButton>
                        </div>
                    </div>

                    <div v-if="uplink_interfaces.length === 0" class="px-5 py-10 text-center text-sm text-slate-500">
                        Tidak ada interface uplink terdeteksi. Pastikan card HUVQ/SMXA terpasang dan klik <strong class="text-slate-700">Refresh Data</strong>.
                    </div>

                    <div v-else class="p-5">
                        <!-- Status indicator -->
                        <div class="mb-5 flex flex-wrap items-center gap-4">
                            <div v-if="!liveTrafficEnabled && uplinkInfo.line_status === null"
                                 class="flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-4 py-2">
                                <div class="h-2.5 w-2.5 rounded-full bg-slate-400"></div>
                                <span class="text-sm text-slate-500">Live traffic standby</span>
                            </div>

                            <div v-else-if="uplinkInfo.line_status === null"
                                 class="flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-4 py-2">
                                <div class="h-2.5 w-2.5 animate-pulse rounded-full bg-slate-400"></div>
                                <span class="text-sm text-slate-500">Memuat status…</span>
                            </div>

                            <div v-else-if="uplinkInfo.line_status === 'up'"
                                 class="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2">
                                <CheckCircle2 class="h-5 w-5 text-emerald-600" />
                                <span class="font-semibold text-emerald-700">UP</span>
                            </div>

                            <div v-else-if="uplinkInfo.line_status === 'admin-down'"
                                 class="flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2">
                                <XCircle class="h-5 w-5 text-amber-600" />
                                <span class="font-semibold text-amber-700">ADMIN DOWN</span>
                            </div>

                            <div v-else
                                 class="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-2">
                                <XCircle class="h-5 w-5 text-red-600" />
                                <span class="font-semibold text-red-700">DOWN</span>
                            </div>

                            <!-- Live stats pills -->
                            <div v-if="uplinkInfo.line_status !== null && uplinkInfo.line_status !== 'admin-down' && uplinkInfo.line_status !== 'down'"
                                 class="flex flex-wrap gap-2">
                                <span class="inline-flex rounded-full px-3 py-1 text-xs font-medium ring-1 bg-sky-50 text-sky-700 ring-sky-200">
                                    ↓ RX {{ formatBps(uplinkInfo.input_bps) }}
                                </span>
                                <span class="inline-flex rounded-full px-3 py-1 text-xs font-medium ring-1 bg-emerald-50 text-emerald-700 ring-emerald-200">
                                    ↑ TX {{ formatBps(uplinkInfo.output_bps) }}
                                </span>
                                <span class="inline-flex rounded-full px-3 py-1 text-xs ring-1 bg-slate-100 text-slate-600 ring-slate-200">
                                    {{ uplinkInfo.input_pps.toLocaleString('id-ID') }} pps in /
                                    {{ uplinkInfo.output_pps.toLocaleString('id-ID') }} pps out
                                </span>
                            </div>
                        </div>

                        <!-- Error banner -->
                        <div v-if="trafficError" class="mb-4 flex items-center gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-xs text-red-700">
                            <span class="h-2 w-2 flex-shrink-0 rounded-full bg-red-500"></span>Gagal ambil data trafik: {{ trafficError }}
                        </div>

                        <!-- Chart -->
                        <VueApexCharts
                            type="area"
                            height="220"
                            :options="chartOptions"
                            :series="chartSeries"
                        />
                        <p v-if="liveTrafficEnabled" class="mt-1 text-right text-xs text-slate-400">Auto-refresh setiap 10 detik · 20-second average</p>
                    </div>
                </div>

                <!-- ══════════════════════════════════════
                     SECTION 2: Port Uplink
                     ══════════════════════════════════════ -->
                <div class="overflow-hidden rounded-lg border border-sky-200 bg-white shadow-sm shadow-sky-100/60">
                    <div class="flex flex-col gap-1 border-b border-slate-100 px-4 py-4 sm:px-5 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex items-center gap-3">
                            <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-sky-100 ring-1 ring-sky-200">
                                <Network class="h-5 w-5 text-sky-600" />
                            </div>
                            <h3 class="font-semibold text-slate-900">Port Uplink</h3>
                        </div>
                        <span class="text-xs text-slate-400">{{ uplinkDetails.length }} port tersimpan</span>
                    </div>

                    <!-- Toast for VLAN actions -->
                    <Transition enter-active-class="transition duration-200 ease-out" enter-from-class="opacity-0 -translate-y-1"
                                leave-active-class="transition duration-150 ease-in" leave-to-class="opacity-0 -translate-y-1">
                        <div v-if="toast.show"
                             class="mx-5 mt-4 flex items-start gap-3 rounded-lg border px-4 py-3 text-sm"
                             :class="toast.ok
                                 ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                                 : 'border-red-200 bg-red-50 text-red-700'">
                            <CheckCircle2 v-if="toast.ok" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" />
                            <XCircle v-else class="mt-0.5 h-4 w-4 shrink-0 text-red-600" />
                            {{ toast.message }}
                        </div>
                    </Transition>

                    <div v-if="uplinkDetails.length === 0" class="px-5 py-10 text-center text-sm text-slate-500">
                        Belum ada data port uplink tersimpan.
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="min-w-[980px] w-full text-sm">
                            <thead>
                                <tr class="border-b border-slate-100 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">
                                    <th class="px-4 py-3.5">Interface</th>
                                    <th class="px-4 py-3.5">Card</th>
                                    <th class="px-4 py-3.5">Admin</th>
                                    <th class="px-4 py-3.5">Link</th>
                                    <th class="px-4 py-3.5">Speed</th>
                                    <th class="px-4 py-3.5">VLAN</th>
                                    <th class="px-4 py-3.5">Optical</th>
                                    <th class="px-4 py-3.5">Module</th>
                                    <th class="px-4 py-3.5">Refresh</th>
                                    <th class="px-4 py-3.5">Aksi VLAN</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <template v-for="row in uplinkDetails" :key="row.interface">
                                    <tr class="transition-colors duration-150 hover:bg-slate-50">
                                        <td class="px-4 py-3 font-mono text-slate-900">{{ row.interface }}</td>
                                        <td class="px-4 py-3 text-slate-700">{{ row.card_type || '-' }}</td>
                                        <td class="px-4 py-3 text-slate-700">{{ row.admin_status || '-' }}</td>
                                        <td class="px-4 py-3">
                                            <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium" :class="linkBadgeColor(row.link_status)">
                                                {{ row.link_status || '-' }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-slate-700">
                                            {{ row.speed_mbps ? `${row.speed_mbps} Mbps` : '-' }}
                                            <span v-if="row.duplex" class="block text-xs text-slate-500">{{ row.duplex }}</span>
                                        </td>
                                        <td class="px-4 py-3 text-slate-700">
                                            <span class="block">Native {{ row.native_vlan ?? '-' }}</span>
                                            <span class="block max-w-48 truncate text-xs text-slate-500">Tagged {{ compactVlans(row.tagged_vlans) }}</span>
                                        </td>
                                        <td class="px-4 py-3 text-slate-700">
                                            <span class="block">Tx {{ formatNumber(row.tx_power_dbm, ' dBm') }}</span>
                                            <span class="block text-xs text-slate-500">Rx {{ formatNumber(row.rx_power_dbm, ' dBm') }}</span>
                                        </td>
                                        <td class="px-4 py-3 text-slate-700">
                                            <span class="block max-w-44 truncate">{{ row.optical_vendor_name || '-' }}</span>
                                            <span class="block max-w-44 truncate text-xs text-slate-500">{{ row.optical_vendor_pn || row.optical_vendor_sn || '-' }}</span>
                                            <span v-if="row.temperature_c !== null && row.temperature_c !== undefined" class="block text-xs text-slate-500">
                                                {{ formatNumber(row.temperature_c, '°C') }} · {{ formatNumber(row.supply_voltage_v, 'V') }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-xs text-slate-500">{{ formatDate(row.refreshed_at) }}</td>
                                        <td class="px-4 py-3">
                                            <div class="flex items-center gap-1.5">
                                                <!-- Lihat VLAN -->
                                                <button
                                                    type="button"
                                                    class="inline-flex min-h-10 items-center gap-1.5 rounded-md border px-2.5 text-xs font-medium shadow-sm transition"
                                                    :class="vlanPanelInterface === row.interface && vlanPanelMode === 'view'
                                                        ? 'border-sky-300 bg-sky-50 text-sky-700'
                                                        : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50 hover:text-slate-900'"
                                                    :title="`Lihat VLAN ${row.interface}`"
                                                    @click="openVlanPanel(row.interface, 'view')"
                                                >
                                                    <Eye class="h-3.5 w-3.5" />
                                                    VLAN
                                                </button>
                                                <!-- Tambah VLAN -->
                                                <button
                                                    type="button"
                                                    class="inline-flex min-h-10 items-center gap-1.5 rounded-md border px-2.5 text-xs font-medium shadow-sm transition"
                                                    :class="vlanPanelInterface === row.interface && vlanPanelMode === 'add'
                                                        ? 'border-sky-300 bg-sky-50 text-sky-700'
                                                        : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50 hover:text-slate-900'"
                                                    :title="`Tag VLAN ke ${row.interface}`"
                                                    @click="openVlanPanel(row.interface, 'add')"
                                                >
                                                    <Tag class="h-3.5 w-3.5" />
                                                    Tag
                                                </button>
                                            </div>
                                        </td>
                                    </tr>

                                    <!-- VLAN inline panel -->
                                    <Transition
                                        enter-active-class="transition duration-150 ease-out"
                                        enter-from-class="opacity-0"
                                        leave-active-class="transition duration-100 ease-in"
                                        leave-to-class="opacity-0"
                                    >
                                        <tr v-if="vlanPanelInterface === row.interface">
                                            <td colspan="10" class="border-t border-slate-100 bg-slate-50 px-5 py-4">
                                                <!-- View mode -->
                                                <div v-if="vlanPanelMode === 'view'">
                                                    <p class="mb-2 text-xs font-semibold text-sky-600">VLAN Tagged — {{ row.interface }}</p>
                                                    <div v-if="panelVlans.length === 0" class="text-sm text-slate-500">
                                                        Tidak ada VLAN tagged. Klik <strong class="text-slate-700">Refresh Data</strong> atau tag VLAN baru.
                                                    </div>
                                                    <div v-else class="flex flex-wrap gap-1.5">
                                                        <span
                                                            v-for="vlan in panelVlans"
                                                            :key="vlan"
                                                            class="inline-flex cursor-default items-center rounded-md px-2.5 py-1 text-xs font-semibold"
                                                            :class="vlanBadgeColor(vlan)"
                                                        >
                                                            VLAN {{ vlan }}
                                                        </span>
                                                    </div>
                                                </div>

                                                <!-- Add mode -->
                                                <div v-else>
                                                    <p class="mb-3 text-xs font-semibold text-sky-700">Tag VLAN ke {{ row.interface }}</p>
                                                    <div class="flex flex-wrap items-end gap-3">
                                                        <div>
                                                            <label class="mb-1.5 block text-xs font-medium text-slate-500">Nomor VLAN (1–4094)</label>
                                                            <input
                                                                v-model.number="vlanForm.vlan_id"
                                                                type="number"
                                                                min="1"
                                                                max="4094"
                                                                placeholder="contoh: 500"
                                                                class="w-36 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder-slate-400 shadow-sm focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-500/30"
                                                                @keydown.enter="submitVlan"
                                                            />
                                                        </div>
                                                        <PrimaryButton
                                                            type="button"
                                                            :disabled="vlanForm.submitting || !vlanForm.vlan_id"
                                                            @click="submitVlan"
                                                        >
                                                            <RefreshCw v-if="vlanForm.submitting" class="mr-2 h-4 w-4 animate-spin" />
                                                            <Plus v-else class="mr-2 h-4 w-4" />
                                                            {{ vlanForm.submitting ? 'Menerapkan…' : 'Terapkan' }}
                                                        </PrimaryButton>
                                                    </div>
                                                    <p class="mt-2 text-xs text-slate-500">
                                                        Script: <code class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-slate-700">configure terminal → vlan {id} → exit → interface {{ row.interface }} → switchport vlan {id} tag → end → write</code>
                                                    </p>
                                                </div>
                                            </td>
                                        </tr>
                                    </Transition>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- ══════════════════════════════════════
                     SECTION 3: GPON Port
                     ══════════════════════════════════════ -->
                <div class="overflow-hidden rounded-lg border border-sky-200 bg-white shadow-sm shadow-sky-100/60">
                    <div class="flex flex-col gap-1 border-b border-slate-100 px-4 py-4 sm:px-5 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex items-center gap-3">
                            <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-sky-100 ring-1 ring-sky-200">
                                <Wifi class="h-5 w-5 text-sky-600" />
                            </div>
                            <h3 class="font-semibold text-slate-900">GPON Port</h3>
                        </div>
                        <span class="text-xs text-slate-400">{{ gponDetails.length }} port terdeteksi</span>
                    </div>

                    <div v-if="gponDetails.length === 0" class="px-5 py-10 text-center text-sm text-slate-500">
                        Belum ada GPON port terdeteksi dari snapshot.
                    </div>

                    <template v-else>
                        <!-- Card/slot selector tabs -->
                        <div class="border-b border-slate-100 px-5">
                            <div class="flex gap-0 overflow-x-auto">
                                <button
                                    v-for="slot in gponSlots"
                                    :key="slot"
                                    type="button"
                                    class="flex shrink-0 items-center gap-1.5 border-b-2 px-4 py-3 text-sm font-medium transition-colors"
                                    :class="selectedGponSlot === slot
                                        ? 'border-sky-500 text-sky-700'
                                        : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700'"
                                    @click="selectedGponSlot = slot"
                                >
                                    <span class="font-mono">{{ gponCardLabel(slot) }}</span>
                                    <span class="rounded-full bg-slate-100 px-1.5 py-0.5 text-xs text-slate-400">
                                        {{ gponDetails.filter(r => r.slot === slot).length }}
                                    </span>
                                </button>
                            </div>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-[980px] w-full text-sm">
                                <thead>
                                    <tr class="border-b border-slate-100 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">
                                        <th class="px-4 py-3.5">Interface</th>
                                        <th class="px-4 py-3.5">Card</th>
                                        <th class="px-4 py-3.5">Admin</th>
                                        <th class="px-4 py-3.5">Link</th>
                                        <th class="px-4 py-3.5">ONU</th>
                                        <th class="px-4 py-3.5">Traffic</th>
                                        <th class="px-4 py-3.5">Throughput</th>
                                        <th class="px-4 py-3.5">Optical</th>
                                        <th class="px-4 py-3.5">Module</th>
                                        <th class="px-4 py-3.5">Peak</th>
                                        <th class="px-4 py-3.5">Aksi</th>
                                        <th class="px-4 py-3.5">Refresh</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <tr v-for="row in filteredGponDetails" :key="row.interface" class="transition-colors duration-150 hover:bg-slate-50">
                                        <td class="px-4 py-3 font-mono text-slate-900">{{ row.interface }}</td>
                                        <td class="px-4 py-3 text-slate-700">{{ row.card_type || '-' }}</td>
                                        <td class="px-4 py-3 text-slate-700">{{ row.admin_status || '-' }}</td>
                                        <td class="px-4 py-3">
                                            <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium" :class="linkBadgeColor(row.link_status)">
                                                {{ row.link_status || '-' }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-slate-700">{{ onuSummary(row) }}</td>
                                        <td class="px-4 py-3 text-slate-700">
                                            <span class="block">↓ {{ formatBps(row.input_bps) }}</span>
                                            <span class="block text-xs text-slate-500">↑ {{ formatBps(row.output_bps) }}</span>
                                        </td>
                                        <td class="px-4 py-3 text-slate-700">
                                            <span class="block">In {{ formatPercent(row.input_throughput_percent) }}</span>
                                            <span class="block text-xs text-slate-500">Out {{ formatPercent(row.output_throughput_percent) }}</span>
                                        </td>
                                        <td class="px-4 py-3 text-slate-700">
                                            <span class="block">Tx {{ formatNumber(row.tx_power_dbm, ' dBm') }}</span>
                                            <span class="block text-xs text-slate-500">Rx {{ formatNumber(row.rx_power_dbm, ' dBm') }}</span>
                                        </td>
                                        <td class="px-4 py-3 text-slate-700">
                                            <span class="block max-w-44 truncate">{{ row.optical_vendor_name || '-' }}</span>
                                            <span class="block max-w-44 truncate text-xs text-slate-500">{{ row.optical_vendor_pn || row.optical_vendor_sn || '-' }}</span>
                                            <span v-if="row.temperature_c !== null && row.temperature_c !== undefined" class="block text-xs text-slate-500">
                                                {{ formatNumber(row.temperature_c, '°C') }} · {{ formatNumber(row.supply_voltage_v, 'V') }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-slate-700">
                                            <span class="block">↓ {{ formatBps(row.input_peak_bps) }}</span>
                                            <span class="block text-xs text-slate-500">↑ {{ formatBps(row.output_peak_bps) }}</span>
                                        </td>
                                        <td class="px-4 py-3">
                                            <button
                                                type="button"
                                                class="inline-flex h-10 w-10 items-center justify-center rounded-md border border-slate-200 bg-white text-slate-600 shadow-sm transition hover:bg-slate-50 hover:text-slate-900 disabled:cursor-not-allowed disabled:opacity-50"
                                                :disabled="refreshingInterface === row.interface"
                                                :title="`Refresh ${row.interface}`"
                                                @click="refreshInterface(row)"
                                            >
                                                <RefreshCw class="h-4 w-4" :class="{ 'animate-spin': refreshingInterface === row.interface }" />
                                            </button>
                                        </td>
                                        <td class="px-4 py-3 text-xs text-slate-500">{{ formatDate(row.refreshed_at) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </template>
                </div>

            </div>
        </div>
    </AuthenticatedLayout>
</template>
