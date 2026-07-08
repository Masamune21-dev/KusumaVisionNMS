<script setup>
import ConfirmModal from '@/Components/ConfirmModal.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import OnuConfigEditor from '@/Components/SmartOlt/OnuConfigEditor.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Check, Copy, Cpu, Globe, LayoutList, Settings, SlidersHorizontal, Terminal, User, Zap } from '@lucide/vue';
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue';

const props = defineProps({
    olt: {
        type: Object,
        required: true,
    },
    defaults: {
        type: Object,
        required: true,
    },
    advanced_defaults: {
        type: Object,
        required: true,
    },
    profiles: {
        type: Object,
        required: true,
    },
});

const clone = (value) => JSON.parse(JSON.stringify(value ?? null));

// 'simple' = wizard template tetap (1 service); 'advanced' = editor granular
// (tcont/gemport/service-port/service per baris) untuk multi-service.
const mode = ref('simple');

const form = useForm({ ...props.defaults });

// Form mode Lanjutan: header registrasi + config granular (dipakai OnuConfigEditor).
const advForm = useForm({
    serial_number: props.defaults.serial_number,
    slot: props.defaults.slot,
    port: props.defaults.port,
    onu_id: props.defaults.onu_id,
    oid_index: props.defaults.oid_index,
    onu_type: props.defaults.onu_type,
    config: clone(props.advanced_defaults),
});

const advErrorList = computed(() => Object.values(advForm.errors ?? {}));
const onuTypeProfiles = computed(() => props.profiles.onu_type ?? []);
const tcontProfiles = computed(() => props.profiles.tcont ?? []);
const vlanProfiles = computed(() => props.profiles.vlan ?? []);
const ipProfiles = computed(() => props.profiles.ip ?? []);
const canExecute = computed(() => !!(props.olt.capabilities?.supports_cli_onu_configure));

const serviceModes = [
    { value: 'vlanpri', label: 'VLAN + Priority' },
    { value: 'transparent', label: 'Transparent' },
];

// VLAN profile hanya menyetel VLAN ID — Service Name dibiarkan independen
// (input user / default 'ServiceName'), tidak ikut nama VLAN profile.
watch(() => form.vlan_profile, (name) => {
    const profile = vlanProfiles.value.find((item) => item.name === name);
    if (!profile) {
        return;
    }

    form.vlan = profile.vlan;
});

// Mode bridge tak memakai vlan-profile (yang cuma relevan untuk baris wan-ip
// routed) — kosongkan agar VLAN ID numerik tetap otoritatif.
watch(() => form.wan_mode, (mode) => {
    if (mode === 'bridge') {
        form.vlan_profile = '';
    }
});

// --- live raw CLI preview (debounced, read-only ke server) ---
const preview = reactive({ script: '# Mengisi form untuk melihat script…', loading: false });
let debounceTimer = null;

const runPreview = () => {
    preview.loading = true;
    const isAdvanced = mode.value === 'advanced';
    const url = isAdvanced
        ? route('smartolt.register.advanced.preview', props.olt.id)
        : route('smartolt.register.preview', props.olt.id);
    const payload = isAdvanced ? { ...advForm.data() } : { ...form.data() };

    window.axios
        .post(url, payload)
        .then(({ data }) => {
            preview.script = data.script && data.script.trim() !== '' ? data.script : '# (script kosong)';
        })
        .catch(() => {
            preview.script = '# Gagal memuat preview script.';
        })
        .finally(() => {
            preview.loading = false;
        });
};

const schedulePreview = () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(runPreview, 400);
};

const activePayload = computed(() => (mode.value === 'advanced'
    ? JSON.stringify(advForm.data())
    : JSON.stringify(form.data())));

watch(activePayload, schedulePreview);
watch(mode, () => { preview.script = '# Memuat…'; runPreview(); });
onMounted(runPreview);
onUnmounted(() => clearTimeout(debounceTimer));

const copied = ref(false);
const copyScript = async () => {
    try {
        await navigator.clipboard.writeText(preview.script);
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 1500);
    } catch {
        // clipboard tak tersedia — abaikan
    }
};

const { confirmState, confirm, handleConfirm, handleCancel } = useConfirm();

const submit = async (execute) => {
    if (execute) {
        const ok = await confirm({
            title: 'Eksekusi ke OLT',
            message: `Register & eksekusi ONU ${form.serial_number || ''} ke ${props.olt.name} sekarang? Script akan langsung dijalankan via CLI Telnet.`,
            confirmLabel: 'Eksekusi',
        });

        if (!ok) {
            return;
        }
    }

    form
        .transform((data) => ({ ...data, execute }))
        .post(route('smartolt.register.store', props.olt.id), {
            preserveScroll: true,
        });
};

const submitAdvanced = async (execute) => {
    if (execute) {
        const ok = await confirm({
            title: 'Eksekusi ke OLT',
            message: `Register & eksekusi ONU ${advForm.serial_number || ''} ke ${props.olt.name} sekarang? Script granular akan langsung dijalankan via CLI Telnet.`,
            confirmLabel: 'Eksekusi',
        });

        if (!ok) {
            return;
        }
    }

    advForm
        .transform((data) => ({ ...data, execute }))
        .post(route('smartolt.register.advanced.store', props.olt.id), {
            preserveScroll: true,
        });
};
</script>

<template>
    <Head title="Register ONU" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h2 class="text-lg font-semibold leading-tight sm:text-xl text-white">Register ONU</h2>
                <p class="mt-1 text-sm text-slate-500">{{ olt.name }} · provisioning ONU + eksekusi langsung ke OLT</p>
            </div>
        </template>

        <div class="min-h-[60vh] pt-5 pb-16 sm:pt-8">
            <div class="w-full px-4 sm:px-6 lg:px-8">
                <div class="grid gap-5 xl:grid-cols-[minmax(0,480px)_1fr]">

                    <!-- Kolom kiri: Live Raw CLI -->
                    <div class="order-2 xl:order-1 xl:sticky xl:top-6 xl:self-start">
                        <div class="overflow-hidden rounded-lg border border-white/10 bg-slate-950/60 shadow-lg shadow-black/30 backdrop-blur-xl">
                            <div class="flex items-center justify-between gap-3 border-b border-white/10 px-4 py-3">
                                <div class="flex items-center gap-2.5">
                                    <div class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-emerald-500/15 ring-1 ring-emerald-500/30">
                                        <Terminal class="h-4 w-4 text-emerald-400" />
                                    </div>
                                    <div>
                                        <h3 class="flex items-center gap-2 text-sm font-semibold text-white">
                                            Live Raw CLI
                                            <span v-if="preview.loading" class="h-1.5 w-1.5 animate-ping rounded-full bg-emerald-400"></span>
                                        </h3>
                                        <p class="text-xs text-slate-500">Script yang akan dieksekusi ke OLT</p>
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1.5 rounded-md border border-white/10 px-2.5 py-1.5 text-xs text-slate-300 transition-colors hover:bg-white/5 hover:text-white"
                                    title="Salin script"
                                    @click="copyScript"
                                >
                                    <Check v-if="copied" class="h-3.5 w-3.5 text-emerald-400" />
                                    <Copy v-else class="h-3.5 w-3.5" />
                                    {{ copied ? 'Tersalin' : 'Salin' }}
                                </button>
                            </div>
                            <pre class="max-h-[70vh] overflow-auto whitespace-pre-wrap break-words bg-slate-950/70 px-4 py-3 font-mono text-xs leading-relaxed text-emerald-300/90">{{ preview.script }}</pre>
                        </div>
                        <p class="mt-2 px-1 text-xs text-slate-500">
                            Preview diperbarui otomatis tiap form berubah. Eksekusi nyata dijalankan saat menekan tombol <span class="text-slate-300">Eksekusi ke OLT</span>.
                        </p>
                    </div>

                    <!-- Kolom kanan: form konfigurasi -->
                    <div class="order-1 space-y-5 xl:order-2">

                    <!-- Mode toggle: Sederhana vs Lanjutan -->
                    <div class="flex flex-col gap-3 rounded-lg border border-white/10 bg-slate-900/40 px-4 py-3 shadow-lg shadow-black/30 backdrop-blur-xl sm:flex-row sm:items-center sm:justify-between sm:px-6">
                        <div>
                            <h3 class="text-sm font-semibold text-white">Mode Registrasi</h3>
                            <p class="text-xs text-slate-500">Sederhana: template 1 service. Lanjutan: atur tcont/gemport/service per baris.</p>
                        </div>
                        <div class="inline-flex rounded-lg border border-white/10 bg-slate-950/40 p-1">
                            <button
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition-all"
                                :class="mode === 'simple' ? 'bg-cyan-500 text-white' : 'text-slate-300 hover:text-white'"
                                @click="mode = 'simple'"
                            >
                                <LayoutList class="h-4 w-4" /> Sederhana
                            </button>
                            <button
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition-all"
                                :class="mode === 'advanced' ? 'bg-cyan-500 text-white' : 'text-slate-300 hover:text-white'"
                                @click="mode = 'advanced'"
                            >
                                <SlidersHorizontal class="h-4 w-4" /> Lanjutan
                            </button>
                        </div>
                    </div>

                    <form v-if="mode === 'simple'" class="space-y-5" @submit.prevent="submit(canExecute)">

                    <!-- Section 1: Identitas ONU -->
                    <div class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
                        <div class="flex items-center gap-3 border-b border-white/10 px-4 py-4 sm:px-6">
                            <div class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-sky-500/15 ring-1 ring-cyan-500/30">
                                <User class="h-4 w-4 text-cyan-400" />
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-white">Identitas ONU</h3>
                                <p class="text-xs text-slate-500">Serial number, posisi port, dan data pelanggan</p>
                            </div>
                        </div>
                        <div class="grid gap-5 p-6 md:grid-cols-3">
                            <div class="md:col-span-3">
                                <InputLabel for="serial_number" value="Serial Number" />
                                <TextInput id="serial_number" v-model="form.serial_number" class="mt-1 block w-full font-mono" required />
                                <InputError class="mt-1.5" :message="form.errors.serial_number" />
                            </div>
                            <div>
                                <InputLabel for="slot" value="Slot" />
                                <TextInput id="slot" v-model="form.slot" type="number" class="mt-1 block w-full" required />
                                <InputError class="mt-1.5" :message="form.errors.slot" />
                            </div>
                            <div>
                                <InputLabel for="port" value="Port" />
                                <TextInput id="port" v-model="form.port" type="number" class="mt-1 block w-full" required />
                                <InputError class="mt-1.5" :message="form.errors.port" />
                            </div>
                            <div>
                                <InputLabel for="onu_id" value="ONU ID" />
                                <TextInput id="onu_id" v-model="form.onu_id" type="number" class="mt-1 block w-full" required />
                                <InputError class="mt-1.5" :message="form.errors.onu_id" />
                            </div>
                            <div class="md:col-span-3">
                                <InputLabel for="customer_name" value="Nama Pelanggan" />
                                <TextInput id="customer_name" v-model="form.customer_name" class="mt-1 block w-full" required />
                                <InputError class="mt-1.5" :message="form.errors.customer_name" />
                            </div>
                        </div>
                    </div>

                    <!-- Section 2: Konfigurasi GPON -->
                    <div class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
                        <div class="flex items-center gap-3 border-b border-white/10 px-4 py-4 sm:px-6">
                            <div class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-sky-500/15 ring-1 ring-cyan-500/30">
                                <Cpu class="h-4 w-4 text-cyan-400" />
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-white">Konfigurasi GPON</h3>
                                <p class="text-xs text-slate-500">ONU type, TCONT, VLAN, dan service name</p>
                            </div>
                        </div>
                        <div class="grid gap-5 p-6 md:grid-cols-3">
                            <div>
                                <InputLabel for="onu_type" value="ONU Type" />
                                <select
                                    id="onu_type"
                                    v-model="form.onu_type"
                                    class="mt-1 block w-full rounded-md border-white/10 shadow-sm focus:border-cyan-500 focus:ring-cyan-500"
                                    required
                                >
                                    <option v-for="profile in onuTypeProfiles" :key="profile.id" :value="profile.name">
                                        {{ profile.name }}
                                    </option>
                                </select>
                                <InputError class="mt-1.5" :message="form.errors.onu_type" />
                            </div>
                            <div>
                                <InputLabel for="tcont_profile" value="TCONT Profile" />
                                <select
                                    id="tcont_profile"
                                    v-model="form.tcont_profile"
                                    class="mt-1 block w-full rounded-md border-white/10 shadow-sm focus:border-cyan-500 focus:ring-cyan-500"
                                    required
                                >
                                    <option v-for="profile in tcontProfiles" :key="profile.id" :value="profile.name">
                                        {{ profile.name }}
                                    </option>
                                </select>
                                <InputError class="mt-1.5" :message="form.errors.tcont_profile" />
                            </div>
                            <div>
                                <InputLabel for="vlan" value="VLAN ID" />
                                <TextInput id="vlan" v-model="form.vlan" type="number" class="mt-1 block w-full" required />
                                <InputError class="mt-1.5" :message="form.errors.vlan" />
                            </div>
                            <div>
                                <InputLabel for="vlan_profile" value="VLAN Profile" />
                                <select
                                    id="vlan_profile"
                                    v-model="form.vlan_profile"
                                    class="mt-1 block w-full rounded-md border-white/10 shadow-sm focus:border-cyan-500 focus:ring-cyan-500"
                                >
                                    <option value="">Tanpa profile</option>
                                    <option v-for="profile in vlanProfiles" :key="profile.id" :value="profile.name">
                                        {{ profile.name }} · VLAN {{ profile.vlan }}
                                    </option>
                                </select>
                                <InputError class="mt-1.5" :message="form.errors.vlan_profile" />
                            </div>
                            <div>
                                <InputLabel for="service_name" value="Service Name" />
                                <TextInput id="service_name" v-model="form.service_name" class="mt-1 block w-full" required />
                                <InputError class="mt-1.5" :message="form.errors.service_name" />
                            </div>
                            <div>
                                <InputLabel value="Service Mapping Mode" />
                                <div class="mt-1 flex flex-wrap gap-2">
                                    <button
                                        v-for="opt in serviceModes"
                                        :key="opt.value"
                                        type="button"
                                        class="rounded-lg border px-4 py-2 text-sm font-medium transition-all duration-150 focus:outline-none focus:ring-2 focus:ring-cyan-400 focus:ring-offset-1"
                                        :class="form.service_mode === opt.value
                                            ? 'bg-cyan-500 text-white border-cyan-500'
                                            : 'bg-slate-900/40 backdrop-blur-xl border border-white/10 text-slate-200 hover:border-cyan-500/40'"
                                        @click="form.service_mode = opt.value"
                                    >
                                        {{ opt.label }}
                                    </button>
                                </div>
                                <p class="mt-1.5 text-xs text-slate-500">
                                    {{ form.service_mode === 'transparent'
                                        ? 'CLI: service ' + (form.service_name || 'ServiceName') + ' gemport 1 (tanpa cos/vlan).'
                                        : 'CLI: service ' + (form.service_name || 'ServiceName') + ' gemport 1 cos 0 vlan ' + (form.vlan || '—') + '.' }}
                                </p>
                                <InputError class="mt-1.5" :message="form.errors.service_mode" />
                            </div>
                        </div>
                    </div>

                    <!-- Section 3: WAN Mode -->
                    <div class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
                        <div class="flex items-center gap-3 border-b border-white/10 px-4 py-4 sm:px-6">
                            <div class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-sky-500/15 ring-1 ring-cyan-500/30">
                                <Globe class="h-4 w-4 text-cyan-400" />
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-white">WAN Mode</h3>
                                <p class="text-xs text-slate-500">Metode koneksi internet pelanggan</p>
                            </div>
                        </div>
                        <div class="p-6 space-y-5">
                            <!-- WAN mode selector buttons -->
                            <div>
                                <InputLabel value="Mode" />
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <button
                                        v-for="mode in ['pppoe', 'dhcp', 'static', 'bridge']"
                                        :key="mode"
                                        type="button"
                                        class="rounded-lg border px-5 py-2 text-sm font-medium transition-all duration-150 focus:outline-none focus:ring-2 focus:ring-cyan-400 focus:ring-offset-1"
                                        :class="form.wan_mode === mode
                                            ? 'bg-cyan-500 text-white border-cyan-500'
                                            : 'bg-slate-900/40 backdrop-blur-xl border border-white/10 text-slate-200 hover:border-cyan-500/40'"
                                        @click="form.wan_mode = mode"
                                    >
                                        {{ mode.toUpperCase() }}
                                    </button>
                                </div>
                                <InputError class="mt-1.5" :message="form.errors.wan_mode" />
                            </div>

                            <!-- PPPoE fields -->
                            <div v-if="form.wan_mode === 'pppoe'" class="grid gap-5 md:grid-cols-2">
                                <div>
                                    <InputLabel for="pppoe_username" value="PPPoE Username" />
                                    <TextInput id="pppoe_username" v-model="form.pppoe_username" class="mt-1 block w-full" autocomplete="off" data-1p-ignore data-lpignore="true" />
                                    <InputError class="mt-1.5" :message="form.errors.pppoe_username" />
                                </div>
                                <div>
                                    <InputLabel for="pppoe_password" value="PPPoE Password" />
                                    <TextInput id="pppoe_password" v-model="form.pppoe_password" class="mt-1 block w-full" type="password" autocomplete="new-password" data-1p-ignore data-lpignore="true" />
                                    <InputError class="mt-1.5" :message="form.errors.pppoe_password" />
                                </div>
                            </div>

                            <!-- DHCP: no extra fields -->
                            <div v-if="form.wan_mode === 'dhcp'" class="rounded-lg border border-white/10 bg-sky-500/15 px-4 py-3 text-sm text-cyan-300">
                                DHCP mode — IP otomatis dari server, tidak ada field tambahan.
                            </div>

                            <!-- Bridge: L2 transparan, tanpa WAN di OLT -->
                            <div v-if="form.wan_mode === 'bridge'" class="rounded-lg border border-white/10 bg-sky-500/15 px-4 py-3 text-sm text-cyan-300">
                                Bridge mode — ONU jadi jembatan L2 murni (VLAN transparan, gunakan <span class="font-semibold">VLAN ID</span> di atas, mis. 100). Tidak ada <code>wan-ip</code>/PPPoE/TR069 di OLT; router pelanggan yang ber-PPPoE. Cocok untuk OLT gaya bridge (mis. Bulumanis Lor). VLAN Profile diabaikan pada mode ini.
                            </div>

                            <!-- Static fields -->
                            <div v-if="form.wan_mode === 'static'" class="grid gap-5 md:grid-cols-3">
                                <div>
                                    <InputLabel for="ip_profile" value="IP Profile" />
                                    <select
                                        id="ip_profile"
                                        v-model="form.ip_profile"
                                        class="mt-1 block w-full rounded-md border-white/10 shadow-sm focus:border-cyan-500 focus:ring-cyan-500"
                                        required
                                    >
                                        <option v-for="profile in ipProfiles" :key="profile.id" :value="profile.name">
                                            {{ profile.name }}
                                        </option>
                                    </select>
                                    <InputError class="mt-1.5" :message="form.errors.ip_profile" />
                                </div>
                                <div>
                                    <InputLabel for="static_ip" value="Static IP" />
                                    <TextInput id="static_ip" v-model="form.static_ip" class="mt-1 block w-full font-mono" />
                                    <InputError class="mt-1.5" :message="form.errors.static_ip" />
                                </div>
                                <div>
                                    <InputLabel for="static_netmask" value="Subnet Prefix (/)" />
                                    <TextInput id="static_netmask" v-model="form.static_netmask" type="number" min="1" max="32" class="mt-1 block w-full" />
                                    <InputError class="mt-1.5" :message="form.errors.static_netmask" />
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Section 4: Fitur Tambahan -->
                    <div class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
                        <div class="flex items-center gap-3 border-b border-white/10 px-4 py-4 sm:px-6">
                            <div class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-sky-500/15 ring-1 ring-cyan-500/30">
                                <Settings class="h-4 w-4 text-cyan-400" />
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-white">Fitur Tambahan</h3>
                                <p class="text-xs text-slate-500">TR069 remote management dan Remote ONT (opsional)</p>
                            </div>
                        </div>
                        <div class="grid gap-5 p-6 md:grid-cols-2">
                            <!-- TR069 -->
                            <div class="rounded-lg border border-cyan-500/30 bg-slate-900/40 backdrop-blur-xl p-4">
                                <label class="inline-flex cursor-pointer items-center gap-2.5">
                                    <input v-model="form.tr069_enabled" type="checkbox" class="h-4 w-4 rounded border-white/10 text-cyan-400 shadow-sm focus:ring-cyan-500" />
                                    <span class="text-sm font-semibold text-slate-200">TR069</span>
                                </label>
                                <div v-if="form.tr069_enabled" class="mt-4 space-y-4">
                                    <div>
                                        <InputLabel for="acs_url" value="ACS URL" />
                                        <TextInput id="acs_url" v-model="form.acs_url" class="mt-1 block w-full" />
                                        <InputError class="mt-1.5" :message="form.errors.acs_url" />
                                    </div>
                                    <div class="grid gap-4 sm:grid-cols-2">
                                        <div>
                                            <InputLabel for="acs_username" value="Username" />
                                            <TextInput id="acs_username" v-model="form.acs_username" class="mt-1 block w-full" />
                                            <InputError class="mt-1.5" :message="form.errors.acs_username" />
                                        </div>
                                        <div>
                                            <InputLabel for="acs_password" value="Password" />
                                            <TextInput id="acs_password" v-model="form.acs_password" type="password" class="mt-1 block w-full" />
                                            <InputError class="mt-1.5" :message="form.errors.acs_password" />
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Remote ONT -->
                            <div class="rounded-lg border border-cyan-500/30 bg-slate-900/40 backdrop-blur-xl p-4">
                                <label class="inline-flex cursor-pointer items-center gap-2.5">
                                    <input v-model="form.remote_ont_enabled" type="checkbox" class="h-4 w-4 rounded border-white/10 text-cyan-400 shadow-sm focus:ring-cyan-500" />
                                    <span class="text-sm font-semibold text-slate-200">Remote ONT</span>
                                </label>
                                <div v-if="form.remote_ont_enabled" class="mt-4 grid gap-4 sm:grid-cols-3">
                                    <div>
                                        <InputLabel for="remote_ont_id" value="ID" />
                                        <TextInput id="remote_ont_id" v-model="form.remote_ont_id" type="number" min="1" max="4095" class="mt-1 block w-full" />
                                        <InputError class="mt-1.5" :message="form.errors.remote_ont_id" />
                                    </div>
                                    <div>
                                        <InputLabel for="remote_ont_mode" value="Mode" />
                                        <select id="remote_ont_mode" v-model="form.remote_ont_mode" class="mt-1 block w-full rounded-md border-white/10 shadow-sm focus:border-cyan-500 focus:ring-cyan-500">
                                            <option value="forward">Forward</option>
                                            <option value="discard">Discard</option>
                                        </select>
                                        <InputError class="mt-1.5" :message="form.errors.remote_ont_mode" />
                                    </div>
                                    <div>
                                        <InputLabel for="remote_ont_protocol" value="Protocol" />
                                        <select id="remote_ont_protocol" v-model="form.remote_ont_protocol" class="mt-1 block w-full rounded-md border-white/10 shadow-sm focus:border-cyan-500 focus:ring-cyan-500">
                                            <option value="web">Web</option>
                                            <option value="telnet">Telnet</option>
                                            <option value="ssh">SSH</option>
                                            <option value="ftp">FTP</option>
                                            <option value="tftp">TFTP</option>
                                            <option value="snmp">SNMP</option>
                                        </select>
                                        <InputError class="mt-1.5" :message="form.errors.remote_ont_protocol" />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <input v-model="form.oid_index" type="hidden" />

                    <!-- Submit bar -->
                    <div class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl px-4 py-4 sm:px-6">
                        <p v-if="!canExecute" class="mb-3 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2.5 text-xs text-amber-200">
                            Driver OLT ini tidak mendukung eksekusi CLI otomatis — script hanya bisa di-generate & disimpan ke audit log.
                        </p>
                        <div class="grid gap-2 sm:flex sm:items-center sm:justify-end sm:gap-3">
                            <Link :href="route('smartolt.unconfigured-all', { olt_id: olt.id })" class="sm:mr-auto">
                                <SecondaryButton type="button" class="w-full sm:w-auto">Batal</SecondaryButton>
                            </Link>
                            <SecondaryButton type="button" :disabled="form.processing" class="w-full sm:w-auto" @click="submit(false)">
                                <LayoutList class="mr-2 h-4 w-4" />
                                Generate script saja
                            </SecondaryButton>
                            <PrimaryButton v-if="canExecute" type="button" :disabled="form.processing" class="w-full sm:w-auto" @click="submit(true)">
                                <Zap class="mr-2 h-4 w-4" />
                                {{ form.processing ? 'Mengeksekusi…' : 'Eksekusi ke OLT' }}
                            </PrimaryButton>
                        </div>
                    </div>
                    </form>

                    <!-- Mode Lanjutan: editor granular -->
                    <div v-else class="space-y-5">
                        <div v-if="advErrorList.length" class="rounded-lg border border-red-500/30 bg-red-500/15 px-4 py-3 text-sm text-red-300">
                            <p class="font-semibold">Periksa kembali input berikut:</p>
                            <ul class="mt-1 list-inside list-disc space-y-0.5">
                                <li v-for="(msg, i) in advErrorList" :key="i">{{ msg }}</li>
                            </ul>
                        </div>

                        <!-- Identitas ONU (header registrasi) -->
                        <div class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
                            <div class="flex items-center gap-3 border-b border-white/10 px-4 py-4 sm:px-6">
                                <div class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-sky-500/15 ring-1 ring-cyan-500/30">
                                    <User class="h-4 w-4 text-cyan-400" />
                                </div>
                                <div>
                                    <h3 class="text-sm font-semibold text-white">Identitas ONU</h3>
                                    <p class="text-xs text-slate-500">SN & posisi port — nama pelanggan diisi di kolom <span class="text-slate-300">Name</span> bawah.</p>
                                </div>
                            </div>
                            <div class="grid gap-5 p-6 md:grid-cols-4">
                                <div class="md:col-span-4">
                                    <InputLabel for="adv_sn" value="Serial Number" />
                                    <TextInput id="adv_sn" v-model="advForm.serial_number" class="mt-1 block w-full font-mono" required />
                                    <InputError class="mt-1.5" :message="advForm.errors.serial_number" />
                                </div>
                                <div>
                                    <InputLabel for="adv_slot" value="Slot" />
                                    <TextInput id="adv_slot" v-model="advForm.slot" type="number" class="mt-1 block w-full" required />
                                    <InputError class="mt-1.5" :message="advForm.errors.slot" />
                                </div>
                                <div>
                                    <InputLabel for="adv_port" value="Port" />
                                    <TextInput id="adv_port" v-model="advForm.port" type="number" class="mt-1 block w-full" required />
                                    <InputError class="mt-1.5" :message="advForm.errors.port" />
                                </div>
                                <div>
                                    <InputLabel for="adv_onu_id" value="ONU ID" />
                                    <TextInput id="adv_onu_id" v-model="advForm.onu_id" type="number" class="mt-1 block w-full" required />
                                    <InputError class="mt-1.5" :message="advForm.errors.onu_id" />
                                </div>
                                <div>
                                    <InputLabel for="adv_onu_type" value="ONU Type" />
                                    <select id="adv_onu_type" v-model="advForm.onu_type" class="mt-1 block w-full rounded-md border-white/10 shadow-sm focus:border-cyan-500 focus:ring-cyan-500" required>
                                        <option v-for="profile in onuTypeProfiles" :key="profile.id" :value="profile.name">{{ profile.name }}</option>
                                    </select>
                                    <InputError class="mt-1.5" :message="advForm.errors.onu_type" />
                                </div>
                            </div>
                        </div>

                        <!-- Editor granular -->
                        <OnuConfigEditor :config="advForm.config" :profiles="profiles" :errors="advForm.errors" />

                        <input v-model="advForm.oid_index" type="hidden" />

                        <!-- Submit bar (Lanjutan) -->
                        <div class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl px-4 py-4 sm:px-6">
                            <p v-if="!canExecute" class="mb-3 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2.5 text-xs text-amber-200">
                                Driver OLT ini tidak mendukung eksekusi CLI otomatis — script hanya bisa di-generate &amp; disimpan ke audit log.
                            </p>
                            <div class="grid gap-2 sm:flex sm:items-center sm:justify-end sm:gap-3">
                                <Link :href="route('smartolt.unconfigured-all', { olt_id: olt.id })" class="sm:mr-auto">
                                    <SecondaryButton type="button" class="w-full sm:w-auto">Batal</SecondaryButton>
                                </Link>
                                <SecondaryButton type="button" :disabled="advForm.processing" class="w-full sm:w-auto" @click="submitAdvanced(false)">
                                    <LayoutList class="mr-2 h-4 w-4" />
                                    Generate script saja
                                </SecondaryButton>
                                <PrimaryButton v-if="canExecute" type="button" :disabled="advForm.processing" class="w-full sm:w-auto" @click="submitAdvanced(true)">
                                    <Zap class="mr-2 h-4 w-4" />
                                    {{ advForm.processing ? 'Mengeksekusi…' : 'Eksekusi ke OLT' }}
                                </PrimaryButton>
                            </div>
                        </div>
                    </div>

                    </div>
                </div>
            </div>
        </div>

        <ConfirmModal :state="confirmState" @confirm="handleConfirm" @cancel="handleCancel" />
    </AuthenticatedLayout>
</template>
