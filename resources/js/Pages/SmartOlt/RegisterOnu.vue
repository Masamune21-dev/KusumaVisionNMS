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
import { useI18n } from 'vue-i18n';
import { Check, Copy, Cpu, Globe, LayoutList, RefreshCw, Settings, SlidersHorizontal, Terminal, User, Zap } from '@lucide/vue';
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue';

const { t } = useI18n({ useScope: 'global' });

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
    c600_defaults: {
        type: Object,
        default: () => ({}),
    },
});

const clone = (value) => JSON.parse(JSON.stringify(value ?? null));

// C600 (Titan) = form provisioning tersendiri (Model B / SmartOLT TR069): dua layanan
// internet+manajemen, mgmt-ip in-band, ACS. Menggantikan tab simple/advanced C300.
const isC600 = computed(() => props.olt.capabilities?.is_c600 === true);
const c600Form = useForm({ ...props.c600_defaults });

// Auto mgmt-IP: baca IP terpakai dari OLT (hindari bentrok SmartOLT) → isi mgmt-ip bebas +
// mask/gateway/vlan/priority/host. Scan pertama ~18 dtk (di-cache 10 mnt server-side).
const mgmtAuto = reactive({ loading: false, error: null, info: null });
const autoMgmtIp = async (fresh = false) => {
    mgmtAuto.loading = true;
    mgmtAuto.error = null;
    try {
        const { data } = await window.axios.get(route('smartolt.register.mgmt-pool', props.olt.id), {
            params: { fresh: fresh ? 1 : 0 },
        });
        c600Form.mgmt_ip = data.mgmt_ip;
        c600Form.mgmt_mask = data.mask;
        c600Form.mgmt_gateway = data.gateway;
        if (data.vlan) c600Form.mgmt_vlan = data.vlan;
        if (data.priority !== null && data.priority !== undefined) c600Form.mgmt_priority = data.priority;
        if (data.host !== null && data.host !== undefined) c600Form.mgmt_host = data.host;
        mgmtAuto.info = t('registeronu.c600_pool_info', { free: data.free_count, used: data.used_count });
    } catch (e) {
        mgmtAuto.error = e?.response?.data?.error || t('registeronu.c600_pool_failed');
    } finally {
        mgmtAuto.loading = false;
    }
};

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
// Nama profil untuk memastikan nilai form yang tak ada di katalog (mis. model hasil discovery
// yang belum tersinkron) tetap muncul sebagai opsi terpilih di dropdown C600.
const onuTypeNames = computed(() => onuTypeProfiles.value.map((p) => p.name));
const tcontNames = computed(() => tcontProfiles.value.map((p) => p.name));
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
const preview = reactive({ script: t('registeronu.fill_form_comment'), loading: false });
let debounceTimer = null;

const runPreview = () => {
    preview.loading = true;
    const isAdvanced = !isC600.value && mode.value === 'advanced';
    const url = isAdvanced
        ? route('smartolt.register.advanced.preview', props.olt.id)
        : route('smartolt.register.preview', props.olt.id);
    const payload = isC600.value ? { ...c600Form.data() } : (isAdvanced ? { ...advForm.data() } : { ...form.data() });

    window.axios
        .post(url, payload)
        .then(({ data }) => {
            preview.script = data.script && data.script.trim() !== '' ? data.script : t('registeronu.empty_script_comment');
        })
        .catch(() => {
            preview.script = t('registeronu.preview_failed_comment');
        })
        .finally(() => {
            preview.loading = false;
        });
};

const schedulePreview = () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(runPreview, 400);
};

const activePayload = computed(() => (isC600.value
    ? JSON.stringify(c600Form.data())
    : (mode.value === 'advanced' ? JSON.stringify(advForm.data()) : JSON.stringify(form.data()))));

watch(activePayload, schedulePreview);
watch(mode, () => { preview.script = t('registeronu.loading_comment'); runPreview(); });
onMounted(runPreview);
// C600: auto-suggest IP mgmt bebas saat form dibuka (pakai cache; hanya scan pertama yang lama).
onMounted(() => { if (isC600.value && !c600Form.mgmt_ip) autoMgmtIp(false); });
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
            title: t('registeronu.confirm_exec_title'),
            message: t('registeronu.confirm_exec_msg', { sn: form.serial_number || '', olt: props.olt.name }),
            confirmLabel: t('registeronu.confirm_exec_label'),
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
            title: t('registeronu.confirm_exec_title'),
            message: t('registeronu.confirm_exec_msg_adv', { sn: advForm.serial_number || '', olt: props.olt.name }),
            confirmLabel: t('registeronu.confirm_exec_label'),
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

const submitC600 = async (execute) => {
    if (execute) {
        const ok = await confirm({
            title: t('registeronu.confirm_exec_title'),
            message: t('registeronu.confirm_exec_msg', { sn: c600Form.serial_number || '', olt: props.olt.name }),
            confirmLabel: t('registeronu.confirm_exec_label'),
        });

        if (!ok) {
            return;
        }
    }

    c600Form
        .transform((data) => ({ ...data, execute }))
        .post(route('smartolt.register.store', props.olt.id), {
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
                <p class="mt-1 text-sm text-slate-500">{{ $t('registeronu.subtitle', { olt: olt.name }) }}</p>
            </div>
        </template>

        <div class="min-h-[60vh] pt-5 pb-16 sm:pt-8">
            <div class="w-full px-4 sm:px-6 lg:px-8">
                <div class="grid gap-5 xl:grid-cols-[minmax(0,480px)_1fr]">

                    <!-- Kolom kiri: Live Raw CLI -->
                    <div class="order-2 xl:order-1 xl:sticky xl:top-24 xl:self-start">
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
                                        <p class="text-xs text-slate-500">{{ $t('registeronu.preview_hint') }}</p>
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1.5 rounded-md border border-white/10 px-2.5 py-1.5 text-xs text-slate-300 transition-colors hover:bg-white/5 hover:text-white"
                                    :title="$t('registeronu.copy_title')"
                                    @click="copyScript"
                                >
                                    <Check v-if="copied" class="h-3.5 w-3.5 text-emerald-400" />
                                    <Copy v-else class="h-3.5 w-3.5" />
                                    {{ copied ? $t('configonu.copied') : $t('registeronu.copy') }}
                                </button>
                            </div>
                            <pre class="max-h-[70vh] overflow-auto whitespace-pre-wrap break-words bg-slate-950/70 px-4 py-3 font-mono text-xs leading-relaxed text-emerald-300/90">{{ preview.script }}</pre>
                        </div>
                        <p class="mt-2 px-1 text-xs text-slate-500" v-html="$t('registeronu.preview_note')"></p>
                    </div>

                    <!-- Kolom kanan: form konfigurasi -->
                    <div class="order-1 space-y-5 xl:order-2">

                    <!-- ============ Form C600 (Model B / SmartOLT TR069) ============ -->
                    <form v-if="isC600" class="space-y-5" @submit.prevent="submitC600(false)">
                        <div class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
                            <div class="flex items-center gap-3 border-b border-white/10 px-4 py-4 sm:px-6">
                                <div class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-sky-500/15 ring-1 ring-cyan-500/30">
                                    <User class="h-4 w-4 text-cyan-400" />
                                </div>
                                <div>
                                    <h3 class="text-sm font-semibold text-white">{{ $t('registeronu.c600_identity') }}</h3>
                                    <p class="text-xs text-slate-500">{{ $t('registeronu.c600_identity_hint') }}</p>
                                </div>
                            </div>
                            <div class="grid grid-cols-1 gap-4 p-4 sm:grid-cols-2 sm:p-6">
                                <div class="sm:col-span-2">
                                    <InputLabel :value="$t('registeronu.serial')" />
                                    <TextInput v-model="c600Form.serial_number" class="mt-1 w-full font-mono" />
                                    <InputError class="mt-1.5" :message="c600Form.errors.serial_number" />
                                </div>
                                <div>
                                    <InputLabel value="Slot" />
                                    <TextInput v-model.number="c600Form.slot" type="number" class="mt-1 w-full" />
                                    <InputError class="mt-1.5" :message="c600Form.errors.slot" />
                                </div>
                                <div>
                                    <InputLabel value="Port" />
                                    <TextInput v-model.number="c600Form.port" type="number" class="mt-1 w-full" />
                                    <InputError class="mt-1.5" :message="c600Form.errors.port" />
                                </div>
                                <div>
                                    <InputLabel value="ONU ID" />
                                    <TextInput v-model.number="c600Form.onu_id" type="number" class="mt-1 w-full" />
                                    <InputError class="mt-1.5" :message="c600Form.errors.onu_id" />
                                </div>
                                <div>
                                    <InputLabel :value="$t('registeronu.onu_type')" />
                                    <select v-model="c600Form.onu_type" class="mt-1 block w-full rounded-md border-white/10 bg-slate-950/40 font-mono text-sm shadow-sm focus:border-cyan-500 focus:ring-cyan-500">
                                        <option value="" disabled>{{ $t('registeronu.c600_select') }}</option>
                                        <option v-if="c600Form.onu_type && !onuTypeNames.includes(c600Form.onu_type)" :value="c600Form.onu_type">{{ c600Form.onu_type }}</option>
                                        <option v-for="p in onuTypeProfiles" :key="p.id" :value="p.name">{{ p.name }}</option>
                                    </select>
                                    <InputError class="mt-1.5" :message="c600Form.errors.onu_type" />
                                </div>
                                <div>
                                    <InputLabel :value="$t('registeronu.customer_name')" />
                                    <TextInput v-model="c600Form.customer_name" class="mt-1 w-full" />
                                    <InputError class="mt-1.5" :message="c600Form.errors.customer_name" />
                                </div>
                                <div>
                                    <InputLabel :value="$t('registeronu.c600_zone')" />
                                    <TextInput v-model="c600Form.zone" class="mt-1 w-full" placeholder="ARROYO AL CABO" />
                                    <InputError class="mt-1.5" :message="c600Form.errors.zone" />
                                </div>
                            </div>
                        </div>

                        <div class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
                            <div class="flex items-center gap-3 border-b border-white/10 px-4 py-4 sm:px-6">
                                <div class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-sky-500/15 ring-1 ring-cyan-500/30">
                                    <Globe class="h-4 w-4 text-cyan-400" />
                                </div>
                                <div>
                                    <h3 class="text-sm font-semibold text-white">{{ $t('registeronu.c600_service') }}</h3>
                                    <p class="text-xs text-slate-500">{{ $t('registeronu.c600_service_hint') }}</p>
                                </div>
                            </div>
                            <div class="grid grid-cols-1 gap-4 p-4 sm:grid-cols-2 sm:p-6">
                                <div>
                                    <InputLabel :value="$t('registeronu.c600_internet_vlan')" />
                                    <TextInput v-model.number="c600Form.internet_vlan" type="number" class="mt-1 w-full" />
                                    <InputError class="mt-1.5" :message="c600Form.errors.internet_vlan" />
                                </div>
                                <div>
                                    <InputLabel :value="$t('registeronu.c600_internet_tcont')" />
                                    <select v-model="c600Form.internet_tcont_profile" class="mt-1 block w-full rounded-md border-white/10 bg-slate-950/40 font-mono text-sm shadow-sm focus:border-cyan-500 focus:ring-cyan-500">
                                        <option value="" disabled>{{ $t('registeronu.c600_select') }}</option>
                                        <option v-if="c600Form.internet_tcont_profile && !tcontNames.includes(c600Form.internet_tcont_profile)" :value="c600Form.internet_tcont_profile">{{ c600Form.internet_tcont_profile }}</option>
                                        <option v-for="p in tcontProfiles" :key="p.id" :value="p.name">{{ p.name }}</option>
                                    </select>
                                    <InputError class="mt-1.5" :message="c600Form.errors.internet_tcont_profile" />
                                </div>
                                <div>
                                    <InputLabel :value="$t('registeronu.c600_mgmt_vlan')" />
                                    <TextInput v-model.number="c600Form.mgmt_vlan" type="number" class="mt-1 w-full" />
                                    <InputError class="mt-1.5" :message="c600Form.errors.mgmt_vlan" />
                                </div>
                                <div>
                                    <InputLabel :value="$t('registeronu.c600_mgmt_tcont')" />
                                    <select v-model="c600Form.mgmt_tcont_profile" class="mt-1 block w-full rounded-md border-white/10 bg-slate-950/40 font-mono text-sm shadow-sm focus:border-cyan-500 focus:ring-cyan-500">
                                        <option value="" disabled>{{ $t('registeronu.c600_select') }}</option>
                                        <option v-if="c600Form.mgmt_tcont_profile && !tcontNames.includes(c600Form.mgmt_tcont_profile)" :value="c600Form.mgmt_tcont_profile">{{ c600Form.mgmt_tcont_profile }}</option>
                                        <option v-for="p in tcontProfiles" :key="p.id" :value="p.name">{{ p.name }}</option>
                                    </select>
                                    <InputError class="mt-1.5" :message="c600Form.errors.mgmt_tcont_profile" />
                                </div>
                                <div class="sm:col-span-2">
                                    <InputLabel :value="$t('registeronu.c600_egress')" />
                                    <TextInput v-model="c600Form.egress_traffic_policy" class="mt-1 w-full font-mono" placeholder="SMARTOLT-10M-DOWN" />
                                    <InputError class="mt-1.5" :message="c600Form.errors.egress_traffic_policy" />
                                    <p class="mt-1 text-xs text-slate-500">{{ $t('registeronu.c600_egress_hint') }}</p>
                                </div>
                            </div>
                        </div>

                        <div class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
                            <div class="flex flex-col gap-3 border-b border-white/10 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-sky-500/15 ring-1 ring-cyan-500/30">
                                        <Settings class="h-4 w-4 text-cyan-400" />
                                    </div>
                                    <div>
                                        <h3 class="text-sm font-semibold text-white">{{ $t('registeronu.c600_mgmt') }}</h3>
                                        <p class="text-xs text-slate-500">{{ $t('registeronu.c600_mgmt_hint') }}</p>
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1.5 rounded-md bg-cyan-500/15 px-3 py-1.5 text-xs font-medium text-cyan-300 ring-1 ring-cyan-500/30 transition-colors hover:bg-cyan-500/25 disabled:opacity-50"
                                    :disabled="mgmtAuto.loading"
                                    @click="autoMgmtIp(true)"
                                >
                                    <RefreshCw class="h-3.5 w-3.5" :class="{ 'animate-spin': mgmtAuto.loading }" />
                                    {{ mgmtAuto.loading ? $t('registeronu.c600_scanning') : $t('registeronu.c600_auto_ip') }}
                                </button>
                            </div>
                            <div v-if="mgmtAuto.info || mgmtAuto.error" class="px-4 pt-3 sm:px-6">
                                <p v-if="mgmtAuto.error" class="text-xs text-red-300">{{ mgmtAuto.error }}</p>
                                <p v-else class="text-xs text-emerald-300/80">{{ mgmtAuto.info }}</p>
                            </div>
                            <div class="grid grid-cols-1 gap-4 p-4 sm:grid-cols-2 sm:p-6">
                                <div>
                                    <InputLabel :value="$t('registeronu.c600_mgmt_ip')" />
                                    <TextInput v-model="c600Form.mgmt_ip" class="mt-1 w-full font-mono" placeholder="10.64.68.214" />
                                    <InputError class="mt-1.5" :message="c600Form.errors.mgmt_ip" />
                                </div>
                                <div>
                                    <InputLabel :value="$t('registeronu.c600_mgmt_mask')" />
                                    <TextInput v-model="c600Form.mgmt_mask" class="mt-1 w-full font-mono" placeholder="255.255.240.0" />
                                    <InputError class="mt-1.5" :message="c600Form.errors.mgmt_mask" />
                                </div>
                                <div>
                                    <InputLabel :value="$t('registeronu.c600_mgmt_gateway')" />
                                    <TextInput v-model="c600Form.mgmt_gateway" class="mt-1 w-full font-mono" placeholder="10.64.64.1" />
                                    <InputError class="mt-1.5" :message="c600Form.errors.mgmt_gateway" />
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <InputLabel value="Priority" />
                                        <TextInput v-model.number="c600Form.mgmt_priority" type="number" class="mt-1 w-full" />
                                    </div>
                                    <div>
                                        <InputLabel value="Host" />
                                        <TextInput v-model.number="c600Form.mgmt_host" type="number" class="mt-1 w-full" />
                                    </div>
                                </div>
                                <div class="sm:col-span-2">
                                    <InputLabel value="ACS URL" />
                                    <TextInput v-model="c600Form.acs_url" class="mt-1 w-full font-mono" placeholder="http://10.69.69.1:14501" />
                                    <InputError class="mt-1.5" :message="c600Form.errors.acs_url" />
                                </div>
                                <div>
                                    <InputLabel value="ACS Username" />
                                    <TextInput v-model="c600Form.acs_username" class="mt-1 w-full font-mono" />
                                    <InputError class="mt-1.5" :message="c600Form.errors.acs_username" />
                                </div>
                                <div>
                                    <InputLabel value="ACS Password" />
                                    <TextInput v-model="c600Form.acs_password" type="password" class="mt-1 w-full font-mono" />
                                    <InputError class="mt-1.5" :message="c600Form.errors.acs_password" />
                                </div>
                                <label class="flex items-center gap-2 sm:col-span-2 text-sm text-slate-300">
                                    <input v-model="c600Form.remote_ont_enabled" type="checkbox" class="kv-checkbox" />
                                    {{ $t('registeronu.c600_remote_ont') }}
                                </label>
                            </div>
                        </div>

                        <div class="flex flex-col gap-3 sm:flex-row sm:justify-end">
                            <SecondaryButton type="submit" :disabled="c600Form.processing">
                                <LayoutList class="mr-2 h-4 w-4" />
                                {{ $t('registeronu.generate') }}
                            </SecondaryButton>
                            <PrimaryButton type="button" :disabled="c600Form.processing || !canExecute" @click="submitC600(true)">
                                <Zap class="mr-2 h-4 w-4" />
                                {{ c600Form.processing ? $t('registeronu.executing') : $t('registeronu.execute_to_olt') }}
                            </PrimaryButton>
                        </div>
                    </form>

                    <!-- Mode toggle: Sederhana vs Lanjutan (C300/C320 saja) -->
                    <div v-if="!isC600" class="flex flex-col gap-3 rounded-lg border border-white/10 bg-slate-900/40 px-4 py-3 shadow-lg shadow-black/30 backdrop-blur-xl sm:flex-row sm:items-center sm:justify-between sm:px-6">
                        <div>
                            <h3 class="text-sm font-semibold text-white">{{ $t('registeronu.mode_title') }}</h3>
                            <p class="text-xs text-slate-500">{{ $t('registeronu.mode_hint') }}</p>
                        </div>
                        <div class="inline-flex rounded-lg border border-white/10 bg-slate-950/40 p-1">
                            <button
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition-all"
                                :class="mode === 'simple' ? 'bg-cyan-500 text-white' : 'text-slate-300 hover:text-white'"
                                @click="mode = 'simple'"
                            >
                                <LayoutList class="h-4 w-4" /> {{ $t('registeronu.mode_simple') }}
                            </button>
                            <button
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition-all"
                                :class="mode === 'advanced' ? 'bg-cyan-500 text-white' : 'text-slate-300 hover:text-white'"
                                @click="mode = 'advanced'"
                            >
                                <SlidersHorizontal class="h-4 w-4" /> {{ $t('registeronu.mode_advanced') }}
                            </button>
                        </div>
                    </div>

                    <form v-if="!isC600 && mode === 'simple'" class="space-y-5" @submit.prevent="submit(canExecute)">

                    <!-- Section 1: Identitas ONU -->
                    <div class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
                        <div class="flex items-center gap-3 border-b border-white/10 px-4 py-4 sm:px-6">
                            <div class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-sky-500/15 ring-1 ring-cyan-500/30">
                                <User class="h-4 w-4 text-cyan-400" />
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-white">{{ $t('registeronu.identity') }}</h3>
                                <p class="text-xs text-slate-500">{{ $t('registeronu.identity_hint') }}</p>
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
                                <InputLabel for="customer_name" :value="$t('registeronu.customer_name')" />
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
                                <h3 class="text-sm font-semibold text-white">{{ $t('registeronu.gpon_config') }}</h3>
                                <p class="text-xs text-slate-500">{{ $t('registeronu.gpon_hint') }}</p>
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
                                    <option value="">{{ $t('registeronu.no_profile') }}</option>
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
                                        ? $t('registeronu.svc_hint_transparent', { name: form.service_name || 'ServiceName' })
                                        : $t('registeronu.svc_hint_vlanpri', { name: form.service_name || 'ServiceName', vlan: form.vlan || '—' }) }}
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
                                <p class="text-xs text-slate-500">{{ $t('registeronu.wan_hint') }}</p>
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
                                {{ $t('registeronu.dhcp_note') }}
                            </div>

                            <!-- Bridge: L2 transparan, tanpa WAN di OLT -->
                            <div v-if="form.wan_mode === 'bridge'" class="rounded-lg border border-white/10 bg-sky-500/15 px-4 py-3 text-sm text-cyan-300" v-html="$t('registeronu.bridge_note')"></div>

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
                                <h3 class="text-sm font-semibold text-white">{{ $t('registeronu.extra_features') }}</h3>
                                <p class="text-xs text-slate-500">{{ $t('registeronu.extra_hint') }}</p>
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
                            {{ $t('registeronu.no_cli_note') }}
                        </p>
                        <div class="grid gap-2 sm:flex sm:items-center sm:justify-end sm:gap-3">
                            <Link :href="route('smartolt.unconfigured-all', { olt_id: olt.id })" class="sm:mr-auto">
                                <SecondaryButton type="button" class="w-full sm:w-auto">{{ $t('common.cancel') }}</SecondaryButton>
                            </Link>
                            <SecondaryButton type="button" :disabled="form.processing" class="w-full sm:w-auto" @click="submit(false)">
                                <LayoutList class="mr-2 h-4 w-4" />
                                {{ $t('registeronu.generate_only') }}
                            </SecondaryButton>
                            <PrimaryButton v-if="canExecute" type="button" :disabled="form.processing" class="w-full sm:w-auto" @click="submit(true)">
                                <Zap class="mr-2 h-4 w-4" />
                                {{ form.processing ? $t('registeronu.executing') : $t('registeronu.execute_to_olt') }}
                            </PrimaryButton>
                        </div>
                    </div>
                    </form>

                    <!-- Mode Lanjutan: editor granular -->
                    <div v-if="!isC600 && mode === 'advanced'" class="space-y-5">
                        <div v-if="advErrorList.length" class="rounded-lg border border-red-500/30 bg-red-500/15 px-4 py-3 text-sm text-red-300">
                            <p class="font-semibold">{{ $t('configonu.check_input') }}</p>
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
                                    <h3 class="text-sm font-semibold text-white">{{ $t('registeronu.identity') }}</h3>
                                    <p class="text-xs text-slate-500" v-html="$t('registeronu.adv_identity_hint')"></p>
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
                                {{ $t('registeronu.no_cli_note') }}
                            </p>
                            <div class="grid gap-2 sm:flex sm:items-center sm:justify-end sm:gap-3">
                                <Link :href="route('smartolt.unconfigured-all', { olt_id: olt.id })" class="sm:mr-auto">
                                    <SecondaryButton type="button" class="w-full sm:w-auto">{{ $t('common.cancel') }}</SecondaryButton>
                                </Link>
                                <SecondaryButton type="button" :disabled="advForm.processing" class="w-full sm:w-auto" @click="submitAdvanced(false)">
                                    <LayoutList class="mr-2 h-4 w-4" />
                                    {{ $t('registeronu.generate_only') }}
                                </SecondaryButton>
                                <PrimaryButton v-if="canExecute" type="button" :disabled="advForm.processing" class="w-full sm:w-auto" @click="submitAdvanced(true)">
                                    <Zap class="mr-2 h-4 w-4" />
                                    {{ advForm.processing ? $t('registeronu.executing') : $t('registeronu.execute_to_olt') }}
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
