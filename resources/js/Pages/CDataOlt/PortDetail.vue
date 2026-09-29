<script setup>
import ConfirmModal from '@/Components/ConfirmModal.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useConfirm } from '@/Composables/useConfirm';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { formatBytes, formatCount, formatKbps } from '@/lib/cdataPorts';
import { formatDateTime } from '@/lib/datetime';
import { Deferred, Head, Link, router, useForm } from '@inertiajs/vue3';
import { Activity, ArrowLeft, Cable, ListTree, Network, Plus, RefreshCw, Save, Tag, Users, Zap } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n({ useScope: 'global' });
const { confirmState, confirm, handleConfirm, handleCancel } = useConfirm();

const props = defineProps({
    olt: { type: Object, required: true },
    kind: { type: String, required: true }, // gpon | epon | ge | xge
    slot: { type: Number, required: true },
    port: { type: Number, required: true },
    name: { type: String, required: true },
    port_label: { type: String, default: null },
    onu_summary: { type: Object, default: null },
    can_write: { type: Boolean, default: false },
    // Deferred: undefined sampai CLI selesai dibaca.
    detail: { type: Object, default: null },
});

const isGpon = computed(() => props.kind === 'gpon');
const isPon = computed(() => props.kind === 'gpon' || props.kind === 'epon');
const isUplink = computed(() => !isPon.value);
// Port GPON otomatis ikut VLAN baru; GE/XGE/EPON di-tag manual (Trunk: vlan trunk, Hybrid: vlan hybrid).
const isTaggableKind = computed(() => ['ge', 'xge', 'epon'].includes(props.kind));
const info = computed(() => props.detail?.info ?? {});
const ddm = computed(() => props.detail?.ddm ?? null);
const stats = computed(() => props.detail?.stats ?? null);
const canSaveConfig = computed(() => props.can_write && Boolean(props.olt.capabilities?.supports_config_save));
const isTaggableMode = computed(() => ['trunk', 'hybrid'].includes(String(info.value.mode ?? '').toLowerCase()));
const isCopper = computed(() => String(info.value.media_type ?? '').toLowerCase() === 'copper');

const fmt = (v) => formatDateTime(v);
const dash = (v) => (v === null || v === undefined || v === '' ? '—' : v);
const num = (v, suffix = '', digits = 2) =>
    v === null || v === undefined ? '—' : `${Number(v).toLocaleString('id-ID', { minimumFractionDigits: digits, maximumFractionDigits: digits })}${suffix}`;

const speedLabel = computed(() => {
    if (!info.value.link_up || !info.value.speed_mbps) return '—';
    const s = info.value.speed_mbps;
    const speed = s >= 1000 ? `${s / 1000} Gbps` : `${s} Mbps`;
    return info.value.duplex ? `${speed} · ${info.value.duplex}` : speed;
});

const adminPill = (v) => (String(v ?? '').toLowerCase() === 'enable' ? 'kv-pill-success' : 'kv-pill-muted');

// ── DDM vs ambang OLT ─────────────────────────────────────────────────
// −40 dBm = pembacaan kosong (tanpa cahaya), bukan redaman sungguhan.
const NO_READING_DBM = -40;
const isNoReading = (v) => v !== null && v !== undefined && Number(v) <= NO_READING_DBM;

const thresholdState = (value, warnKey, alarmKey) => {
    if (value === null || value === undefined) return null;
    const th = ddm.value?.thresholds ?? {};
    const alarm = th[alarmKey];
    const warn = th[warnKey];
    if (alarm && (value < alarm[0] || value > alarm[1])) return { level: 'alarm', range: alarm };
    if (warn && (value < warn[0] || value > warn[1])) return { level: 'warn', range: warn };
    return alarm || warn ? { level: 'ok' } : null;
};
const toneClass = (s) => {
    if (!s) return 'text-slate-100';
    if (s.level === 'alarm') return 'text-rose-300';
    if (s.level === 'warn') return 'text-amber-300';
    return 'text-emerald-300';
};
const toneTitle = (s) => {
    if (s?.level === 'alarm') return t('cdataport.threshold_alarm', { range: `[${s.range.join(', ')}]` });
    if (s?.level === 'warn') return t('cdataport.threshold_warn', { range: `[${s.range.join(', ')}]` });
    return undefined;
};
const txState = computed(() => thresholdState(ddm.value?.tx_power_dbm, 'tx_warn', 'tx_alarm'));
const tempState = computed(() => thresholdState(ddm.value?.temperature_c, 'temp_warn', 'temp_alarm'));
const voltState = computed(() => thresholdState(ddm.value?.voltage_v, 'voltage_warn', 'voltage_alarm'));
const biasState = computed(() => thresholdState(ddm.value?.bias_ma, 'bias_warn', 'bias_alarm'));

const counterRows = computed(() => {
    const s = stats.value ?? {};
    return [
        { key: 'octets', label: t('cdataport.octets'), rx: formatBytes(s.rx_octets), tx: formatBytes(s.tx_octets) },
        { key: 'packets', label: t('cdataport.packets'), rx: formatCount(s.rx_packets), tx: formatCount(s.tx_packets) },
        { key: 'discards', label: t('cdataport.discards'), rx: formatCount(s.rx_discards), tx: formatCount(s.tx_discards) },
        { key: 'errors', label: t('cdataport.errors'), rx: formatCount(s.rx_errors), tx: formatCount(s.tx_errors) },
    ];
});

const formatVlan = (v) => String(v).replace('-', '–');
const isRange = (v) => /^\d+-\d+$/.test(String(v));
const taggedCount = computed(() => (info.value.tagged ?? []).reduce((n, v) => {
    const m = String(v).match(/^(\d+)-(\d+)$/);
    return n + (m ? Number(m[2]) - Number(m[1]) + 1 : 1);
}, 0));

// ── Aksi ──────────────────────────────────────────────────────────────
const reloading = ref(false);
const reload = () => router.reload({
    only: ['detail'],
    onStart: () => { reloading.value = true; },
    onFinish: () => { reloading.value = false; },
});

const saving = ref(false);
const saveConfig = async () => {
    const ok = await confirm({
        title: t('smartolt.confirm_save_title'),
        message: t('smartolt.confirm_save_msg', { name: props.olt.name }),
        confirmLabel: t('common.save'),
        variant: 'info',
    });
    if (!ok) return;
    router.post(route('cdata-olt.config.save', props.olt.id), {}, {
        preserveScroll: true,
        onStart: () => { saving.value = true; },
        onFinish: () => { saving.value = false; },
    });
};

const tagForm = useForm({ vlan_id: '' });
const submitTag = async () => {
    const ok = await confirm({
        title: t('cdataport.confirm_title', { vlan: tagForm.vlan_id, port: props.name }),
        message: t('cdataport.confirm_msg', { port: props.name }),
        confirmLabel: t('cdataport.confirm_label'),
        variant: 'warning',
    });
    if (!ok) return;
    tagForm.post(route('cdata-olt.port.vlan', [props.olt.id, props.kind, props.slot, props.port]), {
        preserveScroll: true,
        onSuccess: () => tagForm.reset(),
    });
};
</script>

<template>
    <Head :title="`Port ${name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex items-center gap-3">
                    <span class="kv-circle-cyan !h-10 !w-10 flex-shrink-0"><Cable class="h-5 w-5" /></span>
                    <div>
                        <h1 class="font-mono text-xl font-semibold text-slate-100">{{ name }}</h1>
                        <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-400">
                            <span :class="isPon ? 'kv-pill-info' : 'kv-pill-muted'">{{ kind.toUpperCase() }}</span>
                            <span>{{ olt.name }}</span>
                            <span v-if="olt.model" class="font-mono text-xs">· {{ olt.model }}</span>
                            <span v-if="port_label">· {{ port_label }}</span>
                        </p>
                    </div>
                </div>

                <div class="grid gap-2 [&>a>button]:w-full [&>button]:w-full sm:flex sm:flex-wrap sm:[&>a>button]:w-auto sm:[&>button]:w-auto">
                    <Link :href="route('cdata-olt.detail', olt.id)">
                        <SecondaryButton type="button">
                            <ArrowLeft class="mr-2 h-4 w-4" />
                            {{ $t('common.back') }}
                        </SecondaryButton>
                    </Link>
                    <Link v-if="isPon" :href="route('cdata-olt.port-onus', [olt.id, slot, port])">
                        <SecondaryButton type="button">
                            <Users class="mr-2 h-4 w-4" />
                            {{ $t('cdataport.view_onu') }}
                        </SecondaryButton>
                    </Link>
                    <Link v-if="olt.capabilities?.supports_cli_vlan" :href="route('cdata-olt.vlans', olt.id)">
                        <SecondaryButton type="button">
                            <Network class="mr-2 h-4 w-4" />
                            {{ $t('cdataport.vlan_page') }}
                        </SecondaryButton>
                    </Link>
                    <SecondaryButton v-if="canSaveConfig" type="button" :disabled="saving" @click="saveConfig">
                        <Save class="mr-2 h-4 w-4" :class="{ 'animate-pulse': saving }" />
                        {{ $t('cdatavlan.save_config') }}
                    </SecondaryButton>
                    <PrimaryButton type="button" :disabled="reloading" @click="reload">
                        <RefreshCw class="mr-2 h-4 w-4" :class="{ 'animate-spin': reloading }" />
                        {{ $t('cdatavlan.reload') }}
                    </PrimaryButton>
                </div>
            </div>
        </template>

        <div class="min-h-[60vh] pt-5 pb-16 sm:pt-8">
            <div class="w-full space-y-6 px-4 sm:px-6 lg:px-8">
                <Deferred data="detail">
                    <template #fallback>
                        <div class="kv-glass-panel flex items-center justify-center gap-3 px-6 py-16 text-sm text-slate-400">
                            <RefreshCw class="h-4 w-4 animate-spin text-cyan-400" />
                            {{ $t('cdataport.loading') }}
                        </div>
                    </template>

                    <div class="space-y-6">
                        <div
                            v-if="detail && !detail.ok"
                            class="rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-200"
                            role="alert"
                        >
                            <p>{{ $t('cdatavlan.error_live', { error: detail.error }) }}</p>
                            <p v-if="detail.stale" class="mt-1 text-xs text-rose-200/80">{{ $t('cdatavlan.stale_note', { date: fmt(detail.fetched_at) }) }}</p>
                        </div>

                        <template v-if="detail && detail.info">
                            <div class="grid gap-6 lg:grid-cols-2">
                                <!-- Status port -->
                                <div class="kv-glass-panel">
                                    <div class="flex items-center gap-3 border-b border-white/10 px-4 py-4 sm:px-6">
                                        <Network class="h-5 w-5 text-cyan-400" />
                                        <h3 class="text-base font-semibold text-slate-100">{{ $t('cdataport.status_title') }}</h3>
                                    </div>
                                    <dl class="grid grid-cols-2 gap-px bg-white/5">
                                        <div class="bg-canvas-3/40 px-4 py-3">
                                            <dt class="text-xs uppercase tracking-wide text-slate-500">{{ $t('cdataport.admin') }}</dt>
                                            <dd class="mt-1"><span :class="adminPill(info.admin_status)">{{ dash(info.admin_status) }}</span></dd>
                                        </div>
                                        <div class="bg-canvas-3/40 px-4 py-3">
                                            <dt class="text-xs uppercase tracking-wide text-slate-500">{{ $t('cdataport.link') }}</dt>
                                            <dd class="mt-1"><span :class="info.link_up ? 'kv-pill-success' : 'kv-pill-danger'">{{ dash(info.link_status) }}</span></dd>
                                        </div>
                                        <template v-if="isUplink">
                                            <div class="bg-canvas-3/40 px-4 py-3">
                                                <dt class="text-xs uppercase tracking-wide text-slate-500">{{ $t('cdataport.speed') }}</dt>
                                                <dd class="mt-1 text-sm text-slate-100">{{ speedLabel }}</dd>
                                            </div>
                                            <div class="bg-canvas-3/40 px-4 py-3">
                                                <dt class="text-xs uppercase tracking-wide text-slate-500">{{ $t('cdataport.negotiation') }}</dt>
                                                <dd class="mt-1 text-sm text-slate-100">{{ dash(info.negotiation) }}</dd>
                                            </div>
                                            <div class="bg-canvas-3/40 px-4 py-3">
                                                <dt class="text-xs uppercase tracking-wide text-slate-500">{{ $t('cdataport.media') }}</dt>
                                                <dd class="mt-1 text-sm text-slate-100">{{ dash(info.media_type) }}</dd>
                                            </div>
                                        </template>
                                        <div v-else class="bg-canvas-3/40 px-4 py-3">
                                            <dt class="text-xs uppercase tracking-wide text-slate-500">{{ $t('cdataport.optical_status') }}</dt>
                                            <dd class="mt-1 text-sm text-slate-100">{{ dash(info.optical_status) }}</dd>
                                        </div>
                                        <div class="bg-canvas-3/40 px-4 py-3">
                                            <dt class="text-xs uppercase tracking-wide text-slate-500">{{ $t('cdataport.mode') }}</dt>
                                            <dd class="mt-1 text-sm text-slate-100">{{ dash(info.mode) }}</dd>
                                        </div>
                                        <div class="bg-canvas-3/40 px-4 py-3">
                                            <dt class="text-xs uppercase tracking-wide text-slate-500">{{ $t('cdataport.native_vlan') }}</dt>
                                            <dd class="mt-1 text-sm tabular-nums text-slate-100">{{ dash(info.native_vlan) }}</dd>
                                        </div>
                                        <div class="bg-canvas-3/40 px-4 py-3">
                                            <dt class="text-xs uppercase tracking-wide text-slate-500">{{ $t('cdataport.mtu') }}</dt>
                                            <dd class="mt-1 text-sm tabular-nums text-slate-100">{{ dash(info.mtu) }}</dd>
                                        </div>
                                        <div class="bg-canvas-3/40 px-4 py-3">
                                            <dt class="text-xs uppercase tracking-wide text-slate-500">{{ $t('cdataport.flow_control') }}</dt>
                                            <dd class="mt-1 text-sm text-slate-100">{{ dash(info.flow_control) }}</dd>
                                        </div>
                                        <div v-if="info.link_uptime" class="bg-canvas-3/40 px-4 py-3">
                                            <dt class="text-xs uppercase tracking-wide text-slate-500">{{ $t('cdataport.link_uptime') }}</dt>
                                            <dd class="mt-1 text-sm tabular-nums text-slate-100">{{ info.link_uptime }}</dd>
                                        </div>
                                        <div v-if="info.last_down_cause" class="bg-canvas-3/40 px-4 py-3">
                                            <dt class="text-xs uppercase tracking-wide text-slate-500">{{ $t('cdataport.last_down_cause') }}</dt>
                                            <dd class="mt-1 text-sm text-slate-100">{{ info.last_down_cause }}</dd>
                                        </div>
                                        <div class="col-span-2 bg-canvas-3/40 px-4 py-3">
                                            <dt class="text-xs uppercase tracking-wide text-slate-500">{{ $t('cdataport.description') }}</dt>
                                            <dd class="mt-1 break-words text-sm text-slate-100">{{ dash(info.description) }}</dd>
                                        </div>
                                        <div v-if="port_label" class="col-span-2 bg-canvas-3/40 px-4 py-3">
                                            <dt class="text-xs uppercase tracking-wide text-slate-500">{{ $t('cdataport.label') }}</dt>
                                            <dd class="mt-1 break-words text-sm text-slate-100">{{ port_label }}</dd>
                                        </div>
                                        <div class="col-span-2 bg-canvas-3/40 px-4 py-3">
                                            <dt class="text-xs uppercase tracking-wide text-slate-500">{{ $t('portdetail.updated') }}</dt>
                                            <dd class="mt-1 text-sm text-slate-300">{{ fmt(detail.fetched_at) }}</dd>
                                        </div>
                                    </dl>
                                </div>

                                <!-- Optik / SFP -->
                                <div class="kv-glass-panel">
                                    <div class="flex items-center gap-3 border-b border-white/10 px-4 py-4 sm:px-6">
                                        <Zap class="h-5 w-5 text-cyan-400" />
                                        <h3 class="text-base font-semibold text-slate-100">{{ $t('cdataport.optical_title') }}</h3>
                                    </div>
                                    <div v-if="ddm?.present" class="p-4 sm:p-6">
                                        <div class="grid grid-cols-2 gap-4">
                                            <div class="rounded-lg border border-white/10 bg-canvas-3/40 p-4 text-center">
                                                <p class="text-xs uppercase tracking-wide text-slate-500">{{ $t('cdataport.rx_power') }}</p>
                                                <p v-if="isNoReading(ddm.rx_power_dbm)" class="mt-1 text-sm text-slate-400">{{ $t('cdataport.no_reading') }}</p>
                                                <p v-else class="mt-1 text-2xl font-semibold tabular-nums text-slate-100">
                                                    {{ num(ddm.rx_power_dbm) }}<span class="text-sm font-normal text-slate-500"> dBm</span>
                                                </p>
                                            </div>
                                            <div class="rounded-lg border border-white/10 bg-canvas-3/40 p-4 text-center">
                                                <p class="text-xs uppercase tracking-wide text-slate-500">{{ $t('cdataport.tx_power') }}</p>
                                                <p class="mt-1 text-2xl font-semibold tabular-nums" :class="toneClass(txState)" :title="toneTitle(txState)">
                                                    {{ num(ddm.tx_power_dbm) }}<span class="text-sm font-normal text-slate-500"> dBm</span>
                                                </p>
                                            </div>
                                        </div>
                                        <p v-if="isPon" class="mt-3 text-xs text-slate-500">{{ $t('cdataport.rx_pon_note') }}</p>
                                        <dl class="mt-4 divide-y divide-white/5 text-sm">
                                            <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">{{ $t('cdataport.vendor') }}</dt><dd class="text-right text-slate-100">{{ dash(ddm.vendor_name) }}</dd></div>
                                            <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">{{ $t('cdataport.pn_sn') }}</dt><dd class="text-right font-mono text-xs text-slate-300">{{ dash(ddm.vendor_pn) }} / {{ dash(ddm.serial) }}</dd></div>
                                            <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">{{ $t('cdataport.type_wavelength') }}</dt><dd class="text-right text-slate-300">{{ dash(ddm.module_type) }} · {{ ddm.wavelength_nm ? `${ddm.wavelength_nm} nm` : '—' }}</dd></div>
                                            <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">{{ $t('cdataport.connector_distance') }}</dt><dd class="text-right text-slate-300">{{ dash(ddm.connector) }} · {{ dash(ddm.distance) }}</dd></div>
                                            <div class="flex justify-between gap-4 py-2">
                                                <dt class="text-slate-500">{{ $t('cdataport.bias_temp_volt') }}</dt>
                                                <dd class="text-right tabular-nums">
                                                    <span :class="toneClass(biasState)" :title="toneTitle(biasState)">{{ num(ddm.bias_ma, ' mA') }}</span>
                                                    <span class="text-slate-500"> · </span>
                                                    <span :class="toneClass(tempState)" :title="toneTitle(tempState)">{{ num(ddm.temperature_c, '°C') }}</span>
                                                    <span class="text-slate-500"> · </span>
                                                    <span :class="toneClass(voltState)" :title="toneTitle(voltState)">{{ num(ddm.voltage_v, ' V') }}</span>
                                                </dd>
                                            </div>
                                            <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">{{ $t('cdataport.manufactured') }}</dt><dd class="text-right font-mono text-xs text-slate-300">{{ dash(ddm.manufacture_date) }}</dd></div>
                                        </dl>
                                        <p v-if="txState?.level === 'warn' || txState?.level === 'alarm'" class="mt-3 text-xs" :class="toneClass(txState)">
                                            {{ $t('cdataport.tx_power') }}: {{ toneTitle(txState) }}
                                        </p>
                                    </div>
                                    <!-- GE RJ45 (FD1601S/FD1602S) tak punya DDM: OLT menjawab "Transceiver parm error!". -->
                                    <p v-else-if="isCopper" class="px-5 py-10 text-center text-sm text-slate-500">{{ $t('cdataport.sfp_copper') }}</p>
                                    <p v-else class="px-5 py-10 text-center text-sm text-slate-500">
                                        {{ ddm && ddm.present === false ? $t('cdataport.sfp_absent') : $t('cdataport.sfp_unknown') }}
                                    </p>
                                </div>
                            </div>

                            <!-- Trafik -->
                            <div v-if="stats" class="kv-glass-panel">
                                <div class="flex items-center gap-3 border-b border-white/10 px-4 py-4 sm:px-6">
                                    <Activity class="h-5 w-5 text-cyan-400" />
                                    <div>
                                        <h3 class="text-base font-semibold text-slate-100">{{ $t('cdataport.traffic_title') }}</h3>
                                        <p class="text-xs text-slate-400">{{ $t('cdataport.traffic_sub') }}</p>
                                    </div>
                                </div>
                                <div class="p-4 sm:p-6">
                                    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                                        <div class="rounded-lg border border-white/10 bg-canvas-3/40 p-4">
                                            <p class="text-xs uppercase tracking-wide text-slate-500">{{ $t('cdataport.rx_rate') }}</p>
                                            <p class="mt-1 text-lg font-semibold tabular-nums text-sky-300">{{ formatKbps(stats.rx_kbps) }}</p>
                                        </div>
                                        <div class="rounded-lg border border-white/10 bg-canvas-3/40 p-4">
                                            <p class="text-xs uppercase tracking-wide text-slate-500">{{ $t('cdataport.tx_rate') }}</p>
                                            <p class="mt-1 text-lg font-semibold tabular-nums text-emerald-300">{{ formatKbps(stats.tx_kbps) }}</p>
                                        </div>
                                        <div class="rounded-lg border border-white/10 bg-canvas-3/40 p-4">
                                            <p class="text-xs uppercase tracking-wide text-slate-500">{{ $t('cdataport.pps') }}</p>
                                            <p class="mt-1 text-sm font-semibold tabular-nums text-slate-100">{{ formatCount(stats.rx_pps) }} / {{ formatCount(stats.tx_pps) }}</p>
                                        </div>
                                        <div class="rounded-lg border border-white/10 bg-canvas-3/40 p-4">
                                            <p class="text-xs uppercase tracking-wide text-slate-500">{{ $t('cdataport.utilization') }}</p>
                                            <p class="mt-1 text-sm font-semibold tabular-nums text-slate-100">{{ num(stats.rx_utilization, '%') }} / {{ num(stats.tx_utilization, '%') }}</p>
                                        </div>
                                    </div>

                                    <h4 class="mt-6 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $t('cdataport.counters_title') }}</h4>
                                    <div class="mt-2 overflow-x-auto rounded-lg border border-white/10">
                                        <table class="w-full min-w-[360px] text-xs tabular-nums">
                                            <thead>
                                                <tr class="border-b border-white/10 bg-canvas-3/40">
                                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $t('cdataport.counter') }}</th>
                                                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $t('cdataport.rx') }}</th>
                                                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $t('cdataport.tx') }}</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-white/5">
                                                <tr v-for="r in counterRows" :key="r.key">
                                                    <td class="px-4 py-3 text-slate-300">{{ r.label }}</td>
                                                    <td class="px-4 py-3 text-right text-slate-100">{{ r.rx }}</td>
                                                    <td class="px-4 py-3 text-right text-slate-100">{{ r.tx }}</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <!-- VLAN -->
                            <div class="kv-glass-panel">
                                <div class="flex items-center justify-between gap-3 border-b border-white/10 px-4 py-4 sm:px-6">
                                    <div class="flex items-center gap-3">
                                        <Tag class="h-5 w-5 text-cyan-400" />
                                        <h3 class="text-base font-semibold text-slate-100">{{ $t('cdataport.vlan_title') }}</h3>
                                        <span v-if="info.mode" class="kv-pill-muted">{{ info.mode }}</span>
                                    </div>
                                    <span v-if="info.tagged?.length" class="kv-pill-info">{{ $t('cdataport.vlan_count', { count: taggedCount }) }}</span>
                                </div>
                                <div class="space-y-4 p-4 sm:p-6">
                                    <div>
                                        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $t('cdataport.tagged') }}</p>
                                        <div v-if="info.tagged?.length" class="flex flex-wrap gap-1.5">
                                            <span v-for="v in info.tagged" :key="`t${v}`" class="font-mono" :class="isRange(v) ? 'kv-pill-muted' : 'kv-pill-info'">{{ formatVlan(v) }}</span>
                                        </div>
                                        <p v-else class="text-sm text-slate-500">{{ $t('cdataport.no_tagged') }}</p>
                                    </div>
                                    <div>
                                        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $t('cdataport.untagged') }}</p>
                                        <div v-if="info.untagged?.length" class="flex flex-wrap gap-1.5">
                                            <span v-for="v in info.untagged" :key="`u${v}`" class="kv-pill-muted font-mono">{{ formatVlan(v) }}</span>
                                        </div>
                                        <p v-else class="text-sm text-slate-500">—</p>
                                    </div>

                                    <p v-if="isGpon" class="rounded-lg border border-dashed border-white/10 bg-canvas-3/30 px-4 py-3 text-sm text-slate-400">{{ $t('cdataport.pon_auto_note') }}</p>

                                    <div v-else-if="isTaggableKind" class="border-t border-white/10 pt-4">
                                        <p v-if="!can_write" class="text-sm text-slate-400">{{ $t('cdatavlan.read_only_note') }}</p>
                                        <p v-else-if="!isTaggableMode" class="text-sm text-amber-300">{{ $t('cdataport.not_trunk_note', { mode: info.mode || '—' }) }}</p>
                                        <form v-else @submit.prevent="submitTag">
                                            <label for="tag-vlan" class="mb-2 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $t('cdataport.add_tag_label') }}</label>
                                            <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                                                <TextInput id="tag-vlan" v-model="tagForm.vlan_id" type="number" min="1" max="4094" required class="w-full tabular-nums sm:w-48" :placeholder="$t('cdataport.add_tag_placeholder')" />
                                                <PrimaryButton type="submit" :disabled="tagForm.processing || !tagForm.vlan_id">
                                                    <Plus class="mr-2 h-4 w-4" />
                                                    {{ tagForm.processing ? $t('cdatavlan.submitting') : $t('cdataport.add_tag') }}
                                                </PrimaryButton>
                                            </div>
                                            <InputError class="mt-1" :message="tagForm.errors.vlan_id" />
                                            <p class="mt-2 text-xs text-slate-500">{{ $t('cdataport.tag_hint') }}</p>
                                        </form>
                                    </div>
                                </div>
                            </div>

                            <!-- ONU (GPON) -->
                            <div v-if="isPon && onu_summary" class="kv-glass-panel">
                                <div class="flex flex-col gap-3 border-b border-white/10 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                                    <div class="flex items-center gap-3">
                                        <Users class="h-5 w-5 text-cyan-400" />
                                        <h3 class="text-base font-semibold text-slate-100">{{ $t('cdataport.onu_title') }}</h3>
                                    </div>
                                    <Link :href="route('cdata-olt.port-onus', [olt.id, slot, port])">
                                        <SecondaryButton type="button">
                                            <Users class="mr-2 h-4 w-4" />
                                            {{ $t('cdataport.view_onu') }}
                                        </SecondaryButton>
                                    </Link>
                                </div>
                                <div class="grid grid-cols-2 gap-4 p-4 sm:p-6">
                                    <div class="rounded-lg border border-white/10 bg-canvas-3/40 p-4 text-center">
                                        <p class="text-xs uppercase tracking-wide text-slate-500">{{ $t('cdataport.total_onu') }}</p>
                                        <p class="mt-1 text-2xl font-semibold tabular-nums text-slate-100">{{ onu_summary.total }}</p>
                                    </div>
                                    <div class="rounded-lg border border-white/10 bg-canvas-3/40 p-4 text-center">
                                        <p class="text-xs uppercase tracking-wide text-slate-500">{{ $t('common.online') }}</p>
                                        <p class="mt-1 text-2xl font-semibold tabular-nums text-emerald-300">{{ onu_summary.online }}</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Semua atribut -->
                            <details class="kv-glass-panel group">
                                <summary class="flex cursor-pointer list-none items-center gap-3 rounded-lg px-4 py-4 focus-visible:ring-2 focus-visible:ring-cyan-400/60 sm:px-6">
                                    <ListTree class="h-5 w-5 text-cyan-400" />
                                    <div>
                                        <h3 class="text-base font-semibold text-slate-100">{{ $t('cdataport.attributes_title') }}</h3>
                                        <p class="text-xs text-slate-400">{{ $t('cdataport.attributes_sub') }}</p>
                                    </div>
                                </summary>
                                <dl class="grid border-t border-white/10 sm:grid-cols-2">
                                    <div v-for="a in info.attributes ?? []" :key="a.label" class="flex justify-between gap-4 border-b border-white/5 px-4 py-2 text-sm sm:px-6">
                                        <dt class="text-slate-500">{{ a.label }}</dt>
                                        <dd class="text-right font-mono text-xs text-slate-200">{{ dash(a.value) }}</dd>
                                    </div>
                                </dl>
                            </details>
                        </template>
                    </div>
                </Deferred>
            </div>
        </div>

        <ConfirmModal :state="confirmState" @confirm="handleConfirm" @cancel="handleCancel" />
    </AuthenticatedLayout>
</template>
