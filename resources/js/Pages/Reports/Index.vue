<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import InputLabel from '@/Components/InputLabel.vue';
import { Head, router } from '@inertiajs/vue3';
import { FileBarChart, FileDown, FileText } from '@lucide/vue';
import { reactive, watch } from 'vue';

const props = defineProps({
    report: { type: Object, required: true },
    filters: { type: Object, required: true },
    typeOptions: { type: Array, default: () => [] },
    rangeOptions: { type: Array, default: () => [] },
    oltOptions: { type: Array, default: () => [] },
    ponPortOptions: { type: Array, default: () => [] },
});

const state = reactive({
    type: props.filters.type,
    range: props.filters.range,
    olt_id: props.filters.olt_id ?? '',
    pon_port: props.filters.pon_port ?? '',
    rx_status: props.filters.rx_status ?? '',
    status: props.filters.status ?? '',
});

const queryParams = () => ({
    type: state.type,
    range: state.range,
    ...(state.olt_id !== '' && state.olt_id !== null ? { olt_id: state.olt_id } : {}),
    ...(state.olt_id !== '' && state.olt_id !== null && state.pon_port !== '' ? { pon_port: state.pon_port } : {}),
    ...(state.type === 'rx' && state.rx_status !== '' ? { rx_status: state.rx_status } : {}),
    ...(state.type !== 'rx' && state.status !== '' ? { status: state.status } : {}),
});

const reload = () => {
    router.get(route('reports.index'), queryParams(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

watch(() => [state.type, state.range, state.olt_id, state.pon_port, state.rx_status, state.status], (next, prev) => {
    // OLT berubah -> reset PON port (akan men-trigger ulang watcher ini lalu reload).
    if (next[2] !== prev[2] && state.pon_port !== '') {
        state.pon_port = '';
        return;
    }
    // Jenis laporan berubah -> reset filter spesifik (redaman & status) agar tidak terbawa.
    if (next[0] !== prev[0] && (state.rx_status !== '' || state.status !== '')) {
        state.rx_status = '';
        state.status = '';
        return;
    }
    reload();
});

const exportUrl = (format) => route(`reports.export.${format}`, queryParams());

const statusClass = (value) => {
    const v = String(value).toLowerCase();
    if (['online', 'normal', 'berhasil', 'success', 'executed', 'completed'].includes(v)) {
        return 'border-emerald-500/30 bg-emerald-500/15 text-emerald-300';
    }
    if (['warning', 'minor', 'aktif', 'dying gasp'].includes(v)) {
        return 'border-amber-500/30 bg-amber-500/15 text-amber-300';
    }
    if (['offline', 'critical', 'gagal', 'failed', 'error', 'major', 'los'].includes(v)) {
        return 'border-red-500/30 bg-red-500/15 text-red-300';
    }
    return 'border-slate-500/30 bg-slate-500/15 text-slate-300';
};

const isStatusColumn = (key) => ['status', 'reachable', 'severity'].includes(key);
</script>

<template>
    <Head title="Report" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <h2 class="text-lg font-semibold leading-tight text-white sm:text-xl">Report</h2>
                <div class="flex gap-2">
                    <a :href="exportUrl('csv')">
                        <SecondaryButton class="w-full sm:w-auto">
                            <FileDown class="mr-2 h-4 w-4" /> CSV
                        </SecondaryButton>
                    </a>
                    <a :href="exportUrl('pdf')">
                        <PrimaryButton class="w-full sm:w-auto">
                            <FileText class="mr-2 h-4 w-4" /> PDF
                        </PrimaryButton>
                    </a>
                </div>
            </div>
        </template>

        <div class="min-h-[60vh] pt-5 pb-16 sm:pt-8">
            <div class="w-full space-y-5 px-4 sm:px-6 lg:px-8">
                <!-- Filter bar -->
                <div class="grid gap-4 rounded-lg border border-white/10 bg-slate-900/40 p-4 backdrop-blur-xl sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <InputLabel for="type" value="Jenis Laporan" />
                        <select id="type" v-model="state.type" class="mt-1 block w-full min-h-11 rounded-lg border-white/10 bg-slate-900/60 text-slate-100 shadow-inner shadow-black/20 focus:border-cyan-500 focus:ring-cyan-500">
                            <option v-for="opt in typeOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                        </select>
                    </div>
                    <div>
                        <InputLabel for="range" value="Rentang Waktu" />
                        <select id="range" v-model="state.range" class="mt-1 block w-full min-h-11 rounded-lg border-white/10 bg-slate-900/60 text-slate-100 shadow-inner shadow-black/20 focus:border-cyan-500 focus:ring-cyan-500">
                            <option v-for="opt in rangeOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                        </select>
                    </div>
                    <div>
                        <InputLabel for="olt" value="OLT" />
                        <select id="olt" v-model="state.olt_id" class="mt-1 block w-full min-h-11 rounded-lg border-white/10 bg-slate-900/60 text-slate-100 shadow-inner shadow-black/20 focus:border-cyan-500 focus:ring-cyan-500">
                            <option value="">Semua OLT</option>
                            <option v-for="opt in oltOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                        </select>
                    </div>
                    <div>
                        <InputLabel for="pon_port" value="PON Port" />
                        <select
                            id="pon_port"
                            v-model="state.pon_port"
                            :disabled="!state.olt_id || ponPortOptions.length === 0"
                            class="mt-1 block w-full min-h-11 rounded-lg border-white/10 bg-slate-900/60 text-slate-100 shadow-inner shadow-black/20 focus:border-cyan-500 focus:ring-cyan-500 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <option value="">{{ state.olt_id ? 'Semua Port' : 'Pilih OLT dulu' }}</option>
                            <option v-for="opt in ponPortOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                        </select>
                    </div>
                    <div v-if="state.type === 'rx'">
                        <InputLabel for="rx_status" value="Redaman RX" />
                        <select id="rx_status" v-model="state.rx_status" class="mt-1 block w-full min-h-11 rounded-lg border-white/10 bg-slate-900/60 text-slate-100 shadow-inner shadow-black/20 focus:border-cyan-500 focus:ring-cyan-500">
                            <option value="">Semua Redaman</option>
                            <option value="normal">Normal (&ge; -25 dBm)</option>
                            <option value="warning">Warning (&lt; -25 dBm)</option>
                            <option value="critical">Critical (&lt; -28 dBm)</option>
                        </select>
                    </div>
                    <div v-if="state.type !== 'rx' && (report.status_options?.length ?? 0) > 0">
                        <InputLabel for="status" value="Status" />
                        <select id="status" v-model="state.status" class="mt-1 block w-full min-h-11 rounded-lg border-white/10 bg-slate-900/60 text-slate-100 shadow-inner shadow-black/20 focus:border-cyan-500 focus:ring-cyan-500">
                            <option value="">Semua Status</option>
                            <option v-for="opt in report.status_options" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                        </select>
                    </div>
                </div>

                <!-- Summary -->
                <div class="grid gap-3 sm:grid-cols-3">
                    <div v-for="item in report.summary" :key="item.label" class="rounded-lg border border-white/10 bg-slate-900/40 px-4 py-3 backdrop-blur-xl">
                        <div class="text-xs text-slate-500">{{ item.label }}</div>
                        <div class="mt-1 text-2xl font-semibold text-white">{{ item.value }}</div>
                    </div>
                </div>

                <!-- Table -->
                <div class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
                    <div class="flex items-center gap-3 border-b border-white/10 px-4 py-4 sm:px-6">
                        <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-sky-500/15 ring-1 ring-cyan-500/30">
                            <FileBarChart class="h-5 w-5 text-cyan-400" />
                        </div>
                        <div>
                            <h3 class="text-base font-semibold text-white">{{ report.title }}</h3>
                            <p class="mt-0.5 text-xs text-slate-500">{{ report.rows.length }} baris data.</p>
                        </div>
                    </div>

                    <div v-if="report.rows.length === 0" class="px-6 py-12 text-center">
                        <p class="text-sm text-slate-500">Tidak ada data untuk filter ini.</p>
                    </div>

                    <template v-else>
                        <!-- Mobile cards -->
                        <div class="kv-mobile-list">
                            <article v-for="(row, idx) in report.rows" :key="idx" class="kv-mobile-card">
                                <div class="kv-mobile-fields">
                                    <div v-for="column in report.columns" :key="column.key" class="kv-mobile-field">
                                        <span class="kv-mobile-label">{{ column.label }}</span>
                                        <span class="kv-mobile-value">
                                            <span v-if="isStatusColumn(column.key)" :class="['inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium', statusClass(row[column.key])]">
                                                {{ row[column.key] }}
                                            </span>
                                            <template v-else>{{ row[column.key] ?? '-' }}</template>
                                        </span>
                                    </div>
                                </div>
                            </article>
                        </div>

                        <!-- Desktop table -->
                        <div class="kv-table-desktop">
                            <table class="w-full min-w-[720px]">
                                <thead>
                                    <tr class="border-b border-white/10 bg-slate-950/40">
                                        <th v-for="column in report.columns" :key="column.key" class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                                            {{ column.label }}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-white/5">
                                    <tr v-for="(row, idx) in report.rows" :key="idx" class="transition-colors duration-150 hover:bg-white/[0.03]">
                                        <td v-for="column in report.columns" :key="column.key" class="px-4 py-3 text-sm text-slate-200">
                                            <span v-if="isStatusColumn(column.key)" :class="['inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium', statusClass(row[column.key])]">
                                                {{ row[column.key] }}
                                            </span>
                                            <template v-else>{{ row[column.key] ?? '-' }}</template>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
