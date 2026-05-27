<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    links: {
        type: Array,
        default: () => [],
    },
});
</script>

<template>
    <div v-if="links.length > 3" class="flex max-w-full flex-wrap items-center justify-center gap-1">
        <template v-for="(link, index) in links" :key="index">
            <span
                v-if="link.url === null"
                class="rounded-lg px-3 py-2 text-sm text-slate-500"
                v-html="link.label"
            />
            <Link
                v-else
                :href="link.url"
                preserve-scroll
                preserve-state
                class="inline-flex min-h-10 items-center justify-center rounded-lg border px-3 py-2 text-sm transition-colors"
                :class="link.active
                    ? 'border-cyan-500/40 bg-cyan-500/15 text-cyan-300'
                    : 'border-white/10 bg-slate-900/60 text-slate-300 hover:border-white/20 hover:bg-slate-800/80 hover:text-white'"
                v-html="link.label"
            />
        </template>
    </div>
</template>
