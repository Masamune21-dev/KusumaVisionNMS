import { i18n } from '@/i18n';

// Human-facing timestamps are displayed in one fixed timezone (per deployment) so
// the web UI, charts, and Telegram notifications stay consistent regardless of the
// viewer's browser timezone. Storage stays UTC; only display is converted. The zone +
// label are injected from backend config (window globals in app.blade.php) — e.g.
// Asia/Jakarta "WIB" (default) or America/Santo_Domingo "AST".
const DISPLAY_TZ = (typeof window !== 'undefined' && window.KV_DISPLAY_TZ) || 'Asia/Jakarta';
const TZ_LABEL = (typeof window !== 'undefined' && window.KV_DISPLAY_TZ_LABEL) || 'WIB';

// Format angka/bulan mengikuti bahasa aktif (id "29 Mei, 16.42" · en "29 May, 16:42").
// Dibaca dari ref locale vue-i18n → pemanggilan saat render ikut reaktif switch bahasa.
const INTL_LOCALES = { id: 'id-ID', en: 'en-GB' };
const activeLocale = () => INTL_LOCALES[i18n.global.locale.value] ?? 'id-ID';

function toDate(value) {
    if (value instanceof Date) return value;
    if (value === null || value === undefined || value === '') return null;
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? null : d;
}

// "29 Mei 2026, 16.42 WIB"
export function formatDateTime(value) {
    const d = toDate(value);
    if (!d) return '—';
    return new Intl.DateTimeFormat(activeLocale(), {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: DISPLAY_TZ,
    }).format(d) + ' ' + TZ_LABEL;
}

// "29 Mei 2026"
export function formatDate(value) {
    const d = toDate(value);
    if (!d) return '—';
    return new Intl.DateTimeFormat(activeLocale(), {
        dateStyle: 'medium',
        timeZone: DISPLAY_TZ,
    }).format(d);
}

// Compact header clock: "29 Mei 16.42 WIB"
/** Jam:menit:detik + zona (mis. "21:04:09 WIB") — untuk log aktivitas & "dibaca pukul". */
export function formatTime(value) {
    const d = toDate(value) ?? new Date();
    return new Intl.DateTimeFormat(activeLocale(), {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false,
        timeZone: DISPLAY_TZ,
    }).format(d) + ' ' + TZ_LABEL;
}

export function formatClock(value) {
    const d = toDate(value) ?? new Date();
    return new Intl.DateTimeFormat(activeLocale(), {
        day: '2-digit',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
        timeZone: DISPLAY_TZ,
    }).format(d) + ' ' + TZ_LABEL;
}

// Time-only label for live charts: "16.42.05" (WIB, no suffix to keep axis compact)
export function formatTimeOfDay(value) {
    const d = toDate(value) ?? new Date();
    return new Intl.DateTimeFormat(activeLocale(), {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        timeZone: DISPLAY_TZ,
    }).format(d);
}

// Waktu relatif mengikuti bahasa aktif: "3 menit yang lalu" / "3 minutes ago", "sekarang" / "now".
export function formatRelative(value, now = new Date()) {
    const d = toDate(value);
    if (!d) return '—';
    const seconds = Math.round((d.getTime() - now.getTime()) / 1000);
    const rtf = new Intl.RelativeTimeFormat(activeLocale(), { numeric: 'auto' });
    for (const [unit, size] of [['day', 86400], ['hour', 3600], ['minute', 60]]) {
        if (Math.abs(seconds) >= size) return rtf.format(Math.round(seconds / size), unit);
    }
    return rtf.format(0, 'second');
}
