<script setup>
import ConfirmModal from '@/Components/ConfirmModal.vue';
import IconButton from '@/Components/IconButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, CheckCircle2, ClipboardList, Clock3, Play, XCircle } from '@lucide/vue';
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

const statuses = {
    generated: {
        label: 'Belum dieksekusi',
        pillClass: 'bg-amber-50 text-amber-700 ring-1 ring-amber-200',
        textClass: 'text-amber-700',
        icon: Clock3,
    },
    executed: {
        label: 'Teregister',
        pillClass: 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200',
        textClass: 'text-emerald-700',
        icon: CheckCircle2,
    },
    failed: {
        label: 'Gagal',
        pillClass: 'bg-red-50 text-red-700 ring-1 ring-red-200',
        textClass: 'text-red-700',
        icon: XCircle,
    },
};

const formatDate = (value) => {
    if (!value) return '-';

    return new Intl.DateTimeFormat('id-ID', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
};

const statusMeta = (status) => statuses[status] ?? statuses.generated;

const canExecute = (registration) => registration.status !== 'executed';

const statusDescription = (registration) => {
    if (registration.status === 'executed') {
        return registration.executed_at
            ? `Teregister di OLT pada ${formatDate(registration.executed_at)}.`
            : 'Teregister di OLT.';
    }

    if (registration.status === 'failed') {
        return registration.executed_at
            ? `Eksekusi terakhir gagal pada ${formatDate(registration.executed_at)}.`
            : 'Eksekusi terakhir gagal, bisa dicoba ulang.';
    }

    return 'Belum dikirim ke CLI OLT.';
};

const executeRegistration = async (registration) => {
    if (!canExecute(registration)) {
        return;
    }

    const ok = await confirm({
        title: 'Eksekusi Provisioning',
        message: `${registration.status === 'failed' ? 'Ulangi eksekusi' : 'Eksekusi'} script provisioning untuk ${registration.pon_port} ke OLT?`,
        confirmLabel: registration.status === 'failed' ? 'Coba Lagi' : 'Eksekusi',
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

</script>

<template>
    <Head title="Registration History" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="text-lg font-semibold leading-tight sm:text-xl text-slate-800">Registration History</h2>
                    <p class="mt-1 text-sm text-slate-500">{{ olt.name }}</p>
                </div>
                <Link :href="route('smartolt.unconfigured-all', { olt_id: olt.id })">
                    <SecondaryButton type="button">
                        <ArrowLeft class="mr-2 h-4 w-4" />
                        Unconfigured
                    </SecondaryButton>
                </Link>
            </div>
        </template>

        <div class="min-h-[60vh] pt-5 pb-16 sm:pt-8">
            <div class="w-full space-y-6 px-4 sm:px-6 lg:px-8">
                <div v-if="flash.success" class="mb-5 flex items-center gap-3 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                    <span class="h-2 w-2 flex-shrink-0 rounded-full bg-emerald-500"></span>
                    {{ flash.success }}
                </div>
                <div v-if="flash.error" class="mb-5 flex items-center gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                    <span class="h-2 w-2 flex-shrink-0 rounded-full bg-red-500"></span>
                    {{ flash.error }}
                </div>

                <div class="overflow-hidden rounded-lg border border-sky-200 bg-white shadow-sm shadow-sky-100/60">
                    <div class="flex items-center gap-3 border-b border-slate-100 px-4 py-4 sm:px-6">
                        <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-sky-100 ring-1 ring-sky-200">
                            <ClipboardList class="h-5 w-5 text-sky-600" />
                        </div>
                        <h3 class="text-base font-semibold text-slate-900">Provisioning Scripts</h3>
                    </div>

                    <div v-if="registrations.length === 0" class="px-6 py-10 text-center text-sm text-slate-500">
                        Belum ada provisioning script.
                    </div>

                    <div v-else class="divide-y divide-slate-100">
                        <div v-for="registration in registrations" :key="registration.id" class="p-6">
                            <div class="flex flex-col gap-2 lg:flex-row lg:items-start lg:justify-between">
                                <div>
                                    <div class="font-medium text-slate-900">
                                        {{ registration.customer_name }} · {{ registration.pon_port }}
                                    </div>
                                    <div class="text-sm text-slate-500">
                                        {{ registration.serial_number }} · VLAN {{ registration.vlan }} · {{ registration.wan_mode }} · {{ formatDate(registration.created_at) }}
                                    </div>
                                    <div class="mt-2 text-xs font-medium" :class="statusMeta(registration.status).textClass">
                                        {{ statusDescription(registration) }}
                                    </div>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="inline-flex w-fit items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium" :class="statusMeta(registration.status).pillClass">
                                        <component :is="statusMeta(registration.status).icon" class="h-3.5 w-3.5" />
                                        {{ statusMeta(registration.status).label }}
                                    </span>
                                    <IconButton v-if="canExecute(registration)" variant="success" :title="registration.status === 'failed' ? 'Coba eksekusi lagi' : 'Eksekusi ke OLT'" @click="executeRegistration(registration)">
                                        <Play class="h-4 w-4" />
                                    </IconButton>
                                </div>
                            </div>
                            <pre class="mt-4 overflow-x-auto rounded-lg bg-slate-900 p-4 text-xs text-slate-300 border border-slate-700">{{ registration.cli_script }}</pre>
                            <div v-if="registration.executed_at || registration.execution_output || registration.execution_error" class="mt-4 space-y-2">
                                <div class="text-xs font-medium uppercase tracking-wide text-slate-500">
                                    Execution · {{ formatDate(registration.executed_at) }}
                                </div>
                                <div v-if="registration.execution_error" class="flex items-center gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                                    {{ registration.execution_error }}
                                </div>
                                <pre v-if="registration.execution_output" class="overflow-x-auto rounded-lg bg-slate-900 p-4 text-xs text-slate-300 border border-slate-700">{{ registration.execution_output }}</pre>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <ConfirmModal :state="confirmState" @confirm="handleConfirm" @cancel="handleCancel" />
    </AuthenticatedLayout>
</template>
