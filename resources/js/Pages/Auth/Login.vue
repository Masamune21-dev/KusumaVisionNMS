<script setup>
import Checkbox from '@/Components/Checkbox.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowRight, CheckCircle2, Loader2, Lock, Mail } from '@lucide/vue';
import { ref } from 'vue';

defineProps({
    canResetPassword: { type: Boolean },
    status: { type: String },
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const showPassword = ref(false);

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Masuk — KusumaVision NMS" />

        <div class="mb-6 text-center">
            <h1 class="text-xl font-bold text-white">Selamat Datang Kembali</h1>
            <p class="mt-1 text-sm text-slate-400">Masuk untuk mengakses NMS Dashboard.</p>
        </div>

        <div
            v-if="status"
            class="mb-5 flex items-center gap-2 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-3 py-2 text-sm text-emerald-300"
        >
            <CheckCircle2 class="h-4 w-4 flex-shrink-0" />
            {{ status }}
        </div>

        <form @submit.prevent="submit" class="space-y-4">
            <div>
                <InputLabel for="email" value="Email" />
                <div class="relative mt-1">
                    <Mail class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" />
                    <TextInput
                        id="email"
                        type="email"
                        class="block w-full pl-9"
                        v-model="form.email"
                        required
                        autofocus
                        autocomplete="username"
                        placeholder="nama@perusahaan.com"
                    />
                </div>
                <InputError class="mt-1.5" :message="form.errors.email" />
            </div>

            <div>
                <div class="flex items-center justify-between">
                    <InputLabel for="password" value="Password" />
                    <Link
                        v-if="canResetPassword"
                        :href="route('password.request')"
                        class="text-xs font-medium text-cyan-400 transition-colors hover:text-cyan-300"
                    >
                        Lupa password?
                    </Link>
                </div>
                <div class="relative mt-1">
                    <Lock class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" />
                    <TextInput
                        id="password"
                        :type="showPassword ? 'text' : 'password'"
                        class="block w-full pl-9 pr-16"
                        v-model="form.password"
                        required
                        autocomplete="current-password"
                        placeholder="••••••••"
                    />
                    <button
                        type="button"
                        class="absolute right-2 top-1/2 -translate-y-1/2 rounded-md px-2 py-1 text-[11px] font-semibold text-slate-400 transition-colors hover:text-cyan-400"
                        @click="showPassword = !showPassword"
                    >
                        {{ showPassword ? 'Sembunyikan' : 'Tampilkan' }}
                    </button>
                </div>
                <InputError class="mt-1.5" :message="form.errors.password" />
            </div>

            <label class="flex cursor-pointer items-center gap-2.5 pt-1">
                <Checkbox name="remember" v-model:checked="form.remember" />
                <span class="text-sm text-slate-300">Ingat saya di perangkat ini</span>
            </label>

            <button
                type="submit"
                :disabled="form.processing"
                class="group inline-flex w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-cyan-500 to-sky-600 px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-cyan-500/30 transition-all hover:shadow-cyan-500/50 disabled:cursor-not-allowed disabled:opacity-60"
            >
                <Loader2 v-if="form.processing" class="h-4 w-4 animate-spin" />
                <template v-else>
                    Masuk
                    <ArrowRight class="h-4 w-4 transition-transform group-hover:translate-x-0.5" />
                </template>
            </button>
        </form>

        <div class="mt-6 border-t border-white/5 pt-4 text-center text-xs text-slate-500">
            Butuh akses?
            <a href="mailto:noc@kusumavision.id" class="font-medium text-cyan-400 hover:text-cyan-300">
                Hubungi admin
            </a>
        </div>
    </GuestLayout>
</template>
