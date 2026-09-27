import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', () => ({
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

vi.mock('vue-i18n', () => ({
    useI18n: () => ({ t: (key, params) => (params ? `${key}:${JSON.stringify(params)}` : key) }),
}));

import OltFaceplate from '@/Components/CDataOlt/OltFaceplate.vue';

const port = (name, pos, status = 'up', extra = {}) => ({ name, pos, status, ...extra });
const fixed = (name) => port(name, name[0], 'fixed', { fixed: true, label: name });

/** Panel EPON 8-PON seperti keluaran CDataFaceplateService (kartu 0/2 kiri, papan utama 0/1 kanan). */
const eponPanel = {
    device: { device_type: 'EPON OLT' },
    leds: [{ key: 'pwr', label: 'PWR', state: 'up' }],
    fixed_ports: [],
    groups: [
        { key: 'pon-0/2', label: 'PON 0/2', kind: 'fiber', rows: 1, chunk: 4, module: 1, ports: [1, 2, 3, 4].map((n) => port(`epon 0/2/${n}`, n)) },
        { key: 'pon-0/1', label: 'PON 0/1', kind: 'fiber', rows: 1, chunk: 4, module: 2, ports: [1, 2, 3, 4].map((n) => port(`epon 0/1/${n}`, n, n === 4 ? 'down' : 'up')) },
        { key: 'ge', label: 'GE', kind: 'copper', rows: 1, module: 2, ports: [1, 2, 3, 4].map((n) => port(`ge 0/0/${n}`, n, 'down')) },
        { key: 'xge', label: 'XGE', kind: 'fiber', rows: 2, module: 2, ports: [2, 1, 4, 3].map((n) => port(`xge 0/0/${n}`, n, n === 1 ? 'up' : 'down')) },
        { key: 'mgmt', label: '', kind: 'copper', rows: 2, module: 2, ports: [fixed('CONSOLE'), fixed('MGMT')] },
    ],
};

const mountPanel = (panel, props = {}) =>
    mount(OltFaceplate, { props: { panel, ...props }, global: { mocks: { $t: (k) => k } } });

describe('OltFaceplate', () => {
    it('menggambar satu kartu per modul, urut nomor modul (PON 0/2 kiri, papan utama kanan)', () => {
        const w = mountPanel(eponPanel);
        const modules = w.findAll('.fp-module');

        expect(modules).toHaveLength(2);
        expect(modules[0].classes()).toContain('fp-module--card');
        expect(modules[0].findAll('.fp-bracket').map((b) => b.text())).toEqual(['PON 0/2']);
        expect(modules[1].findAll('.fp-bracket').map((b) => b.text())).toEqual(['PON 0/1', 'GE', 'XGE', '']);
        // LED hanya di modul terakhir (papan utama).
        expect(modules[0].find('.fp-leds').exists()).toBe(false);
        expect(modules[1].find('.fp-leds').exists()).toBe(true);
    });

    it('port bertumpuk: label port atas di atasnya, port bawah di bawahnya', () => {
        const w = mountPanel(eponPanel);
        const xge = w.findAll('.fp-group')[3];
        const cols = xge.findAll('.fp-col');

        expect(cols).toHaveLength(2);
        // Kolom 1: [label "2"] [port 2] [port 1] [label "1"]
        const kids = cols[0].element.children;
        expect(kids[0].className).toContain('fp-num');
        expect(kids[0].textContent.trim()).toBe('2');
        expect(kids[1].className).toContain('fp-port');
        expect(kids[2].className).toContain('fp-port');
        expect(kids[3].textContent.trim()).toBe('1');
        expect(kids[2].className).toContain('is-up');
    });

    it('konektor CONSOLE/MGMT digambar netral (is-fixed) berlabel nama dan tidak dihitung "port up"', () => {
        const w = mountPanel(eponPanel);
        const mgmt = w.findAll('.fp-group')[4];

        expect(mgmt.findAll('.fp-port.is-fixed')).toHaveLength(2);
        expect(mgmt.findAll('.fp-num').map((n) => n.text())).toEqual(['CONSOLE', 'MGMT']);
        // 4 (PON 0/2) + 3 (PON 0/1) + 0 GE + 1 XGE = 8 dari 16 port SNMP.
        expect(w.find('.fp-count').text()).toBe('8/16 port up');
        // Tak ada blok MGMT warisan karena panel mengirim fixed_ports: [].
        expect(w.findAll('.fp-group')).toHaveLength(5);
    });

    it('jeda tiap `chunk` port dan tautan/angka ONU hanya di port PON yang punya link', () => {
        const pon8 = {
            device: {},
            leds: [],
            fixed_ports: [],
            groups: [{ key: 'pon', label: 'PON', kind: 'fiber', rows: 1, chunk: 4, module: 1, ports: [1, 2, 3, 4, 5, 6, 7, 8].map((n) => port(`gpon 0/0/${n}`, n)) }],
        };
        const w = mountPanel(pon8, { portLinks: { 'gpon 0/0/5': '/x/5' }, portInfo: { 'gpon 0/0/5': { count: 10, online: 9 } } });
        const cols = w.findAll('.fp-col');

        expect(w.find('.fp-count').text()).toBe('8/8 port up');

        expect(cols.map((c) => c.classes().includes('fp-col--gap'))).toEqual([false, false, false, false, true, false, false, false]);
        expect(cols[4].find('a.fp-port').attributes('href')).toBe('/x/5');
        expect(cols[4].find('.fp-onu').text()).toBe('9/10');
        expect(cols[4].find('.fp-onu').classes()).toContain('is-warn');
        expect(cols[3].find('a').exists()).toBe(false);
        // Satu modul → tanpa bingkai kartu.
        expect(w.find('.fp-module').classes()).not.toContain('fp-module--card');
    });

    it('port combo (nama sama di grup SFP dan RJ45) dihitung sekali di ringkasan', () => {
        const combo = {
            device: {},
            leds: [],
            fixed_ports: [],
            groups: [
                { key: 'ge-sfp', label: 'COMBO GE', kind: 'fiber', rows: 1, module: 1, ports: [port('ge 0/0/1', 1), port('ge 0/0/2', 2, 'down')] },
                { key: 'ge-rj45', label: 'COMBO GE', kind: 'copper', rows: 2, module: 1, ports: [port('ge 0/0/2', 2, 'down'), port('ge 0/0/1', 1)] },
            ],
        };

        expect(mountPanel(combo).find('.fp-count').text()).toBe('1/2 port up');
    });

    it('panel lama di cache (tanpa rows/module/fixed_ports) tetap tergambar dengan MGMT warisan', () => {
        const legacy = {
            device: { model: 'HA7304' },
            leds: [],
            groups: [
                { key: 'pon', label: 'PON', kind: 'fiber', ports: [port('epon 0/1/1', 1)] },
                { key: 'ge', label: 'GE', kind: 'copper', ports: [port('G1', 1)] },
            ],
        };
        const w = mountPanel(legacy);

        expect(w.findAll('.fp-module')).toHaveLength(1);
        expect(w.findAll('.fp-bracket').map((b) => b.text())).toEqual(['PON', 'GE', 'MGMT']);
        expect(w.find('.fp-count').text()).toBe('2/2 port up');
    });

    it('tidak menggambar apa pun tanpa panel', () => {
        expect(mountPanel(null).find('.fp').exists()).toBe(false);
    });
});
