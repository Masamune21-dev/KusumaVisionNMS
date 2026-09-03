/**
 * Deteksi kapabilitas perangkat untuk menurunkan (degrade) efek visual berat.
 *
 * Landing page memakai canvas partikel, tilt 3D, dan smooth-scroll yang biayanya
 * dibayar per-frame di GPU/compositor — bukan di eksekusi JS. Pada mesin dengan
 * GPU terintegrasi atau core sedikit, biaya itu terasa sebagai scroll tersendat.
 * Helper ini dipakai untuk mematikan efek paling mahal di perangkat tersebut
 * tanpa mengubah tampilan di mesin yang sanggup.
 */

/** Pengguna meminta animasi dikurangi lewat setelan OS. */
export function prefersReducedMotion() {
    return (
        typeof window !== 'undefined' &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches
    );
}

/**
 * Perangkat kelas bawah: core sedikit, RAM kecil, atau layar sentuh tanpa mouse.
 * navigator.deviceMemory hanya ada di Chromium — kalau undefined kita tidak
 * menganggapnya sinyal negatif (Safari/Firefox dinilai dari core saja).
 */
export function isLowPowerDevice() {
    if (typeof navigator === 'undefined') return false;

    const cores = navigator.hardwareConcurrency ?? 8;
    const memory = navigator.deviceMemory; // GB, Chromium-only
    const coarsePointer =
        typeof window !== 'undefined' &&
        window.matchMedia('(pointer: coarse)').matches;

    return cores <= 4 || (memory !== undefined && memory <= 4) || coarsePointer;
}
