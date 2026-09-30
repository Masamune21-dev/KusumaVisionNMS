import { describe, expect, it } from 'vitest';
import { formatRelative } from '@/lib/datetime';

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
