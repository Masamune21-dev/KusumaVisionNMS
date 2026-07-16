<script setup>
import IconButton from '@/Components/IconButton.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import OltFaceplate from '@/Components/CDataOlt/OltFaceplate.vue';
import { formatDateTime } from '@/lib/datetime';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, ChevronRight, LayoutPanelTop, RadioTower, RefreshCw, Server } from '@lucide/vue';
import { computed } from 'vue';

const props = defineProps({
    olt: { type: Object, required: true },
    snapshot: { type: Object, required: true },
});

const system = computed(() => props.snapshot.system ?? {});
const ports = computed(() => props.snapshot.ports ?? []);
const counts = computed(() => props.snapshot.port_counts ?? {});
const panel = computed(() => props.snapshot.panel ?? null);
const device = computed(() => panel.value?.device ?? {});

// Total ONU lintas port untuk ringkasan.
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

const scan = () => router.post(route('hioso-olt.refresh', props.olt.id), {}, { preserveScroll: true });
const fmt = (v) => formatDateTime(v);
</script>

<template>
    <Head :title="`Detail ${olt.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-3">
                    <Link :href="route('smartolt.index', { tab: 'hioso' })" class="text-slate-400 hover:text-white">
                        <ArrowLeft class="h-5 w-5" />
                    </Link>
                    <h2 class="text-lg font-semibold leading-tight text-white sm:text-xl">{{ olt.name }}</h2>
                    <span class="kv-pill-info">{{ olt.capabilities.vendor_family }}</span>
                </div>
                <PrimaryButton class="w-full sm:w-auto" @click="scan">
                    <RefreshCw class="mr-2 h-4 w-4" />
                    {{ $t('cdatadetail.scan_onu') }}
                </PrimaryButton>
            </div>
        </template>

        <div class="min-h-[60vh] pt-5 pb-16 sm:pt-8">
            <div class="w-full space-y-5 px-4 sm:px-6 lg:px-8">

                <!-- Ringkasan -->
                <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <div class="kv-stat">
                        <p class="text-xs uppercase tracking-wider text-slate-500">{{ $t('portonus.stat_total_onu') }}</p>
                        <p class="mt-1 text-2xl font-bold tabular-nums text-white">{{ onuTotals.total.toLocaleString('id-ID') }}</p>
                    </div>
                    <div class="kv-stat">
                        <p class="text-xs uppercase tracking-wider text-slate-500">{{ $t('common.online') }}</p>
                        <p class="mt-1 text-2xl font-bold tabular-nums text-emerald-300">{{ onuTotals.online.toLocaleString('id-ID') }}</p>
                    </div>
                    <div class="kv-stat">
                        <p class="text-xs uppercase tracking-wider text-slate-500">{{ $t('common.offline') }}</p>
                        <p class="mt-1 text-2xl font-bold tabular-nums" :class="onuTotals.offline > 0 ? 'text-red-300' : 'text-slate-300'">{{ onuTotals.offline.toLocaleString('id-ID') }}</p>
                    </div>
                    <div class="kv-stat">
                        <p class="text-xs uppercase tracking-wider text-slate-500">{{ $t('cdatadetail.port_pon_up', { label: olt.capabilities.pon_label }) }}</p>
                        <p class="mt-1 text-2xl font-bold tabular-nums text-white">{{ portsUp }}<span class="text-base text-slate-500"> / {{ ports.length }}</span></p>
                    </div>
                </div>

                <!-- Faceplate panel depan -->
                <div v-if="panel" class="kv-glass-panel">
                    <div class="flex items-center gap-3 border-b border-white/10 px-4 py-4 sm:px-6">
                        <span class="kv-circle-cyan !h-10 !w-10"><LayoutPanelTop class="h-5 w-5" /></span>
                        <div>
                            <h3 class="text-base font-semibold text-white">{{ $t('cdatadetail.panel_front') }}</h3>
                            <p class="text-xs text-slate-400">{{ $t('cdatadetail.panel_sub', { family: olt.capabilities.vendor_family }) }}</p>
                        </div>
                    </div>
                    <div class="p-4 sm:p-6">
                        <OltFaceplate :panel="panel" />
                    </div>
                </div>

                <!-- System info -->
                <div class="kv-glass-panel">
                    <div class="flex items-center gap-3 border-b border-white/10 px-4 py-4 sm:px-6">
                        <span class="kv-circle-sky !h-10 !w-10"><Server class="h-5 w-5" /></span>
                        <div>
                            <h3 class="text-base font-semibold text-white">{{ $t('cdatadetail.system_info') }}</h3>
                            <p class="text-xs text-slate-400">{{ olt.ip }}:{{ olt.snmp_port }} · {{ olt.snmp_version }}</p>
                        </div>
                    </div>
                    <div class="grid gap-4 p-6 sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <p class="text-xs uppercase tracking-wider text-slate-500">{{ $t('portonus.description') }}</p>
                            <p class="mt-1 break-words text-sm text-slate-200">{{ system.sys_descr || '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs uppercase tracking-wider text-slate-500">{{ $t('cdatadetail.system_name') }}</p>
                            <p class="mt-1 text-sm text-slate-200">{{ system.sys_name || '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs uppercase tracking-wider text-slate-500">Uptime</p>
                            <p class="mt-1 text-sm text-slate-200">{{ system.sys_uptime || '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs uppercase tracking-wider text-slate-500">{{ $t('cdatadetail.firmware') }}</p>
                            <p class="mt-1 text-sm text-slate-200">{{ system.firmware || '—' }}</p>
                        </div>
                        <div v-if="device.model">
                            <p class="text-xs uppercase tracking-wider text-slate-500">{{ $t('cdatadetail.model') }}</p>
                            <p class="mt-1 font-mono text-sm text-slate-200">{{ device.model }}</p>
                        </div>
                        <div v-else-if="device.device_type">
                            <p class="text-xs uppercase tracking-wider text-slate-500">{{ $t('cdatadetail.type') }}</p>
                            <p class="mt-1 text-sm text-slate-200">{{ device.device_type }}</p>
                        </div>
                        <div v-if="device.serial">
                            <p class="text-xs uppercase tracking-wider text-slate-500">{{ $t('common.serial') }}</p>
                            <p class="mt-1 font-mono text-sm text-slate-200">{{ device.serial }}</p>
                        </div>
                        <div v-if="device.hw_version">
                            <p class="text-xs uppercase tracking-wider text-slate-500">{{ $t('cdatadetail.hw_version') }}</p>
                            <p class="mt-1 font-mono text-sm text-slate-200">{{ device.hw_version }}</p>
                        </div>
                        <div v-if="device.sw_version">
                            <p class="text-xs uppercase tracking-wider text-slate-500">{{ $t('cdatadetail.sw_version') }}</p>
                            <p class="mt-1 font-mono text-sm text-slate-200">{{ device.sw_version }}</p>
                        </div>
                    </div>
                    <p v-if="snapshot.scanned_at" class="border-t border-white/10 px-6 py-3 text-xs text-slate-500">
                        {{ $t('cdatadetail.last_scan', { date: fmt(snapshot.scanned_at) }) }}
                    </p>
                </div>

                <!-- Ports -->
                <div class="kv-glass-panel">
                    <div class="flex items-center gap-3 border-b border-white/10 px-4 py-4 sm:px-6">
                        <span class="kv-circle-sky !h-10 !w-10"><RadioTower class="h-5 w-5" /></span>
                        <div>
                            <h3 class="text-base font-semibold text-white">{{ $t('cdatadetail.pon_ports') }}</h3>
                            <p class="text-xs text-slate-400">{{ $t('cdatadetail.pon_ports_sub', { count: ports.length, label: olt.capabilities.pon_label }) }}</p>
                        </div>
                    </div>

                    <div v-if="ports.length === 0" class="px-6 py-12 text-center text-sm text-slate-500">
                        <span v-html="$t('cdatadetail.empty_ports')"></span>
                    </div>

                    <div v-else class="kv-table-desktop">
                        <table class="w-full min-w-[640px]">
                            <thead>
                                <tr class="border-b border-white/10 bg-slate-950/40">
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $t('common.port') }}</th>
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $t('common.status') }}</th>
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">ONU</th>
                                    <th class="px-4 py-3.5 text-center text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $t('common.actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/5">
                                <tr v-for="p in ports" :key="p.if_index" class="transition-colors hover:bg-white/[0.03]">
                                    <td class="px-4 py-4 font-mono text-sm text-white">{{ p.name }}</td>
                                    <td class="px-4 py-4">
                                        <span class="inline-flex items-center gap-1.5 text-xs" :class="p.oper_status === 'up' ? 'text-emerald-400' : 'text-slate-500'">
                                            <span class="h-1.5 w-1.5 rounded-full" :class="p.oper_status === 'up' ? 'bg-emerald-400' : 'bg-slate-600'"></span>
                                            {{ p.oper_status }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-4 text-sm text-slate-300">
                                        <span class="text-white">{{ portCount(p).count }}</span>
                                        <span class="text-slate-500"> {{ $t('cdatadetail.online_count', { count: portCount(p).online }) }}</span>
                                    </td>
                                    <td class="px-4 py-4">
                                        <div class="flex justify-center">
                                            <IconButton :href="route('hioso-olt.port-onus', [olt.id, p.slot, p.port])" :title="$t('cdatadetail.view_onu')">
                                                <ChevronRight class="h-4 w-4" />
                                            </IconButton>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- mobile -->
                    <div v-if="ports.length" class="kv-mobile-list">
                        <Link
                            v-for="p in ports"
                            :key="p.if_index"
                            :href="route('hioso-olt.port-onus', [olt.id, p.slot, p.port])"
                            class="kv-mobile-card block"
                        >
                            <div class="flex items-center justify-between">
                                <span class="font-mono text-sm text-white">{{ p.name }}</span>
                                <span class="text-xs" :class="p.oper_status === 'up' ? 'text-emerald-400' : 'text-slate-500'">{{ p.oper_status }}</span>
                            </div>
                            <p class="mt-1 text-xs text-slate-400">{{ $t('cdatadetail.onu_count_mobile', { count: portCount(p).count, online: portCount(p).online }) }}</p>
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
