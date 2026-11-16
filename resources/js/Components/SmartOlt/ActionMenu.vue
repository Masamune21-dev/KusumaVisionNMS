<script setup>
import { Link } from '@inertiajs/vue3';
import { MoreHorizontal } from '@lucide/vue';
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';

/**
 * Menu aksi «⋯» untuk baris tabel/kartu (SmartOLT). Isi menu di-teleport ke <body> supaya
 * tidak terpotong `overflow-x-auto` pembungkus tabel; posisinya dihitung dari tombol pemicu
 * dan dibalik ke atas bila ruang di bawah tak cukup.
 *
 * `items`: [{ key, label, icon?, href?, onClick?, variant?: 'danger'|'warning'|'success',
 *             disabled?, group? }] — item tanpa `group` ikut grup sebelumnya; item ber-`variant`
 * danger sebaiknya ditaruh paling akhir (aturan kv-ui: hapus paling kanan/bawah).
 * `groups`: { [group]: label } — judul kelompok (opsional).
 */
const props = defineProps({
    items: { type: Array, default: () => [] },
    groups: { type: Object, default: () => ({}) },
    title: { type: String, default: '' },
    align: { type: String, default: 'right' },
    // Bila diisi, pemicu tampil sebagai tombol teks sekunder (untuk kepala halaman), bukan ikon ⋯.
    label: { type: String, default: '' },
});

const open = ref(false);
const trigger = ref(null);
const panel = ref(null);
const pos = ref({ top: 0, left: 0, placeUp: false });
const WIDTH = 240;

// Kelompokkan berurutan; grup kosong tak dirender.
const sections = computed(() => {
    const out = [];
    let current = null;
    for (const item of props.items) {
        const g = item.group ?? current?.key ?? '_';
        if (!current || current.key !== g) {
            current = { key: g, label: props.groups[g] ?? null, items: [] };
            out.push(current);
        }
        current.items.push(item);
    }
    return out;
});

const place = () => {
    const el = trigger.value;
    if (!el) return;
    const r = el.getBoundingClientRect();
    const vw = window.innerWidth;
    const vh = window.innerHeight;
    const width = Math.min(WIDTH, vw - 16);
    let left = props.align === 'left' ? r.left : r.right - width;
    left = Math.max(8, Math.min(left, vw - width - 8));
    const h = panel.value?.offsetHeight ?? 320;
    const placeUp = r.bottom + 6 + h > vh - 8 && r.top - 6 - h > 8;
    pos.value = { top: placeUp ? r.top - 6 - h : r.bottom + 6, left, placeUp, width };
};

const menuItems = () => Array.from(panel.value?.querySelectorAll('[role="menuitem"]:not([disabled])') ?? []);

const show = async () => {
    open.value = true;
    place();
    await nextTick();
    place();
    menuItems()[0]?.focus();
    window.addEventListener('scroll', onScroll, true);
    window.addEventListener('resize', onResize);
    document.addEventListener('pointerdown', onOutside, true);
};

const close = (refocus = false) => {
    if (!open.value) return;
    open.value = false;
    window.removeEventListener('scroll', onScroll, true);
    window.removeEventListener('resize', onResize);
    document.removeEventListener('pointerdown', onOutside, true);
    if (refocus === true) trigger.value?.focus();
};

// Gulir di dalam menu sendiri tidak menutup; gulir halaman/tabel menutup (posisi fixed jadi basi).
const onScroll = (e) => { if (!panel.value?.contains(e.target)) close(); };
const onResize = () => close();

const toggle = () => (open.value ? close() : show());

const onOutside = (e) => {
    if (panel.value?.contains(e.target) || trigger.value?.contains(e.target)) return;
    close();
};

const onKeydown = (e) => {
    const list = menuItems();
    const i = list.indexOf(document.activeElement);
    if (e.key === 'Escape') { e.preventDefault(); close(true); }
    else if (e.key === 'ArrowDown') { e.preventDefault(); list[(i + 1) % list.length]?.focus(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); list[(i - 1 + list.length) % list.length]?.focus(); }
    else if (e.key === 'Home') { e.preventDefault(); list[0]?.focus(); }
    else if (e.key === 'End') { e.preventDefault(); list[list.length - 1]?.focus(); }
    else if (e.key === 'Tab') { close(); }
};

const run = (item) => {
    if (item.disabled) return;
    close();
    item.onClick?.();
};

const itemClass = (item) => {
    const tone = {
        danger: 'text-rose-300 hover:bg-rose-500/15 focus-visible:bg-rose-500/15',
        warning: 'text-amber-300 hover:bg-amber-500/10 focus-visible:bg-amber-500/10',
        success: 'text-emerald-300 hover:bg-emerald-500/10 focus-visible:bg-emerald-500/10',
    }[item.variant] ?? 'text-slate-200 hover:bg-white/5 focus-visible:bg-white/5';
    return `flex min-h-11 w-full items-center gap-3 rounded-lg px-3 text-left text-sm font-medium transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-cyan-400/60 disabled:cursor-not-allowed disabled:opacity-50 sm:min-h-9 ${tone}`;
};

onBeforeUnmount(() => close());
</script>

<template>
    <button
        ref="trigger"
        type="button"
        :title="label ? undefined : (title || $t('actionmenu.more'))"
        :aria-label="label ? undefined : (title || $t('actionmenu.more'))"
        aria-haspopup="menu"
        :aria-expanded="open ? 'true' : 'false'"
        :class="label
            ? ['kv-btn-secondary-base inline-flex w-full sm:w-auto min-h-11 items-center justify-center gap-2 rounded-xl border border-slate-700/80 bg-slate-900/80 px-5 text-sm font-semibold text-slate-200 transition-colors duration-150 hover:bg-slate-800 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-cyan-400/60 focus-visible:ring-offset-2 focus-visible:ring-offset-canvas', open ? 'border-cyan-500/50' : '']
            : ['inline-flex h-11 w-11 items-center justify-center rounded-lg ring-1 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-cyan-400/60 sm:h-9 sm:w-9', open ? 'bg-cyan-500/20 text-cyan-200 ring-cyan-500/40' : 'bg-slate-500/15 text-slate-300 ring-slate-500/30 hover:bg-slate-500/25 hover:text-slate-100']"
        @click.stop="toggle"
    >
        <MoreHorizontal class="h-4 w-4" />
        <span v-if="label">{{ label }}</span>
    </button>

    <Teleport to="body">
        <div
            v-if="open"
            ref="panel"
            role="menu"
            class="kv-popover fixed z-[70] max-h-[70vh] overflow-y-auto rounded-xl border border-white/10 bg-slate-900 p-1.5 shadow-2xl shadow-black/60"
            :style="{ top: `${pos.top}px`, left: `${pos.left}px`, width: `${pos.width ?? WIDTH}px` }"
            @keydown="onKeydown"
        >
            <div
                v-for="(section, si) in sections"
                :key="section.key"
                :class="si > 0 ? 'mt-1 border-t border-white/10 pt-1' : ''"
            >
                <p v-if="section.label" class="px-3 pb-1 pt-1.5 text-xs font-semibold text-slate-500">{{ section.label }}</p>
                <template v-for="item in section.items" :key="item.key">
                    <Link
                        v-if="item.href && !item.disabled"
                        :href="item.href"
                        role="menuitem"
                        :class="itemClass(item)"
                        @click="close()"
                    >
                        <component :is="item.icon" v-if="item.icon" class="h-4 w-4 shrink-0" />
                        <span class="min-w-0 flex-1 truncate">{{ item.label }}</span>
                    </Link>
                    <button
                        v-else
                        type="button"
                        role="menuitem"
                        :disabled="item.disabled"
                        :class="itemClass(item)"
                        @click="run(item)"
                    >
                        <component :is="item.icon" v-if="item.icon" class="h-4 w-4 shrink-0" />
                        <span class="min-w-0 flex-1 truncate">{{ item.label }}</span>
                    </button>
                </template>
            </div>
        </div>
    </Teleport>
</template>
