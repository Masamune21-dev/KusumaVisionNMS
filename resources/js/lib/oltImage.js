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

    if (driver.startsWith('cdata-gpon')) return 'cdata-gpon';
    if (driver.startsWith('cdata-epon')) return 'cdata-epon';
    // HA7302 dikenali backend dari firmware/sysDescr juga (capabilities.is_ha7302), bukan cuma nama.
    if (driver.startsWith('hioso')) return olt.capabilities?.is_ha7302 || hay.includes('ha7302') ? 'hioso-ha7302' : 'hioso-epon';
    if (driver.startsWith('hsairpo')) return 'hsairpo-epon';

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
