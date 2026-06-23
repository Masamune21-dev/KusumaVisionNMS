// Klasifikasi level redaman ONU RX — sumber tunggal ambang batas yang dipakai bersama
// oleh ONU Monitoring (tabel) dan Peta ONU (warna marker + badge), agar konsisten.

export function rxLevel(value) {
    if (value === null || value === undefined) return 'none';
    if (value <= -28 || value >= -8) return 'critical';
    if (value <= -25 || value >= -10) return 'warning';
    return 'good';
}

export function rxBadgeClass(value) {
    switch (rxLevel(value)) {
        case 'critical':
            return 'bg-red-500/15 text-red-300 ring-1 ring-red-500/30';
        case 'warning':
            return 'bg-amber-500/15 text-amber-300 ring-1 ring-amber-500/30';
        case 'good':
            return 'bg-emerald-500/15 text-emerald-300 ring-1 ring-emerald-500/30';
        default:
            return 'bg-slate-800/60 text-slate-500 ring-1 ring-slate-500/30';
    }
}

// Warna solid (hex) untuk marker peta — disetel agar kontras di tile gelap & terang.
export function rxMarkerColor(value, online = true) {
    if (!online) return '#64748b'; // slate-500 — ONU offline
    switch (rxLevel(value)) {
        case 'critical':
            return '#ef4444'; // red-500
        case 'warning':
            return '#f59e0b'; // amber-500
        case 'good':
            return '#10b981'; // emerald-500
        default:
            return '#64748b'; // slate-500 — tanpa data RX
    }
}

export function useRxLevel() {
    return { rxLevel, rxBadgeClass, rxMarkerColor };
}
