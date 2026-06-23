<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { rxBadgeClass } from '@/Composables/useRxLevel';
import { useForm } from '@inertiajs/vue3';
import { MapPin, Search, Wifi, WifiOff } from '@lucide/vue';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    olts: { type: Array, default: () => [] },
    onus: { type: Array, default: () => [] },
    coords: { type: Object, default: null }, // {lat,lng} dari klik peta
    preset: { type: Object, default: null }, // {snmp_olt_id,slot,port,onu_id} dari Port ONUs
});

const emit = defineEmits(['close', 'saved']);

const form = useForm({
    snmp_olt_id: '',
    slot: '',
    port: '',
    onu_id: '',
    serial_number: '',
    latitude: '',
    longitude: '',
    customer_name: '',
    address: '',
    phone: '',
    notes: '',
});

const search = ref('');

// --- dropdown bertingkat OLT → Port → ONU ---
const oltScopedOnus = computed(() =>
    form.snmp_olt_id ? props.onus.filter((o) => o.olt_id === Number(form.snmp_olt_id)) : [],
);

const portOptions = computed(() => {
    const set = new Map();
    for (const onu of oltScopedOnus.value) {
        set.set(`${onu.slot}/${onu.port}`, { slot: onu.slot, port: onu.port });
    }
    return [...set.values()].sort((a, b) => a.slot - b.slot || a.port - b.port);
});

const onuOptions = computed(() =>
    oltScopedOnus.value
        .filter((o) => form.port === '' || `${o.slot}/${o.port}` === form.port)
        .sort((a, b) => a.onu_id - b.onu_id),
);

// --- search global lintas semua OLT ---
const searchResults = computed(() => {
    const term = search.value.trim().toLowerCase();
    if (!term) return [];
    return props.onus
        .filter((o) => {
            const hay = [o.interface, o.serial_number, o.customer_name, o.name, o.olt_name]
                .filter(Boolean)
                .join(' ')
                .toLowerCase();
            return hay.includes(term);
        })
        .slice(0, 30);
});

const selectedOnu = computed(() =>
    props.onus.find(
        (o) =>
            o.olt_id === Number(form.snmp_olt_id) &&
            `${o.slot}/${o.port}` === form.port &&
            o.onu_id === Number(form.onu_id),
    ),
);

const applyOnu = (onu) => {
    form.snmp_olt_id = onu.olt_id;
    form.slot = onu.slot;
    form.port = `${onu.slot}/${onu.port}`;
    form.onu_id = onu.onu_id;
    form.serial_number = onu.serial_number ?? '';
    search.value = '';
};

const onPickResult = (onu) => applyOnu(onu);

const onOltChange = () => {
    form.port = '';
    form.onu_id = '';
};

const onPortChange = () => {
    form.onu_id = '';
};

watch(
    () => form.onu_id,
    () => {
        const onu = selectedOnu.value;
        if (onu) form.serial_number = onu.serial_number ?? '';
    },
);

// Inisialisasi saat modal dibuka: isi koordinat + preset ONU (mode placement).
watch(
    () => props.show,
    (open) => {
        if (!open) return;
        form.reset();
        form.clearErrors();
        search.value = '';
        if (props.coords) {
            form.latitude = props.coords.lat.toFixed(7);
            form.longitude = props.coords.lng.toFixed(7);
        }
        if (props.preset) {
            const match = props.onus.find(
                (o) =>
                    o.olt_id === props.preset.snmp_olt_id &&
                    o.slot === props.preset.slot &&
                    o.port === props.preset.port &&
                    o.onu_id === props.preset.onu_id,
            );
            if (match) applyOnu(match);
            else {
                form.snmp_olt_id = props.preset.snmp_olt_id;
                form.slot = props.preset.slot;
                form.port = `${props.preset.slot}/${props.preset.port}`;
                form.onu_id = props.preset.onu_id;
            }
        }
    },
);

const canSubmit = computed(
    () => form.snmp_olt_id && form.onu_id !== '' && form.latitude !== '' && form.longitude !== '',
);

const submit = () => {
    // Kirim slot/port numerik (form.port menyimpan "slot/port" untuk dropdown).
    const onu = selectedOnu.value;
    form
        .transform((data) => ({
            ...data,
            slot: onu ? onu.slot : Number(String(data.port).split('/')[0]),
            port: onu ? onu.port : Number(String(data.port).split('/')[1]),
        }))
        .post(route('map.pins.store'), {
            preserveScroll: true,
            onSuccess: () => emit('saved'),
        });
};
</script>

<template>
    <Modal :show="show" max-width="2xl" @close="emit('close')">
        <div class="p-6">
            <div class="mb-4 flex items-center gap-2">
                <MapPin class="h-5 w-5 text-cyan-400" />
                <h3 class="text-lg font-semibold text-white">Tambah Pin ONU</h3>
            </div>

            <!-- Search global lintas OLT -->
            <div class="relative mb-4">
                <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" />
                <input
                    v-model="search"
                    type="text"
                    placeholder="Cari ONU: interface, serial, nama pelanggan, atau OLT..."
                    class="kv-input w-full pl-9"
                />
                <div
                    v-if="searchResults.length"
                    class="absolute z-20 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-white/10 bg-slate-900/95 shadow-xl backdrop-blur"
                >
                    <button
                        v-for="onu in searchResults"
                        :key="`${onu.olt_id}-${onu.slot}-${onu.port}-${onu.onu_id}`"
                        type="button"
                        class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm hover:bg-white/5"
                        @click="onPickResult(onu)"
                    >
                        <span class="min-w-0">
                            <span class="block truncate font-medium text-white">{{ onu.interface }} · {{ onu.customer_name || onu.name || '—' }}</span>
                            <span class="block truncate text-xs text-slate-500">{{ onu.olt_name }} · {{ onu.serial_number || '—' }}</span>
                        </span>
                        <component :is="onu.online ? Wifi : WifiOff" class="h-4 w-4 shrink-0" :class="onu.online ? 'text-emerald-400' : 'text-slate-500'" />
                    </button>
                </div>
            </div>

            <!-- Dropdown bertingkat -->
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <InputLabel value="OLT" />
                    <select v-model="form.snmp_olt_id" class="kv-input mt-1 w-full" @change="onOltChange">
                        <option value="">— Pilih OLT —</option>
                        <option v-for="olt in olts" :key="olt.id" :value="olt.id">{{ olt.name }}</option>
                    </select>
                    <InputError :message="form.errors.snmp_olt_id" class="mt-1" />
                </div>
                <div>
                    <InputLabel value="Port" />
                    <select v-model="form.port" class="kv-input mt-1 w-full" :disabled="!form.snmp_olt_id" @change="onPortChange">
                        <option value="">— Pilih Port —</option>
                        <option v-for="p in portOptions" :key="`${p.slot}/${p.port}`" :value="`${p.slot}/${p.port}`">{{ p.slot }}/{{ p.port }}</option>
                    </select>
                </div>
                <div>
                    <InputLabel value="ONU" />
                    <select v-model="form.onu_id" class="kv-input mt-1 w-full" :disabled="!form.snmp_olt_id">
                        <option value="">— Pilih ONU —</option>
                        <option v-for="onu in onuOptions" :key="onu.onu_id" :value="onu.onu_id">
                            #{{ onu.onu_id }} · {{ onu.customer_name || onu.name || onu.serial_number || onu.interface }}
                        </option>
                    </select>
                    <InputError :message="form.errors.onu_id" class="mt-1" />
                </div>
            </div>

            <!-- Ringkasan ONU terpilih -->
            <div v-if="selectedOnu" class="mt-3 flex flex-wrap items-center gap-2 rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm">
                <span class="font-medium text-white">{{ selectedOnu.interface }}</span>
                <span class="text-slate-500">·</span>
                <span class="text-slate-300">{{ selectedOnu.serial_number || '—' }}</span>
                <span v-if="selectedOnu.rx_power_label" class="ml-auto inline-flex rounded-full px-2 py-0.5 text-xs font-semibold" :class="rxBadgeClass(selectedOnu.rx_power_dbm)">
                    RX {{ selectedOnu.rx_power_label }}
                </span>
            </div>

            <!-- Koordinat -->
            <div class="mt-4 grid grid-cols-2 gap-4">
                <div>
                    <InputLabel value="Latitude" />
                    <TextInput v-model="form.latitude" type="number" step="any" class="mt-1 w-full" />
                    <InputError :message="form.errors.latitude" class="mt-1" />
                </div>
                <div>
                    <InputLabel value="Longitude" />
                    <TextInput v-model="form.longitude" type="number" step="any" class="mt-1 w-full" />
                    <InputError :message="form.errors.longitude" class="mt-1" />
                </div>
            </div>

            <!-- Data pelanggan tambahan -->
            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <InputLabel value="Nama pelanggan (opsional override)" />
                    <TextInput v-model="form.customer_name" type="text" class="mt-1 w-full" :placeholder="selectedOnu?.customer_name || 'Pakai nama ONU bila kosong'" />
                </div>
                <div>
                    <InputLabel value="No. HP (opsional)" />
                    <TextInput v-model="form.phone" type="text" class="mt-1 w-full" />
                </div>
                <div class="sm:col-span-2">
                    <InputLabel value="Alamat (opsional)" />
                    <TextInput v-model="form.address" type="text" class="mt-1 w-full" />
                </div>
                <div class="sm:col-span-2">
                    <InputLabel value="Catatan (opsional)" />
                    <textarea v-model="form.notes" rows="2" class="kv-input mt-1 w-full"></textarea>
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <SecondaryButton @click="emit('close')">Batal</SecondaryButton>
                <PrimaryButton :disabled="!canSubmit || form.processing" @click="submit">Simpan Pin</PrimaryButton>
            </div>
        </div>
    </Modal>
</template>
