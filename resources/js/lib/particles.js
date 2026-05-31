/**
 * Singleton engine tsParticles.
 *
 * PENTING: state di sini HARUS hidup di module scope (bukan di dalam
 * <script setup> komponen, yang sebenarnya badan fungsi setup() dan dieksekusi
 * ulang tiap komponen mount). tsParticles hanya mengizinkan loadSlim()/register
 * dipanggil SEKALI sebelum load() pertama; setelah itu register melempar
 * "Register plugins can only be done before calling tsParticles.load()".
 *
 * Karena layout app ini non-persistent (komponen partikel re-mount tiap navigasi
 * Inertia), kita cache promise loadSlim di sini supaya plugin hanya didaftarkan
 * sekali seumur tab — mount berikutnya cukup menunggu promise yang sama.
 *
 * tsParticles/slim di-import di module ini, dan module ini hanya di-import oleh
 * ParticleNetwork.vue yang sendirinya lazy (defineAsyncComponent). Jadi engine
 * tetap masuk chunk async — tidak mencemari manifest page Inertia.
 */
import { tsParticles } from '@tsparticles/engine';
import { loadSlim } from '@tsparticles/slim';

let enginePromise = null;
let instanceSeq = 0;

/** Daftarkan engine sekali saja (idempoten lintas mount). */
export function ensureParticlesEngine() {
    if (!enginePromise) {
        enginePromise = loadSlim(tsParticles);
    }
    return enginePromise;
}

/** Id unik per pemanggilan agar instance (DOM + registry) tidak pernah bentrok. */
export function nextParticlesId(prefix = 'kv-particles') {
    return `${prefix}-${++instanceSeq}`;
}

export { tsParticles };
