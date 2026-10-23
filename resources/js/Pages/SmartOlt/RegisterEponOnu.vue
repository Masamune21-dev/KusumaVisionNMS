<script setup>
import ConfirmModal from '@/Components/ConfirmModal.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Check, Copy, LayoutList, Network, Terminal, TriangleAlert, User, Zap } from '@lucide/vue';
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue';

// Registrasi ONU EPON (kartu ETG ZTE C300/C320) — model bridge: otorisasi MAC + SLA + VLAN.
// Sintaksnya belum diverifikasi di OLT ETG asli, jadi pratinjau skrip selalu terlihat.
const { t } = useI18n({ useScope: 'global' });

const props = defineProps({
    olt: { type: Object, required: true },
    epon_ports: { type: Array, default: () => [] },
    odps: { type: Array, default: () => [] },
    suggestions: { type: Object, default: () => ({ onu_types: [], sla_profiles: [] }) },
    onu_model: { type: String, default: '' },
    defaults: { type: Object, required: true },
});

const form = useForm({ ...props.defaults });
const { confirmState, confirm, handleConfirm, handleCancel } = useConfirm();

const portKey = computed({
    get: () => `${form.slot}_${form.port}`,
    set: (value) => {
        const [slot, port] = String(value).split('_').map(Number);
        form.slot = slot;
        form.port = port;
    },
});

const odpOptions = computed(() => props.odps.filter(
    (odp) => (odp.slot === null && odp.port === null)
        || (Number(odp.slot) === Number(form.slot) && Number(odp.port) === Number(form.port)),
));

const canExecute = computed(() => !!props.olt.capabilities?.supports_provisioning
    && props.olt.cli_transport === 'telnet');

const cancelHref = computed(() => (form.slot && form.port
    ? route('smartolt.port-onus', [props.olt.id, form.slot, form.port])
    : route('smartolt.unconfigured-all', { olt_id: props.olt.id })));

// --- pratinjau skrip CLI (debounce, baca-saja ke server) ---
const preview = reactive({ script: t('registeronu.fill_form_comment'), loading: false });
let debounceTimer = null;

const runPreview = () => {
    preview.loading = true;
    window.axios
        .post(route('smartolt.register-epon.preview', props.olt.id), form.data())
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

const payload = computed(() => JSON.stringify(form.data()));
watch(payload, () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(runPreview, 400);
});
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

const submit = async (execute) => {
    if (execute) {
        const ok = await confirm({
            title: t('registerepon.confirm_exec_title'),
            message: t('registerepon.confirm_exec_msg', { mac: form.mac || '', olt: props.olt.name }),
            confirmLabel: t('registeronu.confirm_exec_label'),
            variant: 'warning',
        });
        if (!ok) {
            return;
        }
    }

    form
        .transform((data) => ({ ...data, execute }))
        .post(route('smartolt.register-epon.store', props.olt.id), { preserveScroll: true });
};

const selectClass = 'mt-1 block w-full rounded-md border-white/10 bg-canvas-3/40 text-sm shadow-sm focus:border-cyan-500 focus:ring-cyan-500';
</script>

<template>
    <Head :title="$t('registerepon.title')" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h2 class="flex flex-wrap items-center gap-2 text-lg font-semibold leading-tight text-white sm:text-xl">
                    {{ $t('registerepon.title') }}
                    <span class="kv-pill kv-pill-info">EPON</span>
                </h2>
                <p class="mt-1 text-sm text-slate-500">{{ $t('registerepon.subtitle', { olt: olt.name }) }}</p>
            </div>
        </template>

        <div class="min-h-[60vh] pt-5 pb-16 sm:pt-8">
            <div class="w-full space-y-5 px-4 sm:px-6 lg:px-8">
                <div class="flex items-start gap-3 rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-200">
                    <TriangleAlert class="mt-0.5 h-5 w-5 flex-shrink-0" />
                    <div>
                        <p class="font-semibold">{{ $t('registerepon.unverified_title') }}</p>
                        <p class="mt-1 text-amber-200/90">{{ $t('registerepon.unverified_body') }}</p>
                    </div>
                </div>

                <div class="grid gap-5 xl:grid-cols-[minmax(0,480px)_1fr]">
                    <!-- Kolom kiri: pratinjau skrip -->
                    <div class="order-2 xl:order-1 xl:sticky xl:top-6 xl:self-start">
                        <div class="kv-surface rounded-lg border border-white/10 bg-canvas-3/60 shadow-lg shadow-black/30">
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
                                <SecondaryButton type="button" size="sm" :title="$t('registeronu.copy_title')" @click="copyScript">
                                    <Check v-if="copied" class="mr-1.5 h-3.5 w-3.5 text-emerald-400" />
                                    <Copy v-else class="mr-1.5 h-3.5 w-3.5" />
                                    {{ copied ? $t('configonu.copied') : $t('registeronu.copy') }}
                                </SecondaryButton>
                            </div>
                            <pre data-theme="dark" class="kv-terminal max-h-[70vh] overflow-auto whitespace-pre-wrap break-words rounded-b-lg bg-slate-950/70 px-4 py-3 font-mono text-xs leading-relaxed text-emerald-300/90">{{ preview.script }}</pre>
                        </div>
                    </div>

                    <!-- Kolom kanan: form -->
                    <form class="order-1 space-y-5 xl:order-2" @submit.prevent="submit(false)">
                        <div class="kv-surface rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30">
                            <div class="flex items-center gap-3 border-b border-white/10 px-4 py-4 sm:px-6">
                                <div class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-sky-500/15 ring-1 ring-cyan-500/30">
                                    <User class="h-4 w-4 text-cyan-400" />
                                </div>
                                <div>
                                    <h3 class="text-sm font-semibold text-white">{{ $t('registerepon.section_identity') }}</h3>
                                    <p class="text-xs text-slate-500">{{ $t('registerepon.identity_hint') }}</p>
                                </div>
                            </div>
                            <div class="grid grid-cols-1 gap-4 p-4 sm:grid-cols-2 sm:p-6">
                                <div class="sm:col-span-2">
                                    <InputLabel for="epon-mac" :value="$t('registerepon.mac')" />
                                    <TextInput id="epon-mac" v-model="form.mac" class="mt-1 w-full font-mono" placeholder="1c18.4a0d.d30f" autocomplete="off" />
                                    <p class="mt-1 text-xs text-slate-500">
                                        {{ $t('registerepon.mac_hint') }}
                                        <template v-if="onu_model"> · {{ $t('registerepon.onu_model', { model: onu_model }) }}</template>
                                    </p>
                                    <InputError class="mt-1.5" :message="form.errors.mac" />
                                </div>
                                <div>
                                    <InputLabel for="epon-port" :value="$t('registerepon.port')" />
                                    <select id="epon-port" v-model="portKey" :class="selectClass" class="font-mono">
                                        <option v-for="p in epon_ports" :key="`${p.slot}_${p.port}`" :value="`${p.slot}_${p.port}`">{{ p.name }}</option>
                                    </select>
                                    <InputError class="mt-1.5" :message="form.errors.slot || form.errors.port" />
                                </div>
                                <div>
                                    <InputLabel for="epon-onu-id" value="ONU ID" />
                                    <TextInput id="epon-onu-id" v-model.number="form.onu_id" type="number" min="1" max="128" class="mt-1 w-full tabular-nums" />
                                    <InputError class="mt-1.5" :message="form.errors.onu_id" />
                                </div>
                                <div class="sm:col-span-2">
                                    <InputLabel for="epon-onu-type" :value="$t('registerepon.onu_type')" />
                                    <TextInput id="epon-onu-type" v-model="form.onu_type" list="epon-onu-types" class="mt-1 w-full font-mono" autocomplete="off" />
                                    <datalist id="epon-onu-types">
                                        <option v-for="type in suggestions.onu_types" :key="type" :value="type" />
                                    </datalist>
                                    <p class="mt-1 text-xs text-slate-500">{{ $t('registerepon.onu_type_hint') }}</p>
                                    <InputError class="mt-1.5" :message="form.errors.onu_type" />
                                </div>
                                <div>
                                    <InputLabel for="epon-name" :value="$t('registerepon.customer_name')" />
                                    <TextInput id="epon-name" v-model="form.customer_name" class="mt-1 w-full" maxlength="64" />
                                    <InputError class="mt-1.5" :message="form.errors.customer_name" />
                                </div>
                                <div>
                                    <InputLabel for="epon-desc" :value="$t('registerepon.description')" />
                                    <TextInput id="epon-desc" v-model="form.description" class="mt-1 w-full" maxlength="64" />
                                    <InputError class="mt-1.5" :message="form.errors.description" />
                                </div>
                                <p class="text-xs text-slate-500 sm:col-span-2">{{ $t('registerepon.description_hint') }}</p>
                                <div class="sm:col-span-2">
                                    <InputLabel for="epon-odp" :value="$t('registeronu.odp')" />
                                    <select id="epon-odp" v-model="form.odp_id" :class="selectClass">
                                        <option :value="null">{{ $t('registeronu.odp_none') }}</option>
                                        <option v-for="odp in odpOptions" :key="odp.id" :value="odp.id">{{ odp.name }}</option>
                                    </select>
                                    <p class="mt-1 text-xs text-slate-500">{{ $t('registeronu.odp_hint') }}</p>
                                    <InputError class="mt-1.5" :message="form.errors.odp_id" />
                                </div>
                            </div>
                        </div>

                        <div class="kv-surface rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30">
                            <div class="flex items-center gap-3 border-b border-white/10 px-4 py-4 sm:px-6">
                                <div class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-sky-500/15 ring-1 ring-cyan-500/30">
                                    <Network class="h-4 w-4 text-cyan-400" />
                                </div>
                                <div>
                                    <h3 class="text-sm font-semibold text-white">{{ $t('registerepon.section_service') }}</h3>
                                    <p class="text-xs text-slate-500">{{ $t('registerepon.service_hint') }}</p>
                                </div>
                            </div>
                            <div class="grid grid-cols-1 gap-4 p-4 sm:grid-cols-2 sm:p-6">
                                <div>
                                    <InputLabel for="epon-vlan" value="VLAN" />
                                    <TextInput id="epon-vlan" v-model.number="form.vlan" type="number" min="1" max="4094" class="mt-1 w-full tabular-nums" />
                                    <InputError class="mt-1.5" :message="form.errors.vlan" />
                                </div>
                                <div>
                                    <InputLabel for="epon-sla" :value="$t('registerepon.sla_profile')" />
                                    <TextInput id="epon-sla" v-model="form.sla_profile" list="epon-sla-profiles" class="mt-1 w-full font-mono" autocomplete="off" />
                                    <datalist id="epon-sla-profiles">
                                        <option v-for="sla in suggestions.sla_profiles" :key="sla" :value="sla" />
                                    </datalist>
                                    <p class="mt-1 text-xs text-slate-500">{{ $t('registerepon.sla_hint') }}</p>
                                    <InputError class="mt-1.5" :message="form.errors.sla_profile" />
                                </div>
                                <div class="sm:col-span-2">
                                    <InputLabel for="epon-style" :value="$t('registerepon.service_style')" />
                                    <select id="epon-style" v-model="form.service_style" :class="selectClass">
                                        <option value="service-port">{{ $t('registerepon.style_service_port') }}</option>
                                        <option value="switchport">{{ $t('registerepon.style_switchport') }}</option>
                                    </select>
                                    <InputError class="mt-1.5" :message="form.errors.service_style" />
                                </div>
                                <div>
                                    <InputLabel for="epon-onu-vlan" :value="$t('registerepon.onu_vlan_mode')" />
                                    <select id="epon-onu-vlan" v-model="form.onu_vlan_mode" :class="selectClass">
                                        <option value="none">{{ $t('registerepon.mode_none') }}</option>
                                        <option value="tag">{{ $t('registerepon.mode_tag') }}</option>
                                        <option value="transparent">{{ $t('registerepon.mode_transparent') }}</option>
                                    </select>
                                    <InputError class="mt-1.5" :message="form.errors.onu_vlan_mode" />
                                </div>
                                <div v-if="form.onu_vlan_mode !== 'none'">
                                    <InputLabel for="epon-eth" :value="$t('registerepon.onu_eth_port')" />
                                    <select id="epon-eth" v-model.number="form.onu_eth_port" :class="selectClass">
                                        <option v-for="eth in [1, 2, 3, 4]" :key="eth" :value="eth">eth_0/{{ eth }}</option>
                                    </select>
                                    <InputError class="mt-1.5" :message="form.errors.onu_eth_port" />
                                </div>
                            </div>
                        </div>

                        <div class="kv-surface rounded-lg border border-white/10 bg-slate-900/40 px-4 py-4 shadow-lg shadow-black/30 sm:px-6">
                            <p v-if="!canExecute" class="mb-3 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2.5 text-xs text-amber-200">
                                {{ $t('registeronu.no_cli_note') }}
                            </p>
                            <div class="grid gap-2 sm:flex sm:items-center sm:justify-end sm:gap-3">
                                <Link :href="cancelHref" class="sm:mr-auto">
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
                </div>
            </div>
        </div>

        <ConfirmModal :state="confirmState" @confirm="handleConfirm" @cancel="handleCancel" />
    </AuthenticatedLayout>
</template>
