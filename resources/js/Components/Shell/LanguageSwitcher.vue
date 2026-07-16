<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Check, Languages } from '@lucide/vue';
import { useLocale } from '@/Composables/useLocale';

const { current, options, change } = useLocale();
const open = ref(false);
const root = ref(null);

const currentCode = computed(() => String(current.value ?? 'id').toUpperCase());

const select = (code) => {
    change(code);
    open.value = false;
};

const onClickOutside = (e) => {
    if (open.value && root.value && !root.value.contains(e.target)) {
        open.value = false;
    }
};
onMounted(() => document.addEventListener('click', onClickOutside));
onUnmounted(() => document.removeEventListener('click', onClickOutside));
</script>

<template>
    <div ref="root" class="relative">
        <button
            type="button"
            class="flex h-10 items-center gap-1.5 rounded-xl border border-white/10 bg-slate-900/60 px-2.5 text-slate-300 transition-colors hover:border-cyan-500/30 hover:bg-slate-900/80 hover:text-white lg:h-11"
            :aria-label="$t('language.label')"
            @click.stop="open = !open"
        >
            <Languages class="h-4 w-4" />
            <span class="text-xs font-semibold">{{ currentCode }}</span>
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
                class="absolute right-0 z-50 mt-2 w-48 origin-top-right overflow-hidden rounded-2xl border border-white/10 bg-slate-900/95 shadow-2xl shadow-black/60 backdrop-blur-xl"
            >
                <div class="border-b border-white/10 px-4 py-2 text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                    {{ $t('language.label') }}
                </div>
                <div class="py-1">
                    <button
                        v-for="opt in options"
                        :key="opt.code"
                        type="button"
                        class="flex w-full items-center justify-between gap-3 px-4 py-2.5 text-sm transition-colors hover:bg-white/5"
                        :class="opt.code === current ? 'text-cyan-300' : 'text-slate-300 hover:text-white'"
                        @click="select(opt.code)"
                    >
                        <span>{{ opt.label }}</span>
                        <Check v-if="opt.code === current" class="h-4 w-4 flex-shrink-0" />
                    </button>
                </div>
            </div>
        </Transition>
    </div>
</template>
