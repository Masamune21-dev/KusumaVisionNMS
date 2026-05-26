<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
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

const formatDate = (value) => {
    if (!value) {
        return '-';
    }

    return new Intl.DateTimeFormat('id-ID', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
};

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
    if (s === 'INSERVICE') return 'bg-emerald-500/15 text-emerald-300 ring-1 ring-emerald-500/25';
    if (s === 'STANDBY') return 'bg-amber-500/15 text-amber-300 ring-1 ring-amber-500/25';
    return 'bg-red-500/15 text-red-300 ring-1 ring-red-500/25';
};

const oltImage = computed(() => {
    const hay = (props.olt.name + ' ' + (props.olt.vendor ?? '')).toLowerCase();
    if (hay.includes('c320')) return '/img/c320.jpg';
    if (hay.includes('c300')) return '/img/c300.jpg';
    return null;
});
</script>

<template>
    <Head :title="`Detail ${olt.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="text-xl font-semibold leading-tight text-gray-800">
                        {{ olt.name }}
                    </h2>
                    <p class="mt-1 text-sm text-gray-500">
                        {{ olt.ip }}:{{ olt.snmp_port }} · {{ olt.capabilities.vendor_family }}
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
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

        <div class="bg-gradient-to-br from-slate-900 via-slate-800 to-indigo-950 py-8 pb-16 min-h-[60vh]">
            <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                <div
                    v-if="flash.success"
                    class="mb-5 flex items-center gap-3 rounded-xl border border-emerald-500/25 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-300 backdrop-blur-sm"
                >
                    <span class="h-2 w-2 flex-shrink-0 rounded-full bg-emerald-400 shadow-[0_0_6px_rgba(52,211,153,0.7)]"></span>
                    {{ flash.success }}
                </div>
                <div
                    v-if="flash.error"
                    class="mb-5 flex items-center gap-3 rounded-xl border border-red-500/25 bg-red-500/10 px-4 py-3 text-sm text-red-300 backdrop-blur-sm"
                >
                    <span class="h-2 w-2 flex-shrink-0 rounded-full bg-red-400"></span>
                    {{ flash.error }}
                </div>

                <div class="grid gap-4 md:grid-cols-4">
                    <div class="rounded-xl border border-white/10 bg-white/[0.06] p-5 backdrop-blur-xl">
                        <p class="text-xs font-medium uppercase tracking-wider text-slate-400">Status</p>
                        <p class="mt-3 text-2xl font-bold"
                            :class="snapshot.ok ? 'text-emerald-400' : 'text-slate-400'">
                            {{ snapshot.ok ? 'Online' : 'Unknown' }}
                        </p>
                    </div>
                    <div class="rounded-xl border border-white/10 bg-white/[0.06] p-5 backdrop-blur-xl">
                        <p class="text-xs font-medium uppercase tracking-wider text-slate-400">GPON Port</p>
                        <p class="mt-3 text-2xl font-bold text-white">{{ snapshot.ports.length }}</p>
                    </div>
                    <div class="rounded-xl border border-white/10 bg-white/[0.06] p-5 backdrop-blur-xl">
                        <p class="text-xs font-medium uppercase tracking-wider text-slate-400">Total ONU</p>
                        <p class="mt-3 text-2xl font-bold text-white">
                            {{ onuOnline }}<span class="text-sm font-normal text-slate-400"> / {{ onuTotal }} online</span>
                        </p>
                    </div>
                    <div class="rounded-xl border border-white/10 bg-white/[0.06] p-5 backdrop-blur-xl">
                        <p class="text-xs font-medium uppercase tracking-wider text-slate-400">Refresh Terakhir</p>
                        <p class="mt-3 text-sm font-semibold text-white">{{ formatDate(snapshot.last_tested_at) }}</p>
                        <p class="mt-1 text-xs" :class="olt.polling_enabled ? 'text-emerald-400' : 'text-slate-500'">
                            Auto-poll {{ olt.polling_enabled ? 'On' : 'Off' }} · {{ formatDate(olt.last_polled_at) }}
                        </p>
                    </div>
                </div>

                <div class="grid gap-6 lg:grid-cols-2">
                    <div class="overflow-hidden rounded-2xl border border-white/10 bg-white/[0.06] shadow-2xl backdrop-blur-xl">
                        <div class="flex items-center gap-3 border-b border-white/10 px-6 py-5">
                            <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-sky-500/20 ring-1 ring-sky-500/30">
                                <Server class="h-5 w-5 text-sky-400" />
                            </div>
                            <h3 class="text-base font-semibold text-white">System Info</h3>
                        </div>
                        <dl class="divide-y divide-white/[0.06]">
                            <div class="px-6 py-4">
                                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">sysName</dt>
                                <dd class="mt-1 break-words text-sm text-slate-200">{{ snapshot.system.sys_name || '-' }}</dd>
                            </div>
                            <div class="px-6 py-4">
                                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">sysDescr</dt>
                                <dd class="mt-1 break-words text-sm text-slate-200">{{ snapshot.system.sys_descr || '-' }}</dd>
                            </div>
                            <div class="px-6 py-4">
                                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">sysObjectID</dt>
                                <dd class="mt-1 break-words text-sm text-slate-200">{{ snapshot.system.sys_object_id || '-' }}</dd>
                            </div>
                            <div class="px-6 py-4">
                                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">sysUptime</dt>
                                <dd class="mt-1 break-words text-sm text-slate-200">{{ formatUptime(snapshot.system.sys_uptime) }}</dd>
                            </div>
                        </dl>
                    </div>

                    <div class="flex items-center justify-center overflow-hidden rounded-2xl border border-white/10 bg-white/[0.06] backdrop-blur-xl shadow-2xl">
                        <img v-if="oltImage" :src="oltImage" :alt="olt.name" class="max-h-96 w-full object-contain p-8" />
                        <div v-else class="flex flex-col items-center justify-center gap-2 py-16 text-slate-600">
                            <Router class="h-16 w-16" />
                            <span class="text-sm text-slate-500">Gambar tidak tersedia</span>
                        </div>
                    </div>
                </div>

                <!-- Status Card / Hardware -->
                <div class="overflow-hidden rounded-2xl border border-white/10 bg-white/[0.06] shadow-2xl backdrop-blur-xl">
                    <div class="flex flex-col gap-3 border-b border-white/10 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex items-center gap-3">
                            <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-sky-500/20 ring-1 ring-sky-500/30">
                                <Layers class="h-5 w-5 text-sky-400" />
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
                    <div v-if="cards.length === 0" class="px-5 py-10 text-center text-sm text-slate-400">
                        Belum ada data hardware tersimpan.
                    </div>
                    <div v-else class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-white/[0.06] bg-white/[0.03]">
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">Rack/Shelf/Slot</th>
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">Tipe</th>
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">Real</th>
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">Port</th>
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">HW Ver</th>
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">SW Ver</th>
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/[0.05]">
                                <tr v-for="card in cards" :key="`${card.rack}-${card.shelf}-${card.slot}`"
                                    class="transition-colors duration-150 hover:bg-white/[0.04]">
                                    <td class="px-4 py-4 font-mono text-sm text-slate-200">{{ card.rack }}/{{ card.shelf }}/{{ card.slot }}</td>
                                    <td class="px-4 py-4 font-semibold text-slate-100">{{ card.cfg_type }}</td>
                                    <td class="px-4 py-4 text-sm text-slate-300">{{ card.real_type || '—' }}</td>
                                    <td class="px-4 py-4 text-sm text-slate-300">{{ card.port_count }}</td>
                                    <td class="px-4 py-4 font-mono text-xs text-slate-400">{{ card.hard_ver || '—' }}</td>
                                    <td class="px-4 py-4 font-mono text-xs text-slate-400">{{ card.soft_ver || '—' }}</td>
                                    <td class="px-4 py-4">
                                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                              :class="cardStatusColor(card.status)">
                                            {{ card.status }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </AuthenticatedLayout>
</template>
