<script setup>
import { formatDateTime } from '@/lib/datetime';
import { Link } from '@inertiajs/vue3';
import { Cpu } from '@lucide/vue';
import { computed } from 'vue';

const props = defineProps({
    oltId: { type: [Number, String], required: true },
    // Kartu hardware hasil `show card` (slot, cfg_type, port_count, status, ...)
    cards: { type: Array, default: () => [] },
    // Port GPON dari snapshot SNMP (slot, port, oper_status)
    ports: { type: Array, default: () => [] },
    // Status interface tersimpan (uplink): { interface, link_status, admin_status }
    interfaces: { type: Array, default: () => [] },
    model: { type: String, default: '' },
    lastRefresh: { type: String, default: null },
});

// Tipe kartu layanan GPON (C300/C320/C600) — port-nya diwarnai live dari oper_status.
const GPON_PREFIX = /^G[TF]/i;
// Kartu uplink (selaras dgn ZteCardUplinkService): XGEI = 10GE, GEI = 1GE.
const XGEI_CARDS = ['HUVQ', 'HUVG', 'HUVX'];
const GEI_CARDS = ['SMXA', 'SMXB'];

// C320 = chassis kecil → kartu digambar sebagai strip horizontal yang ditumpuk.
const isHorizontal = computed(() => /c320/i.test(props.model ?? ''));

const portsBySlot = computed(() => {
    const map = {};
    for (const p of props.ports) {
        const slot = Number(p.slot);
        (map[slot] ??= {})[Number(p.port)] = String(p.oper_status ?? '').toLowerCase();
    }
    return map;
});

// Status link uplink tersimpan, di-key per nama interface.
const linkByInterface = computed(() => {
    const map = {};
    for (const i of props.interfaces) {
        if (i.interface) map[i.interface] = String(i.link_status ?? '').toLowerCase();
    }
    return map;
});

const sortedCards = computed(() =>
    [...props.cards].sort((a, b) => (a.slot ?? 0) - (b.slot ?? 0)),
);

// Daftar slot lengkap (min..max). Slot kosong di antaranya tetap ditampilkan agar chassis terlihat penuh.
const chassisSlots = computed(() => {
    const cards = sortedCards.value;
    if (cards.length === 0) return [];

    const bySlot = {};
    let min = Infinity;
    let max = -Infinity;
    for (const c of cards) {
        const s = Number(c.slot);
        bySlot[s] = c;
        if (s < min) min = s;
        if (s > max) max = s;
    }

    // C320: mulai dari slot 1 agar slot line-card kosong (slot 1) tetap tampil.
    if (isHorizontal.value) min = Math.min(min, 1);

    const list = [];
    for (let s = min; s <= max; s++) {
        list.push(bySlot[s] ? { key: `c${s}`, empty: false, card: bySlot[s] } : { key: `e${s}`, empty: true, slot: s });
    }
    return list;
});

// Nomor slot dari sebuah entry chassis (kartu atau kosong).
const slotOf = (entry) => (entry.empty ? entry.slot : Number(entry.card.slot));

// C320: slot line-card (1–2) span penuh; slot kontrol/uplink (≥3) berbagi 2 kolom.
const isWideSlot = (entry) => slotOf(entry) <= 2;

// C300 (vertikal): pasangan slot yang fisiknya ditumpuk atas-bawah dalam 1 kolom
// (kartu power/kontrol PRWG di slot 0–1, dan uplink HUVQ di slot 19–20).
const STACK_PAIRS = [[0, 1], [19, 20]];

// Susun chassisSlots jadi kolom; pasangan STACK_PAIRS digabung jadi satu kolom (2 kartu atas-bawah).
const chassisColumns = computed(() => {
    const entries = chassisSlots.value;
    const bySlot = {};
    for (const e of entries) bySlot[slotOf(e)] = e;

    const partnerOf = {};
    const isBottom = new Set();
    for (const [top, bottom] of STACK_PAIRS) {
        if (bySlot[top] && bySlot[bottom]) {
            partnerOf[top] = bottom;
            isBottom.add(bottom);
        }
    }

    const cols = [];
    for (const e of entries) {
        const s = slotOf(e);
        if (isBottom.has(s)) continue; // sudah ikut kolom slot di atasnya
        cols.push(partnerOf[s] !== undefined
            ? { key: `col${s}`, stack: [e, bySlot[partnerOf[s]]] }
            : { key: `col${s}`, stack: [e] });
    }
    return cols;
});

const isGponCard = (card) => {
    const slotPorts = portsBySlot.value[Number(card.slot)];
    return (slotPorts && Object.keys(slotPorts).length > 0) || GPON_PREFIX.test(card.cfg_type ?? '');
};

const portCount = (card) => {
    const fromCard = Number(card.port_count ?? 0);
    if (fromCard > 0) return fromCard;
    const slotPorts = portsBySlot.value[Number(card.slot)];
    return slotPorts ? Object.keys(slotPorts).length : 0;
};

// Nama interface CLI untuk port tertentu, atau null bila kartu tak punya interface (kontrol/power).
const interfaceName = (card, port) => {
    const cfg = String(card.cfg_type ?? '').toUpperCase();
    if (isGponCard(card)) return `gpon-olt_1/${card.slot}/${port}`;
    if (XGEI_CARDS.includes(cfg)) return `xgei_1/${card.slot}/${port}`;
    if (GEI_CARDS.includes(cfg)) return `gei_1/${card.slot}/${port}`;
    return null;
};

const portDetailHref = (iface) =>
    `${route('smartolt.port.detail', props.oltId)}?interface=${encodeURIComponent(iface)}`;

// Hasilkan daftar LED per kartu: { num, state, live, link, title }
const ledsFor = (card) => {
    const count = portCount(card);
    if (count === 0) return [];

    const gpon = isGponCard(card);
    const slotPorts = portsBySlot.value[Number(card.slot)] ?? {};
    const cardStatus = String(card.status ?? '').toUpperCase();
    const leds = [];

    for (let num = 1; num <= count; num++) {
        const iface = interfaceName(card, num);
        let state;
        let live;
        let title;

        if (gpon) {
            const oper = slotPorts[num];
            state = oper === undefined ? 'empty' : oper === 'up' ? 'up' : 'down';
            live = true;
            const label = state === 'up' ? 'Aktif' : state === 'down' ? 'Nonaktif' : 'Tidak ada data';
            title = `${iface} — ${label}`;
        } else if (iface) {
            // Port uplink: status link real-time dari interface tersimpan (perlu Refresh Hardware).
            const link = linkByInterface.value[iface];
            state = link === 'up' ? 'up' : link === 'down' ? 'down' : 'unknown';
            live = true;
            const label =
                state === 'up' ? 'Aktif' : state === 'down' ? 'Nonaktif' : 'Belum dipoll — klik Refresh Hardware';
            title = `${iface} — ${label}`;
        } else {
            // Kartu kontrol/power tanpa interface: tampilkan status kartu.
            state = cardStatus === 'INSERVICE' ? 'up' : cardStatus === 'STANDBY' ? 'standby' : 'down';
            live = false;
            title = `Port ${num} — ${card.cfg_type} (${card.status})`;
        }

        leds.push({
            num,
            state,
            live,
            link: iface ? portDetailHref(iface) : null,
            title,
        });
    }

    return leds;
};

const ledCols = (card) => {
    if (isHorizontal.value) {
        // Horizontal (C320): semua port 1 baris ke kanan (seperti GTGO).
        return Math.max(1, portCount(card));
    }
    // Vertikal (C300/dll): 1 kolom ke bawah agar kartu ramping.
    return 1;
};

const ledClass = (led) => {
    const base = 'ring-1 transition-transform duration-150 group-hover:scale-110';
    switch (led.state) {
        case 'up':
            return led.live
                ? `${base} bg-emerald-500 ring-emerald-300/50 shadow-[0_0_8px_rgba(16,185,129,0.7)]`
                : `${base} bg-emerald-500/40 ring-emerald-400/30`;
        case 'down':
            return led.live
                ? `${base} bg-red-500 ring-red-300/50 shadow-[0_0_8px_rgba(239,68,68,0.7)]`
                : `${base} bg-red-500/30 ring-red-400/20`;
        case 'standby':
            return `${base} bg-amber-400/60 ring-amber-300/40`;
        default:
            return `${base} bg-slate-700/70 ring-white/10`;
    }
};

const cardStatusDot = (status) => {
    const s = String(status ?? '').toUpperCase();
    if (s === 'INSERVICE') return 'bg-emerald-400';
    if (s === 'STANDBY') return 'bg-amber-400';
    return 'bg-red-400';
};

const cardStatusText = (status) => {
    const s = String(status ?? '').toUpperCase();
    if (s === 'INSERVICE') return 'text-emerald-300';
    if (s === 'STANDBY') return 'text-amber-300';
    return 'text-red-300';
};

// Beban processor per board (CPU/Mem/PhyMem dari SNMP zxAnCardTable). null = board
// tanpa CPU (mis. kartu power) → overlay tidak ditampilkan.
const procFor = (card) => {
    if (!card) return null;
    const cpu = card.cpu_load;
    const mem = card.mem_load;
    const phy = Number(card.phy_mem_mb ?? 0);
    if (cpu == null && mem == null && phy <= 0) return null;
    const clamp = (v) => (v == null ? null : Math.max(0, Math.min(100, Number(v))));
    return { cpu: clamp(cpu), mem: clamp(mem), phy };
};

// Warna bar: cyan/sky normal, amber >70%, merah >85%.
const loadBarClass = (pct, base) => {
    if (pct == null) return 'bg-slate-700';
    if (pct >= 85) return 'bg-red-500';
    if (pct >= 70) return 'bg-amber-400';
    return base;
};

// Detail lengkap untuk tooltip (hover).
const procTitle = (card) => {
    const p = procFor(card);
    if (!p) return '';
    const parts = [`CPU ${p.cpu ?? '—'}%`, `Mem ${p.mem ?? '—'}%`];
    if (p.phy > 0) parts.push(`PhyMem ${p.phy} MB`);
    return parts.join(' · ');
};

// Map beban processor per id card, supaya template tidak menghitung ulang.
const procByCardId = computed(() => {
    const map = {};
    for (const c of props.cards) {
        const p = procFor(c);
        if (p) map[c.id] = p;
    }
    return map;
});

// Ringkasan port GPON live.
const gponUp = computed(() => props.ports.filter((p) => String(p.oper_status).toLowerCase() === 'up').length);
const gponDown = computed(() => props.ports.length - gponUp.value);

const lastRefreshText = computed(() => (props.lastRefresh ? formatDateTime(props.lastRefresh) : '—'));
</script>

<template>
    <div class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
        <div class="flex flex-col gap-3 border-b border-white/10 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-sky-500/15 ring-1 ring-cyan-500/30">
                    <Cpu class="h-5 w-5 text-cyan-400" />
                </div>
                <div>
                    <h3 class="text-base font-semibold text-white">Visualisasi Chassis</h3>
                    <p class="mt-0.5 text-xs text-slate-500">
                        {{ model || 'OLT' }} · {{ sortedCards.length }} card ·
                        <span class="text-emerald-300">{{ gponUp }} port aktif</span> /
                        <span class="text-red-300">{{ gponDown }} nonaktif</span>
                        <span class="text-slate-600"> · Refresh terakhir: {{ lastRefreshText }}</span>
                    </p>
                </div>
            </div>
            <slot name="actions" />
        </div>

        <div v-if="sortedCards.length === 0" class="px-5 py-12 text-center text-sm text-slate-500">
            Belum ada data hardware. Klik <span class="text-slate-300">Refresh Hardware</span> untuk memuat kartu &amp; port.
        </div>

        <!-- Chassis frame -->
        <div v-else class="p-4 sm:p-6">
            <!-- Orientasi vertikal (C300 dll): kartu berdiri, ramping, port 1 kolom -->
            <div v-if="!isHorizontal" class="flex gap-2 overflow-x-auto rounded-xl border border-white/10 bg-gradient-to-b from-slate-950/70 to-slate-900/30 p-4 shadow-inner">
                <!-- Rack rail kiri -->
                <div class="flex w-2.5 flex-shrink-0 flex-col items-center justify-around rounded bg-slate-800/60 py-3">
                    <span v-for="n in 6" :key="n" class="h-1.5 w-1.5 rounded-full bg-slate-600/80"></span>
                </div>

                <div
                    v-for="col in chassisColumns"
                    :key="col.key"
                    class="flex min-w-[60px] max-w-[120px] flex-1 flex-col gap-2"
                >
                    <template v-for="entry in col.stack" :key="entry.key">
                        <!-- Slot kosong -->
                        <div v-if="entry.empty" class="flex flex-1 flex-col overflow-hidden rounded-lg border border-dashed border-white/10 bg-slate-950/20">
                            <div class="border-b border-white/5 bg-white/[0.02] px-1 py-2 text-center">
                                <div class="text-[10px] font-medium uppercase tracking-wide text-slate-600">Slot {{ entry.slot }}</div>
                                <div class="text-sm font-semibold text-slate-700">—</div>
                            </div>
                            <div class="flex flex-1 items-center justify-center p-2">
                                <span class="text-[10px] text-slate-700">kosong</span>
                            </div>
                        </div>

                        <!-- Kartu terpasang -->
                        <div v-else class="flex flex-1 flex-col overflow-hidden rounded-lg border border-white/10 bg-slate-950/50">
                            <div class="border-b border-white/10 bg-white/[0.03] px-1 py-2 text-center">
                                <div class="text-[10px] font-medium uppercase tracking-wide text-slate-500">Slot {{ entry.card.slot }}</div>
                                <div class="truncate text-sm font-bold text-white" :title="entry.card.real_type || entry.card.cfg_type">{{ entry.card.cfg_type || '—' }}</div>
                                <div class="text-[10px] text-slate-600">{{ portCount(entry.card) }}p</div>
                            </div>
                            <div class="flex flex-1 items-center justify-center p-1.5">
                                <div v-if="ledsFor(entry.card).length" class="grid gap-1.5" :style="{ gridTemplateColumns: `repeat(${ledCols(entry.card)}, 1.75rem)` }">
                                    <component
                                        :is="led.link ? Link : 'div'"
                                        v-for="led in ledsFor(entry.card)"
                                        :key="led.num"
                                        :href="led.link || undefined"
                                        :title="led.title"
                                        class="group flex h-7 w-7 items-center justify-center rounded-md text-[10px] font-semibold leading-none text-black/70"
                                        :class="[ledClass(led), led.link ? 'cursor-pointer' : 'cursor-default']"
                                    >{{ led.num }}</component>
                                </div>
                                <span v-else class="py-4 text-center text-[10px] leading-tight text-slate-600">tanpa<br />port</span>
                            </div>
                            <!-- Beban processor (CPU/Mem) — detail saat hover -->
                            <div
                                v-if="procByCardId[entry.card.id]"
                                class="space-y-1 border-t border-white/10 px-1.5 py-1.5"
                                :title="procTitle(entry.card)"
                            >
                                <div class="flex items-center gap-1">
                                    <span class="w-2.5 flex-shrink-0 text-[8px] font-bold leading-none text-cyan-300/80">C</span>
                                    <div class="h-1 flex-1 overflow-hidden rounded-full bg-slate-800">
                                        <div class="h-full rounded-full transition-all" :class="loadBarClass(procByCardId[entry.card.id].cpu, 'bg-cyan-400')" :style="{ width: (procByCardId[entry.card.id].cpu ?? 0) + '%' }"></div>
                                    </div>
                                </div>
                                <div class="flex items-center gap-1">
                                    <span class="w-2.5 flex-shrink-0 text-[8px] font-bold leading-none text-sky-300/80">M</span>
                                    <div class="h-1 flex-1 overflow-hidden rounded-full bg-slate-800">
                                        <div class="h-full rounded-full transition-all" :class="loadBarClass(procByCardId[entry.card.id].mem, 'bg-sky-400')" :style="{ width: (procByCardId[entry.card.id].mem ?? 0) + '%' }"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-center justify-center gap-1 border-t border-white/10 px-1 py-1.5">
                                <span class="h-1.5 w-1.5 flex-shrink-0 rounded-full" :class="cardStatusDot(entry.card.status)"></span>
                                <span class="truncate text-[9px] font-medium uppercase" :class="cardStatusText(entry.card.status)">{{ entry.card.status }}</span>
                            </div>
                        </div>
                    </template>
                </div>

                <!-- Rack rail kanan -->
                <div class="flex w-2.5 flex-shrink-0 flex-col items-center justify-around rounded bg-slate-800/60 py-3">
                    <span v-for="n in 6" :key="n" class="h-1.5 w-1.5 rounded-full bg-slate-600/80"></span>
                </div>
            </div>

            <!-- Orientasi horizontal (C320): line-card span penuh, slot kontrol (≥3) berbagi 2 kolom -->
            <div v-else class="grid grid-cols-2 gap-2 rounded-xl border border-white/10 bg-gradient-to-b from-slate-950/70 to-slate-900/30 p-4 shadow-inner">
                <template v-for="entry in chassisSlots" :key="entry.key">
                    <!-- Slot kosong -->
                    <div v-if="entry.empty" class="flex items-center gap-2 rounded-lg border border-dashed border-white/10 bg-slate-950/20 px-4 py-4" :class="isWideSlot(entry) ? 'col-span-2' : ''">
                        <span class="text-[10px] font-medium uppercase tracking-wide text-slate-600">Slot {{ entry.slot }}</span>
                        <span class="text-xs font-semibold text-slate-700">kosong</span>
                    </div>

                    <!-- Kartu terpasang -->
                    <div v-else class="flex items-center gap-3 rounded-lg border border-white/10 bg-slate-950/50 px-3 py-3 sm:gap-4 sm:px-4" :class="isWideSlot(entry) ? 'col-span-2' : ''">
                        <div class="w-20 flex-shrink-0 sm:w-24">
                            <div class="text-[10px] font-medium uppercase tracking-wide text-slate-500">Slot {{ entry.card.slot }}</div>
                            <div class="truncate text-sm font-bold text-white" :title="entry.card.real_type || entry.card.cfg_type">{{ entry.card.cfg_type || '—' }}</div>
                            <div class="text-[10px] text-slate-600">{{ portCount(entry.card) }} port</div>
                        </div>
                        <div class="flex flex-1 items-center justify-center overflow-x-auto py-1">
                            <div v-if="ledsFor(entry.card).length" class="grid gap-2" :style="{ gridTemplateColumns: `repeat(${ledCols(entry.card)}, 2rem)` }">
                                <component
                                    :is="led.link ? Link : 'div'"
                                    v-for="led in ledsFor(entry.card)"
                                    :key="led.num"
                                    :href="led.link || undefined"
                                    :title="led.title"
                                    class="group flex h-8 w-8 items-center justify-center rounded-md text-[11px] font-semibold leading-none text-black/70"
                                    :class="[ledClass(led), led.link ? 'cursor-pointer' : 'cursor-default']"
                                >{{ led.num }}</component>
                            </div>
                            <span v-else class="text-[11px] text-slate-600">tanpa port</span>
                        </div>
                        <!-- Beban processor (CPU/Mem) — detail saat hover -->
                        <div
                            v-if="procByCardId[entry.card.id]"
                            class="hidden w-28 flex-shrink-0 flex-col gap-1.5 sm:flex"
                            :title="procTitle(entry.card)"
                        >
                            <div class="flex items-center gap-1.5">
                                <span class="w-7 flex-shrink-0 text-[9px] font-medium uppercase tracking-wide text-slate-500">CPU</span>
                                <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-800">
                                    <div class="h-full rounded-full transition-all" :class="loadBarClass(procByCardId[entry.card.id].cpu, 'bg-cyan-400')" :style="{ width: (procByCardId[entry.card.id].cpu ?? 0) + '%' }"></div>
                                </div>
                                <span class="w-7 flex-shrink-0 text-right text-[9px] tabular-nums text-slate-300">{{ procByCardId[entry.card.id].cpu ?? '—' }}%</span>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <span class="w-7 flex-shrink-0 text-[9px] font-medium uppercase tracking-wide text-slate-500">Mem</span>
                                <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-800">
                                    <div class="h-full rounded-full transition-all" :class="loadBarClass(procByCardId[entry.card.id].mem, 'bg-sky-400')" :style="{ width: (procByCardId[entry.card.id].mem ?? 0) + '%' }"></div>
                                </div>
                                <span class="w-7 flex-shrink-0 text-right text-[9px] tabular-nums text-slate-300">{{ procByCardId[entry.card.id].mem ?? '—' }}%</span>
                            </div>
                        </div>
                        <div class="flex w-20 flex-shrink-0 items-center justify-end gap-1.5 sm:w-24">
                            <span class="h-2 w-2 flex-shrink-0 rounded-full" :class="cardStatusDot(entry.card.status)"></span>
                            <span class="truncate text-[10px] font-medium uppercase" :class="cardStatusText(entry.card.status)">{{ entry.card.status }}</span>
                        </div>
                    </div>
                </template>
            </div>

            <!-- Legend + catatan -->
            <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-xs text-slate-400">
                    <span class="flex items-center gap-1.5">
                        <span class="h-3 w-3 rounded bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.7)]"></span> Aktif
                    </span>
                    <span class="flex items-center gap-1.5">
                        <span class="h-3 w-3 rounded bg-red-500 shadow-[0_0_8px_rgba(239,68,68,0.7)]"></span> Nonaktif
                    </span>
                    <span class="flex items-center gap-1.5">
                        <span class="h-3 w-3 rounded bg-amber-400/60"></span> Standby
                    </span>
                    <span class="flex items-center gap-1.5">
                        <span class="h-3 w-3 rounded bg-slate-700"></span> Kosong / belum dipoll
                    </span>
                </div>
                <p class="text-[11px] text-slate-500">
                    Port GPON live dari SNMP; port uplink &amp; bar <span class="text-cyan-300">CPU</span>/<span class="text-sky-300">Mem</span> per board terisi setelah <span class="text-slate-400">Refresh Hardware</span>. Arahkan kursor ke board untuk detail processor; klik port untuk detail (trafik &amp; SFP).
                </p>
            </div>
        </div>
    </div>
</template>
