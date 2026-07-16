<script setup>
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Cloud, RefreshCw, ShieldCheck, TriangleAlert } from '@lucide/vue';
import { computed, onUnmounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n({ useScope: 'global' });

const props = defineProps({
    show: { type: Boolean, default: false },
    olt: { type: Object, required: true },
    slot: { type: Number, required: true },
    port: { type: Number, required: true },
    // Target ACS (url + username) dari Pengaturan → SmartOltController::portOnus.
    acs: { type: Object, default: () => ({ url: '', username: '' }) },
});

const emit = defineEmits(['close']);

const blankProgress = () => ({
    status: 'queued', execute: false, total: 0, processed: 0,
    applied: 0, skipped: 0, failed: 0, finished: false, items: [], error: null,
});

// intro → running → dry-done / execute-done
const phase = ref('intro');
const submitting = ref(false);
const errorMsg = ref('');
const statusUrl = ref('');
const progress = ref(blankProgress());
let pollTimer = null;

const percent = computed(() => {
    const total = progress.value.total || 0;
    return total > 0 ? Math.round((progress.value.processed / total) * 100) : 0;
});
const failedItems = computed(() => (progress.value.items ?? []).filter((i) => i.status === 'failed'));
const isExecute = computed(() => progress.value.execute);

const stopPolling = () => { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } };

const reset = () => {
    stopPolling();
    phase.value = 'intro';
    submitting.value = false;
    errorMsg.value = '';
    progress.value = blankProgress();
};

const close = () => { emit('close'); };

watch(() => props.show, (open) => { if (open) reset(); else stopPolling(); });
onUnmounted(stopPolling);

const pollStatus = async () => {
    try {
        const { data } = await window.axios.get(statusUrl.value);
        progress.value = data;
        if (data.finished) {
            stopPolling();
            phase.value = data.execute ? 'execute-done' : 'dry-done';
        }
    } catch (e) {
        // transient (worker belum sempat update) — biarkan polling lanjut
    }
};

const start = async (execute) => {
    errorMsg.value = '';
    submitting.value = true;
    try {
        const { data } = await window.axios.post(route('smartolt.tr069-bulk', [props.olt.id, props.slot, props.port]), { execute });
        statusUrl.value = data.status_url;
        progress.value = { ...blankProgress(), execute };
        phase.value = 'running';
        await pollStatus();
        pollTimer = setInterval(pollStatus, 1500);
    } catch (e) {
        errorMsg.value = e.response?.data?.message ?? e.message ?? t('portonus.request_failed');
    } finally {
        submitting.value = false;
    }
};
</script>

<template>
    <Modal :show="show" max-width="lg" @close="close">
        <!-- Fase 1: intro -->
        <div v-if="phase === 'intro'" class="p-6">
            <div class="flex items-center gap-3">
                <span class="flex h-9 w-9 items-center justify-center rounded-full bg-sky-500/15 ring-1 ring-cyan-500/30">
                    <Cloud class="h-5 w-5 text-cyan-400" />
                </span>
                <h3 class="text-base font-semibold text-white">{{ $t('tr069.enable_title') }}</h3>
            </div>
            <p class="mt-2 text-sm text-slate-400" v-html="$t('tr069.intro', { slot, port, olt: olt.name })"></p>

            <dl class="mt-4 space-y-1.5 rounded-lg border border-white/10 bg-slate-950/40 px-3 py-3 text-xs">
                <div class="flex justify-between gap-3"><dt class="text-slate-500">ACS URL</dt><dd class="font-mono text-slate-200">{{ acs.url }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-slate-500">Username</dt><dd class="font-mono text-slate-200">{{ acs.username }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-slate-500">Password</dt><dd class="font-mono text-slate-200">••••••••</dd></div>
            </dl>

            <div class="mt-3 flex items-start gap-2 rounded-lg border border-cyan-500/30 bg-cyan-500/10 px-3 py-2.5 text-xs text-cyan-200">
                <ShieldCheck class="mt-0.5 h-4 w-4 flex-shrink-0" />
                <span v-html="$t('tr069.dryrun_hint')"></span>
            </div>

            <p v-if="errorMsg" class="mt-3 rounded-lg border border-red-500/30 bg-red-500/10 px-3 py-2.5 text-xs text-red-300">{{ errorMsg }}</p>

            <div class="mt-6 grid gap-2 sm:flex sm:justify-end">
                <SecondaryButton type="button" @click="close">{{ $t('common.cancel') }}</SecondaryButton>
                <PrimaryButton type="button" :disabled="submitting" @click="start(false)">
                    <RefreshCw class="mr-2 h-4 w-4" :class="{ 'animate-spin': submitting }" />
                    {{ submitting ? $t('portonus.processing') : $t('tr069.dry_run') }}
                </PrimaryButton>
            </div>
        </div>

        <!-- Fase 2: berjalan -->
        <div v-else-if="phase === 'running'" class="p-6">
            <div class="flex items-center gap-3">
                <RefreshCw class="h-5 w-5 animate-spin text-cyan-400" />
                <h3 class="text-base font-semibold text-white">
                    {{ isExecute ? $t('tr069.running_execute') : $t('tr069.running_scan') }}
                </h3>
            </div>
            <p class="mt-1 text-sm text-slate-500">
                {{ $t('tr069.running_sub', { slot, port, olt: olt.name }) }}
            </p>

            <div class="mt-4">
                <div class="mb-1.5 flex items-center justify-between text-xs text-slate-400">
                    <span>{{ $t('portonus.n_processed', { processed: progress.processed, total: progress.total }) }}</span>
                    <span>{{ percent }}%</span>
                </div>
                <div class="h-2.5 w-full overflow-hidden rounded-full bg-slate-800">
                    <div class="h-full rounded-full bg-cyan-500 transition-all duration-300" :style="{ width: `${percent}%` }"></div>
                </div>
                <div class="mt-3 grid grid-cols-3 gap-2 text-center text-xs">
                    <div class="rounded-lg bg-slate-800/60 py-2"><div class="text-base font-semibold text-cyan-300">{{ progress.applied }}</div>{{ isExecute ? $t('tr069.activated_done') : $t('tr069.activated_pending') }}</div>
                    <div class="rounded-lg bg-slate-800/60 py-2"><div class="text-base font-semibold text-emerald-400">{{ progress.skipped }}</div>{{ $t('tr069.skipped_active') }}</div>
                    <div class="rounded-lg bg-slate-800/60 py-2"><div class="text-base font-semibold text-red-300">{{ progress.failed }}</div>{{ $t('tr069.failed') }}</div>
                </div>
            </div>

            <div class="mt-6 flex justify-end">
                <SecondaryButton type="button" @click="close">{{ $t('portonus.close_keep_running') }}</SecondaryButton>
            </div>
        </div>

        <!-- Fase 3a: dry-run selesai -->
        <div v-else-if="phase === 'dry-done'" class="p-6">
            <div class="flex items-center gap-3">
                <span class="flex h-9 w-9 items-center justify-center rounded-full bg-sky-500/15">
                    <ShieldCheck class="h-5 w-5 text-cyan-400" />
                </span>
                <h3 class="text-base font-semibold text-white">{{ $t('tr069.scan_result') }}</h3>
            </div>

            <div class="mt-4 grid grid-cols-3 gap-2 text-center text-xs">
                <div class="rounded-lg bg-slate-800/60 py-2.5"><div class="text-lg font-semibold text-cyan-300">{{ progress.applied }}</div>{{ $t('tr069.activated_pending') }}</div>
                <div class="rounded-lg bg-slate-800/60 py-2.5"><div class="text-lg font-semibold text-emerald-400">{{ progress.skipped }}</div>{{ $t('tr069.already_active_skip') }}</div>
                <div class="rounded-lg bg-slate-800/60 py-2.5"><div class="text-lg font-semibold text-red-300">{{ progress.failed }}</div>{{ $t('tr069.read_failed') }}</div>
            </div>

            <p v-if="progress.total === 0" class="mt-4 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2.5 text-xs text-amber-200" v-html="$t('tr069.no_cache', { slot, port })"></p>
            <p v-else-if="progress.applied === 0" class="mt-4 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-3 py-2.5 text-xs text-emerald-200">
                {{ $t('tr069.all_active') }}
            </p>
            <p v-else class="mt-4 flex items-start gap-2 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2.5 text-xs text-amber-200">
                <TriangleAlert class="mt-0.5 h-4 w-4 flex-shrink-0" />
                <span v-html="$t('tr069.will_write', { applied: progress.applied })"></span>
            </p>

            <div v-if="failedItems.length" class="mt-3 max-h-32 space-y-1.5 overflow-y-auto rounded-lg border border-white/10 bg-slate-950/40 p-3">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $t('tr069.read_failed_header') }}</p>
                <div v-for="(item, idx) in failedItems" :key="idx" class="text-xs text-slate-400">
                    <span class="font-mono text-slate-300">{{ item.slot }}/{{ item.port }}:{{ item.onu_id }}</span><span v-if="item.serial_number"> · {{ item.serial_number }}</span> — {{ item.message }}
                </div>
            </div>

            <div class="mt-6 grid gap-2 sm:flex sm:justify-end">
                <SecondaryButton type="button" @click="close">{{ $t('common.close') }}</SecondaryButton>
                <SecondaryButton type="button" :disabled="submitting" @click="start(false)">{{ $t('tr069.rescan') }}</SecondaryButton>
                <PrimaryButton v-if="progress.applied > 0" type="button" :disabled="submitting" @click="start(true)">
                    <Cloud class="mr-2 h-4 w-4" />
                    {{ submitting ? $t('portonus.processing') : $t('tr069.execute_to_olt', { applied: progress.applied }) }}
                </PrimaryButton>
            </div>
        </div>

        <!-- Fase 3b: eksekusi selesai -->
        <div v-else class="p-6">
            <div class="flex items-center gap-3">
                <span class="flex h-9 w-9 items-center justify-center rounded-full" :class="progress.status === 'failed' || progress.failed > 0 ? 'bg-amber-500/15' : 'bg-emerald-500/15'">
                    <Cloud class="h-5 w-5" :class="progress.status === 'failed' || progress.failed > 0 ? 'text-amber-300' : 'text-emerald-400'" />
                </span>
                <h3 class="text-base font-semibold text-white">
                    {{ progress.status === 'failed' ? $t('portonus.batch_failed') : $t('tr069.execute_done') }}
                </h3>
            </div>
            <p class="mt-2 text-sm text-slate-300">
                <span class="text-cyan-300 font-semibold">{{ progress.applied }}</span> {{ $t('tr069.activated_done') }} ·
                <span class="text-emerald-400 font-semibold">{{ progress.skipped }}</span> {{ $t('tr069.already_active_skip') }} ·
                <span class="text-red-300 font-semibold">{{ progress.failed }}</span> {{ $t('tr069.failed') }}
                <span class="text-slate-500">{{ $t('portonus.done_from_total', { total: progress.total }) }}</span>
            </p>
            <p v-if="progress.error" class="mt-2 rounded-lg border border-red-500/30 bg-red-500/10 px-3 py-2.5 text-xs text-red-300">{{ progress.error }}</p>

            <div v-if="failedItems.length" class="mt-3 max-h-40 space-y-1.5 overflow-y-auto rounded-lg border border-white/10 bg-slate-950/40 p-3">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $t('portonus.failed_header') }}</p>
                <div v-for="(item, idx) in failedItems" :key="idx" class="text-xs text-slate-400">
                    <span class="font-mono text-slate-300">{{ item.slot }}/{{ item.port }}:{{ item.onu_id }}</span><span v-if="item.serial_number"> · {{ item.serial_number }}</span> — {{ item.message }}
                </div>
            </div>

            <div class="mt-6 grid gap-2 sm:flex sm:justify-end">
                <SecondaryButton type="button" @click="start(false)">{{ $t('tr069.rescan') }}</SecondaryButton>
                <PrimaryButton type="button" @click="close">{{ $t('portonus.done') }}</PrimaryButton>
            </div>
        </div>
    </Modal>
</template>
