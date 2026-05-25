<script setup>
import IconButton from '@/Components/IconButton.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ClipboardList, Plus, RefreshCw, Router, Wifi } from '@lucide/vue';
import { computed, ref } from 'vue';

const props = defineProps({
    olts: { type: Array, required: true },
    selected_olt: { type: Object, default: null },
    snapshot: { type: Object, default: null },
});

const page = usePage();
const flash = computed(() => page.props.flash ?? {});

const selectOlt = (oltId) => {
    router.get(route('smartolt.unconfigured-all'), { olt_id: oltId }, { preserveState: false });
};

const refreshing = ref(false);
const doRefresh = () => {
    if (!props.selected_olt) return;
    refreshing.value = true;
    router.post(route('smartolt.unconfigured.refresh', props.selected_olt.id), {}, {
        preserveScroll: true,
        onFinish: () => { refreshing.value = false; },
    });
};

const formatDate = (value) => {
    if (!value) return '-';
    return new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
};
</script>

<template>
    <Head title="Unconfigured ONU" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="text-xl font-semibold leading-tight text-gray-800">Unconfigured ONU</h2>
                    <p class="mt-1 text-sm text-gray-500">
                        {{ selected_olt ? selected_olt.name + ' · ' + selected_olt.ip : 'Pilih OLT untuk melihat ONU yang belum terdaftar' }}
                    </p>
                </div>

                <div v-if="selected_olt" class="flex flex-wrap gap-2">
                    <Link :href="route('smartolt.registrations', selected_olt.id)">
                        <SecondaryButton type="button">
                            <ClipboardList class="mr-2 h-4 w-4" />
                            Registration History
                        </SecondaryButton>
                    </Link>
                    <PrimaryButton type="button" :disabled="refreshing" @click="doRefresh">
                        <RefreshCw class="mr-2 h-4 w-4" :class="{ 'animate-spin': refreshing }" />
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

                <!-- OLT selector -->
                <div class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="flex items-center gap-2 border-b border-gray-100 px-5 py-4">
                        <Router class="h-5 w-5 text-gray-500" />
                        <h3 class="font-semibold text-gray-800">Pilih OLT</h3>
                    </div>
                    <div class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        <button
                            v-for="olt in olts"
                            :key="olt.id"
                            type="button"
                            class="flex items-center gap-3 rounded-lg border p-4 text-left transition hover:border-indigo-300 hover:bg-indigo-50/50"
                            :class="selected_olt?.id === olt.id
                                ? 'border-indigo-400 bg-indigo-50 ring-1 ring-indigo-300'
                                : 'border-gray-200 bg-white'"
                            @click="selectOlt(olt.id)"
                        >
                            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-gray-100 text-gray-500">
                                <Router class="h-5 w-5" />
                            </div>
                            <div class="min-w-0">
                                <div class="truncate font-semibold text-sm text-gray-800">{{ olt.name }}</div>
                                <div class="truncate text-xs text-gray-500 font-mono">{{ olt.ip }}</div>
                            </div>
                            <div v-if="selected_olt?.id === olt.id"
                                 class="ml-auto h-2 w-2 shrink-0 rounded-full bg-indigo-500">
                            </div>
                        </button>
                    </div>
                </div>

                <!-- Hasil unconfigured -->
                <template v-if="selected_olt && snapshot !== null">
                    <!-- Summary cards -->
                    <div class="grid gap-4 md:grid-cols-3">
                        <div class="rounded-lg bg-white p-5 shadow-sm">
                            <div class="text-sm font-medium text-gray-500">Data</div>
                            <div class="mt-2 text-2xl font-semibold" :class="snapshot.ok ? 'text-emerald-700' : 'text-gray-900'">
                                {{ snapshot.ok ? 'Tersedia' : 'Kosong' }}
                            </div>
                        </div>
                        <div class="rounded-lg bg-white p-5 shadow-sm">
                            <div class="text-sm font-medium text-gray-500">ONU Baru Terdeteksi</div>
                            <div class="mt-2 text-2xl font-semibold text-gray-900">{{ snapshot.count }}</div>
                        </div>
                        <div class="rounded-lg bg-white p-5 shadow-sm">
                            <div class="text-sm font-medium text-gray-500">Refresh Terakhir</div>
                            <div class="mt-2 text-sm font-semibold text-gray-900">{{ formatDate(snapshot.refreshed_at) }}</div>
                        </div>
                    </div>

                    <!-- Tabel ONU -->
                    <div class="rounded-lg bg-white shadow-sm">
                        <div class="flex items-center gap-3 border-b border-gray-200 px-6 py-4">
                            <Wifi class="h-5 w-5 text-gray-500" />
                            <h3 class="text-base font-semibold text-gray-900">ONU Terdeteksi</h3>
                        </div>

                        <div v-if="snapshot.onus.length === 0" class="px-6 py-10 text-center text-sm text-gray-500">
                            Belum ada data. Jalankan <strong>Refresh Discovery</strong>.
                        </div>

                        <div v-else class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Serial</th>
                                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Port</th>
                                        <th class="px-6 py-3 text-center text-xs font-semibold uppercase tracking-wide text-gray-600">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    <tr v-for="onu in snapshot.onus" :key="onu.serial_number">
                                        <td class="px-6 py-4 text-sm font-semibold text-gray-900">{{ onu.serial_number }}</td>
                                        <td class="px-6 py-4 text-sm text-gray-700">
                                            <span v-if="onu.slot && onu.port">Slot {{ onu.slot }} Port {{ onu.port }}</span>
                                            <span v-else>-</span>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="flex justify-center">
                                                <IconButton
                                                    variant="primary"
                                                    title="Register ONU"
                                                    :href="route('smartolt.register', {
                                                        olt: selected_olt.id,
                                                        sn: onu.serial_number,
                                                        slot: onu.slot,
                                                        port: onu.port,
                                                        oid_index: onu.oid_index,
                                                        suggested_onu_id: onu.suggested_onu_id,
                                                    })"
                                                >
                                                    <Plus class="h-4 w-4" />
                                                </IconButton>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </template>

                <!-- State awal: belum pilih OLT -->
                <div v-else-if="!selected_olt"
                     class="flex flex-col items-center justify-center rounded-lg border-2 border-dashed border-gray-200 bg-white py-16 text-gray-400">
                    <Wifi class="h-12 w-12 mb-3 opacity-40" />
                    <p class="text-sm font-medium">Pilih OLT di atas untuk melihat ONU yang belum terkonfigurasi</p>
                </div>

            </div>
        </div>
    </AuthenticatedLayout>
</template>
