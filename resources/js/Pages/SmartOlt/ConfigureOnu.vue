<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    Activity, AlertTriangle, ArrowLeft, Check, Cloud, Copy, Cpu, Eye, EyeOff, Globe,
    ListChecks, Network, Plus, RefreshCw, Settings, ShieldCheck, Terminal, Trash2, X,
} from '@lucide/vue';
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue';

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

const page = usePage();
const flash = computed(() => page.props.flash ?? {});
const clone = (value) => JSON.parse(JSON.stringify(value ?? null));

const tcontProfiles = computed(() => props.profiles.tcont ?? []);
const vlanProfiles = computed(() => props.profiles.vlan ?? []);
const ipProfiles = computed(() => props.profiles.ip ?? []);
// Nama profil yang dikenal katalog; dipakai untuk mendeteksi nilai live OLT yang
// belum tersinkron sehingga tetap bisa ditampilkan & dipertahankan di dropdown.
const vlanProfileNames = computed(() => vlanProfiles.value.map((p) => p.name));
const ipProfileNames = computed(() => ipProfiles.value.map((p) => p.name));

const form = useForm({
    config: clone(props.config),
    baseline: clone(props.config),
});

const cfg = form.config;
// Visibilitas password PPPoE per-baris WAN-IP (key = index baris).
const pppoeShown = ref({});

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

const nextId = (rows) => (rows.length ? Math.max(...rows.map((r) => Number(r.id) || 0)) + 1 : 1);

const addTcont = () => cfg.tconts.push({ id: nextId(cfg.tconts), name: '1', profile: tcontProfiles.value[0]?.name ?? '', gap: 'mode0' });
const addGemport = () => cfg.gemports.push({ id: nextId(cfg.gemports), name: '1', tcont: 1, traffic_up: '', traffic_down: '' });
const addServicePort = () => cfg.service_ports.push({ id: nextId(cfg.service_ports), vport: 1, user_vlan: null, vlan: null });
const addService = () => cfg.services.push({ name: '', type: null, mode: 'vlanpri', gem: 1, cos: 0, vlan: null });
const addVlanPort = () => cfg.vlan_ports.push({ port_type: 'eth', port: 1, mode: 'tag', def_vlan: null, priority: null });
const addWanService = () => cfg.wan_services.push({ id: nextId(cfg.wan_services), services: [], mvlan: '', ethuni: '', ssid: '', host: '' });
const addWanIp = () => cfg.wan_ips.push({
    id: nextId(cfg.wan_ips),
    mode: 'pppoe',
    vlan_profile: null,
    pppoe_username: '',
    pppoe_password: '',
    ip_profile: null,
    static_ip: '',
    static_mask_length: 24,
    host: 1,
    ping_response: false,
    traceroute_response: false,
});
const removeRow = (rows, index) => rows.splice(index, 1);

const wanIpModes = [
    { value: 'pppoe', label: 'PPPoE' },
    { value: 'dhcp', label: 'DHCP' },
    { value: 'static', label: 'Static IP' },
];

// WAN Service Binding — service type bisa pilih lebih dari satu (mengikuti
// dialog "New ONU WAN Configuration" NetNumen). MVLAN hanya relevan untuk Other.
const wanServiceTypes = [
    { value: 'internet', label: 'Internet' },
    { value: 'tr069', label: 'TR069' },
    { value: 'voip', label: 'VoIP' },
    { value: 'other', label: 'Other' },
];

const toggleWanServiceType = (row, type) => {
    if (!Array.isArray(row.services)) {
        row.services = [];
    }
    const idx = row.services.indexOf(type);
    if (idx === -1) {
        row.services.push(type);
    } else {
        row.services.splice(idx, 1);
    }
};

// Preview baris CLI persis seperti yang akan di-emit builder (urutan token sama).
const wanServicePreview = (row) => {
    const order = wanServiceTypes.map((t) => t.value);
    const types = order.filter((t) => (row.services ?? []).includes(t));
    if (!types.length) {
        return `wan ${row.id} — pilih minimal 1 service type`;
    }
    let line = `wan ${row.id} service ${types.join(' ')}`;
    if (types.includes('other') && row.mvlan) {
        line += ` mvlan ${row.mvlan}`;
    }
    if (row.ethuni) line += ` ethuni ${row.ethuni}`;
    if (row.ssid) line += ` ssid ${row.ssid}`;
    if (row.host) line += ` host ${row.host}`;
    return line;
};

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

// Tabel baris-berulang ditampilkan sebagai grid di ≥768px dan kartu ber-label di
// layar kecil. Karena grid-template-columns dipasang via inline style (tak bisa
// di-override media query), pemilihan layout dikendalikan reaktif lewat matchMedia.
const isWide = ref(typeof window !== 'undefined' ? window.matchMedia('(min-width: 768px)').matches : true);
let wideMq = null;
const syncWide = () => { isWide.value = wideMq?.matches ?? true; };

onMounted(() => {
    runPreview();
    wideMq = window.matchMedia('(min-width: 768px)');
    syncWide();
    wideMq.addEventListener('change', syncWide);
});

onUnmounted(() => {
    wideMq?.removeEventListener('change', syncWide);
});

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

const cols = {
    tcont: '4rem minmax(8rem,1fr) minmax(8rem,1fr) minmax(6rem,1fr) 2.75rem',
    gemport: '4rem minmax(7rem,1fr) 6rem minmax(7rem,1fr) minmax(7rem,1fr) 2.75rem',
    servicePort: '4rem minmax(6rem,1fr) minmax(7rem,1fr) minmax(6rem,1fr) 2.75rem',
    service: 'minmax(8rem,1.3fr) minmax(6rem,1fr) 4rem 4rem minmax(5rem,1fr) 2.75rem',
    uniVlan: '8rem 6rem 7rem minmax(5rem,1fr) minmax(5rem,1fr) 2.75rem',
};

const fieldClass = 'mt-1 block w-full rounded-md border-white/10 bg-slate-950/40 text-slate-100 shadow-sm focus:border-cyan-500 focus:ring-cyan-500';
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
                <!-- Flash -->
                <div v-if="flash.success" class="flex items-center gap-3 rounded-lg border border-emerald-500/30 bg-emerald-500/15 px-4 py-3 text-sm text-emerald-300">
                    <span class="h-2 w-2 flex-shrink-0 rounded-full bg-emerald-500"></span>{{ flash.success }}
                </div>
                <div v-if="flash.error" class="flex items-center gap-3 rounded-lg border border-red-500/30 bg-red-500/15 px-4 py-3 text-sm text-red-300">
                    <span class="h-2 w-2 flex-shrink-0 rounded-full bg-red-500"></span>{{ flash.error }}
                </div>

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
                    <div class="space-y-5">
                        <!-- Interface name -->
                        <section class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
                            <header class="flex items-center gap-2 border-b border-white/10 px-4 py-3 sm:px-6">
                                <Network class="h-4 w-4 text-cyan-400" />
                                <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-200">Interface GPON-ONU</h3>
                            </header>
                            <div class="p-4 sm:p-6">
                                <InputLabel for="name" value="Name *" />
                                <TextInput id="name" v-model="cfg.name" class="mt-1 block w-full" required />
                                <InputError class="mt-1.5" :message="form.errors['config.name']" />
                            </div>
                        </section>

                        <!-- T-CONT -->
                        <section class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
                            <header class="flex items-center justify-between border-b border-white/10 px-4 py-3 sm:px-6">
                                <h3 class="flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-slate-200"><Cpu class="h-4 w-4 text-cyan-400" /> T-CONT</h3>
                                <button type="button" class="kv-add" @click="addTcont"><Plus class="h-3.5 w-3.5" /> Tambah</button>
                            </header>
                            <div class="overflow-x-auto px-3 py-3 sm:px-4">
                                <div class="space-y-3 md:min-w-[600px] md:space-y-1.5">
                                    <div v-if="isWide" class="kv-thead" :style="{ gridTemplateColumns: cols.tcont }">
                                        <span>ID</span><span>Name</span><span>Profile</span><span>Gap</span><span></span>
                                    </div>
                                    <div v-for="(row, i) in cfg.tconts" :key="`tc-${i}`" :class="isWide ? 'kv-trow' : 'kv-rowcard'" :style="isWide ? { gridTemplateColumns: cols.tcont } : null">
                                        <div class="kv-cell"><span v-if="!isWide" class="kv-flabel">ID</span><TextInput v-model.number="row.id" type="number" :class="fieldClass" /></div>
                                        <div class="kv-cell"><span v-if="!isWide" class="kv-flabel">Name</span><TextInput v-model="row.name" :class="fieldClass" /></div>
                                        <div class="kv-cell"><span v-if="!isWide" class="kv-flabel">Profile</span>
                                            <select v-model="row.profile" :class="fieldClass">
                                                <option v-for="p in tcontProfiles" :key="p.id" :value="p.name">{{ p.name }}</option>
                                            </select>
                                        </div>
                                        <div class="kv-cell"><span v-if="!isWide" class="kv-flabel">Gap</span><TextInput v-model="row.gap" :class="fieldClass" /></div>
                                        <div class="kv-cell kv-action-cell">
                                            <button type="button" :class="isWide ? 'kv-del' : 'kv-del-mobile'" @click="removeRow(cfg.tconts, i)"><Trash2 class="h-4 w-4" /><span v-if="!isWide">Hapus baris</span></button>
                                        </div>
                                    </div>
                                    <p v-if="!cfg.tconts.length" class="py-1 text-xs text-slate-500">Belum ada T-CONT.</p>
                                </div>
                            </div>
                        </section>

                        <!-- GEM Port -->
                        <section class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
                            <header class="flex items-center justify-between border-b border-white/10 px-4 py-3 sm:px-6">
                                <h3 class="flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-slate-200"><Network class="h-4 w-4 text-cyan-400" /> GEM Port</h3>
                                <button type="button" class="kv-add" @click="addGemport"><Plus class="h-3.5 w-3.5" /> Tambah</button>
                            </header>
                            <div class="overflow-x-auto px-3 py-3 sm:px-4">
                                <div class="space-y-3 md:min-w-[700px] md:space-y-1.5">
                                    <div v-if="isWide" class="kv-thead" :style="{ gridTemplateColumns: cols.gemport }">
                                        <span>ID</span><span>Name</span><span>T-CONT</span><span>Upstream Limit</span><span>Downstream Limit</span><span></span>
                                    </div>
                                    <div v-for="(row, i) in cfg.gemports" :key="`gp-${i}`" :class="isWide ? 'kv-trow' : 'kv-rowcard'" :style="isWide ? { gridTemplateColumns: cols.gemport } : null">
                                        <div class="kv-cell"><span v-if="!isWide" class="kv-flabel">ID</span><TextInput v-model.number="row.id" type="number" :class="fieldClass" /></div>
                                        <div class="kv-cell"><span v-if="!isWide" class="kv-flabel">Name</span><TextInput v-model="row.name" :class="fieldClass" /></div>
                                        <div class="kv-cell"><span v-if="!isWide" class="kv-flabel">T-CONT</span><TextInput v-model.number="row.tcont" type="number" :class="fieldClass" /></div>
                                        <div class="kv-cell"><span v-if="!isWide" class="kv-flabel">Upstream Limit</span><TextInput v-model="row.traffic_up" :class="fieldClass" placeholder="profile" /></div>
                                        <div class="kv-cell"><span v-if="!isWide" class="kv-flabel">Downstream Limit</span><TextInput v-model="row.traffic_down" :class="fieldClass" placeholder="profile" /></div>
                                        <div class="kv-cell kv-action-cell">
                                            <button type="button" :class="isWide ? 'kv-del' : 'kv-del-mobile'" @click="removeRow(cfg.gemports, i)"><Trash2 class="h-4 w-4" /><span v-if="!isWide">Hapus baris</span></button>
                                        </div>
                                    </div>
                                    <p v-if="!cfg.gemports.length" class="py-1 text-xs text-slate-500">Belum ada GEM Port.</p>
                                </div>
                            </div>
                        </section>

                        <!-- Service-port -->
                        <section class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
                            <header class="flex items-center justify-between border-b border-white/10 px-4 py-3 sm:px-6">
                                <h3 class="flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-slate-200"><ListChecks class="h-4 w-4 text-cyan-400" /> Service-port</h3>
                                <button type="button" class="kv-add" @click="addServicePort"><Plus class="h-3.5 w-3.5" /> Tambah</button>
                            </header>
                            <div class="overflow-x-auto px-3 py-3 sm:px-4">
                                <div class="space-y-3 md:min-w-[560px] md:space-y-1.5">
                                    <div v-if="isWide" class="kv-thead" :style="{ gridTemplateColumns: cols.servicePort }">
                                        <span>ID</span><span>VPort</span><span>User VLAN</span><span>VLAN</span><span></span>
                                    </div>
                                    <div v-for="(row, i) in cfg.service_ports" :key="`sp-${i}`" :class="isWide ? 'kv-trow' : 'kv-rowcard'" :style="isWide ? { gridTemplateColumns: cols.servicePort } : null">
                                        <div class="kv-cell"><span v-if="!isWide" class="kv-flabel">ID</span><TextInput v-model.number="row.id" type="number" :class="fieldClass" /></div>
                                        <div class="kv-cell"><span v-if="!isWide" class="kv-flabel">VPort</span><TextInput v-model.number="row.vport" type="number" :class="fieldClass" /></div>
                                        <div class="kv-cell"><span v-if="!isWide" class="kv-flabel">User VLAN</span><TextInput v-model.number="row.user_vlan" type="number" :class="fieldClass" /></div>
                                        <div class="kv-cell"><span v-if="!isWide" class="kv-flabel">VLAN</span><TextInput v-model.number="row.vlan" type="number" :class="fieldClass" /></div>
                                        <div class="kv-cell kv-action-cell">
                                            <button type="button" :class="isWide ? 'kv-del' : 'kv-del-mobile'" @click="removeRow(cfg.service_ports, i)"><Trash2 class="h-4 w-4" /><span v-if="!isWide">Hapus baris</span></button>
                                        </div>
                                    </div>
                                    <p v-if="!cfg.service_ports.length" class="py-1 text-xs text-slate-500">Belum ada service-port.</p>
                                </div>
                            </div>
                        </section>

                        <!-- PON-ONU-MNG / Service -->
                        <section class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
                            <header class="flex items-center justify-between border-b border-white/10 px-4 py-3 sm:px-6">
                                <h3 class="flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-slate-200"><Settings class="h-4 w-4 text-cyan-400" /> PON-ONU-MNG / Service</h3>
                                <button type="button" class="kv-add" @click="addService"><Plus class="h-3.5 w-3.5" /> Tambah</button>
                            </header>
                            <div class="overflow-x-auto px-3 py-3 sm:px-4">
                                <div class="space-y-3 md:min-w-[600px] md:space-y-1.5">
                                    <div v-if="isWide" class="kv-thead" :style="{ gridTemplateColumns: cols.service }">
                                        <span>Name</span><span>Mode</span><span>GEM</span><span>COS</span><span>VLAN</span><span></span>
                                    </div>
                                    <div v-for="(row, i) in cfg.services" :key="`sv-${i}`" :class="isWide ? 'kv-trow' : 'kv-rowcard'" :style="isWide ? { gridTemplateColumns: cols.service } : null">
                                        <div class="kv-cell"><span v-if="!isWide" class="kv-flabel">Name</span><TextInput v-model="row.name" :class="fieldClass" /></div>
                                        <div class="kv-cell"><span v-if="!isWide" class="kv-flabel">Mode</span>
                                            <select v-model="row.mode" :class="fieldClass">
                                                <option value="vlanpri">VLAN+Priority</option>
                                                <option value="transparent">Transparent</option>
                                            </select>
                                        </div>
                                        <div class="kv-cell"><span v-if="!isWide" class="kv-flabel">GEM</span><TextInput v-model.number="row.gem" type="number" :class="fieldClass" /></div>
                                        <div class="kv-cell"><span v-if="!isWide" class="kv-flabel">COS</span><TextInput v-model.number="row.cos" type="number" :class="fieldClass" :disabled="row.mode === 'transparent'" /></div>
                                        <div class="kv-cell"><span v-if="!isWide" class="kv-flabel">VLAN</span><TextInput v-model.number="row.vlan" type="number" :class="fieldClass" :disabled="row.mode === 'transparent'" /></div>
                                        <div class="kv-cell kv-action-cell">
                                            <button type="button" :class="isWide ? 'kv-del' : 'kv-del-mobile'" @click="removeRow(cfg.services, i)"><Trash2 class="h-4 w-4" /><span v-if="!isWide">Hapus baris</span></button>
                                        </div>
                                    </div>
                                    <p v-if="!cfg.services.length" class="py-1 text-xs text-slate-500">Belum ada service.</p>
                                </div>
                            </div>
                        </section>

                        <!-- UNI VLAN -->
                        <section class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
                            <header class="flex items-center justify-between border-b border-white/10 px-4 py-3 sm:px-6">
                                <h3 class="flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-slate-200"><Globe class="h-4 w-4 text-cyan-400" /> UNI VLAN</h3>
                                <button type="button" class="kv-add" @click="addVlanPort"><Plus class="h-3.5 w-3.5" /> Tambah</button>
                            </header>
                            <div class="overflow-x-auto px-3 py-3 sm:px-4">
                                <div class="space-y-3 md:min-w-[680px] md:space-y-1.5">
                                    <div v-if="isWide" class="kv-thead" :style="{ gridTemplateColumns: cols.uniVlan }">
                                        <span>Port Type</span><span>Port</span><span>Mode</span><span>Def VLAN</span><span>Priority</span><span></span>
                                    </div>
                                    <div v-for="(row, i) in cfg.vlan_ports" :key="`uv-${i}`" :class="isWide ? 'kv-trow' : 'kv-rowcard'" :style="isWide ? { gridTemplateColumns: cols.uniVlan } : null">
                                        <div class="kv-cell"><span v-if="!isWide" class="kv-flabel">Port Type</span>
                                            <select v-model="row.port_type" :class="fieldClass">
                                                <option value="eth">Ethernet</option>
                                                <option value="wifi">WiFi</option>
                                            </select>
                                        </div>
                                        <div class="kv-cell"><span v-if="!isWide" class="kv-flabel">Port</span><TextInput v-model.number="row.port" type="number" :class="fieldClass" /></div>
                                        <div class="kv-cell"><span v-if="!isWide" class="kv-flabel">Mode</span>
                                            <select v-model="row.mode" :class="fieldClass">
                                                <option value="tag">tag</option>
                                                <option value="hybrid">hybrid</option>
                                                <option value="trunk">trunk</option>
                                                <option value="transparent">transparent</option>
                                            </select>
                                        </div>
                                        <div class="kv-cell"><span v-if="!isWide" class="kv-flabel">Def VLAN</span><TextInput v-model.number="row.def_vlan" type="number" :class="fieldClass" :disabled="row.mode === 'trunk' || row.mode === 'transparent'" /></div>
                                        <div class="kv-cell"><span v-if="!isWide" class="kv-flabel">Priority</span><TextInput v-model.number="row.priority" type="number" :class="fieldClass" :disabled="row.mode === 'trunk' || row.mode === 'transparent'" /></div>
                                        <div class="kv-cell kv-action-cell">
                                            <button type="button" :class="isWide ? 'kv-del' : 'kv-del-mobile'" @click="removeRow(cfg.vlan_ports, i)"><Trash2 class="h-4 w-4" /><span v-if="!isWide">Hapus baris</span></button>
                                        </div>
                                    </div>
                                    <p v-if="!cfg.vlan_ports.length" class="py-1 text-xs text-slate-500">Belum ada UNI VLAN.</p>
                                </div>
                            </div>
                        </section>

                        <!-- WAN service binding -->
                        <section class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
                            <header class="flex items-center justify-between border-b border-white/10 px-4 py-3 sm:px-6">
                                <h3 class="flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-slate-200"><Network class="h-4 w-4 text-cyan-400" /> WAN Service Binding</h3>
                                <button type="button" class="kv-add" @click="addWanService"><Plus class="h-3.5 w-3.5" /> Tambah</button>
                            </header>
                            <div class="space-y-4 p-4 sm:p-6">
                                <p v-if="!cfg.wan_services.length" class="rounded-lg border border-dashed border-white/10 bg-slate-950/30 px-4 py-3 text-sm text-slate-500">
                                    Belum ada WAN binding. Klik <span class="font-semibold text-slate-300">Tambah</span>.
                                </p>

                                <div
                                    v-for="(w, i) in cfg.wan_services" :key="`ws-${i}`"
                                    class="space-y-4 rounded-lg border border-white/10 bg-slate-950/30 p-4"
                                >
                                    <div class="flex items-center justify-between">
                                        <span class="inline-flex items-center gap-2 rounded-full bg-sky-500/15 px-2.5 py-0.5 text-xs font-semibold text-cyan-300 ring-1 ring-cyan-500/30">
                                            WAN {{ w.id }}
                                        </span>
                                        <button type="button" class="kv-del" title="Hapus WAN binding" @click="removeRow(cfg.wan_services, i)"><Trash2 class="h-4 w-4" /></button>
                                    </div>

                                    <!-- Service Type (multi) -->
                                    <div>
                                        <InputLabel value="Service Type" />
                                        <div class="mt-2 flex flex-wrap gap-2">
                                            <button
                                                v-for="t in wanServiceTypes" :key="t.value" type="button"
                                                class="rounded-lg border px-4 py-2 text-sm font-medium transition-all"
                                                :class="(w.services ?? []).includes(t.value) ? 'border-cyan-500 bg-cyan-500 text-white' : 'border-white/10 bg-slate-900/40 text-slate-200 hover:border-cyan-500/40'"
                                                @click="toggleWanServiceType(w, t.value)"
                                            >{{ t.label }}</button>
                                        </div>
                                    </div>

                                    <!-- WAN ID / Host / Ethuni / SSID -->
                                    <div class="grid gap-4 sm:grid-cols-4">
                                        <div>
                                            <InputLabel value="WAN ID" />
                                            <TextInput v-model.number="w.id" type="number" min="1" class="mt-1 block w-full" />
                                        </div>
                                        <div>
                                            <InputLabel value="Host (IP Host ID)" />
                                            <TextInput v-model="w.host" class="mt-1 block w-full" placeholder="1" />
                                        </div>
                                        <div>
                                            <InputLabel value="Ethernet UNI" />
                                            <TextInput v-model="w.ethuni" class="mt-1 block w-full" placeholder="1,2,3" />
                                        </div>
                                        <div>
                                            <InputLabel value="SSID" />
                                            <TextInput v-model="w.ssid" class="mt-1 block w-full" placeholder="1,2" />
                                        </div>
                                    </div>

                                    <!-- MVLAN: hanya untuk Other -->
                                    <div v-if="(w.services ?? []).includes('other')" class="sm:max-w-xs">
                                        <InputLabel value="MVLAN" />
                                        <TextInput v-model="w.mvlan" class="mt-1 block w-full" placeholder="1001" />
                                        <p class="mt-1 text-xs text-slate-500">Wajib untuk service <span class="font-semibold text-slate-300">Other</span>.</p>
                                    </div>

                                    <p class="text-xs text-slate-500">
                                        CLI: <span class="font-mono text-slate-400">{{ wanServicePreview(w) }}</span>
                                    </p>
                                </div>
                            </div>
                        </section>

                        <!-- WAN (multi WAN-IP) -->
                        <section class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
                            <header class="flex items-center justify-between border-b border-white/10 px-4 py-3 sm:px-6">
                                <h3 class="flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-slate-200">
                                    <Globe class="h-4 w-4 text-cyan-400" /> WAN
                                </h3>
                                <button type="button" class="kv-add" @click="addWanIp"><Plus class="h-3.5 w-3.5" /> Tambah WAN-IP</button>
                            </header>
                            <div class="space-y-4 p-4 sm:p-6">
                                <p v-if="!cfg.wan_ips.length" class="rounded-lg border border-dashed border-white/10 bg-slate-950/30 px-4 py-3 text-sm text-slate-500">
                                    Belum ada WAN-IP. Klik <span class="font-semibold text-slate-300">Tambah WAN-IP</span> untuk membuat koneksi WAN.
                                </p>

                                <div
                                    v-for="(w, i) in cfg.wan_ips" :key="`wan-${i}`"
                                    class="space-y-4 rounded-lg border border-white/10 bg-slate-950/30 p-4"
                                >
                                    <div class="flex items-center justify-between">
                                        <span class="inline-flex items-center gap-2 rounded-full bg-sky-500/15 px-2.5 py-0.5 text-xs font-semibold text-cyan-300 ring-1 ring-cyan-500/30">
                                            WAN-IP {{ w.id }}
                                        </span>
                                        <button type="button" class="kv-del" title="Hapus WAN-IP" @click="removeRow(cfg.wan_ips, i)"><Trash2 class="h-4 w-4" /></button>
                                    </div>

                                    <!-- Mode -->
                                    <div>
                                        <InputLabel value="Mode" />
                                        <div class="mt-2 flex flex-wrap gap-2">
                                            <button
                                                v-for="m in wanIpModes" :key="m.value" type="button"
                                                class="rounded-lg border px-4 py-2 text-sm font-medium transition-all"
                                                :class="w.mode === m.value ? 'border-cyan-500 bg-cyan-500 text-white' : 'border-white/10 bg-slate-900/40 text-slate-200 hover:border-cyan-500/40'"
                                                @click="w.mode = m.value"
                                            >{{ m.label }}</button>
                                        </div>
                                    </div>

                                    <!-- Index / Host / VLAN Profile -->
                                    <div class="grid gap-4 sm:grid-cols-4">
                                        <div>
                                            <InputLabel value="Index" />
                                            <TextInput v-model.number="w.id" type="number" min="1" max="8" class="mt-1 block w-full" />
                                        </div>
                                        <div>
                                            <InputLabel value="Host" />
                                            <TextInput v-model.number="w.host" type="number" min="1" max="16" class="mt-1 block w-full" />
                                        </div>
                                        <div class="sm:col-span-2">
                                            <InputLabel value="VLAN Profile (optional)" />
                                            <select v-model="w.vlan_profile" :class="fieldClass">
                                                <option :value="null">Tanpa profile</option>
                                                <option v-if="w.vlan_profile && !vlanProfileNames.includes(w.vlan_profile)" :value="w.vlan_profile">{{ w.vlan_profile }} (dari OLT)</option>
                                                <option v-for="p in vlanProfiles" :key="p.id" :value="p.name">{{ p.name }}</option>
                                            </select>
                                        </div>
                                    </div>

                                    <!-- PPPoE -->
                                    <div v-if="w.mode === 'pppoe'" class="grid gap-4 md:grid-cols-2">
                                        <div>
                                            <InputLabel value="PPPoE Username" />
                                            <TextInput v-model="w.pppoe_username" class="mt-1 block w-full" autocomplete="off" data-1p-ignore data-lpignore="true" />
                                        </div>
                                        <div>
                                            <InputLabel value="PPPoE Password" />
                                            <div class="relative mt-1">
                                                <TextInput v-model="w.pppoe_password" :type="pppoeShown[i] ? 'text' : 'password'" class="block w-full pr-10" placeholder="••••••••" autocomplete="new-password" data-1p-ignore data-lpignore="true" />
                                                <button type="button" class="absolute inset-y-0 right-0 flex items-center px-3 text-slate-400 hover:text-white" @click="pppoeShown[i] = !pppoeShown[i]">
                                                    <EyeOff v-if="pppoeShown[i]" class="h-4 w-4" /><Eye v-else class="h-4 w-4" />
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Static -->
                                    <div v-if="w.mode === 'static'" class="grid gap-4 md:grid-cols-3">
                                        <div>
                                            <InputLabel value="IP Profile" />
                                            <select v-model="w.ip_profile" :class="fieldClass">
                                                <option :value="null">—</option>
                                                <option v-if="w.ip_profile && !ipProfileNames.includes(w.ip_profile)" :value="w.ip_profile">{{ w.ip_profile }} (dari OLT)</option>
                                                <option v-for="p in ipProfiles" :key="p.id" :value="p.name">{{ p.name }}</option>
                                            </select>
                                        </div>
                                        <div>
                                            <InputLabel value="Static IP" />
                                            <TextInput v-model="w.static_ip" class="mt-1 block w-full font-mono" />
                                        </div>
                                        <div>
                                            <InputLabel value="Subnet Prefix (/)" />
                                            <TextInput v-model.number="w.static_mask_length" type="number" min="1" max="32" class="mt-1 block w-full" />
                                        </div>
                                    </div>

                                    <!-- Probe response toggles -->
                                    <div>
                                        <InputLabel value="Probe Response" />
                                        <div class="mt-2 flex flex-wrap gap-2">
                                            <button
                                                type="button"
                                                class="inline-flex items-center gap-2 rounded-lg border px-4 py-2 text-sm font-medium transition-all"
                                                :class="w.ping_response ? 'border-emerald-500 bg-emerald-500/15 text-emerald-300' : 'border-white/10 bg-slate-900/40 text-slate-300 hover:border-emerald-500/40'"
                                                @click="w.ping_response = !w.ping_response"
                                            >
                                                <Check v-if="w.ping_response" class="h-4 w-4" /><X v-else class="h-4 w-4" />
                                                Ping Response
                                            </button>
                                            <button
                                                type="button"
                                                class="inline-flex items-center gap-2 rounded-lg border px-4 py-2 text-sm font-medium transition-all"
                                                :class="w.traceroute_response ? 'border-emerald-500 bg-emerald-500/15 text-emerald-300' : 'border-white/10 bg-slate-900/40 text-slate-300 hover:border-emerald-500/40'"
                                                @click="w.traceroute_response = !w.traceroute_response"
                                            >
                                                <Activity v-if="w.traceroute_response" class="h-4 w-4" /><X v-else class="h-4 w-4" />
                                                Traceroute Response
                                            </button>
                                        </div>
                                        <p class="mt-1.5 text-xs text-slate-500">
                                            CLI: <span class="font-mono text-slate-400">wan-ip {{ w.id }} ping-response {{ w.ping_response ? 'enable' : 'disable' }} traceroute-response {{ w.traceroute_response ? 'enable' : 'disable' }}</span>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <!-- TR069 -->
                        <section class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
                            <header class="flex items-center justify-between border-b border-white/10 px-4 py-3 sm:px-6">
                                <h3 class="flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-slate-200"><Cloud class="h-4 w-4 text-cyan-400" /> TR069 / ACS</h3>
                                <label class="inline-flex cursor-pointer items-center gap-2">
                                    <input v-model="cfg.tr069" type="checkbox" class="h-4 w-4 rounded border-white/10 text-cyan-400 focus:ring-cyan-500" />
                                    <span class="text-xs font-medium text-slate-300">Enable</span>
                                </label>
                            </header>
                            <div v-if="cfg.tr069" class="space-y-4 p-4 sm:p-6">
                                <div>
                                    <InputLabel for="acs_url" value="ACS URL" />
                                    <TextInput id="acs_url" v-model="cfg.acs_url" class="mt-1 block w-full" />
                                </div>
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <InputLabel for="acs_username" value="Username" />
                                        <TextInput id="acs_username" v-model="cfg.acs_username" class="mt-1 block w-full" />
                                    </div>
                                    <div>
                                        <InputLabel for="acs_password" value="Password" />
                                        <TextInput id="acs_password" v-model="cfg.acs_password" type="password" class="mt-1 block w-full" />
                                    </div>
                                </div>
                            </div>
                        </section>

                        <!-- Security-mgmt / Remote ONT -->
                        <section class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
                            <header class="flex items-center justify-between border-b border-white/10 px-4 py-3 sm:px-6">
                                <h3 class="flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-slate-200"><ShieldCheck class="h-4 w-4 text-cyan-400" /> Security-mgmt (Remote ONT)</h3>
                                <label class="inline-flex cursor-pointer items-center gap-2">
                                    <input v-model="cfg.remote_ont" type="checkbox" class="h-4 w-4 rounded border-white/10 text-cyan-400 focus:ring-cyan-500" />
                                    <span class="text-xs font-medium text-slate-300">Enable</span>
                                </label>
                            </header>
                            <div v-if="cfg.remote_ont" class="grid gap-4 p-4 sm:grid-cols-3 sm:p-6">
                                <div>
                                    <InputLabel for="remote_ont_id" value="ID" />
                                    <TextInput id="remote_ont_id" v-model.number="cfg.remote_ont_id" type="number" class="mt-1 block w-full" />
                                </div>
                                <div>
                                    <InputLabel for="remote_ont_mode" value="Mode" />
                                    <select id="remote_ont_mode" v-model="cfg.remote_ont_mode" :class="fieldClass">
                                        <option value="forward">forward</option>
                                        <option value="discard">discard</option>
                                    </select>
                                </div>
                                <div>
                                    <InputLabel for="remote_ont_protocol" value="Protocol" />
                                    <select id="remote_ont_protocol" v-model="cfg.remote_ont_protocol" :class="fieldClass">
                                        <option v-for="p in ['web','telnet','ssh','ftp','tftp','snmp']" :key="p" :value="p">{{ p }}</option>
                                    </select>
                                </div>
                            </div>
                        </section>
                    </div>
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
.kv-del {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 0.5rem;
    border: 1px solid rgb(239 68 68 / 0.3);
    background: rgb(239 68 68 / 0.1);
    padding: 0.5rem;
    color: rgb(252 165 165);
    transition: all 0.15s;
}
.kv-del:hover { background: rgb(239 68 68 / 0.2); }

.kv-thead {
    display: grid;
    align-items: center;
    gap: 0.5rem;
    border-radius: 0.375rem;
    background: rgb(2 6 23 / 0.4);
    border: 1px solid rgb(255 255 255 / 0.08);
    padding: 0.5rem 0.625rem;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: rgb(100 116 139);
}
.kv-trow {
    display: grid;
    align-items: center;
    gap: 0.5rem;
    padding: 0.125rem 0.625rem;
}

/* Grid cells (desktop) must be allowed to shrink so inputs don't overflow. */
.kv-cell {
    min-width: 0;
}
.kv-action-cell {
    display: flex;
    align-items: center;
    justify-content: center;
}

/* === Mobile: each repeating row becomes a labeled card === */
.kv-rowcard {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.5rem 0.75rem;
    border-radius: 0.625rem;
    border: 1px solid rgb(255 255 255 / 0.08);
    background: rgb(2 6 23 / 0.4);
    padding: 0.75rem;
}
.kv-rowcard .kv-action-cell {
    grid-column: 1 / -1;
    margin-top: 0.125rem;
}
.kv-flabel {
    display: block;
    margin-bottom: 0;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: rgb(100 116 139);
}
.kv-del-mobile {
    display: inline-flex;
    width: 100%;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    border-radius: 0.5rem;
    border: 1px solid rgb(239 68 68 / 0.3);
    background: rgb(239 68 68 / 0.1);
    padding: 0.5rem 0.75rem;
    font-size: 0.8125rem;
    font-weight: 600;
    color: rgb(252 165 165);
    transition: all 0.15s;
}
.kv-del-mobile:hover { background: rgb(239 68 68 / 0.2); }
</style>
