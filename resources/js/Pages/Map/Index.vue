<script setup>
import AddPinModal from '@/Components/Map/AddPinModal.vue';
import PinDetailCard from '@/Components/Map/PinDetailCard.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { Crosshair, MapPin, X } from '@lucide/vue';
import { computed, defineAsyncComponent, onMounted, ref } from 'vue';

// Lazy-load peta Leaflet (chunk async) agar key manifest Inertia tidak hilang saat build.
const OnuMap = defineAsyncComponent(() => import('@/Components/Map/OnuMap.vue'));

const props = defineProps({
    pins: { type: Array, default: () => [] },
    olts: { type: Array, default: () => [] },
    onus: { type: Array, default: () => [] },
    default_center: { type: Object, default: () => ({ lat: -6.7559, lng: 111.0381, zoom: 11 }) },
    placement: { type: Object, default: null },
    focus_pin_id: { type: [Number, null], default: null },
});

const page = usePage();
const flash = computed(() => page.props.flash ?? {});

const mapRef = ref(null);
const addMode = ref(false);
const draftCoords = ref(null);
const addModalOpen = ref(false);
const presetForModal = ref(null);
const selectedPinId = ref(null);
const cardPos = ref(null); // posisi piksel pin terpilih (untuk menempel kartu detail di atasnya)

const selectedPin = computed(() => props.pins.find((p) => p.id === selectedPinId.value) ?? null);

// Kartu detail diposisikan absolut di atas pin; ikut bergeser saat peta dipan/zoom.
const cardStyle = computed(() =>
    cardPos.value
        ? { left: `${cardPos.value.x}px`, top: `${cardPos.value.y}px` }
        : {},
);

const closeDetail = () => {
    selectedPinId.value = null;
    cardPos.value = null;
};

const onlineCount = computed(() => props.pins.filter((p) => p.online).length);

// Mode placement dari Port ONUs ("klik langsung di map") — buka peta siap tempel pin ONU tsb.
// Atau fokus ke pin tertentu ("Lihat di Peta") — langsung buka kartu detailnya.
onMounted(() => {
    if (props.placement) {
        presetForModal.value = props.placement;
        addMode.value = true;
    } else if (props.focus_pin_id && props.pins.some((p) => p.id === props.focus_pin_id)) {
        selectedPinId.value = props.focus_pin_id;
    }
});

const toggleAddMode = () => {
    addMode.value = !addMode.value;
    if (!addMode.value) {
        presetForModal.value = null;
        draftCoords.value = null;
    }
    selectedPinId.value = null;
};

const onMapClick = ({ lat, lng }) => {
    draftCoords.value = { lat, lng };
    addModalOpen.value = true;
};

const onSelectPin = (id) => {
    selectedPinId.value = id;
    const pin = props.pins.find((p) => p.id === id);
    if (pin && mapRef.value) mapRef.value.flyTo(pin.latitude, pin.longitude);
};

const closeModal = () => {
    addModalOpen.value = false;
    draftCoords.value = null;
};

const onSaved = () => {
    addModalOpen.value = false;
    addMode.value = false;
    draftCoords.value = null;
    presetForModal.value = null;
};
</script>

<template>
    <Head title="Peta ONU" />

    <AuthenticatedLayout>
        <div class="flex flex-col gap-3 px-4 py-4 sm:px-6 lg:px-8">
            <!-- Header + toolbar -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <MapPin class="h-6 w-6 text-cyan-400" />
                    <div>
                        <h1 class="text-lg font-semibold text-white">Peta ONU</h1>
                        <p class="text-xs text-slate-400">
                            {{ pins.length }} pin · {{ onlineCount }} online · sebaran ONU pelanggan lintas-OLT
                        </p>
                    </div>
                </div>
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg border px-4 py-2 text-sm font-semibold transition"
                    :class="addMode ? 'border-cyan-500/40 bg-cyan-500/15 text-cyan-300' : 'border-white/10 bg-white/5 text-slate-200 hover:bg-white/10'"
                    @click="toggleAddMode"
                >
                    <Crosshair class="h-4 w-4" />
                    {{ addMode ? 'Mode tambah aktif — klik di peta' : 'Tambah Pin' }}
                </button>
            </div>

            <!-- Banner mode placement -->
            <div v-if="addMode && presetForModal" class="flex items-center gap-3 rounded-lg border border-cyan-500/30 bg-cyan-500/10 px-4 py-2.5 text-sm text-cyan-200">
                <Crosshair class="h-4 w-4" />
                Klik lokasi di peta untuk menempatkan pin ONU terpilih.
            </div>

            <!-- Peta + panel detail -->
            <div class="relative h-[78vh] min-h-[420px] overflow-hidden rounded-xl border border-white/10">
                <OnuMap
                    ref="mapRef"
                    :pins="pins"
                    :center="default_center"
                    :add-mode="addMode"
                    :selected-id="selectedPinId"
                    :draft="draftCoords"
                    @map-click="onMapClick"
                    @select-pin="onSelectPin"
                    @pin-position="cardPos = $event"
                />

                <!-- Kartu detail pin — menempel tepat di atas pin -->
                <div
                    v-if="selectedPin && cardPos"
                    class="kv-pin-popup absolute z-[500] w-72"
                    :style="cardStyle"
                >
                    <PinDetailCard :pin="selectedPin" @close="closeDetail" />
                    <span class="kv-pin-popup__arrow"></span>
                </div>

                <!-- Empty hint -->
                <div v-if="!pins.length && !addMode" class="pointer-events-none absolute inset-x-0 bottom-6 z-[400] flex justify-center">
                    <div class="pointer-events-auto flex items-center gap-2 rounded-full border border-white/10 bg-slate-900/85 px-4 py-2 text-sm text-slate-300 backdrop-blur">
                        <MapPin class="h-4 w-4 text-cyan-400" />
                        Belum ada pin. Klik <button type="button" class="font-semibold text-cyan-300 underline" @click="toggleAddMode">Tambah Pin</button> lalu klik lokasi di peta.
                    </div>
                </div>
            </div>
        </div>

        <AddPinModal
            :show="addModalOpen"
            :olts="olts"
            :onus="onus"
            :coords="draftCoords"
            :preset="presetForModal"
            @close="closeModal"
            @saved="onSaved"
        />
    </AuthenticatedLayout>
</template>

<style scoped>
/* Kartu detail melayang tepat di atas pin (titik = ujung bawah pin). */
.kv-pin-popup {
    transform: translate(-50%, calc(-100% - 34px));
    filter: drop-shadow(0 10px 25px rgba(0, 0, 0, 0.45));
}

/* Panah penunjuk ke pin — diamond serasi kaca kartu. */
.kv-pin-popup__arrow {
    position: absolute;
    left: 50%;
    bottom: -6px;
    width: 12px;
    height: 12px;
    margin-left: -6px;
    background: rgb(2 6 23 / 0.95);
    border-right: 1px solid rgb(255 255 255 / 0.1);
    border-bottom: 1px solid rgb(255 255 255 / 0.1);
    transform: rotate(45deg);
    backdrop-filter: blur(16px);
}
</style>
