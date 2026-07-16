<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import CDataOltForm from './Partials/CDataOltForm.vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { computed } from 'vue';

const { t } = useI18n({ useScope: 'global' });

const props = defineProps({
    defaults: {
        type: Object,
        required: true,
    },
});

const title = computed(() => (props.defaults.family === 'hioso' ? t('cdataform.create_hioso') : t('cdataform.create_cdata')));
</script>

<template>
    <Head :title="title" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-lg font-semibold leading-tight sm:text-xl text-white">
                {{ title }}
            </h2>
        </template>

        <div class="min-h-[60vh] pt-5 pb-16 sm:pt-8">
            <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                <CDataOltForm :defaults="defaults" :submit-label="$t('oltform.submit_create')" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
