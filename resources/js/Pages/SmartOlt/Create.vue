<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import OltForm from './Partials/OltForm.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    defaults: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <Head title="Tambah OLT" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Tambah OLT
            </h2>
        </template>

        <div class="bg-gradient-to-br from-slate-50 via-blue-50/80 to-indigo-100/60 py-8 pb-16 min-h-[60vh]">
            <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                <OltForm :defaults="defaults" submit-label="Simpan OLT" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
