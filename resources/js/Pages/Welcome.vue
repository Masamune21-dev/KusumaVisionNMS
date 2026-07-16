<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Head, Link } from '@inertiajs/vue3';
import {
    Activity,
    ArrowRight,
    BellRing,
    Boxes,
    Cable,
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
    Play,
    Radar,
    RadioTower,
    Router,
    ScrollText,
    Search,
    Send,
    Server,
    ShieldCheck,
    Smartphone,
    Sparkles,
    Terminal,
    Wifi,
    WifiOff,
    Workflow,
} from '@lucide/vue';
import NumberFlow from '@number-flow/vue';
import { computed, defineAsyncComponent, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { gsap } from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import Lenis from 'lenis';
import Typed from 'typed.js';

defineProps({
    canLogin: { type: Boolean },
    canRegister: { type: Boolean },
});

// tsParticles dimuat sebagai chunk terpisah (async) — jika di-import statis,
// banyaknya dynamic import internal tsParticles membuat Rollup menggabungkan
// facade chunk Welcome sehingga key-nya hilang dari Vite manifest (500 di prod).
const ParticleNetwork = defineAsyncComponent(
    () => import('@/Components/Shell/ParticleNetwork.vue'),
);

const reduceMotion = () =>
    typeof window !== 'undefined' &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/* ===== Directive: tilt 3D + parallax anak [data-depth] (mouse only) ===== */
const vTilt = {
    mounted(el, binding) {
        if (reduceMotion()) return;
        const strength = binding.value?.strength ?? 6;
        let raf = null;
        const onMove = (e) => {
            const r = el.getBoundingClientRect();
            const px = (e.clientX - r.left) / r.width - 0.5;
            const py = (e.clientY - r.top) / r.height - 0.5;
            cancelAnimationFrame(raf);
            raf = requestAnimationFrame(() => {
                el.style.transform = `perspective(1100px) rotateX(${(-py * strength).toFixed(2)}deg) rotateY(${(px * strength).toFixed(2)}deg)`;
                el.querySelectorAll('[data-depth]').forEach((c) => {
                    const d = parseFloat(c.dataset.depth) || 0;
                    c.style.transform = `translate3d(${(px * d * 22).toFixed(1)}px, ${(py * d * 22).toFixed(1)}px, 0)`;
                });
            });
        };
        const onLeave = () => {
            cancelAnimationFrame(raf);
            el.style.transform = 'perspective(1100px) rotateX(0deg) rotateY(0deg)';
            el.querySelectorAll('[data-depth]').forEach((c) => {
                c.style.transform = 'translate3d(0,0,0)';
            });
        };
        el.__tilt = { onMove, onLeave };
        el.addEventListener('mousemove', onMove);
        el.addEventListener('mouseleave', onLeave);
    },
    unmounted(el) {
        if (!el.__tilt) return;
        el.removeEventListener('mousemove', el.__tilt.onMove);
        el.removeEventListener('mouseleave', el.__tilt.onLeave);
    },
};

/* ===== Directive: magnetic (tombol mengikuti kursor) ===== */
const vMagnetic = {
    mounted(el, binding) {
        if (reduceMotion()) return;
        const strength = binding.value?.strength ?? 0.35;
        let raf = null;
        const onMove = (e) => {
            const r = el.getBoundingClientRect();
            const x = e.clientX - r.left - r.width / 2;
            const y = e.clientY - r.top - r.height / 2;
            cancelAnimationFrame(raf);
            raf = requestAnimationFrame(() => {
                el.style.transform = `translate(${(x * strength).toFixed(1)}px, ${(y * strength).toFixed(1)}px)`;
            });
        };
        const onLeave = () => {
            cancelAnimationFrame(raf);
            el.style.transform = 'translate(0,0)';
        };
        el.__mag = { onMove, onLeave };
        el.addEventListener('mousemove', onMove);
        el.addEventListener('mouseleave', onLeave);
    },
    unmounted(el) {
        if (!el.__mag) return;
        el.removeEventListener('mousemove', el.__mag.onMove);
        el.removeEventListener('mouseleave', el.__mag.onLeave);
    },
};

/* ===== Directive: spotlight (radial highlight mengikuti kursor di kartu) ===== */
const vSpotlight = {
    mounted(el) {
        const onMove = (e) => {
            const r = el.getBoundingClientRect();
            el.style.setProperty('--spot-x', `${e.clientX - r.left}px`);
            el.style.setProperty('--spot-y', `${e.clientY - r.top}px`);
        };
        el.__spot = onMove;
        el.addEventListener('mousemove', onMove);
    },
    unmounted(el) {
        if (el.__spot) el.removeEventListener('mousemove', el.__spot);
    },
};

const { t } = useI18n({ useScope: 'global' });

const mobileOpen = ref(false);
const scrolled = ref(false);
const activeShot = ref('dashboard');
const cliEl = ref(null);
const statsEl = ref(null);
const stepsLineEl = ref(null);
const galleryPaused = ref(false);
const GALLERY_MS = 5000;

// Navbar pakai latar solid (semi-transparan) saat halaman sudah di-scroll
// atau ketika drawer mobile dibuka — di puncak halaman ia transparan penuh.
const navSolid = computed(() => scrolled.value || mobileOpen.value);

// Semua teks marketing di lang/{id,en}.json namespace `welcome.*` — array di bawah
// dirakit sebagai computed agar reaktif terhadap switch bahasa.
const navLinks = computed(() => [
    { label: t('welcome.nav_home'), href: '#beranda' },
    { label: t('welcome.nav_features'), href: '#fitur' },
    { label: t('welcome.nav_how'), href: '#cara-kerja' },
    { label: t('welcome.nav_screens'), href: '#tampilan' },
    { label: t('welcome.nav_tech'), href: '#tech' },
    { label: t('welcome.nav_contact'), href: '#kontak' },
]);

const heroPills = computed(() => [
    { icon: Cable, label: 'ZTE C300/C320/C600' },
    { icon: Router, label: 'C-Data EPON/GPON' },
    { icon: RadioTower, label: 'HiOSO / V-Sol EPON' },
    { icon: Wifi, label: 'ONU Provisioning' },
    { icon: MapPin, label: t('welcome.pill_map') },
    { icon: Terminal, label: 'Web Telnet' },
    { icon: BellRing, label: 'Alarm Engine' },
    { icon: Smartphone, label: t('welcome.pill_android') },
]);

// Label & sub tiap stat dirender via $t('welcome.stat{i}_label/_sub') di template.
const stats = [
    { value: 3, suffix: '', icon: Router, circle: 'kv-circle-sky' },
    { value: 14, suffix: '+', icon: Boxes, circle: 'kv-circle-cyan' },
    { value: 24, suffix: '/7', icon: Activity, circle: 'kv-circle-emerald' },
    { value: 100, suffix: '%', icon: MonitorPlay, circle: 'kv-circle-purple' },
];
const displayStats = ref(stats.map((s) => ({ ...s, current: 0 })));

const steps = computed(() => [
    { n: '01', icon: Server, title: t('welcome.step1_title'), body: t('welcome.step1_body') },
    { n: '02', icon: Radar, title: t('welcome.step2_title'), body: t('welcome.step2_body') },
    { n: '03', icon: Workflow, title: t('welcome.step3_title'), body: t('welcome.step3_body') },
    { n: '04', icon: BellRing, title: t('welcome.step4_title'), body: t('welcome.step4_body') },
]);

const FEATURE_DEFS = [
    { icon: Router, accent: 'kv-circle-cyan', badge: true, key: 'multivendor' },
    { icon: MapPin, accent: 'kv-circle-emerald', badge: true, key: 'map' },
    { icon: Smartphone, accent: 'kv-circle-purple', badge: true, key: 'android' },
    { icon: Database, accent: 'kv-circle-sky', key: 'inventory' },
    { icon: Wifi, accent: 'kv-circle-emerald', key: 'monitoring' },
    { icon: Workflow, accent: 'kv-circle-purple', key: 'provisioning' },
    { icon: Gauge, accent: 'kv-circle-cyan', key: 'polling' },
    { icon: BellRing, accent: 'kv-circle-red', key: 'alarm' },
    { icon: Cog, accent: 'kv-circle-amber', key: 'remote' },
    { icon: Terminal, accent: 'kv-circle-purple', key: 'telnet' },
    { icon: Search, accent: 'kv-circle-sky', key: 'search' },
    { icon: FileBarChart, accent: 'kv-circle-sky', key: 'reports' },
    { icon: Send, accent: 'kv-circle-cyan', key: 'notif' },
    { icon: ScrollText, accent: 'kv-circle-emerald', key: 'audit' },
    { icon: ShieldCheck, accent: 'kv-circle-amber', key: 'rbac' },
];
const features = computed(() => FEATURE_DEFS.map((def) => ({
    ...def,
    badge: def.badge ? t('welcome.badge_new') : null,
    title: t(`welcome.f_${def.key}_title`),
    body: t(`welcome.f_${def.key}_body`),
})));

const benefits = [
    { icon: MonitorPlay, label: 'Centralized Monitoring' },
    { icon: BellRing, label: 'Real-time Alerts' },
    { icon: Boxes, label: 'Scalable Architecture' },
    { icon: ShieldCheck, label: 'Secure Operations' },
    { icon: Activity, label: 'ISP-Focused Workflow' },
];

// Kapabilitas untuk marquee berjalan (infinite scroll antar-section)
const marqueeItems = computed(() => [
    'Multi-Vendor OLT',
    'C-Data EPON/GPON',
    'HiOSO / V-Sol EPON',
    'GPON Monitoring',
    'SNMP Polling',
    'ONU Provisioning',
    t('welcome.marquee_tr069'),
    'Alarm Engine',
    'Web Telnet',
    'RX Optical Power',
    t('welcome.marquee_map'),
    t('welcome.marquee_android'),
    'Push Notification',
    'Remote ONU',
    'Telegram Alerts',
    'Audit Logs',
    'Role-based Access',
    'Reports & Analytics',
    'Global Search',
]);

const techStack = [
    { name: 'Laravel 12', sub: 'PHP Framework', logo: '/img/tech/laravel.svg', glow: 'rgba(239, 68, 68, 0.25)' },
    { name: 'Vue 3', sub: 'Frontend SPA', logo: '/img/tech/vue.svg', glow: 'rgba(16, 185, 129, 0.25)' },
    { name: 'Inertia.js', sub: 'SPA Bridge', logo: '/img/tech/inertia.svg', glow: 'rgba(168, 85, 247, 0.25)' },
    { name: 'Tailwind CSS', sub: 'UI Styling', logo: '/img/tech/tailwind.svg', glow: 'rgba(56, 189, 248, 0.25)' },
    { name: 'PostgreSQL', sub: 'Database', logo: '/img/tech/postgresql.svg', glow: 'rgba(110, 168, 249, 0.25)' },
    { name: 'Redis', sub: 'Cache & Queue', logo: '/img/tech/redis.svg', glow: 'rgba(239, 68, 68, 0.25)' },
    { name: 'Golang', sub: 'Polling Engine', logo: '/img/tech/go.svg', glow: 'rgba(34, 211, 238, 0.25)' },
    { name: 'Flutter', sub: null, logo: '/img/tech/flutter.svg', glow: 'rgba(71, 197, 251, 0.25)' }, // sub via $t('welcome.tech_flutter_sub')
];

const modules = computed(() => [
    { icon: LayoutDashboard, title: 'Dashboard', sub: t('welcome.m_dashboard_sub') },
    { icon: Server, title: 'OLT Inventory', sub: t('welcome.m_inventory_sub') },
    { icon: Router, title: 'OLT C-Data & HiOSO', sub: t('welcome.m_nonzte_sub') },
    { icon: BellRing, title: 'Alarm Center', sub: t('welcome.m_alarm_sub') },
    { icon: Workflow, title: 'Provisioning', sub: t('welcome.m_prov_sub') },
    { icon: Radar, title: 'ONU Monitoring', sub: t('welcome.m_monitoring_sub') },
    { icon: MapPin, title: t('welcome.m_map_title'), sub: t('welcome.m_map_sub') },
    { icon: Terminal, title: 'Telnet Console', sub: t('welcome.m_telnet_sub') },
    { icon: Database, title: 'Profiles', sub: t('welcome.m_profiles_sub') },
    { icon: Smartphone, title: t('welcome.m_android_title'), sub: t('welcome.m_android_sub') },
    { icon: FileBarChart, title: 'Reports', sub: t('welcome.m_reports_sub') },
]);

// ?v= untuk cache-bust file yang ditimpa (nama sama, isi baru — Jul 2026).
const SHOT_V = '?v=20260711';
// label/desc/alt tiap screenshot dirender via $t('welcome.shot_{key}_label/_desc').
const screenshots = [
    {
        key: 'dashboard',
        icon: LayoutDashboard,
        label: 'Dashboard',
        desc: 'Ringkasan jaringan FTTH real-time',
        src: `/img/dashboard1.webp${SHOT_V}`,
        ratio: '1920 / 1282',
        url: 'app.kusumavision.net/dashboard',
        alt: 'Dashboard KusumaVision NMS — ringkasan OLT, ONU, dan alarm',
    },
    {
        key: 'olt',
        icon: Server,
        label: 'OLT Inventory',
        desc: 'Perangkat, line card & port GPON',
        src: `/img/oltinventory.webp${SHOT_V}`,
        ratio: '1920 / 911',
        url: 'app.kusumavision.net/smartolt',
        alt: 'Inventaris OLT — daftar perangkat ZTE dan kapabilitasnya',
    },
    {
        key: 'oltdetail',
        icon: Router,
        label: 'Detail OLT',
        desc: 'System info & visualisasi chassis live',
        src: `/img/detail.webp${SHOT_V}`,
        ratio: '1920 / 1699',
        url: 'app.kusumavision.net/smartolt/2/detail',
        alt: 'Detail OLT — system info dan visualisasi chassis per slot/port',
    },
    {
        key: 'portdetail',
        icon: Gauge,
        src: '/img/portdetail.webp',
        ratio: '1920 / 1130',
        url: 'app.kusumavision.net/smartolt/1/port-detail',
    },
    {
        key: 'portonus',
        icon: Wifi,
        src: '/img/portonus.webp',
        ratio: '1920 / 911',
        url: 'app.kusumavision.net/smartolt/1/ports/2/1/onus',
    },
    {
        key: 'monitoring',
        icon: Radar,
        src: '/img/onumonitoring.webp',
        ratio: '1920 / 911',
        url: 'app.kusumavision.net/onu-monitoring',
    },
    {
        key: 'map',
        icon: MapPin,
        src: '/img/map.webp',
        ratio: '1920 / 913',
        url: 'app.kusumavision.net/map',
    },
    {
        key: 'unconfigured',
        icon: WifiOff,
        label: 'ONU Belum Terdaftar',
        desc: 'Temukan & provisioning ONU baru',
        src: `/img/unconfigured.webp${SHOT_V}`,
        ratio: '1920 / 911',
        url: 'app.kusumavision.net/smartolt/unconfigured',
        alt: 'Daftar ONU belum terkonfigurasi siap di-provisioning',
    },
    {
        key: 'alarms',
        icon: BellRing,
        src: '/img/alarms.webp',
        ratio: '1920 / 911',
        url: 'app.kusumavision.net/alarms',
    },
    {
        key: 'reports',
        icon: FileBarChart,
        src: '/img/reports.webp',
        ratio: '1920 / 911',
        url: 'app.kusumavision.net/reports',
    },
    {
        key: 'login',
        icon: LogIn,
        label: 'Login',
        desc: 'Akses aman untuk operator NOC',
        src: `/img/login.webp${SHOT_V}`,
        ratio: '1920 / 911',
        url: 'app.kusumavision.net/login',
        alt: 'Halaman login KusumaVision NMS',
    },
];

const currentShot = computed(
    () => screenshots.find((s) => s.key === activeShot.value) ?? screenshots[0],
);

/* ===== Galeri "Tampilan Aplikasi": autoplay + pause saat hover ===== */
let galleryTimer = null;
const advanceShot = (dir = 1) => {
    const idx = screenshots.findIndex((s) => s.key === activeShot.value);
    const next = (idx + dir + screenshots.length) % screenshots.length;
    activeShot.value = screenshots[next].key;
};
const stopGallery = () => {
    if (galleryTimer) {
        clearInterval(galleryTimer);
        galleryTimer = null;
    }
};
const startGallery = () => {
    if (reduceMotion()) return;
    stopGallery();
    galleryTimer = window.setInterval(() => {
        if (!galleryPaused.value) advanceShot(1);
    }, GALLERY_MS);
};
const selectShot = (key) => {
    activeShot.value = key;
    startGallery(); // reset timer ketika dipilih manual
};

const productLinks = computed(() => [
    { label: 'Dashboard', href: '#beranda' },
    { label: t('welcome.nav_features'), href: '#fitur' },
    { label: t('welcome.nav_tech'), href: '#tech' },
    { label: t('welcome.nav_screens'), href: '#tampilan' },
]);
const companyLinks = computed(() => [
    { label: t('welcome.link_about'), href: '#' },
    { label: t('welcome.link_blog'), href: '#' },
    { label: t('welcome.link_careers'), href: '#' },
]);

/* ===== Animation engine: GSAP + ScrollTrigger + Lenis ===== */
let lenis = null;
let typed = null;
let statsObserver = null;
const lenisRaf = (time) => lenis && lenis.raf(time * 1000);

const onWindowScroll = () => {
    scrolled.value = (window.scrollY || window.pageYOffset || 0) > 12;
};

const scrollToHash = (e, href) => {
    if (!href || !href.startsWith('#')) return;
    const target = document.querySelector(href);
    if (!target) return;
    e.preventDefault();
    mobileOpen.value = false;
    if (lenis) {
        lenis.scrollTo(target, { offset: -72 });
    } else {
        target.scrollIntoView({ behavior: reduceMotion() ? 'auto' : 'smooth' });
    }
};

onMounted(() => {
    const reduced = reduceMotion();

    // Status scroll untuk transisi navbar (transparan → semi-transparan)
    window.addEventListener('scroll', onWindowScroll, { passive: true });
    onWindowScroll();

    // CLI typewriter (hero terminal)
    if (cliEl.value) {
        if (reduced) {
            cliEl.value.textContent = 'show gpon onu state gpon-olt_1/1/1';
        } else {
            typed = new Typed(cliEl.value, {
                strings: [
                    'show gpon onu state gpon-olt_1/1/1',
                    'show pon power onu-rx gpon-onu_1/1/1:1',
                    'show gpon onu uncfg',
                    'show card',
                ],
                typeSpeed: 45,
                backSpeed: 18,
                backDelay: 1700,
                startDelay: 500,
                loop: true,
                smartBackspace: true,
                cursorChar: '▋',
            });
        }
    }

    // Stat counters animate when scrolled into view
    if (statsEl.value) {
        statsObserver = new IntersectionObserver(
            (entries) => {
                if (entries.some((en) => en.isIntersecting)) {
                    displayStats.value = stats.map((s) => ({ ...s, current: s.value }));
                    statsObserver.disconnect();
                }
            },
            { threshold: 0.35 },
        );
        statsObserver.observe(statsEl.value);
    }

    // Autoplay galeri tampilan aplikasi
    startGallery();

    if (reduced) return; // CSS sudah menampilkan elemen reveal; lewati animasi.

    gsap.registerPlugin(ScrollTrigger);

    // Smooth scroll (sinkron dengan ScrollTrigger via ticker GSAP)
    lenis = new Lenis({
        duration: 1.1,
        easing: (t) => Math.min(1, 1.001 - Math.pow(2, -10 * t)),
    });
    lenis.on('scroll', ScrollTrigger.update);
    gsap.ticker.add(lenisRaf);
    gsap.ticker.lagSmoothing(0);

    // Hero intro ditangani via CSS (kelas .reveal-hero) agar tidak bergantung
    // pada chunk GSAP yang dimuat belakangan.

    // Section reveals (scroll-triggered, staggered batch)
    ScrollTrigger.batch('[data-reveal]', {
        start: 'top 86%',
        once: true,
        onEnter: (els) =>
            gsap.to(els, {
                opacity: 1,
                y: 0,
                duration: 0.7,
                ease: 'power3.out',
                stagger: 0.08,
                overwrite: true,
            }),
    });

    // Garis konektor "Cara Kerja" menggambar mengikuti scroll
    if (stepsLineEl.value) {
        gsap.fromTo(
            stepsLineEl.value,
            { scaleX: 0 },
            {
                scaleX: 1,
                ease: 'none',
                scrollTrigger: {
                    trigger: stepsLineEl.value,
                    start: 'top 92%',
                    end: 'top 45%',
                    scrub: true,
                },
            },
        );
    }

    ScrollTrigger.refresh();
});

onBeforeUnmount(() => {
    window.removeEventListener('scroll', onWindowScroll);
    stopGallery();
    typed?.destroy();
    statsObserver?.disconnect();
    ScrollTrigger.getAll().forEach((t) => t.kill());
    gsap.ticker.remove(lenisRaf);
    if (lenis) {
        lenis.destroy();
        lenis = null;
    }
});
</script>

<template>
    <Head title="KusumaVision NMS — ZTE OLT Management & Provisioning Platform" />

    <div class="min-h-screen bg-slate-950 text-slate-100">
        <!-- ===== Top nav ===== -->
        <header
            class="fixed inset-x-0 top-0 z-40 transition-colors duration-300"
            :class="navSolid
                ? 'border-b border-white/10 bg-slate-950/60 shadow-lg shadow-black/20 backdrop-blur-xl'
                : 'border-b border-transparent bg-transparent'"
        >
            <div class="mx-auto flex max-w-[1600px] items-center justify-between gap-4 px-4 py-4 sm:px-6 lg:px-8">
                <Link href="/" class="flex items-center gap-2.5">
                    <ApplicationLogo class="h-9 w-auto fill-current text-cyan-400 drop-shadow-[0_0_8px_rgba(34,211,238,0.45)]" />
                    <div class="leading-tight">
                        <div class="text-[15px] font-bold text-white">KusumaVision</div>
                        <div class="text-[11px] text-slate-500">NMS v2 &middot; GPON Management</div>
                    </div>
                </Link>

                <nav class="hidden items-center gap-1 md:flex">
                    <a
                        v-for="link in navLinks"
                        :key="link.href"
                        :href="link.href"
                        class="rounded-lg px-3 py-2 text-sm font-medium text-slate-400 transition-colors hover:text-white"
                        @click="scrollToHash($event, link.href)"
                    >
                        {{ link.label }}
                    </a>
                </nav>

                <div class="flex items-center gap-2">
                    <template v-if="canLogin">
                        <Link
                            v-if="$page.props.auth.user"
                            v-magnetic
                            :href="route('dashboard')"
                            class="kv-magnetic group relative inline-flex items-center gap-2 overflow-hidden rounded-xl bg-gradient-to-r from-cyan-500 to-sky-600 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-cyan-500/30 ring-1 ring-inset ring-white/20 transition-all duration-300 hover:shadow-cyan-500/50 hover:brightness-110"
                        >
                            <span class="pointer-events-none absolute inset-0 -translate-x-full bg-gradient-to-r from-transparent via-white/30 to-transparent transition-transform duration-700 ease-out group-hover:translate-x-full" />
                            <span class="relative">Dashboard</span>
                            <ArrowRight class="relative h-4 w-4 transition-transform duration-300 group-hover:translate-x-0.5" />
                        </Link>
                        <Link
                            v-else
                            v-magnetic
                            :href="route('login')"
                            class="kv-magnetic group relative inline-flex items-center gap-2 overflow-hidden rounded-xl bg-gradient-to-r from-cyan-500 to-sky-600 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-cyan-500/30 ring-1 ring-inset ring-white/20 transition-all duration-300 hover:shadow-cyan-500/50 hover:brightness-110"
                        >
                            <span class="pointer-events-none absolute inset-0 -translate-x-full bg-gradient-to-r from-transparent via-white/30 to-transparent transition-transform duration-700 ease-out group-hover:translate-x-full" />
                            <span class="relative">Login</span>
                            <ArrowRight class="relative h-4 w-4 transition-transform duration-300 group-hover:translate-x-0.5" />
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
                    <div class="mx-auto flex max-w-[1600px] flex-col gap-1 px-4 py-3 sm:px-6">
                        <a
                            v-for="link in navLinks"
                            :key="link.href"
                            :href="link.href"
                            class="rounded-lg px-3 py-2.5 text-sm font-medium text-slate-300 transition-colors hover:bg-white/5 hover:text-white"
                            @click="scrollToHash($event, link.href)"
                        >
                            {{ link.label }}
                        </a>
                    </div>
                </nav>
            </Transition>
        </header>

        <main>
            <!-- ===== Hero ===== -->
            <section id="beranda" class="kv-grid-bg relative flex min-h-screen items-center overflow-hidden">
                <ParticleNetwork id="kv-hero-particles" />
                <!-- Ambient glows -->
                <div class="pointer-events-none absolute -left-32 top-20 h-96 w-96 animate-pulse rounded-full bg-cyan-500/15 blur-[120px]" />
                <div class="pointer-events-none absolute -right-32 top-40 h-96 w-96 animate-pulse rounded-full bg-purple-500/10 blur-[120px]" style="animation-delay: 1.5s" />

                <div class="relative mx-auto grid w-full max-w-[1600px] items-center gap-10 px-4 py-16 sm:px-6 lg:grid-cols-2 lg:gap-12 lg:px-8 lg:py-24">
                    <div>
                        <div class="reveal-hero inline-flex items-center gap-2 rounded-full border border-cyan-500/30 bg-cyan-500/10 px-3 py-1 text-xs font-semibold text-cyan-300" style="animation-delay: 0.05s">
                            <Sparkles class="h-3.5 w-3.5" />
                            GPON Operations Console &middot; v2
                        </div>

                        <h1 class="reveal-hero mt-6 text-4xl font-bold leading-tight tracking-tight text-white sm:text-5xl lg:text-[3.5rem]" style="animation-delay: 0.12s">
                            ZTE <span class="bg-gradient-to-r from-cyan-400 to-sky-500 bg-clip-text text-transparent">OLT Management</span> &amp; Provisioning Platform
                        </h1>
                        <p class="reveal-hero mt-5 max-w-xl text-base leading-7 text-slate-400 sm:text-lg" style="animation-delay: 0.19s">
                            {{ $t('welcome.hero_desc') }}
                        </p>

                        <!-- Hero CTAs -->
                        <div class="reveal-hero mt-8 flex flex-wrap items-center gap-3" style="animation-delay: 0.26s">
                            <Link
                                v-if="$page.props.auth.user"
                                v-magnetic
                                :href="route('dashboard')"
                                class="kv-magnetic inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-cyan-500 to-sky-600 px-6 py-3.5 text-sm font-semibold text-white shadow-lg shadow-cyan-500/30 transition hover:shadow-cyan-500/50"
                            >
                                <LayoutDashboard class="h-4 w-4" />
                                {{ $t('welcome.open_dashboard') }}
                                <ArrowRight class="h-4 w-4" />
                            </Link>
                            <template v-else>
                                <Link
                                    v-if="canLogin"
                                    v-magnetic
                                    :href="route('login')"
                                    class="kv-magnetic inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-cyan-500 to-sky-600 px-6 py-3.5 text-sm font-semibold text-white shadow-lg shadow-cyan-500/30 transition hover:shadow-cyan-500/50"
                                >
                                    Get Started
                                    <ArrowRight class="h-4 w-4" />
                                </Link>
                            </template>
                            <a
                                href="#tampilan"
                                class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-slate-900/60 px-6 py-3.5 text-sm font-semibold text-slate-200 backdrop-blur transition hover:border-white/20 hover:bg-slate-800/80 hover:text-white"
                                @click="scrollToHash($event, '#tampilan')"
                            >
                                <Play class="h-4 w-4" />
                                {{ $t('welcome.see_screens') }}
                            </a>
                        </div>

                        <!-- Hero pills -->
                        <div class="reveal-hero mt-8 flex flex-wrap gap-2" style="animation-delay: 0.33s">
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

                    <!-- Visual: tilt group (dashboard preview + floating live terminal) -->
                    <div class="reveal-hero relative" style="animation-delay: 0.2s">
                        <div v-tilt="{ strength: 7 }" class="kv-tilt relative">
                            <div class="pointer-events-none absolute -inset-4 rounded-3xl bg-gradient-to-br from-cyan-500/20 via-sky-500/10 to-purple-500/20 blur-2xl" />

                            <!-- Dashboard preview -->
                            <div data-depth="0.25" class="relative overflow-hidden rounded-2xl border border-white/10 bg-slate-900/40 shadow-2xl shadow-cyan-500/10 backdrop-blur-xl">
                                <div class="flex items-center gap-2 border-b border-white/10 bg-slate-950/60 px-4 py-2.5">
                                    <span class="h-3 w-3 rounded-full bg-red-500/70" />
                                    <span class="h-3 w-3 rounded-full bg-amber-500/70" />
                                    <span class="h-3 w-3 rounded-full bg-emerald-500/70" />
                                    <span class="ml-3 flex-1 truncate rounded-md bg-slate-900/60 px-3 py-1 text-xs text-slate-500">app.kusumavision.net/dashboard</span>
                                </div>
                                <img
                                    src="/img/dashboard1.webp?v=20260711"
                                    alt="KusumaVision NMS Dashboard"
                                    class="block w-full"
                                    loading="eager"
                                />
                            </div>

                            <!-- Floating status chip -->
                            <div
                                data-depth="1.2"
                                class="absolute -top-4 right-4 hidden items-center gap-2 rounded-xl border border-emerald-400/30 bg-slate-950/80 px-3 py-2 shadow-xl shadow-emerald-500/10 backdrop-blur-xl lg:flex"
                            >
                                <span class="relative flex h-2.5 w-2.5">
                                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400/70" />
                                    <span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-emerald-400" />
                                </span>
                                <div class="leading-tight">
                                    <div class="text-[11px] font-semibold text-white">ONU Online</div>
                                    <div class="text-[10px] text-emerald-300/80">RX −18.2 dBm · OK</div>
                                </div>
                            </div>

                            <!-- Floating live terminal (typed CLI) -->
                            <div
                                data-depth="0.85"
                                class="relative mt-5 overflow-hidden rounded-xl border border-white/10 bg-slate-950/90 shadow-2xl shadow-black/40 backdrop-blur-xl lg:absolute lg:-bottom-10 lg:-left-10 lg:mt-0 lg:w-[20rem]"
                            >
                                <div class="flex items-center gap-2 border-b border-white/10 bg-slate-900/70 px-3 py-2">
                                    <Terminal class="h-3.5 w-3.5 text-cyan-400" />
                                    <span class="text-[11px] font-medium text-slate-300">OLT-C320-PATI · telnet</span>
                                    <span class="ml-auto flex gap-1">
                                        <span class="h-2 w-2 rounded-full bg-slate-600" />
                                        <span class="h-2 w-2 rounded-full bg-slate-600" />
                                    </span>
                                </div>
                                <div class="space-y-1 px-3 py-3 font-mono text-[11px] leading-relaxed">
                                    <div class="text-slate-500">$ telnet 10.10.0.1</div>
                                    <div class="text-emerald-400">✓ Connected — ZXAN login: admin</div>
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <span class="text-cyan-400">OLT-C320-PATI#</span>
                                        <span ref="cliEl" class="text-slate-200"></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ===== Stats band ===== -->
            <section class="border-y border-white/10 bg-slate-950">
                <div ref="statsEl" class="mx-auto grid max-w-[1600px] grid-cols-2 gap-px overflow-hidden px-4 sm:px-6 lg:grid-cols-4 lg:px-8">
                    <div
                        v-for="(s, i) in displayStats"
                        :key="i"
                        v-spotlight
                        class="kv-spotlight group relative px-4 py-10 text-center transition-colors duration-300 hover:bg-white/[0.025] sm:px-6"
                        data-reveal
                    >
                        <span class="pointer-events-none absolute inset-x-8 top-0 h-px bg-gradient-to-r from-transparent via-cyan-400/60 to-transparent opacity-0 transition-opacity duration-300 group-hover:opacity-100" />
                        <span :class="s.circle" class="mx-auto mb-4 transition-transform duration-300 group-hover:-translate-y-0.5 group-hover:scale-105">
                            <component :is="s.icon" class="h-5 w-5" />
                        </span>
                        <div class="flex items-baseline justify-center text-4xl font-bold tracking-tight text-white sm:text-5xl">
                            <NumberFlow :value="s.current" />
                            <span class="bg-gradient-to-r from-cyan-400 to-sky-500 bg-clip-text text-transparent">{{ s.suffix }}</span>
                        </div>
                        <div class="mt-2 text-sm font-semibold text-slate-200">{{ $t(`welcome.stat${i}_label`) }}</div>
                        <div class="mt-0.5 text-xs text-slate-500">{{ $t(`welcome.stat${i}_sub`) }}</div>
                        <div v-if="i < displayStats.length - 1" class="absolute inset-y-6 right-0 hidden w-px bg-white/10 lg:block" />
                    </div>
                </div>
            </section>

            <!-- ===== Hardware showcase strip ===== -->
            <section class="border-b border-white/10 bg-slate-950">
                <div class="mx-auto max-w-[1600px] px-4 py-10 sm:px-6 lg:px-8">
                    <div
                        v-spotlight
                        class="kv-spotlight kv-ring group relative overflow-hidden rounded-2xl border border-white/10 bg-slate-900/30 backdrop-blur-xl"
                        data-reveal
                    >
                        <div class="pointer-events-none absolute -right-16 -top-20 h-56 w-56 rounded-full bg-cyan-500/10 blur-3xl" />
                        <div class="pointer-events-none absolute -bottom-20 left-1/3 h-56 w-56 rounded-full bg-purple-500/10 blur-3xl" />
                        <div class="grid items-center gap-8 p-6 md:grid-cols-[1fr_auto] md:gap-12 md:p-10">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-widest text-cyan-400">Multi-Vendor Hardware</p>
                                <h2 class="mt-2 text-2xl font-bold text-white sm:text-3xl">ZTE C-series + C-Data + HiOSO</h2>
                                <p class="mt-3 max-w-xl text-sm text-slate-400">
                                    {{ $t('welcome.hw_desc') }}
                                </p>
                                <div class="mt-5 flex flex-wrap gap-2">
                                    <span class="kv-pill-info">ZTE C300</span>
                                    <span class="kv-pill-info">ZTE C320</span>
                                    <span class="kv-pill-info">ZTE C600</span>
                                    <span class="kv-pill-info">C-Data EPON</span>
                                    <span class="kv-pill-info">C-Data GPON</span>
                                    <span class="kv-pill-info">HiOSO / V-Sol</span>
                                </div>
                            </div>
                            <img
                                src="/img/c320.webp"
                                alt="ZTE OLT hardware"
                                class="kv-float h-20 w-auto object-contain opacity-90 drop-shadow-[0_12px_30px_rgba(56,189,248,0.18)] md:h-24 lg:h-28"
                                loading="lazy"
                            />
                        </div>
                    </div>
                </div>
            </section>

            <!-- ===== Capability marquee ===== -->
            <section class="border-b border-white/10 bg-slate-950/60">
                <div class="kv-marquee-wrap group relative overflow-hidden py-5">
                    <!-- fade tepi kiri/kanan -->
                    <div class="pointer-events-none absolute inset-y-0 left-0 z-10 w-24 bg-gradient-to-r from-slate-950 to-transparent" />
                    <div class="pointer-events-none absolute inset-y-0 right-0 z-10 w-24 bg-gradient-to-l from-slate-950 to-transparent" />
                    <div class="kv-marquee gap-3">
                        <span
                            v-for="(item, i) in [...marqueeItems, ...marqueeItems]"
                            :key="`${item}-${i}`"
                            class="inline-flex items-center gap-2 whitespace-nowrap rounded-full border border-white/10 bg-slate-900/50 px-4 py-2 text-sm font-medium text-slate-300"
                        >
                            <span class="h-1.5 w-1.5 rounded-full bg-cyan-400" />
                            {{ item }}
                        </span>
                    </div>
                </div>
            </section>

            <!-- ===== Feature grid ===== -->
            <section id="fitur" class="mx-auto max-w-[1600px] px-4 py-20 sm:px-6 lg:px-8">
                <div class="mx-auto max-w-2xl text-center" data-reveal>
                    <p class="text-xs font-semibold uppercase tracking-widest text-cyan-400">{{ $t('welcome.features_eyebrow') }}</p>
                    <h2 class="mt-3 text-3xl font-bold text-white sm:text-4xl">{{ $t('welcome.features_title') }}</h2>
                    <p class="mt-4 text-base text-slate-400">{{ $t('welcome.features_sub') }}</p>
                </div>

                <div class="mt-14 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    <div
                        v-for="f in features"
                        :key="f.title"
                        v-tilt="{ strength: 4 }"
                        v-spotlight
                        class="kv-tilt kv-spotlight kv-ring kv-glass-card kv-glass-hover group text-center"
                        data-reveal
                    >
                        <span :class="f.accent" class="mx-auto !h-12 !w-12 transition-transform duration-300 group-hover:scale-110 group-hover:-translate-y-0.5">
                            <component :is="f.icon" class="h-5 w-5" />
                        </span>
                        <h3 class="mt-4 flex items-center justify-center gap-2 text-base font-semibold text-white transition-colors group-hover:text-cyan-300">
                            <span>{{ f.title }}</span>
                            <span
                                v-if="f.badge"
                                class="rounded-full border border-cyan-400/40 bg-cyan-500/15 px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wide text-cyan-300"
                            >{{ f.badge }}</span>
                        </h3>
                        <p class="mt-2 text-sm leading-6 text-slate-400">{{ f.body }}</p>
                    </div>
                </div>

                <!-- Benefit pills strip -->
                <div class="mt-14 flex flex-wrap items-center justify-center gap-3 border-y border-white/10 py-6">
                    <span
                        v-for="b in benefits"
                        :key="b.label"
                        class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-slate-900/60 px-4 py-2 text-xs font-medium text-slate-300 backdrop-blur"
                        data-reveal
                    >
                        <component :is="b.icon" class="h-3.5 w-3.5 text-cyan-400" />
                        {{ b.label }}
                    </span>
                </div>
            </section>

            <!-- ===== How it works ===== -->
            <section id="cara-kerja" class="border-y border-white/10 bg-slate-950">
                <div class="mx-auto max-w-[1600px] px-4 py-20 sm:px-6 lg:px-8">
                    <div class="mx-auto max-w-2xl text-center" data-reveal>
                        <p class="text-xs font-semibold uppercase tracking-widest text-cyan-400">{{ $t('welcome.how_eyebrow') }}</p>
                        <h2 class="mt-3 text-3xl font-bold text-white sm:text-4xl">{{ $t('welcome.how_title') }}</h2>
                        <p class="mt-4 text-base text-slate-400">{{ $t('welcome.how_sub') }}</p>
                    </div>

                    <div class="relative mt-14 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                        <!-- Connector line — digambar mengikuti scroll (lg only) -->
                        <div
                            ref="stepsLineEl"
                            class="pointer-events-none absolute left-0 right-0 top-8 hidden h-px origin-left bg-gradient-to-r from-cyan-500/0 via-cyan-400/50 to-purple-500/0 lg:block"
                        />

                        <div
                            v-for="step in steps"
                            :key="step.n"
                            v-tilt="{ strength: 4 }"
                            v-spotlight
                            class="kv-tilt kv-spotlight kv-ring group relative rounded-2xl border border-white/10 bg-slate-900/40 p-6 shadow-lg shadow-black/30 backdrop-blur-xl transition-colors hover:border-cyan-400/30"
                            data-reveal
                        >
                            <span class="pointer-events-none absolute right-4 top-3 text-4xl font-black text-white/5 transition-colors group-hover:text-cyan-500/10">{{ step.n }}</span>
                            <span class="relative flex h-14 w-14 items-center justify-center rounded-xl border border-cyan-400/30 bg-gradient-to-br from-cyan-500/20 to-sky-600/10 text-cyan-300 shadow-lg shadow-cyan-500/10 transition-transform duration-300 group-hover:-translate-y-0.5 group-hover:scale-105">
                                <component :is="step.icon" class="h-6 w-6" />
                            </span>
                            <h3 class="mt-5 text-base font-semibold text-white">{{ step.title }}</h3>
                            <p class="mt-2 text-sm leading-6 text-slate-400">{{ step.body }}</p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ===== Tampilan aplikasi (galeri) ===== -->
            <section id="tampilan" class="bg-slate-950">
                <div class="mx-auto max-w-[1600px] px-4 py-20 sm:px-6 lg:px-8">
                    <div class="mx-auto max-w-2xl text-center" data-reveal>
                        <p class="text-xs font-semibold uppercase tracking-widest text-cyan-400">{{ $t('welcome.gallery_eyebrow') }}</p>
                        <h2 class="mt-3 text-3xl font-bold text-white sm:text-4xl">{{ $t('welcome.gallery_title') }}</h2>
                        <p class="mt-4 text-base text-slate-400">{{ $t('welcome.gallery_sub') }}</p>
                    </div>

                    <div
                        class="mt-14 grid items-start gap-6 lg:grid-cols-[20rem_1fr]"
                        data-reveal
                        @mouseenter="galleryPaused = true"
                        @mouseleave="galleryPaused = false"
                    >
                        <!-- Tab list -->
                        <div
                            role="tablist"
                            :aria-label="$t('welcome.gallery_aria')"
                            class="flex gap-3 overflow-x-auto pb-2 lg:flex-col lg:gap-2.5 lg:overflow-visible lg:pb-0"
                        >
                            <button
                                v-for="shot in screenshots"
                                :key="shot.key"
                                type="button"
                                role="tab"
                                :aria-selected="activeShot === shot.key"
                                @click="selectShot(shot.key)"
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
                                    <span class="block text-sm font-semibold" :class="activeShot === shot.key ? 'text-white' : 'text-slate-200'">{{ $t(`welcome.shot_${shot.key}_label`) }}</span>
                                    <span class="block truncate text-xs text-slate-400">{{ $t(`welcome.shot_${shot.key}_desc`) }}</span>
                                </span>
                            </button>
                        </div>

                        <!-- Preview frame -->
                        <div class="relative">
                            <div class="absolute -inset-4 rounded-3xl bg-gradient-to-br from-cyan-500/20 via-sky-500/10 to-purple-500/20 blur-2xl" />
                            <div class="relative overflow-hidden rounded-2xl border border-white/10 bg-slate-900/40 shadow-2xl shadow-cyan-500/10 backdrop-blur-xl">
                                <!-- Progress bar autoplay -->
                                <div class="absolute inset-x-0 top-0 z-20 h-0.5 bg-white/5">
                                    <div
                                        :key="activeShot"
                                        class="kv-prog h-full bg-gradient-to-r from-cyan-400 to-sky-500"
                                        :style="{ animationPlayState: galleryPaused ? 'paused' : 'running' }"
                                    />
                                </div>
                                <div class="flex items-center gap-2 border-b border-white/10 bg-slate-950/60 px-4 py-2.5">
                                    <span class="h-3 w-3 rounded-full bg-red-500/70" />
                                    <span class="h-3 w-3 rounded-full bg-amber-500/70" />
                                    <span class="h-3 w-3 rounded-full bg-emerald-500/70" />
                                    <span class="ml-3 flex flex-1 items-center gap-1.5 truncate rounded-md bg-slate-900/60 px-3 py-1 text-xs text-slate-500">
                                        <ShieldCheck class="h-3 w-3 shrink-0 text-emerald-400/70" />
                                        <span class="truncate">{{ currentShot.url }}</span>
                                    </span>
                                </div>
                                <div
                                    class="relative bg-slate-950 transition-[aspect-ratio] duration-300"
                                    :style="{ aspectRatio: currentShot.ratio }"
                                >
                                    <Transition name="kv-fade">
                                        <img
                                            :key="activeShot"
                                            :src="currentShot.src"
                                            :alt="$t(`welcome.shot_${currentShot.key}_label`)"
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
                <div class="mx-auto max-w-[1600px] px-4 py-16 sm:px-6 lg:px-8">
                    <div class="mx-auto max-w-2xl text-center" data-reveal>
                        <p class="text-xs font-semibold uppercase tracking-widest text-cyan-400">Tech Stack</p>
                        <h2 class="mt-3 text-3xl font-bold text-white sm:text-4xl">{{ $t('welcome.tech_title') }}</h2>
                    </div>

                    <div class="mt-10 grid gap-5 sm:grid-cols-2 md:grid-cols-4 lg:grid-cols-4">
                        <div
                            v-for="(t, i) in techStack"
                            :key="t.name"
                            class="kv-ring group relative flex flex-col items-center justify-center rounded-2xl border border-white/10 bg-slate-900/40 px-4 py-8 text-center backdrop-blur-xl transition-all duration-300 hover:-translate-y-1 hover:border-white/25 hover:bg-slate-900/60"
                            :style="{ '--glow-color': t.glow }"
                            data-reveal
                        >
                            <div
                                class="pointer-events-none absolute inset-0 rounded-2xl opacity-0 blur-xl transition-opacity duration-300 group-hover:opacity-100"
                                :style="{ background: `radial-gradient(circle at 50% 30%, ${t.glow}, transparent 70%)` }"
                            />
                            <div
                                class="kv-float relative mb-4 flex h-16 w-16 items-center justify-center transition-transform duration-300 group-hover:scale-110"
                                :style="{ animationDelay: `${i * 0.45}s` }"
                            >
                                <img
                                    :src="t.logo"
                                    :alt="`${t.name} logo`"
                                    class="h-full w-full object-contain drop-shadow-[0_0_12px_var(--glow-color)]"
                                    loading="lazy"
                                />
                            </div>
                            <p class="relative text-sm font-semibold text-white">{{ t.name }}</p>
                            <p class="relative mt-1 text-[11px] text-slate-500">{{ t.sub ?? $t('welcome.tech_flutter_sub') }}</p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ===== Modul lengkap ===== -->
            <section id="modul" class="mx-auto max-w-[1600px] px-4 py-20 sm:px-6 lg:px-8">
                <div class="mx-auto max-w-2xl text-center" data-reveal>
                    <p class="text-xs font-semibold uppercase tracking-widest text-cyan-400">{{ $t('welcome.modules_eyebrow') }}</p>
                    <h2 class="mt-3 text-3xl font-bold text-white sm:text-4xl">{{ $t('welcome.modules_title') }}</h2>
                    <p class="mt-4 text-base text-slate-400">{{ $t('welcome.modules_sub') }}</p>
                </div>

                <div class="mt-12 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div
                        v-for="m in modules"
                        :key="m.title"
                        v-spotlight
                        class="kv-glass-card kv-glass-hover kv-spotlight kv-ring group flex items-start gap-4 transition-transform duration-200 hover:-translate-y-0.5"
                        data-reveal
                    >
                        <span class="kv-circle-cyan !h-11 !w-11 transition-transform duration-300 group-hover:scale-105">
                            <component :is="m.icon" class="h-5 w-5" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center justify-between gap-2">
                                <h3 class="text-sm font-semibold text-white transition-colors group-hover:text-cyan-300">{{ m.title }}</h3>
                                <ChevronRight class="h-4 w-4 flex-shrink-0 text-slate-600 transition-all group-hover:translate-x-0.5 group-hover:text-cyan-400" />
                            </div>
                            <p class="mt-1 text-xs leading-5 text-slate-400">{{ m.sub }}</p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ===== Final CTA ===== -->
            <section class="mx-auto max-w-[1600px] px-4 pb-20 sm:px-6 lg:px-8">
                <div class="kv-ring group relative overflow-hidden rounded-3xl border border-cyan-500/30 bg-gradient-to-br from-cyan-500/10 via-slate-900/40 to-purple-500/10 p-8 backdrop-blur-xl sm:p-12" data-reveal>
                    <div class="pointer-events-none absolute -right-20 -top-20 h-72 w-72 animate-pulse rounded-full bg-cyan-500/20 blur-3xl" />
                    <div class="kv-float pointer-events-none absolute -bottom-20 -left-20 h-72 w-72 rounded-full bg-purple-500/15 blur-3xl" />

                    <div class="relative flex flex-col items-center justify-between gap-6 md:flex-row md:gap-10">
                        <div class="text-center md:text-left">
                            <h2 class="text-2xl font-bold text-white sm:text-3xl">{{ $t('welcome.cta_title') }}</h2>
                            <p class="mt-2 text-sm text-slate-300 sm:text-base">{{ $t('welcome.cta_sub') }}</p>
                        </div>
                        <div class="flex flex-wrap items-center justify-center gap-3">
                            <Link
                                v-if="canLogin && !$page.props.auth.user"
                                v-magnetic
                                :href="route('login')"
                                class="kv-magnetic inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-cyan-500 to-sky-600 px-6 py-3.5 text-sm font-semibold text-white shadow-lg shadow-cyan-500/40 transition hover:shadow-cyan-500/60"
                            >
                                Login
                                <ArrowRight class="h-4 w-4" />
                            </Link>
                            <a
                                href="https://t.me/+RMTs-9c028g0MDdl"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-2 rounded-xl border border-white/15 bg-slate-900/60 px-6 py-3.5 text-sm font-semibold text-slate-100 backdrop-blur transition hover:border-white/25 hover:bg-slate-800/80"
                            >
                                <Send class="h-4 w-4" />
                                {{ $t('welcome.contact_us') }}
                            </a>
                        </div>
                    </div>
                </div>
            </section>
        </main>

        <!-- ===== Footer ===== -->
        <footer id="kontak" class="border-t border-white/10 bg-slate-950">
            <div class="mx-auto max-w-[1600px] px-4 py-12 sm:px-6 lg:px-8">
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
                            {{ $t('welcome.footer_desc') }}
                        </p>
                    </div>

                    <div>
                        <h4 class="text-sm font-semibold text-white">{{ $t('welcome.footer_product') }}</h4>
                        <ul class="mt-4 space-y-2.5 text-sm">
                            <li v-for="l in productLinks" :key="l.label">
                                <a :href="l.href" class="text-slate-400 transition-colors hover:text-cyan-400" @click="scrollToHash($event, l.href)">{{ l.label }}</a>
                            </li>
                        </ul>
                    </div>

                    <div>
                        <h4 class="text-sm font-semibold text-white">{{ $t('welcome.footer_company') }}</h4>
                        <ul class="mt-4 space-y-2.5 text-sm">
                            <li v-for="l in companyLinks" :key="l.label">
                                <a :href="l.href" class="text-slate-400 transition-colors hover:text-cyan-400">{{ l.label }}</a>
                            </li>
                        </ul>
                    </div>

                    <div>
                        <h4 class="text-sm font-semibold text-white">{{ $t('welcome.footer_contact') }}</h4>
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
                                <Send class="mt-0.5 h-4 w-4 flex-shrink-0 text-cyan-400" />
                                <a
                                    href="https://t.me/+RMTs-9c028g0MDdl"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="hover:text-cyan-400"
                                >Grup Telegram · KusumaVisionNMS-Share</a>
                            </li>
                            <li class="flex items-start gap-2">
                                <svg
                                    class="mt-0.5 h-4 w-4 flex-shrink-0 text-cyan-400"
                                    viewBox="0 0 16 16"
                                    fill="currentColor"
                                    aria-hidden="true"
                                >
                                    <path
                                        d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0 0 16 8c0-4.42-3.58-8-8-8Z"
                                    />
                                </svg>
                                <a
                                    href="https://github.com/Masamune21-dev/KusumaVisionNMS"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="hover:text-cyan-400"
                                >Masamune21-dev/KusumaVisionNMS</a>
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="mt-10 flex flex-col items-center justify-between gap-3 border-t border-white/10 pt-6 text-xs text-slate-500 sm:flex-row">
                    <p>{{ $t('welcome.footer_made') }}</p>
                    <p>ZTE OLT Management &amp; Provisioning Platform</p>
                </div>
            </div>
        </footer>
    </div>
</template>

<style scoped>
/* === Hero intro: animasi CSS murni (tidak bergantung GSAP) === */
.reveal-hero {
    animation: kv-hero-in 0.7s cubic-bezier(0.16, 1, 0.3, 1) both;
    will-change: opacity, transform;
}
@keyframes kv-hero-in {
    from {
        opacity: 0;
        transform: translateY(22px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* === Scroll reveal: keadaan awal tersembunyi (di-reveal oleh GSAP) === */
[data-reveal] {
    opacity: 0;
    transform: translateY(26px);
    will-change: opacity, transform;
}

/* === Tilt & magnetic: smoothing transform === */
.kv-tilt {
    transform-style: preserve-3d;
    transition: transform 0.3s ease-out;
    will-change: transform;
}
.kv-tilt [data-depth] {
    transition: transform 0.3s ease-out;
}
.kv-magnetic {
    transition:
        transform 0.25s cubic-bezier(0.33, 1, 0.68, 1),
        box-shadow 0.2s ease;
    will-change: transform;
}

/* Crossfade antar screenshot di galeri "Tampilan Aplikasi" */
.kv-fade-enter-active,
.kv-fade-leave-active {
    transition: opacity 250ms ease;
}
.kv-fade-enter-from,
.kv-fade-leave-to {
    opacity: 0;
}

/* Progress bar autoplay galeri (restart via :key, durasi = GALLERY_MS) */
.kv-prog {
    transform-origin: left;
    animation: kv-prog 5000ms linear forwards;
}
@keyframes kv-prog {
    from {
        transform: scaleX(0);
    }
    to {
        transform: scaleX(1);
    }
}

/* Aksesibilitas: hormati pengguna yang mengurangi animasi. */
@media (prefers-reduced-motion: reduce) {
    .reveal-hero {
        animation: none;
    }
    [data-reveal] {
        opacity: 1 !important;
        transform: none !important;
    }
    .kv-tilt,
    .kv-magnetic,
    .kv-tilt [data-depth] {
        transition: none;
    }
    .kv-fade-enter-active,
    .kv-fade-leave-active {
        transition: none;
    }
    .kv-prog {
        animation: none;
        transform: scaleX(1);
    }
}
</style>
