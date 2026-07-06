<script setup>
import ConfirmModal from '@/Components/ConfirmModal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { rxBadgeClass } from '@/Composables/useRxLevel';
import { Link, router, useForm } from '@inertiajs/vue3';
import { ExternalLink, Info, MapPin, Pencil, Power, Trash2, Wifi, WifiOff, X } from '@lucide/vue';
import { computed, ref } from 'vue';

const props = defineProps({
    pin: { type: Object, required: true },
});

const emit = defineEmits(['close']);

const { confirmState, confirm, handleConfirm, handleCancel } = useConfirm();
const caps = computed(() => props.pin.capabilities ?? {});
const busy = ref(false);

const portOnuHref = computed(() => {
    // port_route sudah menentukan family (smartolt / cdata-olt / hioso-olt) dari server.
    const name = props.pin.port_route ?? 'smartolt.port-onus';
    return `${route(name, [props.pin.snmp_olt_id, props.pin.slot, props.pin.port])}?focus=${props.pin.onu_id}`;
});

const googleHref = computed(() => `https://www.google.com/maps?q=${props.pin.latitude},${props.pin.longitude}`);

// --- ganti nama ---
const renameOpen = ref(false);
const renameForm = useForm({ name: '' });

const openRename = () => {
    renameForm.name = props.pin.onu_name ?? '';
    renameForm.clearErrors();
    renameOpen.value = true;
};

const submitRename = () => {
    renameForm.post(route('map.pins.rename', props.pin.id), {
        preserveScroll: true,
        onSuccess: () => {
            renameOpen.value = false;
        },
    });
};

// --- reboot ---
const rebootOnu = async () => {
    const ok = await confirm({
        title: 'Reboot ONU',
        message: `Reboot ${props.pin.interface}? ONU akan restart 30-60 detik.`,
        confirmLabel: 'Reboot',
        variant: 'danger',
    });
    if (!ok) return;
    busy.value = true;
    router.post(
        route('map.pins.reboot', props.pin.id),
        {},
        { preserveScroll: true, onFinish: () => (busy.value = false) },
    );
};

// --- hapus pin ---
const deletePin = async () => {
    const ok = await confirm({
        title: 'Hapus pin',
        message: 'Hapus pin ONU ini dari peta? ONU di OLT tidak terpengaruh.',
        confirmLabel: 'Hapus',
        variant: 'danger',
    });
    if (!ok) return;
    busy.value = true;
    router.delete(route('map.pins.destroy', props.pin.id), {
        preserveScroll: true,
        onFinish: () => (busy.value = false),
        onSuccess: () => emit('close'),
    });
};
</script>

<template>
    <div class="flex flex-col gap-2.5 rounded-2xl border border-white/10 bg-slate-950/95 p-3.5 shadow-xl shadow-black/50 backdrop-blur-xl">
        <!-- Header -->
        <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
                <h3 class="truncate text-sm font-semibold text-white">{{ pin.customer_name || 'ONU tanpa nama' }}</h3>
                <p class="mt-0.5 truncate text-[11px] text-slate-400">{{ pin.interface }} · {{ pin.olt_name }}</p>
            </div>
            <button type="button" class="-mr-1 -mt-1 rounded-lg p-1 text-slate-400 transition hover:bg-white/10 hover:text-white" title="Tutup" @click="emit('close')">
                <X class="h-4 w-4" />
            </button>
        </div>

        <!-- Status & RX -->
        <div class="flex flex-wrap items-center gap-1.5">
            <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="pin.online ? 'bg-emerald-500/15 text-emerald-300 ring-1 ring-emerald-500/30' : 'bg-slate-800/60 text-slate-400 ring-1 ring-slate-500/30'">
                <component :is="pin.online ? Wifi : WifiOff" class="h-3 w-3" />
                {{ pin.online ? 'Online' : 'Offline' }}
            </span>
            <span class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="rxBadgeClass(pin.rx_power_dbm)">
                RX {{ pin.rx_power_label || '—' }}
            </span>
            <span v-if="!pin.has_live" class="text-[11px] text-amber-400">tidak ada di cache OLT</span>
        </div>

        <!-- Detail -->
        <dl class="grid grid-cols-3 gap-x-3 gap-y-1 text-xs">
            <dt class="text-slate-500">Serial</dt>
            <dd class="col-span-2 truncate text-slate-200">{{ pin.serial_number || '—' }}</dd>
            <dt class="text-slate-500">Slot/Port/ONU</dt>
            <dd class="col-span-2 text-slate-200">{{ pin.slot }}/{{ pin.port }}/{{ pin.onu_id }}</dd>
            <template v-if="pin.address">
                <dt class="text-slate-500">Alamat</dt>
                <dd class="col-span-2 text-slate-200">{{ pin.address }}</dd>
            </template>
            <template v-if="pin.phone">
                <dt class="text-slate-500">No. HP</dt>
                <dd class="col-span-2 text-slate-200">{{ pin.phone }}</dd>
            </template>
            <template v-if="pin.notes">
                <dt class="text-slate-500">Catatan</dt>
                <dd class="col-span-2 text-slate-200">{{ pin.notes }}</dd>
            </template>
            <dt class="text-slate-500">Koordinat</dt>
            <dd class="col-span-2 text-slate-400">{{ Number(pin.latitude).toFixed(6) }}, {{ Number(pin.longitude).toFixed(6) }}</dd>
        </dl>

        <!-- Aksi -->
        <div class="mt-1 space-y-2 border-t border-white/10 pt-3">
            <div class="grid grid-cols-2 gap-2">
                <button
                    v-if="caps.supports_onu_info_write"
                    type="button"
                    class="kv-action-btn"
                    :disabled="busy"
                    @click="openRename"
                >
                    <Pencil class="h-4 w-4" /> Edit Nama
                </button>
                <button
                    v-if="caps.supports_reboot"
                    type="button"
                    class="kv-action-btn"
                    :disabled="busy"
                    @click="rebootOnu"
                >
                    <Power class="h-4 w-4" /> Reboot
                </button>
                <Link
                    v-if="!pin.olt_cdata && caps.supports_cli_onu_detail"
                    :href="route('smartolt.onu.detail', [pin.snmp_olt_id, pin.slot, pin.port, pin.onu_id])"
                    class="kv-action-btn"
                >
                    <Info class="h-4 w-4" /> Detail ONU
                </Link>
                <Link :href="portOnuHref" class="kv-action-btn">
                    <ExternalLink class="h-4 w-4" /> Port
                </Link>
                <a :href="googleHref" target="_blank" rel="noopener" class="kv-action-btn">
                    <MapPin class="h-4 w-4" /> Maps
                </a>
            </div>
            <button type="button" class="kv-action-btn kv-action-btn--danger w-full" :disabled="busy" @click="deletePin">
                <Trash2 class="h-4 w-4" /> Hapus Pin
            </button>
        </div>

        <!-- Modal ganti nama -->
        <Modal :show="renameOpen" max-width="md" @close="renameOpen = false">
            <div class="p-6">
                <h3 class="mb-4 text-lg font-semibold text-white">Ganti Nama ONU</h3>
                <InputLabel value="Nama pelanggan" />
                <TextInput v-model="renameForm.name" type="text" class="mt-1 w-full" placeholder="Nama / deskripsi ONU" @keyup.enter="submitRename" />
                <p class="mt-1 text-xs text-slate-500">Ditulis langsung ke OLT ({{ pin.olt_cdata ? 'C-Data CLI' : 'ZTE SNMP' }}).</p>
                <div class="mt-6 flex justify-end gap-3">
                    <SecondaryButton @click="renameOpen = false">Batal</SecondaryButton>
                    <PrimaryButton :disabled="renameForm.processing" @click="submitRename">Simpan</PrimaryButton>
                </div>
            </div>
        </Modal>

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

.kv-action-btn--danger {
    color: #fca5a5;
    border-color: rgba(248, 113, 113, 0.25);
}

.kv-action-btn--danger:hover {
    background: rgba(248, 113, 113, 0.12);
    color: #fecaca;
}
</style>
