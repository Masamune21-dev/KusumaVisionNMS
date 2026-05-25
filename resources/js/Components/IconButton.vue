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

const base = 'inline-flex h-9 w-9 items-center justify-center rounded-md border bg-white shadow-sm transition focus:outline-none focus:ring-2 focus:ring-offset-1 disabled:cursor-not-allowed disabled:opacity-50';

const variants = {
    default: 'border-gray-300 text-gray-600 hover:bg-gray-50 focus:ring-gray-400',
    primary: 'border-indigo-300 text-indigo-700 hover:bg-indigo-50 focus:ring-indigo-500',
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
