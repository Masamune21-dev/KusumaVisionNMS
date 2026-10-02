<script setup>
/*
 * Gauge busur 270° (speedometer) dalam SVG murni — pengganti radialBar
 * ApexCharts. Tanpa pustaka grafik: busurnya satu <path> dengan
 * `pathLength="100"`, jadi panjang isian = persentase apa adanya.
 *
 * Isi tengah (nilai, satuan, keterangan) lewat slot bawaan.
 */
import { computed } from 'vue';

const props = defineProps({
    // 0…100 — porsi busur yang terisi.
    percent: { type: Number, default: 0 },
    // Warna isian (heks/rgb apa pun yang diterima SVG).
    color: { type: String, required: true },
    label: { type: String, default: '' },
});

// Busur dari -135° ke +135° (0° = atas), pusat (100,100), jari-jari 80.
const ARC = 'M 43.43 156.57 A 80 80 0 1 1 156.57 156.57';

const filled = computed(() => Math.max(0, Math.min(100, Number(props.percent) || 0)));
</script>

<template>
    <div class="relative mx-auto aspect-square w-full max-w-[13rem]">
        <svg viewBox="0 0 200 200" class="h-full w-full" role="img" :aria-label="label || undefined">
            <path :d="ARC" fill="none" stroke-width="16" stroke-linecap="round" class="stroke-slate-400/15" />
            <path
                v-if="filled > 0"
                :d="ARC"
                fill="none"
                :stroke="color"
                stroke-width="16"
                stroke-linecap="round"
                pathLength="100"
                :stroke-dasharray="`${filled} 100`"
            />
        </svg>
        <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center text-center">
            <slot />
        </div>
    </div>
</template>
