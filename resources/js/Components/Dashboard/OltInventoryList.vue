<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ChevronRight, Server } from '@lucide/vue';

const props = defineProps({
    items: { type: Array, default: () => [] },
});

const totals = computed(() => props.items.reduce(
    (acc, row) => ({
        unit: acc.unit + (row.unit ?? 0),
        up: acc.up + (row.up ?? 0),
        down: acc.down + (row.down ?? 0),
    }),
    { unit: 0, up: 0, down: 0 },
));
</script>

<template>
    <div class="kv-glass-panel flex h-full flex-col overflow-hidden">
        <div class="flex items-center justify-between border-b border-white/5 px-5 py-4">
            <div class="flex items-center gap-3">
                <span class="kv-circle-sky">
                    <Server class="h-5 w-5" />
                </span>
                <h3 class="text-base font-semibold text-white">Inventory OLT</h3>
            </div>
            <Link :href="route('smartolt.index')" class="text-slate-400 transition-colors hover:text-cyan-400">
                <ChevronRight class="h-5 w-5" />
            </Link>
        </div>

        <!-- Scroll di dalam ruang tersisa (min-h-0 + absolut) supaya card tak bertambah tinggi berapa pun jumlah OLT -->
        <div v-if="items.length > 0" class="relative min-h-0 flex-1">
            <ul class="absolute inset-0 divide-y divide-white/5 overflow-y-auto">
                <li v-for="row in items" :key="row.id" class="flex items-center justify-between gap-3 px-5 py-3">
                    <div class="flex min-w-0 items-center gap-3">
                        <span class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-slate-800/80 ring-1 ring-white/10">
                            <Server class="h-4 w-4 text-slate-400" />
                        </span>
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-white">{{ row.name }}</p>
                            <p class="truncate text-xs text-slate-500">{{ row.model }}</p>
                        </div>
                    </div>
                    <span :class="row.reachable ? 'kv-pill-success' : 'kv-pill-danger'">
                        {{ row.reachable ? 'Up' : 'Down' }}
                    </span>
                </li>
            </ul>
        </div>
        <div v-else class="flex flex-1 items-center justify-center px-5 py-10 text-center text-sm text-slate-500">
            Belum ada OLT terdaftar.
        </div>

        <div v-if="items.length > 0" class="flex items-center justify-between border-t border-white/10 bg-slate-950/30 px-5 py-3">
            <p class="text-sm font-semibold text-slate-300">Total <span class="ml-2 text-xs font-normal text-slate-500">{{ totals.unit }} Unit</span></p>
            <div class="flex items-center gap-2">
                <span class="kv-pill-success">Up {{ totals.up }}</span>
                <span class="kv-pill-danger">Down {{ totals.down }}</span>
            </div>
        </div>
    </div>
</template>
