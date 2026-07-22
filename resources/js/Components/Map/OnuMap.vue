<script setup>
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const { t, locale } = useI18n({ useScope: 'global' });

// Warna status ONU disederhanakan: hanya hijau (online) / merah (offline/LOS/dying-gasp).
const ONLINE_COLOR = '#10b981'; // emerald-500
const OFFLINE_COLOR = '#ef4444'; // red-500
const ODP_COLOR = '#f59e0b'; // amber-500 — pin ODP kuning

const props = defineProps({
    pins: { type: Array, default: () => [] },
    odps: { type: Array, default: () => [] },
    center: { type: Object, default: () => ({ lat: -6.7559, lng: 111.0381, zoom: 11 }) },
    addMode: { type: Boolean, default: false },
    selectedId: { type: [Number, null], default: null },
    selectedOdpId: { type: [Number, null], default: null },
    draft: { type: Object, default: null },
});

const emit = defineEmits(['map-click', 'select-pin', 'select-odp', 'pin-position', 'odp-position']);

const mapEl = ref(null);
let map = null;
let markerLayer = null;
let odpLayer = null;
let lineLayer = null;
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
    const color = pin.online ? ONLINE_COLOR : OFFLINE_COLOR;
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

// Pin ODP — bentuk teardrop sama dgn pin ONU, warna kuning + badge jumlah ONU terhubung.
const buildOdpIcon = (odp, selected) => {
    const count = (odp.onus ?? []).length;
    const cls = ['kv-odp-pin'];
    if (selected) cls.push('kv-odp-pin--selected');
    return L.divIcon({
        className: '',
        html: `<div class="${cls.join(' ')}">
            <svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true">
                <path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"
                      fill="${ODP_COLOR}" stroke="#ffffff" stroke-width="1.5" stroke-linejoin="round" />
                <circle cx="12" cy="10" r="3" fill="#ffffff" />
            </svg>
            ${count ? `<span class="kv-odp-pin__badge">${count}</span>` : ''}
        </div>`,
        iconSize: [26, 26],
        iconAnchor: [13, 24],
        popupAnchor: [0, -22],
    });
};

// Garis animasi ODP→ONU: warna ikut status ONU (hijau/merah), aliran via stroke-dashoffset (CSS).
const renderLines = () => {
    if (!lineLayer) return;
    lineLayer.clearLayers();

    for (const odp of props.odps) {
        if (odp.latitude == null || odp.longitude == null) continue;
        for (const onu of odp.onus ?? []) {
            if (onu.latitude == null || onu.longitude == null) continue;
            L.polyline(
                [
                    [odp.latitude, odp.longitude],
                    [onu.latitude, onu.longitude],
                ],
                {
                    color: onu.online ? ONLINE_COLOR : OFFLINE_COLOR,
                    weight: 2.5,
                    opacity: 0.9,
                    className: 'kv-flow',
                },
            ).addTo(lineLayer);
        }
    }
};

const renderOdps = () => {
    if (!odpLayer) return;
    odpLayer.clearLayers();

    for (const odp of props.odps) {
        if (odp.latitude == null || odp.longitude == null) continue;
        const marker = L.marker([odp.latitude, odp.longitude], {
            icon: buildOdpIcon(odp, odp.id === props.selectedOdpId),
            title: odp.name,
            riseOnHover: true,
            zIndexOffset: 500,
        });
        marker.on('click', () => emit('select-odp', odp.id));
        marker.addTo(odpLayer);
    }
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

// Posisi piksel pin ODP terpilih — untuk menempel kartu detail ODP di atasnya.
const emitOdpPosition = () => {
    if (!map) return;
    const odp = props.odps.find((o) => o.id === props.selectedOdpId);
    if (!odp || odp.latitude == null || odp.longitude == null) {
        emit('odp-position', null);
        return;
    }
    const pt = map.latLngToContainerPoint([odp.latitude, odp.longitude]);
    emit('odp-position', { id: odp.id, x: pt.x, y: pt.y });
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

    // Kontrol layer & legenda dibuat ulang saat ganti bahasa (label Leaflet bukan reactive Vue).
    let layersControl = null;
    const addLayersControl = () => {
        if (layersControl) map.removeControl(layersControl);
        layersControl = L.control
            .layers(
                {
                    'Google Streets': streets,
                    [t('map.layer_satellite')]: satellite,
                    'Google Hybrid': hybrid,
                    'Google Terrain': terrain,
                    OpenStreetMap: osm,
                },
                {},
                { position: 'topright' },
            )
            .addTo(map);
    };
    addLayersControl();

    // Legenda status ONU (hijau/merah) + pin ODP kuning.
    const legendHtml = () => `
            <div class="kv-map-legend__title">${t('map.legend_title')}</div>
            <div><span style="background:${ONLINE_COLOR}"></span> ${t('map.legend_online')}</div>
            <div><span style="background:${OFFLINE_COLOR}"></span> ${t('map.legend_offline')}</div>
            <div><span class="kv-map-legend__odp" style="background:${ODP_COLOR}"></span> ${t('map.legend_odp')}</div>`;
    let legendDiv = null;
    const legend = L.control({ position: 'bottomright' });
    legend.onAdd = () => {
        legendDiv = L.DomUtil.create('div', 'kv-map-legend');
        legendDiv.innerHTML = legendHtml();
        return legendDiv;
    };
    legend.addTo(map);

    watch(locale, () => {
        if (legendDiv) legendDiv.innerHTML = legendHtml();
        addLayersControl();
    });

    // Urutan tambah menentukan z-order pane: garis di bawah, lalu pin ONU, lalu pin ODP.
    lineLayer = L.layerGroup().addTo(map);
    markerLayer = L.layerGroup().addTo(map);
    odpLayer = L.layerGroup().addTo(map);

    map.on('click', (e) => {
        if (props.addMode) {
            emit('map-click', { lat: e.latlng.lat, lng: e.latlng.lng });
        }
    });

    // Jaga kartu detail tetap menempel di atas pin/ODP saat peta digeser/zoom.
    map.on('move zoom resize', () => { emitPinPosition(); emitOdpPosition(); });

    applyCursor();
    renderPins();
    renderOdps();
    renderLines();
    renderDraft();
    emitPinPosition();
    emitOdpPosition();

    // Leaflet kadang render tile abu-abu bila container baru di-layout.
    setTimeout(() => map && map.invalidateSize(), 200);
});

onBeforeUnmount(() => {
    if (map) {
        map.remove();
        map = null;
    }
});

watch(() => props.pins, () => { renderPins(); renderLines(); emitPinPosition(); }, { deep: true });
watch(() => props.odps, () => { renderOdps(); renderLines(); emitOdpPosition(); }, { deep: true });
watch(() => props.selectedId, () => { renderPins(); emitPinPosition(); });
watch(() => props.selectedOdpId, () => { renderOdps(); emitOdpPosition(); });
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

/* Cincin pulsa merah di kepala pin untuk ONU offline. */
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
    background: rgba(239, 68, 68, 0.5);
    z-index: -1;
    animation: kv-pin-pulse 1.8s ease-out infinite;
}

@keyframes kv-pin-pulse {
    0% {
        box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.6);
    }
    70% {
        box-shadow: 0 0 0 12px rgba(239, 68, 68, 0);
    }
    100% {
        box-shadow: 0 0 0 0 rgba(239, 68, 68, 0);
    }
}

/* Pin ODP — teardrop kuning (sama bentuk dgn pin ONU) + badge jumlah ONU terhubung. */
.kv-odp-pin {
    position: relative;
    width: 26px;
    height: 26px;
}

.kv-odp-pin svg {
    display: block;
    filter: drop-shadow(0 1px 2px rgba(0, 0, 0, 0.55));
    transform-origin: 50% 92%;
    transition: transform 0.15s ease;
}

.kv-odp-pin--selected svg {
    transform: scale(1.18);
    filter: drop-shadow(0 0 5px rgba(245, 158, 11, 0.95)) drop-shadow(0 1px 2px rgba(0, 0, 0, 0.5));
}

.kv-odp-pin__badge {
    position: absolute;
    top: -5px;
    right: -3px;
    min-width: 15px;
    height: 15px;
    padding: 0 3px;
    border-radius: 9999px;
    background: #0f172a;
    border: 1px solid #f59e0b;
    color: #fde68a;
    font-size: 10px;
    font-weight: 700;
    line-height: 13px;
    text-align: center;
}

/* Garis kabel ODP→ONU dengan aliran animasi (arah pergerakan dash). */
.kv-flow {
    stroke-dasharray: 7 7;
    animation: kv-flow-dash 0.9s linear infinite;
}

@keyframes kv-flow-dash {
    to {
        stroke-dashoffset: -14;
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

/* Penanda ODP di legend = kotak (samakan dgn bentuk pin ODP). */
.kv-map-legend__odp {
    border-radius: 2px !important;
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
