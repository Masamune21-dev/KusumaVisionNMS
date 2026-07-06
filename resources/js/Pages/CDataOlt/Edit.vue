<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import CDataOltForm from './Partials/CDataOltForm.vue';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    olt: {
        type: Object,
        required: true,
    },
});

const title = computed(() => (props.olt.driver === 'hioso-epon-25355' ? 'Edit OLT HiOSO' : 'Edit OLT C-Data'));
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
                <CDataOltForm :olt="olt" submit-label="Update OLT" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
