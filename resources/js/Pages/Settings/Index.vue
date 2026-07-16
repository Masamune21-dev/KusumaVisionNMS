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
import { AlertTriangle, Bell, Building2, Check, CheckCircle2, Cloud, Copy, Cpu, Download, ImageUp, Info, KeyRound, Plus, Send, SlidersHorizontal, Smartphone, Trash2, Upload } from '@lucide/vue';
import { computed, onBeforeUnmount, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { alarmTypeLabel } from '@/lib/alarm';

const props = defineProps({
    general: { type: Object, required: true },
    appInfo: { type: Object, default: () => ({ description: '', owner: '', stack: [] }) },
    mobileApk: { type: Object, default: () => ({ available: false, url: null, version: null, size: null, updated_at: null }) },
    acs: { type: Object, default: () => ({ url: '', username: '', password_set: false, default_url: '', default_username: '' }) },
    alarm: { type: Object, default: () => ({ confirm_before_notify: true }) },
    telegram: { type: Object, required: true },
    fcm: { type: Object, required: true },
    api: { type: Object, default: () => ({ enabled: false, base_url: '', public_status_url: '', new_token: null, tokens: [] }) },
    severityOptions: { type: Array, default: () => [] },
    alarmTypeOptions: { type: Array, default: () => [] },
});

const page = usePage();
const flash = computed(() => page.props.flash ?? {});

const { t } = useI18n({ useScope: 'global' });

// Label tab dirakit reaktif dari i18n agar ikut berganti saat switch bahasa.
const tabs = computed(() => [
    { key: 'general', label: t('settings.tab_general'), icon: SlidersHorizontal },
    { key: 'acs', label: t('settings.tab_acs'), icon: Cloud },
    { key: 'alarm', label: t('settings.tab_alarm'), icon: AlertTriangle },
    { key: 'telegram', label: t('settings.tab_telegram'), icon: Send },
    { key: 'fcm', label: t('settings.tab_fcm'), icon: Smartphone },
    { key: 'api', label: t('settings.tab_api'), icon: KeyRound },
]);
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
/* Tab: Alarm (perilaku konfirmasi)                                    */
/* ------------------------------------------------------------------ */
const alarmForm = useForm({
    confirm_before_notify: props.alarm.confirm_before_notify,
});

const submitAlarm = () => {
    alarmForm.put(route('settings.alarm.update'), { preserveScroll: true });
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
    if (!confirm(t('settings.revoke_confirm'))) return;
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
    <Head :title="$t('settings.title')" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-lg font-semibold leading-tight sm:text-xl text-white">{{ $t('settings.title') }}</h2>
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
                                <h3 class="text-base font-semibold text-white">{{ $t('settings.identity_title') }}</h3>
                                <p class="text-sm text-slate-400">{{ $t('settings.identity_sub') }}</p>
                            </div>
                        </div>

                        <div class="grid gap-x-6 gap-y-6 p-5 sm:p-6 lg:grid-cols-2">
                            <!-- Logo -->
                            <div class="lg:col-span-2">
                                <InputLabel :value="$t('settings.logo_label')" />
                                <div class="mt-1 flex flex-wrap items-center gap-4 rounded-lg border border-white/10 bg-slate-950/40 p-4">
                                    <div class="flex h-20 w-20 flex-shrink-0 items-center justify-center overflow-hidden rounded-lg border border-white/10 bg-slate-900/60">
                                        <img v-if="currentLogo" :src="currentLogo" alt="Logo" class="h-full w-full object-contain p-1.5" />
                                        <ImageUp v-else class="h-7 w-7 text-slate-600" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap gap-2">
                                            <SecondaryButton type="button" @click="pickLogo">
                                                <Upload class="mr-2 h-4 w-4" />
                                                {{ currentLogo ? $t('settings.change_logo') : $t('settings.upload_logo') }}
                                            </SecondaryButton>
                                            <SecondaryButton v-if="currentLogo" type="button" @click="removeLogo">
                                                <Trash2 class="mr-2 h-4 w-4" />
                                                {{ $t('common.delete') }}
                                            </SecondaryButton>
                                        </div>
                                        <input
                                            ref="logoInput"
                                            type="file"
                                            accept="image/png,image/jpeg,image/webp,image/svg+xml"
                                            class="hidden"
                                            @change="onLogoChange"
                                        />
                                        <p class="mt-2 text-xs text-slate-400">{{ $t('settings.logo_hint') }}</p>
                                    </div>
                                </div>
                                <InputError :message="generalForm.errors.logo" class="mt-2" />
                            </div>

                            <div>
                                <InputLabel for="app_name" :value="$t('settings.app_name')" />
                                <TextInput
                                    id="app_name"
                                    v-model="generalForm.app_name"
                                    type="text"
                                    class="mt-1 block w-full"
                                    maxlength="60"
                                    :placeholder="$t('settings.app_name_placeholder')"
                                />
                                <InputError :message="generalForm.errors.app_name" class="mt-2" />
                            </div>

                            <div>
                                <InputLabel for="app_version" :value="$t('settings.app_version')" />
                                <TextInput
                                    id="app_version"
                                    v-model="generalForm.app_version"
                                    type="text"
                                    class="mt-1 block w-full"
                                    maxlength="30"
                                    :placeholder="$t('settings.app_version_placeholder')"
                                />
                                <InputError :message="generalForm.errors.app_version" class="mt-2" />
                                <p class="mt-1 text-xs text-slate-400">{{ $t('settings.app_version_hint') }}</p>
                            </div>

                            <div class="flex flex-wrap items-center gap-3 border-t border-white/10 pt-5 lg:col-span-2">
                                <PrimaryButton :disabled="generalForm.processing">{{ $t('common.save') }}</PrimaryButton>
                                <span v-if="generalForm.recentlySuccessful" class="text-xs text-emerald-400">{{ $t('settings.saved') }}</span>
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
                                <h3 class="text-base font-semibold text-white">{{ $t('settings.sysinfo_title') }}</h3>
                                <p class="text-sm text-slate-400">{{ $t('settings.sysinfo_sub') }}</p>
                            </div>
                        </div>

                        <div class="space-y-5 p-5 sm:p-6">
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div class="flex items-start gap-3 rounded-lg border border-white/10 bg-slate-950/40 px-4 py-3">
                                    <Building2 class="mt-0.5 h-5 w-5 flex-shrink-0 text-slate-400" />
                                    <div class="min-w-0">
                                        <p class="text-xs uppercase tracking-wide text-slate-500">{{ $t('settings.owner') }}</p>
                                        <p class="text-sm font-medium text-white">{{ appInfo.owner }}</p>
                                    </div>
                                </div>
                                <div class="flex items-start gap-3 rounded-lg border border-white/10 bg-slate-950/40 px-4 py-3">
                                    <SlidersHorizontal class="mt-0.5 h-5 w-5 flex-shrink-0 text-slate-400" />
                                    <div class="min-w-0">
                                        <p class="text-xs uppercase tracking-wide text-slate-500">{{ $t('settings.description') }}</p>
                                        <p class="text-sm font-medium text-white">{{ appInfo.description }}</p>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <div class="mb-3 flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-slate-500">
                                    <Cpu class="h-4 w-4" />
                                    {{ $t('settings.stack') }}
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

                    <!-- Unduh aplikasi Android (APK) -->
                    <div class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 backdrop-blur-xl shadow-lg shadow-black/30">
                        <div class="flex items-center gap-3 border-b border-white/10 px-5 py-4 sm:px-6">
                            <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-cyan-500/20 ring-1 ring-cyan-500/30">
                                <Smartphone class="h-5 w-5 text-cyan-300" />
                            </div>
                            <div>
                                <h3 class="text-base font-semibold text-white">{{ $t('settings.android_title') }}</h3>
                                <p class="text-sm text-slate-400">{{ $t('settings.android_sub') }}</p>
                            </div>
                        </div>

                        <div class="p-5 sm:p-6">
                            <div v-if="mobileApk.available" class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                <div class="flex items-start gap-3">
                                    <Smartphone class="mt-0.5 h-5 w-5 flex-shrink-0 text-slate-400" />
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-white">
                                            KusumaVision NMS
                                            <span v-if="mobileApk.version" class="text-slate-400">v{{ mobileApk.version }}</span>
                                        </p>
                                        <p class="text-xs text-slate-500">
                                            <span v-if="mobileApk.size">{{ mobileApk.size }}</span>
                                            <span v-if="mobileApk.updated_at"> · {{ $t('settings.apk_updated', { date: formatDateTime(mobileApk.updated_at) }) }}</span>
                                        </p>
                                    </div>
                                </div>
                                <a
                                    :href="mobileApk.url"
                                    download
                                    class="inline-flex items-center justify-center gap-2 rounded-lg bg-cyan-500/90 px-4 py-2.5 text-sm font-semibold text-slate-950 shadow-lg shadow-cyan-500/20 transition hover:bg-cyan-400"
                                >
                                    <Download class="h-4 w-4" />
                                    {{ $t('settings.download_apk') }}
                                </a>
                            </div>
                            <div v-else class="flex items-start gap-3 rounded-lg border border-amber-500/20 bg-amber-500/5 px-4 py-3">
                                <AlertTriangle class="mt-0.5 h-5 w-5 flex-shrink-0 text-amber-400" />
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-amber-200">{{ $t('settings.apk_missing') }}</p>
                                    <p class="text-xs text-slate-400" v-html="$t('settings.apk_missing_hint')"></p>
                                </div>
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
                            <h3 class="text-base font-semibold text-white">{{ $t('settings.acs_title') }}</h3>
                            <p class="text-sm text-slate-400">{{ $t('settings.acs_sub') }}</p>
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
                            <p class="mt-1 text-xs text-slate-400" v-html="$t('settings.acs_url_hint')"></p>
                        </div>

                        <div>
                            <InputLabel for="acs_username" :value="$t('settings.acs_username')" />
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
                            <InputLabel for="acs_password" :value="$t('settings.acs_password')" />
                            <TextInput
                                id="acs_password"
                                v-model="acsForm.password"
                                type="password"
                                class="mt-1 block w-full"
                                autocomplete="new-password"
                                :placeholder="acs.password_set ? $t('settings.acs_pw_saved') : $t('settings.acs_pw_placeholder')"
                            />
                            <InputError :message="acsForm.errors.password" class="mt-2" />
                            <p class="mt-1 text-xs text-slate-400">{{ $t('settings.acs_pw_hint') }}</p>
                        </div>

                        <div class="flex items-start gap-3 rounded-lg border border-white/10 bg-slate-950/40 px-4 py-3 text-xs text-slate-400 lg:col-span-2">
                            <Info class="mt-0.5 h-4 w-4 flex-shrink-0 text-cyan-300" />
                            <span v-html="$t('settings.acs_note')"></span>
                        </div>

                        <div class="flex flex-wrap items-center gap-3 border-t border-white/10 pt-5 lg:col-span-2">
                            <PrimaryButton :disabled="acsForm.processing">{{ $t('common.save') }}</PrimaryButton>
                        </div>
                    </div>
                </form>

                <!-- ============================ TAB: ALARM ============================ -->
                <form v-show="activeTab === 'alarm'" class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 backdrop-blur-xl shadow-lg shadow-black/30" @submit.prevent="submitAlarm">
                    <div class="flex items-center gap-3 border-b border-white/10 px-5 py-4 sm:px-6">
                        <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-amber-500/20 ring-1 ring-amber-500/30">
                            <AlertTriangle class="h-5 w-5 text-amber-300" />
                        </div>
                        <div class="flex-1">
                            <h3 class="text-base font-semibold text-white">{{ $t('settings.alarm_title') }}</h3>
                            <p class="text-sm text-slate-400">{{ $t('settings.alarm_sub') }}</p>
                        </div>
                        <span
                            class="hidden shrink-0 rounded-full px-2.5 py-1 text-xs font-medium sm:inline"
                            :class="alarmForm.confirm_before_notify ? 'bg-cyan-500/15 text-cyan-300' : 'bg-amber-500/15 text-amber-300'"
                        >
                            {{ alarmForm.confirm_before_notify ? $t('settings.alarm_badge_confirm') : $t('settings.alarm_badge_realtime') }}
                        </span>
                    </div>

                    <div class="space-y-6 p-5 sm:p-6">
                        <label class="flex items-center justify-between gap-4 rounded-lg border border-white/10 bg-slate-950/40 px-4 py-3">
                            <span>
                                <span class="block text-sm font-medium text-white">{{ $t('settings.alarm_toggle') }}</span>
                                <span class="block text-xs text-slate-400" v-html="$t('settings.alarm_toggle_hint')"></span>
                            </span>
                            <Checkbox v-model:checked="alarmForm.confirm_before_notify" class="h-5 w-5" />
                        </label>

                        <div class="flex items-start gap-3 rounded-lg border border-white/10 bg-slate-950/40 px-4 py-3 text-xs text-slate-400">
                            <Info class="mt-0.5 h-4 w-4 flex-shrink-0 text-cyan-300" />
                            <span v-html="$t('settings.alarm_note')"></span>
                        </div>

                        <div class="flex flex-wrap items-center gap-3 border-t border-white/10 pt-5">
                            <PrimaryButton :disabled="alarmForm.processing">{{ $t('common.save') }}</PrimaryButton>
                        </div>
                    </div>
                </form>

                <form v-show="activeTab === 'telegram'" class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 backdrop-blur-xl shadow-lg shadow-black/30" @submit.prevent="submit">
                    <div class="flex items-center gap-3 border-b border-white/10 px-5 py-4 sm:px-6">
                        <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-cyan-500/20 ring-1 ring-cyan-500/30">
                            <Send class="h-5 w-5 text-cyan-300" />
                        </div>
                        <div>
                            <h3 class="text-base font-semibold text-white">{{ $t('telegrambot.section_title') }}</h3>
                            <p class="text-sm text-slate-400">{{ $t('telegrambot.section_sub_admin') }}</p>
                        </div>
                    </div>

                    <div class="grid gap-x-6 gap-y-6 p-5 sm:p-6 lg:grid-cols-2">
                        <label class="flex items-center justify-between gap-4 rounded-lg border border-white/10 bg-slate-950/40 px-4 py-3 lg:col-span-2">
                            <span>
                                <span class="block text-sm font-medium text-white">{{ $t('telegrambot.enable') }}</span>
                                <span class="block text-xs text-slate-400">{{ $t('telegrambot.enable_hint') }}</span>
                            </span>
                            <Checkbox v-model:checked="form.enabled" class="h-5 w-5" />
                        </label>

                        <div>
                            <InputLabel for="bot_token" :value="$t('telegrambot.bot_token')" />
                            <TextInput
                                id="bot_token"
                                v-model="form.bot_token"
                                type="password"
                                class="mt-1 block w-full"
                                autocomplete="off"
                                :placeholder="telegram.bot_token_set ? $t('telegrambot.token_saved_placeholder') : $t('telegrambot.token_example_placeholder')"
                            />
                            <InputError :message="form.errors.bot_token" class="mt-2" />
                            <p class="mt-1 text-xs text-slate-400" v-html="$t('telegrambot.token_hint')"></p>
                        </div>

                        <div>
                            <InputLabel for="chat_id" :value="$t('telegrambot.chat_id')" />
                            <textarea
                                id="chat_id"
                                v-model="form.chat_id"
                                rows="2"
                                class="mt-1 block w-full rounded-lg border-white/10 bg-slate-900/60 text-slate-100 shadow-inner shadow-black/20 placeholder:text-slate-500 focus:border-cyan-500 focus:ring-cyan-500"
                                :placeholder="$t('telegrambot.chat_id_placeholder')"
                            ></textarea>
                            <InputError :message="form.errors.chat_id" class="mt-2" />
                            <p class="mt-1 text-xs text-slate-400" v-html="$t('telegrambot.chat_id_hint')"></p>
                        </div>

                        <div>
                            <InputLabel for="min_severity" :value="$t('telegrambot.min_severity')" />
                            <select
                                id="min_severity"
                                v-model="form.min_severity"
                                class="mt-1 block min-h-11 w-full rounded-md border border-white/10 bg-slate-900/60 py-2.5 px-3 text-sm text-white shadow-sm focus:border-cyan-500 focus:ring-cyan-500"
                            >
                                <option v-for="opt in severityOptions" :key="opt.value" :value="opt.value">{{ $t(`alarms.sev_opt_${opt.value}`) }}</option>
                            </select>
                            <InputError :message="form.errors.min_severity" class="mt-2" />
                            <p class="mt-1 text-xs text-slate-400">{{ $t('telegrambot.min_severity_hint') }}</p>
                        </div>

                        <div>
                            <InputLabel :value="$t('telegrambot.triggers')" />
                            <div class="mt-1 space-y-3 rounded-lg border border-white/10 bg-slate-950/40 px-4 py-3">
                                <label class="flex items-start gap-3">
                                    <Checkbox v-model:checked="form.notify_on_raise" class="mt-0.5" />
                                    <span>
                                        <span class="block text-sm font-medium text-white">{{ $t('telegrambot.on_raise') }}</span>
                                        <span class="block text-xs text-slate-400">{{ $t('telegrambot.on_raise_hint') }}</span>
                                    </span>
                                </label>
                                <label class="flex items-start gap-3">
                                    <Checkbox v-model:checked="form.notify_on_clear" class="mt-0.5" />
                                    <span>
                                        <span class="block text-sm font-medium text-white">{{ $t('telegrambot.on_clear') }}</span>
                                        <span class="block text-xs text-slate-400">{{ $t('telegrambot.on_clear_hint') }}</span>
                                    </span>
                                </label>
                            </div>
                        </div>

                        <div class="lg:col-span-2">
                            <div class="flex items-center justify-between gap-3">
                                <InputLabel :value="$t('telegrambot.types_label')" />
                                <button
                                    type="button"
                                    class="text-xs font-medium text-cyan-300 hover:text-cyan-200"
                                    @click="toggleAllTypes"
                                >
                                    {{ allTypesSelected ? $t('telegrambot.clear_all') : $t('telegrambot.select_all') }}
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
                                    <span class="text-sm text-slate-200">{{ alarmTypeLabel(t, opt.value) }}</span>
                                </label>
                            </div>
                            <p class="mt-1 text-xs text-slate-400">
                                {{ $t('telegrambot.types_hint_admin') }}
                                <span v-if="form.notify_types.length === 0" class="text-amber-400">{{ $t('telegrambot.types_none') }}</span>
                            </p>
                            <InputError :message="form.errors.notify_types" class="mt-2" />
                        </div>

                        <div class="rounded-lg border border-white/10 bg-slate-950/40 px-4 py-4 lg:col-span-2">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <h4 class="text-sm font-semibold text-white">{{ $t('telegrambot.webhook_title') }}</h4>
                                    <p class="mt-0.5 text-xs text-slate-400">{{ $t('telegrambot.webhook_sub_admin') }}</p>
                                </div>
                                <span
                                    class="shrink-0 rounded-full px-2.5 py-1 text-xs font-medium"
                                    :class="telegram.webhook_set ? 'bg-emerald-500/15 text-emerald-300' : 'bg-slate-500/15 text-slate-400'"
                                >
                                    {{ telegram.webhook_set ? $t('telegrambot.webhook_set') : $t('telegrambot.webhook_unset') }}
                                </span>
                            </div>

                            <label class="mt-4 flex items-start gap-3">
                                <Checkbox v-model:checked="form.commands_enabled" class="mt-0.5" />
                                <span>
                                    <span class="block text-sm font-medium text-white">{{ $t('telegrambot.commands_enable') }}</span>
                                    <span class="block text-xs text-slate-400">{{ $t('telegrambot.commands_hint_admin') }}</span>
                                </span>
                            </label>

                            <div class="mt-4 flex flex-wrap items-center gap-3">
                                <SecondaryButton type="button" :disabled="!telegram.bot_token_set || webhookBusy" @click="registerWebhook">
                                    {{ webhookBusy ? $t('common.processing') : (telegram.webhook_set ? $t('telegrambot.webhook_reregister') : $t('telegrambot.webhook_register')) }}
                                </SecondaryButton>
                                <SecondaryButton v-if="telegram.webhook_set" type="button" :disabled="webhookBusy" @click="deleteWebhook">
                                    {{ $t('telegrambot.webhook_delete') }}
                                </SecondaryButton>
                                <span v-if="!telegram.bot_token_set" class="text-xs text-slate-500">{{ $t('telegrambot.webhook_need_token') }}</span>
                            </div>

                            <div class="mt-4 rounded-md border border-white/5 bg-slate-900/40 px-3 py-2 text-xs text-slate-400">
                                <p class="font-medium text-slate-300">{{ $t('telegrambot.commands_available') }}</p>
                                <p class="mt-1 font-mono leading-relaxed">/status · /olt [nama|id] · /alarm · /onu &lt;serial&gt; · /prov · /id · /ping</p>
                            </div>
                        </div>

                        <div v-if="lastSent || telegram.last_error" class="rounded-lg border border-white/10 bg-slate-950/40 px-4 py-3 text-xs lg:col-span-2">
                            <p v-if="lastSent" class="text-slate-400">{{ $t('telegrambot.last_sent') }} <span class="text-slate-200">{{ lastSent }}</span></p>
                            <p v-if="telegram.last_error" class="mt-1 text-red-400">{{ $t('telegrambot.last_error') }} {{ telegram.last_error }}</p>
                        </div>

                        <div class="flex flex-wrap items-center gap-3 border-t border-white/10 pt-5 lg:col-span-2">
                            <PrimaryButton :disabled="form.processing">{{ $t('common.save') }}</PrimaryButton>
                            <SecondaryButton type="button" :disabled="!canTest || testing" @click="sendTest">
                                <Send class="mr-2 h-4 w-4" />
                                {{ testing ? $t('telegrambot.sending') : $t('telegrambot.send_test') }}
                            </SecondaryButton>
                            <span v-if="!canTest" class="text-xs text-slate-500">{{ $t('telegrambot.need_token_chat') }}</span>
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
                                <h3 class="text-base font-semibold text-white">{{ $t('settings.fcm_title') }}</h3>
                                <p class="text-sm text-slate-400">{{ $t('settings.fcm_sub') }}</p>
                            </div>
                            <span
                                class="hidden shrink-0 rounded-full px-2.5 py-1 text-xs font-medium sm:inline"
                                :class="fcm.device_count > 0 ? 'bg-cyan-500/15 text-cyan-300' : 'bg-slate-500/15 text-slate-400'"
                            >
                                {{ $t('settings.fcm_devices', { n: fcm.device_count }) }}
                            </span>
                        </div>

                        <div class="grid gap-x-6 gap-y-6 p-5 sm:p-6 lg:grid-cols-2">
                            <div v-if="!fcm.credentials_ready" class="flex items-start gap-3 rounded-lg border border-amber-500/40 bg-amber-500/10 px-4 py-3 lg:col-span-2">
                                <AlertTriangle class="mt-0.5 h-5 w-5 flex-shrink-0 text-amber-300" />
                                <p class="text-sm text-amber-200" v-html="$t('settings.fcm_creds')"></p>
                            </div>

                            <label class="flex items-center justify-between gap-4 rounded-lg border border-white/10 bg-slate-950/40 px-4 py-3 lg:col-span-2">
                                <span>
                                    <span class="block text-sm font-medium text-white">{{ $t('settings.fcm_enable') }}</span>
                                    <span class="block text-xs text-slate-400">{{ $t('settings.fcm_enable_hint') }}</span>
                                </span>
                                <Checkbox v-model:checked="fcmForm.enabled" class="h-5 w-5" />
                            </label>

                            <div>
                                <InputLabel for="fcm_min_severity" :value="$t('telegrambot.min_severity')" />
                                <select
                                    id="fcm_min_severity"
                                    v-model="fcmForm.min_severity"
                                    class="mt-1 block min-h-11 w-full rounded-md border border-white/10 bg-slate-900/60 py-2.5 px-3 text-sm text-white shadow-sm focus:border-cyan-500 focus:ring-cyan-500"
                                >
                                    <option v-for="opt in severityOptions" :key="opt.value" :value="opt.value">{{ $t(`alarms.sev_opt_${opt.value}`) }}</option>
                                </select>
                                <InputError :message="fcmForm.errors.min_severity" class="mt-2" />
                                <p class="mt-1 text-xs text-slate-400">{{ $t('telegrambot.min_severity_hint') }}</p>
                            </div>

                            <div>
                                <InputLabel :value="$t('telegrambot.triggers')" />
                                <div class="mt-1 space-y-3 rounded-lg border border-white/10 bg-slate-950/40 px-4 py-3">
                                    <label class="flex items-start gap-3">
                                        <Checkbox v-model:checked="fcmForm.notify_on_raise" class="mt-0.5" />
                                        <span>
                                            <span class="block text-sm font-medium text-white">{{ $t('telegrambot.on_raise') }}</span>
                                            <span class="block text-xs text-slate-400">{{ $t('settings.fcm_on_raise_hint') }}</span>
                                        </span>
                                    </label>
                                    <label class="flex items-start gap-3">
                                        <Checkbox v-model:checked="fcmForm.notify_on_clear" class="mt-0.5" />
                                        <span>
                                            <span class="block text-sm font-medium text-white">{{ $t('telegrambot.on_clear') }}</span>
                                            <span class="block text-xs text-slate-400">{{ $t('settings.fcm_on_clear_hint') }}</span>
                                        </span>
                                    </label>
                                </div>
                            </div>

                            <div class="lg:col-span-2">
                                <div class="flex items-center justify-between gap-3">
                                    <InputLabel :value="$t('telegrambot.types_label')" />
                                    <button type="button" class="text-xs font-medium text-cyan-300 hover:text-cyan-200" @click="toggleAllFcmTypes">
                                        {{ allFcmTypesSelected ? $t('telegrambot.clear_all') : $t('telegrambot.select_all') }}
                                    </button>
                                </div>
                                <div class="mt-1 grid gap-2 rounded-lg border border-white/10 bg-slate-950/40 px-4 py-3 sm:grid-cols-2">
                                    <label
                                        v-for="opt in alarmTypeOptions"
                                        :key="opt.value"
                                        class="flex items-center gap-3 rounded-md px-2 py-1.5 transition-colors hover:bg-white/5"
                                    >
                                        <Checkbox :checked="isFcmTypeSelected(opt.value)" class="h-4 w-4" @update:checked="toggleFcmType(opt.value)" />
                                        <span class="text-sm text-slate-200">{{ alarmTypeLabel(t, opt.value) }}</span>
                                    </label>
                                </div>
                                <p class="mt-1 text-xs text-slate-400">
                                    {{ $t('settings.fcm_types_hint') }}
                                    <span v-if="fcmForm.notify_types.length === 0" class="text-amber-400">{{ $t('settings.fcm_types_none') }}</span>
                                </p>
                                <InputError :message="fcmForm.errors.notify_types" class="mt-2" />
                            </div>

                            <div v-if="fcmLastSent || fcm.last_error" class="rounded-lg border border-white/10 bg-slate-950/40 px-4 py-3 text-xs lg:col-span-2">
                                <p v-if="fcmLastSent" class="text-slate-400">{{ $t('telegrambot.last_sent') }} <span class="text-slate-200">{{ fcmLastSent }}</span></p>
                                <p v-if="fcm.last_error" class="mt-1 text-red-400">{{ $t('telegrambot.last_error') }} {{ fcm.last_error }}</p>
                            </div>

                            <div class="flex flex-wrap items-center gap-3 border-t border-white/10 pt-5 lg:col-span-2">
                                <PrimaryButton :disabled="fcmForm.processing">{{ $t('common.save') }}</PrimaryButton>
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
                                <h3 class="text-base font-semibold text-white">{{ $t('settings.fcm_manual_title') }}</h3>
                                <p class="text-sm text-slate-400">{{ $t('settings.fcm_manual_sub', { n: fcm.device_count }) }}</p>
                            </div>
                        </div>

                        <div class="grid gap-x-6 gap-y-5 p-5 sm:p-6">
                            <div>
                                <InputLabel for="fcm_title" :value="$t('settings.fcm_field_title')" />
                                <TextInput id="fcm_title" v-model="fcmSendForm.title" type="text" class="mt-1 block w-full" maxlength="120" :placeholder="$t('settings.fcm_title_placeholder')" />
                                <InputError :message="fcmSendForm.errors.title" class="mt-2" />
                            </div>
                            <div>
                                <InputLabel for="fcm_body" :value="$t('settings.fcm_body')" />
                                <textarea
                                    id="fcm_body"
                                    v-model="fcmSendForm.body"
                                    rows="3"
                                    maxlength="500"
                                    class="mt-1 block w-full rounded-lg border-white/10 bg-slate-900/60 text-slate-100 shadow-inner shadow-black/20 placeholder:text-slate-500 focus:border-cyan-500 focus:ring-cyan-500"
                                    :placeholder="$t('settings.fcm_body_placeholder')"
                                ></textarea>
                                <InputError :message="fcmSendForm.errors.body" class="mt-2" />
                            </div>
                            <div class="flex flex-wrap items-center gap-3">
                                <PrimaryButton :disabled="fcmSendForm.processing || !fcm.credentials_ready || fcm.device_count === 0">
                                    <Bell class="mr-2 h-4 w-4" />
                                    {{ fcmSendForm.processing ? $t('telegrambot.sending') : $t('settings.fcm_send_all') }}
                                </PrimaryButton>
                                <span v-if="fcm.device_count === 0" class="text-xs text-slate-500">{{ $t('settings.fcm_no_devices') }}</span>
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
                            <h4 class="text-sm font-semibold text-white">{{ $t('settings.api_disabled_title') }}</h4>
                            <p class="mt-0.5 text-xs text-amber-200/90" v-html="$t('settings.api_disabled_note')"></p>
                        </div>
                    </div>

                    <!-- Akses API + URL -->
                    <div class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 backdrop-blur-xl shadow-lg shadow-black/30">
                        <div class="flex items-center gap-3 border-b border-white/10 px-5 py-4 sm:px-6">
                            <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-cyan-500/20 ring-1 ring-cyan-500/30">
                                <KeyRound class="h-5 w-5 text-cyan-300" />
                            </div>
                            <div>
                                <h3 class="text-base font-semibold text-white">{{ $t('settings.api_access_title') }}</h3>
                                <p class="text-sm text-slate-400">{{ $t('settings.api_access_sub') }}</p>
                            </div>
                        </div>

                        <div class="space-y-4 p-5 sm:p-6">
                            <div>
                                <InputLabel :value="$t('settings.api_base')" />
                                <div class="mt-1 flex items-stretch gap-2">
                                    <input :value="api.base_url" readonly class="block w-full rounded-lg border-white/10 bg-slate-950/60 font-mono text-sm text-slate-200 focus:border-cyan-500 focus:ring-cyan-500" />
                                    <SecondaryButton type="button" @click="copyText(api.base_url, 'base')">
                                        <component :is="copied === 'base' ? Check : Copy" class="h-4 w-4" :class="copied === 'base' ? 'text-emerald-400' : ''" />
                                    </SecondaryButton>
                                </div>
                                <p class="mt-1 text-xs text-slate-400" v-html="$t('settings.api_base_hint', { url: api.base_url })"></p>
                            </div>

                            <div>
                                <InputLabel :value="$t('settings.api_public')" />
                                <div class="mt-1 flex items-stretch gap-2">
                                    <input :value="api.public_status_url" readonly class="block w-full rounded-lg border-white/10 bg-slate-950/60 font-mono text-sm text-slate-200 focus:border-cyan-500 focus:ring-cyan-500" />
                                    <SecondaryButton type="button" @click="copyText(api.public_status_url, 'pub')">
                                        <component :is="copied === 'pub' ? Check : Copy" class="h-4 w-4" :class="copied === 'pub' ? 'text-emerald-400' : ''" />
                                    </SecondaryButton>
                                </div>
                                <p class="mt-1 text-xs text-slate-400" v-html="$t('settings.api_public_hint')"></p>
                            </div>
                        </div>
                    </div>

                    <!-- Token baru dibuat (tampil sekali) -->
                    <div v-if="newToken" class="overflow-hidden rounded-lg border border-emerald-500/40 bg-emerald-500/10 shadow-lg shadow-black/30">
                        <div class="flex items-start gap-3 px-5 py-4 sm:px-6">
                            <CheckCircle2 class="mt-0.5 h-5 w-5 flex-shrink-0 text-emerald-300" />
                            <div class="min-w-0 flex-1">
                                <h4 class="text-sm font-semibold text-white">{{ $t('settings.api_new_token_title') }}</h4>
                                <p class="mt-0.5 text-xs text-emerald-200/80" v-html="$t('settings.api_new_token_note')"></p>
                                <div class="mt-3 flex items-stretch gap-2">
                                    <input :value="newToken" readonly class="block w-full rounded-lg border-emerald-500/30 bg-slate-950/70 font-mono text-xs text-emerald-200 focus:border-emerald-500 focus:ring-emerald-500" @focus="$event.target.select()" />
                                    <SecondaryButton type="button" @click="copyText(newToken, 'new')">
                                        <component :is="copied === 'new' ? Check : Copy" class="mr-2 h-4 w-4" :class="copied === 'new' ? 'text-emerald-400' : ''" />
                                        {{ copied === 'new' ? $t('settings.copied') : $t('settings.copy') }}
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
                                <h3 class="text-base font-semibold text-white">{{ $t('settings.api_personal_title') }}</h3>
                                <p class="text-sm text-slate-400">{{ $t('settings.api_personal_sub') }}</p>
                            </div>
                        </div>

                        <div class="p-5 sm:p-6">
                            <form class="flex flex-col gap-3 sm:flex-row sm:items-end" @submit.prevent="createToken">
                                <div class="flex-1">
                                    <InputLabel for="token_name" :value="$t('settings.token_name')" />
                                    <TextInput
                                        id="token_name"
                                        v-model="tokenForm.name"
                                        type="text"
                                        class="mt-1 block w-full"
                                        maxlength="60"
                                        :placeholder="$t('settings.token_name_placeholder')"
                                    />
                                    <InputError :message="tokenForm.errors.name" class="mt-2" />
                                </div>
                                <PrimaryButton :disabled="tokenForm.processing || !tokenForm.name.trim() || !api.enabled" class="shrink-0">
                                    <Plus class="mr-2 h-4 w-4" />
                                    {{ $t('settings.create_token') }}
                                </PrimaryButton>
                            </form>
                            <p v-if="!api.enabled" class="mt-2 text-xs text-amber-300/90">{{ $t('settings.api_enable_first') }}</p>

                            <!-- Daftar token -->
                            <div class="mt-6">
                                <div v-if="api.tokens.length === 0" class="rounded-lg border border-dashed border-white/10 bg-slate-950/40 px-4 py-8 text-center">
                                    <KeyRound class="mx-auto h-7 w-7 text-slate-600" />
                                    <p class="mt-2 text-sm text-slate-400">{{ $t('settings.tokens_empty') }}</p>
                                </div>

                                <!-- Desktop: tabel -->
                                <div v-else class="hidden overflow-hidden rounded-lg border border-white/10 sm:block">
                                    <table class="min-w-full divide-y divide-white/10 text-sm">
                                        <thead class="bg-slate-950/40 text-xs uppercase tracking-wide text-slate-500">
                                            <tr>
                                                <th class="px-4 py-2.5 text-left font-medium">{{ $t('settings.col_name') }}</th>
                                                <th class="px-4 py-2.5 text-left font-medium">{{ $t('settings.col_created') }}</th>
                                                <th class="px-4 py-2.5 text-left font-medium">{{ $t('settings.col_last_used') }}</th>
                                                <th class="px-4 py-2.5 text-right font-medium">{{ $t('common.actions') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-white/5">
                                            <tr v-for="t in api.tokens" :key="t.id" class="hover:bg-white/5">
                                                <td class="px-4 py-3 font-medium text-white">{{ t.name }}</td>
                                                <td class="px-4 py-3 text-slate-400">{{ formatDateTime(t.created_at) }}</td>
                                                <td class="px-4 py-3 text-slate-400">{{ t.last_used_at ? formatDateTime(t.last_used_at) : $t('settings.never') }}</td>
                                                <td class="px-4 py-3 text-right">
                                                    <button type="button" class="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-xs font-medium text-red-300 transition-colors hover:bg-red-500/10" @click="revokeToken(t.id)">
                                                        <Trash2 class="h-3.5 w-3.5" />
                                                        {{ $t('settings.revoke') }}
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
                                                {{ $t('settings.revoke') }}
                                            </button>
                                        </div>
                                        <dl class="mt-2 space-y-1 text-xs text-slate-400">
                                            <div class="flex justify-between gap-3"><dt>{{ $t('settings.col_created') }}</dt><dd class="text-slate-300">{{ formatDateTime(t.created_at) }}</dd></div>
                                            <div class="flex justify-between gap-3"><dt>Terakhir dipakai</dt><dd class="text-slate-300">{{ t.last_used_at ? formatDateTime(t.last_used_at) : $t('settings.never') }}</dd></div>
                                        </dl>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-5 flex items-start gap-2 rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-3">
                                <AlertTriangle class="mt-0.5 h-4 w-4 flex-shrink-0 text-amber-300" />
                                <p class="text-xs text-amber-200/90" v-html="$t('settings.token_warning')"></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
