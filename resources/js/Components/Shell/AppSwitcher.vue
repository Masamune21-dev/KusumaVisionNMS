<script setup>
/*
 * App Switcher — pindah dashboard tanpa login ulang.
 *
 * Tautannya hanyalah URL biasa ke app tetangga. Yang membuatnya terasa
 * "langsung masuk" adalah middleware `auth` di app tujuan: ia mengarahkan ke IdP,
 * IdP mengenali sesi hub yang sudah ada, lalu memantulkan kembali dengan
 * authorization code — semuanya dalam satu tarikan redirect.
 */
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { ArrowUpRight, Cable, Check, Grid3x3, LayoutGrid, Lock, Router, Wallet } from '@lucide/vue';

const page = usePage();

const ecosystem = computed(() => page.props.ecosystem);
const apps = computed(() => ecosystem.value?.apps ?? []);

// Pemetaan eksplisit: nama ikon datang dari config, dan hanya yang terdaftar
// di sini yang boleh dirender.
const icons = { Cable, Router, Wallet };
const iconFor = (name) => icons[name] ?? LayoutGrid;

const open = ref(false);
const rootRef = ref(null);

const onClickOutside = (e) => {
    if (open.value && rootRef.value && !rootRef.value.contains(e.target)) {
        open.value = false;
    }
};
const onEscape = (e) => {
    if (e.key === 'Escape') open.value = false;
};

onMounted(() => {
    document.addEventListener('click', onClickOutside);
    document.addEventListener('keydown', onEscape);
});
onUnmounted(() => {
    document.removeEventListener('click', onClickOutside);
    document.removeEventListener('keydown', onEscape);
});
</script>

<template>
    <div v-if="ecosystem" ref="rootRef" class="relative">
        <button
            type="button"
            class="flex h-11 w-11 items-center justify-center rounded-xl border border-white/10 bg-slate-900/60 text-slate-400 transition-colors hover:border-cyan-500/30 hover:text-cyan-300"
            :aria-label="$t('ecosystem.switch')"
            :aria-expanded="open"
            @click.stop="open = !open"
        >
            <Grid3x3 class="h-5 w-5" />
        </button>

        <Transition
            enter-active-class="transition duration-150 ease-out"
            enter-from-class="opacity-0 translate-y-1"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition duration-100 ease-in"
            leave-from-class="opacity-100 translate-y-0"
            leave-to-class="opacity-0 translate-y-1"
        >
            <div
                v-if="open"
                class="absolute right-0 z-50 mt-2 w-72 origin-top-right overflow-hidden rounded-2xl border border-white/10 bg-slate-900/95 shadow-2xl shadow-black/60 backdrop-blur-xl"
            >
                <div class="border-b border-white/10 px-4 py-3">
                    <p class="text-sm font-semibold text-white">{{ $t('ecosystem.title') }}</p>
                    <p class="mt-0.5 text-[11px] text-slate-500">{{ $t('ecosystem.subtitle') }}</p>
                </div>

                <div class="py-1">
                    <template v-for="app in apps" :key="app.key">
                        <!-- App yang sedang dibuka: bukan tautan, hanya penanda posisi. -->
                        <div
                            v-if="app.is_current"
                            class="flex items-center gap-3 bg-cyan-500/5 px-4 py-2.5"
                        >
                            <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-cyan-500/15 text-cyan-300">
                                <component :is="iconFor(app.icon)" class="h-4 w-4" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium text-white">{{ app.name }}</span>
                                <span class="block text-[11px] text-cyan-400">{{ $t('ecosystem.current') }}</span>
                            </span>
                            <Check class="h-4 w-4 flex-shrink-0 text-cyan-400" />
                        </div>

                        <a
                            v-else-if="app.enabled"
                            :href="app.url"
                            class="group flex items-center gap-3 px-4 py-2.5 transition-colors hover:bg-white/5"
                        >
                            <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-slate-800/80 text-slate-400 transition-colors group-hover:bg-cyan-500/15 group-hover:text-cyan-300">
                                <component :is="iconFor(app.icon)" class="h-4 w-4" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium text-slate-300 group-hover:text-white">{{ app.name }}</span>
                                <span class="block text-[11px] text-slate-500">{{ $t('ecosystem.open') }}</span>
                            </span>
                            <ArrowUpRight class="h-4 w-4 flex-shrink-0 text-slate-600 group-hover:text-cyan-400" />
                        </a>

                        <div
                            v-else
                            class="flex cursor-not-allowed items-center gap-3 px-4 py-2.5 opacity-45"
                            :title="$t('ecosystem.no_access')"
                        >
                            <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-slate-800/60 text-slate-600">
                                <component :is="iconFor(app.icon)" class="h-4 w-4" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium text-slate-500">{{ app.name }}</span>
                                <span class="block text-[11px] text-slate-600">{{ $t('ecosystem.no_access') }}</span>
                            </span>
                            <Lock class="h-3.5 w-3.5 flex-shrink-0 text-slate-600" />
                        </div>
                    </template>
                </div>
            </div>
        </Transition>
    </div>
</template>
