<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import OnuConfigEditor from '@/Components/SmartOlt/OnuConfigEditor.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle, ArrowLeft, Check, Copy, Eye, ListChecks, RefreshCw, Settings, Terminal,
} from '@lucide/vue';
import { computed, onMounted, reactive, ref, watch } from 'vue';

const props = defineProps({
    olt: { type: Object, required: true },
    slot: { type: Number, required: true },
    port: { type: Number, required: true },
    onu_id: { type: Number, required: true },
    interface: { type: String, required: true },
    profiles: { type: Object, required: true },
    meta: { type: Object, required: true },
    config: { type: Object, required: true },
    raw: { type: String, default: '' },
    fetch_ok: { type: Boolean, default: false },
    fetch_error: { type: String, default: null },
});

const clone = (value) => JSON.parse(JSON.stringify(value ?? null));

const form = useForm({
    config: clone(props.config),
    baseline: clone(props.config),
});

const cfg = form.config;

const summary = computed(() => {
    const b = props.config;
    const wans = b.wan_ips ?? [];
    const firstWan = wans[0] ?? {};
    return [
        ['SN', props.meta.sn || '—'],
        ['ONU ID', `${props.onu_id} (immutable)`],
        ['Name', b.name || '—'],
        ['T-CONT', `${(b.tconts ?? []).length} row`],
        ['GEM Port', `${(b.gemports ?? []).length} row`],
        ['Service-port', `${(b.service_ports ?? []).length} row`],
        ['Service', `${(b.services ?? []).length} row`],
        ['UNI VLAN', `${(b.vlan_ports ?? []).length} row`],
        ['WAN Binding', `${(b.wan_services ?? []).length} row`],
        ['Primary VLAN', b.primary_vlan ?? '—'],
        ['WAN-IP', `${wans.length} entry`],
        ['WAN mode', firstWan.mode || '—'],
        ['VLAN profile', firstWan.vlan_profile || '—'],
        ['PPPoE user', firstWan.pppoe_username || '—'],
        ['TR069', b.tr069 ? 'on' : 'off'],
        ['Sec-mgmt', b.remote_ont ? 'enabled' : 'disabled'],
    ];
});

// --- delta-live preview ---
const preview = reactive({ script: '# Memuat...', changes: [], loading: false });
const copied = ref(false);
let debounceTimer = null;

const runPreview = () => {
    preview.loading = true;
    window.axios
        .post(route('smartolt.onu.configure.preview', [props.olt.id, props.slot, props.port, props.onu_id]), {
            config: cfg,
            baseline: form.baseline,
        })
        .then(({ data }) => {
            preview.script = data.script && data.script.trim() !== ''
                ? data.script
                : '# Tidak ada perubahan vs current config.';
            preview.changes = data.changes ?? [];
        })
        .catch(() => {
            preview.script = '# Gagal memuat preview.';
            preview.changes = [];
        })
        .finally(() => { preview.loading = false; });
};

const schedulePreview = () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(runPreview, 400);
};

watch(() => cfg, schedulePreview, { deep: true });

onMounted(runPreview);

const copyScript = async () => {
    try {
        await navigator.clipboard.writeText(preview.script);
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 1500);
    } catch (e) {
        // clipboard unavailable
    }
};

const refresh = () => router.reload({ preserveScroll: true });

const apply = () => {
    form.post(route('smartolt.onu.configure.apply', [props.olt.id, props.slot, props.port, props.onu_id]), {
        preserveScroll: true,
    });
};

const ifaceLabel = computed(() => props.interface);
const errorList = computed(() => Object.values(form.errors ?? {}));
</script>

<template>
    <Head :title="`Configure ONU ${ifaceLabel}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h2 class="flex items-center gap-2 text-lg font-semibold leading-tight sm:text-xl text-white">
                        <Settings class="h-5 w-5 text-cyan-400" />
                        Configure ONU (CLI): {{ ifaceLabel }}
                    </h2>
                    <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500">
                        <span>{{ olt.name }}</span>
                        <span>· OLT {{ olt.ip }}</span>
                        <span v-if="meta.sn">· SN <span class="font-mono text-slate-400">{{ meta.sn }}</span></span>
                        <span class="inline-flex items-center gap-1 rounded-full bg-sky-500/15 px-2 py-0.5 text-cyan-300 ring-1 ring-cyan-500/30">
                            CLI (show running-config + show onu running config)
                        </span>
                    </p>
                </div>
                <Link :href="route('smartolt.port-onus', [olt.id, slot, port])">
                    <SecondaryButton type="button">
                        <ArrowLeft class="mr-2 h-4 w-4" />
                        Kembali ke Port
                    </SecondaryButton>
                </Link>
            </div>
        </template>

        <div class="min-h-[60vh] pt-5 pb-16 sm:pt-8">
            <div class="w-full space-y-5 px-4 sm:px-6 lg:px-8">

                <!-- Warning banner -->
                <div class="flex items-start gap-3 rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-200">
                    <AlertTriangle class="mt-0.5 h-5 w-5 flex-shrink-0 text-amber-400" />
                    <p>
                        <span class="font-semibold">Perhatian:</span> Setiap perubahan VLAN, service-port, atau WAN credentials akan
                        <span class="font-semibold">memutus koneksi pelanggan ±5-10 detik</span> (re-PPPoE auth). Pastikan info SN &amp; ONU ID benar sebelum Apply.
                    </p>
                </div>

                <div v-if="fetch_error" class="rounded-lg border border-red-500/30 bg-red-500/15 px-4 py-3 text-sm text-red-300">
                    Gagal baca running-config live: {{ fetch_error }}
                </div>

                <div v-if="errorList.length" class="rounded-lg border border-red-500/30 bg-red-500/15 px-4 py-3 text-sm text-red-300">
                    <p class="font-semibold">Periksa kembali input berikut:</p>
                    <ul class="mt-1 list-inside list-disc space-y-0.5">
                        <li v-for="(msg, i) in errorList" :key="i">{{ msg }}</li>
                    </ul>
                </div>

                <div class="grid gap-5 xl:grid-cols-[minmax(0,420px)_1fr]">
                    <!-- LEFT: current config -->
                    <div class="space-y-5">
                        <div class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
                            <div class="flex items-center justify-between border-b border-white/10 px-4 py-4 sm:px-6">
                                <h3 class="flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-slate-200">
                                    <Eye class="h-4 w-4 text-cyan-400" /> Current Config
                                </h3>
                                <button type="button" class="rounded-md p-1.5 text-slate-400 hover:bg-white/5 hover:text-white" title="Refresh" @click="refresh">
                                    <RefreshCw class="h-4 w-4" />
                                </button>
                            </div>
                            <dl class="divide-y divide-white/5 px-4 py-2 text-sm sm:px-6">
                                <div v-for="[label, value] in summary" :key="label" class="flex items-center justify-between gap-4 py-1.5">
                                    <dt class="text-slate-500">{{ label }}</dt>
                                    <dd class="text-right font-medium text-slate-200">{{ value }}</dd>
                                </div>
                            </dl>
                        </div>

                        <div class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
                            <div class="flex items-center gap-2 border-b border-white/10 px-4 py-3 text-xs font-semibold uppercase tracking-wide text-slate-400 sm:px-6">
                                <Terminal class="h-4 w-4" /> Raw running-config
                            </div>
                            <pre class="overflow-x-auto whitespace-pre-wrap break-words bg-slate-950/70 px-4 py-3 font-mono text-xs leading-relaxed text-emerald-300/90">{{ raw || '(kosong)' }}</pre>
                        </div>
                    </div>

                    <!-- RIGHT: editable form -->
                    <OnuConfigEditor :config="cfg" :profiles="profiles" :errors="form.errors" />
                </div>

                <!-- Bottom: generated script + what will change -->
                <div class="grid gap-5 lg:grid-cols-[1fr_minmax(0,360px)]">
                    <section class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
                        <header class="flex items-center justify-between border-b border-white/10 px-4 py-3 sm:px-6">
                            <h3 class="flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-slate-200">
                                <Terminal class="h-4 w-4 text-cyan-400" /> Generated Script (Delta Live)
                                <RefreshCw v-if="preview.loading" class="h-3.5 w-3.5 animate-spin text-slate-500" />
                            </h3>
                            <button type="button" class="kv-add" @click="copyScript">
                                <Check v-if="copied" class="h-3.5 w-3.5" /><Copy v-else class="h-3.5 w-3.5" /> {{ copied ? 'Tersalin' : 'Copy' }}
                            </button>
                        </header>
                        <pre class="max-h-[360px] overflow-auto bg-slate-950/70 px-4 py-3 font-mono text-xs leading-relaxed text-cyan-200/90">{{ preview.script }}</pre>
                    </section>

                    <section class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
                        <header class="flex items-center gap-2 border-b border-white/10 px-4 py-3 sm:px-6">
                            <ListChecks class="h-4 w-4 text-cyan-400" />
                            <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-200">What Will Change</h3>
                        </header>
                        <div class="p-4 sm:p-6">
                            <p v-if="!preview.changes.length" class="text-sm text-slate-500">Tidak ada perubahan.</p>
                            <ul v-else class="space-y-2">
                                <li v-for="(c, i) in preview.changes" :key="i" class="border-l-2 border-amber-500/50 pl-3 text-sm">
                                    <div class="font-medium text-slate-200">{{ c.label }}</div>
                                    <div class="text-xs text-slate-400">
                                        <span class="text-slate-500 line-through">{{ c.from }}</span>
                                        <span class="mx-1 text-cyan-400">→</span>
                                        <span class="text-emerald-300">{{ c.to }}</span>
                                    </div>
                                </li>
                            </ul>
                        </div>
                    </section>
                </div>

                <!-- Action bar -->
                <div class="grid gap-2 rounded-lg border border-white/10 bg-slate-900/40 px-4 py-4 shadow-lg shadow-black/30 backdrop-blur-xl sm:flex sm:items-center sm:justify-end sm:gap-3 sm:px-6">
                    <Link :href="route('smartolt.port-onus', [olt.id, slot, port])" class="block w-full sm:w-auto">
                        <SecondaryButton type="button" class="w-full sm:w-auto">Batal</SecondaryButton>
                    </Link>
                    <PrimaryButton class="w-full sm:w-auto" :disabled="form.processing" @click="apply">
                        <Check class="mr-2 h-4 w-4" />
                        Apply ke OLT (CLI)
                    </PrimaryButton>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
.kv-add {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    border-radius: 0.5rem;
    border: 1px solid rgb(255 255 255 / 0.1);
    background: rgb(15 23 42 / 0.4);
    padding: 0.375rem 0.75rem;
    font-size: 0.75rem;
    font-weight: 600;
    color: rgb(226 232 240);
    transition: all 0.15s;
}
.kv-add:hover { border-color: rgb(6 182 212 / 0.4); color: white; }
</style>
