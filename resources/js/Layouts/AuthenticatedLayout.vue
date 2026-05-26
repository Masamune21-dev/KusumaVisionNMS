<script setup>
import { ref } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Link, usePage } from '@inertiajs/vue3';
import { Activity, BellRing, Cable, LayoutDashboard, LogOut, Menu, User, Users, WifiOff, X } from '@lucide/vue';

const sidebarOpen = ref(false);
const page = usePage();
</script>

<template>
    <div class="min-h-screen bg-slate-900">
        <!-- Mobile top bar -->
        <div class="sticky top-0 z-50 flex h-14 items-center gap-4 border-b border-white/10 bg-slate-900 px-4 lg:hidden">
            <button
                type="button"
                class="flex h-8 w-8 items-center justify-center rounded-md text-slate-400 transition-colors hover:bg-white/10 hover:text-white"
                aria-label="Buka menu navigasi"
                @click="sidebarOpen = true"
            >
                <Menu class="h-5 w-5" />
            </button>
            <Link :href="route('dashboard')" class="flex items-center gap-2">
                <ApplicationLogo class="h-6 w-auto fill-current text-indigo-400" />
                <span class="text-sm font-bold text-white">KusumaVision</span>
            </Link>
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
                class="fixed inset-0 z-40 bg-black/60 backdrop-blur-sm lg:hidden"
                @click="sidebarOpen = false"
            />
        </Transition>

        <!-- Sidebar -->
        <aside
            class="fixed inset-y-0 left-0 z-50 flex w-64 flex-col border-r border-white/10 bg-slate-900 transition-transform duration-200 ease-in-out lg:translate-x-0"
            :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
        >
            <!-- Logo -->
            <div class="flex items-center justify-between border-b border-white/10 px-5 py-[18px]">
                <Link :href="route('dashboard')" class="flex items-center gap-3" @click="sidebarOpen = false">
                    <ApplicationLogo class="h-7 w-auto fill-current text-indigo-400" />
                    <div>
                        <div class="text-sm font-bold leading-tight text-white">KusumaVision</div>
                        <div class="text-[10px] text-slate-500">NMS v2 · GPON Management</div>
                    </div>
                </Link>
                <button
                    type="button"
                    class="flex h-7 w-7 items-center justify-center rounded-md text-slate-400 transition-colors hover:bg-white/10 hover:text-white lg:hidden"
                    aria-label="Tutup menu"
                    @click="sidebarOpen = false"
                >
                    <X class="h-4 w-4" />
                </button>
            </div>

            <!-- Navigation links -->
            <nav class="flex-1 overflow-y-auto px-3 py-4">
                <p class="mb-2 px-3 text-[10px] font-semibold uppercase tracking-widest text-slate-600">Navigasi</p>

                <div class="space-y-0.5">
                    <Link
                        :href="route('dashboard')"
                        class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors"
                        :class="route().current('dashboard')
                            ? 'bg-white/10 text-white'
                            : 'text-slate-400 hover:bg-white/5 hover:text-slate-100'"
                        @click="sidebarOpen = false"
                    >
                        <LayoutDashboard class="h-4 w-4 flex-shrink-0" />
                        Dashboard
                    </Link>

                    <Link
                        :href="route('smartolt.index')"
                        class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors"
                        :class="route().current('smartolt.*') && !route().current('smartolt.unconfigured-all')
                            ? 'bg-white/10 text-white'
                            : 'text-slate-400 hover:bg-white/5 hover:text-slate-100'"
                        @click="sidebarOpen = false"
                    >
                        <Cable class="h-4 w-4 flex-shrink-0" />
                        SmartOLT
                    </Link>

                    <Link
                        :href="route('smartolt.unconfigured-all')"
                        class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors"
                        :class="route().current('smartolt.unconfigured-all')
                            ? 'bg-white/10 text-white'
                            : 'text-slate-400 hover:bg-white/5 hover:text-slate-100'"
                        @click="sidebarOpen = false"
                    >
                        <WifiOff class="h-4 w-4 flex-shrink-0" />
                        Unconfigured
                    </Link>

                    <Link
                        :href="route('alarms.index')"
                        class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors"
                        :class="route().current('alarms.*')
                            ? 'bg-white/10 text-white'
                            : 'text-slate-400 hover:bg-white/5 hover:text-slate-100'"
                        @click="sidebarOpen = false"
                    >
                        <BellRing class="h-4 w-4 flex-shrink-0" />
                        Alarms
                    </Link>

                    <Link
                        :href="route('users.index')"
                        class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors"
                        :class="route().current('users.*')
                            ? 'bg-white/10 text-white'
                            : 'text-slate-400 hover:bg-white/5 hover:text-slate-100'"
                        @click="sidebarOpen = false"
                    >
                        <Users class="h-4 w-4 flex-shrink-0" />
                        Users
                    </Link>
                </div>
            </nav>

            <!-- User section -->
            <div class="border-t border-white/10 px-4 py-4">
                <div class="mb-3 flex items-center gap-3">
                    <div class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full bg-indigo-600 text-xs font-bold text-white">
                        {{ $page.props.auth.user.name.charAt(0).toUpperCase() }}
                    </div>
                    <div class="min-w-0">
                        <div class="truncate text-sm font-medium text-white">{{ $page.props.auth.user.name }}</div>
                        <div class="truncate text-xs text-slate-500">{{ $page.props.auth.user.email }}</div>
                    </div>
                </div>
                <div class="flex gap-1.5">
                    <Link
                        :href="route('profile.edit')"
                        class="flex flex-1 items-center justify-center gap-1.5 rounded-md px-2 py-1.5 text-xs text-slate-400 transition-colors hover:bg-white/5 hover:text-white"
                    >
                        <User class="h-3.5 w-3.5" />
                        Profile
                    </Link>
                    <Link
                        :href="route('logout')"
                        method="post"
                        as="button"
                        class="flex flex-1 items-center justify-center gap-1.5 rounded-md px-2 py-1.5 text-xs text-slate-400 transition-colors hover:bg-red-500/10 hover:text-red-400"
                    >
                        <LogOut class="h-3.5 w-3.5" />
                        Keluar
                    </Link>
                </div>
            </div>
        </aside>

        <!-- Main content (offset by sidebar on desktop) -->
        <div class="flex min-h-screen flex-col bg-gradient-to-br from-slate-900 via-slate-800 to-indigo-950 lg:pl-64">
            <!-- Page header slot -->
            <header
                v-if="$slots.header"
                class="sticky top-0 z-30 border-b border-white/10 bg-slate-900/95 backdrop-blur-sm [&_h2]:!text-white [&_p]:!text-slate-400"
            >
                <div class="mx-auto flex min-h-[64px] max-w-7xl items-center px-4 py-3 sm:px-6 lg:px-8">
                    <div class="w-full">
                        <slot name="header" />
                    </div>
                </div>
            </header>

            <!-- Page content -->
            <main class="flex-1">
                <Transition name="page" mode="out-in">
                    <div :key="page.component">
                        <slot />
                    </div>
                </Transition>
            </main>

            <!-- Footer -->
            <footer class="sticky bottom-0 z-10 border-t border-white/10 bg-slate-900/95 backdrop-blur-sm">
                <div class="mx-auto max-w-7xl px-4 py-2 sm:px-6 lg:px-8">
                    <p class="text-center text-xs text-slate-500">
                        &copy; 2026 KusumaVisionNMS &bull; Dibuat Oleh Masamune
                    </p>
                </div>
            </footer>
        </div>
    </div>
</template>
