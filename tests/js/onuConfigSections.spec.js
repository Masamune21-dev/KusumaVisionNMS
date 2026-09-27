import { describe, expect, it } from 'vitest';
import { findItem, isLockedRow, normalizeRow, validateRow } from '@/lib/onuConfigSections';

const profiled = {
    onu_profile: 'VLAN2100',
    profile_lines: ['tcont 1 name 1 profile SERVER', 'gemport 1 name 1 tcont 1', 'service ServiceName gemport 1 cos 0 vlan 2100'],
};

describe('onuConfigSections', () => {
    it('menandai baris milik onu-profile sebagai terkunci', () => {
        expect(isLockedRow(findItem('tconts'), { id: 1 }, profiled)).toBe(true);
        expect(isLockedRow(findItem('tconts'), { id: 2 }, profiled)).toBe(false);
        expect(isLockedRow(findItem('services'), { name: 'ServiceName' }, profiled)).toBe(true);
        // `service ServiceName1` bukan milik profile walau awalannya mirip.
        expect(isLockedRow(findItem('services'), { name: 'Service' }, profiled)).toBe(false);
        // service-port tak pernah dikunci profile.
        expect(isLockedRow(findItem('service_ports'), { id: 1 }, profiled)).toBe(false);
    });

    it('tidak mengunci apa pun bila ONU tanpa profile', () => {
        expect(isLockedRow(findItem('tconts'), { id: 1 }, { onu_profile: null, profile_lines: [] })).toBe(false);
    });

    it('menormalkan isian kosong sesuai aturan server', () => {
        const row = normalizeRow(findItem('service_ports'), { id: '3', vport: '1', user_vlan: '', vlan: '2101' });
        expect(row).toEqual({ id: 3, vport: 1, user_vlan: null, vlan: 2101 });
    });

    it('menolak VLAN di luar rentang dan ID kembar', () => {
        const item = findItem('service_ports');
        const rows = [{ id: 1, vport: 1, user_vlan: 2100, vlan: 2100 }];
        expect(validateRow(item, { id: 2, vport: 1, user_vlan: 5000, vlan: 1 }, rows, null)?.key).toBe('onucfg.v_range');
        expect(validateRow(item, { id: 1, vport: 1, user_vlan: 10, vlan: 10 }, rows, null)?.key).toBe('onucfg.v_duplicate');
        // Mengubah baris itu sendiri bukan duplikat.
        expect(validateRow(item, { id: 1, vport: 1, user_vlan: 10, vlan: 10 }, rows, 0)).toBeNull();
    });

    it('VLAN service wajib kecuali mode transparent', () => {
        const item = findItem('services');
        expect(validateRow(item, { name: 'PPPOE', mode: 'vlanpri', gem: 1, cos: 0, vlan: null }, [], null)?.key).toBe('onucfg.v_required');
        expect(validateRow(item, { name: 'PPPOE', mode: 'transparent', gem: 1 }, [], null)).toBeNull();
    });
});
