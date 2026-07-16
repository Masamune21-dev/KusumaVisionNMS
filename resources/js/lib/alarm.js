/**
 * Label alarm by-key (dwibahasa) — memetakan enum backend (AlarmEvent) ke i18n.
 * Backend tetap mengirim nilai mentah (`type`, `status`); terjemahan terjadi di
 * frontend supaya reaktif terhadap switch bahasa tanpa reload.
 *
 * Pakai: const { t } = useI18n({ useScope: 'global' });
 *        alarmTypeLabel(t, alarm.type); alarmStatusLabel(t, alarm.status);
 */

// Tipe dikenal = kunci di AlarmEvent::TYPE_LABELS (sinkron manual bila bertambah).
const KNOWN_TYPES = new Set([
    'olt_unreachable',
    'port_down',
    'los',
    'dying_gasp',
    'onu_offline',
    'high_rx_attenuation',
]);

const KNOWN_STATUSES = new Set(['active', 'cleared', 'pending']);

/** Label tipe alarm; tipe tak dikenal di-prettify apa adanya (snake_case → Title Case). */
export function alarmTypeLabel(t, type) {
    if (!type) return 'Alarm';
    if (KNOWN_TYPES.has(type)) return t(`alarms.type_${type}`);

    return String(type).replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

/** Label status alarm; status tak dikenal ditampilkan apa adanya. */
export function alarmStatusLabel(t, status) {
    if (KNOWN_STATUSES.has(status)) return t(`alarms.status_${status}`);

    return String(status ?? '');
}
