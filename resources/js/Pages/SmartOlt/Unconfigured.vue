<script setup>
import IconButton from '@/Components/IconButton.vue';
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

        <div class="bg-gradient-to-br from-slate-900 via-slate-800 to-indigo-950 py-8 pb-16 min-h-[60vh]">
            <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                <div v-if="flash.success" class="mb-5 flex items-center gap-3 rounded-xl border border-emerald-500/25 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-300 backdrop-blur-sm">
                    <span class="h-2 w-2 flex-shrink-0 rounded-full bg-emerald-400 shadow-[0_0_6px_rgba(52,211,153,0.7)]"></span>
                    {{ flash.success }}
                </div>
                <div v-if="flash.error" class="mb-5 flex items-center gap-3 rounded-xl border border-red-500/25 bg-red-500/10 px-4 py-3 text-sm text-red-300 backdrop-blur-sm">
                    <span class="h-2 w-2 flex-shrink-0 rounded-full bg-red-400"></span>
                    {{ flash.error }}
                </div>

                <div class="grid gap-4 md:grid-cols-3">
                    <div class="rounded-xl border border-white/10 bg-white/[0.06] p-5 backdrop-blur-xl">
                        <p class="text-xs font-medium uppercase tracking-wider text-slate-400">Data</p>
                        <p class="mt-3 text-2xl font-bold" :class="snapshot.ok ? 'text-emerald-400' : 'text-slate-400'">
                            {{ snapshot.ok ? 'Tersedia' : 'Kosong' }}
                        </p>
                    </div>
                    <div class="rounded-xl border border-white/10 bg-white/[0.06] p-5 backdrop-blur-xl">
                        <p class="text-xs font-medium uppercase tracking-wider text-slate-400">ONU Baru</p>
                        <p class="mt-3 text-2xl font-bold text-white">{{ snapshot.count }}</p>
                    </div>
                    <div class="rounded-xl border border-white/10 bg-white/[0.06] p-5 backdrop-blur-xl">
                        <p class="text-xs font-medium uppercase tracking-wider text-slate-400">Refresh Terakhir</p>
                        <p class="mt-3 text-sm font-semibold text-white">{{ formatDate(snapshot.refreshed_at) }}</p>
                    </div>
                </div>

                <div class="overflow-hidden rounded-2xl border border-white/10 bg-white/[0.06] shadow-2xl backdrop-blur-xl">
                    <div class="flex items-center gap-3 border-b border-white/10 px-6 py-5">
                        <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-violet-500/20 ring-1 ring-violet-500/30">
                            <Wifi class="h-5 w-5 text-violet-400" />
                        </div>
                        <h3 class="text-base font-semibold text-white">ONU Terdeteksi</h3>
                    </div>

                    <div v-if="snapshot.onus.length === 0" class="px-6 py-10 text-center text-sm text-slate-400">
                        Belum ada data. Jalankan Refresh Discovery.
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="min-w-full">
                            <thead>
                                <tr class="border-b border-white/[0.06] bg-white/[0.03]">
                                    <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">Serial</th>
                                    <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">Port</th>
                                    <th class="px-6 py-3.5 text-center text-xs font-semibold uppercase tracking-wider text-slate-400">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/[0.05]">
                                <tr v-for="onu in snapshot.onus" :key="onu.serial_number"
                                    class="transition-colors duration-150 hover:bg-white/[0.04]">
                                    <td class="px-6 py-4 font-mono text-sm font-semibold text-slate-200">{{ onu.serial_number }}</td>
                                    <td class="px-6 py-4 text-sm text-slate-300">
                                        <span v-if="onu.slot && onu.port">Slot {{ onu.slot }} Port {{ onu.port }}</span>
                                        <span v-else class="text-slate-500">-</span>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="flex justify-center">
                                            <IconButton
                                                variant="primary"
                                                title="Register ONU"
                                                :href="route('smartolt.register', {
                                                    olt: olt.id,
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
            </div>
        </div>
    </AuthenticatedLayout>
</template>
