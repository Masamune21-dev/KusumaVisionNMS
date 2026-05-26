<script setup>
defineProps({
    type: {
        type: String,
        default: 'button',
    },
});
</script>

<template>
    <button
        :type="type"
        class="inline-flex min-h-11 items-center justify-center rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold uppercase tracking-wide text-slate-700 shadow-sm transition duration-150 ease-in-out hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-sky-500 focus:ring-offset-2 disabled:opacity-25"
    >
        <slot />
    </button>
</template>
