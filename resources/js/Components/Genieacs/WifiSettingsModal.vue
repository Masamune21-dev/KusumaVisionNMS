<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { CheckCircle2, TriangleAlert, Wifi } from '@lucide/vue';
import axios from 'axios';
import { computed, ref, watch } from 'vue';

/**
 * Ubah nama (SSID) dan kata sandi WiFi satu ONU.
 *
 * SATU-SATUNYA panel di modul ini yang menulis ke perangkat pelanggan. Karena
 * itu: tidak ada aksi massal, perubahan dikirim hanya setelah tombol ditekan,
 * dan hasilnya dilaporkan apa adanya — termasuk saat ONU menolak.
 *
 * Dirender terus (tanpa v-if pada keadaan terbuka) karena Modal.vue membuka
 * dialog di dalam watcher `show`.
 */
const props = defineProps({
    show: { type: Boolean, default: false },
    oltId: { type: Number, default: 0 },
    slot: { type: Number, default: 0 },
    port: { type: Number, default: 0 },
    onuId: { type: Number, default: 0 },
    onuLabel: { type: String, default: '' },
});

const emit = defineEmits(['close', 'saved']);

const loading = ref(false);
const saving = ref(false);
const loadError = ref('');
const saveError = ref('');
const saved = ref(false);
const networks = ref([]);
const wlanIndex = ref(1);
const ssid = ref('');
const password = ref('');

const current = computed(() =>
    networks.value.find((n) => String(n.index) === String(wlanIndex.value)) ?? null,
);

const passwordTooShort = computed(() => password.value.length > 0 && password.value.length < 8);

// Nilai sekarang dibaca lewat endpoint yang sama dengan panel perangkat
// terhubung, jadi tidak ada permintaan baru ke ACS hanya untuk mengisi form.
const load = async () => {
    loading.value = true;
    loadError.value = '';

    try {
        const url = route('genieacs.onu.clients', {
            olt: props.oltId, slot: props.slot, port: props.port, onuId: props.onuId,
        });
        const { data } = await axios.get(url);
        networks.value = data.wifi_networks ?? [];

        const first = networks.value[0];
        if (first) {
            wlanIndex.value = Number(first.index) || 1;
        }
    } catch (e) {
        const code = e?.response?.data?.error;
        loadError.value = code === 'not_linked'
            ? 'acsclients.err_not_linked'
            : code === 'not_configured'
                ? 'acsclients.err_not_configured'
                : 'acsclients.err_unreachable';
    } finally {
        loading.value = false;
    }
};

// Pindah SSID → isi ulang form dari nilai yang sedang berlaku di ONU.
watch([current, () => props.show], () => {
    if (!props.show) return;

    ssid.value = current.value?.ssid && current.value.ssid !== 'N/A' ? current.value.ssid : '';
    password.value = current.value?.password && current.value.password !== 'N/A' ? current.value.password : '';
});

watch(() => props.show, (open) => {
    if (open && props.onuId) {
        networks.value = [];
        saveError.value = '';
        saved.value = false;
        load();
    }
});

const submit = async () => {
    saving.value = true;
    saveError.value = '';
    saved.value = false;

    try {
        const url = route('genieacs.onu.wifi', {
            olt: props.oltId, slot: props.slot, port: props.port, onuId: props.onuId,
        });
        await axios.post(url, {
            ssid: ssid.value,
            password: password.value,
            wlan_index: Number(wlanIndex.value),
            security_mode: current.value?.security && current.value.security !== 'N/A'
                ? current.value.security
                : 'WPA2PSK',
        });
        saved.value = true;
        emit('saved');
    } catch (e) {
        const body = e?.response?.data;
        // Pesan validasi Laravel ditampilkan apa adanya; sisanya diterjemahkan.
        saveError.value = body?.message && e?.response?.status === 422 && body?.errors
            ? body.message
            : body?.error === 'not_linked'
                ? 'acsclients.err_not_linked'
                : body?.error === 'not_configured'
                    ? 'acsclients.err_not_configured'
                    : 'acswifi.err_write';
    } finally {
        saving.value = false;
    }
};

const errorText = computed(() =>
    saveError.value.startsWith('acs') ? null : saveError.value,
);
</script>

<template>
    <Modal :show="show" max-width="lg" @close="emit('close')">
        <form class="p-6" @submit.prevent="submit">
            <div class="flex items-start gap-3 border-b border-white/10 pb-4">
                <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-cyan-500/20 ring-1 ring-cyan-500/30">
                    <Wifi class="h-5 w-5 text-cyan-300" />
                </div>
                <div class="min-w-0 flex-1">
                    <h3 class="text-base font-semibold text-white">{{ $t('acswifi.title') }}</h3>
                    <p class="truncate text-sm text-slate-400">{{ onuLabel }}</p>
                </div>
            </div>

            <div v-if="loading" class="py-10 text-center text-sm text-slate-400">
                {{ $t('acsclients.loading') }}
            </div>

            <div v-else-if="loadError" class="mt-4 flex items-start gap-3 rounded-lg border border-amber-500/20 bg-amber-500/5 px-4 py-3 text-sm text-amber-200">
                <TriangleAlert class="mt-0.5 h-4 w-4 flex-shrink-0" />
                <span>{{ $t(loadError) }}</span>
            </div>

            <div v-else class="mt-4 space-y-4">
                <div v-if="networks.length > 1">
                    <InputLabel for="wlan_index" :value="$t('acswifi.which_ssid')" />
                    <select id="wlan_index" v-model="wlanIndex" class="kv-filter-control mt-1 block w-full">
                        <option v-for="net in networks" :key="net.index" :value="Number(net.index)">
                            SSID {{ net.index }} — {{ net.ssid || '—' }}
                        </option>
                    </select>
                </div>

                <div>
                    <InputLabel for="acs_ssid" :value="$t('acswifi.ssid')" />
                    <TextInput id="acs_ssid" v-model="ssid" type="text" class="mt-1 block w-full" maxlength="32" autocomplete="off" />
                    <p class="mt-1 text-xs text-slate-400">{{ $t('acswifi.ssid_hint') }}</p>
                </div>

                <div>
                    <InputLabel for="acs_wifi_password" :value="$t('acswifi.password')" />
                    <TextInput id="acs_wifi_password" v-model="password" type="text" class="mt-1 block w-full font-mono" maxlength="63" autocomplete="off" />
                    <InputError v-if="passwordTooShort" :message="$t('acswifi.password_short')" class="mt-2" />
                    <p v-else class="mt-1 text-xs text-slate-400">{{ $t('acswifi.password_hint') }}</p>
                </div>

                <div v-if="saved" class="flex items-start gap-3 rounded-lg border border-emerald-500/20 bg-emerald-500/5 px-4 py-3 text-sm text-emerald-200">
                    <CheckCircle2 class="mt-0.5 h-4 w-4 flex-shrink-0" />
                    <span>{{ $t('acswifi.saved') }}</span>
                </div>

                <div v-else-if="saveError" class="flex items-start gap-3 rounded-lg border border-rose-500/20 bg-rose-500/5 px-4 py-3 text-sm text-rose-200">
                    <TriangleAlert class="mt-0.5 h-4 w-4 flex-shrink-0" />
                    <span>{{ errorText ?? $t(saveError) }}</span>
                </div>

                <div class="flex items-start gap-3 rounded-lg border border-white/10 bg-canvas-3/40 px-4 py-3 text-xs text-slate-400">
                    <TriangleAlert class="mt-0.5 h-4 w-4 flex-shrink-0 text-amber-300" />
                    <span>{{ $t('acswifi.note') }}</span>
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-3 border-t border-white/10 pt-4">
                <SecondaryButton type="button" @click="emit('close')">{{ $t('common.close') }}</SecondaryButton>
                <PrimaryButton :disabled="saving || loading || !!loadError || !ssid || password.length < 8">
                    {{ saving ? $t('acswifi.sending') : $t('acswifi.apply') }}
                </PrimaryButton>
            </div>
        </form>
    </Modal>
</template>
