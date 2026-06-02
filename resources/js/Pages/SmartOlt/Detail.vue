<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { formatDateTime } from '@/lib/datetime';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Cable, CheckCircle2, ClipboardList, Layers, LayoutDashboard, Pencil, RefreshCw, Router, Server } from '@lucide/vue';
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

const cardStatusColor = (status) => {
    const s = String(status ?? '').toUpperCase();
    if (s === 'INSERVICE') return 'bg-emerald-500/15 text-emerald-300 ring-1 ring-emerald-500/30';
    if (s === 'STANDBY') return 'bg-amber-500/15 text-amber-300 ring-1 ring-amber-500/30';
    return 'bg-red-500/15 text-red-300 ring-1 ring-red-500/30';
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
                            Kembali
                        </SecondaryButton>
                    </Link>
                    <Link :href="route('smartolt.port-manager', olt.id)">
                        <SecondaryButton type="button">
                            <LayoutDashboard class="mr-2 h-4 w-4" />
                            Port Manager
                        </SecondaryButton>
                    </Link>
                    <Link :href="route('smartolt.edit', olt.id)">
                        <SecondaryButton type="button">
                            <Pencil class="mr-2 h-4 w-4" />
                            Edit
                        </SecondaryButton>
                    </Link>
                    <Link :href="route('smartolt.gpon-ports', olt.id)">
                        <SecondaryButton type="button">
                            <Cable class="mr-2 h-4 w-4" />
                            GPON Port & ONU
                        </SecondaryButton>
                    </Link>
                    <Link :href="route('smartolt.registrations', olt.id)">
                        <SecondaryButton type="button">
                            <ClipboardList class="mr-2 h-4 w-4" />
                            Registrasi
                        </SecondaryButton>
                    </Link>
                    <PrimaryButton type="button" @click="refresh">
                        <RefreshCw class="mr-2 h-4 w-4" />
                        Refresh SNMP
                    </PrimaryButton>
                </div>
            </div>
        </template>

        <div class="min-h-[60vh] pt-5 pb-16 sm:pt-8">
            <div class="w-full space-y-6 px-4 sm:px-6 lg:px-8">
                <div
                    v-if="flash.success"
                    class="mb-5 flex items-center gap-3 rounded-lg border border-emerald-500/30 bg-emerald-500/15 px-4 py-3 text-sm text-emerald-300"
                >
                    <span class="h-2 w-2 flex-shrink-0 rounded-full bg-emerald-500"></span>
                    {{ flash.success }}
                </div>
                <div
                    v-if="flash.error"
                    class="mb-5 flex items-center gap-3 rounded-lg border border-red-500/30 bg-red-500/15 px-4 py-3 text-sm text-red-300"
                >
                    <span class="h-2 w-2 flex-shrink-0 rounded-full bg-red-500"></span>
                    {{ flash.error }}
                </div>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-lg border border-white/10 bg-slate-900/40 backdrop-blur-xl p-5 shadow-sm shadow-black/30">
                        <p class="text-xs font-medium uppercase tracking-wider text-slate-500">Status</p>
                        <p class="mt-3 text-2xl font-bold"
                            :class="snapshot.ok ? 'text-emerald-400' : 'text-slate-400'">
                            {{ snapshot.ok ? 'Online' : 'Unknown' }}
                        </p>
                    </div>
                    <div class="rounded-lg border border-white/10 bg-slate-900/40 backdrop-blur-xl p-5 shadow-sm shadow-black/30">
                        <p class="text-xs font-medium uppercase tracking-wider text-slate-500">GPON Port</p>
                        <p class="mt-3 text-2xl font-bold text-white">{{ snapshot.ports.length }}</p>
                    </div>
                    <div class="rounded-lg border border-white/10 bg-slate-900/40 backdrop-blur-xl p-5 shadow-sm shadow-black/30">
                        <p class="text-xs font-medium uppercase tracking-wider text-slate-500">Total ONU</p>
                        <p class="mt-3 text-2xl font-bold text-white">
                            {{ onuOnline }}<span class="text-sm font-normal text-slate-500"> / {{ onuTotal }} online</span>
                        </p>
                    </div>
                    <div class="rounded-lg border border-white/10 bg-slate-900/40 backdrop-blur-xl p-5 shadow-sm shadow-black/30">
                        <p class="text-xs font-medium uppercase tracking-wider text-slate-500">Refresh Terakhir</p>
                        <p class="mt-3 text-sm font-semibold text-white">{{ formatDate(snapshot.last_tested_at) }}</p>
                        <p class="mt-1 text-xs" :class="olt.polling_enabled ? 'text-emerald-400' : 'text-slate-400'">
                            Auto-poll {{ olt.polling_enabled ? 'On' : 'Off' }}
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
                            <span class="text-sm text-slate-400">Gambar tidak tersedia</span>
                        </div>
                    </div>
                </div>

                <!-- Status Card / Hardware -->
                <div class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
                    <div class="flex flex-col gap-3 border-b border-white/10 px-4 py-4 sm:px-5 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex items-center gap-3">
                            <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-sky-500/15 ring-1 ring-cyan-500/30">
                                <Layers class="h-5 w-5 text-cyan-400" />
                            </div>
                            <div>
                                <h3 class="text-base font-semibold text-white">Status Card / Hardware</h3>
                                <p class="mt-0.5 text-xs text-slate-500">Refresh terakhir: {{ formatDate(hardwareLastRefresh) }}</p>
                            </div>
                        </div>
                        <SecondaryButton type="button" :disabled="hardwareRefreshing" @click="refreshHardware">
                            <RefreshCw class="mr-2 h-4 w-4" :class="{ 'animate-spin': hardwareRefreshing }" />
                            Refresh Hardware
                        </SecondaryButton>
                    </div>
                    <div v-if="cards.length === 0" class="px-5 py-10 text-center text-sm text-slate-500">
                        Belum ada data hardware tersimpan.
                    </div>
                    <template v-else>
                        <div class="kv-mobile-list">
                            <article v-for="card in cards" :key="`${card.rack}-${card.shelf}-${card.slot}`" class="kv-mobile-card">
                                <div class="kv-mobile-card-header">
                                    <div class="min-w-0">
                                        <h4 class="kv-mobile-card-title">{{ card.cfg_type }}</h4>
                                        <p class="kv-mobile-card-subtitle font-mono">Rack/Shelf/Slot {{ card.rack }}/{{ card.shelf }}/{{ card.slot }}</p>
                                    </div>
                                    <span
                                        class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium"
                                        :class="cardStatusColor(card.status)"
                                    >
                                        {{ card.status }}
                                    </span>
                                </div>
                                <div class="kv-mobile-fields">
                                    <div class="kv-mobile-field">
                                        <span class="kv-mobile-label">Real</span>
                                        <span class="kv-mobile-value">{{ card.real_type || '—' }}</span>
                                    </div>
                                    <div class="kv-mobile-field">
                                        <span class="kv-mobile-label">Port</span>
                                        <span class="kv-mobile-value">{{ card.port_count }}</span>
                                    </div>
                                    <div class="kv-mobile-field">
                                        <span class="kv-mobile-label">HW Ver</span>
                                        <span class="kv-mobile-value font-mono text-xs">{{ card.hard_ver || '—' }}</span>
                                    </div>
                                    <div class="kv-mobile-field">
                                        <span class="kv-mobile-label">SW Ver</span>
                                        <span class="kv-mobile-value font-mono text-xs">{{ card.soft_ver || '—' }}</span>
                                    </div>
                                </div>
                            </article>
                        </div>

                        <div class="kv-table-desktop">
                        <table class="min-w-[980px] w-full text-sm">
                            <thead>
                                <tr class="border-b border-white/10 bg-slate-950/40">
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Rack/Shelf/Slot</th>
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Tipe</th>
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Real</th>
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Port</th>
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">HW Ver</th>
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">SW Ver</th>
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/5">
                                <tr v-for="card in cards" :key="`${card.rack}-${card.shelf}-${card.slot}`"
                                    class="transition-colors duration-150 hover:bg-white/[0.03]">
                                    <td class="px-4 py-4 font-mono text-xs text-slate-300">{{ card.rack }}/{{ card.shelf }}/{{ card.slot }}</td>
                                    <td class="px-4 py-4 font-medium text-white">{{ card.cfg_type }}</td>
                                    <td class="px-4 py-4 text-sm text-slate-200">{{ card.real_type || '—' }}</td>
                                    <td class="px-4 py-4 text-sm text-slate-200">{{ card.port_count }}</td>
                                    <td class="px-4 py-4 font-mono text-xs text-slate-300">{{ card.hard_ver || '—' }}</td>
                                    <td class="px-4 py-4 font-mono text-xs text-slate-300">{{ card.soft_ver || '—' }}</td>
                                    <td class="px-4 py-4">
                                        <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium"
                                              :class="cardStatusColor(card.status)">
                                            {{ card.status }}
                                        </span>
                                    </td>
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
