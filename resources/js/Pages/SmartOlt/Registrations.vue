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
    generated: 'bg-gray-100 text-gray-700',
    executed: 'bg-emerald-100 text-emerald-800',
    failed: 'bg-red-100 text-red-800',
}[status] ?? 'bg-gray-100 text-gray-700');
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
                <Link :href="route('smartolt.detail', olt.id)">
                    <SecondaryButton type="button">
                        <ArrowLeft class="mr-2 h-4 w-4" />
                        Detail OLT
                    </SecondaryButton>
                </Link>
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

                <div class="rounded-lg bg-white shadow-sm">
                    <div class="flex items-center gap-3 border-b border-gray-200 px-6 py-4">
                        <ClipboardList class="h-5 w-5 text-gray-500" />
                        <h3 class="text-base font-semibold text-gray-900">Generated Scripts</h3>
                    </div>

                    <div v-if="registrations.length === 0" class="px-6 py-10 text-center text-sm text-gray-500">
                        Belum ada provisioning script.
                    </div>

                    <div v-else class="divide-y divide-gray-200">
                        <div v-for="registration in registrations" :key="registration.id" class="p-6">
                            <div class="flex flex-col gap-2 lg:flex-row lg:items-start lg:justify-between">
                                <div>
                                    <div class="font-semibold text-gray-900">
                                        {{ registration.customer_name }} · {{ registration.pon_port }}
                                    </div>
                                    <div class="text-sm text-gray-500">
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
                            <pre class="mt-4 overflow-x-auto rounded-md bg-gray-950 p-4 text-xs text-gray-100">{{ registration.cli_script }}</pre>
                            <div v-if="registration.executed_at || registration.execution_output || registration.execution_error" class="mt-4 space-y-2">
                                <div class="text-xs font-medium uppercase tracking-wide text-gray-500">
                                    Execution · {{ formatDate(registration.executed_at) }}
                                </div>
                                <div v-if="registration.execution_error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                                    {{ registration.execution_error }}
                                </div>
                                <pre v-if="registration.execution_output" class="overflow-x-auto rounded-md bg-gray-900 p-4 text-xs text-gray-100">{{ registration.execution_output }}</pre>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <ConfirmModal :state="confirmState" @confirm="handleConfirm" @cancel="handleCancel" />
    </AuthenticatedLayout>
</template>
