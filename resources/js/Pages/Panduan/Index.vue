<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    BellRing, BookOpen, Cable, Compass, FileBarChart, KeyRound, LayoutDashboard,
    LifeBuoy, ListChecks, MapPin, PlugZap, Radar, Rocket, ScrollText, Send,
    ShieldCheck, Smartphone, Sparkles, Terminal, Users, Wrench, WifiOff,
} from '@lucide/vue';

const page = usePage();
const appName = computed(() => page.props.branding?.name ?? 'KusumaVision NMS');

/*
 * Preset warna aksen per-bagian. Kelas ditulis sebagai string literal penuh
 * (bukan interpolasi `bg-${c}-...`) supaya JIT Tailwind memindainya — jangan
 * mengubah jadi template dinamis atau kelasnya akan ter-purge.
 */
const ACCENTS = {
    cyan: { icon: 'text-cyan-300', tile: 'bg-cyan-500/15 text-cyan-300 ring-cyan-500/30', num: 'text-cyan-300', bullet: 'bg-cyan-400/80', step: 'bg-cyan-500/15 text-cyan-200 ring-cyan-500/30', active: 'bg-cyan-500/10 text-cyan-100 ring-cyan-500/40', bar: 'from-cyan-500/70' },
    sky: { icon: 'text-sky-300', tile: 'bg-sky-500/15 text-sky-300 ring-sky-500/30', num: 'text-sky-300', bullet: 'bg-sky-400/80', step: 'bg-sky-500/15 text-sky-200 ring-sky-500/30', active: 'bg-sky-500/10 text-sky-100 ring-sky-500/40', bar: 'from-sky-500/70' },
    blue: { icon: 'text-blue-300', tile: 'bg-blue-500/15 text-blue-300 ring-blue-500/30', num: 'text-blue-300', bullet: 'bg-blue-400/80', step: 'bg-blue-500/15 text-blue-200 ring-blue-500/30', active: 'bg-blue-500/10 text-blue-100 ring-blue-500/40', bar: 'from-blue-500/70' },
    teal: { icon: 'text-teal-300', tile: 'bg-teal-500/15 text-teal-300 ring-teal-500/30', num: 'text-teal-300', bullet: 'bg-teal-400/80', step: 'bg-teal-500/15 text-teal-200 ring-teal-500/30', active: 'bg-teal-500/10 text-teal-100 ring-teal-500/40', bar: 'from-teal-500/70' },
    emerald: { icon: 'text-emerald-300', tile: 'bg-emerald-500/15 text-emerald-300 ring-emerald-500/30', num: 'text-emerald-300', bullet: 'bg-emerald-400/80', step: 'bg-emerald-500/15 text-emerald-200 ring-emerald-500/30', active: 'bg-emerald-500/10 text-emerald-100 ring-emerald-500/40', bar: 'from-emerald-500/70' },
    violet: { icon: 'text-violet-300', tile: 'bg-violet-500/15 text-violet-300 ring-violet-500/30', num: 'text-violet-300', bullet: 'bg-violet-400/80', step: 'bg-violet-500/15 text-violet-200 ring-violet-500/30', active: 'bg-violet-500/10 text-violet-100 ring-violet-500/40', bar: 'from-violet-500/70' },
    fuchsia: { icon: 'text-fuchsia-300', tile: 'bg-fuchsia-500/15 text-fuchsia-300 ring-fuchsia-500/30', num: 'text-fuchsia-300', bullet: 'bg-fuchsia-400/80', step: 'bg-fuchsia-500/15 text-fuchsia-200 ring-fuchsia-500/30', active: 'bg-fuchsia-500/10 text-fuchsia-100 ring-fuchsia-500/40', bar: 'from-fuchsia-500/70' },
    amber: { icon: 'text-amber-300', tile: 'bg-amber-500/15 text-amber-300 ring-amber-500/30', num: 'text-amber-300', bullet: 'bg-amber-400/80', step: 'bg-amber-500/15 text-amber-200 ring-amber-500/30', active: 'bg-amber-500/10 text-amber-100 ring-amber-500/40', bar: 'from-amber-500/70' },
    orange: { icon: 'text-orange-300', tile: 'bg-orange-500/15 text-orange-300 ring-orange-500/30', num: 'text-orange-300', bullet: 'bg-orange-400/80', step: 'bg-orange-500/15 text-orange-200 ring-orange-500/30', active: 'bg-orange-500/10 text-orange-100 ring-orange-500/40', bar: 'from-orange-500/70' },
    rose: { icon: 'text-rose-300', tile: 'bg-rose-500/15 text-rose-300 ring-rose-500/30', num: 'text-rose-300', bullet: 'bg-rose-400/80', step: 'bg-rose-500/15 text-rose-200 ring-rose-500/30', active: 'bg-rose-500/10 text-rose-100 ring-rose-500/40', bar: 'from-rose-500/70' },
    indigo: { icon: 'text-indigo-300', tile: 'bg-indigo-500/15 text-indigo-300 ring-indigo-500/30', num: 'text-indigo-300', bullet: 'bg-indigo-400/80', step: 'bg-indigo-500/15 text-indigo-200 ring-indigo-500/30', active: 'bg-indigo-500/10 text-indigo-100 ring-indigo-500/40', bar: 'from-indigo-500/70' },
};

/*
 * Panduan penggunaan — struktur section (ikon/aksen/urutan) di sini; seluruh
 * teks di lang/{id,en}.json namespace `panduan.*` dengan key flat per-section:
 * `{id}_title`, `{id}_intro`, `{id}_tip` (bila tip: true), butir `{id}_i{n}`
 * (+ `{id}_i{n}s` untuk kata kunci tebal). `items` = daftar boolean: true bila
 * butir ke-n punya kata kunci tebal (strong).
 */
const SECTION_DEFS = [
    { id: 'pengantar', icon: Rocket, accent: 'cyan', ordered: false, items: [true, true, true], tip: true },
    { id: 'peran', icon: ShieldCheck, accent: 'emerald', ordered: false, items: [true, true, true, true] },
    { id: 'navigasi', icon: Compass, accent: 'sky', ordered: false, items: [true, true, true, true] },
    { id: 'dashboard', icon: LayoutDashboard, accent: 'violet', ordered: false, items: [false, false, false] },
    { id: 'olt', icon: Cable, accent: 'blue', ordered: true, items: [true, true, true, true], tip: true },
    { id: 'port-onu', icon: Radar, accent: 'teal', ordered: false, items: [true, true, true] },
    { id: 'unconfigured', icon: WifiOff, accent: 'amber', ordered: true, items: [false, false] },
    { id: 'provisioning', icon: PlugZap, accent: 'fuchsia', ordered: true, items: [true, true, true, true], tip: true },
    { id: 'aksi-onu', icon: Wrench, accent: 'orange', ordered: false, items: [true, true, true, true, true] },
    { id: 'monitoring', icon: ListChecks, accent: 'cyan', ordered: false, items: [false, false] },
    { id: 'peta', icon: MapPin, accent: 'rose', ordered: true, items: [true, true, true] },
    { id: 'alarm', icon: BellRing, accent: 'amber', ordered: false, items: [true, true, true, true], tip: true },
    { id: 'telnet', icon: Terminal, accent: 'indigo', ordered: false, items: [false, false] },
    { id: 'report', icon: FileBarChart, accent: 'emerald', ordered: false, items: [true, true, true] },
    { id: 'pengaturan', icon: KeyRound, accent: 'sky', badges: ['Admin'], ordered: false, items: [true, true, true, true, true, true] },
    { id: 'users', icon: Users, accent: 'violet', badges: ['Admin'], ordered: false, items: [true, true] },
    { id: 'partner', icon: Send, accent: 'fuchsia', badges: ['Partner'], ordered: false, items: [true, true] },
    { id: 'mobile', icon: Smartphone, accent: 'teal', ordered: false, items: [false, false] },
    { id: 'troubleshooting', icon: LifeBuoy, accent: 'rose', ordered: false, items: [true, true, true, true] },
];

const { t } = useI18n({ useScope: 'global' });

// Teks dirakit reaktif dari i18n agar ikut berganti saat switch bahasa.
const sections = computed(() =>
    SECTION_DEFS.map((def) => ({
        ...def,
        title: t(`panduan.${def.id}_title`),
        intro: t(`panduan.${def.id}_intro`),
        tip: def.tip ? t(`panduan.${def.id}_tip`) : null,
        list: def.items.map((hasStrong, i) => ({
            strong: hasStrong ? t(`panduan.${def.id}_i${i}s`) : null,
            text: t(`panduan.${def.id}_i${i}`),
        })),
    })),
);

const accentOf = (sec) => ACCENTS[sec.accent] ?? ACCENTS.cyan;

const activeId = ref(SECTION_DEFS[0].id);
const activeIndex = computed(() => Math.max(0, SECTION_DEFS.findIndex((s) => s.id === activeId.value)));
const scrollTo = (id) => {
    activeId.value = id;
    document.getElementById(`sec-${id}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
};

// Scroll-spy: sorot bagian yang sedang di sekitar sepertiga atas viewport.
let observer = null;
onMounted(() => {
    observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((e) => {
                if (e.isIntersecting) activeId.value = e.target.id.replace('sec-', '');
            });
        },
        { rootMargin: '-25% 0px -65% 0px', threshold: 0 },
    );
    SECTION_DEFS.forEach((s) => {
        const el = document.getElementById(`sec-${s.id}`);
        if (el) observer.observe(el);
    });
});
onBeforeUnmount(() => observer?.disconnect());
</script>

<template>
    <Head :title="$t('panduan.title')" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-lg font-semibold leading-tight text-white sm:text-xl">{{ $t('panduan.title') }}</h2>
        </template>

        <div class="min-h-[60vh] pt-5 pb-16 sm:pt-8">
            <div class="w-full px-4 sm:px-6 lg:px-8">
                <!-- Hero -->
                <div class="relative mb-7 overflow-hidden rounded-2xl border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
                    <div class="pointer-events-none absolute -right-20 -top-24 h-72 w-72 rounded-full bg-cyan-500/20 blur-3xl"></div>
                    <div class="pointer-events-none absolute -bottom-24 left-10 h-56 w-56 rounded-full bg-violet-500/15 blur-3xl"></div>
                    <div class="relative p-6 sm:p-8">
                        <span class="inline-flex items-center gap-1.5 rounded-full border border-cyan-500/30 bg-cyan-500/10 px-3 py-1 text-xs font-medium text-cyan-200">
                            <Sparkles class="h-3.5 w-3.5" /> {{ $t('panduan.hero_badge') }}
                        </span>
                        <h1 class="mt-3 text-2xl font-bold tracking-tight sm:text-3xl">
                            <span class="bg-gradient-to-r from-white via-cyan-100 to-sky-300 bg-clip-text text-transparent">{{ $t('panduan.hero_title', { app: appName }) }}</span>
                        </h1>
                        <p class="mt-2 max-w-2xl text-sm leading-relaxed text-slate-300 sm:text-base">
                            {{ $t('panduan.hero_desc') }}
                        </p>
                        <div class="mt-5 flex flex-wrap items-center gap-2.5">
                            <span class="inline-flex items-center gap-1.5 rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-slate-200">
                                <BookOpen class="h-3.5 w-3.5 text-cyan-300" /> {{ $t('panduan.hero_topics', { n: sections.length }) }}
                            </span>
                            <span class="inline-flex items-center gap-1.5 rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-slate-200">
                                <Users class="h-3.5 w-3.5 text-emerald-300" /> {{ $t('panduan.hero_roles') }}
                            </span>
                            <span class="hidden items-center gap-1.5 rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-slate-200 sm:inline-flex">
                                <kbd class="rounded border border-white/15 bg-slate-800 px-1.5 py-0.5 font-mono text-[10px] text-slate-300">⌘K</kbd> {{ $t('panduan.hero_search') }}
                            </span>
                        </div>
                    </div>
                </div>

                <div class="grid gap-6 lg:grid-cols-[268px_minmax(0,1fr)]">
                    <!-- Daftar isi (sticky di bawah header desktop 72px) -->
                    <aside class="lg:sticky lg:top-[84px] lg:self-start">
                        <div class="rounded-2xl border border-white/10 bg-slate-900/40 p-3 shadow-lg shadow-black/30 backdrop-blur-xl">
                            <div class="flex items-center justify-between px-2 pb-2 pt-1">
                                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $t('panduan.toc') }}</p>
                                <span class="rounded-full bg-white/5 px-2 py-0.5 text-[10px] font-medium text-slate-400">{{ activeIndex + 1 }}/{{ sections.length }}</span>
                            </div>
                            <nav class="flex flex-row flex-wrap gap-1 lg:max-h-[calc(100vh-7rem)] lg:flex-col lg:flex-nowrap lg:overflow-y-auto lg:pr-1">
                                <button
                                    v-for="(sec, idx) in sections"
                                    :key="sec.id"
                                    type="button"
                                    class="group flex items-center gap-2.5 rounded-lg px-2.5 py-2 text-left text-sm ring-1 ring-transparent transition-all duration-150"
                                    :class="activeId === sec.id ? accentOf(sec).active : 'text-slate-300 hover:bg-white/5 hover:text-white'"
                                    @click="scrollTo(sec.id)"
                                >
                                    <component :is="sec.icon" class="h-4 w-4 flex-shrink-0" :class="activeId === sec.id ? accentOf(sec).icon : 'text-slate-500 group-hover:text-slate-300'" />
                                    <span class="hidden w-4 flex-shrink-0 text-right text-xs tabular-nums text-slate-500 sm:inline">{{ idx + 1 }}</span>
                                    <span class="truncate">{{ sec.title }}</span>
                                </button>
                            </nav>
                        </div>
                    </aside>

                    <!-- Konten -->
                    <div class="space-y-5">
                        <section
                            v-for="(sec, idx) in sections"
                            :id="`sec-${sec.id}`"
                            :key="sec.id"
                            class="kv-spotlight kv-ring group relative scroll-mt-24 overflow-hidden rounded-2xl border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl transition-all duration-200 hover:border-white/20"
                        >
                            <!-- Aksen atas per-bagian -->
                            <div class="h-1 bg-gradient-to-r to-transparent" :class="accentOf(sec).bar"></div>

                            <div class="flex items-center gap-3.5 border-b border-white/10 px-5 py-4 sm:px-6">
                                <div class="flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-xl ring-1" :class="accentOf(sec).tile">
                                    <component :is="sec.icon" class="h-5 w-5" />
                                </div>
                                <div class="flex min-w-0 flex-1 flex-col gap-1.5">
                                    <div class="flex items-baseline gap-2">
                                        <span class="text-sm font-bold tabular-nums" :class="accentOf(sec).num">{{ String(idx + 1).padStart(2, '0') }}</span>
                                        <h3 class="text-base font-semibold leading-snug text-white sm:text-lg">{{ sec.title }}</h3>
                                    </div>
                                    <div v-if="(sec.badges ?? []).length" class="flex flex-wrap gap-1.5">
                                        <span
                                            v-for="badge in sec.badges"
                                            :key="badge"
                                            class="rounded-full bg-amber-500/15 px-2 py-0.5 text-xs font-medium text-amber-300 ring-1 ring-amber-500/30"
                                        >
                                            {{ $t('panduan.badge_only', { badge }) }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div class="px-5 py-5 sm:px-6">
                                <p class="text-sm leading-relaxed text-slate-300">{{ sec.intro }}</p>

                                <component
                                    :is="sec.ordered ? 'ol' : 'ul'"
                                    class="mt-4 space-y-3"
                                >
                                    <li
                                        v-for="(item, i) in sec.list"
                                        :key="i"
                                        class="flex gap-3 text-sm leading-relaxed text-slate-300"
                                    >
                                        <span
                                            v-if="sec.ordered"
                                            class="mt-0.5 flex h-5 w-5 flex-shrink-0 items-center justify-center rounded-full text-[11px] font-bold tabular-nums ring-1"
                                            :class="accentOf(sec).step"
                                        >{{ i + 1 }}</span>
                                        <span v-else class="mt-2 h-1.5 w-1.5 flex-shrink-0 rounded-full" :class="accentOf(sec).bullet"></span>
                                        <span>
                                            <span v-if="item.strong" class="font-semibold text-white">{{ item.strong }} — </span>{{ item.text }}
                                        </span>
                                    </li>
                                </component>

                                <div v-if="sec.tip" class="mt-5 flex items-start gap-2.5 rounded-xl border border-cyan-500/25 bg-cyan-500/[0.07] px-4 py-3">
                                    <LifeBuoy class="mt-0.5 h-4 w-4 flex-shrink-0 text-cyan-300" />
                                    <p class="text-xs leading-relaxed text-cyan-100/90"><span class="font-semibold text-cyan-200">{{ $t('panduan.tips_label') }}</span> {{ sec.tip }}</p>
                                </div>
                            </div>
                        </section>

                        <!-- Penutup -->
                        <div class="flex items-center gap-3 rounded-2xl border border-white/10 bg-slate-900/40 px-5 py-4 text-sm text-slate-400 shadow-lg shadow-black/30 backdrop-blur-xl">
                            <div class="kv-icon-tile-sm"><ScrollText class="h-4 w-4" /></div>
                            <p>{{ $t('panduan.footer_before') }} <span class="font-mono text-xs text-slate-300">docs/handbook/</span>{{ $t('panduan.footer_after') }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
