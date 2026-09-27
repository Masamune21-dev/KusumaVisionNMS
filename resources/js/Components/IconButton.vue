<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    href: {
        type: String,
        default: null,
    },
    variant: {
        type: String,
        default: 'default',
    },
    title: {
        type: String,
        default: '',
    },
    type: {
        type: String,
        default: 'button',
    },
    disabled: {
        type: Boolean,
        default: false,
    },
});

// Tampilan baku (UI_DESIGN_SYSTEM): isi berwarna tipis per varian, 44 px di HP
// dan 36 px mulai `sm:`. Sengaja tanpa backdrop-blur — tombol ini muncul puluhan kali per
// tabel, dan blur per tombol membuat Chromium kehabisan tile saat menggulir.
const base = 'inline-flex h-11 w-11 items-center justify-center rounded-lg ring-1 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-1 focus-visible:ring-offset-canvas disabled:cursor-not-allowed disabled:opacity-50 sm:h-9 sm:w-9';

const variants = {
    default: 'bg-slate-500/15 text-slate-300 ring-slate-500/30 hover:bg-slate-500/25 hover:text-slate-100 focus-visible:ring-slate-400/60',
    primary: 'bg-cyan-500/15 text-cyan-300 ring-cyan-500/30 hover:bg-cyan-500/25 focus-visible:ring-cyan-400/60',
    info:    'bg-sky-500/15 text-sky-300 ring-sky-500/30 hover:bg-sky-500/25 focus-visible:ring-sky-400/60',
    success: 'bg-emerald-500/15 text-emerald-300 ring-emerald-500/30 hover:bg-emerald-500/25 focus-visible:ring-emerald-400/60',
    warning: 'bg-amber-500/15 text-amber-300 ring-amber-500/30 hover:bg-amber-500/25 focus-visible:ring-amber-400/60',
    danger:  'bg-rose-500/15 text-rose-300 ring-rose-500/30 hover:bg-rose-500/25 focus-visible:ring-rose-400/60',
};

const classes = computed(() => `${base} ${variants[props.variant] ?? variants.default}`);
</script>

<template>
    <Link v-if="href" :href="href" :title="title" :aria-label="title" :class="classes">
        <slot />
    </Link>
    <button v-else :type="type" :disabled="disabled" :title="title" :aria-label="title" :class="classes">
        <slot />
    </button>
</template>
