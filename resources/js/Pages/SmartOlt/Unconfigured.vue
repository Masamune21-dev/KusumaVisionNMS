<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Plus, RefreshCw, Wifi } from '@lucide/vue';
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
});

const page = usePage();
const flash = computed(() => page.props.flash ?? {});

const refresh = () => {
    router.post(route('smartolt.unconfigured.refresh', props.olt.id), {}, {
        preserveScroll: true,
    });
};

const formatDate = (value) => {
    if (!value) return '-';

    return new Intl.DateTimeFormat('id-ID', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
};
</script>

<template>
    <Head :title="`Unconfigured ONU ${olt.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="text-xl font-semibold leading-tight text-gray-800">
                        Unconfigured ONU
                    </h2>
                    <p class="mt-1 text-sm text-gray-500">
                        {{ olt.name }} · {{ olt.ip }}
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
                        Refresh Discovery
                    </PrimaryButton>
                </div>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                <div v-if="flash.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    {{ flash.success }}
                </div>
                <div v-if="flash.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                    {{ flash.error }}
                </div>

                <div class="grid gap-4 md:grid-cols-3">
                    <div class="rounded-lg bg-white p-5 shadow-sm">
                        <div class="text-sm font-medium text-gray-500">Status Cache</div>
                        <div class="mt-2 text-2xl font-semibold" :class="snapshot.ok ? 'text-emerald-700' : 'text-gray-900'">
                            {{ snapshot.ok ? 'OK' : 'Empty' }}
                        </div>
                    </div>
                    <div class="rounded-lg bg-white p-5 shadow-sm">
                        <div class="text-sm font-medium text-gray-500">ONU Baru</div>
                        <div class="mt-2 text-2xl font-semibold text-gray-900">{{ snapshot.count }}</div>
                    </div>
                    <div class="rounded-lg bg-white p-5 shadow-sm">
                        <div class="text-sm font-medium text-gray-500">Refresh Terakhir</div>
                        <div class="mt-2 text-sm font-semibold text-gray-900">{{ formatDate(snapshot.refreshed_at) }}</div>
                    </div>
                </div>

                <div class="rounded-lg bg-white shadow-sm">
                    <div class="flex items-center gap-3 border-b border-gray-200 px-6 py-4">
                        <Wifi class="h-5 w-5 text-gray-500" />
                        <div>
                            <h3 class="text-base font-semibold text-gray-900">Detected ONU</h3>
                            <p class="text-sm text-gray-500">Discovery dari OID unconfigured ZTE.</p>
                        </div>
                    </div>

                    <div v-if="snapshot.onus.length === 0" class="px-6 py-10 text-center text-sm text-gray-500">
                        Belum ada data. Jalankan Refresh Discovery.
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Serial</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Port</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Source OID</th>
                                    <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-600">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <tr v-for="onu in snapshot.onus" :key="onu.serial_number">
                                    <td class="px-6 py-4 text-sm font-semibold text-gray-900">{{ onu.serial_number }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-700">
                                        <span v-if="onu.slot && onu.port">Slot {{ onu.slot }} Port {{ onu.port }}</span>
                                        <span v-else>-</span>
                                    </td>
                                    <td class="px-6 py-4 text-xs text-gray-500">{{ onu.source_oid }}</td>
                                    <td class="px-6 py-4 text-right">
                                        <Link
                                            :href="route('smartolt.register', {
                                                olt: olt.id,
                                                sn: onu.serial_number,
                                                slot: onu.slot,
                                                port: onu.port,
                                                oid_index: onu.oid_index,
                                                suggested_onu_id: onu.suggested_onu_id,
                                            })"
                                        >
                                            <PrimaryButton type="button">
                                                <Plus class="mr-2 h-4 w-4" />
                                                Register
                                            </PrimaryButton>
                                        </Link>
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
