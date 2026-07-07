<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { formatDateTime } from '@/lib/datetime';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { AlertTriangle, Bell, Building2, Check, CheckCircle2, Cloud, Copy, Cpu, ImageUp, Info, KeyRound, Plus, Send, SlidersHorizontal, Smartphone, Trash2, Upload } from '@lucide/vue';
import { computed, onBeforeUnmount, ref } from 'vue';

const props = defineProps({
    general: { type: Object, required: true },
    appInfo: { type: Object, default: () => ({ description: '', owner: '', stack: [] }) },
    acs: { type: Object, default: () => ({ url: '', username: '', password_set: false, default_url: '', default_username: '' }) },
    telegram: { type: Object, required: true },
    fcm: { type: Object, required: true },
    api: { type: Object, default: () => ({ enabled: false, base_url: '', public_status_url: '', new_token: null, tokens: [] }) },
    severityOptions: { type: Array, default: () => [] },
    alarmTypeOptions: { type: Array, default: () => [] },
});

const page = usePage();
const flash = computed(() => page.props.flash ?? {});

const tabs = [
    { key: 'general', label: 'Umum', icon: SlidersHorizontal },
    { key: 'acs', label: 'ACS / TR069', icon: Cloud },
    { key: 'telegram', label: 'Bot Telegram', icon: Send },
    { key: 'fcm', label: 'Notifikasi Mobile', icon: Smartphone },
    { key: 'api', label: 'API & Token', icon: KeyRound },
];
const activeTab = ref('general');

/* ------------------------------------------------------------------ */
/* Tab: Umum (general)                                                 */
/* ------------------------------------------------------------------ */
const generalForm = useForm({
    app_name: props.general.app_name,
    app_version: props.general.app_version,
    logo: null,
    remove_logo: false,
});

const logoPreview = ref(null); // object URL for a freshly picked file
const logoInput = ref(null);

const currentLogo = computed(() => {
    if (logoPreview.value) return logoPreview.value;
    if (generalForm.remove_logo) return null;
    return props.general.logo_url;
});

const pickLogo = () => logoInput.value?.click();

const onLogoChange = (event) => {
    const file = event.target.files?.[0];
    if (!file) return;
    if (logoPreview.value) URL.revokeObjectURL(logoPreview.value);
    generalForm.logo = file;
    generalForm.remove_logo = false;
    logoPreview.value = URL.createObjectURL(file);
};

const removeLogo = () => {
    if (logoPreview.value) URL.revokeObjectURL(logoPreview.value);
    logoPreview.value = null;
    generalForm.logo = null;
    generalForm.remove_logo = true;
    if (logoInput.value) logoInput.value.value = '';
};

const submitGeneral = () => {
    generalForm.post(route('settings.general.update'), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            if (logoPreview.value) URL.revokeObjectURL(logoPreview.value);
            logoPreview.value = null;
            generalForm.reset('logo', 'remove_logo');
            if (logoInput.value) logoInput.value.value = '';
        },
    });
};

onBeforeUnmount(() => {
    if (logoPreview.value) URL.revokeObjectURL(logoPreview.value);
});

/* ------------------------------------------------------------------ */
/* Tab: ACS / TR069                                                    */
/* ------------------------------------------------------------------ */
const acsForm = useForm({
    url: props.acs.url ?? '',
    username: props.acs.username ?? '',
    password: '',
});

const submitAcs = () => {
    acsForm.put(route('settings.acs.update'), {
        preserveScroll: true,
        onSuccess: () => acsForm.reset('password'),
    });
};

/* ------------------------------------------------------------------ */
/* Tab: Bot Telegram                                                   */
/* ------------------------------------------------------------------ */
const form = useForm({
    enabled: props.telegram.enabled,
    bot_token: '',
    chat_id: props.telegram.chat_id ?? '',
    min_severity: props.telegram.min_severity,
    notify_on_raise: props.telegram.notify_on_raise,
    notify_on_clear: props.telegram.notify_on_clear,
    notify_types: [...(props.telegram.notify_types ?? [])],
    commands_enabled: props.telegram.commands_enabled,
});

const isTypeSelected = (value) => form.notify_types.includes(value);

const toggleType = (value) => {
    form.notify_types = isTypeSelected(value)
        ? form.notify_types.filter((v) => v !== value)
        : [...form.notify_types, value];
};

const allTypesSelected = computed(
    () => props.alarmTypeOptions.length > 0 && form.notify_types.length === props.alarmTypeOptions.length,
);

const toggleAllTypes = () => {
    form.notify_types = allTypesSelected.value ? [] : props.alarmTypeOptions.map((opt) => opt.value);
};

const submit = () => {
    form.put(route('settings.telegram.update'), {
        preserveScroll: true,
        onSuccess: () => form.reset('bot_token'),
    });
};

const testing = ref(false);
const canTest = computed(() => props.telegram.bot_token_set && (props.telegram.chat_id ?? '').trim() !== '');

const sendTest = () => {
    testing.value = true;
    router.post(route('settings.telegram.test'), {}, {
        preserveScroll: true,
        onFinish: () => {
            testing.value = false;
        },
    });
};

const webhookBusy = ref(false);
const registerWebhook = () => {
    webhookBusy.value = true;
    router.post(route('settings.telegram.webhook.register'), {}, {
        preserveScroll: true,
        onFinish: () => {
            webhookBusy.value = false;
        },
    });
};

const deleteWebhook = () => {
    webhookBusy.value = true;
    router.post(route('settings.telegram.webhook.delete'), {}, {
        preserveScroll: true,
        onFinish: () => {
            webhookBusy.value = false;
        },
    });
};

const lastSent = computed(() =>
    props.telegram.last_sent_at ? formatDateTime(props.telegram.last_sent_at) : null,
);

/* ------------------------------------------------------------------ */
/* Tab: Notifikasi Mobile (FCM)                                        */
/* ------------------------------------------------------------------ */
const fcmForm = useForm({
    enabled: props.fcm.enabled,
    min_severity: props.fcm.min_severity,
    notify_on_raise: props.fcm.notify_on_raise,
    notify_on_clear: props.fcm.notify_on_clear,
    notify_types: [...(props.fcm.notify_types ?? [])],
});

const isFcmTypeSelected = (value) => fcmForm.notify_types.includes(value);
const toggleFcmType = (value) => {
    fcmForm.notify_types = isFcmTypeSelected(value)
        ? fcmForm.notify_types.filter((v) => v !== value)
        : [...fcmForm.notify_types, value];
};
const allFcmTypesSelected = computed(
    () => props.alarmTypeOptions.length > 0 && fcmForm.notify_types.length === props.alarmTypeOptions.length,
);
const toggleAllFcmTypes = () => {
    fcmForm.notify_types = allFcmTypesSelected.value ? [] : props.alarmTypeOptions.map((opt) => opt.value);
};
const submitFcm = () => {
    fcmForm.put(route('settings.fcm.update'), { preserveScroll: true });
};

const fcmSendForm = useForm({ title: '', body: '' });
const sendFcmManual = () => {
    fcmSendForm.post(route('settings.fcm.send'), {
        preserveScroll: true,
        onSuccess: () => fcmSendForm.reset(),
    });
};

const fcmLastSent = computed(() =>
    props.fcm.last_sent_at ? formatDateTime(props.fcm.last_sent_at) : null,
);

/* ------------------------------------------------------------------ */
/* Tab: API & Token                                                    */
/* ------------------------------------------------------------------ */
const tokenForm = useForm({ name: '' });

const createToken = () => {
    tokenForm.post(route('settings.api-tokens.store'), {
        preserveScroll: true,
        onSuccess: () => {
            tokenForm.reset('name');
            activeTab.value = 'api';
        },
    });
};

const revokeToken = (id) => {
    if (!confirm('Cabut token ini? Aplikasi yang memakainya akan langsung kehilangan akses.')) return;
    router.delete(route('settings.api-tokens.destroy', id), { preserveScroll: true });
};

// Token plain-text yang baru dibuat (hanya muncul sekali via flash dari server).
const newToken = computed(() => props.api?.new_token ?? null);

const copied = ref(null);
const copyText = async (text, key) => {
    try {
        await navigator.clipboard.writeText(text);
        copied.value = key;
        setTimeout(() => { if (copied.value === key) copied.value = null; }, 1500);
    } catch {
        /* clipboard tak tersedia — abaikan */
    }
};
</script>

<template>
    <Head title="Pengaturan" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-lg font-semibold leading-tight sm:text-xl text-white">Pengaturan</h2>
        </template>

        <div class="min-h-[60vh] pt-5 pb-16 sm:pt-8">
            <div class="w-full space-y-6 px-4 sm:px-6 lg:px-8">

                <!-- Tab bar -->
                <div class="flex flex-wrap gap-1 rounded-xl border border-white/10 bg-slate-900/40 p-1 backdrop-blur-xl">
                    <button
                        v-for="tab in tabs"
                        :key="tab.key"
                        type="button"
                        class="flex flex-1 items-center justify-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium transition-colors sm:flex-none"
                        :class="activeTab === tab.key
                            ? 'bg-cyan-500/20 text-cyan-200 ring-1 ring-cyan-500/40'
                            : 'text-slate-400 hover:bg-white/5 hover:text-slate-200'"
                        @click="activeTab = tab.key"
                    >
                        <component :is="tab.icon" class="h-4 w-4" />
                        {{ tab.label }}
                    </button>
                </div>

                <!-- ============================ TAB: UMUM ============================ -->
                <div v-show="activeTab === 'general'" class="space-y-6">
                    <!-- Identitas aplikasi -->
                    <form class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 backdrop-blur-xl shadow-lg shadow-black/30" @submit.prevent="submitGeneral">
                        <div class="flex items-center gap-3 border-b border-white/10 px-5 py-4 sm:px-6">
                            <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-cyan-500/20 ring-1 ring-cyan-500/30">
                                <SlidersHorizontal class="h-5 w-5 text-cyan-300" />
                            </div>
                            <div>
                                <h3 class="text-base font-semibold text-white">Identitas Aplikasi</h3>
                                <p class="text-sm text-slate-400">Nama, versi, dan logo yang tampil di seluruh aplikasi.</p>
                            </div>
                        </div>

                        <div class="grid gap-x-6 gap-y-6 p-5 sm:p-6 lg:grid-cols-2">
                            <!-- Logo -->
                            <div class="lg:col-span-2">
                                <InputLabel value="Logo Aplikasi" />
                                <div class="mt-1 flex flex-wrap items-center gap-4 rounded-lg border border-white/10 bg-slate-950/40 p-4">
                                    <div class="flex h-20 w-20 flex-shrink-0 items-center justify-center overflow-hidden rounded-lg border border-white/10 bg-slate-900/60">
                                        <img v-if="currentLogo" :src="currentLogo" alt="Logo" class="h-full w-full object-contain p-1.5" />
                                        <ImageUp v-else class="h-7 w-7 text-slate-600" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap gap-2">
                                            <SecondaryButton type="button" @click="pickLogo">
                                                <Upload class="mr-2 h-4 w-4" />
                                                {{ currentLogo ? 'Ganti Logo' : 'Unggah Logo' }}
                                            </SecondaryButton>
                                            <SecondaryButton v-if="currentLogo" type="button" @click="removeLogo">
                                                <Trash2 class="mr-2 h-4 w-4" />
                                                Hapus
                                            </SecondaryButton>
                                        </div>
                                        <input
                                            ref="logoInput"
                                            type="file"
                                            accept="image/png,image/jpeg,image/webp,image/svg+xml"
                                            class="hidden"
                                            @change="onLogoChange"
                                        />
                                        <p class="mt-2 text-xs text-slate-400">PNG, JPG, WEBP, atau SVG. Maksimal 1 MB. Kosongkan untuk memakai logo bawaan.</p>
                                    </div>
                                </div>
                                <InputError :message="generalForm.errors.logo" class="mt-2" />
                            </div>

                            <div>
                                <InputLabel for="app_name" value="Nama Aplikasi" />
                                <TextInput
                                    id="app_name"
                                    v-model="generalForm.app_name"
                                    type="text"
                                    class="mt-1 block w-full"
                                    maxlength="60"
                                    placeholder="mis. KusumaVision"
                                />
                                <InputError :message="generalForm.errors.app_name" class="mt-2" />
                            </div>

                            <div>
                                <InputLabel for="app_version" value="Versi Aplikasi" />
                                <TextInput
                                    id="app_version"
                                    v-model="generalForm.app_version"
                                    type="text"
                                    class="mt-1 block w-full"
                                    maxlength="30"
                                    placeholder="mis. 2.0.0"
                                />
                                <InputError :message="generalForm.errors.app_version" class="mt-2" />
                                <p class="mt-1 text-xs text-slate-400">Ditampilkan pada panel sistem di sidebar.</p>
                            </div>

                            <div class="flex flex-wrap items-center gap-3 border-t border-white/10 pt-5 lg:col-span-2">
                                <PrimaryButton :disabled="generalForm.processing">Simpan</PrimaryButton>
                                <span v-if="generalForm.recentlySuccessful" class="text-xs text-emerald-400">Tersimpan.</span>
                            </div>
                        </div>
                    </form>

                    <!-- Informasi sistem / tech stack -->
                    <div class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 backdrop-blur-xl shadow-lg shadow-black/30">
                        <div class="flex items-center gap-3 border-b border-white/10 px-5 py-4 sm:px-6">
                            <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-violet-500/20 ring-1 ring-violet-500/30">
                                <Info class="h-5 w-5 text-violet-300" />
                            </div>
                            <div>
                                <h3 class="text-base font-semibold text-white">Informasi Sistem</h3>
                                <p class="text-sm text-slate-400">Detail platform dan tumpukan teknologi.</p>
                            </div>
                        </div>

                        <div class="space-y-5 p-5 sm:p-6">
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div class="flex items-start gap-3 rounded-lg border border-white/10 bg-slate-950/40 px-4 py-3">
                                    <Building2 class="mt-0.5 h-5 w-5 flex-shrink-0 text-slate-400" />
                                    <div class="min-w-0">
                                        <p class="text-xs uppercase tracking-wide text-slate-500">Pemilik</p>
                                        <p class="text-sm font-medium text-white">{{ appInfo.owner }}</p>
                                    </div>
                                </div>
                                <div class="flex items-start gap-3 rounded-lg border border-white/10 bg-slate-950/40 px-4 py-3">
                                    <SlidersHorizontal class="mt-0.5 h-5 w-5 flex-shrink-0 text-slate-400" />
                                    <div class="min-w-0">
                                        <p class="text-xs uppercase tracking-wide text-slate-500">Deskripsi</p>
                                        <p class="text-sm font-medium text-white">{{ appInfo.description }}</p>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <div class="mb-3 flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-slate-500">
                                    <Cpu class="h-4 w-4" />
                                    Tumpukan Teknologi
                                </div>
                                <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                                    <div
                                        v-for="item in appInfo.stack"
                                        :key="item.label"
                                        class="flex items-center justify-between gap-3 border-b border-white/5 pb-2"
                                    >
                                        <dt class="text-sm text-slate-400">{{ item.label }}</dt>
                                        <dd class="truncate text-right text-sm font-medium text-slate-200">{{ item.value }}</dd>
                                    </div>
                                </dl>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ========================== TAB: TELEGRAM ========================== -->
                <!-- ============================ TAB: ACS / TR069 ============================ -->
                <form v-show="activeTab === 'acs'" class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 backdrop-blur-xl shadow-lg shadow-black/30" @submit.prevent="submitAcs">
                    <div class="flex items-center gap-3 border-b border-white/10 px-5 py-4 sm:px-6">
                        <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-cyan-500/20 ring-1 ring-cyan-500/30">
                            <Cloud class="h-5 w-5 text-cyan-300" />
                        </div>
                        <div>
                            <h3 class="text-base font-semibold text-white">Endpoint ACS / TR069</h3>
                            <p class="text-sm text-slate-400">Dipakai fitur "Aktifkan TR069 Massal" di halaman ONU per port (OLT ZTE).</p>
                        </div>
                    </div>

                    <div class="grid gap-x-6 gap-y-6 p-5 sm:p-6 lg:grid-cols-2">
                        <div class="lg:col-span-2">
                            <InputLabel for="acs_url" value="ACS URL" />
                            <TextInput
                                id="acs_url"
                                v-model="acsForm.url"
                                type="text"
                                class="mt-1 block w-full font-mono"
                                autocomplete="off"
                                :placeholder="acs.default_url || 'http://acs.contoh.net:7547'"
                            />
                            <InputError :message="acsForm.errors.url" class="mt-2" />
                            <p class="mt-1 text-xs text-slate-400">Alamat lengkap server ACS beserta port, mis. <span class="font-mono text-cyan-300">http://acs.bmkv.net:7547</span>.</p>
                        </div>

                        <div>
                            <InputLabel for="acs_username" value="Username ACS" />
                            <TextInput
                                id="acs_username"
                                v-model="acsForm.username"
                                type="text"
                                class="mt-1 block w-full"
                                autocomplete="off"
                                :placeholder="acs.default_username || 'mis. cms'"
                            />
                            <InputError :message="acsForm.errors.username" class="mt-2" />
                        </div>

                        <div>
                            <InputLabel for="acs_password" value="Password ACS" />
                            <TextInput
                                id="acs_password"
                                v-model="acsForm.password"
                                type="password"
                                class="mt-1 block w-full"
                                autocomplete="new-password"
                                :placeholder="acs.password_set ? '•••••••• (tersimpan — kosongkan untuk mempertahankan)' : 'Masukkan password ACS'"
                            />
                            <InputError :message="acsForm.errors.password" class="mt-2" />
                            <p class="mt-1 text-xs text-slate-400">Kosongkan untuk mempertahankan password lama.</p>
                        </div>

                        <div class="flex items-start gap-3 rounded-lg border border-white/10 bg-slate-950/40 px-4 py-3 text-xs text-slate-400 lg:col-span-2">
                            <Info class="mt-0.5 h-4 w-4 flex-shrink-0 text-cyan-300" />
                            <span>
                                Scan TR069 massal menandai ONU sebagai <span class="text-slate-200">sudah aktif</span> hanya bila TR069-nya aktif <span class="text-slate-200">dan</span> URL + username-nya sama persis dengan nilai di atas. ONU yang belum aktif atau mengarah ke ACS berbeda akan ditulis ulang dengan URL, username, dan password ini.
                            </span>
                        </div>

                        <div class="flex flex-wrap items-center gap-3 border-t border-white/10 pt-5 lg:col-span-2">
                            <PrimaryButton :disabled="acsForm.processing">Simpan</PrimaryButton>
                        </div>
                    </div>
                </form>

                <form v-show="activeTab === 'telegram'" class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 backdrop-blur-xl shadow-lg shadow-black/30" @submit.prevent="submit">
                    <div class="flex items-center gap-3 border-b border-white/10 px-5 py-4 sm:px-6">
                        <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-cyan-500/20 ring-1 ring-cyan-500/30">
                            <Send class="h-5 w-5 text-cyan-300" />
                        </div>
                        <div>
                            <h3 class="text-base font-semibold text-white">Notifikasi Telegram</h3>
                            <p class="text-sm text-slate-400">Kirim alarm OLT/ONU terbaru ke bot atau grup Telegram.</p>
                        </div>
                    </div>

                    <div class="grid gap-x-6 gap-y-6 p-5 sm:p-6 lg:grid-cols-2">
                        <label class="flex items-center justify-between gap-4 rounded-lg border border-white/10 bg-slate-950/40 px-4 py-3 lg:col-span-2">
                            <span>
                                <span class="block text-sm font-medium text-white">Aktifkan notifikasi</span>
                                <span class="block text-xs text-slate-400">Bila nonaktif, alarm tetap tersimpan tetapi tidak dikirim ke Telegram.</span>
                            </span>
                            <Checkbox v-model:checked="form.enabled" class="h-5 w-5" />
                        </label>

                        <div>
                            <InputLabel for="bot_token" value="Bot Token" />
                            <TextInput
                                id="bot_token"
                                v-model="form.bot_token"
                                type="password"
                                class="mt-1 block w-full"
                                autocomplete="off"
                                :placeholder="telegram.bot_token_set ? '•••••••• (token tersimpan — kosongkan untuk mempertahankan)' : 'Contoh: 123456789:ABCdefGhIj…'"
                            />
                            <InputError :message="form.errors.bot_token" class="mt-2" />
                            <p class="mt-1 text-xs text-slate-400">
                                Dapatkan token dari <span class="text-cyan-300">@BotFather</span> di Telegram via perintah <span class="text-cyan-300">/newbot</span>.
                            </p>
                        </div>

                        <div>
                            <InputLabel for="chat_id" value="Chat ID" />
                            <textarea
                                id="chat_id"
                                v-model="form.chat_id"
                                rows="2"
                                class="mt-1 block w-full rounded-lg border-white/10 bg-slate-900/60 text-slate-100 shadow-inner shadow-black/20 placeholder:text-slate-500 focus:border-cyan-500 focus:ring-cyan-500"
                                placeholder="mis. 123456789 atau -1001234567890. Pisahkan beberapa ID dengan koma."
                            ></textarea>
                            <InputError :message="form.errors.chat_id" class="mt-2" />
                            <p class="mt-1 text-xs text-slate-400">
                                Untuk personal: chat bot lalu cek <span class="text-cyan-300">@userinfobot</span>. Untuk grup: tambahkan bot ke grup, ID grup diawali tanda minus.
                            </p>
                        </div>

                        <div>
                            <InputLabel for="min_severity" value="Severity minimum" />
                            <select
                                id="min_severity"
                                v-model="form.min_severity"
                                class="mt-1 block min-h-11 w-full rounded-md border border-white/10 bg-slate-900/60 py-2.5 px-3 text-sm text-white shadow-sm focus:border-cyan-500 focus:ring-cyan-500"
                            >
                                <option v-for="opt in severityOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                            </select>
                            <InputError :message="form.errors.min_severity" class="mt-2" />
                            <p class="mt-1 text-xs text-slate-400">Hanya alarm dengan severity ini atau lebih tinggi yang dikirim.</p>
                        </div>

                        <div>
                            <InputLabel value="Pemicu notifikasi" />
                            <div class="mt-1 space-y-3 rounded-lg border border-white/10 bg-slate-950/40 px-4 py-3">
                                <label class="flex items-start gap-3">
                                    <Checkbox v-model:checked="form.notify_on_raise" class="mt-0.5" />
                                    <span>
                                        <span class="block text-sm font-medium text-white">Kirim saat alarm baru muncul</span>
                                        <span class="block text-xs text-slate-400">Notifikasi ketika alarm baru ter-trigger pada siklus polling.</span>
                                    </span>
                                </label>
                                <label class="flex items-start gap-3">
                                    <Checkbox v-model:checked="form.notify_on_clear" class="mt-0.5" />
                                    <span>
                                        <span class="block text-sm font-medium text-white">Kirim saat alarm pulih (cleared)</span>
                                        <span class="block text-xs text-slate-400">Notifikasi ketika alarm yang aktif kembali normal.</span>
                                    </span>
                                </label>
                            </div>
                        </div>

                        <div class="lg:col-span-2">
                            <div class="flex items-center justify-between gap-3">
                                <InputLabel value="Jenis alarm yang dikirim" />
                                <button
                                    type="button"
                                    class="text-xs font-medium text-cyan-300 hover:text-cyan-200"
                                    @click="toggleAllTypes"
                                >
                                    {{ allTypesSelected ? 'Kosongkan semua' : 'Pilih semua' }}
                                </button>
                            </div>
                            <div class="mt-1 grid gap-2 rounded-lg border border-white/10 bg-slate-950/40 px-4 py-3 sm:grid-cols-2">
                                <label
                                    v-for="opt in alarmTypeOptions"
                                    :key="opt.value"
                                    class="flex items-center gap-3 rounded-md px-2 py-1.5 transition-colors hover:bg-white/5"
                                >
                                    <Checkbox
                                        :checked="isTypeSelected(opt.value)"
                                        class="h-4 w-4"
                                        @update:checked="toggleType(opt.value)"
                                    />
                                    <span class="text-sm text-slate-200">{{ opt.label }}</span>
                                </label>
                            </div>
                            <p class="mt-1 text-xs text-slate-400">
                                Hanya jenis alarm yang dicentang yang dikirim ke Telegram. Mis. centang LOS, Dying Gasp, Redaman RX tinggi, dan Port GPON down saja.
                                <span v-if="form.notify_types.length === 0" class="text-amber-400">Tidak ada yang dicentang — semua notifikasi alarm dimatikan.</span>
                            </p>
                            <InputError :message="form.errors.notify_types" class="mt-2" />
                        </div>

                        <div class="rounded-lg border border-white/10 bg-slate-950/40 px-4 py-4 lg:col-span-2">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <h4 class="text-sm font-semibold text-white">Perintah Bot (Webhook)</h4>
                                    <p class="mt-0.5 text-xs text-slate-400">Bot membalas perintah dari chat ID terdaftar (baca-saja).</p>
                                </div>
                                <span
                                    class="shrink-0 rounded-full px-2.5 py-1 text-xs font-medium"
                                    :class="telegram.webhook_set ? 'bg-emerald-500/15 text-emerald-300' : 'bg-slate-500/15 text-slate-400'"
                                >
                                    {{ telegram.webhook_set ? 'Webhook terdaftar' : 'Belum terdaftar' }}
                                </span>
                            </div>

                            <label class="mt-4 flex items-start gap-3">
                                <Checkbox v-model:checked="form.commands_enabled" class="mt-0.5" />
                                <span>
                                    <span class="block text-sm font-medium text-white">Aktifkan perintah bot</span>
                                    <span class="block text-xs text-slate-400">Saat aktif &amp; webhook terdaftar, bot menjawab /status, /olt, /alarm, /onu, /prov.</span>
                                </span>
                            </label>

                            <div class="mt-4 flex flex-wrap items-center gap-3">
                                <SecondaryButton type="button" :disabled="!telegram.bot_token_set || webhookBusy" @click="registerWebhook">
                                    {{ webhookBusy ? 'Memproses…' : (telegram.webhook_set ? 'Daftar Ulang Webhook' : 'Daftarkan Webhook') }}
                                </SecondaryButton>
                                <SecondaryButton v-if="telegram.webhook_set" type="button" :disabled="webhookBusy" @click="deleteWebhook">
                                    Hapus Webhook
                                </SecondaryButton>
                                <span v-if="!telegram.bot_token_set" class="text-xs text-slate-500">Simpan bot token dulu sebelum mendaftarkan webhook.</span>
                            </div>

                            <div class="mt-4 rounded-md border border-white/5 bg-slate-900/40 px-3 py-2 text-xs text-slate-400">
                                <p class="font-medium text-slate-300">Perintah tersedia:</p>
                                <p class="mt-1 font-mono leading-relaxed">/status · /olt [nama|id] · /alarm · /onu &lt;serial&gt; · /prov · /id · /ping</p>
                            </div>
                        </div>

                        <div v-if="lastSent || telegram.last_error" class="rounded-lg border border-white/10 bg-slate-950/40 px-4 py-3 text-xs lg:col-span-2">
                            <p v-if="lastSent" class="text-slate-400">Terakhir terkirim: <span class="text-slate-200">{{ lastSent }}</span></p>
                            <p v-if="telegram.last_error" class="mt-1 text-red-400">Galat terakhir: {{ telegram.last_error }}</p>
                        </div>

                        <div class="flex flex-wrap items-center gap-3 border-t border-white/10 pt-5 lg:col-span-2">
                            <PrimaryButton :disabled="form.processing">Simpan</PrimaryButton>
                            <SecondaryButton type="button" :disabled="!canTest || testing" @click="sendTest">
                                <Send class="mr-2 h-4 w-4" />
                                {{ testing ? 'Mengirim…' : 'Kirim Tes' }}
                            </SecondaryButton>
                            <span v-if="!canTest" class="text-xs text-slate-500">Simpan token &amp; chat ID dulu untuk mengirim tes.</span>
                        </div>
                    </div>
                </form>

                <!-- ============================ TAB: NOTIFIKASI MOBILE (FCM) ============================ -->
                <div v-show="activeTab === 'fcm'" class="space-y-6">
                    <form class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 backdrop-blur-xl shadow-lg shadow-black/30" @submit.prevent="submitFcm">
                        <div class="flex items-center gap-3 border-b border-white/10 px-5 py-4 sm:px-6">
                            <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-cyan-500/20 ring-1 ring-cyan-500/30">
                                <Smartphone class="h-5 w-5 text-cyan-300" />
                            </div>
                            <div class="flex-1">
                                <h3 class="text-base font-semibold text-white">Notifikasi Aplikasi Mobile (Android)</h3>
                                <p class="text-sm text-slate-400">Pilih alarm mana yang dikirim sebagai push ke aplikasi Android via Firebase (FCM).</p>
                            </div>
                            <span
                                class="hidden shrink-0 rounded-full px-2.5 py-1 text-xs font-medium sm:inline"
                                :class="fcm.device_count > 0 ? 'bg-cyan-500/15 text-cyan-300' : 'bg-slate-500/15 text-slate-400'"
                            >
                                {{ fcm.device_count }} perangkat
                            </span>
                        </div>

                        <div class="grid gap-x-6 gap-y-6 p-5 sm:p-6 lg:grid-cols-2">
                            <div v-if="!fcm.credentials_ready" class="flex items-start gap-3 rounded-lg border border-amber-500/40 bg-amber-500/10 px-4 py-3 lg:col-span-2">
                                <AlertTriangle class="mt-0.5 h-5 w-5 flex-shrink-0 text-amber-300" />
                                <p class="text-sm text-amber-200">
                                    Kredensial Firebase belum dipasang di server (service-account JSON + <span class="font-mono text-xs">FIREBASE_CREDENTIALS</span>). Push tidak akan terkirim sampai itu diatur.
                                </p>
                            </div>

                            <label class="flex items-center justify-between gap-4 rounded-lg border border-white/10 bg-slate-950/40 px-4 py-3 lg:col-span-2">
                                <span>
                                    <span class="block text-sm font-medium text-white">Aktifkan push alarm ke mobile</span>
                                    <span class="block text-xs text-slate-400">Bila nonaktif, alarm tetap tersimpan tetapi tidak dikirim ke aplikasi mobile.</span>
                                </span>
                                <Checkbox v-model:checked="fcmForm.enabled" class="h-5 w-5" />
                            </label>

                            <div>
                                <InputLabel for="fcm_min_severity" value="Severity minimum" />
                                <select
                                    id="fcm_min_severity"
                                    v-model="fcmForm.min_severity"
                                    class="mt-1 block min-h-11 w-full rounded-md border border-white/10 bg-slate-900/60 py-2.5 px-3 text-sm text-white shadow-sm focus:border-cyan-500 focus:ring-cyan-500"
                                >
                                    <option v-for="opt in severityOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                                </select>
                                <InputError :message="fcmForm.errors.min_severity" class="mt-2" />
                                <p class="mt-1 text-xs text-slate-400">Hanya alarm dengan severity ini atau lebih tinggi yang dikirim.</p>
                            </div>

                            <div>
                                <InputLabel value="Pemicu notifikasi" />
                                <div class="mt-1 space-y-3 rounded-lg border border-white/10 bg-slate-950/40 px-4 py-3">
                                    <label class="flex items-start gap-3">
                                        <Checkbox v-model:checked="fcmForm.notify_on_raise" class="mt-0.5" />
                                        <span>
                                            <span class="block text-sm font-medium text-white">Kirim saat alarm baru muncul</span>
                                            <span class="block text-xs text-slate-400">Push ketika alarm baru ter-trigger.</span>
                                        </span>
                                    </label>
                                    <label class="flex items-start gap-3">
                                        <Checkbox v-model:checked="fcmForm.notify_on_clear" class="mt-0.5" />
                                        <span>
                                            <span class="block text-sm font-medium text-white">Kirim saat alarm pulih (cleared)</span>
                                            <span class="block text-xs text-slate-400">Push ketika alarm kembali normal.</span>
                                        </span>
                                    </label>
                                </div>
                            </div>

                            <div class="lg:col-span-2">
                                <div class="flex items-center justify-between gap-3">
                                    <InputLabel value="Jenis alarm yang dikirim" />
                                    <button type="button" class="text-xs font-medium text-cyan-300 hover:text-cyan-200" @click="toggleAllFcmTypes">
                                        {{ allFcmTypesSelected ? 'Kosongkan semua' : 'Pilih semua' }}
                                    </button>
                                </div>
                                <div class="mt-1 grid gap-2 rounded-lg border border-white/10 bg-slate-950/40 px-4 py-3 sm:grid-cols-2">
                                    <label
                                        v-for="opt in alarmTypeOptions"
                                        :key="opt.value"
                                        class="flex items-center gap-3 rounded-md px-2 py-1.5 transition-colors hover:bg-white/5"
                                    >
                                        <Checkbox :checked="isFcmTypeSelected(opt.value)" class="h-4 w-4" @update:checked="toggleFcmType(opt.value)" />
                                        <span class="text-sm text-slate-200">{{ opt.label }}</span>
                                    </label>
                                </div>
                                <p class="mt-1 text-xs text-slate-400">
                                    Hanya jenis alarm yang dicentang yang dikirim.
                                    <span v-if="fcmForm.notify_types.length === 0" class="text-amber-400">Tidak ada yang dicentang — semua push alarm dimatikan.</span>
                                </p>
                                <InputError :message="fcmForm.errors.notify_types" class="mt-2" />
                            </div>

                            <div v-if="fcmLastSent || fcm.last_error" class="rounded-lg border border-white/10 bg-slate-950/40 px-4 py-3 text-xs lg:col-span-2">
                                <p v-if="fcmLastSent" class="text-slate-400">Terakhir terkirim: <span class="text-slate-200">{{ fcmLastSent }}</span></p>
                                <p v-if="fcm.last_error" class="mt-1 text-red-400">Galat terakhir: {{ fcm.last_error }}</p>
                            </div>

                            <div class="flex flex-wrap items-center gap-3 border-t border-white/10 pt-5 lg:col-span-2">
                                <PrimaryButton :disabled="fcmForm.processing">Simpan</PrimaryButton>
                            </div>
                        </div>
                    </form>

                    <!-- Kirim notifikasi manual -->
                    <form class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 backdrop-blur-xl shadow-lg shadow-black/30" @submit.prevent="sendFcmManual">
                        <div class="flex items-center gap-3 border-b border-white/10 px-5 py-4 sm:px-6">
                            <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-sky-500/20 ring-1 ring-sky-500/30">
                                <Bell class="h-5 w-5 text-sky-300" />
                            </div>
                            <div>
                                <h3 class="text-base font-semibold text-white">Kirim Notifikasi Manual</h3>
                                <p class="text-sm text-slate-400">Broadcast pesan ke semua aplikasi mobile terdaftar ({{ fcm.device_count }} perangkat).</p>
                            </div>
                        </div>

                        <div class="grid gap-x-6 gap-y-5 p-5 sm:p-6">
                            <div>
                                <InputLabel for="fcm_title" value="Judul" />
                                <TextInput id="fcm_title" v-model="fcmSendForm.title" type="text" class="mt-1 block w-full" maxlength="120" placeholder="mis. Pemeliharaan jaringan" />
                                <InputError :message="fcmSendForm.errors.title" class="mt-2" />
                            </div>
                            <div>
                                <InputLabel for="fcm_body" value="Isi pesan" />
                                <textarea
                                    id="fcm_body"
                                    v-model="fcmSendForm.body"
                                    rows="3"
                                    maxlength="500"
                                    class="mt-1 block w-full rounded-lg border-white/10 bg-slate-900/60 text-slate-100 shadow-inner shadow-black/20 placeholder:text-slate-500 focus:border-cyan-500 focus:ring-cyan-500"
                                    placeholder="Tulis pesan yang akan muncul di notifikasi HP…"
                                ></textarea>
                                <InputError :message="fcmSendForm.errors.body" class="mt-2" />
                            </div>
                            <div class="flex flex-wrap items-center gap-3">
                                <PrimaryButton :disabled="fcmSendForm.processing || !fcm.credentials_ready || fcm.device_count === 0">
                                    <Bell class="mr-2 h-4 w-4" />
                                    {{ fcmSendForm.processing ? 'Mengirim…' : 'Kirim ke Semua Perangkat' }}
                                </PrimaryButton>
                                <span v-if="fcm.device_count === 0" class="text-xs text-slate-500">Belum ada perangkat terdaftar (user login di app dulu).</span>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- ============================ TAB: API & TOKEN ============================ -->
                <div v-show="activeTab === 'api'" class="space-y-6">
                    <!-- API sedang dinonaktifkan di server -->
                    <div v-if="!api.enabled" class="flex items-start gap-3 rounded-lg border border-amber-500/40 bg-amber-500/10 px-5 py-4 shadow-lg shadow-black/30">
                        <AlertTriangle class="mt-0.5 h-5 w-5 flex-shrink-0 text-amber-300" />
                        <div class="min-w-0">
                            <h4 class="text-sm font-semibold text-white">API sedang dinonaktifkan</h4>
                            <p class="mt-0.5 text-xs text-amber-200/90">
                                Seluruh endpoint <span class="font-mono">/api</span> dimatikan demi keamanan (nol permukaan serangan) karena belum dipakai aplikasi mana pun.
                                Token tetap bisa disiapkan, tapi baru berfungsi setelah API diaktifkan di server: ubah <span class="font-mono">$apiEnabled = true</span> di <span class="font-mono">routes/api.php</span> lalu reload PHP-FPM.
                            </p>
                        </div>
                    </div>

                    <!-- Akses API + URL -->
                    <div class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 backdrop-blur-xl shadow-lg shadow-black/30">
                        <div class="flex items-center gap-3 border-b border-white/10 px-5 py-4 sm:px-6">
                            <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-cyan-500/20 ring-1 ring-cyan-500/30">
                                <KeyRound class="h-5 w-5 text-cyan-300" />
                            </div>
                            <div>
                                <h3 class="text-base font-semibold text-white">Akses API</h3>
                                <p class="text-sm text-slate-400">Token untuk memanggil data NMS dari web aplikasi lain atau Android.</p>
                            </div>
                        </div>

                        <div class="space-y-4 p-5 sm:p-6">
                            <div>
                                <InputLabel value="Base URL API" />
                                <div class="mt-1 flex items-stretch gap-2">
                                    <input :value="api.base_url" readonly class="block w-full rounded-lg border-white/10 bg-slate-950/60 font-mono text-sm text-slate-200 focus:border-cyan-500 focus:ring-cyan-500" />
                                    <SecondaryButton type="button" @click="copyText(api.base_url, 'base')">
                                        <component :is="copied === 'base' ? Check : Copy" class="h-4 w-4" :class="copied === 'base' ? 'text-emerald-400' : ''" />
                                    </SecondaryButton>
                                </div>
                                <p class="mt-1 text-xs text-slate-400">Endpoint ber-token, mis. <span class="font-mono text-slate-300">{{ api.base_url }}/onus</span>. Kirim header <span class="font-mono text-cyan-300">Authorization: Bearer &lt;token&gt;</span>.</p>
                            </div>

                            <div>
                                <InputLabel value="Status publik (tanpa token)" />
                                <div class="mt-1 flex items-stretch gap-2">
                                    <input :value="api.public_status_url" readonly class="block w-full rounded-lg border-white/10 bg-slate-950/60 font-mono text-sm text-slate-200 focus:border-cyan-500 focus:ring-cyan-500" />
                                    <SecondaryButton type="button" @click="copyText(api.public_status_url, 'pub')">
                                        <component :is="copied === 'pub' ? Check : Copy" class="h-4 w-4" :class="copied === 'pub' ? 'text-emerald-400' : ''" />
                                    </SecondaryButton>
                                </div>
                                <p class="mt-1 text-xs text-slate-400">Angka agregat tanpa data pelanggan — aman untuk widget di web lain. Dokumentasi lengkap: <span class="font-mono text-slate-300">docs/API.md</span>.</p>
                            </div>
                        </div>
                    </div>

                    <!-- Token baru dibuat (tampil sekali) -->
                    <div v-if="newToken" class="overflow-hidden rounded-lg border border-emerald-500/40 bg-emerald-500/10 shadow-lg shadow-black/30">
                        <div class="flex items-start gap-3 px-5 py-4 sm:px-6">
                            <CheckCircle2 class="mt-0.5 h-5 w-5 flex-shrink-0 text-emerald-300" />
                            <div class="min-w-0 flex-1">
                                <h4 class="text-sm font-semibold text-white">Token baru dibuat — salin sekarang</h4>
                                <p class="mt-0.5 text-xs text-emerald-200/80">Demi keamanan, token <strong>hanya ditampilkan satu kali</strong>. Simpan di tempat aman (mis. <span class="font-mono">.env</span> server aplikasi lain).</p>
                                <div class="mt-3 flex items-stretch gap-2">
                                    <input :value="newToken" readonly class="block w-full rounded-lg border-emerald-500/30 bg-slate-950/70 font-mono text-xs text-emerald-200 focus:border-emerald-500 focus:ring-emerald-500" @focus="$event.target.select()" />
                                    <SecondaryButton type="button" @click="copyText(newToken, 'new')">
                                        <component :is="copied === 'new' ? Check : Copy" class="mr-2 h-4 w-4" :class="copied === 'new' ? 'text-emerald-400' : ''" />
                                        {{ copied === 'new' ? 'Tersalin' : 'Salin' }}
                                    </SecondaryButton>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Buat token + daftar token -->
                    <div class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 backdrop-blur-xl shadow-lg shadow-black/30">
                        <div class="flex items-center gap-3 border-b border-white/10 px-5 py-4 sm:px-6">
                            <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-violet-500/20 ring-1 ring-violet-500/30">
                                <KeyRound class="h-5 w-5 text-violet-300" />
                            </div>
                            <div>
                                <h3 class="text-base font-semibold text-white">Token Akses Personal</h3>
                                <p class="text-sm text-slate-400">Setiap token mewakili akses sebagai akun Anda. Cabut kapan saja.</p>
                            </div>
                        </div>

                        <div class="p-5 sm:p-6">
                            <form class="flex flex-col gap-3 sm:flex-row sm:items-end" @submit.prevent="createToken">
                                <div class="flex-1">
                                    <InputLabel for="token_name" value="Nama token" />
                                    <TextInput
                                        id="token_name"
                                        v-model="tokenForm.name"
                                        type="text"
                                        class="mt-1 block w-full"
                                        maxlength="60"
                                        placeholder="mis. Web Billing, App Android Teknisi"
                                    />
                                    <InputError :message="tokenForm.errors.name" class="mt-2" />
                                </div>
                                <PrimaryButton :disabled="tokenForm.processing || !tokenForm.name.trim() || !api.enabled" class="shrink-0">
                                    <Plus class="mr-2 h-4 w-4" />
                                    Buat Token
                                </PrimaryButton>
                            </form>
                            <p v-if="!api.enabled" class="mt-2 text-xs text-amber-300/90">Aktifkan API di server dulu sebelum membuat token.</p>

                            <!-- Daftar token -->
                            <div class="mt-6">
                                <div v-if="api.tokens.length === 0" class="rounded-lg border border-dashed border-white/10 bg-slate-950/40 px-4 py-8 text-center">
                                    <KeyRound class="mx-auto h-7 w-7 text-slate-600" />
                                    <p class="mt-2 text-sm text-slate-400">Belum ada token. Buat satu di atas untuk mulai memakai API dari aplikasi lain.</p>
                                </div>

                                <!-- Desktop: tabel -->
                                <div v-else class="hidden overflow-hidden rounded-lg border border-white/10 sm:block">
                                    <table class="min-w-full divide-y divide-white/10 text-sm">
                                        <thead class="bg-slate-950/40 text-xs uppercase tracking-wide text-slate-500">
                                            <tr>
                                                <th class="px-4 py-2.5 text-left font-medium">Nama</th>
                                                <th class="px-4 py-2.5 text-left font-medium">Dibuat</th>
                                                <th class="px-4 py-2.5 text-left font-medium">Terakhir dipakai</th>
                                                <th class="px-4 py-2.5 text-right font-medium">Aksi</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-white/5">
                                            <tr v-for="t in api.tokens" :key="t.id" class="hover:bg-white/5">
                                                <td class="px-4 py-3 font-medium text-white">{{ t.name }}</td>
                                                <td class="px-4 py-3 text-slate-400">{{ formatDateTime(t.created_at) }}</td>
                                                <td class="px-4 py-3 text-slate-400">{{ t.last_used_at ? formatDateTime(t.last_used_at) : 'Belum pernah' }}</td>
                                                <td class="px-4 py-3 text-right">
                                                    <button type="button" class="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-xs font-medium text-red-300 transition-colors hover:bg-red-500/10" @click="revokeToken(t.id)">
                                                        <Trash2 class="h-3.5 w-3.5" />
                                                        Cabut
                                                    </button>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- Mobile: kartu -->
                                <div v-if="api.tokens.length > 0" class="space-y-3 sm:hidden">
                                    <div v-for="t in api.tokens" :key="t.id" class="rounded-lg border border-white/10 bg-slate-950/40 p-4">
                                        <div class="flex items-start justify-between gap-3">
                                            <p class="min-w-0 break-words font-medium text-white">{{ t.name }}</p>
                                            <button type="button" class="inline-flex shrink-0 items-center gap-1.5 rounded-md px-2.5 py-1.5 text-xs font-medium text-red-300 transition-colors hover:bg-red-500/10" @click="revokeToken(t.id)">
                                                <Trash2 class="h-3.5 w-3.5" />
                                                Cabut
                                            </button>
                                        </div>
                                        <dl class="mt-2 space-y-1 text-xs text-slate-400">
                                            <div class="flex justify-between gap-3"><dt>Dibuat</dt><dd class="text-slate-300">{{ formatDateTime(t.created_at) }}</dd></div>
                                            <div class="flex justify-between gap-3"><dt>Terakhir dipakai</dt><dd class="text-slate-300">{{ t.last_used_at ? formatDateTime(t.last_used_at) : 'Belum pernah' }}</dd></div>
                                        </dl>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-5 flex items-start gap-2 rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-3">
                                <AlertTriangle class="mt-0.5 h-4 w-4 flex-shrink-0 text-amber-300" />
                                <p class="text-xs text-amber-200/90">Token bersifat rahasia seperti kata sandi. Jangan taruh di kode yang bisa dilihat publik/browser. Untuk web lain, simpan di server (mis. <span class="font-mono">.env</span>) dan panggil API dari sisi server.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
