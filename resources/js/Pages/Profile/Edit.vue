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
                class="text-lg font-semibold leading-tight sm:text-xl text-white"
            >
                Profile
            </h2>
        </template>

        <div class="min-h-[60vh] pt-5 pb-16 sm:pt-8">
            <div class="w-full space-y-6 px-4 sm:px-6 lg:px-8">
                <div class="rounded-lg border border-white/10 bg-slate-900/40 backdrop-blur-xl p-6 shadow-sm shadow-black/30">
                    <UpdateProfileInformationForm
                        :must-verify-email="mustVerifyEmail"
                        :status="status"
                        class="max-w-xl"
                    />
                </div>

                <div class="rounded-lg border border-white/10 bg-slate-900/40 backdrop-blur-xl p-6 shadow-sm shadow-black/30">
                    <UpdatePasswordForm class="max-w-xl" />
                </div>

                <div class="rounded-lg border border-white/10 bg-slate-900/40 backdrop-blur-xl p-6 shadow-sm shadow-black/30">
                    <DeleteUserForm class="max-w-xl" />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
