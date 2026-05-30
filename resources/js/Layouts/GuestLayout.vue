<script setup>
import { computed } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import AuroraBackground from '@/Components/Shell/AuroraBackground.vue';
import { Link, usePage } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';

const page = usePage();
const appName = computed(() => page.props.branding?.name ?? 'KusumaVision');
// Atribusi pemilik permanen (konstanta backend GeneralSetting::OWNER), bukan dari Settings.
const owner = computed(() => page.props.branding?.owner ?? 'PT Berkah Media Kusuma Vision');
const copyrightYear = computed(() => page.props.branding?.copyright_year ?? '2026');
</script>

<template>
    <div class="kv-grid-bg relative flex min-h-screen flex-col items-center justify-center overflow-hidden px-4 py-10">
        <AuroraBackground />
        <!-- Ambient glows -->
        <div class="pointer-events-none absolute -left-40 top-10 h-96 w-96 rounded-full bg-cyan-500/15 blur-[120px]" />
        <div class="pointer-events-none absolute -right-40 bottom-10 h-96 w-96 rounded-full bg-purple-500/10 blur-[120px]" />

        <!-- Back to landing -->
        <Link
            href="/"
            class="absolute left-4 top-4 inline-flex items-center gap-1.5 rounded-lg border border-white/10 bg-slate-900/60 px-3 py-1.5 text-xs font-medium text-slate-400 backdrop-blur transition-colors hover:border-white/20 hover:text-white sm:left-6 sm:top-6"
        >
            <ArrowLeft class="h-3.5 w-3.5" />
            Beranda
        </Link>

        <!-- Brand -->
        <Link href="/" class="relative flex flex-col items-center gap-2">
            <ApplicationLogo class="h-14 w-auto fill-current text-cyan-400 drop-shadow-[0_0_14px_rgba(34,211,238,0.5)]" />
            <div class="text-center">
                <div class="text-lg font-bold text-white">KusumaVision NMS</div>
                <div class="text-[11px] text-slate-500">ZTE OLT Management & Provisioning Platform</div>
            </div>
        </Link>

        <!-- Auth card -->
        <div class="relative mt-6 w-full sm:max-w-md">
            <div class="absolute -inset-1 rounded-3xl bg-gradient-to-br from-cyan-500/20 via-transparent to-purple-500/20 blur-xl" />
            <div class="relative overflow-hidden rounded-2xl border border-white/10 bg-slate-900/60 px-6 py-6 shadow-2xl shadow-black/40 backdrop-blur-xl sm:px-8 sm:py-7">
                <slot />
            </div>
        </div>

        <p class="relative mt-6 text-center text-xs text-slate-500">
            &copy; {{ copyrightYear }} {{ appName }} NMS &middot; {{ owner }}
        </p>
    </div>
</template>
