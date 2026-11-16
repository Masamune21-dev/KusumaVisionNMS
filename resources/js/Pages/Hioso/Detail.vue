<script setup>
import OltImage from '@/Components/OltImage.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import OltFaceplate from '@/Components/CDataOlt/OltFaceplate.vue';
import { formatDateTime } from '@/lib/datetime';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ChevronRight, LayoutPanelTop, RefreshCw, Server } from '@lucide/vue';
import OltPageHeader from '@/Components/SmartOlt/OltPageHeader.vue';
import { computed } from 'vue';

const props = defineProps({
    olt: { type: Object, required: true },
    snapshot: { type: Object, required: true },
    port_labels: { type: Object, default: () => ({}) },
});

const page = usePage();

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


// Faceplate: port PON bisa diklik → langsung ke daftar ONU port itu (nama port = kunci).
const facePortLinks = computed(() => Object.fromEntries(
    ports.value.map((p) => [p.name, route('hioso-olt.port-onus', [props.olt.id, p.slot, p.port])]),
));
const facePortInfo = computed(() => Object.fromEntries(ports.value.map((p) => [p.name, portCount(p)])));
const canManageOlt = computed(() => Boolean(page.props.auth?.can?.manage_olt));

const scan = () => router.post(route('hioso-olt.refresh', props.olt.id), {}, { preserveScroll: true });
const fmt = (v) => formatDateTime(v);
</script>

<template>
    <Head :title="`Detail ${olt.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <OltPageHeader :olt="olt" family="hioso" :title="olt.name">
                <template #badges>
                    <span class="kv-pill kv-pill-info">{{ olt.capabilities.vendor_family }}</span>
                </template>
                <template #subtitle>
                    <span class="font-mono">{{ olt.ip }}:{{ olt.snmp_port }}</span> · {{ olt.snmp_version }}
                </template>
                <template #actions>
                    <PrimaryButton type="button" @click="scan">
                        <RefreshCw class="mr-2 h-4 w-4" />
                        {{ $t('cdatadetail.scan_onu') }}
                    </PrimaryButton>
                </template>
            </OltPageHeader>
        </template>

        <div class="min-h-[60vh] pt-5 pb-16 sm:pt-8">
            <div class="w-full space-y-5 px-4 sm:px-6 lg:px-8">

                <!-- Ringkasan -->
                <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <Link :href="route('hioso-olt' + '.pon-ports', olt.id)" class="kv-stat group block transition-colors hover:border-cyan-500/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-cyan-400/60">
                        <p class="flex items-center justify-between text-xs font-medium text-slate-400"><span>{{ $t('portonus.stat_total_onu') }}</span><ChevronRight class="h-4 w-4 text-slate-600 transition-colors group-hover:text-cyan-300" /></p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums text-white">{{ onuTotals.total.toLocaleString('id-ID') }}</p>
                    </Link>
                    <Link :href="route('hioso-olt' + '.pon-ports', olt.id)" class="kv-stat group block transition-colors hover:border-cyan-500/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-cyan-400/60">
                        <p class="flex items-center justify-between text-xs font-medium text-slate-400"><span>{{ $t('common.online') }}</span><ChevronRight class="h-4 w-4 text-slate-600 transition-colors group-hover:text-cyan-300" /></p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums text-emerald-300">{{ onuTotals.online.toLocaleString('id-ID') }}</p>
                    </Link>
                    <Link :href="route('hioso-olt.pon-ports', olt.id)" class="kv-stat group block transition-colors hover:border-cyan-500/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-cyan-400/60">
                        <p class="flex items-center justify-between text-xs font-medium text-slate-400"><span>{{ $t('common.offline') }}</span><ChevronRight class="h-4 w-4 text-slate-600 transition-colors group-hover:text-cyan-300" /></p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums" :class="onuTotals.offline > 0 ? 'text-rose-300' : 'text-slate-300'">{{ onuTotals.offline.toLocaleString('id-ID') }}</p>
                    </Link>
                    <Link :href="route('hioso-olt' + '.pon-ports', olt.id)" class="kv-stat group block transition-colors hover:border-cyan-500/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-cyan-400/60">
                        <p class="flex items-center justify-between text-xs font-medium text-slate-400"><span>{{ $t('cdatadetail.port_pon_up', { label: olt.capabilities.pon_label }) }}</span><ChevronRight class="h-4 w-4 text-slate-600 transition-colors group-hover:text-cyan-300" /></p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums text-white">{{ portsUp }}<span class="text-base text-slate-500"> / {{ ports.length }}</span></p>
                    </Link>
                </div>

                <!-- System info + gambar OLT (tata letak sama dengan ZTE) -->
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

                    <OltImage :olt="{ ...olt, model: device.model || device.device_type }" />
                </div>

                <!-- Visualisasi chassis (panel depan) — posisi sama dengan halaman detail ZTE -->
                <div v-if="panel" class="kv-glass-panel">
                    <div class="flex items-center gap-3 border-b border-white/10 px-4 py-4 sm:px-6">
                        <span class="kv-circle-cyan !h-10 !w-10"><LayoutPanelTop class="h-5 w-5" /></span>
                        <div>
                            <h3 class="text-base font-semibold text-white">{{ $t('cdatadetail.panel_front') }}</h3>
                            <p class="text-xs text-slate-400">{{ $t('cdatadetail.panel_sub', { family: olt.capabilities.vendor_family }) }}</p>
                        </div>
                    </div>
                    <div class="p-4 sm:p-6">
                        <OltFaceplate :panel="panel" :port-links="facePortLinks" :port-info="facePortInfo" />
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
