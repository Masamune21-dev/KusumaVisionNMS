/**
 * Efek mengetik berulang untuk terminal hero di landing page.
 *
 * Pengganti typed.js sejak 2 Okt 2026: typed.js 3 berlisensi GPL-3.0, tidak
 * cocok dengan repo MIT. Perilakunya sama dengan konfigurasi yang dulu
 * dipakai: ketik tiap string, jeda, lalu hapus HANYA sampai awalan yang sama
 * dengan string berikutnya ("smart backspace"), berulang tanpa akhir.
 *
 * Modul ini tidak menyentuh DOM — teks diserahkan lewat `onText`, pemanggil
 * yang merendernya (cukup satu ref).
 *
 * @param {string[]} strings
 * @param {(text: string) => void} onText
 */
export function createTypewriter(strings, onText, {
    typeSpeed = 45,
    backSpeed = 18,
    backDelay = 1700,
    startDelay = 500,
    // Jeda ketik acak +0…50% supaya terasa diketik manusia (seperti typed.js).
    humanize = true,
} = {}) {
    let timer = null;
    let running = false;
    let started = false;
    let index = 0;
    let length = 0;
    let deleting = false;

    const commonPrefix = (a, b) => {
        let i = 0;
        while (i < a.length && i < b.length && a[i] === b[i]) i += 1;
        return i;
    };

    const typeDelay = () => (humanize ? typeSpeed + Math.round(Math.random() * typeSpeed / 2) : typeSpeed);

    const schedule = (ms) => {
        timer = running ? setTimeout(tick, ms) : null;
    };

    function tick() {
        const current = strings[index];

        if (!deleting) {
            length += 1;
            onText(current.slice(0, length));
            if (length >= current.length) {
                deleting = true;
                return schedule(backDelay);
            }
            return schedule(typeDelay());
        }

        const next = strings[(index + 1) % strings.length];
        if (length > commonPrefix(current, next)) {
            length -= 1;
            onText(current.slice(0, length));
            return schedule(backSpeed);
        }

        // Awalan yang tersisa sudah milik string berikutnya — lanjut mengetik dari situ.
        index = (index + 1) % strings.length;
        deleting = false;
        return schedule(typeDelay());
    }

    return {
        start() {
            if (running || strings.length === 0) return;
            running = true;
            schedule(started ? typeSpeed : startDelay);
            started = true;
        },
        stop() {
            running = false;
            clearTimeout(timer);
            timer = null;
        },
    };
}
