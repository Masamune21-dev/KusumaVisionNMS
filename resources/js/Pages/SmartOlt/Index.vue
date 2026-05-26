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
                <h2 class="text-lg font-semibold leading-tight sm:text-xl text-slate-800">
                    SmartOLT
                </h2>
                <Link :href="route('smartolt.create')" class="sm:w-auto">
                    <PrimaryButton class="w-full sm:w-auto">
                        <Plus class="mr-2 h-4 w-4" />
                        Tambah OLT
                    </PrimaryButton>
                </Link>
            </div>
        </template>

        <div class="min-h-[60vh] pt-5 pb-16 sm:pt-8">
            <div class="w-full px-4 sm:px-6 lg:px-8">
                <!-- Flash messages -->
                <div
                    v-if="flash.success"
                    class="mb-5 flex items-center gap-3 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700"
                >
                    <span class="h-2 w-2 flex-shrink-0 rounded-full bg-emerald-500"></span>
                    {{ flash.success }}
                </div>
                <div
                    v-if="flash.error"
                    class="mb-5 flex items-center gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
                >
                    <span class="h-2 w-2 flex-shrink-0 rounded-full bg-red-500"></span>
                    {{ flash.error }}
                </div>

                <!-- Main card -->
                <div class="overflow-hidden rounded-lg border border-sky-200 bg-white shadow-sm shadow-sky-100/60">
                    <!-- Card header -->
                    <div class="flex items-center gap-3 border-b border-slate-100 px-4 py-4 sm:px-6">
                        <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-sky-100 ring-1 ring-sky-200">
                            <Cable class="h-5 w-5 text-sky-600" />
                        </div>
                        <div>
                            <h3 class="text-base font-semibold text-slate-900">OLT Inventory</h3>
                            <p class="text-xs text-slate-500">SNMP inventory & test koneksi ZTE C300/C320</p>
                        </div>
                    </div>

                    <!-- Empty state -->
                    <div v-if="olts.length === 0" class="px-6 py-16 text-center">
                        <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-slate-100 ring-1 ring-slate-200">
                            <Cable class="h-7 w-7 text-slate-400" />
                        </div>
                        <h3 class="text-sm font-semibold text-slate-700">Belum ada OLT</h3>
                        <p class="mt-1 text-sm text-slate-500">Tambahkan OLT pertama untuk mulai test SNMP.</p>
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
                        <table class="min-w-[720px] w-full">
                            <thead>
                                <tr class="border-b border-slate-100 bg-slate-50">
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">OLT</th>
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">SNMP</th>
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Driver</th>
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Test Terakhir</th>
                                    <th class="px-4 py-3.5 text-center text-xs font-semibold uppercase tracking-wider text-slate-500">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr
                                    v-for="olt in olts"
                                    :key="olt.id"
                                    class="transition-colors duration-150 hover:bg-slate-50"
                                >
                                    <td class="px-4 py-4">
                                        <div class="font-medium text-slate-900">{{ olt.name }}</div>
                                        <div class="mt-0.5 text-xs text-slate-500">{{ olt.vendor || 'Vendor belum diisi' }}</div>
                                    </td>
                                    <td class="px-4 py-4">
                                        <div class="font-mono text-xs text-slate-600">{{ olt.ip }}:{{ olt.snmp_port }}</div>
                                        <div class="mt-0.5 text-xs uppercase tracking-widest text-slate-500">{{ olt.snmp_version }}</div>
                                    </td>
                                    <td class="px-4 py-4">
                                        <div class="space-y-1.5">
                                            <span
                                                class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium ring-1"
                                                :class="olt.driver === 'zte'
                                                    ? 'bg-sky-50 text-sky-700 ring-sky-200'
                                                    : 'bg-slate-100 text-slate-600 ring-slate-200'"
                                            >
                                                {{ olt.capabilities.vendor_family }}
                                            </span>
                                            <div
                                                class="flex items-center gap-1.5 text-xs"
                                                :class="olt.polling_enabled ? 'text-emerald-600' : 'text-slate-400'"
                                            >
                                                <span
                                                    class="h-1.5 w-1.5 rounded-full"
                                                    :class="olt.polling_enabled ? 'bg-emerald-500' : 'bg-slate-300'"
                                                ></span>
                                                Auto-poll: {{ olt.polling_enabled ? 'On' : 'Off' }}
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-4">
                                        <div class="flex items-center gap-2">
                                            <span
                                                class="h-2 w-2 rounded-full"
                                                :class="olt.last_test_result?.ok
                                                    ? 'bg-emerald-500'
                                                    : olt.last_test_result
                                                        ? 'bg-red-500'
                                                        : 'bg-slate-300'"
                                            ></span>
                                            <span
                                                class="text-sm font-semibold"
                                                :class="olt.last_test_result?.ok
                                                    ? 'text-emerald-700'
                                                    : olt.last_test_result
                                                        ? 'text-red-700'
                                                        : 'text-slate-400'"
                                            >
                                                {{ olt.last_test_result?.ok ? 'OK' : (olt.last_test_result ? 'Gagal' : 'Belum dites') }}
                                            </span>
                                        </div>
                                        <div class="mt-1 text-xs text-slate-500">{{ formatDate(olt.last_tested_at) }}</div>
                                    </td>
                                    <td class="px-4 py-4">
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
