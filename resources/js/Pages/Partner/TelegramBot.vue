<script setup>
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { formatDateTime } from '@/lib/datetime';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Send } from '@lucide/vue';
import { computed, ref } from 'vue';

const props = defineProps({
    bot: { type: Object, required: true },
    assignedOltCount: { type: Number, default: 0 },
    severityOptions: { type: Array, default: () => [] },
    alarmTypeOptions: { type: Array, default: () => [] },
});

const page = usePage();
const flash = computed(() => page.props.flash ?? {});

const form = useForm({
    enabled: props.bot.enabled,
    bot_token: '',
    chat_id: props.bot.chat_id ?? '',
    min_severity: props.bot.min_severity,
    notify_on_raise: props.bot.notify_on_raise,
    notify_on_clear: props.bot.notify_on_clear,
    notify_types: [...(props.bot.notify_types ?? [])],
    commands_enabled: props.bot.commands_enabled,
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
    form.put(route('partner.telegram.update'), {
        preserveScroll: true,
        onSuccess: () => form.reset('bot_token'),
    });
};

const testing = ref(false);
const canTest = computed(() => props.bot.bot_token_set && (props.bot.chat_id ?? '').trim() !== '');
const sendTest = () => {
    testing.value = true;
    router.post(route('partner.telegram.test'), {}, {
        preserveScroll: true,
        onFinish: () => { testing.value = false; },
    });
};

const webhookBusy = ref(false);
const registerWebhook = () => {
    webhookBusy.value = true;
    router.post(route('partner.telegram.webhook.register'), {}, {
        preserveScroll: true,
        onFinish: () => { webhookBusy.value = false; },
    });
};
const deleteWebhook = () => {
    webhookBusy.value = true;
    router.post(route('partner.telegram.webhook.delete'), {}, {
        preserveScroll: true,
        onFinish: () => { webhookBusy.value = false; },
    });
};

const lastSent = computed(() => (props.bot.last_sent_at ? formatDateTime(props.bot.last_sent_at) : null));
</script>

<template>
    <Head title="Bot Telegram Saya" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-lg font-semibold leading-tight text-white sm:text-xl">Bot Telegram Saya</h2>
        </template>

        <div class="min-h-[60vh] pt-5 pb-16 sm:pt-8">
            <div class="mx-auto w-full max-w-4xl px-4 sm:px-6 lg:px-8">

                <div v-if="flash.success" class="mb-4 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200">
                    {{ flash.success }}
                </div>
                <div v-if="flash.error" class="mb-4 rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-200">
                    {{ flash.error }}
                </div>

                <div class="mb-4 rounded-lg border border-white/10 bg-slate-950/40 px-4 py-3 text-sm text-slate-300">
                    Bot ini hanya menerima alarm & melayani perintah untuk
                    <span class="font-semibold text-cyan-300">{{ assignedOltCount }} OLT</span>
                    yang di-assign ke akun Anda oleh admin.
                </div>

                <form class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl" @submit.prevent="submit">
                    <div class="flex items-center gap-3 border-b border-white/10 px-5 py-4 sm:px-6">
                        <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-cyan-500/20 ring-1 ring-cyan-500/30">
                            <Send class="h-5 w-5 text-cyan-300" />
                        </div>
                        <div>
                            <h3 class="text-base font-semibold text-white">Notifikasi Telegram</h3>
                            <p class="text-sm text-slate-400">Kirim alarm OLT/ONU Anda ke bot atau grup Telegram sendiri.</p>
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
                                :placeholder="bot.bot_token_set ? '•••••••• (token tersimpan — kosongkan untuk mempertahankan)' : 'Contoh: 123456789:ABCdefGhIj…'"
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
                                class="mt-1 block min-h-11 w-full rounded-md border border-white/10 bg-slate-900/60 px-3 py-2.5 text-sm text-white shadow-sm focus:border-cyan-500 focus:ring-cyan-500"
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
                                <button type="button" class="text-xs font-medium text-cyan-300 hover:text-cyan-200" @click="toggleAllTypes">
                                    {{ allTypesSelected ? 'Kosongkan semua' : 'Pilih semua' }}
                                </button>
                            </div>
                            <div class="mt-1 grid gap-2 rounded-lg border border-white/10 bg-slate-950/40 px-4 py-3 sm:grid-cols-2">
                                <label
                                    v-for="opt in alarmTypeOptions"
                                    :key="opt.value"
                                    class="flex items-center gap-3 rounded-md px-2 py-1.5 transition-colors hover:bg-white/5"
                                >
                                    <Checkbox :checked="isTypeSelected(opt.value)" class="h-4 w-4" @update:checked="toggleType(opt.value)" />
                                    <span class="text-sm text-slate-200">{{ opt.label }}</span>
                                </label>
                            </div>
                            <p class="mt-1 text-xs text-slate-400">
                                Hanya jenis alarm yang dicentang yang dikirim ke Telegram.
                                <span v-if="form.notify_types.length === 0" class="text-amber-400">Tidak ada yang dicentang — semua notifikasi alarm dimatikan.</span>
                            </p>
                            <InputError :message="form.errors.notify_types" class="mt-2" />
                        </div>

                        <div class="rounded-lg border border-white/10 bg-slate-950/40 px-4 py-4 lg:col-span-2">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <h4 class="text-sm font-semibold text-white">Perintah Bot (Webhook)</h4>
                                    <p class="mt-0.5 text-xs text-slate-400">Bot membalas perintah dari chat ID terdaftar (baca-saja, hanya OLT Anda).</p>
                                </div>
                                <span
                                    class="shrink-0 rounded-full px-2.5 py-1 text-xs font-medium"
                                    :class="bot.webhook_set ? 'bg-emerald-500/15 text-emerald-300' : 'bg-slate-500/15 text-slate-400'"
                                >
                                    {{ bot.webhook_set ? 'Webhook terdaftar' : 'Belum terdaftar' }}
                                </span>
                            </div>

                            <label class="mt-4 flex items-start gap-3">
                                <Checkbox v-model:checked="form.commands_enabled" class="mt-0.5" />
                                <span>
                                    <span class="block text-sm font-medium text-white">Aktifkan perintah bot</span>
                                    <span class="block text-xs text-slate-400">Saat aktif &amp; webhook terdaftar, bot menjawab /status, /olt, /alarm, /onu.</span>
                                </span>
                            </label>

                            <div class="mt-4 flex flex-wrap items-center gap-3">
                                <SecondaryButton type="button" :disabled="!bot.bot_token_set || webhookBusy" @click="registerWebhook">
                                    {{ webhookBusy ? 'Memproses…' : (bot.webhook_set ? 'Daftar Ulang Webhook' : 'Daftarkan Webhook') }}
                                </SecondaryButton>
                                <SecondaryButton v-if="bot.webhook_set" type="button" :disabled="webhookBusy" @click="deleteWebhook">
                                    Hapus Webhook
                                </SecondaryButton>
                                <span v-if="!bot.bot_token_set" class="text-xs text-slate-500">Simpan bot token dulu sebelum mendaftarkan webhook.</span>
                            </div>

                            <div class="mt-4 rounded-md border border-white/5 bg-slate-900/40 px-3 py-2 text-xs text-slate-400">
                                <p class="font-medium text-slate-300">Perintah tersedia:</p>
                                <p class="mt-1 font-mono leading-relaxed">/status · /olt [nama|id] · /alarm · /onu &lt;serial&gt; · /los · /redaman · /id · /ping</p>
                            </div>
                        </div>

                        <div v-if="lastSent || bot.last_error" class="rounded-lg border border-white/10 bg-slate-950/40 px-4 py-3 text-xs lg:col-span-2">
                            <p v-if="lastSent" class="text-slate-400">Terakhir terkirim: <span class="text-slate-200">{{ lastSent }}</span></p>
                            <p v-if="bot.last_error" class="mt-1 text-red-400">Galat terakhir: {{ bot.last_error }}</p>
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
