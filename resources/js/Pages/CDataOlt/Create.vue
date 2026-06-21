<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import CDataOltForm from './Partials/CDataOltForm.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    defaults: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <Head title="Tambah OLT C-Data" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-lg font-semibold leading-tight sm:text-xl text-white">
                Tambah OLT C-Data
            </h2>
        </template>

        <div class="min-h-[60vh] pt-5 pb-16 sm:pt-8">
            <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                <CDataOltForm :defaults="defaults" submit-label="Simpan OLT" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
