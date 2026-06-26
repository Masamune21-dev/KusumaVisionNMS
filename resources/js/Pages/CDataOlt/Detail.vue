<script setup>
import IconButton from '@/Components/IconButton.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { formatDateTime } from '@/lib/datetime';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, ChevronRight, RadioTower, RefreshCw, Server } from '@lucide/vue';
import { computed } from 'vue';

const props = defineProps({
    olt: { type: Object, required: true },
    snapshot: { type: Object, required: true },
});

const page = usePage();
const flash = computed(() => page.props.flash ?? {});
const system = computed(() => props.snapshot.system ?? {});
const ports = computed(() => props.snapshot.ports ?? []);
const counts = computed(() => props.snapshot.port_counts ?? {});

const portCount = (p) => counts.value[`${p.slot}_${p.port}`] ?? { count: 0, online: 0 };

const scan = () => router.post(route('cdata-olt.refresh', props.olt.id), {}, { preserveScroll: true });
const fmt = (v) => formatDateTime(v);
</script>

<template>
    <Head :title="`Detail ${olt.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-3">
                    <Link :href="route('smartolt.index', { tab: 'cdata' })" class="text-slate-400 hover:text-white">
                        <ArrowLeft class="h-5 w-5" />
                    </Link>
                    <h2 class="text-lg font-semibold leading-tight text-white sm:text-xl">{{ olt.name }}</h2>
                    <span class="kv-pill-info">{{ olt.capabilities.vendor_family }}</span>
                </div>
                <PrimaryButton class="w-full sm:w-auto" @click="scan">
                    <RefreshCw class="mr-2 h-4 w-4" />
                    Scan ONU
                </PrimaryButton>
            </div>
        </template>

        <div class="min-h-[60vh] pt-5 pb-16 sm:pt-8">
            <div class="w-full space-y-5 px-4 sm:px-6 lg:px-8">

                <!-- System info -->
                <div class="kv-glass-panel">
                    <div class="flex items-center gap-3 border-b border-white/10 px-4 py-4 sm:px-6">
                        <span class="kv-circle-sky !h-10 !w-10"><Server class="h-5 w-5" /></span>
                        <div>
                            <h3 class="text-base font-semibold text-white">Informasi Sistem</h3>
                            <p class="text-xs text-slate-400">{{ olt.ip }}:{{ olt.snmp_port }} · {{ olt.snmp_version }}</p>
                        </div>
                    </div>
                    <div class="grid gap-4 p-6 sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <p class="text-xs uppercase tracking-wider text-slate-500">Deskripsi</p>
                            <p class="mt-1 break-words text-sm text-slate-200">{{ system.sys_descr || '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs uppercase tracking-wider text-slate-500">Nama Sistem</p>
                            <p class="mt-1 text-sm text-slate-200">{{ system.sys_name || '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs uppercase tracking-wider text-slate-500">Uptime</p>
                            <p class="mt-1 text-sm text-slate-200">{{ system.sys_uptime || '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs uppercase tracking-wider text-slate-500">Firmware</p>
                            <p class="mt-1 text-sm">
                                <span v-if="snapshot.firmware_v3" class="kv-pill-muted">FlashV3.x (inventory via CLI)</span>
                                <span v-else class="text-slate-200">Legacy / SNMP</span>
                            </p>
                        </div>
                    </div>
                    <p v-if="snapshot.scanned_at" class="border-t border-white/10 px-6 py-3 text-xs text-slate-500">
                        Scan ONU terakhir: {{ fmt(snapshot.scanned_at) }}
                    </p>
                </div>

                <!-- Ports -->
                <div class="kv-glass-panel">
                    <div class="flex items-center gap-3 border-b border-white/10 px-4 py-4 sm:px-6">
                        <span class="kv-circle-sky !h-10 !w-10"><RadioTower class="h-5 w-5" /></span>
                        <div>
                            <h3 class="text-base font-semibold text-white">Port PON</h3>
                            <p class="text-xs text-slate-400">{{ ports.length }} port · {{ olt.capabilities.pon_label }}</p>
                        </div>
                    </div>

                    <div v-if="ports.length === 0" class="px-6 py-12 text-center text-sm text-slate-500">
                        Belum ada data port. Klik <span class="text-slate-300">Scan ONU</span> untuk memuat.
                    </div>

                    <div v-else class="kv-table-desktop">
                        <table class="w-full min-w-[640px]">
                            <thead>
                                <tr class="border-b border-white/10 bg-slate-950/40">
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">Port</th>
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">Status</th>
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">ONU</th>
                                    <th class="px-4 py-3.5 text-center text-xs font-semibold uppercase tracking-wider text-slate-400">Aksi</th>
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
                                        <span class="text-slate-500"> ({{ portCount(p).online }} online)</span>
                                    </td>
                                    <td class="px-4 py-4">
                                        <div class="flex justify-center">
                                            <IconButton :href="route('cdata-olt.port-onus', [olt.id, p.slot, p.port])" title="Lihat ONU">
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
                            :href="route('cdata-olt.port-onus', [olt.id, p.slot, p.port])"
                            class="kv-mobile-card block"
                        >
                            <div class="flex items-center justify-between">
                                <span class="font-mono text-sm text-white">{{ p.name }}</span>
                                <span class="text-xs" :class="p.oper_status === 'up' ? 'text-emerald-400' : 'text-slate-500'">{{ p.oper_status }}</span>
                            </div>
                            <p class="mt-1 text-xs text-slate-400">{{ portCount(p).count }} ONU · {{ portCount(p).online }} online</p>
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
