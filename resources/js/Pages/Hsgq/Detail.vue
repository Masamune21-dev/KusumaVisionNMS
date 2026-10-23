<script setup>
import ConfirmModal from '@/Components/ConfirmModal.vue';
import IconButton from '@/Components/IconButton.vue';
import OltImage from '@/Components/OltImage.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { formatDateTime } from '@/lib/datetime';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Cable, ClipboardCopy, List, Pencil, RefreshCw, Save, Server, Stethoscope, TriangleAlert } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    olt: { type: Object, required: true },
    snapshot: { type: Object, required: true },
    port_labels: { type: Object, default: () => ({}) },
});

const { t } = useI18n({ useScope: 'global' });
const page = usePage();
const { confirmState, confirm, handleConfirm, handleCancel } = useConfirm();

const caps = computed(() => props.olt.capabilities ?? {});
const system = computed(() => props.snapshot.system ?? {});
const ports = computed(() => props.snapshot.ports ?? []);
const counts = computed(() => props.snapshot.port_counts ?? {});
const canManageOlt = computed(() => Boolean(page.props.auth?.can?.manage_olt));
const canSaveConfig = computed(() => canManageOlt.value && Boolean(caps.value.supports_config_save) && Boolean(props.olt.web_port));

const onuTotals = computed(() => {
    let total = 0;
    let online = 0;
    for (const c of Object.values(counts.value)) {
        total += c.count ?? 0;
        online += c.online ?? 0;
    }
    return { total, online, offline: total - online };
});
const portsUp = computed(() => ports.value.filter((p) => p.oper_status === 'up').length);
const portCount = (p) => counts.value[`${p.slot}_${p.port}`] ?? { count: 0, online: 0 };
const portLabel = (p) => props.port_labels?.[`${p.slot}_${p.port}`] ?? null;

const statusPill = (status) => ({
    up: 'kv-pill-success',
    down: 'kv-pill-danger',
}[status] ?? 'kv-pill-muted');
const statusText = (status) => ({
    up: 'Up',
    down: 'Down',
}[status] ?? '—');

const num = (v, digits = 2) => (v === null || v === undefined ? '—' : Number(v).toFixed(digits));
const sfpText = (sfp) => (sfp
    ? `${num(sfp.temperature_c, 1)} °C · ${num(sfp.voltage_v)} V · ${num(sfp.bias_ma)} mA · Tx ${num(sfp.tx_dbm)} dBm`
    : '—');
const counterText = (p) => (p.onu_registered_snmp === null || p.onu_registered_snmp === undefined
    ? '—'
    : `${p.onu_online_snmp ?? '—'} / ${p.onu_registered_snmp}`);

const scan = () => router.post(route('hsgq-olt.refresh', props.olt.id), {}, { preserveScroll: true });
const runTest = () => router.post(route('hsgq-olt.test', props.olt.id), {}, { preserveScroll: true });

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

// Laporan diagnostik: JSON hasil tombol Test (SN/MAC sudah disamarkan server, tanpa sandi).
const diagText = computed(() => (props.snapshot.diag ? JSON.stringify({
    olt_driver: props.olt.driver,
    vendor_family: caps.value.vendor_family,
    ...props.snapshot.diag,
}, null, 2) : ''));
const copyState = ref(''); // '' | 'copied' | 'failed'
const copyDiag = async () => {
    try {
        await navigator.clipboard.writeText(diagText.value);
        copyState.value = 'copied';
    } catch {
        copyState.value = 'failed';
    }
    window.setTimeout(() => { copyState.value = ''; }, 2500);
};

const fmt = (v) => formatDateTime(v);
</script>

<template>
    <Head :title="`Detail ${olt.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="flex flex-wrap items-center gap-2 text-lg font-semibold leading-tight text-white sm:text-xl">
                        {{ olt.name }}
                        <span class="kv-pill-info">{{ caps.vendor_family }}</span>
                        <span v-if="caps.hsgq_unverified" class="kv-pill-warning" :title="$t('hsgq.unverified_hint')">{{ $t('hsgq.unverified_badge') }}</span>
                    </h2>
                    <p class="mt-1 text-sm text-slate-500">{{ olt.ip }}:{{ olt.snmp_port }} · {{ olt.snmp_version }}<span v-if="olt.web_port"> · web :{{ olt.web_port }}</span></p>
                </div>

                <div class="grid gap-2 [&>a>button]:w-full [&>button]:w-full sm:flex sm:flex-wrap sm:[&>a>button]:w-auto sm:[&>button]:w-auto">
                    <Link :href="route('smartolt.index', { tab: 'hsgq' })">
                        <SecondaryButton type="button">
                            <ArrowLeft class="mr-2 h-4 w-4" />
                            {{ $t('common.back') }}
                        </SecondaryButton>
                    </Link>
                    <Link v-if="canManageOlt" :href="route('hsgq-olt.edit', olt.id)">
                        <SecondaryButton type="button">
                            <Pencil class="mr-2 h-4 w-4" />
                            {{ $t('common.edit') }}
                        </SecondaryButton>
                    </Link>
                    <Link :href="route('hsgq-olt.pon-ports', olt.id)">
                        <SecondaryButton type="button">
                            <Cable class="mr-2 h-4 w-4" />
                            {{ $t('ponports.title', { label: caps.pon_label }) }}
                        </SecondaryButton>
                    </Link>
                    <SecondaryButton v-if="!olt.connection_locked" type="button" @click="runTest">
                        <Stethoscope class="mr-2 h-4 w-4" />
                        {{ $t('hsgq.run_test') }}
                    </SecondaryButton>
                    <SecondaryButton v-if="canSaveConfig" type="button" :disabled="saving" @click="saveConfig">
                        <Save class="mr-2 h-4 w-4" />
                        {{ $t('hsgq.save_config') }}
                    </SecondaryButton>
                    <PrimaryButton type="button" @click="scan">
                        <RefreshCw class="mr-2 h-4 w-4" />
                        {{ $t('cdatadetail.scan_onu') }}
                    </PrimaryButton>
                </div>
            </div>
        </template>

        <div class="min-h-[60vh] pt-5 pb-16 sm:pt-8">
            <div class="w-full space-y-5 px-4 sm:px-6 lg:px-8">

                <div class="flex items-start gap-3 rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-100/90">
                    <TriangleAlert class="mt-0.5 h-4 w-4 flex-shrink-0 text-amber-300" />
                    <div>
                        <p class="font-semibold text-amber-200">{{ $t('hsgq.notice_title') }}</p>
                        <p class="mt-0.5 text-xs">{{ $t('hsgq.notice_body') }}</p>
                        <p v-if="snapshot.status_unverified" class="mt-1 text-xs font-semibold text-amber-200">{{ $t('hsgq.status_unverified') }}</p>
                    </div>
                </div>

                <!-- Ringkasan -->
                <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <div class="kv-stat">
                        <p class="text-xs uppercase tracking-wider text-slate-500">{{ $t('portonus.stat_total_onu') }}</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums text-white">{{ onuTotals.total.toLocaleString('id-ID') }}</p>
                    </div>
                    <div class="kv-stat">
                        <p class="text-xs uppercase tracking-wider text-slate-500">{{ $t('common.online') }}</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums text-emerald-300">{{ onuTotals.online.toLocaleString('id-ID') }}</p>
                    </div>
                    <div class="kv-stat">
                        <p class="text-xs uppercase tracking-wider text-slate-500">{{ $t('common.offline') }}</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums" :class="onuTotals.offline > 0 ? 'text-rose-300' : 'text-slate-300'">{{ onuTotals.offline.toLocaleString('id-ID') }}</p>
                    </div>
                    <div class="kv-stat">
                        <p class="text-xs uppercase tracking-wider text-slate-500">{{ $t('cdatadetail.port_pon_up', { label: caps.pon_label }) }}</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums text-white">{{ portsUp }}<span class="text-base text-slate-500"> / {{ ports.length }}</span></p>
                    </div>
                </div>

                <!-- Info sistem + gambar OLT -->
                <div class="grid gap-6 lg:grid-cols-2">
                    <div class="kv-glass-panel">
                        <div class="flex items-center gap-3 border-b border-white/10 px-4 py-4 sm:px-6">
                            <span class="kv-circle-sky !h-10 !w-10"><Server class="h-5 w-5" /></span>
                            <div>
                                <h3 class="text-base font-semibold text-white">{{ $t('cdatadetail.system_info') }}</h3>
                                <p class="text-xs text-slate-400">{{ olt.ip }}:{{ olt.snmp_port }} · {{ olt.snmp_version }}</p>
                            </div>
                        </div>
                        <div class="grid gap-4 p-6 sm:grid-cols-2">
                            <div>
                                <p class="text-xs uppercase tracking-wider text-slate-500">{{ $t('cdatadetail.model') }}</p>
                                <p class="mt-1 break-words font-mono text-sm text-slate-200">{{ system.model || system.sys_descr || '—' }}</p>
                            </div>
                            <div>
                                <p class="text-xs uppercase tracking-wider text-slate-500">{{ $t('cdatadetail.system_name') }}</p>
                                <p class="mt-1 text-sm text-slate-200">{{ system.sys_name || '—' }}</p>
                            </div>
                            <div>
                                <p class="text-xs uppercase tracking-wider text-slate-500">{{ $t('cdatadetail.firmware') }}</p>
                                <p class="mt-1 break-words font-mono text-sm text-slate-200">{{ system.firmware || '—' }}</p>
                            </div>
                            <div>
                                <p class="text-xs uppercase tracking-wider text-slate-500">Uptime</p>
                                <p class="mt-1 text-sm text-slate-200">{{ system.sys_uptime || '—' }}</p>
                            </div>
                            <div>
                                <p class="text-xs uppercase tracking-wider text-slate-500">{{ $t('common.serial') }}</p>
                                <p class="mt-1 font-mono text-sm text-slate-200">{{ system.serial || '—' }}</p>
                            </div>
                            <div>
                                <p class="text-xs uppercase tracking-wider text-slate-500">MAC</p>
                                <p class="mt-1 font-mono text-sm text-slate-200">{{ system.mac || '—' }}</p>
                            </div>
                            <div>
                                <p class="text-xs uppercase tracking-wider text-slate-500">{{ $t('hsgq.cpu_memory') }}</p>
                                <p class="mt-1 text-sm tabular-nums text-slate-200">{{ system.cpu_percent ?? '—' }}% · {{ system.memory_percent ?? '—' }}%</p>
                            </div>
                            <div>
                                <p class="text-xs uppercase tracking-wider text-slate-500">{{ $t('hsgq.onu_table_schema') }}</p>
                                <p class="mt-1 font-mono text-sm text-slate-200">{{ system.hsgq_schema || '—' }}</p>
                            </div>
                        </div>
                        <p v-if="snapshot.scanned_at" class="border-t border-white/10 px-6 py-3 text-xs text-slate-500">
                            {{ $t('cdatadetail.last_scan', { date: fmt(snapshot.scanned_at) }) }}
                        </p>
                    </div>

                    <OltImage :olt="{ ...olt, model: system.model }" />
                </div>

                <!-- Port PON -->
                <div class="kv-table-card">
                    <div class="flex items-center gap-3 border-b border-white/10 px-4 py-4 sm:px-6">
                        <span class="kv-circle-cyan !h-10 !w-10"><Cable class="h-5 w-5" /></span>
                        <div>
                            <h3 class="text-base font-semibold text-white">{{ $t('ponports.title', { label: caps.pon_label }) }}</h3>
                            <p class="text-xs text-slate-400">{{ $t('hsgq.ports_sub') }}</p>
                        </div>
                    </div>

                    <div v-if="ports.length === 0" class="px-6 py-14 text-center text-sm text-slate-500">
                        {{ $t('cdataportonus.empty_hint') }}
                    </div>

                    <template v-else>
                        <div class="kv-mobile-list">
                            <article v-for="p in ports" :key="p.port" class="kv-mobile-card">
                                <div class="kv-mobile-card-header">
                                    <div class="min-w-0">
                                        <h4 class="kv-mobile-card-title font-mono">{{ p.name }}</h4>
                                        <p class="kv-mobile-card-subtitle">{{ portLabel(p) || p.label || '—' }}</p>
                                    </div>
                                    <span :class="statusPill(p.oper_status)">{{ statusText(p.oper_status) }}</span>
                                </div>
                                <div class="kv-mobile-fields">
                                    <div class="kv-mobile-field">
                                        <span class="kv-mobile-label">ONU</span>
                                        <span class="kv-mobile-value tabular-nums">{{ portCount(p).online }} / {{ portCount(p).count }}</span>
                                    </div>
                                    <div class="kv-mobile-field">
                                        <span class="kv-mobile-label">{{ $t('hsgq.col_counter') }}</span>
                                        <span class="kv-mobile-value tabular-nums">{{ counterText(p) }}</span>
                                    </div>
                                    <div class="kv-mobile-field">
                                        <span class="kv-mobile-label">{{ $t('hsgq.col_sfp') }}</span>
                                        <span class="kv-mobile-value font-mono text-xs">{{ sfpText(p.sfp) }}</span>
                                    </div>
                                </div>
                                <div class="mt-3 flex gap-2">
                                    <IconButton variant="primary" :href="route('hsgq-olt.port-onus', [olt.id, p.slot, p.port])" :title="$t('hsgq.open_onus')">
                                        <List class="h-4 w-4" />
                                    </IconButton>
                                </div>
                            </article>
                        </div>

                        <div class="kv-table-desktop">
                            <table class="w-full min-w-[760px] text-xs">
                                <thead>
                                    <tr class="border-b border-white/10 bg-canvas-3/40">
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">Port</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $t('portlabel.column') }}</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $t('common.status') }}</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">ONU</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $t('hsgq.col_counter') }}</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $t('hsgq.col_sfp') }}</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $t('common.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-white/5">
                                    <tr v-for="p in ports" :key="p.port" class="transition-colors hover:bg-white/[0.03]">
                                        <td class="px-4 py-3 font-mono text-white">{{ p.name }}</td>
                                        <td class="px-4 py-3 text-slate-300">{{ portLabel(p) || p.label || '—' }}</td>
                                        <td class="px-4 py-3"><span :class="statusPill(p.oper_status)">{{ statusText(p.oper_status) }}</span></td>
                                        <td class="px-4 py-3 tabular-nums text-slate-200">{{ portCount(p).online }} / {{ portCount(p).count }}</td>
                                        <td class="px-4 py-3 tabular-nums text-slate-400">{{ counterText(p) }}</td>
                                        <td class="px-4 py-3 font-mono text-slate-300">{{ sfpText(p.sfp) }}</td>
                                        <td class="px-4 py-3">
                                            <IconButton variant="primary" :href="route('hsgq-olt.port-onus', [olt.id, p.slot, p.port])" :title="$t('hsgq.open_onus')">
                                                <List class="h-4 w-4" />
                                            </IconButton>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </template>
                </div>

                <!-- Laporan diagnostik -->
                <div class="kv-glass-panel">
                    <div class="flex flex-col gap-3 border-b border-white/10 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                        <div class="flex items-center gap-3">
                            <span class="kv-circle-amber !h-10 !w-10"><Stethoscope class="h-5 w-5" /></span>
                            <div>
                                <h3 class="text-base font-semibold text-white">{{ $t('hsgq.diag_title') }}</h3>
                                <p class="text-xs text-slate-400">{{ $t('hsgq.diag_sub') }}</p>
                            </div>
                        </div>
                        <div v-if="diagText" class="flex items-center gap-3">
                            <span v-if="copyState === 'copied'" class="text-xs text-emerald-300">{{ $t('hsgq.diag_copied') }}</span>
                            <span v-else-if="copyState === 'failed'" class="text-xs text-rose-300">{{ $t('hsgq.diag_copy_failed') }}</span>
                            <SecondaryButton type="button" @click="copyDiag">
                                <ClipboardCopy class="mr-2 h-4 w-4" />
                                {{ $t('hsgq.diag_copy') }}
                            </SecondaryButton>
                        </div>
                    </div>
                    <div class="p-4 sm:p-6">
                        <p v-if="!diagText" class="text-sm text-slate-500">{{ $t('hsgq.diag_empty') }}</p>
                        <pre v-else data-theme="dark" class="kv-terminal max-h-96 overflow-auto rounded-lg bg-slate-950/70 p-4 font-mono text-xs leading-relaxed text-emerald-300/90">{{ diagText }}</pre>
                    </div>
                </div>
            </div>
        </div>

        <ConfirmModal :state="confirmState" @confirm="handleConfirm" @cancel="handleCancel" />
    </AuthenticatedLayout>
</template>
