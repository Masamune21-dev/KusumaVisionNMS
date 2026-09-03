<script setup>
/**
 * ParticleNetwork — latar belakang partikel yang saling terhubung garis,
 * menyerupai topologi fiber/GPON. Dibangun di atas tsParticles (slim bundle).
 * Reaktif ke kursor (mode "grab") dan menghormati prefers-reduced-motion.
 */
import { onBeforeUnmount, onMounted, ref } from 'vue';
// Singleton engine + id unik hidup di module eksternal — BUKAN di <script setup>,
// karena isi <script setup> adalah badan setup() yang dieksekusi ulang tiap mount
// (sehingga singleton apa pun di sini akan ter-reset tiap navigasi → loadSlim
// terpanggil lagi → register throw). Lihat resources/js/lib/particles.js.
import { ensureParticlesEngine, nextParticlesId, tsParticles } from '@/lib/particles';
import { isLowPowerDevice, prefersReducedMotion } from '@/lib/perf';

const props = defineProps({
    // id dasar — dibedakan otomatis per mount, jadi cukup deskriptif saja.
    id: { type: String, default: 'kv-particles' },
    // jumlah node — turunkan di area kecil untuk hemat resource.
    quantity: { type: Number, default: 64 },
    // warna garis penghubung & node.
    linkColor: { type: String, default: '#38bdf8' },
    // Efek "grab" mengikuti kursor. Wajib false untuk latar yang menutupi
    // elemen interaktif (mis. app shell): tsParticles memaksa
    // `pointer-events: initial` pada canvas-nya saat hover aktif.
    interactive: { type: Boolean, default: true },
});

const el = ref(null);
// id unik untuk mount ini (DOM + registry tsParticles).
const uid = nextParticlesId(props.id);
let container = null;
let destroyed = false;

onMounted(async () => {
    // Hormati pengguna yang mengurangi animasi, dan lewati sepenuhnya di
    // perangkat kelas bawah — merasterisasi canvas full-bleed 60fps adalah
    // beban terbesar halaman ini (≈74% CPU idle terukur), dan di GPU lemah
    // itulah yang membuat scroll tersendat. Latar tetap rapi tanpa canvas.
    if (prefersReducedMotion() || isLowPowerDevice() || !el.value) return;

    // Daftarkan engine sekali saja (idempoten lintas mount, dari module singleton).
    await ensureParticlesEngine();

    // Komponen bisa keburu unmount selama await di atas (navigasi cepat).
    if (destroyed || !el.value) return;

    container = await tsParticles.load({
        id: uid,
        element: el.value,
        options: {
            fullScreen: { enable: false },
            // Partikel bergerak sangat lambat (speed 0.7); 30fps tidak terlihat
            // berbeda tapi memangkas separuh kerja rasterisasi.
            fpsLimit: 30,
            // Di Retina, detectRetina menggambar canvas pada 2x -> 4x piksel per
            // frame. Untuk titik lembut & garis tipis selisihnya tak kasat mata.
            detectRetina: false,
            // hdr default true di engine v4; tidak berguna untuk latar dekoratif.
            hdr: false,
            background: { color: 'transparent' },
            particles: {
                number: {
                    value: props.quantity,
                    density: { enable: true, width: 1200, height: 900 },
                },
                color: { value: ['#22d3ee', '#38bdf8', '#64748b'] },
                links: {
                    enable: true,
                    color: props.linkColor,
                    distance: 150,
                    opacity: 0.22,
                    width: 1,
                },
                move: {
                    enable: true,
                    speed: 0.7,
                    direction: 'none',
                    random: true,
                    straight: false,
                    outModes: { default: 'bounce' },
                },
                opacity: {
                    value: { min: 0.25, max: 0.6 },
                    animation: { enable: true, speed: 0.4, sync: false },
                },
                size: { value: { min: 1, max: 2.6 } },
            },
            interactivity: {
                // 'window' membuat SETIAP mousemove di halaman (termasuk saat
                // hero sudah jauh di atas) memicu hitung ulang link grab untuk
                // semua partikel. Batasi ke area canvas-nya sendiri.
                detectsOn: 'canvas',
                events: {
                    onHover: { enable: props.interactive, mode: 'grab' },
                    onClick: { enable: false },
                    resize: { enable: true },
                },
                modes: {
                    grab: { distance: 170, links: { opacity: 0.5 } },
                },
            },
        },
    });

    // Kalau sudah keburu unmount saat load() selesai, langsung bersihkan.
    if (destroyed) {
        container?.destroy();
        container = null;
    }
});

onBeforeUnmount(() => {
    destroyed = true;
    container?.destroy();
    container = null;
});
</script>

<template>
    <div
        :id="uid"
        ref="el"
        class="pointer-events-none absolute inset-0 h-full w-full"
        :class="{ 'kv-particles--inert': !interactive }"
        aria-hidden="true"
    />
</template>

<style scoped>
/* tsParticles menulis inline `pointer-events: initial` ke <canvas> miliknya
 * (InteractivityEventListeners -> canvas.setPointerEvents) sehingga menembus
 * `pointer-events-none` milik wrapper. Declaration !important dari stylesheet
 * mengalahkan inline non-important, jadi latar dekoratif ini tidak pernah
 * menelan klik elemen di bawahnya. */
.kv-particles--inert :deep(canvas) {
    pointer-events: none !important;
}
</style>
