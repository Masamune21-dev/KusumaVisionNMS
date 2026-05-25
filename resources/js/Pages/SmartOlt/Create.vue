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

        <div class="py-8">
            <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                <div class="rounded-lg bg-white p-6 shadow-sm">
                    <OltForm :defaults="defaults" submit-label="Simpan OLT" />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
