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

// Jenis pin yang dibuat: 'onu' (default) atau 'odp'.
const pinType = ref('onu');

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

// Form ODP terpisah (hanya nama + OLT + koordinat).
const odpForm = useForm({
    snmp_olt_id: '',
    name: '',
    latitude: '',
    longitude: '',
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
        odpForm.reset();
        odpForm.clearErrors();
        search.value = '';
        // Preset dari Port ONUs = selalu pin ONU; klik peta biasa boleh pilih jenis.
        pinType.value = 'onu';
        if (props.coords) {
            form.latitude = props.coords.lat.toFixed(7);
            form.longitude = props.coords.lng.toFixed(7);
            odpForm.latitude = props.coords.lat.toFixed(7);
            odpForm.longitude = props.coords.lng.toFixed(7);
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

const canSubmitOdp = computed(
    () => odpForm.snmp_olt_id && odpForm.name.trim() !== '' && odpForm.latitude !== '' && odpForm.longitude !== '',
);

const submitOdp = () => {
    odpForm.post(route('map.odps.store'), {
        preserveScroll: true,
        onSuccess: () => emit('saved'),
    });
};

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
                <MapPin class="h-5 w-5" :class="pinType === 'odp' ? 'text-amber-400' : 'text-cyan-400'" />
                <h3 class="text-lg font-semibold text-white">{{ pinType === 'odp' ? $t('map.odp_modal_title') : $t('map.modal_title') }}</h3>
            </div>

            <!-- Toggle jenis pin (disembunyikan saat preset dari Port ONUs = selalu ONU) -->
            <div v-if="!preset" class="mb-4 inline-flex rounded-lg border border-white/10 bg-white/5 p-0.5 text-sm">
                <button
                    type="button"
                    class="rounded-md px-4 py-1.5 font-medium transition"
                    :class="pinType === 'onu' ? 'bg-cyan-500/20 text-cyan-200' : 'text-slate-400 hover:text-slate-200'"
                    @click="pinType = 'onu'"
                >
                    {{ $t('map.type_onu') }}
                </button>
                <button
                    type="button"
                    class="rounded-md px-4 py-1.5 font-medium transition"
                    :class="pinType === 'odp' ? 'bg-amber-500/20 text-amber-200' : 'text-slate-400 hover:text-slate-200'"
                    @click="pinType = 'odp'"
                >
                    {{ $t('map.type_odp') }}
                </button>
            </div>

            <!-- ===== Mode ONU ===== -->
            <template v-if="pinType === 'onu'">
            <!-- Search global lintas OLT -->
            <div class="relative mb-4">
                <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" />
                <input
                    v-model="search"
                    type="text"
                    :placeholder="$t('map.search_placeholder')"
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
                        <option value="">{{ $t('map.pick_olt') }}</option>
                        <option v-for="olt in olts" :key="olt.id" :value="olt.id">{{ olt.name }}</option>
                    </select>
                    <InputError :message="form.errors.snmp_olt_id" class="mt-1" />
                </div>
                <div>
                    <InputLabel value="Port" />
                    <select v-model="form.port" class="kv-input mt-1 w-full" :disabled="!form.snmp_olt_id" @change="onPortChange">
                        <option value="">{{ $t('map.pick_port') }}</option>
                        <option v-for="p in portOptions" :key="`${p.slot}/${p.port}`" :value="`${p.slot}/${p.port}`">{{ p.slot }}/{{ p.port }}</option>
                    </select>
                </div>
                <div>
                    <InputLabel value="ONU" />
                    <select v-model="form.onu_id" class="kv-input mt-1 w-full" :disabled="!form.snmp_olt_id">
                        <option value="">{{ $t('map.pick_onu') }}</option>
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
                    <InputLabel :value="$t('map.customer_name_label')" />
                    <TextInput v-model="form.customer_name" type="text" class="mt-1 w-full" :placeholder="selectedOnu?.customer_name || $t('map.customer_name_placeholder')" />
                </div>
                <div>
                    <InputLabel :value="$t('map.phone_label')" />
                    <TextInput v-model="form.phone" type="text" class="mt-1 w-full" />
                </div>
                <div class="sm:col-span-2">
                    <InputLabel :value="$t('map.address_label')" />
                    <TextInput v-model="form.address" type="text" class="mt-1 w-full" />
                </div>
                <div class="sm:col-span-2">
                    <InputLabel :value="$t('map.notes_label')" />
                    <textarea v-model="form.notes" rows="2" class="kv-input mt-1 w-full"></textarea>
                </div>
            </div>
            </template>

            <!-- ===== Mode ODP ===== -->
            <template v-else>
                <div>
                    <InputLabel value="OLT" />
                    <select v-model="odpForm.snmp_olt_id" class="kv-input mt-1 w-full">
                        <option value="">{{ $t('map.pick_olt') }}</option>
                        <option v-for="olt in olts" :key="olt.id" :value="olt.id">{{ olt.name }}</option>
                    </select>
                    <InputError :message="odpForm.errors.snmp_olt_id" class="mt-1" />
                </div>

                <div class="mt-4">
                    <InputLabel :value="$t('map.odp_name')" />
                    <TextInput v-model="odpForm.name" type="text" maxlength="128" class="mt-1 w-full" :placeholder="$t('map.odp_name_placeholder')" />
                    <InputError :message="odpForm.errors.name" class="mt-1" />
                </div>

                <!-- Koordinat ODP -->
                <div class="mt-4 grid grid-cols-2 gap-4">
                    <div>
                        <InputLabel value="Latitude" />
                        <TextInput v-model="odpForm.latitude" type="number" step="any" class="mt-1 w-full" />
                        <InputError :message="odpForm.errors.latitude" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel value="Longitude" />
                        <TextInput v-model="odpForm.longitude" type="number" step="any" class="mt-1 w-full" />
                        <InputError :message="odpForm.errors.longitude" class="mt-1" />
                    </div>
                </div>

                <div class="mt-4">
                    <InputLabel :value="$t('map.notes_label')" />
                    <textarea v-model="odpForm.notes" rows="2" class="kv-input mt-1 w-full"></textarea>
                </div>
            </template>

            <div class="mt-6 flex justify-end gap-3">
                <SecondaryButton @click="emit('close')">{{ $t('common.cancel') }}</SecondaryButton>
                <PrimaryButton v-if="pinType === 'onu'" :disabled="!canSubmit || form.processing" @click="submit">{{ $t('map.save_pin') }}</PrimaryButton>
                <PrimaryButton v-else :disabled="!canSubmitOdp || odpForm.processing" @click="submitOdp">{{ $t('map.save_odp') }}</PrimaryButton>
            </div>
        </div>
    </Modal>
</template>
