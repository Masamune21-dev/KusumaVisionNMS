<script>
// Guard modul-level: satu objek `flash` hanya ditoast SEKALI. Layout ini
// non-persistent, jadi tiap visit instance FlashMessages baru memanggil pump()
// di onMounted SEKALIGUS listener router.on('success')-nya menyala untuk visit
// yang sama → tanpa guard, toast muncul dobel. `page.props.flash` stabil
// (referensi sama) dalam satu visit, jadi cukup bandingkan identitas objek.
let lastFlashSeen = null;
</script>

<script setup>
/**
 * FlashMessages — toast terpusat untuk flash `success`/`error` dari Inertia.
 * Menggantikan blok flash inline yang dulu diduplikasi di tiap halaman.
 * Non-blocking, auto-dismiss, bisa ditutup manual, dan diumumkan ke screen
 * reader (aria-live polite untuk sukses, role="alert" untuk error).
 */
import { nextTick, onMounted, onUnmounted, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { CheckCircle2, TriangleAlert, X } from '@lucide/vue';

const DISMISS_MS = { success: 5000, error: 8000 };

const page = usePage();
const toasts = ref([]);
let seq = 0;
const timers = new Map();

const remove = (id) => {
    toasts.value = toasts.value.filter((t) => t.id !== id);
    const timer = timers.get(id);
    if (timer) {
        clearTimeout(timer);
        timers.delete(id);
    }
};

const push = (type, message) => {
    if (!message) return;
    const id = ++seq;
    toasts.value.push({ id, type, message });
    timers.set(id, setTimeout(() => remove(id), DISMISS_MS[type] ?? 5000));
};

// Baca flash saat ini (initial load + tiap kunjungan Inertia sukses).
const pump = () => {
    const flash = page.props.flash;
    // Objek flash yang sama (visit yang sama) — sudah ditoast, lewati.
    if (flash === lastFlashSeen) return;
    lastFlashSeen = flash;
    if (!flash) return;
    push('success', flash.success);
    push('error', flash.error);
};

let stopListening = null;

onMounted(() => {
    pump();
    // `success` hanya terpicu pada kunjungan berikutnya, bukan initial mount —
    // jadi tidak ada toast ganda untuk flash awal.
    stopListening = router.on('success', () => nextTick(pump));
});

onUnmounted(() => {
    stopListening?.();
    timers.forEach((t) => clearTimeout(t));
    timers.clear();
});
</script>

<template>
    <div
        class="pointer-events-none fixed inset-x-0 top-16 z-[70] flex flex-col items-center gap-2 px-4 sm:inset-x-auto sm:right-4 sm:top-20 sm:items-end sm:px-0"
        aria-live="polite"
        aria-atomic="false"
    >
        <TransitionGroup
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0 -translate-y-2 sm:translate-y-0 sm:translate-x-4"
            enter-to-class="opacity-100 translate-y-0 sm:translate-x-0"
            leave-active-class="transition duration-150 ease-in absolute"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0 sm:translate-x-4"
            move-class="transition-transform duration-200"
        >
            <div
                v-for="toast in toasts"
                :key="toast.id"
                :role="toast.type === 'error' ? 'alert' : 'status'"
                class="pointer-events-auto flex w-full max-w-md items-start gap-3 rounded-xl border px-4 py-3 text-sm shadow-lg shadow-black/40 backdrop-blur-xl sm:w-auto sm:min-w-[18rem]"
                :class="toast.type === 'error'
                    ? 'border-red-500/30 bg-red-950/70 text-red-200'
                    : 'border-emerald-500/30 bg-emerald-950/70 text-emerald-200'"
            >
                <CheckCircle2 v-if="toast.type === 'success'" class="mt-0.5 h-5 w-5 flex-shrink-0 text-emerald-400" />
                <TriangleAlert v-else class="mt-0.5 h-5 w-5 flex-shrink-0 text-red-400" />
                <span class="min-w-0 flex-1 break-words">{{ toast.message }}</span>
                <button
                    type="button"
                    class="-mr-1 -mt-0.5 flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-md opacity-70 transition hover:bg-white/10 hover:opacity-100"
                    aria-label="Tutup notifikasi"
                    @click="remove(toast.id)"
                >
                    <X class="h-4 w-4" />
                </button>
            </div>
        </TransitionGroup>
    </div>
</template>
