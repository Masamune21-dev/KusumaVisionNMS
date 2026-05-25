<script setup>
import SecondaryButton from '@/Components/SecondaryButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowLeft, ClipboardList } from '@lucide/vue';
import { computed } from 'vue';

defineProps({
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

const formatDate = (value) => {
    if (!value) return '-';

    return new Intl.DateTimeFormat('id-ID', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
};
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
                                <span class="inline-flex w-fit rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700">
                                    {{ registration.status }}
                                </span>
                            </div>
                            <pre class="mt-4 overflow-x-auto rounded-md bg-gray-950 p-4 text-xs text-gray-100">{{ registration.cli_script }}</pre>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
