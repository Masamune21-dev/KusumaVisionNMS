<script setup>
import { computed } from 'vue';

const props = defineProps({
    panel: { type: Object, default: null },
});

const groups = computed(() => props.panel?.groups ?? []);
const leds = computed(() => props.panel?.leds ?? []);
const device = computed(() => props.panel?.device ?? {});

// Ringkasan port untuk badge atas chassis.
const summary = computed(() => {
    let up = 0;
    let total = 0;
    for (const g of groups.value) {
        for (const p of g.ports) {
            total += 1;
            if (p.status === 'up') up += 1;
        }
    }
    return { up, total };
});

const legend = [
    { key: 'up', label: 'Up' },
    { key: 'down', label: 'Down' },
    { key: 'shutdown', label: 'Shutdown' },
    { key: 'copper', label: 'Copper' },
    { key: 'fiber', label: 'Fiber' },
];
</script>

<template>
    <div v-if="panel" class="fp">
        <!-- Chassis -->
        <div class="fp-chassis">
            <div class="fp-bevel" aria-hidden="true"></div>

            <div class="fp-face">
                <div class="fp-headline">
                    <span v-if="device.model || device.device_type" class="fp-model">{{ device.model || device.device_type }}</span>
                    <span class="fp-count">{{ summary.up }}/{{ summary.total }} port up</span>
                </div>

                <div class="fp-rows">
                    <!-- Port groups (PON / GE / XGE) -->
                    <div v-for="g in groups" :key="g.key" class="fp-group">
                        <div class="fp-bracket">{{ g.label }}</div>
                        <div class="fp-ports">
                            <div v-for="p in g.ports" :key="p.name" class="fp-port-cell">
                                <div class="fp-port" :class="`is-${p.status} kind-${g.kind}`" :title="`${p.name} · ${p.status}`">
                                    <!-- Fiber (SFP/optical) icon -->
                                    <svg v-if="g.kind === 'fiber'" viewBox="0 0 24 24" class="fp-ico">
                                        <circle cx="12" cy="12" r="7.5" fill="none" stroke="currentColor" stroke-width="1.6" />
                                        <circle cx="12" cy="12" r="2.6" fill="currentColor" />
                                    </svg>
                                    <!-- Copper (RJ45) icon -->
                                    <svg v-else viewBox="0 0 24 24" class="fp-ico">
                                        <path d="M6 5h12v9.5l-2.5 3.5h-7L6 14.5V5z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                                        <path d="M9 5v2M12 5v2M15 5v2" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" />
                                    </svg>
                                </div>
                                <span class="fp-num">{{ p.pos }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- CONSOLE + MGMT (fixed) -->
                    <div class="fp-group">
                        <div class="fp-bracket fp-bracket--plain">MGMT</div>
                        <div class="fp-ports">
                            <div class="fp-port-cell">
                                <div class="fp-port is-up kind-copper" title="Management · up">
                                    <svg viewBox="0 0 24 24" class="fp-ico">
                                        <path d="M6 5h12v9.5l-2.5 3.5h-7L6 14.5V5z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                                        <path d="M9 5v2M12 5v2M15 5v2" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" />
                                    </svg>
                                </div>
                                <span class="fp-num">M</span>
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
                </div>
            </div>
        </div>

        <!-- Legend -->
        <div class="fp-legend">
            <div v-for="item in legend" :key="item.key" class="fp-legend-row">
                <span class="fp-legend-swatch" :class="`sw-${item.key}`"></span>
                <span>{{ item.label }}</span>
            </div>
        </div>
    </div>
</template>

<style scoped>
.fp {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    align-items: stretch;
}

/* === Chassis === */
.fp-chassis {
    position: relative;
    flex: 1 1 520px;
    min-width: 0;
    border-radius: 14px;
    background: linear-gradient(180deg, #161c34 0%, #0f1426 100%);
    border: 1px solid rgba(99, 102, 241, 0.22);
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.05), 0 18px 40px rgba(2, 6, 23, 0.45);
    padding: 0.5rem;
    overflow-x: auto;
}

/* Bevel atas (perspektif tipis) */
.fp-bevel {
    height: 12px;
    margin: -0.5rem -0.5rem 0.5rem;
    border-radius: 14px 14px 0 0;
    background: linear-gradient(180deg, rgba(129, 140, 248, 0.18), rgba(129, 140, 248, 0));
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
    color: #c7d2fe;
    letter-spacing: 0.02em;
}

.fp-count {
    font-size: 0.7rem;
    color: #94a3b8;
    white-space: nowrap;
}

.fp-rows {
    display: flex;
    align-items: flex-end;
    gap: 1.5rem;
    flex-wrap: wrap;
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
    font-size: 0.62rem;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: #cbd5e1;
    padding-bottom: 0.3rem;
    margin: 0 0.5rem;
    border-bottom: 1px solid rgba(148, 163, 184, 0.3);
}
.fp-bracket::before,
.fp-bracket::after {
    content: '';
    position: absolute;
    bottom: -1px;
    width: 1px;
    height: 5px;
    background: rgba(148, 163, 184, 0.3);
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
    gap: 0.4rem;
}

.fp-port-cell {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.2rem;
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
    transition: transform 0.12s ease;
}
.fp-port:hover {
    transform: translateY(-1px);
}
.fp-ico {
    width: 20px;
    height: 20px;
}

/* Status — selaras legend */
.fp-port.is-up {
    border-color: #22d3ee;
    color: #67e8f9;
    background: rgba(34, 211, 238, 0.12);
    box-shadow: 0 0 10px rgba(34, 211, 238, 0.25);
}
.fp-port.is-down {
    border-color: rgba(100, 116, 139, 0.5);
    color: #64748b;
    background: rgba(100, 116, 139, 0.08);
}
.fp-port.is-shutdown {
    border-color: rgba(100, 116, 139, 0.4);
    color: #475569;
    background: rgba(15, 23, 42, 0.6);
}

.fp-num {
    font-size: 0.6rem;
    color: #94a3b8;
    font-variant-numeric: tabular-nums;
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
    background: #334155;
    box-shadow: inset 0 0 2px rgba(0, 0, 0, 0.6);
}
.fp-led.led-up {
    background: #34d399;
    box-shadow: 0 0 7px rgba(52, 211, 153, 0.7);
}
.fp-led.led-alarm {
    background: #f87171;
    box-shadow: 0 0 7px rgba(248, 113, 113, 0.7);
}
.fp-led-label {
    font-size: 0.6rem;
    color: #94a3b8;
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
}
.fp-legend-row {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.72rem;
    color: #cbd5e1;
}
.fp-legend-swatch {
    width: 16px;
    height: 16px;
    border-radius: 4px;
    border: 1.5px solid;
    flex-shrink: 0;
}
.sw-up { border-color: #22d3ee; background: rgba(34, 211, 238, 0.15); }
.sw-down { border-color: rgba(100, 116, 139, 0.6); background: rgba(100, 116, 139, 0.1); }
.sw-shutdown { border-color: rgba(100, 116, 139, 0.4); background: rgba(15, 23, 42, 0.7); }
.sw-copper { border-color: #a78bfa; background: rgba(167, 139, 250, 0.12); }
.sw-fiber { border-color: #38bdf8; background: rgba(56, 189, 248, 0.12); }
</style>
