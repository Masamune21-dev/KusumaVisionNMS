import { describe, expect, it } from 'vitest';
import { displayTzOffsetMs, formatAxisTime, formatRelative } from '@/lib/datetime';

describe('formatRelative', () => {
    const now = new Date('2026-09-30T08:00:00Z');

    it('memakai satuan terbesar yang pas', () => {
        expect(formatRelative('2026-09-30T07:57:00Z', now)).toBe('3 menit yang lalu');
        expect(formatRelative('2026-09-30T05:00:00Z', now)).toBe('3 jam yang lalu');
        expect(formatRelative('2026-09-27T08:00:00Z', now)).toBe('3 hari yang lalu');
    });

    it('di bawah satu menit = sekarang; nilai kosong = tanda pisah', () => {
        expect(formatRelative('2026-09-30T07:59:40Z', now)).toBe('sekarang');
        expect(formatRelative(null, now)).toBe('—');
    });
});

describe('sumbu waktu grafik', () => {
    it('selisih zona tampilan (WIB) = +7 jam', () => {
        expect(displayTzOffsetMs('2026-10-02T01:00:00Z')).toBe(7 * 3600 * 1000);
    });

    it('label jam & tanggal di zona tampilan, tanpa akhiran zona', () => {
        // 17.30 UTC = 00.30 WIB keesokan harinya.
        expect(formatAxisTime('2026-10-01T17:30:00Z')).toBe('00.30');
        expect(formatAxisTime('2026-10-01T17:30:00Z', { date: true })).toBe('2 Okt');
        expect(formatAxisTime(null)).toBe('');
    });
});
