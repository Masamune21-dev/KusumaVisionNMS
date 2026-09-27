<script setup>
import { oltImageCandidates, oltImageKey } from '@/lib/oltImage';
import { ImageOff } from '@lucide/vue';
import { computed, ref, watch } from 'vue';

/**
 * Gambar produk OLT dengan cadangan berurutan (lihat lib/oltImage.js). Berkas yang gagal
 * dimuat dilewati ke kandidat berikutnya; bila habis, tampil placeholder yang menyebut
 * nama berkas yang dicari — supaya admin tahu harus menaruh berkas apa di public/img/olt/.
 */
const props = defineProps({
    olt: { type: Object, required: true },
    model: { type: String, default: '' },
});

const key = computed(() => oltImageKey({ ...props.olt, model: props.model || props.olt.model }));
const candidates = computed(() => oltImageCandidates(key.value));
const index = ref(0);
watch(key, () => { index.value = 0; });

const src = computed(() => candidates.value[index.value] ?? null);
const next = () => { index.value += 1; };
</script>

<template>
    <div class="kv-surface flex min-h-[16rem] items-center justify-center overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30">
        <img v-if="src" :key="src" :src="src" :alt="olt.name" class="max-h-96 w-full object-contain p-6 sm:p-8" loading="lazy" @error="next" />
        <div v-else class="flex flex-col items-center gap-2 px-6 py-12 text-center">
            <span class="flex h-14 w-14 items-center justify-center rounded-full bg-slate-800/60 ring-1 ring-slate-500/30">
                <ImageOff class="h-7 w-7 text-slate-400" />
            </span>
            <p class="text-sm font-medium text-slate-300">{{ $t('detail.image_unavailable') }}</p>
            <p class="text-xs text-slate-500">
                {{ $t('detail.image_hint') }}
                <code class="rounded bg-canvas-3/60 px-1.5 py-0.5 font-mono text-slate-300">public/img/olt/{{ key }}.webp</code>
            </p>
        </div>
    </div>
</template>
