<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n({ useScope: 'global' });

const props = defineProps({
    panel: { type: Object, default: null },
    // Nama port (mis. "epon 0/1/1") → URL halaman ONU port itu. Port yang ada di sini bisa diklik.
    portLinks: { type: Object, default: () => ({}) },
    // Nama port → { count, online } untuk tooltip & angka kecil di bawah port.
    portInfo: { type: Object, default: () => ({}) },
    // Teks tooltip & petunjuk bila tautan port bukan ke daftar ONU (mis. detail port C-Data GPON).
    linkHint: { type: String, default: null },
    clickHint: { type: String, default: null },
});

/*
 * Kontrak `panel` (dibangun CDataFaceplateService / HiosoFaceplateService):
 *   groups[]: { key, label, kind: fiber|copper, rows: 1|2, chunk?, module?, ports[] }
 *     rows=2  → port bertumpuk dua baris; urutan `ports` = kolom demi kolom dari ATAS
 *               (C-Data: genap di atas, ganjil di bawah; HiOSO: G4/G3, CONSOLE/MGMT).
 *     chunk=N → jeda visual tiap N port (PON 1-4 | 5-8 di FD1608S).
 *     module  → nomor kartu fisik; grup se-modul digambar dalam satu kartu (EPON: kartu PON 0/1
 *               terpisah dari papan utama). Satu modul = tanpa bingkai kartu.
 *   ports[]: { pos, name, status: up|down|shutdown|fixed, kind?, fixed?, label? }
 *     fixed=true → konektor non-SNMP (CONSOLE/MGMT), digambar netral tanpa status.
 *   fixed_ports[] (warisan panel lama di cache): MGMT/Console sebagai grup tersendiri.
 */
const groups = computed(() => props.panel?.groups ?? []);
const leds = computed(() => props.panel?.leds ?? []);
const device = computed(() => props.panel?.device ?? {});
// Panel lama di cache (sebelum 26 Sep 2026) belum punya blok CONSOLE/MGMT di groups → gambar MGMT
// warisan. Panel baru mengirim `fixed_ports: []`.
const legacyFixedPorts = computed(() => props.panel?.fixed_ports ?? [{ label: 'MGMT', num: 'M' }]);

const modules = computed(() => {
    const byId = new Map();
    for (const g of groups.value) {
        const id = Number(g.module ?? 1);
        if (!byId.has(id)) byId.set(id, []);
        byId.get(id).push(g);
    }
    return [...byId.entries()].sort((a, b) => a[0] - b[0]).map(([id, list]) => ({ id, groups: list }));
});

// Ringkasan port untuk badge atas chassis. Konektor non-SNMP tak dihitung, dan port combo
// (satu port logis dengan konektor SFP + RJ45, muncul di dua grup) dihitung sekali per nama.
const summary = computed(() => {
    const seen = new Map();
    for (const g of groups.value) {
        for (const p of g.ports) {
            if (p.fixed || seen.has(p.name)) continue;
            seen.set(p.name, p.status === 'up');
        }
    }
    let up = 0;
    for (const isUp of seen.values()) if (isUp) up += 1;
    return { up, total: seen.size };
});

/** Susun port satu grup menjadi kolom: rows=2 → pasangan [atas, bawah]; selain itu satu port per kolom. */
const columnsOf = (g) => {
    const rows = Number(g.rows ?? 1) === 2 ? 2 : 1;
    const cols = [];
    for (let i = 0; i < g.ports.length; i += rows) {
        cols.push(g.ports.slice(i, i + rows));
    }
    return cols;
};
const hasGap = (g, index) => Boolean(g.chunk) && index > 0 && index % Number(g.chunk) === 0;
const kindOf = (g, p) => p.kind ?? g.kind;
const labelOf = (p) => (p.fixed ? p.label ?? p.name : p.pos);

const infoOf = (p) => props.portInfo?.[p.name] ?? null;
const titleOf = (p) => {
    if (p.fixed) return p.label ?? p.name;
    const info = infoOf(p);
    const base = `${p.name} · ${p.status}`;
    const hint = props.portLinks?.[p.name] ? ` — ${props.linkHint ?? t('faceplate.open_onu')}` : '';
    if (!info) return props.linkHint ? `${base}${hint}` : base;
    const onus = t('faceplate.onu_count', { online: info.online ?? 0, total: info.count ?? 0 });
    return `${base} · ${onus}${hint}`;
};

const legend = computed(() => [
    { key: 'up', label: t('faceplate.legend_up') },
    { key: 'down', label: t('faceplate.legend_down') },
    { key: 'shutdown', label: t('faceplate.legend_shutdown') },
    { key: 'copper', label: t('faceplate.legend_copper') },
    { key: 'fiber', label: t('faceplate.legend_fiber') },
    { key: 'fixed', label: t('faceplate.legend_fixed') },
]);
</script>

<template>
    <div v-if="panel" class="fp">
        <!-- Panel depan perangkat. Ikut tema: logam gelap di tema gelap, perak di tema terang
             (OLT C-Data/HiOSO fisiknya memang berpanel terang). -->
        <div class="fp-chassis">
            <div class="fp-bevel" aria-hidden="true"></div>

            <div class="fp-face">
                <div class="fp-headline">
                    <span v-if="device.model || device.device_type" class="fp-model">{{ device.model || device.device_type }}</span>
                    <span class="fp-count">{{ summary.up }}/{{ summary.total }} port up</span>
                </div>

                <div class="fp-rows">
                    <!-- Satu kartu per modul fisik (EPON: kartu PON 0/1, lalu papan utama) -->
                    <div
                        v-for="(m, mi) in modules"
                        :key="m.id"
                        class="fp-module"
                        :class="{ 'fp-module--card': modules.length > 1 }"
                    >
                        <div v-for="g in m.groups" :key="g.key" class="fp-group">
                            <div class="fp-bracket" :class="{ 'fp-bracket--plain': !g.label }">{{ g.label || ' ' }}</div>
                            <div class="fp-ports">
                                <div
                                    v-for="(col, ci) in columnsOf(g)"
                                    :key="ci"
                                    class="fp-col"
                                    :class="{ 'fp-col--gap': hasGap(g, ci) }"
                                >
                                    <template v-for="(p, ri) in col" :key="p.name">
                                        <!-- Kolom bertumpuk: label port ATAS ditulis di atasnya (seperti cetakan panel) -->
                                        <span v-if="col.length > 1 && ri === 0" class="fp-num">{{ labelOf(p) }}</span>
                                        <component
                                            :is="portLinks[p.name] ? Link : 'div'"
                                            :href="portLinks[p.name] || undefined"
                                            class="fp-port"
                                            :class="[`is-${p.status}`, `kind-${kindOf(g, p)}`, portLinks[p.name] ? 'is-link' : '']"
                                            :title="titleOf(p)"
                                            :aria-label="portLinks[p.name] ? titleOf(p) : undefined"
                                        >
                                            <!-- Fiber (SFP/optical) icon -->
                                            <svg v-if="kindOf(g, p) === 'fiber'" viewBox="0 0 24 24" class="fp-ico" aria-hidden="true">
                                                <circle cx="12" cy="12" r="7.5" fill="none" stroke="currentColor" stroke-width="1.6" />
                                                <circle cx="12" cy="12" r="2.6" fill="currentColor" />
                                            </svg>
                                            <!-- Copper (RJ45) icon -->
                                            <svg v-else viewBox="0 0 24 24" class="fp-ico" aria-hidden="true">
                                                <path d="M6 5h12v9.5l-2.5 3.5h-7L6 14.5V5z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                                                <path d="M9 5v2M12 5v2M15 5v2" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" />
                                            </svg>
                                        </component>
                                        <span v-if="!(col.length > 1 && ri === 0)" class="fp-num">{{ labelOf(p) }}</span>
                                        <span v-if="infoOf(p)" class="fp-onu" :class="{ 'is-warn': (infoOf(p).online ?? 0) < (infoOf(p).count ?? 0) }">
                                            {{ infoOf(p).online ?? 0 }}/{{ infoOf(p).count ?? 0 }}
                                        </span>
                                    </template>
                                </div>
                            </div>
                        </div>

                        <template v-if="mi === modules.length - 1">
                            <!-- Warisan: MGMT/Console dari panel lama yang masih di cache -->
                            <div v-for="fx in legacyFixedPorts" :key="fx.label" class="fp-group">
                                <div class="fp-bracket fp-bracket--plain">{{ fx.label }}</div>
                                <div class="fp-ports">
                                    <div class="fp-col">
                                        <div class="fp-port is-fixed kind-copper" :title="fx.label">
                                            <svg viewBox="0 0 24 24" class="fp-ico" aria-hidden="true">
                                                <path d="M6 5h12v9.5l-2.5 3.5h-7L6 14.5V5z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                                                <path d="M9 5v2M12 5v2M15 5v2" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" />
                                            </svg>
                                        </div>
                                        <span class="fp-num">{{ fx.num }}</span>
                                    </div>
                                </div>
                            </div>

                            <!-- LED cluster -->
                            <div class="fp-leds">
                                <div v-for="led in leds" :key="led.key" class="fp-led-row">
                                    <span class="fp-led" :class="`led-${led.state}`"></span>
                                    <span class="fp-led-label">{{ led.label }}</span>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <!-- Legend -->
        <div class="fp-legend">
            <div v-for="item in legend" :key="item.key" class="fp-legend-row">
                <span class="fp-legend-swatch" :class="`sw-${item.key}`"></span>
                <span>{{ item.label }}</span>
            </div>
            <p v-if="Object.keys(portLinks).length" class="fp-legend-note">{{ clickHint ?? $t('faceplate.click_hint') }}</p>
        </div>
    </div>
</template>

<style scoped>
/* Palet panel per tema. Tema gelap = nilai lama (logam gelap); tema terang = perak. */
.fp {
    --fp-bg-from: #161c34;
    --fp-bg-to: #0f1426;
    --fp-border: rgba(99, 102, 241, 0.22);
    --fp-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.05), 0 18px 40px rgba(2, 6, 23, 0.45);
    --fp-bevel: rgba(129, 140, 248, 0.18);
    --fp-model: #c7d2fe;
    --fp-text: #cbd5e1;
    --fp-muted: #94a3b8;
    --fp-rule: rgba(148, 163, 184, 0.3);
    --fp-up-border: #22d3ee;
    --fp-up-fg: #67e8f9;
    --fp-up-bg: rgba(34, 211, 238, 0.12);
    --fp-up-glow: 0 0 10px rgba(34, 211, 238, 0.25);
    --fp-down-border: rgba(100, 116, 139, 0.5);
    --fp-down-fg: #64748b;
    --fp-down-bg: rgba(100, 116, 139, 0.08);
    --fp-off-bg: rgba(15, 23, 42, 0.6);
    --fp-led-off: #334155;
    --fp-warn: #fbbf24;

    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    align-items: stretch;
}

[data-theme='light'] .fp {
    --fp-bg-from: #f1f5f9;
    --fp-bg-to: #d9dee6;
    --fp-border: rgba(100, 116, 139, 0.35);
    --fp-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.9), 0 10px 24px rgba(15, 23, 42, 0.1);
    --fp-bevel: rgba(255, 255, 255, 0.8);
    --fp-model: #1e293b;
    --fp-text: #334155;
    --fp-muted: #64748b;
    --fp-rule: rgba(100, 116, 139, 0.4);
    --fp-up-border: #0891b2;
    --fp-up-fg: #0e7490;
    --fp-up-bg: rgba(6, 182, 212, 0.12);
    --fp-up-glow: 0 0 8px rgba(6, 182, 212, 0.25);
    --fp-down-border: rgba(100, 116, 139, 0.55);
    --fp-down-fg: #64748b;
    --fp-down-bg: rgba(255, 255, 255, 0.6);
    --fp-off-bg: rgba(148, 163, 184, 0.25);
    --fp-led-off: #94a3b8;
    --fp-warn: #b45309;
}

/* === Chassis === */
.fp-chassis {
    position: relative;
    flex: 1 1 520px;
    min-width: 0;
    border-radius: 14px;
    background: linear-gradient(180deg, var(--fp-bg-from) 0%, var(--fp-bg-to) 100%);
    border: 1px solid var(--fp-border);
    box-shadow: var(--fp-shadow);
    padding: 0.5rem;
    overflow-x: auto;
}

/* Bevel atas (perspektif tipis) */
.fp-bevel {
    height: 12px;
    margin: -0.5rem -0.5rem 0.5rem;
    border-radius: 14px 14px 0 0;
    background: linear-gradient(180deg, var(--fp-bevel), transparent);
    clip-path: polygon(2% 100%, 0 0, 100% 0, 98% 100%);
}

.fp-face {
    min-width: 640px;
    padding: 0.25rem 0.75rem 0.75rem;
}

.fp-headline {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 0.85rem;
    gap: 1rem;
}

.fp-model {
    font-family: ui-monospace, monospace;
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--fp-model);
    letter-spacing: 0.02em;
}

.fp-count {
    font-size: 0.75rem;
    color: var(--fp-muted);
    white-space: nowrap;
}

.fp-rows {
    display: flex;
    align-items: stretch;
    gap: 0.75rem;
    flex-wrap: wrap;
}

/* === Modul / kartu fisik === */
.fp-module {
    display: flex;
    align-items: flex-end;
    gap: 1.25rem;
    flex-wrap: wrap;
}
.fp-module--card {
    border: 1px solid var(--fp-rule);
    border-radius: 10px;
    padding: 0.5rem 0.75rem 0.6rem;
}
.fp-module--card:last-child {
    flex: 1 1 auto;
}

/* === Group + bracket === */
.fp-group {
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
}

.fp-bracket {
    position: relative;
    text-align: center;
    font-size: 0.65rem;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--fp-text);
    padding-bottom: 0.3rem;
    margin: 0 0.5rem;
    border-bottom: 1px solid var(--fp-rule);
    white-space: nowrap;
}
.fp-bracket::before,
.fp-bracket::after {
    content: '';
    position: absolute;
    bottom: -1px;
    width: 1px;
    height: 5px;
    background: var(--fp-rule);
}
.fp-bracket::before { left: 0; }
.fp-bracket::after { right: 0; }
.fp-bracket--plain {
    border-bottom-color: transparent;
}
.fp-bracket--plain::before,
.fp-bracket--plain::after { display: none; }

.fp-ports {
    display: flex;
    align-items: flex-end;
    gap: 0.4rem;
}

/* Satu kolom = satu port, atau dua port bertumpuk (label atas di atas, label bawah di bawah) */
.fp-col {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.2rem;
}
.fp-col--gap {
    margin-left: 0.6rem;
}

/* === Port === */
.fp-port {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 34px;
    height: 34px;
    border-radius: 6px;
    border: 1.5px solid;
    transition: transform 0.12s ease, box-shadow 0.12s ease;
}
.fp-port.is-link {
    cursor: pointer;
}
.fp-port.is-link:hover {
    transform: translateY(-2px);
    box-shadow: 0 0 0 2px var(--fp-up-border);
}
.fp-port.is-link:focus-visible {
    outline: none;
    box-shadow: 0 0 0 2px rgb(34 211 238 / 0.6);
}
.fp-ico {
    width: 20px;
    height: 20px;
}

/* Status — selaras legend */
.fp-port.is-up {
    border-color: var(--fp-up-border);
    color: var(--fp-up-fg);
    background: var(--fp-up-bg);
    box-shadow: var(--fp-up-glow);
}
.fp-port.is-down {
    border-color: var(--fp-down-border);
    color: var(--fp-down-fg);
    background: var(--fp-down-bg);
}
.fp-port.is-shutdown {
    border-color: var(--fp-down-border);
    color: var(--fp-down-fg);
    background: var(--fp-off-bg);
}
/* Konektor non-SNMP (CONSOLE/MGMT): netral, tanpa klaim status */
.fp-port.is-fixed {
    border-color: var(--fp-rule);
    border-style: dashed;
    color: var(--fp-text);
    background: transparent;
}

.fp-num {
    font-size: 0.65rem;
    color: var(--fp-muted);
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}
.fp-onu {
    font-size: 0.6rem;
    font-weight: 600;
    color: var(--fp-up-fg);
    font-variant-numeric: tabular-nums;
    line-height: 1;
}
.fp-onu.is-warn {
    color: var(--fp-warn);
}

/* === LED cluster === */
.fp-leds {
    display: grid;
    grid-template-columns: auto auto;
    gap: 0.3rem 0.65rem;
    align-self: center;
    padding-left: 0.5rem;
    margin-left: auto;
}
.fp-led-row {
    display: flex;
    align-items: center;
    gap: 0.35rem;
}
.fp-led {
    width: 9px;
    height: 9px;
    border-radius: 50%;
    background: var(--fp-led-off);
    box-shadow: inset 0 0 2px rgba(0, 0, 0, 0.6);
}
.fp-led.led-up {
    background: #34d399;
    box-shadow: 0 0 7px rgba(52, 211, 153, 0.7);
}
.fp-led.led-alarm {
    background: #fb7185;
    box-shadow: 0 0 7px rgba(251, 113, 133, 0.7);
}
.fp-led-label {
    font-size: 0.65rem;
    color: var(--fp-muted);
    letter-spacing: 0.04em;
}

/* === Legend === */
.fp-legend {
    flex: 0 0 auto;
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: 0.5rem;
    padding: 0.5rem 0.25rem;
    max-width: 12rem;
}
.fp-legend-row {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.75rem;
    color: rgb(var(--kv-slate-300));
}
.fp-legend-note {
    margin-top: 0.25rem;
    font-size: 0.75rem;
    line-height: 1.35;
    color: rgb(var(--kv-slate-500));
}
.fp-legend-swatch {
    width: 16px;
    height: 16px;
    border-radius: 4px;
    border: 1.5px solid;
    flex-shrink: 0;
}
.sw-up { border-color: var(--fp-up-border); background: var(--fp-up-bg); }
.sw-down { border-color: var(--fp-down-border); background: var(--fp-down-bg); }
.sw-shutdown { border-color: var(--fp-down-border); background: var(--fp-off-bg); }
.sw-copper { border-color: #a78bfa; background: rgba(167, 139, 250, 0.12); }
.sw-fiber { border-color: #38bdf8; background: rgba(56, 189, 248, 0.12); }
.sw-fixed { border-color: var(--fp-rule); border-style: dashed; background: transparent; }
</style>
