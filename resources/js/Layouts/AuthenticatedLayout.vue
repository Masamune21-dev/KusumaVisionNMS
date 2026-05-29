<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import GlobalSearch from '@/Components/Shell/GlobalSearch.vue';
import AuroraBackground from '@/Components/Shell/AuroraBackground.vue';
import NotificationBell from '@/Components/Shell/NotificationBell.vue';
import SidebarConstellation from '@/Components/Shell/SidebarConstellation.vue';
import SystemInfoPanel from '@/Components/Shell/SystemInfoPanel.vue';
import UserMenu from '@/Components/Shell/UserMenu.vue';
import { Link, usePage } from '@inertiajs/vue3';
import { BellRing, Cable, ChevronLeft, Eye, FileBarChart, LayoutDashboard, LogOut, Menu, Radar, ScrollText, Search, Settings, User, Users, WifiOff } from '@lucide/vue';

const SIDEBAR_COLLAPSED_KEY = 'kv-sidebar-collapsed';
const sidebarOpen = ref(false);
const sidebarCollapsed = ref(
    typeof window !== 'undefined' && window.localStorage.getItem(SIDEBAR_COLLAPSED_KEY) === '1',
);

watch(sidebarCollapsed, (value) => {
    if (typeof window !== 'undefined') {
        window.localStorage.setItem(SIDEBAR_COLLAPSED_KEY, value ? '1' : '0');
    }
});
const searchOpen = ref(false);
const isDesktop = ref(false);
const page = usePage();
const showSidebarContent = computed(() => !sidebarCollapsed.value || (!isDesktop.value && sidebarOpen.value));
let sidebarMediaQuery = null;

const can = computed(() => page.props.auth?.can ?? {});
const isDemo = computed(() => Boolean(can.value.is_demo));
const appName = computed(() => page.props.branding?.name ?? 'KusumaVision');
const user = computed(() => page.props.auth?.user ?? {});
const userInitial = computed(() => (user.value.name ?? '?').charAt(0).toUpperCase());

const navLinks = computed(() => {
    const links = [
        { name: 'Dashboard', icon: LayoutDashboard, href: route('dashboard'), match: 'dashboard' },
        { name: 'SmartOLT', icon: Cable, href: route('smartolt.index'), match: 'smartolt.*', except: 'smartolt.unconfigured-all' },
        { name: 'ONU Monitoring', icon: Radar, href: route('monitoring.onu'), match: 'monitoring.*' },
        { name: 'Unconfigured', icon: WifiOff, href: route('smartolt.unconfigured-all'), match: 'smartolt.unconfigured-all' },
        { name: 'Alarms', icon: BellRing, href: route('alarms.index'), match: 'alarms.*' },
        { name: 'Report', icon: FileBarChart, href: route('reports.index'), match: 'reports.*' },
    ];

    if (can.value.manage_users) {
        links.push({ name: 'Users', icon: Users, href: route('users.index'), match: 'users.*' });
        links.push({ name: 'Audit Logs', icon: ScrollText, href: route('audit-logs.index'), match: 'audit-logs.*' });
        links.push({ name: 'Pengaturan', icon: Settings, href: route('settings.edit'), match: 'settings.*' });
    }

    return links;
});

const isActive = (link) => {
    if (!route().current(link.match)) return false;
    if (link.except && route().current(link.except)) return false;
    return true;
};

const onKey = (e) => {
    const isModK = (e.key === 'k' || e.key === 'K') && (e.metaKey || e.ctrlKey);
    if (isModK) {
        e.preventDefault();
        searchOpen.value = true;
    }
    if (e.key === 'Escape' && searchOpen.value) {
        searchOpen.value = false;
    }
};

const syncSidebarViewport = () => {
    isDesktop.value = sidebarMediaQuery?.matches ?? false;
    if (isDesktop.value) {
        sidebarOpen.value = false;
    }
};

onMounted(() => {
    window.addEventListener('keydown', onKey);
    sidebarMediaQuery = window.matchMedia('(min-width: 1024px)');
    syncSidebarViewport();
    sidebarMediaQuery.addEventListener('change', syncSidebarViewport);
});

onUnmounted(() => {
    window.removeEventListener('keydown', onKey);
    sidebarMediaQuery?.removeEventListener('change', syncSidebarViewport);
});
</script>

<template>
    <div class="min-h-screen overflow-x-hidden bg-slate-950">
        <!-- Mobile top bar -->
        <div class="sticky top-0 z-50 flex h-14 items-center gap-3 border-b border-white/10 bg-slate-950/40 px-4 backdrop-blur-xl lg:hidden">
            <button
                type="button"
                class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-md text-slate-400 transition-colors hover:bg-white/10 hover:text-white"
                aria-label="Buka menu navigasi"
                @click="sidebarOpen = true"
            >
                <Menu class="h-5 w-5" />
            </button>
            <Link :href="route('dashboard')" class="flex min-w-0 items-center gap-2">
                <ApplicationLogo class="h-6 w-auto fill-current text-cyan-400" />
                <span class="hidden text-sm font-bold text-white sm:inline">{{ appName }}</span>
            </Link>
            <div class="ml-auto flex items-center gap-2">
                <button
                    type="button"
                    class="flex h-10 w-10 items-center justify-center rounded-md border border-white/10 bg-slate-900/60 text-slate-300 transition-colors hover:border-cyan-500/30 hover:bg-slate-900/80 hover:text-white"
                    aria-label="Buka pencarian"
                    @click="searchOpen = true"
                >
                    <Search class="h-4 w-4" />
                </button>
                <NotificationBell />
            </div>
        </div>

        <!-- Mobile sidebar overlay -->
        <Transition
            enter-active-class="transition-opacity duration-200"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition-opacity duration-200"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="sidebarOpen"
                class="fixed inset-0 z-40 bg-black/70 backdrop-blur-sm lg:hidden"
                @click="sidebarOpen = false"
            />
        </Transition>

        <!-- Sidebar -->
        <aside
            class="fixed inset-y-0 left-0 z-50 flex max-w-[calc(100vw-1rem)] flex-col border-r border-white/10 bg-slate-950/35 backdrop-blur-xl transition-all duration-200 ease-in-out lg:translate-x-0"
            :class="[
                sidebarOpen ? 'translate-x-0' : '-translate-x-full',
                sidebarCollapsed ? 'w-64 lg:w-20' : 'w-64',
            ]"
        >
            <SidebarConstellation v-if="showSidebarContent" />

            <!-- Logo -->
            <div class="relative z-10 flex h-[72px] items-center justify-between border-b border-white/10 bg-slate-950/20 px-5 backdrop-blur-sm">
                <Link
                    :href="route('dashboard')"
                    class="flex items-center gap-3 overflow-hidden"
                    @click="sidebarOpen = false"
                >
                    <div class="relative flex-shrink-0">
                        <ApplicationLogo class="h-8 w-auto fill-current text-cyan-400 drop-shadow-[0_0_8px_rgba(34,211,238,0.45)]" />
                    </div>
                    <div v-if="showSidebarContent" class="min-w-0">
                        <div class="truncate text-base font-bold leading-tight text-white">{{ appName }}</div>
                        <div class="truncate text-[11px] text-slate-500">NMS v2 &middot; GPON Management</div>
                    </div>
                </Link>
                <!-- Desktop collapse toggle — always centered on right border -->
                <button
                    type="button"
                    class="absolute right-0 top-1/2 z-20 hidden h-7 w-7 -translate-y-1/2 translate-x-1/2 items-center justify-center rounded-full border border-white/10 bg-slate-900 text-slate-400 shadow-md shadow-black/40 transition-colors hover:border-cyan-500/40 hover:text-white lg:flex"
                    :aria-label="sidebarCollapsed ? 'Buka sidebar' : 'Tutup sidebar'"
                    @click="sidebarCollapsed = !sidebarCollapsed"
                >
                    <ChevronLeft class="h-4 w-4 transition-transform" :class="{ 'rotate-180': sidebarCollapsed }" />
                </button>
            </div>

            <!-- Navigation -->
            <nav class="relative z-10 flex-1 overflow-y-auto px-3 py-5">
                <div class="space-y-1">
                    <Link
                        v-for="link in navLinks"
                        :key="link.name"
                        :href="link.href"
                        class="group relative flex items-center gap-3.5 rounded-xl px-3 py-2.5 text-[14px] font-semibold transition-all"
                        :class="isActive(link)
                            ? 'bg-gradient-to-r from-cyan-500 to-sky-500 text-white shadow-lg shadow-cyan-500/30'
                            : 'text-slate-400 hover:bg-white/5 hover:text-slate-100'"
                        :title="!showSidebarContent ? link.name : null"
                        @click="sidebarOpen = false"
                    >
                        <component :is="link.icon" class="h-5 w-5 flex-shrink-0" />
                        <span v-if="showSidebarContent" class="truncate">{{ link.name }}</span>
                    </Link>
                </div>
            </nav>

            <!-- User account (mobile only — desktop uses header UserMenu) -->
            <div v-if="showSidebarContent" class="relative z-10 border-t border-white/10 px-3 py-3 lg:hidden">
                <div class="flex items-center gap-3 rounded-xl bg-white/5 px-3 py-2.5">
                    <span class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-gradient-to-br from-cyan-500 to-sky-600 text-sm font-bold text-white shadow-inner shadow-white/10">
                        {{ userInitial }}
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold text-white">{{ user.name }}</p>
                        <p class="truncate text-[11px] text-slate-500">{{ user.email }}</p>
                    </div>
                </div>
                <div class="mt-2 grid grid-cols-2 gap-2">
                    <Link
                        :href="route('profile.edit')"
                        class="flex items-center justify-center gap-2 rounded-lg border border-white/10 bg-slate-900/60 px-3 py-2 text-sm font-medium text-slate-300 transition-colors hover:bg-white/5 hover:text-white"
                        @click="sidebarOpen = false"
                    >
                        <User class="h-4 w-4" />
                        Profile
                    </Link>
                    <Link
                        :href="route('logout')"
                        method="post"
                        as="button"
                        class="flex items-center justify-center gap-2 rounded-lg border border-red-500/20 bg-red-500/10 px-3 py-2 text-sm font-medium text-red-300 transition-colors hover:bg-red-500/20 hover:text-red-200"
                    >
                        <LogOut class="h-4 w-4" />
                        Keluar
                    </Link>
                </div>
            </div>

            <!-- System info panel (bottom) -->
            <div v-if="showSidebarContent" class="relative z-10">
                <SystemInfoPanel />
            </div>
        </aside>

        <!-- Main column (offset by sidebar on desktop) -->
        <div
            class="flex min-h-screen min-w-0 flex-col transition-[padding] duration-200"
            :class="sidebarCollapsed ? 'lg:pl-20' : 'lg:pl-64'"
        >
            <!-- Top header (desktop) — search + notif + user -->
            <header
                class="sticky top-0 z-30 hidden border-b border-white/10 bg-slate-950/35 backdrop-blur-xl lg:block"
            >
                <div class="flex h-[72px] w-full items-center gap-4 px-6 lg:px-8">
                    <!-- Search trigger -->
                    <button
                        type="button"
                        class="group flex h-11 max-w-2xl flex-1 items-center gap-3 rounded-xl border border-white/10 bg-slate-900/60 px-4 text-left text-sm text-slate-500 transition-colors hover:border-cyan-500/30 hover:bg-slate-900/80 hover:text-slate-300"
                        @click="searchOpen = true"
                    >
                        <Search class="h-4 w-4 flex-shrink-0 text-slate-500 group-hover:text-cyan-400" />
                        <span class="flex-1 truncate">Cari perangkat, OLT, ONU, atau lokasi&hellip;</span>
                        <kbd class="hidden items-center gap-1 rounded-md border border-white/10 bg-slate-800/80 px-2 py-0.5 text-[11px] font-medium text-slate-400 sm:inline-flex">
                            <span class="text-xs">&#8984;</span>K
                        </kbd>
                    </button>

                    <div class="ml-auto flex items-center gap-3">
                        <NotificationBell />
                        <UserMenu />
                    </div>
                </div>
            </header>

            <!-- Page header slot (optional, used by inner pages) -->
            <header
                v-if="$slots.header"
                class="sticky top-14 z-20 border-b border-white/10 bg-slate-950/30 backdrop-blur-xl lg:top-[72px]"
            >
                <div class="flex min-h-14 w-full items-center px-4 py-3 sm:px-6 lg:px-8">
                    <div class="w-full text-slate-100">
                        <slot name="header" />
                    </div>
                </div>
            </header>

            <!-- Demo mode banner -->
            <div
                v-if="isDemo"
                class="flex items-center gap-2 border-b border-amber-500/30 bg-amber-500/10 px-4 py-2 text-xs font-medium text-amber-300 sm:px-6 lg:px-8"
            >
                <Eye class="h-4 w-4 flex-shrink-0" />
                <span>Mode Demo &mdash; tampilan read-only dengan data contoh. Perubahan data dinonaktifkan.</span>
            </div>

            <!-- Page content -->
            <main class="kv-grid-bg flex-1">
                <AuroraBackground />
                <Transition name="page" mode="out-in">
                    <div :key="page.component" class="min-w-0">
                        <slot />
                    </div>
                </Transition>
            </main>

            <!-- Footer -->
            <footer class="border-t border-white/10 bg-slate-950/40 backdrop-blur-xl lg:sticky lg:bottom-0 lg:z-10">
                <div class="flex flex-col items-center justify-between gap-1 px-4 py-3 text-xs text-slate-500 sm:flex-row sm:px-6 lg:px-8">
                    <p>&copy; 2026 {{ appName }} NMS &middot; Dibuat Oleh Masamune</p>
                    <p class="hidden sm:block">Platform manajemen jaringan FTTH untuk ISP Indonesia</p>
                </div>
            </footer>
        </div>

        <!-- Global search palette -->
        <GlobalSearch v-model:open="searchOpen" />
    </div>
</template>
