<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import AuroraBackground from '@/Components/Shell/AuroraBackground.vue';
import { Head, Link } from '@inertiajs/vue3';
import {
    Activity,
    ArrowRight,
    BellRing,
    Boxes,
    Cable,
    CheckCircle2,
    ChevronRight,
    Cog,
    Database,
    FileBarChart,
    Gauge,
    LayoutDashboard,
    LogIn,
    Mail,
    MapPin,
    Menu,
    MonitorPlay,
    Phone,
    Play,
    Radar,
    RadioTower,
    ScrollText,
    Search,
    Send,
    Server,
    ShieldCheck,
    Sparkles,
    Terminal,
    Wifi,
    Workflow,
} from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import AOS from 'aos';
import 'aos/dist/aos.css';

defineProps({
    canLogin: { type: Boolean },
    canRegister: { type: Boolean },
});

const mobileOpen = ref(false);
const activeShot = ref('dashboard');

onMounted(() => {
    AOS.init({
        duration: 650,
        easing: 'ease-out-cubic',
        once: true,
        offset: 80,
        // Hormati preferensi pengguna yang mengurangi animasi (aksesibilitas).
        disable: () =>
            window.matchMedia('(prefers-reduced-motion: reduce)').matches,
    });
});

const navLinks = [
    { label: 'Beranda', href: '#beranda' },
    { label: 'Fitur', href: '#fitur' },
    { label: 'Tampilan', href: '#tampilan' },
    { label: 'Tech Stack', href: '#tech' },
    { label: 'Modul', href: '#modul' },
    { label: 'Kontak', href: '#kontak' },
];

const heroPills = [
    { icon: Cable, label: 'ZTE C300/C320/C600' },
    { icon: RadioTower, label: 'SNMP Polling' },
    { icon: BellRing, label: 'Alarm Engine' },
    { icon: Wifi, label: 'ONU Provisioning' },
    { icon: Terminal, label: 'Web Telnet' },
];

const features = [
    {
        icon: Database,
        accent: 'kv-circle-sky',
        title: 'OLT Inventory',
        body: 'Kelola seluruh perangkat OLT, kartu, dan port GPON dalam satu inventaris terpusat dengan capability detection.',
    },
    {
        icon: Wifi,
        accent: 'kv-circle-emerald',
        title: 'ONU Monitoring',
        body: 'Pantau seluruh ONU lintas OLT & port dalam satu halaman — status online/LOS/dying-gasp, RX optical power, dengan filter cepat per OLT, port, dan status.',
    },
    {
        icon: Workflow,
        accent: 'kv-circle-purple',
        title: 'Provisioning ONU',
        body: 'Generate CLI script provisioning ZTE (register, T-CONT, VLAN, PPPoE/DHCP/Static, TR-069) lalu eksekusi via Telnet.',
    },
    {
        icon: Gauge,
        accent: 'kv-circle-cyan',
        title: 'SNMP Polling',
        body: 'Polling terjadwal untuk system info, port status, dan ONU table — semua disimpan di snapshot per OLT.',
    },
    {
        icon: BellRing,
        accent: 'kv-circle-red',
        title: 'Alarm Engine',
        body: 'Korelasi alarm berdasarkan signature dengan severity Critical/Major/Minor/Warning dan tracking auto-clear.',
    },
    {
        icon: Cog,
        accent: 'kv-circle-amber',
        title: 'Remote ONU Management',
        body: 'Reboot, enable/disable, dan kontrol jarak jauh ONU langsung dari dashboard tanpa SSH ke OLT.',
    },
    {
        icon: Terminal,
        accent: 'kv-circle-purple',
        title: 'Telnet via Browser',
        body: 'Akses CLI OLT langsung dari browser lewat terminal xterm.js — jendela bisa digeser, minimize/maximize, auto-login, tanpa aplikasi telnet terpisah.',
    },
    {
        icon: Search,
        accent: 'kv-circle-sky',
        title: 'Global Search',
        body: 'Cari OLT atau ONU instan berdasarkan serial number, nama pelanggan, atau interface — langsung lompat ke port terkait.',
    },
    {
        icon: FileBarChart,
        accent: 'kv-circle-sky',
        title: 'Reports & Analytics',
        body: 'Laporan statistik jaringan — utilisasi port, RX optical power (warning/critical), dan status OLT — siap diunduh per OLT maupun rentang waktu.',
    },
    {
        icon: Send,
        accent: 'kv-circle-cyan',
        title: 'Notifikasi Telegram',
        body: 'Alarm penting dikirim langsung ke grup/chat Telegram, plus bot read-only untuk cek status OLT & ONU dari mana saja.',
    },
    {
        icon: ScrollText,
        accent: 'kv-circle-emerald',
        title: 'Audit Logs',
        body: 'Jejak audit tak terhapus untuk setiap perubahan konfigurasi, login, dan akses telnet — lengkap dengan aktor, waktu, dan diff lama→baru.',
    },
    {
        icon: ShieldCheck,
        accent: 'kv-circle-amber',
        title: 'Role-based Access',
        body: 'Pemisahan hak akses admin dan operator NOC — fitur sensitif seperti audit log & pengaturan sistem hanya untuk admin.',
    },
];

const benefits = [
    { icon: MonitorPlay, label: 'Centralized Monitoring' },
    { icon: BellRing, label: 'Real-time Alerts' },
    { icon: Boxes, label: 'Scalable Architecture' },
    { icon: ShieldCheck, label: 'Secure Operations' },
    { icon: Activity, label: 'ISP-Focused Workflow' },
];

const techStack = [
    { name: 'Laravel 12', sub: 'PHP Framework', logo: '/img/tech/laravel.svg', glow: 'rgba(239, 68, 68, 0.25)' },
    { name: 'Vue 3', sub: 'Frontend SPA', logo: '/img/tech/vue.svg', glow: 'rgba(16, 185, 129, 0.25)' },
    { name: 'Inertia.js', sub: 'SPA Bridge', logo: '/img/tech/inertia.svg', glow: 'rgba(168, 85, 247, 0.25)' },
    { name: 'PostgreSQL', sub: 'Database', logo: '/img/tech/postgresql.svg', glow: 'rgba(110, 168, 249, 0.25)' },
    { name: 'Redis', sub: 'Cache & Queue', logo: '/img/tech/redis.svg', glow: 'rgba(239, 68, 68, 0.25)' },
    { name: 'Golang', sub: 'Polling Engine', logo: '/img/tech/go.svg', glow: 'rgba(34, 211, 238, 0.25)' },
];

const modules = [
    { icon: LayoutDashboard, title: 'Dashboard', sub: 'Tampilan ringkas seluruh jaringan FTTH' },
    { icon: Server, title: 'OLT Inventory', sub: 'Detail perangkat & kartu line card' },
    { icon: BellRing, title: 'Alarm Center', sub: 'Pusat notifikasi & histori alarm' },
    { icon: Workflow, title: 'Provisioning', sub: 'Wizard registrasi ONU otomatis' },
    { icon: Radar, title: 'ONU Monitoring', sub: 'Pantau ONU lintas OLT & port' },
    { icon: Terminal, title: 'Telnet Console', sub: 'CLI OLT langsung dari browser' },
    { icon: Database, title: 'Profiles', sub: 'Manajemen ONU type, T-CONT, VLAN' },
    { icon: FileBarChart, title: 'Reports', sub: 'Laporan statistik dan utilisasi' },
];

const screenshots = [
    {
        key: 'dashboard',
        icon: LayoutDashboard,
        label: 'Dashboard',
        desc: 'Ringkasan jaringan FTTH real-time',
        src: '/img/dashboard1.webp',
        ratio: '1920 / 1282',
        url: 'app.kusumavision.net/dashboard',
        alt: 'Dashboard KusumaVision NMS — ringkasan OLT, ONU, dan alarm',
    },
    {
        key: 'olt',
        icon: Server,
        label: 'OLT Inventory',
        desc: 'Perangkat, line card & port GPON',
        src: '/img/oltinventory.webp',
        ratio: '1920 / 911',
        url: 'app.kusumavision.net/smartolt',
        alt: 'Inventaris OLT — daftar perangkat ZTE dan kapabilitasnya',
    },
    {
        key: 'unconfigured',
        icon: Radar,
        label: 'ONU Belum Terdaftar',
        desc: 'Temukan & provisioning ONU baru',
        src: '/img/unconfigured.webp',
        ratio: '1920 / 911',
        url: 'app.kusumavision.net/smartolt/unconfigured',
        alt: 'Daftar ONU belum terkonfigurasi siap di-provisioning',
    },
    {
        key: 'detail',
        icon: Activity,
        label: 'Detail ONU',
        desc: 'Status, RX power & konfigurasi WAN',
        src: '/img/detail.webp',
        ratio: '1920 / 1112',
        url: 'app.kusumavision.net/smartolt/onu',
        alt: 'Halaman detail ONU — status optik, RX power, dan konfigurasi',
    },
    {
        key: 'login',
        icon: LogIn,
        label: 'Login',
        desc: 'Akses aman untuk operator NOC',
        src: '/img/login.webp',
        ratio: '1920 / 911',
        url: 'app.kusumavision.net/login',
        alt: 'Halaman login KusumaVision NMS',
    },
];

const currentShot = computed(
    () => screenshots.find((s) => s.key === activeShot.value) ?? screenshots[0],
);

const productLinks = [
    { label: 'Dashboard', href: '#beranda' },
    { label: 'Fitur', href: '#fitur' },
    { label: 'Tech Stack', href: '#tech' },
    { label: 'Modul', href: '#modul' },
];
const companyLinks = [
    { label: 'Tentang', href: '#' },
    { label: 'Blog', href: '#' },
    { label: 'Karier', href: '#' },
];
const supportLinks = [
    { label: 'Dokumentasi', href: '#' },
    { label: 'FAQ', href: '#' },
    { label: 'Status', href: '#' },
];
</script>

<template>
    <Head title="KusumaVision NMS — ZTE OLT Management & Provisioning Platform" />

    <div class="min-h-screen bg-slate-950 text-slate-100">
        <!-- ===== Top nav ===== -->
        <header class="sticky top-0 z-40 border-b border-white/10 bg-slate-950/80 backdrop-blur-xl">
            <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-3 sm:px-6 lg:px-8">
                <Link href="/" class="flex items-center gap-2.5">
                    <ApplicationLogo class="h-8 w-auto fill-current text-cyan-400 drop-shadow-[0_0_8px_rgba(34,211,238,0.45)]" />
                    <div class="leading-tight">
                        <div class="text-sm font-bold text-white">KusumaVision</div>
                        <div class="text-[10px] text-slate-500">NMS v2 &middot; GPON Management</div>
                    </div>
                </Link>

                <nav class="hidden items-center gap-1 md:flex">
                    <a
                        v-for="link in navLinks"
                        :key="link.href"
                        :href="link.href"
                        class="rounded-lg px-3 py-2 text-sm font-medium text-slate-400 transition-colors hover:text-white"
                    >
                        {{ link.label }}
                    </a>
                </nav>

                <div class="flex items-center gap-2">
                    <template v-if="canLogin">
                        <Link
                            v-if="$page.props.auth.user"
                            :href="route('dashboard')"
                            class="inline-flex items-center gap-2 rounded-lg bg-gradient-to-r from-cyan-500 to-sky-600 px-4 py-2 text-sm font-semibold text-white shadow-lg shadow-cyan-500/30 transition hover:shadow-cyan-500/50"
                        >
                            <LayoutDashboard class="h-4 w-4" />
                            Dashboard
                        </Link>
                        <Link
                            v-else
                            :href="route('login')"
                            class="inline-flex items-center gap-2 rounded-lg bg-gradient-to-r from-cyan-500 to-sky-600 px-4 py-2 text-sm font-semibold text-white shadow-lg shadow-cyan-500/30 transition hover:shadow-cyan-500/50"
                        >
                            <LogIn class="h-4 w-4" />
                            Login
                        </Link>
                    </template>

                    <button
                        type="button"
                        class="flex h-10 w-10 items-center justify-center rounded-lg text-slate-400 transition-colors hover:bg-white/5 hover:text-white md:hidden"
                        @click="mobileOpen = !mobileOpen"
                        aria-label="Toggle navigation"
                    >
                        <Menu class="h-5 w-5" />
                    </button>
                </div>
            </div>

            <!-- Mobile nav drawer -->
            <Transition
                enter-active-class="transition duration-150"
                enter-from-class="opacity-0 -translate-y-2"
                enter-to-class="opacity-100 translate-y-0"
                leave-active-class="transition duration-100"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <nav v-if="mobileOpen" class="border-t border-white/10 md:hidden">
                    <div class="mx-auto flex max-w-7xl flex-col gap-1 px-4 py-3 sm:px-6">
                        <a
                            v-for="link in navLinks"
                            :key="link.href"
                            :href="link.href"
                            class="rounded-lg px-3 py-2.5 text-sm font-medium text-slate-300 transition-colors hover:bg-white/5 hover:text-white"
                            @click="mobileOpen = false"
                        >
                            {{ link.label }}
                        </a>
                    </div>
                </nav>
            </Transition>
        </header>

        <main>
            <!-- ===== Hero ===== -->
            <section id="beranda" class="kv-grid-bg relative flex items-center overflow-hidden lg:min-h-[calc(100vh-57px)]">
                <AuroraBackground />
                <!-- Ambient glows -->
                <div class="pointer-events-none absolute -left-32 top-20 h-96 w-96 animate-pulse rounded-full bg-cyan-500/15 blur-[120px]" />
                <div class="pointer-events-none absolute -right-32 top-40 h-96 w-96 animate-pulse rounded-full bg-purple-500/10 blur-[120px]" style="animation-delay: 1.5s" />

                <div class="relative mx-auto grid w-full max-w-7xl items-center gap-10 px-4 py-16 sm:px-6 lg:grid-cols-2 lg:gap-12 lg:px-8 lg:py-24">
                    <div>
                        <div
                            class="inline-flex items-center gap-2 rounded-full border border-cyan-500/30 bg-cyan-500/10 px-3 py-1 text-xs font-semibold text-cyan-300"
                            data-aos="fade-down"
                        >
                            <Sparkles class="h-3.5 w-3.5" />
                            GPON Operations Console &middot; v2
                        </div>

                        <h1
                            class="mt-6 text-4xl font-bold leading-tight tracking-tight text-white sm:text-5xl lg:text-[3.5rem]"
                            data-aos="fade-up"
                            data-aos-delay="80"
                        >
                            ZTE <span class="bg-gradient-to-r from-cyan-400 to-sky-500 bg-clip-text text-transparent">OLT Management</span> &amp; Provisioning Platform
                        </h1>
                        <p
                            class="mt-5 max-w-xl text-base leading-7 text-slate-400 sm:text-lg"
                            data-aos="fade-up"
                            data-aos-delay="160"
                        >
                            Monitor, provisioning, dan manajemen OLT ZTE C300/C320/C600 secara terpusat. Dibangun untuk operasional ISP Indonesia yang menuntut kecepatan dan akurasi.
                        </p>

                        <!-- Hero CTAs -->
                        <div
                            class="mt-8 flex flex-wrap items-center gap-3"
                            data-aos="fade-up"
                            data-aos-delay="240"
                        >
                            <Link
                                v-if="$page.props.auth.user"
                                :href="route('dashboard')"
                                class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-cyan-500 to-sky-600 px-6 py-3.5 text-sm font-semibold text-white shadow-lg shadow-cyan-500/30 transition hover:shadow-cyan-500/50"
                            >
                                <LayoutDashboard class="h-4 w-4" />
                                Buka Dashboard
                                <ArrowRight class="h-4 w-4" />
                            </Link>
                            <template v-else>
                                <Link
                                    v-if="canLogin"
                                    :href="route('login')"
                                    class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-cyan-500 to-sky-600 px-6 py-3.5 text-sm font-semibold text-white shadow-lg shadow-cyan-500/30 transition hover:shadow-cyan-500/50"
                                >
                                    Get Started
                                    <ArrowRight class="h-4 w-4" />
                                </Link>
                            </template>
                            <a
                                href="#modul"
                                class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-slate-900/60 px-6 py-3.5 text-sm font-semibold text-slate-200 backdrop-blur transition hover:border-white/20 hover:bg-slate-800/80 hover:text-white"
                            >
                                <Play class="h-4 w-4" />
                                View Demo
                            </a>
                        </div>

                        <!-- Hero pills -->
                        <div
                            class="mt-8 flex flex-wrap gap-2"
                            data-aos="fade-up"
                            data-aos-delay="320"
                        >
                            <span
                                v-for="pill in heroPills"
                                :key="pill.label"
                                class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-slate-900/60 px-3 py-1.5 text-xs font-medium text-slate-300 backdrop-blur"
                            >
                                <component :is="pill.icon" class="h-3.5 w-3.5 text-cyan-400" />
                                {{ pill.label }}
                            </span>
                        </div>
                    </div>

                    <!-- Dashboard preview -->
                    <div class="relative" data-aos="fade-left" data-aos-delay="200">
                        <div class="absolute -inset-4 rounded-3xl bg-gradient-to-br from-cyan-500/20 via-sky-500/10 to-purple-500/20 blur-2xl" />
                        <div class="relative overflow-hidden rounded-2xl border border-white/10 bg-slate-900/40 shadow-2xl shadow-cyan-500/10 backdrop-blur-xl">
                            <!-- Browser chrome -->
                            <div class="flex items-center gap-2 border-b border-white/10 bg-slate-950/60 px-4 py-2.5">
                                <span class="h-3 w-3 rounded-full bg-red-500/70" />
                                <span class="h-3 w-3 rounded-full bg-amber-500/70" />
                                <span class="h-3 w-3 rounded-full bg-emerald-500/70" />
                                <span class="ml-3 flex-1 truncate rounded-md bg-slate-900/60 px-3 py-1 text-xs text-slate-500">http://localhost/dashboard</span>
                            </div>
                            <img
                                src="/img/dashboard1.webp"
                                alt="KusumaVision NMS Dashboard"
                                class="block w-full"
                                loading="eager"
                            />
                        </div>
                    </div>
                </div>
            </section>

            <!-- ===== Hardware showcase strip ===== -->
            <section class="border-y border-white/10 bg-slate-950">
                <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
                    <div class="relative overflow-hidden rounded-2xl border border-white/10 bg-slate-900/30 backdrop-blur-xl" data-aos="fade-up">
                        <div class="grid items-center gap-8 p-6 md:grid-cols-[1fr_auto] md:gap-12 md:p-10">
                            <div data-aos="fade-right" data-aos-delay="100">
                                <p class="text-xs font-semibold uppercase tracking-widest text-cyan-400">Hardware Compatible</p>
                                <h2 class="mt-2 text-2xl font-bold text-white sm:text-3xl">Mendukung lini OLT ZTE C-series</h2>
                                <p class="mt-3 max-w-xl text-sm text-slate-400">
                                    Driver SNMP & CLI matang untuk ZTE C300, C320, dan C600 — battle-tested di OLT produksi ISP Indonesia.
                                </p>
                                <div class="mt-5 flex flex-wrap gap-2">
                                    <span class="kv-pill-info">ZTE C300</span>
                                    <span class="kv-pill-info">ZTE C320</span>
                                    <span class="kv-pill-info">ZTE C600</span>
                                </div>
                            </div>
                            <img
                                src="/img/c320.webp"
                                alt="ZTE OLT hardware"
                                class="h-32 w-auto object-contain opacity-90 md:h-40"
                                loading="lazy"
                                data-aos="zoom-in"
                                data-aos-delay="200"
                            />
                        </div>
                    </div>
                </div>
            </section>

            <!-- ===== Feature grid ===== -->
            <section id="fitur" class="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8">
                <div class="mx-auto max-w-2xl text-center" data-aos="fade-up">
                    <p class="text-xs font-semibold uppercase tracking-widest text-cyan-400">Fitur Utama</p>
                    <h2 class="mt-3 text-3xl font-bold text-white sm:text-4xl">Semua yang Anda Butuhkan dalam Satu Platform</h2>
                    <p class="mt-4 text-base text-slate-400">Dirancang khusus untuk operasional FTTH/GPON ISP Indonesia — dari monitoring hingga remote management.</p>
                </div>

                <div class="mt-14 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    <div
                        v-for="(f, i) in features"
                        :key="f.title"
                        class="kv-glass-card kv-glass-hover group"
                        data-aos="fade-up"
                        :data-aos-delay="(i % 3) * 100"
                    >
                        <span :class="f.accent" class="!h-12 !w-12 transition-transform group-hover:scale-105">
                            <component :is="f.icon" class="h-5 w-5" />
                        </span>
                        <h3 class="mt-4 text-base font-semibold text-white">{{ f.title }}</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-400">{{ f.body }}</p>
                    </div>
                </div>

                <!-- Benefit pills strip -->
                <div class="mt-14 flex flex-wrap items-center justify-center gap-3 border-y border-white/10 py-6">
                    <span
                        v-for="(b, i) in benefits"
                        :key="b.label"
                        class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-slate-900/60 px-4 py-2 text-xs font-medium text-slate-300 backdrop-blur"
                        data-aos="zoom-in"
                        :data-aos-delay="i * 80"
                    >
                        <component :is="b.icon" class="h-3.5 w-3.5 text-cyan-400" />
                        {{ b.label }}
                    </span>
                </div>
            </section>

            <!-- ===== Tampilan aplikasi (galeri) ===== -->
            <section id="tampilan" class="border-y border-white/10 bg-slate-950">
                <div class="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8">
                    <div class="mx-auto max-w-2xl text-center" data-aos="fade-up">
                        <p class="text-xs font-semibold uppercase tracking-widest text-cyan-400">Tampilan Aplikasi</p>
                        <h2 class="mt-3 text-3xl font-bold text-white sm:text-4xl">Lihat Langsung Antarmukanya</h2>
                        <p class="mt-4 text-base text-slate-400">Dari dashboard hingga provisioning ONU — antarmuka bersih yang dirancang untuk kecepatan operasional NOC.</p>
                    </div>

                    <div class="mt-14 grid items-start gap-6 lg:grid-cols-[20rem_1fr]" data-aos="fade-up" data-aos-delay="100">
                        <!-- Tab list -->
                        <div
                            role="tablist"
                            aria-label="Pilih tampilan aplikasi"
                            class="flex gap-3 overflow-x-auto pb-2 lg:flex-col lg:gap-2.5 lg:overflow-visible lg:pb-0"
                        >
                            <button
                                v-for="shot in screenshots"
                                :key="shot.key"
                                type="button"
                                role="tab"
                                :aria-selected="activeShot === shot.key"
                                @click="activeShot = shot.key"
                                class="group flex min-w-[15rem] shrink-0 items-center gap-3 rounded-xl border px-4 py-3 text-left transition lg:min-w-0"
                                :class="activeShot === shot.key
                                    ? 'border-cyan-400/40 bg-gradient-to-r from-cyan-500/15 to-sky-500/5 shadow-lg shadow-cyan-500/10'
                                    : 'border-white/10 bg-slate-900/50 hover:border-white/20 hover:bg-slate-800/60'"
                            >
                                <span
                                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border transition"
                                    :class="activeShot === shot.key
                                        ? 'border-cyan-400/40 bg-cyan-500/15 text-cyan-300'
                                        : 'border-white/10 bg-slate-800/60 text-slate-400 group-hover:text-slate-200'"
                                >
                                    <component :is="shot.icon" class="h-5 w-5" />
                                </span>
                                <span class="min-w-0">
                                    <span class="block text-sm font-semibold" :class="activeShot === shot.key ? 'text-white' : 'text-slate-200'">{{ shot.label }}</span>
                                    <span class="block truncate text-xs text-slate-400">{{ shot.desc }}</span>
                                </span>
                            </button>
                        </div>

                        <!-- Preview frame -->
                        <div class="relative">
                            <div class="absolute -inset-4 rounded-3xl bg-gradient-to-br from-cyan-500/20 via-sky-500/10 to-purple-500/20 blur-2xl" />
                            <div class="relative overflow-hidden rounded-2xl border border-white/10 bg-slate-900/40 shadow-2xl shadow-cyan-500/10 backdrop-blur-xl">
                                <!-- Browser chrome -->
                                <div class="flex items-center gap-2 border-b border-white/10 bg-slate-950/60 px-4 py-2.5">
                                    <span class="h-3 w-3 rounded-full bg-red-500/70" />
                                    <span class="h-3 w-3 rounded-full bg-amber-500/70" />
                                    <span class="h-3 w-3 rounded-full bg-emerald-500/70" />
                                    <span class="ml-3 flex flex-1 items-center gap-1.5 truncate rounded-md bg-slate-900/60 px-3 py-1 text-xs text-slate-500">
                                        <ShieldCheck class="h-3 w-3 shrink-0 text-emerald-400/70" />
                                        <span class="truncate">{{ currentShot.url }}</span>
                                    </span>
                                </div>
                                <!-- Screenshot dengan crossfade — tinggi frame mengikuti rasio asli tiap gambar agar tidak terpotong -->
                                <div
                                    class="relative bg-slate-950 transition-[aspect-ratio] duration-300"
                                    :style="{ aspectRatio: currentShot.ratio }"
                                >
                                    <Transition name="kv-fade">
                                        <img
                                            :key="activeShot"
                                            :src="currentShot.src"
                                            :alt="currentShot.alt"
                                            loading="lazy"
                                            decoding="async"
                                            class="absolute inset-0 h-full w-full object-contain"
                                        />
                                    </Transition>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ===== Tech stack ===== -->
            <section id="tech" class="border-y border-white/10 bg-slate-950/50">
                <div class="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8">
                    <div class="mx-auto max-w-2xl text-center" data-aos="fade-up">
                        <p class="text-xs font-semibold uppercase tracking-widest text-cyan-400">Tech Stack</p>
                        <h2 class="mt-3 text-3xl font-bold text-white sm:text-4xl">Dibangun dengan Teknologi Modern & Andal</h2>
                    </div>

                    <div class="mt-10 grid gap-5 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-6">
                        <div
                            v-for="(t, i) in techStack"
                            :key="t.name"
                            class="group relative flex flex-col items-center justify-center rounded-2xl border border-white/10 bg-slate-900/40 px-4 py-8 text-center backdrop-blur-xl transition-all duration-300 hover:-translate-y-1 hover:border-white/25 hover:bg-slate-900/60"
                            :style="{ '--glow-color': t.glow }"
                            data-aos="zoom-in"
                            :data-aos-delay="i * 70"
                        >
                            <!-- Ambient glow on hover -->
                            <div
                                class="pointer-events-none absolute inset-0 rounded-2xl opacity-0 blur-xl transition-opacity duration-300 group-hover:opacity-100"
                                :style="{ background: `radial-gradient(circle at 50% 30%, ${t.glow}, transparent 70%)` }"
                            />

                            <div class="relative mb-4 flex h-16 w-16 items-center justify-center transition-transform duration-300 group-hover:scale-110">
                                <img
                                    :src="t.logo"
                                    :alt="`${t.name} logo`"
                                    class="h-full w-full object-contain drop-shadow-[0_0_12px_var(--glow-color)]"
                                    loading="lazy"
                                />
                            </div>
                            <p class="relative text-sm font-semibold text-white">{{ t.name }}</p>
                            <p class="relative mt-1 text-[11px] text-slate-500">{{ t.sub }}</p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ===== Modul lengkap ===== -->
            <section id="modul" class="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8">
                <div class="mx-auto max-w-2xl text-center" data-aos="fade-up">
                    <p class="text-xs font-semibold uppercase tracking-widest text-cyan-400">Modul</p>
                    <h2 class="mt-3 text-3xl font-bold text-white sm:text-4xl">Modul Lengkap untuk Operasional FTTH</h2>
                    <p class="mt-4 text-base text-slate-400">Setiap modul dirancang ringkas, dengan alur kerja yang terasa natural buat tim NOC.</p>
                </div>

                <div class="mt-12 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div
                        v-for="(m, i) in modules"
                        :key="m.title"
                        class="kv-glass-card kv-glass-hover group flex items-start gap-4"
                        data-aos="fade-up"
                        :data-aos-delay="(i % 3) * 100"
                    >
                        <span class="kv-circle-cyan !h-11 !w-11">
                            <component :is="m.icon" class="h-5 w-5" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center justify-between gap-2">
                                <h3 class="text-sm font-semibold text-white">{{ m.title }}</h3>
                                <ChevronRight class="h-4 w-4 flex-shrink-0 text-slate-600 transition-colors group-hover:text-cyan-400" />
                            </div>
                            <p class="mt-1 text-xs leading-5 text-slate-400">{{ m.sub }}</p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ===== Final CTA ===== -->
            <section class="mx-auto max-w-7xl px-4 pb-20 sm:px-6 lg:px-8">
                <div class="relative overflow-hidden rounded-3xl border border-cyan-500/30 bg-gradient-to-br from-cyan-500/10 via-slate-900/40 to-purple-500/10 p-8 backdrop-blur-xl sm:p-12" data-aos="zoom-in-up">
                    <div class="pointer-events-none absolute -right-20 -top-20 h-72 w-72 rounded-full bg-cyan-500/20 blur-3xl" />
                    <div class="pointer-events-none absolute -bottom-20 -left-20 h-72 w-72 rounded-full bg-purple-500/15 blur-3xl" />

                    <div class="relative flex flex-col items-center justify-between gap-6 md:flex-row md:gap-10">
                        <div class="text-center md:text-left">
                            <h2 class="text-2xl font-bold text-white sm:text-3xl">Siap Mengelola Jaringan FTTH Anda Lebih Efisien?</h2>
                            <p class="mt-2 text-sm text-slate-300 sm:text-base">Mulai monitoring OLT &amp; ONU dengan dashboard yang terpusat &amp; konsisten.</p>
                        </div>
                        <div class="flex flex-wrap items-center justify-center gap-3">
                            <Link
                                v-if="canLogin && !$page.props.auth.user"
                                :href="route('login')"
                                class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-cyan-500 to-sky-600 px-6 py-3.5 text-sm font-semibold text-white shadow-lg shadow-cyan-500/40 transition hover:shadow-cyan-500/60"
                            >
                                Login
                                <ArrowRight class="h-4 w-4" />
                            </Link>
                            <a
                                href="#kontak"
                                class="inline-flex items-center gap-2 rounded-xl border border-white/15 bg-slate-900/60 px-6 py-3.5 text-sm font-semibold text-slate-100 backdrop-blur transition hover:border-white/25 hover:bg-slate-800/80"
                            >
                                <Phone class="h-4 w-4" />
                                Hubungi Kami
                            </a>
                        </div>
                    </div>
                </div>
            </section>
        </main>

        <!-- ===== Footer ===== -->
        <footer id="kontak" class="border-t border-white/10 bg-slate-950">
            <div class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
                <div class="grid gap-10 md:grid-cols-2 lg:grid-cols-5">
                    <div class="lg:col-span-2">
                        <div class="flex items-center gap-2.5">
                            <ApplicationLogo class="h-8 w-auto fill-current text-cyan-400 drop-shadow-[0_0_8px_rgba(34,211,238,0.4)]" />
                            <div class="leading-tight">
                                <div class="text-sm font-bold text-white">KusumaVision NMS</div>
                                <div class="text-[10px] text-slate-500">PT Berkah Media Kusuma Vision</div>
                            </div>
                        </div>
                        <p class="mt-4 max-w-sm text-sm leading-6 text-slate-400">
                            Platform manajemen jaringan FTTH untuk ISP Indonesia. Dikembangkan dengan fokus operasional NOC yang cepat &amp; konsisten.
                        </p>
                    </div>

                    <div>
                        <h4 class="text-sm font-semibold text-white">Produk</h4>
                        <ul class="mt-4 space-y-2.5 text-sm">
                            <li v-for="l in productLinks" :key="l.label">
                                <a :href="l.href" class="text-slate-400 transition-colors hover:text-cyan-400">{{ l.label }}</a>
                            </li>
                        </ul>
                    </div>

                    <div>
                        <h4 class="text-sm font-semibold text-white">Perusahaan</h4>
                        <ul class="mt-4 space-y-2.5 text-sm">
                            <li v-for="l in companyLinks" :key="l.label">
                                <a :href="l.href" class="text-slate-400 transition-colors hover:text-cyan-400">{{ l.label }}</a>
                            </li>
                        </ul>
                    </div>

                    <div>
                        <h4 class="text-sm font-semibold text-white">Kontak</h4>
                        <ul class="mt-4 space-y-2.5 text-sm text-slate-400">
                            <li class="flex items-start gap-2">
                                <MapPin class="mt-0.5 h-4 w-4 flex-shrink-0 text-cyan-400" />
                                <span>Pati, Jawa Tengah, Indonesia</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <Mail class="mt-0.5 h-4 w-4 flex-shrink-0 text-cyan-400" />
                                <a href="mailto:misbakhulmunir@kusumavision.net" class="hover:text-cyan-400">misbakhulmunir@kusumavision.net</a>
                            </li>
                            <li class="flex items-start gap-2">
                                <Phone class="mt-0.5 h-4 w-4 flex-shrink-0 text-cyan-400" />
                                <span>+62 858-0303-0268</span>
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="mt-10 flex flex-col items-center justify-between gap-3 border-t border-white/10 pt-6 text-xs text-slate-500 sm:flex-row">
                    <p>&copy; 2026 KusumaVision NMS &middot; Dibuat oleh Masamune</p>
                    <p>ZTE OLT Management &amp; Provisioning Platform</p>
                </div>
            </div>
        </footer>
    </div>
</template>

<style scoped>
/* Crossfade antar screenshot di galeri "Tampilan Aplikasi" */
.kv-fade-enter-active,
.kv-fade-leave-active {
    transition: opacity 250ms ease;
}
.kv-fade-enter-from,
.kv-fade-leave-to {
    opacity: 0;
}

@media (prefers-reduced-motion: reduce) {
    .kv-fade-enter-active,
    .kv-fade-leave-active {
        transition: none;
    }
}
</style>
