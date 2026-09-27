<script setup>
import OltImage from '@/Components/OltImage.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import OltFaceplate from '@/Components/CDataOlt/OltFaceplate.vue';
import { formatDateTime } from '@/lib/datetime';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Cable, LayoutPanelTop, Pencil, RefreshCw, Server } from '@lucide/vue';
import { computed } from 'vue';

const props = defineProps({
    olt: { type: Object, required: true },
    snapshot: { type: Object, required: true },
    port_labels: { type: Object, default: () => ({}) },
});

const page = usePage();
const flash = computed(() => page.props.flash ?? {});
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

// HiOSO punya tab inventory sendiri — kembali ke tab yang sesuai.
const indexTab = computed(() => (props.olt.driver === 'hioso-epon-25355' ? 'hioso' : 'cdata'));

const portCount = (p) => counts.value[`${p.slot}_${p.port}`] ?? { count: 0, online: 0 };


// Faceplate: port PON bisa diklik → langsung ke daftar ONU port itu (nama port = kunci).
const facePortLinks = computed(() => Object.fromEntries(
    ports.value.map((p) => [p.name, route('cdata-olt.port-onus', [props.olt.id, p.slot, p.port])]),
));
const facePortInfo = computed(() => Object.fromEntries(ports.value.map((p) => [p.name, portCount(p)])));
const canManageOlt = computed(() => Boolean(page.props.auth?.can?.manage_olt));

const scan = () => router.post(route('cdata-olt.refresh', props.olt.id), {}, { preserveScroll: true });
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
                        <span class="kv-pill kv-pill-info">{{ olt.capabilities.vendor_family }}</span>
                    </h2>
                    <p class="mt-1 text-sm text-slate-500">{{ olt.ip }}:{{ olt.snmp_port }} · {{ olt.snmp_version }}</p>
                </div>

                <div class="grid gap-2 [&>a>button]:w-full [&>button]:w-full sm:flex sm:flex-wrap sm:[&>a>button]:w-auto sm:[&>button]:w-auto">
                    <Link :href="route('smartolt.index', { tab: indexTab })">
                        <SecondaryButton type="button">
                            <ArrowLeft class="mr-2 h-4 w-4" />
                            {{ $t('common.back') }}
                        </SecondaryButton>
                    </Link>
                    <Link v-if="canManageOlt" :href="route('cdata-olt.edit', olt.id)">
                        <SecondaryButton type="button">
                            <Pencil class="mr-2 h-4 w-4" />
                            {{ $t('common.edit') }}
                        </SecondaryButton>
                    </Link>
                    <Link :href="route('cdata-olt.pon-ports', olt.id)">
                        <SecondaryButton type="button">
                            <Cable class="mr-2 h-4 w-4" />
                            {{ $t('ponports.title', { label: olt.capabilities.pon_label }) }}
                        </SecondaryButton>
                    </Link>
                    <PrimaryButton type="button" @click="scan">
                        <RefreshCw class="mr-2 h-4 w-4" />
                        {{ $t('cdatadetail.scan_onu') }}
                    </PrimaryButton>
                </div>
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
                                <p class="mt-1 text-sm">
                                    <span v-if="snapshot.firmware_v3" class="kv-pill-muted">{{ $t('cdatadetail.firmware_v3') }}</span>
                                    <span v-else class="text-slate-200">{{ $t('cdatadetail.firmware_legacy') }}</span>
                                </p>
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
