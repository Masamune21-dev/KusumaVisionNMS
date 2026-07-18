<script setup>
import IconButton from '@/Components/IconButton.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Plus, RefreshCw, Wifi } from '@lucide/vue';
import { computed } from 'vue';
import { formatDateTime } from '@/lib/datetime';

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

const formatDate = (value) => formatDateTime(value);
</script>

<template>
    <Head :title="`Unconfigured ONU ${olt.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="text-lg font-semibold leading-tight sm:text-xl text-white">
                        {{ $t('unconfigured.title') }}
                    </h2>
                    <p class="mt-1 text-sm text-slate-500">
                        {{ olt.name }} · {{ olt.ip }}
                    </p>
                </div>

                <div class="grid gap-2 [&>a>button]:w-full [&>button]:w-full sm:flex sm:flex-wrap sm:[&>a>button]:w-auto sm:[&>button]:w-auto">
                    <Link :href="route('smartolt.detail', olt.id)">
                        <SecondaryButton type="button">
                            <ArrowLeft class="mr-2 h-4 w-4" />
                            {{ $t('common.detail_olt') }}
                        </SecondaryButton>
                    </Link>
                    <PrimaryButton type="button" @click="refresh">
                        <RefreshCw class="mr-2 h-4 w-4" />
                        {{ $t('common.refresh_discovery') }}
                    </PrimaryButton>
                </div>
            </div>
        </template>

        <div class="min-h-[60vh] pt-5 pb-16 sm:pt-8">
            <div class="w-full space-y-6 px-4 sm:px-6 lg:px-8">

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div class="kv-stat">
                        <p class="text-xs font-medium uppercase tracking-wider text-slate-500">{{ $t('unconfigured.stat_data') }}</p>
                        <p class="mt-3 text-2xl font-bold" :class="snapshot.ok ? 'text-emerald-400' : 'text-slate-400'">
                            {{ snapshot.ok ? $t('common.available') : $t('common.empty') }}
                        </p>
                    </div>
                    <div class="kv-stat">
                        <p class="text-xs font-medium uppercase tracking-wider text-slate-500">{{ $t('unconfigured.new_onu') }}</p>
                        <p class="mt-3 text-2xl font-bold text-white">{{ snapshot.count }}</p>
                    </div>
                    <div class="kv-stat">
                        <p class="text-xs font-medium uppercase tracking-wider text-slate-500">{{ $t('common.last_refresh') }}</p>
                        <p class="mt-3 text-sm font-semibold text-white">{{ formatDate(snapshot.refreshed_at) }}</p>
                    </div>
                </div>

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
                                            <span v-if="onu.model"> · {{ onu.model }}</span>
                                        </p>
                                    </div>
                                    <IconButton
                                        variant="primary"
                                        :title="$t('common.register_onu')"
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
                            </article>
                        </div>

                        <div class="kv-table-desktop">
                        <table class="min-w-[720px] w-full">
                            <thead>
                                <tr class="border-b border-white/10 bg-slate-950/40">
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $t('common.serial') }}</th>
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $t('common.type') }}</th>
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $t('common.port') }}</th>
                                    <th class="px-4 py-3.5 text-center text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $t('common.actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/5">
                                <tr v-for="onu in snapshot.onus" :key="onu.serial_number"
                                    class="transition-colors duration-150 hover:bg-white/[0.03]">
                                    <td class="px-4 py-4 font-mono text-xs text-slate-300">{{ onu.serial_number }}</td>
                                    <td class="px-4 py-4 text-sm text-slate-300">{{ onu.model || '—' }}</td>
                                    <td class="px-4 py-4 text-sm text-slate-200">
                                        <span v-if="onu.slot && onu.port">Slot {{ onu.slot }} Port {{ onu.port }}</span>
                                        <span v-else class="text-slate-400">-</span>
                                    </td>
                                    <td class="px-4 py-4">
                                        <div class="flex justify-center">
                                            <IconButton
                                                variant="primary"
                                                :title="$t('common.register_onu')"
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
                    </template>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
