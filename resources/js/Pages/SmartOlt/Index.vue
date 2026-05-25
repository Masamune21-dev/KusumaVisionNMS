<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
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

const destroyOlt = (olt) => {
    if (!window.confirm(`Hapus OLT ${olt.name}?`)) {
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
                <div class="flex gap-2">
                    <Link :href="route('smartolt.profiles.index')">
                        <SecondaryButton>
                            <Database class="mr-2 h-4 w-4" />
                            Profile
                        </SecondaryButton>
                    </Link>
                    <Link :href="route('smartolt.create')">
                        <PrimaryButton>
                            <Plus class="mr-2 h-4 w-4" />
                            Tambah OLT
                        </PrimaryButton>
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div
                    v-if="flash.success"
                    class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800"
                >
                    {{ flash.success }}
                </div>
                <div
                    v-if="flash.error"
                    class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"
                >
                    {{ flash.error }}
                </div>

                <div class="overflow-hidden rounded-lg bg-white shadow-sm">
                    <div class="border-b border-gray-200 px-6 py-4">
                        <div class="flex items-center gap-3">
                            <Cable class="h-5 w-5 text-gray-500" />
                            <div>
                                <h3 class="text-base font-semibold text-gray-900">
                                    OLT Inventory
                                </h3>
                                <p class="text-sm text-gray-500">
                                    SNMP inventory dan test koneksi awal untuk ZTE C300/C320.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div v-if="olts.length === 0" class="px-6 py-12 text-center">
                        <Cable class="mx-auto h-10 w-10 text-gray-300" />
                        <h3 class="mt-3 text-sm font-semibold text-gray-900">
                            Belum ada OLT
                        </h3>
                        <p class="mt-1 text-sm text-gray-500">
                            Tambahkan OLT pertama untuk mulai test SNMP.
                        </p>
                        <div class="mt-5">
                            <Link :href="route('smartolt.create')">
                                <PrimaryButton>
                                    <Plus class="mr-2 h-4 w-4" />
                                    Tambah OLT
                                </PrimaryButton>
                            </Link>
                        </div>
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">
                                        OLT
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">
                                        SNMP
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">
                                        Driver
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">
                                        Test Terakhir
                                    </th>
                                    <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-600">
                                        Aksi
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white">
                                <tr v-for="olt in olts" :key="olt.id">
                                    <td class="px-6 py-4">
                                        <div class="font-medium text-gray-900">
                                            {{ olt.name }}
                                        </div>
                                        <div class="text-sm text-gray-500">
                                            {{ olt.vendor || 'Vendor belum diisi' }}
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-700">
                                        <div>{{ olt.ip }}:{{ olt.snmp_port }}</div>
                                        <div class="text-xs uppercase text-gray-500">
                                            {{ olt.snmp_version }}
                                        </div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <span
                                            class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium"
                                            :class="olt.driver === 'zte'
                                                ? 'bg-sky-100 text-sky-800'
                                                : 'bg-gray-100 text-gray-700'"
                                        >
                                            {{ olt.capabilities.vendor_family }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-sm">
                                        <div
                                            class="font-medium"
                                            :class="olt.last_test_result?.ok ? 'text-emerald-700' : 'text-gray-700'"
                                        >
                                            {{ olt.last_test_result?.ok ? 'OK' : (olt.last_test_result ? 'Gagal' : 'Belum dites') }}
                                        </div>
                                        <div class="text-xs text-gray-500">
                                            {{ formatDate(olt.last_tested_at) }}
                                        </div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="flex justify-end gap-2">
                                            <Link :href="route('smartolt.detail', olt.id)">
                                                <SecondaryButton type="button">
                                                    <Eye class="mr-2 h-4 w-4" />
                                                    Detail
                                                </SecondaryButton>
                                            </Link>
                                            <SecondaryButton type="button" @click="testOlt(olt)">
                                                <RefreshCw class="mr-2 h-4 w-4" />
                                                Test
                                            </SecondaryButton>
                                            <Link :href="route('smartolt.edit', olt.id)">
                                                <SecondaryButton type="button">
                                                    <Pencil class="mr-2 h-4 w-4" />
                                                    Edit
                                                </SecondaryButton>
                                            </Link>
                                            <button
                                                type="button"
                                                class="inline-flex items-center rounded-md border border-red-200 bg-white px-4 py-2 text-xs font-semibold uppercase tracking-widest text-red-700 shadow-sm transition duration-150 ease-in-out hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2"
                                                @click="destroyOlt(olt)"
                                            >
                                                <Trash2 class="mr-2 h-4 w-4" />
                                                Hapus
                                            </button>
                                        </div>
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
