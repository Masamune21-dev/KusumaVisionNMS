import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createTypewriter } from '@/lib/typewriter';

describe('createTypewriter', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    const opts = { typeSpeed: 10, backSpeed: 5, backDelay: 100, startDelay: 50, humanize: false };

    it('mengetik, jeda, lalu menghapus hanya sampai awalan yang sama', () => {
        const seen = [];
        const tw = createTypewriter(['show card', 'show gpon'], (t) => seen.push(t), opts);
        tw.start();

        vi.advanceTimersByTime(49);
        expect(seen).toEqual([]);

        // startDelay + 9 karakter
        vi.advanceTimersByTime(1 + 8 * 10);
        expect(seen.at(-1)).toBe('show card');
        const typed = seen.length;

        // jeda backDelay, lalu hapus "card" saja — "show " dipakai string berikutnya
        vi.advanceTimersByTime(100 + 3 * 5);
        expect(seen.at(-1)).toBe('show ');
        expect(seen.slice(typed)).toEqual(['show car', 'show ca', 'show c', 'show ']);

        vi.advanceTimersByTime(10 + 4 * 10);
        expect(seen.at(-1)).toBe('show gpon');
    });

    it('berulang tanpa akhir dan bisa dihentikan/dilanjutkan', () => {
        let text = '';
        const tw = createTypewriter(['ab', 'cd'], (t) => { text = t; }, opts);
        tw.start();
        vi.advanceTimersByTime(50 + 10);
        expect(text).toBe('ab');

        tw.stop();
        vi.advanceTimersByTime(10_000);
        expect(text).toBe('ab');

        // Dilanjutkan tanpa mengulang jeda: hapus "ab" seluruhnya (tak ada awalan sama), ketik "cd".
        tw.start();
        vi.advanceTimersByTime(10 + 2 * 5 + 10 + 10);
        expect(text).toBe('cd');
        // jeda, hapus, kembali ke string pertama
        vi.advanceTimersByTime(100 + 2 * 5 + 10 + 10 + 10);
        expect(text).toBe('ab');
    });
});
