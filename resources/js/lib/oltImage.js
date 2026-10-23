// Gambar produk OLT per model/keluarga, ditaruh sebagai berkas statis di public/img/olt/.
//
// Nama berkas = `<key>.webp` (daftar di public/img/olt/README.md). Komponen OltImage mencoba
// kandidat berurutan dan jatuh ke placeholder bila tak ada satu pun — jadi gambar cukup
// DITARUH, tanpa mengubah kode. Tiga gambar ZTE lama (/img/c300.webp dst.) tetap dipakai
// sebagai cadangan karena halaman Welcome juga memakainya.

const LEGACY = {
    'zte-c300': '/img/c300.webp',
    'zte-c320': '/img/c320.webp',
    'zte-c600': '/img/c600.webp',
};

/**
 * Kunci gambar untuk satu OLT.
 * @param {{ driver?: string, vendor?: string, name?: string, model?: string, capabilities?: object }} olt
 */
export function oltImageKey(olt = {}) {
    const driver = String(olt.driver ?? '');
    const hay = `${olt.name ?? ''} ${olt.vendor ?? ''} ${olt.model ?? ''}`.toLowerCase();

    // GPON kecil punya panel sendiri (1/2 PON, GE RJ45, 10GE, CONSOLE); model dari faceplate `device.model`.
    if (driver.startsWith('cdata-gpon')) {
        if (hay.includes('fd1601')) return 'cdata-gpon-fd1601s';
        if (hay.includes('fd1602')) return 'cdata-gpon-fd1602s';
        return 'cdata-gpon';
    }
    if (driver.startsWith('cdata-epon')) return 'cdata-epon';
    // HA7302 dikenali backend dari firmware/sysDescr juga (capabilities.is_ha7302), bukan cuma nama.
    if (driver.startsWith('hioso')) return olt.capabilities?.is_ha7302 || hay.includes('ha7302') ? 'hioso-ha7302' : 'hioso-epon';
    // HSGQ: jumlah PON dari kode model (`HSGQ-G16L`, `HSGQ-XE08R`; model = sysDescr); EPON dari
    // capabilities (hasil scan) atau kode model. Tanpa model → foto 4 PON.
    if (driver.startsWith('hsgq')) {
        const ports = hay.match(/hsgq[\s_-]*x?[eg](\d{2})/)?.[1];
        if (olt.capabilities?.is_hsgq_epon || /hsgq[\s_-]*x?e\d/.test(hay)) return ports === '08' ? 'hsgq-epon-8' : 'hsgq-epon';
        if (ports === '01' || ports === '02') return 'hsgq-gpon-mini';
        if (ports === '08' || ports === '16') return `hsgq-gpon-${Number(ports)}`;
        return 'hsgq-gpon';
    }

    if (hay.includes('c320')) return 'zte-c320';
    if (hay.includes('c600') || hay.includes('titan')) return 'zte-c600';
    if (hay.includes('c300')) return 'zte-c300';

    return driver === 'zte' ? 'zte-c300' : 'generic';
}

/** Daftar URL kandidat, urut prioritas. */
export function oltImageCandidates(key) {
    const list = [`/img/olt/${key}.webp`, `/img/olt/${key}.png`, `/img/olt/${key}.jpg`];
    if (LEGACY[key]) list.push(LEGACY[key]);
    return list;
}
