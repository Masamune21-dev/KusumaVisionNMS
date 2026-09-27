<script setup>
/* Tombol pendukung / Batal — tampilan baku (UI_DESIGN_SYSTEM). */
import { computed } from 'vue';

const props = defineProps({
    type: { type: String, default: 'button' },
    size: { type: String, default: 'md' },
});

const sizeClass = computed(() => (props.size === 'sm' ? 'min-h-[34px] gap-1.5 rounded-lg px-3.5' : 'min-h-11 gap-2 rounded-xl px-5'));
</script>

<template>
    <button
        :type="type"
        class="kv-btn-secondary-base inline-flex items-center justify-center border border-slate-700/80 bg-slate-900/80 text-sm font-semibold text-slate-200 transition-colors duration-150 hover:bg-slate-800 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-cyan-400/60 focus-visible:ring-offset-2 focus-visible:ring-offset-canvas disabled:cursor-not-allowed disabled:opacity-50"
        :class="sizeClass"
    >
        <slot />
    </button>
</template>
