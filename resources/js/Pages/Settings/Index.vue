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
import { AlertTriangle, Building2, CheckCircle2, Cpu, ImageUp, Info, Send, SlidersHorizontal, Trash2, Upload } from '@lucide/vue';
import { computed, onBeforeUnmount, ref } from 'vue';

const props = defineProps({
    general: { type: Object, required: true },
    appInfo: { type: Object, default: () => ({ description: '', owner: '', stack: [] }) },
    telegram: { type: Object, required: true },
    severityOptions: { type: Array, default: () => [] },
    alarmTypeOptions: { type: Array, default: () => [] },
});

const page = usePage();
const flash = computed(() => page.props.flash ?? {});

const tabs = [
    { key: 'general', label: 'Umum', icon: SlidersHorizontal },
    { key: 'telegram', label: 'Bot Telegram', icon: Send },
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
            </div>
        </div>
    </AuthenticatedLayout>
</template>
