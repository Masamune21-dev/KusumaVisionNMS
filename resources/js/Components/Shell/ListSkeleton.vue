<script setup>
/**
 * ListSkeleton — placeholder shimmer untuk daftar ONU saat scan/refresh (operasi
 * SNMP lambat). Meniru struktur responsif kv-mobile-list + kv-table-desktop.
 * `animate-pulse` dimatikan otomatis saat prefers-reduced-motion (lihat app.css).
 */
defineProps({
    rows: { type: Number, default: 8 },
});

// Lebar bar acak-tapi-stabil agar skeleton tak terlihat kaku.
const widths = ['w-3/4', 'w-1/2', 'w-2/3', 'w-5/6', 'w-1/3', 'w-3/5'];
const pick = (i, offset = 0) => widths[(i + offset) % widths.length];
</script>

<template>
    <div class="animate-pulse" aria-hidden="true">
        <!-- Mobile cards -->
        <div class="kv-mobile-list">
            <div v-for="i in Math.min(rows, 4)" :key="`m-${i}`" class="kv-mobile-card">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0 flex-1 space-y-2">
                        <div class="h-3.5 rounded bg-white/10" :class="pick(i)"></div>
                        <div class="h-2.5 rounded bg-white/5" :class="pick(i, 2)"></div>
                    </div>
                    <div class="h-5 w-14 rounded-full bg-white/10"></div>
                </div>
                <div class="mt-4 space-y-2">
                    <div v-for="j in 3" :key="j" class="flex items-center justify-between gap-4">
                        <div class="h-2.5 w-16 rounded bg-white/5"></div>
                        <div class="h-2.5 rounded bg-white/10" :class="pick(i, j)"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Desktop rows -->
        <div class="hidden md:block">
            <div class="space-y-px">
                <div
                    v-for="i in rows"
                    :key="`d-${i}`"
                    class="flex items-center gap-6 px-6 py-4"
                >
                    <div class="h-3.5 flex-1 rounded bg-white/10" :class="pick(i)"></div>
                    <div class="h-3.5 w-32 rounded bg-white/5"></div>
                    <div class="h-3.5 w-24 rounded bg-white/10" :class="pick(i, 1)"></div>
                    <div class="h-5 w-16 rounded-full bg-white/10"></div>
                    <div class="h-3.5 w-20 rounded bg-white/5"></div>
                    <div class="h-8 w-24 rounded bg-white/5"></div>
                </div>
            </div>
        </div>
    </div>
</template>
