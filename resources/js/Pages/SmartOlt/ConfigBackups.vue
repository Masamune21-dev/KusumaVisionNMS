<script setup>
import Modal from '@/Components/Modal.vue';
import Pagination from '@/Components/Pagination.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { formatDateTime } from '@/lib/datetime';
import { diffStats, lineDiff } from '@/lib/linediff';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ArrowLeft, Clock, Database, Download, Eye, History, Save, ShieldCheck, ToggleLeft, ToggleRight, TriangleAlert } from '@lucide/vue';
import { computed, ref } from 'vue';

const { t } = useI18n({ useScope: 'global' });

const props = defineProps({
    olt: { type: Object, required: true },
    supported: { type: Boolean, default: false },
    backups: { type: Object, required: true },
});

const rows = computed(() => props.backups.data || []);
const okBackups = computed(() => rows.value.filter((b) => b.status === 'ok'));

const backingUp = ref(false);
const runBackup = () => {
    if (backingUp.value) return;
    backingUp.value = true;
    router.post(route('smartolt.config-backups.store', props.olt.id), {}, {
        preserveScroll: true,
        onFinish: () => (backingUp.value = false),
    });
};

const toggleDaily = () => {
    router.post(route('smartolt.config-backups.toggle', props.olt.id), {}, { preserveScroll: true });
};

// --- Lihat isi satu versi ---
const viewOpen = ref(false);
const viewLoading = ref(false);
const viewBackup = ref(null);
const viewContent = ref('');
const openView = async (b) => {
    viewBackup.value = b;
    viewContent.value = '';
    viewOpen.value = true;
    viewLoading.value = true;
    try {
        const { data } = await window.axios.get(route('smartolt.config-backups.content', [props.olt.id, b.id]));
        viewContent.value = data.content || t('configbackups.empty_content');
    } catch {
        viewContent.value = t('configbackups.load_content_failed');
    } finally {
        viewLoading.value = false;
    }
};

const downloadUrl = (b) => route('smartolt.config-backups.download', [props.olt.id, b.id]);

// --- Bandingkan dua versi (diff) ---
const diffFrom = ref(null);
const diffTo = ref(null);
const diffOpen = ref(false);
const diffLoading = ref(false);
const diffRows = ref([]);
const diffError = ref('');
const stats = computed(() => diffStats(diffRows.value));
const changedRows = computed(() => diffRows.value.filter((r) => r.type !== 'same'));

const runDiff = async () => {
    diffError.value = '';
    if (!diffFrom.value || !diffTo.value) {
        diffError.value = t('configbackups.diff_pick_two');
        return;
    }
    if (diffFrom.value === diffTo.value) {
        diffError.value = t('configbackups.diff_pick_different');
        return;
    }
    diffOpen.value = true;
    diffLoading.value = true;
    diffRows.value = [];
    try {
        const [a, b] = await Promise.all([
            window.axios.get(route('smartolt.config-backups.content', [props.olt.id, diffFrom.value])),
            window.axios.get(route('smartolt.config-backups.content', [props.olt.id, diffTo.value])),
        ]);
        diffRows.value = lineDiff(a.data.content || '', b.data.content || '');
    } catch {
        diffError.value = t('configbackups.diff_load_failed');
        diffOpen.value = false;
    } finally {
        diffLoading.value = false;
    }
};

const formatSize = (bytes) => {
    if (!bytes) return '—';
    if (bytes < 1024) return `${bytes} B`;
    return `${(bytes / 1024).toFixed(1)} KB`;
};
const triggerLabel = (trg) => (trg === 'scheduled' ? t('configbackups.trigger_scheduled') : t('configbackups.trigger_manual'));
const labelFor = (id) => {
    const b = okBackups.value.find((x) => x.id === id);
    return b ? formatDateTime(b.captured_at) : '';
};
</script>

<template>
    <Head :title="`Backup Config ${olt.name}`" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:px-8">
            <!-- Header -->
            <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <Link :href="route('smartolt.detail', olt.id)" class="mb-2 inline-flex items-center gap-1.5 text-sm text-slate-400 hover:text-cyan-300">
                        <ArrowLeft class="h-4 w-4" /> {{ $t('configbackups.back_to', { name: olt.name }) }}
                    </Link>
                    <h1 class="flex items-center gap-2 text-2xl font-semibold text-white">
                        <Database class="h-6 w-6 text-cyan-400" /> {{ $t('configbackups.title') }}
                    </h1>
                    <p class="mt-1 text-sm text-slate-400">{{ olt.name }} · {{ olt.ip }}</p>
                </div>
            </div>

            <!-- Banner tak didukung -->
            <div v-if="!supported" class="mb-4 flex items-start gap-3 rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-200">
                <TriangleAlert class="mt-0.5 h-5 w-5 flex-shrink-0" />
                <p v-html="$t('configbackups.unsupported')"></p>
            </div>

            <!-- Toolbar -->
            <div class="mb-4 grid gap-4 md:grid-cols-2">
                <div class="kv-card flex items-center justify-between gap-4 p-4">
                    <div>
                        <p class="flex items-center gap-1.5 text-sm font-medium text-white"><Clock class="h-4 w-4 text-cyan-400" /> {{ $t('configbackups.daily_backup') }}</p>
                        <p class="mt-1 text-xs text-slate-400">{{ $t('configbackups.daily_hint') }}</p>
                    </div>
                    <button
                        type="button"
                        class="inline-flex flex-shrink-0 items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium ring-1 transition"
                        :class="olt.config_backup_enabled
                            ? 'bg-emerald-500/15 text-emerald-300 ring-emerald-500/30 hover:bg-emerald-500/25'
                            : 'bg-slate-800/60 text-slate-400 ring-slate-600/40 hover:bg-slate-700/60'"
                        @click="toggleDaily"
                    >
                        <component :is="olt.config_backup_enabled ? ToggleRight : ToggleLeft" class="h-5 w-5" />
                        {{ olt.config_backup_enabled ? $t('configbackups.active') : $t('configbackups.inactive') }}
                    </button>
                </div>

                <div class="kv-card flex items-center justify-between gap-4 p-4">
                    <div>
                        <p class="flex items-center gap-1.5 text-sm font-medium text-white"><Save class="h-4 w-4 text-cyan-400" /> {{ $t('configbackups.backup_now') }}</p>
                        <p class="mt-1 text-xs text-slate-400">{{ $t('configbackups.backup_now_hint') }}</p>
                    </div>
                    <button
                        type="button"
                        class="inline-flex flex-shrink-0 items-center gap-1.5 rounded-lg bg-cyan-500/90 px-3 py-2 text-sm font-medium text-white ring-1 ring-cyan-400/40 transition hover:bg-cyan-400 disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="!supported || backingUp"
                        @click="runBackup"
                    >
                        <Save class="h-4 w-4" :class="backingUp ? 'animate-pulse' : ''" />
                        {{ backingUp ? $t('common.processing') : $t('configbackups.backup_btn') }}
                    </button>
                </div>
            </div>

            <!-- Bandingkan versi -->
            <div v-if="okBackups.length >= 2" class="kv-card mb-4 p-4">
                <p class="mb-3 flex items-center gap-1.5 text-sm font-medium text-white"><History class="h-4 w-4 text-cyan-400" /> {{ $t('configbackups.compare_versions') }}</p>
                <div class="flex flex-wrap items-end gap-3">
                    <label class="text-xs text-slate-400">
                        {{ $t('configbackups.old_version') }}
                        <select v-model.number="diffFrom" class="kv-filter-control mt-1 block w-full sm:w-64">
                            <option :value="null">{{ $t('configbackups.choose') }}</option>
                            <option v-for="b in okBackups" :key="`a${b.id}`" :value="b.id">{{ formatDateTime(b.captured_at) }} · {{ formatSize(b.size_bytes) }}</option>
                        </select>
                    </label>
                    <label class="text-xs text-slate-400">
                        {{ $t('configbackups.new_version') }}
                        <select v-model.number="diffTo" class="kv-filter-control mt-1 block w-full sm:w-64">
                            <option :value="null">{{ $t('configbackups.choose') }}</option>
                            <option v-for="b in okBackups" :key="`b${b.id}`" :value="b.id">{{ formatDateTime(b.captured_at) }} · {{ formatSize(b.size_bytes) }}</option>
                        </select>
                    </label>
                    <button type="button" class="kv-filter-apply" @click="runDiff">{{ $t('configbackups.compare_btn') }}</button>
                </div>
                <p v-if="diffError" class="mt-2 text-xs text-amber-300">{{ diffError }}</p>
            </div>

            <!-- Daftar versi -->
            <div class="kv-card overflow-hidden">
                <div v-if="rows.length === 0" class="px-6 py-12 text-center text-slate-400">
                    <Database class="mx-auto mb-3 h-8 w-8 text-slate-600" />
                    <p v-html="$t('configbackups.empty')"></p>
                </div>

                <template v-else>
                    <!-- Desktop -->
                    <div class="kv-table-desktop">
                        <table class="min-w-[720px] w-full">
                            <thead>
                                <tr class="border-b border-white/5">
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $t('configbackups.col_time') }}</th>
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $t('configbackups.col_size') }}</th>
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $t('configbackups.col_source') }}</th>
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $t('common.status') }}</th>
                                    <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $t('configbackups.col_by') }}</th>
                                    <th class="px-4 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $t('common.actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/5">
                                <tr v-for="b in rows" :key="b.id" class="hover:bg-white/[0.02]">
                                    <td class="px-4 py-3 text-sm text-slate-200">{{ formatDateTime(b.captured_at) }}</td>
                                    <td class="px-4 py-3 text-sm text-slate-400">{{ formatSize(b.size_bytes) }}</td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium ring-1"
                                            :class="b.trigger === 'scheduled' ? 'bg-sky-500/15 text-sky-300 ring-sky-500/30' : 'bg-slate-700/50 text-slate-300 ring-slate-500/30'">
                                            {{ triggerLabel(b.trigger) }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span v-if="b.status === 'ok'" class="inline-flex items-center gap-1 rounded-full bg-emerald-500/15 px-2 py-0.5 text-xs font-medium text-emerald-300 ring-1 ring-emerald-500/30">
                                            <ShieldCheck class="h-3.5 w-3.5" /> OK
                                        </span>
                                        <span v-else class="inline-flex items-center gap-1 rounded-full bg-rose-500/15 px-2 py-0.5 text-xs font-medium text-rose-300 ring-1 ring-rose-500/30" :title="b.error || ''">
                                            <TriangleAlert class="h-3.5 w-3.5" /> {{ $t('common.failed') }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-slate-400">{{ b.created_by || '—' }}</td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center justify-end gap-1.5">
                                            <button v-if="b.status === 'ok'" type="button" class="inline-flex items-center justify-center rounded-lg bg-slate-800/60 p-2 text-slate-300 ring-1 ring-slate-600/40 transition hover:bg-slate-700/60 hover:text-white" :title="$t('configbackups.view_content')" @click="openView(b)">
                                                <Eye class="h-4 w-4" />
                                            </button>
                                            <a v-if="b.status === 'ok'" :href="downloadUrl(b)" class="inline-flex items-center justify-center rounded-lg bg-slate-800/60 p-2 text-slate-300 ring-1 ring-slate-600/40 transition hover:bg-slate-700/60 hover:text-white" :title="$t('configbackups.download_txt')">
                                                <Download class="h-4 w-4" />
                                            </a>
                                            <span v-if="b.status !== 'ok'" class="text-xs text-slate-500">—</span>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Mobile -->
                    <div class="kv-mobile-list">
                        <article v-for="b in rows" :key="`m${b.id}`" class="kv-mobile-card">
                            <div class="flex items-center justify-between gap-2">
                                <h4 class="kv-mobile-card-title">{{ formatDateTime(b.captured_at) }}</h4>
                                <span v-if="b.status === 'ok'" class="inline-flex items-center gap-1 rounded-full bg-emerald-500/15 px-2 py-0.5 text-xs font-medium text-emerald-300 ring-1 ring-emerald-500/30">OK</span>
                                <span v-else class="inline-flex items-center gap-1 rounded-full bg-rose-500/15 px-2 py-0.5 text-xs font-medium text-rose-300 ring-1 ring-rose-500/30">{{ $t('common.failed') }}</span>
                            </div>
                            <div class="kv-mobile-fields">
                                <div class="kv-mobile-field"><span class="kv-mobile-label">{{ $t('configbackups.col_size') }}</span><span class="kv-mobile-value">{{ formatSize(b.size_bytes) }}</span></div>
                                <div class="kv-mobile-field"><span class="kv-mobile-label">{{ $t('configbackups.col_source') }}</span><span class="kv-mobile-value">{{ triggerLabel(b.trigger) }}</span></div>
                                <div class="kv-mobile-field"><span class="kv-mobile-label">{{ $t('configbackups.col_by') }}</span><span class="kv-mobile-value">{{ b.created_by || '—' }}</span></div>
                                <div v-if="b.status !== 'ok' && b.error" class="kv-mobile-field"><span class="kv-mobile-label">{{ $t('configbackups.col_error') }}</span><span class="kv-mobile-value text-rose-300">{{ b.error }}</span></div>
                            </div>
                            <div v-if="b.status === 'ok'" class="mt-3 flex gap-2">
                                <button type="button" class="inline-flex items-center gap-1.5 rounded-lg bg-slate-800/60 px-3 py-1.5 text-xs text-slate-200 ring-1 ring-slate-600/40" @click="openView(b)"><Eye class="h-4 w-4" /> {{ $t('common.view') }}</button>
                                <a :href="downloadUrl(b)" class="inline-flex items-center gap-1.5 rounded-lg bg-slate-800/60 px-3 py-1.5 text-xs text-slate-200 ring-1 ring-slate-600/40"><Download class="h-4 w-4" /> {{ $t('common.download') }}</a>
                            </div>
                        </article>
                    </div>

                    <div v-if="backups.last_page > 1" class="border-t border-white/5 px-4 py-3">
                        <Pagination :links="backups.links" />
                    </div>
                </template>
            </div>
        </div>

        <!-- Modal lihat isi -->
        <Modal :show="viewOpen" max-width="2xl" @close="viewOpen = false">
            <div class="p-5">
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-white">running-config · {{ viewBackup ? formatDateTime(viewBackup.captured_at) : '' }}</h3>
                    <a v-if="viewBackup" :href="downloadUrl(viewBackup)" class="inline-flex items-center gap-1.5 text-xs text-cyan-300 hover:text-cyan-200"><Download class="h-4 w-4" /> {{ $t('common.download') }}</a>
                </div>
                <div v-if="viewLoading" class="py-10 text-center text-sm text-slate-400">{{ $t('common.loading') }}</div>
                <pre v-else class="max-h-[60vh] overflow-auto rounded-lg bg-slate-950/70 p-3 text-xs leading-relaxed text-slate-200 ring-1 ring-white/5">{{ viewContent }}</pre>
            </div>
        </Modal>

        <!-- Modal diff -->
        <Modal :show="diffOpen" max-width="2xl" @close="diffOpen = false">
            <div class="p-5">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-sm font-semibold text-white">{{ $t('configbackups.comparison', { a: labelFor(diffFrom), b: labelFor(diffTo) }) }}</h3>
                    <div class="flex items-center gap-2 text-xs">
                        <span class="rounded-full bg-emerald-500/15 px-2 py-0.5 text-emerald-300 ring-1 ring-emerald-500/30">+{{ stats.added }}</span>
                        <span class="rounded-full bg-rose-500/15 px-2 py-0.5 text-rose-300 ring-1 ring-rose-500/30">−{{ stats.removed }}</span>
                    </div>
                </div>
                <div v-if="diffLoading" class="py-10 text-center text-sm text-slate-400">{{ $t('configbackups.comparing') }}</div>
                <div v-else-if="stats.changed === 0" class="py-10 text-center text-sm text-emerald-300">{{ $t('configbackups.no_diff') }}</div>
                <div v-else class="max-h-[60vh] overflow-auto rounded-lg bg-slate-950/70 p-3 font-mono text-xs leading-relaxed ring-1 ring-white/5">
                    <div v-for="(r, i) in changedRows" :key="i" class="whitespace-pre-wrap"
                        :class="r.type === 'add' ? 'bg-emerald-500/10 text-emerald-200' : 'bg-rose-500/10 text-rose-200'">
                        <span class="select-none pr-1 opacity-60">{{ r.type === 'add' ? '+' : '−' }}</span>{{ r.text }}
                    </div>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
