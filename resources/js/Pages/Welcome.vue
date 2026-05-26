<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Head, Link } from '@inertiajs/vue3';
import {
    Activity,
    BellRing,
    Cable,
    CheckCircle2,
    Clock3,
    Cpu,
    Database,
    LayoutDashboard,
    LogIn,
    Network,
    RadioTower,
    ShieldCheck,
    UserPlus,
    Wifi,
} from '@lucide/vue';

defineProps({
    canLogin: {
        type: Boolean,
    },
    canRegister: {
        type: Boolean,
    },
    laravelVersion: {
        type: String,
        required: true,
    },
    phpVersion: {
        type: String,
        required: true,
    },
});

const signals = [
    { label: 'OLT ZTE C300/C320', icon: Cable },
    { label: 'SNMP Polling', icon: RadioTower },
    { label: 'Alarm Engine', icon: BellRing },
    { label: 'ONU Provisioning', icon: Wifi },
];

const metrics = [
    { label: 'Inventory', value: 'OLT & Card', icon: Database },
    { label: 'Discovery', value: 'ONU Baru', icon: Network },
    { label: 'Polling', value: 'Terjadwal', icon: Clock3 },
    { label: 'Status', value: 'Realtime', icon: Activity },
];

const workflows = [
    {
        title: 'Pantau perangkat',
        description: 'Ringkasan OLT, port GPON, card, optical power, dan trafik tersaji untuk kerja harian NOC.',
        icon: Cpu,
    },
    {
        title: 'Tindak alarm',
        description: 'Severity, target, status aktif, dan histori alarm dibuat mudah dipindai tanpa warna yang saling berebut.',
        icon: BellRing,
    },
    {
        title: 'Provisioning rapi',
        description: 'Unconfigured ONU, profile layanan, dan script CLI berada dalam alur yang jelas dari discovery sampai register.',
        icon: ShieldCheck,
    },
];
</script>

<template>
    <Head title="KusumaVision NMS" />

    <div class="min-h-screen bg-slate-50 text-slate-900">
        <header class="absolute inset-x-0 top-0 z-20">
            <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-5 sm:px-6 lg:px-8">
                <Link href="/" class="flex items-center gap-3 text-white">
                    <ApplicationLogo class="h-9 w-auto fill-current text-sky-300" />
                    <div>
                        <div class="text-sm font-bold leading-tight">KusumaVision</div>
                        <div class="text-[11px] font-medium text-sky-100/80">Network Management</div>
                    </div>
                </Link>

                <nav v-if="canLogin" class="flex items-center gap-2">
                    <Link
                        v-if="$page.props.auth.user"
                        :href="route('dashboard')"
                        class="inline-flex min-h-11 items-center gap-2 rounded-md bg-white px-4 py-2 text-sm font-semibold text-slate-900 shadow-sm transition hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-sky-300"
                    >
                        <LayoutDashboard class="h-4 w-4" />
                        Dashboard
                    </Link>

                    <template v-else>
                        <Link
                            :href="route('login')"
                            class="inline-flex min-h-11 items-center gap-2 rounded-md bg-white px-4 py-2 text-sm font-semibold text-slate-900 shadow-sm transition hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-sky-300"
                        >
                            <LogIn class="h-4 w-4" />
                            Masuk
                        </Link>

                        <Link
                            v-if="canRegister"
                            :href="route('register')"
                            class="hidden min-h-11 items-center gap-2 rounded-md border border-white/30 px-4 py-2 text-sm font-semibold text-white transition hover:bg-white/10 focus:outline-none focus:ring-2 focus:ring-sky-300 sm:inline-flex"
                        >
                            <UserPlus class="h-4 w-4" />
                            Daftar
                        </Link>
                    </template>
                </nav>
            </div>
        </header>

        <main>
            <section class="relative min-h-[70dvh] md:min-h-[78dvh] overflow-hidden bg-slate-950">
                <picture>
                    <source srcset="/img/c320.jpg" media="(min-width: 768px)" />
                    <img
                        src="/img/c300.jpg"
                        alt="Rak OLT ZTE untuk GPON"
                        class="absolute inset-0 h-full w-full object-cover object-[center_42%] opacity-45"
                    />
                </picture>
                <div class="absolute inset-0 bg-slate-950/65"></div>

                <div class="relative mx-auto flex min-h-[70dvh] md:min-h-[78dvh] max-w-7xl flex-col justify-end px-4 pb-10 pt-28 sm:px-6 lg:px-8">
                    <div class="max-w-3xl">
                        <div class="inline-flex items-center gap-2 rounded-full border border-sky-200/30 bg-white/10 px-3 py-1.5 text-xs font-semibold text-sky-100">
                            <CheckCircle2 class="h-4 w-4 text-emerald-300" />
                            GPON Operations Console
                        </div>

                        <h1 class="mt-5 text-4xl font-bold text-white sm:text-5xl lg:text-6xl">
                            KusumaVision NMS
                        </h1>
                        <p class="mt-5 max-w-2xl text-base leading-7 text-slate-200 sm:text-lg">
                            Dashboard NMS untuk memantau OLT, ONU, alarm, trafik, dan provisioning ZTE C300/C320 dalam satu tampilan yang padat dan konsisten.
                        </p>

                        <div class="mt-6 flex flex-wrap gap-2">
                            <span
                                v-for="signal in signals"
                                :key="signal.label"
                                class="inline-flex items-center gap-2 rounded-full border border-white/20 bg-white/10 px-3 py-1.5 text-xs font-medium text-slate-100"
                            >
                                <component :is="signal.icon" class="h-3.5 w-3.5 text-sky-200" />
                                {{ signal.label }}
                            </span>
                        </div>

                        <div v-if="canLogin" class="mt-8 flex flex-wrap gap-3">
                            <Link
                                v-if="$page.props.auth.user"
                                :href="route('dashboard')"
                                class="inline-flex min-h-11 items-center gap-2 rounded-md bg-sky-600 px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-sky-700 focus:outline-none focus:ring-2 focus:ring-sky-300"
                            >
                                <LayoutDashboard class="h-4 w-4" />
                                Buka Dashboard
                            </Link>

                            <template v-else>
                                <Link
                                    :href="route('login')"
                                    class="inline-flex min-h-11 items-center gap-2 rounded-md bg-sky-600 px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-sky-700 focus:outline-none focus:ring-2 focus:ring-sky-300"
                                >
                                    <LogIn class="h-4 w-4" />
                                    Masuk ke NMS
                                </Link>
                                <Link
                                    v-if="canRegister"
                                    :href="route('register')"
                                    class="inline-flex min-h-11 items-center gap-2 rounded-md border border-white/30 px-5 py-3 text-sm font-semibold text-white transition hover:bg-white/10 focus:outline-none focus:ring-2 focus:ring-sky-300"
                                >
                                    <UserPlus class="h-4 w-4" />
                                    Buat User
                                </Link>
                            </template>
                        </div>
                    </div>
                </div>
            </section>

            <section class="border-b border-slate-200 bg-white">
                <div class="mx-auto grid max-w-7xl gap-px bg-slate-200 px-4 sm:grid-cols-2 sm:px-6 lg:grid-cols-4 lg:px-8">
                    <div
                        v-for="metric in metrics"
                        :key="metric.label"
                        class="flex items-center gap-3 bg-white px-4 py-5"
                    >
                        <div class="kv-icon-tile">
                            <component :is="metric.icon" class="h-5 w-5" />
                        </div>
                        <div>
                            <p class="text-xs font-medium text-slate-500">{{ metric.label }}</p>
                            <p class="mt-1 text-sm font-semibold text-slate-900">{{ metric.value }}</p>
                        </div>
                    </div>
                </div>
            </section>

            <section class="mx-auto grid max-w-7xl gap-6 px-4 py-10 sm:px-6 lg:grid-cols-[1.05fr_0.95fr] lg:px-8">
                <div class="space-y-4">
                    <div>
                        <p class="text-sm font-semibold text-sky-700">NOC-ready workflow</p>
                        <h2 class="mt-2 text-2xl font-bold text-slate-900">Dibuat untuk operasi jaringan yang perlu cepat dipindai.</h2>
                    </div>

                    <div class="grid gap-4 md:grid-cols-3 lg:grid-cols-1">
                        <div
                            v-for="workflow in workflows"
                            :key="workflow.title"
                            class="rounded-lg border border-sky-200 bg-white p-5 shadow-sm shadow-sky-100/60"
                        >
                            <div class="flex items-start gap-4">
                                <div class="kv-icon-tile">
                                    <component :is="workflow.icon" class="h-5 w-5" />
                                </div>
                                <div>
                                    <h3 class="text-base font-semibold text-slate-900">{{ workflow.title }}</h3>
                                    <p class="mt-1 text-sm leading-6 text-slate-500">{{ workflow.description }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="kv-panel self-start">
                    <div class="kv-panel-header">
                        <div class="kv-icon-tile">
                            <LayoutDashboard class="h-5 w-5" />
                        </div>
                        <div>
                            <h2 class="text-base font-semibold text-slate-900">Operation Snapshot</h2>
                            <p class="mt-0.5 text-xs text-slate-500">Contoh ritme informasi di dashboard NMS.</p>
                        </div>
                    </div>

                    <div class="space-y-4 p-4 sm:p-5">
                        <div class="grid gap-3 sm:grid-cols-3">
                            <div class="rounded-lg border border-sky-100 bg-slate-50 p-4">
                                <p class="text-xs font-medium text-slate-500">OLT</p>
                                <p class="mt-2 text-xl font-bold text-slate-900">Online</p>
                            </div>
                            <div class="rounded-lg border border-sky-100 bg-slate-50 p-4">
                                <p class="text-xs font-medium text-slate-500">ONU</p>
                                <p class="mt-2 text-xl font-bold text-emerald-700">Active</p>
                            </div>
                            <div class="rounded-lg border border-sky-100 bg-slate-50 p-4">
                                <p class="text-xs font-medium text-slate-500">Alarm</p>
                                <p class="mt-2 text-xl font-bold text-red-700">Critical</p>
                            </div>
                        </div>

                        <div class="overflow-x-auto rounded-lg border border-sky-100">
                            <div class="grid min-w-[520px] grid-cols-4 border-b border-slate-100 bg-slate-50 px-4 py-3 text-xs font-semibold text-slate-500">
                                <span>OLT</span>
                                <span>Port</span>
                                <span>ONU</span>
                                <span>Status</span>
                            </div>
                            <div class="min-w-[520px] divide-y divide-slate-100 bg-white text-sm">
                                <div class="grid grid-cols-4 px-4 py-3">
                                    <span class="font-medium text-slate-900">C320-01</span>
                                    <span class="text-slate-600">1/1/1</span>
                                    <span class="text-slate-600">64/64</span>
                                    <span class="font-medium text-emerald-700">Normal</span>
                                </div>
                                <div class="grid grid-cols-4 px-4 py-3">
                                    <span class="font-medium text-slate-900">C300-02</span>
                                    <span class="text-slate-600">1/2/8</span>
                                    <span class="text-slate-600">42/64</span>
                                    <span class="font-medium text-amber-700">Warning</span>
                                </div>
                                <div class="grid grid-cols-4 px-4 py-3">
                                    <span class="font-medium text-slate-900">C320-03</span>
                                    <span class="text-slate-600">1/3/4</span>
                                    <span class="text-slate-600">58/64</span>
                                    <span class="font-medium text-sky-700">Polling</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <footer class="border-t border-slate-200 bg-white">
                <div class="mx-auto max-w-7xl px-4 py-2 sm:px-6 lg:px-8">
                    <p class="text-center text-xs text-slate-400">
                        &copy; 2026 KusumaVisionNMS &bull; Dibuat Oleh Masamune
                    </p>
                </div>
            </footer>
        </main>
    </div>
</template>
