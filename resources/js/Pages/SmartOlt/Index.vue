<script setup>
import ConfirmModal from '@/Components/ConfirmModal.vue';
import IconButton from '@/Components/IconButton.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Cable, Database, Eye, Pencil, Plus, RefreshCw, Trash2 } from '@lucide/vue';
import { computed } from 'vue';

defineProps({
    olts: {
        type: Array,
        required: true,
    },
});

const page = usePage();
const flash = computed(() => page.props.flash ?? {});
const { confirmState, confirm, handleConfirm, handleCancel } = useConfirm();

const destroyOlt = async (olt) => {
    const ok = await confirm({
        title: 'Hapus OLT',
        message: `Hapus OLT ${olt.name}? Tindakan ini permanen.`,
        confirmLabel: 'Hapus',
    });

    if (!ok) {
        return;
    }

    router.delete(route('smartolt.destroy', olt.id), {
        preserveScroll: true,
    });
};

const testOlt = (olt) => {
    router.post(route('smartolt.test', olt.id), {}, {
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
</script>

<template>
    <Head title="SmartOLT" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    SmartOLT
                </h2>
                <Link :href="route('smartolt.create')">
                    <PrimaryButton>
                        <Plus class="mr-2 h-4 w-4" />
                        Tambah OLT
                    </PrimaryButton>
                </Link>
            </div>
        </template>

        <div class="bg-gradient-to-br from-slate-900 via-slate-800 to-indigo-950 py-8 pb-16 min-h-[60vh]">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <!-- Flash messages -->
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

                <!-- Glass card -->
                <div class="overflow-hidden rounded-2xl border border-white/10 bg-white/[0.06] shadow-2xl backdrop-blur-xl">
                    <!-- Card header -->
                    <div class="flex items-center gap-3 border-b border-white/10 px-6 py-5">
                        <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-sky-500/20 ring-1 ring-sky-500/30">
                            <Cable class="h-5 w-5 text-sky-400" />
                        </div>
                        <div>
                            <h3 class="text-base font-semibold text-white">OLT Inventory</h3>
                            <p class="text-xs text-slate-400">SNMP inventory & test koneksi ZTE C300/C320</p>
                        </div>
                    </div>

                    <!-- Empty state -->
                    <div v-if="olts.length === 0" class="px-6 py-16 text-center">
                        <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-white/[0.06] ring-1 ring-white/10">
                            <Cable class="h-7 w-7 text-slate-500" />
                        </div>
                        <h3 class="text-sm font-semibold text-slate-200">Belum ada OLT</h3>
                        <p class="mt-1 text-sm text-slate-400">Tambahkan OLT pertama untuk mulai test SNMP.</p>
                        <div class="mt-5">
                            <Link :href="route('smartolt.create')">
                                <PrimaryButton>
                                    <Plus class="mr-2 h-4 w-4" />
                                    Tambah OLT
                                </PrimaryButton>
                            </Link>
                        </div>
                    </div>

                    <!-- Table -->
                    <div v-else class="overflow-x-auto">
                        <table class="min-w-full">
                            <thead>
                                <tr class="border-b border-white/[0.06] bg-white/[0.03]">
                                    <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">OLT</th>
                                    <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">SNMP</th>
                                    <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">Driver</th>
                                    <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">Test Terakhir</th>
                                    <th class="px-6 py-3.5 text-center text-xs font-semibold uppercase tracking-wider text-slate-400">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/[0.05]">
                                <tr
                                    v-for="olt in olts"
                                    :key="olt.id"
                                    class="transition-colors duration-150 hover:bg-white/[0.04]"
                                >
                                    <td class="px-6 py-4">
                                        <div class="font-semibold text-slate-100">{{ olt.name }}</div>
                                        <div class="mt-0.5 text-xs text-slate-500">{{ olt.vendor || 'Vendor belum diisi' }}</div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="font-mono text-sm text-slate-200">{{ olt.ip }}:{{ olt.snmp_port }}</div>
                                        <div class="mt-0.5 text-xs uppercase tracking-widest text-slate-500">{{ olt.snmp_version }}</div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="space-y-1.5">
                                            <span
                                                class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ring-1"
                                                :class="olt.driver === 'zte'
                                                    ? 'bg-sky-500/15 text-sky-300 ring-sky-500/25'
                                                    : 'bg-slate-500/15 text-slate-400 ring-slate-500/25'"
                                            >
                                                {{ olt.capabilities.vendor_family }}
                                            </span>
                                            <div
                                                class="flex items-center gap-1.5 text-xs"
                                                :class="olt.polling_enabled ? 'text-emerald-400' : 'text-slate-500'"
                                            >
                                                <span
                                                    class="h-1.5 w-1.5 rounded-full"
                                                    :class="olt.polling_enabled ? 'bg-emerald-400' : 'bg-slate-600'"
                                                ></span>
                                                Auto-poll: {{ olt.polling_enabled ? 'On' : 'Off' }}
                                            </div>
                                            <div v-if="olt.polling_enabled" class="text-xs text-slate-500">
                                                {{ olt.poll_interval_minutes }}m · RX {{ olt.rx_poll_interval_minutes }}m
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="flex items-center gap-2">
                                            <span
                                                class="inline-flex h-2 w-2 flex-shrink-0 rounded-full"
                                                :class="olt.last_test_result?.ok
                                                    ? 'bg-emerald-400 shadow-[0_0_8px_rgba(52,211,153,0.7)]'
                                                    : olt.last_test_result
                                                        ? 'bg-red-400'
                                                        : 'bg-slate-600'"
                                            ></span>
                                            <span
                                                class="text-sm font-semibold"
                                                :class="olt.last_test_result?.ok
                                                    ? 'text-emerald-400'
                                                    : olt.last_test_result
                                                        ? 'text-red-400'
                                                        : 'text-slate-500'"
                                            >
                                                {{ olt.last_test_result?.ok ? 'OK' : (olt.last_test_result ? 'Gagal' : 'Belum dites') }}
                                            </span>
                                        </div>
                                        <div class="mt-1 text-xs text-slate-500">{{ formatDate(olt.last_tested_at) }}</div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="flex justify-center gap-1.5">
                                            <IconButton :href="route('smartolt.detail', olt.id)" title="Detail">
                                                <Eye class="h-4 w-4" />
                                            </IconButton>
                                            <IconButton title="Test SNMP" @click="testOlt(olt)">
                                                <RefreshCw class="h-4 w-4" />
                                            </IconButton>
                                            <IconButton :href="route('smartolt.edit', olt.id)" title="Edit">
                                                <Pencil class="h-4 w-4" />
                                            </IconButton>
                                            <IconButton :href="route('smartolt.profiles.index', olt.id)" title="Profile">
                                                <Database class="h-4 w-4" />
                                            </IconButton>
                                            <IconButton variant="danger" title="Hapus OLT" @click="destroyOlt(olt)">
                                                <Trash2 class="h-4 w-4" />
                                            </IconButton>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <ConfirmModal :state="confirmState" @confirm="handleConfirm" @cancel="handleCancel" />
    </AuthenticatedLayout>
</template>
