<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

/*
 * Logo bawaan = logo Kusumanet One (logo pemilik 17 Okt 2026, dipakai di seluruh app
 * ekosistem sejak 23 Okt 2026): «K» kabel serat biru + kaki oranye di atas ubin putih
 * membulat, bentuk yang sama dengan ikon APK Kusumanet One. Ubin putihnya ikut di dalam
 * gambar karena logonya bertepi halo putih dan tidak boleh ditaruh langsung di latar gelap;
 * garis tepi `slate-700` hanya terlihat di tema terang. Logo unggahan di Pengaturan tetap
 * menggantikannya bila diisi.
 */
const page = usePage();
const logoUrl = computed(() => page.props.branding?.logo_url ?? null);
const appName = computed(() => page.props.branding?.name ?? 'KusumaVision');
</script>

<template>
    <img
        v-if="logoUrl"
        :src="logoUrl"
        :alt="appName"
        class="object-contain"
    />
    <img
        v-else
        src="/images/kusumanet-one.webp"
        :alt="appName"
        width="256"
        height="256"
        class="aspect-square rounded-[23.4%] ring-1 ring-slate-700/60"
    />
</template>
