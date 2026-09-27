<script setup>
/* Tombol destruktif — tampilan baku (UI_DESIGN_SYSTEM): gradien rose -> red. */
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
        class="inline-flex items-center justify-center border border-transparent bg-gradient-to-r from-rose-600 to-red-600 text-sm font-semibold text-onaccent shadow-md shadow-rose-600/30 transition-all duration-150 hover:from-rose-500 hover:to-red-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-400/60 focus-visible:ring-offset-2 focus-visible:ring-offset-canvas disabled:cursor-not-allowed disabled:opacity-50"
        :class="sizeClass"
    >
        <slot />
    </button>
</template>
