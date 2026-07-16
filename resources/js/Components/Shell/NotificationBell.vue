<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { BellRing, CheckCheck } from '@lucide/vue';

const page = usePage();
const open = ref(false);
const dropdownRef = ref(null);

const notifications = computed(() => page.props.notifications?.items ?? []);
const unreadCount = computed(() => page.props.notifications?.unread_count ?? 0);

const severityClass = (severity) => ({
    critical: 'kv-pill-critical',
    major: 'kv-pill-major',
    minor: 'kv-pill-minor',
    warning: 'kv-pill-warning',
}[severity] ?? 'kv-pill-muted');

const formatRelative = (iso) => {
    if (!iso) return '';
    const diff = Math.floor((Date.now() - new Date(iso).getTime()) / 1000);
    if (diff < 60) return 'baru saja';
    if (diff < 3600) return `${Math.floor(diff / 60)} menit lalu`;
    if (diff < 86400) return `${Math.floor(diff / 3600)} jam lalu`;
    return `${Math.floor(diff / 86400)} hari lalu`;
};

const markAllRead = () => {
    router.post(route('notifications.read-all'), {}, {
        preserveScroll: true,
        only: ['notifications'],
        onSuccess: () => (open.value = false),
    });
};

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
            class="relative flex h-11 w-11 items-center justify-center rounded-xl border border-white/10 bg-slate-900/60 text-slate-300 transition-colors hover:border-cyan-500/30 hover:bg-slate-900/80 hover:text-white"
            aria-label="Notifikasi"
            @click.stop="open = !open"
        >
            <BellRing class="h-5 w-5" />
            <span
                v-if="unreadCount > 0"
                class="absolute -right-1 -top-1 flex h-5 min-w-[1.25rem] items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white ring-2 ring-slate-950"
            >
                {{ unreadCount > 99 ? '99+' : unreadCount }}
            </span>
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
                class="absolute right-0 z-50 mt-2 w-96 max-w-[calc(100vw-2rem)] origin-top-right rounded-2xl border border-white/10 bg-slate-900/95 shadow-2xl shadow-black/60 backdrop-blur-xl"
            >
                <div class="flex items-center justify-between border-b border-white/10 px-4 py-3">
                    <h3 class="text-sm font-semibold text-white">Notifikasi</h3>
                    <button
                        v-if="unreadCount > 0"
                        type="button"
                        class="flex items-center gap-1.5 text-xs font-medium text-cyan-400 transition-colors hover:text-cyan-300"
                        @click="markAllRead"
                    >
                        <CheckCheck class="h-3.5 w-3.5" />
                        Tandai semua dibaca
                    </button>
                </div>
                <div class="max-h-[400px] overflow-y-auto">
                    <ul v-if="notifications.length > 0" class="divide-y divide-white/5">
                        <li
                            v-for="notif in notifications"
                            :key="notif.id"
                            class="group px-4 py-3 transition-colors hover:bg-white/5"
                            :class="{ 'bg-cyan-500/5': !notif.read_at }"
                        >
                            <div class="flex items-start gap-3">
                                <span :class="severityClass(notif.severity)">{{ notif.severity }}</span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm text-slate-100">{{ notif.message }}</p>
                                    <p class="mt-0.5 text-xs text-slate-500">
                                        {{ notif.olt_name }} &middot; {{ formatRelative(notif.created_at) }}
                                    </p>
                                </div>
                                <span v-if="!notif.read_at" class="mt-1 h-2 w-2 flex-shrink-0 rounded-full bg-cyan-400" />
                            </div>
                        </li>
                    </ul>
                    <div v-else class="px-4 py-10 text-center text-sm text-slate-500">
                        {{ $t('shell.no_notifications') }}
                    </div>
                </div>
                <div class="border-t border-white/10 px-4 py-2.5">
                    <Link
                        :href="route('alarms.index')"
                        class="block text-center text-xs font-medium text-cyan-400 hover:text-cyan-300"
                        @click="open = false"
                    >
                        {{ $t('shell.view_all_alarms') }} &rarr;
                    </Link>
                </div>
            </div>
        </Transition>
    </div>
</template>
