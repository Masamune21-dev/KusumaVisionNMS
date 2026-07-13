// Interpretasi kode "last down cause" (penyebab ONU terakhir turun) dari OLT ZTE
// ke bahasa Indonesia yang mudah dipahami operator/CS. Kode teknis aslinya (mis. LOSi,
// DyingGasp) tetap ditampilkan sebagai tooltip. Sumber kode: OltSnmpClient::decodeLastDownCause
// dan Go poller (cmd/kv-snmp-poller) — keduanya memakai taksonomi yang sama.
const LAST_DOWN_CAUSE_LABELS = {
    Normal: 'Normal',
    LOS: 'Fiber putus / kehilangan sinyal (LOS)',
    LOSi: 'Fiber putus / kehilangan sinyal (LOS)',
    LOFi: 'Kehilangan frame (LOF)',
    SFi: 'Sinyal buruk / Signal Fail',
    LOAi: 'Kehilangan alignment (LOA)',
    LOAMi: 'Gangguan OAM (LOAM)',
    Deactivated: 'Dinonaktifkan dari OLT',
    Manual: 'Dimatikan manual (admin)',
    DyingGasp: 'Listrik pelanggan mati (dying gasp)',
    Unknown: 'Tidak diketahui',
};

/**
 * Ubah kode last-down-cause menjadi keterangan bahasa Indonesia. Kode tak dikenal
 * dikembalikan apa adanya; kosong/null → '—'.
 * @param {string|null|undefined} code
 * @returns {string}
 */
export function lastDownCauseLabel(code) {
    if (code === null || code === undefined || code === '') {
        return '—';
    }

    return LAST_DOWN_CAUSE_LABELS[code] ?? code;
}
