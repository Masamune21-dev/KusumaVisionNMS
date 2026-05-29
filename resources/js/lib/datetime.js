// All human-facing timestamps are displayed in WIB (Asia/Jakarta) so the web UI,
// charts, and Telegram notifications stay consistent regardless of the viewer's
// browser timezone. Storage stays UTC; only display is converted.
const DISPLAY_TZ = 'Asia/Jakarta';
const TZ_LABEL = 'WIB';
const LOCALE = 'id-ID';

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
    return new Intl.DateTimeFormat(LOCALE, {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: DISPLAY_TZ,
    }).format(d) + ' ' + TZ_LABEL;
}

// "29 Mei 2026"
export function formatDate(value) {
    const d = toDate(value);
    if (!d) return '—';
    return new Intl.DateTimeFormat(LOCALE, {
        dateStyle: 'medium',
        timeZone: DISPLAY_TZ,
    }).format(d);
}

// Compact header clock: "29 Mei 16.42 WIB"
export function formatClock(value) {
    const d = toDate(value) ?? new Date();
    return new Intl.DateTimeFormat(LOCALE, {
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
    return new Intl.DateTimeFormat(LOCALE, {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        timeZone: DISPLAY_TZ,
    }).format(d);
}
