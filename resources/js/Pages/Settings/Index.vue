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
import { AlertTriangle, CheckCircle2, Send } from '@lucide/vue';
import { computed, ref } from 'vue';

const props = defineProps({
    telegram: { type: Object, required: true },
    severityOptions: { type: Array, default: () => [] },
});

const page = usePage();
const flash = computed(() => page.props.flash ?? {});

const form = useForm({
    enabled: props.telegram.enabled,
    bot_token: '',
    chat_id: props.telegram.chat_id ?? '',
    min_severity: props.telegram.min_severity,
    notify_on_raise: props.telegram.notify_on_raise,
    notify_on_clear: props.telegram.notify_on_clear,
});

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
            <div class="mx-auto w-full max-w-3xl space-y-6 px-4 sm:px-6 lg:px-8">
                <div v-if="flash.success" class="flex items-center gap-3 rounded-lg border border-emerald-500/30 bg-emerald-500/15 px-4 py-3 text-sm text-emerald-300">
                    <CheckCircle2 class="h-5 w-5 flex-shrink-0" />
                    {{ flash.success }}
                </div>
                <div v-if="flash.error" class="flex items-center gap-3 rounded-lg border border-red-500/30 bg-red-500/15 px-4 py-3 text-sm text-red-300">
                    <AlertTriangle class="h-5 w-5 flex-shrink-0" />
                    {{ flash.error }}
                </div>

                <form class="overflow-hidden rounded-lg border border-white/10 bg-slate-900/40 backdrop-blur-xl shadow-lg shadow-black/30" @submit.prevent="submit">
                    <div class="flex items-center gap-3 border-b border-white/10 px-5 py-4 sm:px-6">
                        <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-cyan-500/20 ring-1 ring-cyan-500/30">
                            <Send class="h-5 w-5 text-cyan-300" />
                        </div>
                        <div>
                            <h3 class="text-base font-semibold text-white">Notifikasi Telegram</h3>
                            <p class="text-sm text-slate-400">Kirim alarm OLT/ONU terbaru ke bot atau grup Telegram.</p>
                        </div>
                    </div>

                    <div class="space-y-6 p-5 sm:p-6">
                        <label class="flex items-center justify-between gap-4 rounded-lg border border-white/10 bg-slate-950/40 px-4 py-3">
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

                        <div class="space-y-3">
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

                        <div class="flex flex-wrap items-center gap-3 border-t border-white/10 pt-5">
                            <PrimaryButton :disabled="form.processing">Simpan</PrimaryButton>
                            <SecondaryButton type="button" :disabled="!canTest || testing" @click="sendTest">
                                <Send class="mr-2 h-4 w-4" />
                                {{ testing ? 'Mengirim…' : 'Kirim Tes' }}
                            </SecondaryButton>
                            <span v-if="!canTest" class="text-xs text-slate-500">Simpan token &amp; chat ID dulu untuk mengirim tes.</span>
                        </div>

                        <div v-if="lastSent || telegram.last_error" class="rounded-lg border border-white/10 bg-slate-950/40 px-4 py-3 text-xs">
                            <p v-if="lastSent" class="text-slate-400">Terakhir terkirim: <span class="text-slate-200">{{ lastSent }}</span></p>
                            <p v-if="telegram.last_error" class="mt-1 text-red-400">Galat terakhir: {{ telegram.last_error }}</p>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
