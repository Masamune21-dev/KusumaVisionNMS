<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { ExternalLink, KeyRound, ShieldAlert, User } from '@lucide/vue';
import { computed } from 'vue';

defineProps({
    status: { type: String, default: null },
    ssoProfileUrl: { type: String, required: true },
    role: { type: String, default: null },
    isEmergencySession: { type: Boolean, default: false },
});

const page = usePage();
const user = computed(() => page.props.auth?.user ?? {});
</script>

<template>
    <Head :title="$t('profile.title')" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-lg font-semibold leading-tight text-white sm:text-xl">
                {{ $t('profile.title') }}
            </h2>
        </template>

        <div class="min-h-[60vh] pb-16 pt-5 sm:pt-8">
            <div class="w-full max-w-2xl space-y-6 px-4 sm:px-6 lg:px-8">
                <div
                    v-if="isEmergencySession"
                    class="flex gap-3 rounded-lg border border-amber-500/30 bg-amber-500/10 p-4 text-sm text-amber-200"
                >
                    <ShieldAlert class="h-5 w-5 flex-shrink-0" />
                    <p>{{ $t('profile.emergency_session') }}</p>
                </div>

                <div class="rounded-lg border border-white/10 bg-slate-900/40 p-6 shadow-sm shadow-black/30 backdrop-blur-xl">
                    <div class="flex items-start gap-4">
                        <span class="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-cyan-500 to-sky-600 text-lg font-bold text-white">
                            {{ (user.name ?? '?').charAt(0).toUpperCase() }}
                        </span>
                        <div class="min-w-0">
                            <h3 class="text-base font-semibold text-white">{{ user.name }}</h3>
                            <p class="truncate text-sm text-slate-400">{{ user.email }}</p>
                            <p v-if="role" class="mt-1 inline-block rounded-md bg-cyan-500/10 px-2 py-0.5 text-xs font-medium text-cyan-300">
                                {{ role }}
                            </p>
                        </div>
                    </div>
                </div>

                <div class="rounded-lg border border-white/10 bg-slate-900/40 p-6 shadow-sm shadow-black/30 backdrop-blur-xl">
                    <div class="flex items-start gap-3">
                        <div class="flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-xl bg-cyan-500/10 text-cyan-400">
                            <KeyRound class="h-5 w-5" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h3 class="text-base font-semibold text-white">{{ $t('profile.managed_centrally') }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-slate-400">
                                {{ $t('profile.managed_centrally_desc') }}
                            </p>

                            <a
                                :href="ssoProfileUrl"
                                class="mt-4 inline-flex items-center gap-2 rounded-lg bg-gradient-to-br from-cyan-500 to-sky-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-cyan-500/20 transition hover:from-cyan-400 hover:to-sky-500"
                            >
                                <User class="h-4 w-4" />
                                {{ $t('profile.open_sso') }}
                                <ExternalLink class="h-3.5 w-3.5" />
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
