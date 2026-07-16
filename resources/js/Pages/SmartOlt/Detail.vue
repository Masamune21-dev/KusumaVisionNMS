<script setup>
import OltChassis from '@/Components/SmartOlt/OltChassis.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { formatDateTime } from '@/lib/datetime';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Cable, ClipboardList, Database, Pencil, RefreshCw, Router, Server } from '@lucide/vue';
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

const page = usePage();
const flash = computed(() => page.props.flash ?? {});

const onuTotal  = computed(() => props.snapshot.ports.reduce((s, p) => s + (p.onu_count ?? 0), 0));
const onuOnline = computed(() => props.snapshot.ports.reduce((s, p) => s + (p.online_onu_count ?? 0), 0));
const hardwareRefreshing = ref(false);
const hardwareLastRefresh = computed(() => props.cards[0]?.refreshed_at ?? null);

const refresh = () => {
    router.post(route('smartolt.refresh', props.olt.id), {}, {
        preserveScroll: true,
    });
};

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
    const totalSeconds = Math.floor(Number(timeticks) / 100);
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

const oltImage = computed(() => {
    const hay = (props.olt.name + ' ' + (props.olt.vendor ?? '')).toLowerCase();
    if (hay.includes('c320')) return '/img/c320.webp';
    if (hay.includes('c300')) return '/img/c300.webp';
    if (hay.includes('c600')) return '/img/c600.webp';
    return null;
});
</script>

<template>
    <Head :title="`Detail ${olt.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="text-lg font-semibold leading-tight sm:text-xl text-white">
                        {{ olt.name }}
                    </h2>
                    <p class="mt-1 text-sm text-slate-500">
                        {{ olt.ip }}:{{ olt.snmp_port }} · {{ olt.capabilities.vendor_family }}
                    </p>
                </div>

                <div class="grid gap-2 [&>a>button]:w-full [&>button]:w-full sm:flex sm:flex-wrap sm:[&>a>button]:w-auto sm:[&>button]:w-auto">
                    <Link :href="route('smartolt.index')">
                        <SecondaryButton type="button">
                            <ArrowLeft class="mr-2 h-4 w-4" />
                            {{ $t('common.back') }}
                        </SecondaryButton>
                    </Link>
                    <Link :href="route('smartolt.edit', olt.id)">
                        <SecondaryButton type="button">
                            <Pencil class="mr-2 h-4 w-4" />
                            {{ $t('common.edit') }}
                        </SecondaryButton>
                    </Link>
                    <Link :href="route('smartolt.gpon-ports', olt.id)">
                        <SecondaryButton type="button">
                            <Cable class="mr-2 h-4 w-4" />
                            {{ $t('gponports.title') }}
                        </SecondaryButton>
                    </Link>
                    <Link :href="route('smartolt.registrations', olt.id)">
                        <SecondaryButton type="button">
                            <ClipboardList class="mr-2 h-4 w-4" />
                            {{ $t('detail.registrations') }}
                        </SecondaryButton>
                    </Link>
                    <Link :href="route('smartolt.config-backups.index', olt.id)">
                        <SecondaryButton type="button">
                            <Database class="mr-2 h-4 w-4" />
                            {{ $t('detail.backup_config') }}
                        </SecondaryButton>
                    </Link>
                    <PrimaryButton type="button" @click="refresh">
                        <RefreshCw class="mr-2 h-4 w-4" />
                        {{ $t('gponports.refresh_snmp') }}
                    </PrimaryButton>
                </div>
            </div>
        </template>

        <div class="min-h-[60vh] pt-5 pb-16 sm:pt-8">
            <div class="w-full space-y-6 px-4 sm:px-6 lg:px-8">

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="kv-stat">
                        <p class="text-xs font-medium uppercase tracking-wider text-slate-500">{{ $t('common.status') }}</p>
                        <p class="mt-3 text-2xl font-bold"
                            :class="snapshot.ok ? 'text-emerald-400' : 'text-slate-400'">
                            {{ snapshot.ok ? $t('common.online') : $t('common.unknown') }}
                        </p>
                    </div>
                    <div class="kv-stat">
                        <p class="text-xs font-medium uppercase tracking-wider text-slate-500">{{ $t('gponports.stat_gpon_port') }}</p>
                        <p class="mt-3 text-2xl font-bold text-white">{{ snapshot.ports.length }}</p>
                    </div>
                    <div class="kv-stat">
                        <p class="text-xs font-medium uppercase tracking-wider text-slate-500">{{ $t('detail.total_onu') }}</p>
                        <p class="mt-3 text-2xl font-bold text-white">
                            {{ onuOnline }}<span class="text-sm font-normal text-slate-500">{{ $t('detail.onu_online_suffix', { total: onuTotal }) }}</span>
                        </p>
                    </div>
                    <div class="kv-stat">
                        <p class="text-xs font-medium uppercase tracking-wider text-slate-500">{{ $t('common.last_refresh') }}</p>
                        <p class="mt-3 text-sm font-semibold text-white">{{ formatDate(snapshot.last_tested_at) }}</p>
                        <p class="mt-1 text-xs" :class="olt.polling_enabled ? 'text-emerald-400' : 'text-slate-400'">
                            {{ $t('smartolt.col_autopoll') }} {{ olt.polling_enabled ? $t('common.on') : $t('common.off') }}
                        </p>
                    </div>
                </div>

                <div class="grid gap-6 lg:grid-cols-2">
                    <div class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
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

                    <div class="flex items-center justify-center overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
                        <img v-if="oltImage" :src="oltImage" :alt="olt.name" class="max-h-96 w-full object-contain p-8" />
                        <div v-else class="flex flex-col items-center justify-center gap-2 py-16 text-slate-300">
                            <Router class="h-16 w-16" />
                            <span class="text-sm text-slate-400">{{ $t('detail.image_unavailable') }}</span>
                        </div>
                    </div>
                </div>

                <!-- Visualisasi Chassis -->
                <OltChassis
                    :olt-id="olt.id"
                    :cards="cards"
                    :ports="snapshot.ports"
                    :interfaces="interfaces"
                    :model="olt.name"
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
