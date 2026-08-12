<script setup>
import ConfirmModal from '@/Components/ConfirmModal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import OdpColorModal from '@/Components/Map/OdpColorModal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { odpColor } from '@/lib/odpColors';
import { router, useForm } from '@inertiajs/vue3';
import { Lock, LockOpen, MapPin, Palette, Pencil, Trash2, Wifi, WifiOff, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n({ useScope: 'global' });

const props = defineProps({
    odp: { type: Object, required: true },
    // Palet warna dari server (prop halaman `odp_color_palette`).
    palette: { type: Array, default: () => [] },
    // Jumlah ODP di PON port yang sama — label saklar "terapkan se-port" di modal warna.
    portCount: { type: Number, default: 1 },
});

const emit = defineEmits(['close']);

const { confirmState, confirm, handleConfirm, handleCancel } = useConfirm();
const busy = ref(false);

const onus = computed(() => props.odp.onus ?? []);
const onlineCount = computed(() => onus.value.filter((o) => o.online).length);
// Warna ODP mewarnai aksen kartu (titik judul, chip jumlah ONU, bingkai) supaya kartu
// dan pin-nya di peta terbaca sebagai satu kesatuan.
const color = computed(() => odpColor(props.odp));
const accentChip = computed(() => ({
    background: `${color.value}26`,
    color: color.value,
    boxShadow: `inset 0 0 0 1px ${color.value}55`,
}));
const colorOpen = ref(false);
const googleHref = computed(() => `https://www.google.com/maps?q=${props.odp.latitude},${props.odp.longitude}`);

// --- edit nama ODP ---
const editOpen = ref(false);
const editForm = useForm({ name: '', latitude: '', longitude: '', notes: '' });

const openEdit = () => {
    editForm.name = props.odp.name ?? '';
    editForm.latitude = props.odp.latitude;
    editForm.longitude = props.odp.longitude;
    editForm.notes = props.odp.notes ?? '';
    editForm.clearErrors();
    editOpen.value = true;
};

const submitEdit = () => {
    editForm.put(route('map.odps.update', props.odp.id), {
        preserveScroll: true,
        onSuccess: () => {
            editOpen.value = false;
        },
    });
};

// --- kunci / buka posisi pin ODP ---
// Sama seperti pin ONU: unlock → marker bisa digeser & koordinat tersimpan tiap dilepas.
const toggleLock = () => {
    busy.value = true;
    router.put(
        route('map.odps.update', props.odp.id),
        { locked: props.odp.locked === false },
        {
            preserveScroll: true,
            preserveState: true,
            // Cukup prop odps (+ flash toast) — lihat catatan yang sama di PinDetailCard.
            only: ['odps', 'flash'],
            onFinish: () => (busy.value = false),
        },
    );
};

// --- hapus ODP ---
const deleteOdp = async () => {
    const ok = await confirm({
        title: t('map.delete_odp_title'),
        message: t('map.delete_odp_msg'),
        confirmLabel: t('common.delete'),
        variant: 'danger',
    });
    if (!ok) return;
    busy.value = true;
    router.delete(route('map.odps.destroy', props.odp.id), {
        preserveScroll: true,
        onFinish: () => (busy.value = false),
        onSuccess: () => emit('close'),
    });
};
</script>

<template>
    <div
        class="flex flex-col gap-2.5 rounded-2xl border bg-slate-950/95 p-3.5 shadow-xl shadow-black/50 backdrop-blur-xl"
        :style="{ borderColor: `${color}40` }"
    >
        <!-- Header -->
        <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
                <div class="flex items-start gap-1.5">
                    <span class="mt-1 inline-block h-2.5 w-2.5 shrink-0 rounded-sm ring-1 ring-white/40" :style="{ background: color }"></span>
                    <h3 class="break-words text-sm font-semibold leading-snug text-white" :title="odp.name">{{ odp.name }}</h3>
                </div>
                <p class="mt-0.5 truncate text-[11px] text-slate-400">
                    {{ $t('map.odp_label') }} · {{ odp.olt_name }}<span v-if="odp.port != null"> · {{ $t('map.odp_port') }} {{ odp.slot }}/{{ odp.port }}</span>
                </p>
            </div>
            <button type="button" class="-mr-1 -mt-1 rounded-lg p-1 text-slate-400 transition hover:bg-white/10 hover:text-white" :title="$t('common.close')" @click="emit('close')">
                <X class="h-4 w-4" />
            </button>
        </div>

        <!-- Ringkasan -->
        <div class="flex flex-wrap items-center gap-1.5 text-[11px]">
            <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-semibold" :style="accentChip">
                {{ $t('map.odp_connected', { count: onus.length }) }}
            </span>
            <span v-if="onus.length" class="inline-flex items-center gap-1 rounded-full bg-emerald-500/15 px-2 py-0.5 font-semibold text-emerald-300 ring-1 ring-emerald-500/30">
                {{ onlineCount }} {{ $t('common.online') }}
            </span>
        </div>

        <!-- Daftar ONU terhubung -->
        <div v-if="onus.length" class="max-h-52 space-y-1 overflow-auto rounded-lg border border-white/10 bg-white/5 p-1.5">
            <div
                v-for="onu in onus"
                :key="`${onu.snmp_olt_id}-${onu.slot}-${onu.port}-${onu.onu_id}`"
                class="flex items-start justify-between gap-2 rounded-md px-2 py-1 text-xs"
            >
                <span class="min-w-0">
                    <!-- Nama pelanggan dibiarkan membungkus (maks 2 baris) — dipotong 1 baris
                         bikin nama panjang "…(ODP X)" tak terbaca sama sekali. -->
                    <span
                        class="line-clamp-2 break-words font-medium leading-snug text-slate-100"
                        :title="onu.name || onu.interface || `ONU #${onu.onu_id}`"
                    >{{ onu.name || onu.interface || `ONU #${onu.onu_id}` }}</span>
                    <span class="block truncate text-[10px] text-slate-500">{{ onu.serial_number || onu.interface || '—' }}</span>
                </span>
                <component :is="onu.online ? Wifi : WifiOff" class="mt-0.5 h-3.5 w-3.5 shrink-0" :class="onu.online ? 'text-emerald-400' : 'text-red-400'" />
            </div>
        </div>
        <p v-else class="rounded-lg border border-dashed border-white/10 px-2 py-3 text-center text-[11px] text-slate-500">
            {{ $t('map.odp_no_onu') }}
        </p>

        <!-- Detail -->
        <dl class="grid grid-cols-3 gap-x-3 gap-y-1 text-xs">
            <template v-if="odp.notes">
                <dt class="text-slate-500">{{ $t('map.notes') }}</dt>
                <dd class="col-span-2 text-slate-200">{{ odp.notes }}</dd>
            </template>
            <dt class="text-slate-500">{{ $t('map.coords') }}</dt>
            <dd class="col-span-2 text-slate-400">{{ Number(odp.latitude).toFixed(6) }}, {{ Number(odp.longitude).toFixed(6) }}</dd>
        </dl>

        <!-- Aksi -->
        <div class="mt-1 space-y-2 border-t border-white/10 pt-3">
            <p v-if="odp.locked === false" class="rounded-lg bg-cyan-500/10 px-2.5 py-1.5 text-[11px] text-cyan-200">
                {{ $t('map.unlocked_hint') }}
            </p>
            <div class="grid grid-cols-2 gap-2">
                <button
                    type="button"
                    class="kv-action-btn"
                    :class="odp.locked === false ? 'kv-action-btn--active' : ''"
                    :disabled="busy"
                    @click="toggleLock"
                >
                    <component :is="odp.locked === false ? LockOpen : Lock" class="h-4 w-4" />
                    {{ odp.locked === false ? $t('map.lock') : $t('map.unlock') }}
                </button>
                <button type="button" class="kv-action-btn" :disabled="busy" @click="openEdit">
                    <Pencil class="h-4 w-4" /> {{ $t('map.edit_odp_name') }}
                </button>
                <button type="button" class="kv-action-btn" :disabled="busy" @click="colorOpen = true">
                    <Palette class="h-4 w-4" :style="{ color }" /> {{ $t('map.odp_color') }}
                </button>
                <a :href="googleHref" target="_blank" rel="noopener" class="kv-action-btn">
                    <MapPin class="h-4 w-4" /> Maps
                </a>
            </div>
            <button type="button" class="kv-action-btn kv-action-btn--danger w-full" :disabled="busy" @click="deleteOdp">
                <Trash2 class="h-4 w-4" /> {{ $t('map.delete_odp') }}
            </button>
        </div>

        <!-- Modal edit nama -->
        <Modal :show="editOpen" max-width="md" @close="editOpen = false">
            <div class="p-6">
                <h3 class="mb-4 text-lg font-semibold text-white">{{ $t('map.edit_odp_title') }}</h3>
                <InputLabel :value="$t('map.odp_name')" />
                <TextInput v-model="editForm.name" type="text" class="mt-1 w-full" maxlength="128" @keyup.enter="submitEdit" />
                <div class="mt-4">
                    <InputLabel :value="$t('map.notes_label')" />
                    <textarea v-model="editForm.notes" rows="2" class="kv-input mt-1 w-full"></textarea>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <SecondaryButton @click="editOpen = false">{{ $t('common.cancel') }}</SecondaryButton>
                    <PrimaryButton :disabled="editForm.processing || !editForm.name" @click="submitEdit">{{ $t('common.save') }}</PrimaryButton>
                </div>
            </div>
        </Modal>

        <!-- Modal warna pin ODP -->
        <OdpColorModal :show="colorOpen" :odp="odp" :palette="palette" :port-count="portCount" @close="colorOpen = false" />

        <ConfirmModal :state="confirmState" @confirm="handleConfirm" @cancel="handleCancel" />
    </div>
</template>

<style scoped>
.kv-action-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.35rem;
    border-radius: 0.5rem;
    border: 1px solid rgba(255, 255, 255, 0.1);
    background: rgba(255, 255, 255, 0.04);
    padding: 0.4rem 0.6rem;
    font-size: 0.75rem;
    font-weight: 500;
    color: #cbd5e1;
    transition: background-color 0.15s, color 0.15s;
}

.kv-action-btn svg {
    width: 0.875rem;
    height: 0.875rem;
}

.kv-action-btn:hover {
    background: rgba(255, 255, 255, 0.08);
    color: #fff;
}

.kv-action-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* Tombol Lock saat pin ODP sedang terbuka. */
.kv-action-btn--active {
    border-color: rgba(34, 211, 238, 0.4);
    background: rgba(34, 211, 238, 0.12);
    color: #67e8f9;
}

.kv-action-btn--danger {
    color: #fca5a5;
    border-color: rgba(248, 113, 113, 0.25);
}

.kv-action-btn--danger:hover {
    background: rgba(248, 113, 113, 0.12);
    color: #fecaca;
}
</style>
