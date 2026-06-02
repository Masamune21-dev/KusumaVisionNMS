<script setup>
/**
 * Kartu filter standar lintas halaman (bentuk seragam: shell kaca + header
 * ikon-tile + judul, body untuk field). Lihat docs/handbook/15-ui-tema-dashboard.md.
 *
 * Slot:
 * - default  : isi filter (toolbar 1 baris: <div class="flex flex-wrap items-center gap-2"> …
 *              input cari + kontrol .kv-filter-control w-full sm:w-auto … </div>)
 * - actions  : tombol kanan-atas header (mis. Reset untuk filter live)
 */
import { SlidersHorizontal } from '@lucide/vue';

defineProps({
    title: { type: String, default: 'Filter' },
    subtitle: { type: String, default: '' },
    // Komponen ikon Lucide; default SlidersHorizontal.
    icon: { type: [Object, Function], default: null },
});
</script>

<template>
    <section class="kv-filter">
        <div class="kv-filter-head">
            <span class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-sky-500/15 ring-1 ring-cyan-500/30">
                <component :is="icon ?? SlidersHorizontal" class="h-5 w-5 text-cyan-400" />
            </span>
            <div class="min-w-0 flex-1">
                <h3 class="text-base font-semibold text-white">{{ title }}</h3>
                <p v-if="subtitle" class="mt-0.5 text-xs text-slate-500">{{ subtitle }}</p>
            </div>
            <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2">
                <slot name="actions" />
            </div>
        </div>
        <div class="kv-filter-body">
            <slot />
        </div>
    </section>
</template>
