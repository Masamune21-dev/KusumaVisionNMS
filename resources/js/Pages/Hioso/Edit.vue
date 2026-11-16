<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import OltPageHeader from '@/Components/SmartOlt/OltPageHeader.vue';
import HiosoOltForm from './Partials/HiosoOltForm.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    olt: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <Head :title="$t('cdataform.edit_hioso')" />

    <AuthenticatedLayout>
        <template #header>
            <OltPageHeader :olt="olt" family="hioso" :title="$t('cdataform.edit_hioso')" :subtitle="$t('oltnav.settings_subtitle')" />
        </template>

        <div class="min-h-[60vh] pt-5 pb-16 sm:pt-8">
            <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                <HiosoOltForm :olt="olt" :submit-label="$t('oltform.submit_update')" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
