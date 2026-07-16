<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronDown, LogOut, User } from '@lucide/vue';

const page = usePage();
const open = ref(false);
const dropdownRef = ref(null);

const user = computed(() => page.props.auth?.user ?? {});
const initial = computed(() => (user.value.name ?? '?').charAt(0).toUpperCase());
const role = computed(() => user.value.role ?? user.value.email?.split('@')[0] ?? 'user');

const onClickOutside = (e) => {
    if (open.value && dropdownRef.value && !dropdownRef.value.contains(e.target)) {
        open.value = false;
    }
};
onMounted(() => document.addEventListener('click', onClickOutside));
onUnmounted(() => document.removeEventListener('click', onClickOutside));
</script>

<template>
    <div ref="dropdownRef" class="relative">
        <button
            type="button"
            class="flex h-11 items-center gap-3 rounded-xl border border-white/10 bg-slate-900/60 pl-1 pr-3 transition-colors hover:border-cyan-500/30 hover:bg-slate-900/80"
            @click.stop="open = !open"
        >
            <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-gradient-to-br from-cyan-500 to-sky-600 text-sm font-bold text-white shadow-inner shadow-white/10">
                {{ initial }}
            </span>
            <span class="hidden text-left sm:block">
                <span class="block text-sm font-semibold text-white">{{ user.name }}</span>
                <span class="block text-[11px] text-slate-500">{{ role }}</span>
            </span>
            <ChevronDown class="h-4 w-4 text-slate-400 transition-transform" :class="{ 'rotate-180': open }" />
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
                class="absolute right-0 z-50 mt-2 w-56 origin-top-right overflow-hidden rounded-2xl border border-white/10 bg-slate-900/95 shadow-2xl shadow-black/60 backdrop-blur-xl"
            >
                <div class="border-b border-white/10 px-4 py-3">
                    <p class="truncate text-sm font-semibold text-white">{{ user.name }}</p>
                    <p class="truncate text-xs text-slate-500">{{ user.email }}</p>
                </div>
                <div class="py-1">
                    <Link
                        :href="route('profile.edit')"
                        class="flex items-center gap-3 px-4 py-2.5 text-sm text-slate-300 transition-colors hover:bg-white/5 hover:text-white"
                        @click="open = false"
                    >
                        <User class="h-4 w-4" />
                        {{ $t('common.profile') }}
                    </Link>
                    <Link
                        :href="route('logout')"
                        method="post"
                        as="button"
                        class="flex w-full items-center gap-3 px-4 py-2.5 text-sm text-slate-300 transition-colors hover:bg-red-500/10 hover:text-red-300"
                    >
                        <LogOut class="h-4 w-4" />
                        {{ $t('common.logout') }}
                    </Link>
                </div>
            </div>
        </Transition>
    </div>
</template>
