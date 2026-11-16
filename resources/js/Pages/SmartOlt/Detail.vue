<script setup>
import OltImage from '@/Components/OltImage.vue';
import OltChassis from '@/Components/SmartOlt/OltChassis.vue';
import OltPageHeader from '@/Components/SmartOlt/OltPageHeader.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { formatDateTime } from '@/lib/datetime';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ChevronRight, RefreshCw, Server } from '@lucide/vue';
import { computed, ref } from 'vue';

const props = defineProps({
    olt: {
        type: Object,
        required: true,
    },
    snapshot: {
        type: Object,
        required: true,
    },
    cards: {
        type: Array,
        default: () => [],
    },
    interfaces: {
        type: Array,
        default: () => [],
    },
});

const onuTotal  = computed(() => props.snapshot.ports.reduce((s, p) => s + (p.onu_count ?? 0), 0));
const onuOnline = computed(() => props.snapshot.ports.reduce((s, p) => s + (p.online_onu_count ?? 0), 0));
const hardwareRefreshing = ref(false);
const hardwareLastRefresh = computed(() => props.cards[0]?.refreshed_at ?? null);

const refreshing = ref(false);
const refresh = () => {
    refreshing.value = true;
    router.post(route('smartolt.refresh', props.olt.id), {}, {
        preserveScroll: true,
        onFinish: () => { refreshing.value = false; },
    });
};
const onuOffline = computed(() => Math.max(0, onuTotal.value - onuOnline.value));
const portsDown = computed(() => props.snapshot.ports.filter((p) => String(p.oper_status ?? '').toLowerCase() !== 'up').length);

const refreshHardware = () => {
    hardwareRefreshing.value = true;
    router.post(route('smartolt.hardware.refresh', props.olt.id), {}, {
        preserveScroll: true,
        onFinish: () => { hardwareRefreshing.value = false; },
    });
};

const formatDate = (value) => formatDateTime(value);

const formatUptime = (timeticks) => {
    if (!timeticks) return '-';
    // Poller Go menyimpan timeticks mentah; Test/Refresh SNMP (PHP) menyimpan teks net-snmp
    // "(140874804) 16 days, 7:19:08.04" — ambil angka di dalam kurung.
    const raw = String(timeticks).match(/^\s*\((\d+)\)/)?.[1] ?? timeticks;
    const totalSeconds = Math.floor(Number(raw) / 100);
    if (!Number.isFinite(totalSeconds)) return String(timeticks);
    const days    = Math.floor(totalSeconds / 86400);
    const hours   = Math.floor((totalSeconds % 86400) / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;
    const parts = [];
    if (days)    parts.push(`${days}h`);
    if (hours)   parts.push(`${hours}j`);
    if (minutes) parts.push(`${minutes}m`);
    if (seconds || parts.length === 0) parts.push(`${seconds}d`);
    return parts.join(' ');
};

</script>

<template>
    <Head :title="`Detail ${olt.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <OltPageHeader :olt="olt" :title="olt.name">
                <template #badges>
                    <span class="kv-pill" :class="snapshot.ok ? 'kv-pill-success' : 'kv-pill-muted'">
                        {{ snapshot.ok ? $t('common.online') : $t('common.unknown') }}
                    </span>
                </template>
                <template #subtitle>
                    <span class="font-mono">{{ olt.ip }}:{{ olt.snmp_port }}</span> · {{ olt.capabilities.vendor_family }}
                </template>
                <template #actions>
                    <PrimaryButton type="button" :disabled="refreshing" @click="refresh">
                        <RefreshCw class="mr-2 h-4 w-4" :class="{ 'animate-spin': refreshing }" />
                        {{ $t('gponports.refresh_snmp') }}
                    </PrimaryButton>
                </template>
            </OltPageHeader>
        </template>

        <div class="min-h-[60vh] pt-5 pb-16 sm:pt-8">
            <div class="w-full space-y-6 px-4 sm:px-6 lg:px-8">

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Link :href="route('smartolt.gpon-ports', olt.id)" class="kv-stat group block transition-colors hover:border-cyan-500/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-cyan-400/60">
                        <p class="flex items-center justify-between text-xs font-medium text-slate-400">
                            {{ $t('gponports.stat_gpon_port') }}
                            <ChevronRight class="h-4 w-4 text-slate-600 transition-colors group-hover:text-cyan-300" />
                        </p>
                        <p class="mt-3 text-2xl font-semibold tabular-nums text-slate-100">{{ snapshot.ports.length }}</p>
                        <p class="mt-1 text-xs" :class="portsDown ? 'text-rose-300' : 'text-slate-500'">
                            {{ portsDown ? $t('ponports.ports_down', { count: portsDown }) : $t('oltdetail.all_ports_up') }}
                        </p>
                    </Link>
                    <Link :href="route('smartolt.gpon-ports', olt.id)" class="kv-stat group block transition-colors hover:border-cyan-500/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-cyan-400/60">
                        <p class="flex items-center justify-between text-xs font-medium text-slate-400">
                            {{ $t('gponports.stat_onu_online') }}
                            <ChevronRight class="h-4 w-4 text-slate-600 transition-colors group-hover:text-cyan-300" />
                        </p>
                        <p class="mt-3 text-2xl font-semibold tabular-nums text-emerald-300">
                            {{ onuOnline }}<span class="text-sm font-normal text-slate-500"> / {{ onuTotal }}</span>
                        </p>
                        <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-700/40">
                            <div class="h-full rounded-full bg-emerald-400" :style="{ width: `${onuTotal ? Math.round((onuOnline / onuTotal) * 100) : 0}%` }"></div>
                        </div>
                    </Link>
                    <Link :href="route('smartolt.gpon-ports', olt.id)" class="kv-stat group block transition-colors hover:border-cyan-500/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-cyan-400/60">
                        <p class="flex items-center justify-between text-xs font-medium text-slate-400">
                            {{ $t('common.offline') }}
                            <ChevronRight class="h-4 w-4 text-slate-600 transition-colors group-hover:text-cyan-300" />
                        </p>
                        <p class="mt-3 text-2xl font-semibold tabular-nums" :class="onuOffline ? 'text-rose-300' : 'text-slate-300'">{{ onuOffline }}</p>
                        <p class="mt-1 text-xs text-slate-500">{{ $t('oltdetail.offline_hint') }}</p>
                    </Link>
                    <div class="kv-stat">
                        <p class="text-xs font-medium text-slate-400">{{ $t('common.last_refresh') }}</p>
                        <p class="mt-3 text-sm font-semibold text-slate-100">{{ formatDate(snapshot.last_tested_at) }}</p>
                        <p class="mt-1 text-xs" :class="olt.polling_enabled ? 'text-emerald-400' : 'text-slate-400'">
                            {{ $t('smartolt.col_autopoll') }} {{ olt.polling_enabled ? $t('common.on') : $t('common.off') }}
                        </p>
                    </div>
                </div>

                <div class="grid gap-6 lg:grid-cols-2">
                    <div class="kv-surface overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
                        <div class="flex items-center gap-3 border-b border-white/10 px-4 py-4 sm:px-6">
                            <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-sky-500/15 ring-1 ring-cyan-500/30">
                                <Server class="h-5 w-5 text-cyan-400" />
                            </div>
                            <h3 class="text-base font-semibold text-white">System Info</h3>
                        </div>
                        <dl class="divide-y divide-white/5">
                            <div class="px-6 py-4">
                                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">sysName</dt>
                                <dd class="mt-1 break-words text-sm text-white">{{ snapshot.system.sys_name || '-' }}</dd>
                            </div>
                            <div class="px-6 py-4">
                                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">sysDescr</dt>
                                <dd class="mt-1 break-words text-sm text-white">{{ snapshot.system.sys_descr || '-' }}</dd>
                            </div>
                            <div class="px-6 py-4">
                                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">sysObjectID</dt>
                                <dd class="mt-1 break-words text-sm text-white">{{ snapshot.system.sys_object_id || '-' }}</dd>
                            </div>
                            <div class="px-6 py-4">
                                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">sysUptime</dt>
                                <dd class="mt-1 break-words text-sm text-white">{{ formatUptime(snapshot.system.sys_uptime) }}</dd>
                            </div>
                        </dl>
                    </div>

                    <OltImage :olt="olt" />
                </div>

                <!-- Visualisasi Chassis -->
                <OltChassis
                    :olt-id="olt.id"
                    :cards="cards"
                    :ports="snapshot.ports"
                    :interfaces="interfaces"
                    :model="olt.name"
                    :is-c600="olt.capabilities.is_c600"
                    :last-refresh="hardwareLastRefresh"
                >
                    <template #actions>
                        <SecondaryButton type="button" :disabled="hardwareRefreshing" @click="refreshHardware">
                            <RefreshCw class="mr-2 h-4 w-4" :class="{ 'animate-spin': hardwareRefreshing }" />
                            {{ $t('detail.refresh_hardware') }}
                        </SecondaryButton>
                    </template>
                </OltChassis>

            </div>
        </div>
    </AuthenticatedLayout>
</template>
