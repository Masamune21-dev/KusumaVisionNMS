<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { ArrowRight, Cable, Loader2, Search, WifiOff } from '@lucide/vue';

const props = defineProps({
    open: { type: Boolean, default: false },
});
const emit = defineEmits(['update:open']);

const query = ref('');
const results = ref([]);
const loading = ref(false);
const activeIndex = ref(0);
const inputRef = ref(null);
let debounce = null;

const close = () => {
    emit('update:open', false);
    query.value = '';
    results.value = [];
    activeIndex.value = 0;
};

watch(() => props.open, async (val) => {
    if (val) {
        await nextTick();
        inputRef.value?.focus();
    }
});

watch(query, (q) => {
    if (debounce) clearTimeout(debounce);
    if (!q || q.length < 2) {
        results.value = [];
        loading.value = false;
        return;
    }
    loading.value = true;
    debounce = setTimeout(async () => {
        try {
            const { data } = await axios.get(route('dashboard.search'), { params: { q } });
            results.value = data.results ?? [];
            activeIndex.value = 0;
        } catch (e) {
            results.value = [];
        } finally {
            loading.value = false;
        }
    }, 200);
});

const flatResults = computed(() => results.value);

const onSelect = (item) => {
    if (!item?.url) return;
    router.visit(item.url);
    close();
};

const onKeydown = (e) => {
    if (!props.open) return;
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        activeIndex.value = Math.min(activeIndex.value + 1, flatResults.value.length - 1);
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        activeIndex.value = Math.max(activeIndex.value - 1, 0);
    } else if (e.key === 'Enter') {
        e.preventDefault();
        const item = flatResults.value[activeIndex.value];
        if (item) onSelect(item);
    }
};

const iconFor = (type) => ({
    olt: Cable,
    onu: WifiOff,
}[type] ?? Search);
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition duration-150 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition duration-100 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="open"
                class="fixed inset-0 z-[100] flex items-start justify-center bg-black/70 px-4 pt-[10vh] backdrop-blur-sm"
                @click.self="close"
                @keydown="onKeydown"
            >
                <div class="w-full max-w-xl overflow-hidden rounded-2xl border border-white/10 bg-slate-900/95 shadow-2xl shadow-black/60 backdrop-blur-xl">
                    <div class="flex items-center gap-3 border-b border-white/10 px-4">
                        <Search class="h-5 w-5 flex-shrink-0 text-slate-400" />
                        <input
                            ref="inputRef"
                            v-model="query"
                            type="text"
                            placeholder="Cari OLT, ONU serial, lokasi..."
                            class="h-14 w-full border-0 bg-transparent text-slate-100 placeholder:text-slate-500 focus:outline-none focus:ring-0"
                        />
                        <Loader2 v-if="loading" class="h-4 w-4 flex-shrink-0 animate-spin text-cyan-400" />
                        <kbd class="hidden flex-shrink-0 items-center rounded border border-white/10 bg-slate-800/80 px-1.5 py-0.5 text-[11px] text-slate-400 sm:inline-flex">esc</kbd>
                    </div>

                    <div class="max-h-[60vh] overflow-y-auto">
                        <ul v-if="flatResults.length > 0" class="py-2">
                            <li
                                v-for="(item, idx) in flatResults"
                                :key="item.type + ':' + item.id"
                                class="flex cursor-pointer items-center gap-3 px-4 py-2.5 transition-colors"
                                :class="idx === activeIndex ? 'bg-cyan-500/10 text-white' : 'text-slate-300 hover:bg-white/5'"
                                @click="onSelect(item)"
                                @mouseenter="activeIndex = idx"
                            >
                                <component :is="iconFor(item.type)" class="h-4 w-4 flex-shrink-0 text-slate-400" />
                                <div class="min-w-0 flex-1">
                                    <div class="truncate text-sm font-medium">{{ item.label }}</div>
                                    <div class="truncate text-xs text-slate-500">{{ item.sublabel }}</div>
                                </div>
                                <ArrowRight class="h-4 w-4 flex-shrink-0 text-slate-500" />
                            </li>
                        </ul>
                        <div v-else-if="query.length >= 2 && !loading" class="px-4 py-10 text-center text-sm text-slate-500">
                            Tidak ada hasil untuk &ldquo;{{ query }}&rdquo;.
                        </div>
                        <div v-else class="px-4 py-10 text-center text-sm text-slate-500">
                            Ketik minimal 2 karakter untuk mulai mencari.
                        </div>
                    </div>

                    <div class="flex items-center justify-between gap-3 border-t border-white/10 bg-slate-950/40 px-4 py-2 text-[11px] text-slate-500">
                        <span class="flex items-center gap-3">
                            <span class="flex items-center gap-1">
                                <kbd class="rounded border border-white/10 bg-slate-800/80 px-1.5 py-0.5">&uarr;&darr;</kbd>
                                navigasi
                            </span>
                            <span class="flex items-center gap-1">
                                <kbd class="rounded border border-white/10 bg-slate-800/80 px-1.5 py-0.5">&crarr;</kbd>
                                pilih
                            </span>
                        </span>
                        <span>KusumaVision NMS</span>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
