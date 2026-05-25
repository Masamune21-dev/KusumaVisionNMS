<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, RefreshCw, Router, Wifi } from '@lucide/vue';
import { computed } from 'vue';

const props = defineProps({
    olt: {
        type: Object,
        required: true,
    },
    slot: {
        type: Number,
        required: true,
    },
    port: {
        type: Number,
        required: true,
    },
    snapshot: {
        type: Object,
        required: true,
    },
});

const page = usePage();
const flash = computed(() => page.props.flash ?? {});

const refresh = () => {
    router.post(route('smartolt.port-onus.refresh', [props.olt.id, props.slot, props.port]), {}, {
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
    <Head :title="`ONU ${olt.name} ${slot}/${port}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="text-xl font-semibold leading-tight text-gray-800">
                        ONU Slot {{ slot }} Port {{ port }}
                    </h2>
                    <p class="mt-1 text-sm text-gray-500">
                        {{ olt.name }} · {{ olt.ip }} · ifIndex {{ snapshot.if_index || '-' }}
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <Link :href="route('smartolt.detail', olt.id)">
                        <SecondaryButton type="button">
                            <ArrowLeft class="mr-2 h-4 w-4" />
                            Detail OLT
                        </SecondaryButton>
                    </Link>
                    <PrimaryButton type="button" @click="refresh">
                        <RefreshCw class="mr-2 h-4 w-4" />
                        Refresh ONU
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
                        <div class="text-sm font-medium text-gray-500">Status Cache</div>
                        <div
                            class="mt-2 text-2xl font-semibold"
                            :class="snapshot.ok ? 'text-emerald-700' : 'text-gray-900'"
                        >
                            {{ snapshot.ok ? 'OK' : 'Empty' }}
                        </div>
                    </div>
                    <div class="rounded-lg bg-white p-5 shadow-sm">
                        <div class="text-sm font-medium text-gray-500">Total ONU</div>
                        <div class="mt-2 text-2xl font-semibold text-gray-900">
                            {{ snapshot.count }}
                        </div>
                    </div>
                    <div class="rounded-lg bg-white p-5 shadow-sm">
                        <div class="text-sm font-medium text-gray-500">Online</div>
                        <div class="mt-2 text-2xl font-semibold text-gray-900">
                            {{ snapshot.onus.filter((onu) => onu.online).length }}
                        </div>
                    </div>
                    <div class="rounded-lg bg-white p-5 shadow-sm">
                        <div class="text-sm font-medium text-gray-500">Refresh Terakhir</div>
                        <div class="mt-2 text-sm font-semibold text-gray-900">
                            {{ formatDate(snapshot.refreshed_at) }}
                        </div>
                    </div>
                </div>

                <div class="rounded-lg bg-white shadow-sm">
                    <div class="flex items-center gap-3 border-b border-gray-200 px-6 py-4">
                        <Router class="h-5 w-5 text-gray-500" />
                        <div>
                            <h3 class="text-base font-semibold text-gray-900">
                                Registered ONU
                            </h3>
                            <p class="text-sm text-gray-500">
                                Diambil dari ZTE zxGponOntDevMgmtTable dan phase state table.
                            </p>
                        </div>
                    </div>

                    <div v-if="snapshot.onus.length === 0" class="px-6 py-10 text-center">
                        <Wifi class="mx-auto h-10 w-10 text-gray-300" />
                        <h3 class="mt-3 text-sm font-semibold text-gray-900">
                            Belum ada data ONU
                        </h3>
                        <p class="mt-1 text-sm text-gray-500">
                            Jalankan Refresh ONU untuk membaca ONU terdaftar di port ini.
                        </p>
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">ONU</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Serial</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Type</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Phase</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Admin</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Last Down</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <tr v-for="onu in snapshot.onus" :key="`${onu.if_index}-${onu.onu_id}`">
                                    <td class="px-6 py-4">
                                        <div class="text-sm font-semibold text-gray-900">
                                            {{ onu.interface }}
                                        </div>
                                        <div class="text-xs text-gray-500">
                                            {{ onu.name || onu.description || '-' }}
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-700">
                                        {{ onu.serial_number || '-' }}
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-700">
                                        {{ onu.type_name || '-' }}
                                    </td>
                                    <td class="px-6 py-4">
                                        <span
                                            class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium"
                                            :class="onu.online
                                                ? 'bg-emerald-100 text-emerald-800'
                                                : 'bg-gray-100 text-gray-700'"
                                        >
                                            {{ onu.phase_state }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-700">
                                        {{ onu.admin_state }}
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-700">
                                        {{ onu.last_down_cause }}
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
