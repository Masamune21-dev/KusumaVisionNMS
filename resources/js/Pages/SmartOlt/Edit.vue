<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import OltForm from './Partials/OltForm.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    olt: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <Head title="Edit OLT" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-lg font-semibold leading-tight sm:text-xl text-white">
                Edit OLT
            </h2>
        </template>

        <div class="min-h-[60vh] pt-5 pb-16 sm:pt-8">
            <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                <OltForm :olt="olt" submit-label="Update OLT" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
