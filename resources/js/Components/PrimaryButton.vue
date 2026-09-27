<script setup>
/*
 * Tombol aksi utama — tampilan baku (UI_DESIGN_SYSTEM, "Tampilan baku
 * lintas keluarga"): 44 px, rounded-xl, text-sm semibold tanpa uppercase, gradien
 * cyan -> sky. `size="sm"` = 34 px untuk kaki modal & bilah aksi massal.
 */
import { computed } from 'vue';

const props = defineProps({
    type: { type: String, default: 'submit' },
    size: { type: String, default: 'md' },
});

const sizeClass = computed(() => (props.size === 'sm' ? 'min-h-[34px] gap-1.5 rounded-lg px-3.5' : 'min-h-11 gap-2 rounded-xl px-5'));
</script>

<template>
    <button
        :type="type"
        class="inline-flex items-center justify-center border border-transparent bg-gradient-to-r from-cyan-500 to-sky-500 text-sm font-semibold text-onaccent shadow-md shadow-cyan-500/25 transition-all duration-150 hover:from-cyan-600 hover:to-sky-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-cyan-400/60 focus-visible:ring-offset-2 focus-visible:ring-offset-canvas disabled:cursor-not-allowed disabled:opacity-50"
        :class="sizeClass"
    >
        <slot />
    </button>
</template>
