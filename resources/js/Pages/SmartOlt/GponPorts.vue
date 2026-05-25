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

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">

                <div v-if="flash.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    {{ flash.success }}
                </div>
                <div v-if="flash.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                    {{ flash.error }}
                </div>

                <!-- Summary mini cards -->
                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="rounded-lg bg-white p-5 shadow-sm">
                        <div class="text-sm font-medium text-gray-500">GPON Port</div>
                        <div class="mt-2 text-2xl font-semibold text-gray-900">{{ snapshot.ports.length }}</div>
                    </div>
                    <div class="rounded-lg bg-white p-5 shadow-sm">
                        <div class="text-sm font-medium text-gray-500">ONU Online</div>
                        <div class="mt-2 text-2xl font-semibold text-emerald-700">{{ onuOnline }}<span class="text-sm text-gray-400"> / {{ onuTotal }}</span></div>
                    </div>
                    <div class="rounded-lg bg-white p-5 shadow-sm">
                        <div class="text-sm font-medium text-gray-500">Status SNMP</div>
                        <div class="mt-2 text-2xl font-semibold" :class="snapshot.ok ? 'text-emerald-700' : 'text-gray-400'">
                            {{ snapshot.ok ? 'Online' : 'Unknown' }}
                        </div>
                    </div>
                </div>

                <!-- Port grid -->
                <div class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="flex flex-col gap-4 border-b border-gray-900 px-5 py-4 md:flex-row md:items-center md:justify-between">
                        <div class="flex items-center gap-2">
                            <Cable class="h-5 w-5 text-gray-900" />
                            <h3 class="text-base font-semibold uppercase text-gray-900">GPON Port & ONU</h3>
                        </div>

                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                            <input
                                v-model="portSearch"
                                type="search"
                                class="h-9 w-full border-0 bg-transparent px-3 text-sm text-gray-700 placeholder:text-gray-500 focus:ring-0 sm:w-72"
                                placeholder="Cari ONU (SN/Nama)..."
                            />
                            <span class="inline-flex h-8 items-center gap-1.5 rounded-full bg-lime-400 px-3 text-xs font-semibold text-gray-950">
                                <CheckCircle2 class="h-4 w-4" />
                                Selesai
                            </span>
                        </div>
                    </div>

                    <div v-if="snapshot.ports.length === 0" class="px-6 py-10 text-center text-sm text-gray-500">
                        Belum ada data port. Jalankan <strong>Refresh SNMP</strong>.
                    </div>
                    <div v-else-if="filteredPorts.length === 0" class="px-6 py-10 text-center text-sm text-gray-500">
                        Port atau ONU tidak ditemukan.
                    </div>
                    <div v-else class="grid gap-3 p-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5">
                        <Link
                            v-for="port in filteredPorts"
                            :key="port.if_index"
                            :href="route('smartolt.port-onus', [olt.id, port.slot, port.port])"
                            class="block rounded-lg border p-4 transition hover:border-emerald-300 hover:bg-emerald-50/50"
                            :class="port.oper_status === 'up' ? 'border-emerald-200 bg-white' : 'border-gray-200 bg-gray-50'"
                        >
                            <div class="font-mono text-sm font-semibold text-gray-900">{{ port.name }}</div>

                            <div class="mt-4 flex items-end justify-between gap-3">
                                <span class="text-xs font-bold"
                                      :class="port.oper_status === 'up' ? 'text-emerald-600' : 'text-gray-500'">
                                    {{ portStatusLabel(port.oper_status) }}
                                </span>
                                <span class="text-xs text-gray-500">
                                    {{ port.online_onu_count ?? 0 }}/{{ port.onu_count ?? 0 }} ONU
                                </span>
                            </div>

                            <div v-if="isSearching && port.matching_onus.length"
                                 class="mt-3 space-y-1 border-t border-gray-100 pt-3">
                                <div v-for="onu in port.matching_onus.slice(0, 3)"
                                     :key="`${port.if_index}-${onu.onu_id}`"
                                     class="flex items-center justify-between gap-2 text-xs">
                                    <span class="truncate font-medium text-gray-700">{{ onuSummary(onu) }}</span>
                                    <span class="shrink-0 font-semibold"
                                          :class="onu.online ? 'text-emerald-600' : 'text-gray-400'">
                                        {{ onu.online ? 'ON' : 'OFF' }}
                                    </span>
                                </div>
                                <div v-if="port.matching_onus.length > 3" class="text-xs font-medium text-gray-500">
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
