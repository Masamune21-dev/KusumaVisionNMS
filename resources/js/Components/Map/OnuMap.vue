<script setup>
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { rxMarkerColor } from '@/Composables/useRxLevel';

const props = defineProps({
    pins: { type: Array, default: () => [] },
    center: { type: Object, default: () => ({ lat: -6.7559, lng: 111.0381, zoom: 11 }) },
    addMode: { type: Boolean, default: false },
    selectedId: { type: [Number, null], default: null },
    draft: { type: Object, default: null },
});

const emit = defineEmits(['map-click', 'select-pin', 'pin-position']);

const mapEl = ref(null);
let map = null;
let markerLayer = null;
let draftMarker = null;
const markers = new Map(); // pin.id -> L.marker

// Tile Google keyless (tidak resmi, gratis, cocok untuk NMS internal) + OSM fallback.
const googleLayer = (lyrs) =>
    L.tileLayer(`https://mt{s}.google.com/vt/lyrs=${lyrs}&x={x}&y={y}&z={z}&hl=id`, {
        subdomains: ['0', '1', '2', '3'],
        maxZoom: 21,
        attribution: '&copy; Google',
    });

const buildIcon = (pin, selected) => {
    const color = rxMarkerColor(pin.rx_power_dbm, pin.online);
    const cls = ['kv-pin'];
    if (selected) cls.push('kv-pin--selected');
    if (!pin.online) cls.push('kv-pin--offline');
    return L.divIcon({
        className: '',
        html: `<div class="${cls.join(' ')}">
            <svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true">
                <path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"
                      fill="${color}" stroke="#ffffff" stroke-width="1.5" stroke-linejoin="round" />
                <circle cx="12" cy="10" r="3" fill="#ffffff" />
            </svg>
        </div>`,
        iconSize: [26, 26],
        iconAnchor: [13, 24],
        popupAnchor: [0, -22],
    });
};

// Posisi piksel pin terpilih (relatif container) — dipakai induk untuk menempel kartu detail di atas pin.
const emitPinPosition = () => {
    if (!map) return;
    const pin = props.pins.find((p) => p.id === props.selectedId);
    if (!pin || pin.latitude == null || pin.longitude == null) {
        emit('pin-position', null);
        return;
    }
    const pt = map.latLngToContainerPoint([pin.latitude, pin.longitude]);
    emit('pin-position', { id: pin.id, x: pt.x, y: pt.y });
};

const renderPins = () => {
    if (!markerLayer) return;
    markerLayer.clearLayers();
    markers.clear();

    for (const pin of props.pins) {
        if (pin.latitude == null || pin.longitude == null) continue;
        const marker = L.marker([pin.latitude, pin.longitude], {
            icon: buildIcon(pin, pin.id === props.selectedId),
            title: pin.customer_name || pin.interface || `ONU #${pin.onu_id}`,
            riseOnHover: true,
        });
        marker.on('click', () => emit('select-pin', pin.id));
        marker.addTo(markerLayer);
        markers.set(pin.id, marker);
    }
};

const renderDraft = () => {
    if (!map) return;
    if (draftMarker) {
        map.removeLayer(draftMarker);
        draftMarker = null;
    }
    if (props.draft && props.draft.lat != null && props.draft.lng != null) {
        draftMarker = L.marker([props.draft.lat, props.draft.lng], {
            icon: L.divIcon({
                className: 'kv-onu-pin',
                html: '<span class="kv-onu-pin__draft"></span>',
                iconSize: [26, 26],
                iconAnchor: [13, 13],
            }),
            zIndexOffset: 1000,
        }).addTo(map);
    }
};

const applyCursor = () => {
    if (!mapEl.value) return;
    mapEl.value.style.cursor = props.addMode ? 'crosshair' : '';
};

onMounted(() => {
    const streets = googleLayer('m');
    const hybrid = googleLayer('y');
    const satellite = googleLayer('s');
    const terrain = googleLayer('p');
    const osm = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors',
    });

    map = L.map(mapEl.value, {
        center: [props.center.lat, props.center.lng],
        zoom: props.center.zoom ?? 11,
        layers: [osm],
        zoomControl: true,
        attributionControl: true,
    });

    L.control
        .layers(
            {
                'Google Streets': streets,
                'Google Satelit': satellite,
                'Google Hybrid': hybrid,
                'Google Terrain': terrain,
                OpenStreetMap: osm,
            },
            {},
            { position: 'topright' },
        )
        .addTo(map);

    // Legenda level redaman RX.
    const legend = L.control({ position: 'bottomright' });
    legend.onAdd = () => {
        const div = L.DomUtil.create('div', 'kv-map-legend');
        div.innerHTML = `
            <div class="kv-map-legend__title">Redaman RX</div>
            <div><span style="background:#10b981"></span> Baik</div>
            <div><span style="background:#f59e0b"></span> Waspada</div>
            <div><span style="background:#ef4444"></span> Kritis</div>
            <div><span style="background:#64748b"></span> Offline / N/A</div>`;
        return div;
    };
    legend.addTo(map);

    markerLayer = L.layerGroup().addTo(map);

    map.on('click', (e) => {
        if (props.addMode) {
            emit('map-click', { lat: e.latlng.lat, lng: e.latlng.lng });
        }
    });

    // Jaga kartu detail tetap menempel di atas pin saat peta digeser/zoom.
    map.on('move zoom resize', emitPinPosition);

    applyCursor();
    renderPins();
    renderDraft();
    emitPinPosition();

    // Leaflet kadang render tile abu-abu bila container baru di-layout.
    setTimeout(() => map && map.invalidateSize(), 200);
});

onBeforeUnmount(() => {
    if (map) {
        map.remove();
        map = null;
    }
});

watch(() => props.pins, () => { renderPins(); emitPinPosition(); }, { deep: true });
watch(() => props.selectedId, () => { renderPins(); emitPinPosition(); });
watch(() => props.draft, renderDraft, { deep: true });
watch(() => props.addMode, applyCursor);
watch(
    () => props.center,
    (c) => {
        if (map && c) map.setView([c.lat, c.lng], c.zoom ?? map.getZoom());
    },
    { deep: true },
);

// Diekspos agar induk bisa memusatkan peta ke pin terpilih.
defineExpose({
    flyTo(lat, lng, zoom = 16) {
        if (!map) return;
        // Jangan zoom-out bila sudah lebih dekat.
        const targetZoom = Math.max(map.getZoom(), zoom);
        // Geser center ke atas pin agar pin tampil di bawah-tengah → cukup ruang untuk kartu detail.
        const pt = map.project([lat, lng], targetZoom).subtract([0, 130]);
        map.flyTo(map.unproject(pt, targetZoom), targetZoom);
    },
});
</script>

<template>
    <div ref="mapEl" class="kv-onu-map"></div>
</template>

<style>
.kv-onu-map {
    height: 100%;
    width: 100%;
    border-radius: 0.75rem;
    background: #0f172a;
}

/* Pin ONU — bentuk pin peta (teardrop) berwarna sesuai level RX. */
.kv-pin {
    position: relative;
    width: 26px;
    height: 26px;
}

.kv-pin svg {
    display: block;
    filter: drop-shadow(0 1px 2px rgba(0, 0, 0, 0.5));
    transform-origin: 50% 92%;
    transition: transform 0.15s ease;
}

.kv-pin--selected svg {
    transform: scale(1.18);
    filter: drop-shadow(0 0 5px rgba(56, 189, 248, 0.9)) drop-shadow(0 1px 2px rgba(0, 0, 0, 0.5));
}

/* Cincin pulsa di kepala pin untuk ONU offline. */
.kv-pin--offline::after {
    content: '';
    position: absolute;
    left: 50%;
    top: 11px;
    width: 16px;
    height: 16px;
    margin-left: -8px;
    margin-top: -8px;
    border-radius: 9999px;
    background: rgba(100, 116, 139, 0.55);
    z-index: -1;
    animation: kv-pin-pulse 1.8s ease-out infinite;
}

@keyframes kv-pin-pulse {
    0% {
        box-shadow: 0 0 0 0 rgba(100, 116, 139, 0.6);
    }
    70% {
        box-shadow: 0 0 0 12px rgba(100, 116, 139, 0);
    }
    100% {
        box-shadow: 0 0 0 0 rgba(100, 116, 139, 0);
    }
}

/* Marker draft saat menempatkan pin baru. */
.kv-onu-pin__draft {
    display: block;
    width: 22px;
    height: 22px;
    border-radius: 9999px;
    background: rgba(56, 189, 248, 0.35);
    border: 2px dashed #38bdf8;
    animation: kv-pin-pulse 1.4s ease-out infinite;
}

.kv-map-legend {
    background: rgba(15, 23, 42, 0.92);
    border: 1px solid rgba(148, 163, 184, 0.25);
    border-radius: 0.5rem;
    padding: 0.5rem 0.65rem;
    font-size: 11px;
    color: #cbd5e1;
    line-height: 1.6;
    backdrop-filter: blur(6px);
}

.kv-map-legend__title {
    font-weight: 600;
    color: #f1f5f9;
    margin-bottom: 2px;
}

.kv-map-legend span {
    display: inline-block;
    width: 10px;
    height: 10px;
    border-radius: 9999px;
    margin-right: 5px;
    vertical-align: middle;
}

/* Kontrol Leaflet — selaraskan dengan tema gelap dashboard. */
.leaflet-control-layers,
.leaflet-bar {
    background: rgba(15, 23, 42, 0.92) !important;
    color: #e2e8f0 !important;
    border: 1px solid rgba(148, 163, 184, 0.25) !important;
}

.leaflet-control-layers-expanded {
    color: #e2e8f0 !important;
}

.leaflet-bar a {
    background: rgba(15, 23, 42, 0.92) !important;
    color: #e2e8f0 !important;
}

.leaflet-bar a:hover {
    background: rgba(30, 41, 59, 0.95) !important;
}

.leaflet-control-attribution {
    background: rgba(15, 23, 42, 0.7) !important;
    color: #94a3b8 !important;
}

.leaflet-control-attribution a {
    color: #7dd3fc !important;
}
</style>
