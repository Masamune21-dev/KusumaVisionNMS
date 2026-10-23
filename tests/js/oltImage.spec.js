import { describe, expect, it } from 'vitest';
import { oltImageCandidates, oltImageKey } from '@/lib/oltImage';

describe('oltImage', () => {
    it('memilih gambar GPON ringkas dari model faceplate', () => {
        expect(oltImageKey({ driver: 'cdata-gpon-34592', model: 'FD1601S-B1' })).toBe('cdata-gpon-fd1601s');
        expect(oltImageKey({ driver: 'cdata-gpon-34592', model: 'FD1602S-B1' })).toBe('cdata-gpon-fd1602s');
    });

    it('GPON lain tetap memakai gambar keluarga', () => {
        expect(oltImageKey({ driver: 'cdata-gpon-34592', model: 'FD1608S-B1-NDA0' })).toBe('cdata-gpon');
        expect(oltImageKey({ driver: 'cdata-gpon-34592' })).toBe('cdata-gpon');
        expect(oltImageKey({ driver: 'cdata-epon-17409', model: 'FD1304E' })).toBe('cdata-epon');
    });

    it('HSGQ memilih foto per model dan teknologi PON', () => {
        expect(oltImageKey({ driver: 'hsgq-50224', model: 'HSGQ-G16L' })).toBe('hsgq-gpon-16');
        expect(oltImageKey({ driver: 'hsgq-50224', model: 'HSGQ-G08' })).toBe('hsgq-gpon-8');
        expect(oltImageKey({ driver: 'hsgq-50224', model: 'HSGQ-G02ID' })).toBe('hsgq-gpon-mini');
        expect(oltImageKey({ driver: 'hsgq-50224', model: 'HSGQ-XE08R' })).toBe('hsgq-epon-8');
        expect(oltImageKey({ driver: 'hsgq-50224', capabilities: { is_hsgq_epon: true } })).toBe('hsgq-epon');
        expect(oltImageKey({ driver: 'hsgq-50224', name: 'OLT Kantor' })).toBe('hsgq-gpon');
    });

    it('mencoba webp lebih dulu', () => {
        expect(oltImageCandidates('cdata-gpon-fd1601s')[0]).toBe('/img/olt/cdata-gpon-fd1601s.webp');
    });
});
