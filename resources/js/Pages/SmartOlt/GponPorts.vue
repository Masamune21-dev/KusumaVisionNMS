<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Cable, CheckCircle2, RefreshCw } from '@lucide/vue';
import { computed, ref } from 'vue';

const props = defineProps({
    olt: { type: Object, required: true },
    snapshot: { type: Object, required: true },
});

const page = usePage();
const flash = computed(() => page.props.flash ?? {});

const portSearch = ref('');
const isSearching = computed(() => portSearch.value.trim().length > 0);
const normalizeSearch = (value) => String(value ?? '').toLowerCase();

const filteredPorts = computed(() => {
    const term = portSearch.value.trim().toLowerCase();

    if (!term) {
        return props.snapshot.ports.map((port) => ({ ...port, matching_onus: [] }));
    }

    return props.snapshot.ports
        .map((port) => {
            const matchingOnus = (port.onu_search_items ?? []).filter(
                (onu) => normalizeSearch(onu.search_text).includes(term),
            );
            const portMatches = normalizeSearch(`${port.name} ${port.slot}/${port.port}`).includes(term);
            return { ...port, matching_onus: matchingOnus, port_matches: portMatches };
        })
        .filter((port) => port.port_matches || port.matching_onus.length > 0);
});

const onuTotal  = computed(() => props.snapshot.ports.reduce((s, p) => s + (p.onu_count ?? 0), 0));
const onuOnline = computed(() => props.snapshot.ports.reduce((s, p) => s + (p.online_onu_count ?? 0), 0));

const refreshing = ref(false);
const doRefresh = () => {
    refreshing.value = true;
    router.post(route('smartolt.refresh', props.olt.id), {}, {
        onFinish: () => { refreshing.value = false; },
    });
};

const portStatusLabel = (status) => String(status || 'unknown').toUpperCase();
const onuSummary = (onu) => onu.name || onu.description || onu.serial_number || onu.interface || '-';
</script>

<template>
    <Head :title="`GPON Port — ${olt.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="text-xl font-semibold leading-tight text-gray-800">
                        GPON Port & ONU
                    </h2>
                    <p class="mt-1 text-sm text-gray-500">
                        {{ olt.name }} · {{ olt.ip }}
                        <span class="mx-1">·</span>
                        <span class="text-emerald-600 font-medium">{{ onuOnline }}</span>
                        <span class="text-gray-400"> / {{ onuTotal }} ONU online</span>
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <Link :href="route('smartolt.detail', olt.id)">
                        <SecondaryButton type="button">
                            <ArrowLeft class="mr-2 h-4 w-4" />
                            Detail OLT
                        </SecondaryButton>
                    </Link>
                    <PrimaryButton type="button" :disabled="refreshing" @click="doRefresh">
                        <RefreshCw class="mr-2 h-4 w-4" :class="{ 'animate-spin': refreshing }" />
                        Refresh SNMP
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

                <!-- Summary mini cards -->
                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="rounded-xl border border-white/10 bg-white/[0.06] p-5 backdrop-blur-xl">
                        <p class="text-xs font-medium uppercase tracking-wider text-slate-400">GPON Port</p>
                        <p class="mt-3 text-2xl font-bold text-white">{{ snapshot.ports.length }}</p>
                    </div>
                    <div class="rounded-xl border border-white/10 bg-white/[0.06] p-5 backdrop-blur-xl">
                        <p class="text-xs font-medium uppercase tracking-wider text-slate-400">ONU Online</p>
                        <p class="mt-3 text-2xl font-bold text-white">{{ onuOnline }}<span class="text-sm font-normal text-slate-400"> / {{ onuTotal }}</span></p>
                    </div>
                    <div class="rounded-xl border border-white/10 bg-white/[0.06] p-5 backdrop-blur-xl">
                        <p class="text-xs font-medium uppercase tracking-wider text-slate-400">Status SNMP</p>
                        <p class="mt-3 text-2xl font-bold" :class="snapshot.ok ? 'text-emerald-400' : 'text-slate-400'">
                            {{ snapshot.ok ? 'Online' : 'Unknown' }}
                        </p>
                    </div>
                </div>

                <!-- Port grid -->
                <div class="overflow-hidden rounded-2xl border border-white/10 bg-white/[0.06] shadow-2xl backdrop-blur-xl">
                    <div class="flex flex-col gap-4 border-b border-white/10 px-5 py-4 md:flex-row md:items-center md:justify-between">
                        <div class="flex items-center gap-3">
                            <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-sky-500/20 ring-1 ring-sky-500/30">
                                <Cable class="h-5 w-5 text-sky-400" />
                            </div>
                            <h3 class="text-base font-semibold text-white">GPON Port & ONU</h3>
                        </div>

                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                            <input
                                v-model="portSearch"
                                type="search"
                                class="h-9 w-full rounded-lg border border-white/10 bg-white/[0.06] px-3 text-sm text-slate-200 placeholder:text-slate-500 focus:outline-none focus:ring-1 focus:ring-sky-500/50 sm:w-72"
                                placeholder="Cari ONU (SN/Nama)..."
                            />
                            <span class="inline-flex h-8 items-center gap-1.5 rounded-full bg-emerald-500/20 px-3 text-xs font-semibold text-emerald-300 ring-1 ring-emerald-500/30">
                                <CheckCircle2 class="h-4 w-4" />
                                Selesai
                            </span>
                        </div>
                    </div>

                    <div v-if="snapshot.ports.length === 0" class="px-6 py-10 text-center text-sm text-slate-400">
                        Belum ada data port. Jalankan <strong class="text-slate-200">Refresh SNMP</strong>.
                    </div>
                    <div v-else-if="filteredPorts.length === 0" class="px-6 py-10 text-center text-sm text-slate-400">
                        Port atau ONU tidak ditemukan.
                    </div>
                    <div v-else class="grid gap-3 p-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5">
                        <Link
                            v-for="port in filteredPorts"
                            :key="port.if_index"
                            :href="route('smartolt.port-onus', [olt.id, port.slot, port.port])"
                            class="block rounded-xl border p-4 transition hover:border-emerald-500/30 hover:bg-white/[0.08]"
                            :class="port.oper_status === 'up' ? 'border-emerald-500/20 bg-white/[0.06]' : 'border-white/[0.06] bg-white/[0.03]'"
                        >
                            <div class="font-mono text-sm font-semibold text-slate-200">{{ port.name }}</div>

                            <div class="mt-4 flex items-end justify-between gap-3">
                                <span class="text-xs font-bold"
                                      :class="port.oper_status === 'up' ? 'text-emerald-400' : 'text-slate-500'">
                                    {{ portStatusLabel(port.oper_status) }}
                                </span>
                                <span class="text-xs text-slate-500">
                                    {{ port.online_onu_count ?? 0 }}/{{ port.onu_count ?? 0 }} ONU
                                </span>
                            </div>

                            <div v-if="isSearching && port.matching_onus.length"
                                 class="mt-3 space-y-1 border-t border-white/[0.06] pt-3">
                                <div v-for="onu in port.matching_onus.slice(0, 3)"
                                     :key="`${port.if_index}-${onu.onu_id}`"
                                     class="flex items-center justify-between gap-2 text-xs">
                                    <span class="truncate font-medium text-slate-300">{{ onuSummary(onu) }}</span>
                                    <span class="shrink-0 font-semibold"
                                          :class="onu.online ? 'text-emerald-400' : 'text-slate-500'">
                                        {{ onu.online ? 'ON' : 'OFF' }}
                                    </span>
                                </div>
                                <div v-if="port.matching_onus.length > 3" class="text-xs font-medium text-slate-500">
                                    +{{ port.matching_onus.length - 3 }} ONU
                                </div>
                            </div>
                        </Link>
                    </div>
                </div>

            </div>
        </div>
    </AuthenticatedLayout>
</template>
