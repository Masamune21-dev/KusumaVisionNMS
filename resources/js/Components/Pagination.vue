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
                class="rounded-md px-3 py-2 text-sm text-slate-400"
                v-html="link.label"
            />
            <Link
                v-else
                :href="link.url"
                preserve-scroll
                preserve-state
                class="inline-flex min-h-10 items-center justify-center rounded-md border px-3 py-2 text-sm"
                :class="link.active
                    ? 'border-sky-500 bg-sky-50 text-sky-700'
                    : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50'"
                v-html="link.label"
            />
        </template>
    </div>
</template>
