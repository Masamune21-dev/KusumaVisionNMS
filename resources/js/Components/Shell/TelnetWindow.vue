<script setup>
import { FitAddon } from '@xterm/addon-fit';
import { Terminal } from '@xterm/xterm';
import '@xterm/xterm/css/xterm.css';
import axios from 'axios';
import { Loader2, Maximize2, Minus, TerminalSquare, X } from '@lucide/vue';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
    // { id, name, ip } to open a session, or null to close.
    olt: { type: Object, default: null },
});
const emit = defineEmits(['close']);

const termHost = ref(null);
let term = null;
let fit = null;
let ws = null;
const encoder = new TextEncoder();

const status = ref('idle'); // idle | connecting | connected | closed | error
const statusMessage = ref('');
const minimized = ref(false);
const maximized = ref(false);

const pos = ref({ x: 0, y: 0 });
const size = ref({ w: 780, h: 460 });
let restoreGeom = null;

const statusStyle = computed(() => ({
    idle: 'bg-slate-500',
    connecting: 'bg-amber-400 animate-pulse',
    connected: 'bg-emerald-400',
    closed: 'bg-slate-500',
    error: 'bg-red-500',
}[status.value] ?? 'bg-slate-500'));

const windowStyle = computed(() => {
    if (maximized.value) {
        return { top: '64px', left: '8px', right: '8px', bottom: '8px', width: 'auto', height: 'auto' };
    }
    return {
        top: `${pos.value.y}px`,
        left: `${pos.value.x}px`,
        width: `${size.value.w}px`,
        height: minimized.value ? 'auto' : `${size.value.h}px`,
    };
});

function centerWindow() {
    pos.value = {
        x: Math.max(8, Math.round((window.innerWidth - size.value.w) / 2)),
        y: Math.max(72, Math.round((window.innerHeight - size.value.h) / 3)),
    };
}

// ---- drag (move) ----
let dragStart = null;
function onHeaderDown(e) {
    if (maximized.value) return;
    const p = e.touches ? e.touches[0] : e;
    dragStart = { px: p.clientX, py: p.clientY, x: pos.value.x, y: pos.value.y };
    window.addEventListener('mousemove', onDragMove);
    window.addEventListener('mouseup', onDragUp);
    window.addEventListener('touchmove', onDragMove, { passive: false });
    window.addEventListener('touchend', onDragUp);
}
function onDragMove(e) {
    if (!dragStart) return;
    if (e.cancelable) e.preventDefault();
    const p = e.touches ? e.touches[0] : e;
    let nx = dragStart.x + (p.clientX - dragStart.px);
    let ny = dragStart.y + (p.clientY - dragStart.py);
    nx = Math.min(Math.max(nx, -size.value.w + 120), window.innerWidth - 120);
    ny = Math.min(Math.max(ny, 0), window.innerHeight - 48);
    pos.value = { x: nx, y: ny };
}
function onDragUp() {
    dragStart = null;
    window.removeEventListener('mousemove', onDragMove);
    window.removeEventListener('mouseup', onDragUp);
    window.removeEventListener('touchmove', onDragMove);
    window.removeEventListener('touchend', onDragUp);
}

// ---- resize (SE corner) ----
let resizeStart = null;
function onResizeDown(e) {
    if (maximized.value || minimized.value) return;
    e.stopPropagation();
    const p = e.touches ? e.touches[0] : e;
    resizeStart = { px: p.clientX, py: p.clientY, w: size.value.w, h: size.value.h };
    window.addEventListener('mousemove', onResizeMove);
    window.addEventListener('mouseup', onResizeUp);
    window.addEventListener('touchmove', onResizeMove, { passive: false });
    window.addEventListener('touchend', onResizeUp);
}
function onResizeMove(e) {
    if (!resizeStart) return;
    if (e.cancelable) e.preventDefault();
    const p = e.touches ? e.touches[0] : e;
    size.value = {
        w: Math.max(360, resizeStart.w + (p.clientX - resizeStart.px)),
        h: Math.max(220, resizeStart.h + (p.clientY - resizeStart.py)),
    };
    fit?.fit();
}
function onResizeUp() {
    resizeStart = null;
    window.removeEventListener('mousemove', onResizeMove);
    window.removeEventListener('mouseup', onResizeUp);
    window.removeEventListener('touchmove', onResizeMove);
    window.removeEventListener('touchend', onResizeUp);
    fit?.fit();
}

// ---- terminal + websocket ----
function setupTerm() {
    if (term) return;
    term = new Terminal({
        cursorBlink: true,
        fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace',
        fontSize: 13,
        scrollback: 5000,
        theme: { background: '#0b1220', foreground: '#e2e8f0', cursor: '#22d3ee' },
    });
    fit = new FitAddon();
    term.loadAddon(fit);
    term.open(termHost.value);
    fit.fit();
    term.onData((d) => {
        if (ws && ws.readyState === WebSocket.OPEN) {
            ws.send(encoder.encode(d));
        }
    });
}

async function start() {
    status.value = 'connecting';
    statusMessage.value = 'Meminta token…';
    await nextTick();
    setupTerm();
    try {
        const { data } = await axios.post(route('smartolt.telnet.token', props.olt.id));
        connect(data.ws_url);
    } catch (e) {
        status.value = 'error';
        statusMessage.value = e?.response?.data?.message ?? 'Gagal mendapatkan token telnet.';
        term?.writeln(`\x1b[31m${statusMessage.value}\x1b[0m`);
    }
}

function connect(url) {
    statusMessage.value = 'Menyambung…';
    ws = new WebSocket(url);
    ws.binaryType = 'arraybuffer';
    ws.onopen = () => {
        status.value = 'connected';
        statusMessage.value = 'Tersambung';
        nextTick(() => fit?.fit());
    };
    ws.onmessage = (ev) => {
        const data = typeof ev.data === 'string' ? ev.data : new Uint8Array(ev.data);
        term?.write(data);
    };
    ws.onclose = () => {
        if (status.value !== 'error') {
            status.value = 'closed';
            statusMessage.value = 'Koneksi ditutup';
        }
        term?.writeln('\r\n\x1b[33m[koneksi ditutup]\x1b[0m');
    };
    ws.onerror = () => {
        status.value = 'error';
        statusMessage.value = 'WebSocket error — cek daemon telnet:proxy';
    };
}

function cleanup() {
    try {
        ws?.close();
    } catch (e) { /* ignore */ }
    ws = null;
    try {
        term?.dispose();
    } catch (e) { /* ignore */ }
    term = null;
    fit = null;
    status.value = 'idle';
}

function close() {
    cleanup();
    emit('close');
}

function toggleMin() {
    minimized.value = !minimized.value;
    if (!minimized.value) {
        nextTick(() => fit?.fit());
    }
}

function toggleMax() {
    if (maximized.value) {
        maximized.value = false;
        if (restoreGeom) {
            pos.value = { ...restoreGeom.pos };
            size.value = { ...restoreGeom.size };
        }
    } else {
        restoreGeom = { pos: { ...pos.value }, size: { ...size.value } };
        maximized.value = true;
    }
    nextTick(() => fit?.fit());
}

function onWindowResize() {
    if (!minimized.value) {
        fit?.fit();
    }
}

watch(
    () => props.olt,
    (olt) => {
        if (olt) {
            minimized.value = false;
            maximized.value = false;
            centerWindow();
            window.addEventListener('resize', onWindowResize);
            start();
        } else {
            cleanup();
            window.removeEventListener('resize', onWindowResize);
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    cleanup();
    window.removeEventListener('resize', onWindowResize);
    onDragUp();
    onResizeUp();
});
</script>

<template>
    <Teleport to="body">
        <div
            v-if="olt"
            class="fixed z-[120] flex flex-col overflow-hidden rounded-xl border border-white/10 bg-slate-950/95 shadow-2xl shadow-black/60 backdrop-blur-xl"
            :style="windowStyle"
        >
            <!-- Title bar (drag handle) -->
            <div
                class="flex flex-shrink-0 cursor-move select-none items-center gap-2 border-b border-white/10 bg-slate-900/80 px-3 py-2"
                @mousedown="onHeaderDown"
                @touchstart="onHeaderDown"
                @dblclick="toggleMax"
            >
                <TerminalSquare class="h-4 w-4 flex-shrink-0 text-cyan-400" />
                <span class="h-2 w-2 flex-shrink-0 rounded-full" :class="statusStyle" />
                <div class="min-w-0 flex-1">
                    <span class="truncate text-xs font-semibold text-slate-100">Telnet · {{ olt.name }}</span>
                    <span class="ml-2 truncate text-[11px] text-slate-500">{{ olt.ip }} · {{ statusMessage }}</span>
                </div>
                <div class="flex flex-shrink-0 items-center gap-1">
                    <button type="button" class="rounded p-1 text-slate-400 transition-colors hover:bg-white/10 hover:text-white" title="Minimize" @mousedown.stop @click="toggleMin">
                        <Minus class="h-4 w-4" />
                    </button>
                    <button type="button" class="rounded p-1 text-slate-400 transition-colors hover:bg-white/10 hover:text-white" :title="maximized ? 'Restore' : 'Maximize'" @mousedown.stop @click="toggleMax">
                        <Maximize2 class="h-4 w-4" />
                    </button>
                    <button type="button" class="rounded p-1 text-slate-400 transition-colors hover:bg-red-500/20 hover:text-red-300" title="Tutup" @mousedown.stop @click="close">
                        <X class="h-4 w-4" />
                    </button>
                </div>
            </div>

            <!-- Terminal body -->
            <div v-show="!minimized" class="relative min-h-0 flex-1 bg-[#0b1220]">
                <div
                    v-if="status === 'connecting'"
                    class="pointer-events-none absolute left-1/2 top-3 z-10 flex -translate-x-1/2 items-center gap-2 rounded-full bg-slate-900/80 px-3 py-1 text-xs text-slate-300 ring-1 ring-white/10"
                >
                    <Loader2 class="h-3.5 w-3.5 animate-spin text-cyan-400" />
                    {{ statusMessage }}
                </div>
                <div ref="termHost" class="h-full w-full px-2 py-1" />

                <!-- SE resize handle -->
                <div
                    class="absolute bottom-0 right-0 h-4 w-4 cursor-se-resize"
                    @mousedown="onResizeDown"
                    @touchstart="onResizeDown"
                >
                    <svg viewBox="0 0 10 10" class="h-full w-full text-slate-600"><path d="M9 1v8H1" fill="none" stroke="currentColor" stroke-width="1.2" opacity="0.6" /></svg>
                </div>
            </div>
        </div>
    </Teleport>
</template>
