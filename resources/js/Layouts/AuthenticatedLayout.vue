<script setup>
import { computed, defineAsyncComponent, onMounted, onUnmounted, ref, watch } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import FlashMessages from '@/Components/Shell/FlashMessages.vue';
import GlobalSearch from '@/Components/Shell/GlobalSearch.vue';
import LanguageSwitcher from '@/Components/Shell/LanguageSwitcher.vue';
import NotificationBell from '@/Components/Shell/NotificationBell.vue';
import SidebarConstellation from '@/Components/Shell/SidebarConstellation.vue';
import SystemInfoPanel from '@/Components/Shell/SystemInfoPanel.vue';
import ThemeSegmented from '@/Components/Shell/ThemeSegmented.vue';
import UserMenu from '@/Components/Shell/UserMenu.vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { BellRing, BookOpen, Cable, ChevronLeft, Eye, FileBarChart, LayoutDashboard, LogOut, MapPin, Menu, Radar, ScrollText, Search, Send, Settings, User, Users, Waypoints, WifiOff } from '@lucide/vue';

const { t } = useI18n({ useScope: 'global' });

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
// Atribusi pemilik permanen (dari konstanta backend, bukan Settings). Lihat GeneralSetting::OWNER.
const owner = computed(() => page.props.branding?.owner ?? 'PT Berkah Media Kusuma Vision');
const copyrightYear = computed(() => page.props.branding?.copyright_year ?? '2026');
const user = computed(() => page.props.auth?.user ?? {});
const userInitial = computed(() => (user.value.name ?? '?').charAt(0).toUpperCase());

// Menu dikelompokkan per alur kerja: label kelompok
// saat sidebar lebar, garis pemisah saat diciutkan. Kelompok yang semua itemnya tersembunyi
// (mis. Administrasi untuk operator) ikut hilang supaya tak ada label kosong.
const navGroups = computed(() => {
    const groups = [
        {
            key: 'overview',
            label: t('nav.group_overview'),
            items: [
                { name: t('nav.dashboard'), icon: LayoutDashboard, href: route('dashboard'), match: 'dashboard' },
            ],
        },
        {
            key: 'network',
            label: t('nav.group_network'),
            items: [
                { name: t('nav.smartolt'), icon: Cable, href: route('smartolt.index'), match: ['smartolt.*', 'cdata-olt.*', 'hioso-olt.*', 'hsairpo-olt.*'], except: 'smartolt.unconfigured-all' },
                { name: t('nav.unconfigured'), icon: WifiOff, href: route('smartolt.unconfigured-all'), match: 'smartolt.unconfigured-all' },
                { name: t('nav.monitoring'), icon: Radar, href: route('monitoring.onu'), match: 'monitoring.*' },
            ],
        },
        {
            key: 'field',
            label: t('nav.group_field'),
            items: [
                { name: t('nav.map'), icon: MapPin, href: route('map.index'), match: 'map.*' },
                { name: t('nav.odp'), icon: Waypoints, href: route('odp.index'), match: 'odp.*' },
            ],
        },
        {
            key: 'watch',
            label: t('nav.group_watch'),
            items: [
                { name: t('nav.alarms'), icon: BellRing, href: route('alarms.index'), match: 'alarms.*' },
                { name: t('nav.report'), icon: FileBarChart, href: route('reports.index'), match: 'reports.*' },
            ],
        },
        {
            key: 'admin',
            label: t('nav.group_admin'),
            items: [
                can.value.manage_users && {
                    name: t('nav.users'),
                    icon: Users,
                    href: route('users.index'),
                    match: 'users.*',
                },
                can.value.is_partner && { name: t('nav.partner_telegram'), icon: Send, href: route('partner.telegram.edit'), match: 'partner.telegram.*' },
                can.value.manage_users && !can.value.is_partner && { name: t('nav.audit_logs'), icon: ScrollText, href: route('audit-logs.index'), match: 'audit-logs.*' },
                can.value.manage_users && !can.value.is_partner && { name: t('nav.settings'), icon: Settings, href: route('settings.edit'), match: 'settings.*' },
            ].filter(Boolean),
        },
        {
            key: 'help',
            label: t('nav.group_help'),
            items: [
                { name: t('nav.guide'), icon: BookOpen, href: route('panduan'), match: 'panduan' },
            ],
        },
    ];

    return groups.filter((group) => group.items.length > 0);
});

const isActive = (link) => {
    const patterns = Array.isArray(link.match) ? link.match : [link.match];
    if (!patterns.some((pattern) => route().current(pattern))) return false;
    if (link.except && route().current(link.except)) return false;
    return true;
};

const onKey = (e) => {
    const isModK = (e.key === 'k' || e.key === 'K') && (e.metaKey || e.ctrlKey);
    if (isModK) {
        e.preventDefault();
        searchOpen.value = true;
    }
    if (e.key === 'Escape') {
        if (searchOpen.value) searchOpen.value = false;
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
    <!-- Desktop = kerangka setinggi layar: sidebar, header, dan footer diam;
         hanya area konten (.kv-app-scroll, `scroll-region` untuk preserveScroll Inertia) yang
         menggulir. HP tetap menggulir di level dokumen dengan bar atas sticky.
         Snapshot full-page membuka kerangka ini lewat CSS (lihat scripts/snapshot.mjs). -->
    <div class="kv-grid-stage kv-app-shell flex min-h-screen flex-col lg:flex-row text-slate-100 font-sans antialiased selection:bg-cyan-500 selection:text-onaccent">
        <!-- 1. Background Grid Pattern Layer -->
        <div class="kv-grid-pattern" aria-hidden="true"></div>

        <!-- 2. Efek Light Beam di Tengah Atas (Natural Spotlight) -->
        <div class="kv-top-light" aria-hidden="true"></div>
        <div class="kv-ambient-glow" aria-hidden="true"></div>
        <!-- Mobile top bar (sticky, tetap terlihat saat scroll) -->
        <div class="kv-shell-bar sticky top-0 z-50 flex h-14 flex-shrink-0 items-center gap-3 border-b border-white/10 bg-canvas-3/40 px-4 backdrop-blur-xl lg:hidden">
            <button
                type="button"
                class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-md text-slate-400 transition-colors hover:bg-white/10 hover:text-white"
                :aria-label="$t('common.open_nav')"
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
                    :aria-label="$t('common.open_search')"
                    @click="searchOpen = true"
                >
                    <Search class="h-4 w-4" />
                </button>
                <LanguageSwitcher />
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

        <!-- Sidebar — mobile: drawer fixed; desktop: setinggi layar, diam saat konten digulir.
             Menu yang panjang menggulir di dalam <nav>, panel sistem tetap di dasar. -->
        <aside
            class="kv-shell-sidebar fixed inset-y-0 left-0 z-50 flex max-w-[calc(100vw-1rem)] flex-col border-r border-white/10 bg-canvas-3/35 backdrop-blur-xl transition-all duration-200 ease-in-out lg:relative lg:flex-shrink-0 lg:translate-x-0"
            :class="[
                sidebarOpen ? 'translate-x-0' : '-translate-x-full',
                sidebarCollapsed ? 'w-64 lg:w-20' : 'w-64',
            ]"
        >
            <SidebarConstellation v-if="showSidebarContent" />

            <!-- Blok atas: logo + navigasi. Mengisi sisa tinggi sidebar; <nav> menggulir sendiri. -->
            <div class="relative z-10 min-h-0 flex-1">
                <div class="flex h-full flex-col">
                    <!-- Logo -->
                    <div class="relative flex h-[72px] flex-shrink-0 items-center justify-between border-b border-white/10 bg-canvas-3/20 px-5 backdrop-blur-sm">
                        <Link
                            :href="route('dashboard')"
                            prefetch
                            :cache-for="['30s', '1m']"
                            class="flex items-center gap-3 overflow-hidden"
                            @click="sidebarOpen = false"
                        >
                            <div class="relative flex-shrink-0">
                                <ApplicationLogo class="h-8 w-auto fill-current text-cyan-400" />
                            </div>
                            <div v-if="showSidebarContent" class="min-w-0">
                                <div class="truncate text-base font-bold leading-tight text-white">{{ appName }}</div>
                                <div class="truncate text-[11px] text-slate-500">{{ $t('shell.app_subtitle') }}</div>
                            </div>
                        </Link>
                        <!-- Desktop collapse toggle — always centered on right border -->
                        <button
                            type="button"
                            class="absolute right-0 top-1/2 z-20 hidden h-7 w-7 -translate-y-1/2 translate-x-1/2 items-center justify-center rounded-full border border-white/10 bg-slate-900 text-slate-400 shadow-md shadow-black/40 transition-colors hover:border-cyan-500/40 hover:text-white lg:flex"
                            :aria-label="sidebarCollapsed ? $t('common.expand_sidebar') : $t('common.collapse_sidebar')"
                            @click="sidebarCollapsed = !sidebarCollapsed"
                        >
                            <ChevronLeft class="h-4 w-4 transition-transform" :class="{ 'rotate-180': sidebarCollapsed }" />
                        </button>
                    </div>

                    <!-- Navigation -->
                    <nav class="min-h-0 flex-1 overflow-y-auto px-3 py-4">
                        <template v-for="(group, gi) in navGroups" :key="group.key">
                            <!-- Label kelompok; saat sidebar diciutkan berubah jadi garis pemisah -->
                            <div
                                v-if="showSidebarContent"
                                class="px-3 pb-1.5 text-[9.5px] font-bold uppercase tracking-[0.16em] text-slate-500"
                                :class="gi === 0 ? 'pt-0.5' : 'pt-4'"
                            >
                                {{ group.label }}
                            </div>
                            <div v-else-if="gi > 0" class="mx-auto my-2.5 h-px w-8 bg-slate-700/70" role="separator" :aria-label="group.label"></div>

                            <div class="space-y-1.5">
                                <Link
                                    v-for="link in group.items"
                                    :key="link.name"
                                    :href="link.href"
                                    prefetch
                                    :cache-for="['30s', '1m']"
                                    class="group relative flex items-center rounded-xl text-xs font-semibold transition-all"
                                    :class="[
                                        isActive(link)
                                            ? 'bg-gradient-to-r from-cyan-500 to-sky-500 text-onaccent shadow-lg shadow-cyan-500/30'
                                            : 'text-slate-400 hover:bg-white/5 hover:text-slate-100',
                                        showSidebarContent ? 'gap-3 px-3.5 py-3' : 'mx-auto h-11 w-11 justify-center',
                                    ]"
                                    :title="!showSidebarContent ? link.name : null"
                                    @click="sidebarOpen = false"
                                >
                                    <component :is="link.icon" class="h-4 w-4 flex-shrink-0" />
                                    <span v-if="showSidebarContent" class="truncate">{{ link.name }}</span>
                                </Link>
                            </div>
                        </template>
                    </nav>
                </div>
            </div>
            <!-- /Blok atas -->

            <!-- Blok bawah: akun (mobile) + panel sistem (desktop-only), selalu di dasar sidebar. -->
            <div v-if="showSidebarContent" class="relative z-10 mt-auto flex-shrink-0">
                <!-- User account (mobile only — desktop uses header UserMenu) -->
                <div class="border-t border-white/10 px-3 py-3 lg:hidden">
                    <div class="flex items-center gap-3 rounded-xl bg-white/5 px-3 py-2.5">
                        <span class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-gradient-to-br from-cyan-500 to-sky-600 text-sm font-bold text-onaccent shadow-inner shadow-white/10">
                            {{ userInitial }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-white">{{ user.name }}</p>
                            <p class="truncate text-[11px] text-slate-500">{{ user.email }}</p>
                        </div>
                    </div>
                    <ThemeSegmented class="mt-2" />
                    <div class="mt-2 grid grid-cols-2 gap-2">
                        <Link
                            :href="route('profile.edit')"
                            class="flex items-center justify-center gap-2 rounded-lg border border-white/10 bg-slate-900/60 px-3 py-2 text-sm font-medium text-slate-300 transition-colors hover:bg-white/5 hover:text-white"
                            @click="sidebarOpen = false"
                        >
                            <User class="h-4 w-4" />
                            {{ $t('common.profile') }}
                        </Link>
                        <Link
                            :href="route('logout')"
                            method="post"
                            as="button"
                            class="flex items-center justify-center gap-2 rounded-lg border border-red-500/20 bg-red-500/10 px-3 py-2 text-sm font-medium text-red-300 transition-colors hover:bg-red-500/20 hover:text-red-200"
                        >
                            <LogOut class="h-4 w-4" />
                            {{ $t('common.logout') }}
                        </Link>
                    </div>
                </div>

                <!-- System info panel (desktop saja — di HP tak di-mount: drawer lebih lega
                     dan timer jam/polling health-nya tidak jalan sia-sia) -->
                <SystemInfoPanel v-if="isDesktop" />
            </div>
            <!-- /Blok bawah -->
        </aside>

        <!-- Main column (bersebelahan dengan sidebar di desktop) -->
        <div class="flex min-w-0 flex-1 flex-col lg:min-h-0">
            <!-- Top header (desktop, diam di atas area konten) — search + notif + user -->
            <header
                class="kv-shell-bar sticky top-0 z-30 hidden flex-shrink-0 border-b border-white/10 bg-canvas-3/35 backdrop-blur-xl lg:block"
            >
                <div class="flex h-[72px] w-full items-center gap-4 px-6 lg:px-8">
                    <!-- Search trigger -->
                    <button
                        type="button"
                        class="group flex h-11 max-w-2xl flex-1 items-center gap-3 rounded-xl border border-white/10 bg-slate-900/60 px-4 text-left text-sm text-slate-500 transition-colors hover:border-cyan-500/30 hover:bg-slate-900/80 hover:text-slate-300"
                        @click="searchOpen = true"
                    >
                        <Search class="h-4 w-4 flex-shrink-0 text-slate-500 group-hover:text-cyan-400" />
                        <span class="flex-1 truncate">{{ $t('common.search_placeholder') }}</span>
                        <kbd class="hidden items-center gap-1 rounded-md border border-white/10 bg-slate-800/80 px-2 py-0.5 text-[11px] font-medium text-slate-400 sm:inline-flex">
                            <span class="text-xs">&#8984;</span>K
                        </kbd>
                    </button>

                    <div class="ml-auto flex items-center gap-3">
                                <LanguageSwitcher />
                        <NotificationBell />
                        <UserMenu />
                    </div>
                </div>
            </header>

            <!-- Header per-halaman + konten — satu-satunya bagian yang menggulir di desktop -->
            <div class="kv-app-scroll flex flex-1 flex-col" scroll-region>
            <!-- Page header slot (optional, used by inner pages) — ikut scroll -->
            <header
                v-if="$slots.header"
                class="flex-shrink-0 border-b border-white/10 bg-canvas-3/30 backdrop-blur-xl"
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
                <span>{{ $t('shell.demo_banner') }}</span>
            </div>

            <!-- Page content -->
            <main class="relative z-10 flex-1">
                <Transition name="page" mode="out-in">
                    <div :key="page.component" class="relative min-w-0">
                        <slot />
                    </div>
                </Transition>
            </main>
            </div>
            <!-- /Konten -->

            <!-- Footer — desktop: diam di dasar layar (di luar area gulir); HP: di akhir halaman -->
            <footer class="kv-shell-bar z-10 flex-shrink-0 border-t border-white/10 bg-canvas-3/40 backdrop-blur-xl">
                <div class="flex flex-col items-center justify-between gap-1 px-4 py-3 text-xs text-slate-500 sm:flex-row sm:px-6 lg:px-8">
                    <p>&copy; {{ copyrightYear }} {{ appName }} NMS &middot; {{ owner }}</p>
                    <p class="hidden sm:block">{{ $t('shell.footer_tagline') }}</p>
                </div>
            </footer>
        </div>

        <!-- Toast flash terpusat (sukses/error) -->
        <FlashMessages />

        <!-- Global search palette -->
        <GlobalSearch v-model:open="searchOpen" />

    </div>
</template>
