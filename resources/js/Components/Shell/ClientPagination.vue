<script setup>
/**
 * ClientPagination — kontrol paginasi sisi-klien (dipasangkan dengan usePagination).
 * Responsif: info "X–Y dari Z" + pemilih ukuran halaman (desktop) dan prev/next
 * dengan target sentuh ≥44px.
 */
import { ChevronLeft, ChevronRight } from '@lucide/vue';

defineProps({
    page: { type: Number, required: true },
    pageCount: { type: Number, required: true },
    total: { type: Number, required: true },
    rangeStart: { type: Number, required: true },
    rangeEnd: { type: Number, required: true },
    pageSize: { type: Number, required: true },
    pageSizeOptions: { type: Array, default: () => [25, 50, 100] },
    label: { type: String, default: 'item' },
});

const emit = defineEmits(['update:page', 'update:pageSize']);
</script>

<template>
    <div class="flex flex-col gap-3 border-t border-white/10 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-6">
        <div class="flex items-center gap-3 text-xs text-slate-500">
            <span class="tabular-nums">
                Menampilkan <span class="font-semibold text-slate-300">{{ rangeStart }}–{{ rangeEnd }}</span>
                dari <span class="font-semibold text-slate-300">{{ total }}</span> {{ label }}
            </span>
            <label class="hidden items-center gap-1.5 sm:flex">
                <span class="sr-only">Item per halaman</span>
                <select
                    :value="pageSize"
                    class="min-h-9 rounded-lg border border-white/10 bg-slate-900/60 py-0 pl-2 pr-7 text-xs text-slate-200 focus:border-cyan-500 focus:ring-cyan-500"
                    :title="$t('shell.items_per_page')"
                    @change="emit('update:pageSize', Number($event.target.value))"
                >
                    <option v-for="opt in pageSizeOptions" :key="opt" :value="opt">{{ opt }} / hal</option>
                </select>
            </label>
        </div>

        <div class="flex items-center justify-between gap-2 sm:justify-end">
            <button
                type="button"
                class="inline-flex min-h-9 items-center gap-1 rounded-lg border border-white/10 bg-slate-900/60 px-3 text-sm font-medium text-slate-200 transition-colors enabled:hover:bg-white/5 disabled:cursor-not-allowed disabled:opacity-40"
                :disabled="page <= 1"
                @click="emit('update:page', page - 1)"
            >
                <ChevronLeft class="h-4 w-4" />
                <span class="hidden sm:inline">Sebelumnya</span>
            </button>
            <span class="px-1 text-xs text-slate-400 tabular-nums">Hal {{ page }} / {{ pageCount }}</span>
            <button
                type="button"
                class="inline-flex min-h-9 items-center gap-1 rounded-lg border border-white/10 bg-slate-900/60 px-3 text-sm font-medium text-slate-200 transition-colors enabled:hover:bg-white/5 disabled:cursor-not-allowed disabled:opacity-40"
                :disabled="page >= pageCount"
                @click="emit('update:page', page + 1)"
            >
                <span class="hidden sm:inline">Berikutnya</span>
                <ChevronRight class="h-4 w-4" />
            </button>
        </div>
    </div>
</template>
