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
    <div v-if="links.length > 3" class="flex flex-wrap items-center gap-1">
        <template v-for="(link, index) in links" :key="index">
            <span
                v-if="link.url === null"
                class="rounded-md px-3 py-2 text-sm text-gray-400"
                v-html="link.label"
            />
            <Link
                v-else
                :href="link.url"
                preserve-scroll
                preserve-state
                class="rounded-md border px-3 py-2 text-sm"
                :class="link.active
                    ? 'border-indigo-500 bg-indigo-50 text-indigo-700'
                    : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50'"
                v-html="link.label"
            />
        </template>
    </div>
</template>
