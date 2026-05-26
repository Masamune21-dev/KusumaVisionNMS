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

const base = 'inline-flex h-11 w-11 items-center justify-center rounded-md border bg-white shadow-sm transition focus:outline-none focus:ring-2 focus:ring-offset-1 disabled:cursor-not-allowed disabled:opacity-50 sm:h-9 sm:w-9';

const variants = {
    default: 'border-slate-300 text-slate-600 hover:bg-slate-50 focus:ring-slate-400',
    primary: 'border-sky-300 text-sky-700 hover:bg-sky-50 focus:ring-sky-500',
    danger: 'border-red-300 text-red-700 hover:bg-red-50 focus:ring-red-500',
    success: 'border-emerald-300 text-emerald-700 hover:bg-emerald-50 focus:ring-emerald-500',
    warning: 'border-amber-300 text-amber-700 hover:bg-amber-50 focus:ring-amber-500',
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
