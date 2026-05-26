<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    mustVerifyEmail: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});
</script>

<template>
    <Head title="Profile" />

    <AuthenticatedLayout>
        <template #header>
            <h2
                class="text-xl font-semibold leading-tight text-gray-800"
            >
                Profile
            </h2>
        </template>

        <div class="bg-gradient-to-br from-slate-50 via-blue-50/80 to-indigo-100/60 py-8 pb-16 min-h-[60vh]">
            <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                <div class="overflow-hidden rounded-2xl border border-white/70 bg-white/70 p-6 shadow-xl shadow-blue-100/40 backdrop-blur-xl">
                    <UpdateProfileInformationForm
                        :must-verify-email="mustVerifyEmail"
                        :status="status"
                        class="max-w-xl"
                    />
                </div>

                <div class="overflow-hidden rounded-2xl border border-white/70 bg-white/70 p-6 shadow-xl shadow-blue-100/40 backdrop-blur-xl">
                    <UpdatePasswordForm class="max-w-xl" />
                </div>

                <div class="overflow-hidden rounded-2xl border border-white/70 bg-white/70 p-6 shadow-xl shadow-blue-100/40 backdrop-blur-xl">
                    <DeleteUserForm class="max-w-xl" />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
