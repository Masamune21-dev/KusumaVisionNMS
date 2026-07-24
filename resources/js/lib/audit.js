import { i18n } from '@/i18n';

// Deskripsi audit log yang disimpan backend (App\Support\AuditLogger) ditulis dalam bahasa
// Indonesia SAAT event terjadi (locale penulis), bukan kode yang diterjemahkan di render seperti
// pola lain di app ini (phaseStateLabel, alarmTypeLabel, dst.). Fungsi ini merangkai ulang teks
// sesuai locale VIEWER saat ditampilkan, dari data terstruktur (`event` + `properties.subject_*`)
// yang mulai disimpan AuditLogger — bukan dari string `description` mentah.

// Event tanpa konten dinamis — teksnya tetap sama tiap kali terjadi.
const PLAIN_DESC_KEYS = {
    login: 'auditlogs.desc_login',
    logout: 'auditlogs.desc_logout',
    login_failed: 'auditlogs.desc_login_failed',
};

const VERB_KEYS = {
    created: 'auditlogs.verb_created',
    updated: 'auditlogs.verb_updated',
    deleted: 'auditlogs.verb_deleted',
};

/**
 * @param {{event: string, description: string|null, properties: Record<string, unknown>|null}} log
 * @returns {string}
 */
export function auditDescription(log) {
    const t = i18n.global.t;
    const props = log.properties ?? {};

    if (log.event in PLAIN_DESC_KEYS) {
        return t(PLAIN_DESC_KEYS[log.event]);
    }

    if (log.event === 'telnet_opened' && props.subject_title) {
        return t('auditlogs.desc_telnet_opened', { target: props.subject_title });
    }

    if (log.event === 'onu_zone_assigned' && props.subject_title) {
        return props.zone_name
            ? t('auditlogs.desc_onu_zone_assigned', { zone: props.zone_name, target: props.subject_title })
            : t('auditlogs.desc_onu_zone_cleared', { target: props.subject_title });
    }

    if (log.event in VERB_KEYS && props.subject_label && props.subject_title) {
        return `${t(VERB_KEYS[log.event])} ${props.subject_label} ${props.subject_title}`;
    }

    // Baris lama (sebelum fix ini) tak punya subject_label/subject_title di properties —
    // fallback ke description mentah (kemungkinan bahasa Indonesia, tak bisa diperbaiki
    // retroaktif tanpa backfill data historis).
    return log.description || '—';
}
