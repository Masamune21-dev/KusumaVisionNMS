<script setup>
import AddPinModal from '@/Components/Map/AddPinModal.vue';
import OdpDetailCard from '@/Components/Map/OdpDetailCard.vue';
import PinDetailCard from '@/Components/Map/PinDetailCard.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { Crosshair, MapPin, X } from '@lucide/vue';
import { computed, defineAsyncComponent, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

// Lazy-load peta Leaflet (chunk async) agar key manifest Inertia tidak hilang saat build.
const OnuMap = defineAsyncComponent(() => import('@/Components/Map/OnuMap.vue'));

const props = defineProps({
    pins: { type: Array, default: () => [] },
    odps: { type: Array, default: () => [] },
    olts: { type: Array, default: () => [] },
    onus: { type: Array, default: () => [] },
    default_center: { type: Object, default: () => ({ lat: -6.7559, lng: 111.0381, zoom: 11 }) },
    placement: { type: Object, default: null },
    focus_pin_id: { type: [Number, null], default: null },
    focus_odp_id: { type: [Number, null], default: null },
    // Palet warna pin ODP (sumber: App\Support\OdpColors di server).
    odp_color_palette: { type: Array, default: () => [] },
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
const selectedOdpId = ref(null);
const odpCardPos = ref(null);

const selectedPin = computed(() => props.pins.find((p) => p.id === selectedPinId.value) ?? null);
const selectedOdp = computed(() => props.odps.find((o) => o.id === selectedOdpId.value) ?? null);

// Jumlah ODP se-PON-port dgn ODP terpilih — dipakai label saklar "terapkan ke satu port"
// di modal warna (warna ODP normalnya seragam per port).
const selectedOdpPortCount = computed(() => {
    const odp = selectedOdp.value;
    if (!odp || odp.slot == null || odp.port == null) return 1;

    return props.odps.filter(
        (o) => o.snmp_olt_id === odp.snmp_olt_id && o.slot === odp.slot && o.port === odp.port,
    ).length;
});

// Kartu detail diposisikan absolut di atas pin; ikut bergeser saat peta dipan/zoom.
const cardStyle = computed(() =>
    cardPos.value
        ? { left: `${cardPos.value.x}px`, top: `${cardPos.value.y}px` }
        : {},
);
const odpCardStyle = computed(() =>
    odpCardPos.value
        ? { left: `${odpCardPos.value.x}px`, top: `${odpCardPos.value.y}px` }
        : {},
);

// Ujung bawah kartu berjarak 34 px di atas ujung pin (lihat .kv-pin-popup). Saat pin dipilih
// peta digeser supaya kartu + pin tampil utuh di tengah area peta, bukan terpotong di atas.
const POPUP_GAP = 34;
const EDGE = 12;

const mapBox = ref(null);
const pinCardEl = ref(null);
const odpCardEl = ref(null);
const boxHeight = ref(0);

// Kartu tak boleh lebih tinggi dari peta; sisanya di-scroll di dalam kartu.
const cardMaxHeight = computed(() =>
    boxHeight.value ? `${Math.max(160, boxHeight.value - POPUP_GAP - EDGE * 2)}px` : null,
);

const waitFor = async (getter, tries = 5) => {
    for (let i = 0; i < tries && !getter(); i++) await nextTick();

    return getter();
};

const lastCardHeight = new WeakMap();
let centeringAt = 0;

// Pin ditaruh di bawah pusat peta sejauh setengah (tinggi kartu + jarak) → blok kartu+pin di tengah.
const centerOnCard = async (item, cardEl) => {
    centeringAt = Date.now();
    await nextTick(); // isi kartu pin/ODP baru sudah dirender sebelum diukur
    const map = await waitFor(() => mapRef.value);
    const el = await waitFor(() => cardEl.value);
    if (!map || !item) return;

    const h = el?.offsetHeight ?? 0;
    if (el) lastCardHeight.set(el, h);
    map.flyTo(item.latitude, item.longitude, 16, h ? (h + POPUP_GAP) / 2 : 130);
};

// Kartu yang membesar selagi terbuka (mis. form ubah nama) digeser turun seperlunya agar tak
// keluar dari atas peta. Ukuran awal & perubahan selama animasi pemusatan diabaikan — itu urusan
// centerOnCard (panBy di tengah flyTo membatalkan animasinya).
const keepCardInView = (el) => {
    if (Date.now() - centeringAt < 1500) return;

    const pos = el === pinCardEl.value ? cardPos.value : el === odpCardEl.value ? odpCardPos.value : null;
    if (!pos || !mapRef.value) return;

    const top = pos.y - POPUP_GAP - el.offsetHeight;
    if (top < EDGE) mapRef.value.panBy(0, top - EDGE);
};

let resizeObserver = null;
onMounted(() => {
    resizeObserver = new ResizeObserver((entries) => {
        for (const { target } of entries) {
            if (target === mapBox.value) {
                boxHeight.value = target.clientHeight;
                continue;
            }
            const prev = lastCardHeight.get(target);
            lastCardHeight.set(target, target.offsetHeight);
            if (prev != null && target.offsetHeight > prev) keepCardInView(target);
        }
    });
    if (mapBox.value) resizeObserver.observe(mapBox.value);
});
onBeforeUnmount(() => resizeObserver?.disconnect());
watch([pinCardEl, odpCardEl], ([pinEl, odpEl], [oldPin, oldOdp]) => {
    if (!resizeObserver) return;
    for (const el of [oldPin, oldOdp]) if (el && el !== pinEl && el !== odpEl) resizeObserver.unobserve(el);
    for (const el of [pinEl, odpEl]) if (el) resizeObserver.observe(el);
});

// Fokus dari halaman lain ("Lihat di Peta"): dipusatkan begitu kartunya pertama kali muncul.
let pendingCenter = null;
watch(cardPos, (pos) => {
    if (pos && pendingCenter === 'pin') {
        pendingCenter = null;
        centerOnCard(selectedPin.value, pinCardEl);
    }
});
watch(odpCardPos, (pos) => {
    if (pos && pendingCenter === 'odp') {
        pendingCenter = null;
        centerOnCard(selectedOdp.value, odpCardEl);
    }
});

const closeDetail = () => {
    selectedPinId.value = null;
    cardPos.value = null;
};

const closeOdpDetail = () => {
    selectedOdpId.value = null;
    odpCardPos.value = null;
};

const onlineCount = computed(() => props.pins.filter((p) => p.online).length);

// Daftar ONU (prop optional di server, ±4.500 baris) tidak ikut saat peta dibuka — baru
// diambil begitu pengguna masuk mode tambah pin, supaya halaman peta ringan.
const onusLoading = ref(false);
const ensureOnus = () => {
    if (props.onus.length || onusLoading.value) return;
    onusLoading.value = true;
    router.reload({ only: ['onus'], onFinish: () => (onusLoading.value = false) });
};

// Mode placement dari Port ONUs ("klik langsung di map") — buka peta siap tempel pin ONU tsb.
// Atau fokus ke pin tertentu ("Lihat di Peta") — langsung buka kartu detailnya.
onMounted(() => {
    if (props.placement) {
        presetForModal.value = props.placement;
        addMode.value = true;
        ensureOnus();
    } else if (props.focus_pin_id && props.pins.some((p) => p.id === props.focus_pin_id)) {
        pendingCenter = 'pin';
        selectedPinId.value = props.focus_pin_id;
    } else if (props.focus_odp_id && props.odps.some((o) => o.id === props.focus_odp_id)) {
        pendingCenter = 'odp';
        selectedOdpId.value = props.focus_odp_id;
    }
});

const toggleAddMode = () => {
    addMode.value = !addMode.value;
    if (addMode.value) ensureOnus();
    if (!addMode.value) {
        presetForModal.value = null;
        draftCoords.value = null;
    }
    closeDetail();
    closeOdpDetail();
};

const onMapClick = ({ lat, lng }) => {
    draftCoords.value = { lat, lng };
    ensureOnus();
    addModalOpen.value = true;
};

const onSelectPin = (id) => {
    closeOdpDetail();
    selectedPinId.value = id;
    centerOnCard(props.pins.find((p) => p.id === id), pinCardEl);
};

const onSelectOdp = (id) => {
    closeDetail();
    selectedOdpId.value = id;
    centerOnCard(props.odps.find((o) => o.id === id), odpCardEl);
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

// Pin yang sedang terbuka (unlocked) langsung menyimpan koordinat begitu dilepas, supaya
// posisi tak hilang bila halaman ditutup sebelum sempat dikunci. Tombol Lock hanya mengunci.
// `only` menahan server agar hanya menghitung prop yang berubah — tanpa itu tiap geser pin
// membangun ulang seluruh payload peta (ODP + ONU semua OLT) dan terasa seperti reload.
const onPinMoved = ({ id, latitude, longitude }) => {
    router.put(
        route('map.pins.update', id),
        { latitude, longitude },
        { preserveScroll: true, preserveState: true, only: ['pins'] },
    );
};

const onOdpMoved = ({ id, latitude, longitude }) => {
    router.put(
        route('map.odps.update', id),
        { latitude, longitude },
        { preserveScroll: true, preserveState: true, only: ['odps'] },
    );
};
</script>

<template>
    <Head :title="$t('map.title')" />

    <AuthenticatedLayout>
        <div class="flex flex-col gap-3 px-4 py-4 sm:px-6 lg:px-8">
            <!-- Header + toolbar -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <MapPin class="h-6 w-6 text-cyan-400" />
                    <div>
                        <h1 class="text-xl font-semibold text-white">{{ $t('map.title') }}</h1>
                        <p class="text-xs text-slate-400">
                            {{ $t('map.stats', { pins: pins.length, online: onlineCount }) }}
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
                    {{ addMode ? $t('map.add_mode_active') : $t('map.add_pin') }}
                </button>
            </div>

            <!-- Banner mode placement -->
            <div v-if="addMode && presetForModal" class="flex items-center gap-3 rounded-lg border border-cyan-500/30 bg-cyan-500/10 px-4 py-2.5 text-sm text-cyan-200">
                <Crosshair class="h-4 w-4" />
                {{ $t('map.placement_hint') }}
            </div>

            <!-- Peta + panel detail -->
            <div ref="mapBox" class="relative h-[78vh] min-h-[420px] overflow-hidden rounded-xl border border-white/10">
                <OnuMap
                    ref="mapRef"
                    :pins="pins"
                    :odps="odps"
                    :center="default_center"
                    :add-mode="addMode"
                    :selected-id="selectedPinId"
                    :selected-odp-id="selectedOdpId"
                    :draft="draftCoords"
                    @map-click="onMapClick"
                    @select-pin="onSelectPin"
                    @select-odp="onSelectOdp"
                    @pin-position="cardPos = $event"
                    @odp-position="odpCardPos = $event"
                    @pin-moved="onPinMoved"
                    @odp-moved="onOdpMoved"
                />

                <!-- Kartu detail pin ONU — menempel tepat di atas pin, maks setinggi peta -->
                <div
                    v-if="selectedPin && cardPos"
                    class="kv-pin-popup absolute z-[500] w-72"
                    :style="cardStyle"
                >
                    <div ref="pinCardEl" class="overflow-y-auto overscroll-contain rounded-2xl" :style="{ maxHeight: cardMaxHeight }">
                        <PinDetailCard :pin="selectedPin" @close="closeDetail" />
                    </div>
                    <span class="kv-pin-popup__arrow"></span>
                </div>

                <!-- Kartu detail pin ODP — sedikit lebih lebar dari kartu ONU karena memuat
                     daftar ONU anggota; dibatasi lebar layar supaya tak meluber di ponsel. -->
                <div
                    v-if="selectedOdp && odpCardPos"
                    class="kv-pin-popup absolute z-[500] w-80 max-w-[calc(100vw-1.5rem)]"
                    :style="odpCardStyle"
                >
                    <div ref="odpCardEl" class="overflow-y-auto overscroll-contain rounded-2xl" :style="{ maxHeight: cardMaxHeight }">
                        <OdpDetailCard
                            :odp="selectedOdp"
                            :palette="odp_color_palette"
                            :port-count="selectedOdpPortCount"
                            @close="closeOdpDetail"
                        />
                    </div>
                    <span class="kv-pin-popup__arrow"></span>
                </div>

                <!-- Empty hint -->
                <div v-if="!pins.length && !odps.length && !addMode" class="pointer-events-none absolute inset-x-0 bottom-6 z-[400] flex justify-center">
                    <div class="pointer-events-auto flex items-center gap-2 rounded-full border border-white/10 bg-slate-900/85 px-4 py-2 text-sm text-slate-300 backdrop-blur">
                        <MapPin class="h-4 w-4 text-cyan-400" />
                        {{ $t('map.empty_before') }} <button type="button" class="font-semibold text-cyan-300 underline" @click="toggleAddMode">{{ $t('map.add_pin') }}</button> {{ $t('map.empty_after') }}
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
            :loading="onusLoading"
            @close="closeModal"
            @saved="onSaved"
        />
    </AuthenticatedLayout>
</template>

<style scoped>
/* Kartu detail melayang tepat di atas pin (titik = ujung bawah pin). */
.kv-pin-popup {
    transform: translate(-50%, calc(-100% - 34px));
    filter: drop-shadow(0 10px 25px rgb(var(--kv-black) / 0.45));
}

/* Panah penunjuk ke pin — diamond serasi kaca kartu. */
.kv-pin-popup__arrow {
    position: absolute;
    left: 50%;
    bottom: -6px;
    width: 12px;
    height: 12px;
    margin-left: -6px;
    /* Sewarna kartu (bg-canvas-3/95) — ikut tema. */
    background: rgb(var(--kv-canvas-3) / 0.95);
    border-right: 1px solid rgb(var(--kv-white) / 0.1);
    border-bottom: 1px solid rgb(var(--kv-white) / 0.1);
    transform: rotate(45deg);
    backdrop-filter: blur(16px);
}
</style>
