<script setup>
import ConfirmModal from '@/Components/ConfirmModal.vue';
import IconButton from '@/Components/IconButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, ClipboardList, Play } from '@lucide/vue';
import { computed } from 'vue';

const props = defineProps({
    olt: {
        type: Object,
        required: true,
    },
    registrations: {
        type: Array,
        required: true,
    },
});

const page = usePage();
const flash = computed(() => page.props.flash ?? {});
const { confirmState, confirm, handleConfirm, handleCancel } = useConfirm();

const formatDate = (value) => {
    if (!value) return '-';

    return new Intl.DateTimeFormat('id-ID', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
};

const executeRegistration = async (registration) => {
    const ok = await confirm({
        title: 'Eksekusi Provisioning',
        message: `Eksekusi script provisioning untuk ${registration.pon_port} ke OLT?`,
        confirmLabel: 'Eksekusi',
        variant: 'primary',
    });

    if (!ok) {
        return;
    }

    router.post(route('smartolt.registrations.execute', {
        olt: props.olt.id,
        registration: registration.id,
    }), {}, {
        preserveScroll: true,
    });
};

const statusClass = (status) => ({
    generated: 'bg-slate-500/15 text-slate-400 ring-1 ring-slate-500/25',
    executed:  'bg-emerald-500/15 text-emerald-300 ring-1 ring-emerald-500/25',
    failed:    'bg-red-500/15 text-red-300 ring-1 ring-red-500/25',
}[status] ?? 'bg-slate-500/15 text-slate-400 ring-1 ring-slate-500/25');
</script>

<template>
    <Head title="Registration History" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="text-xl font-semibold leading-tight text-gray-800">Registration History</h2>
                    <p class="mt-1 text-sm text-gray-500">{{ olt.name }}</p>
                </div>
                <Link :href="route('smartolt.unconfigured-all', { olt_id: olt.id })">
                    <SecondaryButton type="button">
                        <ArrowLeft class="mr-2 h-4 w-4" />
                        Unconfigured
                    </SecondaryButton>
                </Link>
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

                <div class="overflow-hidden rounded-2xl border border-white/10 bg-white/[0.06] shadow-2xl backdrop-blur-xl">
                    <div class="flex items-center gap-3 border-b border-white/10 px-6 py-5">
                        <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-violet-500/20 ring-1 ring-violet-500/30">
                            <ClipboardList class="h-5 w-5 text-violet-400" />
                        </div>
                        <h3 class="text-base font-semibold text-white">Generated Scripts</h3>
                    </div>

                    <div v-if="registrations.length === 0" class="px-6 py-10 text-center text-sm text-slate-400">
                        Belum ada provisioning script.
                    </div>

                    <div v-else class="divide-y divide-white/[0.06]">
                        <div v-for="registration in registrations" :key="registration.id" class="p-6">
                            <div class="flex flex-col gap-2 lg:flex-row lg:items-start lg:justify-between">
                                <div>
                                    <div class="font-semibold text-slate-100">
                                        {{ registration.customer_name }} · {{ registration.pon_port }}
                                    </div>
                                    <div class="text-sm text-slate-400">
                                        {{ registration.serial_number }} · VLAN {{ registration.vlan }} · {{ registration.wan_mode }} · {{ formatDate(registration.created_at) }}
                                    </div>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="inline-flex w-fit rounded-full px-2.5 py-1 text-xs font-medium" :class="statusClass(registration.status)">
                                        {{ registration.status }}
                                    </span>
                                    <IconButton variant="success" title="Eksekusi ke OLT" @click="executeRegistration(registration)">
                                        <Play class="h-4 w-4" />
                                    </IconButton>
                                </div>
                            </div>
                            <pre class="mt-4 overflow-x-auto rounded-xl bg-slate-950 p-4 text-xs text-slate-300 border border-white/[0.06]">{{ registration.cli_script }}</pre>
                            <div v-if="registration.executed_at || registration.execution_output || registration.execution_error" class="mt-4 space-y-2">
                                <div class="text-xs font-medium uppercase tracking-wide text-slate-500">
                                    Execution · {{ formatDate(registration.executed_at) }}
                                </div>
                                <div v-if="registration.execution_error" class="flex items-center gap-3 rounded-xl border border-red-500/25 bg-red-500/10 px-4 py-3 text-sm text-red-300 backdrop-blur-sm">
                                    {{ registration.execution_error }}
                                </div>
                                <pre v-if="registration.execution_output" class="overflow-x-auto rounded-xl bg-slate-950 p-4 text-xs text-slate-300 border border-white/[0.06]">{{ registration.execution_output }}</pre>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <ConfirmModal :state="confirmState" @confirm="handleConfirm" @cancel="handleCancel" />
    </AuthenticatedLayout>
</template>
