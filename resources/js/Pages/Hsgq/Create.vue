<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import HsgqOltForm from './Partials/HsgqOltForm.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    defaults: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <Head :title="$t('hsgq.create_title')" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-lg font-semibold leading-tight sm:text-xl text-white">
                {{ $t('hsgq.create_title') }}
            </h2>
        </template>

        <div class="min-h-[60vh] pt-5 pb-16 sm:pt-8">
            <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                <HsgqOltForm :defaults="defaults" :submit-label="$t('oltform.submit_create')" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
