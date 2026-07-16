<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import HiosoOltForm from './Partials/HiosoOltForm.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    defaults: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <Head :title="$t('cdataform.create_hioso')" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-lg font-semibold leading-tight sm:text-xl text-white">
                {{ $t('cdataform.create_hioso') }}
            </h2>
        </template>

        <div class="min-h-[60vh] pt-5 pb-16 sm:pt-8">
            <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                <HiosoOltForm :defaults="defaults" :submit-label="$t('oltform.submit_create')" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
