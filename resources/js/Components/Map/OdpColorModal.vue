<script setup>
// Pemilih warna pin ODP — dipakai kartu detail ODP di peta (Components/Map/OdpDetailCard)
// dan halaman pengelolaan ODP (Pages/Odp/Index).
//
// Bawaannya mewarnai SEMUA ODP di satu PON port (warna = penanda kelompok port di peta);
// saklarnya bisa dimatikan untuk mewarnai satu ODP saja. ODP yang belum punya slot/port
// (belum ada ONU) selalu diwarnai sendiri.
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { DEFAULT_ODP_COLOR, odpColor, textOn } from '@/lib/odpColors';
import { router } from '@inertiajs/vue3';
import { Check, Dices, RotateCcw } from '@lucide/vue';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    odp: { type: Object, required: true },
    // Palet dikirim server (App\Support\OdpColors) — jangan hardcode daftar warna di klien.
    palette: { type: Array, default: () => [] },
    // Jumlah ODP di PON port yang sama (termasuk ODP ini) untuk label saklar "se-port".
    portCount: { type: Number, default: 1 },
});

const emit = defineEmits(['close']);

const busy = ref(false);
const selected = ref(DEFAULT_ODP_COLOR);
const applyToPort = ref(true);

const hasPort = computed(() => props.odp?.slot != null && props.odp?.port != null);
const portLabel = computed(() => (hasPort.value ? `${props.odp.slot}/${props.odp.port}` : ''));
const badgeText = computed(() => textOn(selected.value));

// Sinkronkan state lokal tiap modal dibuka (bukan sekali saat mount) — kartu detail
// memakai ulang komponen ini untuk ODP yang berbeda-beda.
watch(
    () => props.show,
    (open) => {
        if (!open) return;
        selected.value = odpColor(props.odp);
        applyToPort.value = hasPort.value;
    },
    { immediate: true },
);

const submit = (payload) => {
    busy.value = true;
    router.post(
        route('map.odps.color', props.odp.id),
        { apply_to_port: hasPort.value && applyToPort.value, ...payload },
        {
            preserveScroll: true,
            preserveState: true,
            // Prop `odps` ada di halaman Peta maupun halaman ODP; `flash` wajib ikut karena
            // `only` menyaring shared prop juga (kalau tidak, toast-nya hilang).
            only: ['odps', 'flash'],
            onFinish: () => {
                busy.value = false;
                emit('close');
            },
        },
    );
};

const save = () => submit({ color: selected.value });
const randomize = () => submit({ random: true });
const reset = () => submit({ color: null });
</script>

<template>
    <Modal :show="show" max-width="md" @close="emit('close')">
        <div class="p-6">
            <h3 class="text-lg font-semibold text-white">{{ $t('map.odp_color_title') }}</h3>
            <p class="mt-1 truncate text-xs text-slate-400">
                {{ odp.name }}<span v-if="hasPort"> · {{ $t('map.odp_port') }} {{ portLabel }}</span>
            </p>

            <!-- Pratinjau pin -->
            <div class="mt-4 flex items-center gap-3 rounded-xl border border-white/10 bg-white/5 px-3 py-2.5">
                <span class="relative inline-flex h-8 w-8 items-center justify-center rounded-full ring-2 ring-white/70" :style="{ background: selected }">
                    <span
                        class="absolute -right-1 -top-1 rounded-full px-1 text-[10px] font-bold leading-4"
                        :style="{ background: selected, color: badgeText, boxShadow: '0 0 0 1.5px rgba(255,255,255,0.7)' }"
                    >{{ odp.onus?.length ?? odp.onu_count ?? 0 }}</span>
                </span>
                <span class="font-mono text-xs uppercase text-slate-300">{{ selected }}</span>
            </div>

            <!-- Palet -->
            <div class="mt-4 grid grid-cols-8 gap-2">
                <button
                    v-for="hex in palette"
                    :key="hex"
                    type="button"
                    class="relative flex h-8 w-full items-center justify-center rounded-lg ring-1 ring-white/15 transition hover:scale-105"
                    :class="hex.toLowerCase() === selected.toLowerCase() ? 'ring-2 ring-white' : ''"
                    :style="{ background: hex }"
                    :title="hex"
                    @click="selected = hex"
                >
                    <Check v-if="hex.toLowerCase() === selected.toLowerCase()" class="h-4 w-4" :style="{ color: textOn(hex) }" />
                </button>
            </div>

            <!-- Warna bebas -->
            <label class="mt-3 flex items-center gap-2 text-xs text-slate-300">
                <input v-model="selected" type="color" class="h-8 w-12 cursor-pointer rounded-lg border border-white/15 bg-transparent p-0.5" />
                {{ $t('map.odp_color_custom') }}
            </label>

            <!-- Cakupan -->
            <label v-if="hasPort" class="mt-4 flex items-start gap-2 rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-xs text-slate-200">
                <input v-model="applyToPort" type="checkbox" class="mt-0.5 rounded border-white/20 bg-slate-900 text-cyan-500 focus:ring-cyan-500" />
                <span>
                    {{ $t('map.odp_color_apply_port', { port: portLabel, count: portCount }) }}
                    <span class="mt-0.5 block text-[11px] text-slate-400">{{ $t('map.odp_color_apply_port_hint') }}</span>
                </span>
            </label>
            <p v-else class="mt-4 rounded-xl border border-dashed border-white/10 px-3 py-2.5 text-[11px] text-slate-400">
                {{ $t('map.odp_color_no_port') }}
            </p>

            <div class="mt-6 flex flex-wrap items-center justify-between gap-2">
                <div class="flex gap-2">
                    <SecondaryButton :disabled="busy" @click="randomize">
                        <Dices class="mr-1.5 h-4 w-4" /> {{ $t('map.odp_color_random') }}
                    </SecondaryButton>
                    <SecondaryButton :disabled="busy" @click="reset">
                        <RotateCcw class="mr-1.5 h-4 w-4" /> {{ $t('map.odp_color_reset') }}
                    </SecondaryButton>
                </div>
                <div class="flex gap-2">
                    <SecondaryButton :disabled="busy" @click="emit('close')">{{ $t('common.cancel') }}</SecondaryButton>
                    <PrimaryButton :disabled="busy" @click="save">{{ $t('common.save') }}</PrimaryButton>
                </div>
            </div>
        </div>
    </Modal>
</template>
