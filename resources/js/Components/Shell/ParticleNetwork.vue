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

const props = defineProps({
    // id dasar — dibedakan otomatis per mount, jadi cukup deskriptif saja.
    id: { type: String, default: 'kv-particles' },
    // jumlah node — turunkan di area kecil untuk hemat resource.
    quantity: { type: Number, default: 64 },
    // warna garis penghubung & node.
    linkColor: { type: String, default: '#38bdf8' },
});

const el = ref(null);
// id unik untuk mount ini (DOM + registry tsParticles).
const uid = nextParticlesId(props.id);
let container = null;
let destroyed = false;

const reduceMotion = () =>
    typeof window !== 'undefined' &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

onMounted(async () => {
    // Hormati pengguna yang mengurangi animasi — biarkan latar statis.
    if (reduceMotion() || !el.value) return;

    // Daftarkan engine sekali saja (idempoten lintas mount, dari module singleton).
    await ensureParticlesEngine();

    // Komponen bisa keburu unmount selama await di atas (navigasi cepat).
    if (destroyed || !el.value) return;

    container = await tsParticles.load({
        id: uid,
        element: el.value,
        options: {
            fullScreen: { enable: false },
            fpsLimit: 60,
            detectRetina: true,
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
                detectsOn: 'window',
                events: {
                    onHover: { enable: true, mode: 'grab' },
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
        aria-hidden="true"
    />
</template>
