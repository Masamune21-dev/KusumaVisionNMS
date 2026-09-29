// Helper tampilan halaman VLAN & detail port C-Data GPON & EPON (Pages/CDataOlt/{Vlans,PortDetail}.vue).

const PORT_RE = /^(gpon|epon|ge|xge) (\d+)\/(\d+)\/(\d+)$/;

/** "xge 0/0/1" → { kind: 'xge', slot: 0, port: 1 } · "epon 0/2/3" → slot 2 · nama lain → null. */
export const parsePortName = (name) => {
    const m = PORT_RE.exec(String(name ?? '').trim().toLowerCase());
    return m ? { kind: m[1], slot: Number(m[3]), port: Number(m[4]) } : null;
};

/** [1,2,3,5,7,8] → "1–3, 5, 7–8" */
export const compressNumbers = (numbers) => {
    const sorted = [...new Set(numbers)].sort((a, b) => a - b);
    const parts = [];
    for (let i = 0; i < sorted.length; i += 1) {
        let j = i;
        while (j + 1 < sorted.length && sorted[j + 1] === sorted[j] + 1) j += 1;
        parts.push(i === j ? `${sorted[i]}` : `${sorted[i]}–${sorted[j]}`);
        i = j;
    }
    return parts.join(', ');
};

/**
 * Pisahkan daftar nama port jadi uplink (GE/XGE, satu per satu) dan ringkasan PON per slot
 * ("1–8", atau "0/1: 1–4 · 0/2: 1" untuk EPON dua kartu) supaya baris VLAN tak dibanjiri chip PON.
 */
export const splitPorts = (names) => {
    const uplinks = [];
    const pon = new Map();
    for (const name of names ?? []) {
        const p = parsePortName(name);
        if (!p) continue;
        if (p.kind === 'gpon' || p.kind === 'epon') {
            if (!pon.has(p.slot)) pon.set(p.slot, []);
            pon.get(p.slot).push(p.port);
        } else {
            uplinks.push({ name, ...p });
        }
    }
    uplinks.sort((a, b) => (a.kind === b.kind ? a.port - b.port : a.kind === 'xge' ? -1 : 1));
    const ponLabel = [...pon.entries()]
        .map(([slot, ports]) => (pon.size > 1 ? `0/${slot}: ${compressNumbers(ports)}` : compressNumbers(ports)))
        .join(' · ');
    return { uplinks, ponLabel, ponCount: [...pon.values()].reduce((n, ports) => n + ports.length, 0) };
};

export const formatKbps = (kbps) => {
    if (kbps === null || kbps === undefined || kbps === '') return '—';
    const v = Number(kbps);
    if (v >= 1_000_000) return `${(v / 1_000_000).toFixed(2)} Gbps`;
    if (v >= 1000) return `${(v / 1000).toFixed(v >= 100_000 ? 0 : 1)} Mbps`;
    return `${v.toLocaleString('id-ID')} kbps`;
};

export const formatBytes = (bytes) => {
    if (bytes === null || bytes === undefined || bytes === '') return '—';
    let v = Number(bytes);
    const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    let i = 0;
    while (v >= 1024 && i < units.length - 1) {
        v /= 1024;
        i += 1;
    }
    return `${v.toLocaleString('id-ID', { maximumFractionDigits: i === 0 ? 0 : 2 })} ${units[i]}`;
};

export const formatCount = (value) =>
    value === null || value === undefined || value === '' ? '—' : Number(value).toLocaleString('id-ID');

/** Tipe VLAN C-Data → kunci i18n (dipetakan eksplisit, bukan dirakit dari string). */
export const vlanTypeKey = (type) => {
    const t = String(type ?? '').toLowerCase();
    if (t.startsWith('l3')) return 'cdatavlan.type_l3';
    if (t.startsWith('normal')) return 'cdatavlan.type_normal';
    return null;
};
