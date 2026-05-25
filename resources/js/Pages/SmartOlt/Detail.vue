<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Cable, CheckCircle2, ClipboardList, Layers, LayoutDashboard, Pencil, RefreshCw, Router, Server } from '@lucide/vue';
import { computed } from 'vue';

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

const refresh = () => {
    router.post(route('smartolt.refresh', props.olt.id), {}, {
        preserveScroll: true,
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
    if (s === 'INSERVICE') return 'text-green-600 bg-green-50';
    if (s === 'STANDBY') return 'text-yellow-600 bg-yellow-50';
    return 'text-red-600 bg-red-50';
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
                    <Link :href="route('smartolt.dashboard', olt.id)">
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

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                <div
                    v-if="flash.success"
                    class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800"
                >
                    {{ flash.success }}
                </div>
                <div
                    v-if="flash.error"
                    class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"
                >
                    {{ flash.error }}
                </div>

                <div class="grid gap-4 md:grid-cols-4">
                    <div class="rounded-lg bg-white p-5 shadow-sm">
                        <div class="text-sm font-medium text-gray-500">Status</div>
                        <div
                            class="mt-2 text-2xl font-semibold"
                            :class="snapshot.ok ? 'text-emerald-700' : 'text-gray-900'"
                        >
                            {{ snapshot.ok ? 'Online' : 'Unknown' }}
                        </div>
                    </div>
                    <div class="rounded-lg bg-white p-5 shadow-sm">
                        <div class="text-sm font-medium text-gray-500">GPON Port</div>
                        <div class="mt-2 text-2xl font-semibold text-gray-900">
                            {{ snapshot.ports.length }}
                        </div>
                    </div>
                    <div class="rounded-lg bg-white p-5 shadow-sm">
                        <div class="text-sm font-medium text-gray-500">Total ONU</div>
                        <div class="mt-2 text-2xl font-semibold text-gray-900">
                            {{ onuOnline }}<span class="text-sm text-gray-400"> / {{ onuTotal }} online</span>
                        </div>
                    </div>
                    <div class="rounded-lg bg-white p-5 shadow-sm">
                        <div class="text-sm font-medium text-gray-500">Refresh Terakhir</div>
                        <div class="mt-2 text-sm font-semibold text-gray-900">
                            {{ formatDate(snapshot.last_tested_at) }}
                        </div>
                        <div class="mt-1 text-xs" :class="olt.polling_enabled ? 'text-emerald-600' : 'text-gray-400'">
                            Auto-poll {{ olt.polling_enabled ? 'On' : 'Off' }} · {{ formatDate(olt.last_polled_at) }}
                        </div>
                    </div>
                </div>

                <div class="grid gap-6 lg:grid-cols-2">
                    <div class="rounded-lg bg-white shadow-sm">
                        <div class="flex items-center gap-3 border-b border-gray-200 px-6 py-4">
                            <Server class="h-5 w-5 text-gray-500" />
                            <h3 class="text-base font-semibold text-gray-900">
                                System Info
                            </h3>
                        </div>
                        <dl class="divide-y divide-gray-100">
                            <div class="px-6 py-4">
                                <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">sysName</dt>
                                <dd class="mt-1 break-words text-sm text-gray-900">{{ snapshot.system.sys_name || '-' }}</dd>
                            </div>
                            <div class="px-6 py-4">
                                <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">sysDescr</dt>
                                <dd class="mt-1 break-words text-sm text-gray-900">{{ snapshot.system.sys_descr || '-' }}</dd>
                            </div>
                            <div class="px-6 py-4">
                                <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">sysObjectID</dt>
                                <dd class="mt-1 break-words text-sm text-gray-900">{{ snapshot.system.sys_object_id || '-' }}</dd>
                            </div>
                            <div class="px-6 py-4">
                                <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">sysUptime</dt>
                                <dd class="mt-1 break-words text-sm text-gray-900">{{ formatUptime(snapshot.system.sys_uptime) }}</dd>
                            </div>
                        </dl>
                    </div>

                    <div class="flex items-center justify-center overflow-hidden rounded-lg bg-white shadow-sm">
                        <img
                            v-if="oltImage"
                            :src="oltImage"
                            :alt="olt.name"
                            class="max-h-96 w-full object-contain p-8"
                        />
                        <div v-else class="flex flex-col items-center justify-center gap-2 py-16 text-gray-300">
                            <Router class="h-16 w-16" />
                            <span class="text-sm">Gambar tidak tersedia</span>
                        </div>
                    </div>
                </div>

                <!-- Status Card / Hardware -->
                <div v-if="cards.length > 0" class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="flex items-center gap-2 border-b border-gray-200 px-5 py-4">
                        <Layers class="h-5 w-5 text-gray-500" />
                        <h3 class="text-base font-semibold text-gray-900">Status Card / Hardware</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-100 bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                                    <th class="px-4 py-3">Rack/Shelf/Slot</th>
                                    <th class="px-4 py-3">Tipe</th>
                                    <th class="px-4 py-3">Real</th>
                                    <th class="px-4 py-3">Port</th>
                                    <th class="px-4 py-3">HW Ver</th>
                                    <th class="px-4 py-3">SW Ver</th>
                                    <th class="px-4 py-3">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="card in cards" :key="`${card.rack}-${card.shelf}-${card.slot}`"
                                    class="transition-colors hover:bg-gray-50">
                                    <td class="px-4 py-3 font-mono text-gray-700">{{ card.rack }}/{{ card.shelf }}/{{ card.slot }}</td>
                                    <td class="px-4 py-3 font-semibold text-gray-800">{{ card.cfg_type }}</td>
                                    <td class="px-4 py-3 text-gray-600">{{ card.real_type || '—' }}</td>
                                    <td class="px-4 py-3 text-gray-600">{{ card.port_count }}</td>
                                    <td class="px-4 py-3 font-mono text-xs text-gray-500">{{ card.hard_ver || '—' }}</td>
                                    <td class="px-4 py-3 font-mono text-xs text-gray-500">{{ card.soft_ver || '—' }}</td>
                                    <td class="px-4 py-3">
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
