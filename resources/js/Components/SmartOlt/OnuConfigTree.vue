<script setup>
import ConfirmModal from '@/Components/ConfirmModal.vue';
import DangerButton from '@/Components/DangerButton.vue';
import IconButton from '@/Components/IconButton.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { formatTime } from '@/lib/datetime';
import { findItem, isLockedRow, normalizeRow, rowIdentity, sections, validateRow } from '@/lib/onuConfigSections';
import {
    Check, CheckCircle2, ChevronDown, ChevronRight, Cloud, Copy, Cpu, FileText, Globe, Inbox, Link2, ListChecks,
    Lock, Network, Pencil, Plus, RefreshCw, Router, Search, Settings, ShieldCheck, Tag, Terminal, Trash2,
    TriangleAlert, Unlock, X,
} from '@lucide/vue';
import axios from 'axios';
import { computed, nextTick, onUnmounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * Editor ONU per-bagian, mengikuti pola NetNumen: pohon bagian di kiri, tabel di kanan, dan
 * Tambah/Ubah/Hapus yang OK-nya LANGSUNG mengirim satu perubahan ke OLT. Setelah tiap perubahan,
 * running-config dibaca ulang dari OLT dan menjadi baseline baru — layar selalu menampilkan
 * keadaan perangkat, bukan harapan klien. CLI tetap disusun ZteOnuReconfigureScriptBuilder.
 */
const props = defineProps({
    olt: { type: Object, required: true },
    slot: { type: Number, required: true },
    port: { type: Number, required: true },
    onuId: { type: Number, required: true },
    interfaceName: { type: String, default: '' },
    serial: { type: String, default: null },
    config: { type: Object, required: true },
    raw: { type: String, default: '' },
    profiles: { type: Object, default: () => ({}) },
    canWrite: { type: Boolean, default: false },
});

const emit = defineEmits(['updated']);

const { t } = useI18n({ useScope: 'global' });
const { confirmState, confirm, handleConfirm, handleCancel } = useConfirm();

const icons = { Cloud, Cpu, FileText, Globe, Link2, ListChecks, Lock, Network, Router, Settings, ShieldCheck, Tag };
// Label grup dipetakan eksplisit (jangan merakit kunci i18n dari string).
const groupLabels = {
    basic: 'onucfg.group_basic',
    line: 'onucfg.group_line',
    vport: 'onucfg.group_vport',
    pon: 'onucfg.group_pon',
    wan: 'onucfg.group_wan',
    security: 'onucfg.group_security',
};
const tr = (label) => (typeof label === 'string' && label.startsWith('onucfg.') ? t(label) : label);
const clone = (value) => JSON.parse(JSON.stringify(value ?? null));

// --- keadaan dari OLT ---
const baseline = ref(clone(props.config));
const rawText = ref(props.raw);
const readAt = ref(new Date());
watch(() => props.config, (value) => { baseline.value = clone(value); readAt.value = new Date(); });
watch(() => props.raw, (value) => { rawText.value = value; });

const applyLive = (data) => {
    if (data?.fetch_ok && data.config) {
        baseline.value = data.config;
        rawText.value = data.raw ?? rawText.value;
        readAt.value = new Date();
        emit('updated', { config: data.config, raw: data.raw });
    }
};

const primaryVlan = computed(() => baseline.value?.primary_vlan ?? null);
const stats = computed(() => [
    { key: 'tconts', label: 'T-CONT', value: (baseline.value?.tconts ?? []).length },
    { key: 'gemports', label: 'GEM', value: (baseline.value?.gemports ?? []).length },
    { key: 'service_ports', label: 'Service-port', value: (baseline.value?.service_ports ?? []).length },
    { key: 'services', label: 'Service', value: (baseline.value?.services ?? []).length },
    { key: 'wan_ips', label: 'WAN-IP', value: (baseline.value?.wan_ips ?? []).length },
]);

// --- navigasi ---
const activeKey = ref('tconts');
const active = computed(() => findItem(activeKey.value));
const collapsed = reactive({});
const navQuery = ref('');
const selectItem = (key) => { activeKey.value = key; };

const filteredSections = computed(() => {
    const q = navQuery.value.trim().toLowerCase();
    if (!q) return sections;
    return sections
        .map((group) => ({ ...group, items: group.items.filter((item) => tr(item.label).toLowerCase().includes(q) || (item.cli ?? '').includes(q)) }))
        .filter((group) => group.items.length);
});

const rows = computed(() => (active.value.kind === 'table' ? baseline.value?.[active.value.path] ?? [] : []));
const countOf = (item) => (item.kind === 'table' ? (baseline.value?.[item.path] ?? []).length : null);
const cell = (column, row) => {
    const value = column.fmt ? column.fmt(row) : row[column.key];
    return value === null || value === undefined || value === '' ? '—' : value;
};
const locked = (row) => isLockedRow(active.value, row, baseline.value);
const lockedCount = computed(() => rows.value.filter((row) => locked(row)).length);

// Sorotan singkat pada baris yang barusan berubah.
const flashKey = ref(null);
let flashTimer = null;
const flash = (item, row) => {
    flashKey.value = row ? `${item.key}:${rowIdentity(item, row)}` : null;
    clearTimeout(flashTimer);
    flashTimer = setTimeout(() => { flashKey.value = null; }, 2600);
};
const isFlashed = (row) => flashKey.value === `${active.value.key}:${rowIdentity(active.value, row)}`;

// --- toast lokal ---
const toasts = ref([]);
let toastSeq = 0;
const toast = (type, message) => {
    const id = ++toastSeq;
    toasts.value = [...toasts.value, { id, type, message }];
    setTimeout(() => { toasts.value = toasts.value.filter((x) => x.id !== id); }, type === 'error' ? 7000 : 4000);
};

// --- dialog Tambah/Ubah ---
const dialog = reactive({ open: false, mode: 'add', index: null, row: {}, error: null });
const preview = reactive({ script: '', conflicts: [], loading: false });
const copied = ref(false);
let previewTimer = null;

const buildTarget = (item, change) => {
    const target = clone(baseline.value);
    if (item.kind === 'form') {
        Object.assign(target, change.values);
        return target;
    }
    const list = [...(target[item.path] ?? [])];
    if (change.type === 'add') list.push(change.row);
    if (change.type === 'edit') list[change.index] = change.row;
    if (change.type === 'delete') {
        if (item.deleteAs) list[change.index] = item.deleteAs(list[change.index]);
        else list.splice(change.index, 1);
    }
    target[item.path] = list;
    return target;
};

const currentChange = () => (active.value.kind === 'form'
    ? { values: normalizeRow(active.value, dialog.row) }
    : { type: dialog.mode, index: dialog.index, row: normalizeRow(active.value, dialog.row) });

const runPreview = async () => {
    if (!dialog.open || !props.canWrite) return;
    preview.loading = true;
    try {
        const { data } = await axios.post(route('smartolt.onu.configure.preview', [props.olt.id, props.slot, props.port, props.onuId]), {
            baseline: baseline.value,
            config: buildTarget(active.value, currentChange()),
        });
        preview.script = data.script ?? '';
        preview.conflicts = data.profile_conflicts ?? [];
    } catch {
        preview.script = '';
        preview.conflicts = [];
    } finally {
        preview.loading = false;
    }
};

watch(() => dialog.row, () => {
    dialog.error = null;
    preview.loading = true;
    clearTimeout(previewTimer);
    previewTimer = setTimeout(runPreview, 350);
}, { deep: true });

const focusFirstField = () => nextTick(() => {
    document.querySelector('#onucfg-dialog input:not([disabled]), #onucfg-dialog select:not([disabled])')?.focus();
});

const openAdd = () => {
    Object.assign(dialog, { open: true, mode: 'add', index: null, row: active.value.newRow(baseline.value, props.profiles), error: null });
    focusFirstField();
};
const openEdit = (index = null) => {
    if (active.value.kind === 'form') {
        const values = {};
        for (const field of active.value.fields) values[field.key] = clone(baseline.value?.[field.key]);
        Object.assign(dialog, { open: true, mode: 'edit', index: null, row: values, error: null });
    } else {
        if (index === null || locked(rows.value[index])) return;
        Object.assign(dialog, { open: true, mode: 'edit', index, row: clone(rows.value[index]), error: null });
    }
    focusFirstField();
};
const closeDialog = () => {
    if (busy.value) return;
    dialog.open = false;
    preview.script = '';
    preview.conflicts = [];
};

const visibleFields = computed(() => (active.value.fields ?? []).filter((f) => !f.showIf || f.showIf(dialog.row)));
const optionsFor = (field) => {
    const list = field.options ? field.options(props.profiles) : [];
    const current = dialog.row[field.key];
    return field.allowCustom && current && !list.includes(current) ? [...list, current] : list;
};
const toggleMulti = (field, option) => {
    const list = Array.isArray(dialog.row[field.key]) ? [...dialog.row[field.key]] : [];
    const at = list.indexOf(option);
    if (at === -1) list.push(option); else list.splice(at, 1);
    dialog.row[field.key] = list;
};
const previewLines = computed(() => preview.script.split('\n').filter((line) => line.trim() && !['conf t', 'exit'].includes(line.trim())).length);

const copyPreview = async () => {
    try {
        await navigator.clipboard.writeText(preview.script);
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 1500);
    } catch { /* clipboard tak tersedia */ }
};

// --- kirim ke OLT ---
const busy = ref(false);
const log = ref([]);
const pushLog = (entry) => { log.value = [{ at: new Date(), ...entry }, ...log.value].slice(0, 20); };

const errorText = (data) => {
    if (data?.errors) return Object.values(data.errors).flat()[0];
    if (data?.error === 'no_change') return t('onucfg.err_no_change');
    if (data?.error === 'profile_locked') return t('onucfg.err_profile_locked', { profile: data.profile ?? '' });
    return data?.message || t('onucfg.err_generic');
};

const send = async (item, change, label) => {
    busy.value = true;
    try {
        const { data } = await axios.post(route('smartolt.onu.configure.item', [props.olt.id, props.slot, props.port, props.onuId]), {
            baseline: baseline.value,
            config: buildTarget(item, change),
        });
        applyLive(data);
        pushLog({ label, ok: data.ok, script: data.script, message: data.ok ? null : data.message });
        if (data.ok) toast('success', t('onucfg.toast_ok', { action: label }));
        else toast('error', data.message || t('onucfg.err_generic'));
        return data.ok ? null : (data.message || t('onucfg.err_generic'));
    } catch (e) {
        const message = errorText(e?.response?.data);
        pushLog({ label, ok: false, script: e?.response?.data?.script ?? null, message });
        toast('error', message);
        return message;
    } finally {
        busy.value = false;
    }
};

const submitDialog = async () => {
    const item = active.value;
    const row = normalizeRow(item, dialog.row);
    const invalid = validateRow(item, row, rows.value, dialog.mode === 'edit' ? dialog.index : null);
    if (invalid) {
        dialog.error = t(invalid.key, { ...invalid.params, field: tr(invalid.params.field ?? '') });
        return;
    }
    const label = `${dialog.mode === 'add' ? t('onucfg.action_add') : t('onucfg.action_edit')} · ${tr(item.label)}`;
    const error = await send(item, currentChange(), label);
    if (error) {
        dialog.error = error;
        return;
    }
    dialog.open = false;
    if (item.kind === 'table') flash(item, row);
};

const removeRow = async (index) => {
    const item = active.value;
    const row = rows.value[index];
    if (!row || locked(row)) return;
    const title = item.columns.slice(0, 2).map((c) => cell(c, row)).join(' · ');
    const ok = await confirm({
        title: t('onucfg.delete_title', { section: tr(item.label) }),
        message: t('onucfg.delete_msg', { row: title }),
        confirmLabel: t('common.delete'),
        variant: 'danger',
    });
    if (!ok) return;
    await send(item, { type: 'delete', index }, `${t('onucfg.action_delete')} · ${tr(item.label)} ${title}`);
};

// --- muat ulang & lepas profile ---
const reload = () => emit('updated', { reload: true });

const unbinding = ref(false);
const unbindResult = ref(null);
const unbindProfile = async () => {
    const profile = baseline.value?.onu_profile;
    const ok = await confirm({
        title: t('onucfg.unbind_title', { profile }),
        message: t('onucfg.unbind_msg', { profile, onuId: props.onuId }),
        confirmLabel: t('onucfg.unbind_confirm'),
        variant: 'warning',
    });
    if (!ok) return;
    unbinding.value = true;
    busy.value = true;
    unbindResult.value = null;
    try {
        const { data } = await axios.post(route('smartolt.onu.configure.unbind-profile', [props.olt.id, props.slot, props.port, props.onuId]));
        unbindResult.value = data;
    } catch (e) {
        unbindResult.value = e?.response?.data ?? { ok: false, message: t('onucfg.err_generic') };
    } finally {
        const data = unbindResult.value;
        applyLive(data);
        const message = data?.ok ? null : (data?.remaining ? t('onucfg.unbind_remaining') : errorText(data));
        pushLog({ label: t('onucfg.unbind_log', { profile }), ok: !!data?.ok, script: data?.script ?? null, message });
        toast(data?.ok ? 'success' : 'error', data?.ok ? t('onucfg.unbind_ok') : message);
        unbinding.value = false;
        busy.value = false;
    }
};

const timeLabel = (date) => formatTime(date);

onUnmounted(() => { clearTimeout(previewTimer); clearTimeout(flashTimer); });
</script>

<template>
    <div class="space-y-5">
        <!-- Identitas ONU -->
        <section class="kv-surface rounded-xl border border-white/10 bg-slate-900/60 p-4 shadow-lg shadow-black/30 sm:p-5">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex min-w-0 items-start gap-3">
                    <div class="kv-icon-tile h-11 w-11"><Router class="h-5 w-5" /></div>
                    <div class="min-w-0">
                        <h3 class="truncate text-base font-semibold text-slate-100">{{ baseline?.name || $t('onucfg.unnamed') }}</h3>
                        <p class="mt-0.5 flex flex-wrap gap-x-3 gap-y-1 font-mono text-xs text-slate-400">
                            <span>{{ interfaceName }}</span>
                            <span v-if="serial">SN {{ serial }}</span>
                            <span v-if="primaryVlan">VLAN {{ primaryVlan }}</span>
                        </p>
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <span v-if="baseline?.onu_profile" class="kv-pill kv-pill-warning inline-flex items-center gap-1">
                                <Lock class="h-3 w-3" /> {{ $t('onucfg.pill_profile', { profile: baseline.onu_profile }) }}
                            </span>
                            <span v-else class="kv-pill kv-pill-success">{{ $t('onucfg.pill_manual') }}</span>
                            <span v-if="busy" class="kv-pill kv-pill-info inline-flex items-center gap-1">
                                <RefreshCw class="h-3 w-3 animate-spin" /> {{ $t('onucfg.pill_sending') }}
                            </span>
                            <span v-else class="kv-pill kv-pill-muted">{{ $t('onucfg.pill_read_at', { time: timeLabel(readAt) }) }}</span>
                            <span v-if="!canWrite" class="kv-pill kv-pill-info">{{ $t('onucfg.pill_readonly') }}</span>
                        </div>
                    </div>
                </div>

                <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                    <div class="hidden grid-cols-5 gap-2 text-center sm:grid">
                        <button
                            v-for="stat in stats"
                            :key="stat.key"
                            type="button"
                            class="rounded-lg border border-white/10 bg-canvas-3/40 px-2 py-1.5 transition hover:border-cyan-500/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cyan-400/60"
                            :class="activeKey === stat.key ? 'border-cyan-500/40 bg-cyan-500/10' : ''"
                            @click="selectItem(stat.key)"
                        >
                            <span class="block text-base font-semibold tabular-nums text-slate-100">{{ stat.value }}</span>
                            <span class="block truncate text-xs text-slate-500">{{ stat.label }}</span>
                        </button>
                    </div>
                    <SecondaryButton type="button" :disabled="busy" :title="$t('onucfg.reload_hint')" @click="reload">
                        <RefreshCw class="mr-2 h-4 w-4" /> {{ $t('onucfg.reload') }}
                    </SecondaryButton>
                </div>
            </div>
        </section>

        <!-- Navigasi HP: chip gulir -->
        <nav class="-mx-4 overflow-x-auto px-4 lg:hidden" :aria-label="$t('onucfg.tree_label')">
            <div class="flex w-max gap-2 pb-1">
                <template v-for="group in sections" :key="group.group">
                    <button
                        v-for="item in group.items"
                        :key="item.key"
                        type="button"
                        class="inline-flex min-h-11 items-center gap-2 whitespace-nowrap rounded-xl border px-3 text-sm transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cyan-400/60"
                        :class="activeKey === item.key ? 'border-cyan-500/40 bg-cyan-500/15 text-cyan-200' : 'border-white/10 bg-slate-900/60 text-slate-300'"
                        @click="selectItem(item.key)"
                    >
                        <component :is="icons[item.icon] ?? Settings" class="h-4 w-4 text-cyan-400" />
                        {{ tr(item.label) }}
                        <span v-if="countOf(item) !== null" class="rounded-md bg-white/5 px-1.5 text-xs tabular-nums text-slate-400">{{ countOf(item) }}</span>
                    </button>
                </template>
            </div>
        </nav>

        <div class="grid grid-cols-[minmax(0,1fr)] gap-5 lg:grid-cols-[minmax(0,288px)_minmax(0,1fr)]">
            <!-- Pohon bagian (layar lebar) -->
            <nav class="kv-surface hidden self-start rounded-xl border border-white/10 bg-slate-900/60 p-3 shadow-lg shadow-black/30 lg:sticky lg:top-4 lg:block" :aria-label="$t('onucfg.tree_label')">
                <div class="relative mb-2">
                    <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" />
                    <input
                        v-model="navQuery"
                        type="search"
                        class="kv-input block min-h-10 w-full pl-9 text-sm"
                        :placeholder="$t('onucfg.nav_search')"
                        :aria-label="$t('onucfg.nav_search')"
                    />
                </div>
                <div v-for="group in filteredSections" :key="group.group" class="py-0.5">
                    <button
                        type="button"
                        class="flex min-h-9 w-full items-center gap-1.5 rounded-lg px-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-400 hover:text-slate-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cyan-400/60"
                        :aria-expanded="!collapsed[group.group]"
                        @click="collapsed[group.group] = !collapsed[group.group]"
                    >
                        <ChevronRight v-if="collapsed[group.group]" class="h-3.5 w-3.5" />
                        <ChevronDown v-else class="h-3.5 w-3.5" />
                        {{ $t(groupLabels[group.group]) }}
                    </button>
                    <ul v-show="!collapsed[group.group]" class="mb-1 ml-3 space-y-0.5 border-l border-white/10 pl-2">
                        <li v-for="item in group.items" :key="item.key">
                            <button
                                type="button"
                                class="group flex min-h-10 w-full items-center gap-2.5 rounded-lg px-2.5 text-left text-sm transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cyan-400/60"
                                :class="activeKey === item.key ? 'bg-cyan-500/15 text-cyan-100 ring-1 ring-cyan-500/30' : 'text-slate-300 hover:bg-white/5 hover:text-slate-100'"
                                :aria-current="activeKey === item.key ? 'page' : undefined"
                                @click="selectItem(item.key)"
                            >
                                <component :is="icons[item.icon] ?? Settings" class="h-4 w-4 shrink-0" :class="activeKey === item.key ? 'text-cyan-300' : 'text-slate-500 group-hover:text-cyan-400'" />
                                <span class="min-w-0 flex-1 py-1.5 leading-snug">{{ tr(item.label) }}</span>
                                <Lock v-if="item.kind === 'profile' && baseline?.onu_profile" class="h-3.5 w-3.5 text-amber-300" />
                                <span v-else-if="countOf(item) !== null" class="rounded-md bg-white/5 px-1.5 text-xs tabular-nums text-slate-400">{{ countOf(item) }}</span>
                            </button>
                        </li>
                    </ul>
                </div>
                <p v-if="!filteredSections.length" class="px-2 py-4 text-center text-xs text-slate-500">{{ $t('onucfg.nav_empty') }}</p>
            </nav>

            <!-- Panel bagian aktif -->
            <div class="min-w-0 space-y-5">
                <section class="kv-table-card relative">
                    <!-- bilah progres saat mengirim -->
                    <div v-if="busy" class="absolute inset-x-0 top-0 h-0.5 overflow-hidden rounded-t-lg bg-cyan-500/10">
                        <div class="h-full w-1/3 animate-pulse bg-cyan-400"></div>
                    </div>

                    <header class="flex flex-col gap-3 border-b border-white/10 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                        <div class="flex min-w-0 items-start gap-3">
                            <div class="kv-icon-tile"><component :is="icons[active.icon] ?? Settings" class="h-4 w-4" /></div>
                            <div class="min-w-0">
                                <h3 class="text-base font-semibold text-slate-100">{{ tr(active.label) }}</h3>
                                <p class="mt-0.5 text-sm text-slate-400">{{ $t(active.desc) }}</p>
                                <p class="mt-1 font-mono text-xs text-slate-500">{{ active.cli }}</p>
                            </div>
                        </div>
                        <div class="flex shrink-0 flex-wrap gap-2">
                            <PrimaryButton v-if="canWrite && active.kind === 'table'" type="button" :disabled="busy" @click="openAdd">
                                <Plus class="h-4 w-4" /> {{ $t('onucfg.action_add') }}
                            </PrimaryButton>
                            <PrimaryButton v-if="canWrite && active.kind === 'form'" type="button" :disabled="busy" @click="openEdit()">
                                <Pencil class="h-4 w-4" /> {{ $t('onucfg.action_edit') }}
                            </PrimaryButton>
                        </div>
                    </header>

                    <!-- ===== Tabel ===== -->
                    <template v-if="active.kind === 'table'">
                        <div v-if="lockedCount" class="flex items-start gap-2 border-b border-white/10 bg-amber-500/10 px-4 py-2.5 text-xs text-amber-200 sm:px-6">
                            <Lock class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                            <span>{{ $t('onucfg.locked_hint', { profile: baseline.onu_profile }) }}</span>
                            <button type="button" class="ml-auto shrink-0 font-semibold text-amber-100 underline underline-offset-2 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400/60" @click="selectItem('profile')">
                                {{ $t('onucfg.locked_goto') }}
                            </button>
                        </div>

                        <!-- kosong -->
                        <div v-if="!rows.length" class="flex flex-col items-center px-6 py-12 text-center">
                            <div class="flex h-12 w-12 items-center justify-center rounded-full bg-slate-800/60 ring-1 ring-slate-500/30">
                                <Inbox class="h-6 w-6 text-slate-400" />
                            </div>
                            <p class="mt-3 text-sm font-medium text-slate-300">{{ $t('onucfg.empty_title', { section: tr(active.label) }) }}</p>
                            <p class="mt-1 text-xs text-slate-500">{{ $t('onucfg.empty_body') }}</p>
                            <PrimaryButton v-if="canWrite" class="mt-4" type="button" size="sm" :disabled="busy" @click="openAdd">
                                <Plus class="h-4 w-4" /> {{ $t('onucfg.action_add') }}
                            </PrimaryButton>
                        </div>

                        <template v-else>
                            <!-- HP: kartu -->
                            <div class="kv-mobile-list">
                                <article
                                    v-for="(row, index) in rows"
                                    :key="`m-${index}`"
                                    class="kv-mobile-card transition-colors duration-700"
                                    :class="isFlashed(row) ? 'bg-emerald-500/10' : ''"
                                >
                                    <div class="kv-mobile-card-header">
                                        <div class="min-w-0">
                                            <h4 class="kv-mobile-card-title flex items-center gap-1.5 font-mono">
                                                <Lock v-if="locked(row)" class="h-3.5 w-3.5 text-amber-300" />
                                                {{ cell(active.columns[0], row) }}
                                            </h4>
                                            <p class="kv-mobile-card-subtitle">{{ active.columns[0].label }}</p>
                                        </div>
                                        <div v-if="canWrite && !locked(row)" class="flex gap-2">
                                            <IconButton variant="primary" :title="$t('onucfg.action_edit')" :disabled="busy" @click="openEdit(index)"><Pencil class="h-4 w-4" /></IconButton>
                                            <IconButton variant="danger" :title="$t('onucfg.action_delete')" :disabled="busy" @click="removeRow(index)"><Trash2 class="h-4 w-4" /></IconButton>
                                        </div>
                                    </div>
                                    <div class="kv-mobile-fields">
                                        <div v-for="column in active.columns.slice(1)" :key="column.key" class="kv-mobile-field">
                                            <span class="kv-mobile-label">{{ column.label }}</span>
                                            <span class="kv-mobile-value font-mono text-xs">{{ cell(column, row) }}</span>
                                        </div>
                                    </div>
                                </article>
                            </div>

                            <!-- Layar lebar: tabel -->
                            <div class="kv-table-desktop">
                                <table class="w-full min-w-[560px] text-xs tabular-nums">
                                    <thead>
                                        <tr class="border-b border-white/10 bg-canvas-3/40 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                            <th v-for="column in active.columns" :key="column.key" class="px-4 py-3 first:pl-6">{{ column.label }}</th>
                                            <th class="w-28 px-4 py-3 pr-6 text-right">{{ canWrite ? $t('onucfg.col_actions') : '' }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-white/5">
                                        <tr
                                            v-for="(row, index) in rows"
                                            :key="`d-${index}`"
                                            class="transition-colors duration-700"
                                            :class="[isFlashed(row) ? 'bg-emerald-500/10' : 'hover:bg-white/[0.03]', canWrite && !locked(row) ? 'cursor-pointer' : '']"
                                            @dblclick="canWrite && openEdit(index)"
                                        >
                                            <td v-for="column in active.columns" :key="column.key" class="px-4 py-3 font-mono text-slate-200 first:pl-6">{{ cell(column, row) }}</td>
                                            <td class="px-4 py-2 pr-6">
                                                <div class="flex items-center justify-end gap-2">
                                                    <span v-if="locked(row)" class="kv-pill kv-pill-warning inline-flex items-center gap-1" :title="$t('onucfg.locked_row', { profile: baseline.onu_profile })">
                                                        <Lock class="h-3 w-3" /> {{ $t('onucfg.locked_short') }}
                                                    </span>
                                                    <template v-else-if="canWrite">
                                                        <IconButton variant="primary" :title="$t('onucfg.action_edit')" :disabled="busy" @click.stop="openEdit(index)"><Pencil class="h-4 w-4" /></IconButton>
                                                        <IconButton variant="danger" :title="$t('onucfg.action_delete')" :disabled="busy" @click.stop="removeRow(index)"><Trash2 class="h-4 w-4" /></IconButton>
                                                    </template>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                                <p v-if="canWrite" class="border-t border-white/10 px-6 py-2 text-xs text-slate-500">{{ $t('onucfg.table_hint') }}</p>
                            </div>
                        </template>
                    </template>

                    <!-- ===== Form tunggal ===== -->
                    <dl v-else-if="active.kind === 'form'" class="grid gap-3 p-4 sm:grid-cols-2 sm:p-6">
                        <div v-for="field in active.fields" :key="field.key" class="rounded-lg border border-white/10 bg-canvas-3/40 px-4 py-3">
                            <dt class="text-xs text-slate-500">{{ tr(field.label) }}</dt>
                            <dd class="mt-1 break-all font-mono text-sm text-slate-100">
                                <span v-if="field.type === 'bool'" class="kv-pill" :class="baseline?.[field.key] ? 'kv-pill-success' : 'kv-pill-muted'">{{ baseline?.[field.key] ? 'on' : 'off' }}</span>
                                <template v-else-if="field.type === 'password'">{{ baseline?.[field.key] ? '••••••••' : '—' }}</template>
                                <template v-else>{{ baseline?.[field.key] ?? '—' }}</template>
                            </dd>
                        </div>
                    </dl>

                    <!-- ===== onu-profile ===== -->
                    <div v-else-if="active.kind === 'profile'" class="space-y-4 p-4 text-sm sm:p-6">
                        <template v-if="baseline?.onu_profile">
                            <div class="flex items-start gap-3 rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-amber-100">
                                <Lock class="mt-0.5 h-5 w-5 shrink-0 text-amber-300" />
                                <div class="space-y-1">
                                    <p class="font-semibold">{{ $t('onucfg.profile_bound') }} <span class="font-mono">{{ baseline.onu_profile }}</span></p>
                                    <p class="text-amber-100/80">{{ $t('onucfg.profile_explain') }}</p>
                                </div>
                            </div>
                            <pre data-theme="dark" class="kv-terminal overflow-x-auto rounded-lg bg-slate-950/70 px-4 py-3 font-mono text-xs leading-relaxed text-amber-200">{{ (baseline.profile_lines ?? []).join('\n') }}</pre>
                            <div class="rounded-lg border border-white/10 bg-canvas-3/40 px-4 py-3">
                                <p class="text-slate-300">{{ $t('onucfg.unbind_explain', { onuId }) }}</p>
                                <DangerButton v-if="canWrite" class="mt-3" type="button" :disabled="busy" @click="unbindProfile">
                                    <RefreshCw v-if="unbinding" class="h-4 w-4 animate-spin" />
                                    <Unlock v-else class="h-4 w-4" />
                                    {{ unbinding ? $t('onucfg.unbinding') : $t('onucfg.unbind_button') }}
                                </DangerButton>
                            </div>
                        </template>
                        <div v-else class="flex items-center gap-3 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-emerald-100">
                            <CheckCircle2 class="h-5 w-5 shrink-0 text-emerald-300" /> {{ $t('onucfg.profile_none') }}
                        </div>
                        <div v-if="unbindResult && !unbindResult.ok" class="rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-xs text-rose-200">
                            <p class="font-semibold">{{ $t('onucfg.unbind_failed') }}</p>
                            <pre v-if="unbindResult.remaining" class="mt-1 whitespace-pre-wrap font-mono">{{ unbindResult.remaining }}</pre>
                            <p v-else-if="unbindResult.message" class="mt-1">{{ unbindResult.message }}</p>
                        </div>
                    </div>

                    <!-- ===== Baca saja ===== -->
                    <div v-else class="p-4 sm:p-6">
                        <pre v-if="(baseline?.extra_mgmt ?? []).length" data-theme="dark" class="kv-terminal overflow-x-auto rounded-lg bg-slate-950/70 px-4 py-3 font-mono text-xs text-slate-300">{{ baseline.extra_mgmt.join('\n') }}</pre>
                        <p v-else class="py-6 text-center text-sm text-slate-500">{{ $t('onucfg.empty') }}</p>
                    </div>
                </section>

                <!-- Aktivitas sesi ini -->
                <section v-if="log.length" class="kv-table-card">
                    <header class="flex items-center justify-between border-b border-white/10 px-4 py-3 sm:px-6">
                        <h3 class="flex items-center gap-2 text-base font-semibold text-slate-100">
                            <Terminal class="h-4 w-4 text-cyan-400" /> {{ $t('onucfg.log_title') }}
                        </h3>
                        <span class="text-xs text-slate-500">{{ $t('onucfg.log_count', { n: log.length }) }}</span>
                    </header>
                    <ol class="divide-y divide-white/5">
                        <li v-for="(entry, i) in log" :key="i" class="px-4 py-3 sm:px-6">
                            <div class="flex flex-wrap items-center gap-2 text-sm">
                                <span class="kv-pill" :class="entry.ok ? 'kv-pill-success' : 'kv-pill-danger'">{{ entry.ok ? 'OK' : $t('onucfg.log_failed') }}</span>
                                <span class="min-w-0 flex-1 truncate font-medium text-slate-200">{{ entry.label }}</span>
                                <span class="text-xs tabular-nums text-slate-500">{{ timeLabel(entry.at) }}</span>
                            </div>
                            <p v-if="entry.message" class="mt-1.5 text-xs text-rose-300">{{ entry.message }}</p>
                            <details v-if="entry.script" class="mt-2">
                                <summary class="cursor-pointer text-xs text-slate-400 hover:text-slate-200">{{ $t('onucfg.log_show_cli') }}</summary>
                                <pre data-theme="dark" class="kv-terminal mt-2 overflow-x-auto rounded-lg bg-slate-950/70 px-3 py-2 font-mono text-xs text-cyan-200/90">{{ entry.script }}</pre>
                            </details>
                        </li>
                    </ol>
                </section>

                <details class="kv-table-card">
                    <summary class="flex cursor-pointer items-center gap-2 px-4 py-3 text-sm font-semibold text-slate-300 hover:text-slate-100 sm:px-6">
                        <Terminal class="h-4 w-4 text-cyan-400" /> {{ $t('onucfg.raw_title') }}
                    </summary>
                    <pre data-theme="dark" class="kv-terminal overflow-x-auto whitespace-pre-wrap break-words rounded-b-lg border-t border-white/10 bg-slate-950/70 px-4 py-3 font-mono text-xs leading-relaxed text-emerald-300/90">{{ rawText || '—' }}</pre>
                </details>
            </div>
        </div>
    </div>

    <!-- Dialog Tambah/Ubah -->
    <Modal :show="dialog.open" max-width="2xl" :closeable="!busy" @close="closeDialog">
        <form id="onucfg-dialog" class="flex max-h-[90vh] flex-col" @submit.prevent="submitDialog">
            <header class="flex items-start gap-3 border-b border-white/10 px-6 py-4">
                <div class="kv-icon-tile"><component :is="dialog.mode === 'add' ? Plus : Pencil" class="h-4 w-4" /></div>
                <div class="min-w-0 flex-1">
                    <h2 class="text-base font-semibold text-slate-100">
                        {{ dialog.mode === 'add' ? $t('onucfg.dialog_add', { section: tr(active.label) }) : $t('onucfg.dialog_edit', { section: tr(active.label) }) }}
                    </h2>
                    <p class="mt-0.5 font-mono text-xs text-slate-500">{{ interfaceName }} · {{ active.cli }}</p>
                </div>
                <IconButton :title="$t('common.close')" :disabled="busy" @click="closeDialog"><X class="h-4 w-4" /></IconButton>
            </header>

            <div class="flex-1 space-y-5 overflow-y-auto px-6 py-5">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div v-for="field in visibleFields" :key="field.key" :class="field.type === 'multi' || field.type === 'bool' ? 'sm:col-span-2' : ''">
                        <label v-if="field.type === 'bool'" class="flex min-h-11 cursor-pointer items-center justify-between gap-3 rounded-lg border border-white/10 bg-canvas-3/40 px-4 text-sm text-slate-200">
                            {{ tr(field.label) }}
                            <input v-model="dialog.row[field.key]" type="checkbox" class="h-5 w-5 rounded border-white/20 text-cyan-400 focus:ring-cyan-500" />
                        </label>
                        <template v-else>
                            <label :for="`onucfg-${field.key}`" class="flex items-center gap-1 text-xs font-medium text-slate-400">
                                {{ tr(field.label) }}<span v-if="field.required" class="text-rose-300">*</span>
                                <Lock v-if="field.immutable && dialog.mode === 'edit'" class="h-3 w-3 text-slate-500" :aria-label="$t('onucfg.immutable')" />
                            </label>
                            <select
                                v-if="field.type === 'select'"
                                :id="`onucfg-${field.key}`"
                                v-model="dialog.row[field.key]"
                                class="kv-input mt-1 block min-h-11 w-full"
                                :disabled="(field.immutable && dialog.mode === 'edit') || busy"
                            >
                                <option v-for="option in optionsFor(field)" :key="option" :value="option">{{ option === '' ? '—' : option }}</option>
                            </select>
                            <div v-else-if="field.type === 'multi'" class="mt-1 flex flex-wrap gap-2">
                                <label
                                    v-for="option in optionsFor(field)"
                                    :key="option"
                                    class="flex min-h-11 cursor-pointer items-center gap-2 rounded-xl border px-3 text-sm transition"
                                    :class="(dialog.row[field.key] ?? []).includes(option) ? 'border-cyan-500/40 bg-cyan-500/15 text-cyan-100' : 'border-white/10 text-slate-300 hover:border-white/20'"
                                >
                                    <input type="checkbox" class="h-4 w-4 rounded border-white/20 text-cyan-400 focus:ring-cyan-500" :checked="(dialog.row[field.key] ?? []).includes(option)" :disabled="busy" @change="toggleMulti(field, option)" />
                                    {{ option }}
                                </label>
                            </div>
                            <TextInput
                                v-else
                                :id="`onucfg-${field.key}`"
                                v-model="dialog.row[field.key]"
                                :type="field.type === 'number' ? 'number' : field.type === 'password' ? 'password' : 'text'"
                                class="mt-1 block w-full font-mono"
                                :disabled="(field.immutable && dialog.mode === 'edit') || busy"
                                :min="field.min"
                                :max="field.max"
                                autocomplete="off"
                            />
                        </template>
                    </div>
                </div>

                <!-- Pratinjau CLI -->
                <div class="overflow-hidden rounded-lg border border-white/10">
                    <div class="flex items-center justify-between gap-2 border-b border-white/10 bg-canvas-3/40 px-3 py-2">
                        <p class="flex items-center gap-2 text-xs font-semibold text-slate-400">
                            <Terminal class="h-3.5 w-3.5" /> {{ $t('onucfg.preview_title') }}
                            <RefreshCw v-if="preview.loading" class="h-3.5 w-3.5 animate-spin" />
                            <span v-else-if="preview.script" class="kv-pill kv-pill-info whitespace-nowrap">{{ $t('onucfg.preview_count', { n: previewLines }) }}</span>
                        </p>
                        <IconButton v-if="preview.script" :title="copied ? $t('onucfg.copied') : $t('onucfg.copy')" @click="copyPreview">
                            <Check v-if="copied" class="h-4 w-4" /><Copy v-else class="h-4 w-4" />
                        </IconButton>
                    </div>
                    <pre data-theme="dark" class="kv-terminal max-h-48 overflow-auto bg-slate-950/70 px-3 py-2 font-mono text-xs leading-relaxed text-cyan-200/90">{{ preview.script || $t('onucfg.preview_empty') }}</pre>
                </div>

                <p v-if="preview.conflicts.length" class="flex items-start gap-2 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-200">
                    <Lock class="mt-0.5 h-4 w-4 shrink-0" /> {{ $t('onucfg.err_profile_locked', { profile: baseline?.onu_profile ?? '' }) }}
                </p>
                <p v-if="dialog.error" class="flex items-start gap-2 rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-200" role="alert">
                    <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" /> {{ dialog.error }}
                </p>
            </div>

            <footer class="flex flex-col-reverse gap-2 border-t border-white/10 px-6 py-4 sm:flex-row sm:items-center sm:justify-end sm:gap-3">
                <p class="text-xs text-slate-500 sm:mr-auto sm:max-w-[16rem]">{{ $t('onucfg.dialog_note') }}</p>
                <SecondaryButton type="button" size="sm" class="whitespace-nowrap" :disabled="busy" @click="closeDialog">{{ $t('common.cancel') }}</SecondaryButton>
                <PrimaryButton type="submit" size="sm" class="whitespace-nowrap" :disabled="busy || preview.loading || preview.conflicts.length > 0 || !preview.script">
                    <RefreshCw v-if="busy" class="h-4 w-4 animate-spin" />
                    <Check v-else class="h-4 w-4" />
                    {{ busy ? $t('onucfg.sending') : $t('onucfg.ok_send') }}
                </PrimaryButton>
            </footer>
        </form>
    </Modal>

    <ConfirmModal :state="confirmState" @confirm="handleConfirm" @cancel="handleCancel" />

    <!-- Toast hasil kirim -->
    <div class="pointer-events-none fixed inset-x-0 bottom-4 z-[70] flex flex-col items-center gap-2 px-4 sm:inset-x-auto sm:right-4 sm:items-end sm:px-0" aria-live="polite">
        <TransitionGroup
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0 translate-y-2"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-for="item in toasts"
                :key="item.id"
                :role="item.type === 'error' ? 'alert' : 'status'"
                class="kv-toast pointer-events-auto flex w-full max-w-md items-start gap-3 rounded-xl border px-4 py-3 text-sm shadow-lg shadow-black/40 sm:w-auto sm:min-w-[18rem]"
                :class="item.type === 'error' ? 'border-rose-500/30 bg-rose-950/90 text-rose-200' : 'border-emerald-500/30 bg-emerald-950/90 text-emerald-200'"
            >
                <CheckCircle2 v-if="item.type === 'success'" class="mt-0.5 h-5 w-5 shrink-0 text-emerald-400" />
                <TriangleAlert v-else class="mt-0.5 h-5 w-5 shrink-0 text-rose-400" />
                <span class="min-w-0 flex-1 break-words">{{ item.message }}</span>
            </div>
        </TransitionGroup>
    </div>
</template>
