<script setup>
import { oltImageCandidates, oltImageKey } from '@/lib/oltImage';
import { Server } from '@lucide/vue';
import { computed, ref, watch } from 'vue';

/**
 * Gambar kecil OLT untuk baris daftar SmartOLT. Sumber & urutan cadangan sama dengan
 * Components/OltImage.vue (lib/oltImage.js); bila tak ada berkas yang termuat, tampil ikon.
 * Model C-Data GPON (FD1601S/FD1602S) diambil dari faceplate hasil scan.
 */
const props = defineProps({
    olt: { type: Object, required: true },
    size: { type: String, default: 'md' }, // md = tabel desktop, lg = kartu HP
});

const model = computed(() => props.olt.model || props.olt.last_test_result?.panel?.device?.model || '');
const key = computed(() => oltImageKey({ ...props.olt, model: model.value }));
// Gambar ZTE lama (/img/c300.webp dst.) didahulukan supaya daftar tak memicu 404 per baris
// untuk berkas /img/olt/zte-*.webp yang memang belum ada.
const candidates = computed(() => {
    const list = oltImageCandidates(key.value);
    const legacy = list.filter((src) => !src.startsWith('/img/olt/'));
    return legacy.length ? [...legacy, ...list.filter((src) => src.startsWith('/img/olt/'))] : list;
});
const index = ref(0);
watch(key, () => { index.value = 0; });
const src = computed(() => candidates.value[index.value] ?? null);

const box = computed(() => (props.size === 'lg'
    ? 'h-16 w-24'
    : 'h-12 w-20'));
</script>

<template>
    <div
        class="flex shrink-0 items-center justify-center rounded-lg border border-white/10 bg-canvas-3/40 p-1.5"
        :class="box"
    >
        <img
            v-if="src"
            :key="src"
            :src="src"
            alt=""
            class="max-h-full max-w-full object-contain drop-shadow-md"
            loading="lazy"
            decoding="async"
            @error="index += 1"
        />
        <Server v-else class="h-5 w-5 text-slate-500" aria-hidden="true" />
    </div>
</template>
