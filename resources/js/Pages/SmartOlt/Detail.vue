<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Cable, ClipboardList, Eye, Pencil, RefreshCw, Router, Server, Wifi } from '@lucide/vue';
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
    router.post(route('smartolt.refresh', props.olt.id), {}, {
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
    <Head :title="`Detail ${olt.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="text-xl font-semibold leading-tight text-gray-800">
                        {{ olt.name }}
                    </h2>
                    <p class="mt-1 text-sm text-gray-500">
                        {{ olt.ip }}:{{ olt.snmp_port }} · {{ olt.capabilities.vendor_family }}
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <Link :href="route('smartolt.index')">
                        <SecondaryButton type="button">
                            <ArrowLeft class="mr-2 h-4 w-4" />
                            Kembali
                        </SecondaryButton>
                    </Link>
                    <Link :href="route('smartolt.edit', olt.id)">
                        <SecondaryButton type="button">
                            <Pencil class="mr-2 h-4 w-4" />
                            Edit
                        </SecondaryButton>
                    </Link>
                    <Link :href="route('smartolt.unconfigured', olt.id)">
                        <SecondaryButton type="button">
                            <Wifi class="mr-2 h-4 w-4" />
                            Unconfigured
                        </SecondaryButton>
                    </Link>
                    <Link :href="route('smartolt.registrations', olt.id)">
                        <SecondaryButton type="button">
                            <ClipboardList class="mr-2 h-4 w-4" />
                            Registrasi
                        </SecondaryButton>
                    </Link>
                    <PrimaryButton type="button" @click="refresh">
                        <RefreshCw class="mr-2 h-4 w-4" />
                        Refresh SNMP
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
                        <div class="text-sm font-medium text-gray-500">Status</div>
                        <div
                            class="mt-2 text-2xl font-semibold"
                            :class="snapshot.ok ? 'text-emerald-700' : 'text-gray-900'"
                        >
                            {{ snapshot.ok ? 'Online' : 'Unknown' }}
                        </div>
                    </div>
                    <div class="rounded-lg bg-white p-5 shadow-sm">
                        <div class="text-sm font-medium text-gray-500">GPON Port</div>
                        <div class="mt-2 text-2xl font-semibold text-gray-900">
                            {{ snapshot.ports.length }}
                        </div>
                    </div>
                    <div class="rounded-lg bg-white p-5 shadow-sm">
                        <div class="text-sm font-medium text-gray-500">Latency</div>
                        <div class="mt-2 text-2xl font-semibold text-gray-900">
                            {{ snapshot.latency_ms ?? '-' }}<span v-if="snapshot.latency_ms != null" class="text-sm text-gray-500"> ms</span>
                        </div>
                    </div>
                    <div class="rounded-lg bg-white p-5 shadow-sm">
                        <div class="text-sm font-medium text-gray-500">Refresh Terakhir</div>
                        <div class="mt-2 text-sm font-semibold text-gray-900">
                            {{ formatDate(snapshot.last_tested_at) }}
                        </div>
                    </div>
                </div>

                <div class="grid gap-6 lg:grid-cols-2">
                    <div class="rounded-lg bg-white shadow-sm">
                        <div class="flex items-center gap-3 border-b border-gray-200 px-6 py-4">
                            <Server class="h-5 w-5 text-gray-500" />
                            <h3 class="text-base font-semibold text-gray-900">
                                System Info
                            </h3>
                        </div>
                        <dl class="divide-y divide-gray-100">
                            <div class="px-6 py-4">
                                <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">sysName</dt>
                                <dd class="mt-1 break-words text-sm text-gray-900">{{ snapshot.system.sys_name || '-' }}</dd>
                            </div>
                            <div class="px-6 py-4">
                                <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">sysDescr</dt>
                                <dd class="mt-1 break-words text-sm text-gray-900">{{ snapshot.system.sys_descr || '-' }}</dd>
                            </div>
                            <div class="px-6 py-4">
                                <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">sysObjectID</dt>
                                <dd class="mt-1 break-words text-sm text-gray-900">{{ snapshot.system.sys_object_id || '-' }}</dd>
                            </div>
                            <div class="px-6 py-4">
                                <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">sysUptime</dt>
                                <dd class="mt-1 break-words text-sm text-gray-900">{{ snapshot.system.sys_uptime || '-' }}</dd>
                            </div>
                        </dl>
                    </div>

                    <div class="rounded-lg bg-white shadow-sm">
                        <div class="flex items-center gap-3 border-b border-gray-200 px-6 py-4">
                            <Router class="h-5 w-5 text-gray-500" />
                            <h3 class="text-base font-semibold text-gray-900">
                                Capability
                            </h3>
                        </div>
                        <dl class="divide-y divide-gray-100">
                            <div class="flex items-center justify-between px-6 py-4">
                                <dt class="text-sm text-gray-600">Driver</dt>
                                <dd class="text-sm font-semibold text-gray-900">{{ olt.driver }}</dd>
                            </div>
                            <div class="flex items-center justify-between px-6 py-4">
                                <dt class="text-sm text-gray-600">Provisioning</dt>
                                <dd class="text-sm font-semibold text-gray-900">{{ olt.capabilities.supports_provisioning ? 'Ya' : 'Tidak' }}</dd>
                            </div>
                            <div class="flex items-center justify-between px-6 py-4">
                                <dt class="text-sm text-gray-600">CLI Detail ONU</dt>
                                <dd class="text-sm font-semibold text-gray-900">{{ olt.capabilities.supports_cli_onu_detail ? 'Ya' : 'Tidak' }}</dd>
                            </div>
                            <div class="flex items-center justify-between px-6 py-4">
                                <dt class="text-sm text-gray-600">ONU Toggle</dt>
                                <dd class="text-sm font-semibold text-gray-900">{{ olt.capabilities.supports_onu_toggle ? 'Ya' : 'Tidak' }}</dd>
                            </div>
                        </dl>
                    </div>
                </div>

                <div class="rounded-lg bg-white shadow-sm">
                    <div class="flex items-center gap-3 border-b border-gray-200 px-6 py-4">
                        <Cable class="h-5 w-5 text-gray-500" />
                        <div>
                            <h3 class="text-base font-semibold text-gray-900">
                                GPON Ports
                            </h3>
                            <p class="text-sm text-gray-500">
                                Diambil dari IF-MIB `ifDescr` dan `ifOperStatus`.
                            </p>
                        </div>
                    </div>

                    <div v-if="snapshot.ports.length === 0" class="px-6 py-10 text-center text-sm text-gray-500">
                        Belum ada data port. Jalankan Refresh SNMP.
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Port</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">ifIndex</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Slot</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Status</th>
                                    <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-600">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <tr v-for="port in snapshot.ports" :key="port.if_index">
                                    <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ port.name }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-700">{{ port.if_index }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-700">{{ port.slot }}/{{ port.port }}</td>
                                    <td class="px-6 py-4">
                                        <span
                                            class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium"
                                            :class="port.oper_status === 'up'
                                                ? 'bg-emerald-100 text-emerald-800'
                                                : 'bg-gray-100 text-gray-700'"
                                        >
                                            {{ port.oper_status }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <Link :href="route('smartolt.port-onus', [olt.id, port.slot, port.port])">
                                            <SecondaryButton type="button">
                                                <Eye class="mr-2 h-4 w-4" />
                                                ONU
                                            </SecondaryButton>
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
