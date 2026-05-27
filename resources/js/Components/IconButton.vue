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

const base = 'inline-flex h-11 w-11 items-center justify-center rounded-lg border bg-slate-900/60 backdrop-blur transition focus:outline-none focus:ring-2 focus:ring-offset-1 focus:ring-offset-slate-950 disabled:cursor-not-allowed disabled:opacity-50 sm:h-9 sm:w-9';

const variants = {
    default: 'border-white/10 text-slate-300 hover:border-white/20 hover:bg-slate-800/80 hover:text-white focus:ring-slate-500',
    primary: 'border-cyan-500/30 text-cyan-300 hover:border-cyan-400/50 hover:bg-cyan-500/10 hover:text-cyan-200 focus:ring-cyan-500',
    danger:  'border-red-500/30 text-red-300 hover:border-red-400/50 hover:bg-red-500/10 hover:text-red-200 focus:ring-red-500',
    success: 'border-emerald-500/30 text-emerald-300 hover:border-emerald-400/50 hover:bg-emerald-500/10 hover:text-emerald-200 focus:ring-emerald-500',
    warning: 'border-amber-500/30 text-amber-300 hover:border-amber-400/50 hover:bg-amber-500/10 hover:text-amber-200 focus:ring-amber-500',
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
