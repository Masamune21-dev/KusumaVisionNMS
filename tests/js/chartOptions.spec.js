import { describe, expect, it } from 'vitest';
import { timeTicks, withAlpha } from '@/lib/chartOptions';

const HOUR = 3600 * 1000;

describe('withAlpha', () => {
    it('menempelkan opasitas ke heks 6 digit (dan mengganti yang sudah ada)', () => {
        expect(withAlpha('#10b981', 0.06)).toBe('#10b9810f');
        expect(withAlpha('#10b981ff', 0)).toBe('#10b98100');
        expect(withAlpha('#22d3ee', 2)).toBe('#22d3eeff');
    });
});

describe('timeTicks', () => {
    it('24 jam → centang tiap 6 jam di jam bulat WIB (00/06/12/18), bukan UTC', () => {
        // 1 Okt 10.20 WIB … 2 Okt 10.20 WIB
        const min = Date.parse('2026-10-01T03:20:00Z');
        const { step, values } = timeTicks(min, min + 24 * HOUR, 6);
        expect(step).toBe(6 * HOUR);
        // 12.00 WIB = 05.00 UTC
        expect(new Date(values[0]).toISOString()).toBe('2026-10-01T05:00:00.000Z');
        expect(values).toHaveLength(4);
    });

    it('7 hari → centang harian di tengah malam WIB', () => {
        const min = Date.parse('2026-09-25T03:00:00Z');
        const { step, values } = timeTicks(min, min + 7 * 24 * HOUR, 7);
        expect(step).toBe(24 * HOUR);
        // 00.00 WIB 26 Sep = 17.00 UTC 25 Sep
        expect(new Date(values[0]).toISOString()).toBe('2026-09-25T17:00:00.000Z');
    });

    it('rentang kosong tidak berputar tanpa akhir', () => {
        expect(timeTicks(1000, 1000).values).toEqual([1000]);
        expect(timeTicks(NaN, NaN).values).toEqual([]);
    });
});
