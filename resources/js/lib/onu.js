import { i18n } from '@/i18n';

// Interpretasi kode "last down cause" (penyebab ONU terakhir turun) dari OLT ZTE
// ke keterangan dwibahasa (lang/{id,en}.json namespace `onu.ldc_*`). Kode teknis
// aslinya (mis. LOSi, DyingGasp) tetap ditampilkan sebagai tooltip. Sumber kode:
// OltSnmpClient::decodeLastDownCause dan Go poller (cmd/kv-snmp-poller) —
// keduanya memakai taksonomi yang sama.
const LAST_DOWN_CAUSE_KEYS = {
    Normal: 'onu.ldc_normal',
    LOS: 'onu.ldc_los',
    LOSi: 'onu.ldc_los',
    LOFi: 'onu.ldc_lof',
    SFi: 'onu.ldc_sf',
    LOAi: 'onu.ldc_loa',
    LOAMi: 'onu.ldc_loam',
    Deactivated: 'onu.ldc_deactivated',
    Manual: 'onu.ldc_manual',
    DyingGasp: 'onu.ldc_dying_gasp',
    Unknown: 'onu.ldc_unknown',
};

/**
 * Ubah kode last-down-cause menjadi keterangan sesuai bahasa aktif. Kode tak
 * dikenal dikembalikan apa adanya; kosong/null → '—'. Dipanggil di render Vue,
 * `i18n.global.t` reaktif terhadap switch bahasa.
 * @param {string|null|undefined} code
 * @returns {string}
 */
export function lastDownCauseLabel(code) {
    if (code === null || code === undefined || code === '') {
        return '—';
    }

    const key = LAST_DOWN_CAUSE_KEYS[code];

    return key ? i18n.global.t(key) : code;
}
