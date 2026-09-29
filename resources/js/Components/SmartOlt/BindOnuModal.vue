<script setup>
import DangerButton from '@/Components/DangerButton.vue';
import InputError from '@/Components/InputError.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { formatDateTime } from '@/lib/datetime';
import { phaseStateLabel } from '@/lib/onu';
import { useForm } from '@inertiajs/vue3';
import { RefreshCw, Replace, Search, TriangleAlert } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n({ useScope: 'global' });

// "Bind ONU" gaya NetNumen: SN ONU unconfigured dipasang ke slot ONU lama di port yang sama
// (`registration-method sn`), jadi ONU pengganti mewarisi seluruh config tanpa register ulang.
const props = defineProps({
    show: { type: Boolean, default: false },
    olt: { type: Object, required: true },
    // Baris ONU unconfigured: serial_number, slot, port, model (opsional).
    onu: { type: Object, default: null },
});

const emit = defineEmits(['close']);

// Salinan baris ONU saat modal dibuka: `onu` dari induk sudah null ketika modal menutup,
// dan isi modal tak boleh hilang di tengah transisi keluar.
const target = ref(null);
// pick → review
const phase = ref('pick');
const loading = ref(false);
const loadError = ref('');
const candidates = ref([]);
const live = ref(true);
const refreshedAt = ref(null);
const query = ref('');
const selectedId = ref(null);
const selected = computed(() => candidates.value.find((c) => c.onu_id === selectedId.value) ?? null);

const form = useForm({ serial_number: '', save_config: true });

const load = async () => {
    if (!target.value) return;
    loading.value = true;
    loadError.value = '';
    try {
        const { data } = await window.axios.get(
            route('smartolt.onu.replace-candidates', [props.olt.id, target.value.slot, target.value.port]),
        );
        candidates.value = data.onus ?? [];
        live.value = Boolean(data.live);
        refreshedAt.value = data.refreshed_at;
    } catch (e) {
        candidates.value = [];
        loadError.value = e.response?.data?.message || e.message;
    } finally {
        loading.value = false;
    }
};

watch(() => props.show, (open) => {
    if (!open) return;
    target.value = props.onu;
    phase.value = 'pick';
    selectedId.value = null;
    query.value = '';
    form.reset();
    form.clearErrors();
    load();
});

const filtered = computed(() => {
    const q = query.value.trim().toLowerCase();
    if (!q) return candidates.value;

    return candidates.value.filter((c) => [String(c.onu_id), c.customer_name, c.serial_number, c.type_name]
        .some((value) => String(value ?? '').toLowerCase().includes(q)));
});

const statusLabel = (c) => {
    if (c.online) return t('common.online');
    const label = phaseStateLabel(c.phase_state);

    return label === '—' ? t('common.offline') : label;
};

// Pratinjau baris CLI — sama dengan ZteRemoteOnuService::replaceSerial (+ `write` terpisah).
const script = computed(() => {
    if (!selected.value || !target.value) return '';
    const lines = [
        'conf t',
        `interface ${selected.value.interface}`,
        `registration-method sn ${target.value.serial_number}`,
        'exit',
    ];
    if (form.save_config) lines.push('write');

    return lines.join('\n');
});

const submit = () => {
    form.serial_number = target.value.serial_number;
    form.post(route('smartolt.onu.replace', [props.olt.id, target.value.slot, target.value.port, selected.value.onu_id]), {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
};
</script>

<template>
    <Modal :show="show" max-width="2xl" @close="emit('close')">
        <div v-if="target" class="p-6">
            <div class="flex items-center gap-2">
                <Replace class="h-5 w-5 text-cyan-400" />
                <h3 class="text-base font-semibold text-white">{{ $t('bindonu.title') }}</h3>
            </div>
            <p class="mt-1 text-sm text-slate-400">
                {{ $t('bindonu.subtitle', { sn: target.serial_number, slot: target.slot, port: target.port }) }}
            </p>

            <!-- Fase 1: pilih ONU lama -->
            <template v-if="phase === 'pick'">
                <p class="mt-4 text-sm text-slate-300">{{ $t('bindonu.pick_hint') }}</p>

                <div class="mt-3 flex gap-2">
                    <div class="relative flex-1">
                        <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" />
                        <TextInput
                            v-model="query"
                            type="search"
                            class="block w-full pl-9"
                            :placeholder="$t('bindonu.search_placeholder')"
                            :aria-label="$t('bindonu.search_placeholder')"
                        />
                    </div>
                    <SecondaryButton type="button" :disabled="loading" @click="load">
                        <RefreshCw class="mr-2 h-4 w-4" :class="{ 'animate-spin': loading }" />
                        {{ $t('bindonu.reload') }}
                    </SecondaryButton>
                </div>

                <div v-if="loading" class="mt-4 flex items-center justify-center gap-2 rounded-lg border border-white/10 bg-canvas-3/40 px-4 py-8 text-sm text-slate-400">
                    <RefreshCw class="h-4 w-4 animate-spin" />
                    {{ $t('bindonu.loading') }}
                </div>

                <p v-else-if="loadError" class="mt-4 rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2.5 text-xs text-rose-300">
                    {{ loadError }}
                </p>

                <template v-else>
                    <p v-if="!live" class="mt-4 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2.5 text-xs text-amber-200">
                        {{ $t('bindonu.stale', { time: formatDateTime(refreshedAt) }) }}
                    </p>

                    <p v-if="filtered.length === 0" class="mt-4 px-4 py-8 text-center text-sm text-slate-500">
                        {{ $t('bindonu.empty') }}
                    </p>

                    <ul v-else class="mt-3 max-h-[45vh] space-y-2 overflow-y-auto pr-1" role="radiogroup" :aria-label="$t('bindonu.pick_hint')">
                        <li v-for="c in filtered" :key="c.onu_id">
                            <label
                                class="flex min-h-11 cursor-pointer items-center gap-3 rounded-lg border px-3 py-2.5 transition-colors has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-cyan-400/60"
                                :class="selectedId === c.onu_id
                                    ? 'border-cyan-500/50 bg-cyan-500/10'
                                    : c.online
                                        ? 'border-white/10 bg-canvas-3/40 hover:border-white/20'
                                        : 'border-rose-500/25 bg-rose-500/5 hover:border-rose-500/40'"
                            >
                                <input v-model="selectedId" type="radio" name="bind-onu-target" :value="c.onu_id" class="sr-only" />
                                <span class="w-12 shrink-0 font-mono text-xs text-slate-400">#{{ c.onu_id }}</span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-semibold text-slate-100">{{ c.customer_name || c.interface }}</span>
                                    <span class="block truncate font-mono text-xs text-slate-400">{{ c.serial_number || '—' }} · {{ c.type_name || '—' }}</span>
                                </span>
                                <span class="shrink-0" :class="c.online ? 'kv-pill-success' : 'kv-pill-danger'">{{ statusLabel(c) }}</span>
                            </label>
                        </li>
                    </ul>
                </template>

                <div class="mt-6 grid gap-2 sm:flex sm:justify-end">
                    <SecondaryButton type="button" @click="emit('close')">{{ $t('common.cancel') }}</SecondaryButton>
                    <PrimaryButton type="button" :disabled="!selected" @click="phase = 'review'">{{ $t('bindonu.next') }}</PrimaryButton>
                </div>
            </template>

            <!-- Fase 2: tinjau & jalankan -->
            <template v-else>
                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    <div class="rounded-lg border border-white/10 bg-canvas-3/40 p-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $t('bindonu.old_onu') }}</p>
                        <p class="mt-1 truncate text-sm font-semibold text-slate-100">{{ selected.customer_name || '—' }}</p>
                        <p class="font-mono text-xs text-slate-400">{{ selected.interface }}</p>
                        <p class="font-mono text-xs text-slate-400">{{ selected.serial_number || '—' }}</p>
                        <p class="text-xs text-slate-400">{{ $t('common.type') }}: {{ selected.type_name || '—' }}</p>
                    </div>
                    <div class="rounded-lg border border-cyan-500/30 bg-cyan-500/5 p-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $t('bindonu.new_onu') }}</p>
                        <p class="mt-1 font-mono text-sm font-semibold text-cyan-300">{{ target.serial_number }}</p>
                        <p class="text-xs text-slate-400">{{ target.model || $t('bindonu.model_unknown') }}</p>
                    </div>
                </div>

                <div v-if="selected.online" class="mt-4 flex gap-2 rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2.5 text-xs text-rose-200">
                    <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" />
                    <span>{{ $t('bindonu.warn_online') }}</span>
                </div>

                <ul class="mt-4 list-disc space-y-1 rounded-lg border border-amber-500/30 bg-amber-500/10 py-2.5 pl-7 pr-3 text-xs text-amber-200">
                    <li>{{ $t('bindonu.note_same_type', { type: selected.type_name || '—' }) }}</li>
                    <li>{{ $t('bindonu.note_onu_side') }}</li>
                    <li>{{ $t('bindonu.note_acs') }}</li>
                </ul>

                <div class="mt-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $t('bindonu.command') }}</p>
                    <pre data-theme="dark" class="kv-terminal mt-1 overflow-x-auto rounded-md bg-slate-950/70 px-3 py-2 font-mono text-xs leading-relaxed text-emerald-300/90">{{ script }}</pre>
                </div>

                <label class="mt-4 flex items-start gap-2.5 text-sm text-slate-300">
                    <input v-model="form.save_config" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-white/10 text-cyan-400 focus:ring-cyan-500" />
                    <span>
                        {{ $t('bindonu.save_config') }}
                        <span class="text-slate-500">{{ $t('bindonu.save_config_hint') }}</span>
                    </span>
                </label>

                <InputError :message="form.errors.serial_number" class="mt-2" />

                <div class="mt-6 grid gap-2 sm:flex sm:justify-end">
                    <SecondaryButton type="button" :disabled="form.processing" @click="phase = 'pick'">{{ $t('common.back') }}</SecondaryButton>
                    <component :is="selected.online ? DangerButton : PrimaryButton" type="button" :disabled="form.processing" @click="submit">
                        <RefreshCw v-if="form.processing" class="mr-2 h-4 w-4 animate-spin" />
                        {{ form.processing ? $t('bindonu.processing') : $t('bindonu.submit') }}
                    </component>
                </div>
            </template>
        </div>
    </Modal>
</template>
