import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';

const { routerGet } = vi.hoisted(() => ({ routerGet: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    router: { get: routerGet },
}));

import PortOnuStats from '@/Components/CDataOlt/PortOnuStats.vue';
import PortSwitcher from '@/Components/CDataOlt/PortSwitcher.vue';

globalThis.route = (name, params) => `/${name}/${params.join('/')}`;

const eponPorts = [
    { name: 'epon 0/2/1', slot: 2, port: 1 },
    { name: 'epon 0/1/2', slot: 1, port: 2 },
    { name: 'epon 0/1/1', slot: 1, port: 1 },
    { name: 'epon 0/2/2', slot: 2, port: 2 },
];

const mountSwitcher = (props = {}) =>
    mount(PortSwitcher, {
        props: { oltId: 7, slot: 1, port: 2, ports: eponPorts, ponLabel: 'EPON', routeName: 'cdata-olt.port-onus', ...props },
        global: { mocks: { $t: (k) => k } },
    });

describe('PortSwitcher', () => {
    it('mengurutkan port per slot lalu port, label sisi-NMS ikut di opsi', () => {
        const w = mountSwitcher({ labels: { '2_1': 'BLOK-A' } });

        expect(w.findAll('option').map((o) => o.text())).toEqual([
            'EPON 0/1/1',
            'EPON 0/1/2',
            'EPON 0/2/1 — BLOK-A',
            'EPON 0/2/2',
        ]);
        expect(w.find('select').element.value).toBe('1_2');
    });

    it('tombol ◀ ▶ membuka port tetangga lewat rute family OLT', async () => {
        const w = mountSwitcher();
        const [prev, next] = w.findAll('button');

        expect(prev.attributes('aria-label')).toBe('EPON 0/1/1');
        expect(next.attributes('aria-label')).toBe('EPON 0/2/1');

        await next.trigger('click');
        expect(routerGet).toHaveBeenLastCalledWith('/cdata-olt.port-onus/7/2/1');

        await prev.trigger('click');
        expect(routerGet).toHaveBeenLastCalledWith('/cdata-olt.port-onus/7/1/1');
    });

    it('memilih port dari dropdown berpindah halaman; memilih port sendiri tidak', async () => {
        const w = mountSwitcher({ routeName: 'hioso-olt.port-onus' });
        routerGet.mockClear();

        await w.find('select').setValue('2_2');
        expect(routerGet).toHaveBeenCalledWith('/hioso-olt.port-onus/7/2/2');

        routerGet.mockClear();
        await w.find('select').setValue('1_2');
        expect(routerGet).not.toHaveBeenCalled();
    });

    it('port pertama/terakhir menonaktifkan tombol arah yang tak punya tujuan', () => {
        const first = mountSwitcher({ slot: 1, port: 1 }).findAll('button');
        expect(first[0].attributes('disabled')).toBeDefined();
        expect(first[0].attributes('aria-label')).toBe('portonus.port_first');

        const last = mountSwitcher({ slot: 2, port: 2 }).findAll('button');
        expect(last[1].attributes('disabled')).toBeDefined();
        expect(last[1].attributes('aria-label')).toBe('portonus.port_last');
    });

    it('disembunyikan bila OLT hanya punya satu port (HiOSO HA7302 agregat)', () => {
        const w = mountSwitcher({ slot: 1, port: 1, ports: [{ slot: 1, port: 1 }] });
        expect(w.find('select').exists()).toBe(false);
    });

    it('port saat ini tetap muncul walau belum ada di cache ports', () => {
        const w = mountSwitcher({ slot: 3, port: 1 });
        expect(w.findAll('option').map((o) => o.text())).toContain('EPON 0/3/1');
        expect(w.find('select').element.value).toBe('3_1');
    });
});

describe('PortOnuStats', () => {
    const mountStats = (snapshot) =>
        mount(PortOnuStats, { props: { snapshot }, global: { mocks: { $t: (k) => k } } });

    it('menghitung online dari daftar ONU dan total dari count snapshot', () => {
        const w = mountStats({
            ok: true,
            count: 3,
            refreshed_at: null,
            onus: [{ online: true }, { online: false }, { online: true }],
        });
        const text = w.text();

        expect(text).toContain('portonus.stat_available');
        expect(w.findAll('.kv-stat')[1].text()).toContain('3');
        expect(w.findAll('.kv-stat')[2].text().replace(/\s+/g, '')).toContain('2/3');
    });

    it('snapshot kosong (port belum pernah dipindai) tampil Kosong dan nol', () => {
        const w = mountStats(null);

        expect(w.text()).toContain('portonus.stat_empty');
        expect(w.findAll('.kv-stat')[2].text().replace(/\s+/g, '')).toContain('0/0');
    });
});
