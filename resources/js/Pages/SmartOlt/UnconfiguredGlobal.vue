<script setup>
import IconButton from '@/Components/IconButton.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ClipboardList, Plus, RefreshCw, Router, Wifi } from '@lucide/vue';
import { computed, ref } from 'vue';
import { formatDateTime } from '@/lib/datetime';

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

const formatDate = (value) => formatDateTime(value);
</script>

<template>
    <Head title="Unconfigured ONU" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="text-lg font-semibold leading-tight sm:text-xl text-white">{{ $t('unconfigured.title') }}</h2>
                </div>

                <div v-if="selected_olt" class="grid gap-2 [&>a>button]:w-full [&>button]:w-full sm:flex sm:flex-wrap sm:[&>a>button]:w-auto sm:[&>button]:w-auto">
                    <Link :href="route('smartolt.registrations', selected_olt.id)">
                        <SecondaryButton type="button">
                            <ClipboardList class="mr-2 h-4 w-4" />
                            {{ $t('unconfigured.registration_history') }}
                        </SecondaryButton>
                    </Link>
                    <PrimaryButton type="button" :disabled="refreshing" @click="doRefresh">
                        <RefreshCw class="mr-2 h-4 w-4" :class="{ 'animate-spin': refreshing }" />
                        {{ $t('common.refresh_discovery') }}
                    </PrimaryButton>
                </div>
            </div>
        </template>

        <div class="min-h-[60vh] pt-5 pb-16 sm:pt-8">
            <div class="w-full space-y-6 px-4 sm:px-6 lg:px-8">

                <!-- OLT selector -->
                <div class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
                    <div class="flex items-center gap-3 border-b border-white/10 px-4 py-4 sm:px-6">
                        <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-sky-500/15 ring-1 ring-cyan-500/30">
                            <Router class="h-5 w-5 text-cyan-400" />
                        </div>
                        <h3 class="text-base font-semibold text-white">{{ $t('unconfigured.select_olt') }}</h3>
                    </div>
                    <div class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        <button
                            v-for="olt in olts"
                            :key="olt.id"
                            type="button"
                            class="flex items-center gap-3 rounded-lg border p-4 text-left transition"
                            :class="selected_olt?.id === olt.id
                                ? 'border-cyan-500/40 bg-sky-500/15 ring-2 ring-cyan-500/30'
                                : 'border-white/10 bg-slate-900/40 backdrop-blur-xl hover:border-cyan-500/40 hover:bg-sky-500/15/30'"
                            @click="selectOlt(olt.id)"
                        >
                            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-slate-800/60 text-slate-500">
                                <Router class="h-5 w-5" />
                            </div>
                            <div class="min-w-0">
                                <div class="truncate text-sm font-semibold text-white">{{ olt.name }}</div>
                                <div class="truncate font-mono text-xs text-slate-500">{{ olt.ip }}</div>
                            </div>
                        </button>
                    </div>
                </div>

                <!-- Hasil unconfigured -->
                <template v-if="selected_olt && snapshot !== null">
                    <!-- Summary cards -->
                    <div class="grid gap-4 md:grid-cols-3">
                        <div class="kv-stat">
                            <p class="text-xs font-medium uppercase tracking-wider text-slate-500">{{ $t('unconfigured.stat_data') }}</p>
                            <p class="mt-3 text-2xl font-bold" :class="snapshot.ok ? 'text-emerald-400' : 'text-slate-400'">
                                {{ snapshot.ok ? $t('common.available') : $t('common.empty') }}
                            </p>
                        </div>
                        <div class="kv-stat">
                            <p class="text-xs font-medium uppercase tracking-wider text-slate-500">{{ $t('unconfigured.new_onu_detected') }}</p>
                            <p class="mt-3 text-2xl font-bold text-white">{{ snapshot.count }}</p>
                        </div>
                        <div class="kv-stat">
                            <p class="text-xs font-medium uppercase tracking-wider text-slate-500">{{ $t('common.last_refresh') }}</p>
                            <p class="mt-3 text-sm font-semibold text-white">{{ formatDate(snapshot.refreshed_at) }}</p>
                        </div>
                    </div>

                    <!-- Tabel ONU -->
                    <div class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
                        <div class="flex items-center gap-3 border-b border-white/10 px-4 py-4 sm:px-6">
                            <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-sky-500/15 ring-1 ring-cyan-500/30">
                                <Wifi class="h-5 w-5 text-cyan-400" />
                            </div>
                            <h3 class="text-base font-semibold text-white">{{ $t('unconfigured.detected_onu') }}</h3>
                        </div>

                        <div v-if="snapshot.onus.length === 0" class="px-6 py-10 text-center text-sm text-slate-500">
                            {{ $t('unconfigured.empty_discovery') }}
                        </div>

                        <template v-else>
                            <div class="kv-mobile-list">
                                <article v-for="onu in snapshot.onus" :key="onu.serial_number" class="kv-mobile-card">
                                    <div class="kv-mobile-card-header">
                                        <div class="min-w-0">
                                            <h4 class="kv-mobile-card-title font-mono">{{ onu.serial_number }}</h4>
                                            <p class="kv-mobile-card-subtitle">
                                                <span v-if="onu.slot && onu.port">Slot {{ onu.slot }} Port {{ onu.port }}</span>
                                                <span v-else>-</span>
                                            </p>
                                        </div>
                                        <IconButton
                                            variant="primary"
                                            :title="$t('common.register_onu')"
                                            :href="route('smartolt.register', {
                                                olt: selected_olt.id,
                                                sn: onu.serial_number,
                                                slot: onu.slot,
                                                port: onu.port,
                                                oid_index: onu.oid_index,
                                                suggested_onu_id: onu.suggested_onu_id,
                                                model: onu.model,
                                            })"
                                        >
                                            <Plus class="h-4 w-4" />
                                        </IconButton>
                                    </div>
                                </article>
                            </div>

                            <div class="kv-table-desktop">
                            <table class="min-w-[720px] w-full">
                                <thead>
                                    <tr class="border-b border-white/10 bg-slate-950/40">
                                        <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $t('common.serial') }}</th>
                                        <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $t('common.port') }}</th>
                                        <th class="px-6 py-3.5 text-center text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $t('common.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-white/5">
                                    <tr v-for="onu in snapshot.onus" :key="onu.serial_number"
                                        class="transition-colors duration-150 hover:bg-white/[0.03]">
                                        <td class="px-6 py-4 font-mono text-sm font-semibold text-white">{{ onu.serial_number }}</td>
                                        <td class="px-6 py-4 text-sm text-slate-200">
                                            <span v-if="onu.slot && onu.port">Slot {{ onu.slot }} Port {{ onu.port }}</span>
                                            <span v-else class="text-slate-400">-</span>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="flex justify-center">
                                                <IconButton
                                                    variant="primary"
                                                    :title="$t('common.register_onu')"
                                                    :href="route('smartolt.register', {
                                                        olt: selected_olt.id,
                                                        sn: onu.serial_number,
                                                        slot: onu.slot,
                                                        port: onu.port,
                                                        oid_index: onu.oid_index,
                                                        suggested_onu_id: onu.suggested_onu_id,
                                                        model: onu.model,
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
                        </template>
                    </div>
                </template>

                <!-- State awal: belum pilih OLT -->
                <div v-else-if="!selected_olt"
                     class="flex flex-col items-center justify-center rounded-lg border border-dashed border-white/10 py-16">
                    <Wifi class="h-12 w-12 mb-3 text-slate-300" />
                    <p class="text-sm font-medium text-slate-400">{{ $t('unconfigured.select_olt_hint') }}</p>
                </div>

            </div>
        </div>
    </AuthenticatedLayout>
</template>
