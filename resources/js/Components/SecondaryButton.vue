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
        class="inline-flex min-h-11 items-center justify-center rounded-lg border border-white/10 bg-slate-900/60 px-4 py-2.5 text-sm font-semibold uppercase tracking-wide text-slate-300 backdrop-blur transition duration-150 ease-in-out hover:border-white/20 hover:bg-slate-800/80 hover:text-white focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:ring-offset-2 focus:ring-offset-slate-950 disabled:opacity-25"
    >
        <slot />
    </button>
</template>
