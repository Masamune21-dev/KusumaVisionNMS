<script setup>
import SecondaryButton from '@/Components/SecondaryButton.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import RxTrendCard from '@/Components/SmartOlt/RxTrendCard.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Activity, ArrowLeft, ChevronDown, Clock, Fingerprint, Gauge, ListChecks,
    RefreshCw, Settings, Signal, Terminal, Zap,
} from '@lucide/vue';
import { computed } from 'vue';
import VueApexCharts from 'vue3-apexcharts';

const props = defineProps({
    olt: { type: Object, required: true },
    slot: { type: Number, required: true },
    port: { type: Number, required: true },
    onu_id: { type: Number, required: true },
    interface: { type: String, required: true },
    meta: { type: Object, required: true },
    groups: { type: Object, required: true },
    raw: { type: String, default: '' },
    fetch_ok: { type: Boolean, default: false },
    fetch_error: { type: String, default: null },
    rx_history: { type: Array, default: () => [] },
    range: { type: String, default: '7d' },
});

const ifaceLabel = computed(() => props.interface);
const identity = computed(() => props.groups.identity ?? {});
const state = computed(() => props.groups.state ?? {});
const optical = computed(() => props.groups.optical ?? {});
const lastEvent = computed(() => props.groups.last_event ?? {});

const num = (v) => {
    if (v === null || v === undefined) return null;
    const m = String(v).match(/-?\d+(?:\.\d+)?/);
    return m ? parseFloat(m[0]) : null;
};
const clampPct = (v, min, max) => Math.max(0, Math.min(100, ((v - min) / (max - min)) * 100));

const rxVal = computed(() => num(optical.value.rx_power_dbm ?? optical.value.onu_rx_dbm));
const txVal = computed(() => num(optical.value.tx_power_dbm ?? optical.value.onu_tx_dbm));
const attUp = computed(() => num(optical.value.att_up_db));
const attDown = computed(() => num(optical.value.att_down_db));
const distance = computed(() => num(optical.value.distance_m));
const duration = computed(() => num(state.value.online_duration));

const online = computed(() => {
    const s = `${state.value.phase_state ?? ''} ${state.value.state ?? ''}`.toLowerCase();
    return /work|online|\bup\b|o5|los_off/.test(s) && !/offline|los\b|dying/.test(s);
});
const hasOptical = computed(() => rxVal.value !== null || txVal.value !== null);

const rxTone = (v) => {
    if (v === null) return { text: 'text-slate-400', ring: 'ring-slate-500/30', bg: 'bg-slate-800/60' };
    if (v <= -28 || v >= -8) return { text: 'text-red-300', ring: 'ring-red-500/30', bg: 'bg-red-500/15' };
    if (v <= -25 || v >= -10) return { text: 'text-amber-300', ring: 'ring-amber-500/30', bg: 'bg-amber-500/15' };
    return { text: 'text-emerald-300', ring: 'ring-emerald-500/30', bg: 'bg-emerald-500/15' };
};
const rxToneCur = computed(() => rxTone(rxVal.value));

// Warna zona (hex) untuk gauge speedometer RX.
const rxHex = (v) => {
    if (v === null) return '#64748b';
    if (v <= -28 || v >= -8) return '#ef4444';
    if (v <= -25 || v >= -10) return '#f59e0b';
    return '#10b981';
};
const rxZoneLabel = computed(() => {
    if (rxVal.value === null) return '—';
    if (rxVal.value <= -28 || rxVal.value >= -8) return 'Kritis';
    if (rxVal.value <= -25 || rxVal.value >= -10) return 'Warning';
    return 'Sehat';
});
// Petakan RX (-30…-5 dBm) ke 0…100% busur gauge.
const rxGaugePct = computed(() => (rxVal.value === null ? 0 : clampPct(rxVal.value, -30, -5)));
const rxGaugeSeries = computed(() => [Math.round(rxGaugePct.value * 10) / 10]);
const rxGaugeOptions = computed(() => ({
    chart: { type: 'radialBar', background: 'transparent', sparkline: { enabled: true }, animations: { enabled: false } },
    plotOptions: {
        radialBar: {
            startAngle: -135,
            endAngle: 135,
            hollow: { size: '68%' },
            track: { background: 'rgba(148,163,184,0.15)', strokeWidth: '100%' },
            dataLabels: {
                name: { show: true, offsetY: 30, color: '#94a3b8', fontSize: '12px' },
                value: {
                    show: true,
                    offsetY: -12,
                    color: rxHex(rxVal.value),
                    fontSize: '24px',
                    fontWeight: 700,
                    formatter: () => (rxVal.value === null ? '—' : rxVal.value.toFixed(2)),
                },
            },
        },
    },
    fill: { colors: [rxHex(rxVal.value)] },
    stroke: { lineCap: 'round' },
    labels: [`dBm · ${rxZoneLabel.value}`],
}));

const attMeta = (v) => {
    if (v === null) return { color: 'bg-slate-600', label: '—' };
    if (v <= 25) return { color: 'bg-emerald-500', label: `${v} dB` };
    if (v <= 28) return { color: 'bg-amber-500', label: `${v} dB` };
    return { color: 'bg-red-500', label: `${v} dB` };
};
const attUpMeta = computed(() => attMeta(attUp.value));
const attDownMeta = computed(() => attMeta(attDown.value));

const formatDuration = (secs) => {
    if (secs === null) return '—';
    let s = Math.floor(secs);
    const d = Math.floor(s / 86400); s %= 86400;
    const h = Math.floor(s / 3600); s %= 3600;
    const m = Math.floor(s / 60);
    const parts = [];
    if (d) parts.push(`${d}h`);
    if (h || d) parts.push(`${h}j`);
    parts.push(`${m}m`);
    return parts.join(' ');
};
const distanceLabel = computed(() => {
    if (distance.value === null) return '—';
    return distance.value >= 1000 ? `${(distance.value / 1000).toFixed(2)} km` : `${distance.value} m`;
});

const labels = {
    identity: {
        sn: 'Serial Number', name: 'Name', type: 'Type', auth_mode: 'Auth Mode',
        vendor_id: 'Vendor ID', equipment_id: 'Equipment ID', model_id: 'Model ID',
        hardware_version: 'Hardware Version', software_version: 'Software Version',
    },
    state: {
        state: 'State', admin_state: 'Admin State', phase_state: 'Phase State',
        channel: 'Channel', online_duration: 'Online Duration',
    },
    last_event: {
        last_down_cause: 'Last Down Cause', last_down_time: 'Last Down Time', last_up_time: 'Last Up Time',
    },
};
const detailSections = [
    { key: 'identity', title: 'Identitas', icon: Fingerprint },
    { key: 'state', title: 'Status', icon: Activity },
    { key: 'last_event', title: 'Last Event', icon: Clock },
];
const rowsFor = (key) => {
    const group = props.groups[key] ?? {};
    const labelMap = labels[key] ?? {};
    return Object.entries(group)
        .filter(([, value]) => value !== null && value !== undefined && value !== '')
        .map(([field, value]) => [labelMap[field] ?? field, value]);
};
const allRows = computed(() => Object.entries(props.groups.all ?? {}));

const refresh = () => router.reload({ preserveScroll: true });
</script>

<template>
    <Head :title="`Detail ONU ${ifaceLabel}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h2 class="flex items-center gap-2 text-lg font-semibold leading-tight sm:text-xl text-white">
                        <Settings class="h-5 w-5 text-cyan-400" />
                        Detail ONU: {{ ifaceLabel }}
                    </h2>
                    <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500">
                        <span>{{ olt.name }}</span>
                        <span>· OLT {{ olt.ip }}</span>
                        <span v-if="meta.sn">· SN <span class="font-mono text-slate-400">{{ meta.sn }}</span></span>
                        <span class="inline-flex items-center gap-1 rounded-full bg-sky-500/15 px-2 py-0.5 text-cyan-300 ring-1 ring-cyan-500/30">
                            CLI (show gpon onu detail-info)
                        </span>
                    </p>
                </div>
                <div class="grid gap-2 [&>a>button]:w-full [&>button]:w-full sm:flex sm:flex-wrap sm:[&>a>button]:w-auto sm:[&>button]:w-auto">
                    <Link :href="route('smartolt.port-onus', [olt.id, slot, port])">
                        <SecondaryButton type="button">
                            <ArrowLeft class="mr-2 h-4 w-4" />
                            Kembali ke Port
                        </SecondaryButton>
                    </Link>
                    <PrimaryButton type="button" @click="refresh">
                        <RefreshCw class="mr-2 h-4 w-4" />
                        Refresh
                    </PrimaryButton>
                </div>
            </div>
        </template>

        <div class="min-h-[60vh] pt-5 pb-16 sm:pt-8">
            <div class="w-full space-y-5 px-4 sm:px-6 lg:px-8">
                <div v-if="fetch_error" class="rounded-lg border border-red-500/30 bg-red-500/15 px-4 py-3 text-sm text-red-300">
                    Gagal baca detail-info live: {{ fetch_error }}
                </div>

                <!-- Hero stat cards -->
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <!-- Status -->
                    <div class="rounded-lg border border-white/10 bg-slate-900/40 p-5 shadow-sm shadow-black/30 backdrop-blur-xl">
                        <div class="flex items-center justify-between">
                            <p class="text-xs font-medium uppercase tracking-wider text-slate-500">Status</p>
                            <span class="h-2.5 w-2.5 rounded-full" :class="online ? 'bg-emerald-500 shadow-[0_0_8px] shadow-emerald-500/60' : 'bg-slate-500'"></span>
                        </div>
                        <p class="mt-3 text-2xl font-bold" :class="online ? 'text-emerald-400' : 'text-slate-400'">
                            {{ online ? 'Online' : 'Offline' }}
                        </p>
                        <p class="mt-1 text-xs text-slate-500">{{ state.phase_state || state.state || '—' }}</p>
                    </div>
                    <!-- RX power -->
                    <div class="rounded-lg border border-white/10 bg-slate-900/40 p-5 shadow-sm shadow-black/30 backdrop-blur-xl">
                        <div class="flex items-center justify-between">
                            <p class="text-xs font-medium uppercase tracking-wider text-slate-500">RX Power ONU</p>
                            <Signal class="h-4 w-4 text-slate-500" />
                        </div>
                        <p class="mt-3 text-2xl font-bold" :class="rxToneCur.text">
                            {{ rxVal !== null ? rxVal.toFixed(2) : '—' }}<span v-if="rxVal !== null" class="ml-1 text-sm font-medium text-slate-500">dBm</span>
                        </p>
                        <p class="mt-1 text-xs text-slate-500">Sinyal terima ONU</p>
                    </div>
                    <!-- Distance -->
                    <div class="rounded-lg border border-white/10 bg-slate-900/40 p-5 shadow-sm shadow-black/30 backdrop-blur-xl">
                        <div class="flex items-center justify-between">
                            <p class="text-xs font-medium uppercase tracking-wider text-slate-500">Jarak</p>
                            <Gauge class="h-4 w-4 text-slate-500" />
                        </div>
                        <p class="mt-3 text-2xl font-bold text-white">{{ distanceLabel }}</p>
                        <p class="mt-1 text-xs text-slate-500">Jarak fiber ke OLT</p>
                    </div>
                    <!-- Online duration -->
                    <div class="rounded-lg border border-white/10 bg-slate-900/40 p-5 shadow-sm shadow-black/30 backdrop-blur-xl">
                        <div class="flex items-center justify-between">
                            <p class="text-xs font-medium uppercase tracking-wider text-slate-500">Online Duration</p>
                            <Clock class="h-4 w-4 text-slate-500" />
                        </div>
                        <p class="mt-3 text-2xl font-bold text-white">{{ formatDuration(duration) }}</p>
                        <p class="mt-1 text-xs text-slate-500">{{ identity.name || meta.name || '—' }}</p>
                    </div>
                </div>

                <!-- Optical (gauge) + tren RX power — 2 kolom -->
                <div :class="hasOptical ? 'grid gap-5 xl:grid-cols-2' : ''">
                    <!-- Optical visualization -->
                    <section v-if="hasOptical" class="flex flex-col overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
                        <header class="flex items-center gap-2 border-b border-white/10 px-4 py-3 sm:px-6">
                            <Signal class="h-4 w-4 text-cyan-400" />
                            <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-200">Optical</h3>
                        </header>
                        <div class="flex flex-1 flex-col p-5 sm:p-6">
                            <!-- RX speedometer -->
                            <div class="flex flex-col items-center">
                                <VueApexCharts type="radialBar" height="220" width="100%" :options="rxGaugeOptions" :series="rxGaugeSeries" />
                                <div class="mt-3 flex flex-wrap items-center justify-center gap-2 text-xs">
                                    <span class="rounded-full px-2 py-0.5 font-medium ring-1" :class="[rxToneCur.bg, rxToneCur.text, rxToneCur.ring]">RX Power</span>
                                    <span class="text-slate-500">Zona aman <span class="text-emerald-400">-25…-10 dBm</span></span>
                                </div>
                            </div>

                            <!-- TX + attenuation -->
                            <div class="mt-5 space-y-4">
                                <div class="flex items-center justify-between rounded-lg border border-white/10 bg-slate-950/40 px-4 py-3">
                                    <span class="flex items-center gap-2 text-sm text-slate-400"><Zap class="h-4 w-4 text-cyan-400" /> TX Power ONU</span>
                                    <span class="text-base font-semibold text-slate-100">{{ txVal !== null ? txVal.toFixed(2) + ' dBm' : '—' }}</span>
                                </div>
                                <div>
                                    <div class="mb-1 flex items-center justify-between text-xs">
                                        <span class="text-slate-500">Atenuasi Up</span>
                                        <span class="font-medium text-slate-300">{{ attUpMeta.label }}</span>
                                    </div>
                                    <div class="h-2 overflow-hidden rounded-full bg-slate-800">
                                        <div class="h-full rounded-full transition-all" :class="attUpMeta.color" :style="{ width: clampPct(attUp ?? 0, 0, 32) + '%' }"></div>
                                    </div>
                                </div>
                                <div>
                                    <div class="mb-1 flex items-center justify-between text-xs">
                                        <span class="text-slate-500">Atenuasi Down</span>
                                        <span class="font-medium text-slate-300">{{ attDownMeta.label }}</span>
                                    </div>
                                    <div class="h-2 overflow-hidden rounded-full bg-slate-800">
                                        <div class="h-full rounded-full transition-all" :class="attDownMeta.color" :style="{ width: clampPct(attDown ?? 0, 0, 32) + '%' }"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- RX power trend (historis) -->
                    <RxTrendCard :history="rx_history" :range="range" />
                </div>

                <!-- Detail group cards -->
                <div class="grid gap-5 lg:grid-cols-3">
                    <section
                        v-for="s in detailSections"
                        :key="s.key"
                        class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl"
                    >
                        <header class="flex items-center gap-2 border-b border-white/10 px-4 py-3 sm:px-6">
                            <component :is="s.icon" class="h-4 w-4 text-cyan-400" />
                            <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-200">{{ s.title }}</h3>
                        </header>
                        <dl class="divide-y divide-white/5 px-4 py-2 text-sm sm:px-6">
                            <div v-for="[label, value] in rowsFor(s.key)" :key="label" class="flex items-start justify-between gap-4 py-2">
                                <dt class="text-slate-500">{{ label }}</dt>
                                <dd class="break-all text-right font-medium text-slate-200">{{ value }}</dd>
                            </div>
                            <p v-if="!rowsFor(s.key).length" class="py-3 text-xs text-slate-500">Tidak ada data.</p>
                        </dl>
                    </section>
                </div>

                <!-- All fields -->
                <details class="group overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
                    <summary class="flex cursor-pointer items-center justify-between px-4 py-3 sm:px-6">
                        <span class="flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-slate-200">
                            <ListChecks class="h-4 w-4 text-cyan-400" /> Semua Field ({{ allRows.length }})
                        </span>
                        <ChevronDown class="h-4 w-4 text-slate-400 transition-transform group-open:rotate-180" />
                    </summary>
                    <dl class="grid gap-x-6 gap-y-1 border-t border-white/10 px-4 py-3 text-sm sm:grid-cols-2 sm:px-6">
                        <div v-for="[key, value] in allRows" :key="key" class="flex items-start justify-between gap-4 py-1">
                            <dt class="font-mono text-xs text-slate-500">{{ key }}</dt>
                            <dd class="break-all text-right text-slate-300">{{ value }}</dd>
                        </div>
                        <p v-if="!allRows.length" class="text-xs text-slate-500">Tidak ada data.</p>
                    </dl>
                </details>

                <!-- Raw -->
                <details class="group overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
                    <summary class="flex cursor-pointer items-center justify-between px-4 py-3 sm:px-6">
                        <span class="flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-slate-200">
                            <Terminal class="h-4 w-4 text-cyan-400" /> Raw output
                        </span>
                        <ChevronDown class="h-4 w-4 text-slate-400 transition-transform group-open:rotate-180" />
                    </summary>
                    <pre class="max-h-[480px] overflow-auto border-t border-white/10 bg-slate-950/70 px-4 py-3 font-mono text-xs leading-relaxed text-emerald-300/90">{{ raw || '(kosong)' }}</pre>
                </details>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
