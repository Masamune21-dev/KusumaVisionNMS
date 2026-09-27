<script setup>
import { DEFAULT_ODP_COLOR, odpColor, textOn } from '@/lib/odpColors';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { onBeforeUnmount, onMounted, ref, toRaw, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const { t, locale } = useI18n({ useScope: 'global' });

// Warna status ONU disederhanakan: hanya hijau (online) / merah (offline/LOS/dying-gasp).
const ONLINE_COLOR = '#10b981'; // emerald-500
const OFFLINE_COLOR = '#ef4444'; // red-500
// Warna pin ODP kini per-ODP (kolom `odps.color`, biasanya seragam per PON port);
// konstanta ini hanya nilai bawaan/legenda bila ODP belum diwarnai.
const ODP_COLOR = DEFAULT_ODP_COLOR;

const props = defineProps({
    pins: { type: Array, default: () => [] },
    odps: { type: Array, default: () => [] },
    center: { type: Object, default: () => ({ lat: -6.7559, lng: 111.0381, zoom: 11 }) },
    addMode: { type: Boolean, default: false },
    selectedId: { type: [Number, null], default: null },
    selectedOdpId: { type: [Number, null], default: null },
    draft: { type: Object, default: null },
});

const emit = defineEmits([
    'map-click',
    'select-pin',
    'select-odp',
    'pin-position',
    'odp-position',
    'pin-moved',
    'odp-moved',
]);

const mapEl = ref(null);
let map = null;
let markerLayer = null;
let odpLayer = null;
let lineLayer = null;
let flowLayer = null;
let dotLayer = null;
let dotRenderer = null;
let lineRenderer = null;
let draftMarker = null;
// pin.id / odp.id -> { marker, sig }. Marker di-update di tempat (bukan dibuat ulang) supaya
// perubahan prop — mis. setelah pin digeser atau dikunci — tidak mengedipkan seluruh peta.
const markers = new Map();
const odpMarkers = new Map();
// id marker yang sedang diseret pengguna — posisinya jangan ditimpa oleh prop.
let draggingPinId = null;
let draggingOdpId = null;

// Kinerja (lihat WORKLOG 25 Sep 2026): tiap pin DOM = satu elemen HTML + SVG + filter
// bayangan, jadi ribuan pin sekaligus membuat peta tersendat. Maka:
//  - pin DOM (teardrop, bisa diseret) hanya dibuat untuk yang ada di layar (+ margin), dan
//    hanya bila jumlahnya ≤ DOM_LIMIT; di atas itu (zoom jauh) semua pin digambar sebagai
//    titik di SATU kanvas — ringan untuk ribuan titik, tetap bisa diklik;
//  - garis ODP→ONU digambar di kanvas tanpa animasi; aliran animasi (SVG) hanya untuk ODP
//    yang sedang dipilih / ODP induk pin yang dipilih.
const DOM_LIMIT = 350;
const VIEW_PAD = 0.25;
let domMode = true;

// Inertia membungkus prop dengan proxy reaktif dalam-dalam; loop ribuan item lewat proxy
// jauh lebih lambat, jadi iterasi memakai objek mentahnya.
const rawPins = () => toRaw(props.pins) ?? [];
const rawOdps = () => toRaw(props.odps) ?? [];
const hasCoords = (o) => o.latitude != null && o.longitude != null;

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
    // Pin terbuka (locked=false) ditandai supaya jelas mana yang bisa digeser.
    if (pin.locked === false) cls.push('kv-pin--unlocked');
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

// Pin ODP — bentuk teardrop sama dgn pin ONU, warna dari kolom `odps.color` (default amber)
// + badge jumlah ONU terhubung. Warna teks badge ikut kecerahan warna pin supaya tetap terbaca.
const buildOdpIcon = (odp, selected) => {
    const count = (odp.onus ?? []).length;
    const color = odpColor(odp);
    const cls = ['kv-odp-pin'];
    if (selected) cls.push('kv-odp-pin--selected');
    if (odp.locked === false) cls.push('kv-pin--unlocked');
    return L.divIcon({
        className: '',
        html: `<div class="${cls.join(' ')}">
            <svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true">
                <path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"
                      fill="${color}" stroke="#ffffff" stroke-width="1.5" stroke-linejoin="round" />
                <circle cx="12" cy="10" r="3" fill="#ffffff" />
            </svg>
            ${count ? `<span class="kv-odp-pin__badge" style="background:${color};color:${textOn(color)}">${count}</span>` : ''}
        </div>`,
        iconSize: [26, 26],
        iconAnchor: [13, 24],
        popupAnchor: [0, -22],
    });
};

const onuKey = (o) => `${o.snmp_olt_id}/${o.slot}/${o.port}/${o.onu_id}`;

// Posisi marker DOM hidup dipakai lebih dulu agar garis ikut bergerak selagi pin/ODP diseret.
const liveCoords = (entry, fallback) => {
    const ll = entry?.marker.getLatLng();

    return ll ? [ll.lat, ll.lng] : fallback;
};

// ODP yang garisnya dianimasikan: ODP terpilih, atau ODP induk pin ONU yang terpilih.
const flowOdpId = () => {
    if (props.selectedOdpId != null) return props.selectedOdpId;
    if (props.selectedId == null) return null;
    const pin = rawPins().find((p) => p.id === props.selectedId);
    if (!pin) return null;
    const key = onuKey(pin);

    return rawOdps().find((o) => (o.onus ?? []).some((u) => onuKey(u) === key))?.id ?? null;
};

// Garis kabel ODP→ONU: warna ikut status ONU (hijau/merah). Koordinat ujung ONU diambil dari
// prop `pins` (sumber kebenaran yang ikut ter-update saat pin digeser); nilai bawaan di
// `odp.onus` cuma cadangan bila pin-nya tak ada di prop.
// Semua garis diam digabung jadi DUA polyline multi-ruas (online/offline) di kanvas — ribuan
// objek SVG beranimasi dulu memaksa browser menggambar ulang tiap frame. Garis ODP yang
// sedang dipilih tetap SVG beranimasi (kelas `kv-flow`) di lapisan tersendiri.
const renderLines = () => {
    if (!lineLayer) return;
    lineLayer.clearLayers();
    flowLayer.clearLayers();

    const pinCoords = new Map();
    for (const p of rawPins()) {
        if (hasCoords(p)) pinCoords.set(onuKey(p), liveCoords(markers.get(p.id), [p.latitude, p.longitude]));
    }

    const flowId = flowOdpId();
    const online = [];
    const offline = [];

    for (const odp of rawOdps()) {
        if (!hasCoords(odp)) continue;
        const start = liveCoords(odpMarkers.get(odp.id), [odp.latitude, odp.longitude]);
        for (const onu of odp.onus ?? []) {
            const end = pinCoords.get(onuKey(onu)) ?? (hasCoords(onu) ? [onu.latitude, onu.longitude] : null);
            if (!end) continue;
            const color = onu.online ? ONLINE_COLOR : OFFLINE_COLOR;
            if (odp.id === flowId) {
                L.polyline([start, end], { color, weight: 2.5, opacity: 0.95, className: 'kv-flow', interactive: false })
                    .addTo(flowLayer);
            } else {
                (onu.online ? online : offline).push([start, end]);
            }
        }
    }

    const staticLine = (segments, color) =>
        segments.length &&
        L.polyline(segments, { color, weight: 2, opacity: 0.6, renderer: lineRenderer, interactive: false })
            .addTo(lineLayer);
    staticLine(online, ONLINE_COLOR);
    staticLine(offline, OFFLINE_COLOR);
};

// Sinkronkan posisi/tampilan/draggable marker yang sudah ada dengan data terbaru.
// `sig` merangkum semua yang mempengaruhi ikon, jadi setIcon (bikin ulang elemen DOM)
// hanya dijalankan saat benar-benar berubah.
const syncMarker = (entry, sig, coords, unlocked, buildNextIcon, isDragging) => {
    const { marker } = entry;
    const pos = marker.getLatLng();
    if (!isDragging && (pos.lat !== coords[0] || pos.lng !== coords[1])) {
        marker.setLatLng(coords);
    }

    if (entry.sig !== sig) {
        marker.setIcon(buildNextIcon());
        entry.sig = sig;
    }

    marker.options.draggable = unlocked;
    if (unlocked) marker.dragging?.enable();
    else marker.dragging?.disable();
};

// Selama marker diseret, gambar ulang garis paling sering sekali per frame.
let lineFrame = null;
const scheduleLines = () => {
    if (lineFrame) return;
    lineFrame = requestAnimationFrame(() => {
        lineFrame = null;
        renderLines();
    });
};

// Batas layar (+ margin) saat terakhir dihitung; dipakai memilih pin yang layak jadi marker DOM.
let viewBounds = null;
const inView = (o) => hasCoords(o) && viewBounds != null && viewBounds.contains([o.latitude, o.longitude]);
// Marker DOM dibuat hanya bila mode DOM dan ada di layar. Yang terpilih atau sedang diseret
// selalu DOM supaya sorotan & drag-nya tetap jalan di zoom berapa pun.
const wantsDom = (o, selected, dragging) => selected || dragging || (domMode && inView(o));

const renderOdps = () => {
    if (!odpLayer) return;

    const seen = new Set();

    for (const odp of rawOdps()) {
        if (!hasCoords(odp)) continue;

        const selected = odp.id === props.selectedOdpId;
        if (!wantsDom(odp, selected, draggingOdpId === odp.id)) continue;
        seen.add(odp.id);

        const unlocked = odp.locked === false;
        // Warna ikut sig — tanpa ini marker dianggap "tak berubah" dan pin tetap warna lama
        // sampai halaman dimuat ulang.
        const sig = `${selected}|${unlocked}|${odp.name}|${(odp.onus ?? []).length}|${odpColor(odp)}`;
        const entry = odpMarkers.get(odp.id);

        if (entry) {
            entry.marker.options.title = odp.name;
            syncMarker(
                entry,
                sig,
                [odp.latitude, odp.longitude],
                unlocked,
                () => buildOdpIcon(odp, selected),
                draggingOdpId === odp.id,
            );
            continue;
        }

        const marker = L.marker([odp.latitude, odp.longitude], {
            icon: buildOdpIcon(odp, selected),
            title: odp.name,
            riseOnHover: true,
            zIndexOffset: 500,
            draggable: unlocked,
        });
        marker.on('click', () => emit('select-odp', odp.id));
        // Selama digeser, kartu detail ikut menempel; koordinat disimpan saat dilepas.
        marker.on('dragstart', () => (draggingOdpId = odp.id));
        marker.on('drag', () => {
            emitOdpPosition(marker.getLatLng());
            scheduleLines();
        });
        marker.on('dragend', () => {
            draggingOdpId = null;
            const { lat, lng } = marker.getLatLng();
            emit('odp-moved', { id: odp.id, latitude: lat, longitude: lng });
        });
        marker.addTo(odpLayer);
        odpMarkers.set(odp.id, { marker, sig });
    }

    for (const [id, entry] of odpMarkers) {
        if (seen.has(id)) continue;
        odpLayer.removeLayer(entry.marker);
        odpMarkers.delete(id);
    }
};

// Posisi piksel pin terpilih (relatif container) — dipakai induk untuk menempel kartu detail di atas pin.
// $latLng = override koordinat (dipakai saat marker sedang digeser, karena prop belum berubah).
const emitPinPosition = (latLng = null) => {
    if (!map) return;
    const pin = props.selectedId == null ? null : rawPins().find((p) => p.id === props.selectedId);
    if (!pin || !hasCoords(pin)) {
        emit('pin-position', null);
        return;
    }
    const pt = map.latLngToContainerPoint(latLng ?? [pin.latitude, pin.longitude]);
    emit('pin-position', { id: pin.id, x: pt.x, y: pt.y });
};

// Posisi piksel pin ODP terpilih — untuk menempel kartu detail ODP di atasnya.
const emitOdpPosition = (latLng = null) => {
    if (!map) return;
    const odp = props.selectedOdpId == null ? null : rawOdps().find((o) => o.id === props.selectedOdpId);
    if (!odp || !hasCoords(odp)) {
        emit('odp-position', null);
        return;
    }
    const pt = map.latLngToContainerPoint(latLng ?? [odp.latitude, odp.longitude]);
    emit('odp-position', { id: odp.id, x: pt.x, y: pt.y });
};

const renderPins = () => {
    if (!markerLayer) return;

    const seen = new Set();

    for (const pin of rawPins()) {
        if (!hasCoords(pin)) continue;

        const selected = pin.id === props.selectedId;
        if (!wantsDom(pin, selected, draggingPinId === pin.id)) continue;
        seen.add(pin.id);

        const unlocked = pin.locked === false;
        const title = pin.customer_name || pin.interface || `ONU #${pin.onu_id}`;
        const sig = `${selected}|${unlocked}|${pin.online}|${title}`;
        const entry = markers.get(pin.id);

        if (entry) {
            entry.marker.options.title = title;
            syncMarker(
                entry,
                sig,
                [pin.latitude, pin.longitude],
                unlocked,
                () => buildIcon(pin, selected),
                draggingPinId === pin.id,
            );
            continue;
        }

        const marker = L.marker([pin.latitude, pin.longitude], {
            icon: buildIcon(pin, selected),
            title,
            riseOnHover: true,
            draggable: unlocked,
        });
        marker.on('click', () => emit('select-pin', pin.id));
        marker.on('dragstart', () => (draggingPinId = pin.id));
        marker.on('drag', () => {
            emitPinPosition(marker.getLatLng());
            scheduleLines();
        });
        marker.on('dragend', () => {
            draggingPinId = null;
            const { lat, lng } = marker.getLatLng();
            emit('pin-moved', { id: pin.id, latitude: lat, longitude: lng });
        });
        marker.addTo(markerLayer);
        markers.set(pin.id, { marker, sig });
    }

    for (const [id, entry] of markers) {
        if (seen.has(id)) continue;
        markerLayer.removeLayer(entry.marker);
        markers.delete(id);
    }
};

// Mode titik: semua pin digambar sebagai lingkaran di satu kanvas (pane `kvDots`, di atas
// garis dan di bawah marker DOM). Dibangun ulang hanya saat data atau mode berubah — geser/
// zoom cukup digambar ulang oleh renderer kanvas.
let dotsDirty = true;
const renderDots = () => {
    if (!dotLayer) return;
    dotLayer.clearLayers();
    if (domMode) return;

    for (const pin of rawPins()) {
        if (!hasCoords(pin)) continue;
        L.circleMarker([pin.latitude, pin.longitude], {
            renderer: dotRenderer,
            radius: 4.5,
            color: '#ffffff',
            weight: 1,
            fillColor: pin.online ? ONLINE_COLOR : OFFLINE_COLOR,
            fillOpacity: 1,
            // Seperti marker DOM: klik titik tak ikut memicu klik peta (mode tambah pin).
            bubblingMouseEvents: false,
        })
            .on('click', () => emit('select-pin', pin.id))
            .addTo(dotLayer);
    }
    // ODP digambar belakangan supaya berada di atas titik ONU.
    for (const odp of rawOdps()) {
        if (!hasCoords(odp)) continue;
        L.circleMarker([odp.latitude, odp.longitude], {
            renderer: dotRenderer,
            radius: 6.5,
            color: '#ffffff',
            weight: 1.5,
            fillColor: odpColor(odp),
            fillOpacity: 1,
            // Seperti marker DOM: klik titik tak ikut memicu klik peta (mode tambah pin).
            bubblingMouseEvents: false,
        })
            .on('click', () => emit('select-odp', odp.id))
            .addTo(dotLayer);
    }
};

// Hitung ulang batas layar & mode, lalu sinkronkan marker DOM (dipanggil saat `moveend` dan
// saat data berubah). Hitungan cuma perbandingan koordinat — murah walau ribuan pin.
const refreshView = () => {
    if (!map) return;
    viewBounds = map.getBounds().pad(VIEW_PAD);

    let visible = 0;
    for (const p of rawPins()) if (inView(p)) visible++;
    for (const o of rawOdps()) if (inView(o)) visible++;

    const nextDom = visible <= DOM_LIMIT;
    if (nextDom !== domMode) {
        domMode = nextDom;
        dotsDirty = true;
    }

    renderPins();
    renderOdps();
    if (dotsDirty) {
        dotsDirty = false;
        renderDots();
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

    // Urutan tambah menentukan z-order: garis kanvas → garis beranimasi → titik (pane sendiri,
    // di bawah markerPane) → pin ONU → pin ODP.
    map.createPane('kvDots').style.zIndex = 450;
    lineRenderer = L.canvas({ padding: 0.5 });
    dotRenderer = L.canvas({ padding: 0.5, pane: 'kvDots' });
    lineLayer = L.layerGroup().addTo(map);
    flowLayer = L.layerGroup().addTo(map);
    dotLayer = L.layerGroup().addTo(map);
    markerLayer = L.layerGroup().addTo(map);
    odpLayer = L.layerGroup().addTo(map);

    map.on('click', (e) => {
        if (props.addMode) {
            emit('map-click', { lat: e.latlng.lat, lng: e.latlng.lng });
        }
    });

    // Jaga kartu detail tetap menempel di atas pin/ODP saat peta digeser/zoom.
    map.on('move zoom resize', () => { emitPinPosition(); emitOdpPosition(); });
    // Marker DOM hanya untuk pin di layar — sinkronkan setiap kali geser/zoom selesai.
    map.on('moveend', refreshView);

    applyCursor();
    refreshView();
    renderLines();
    renderDraft();
    emitPinPosition();
    emitOdpPosition();

    // Leaflet kadang render tile abu-abu bila container baru di-layout.
    setTimeout(() => map && map.invalidateSize(), 200);
});

onBeforeUnmount(() => {
    if (lineFrame) {
        cancelAnimationFrame(lineFrame);
        lineFrame = null;
    }
    if (map) {
        map.remove();
        map = null;
    }
});

// Tanpa `deep`: Inertia selalu mengganti array prop utuh saat reload, dan watcher deep
// menelusuri ribuan objek (+ ONU di tiap ODP) di setiap pemicu.
const onDataChange = () => {
    dotsDirty = true;
    refreshView();
    renderLines();
};
watch(() => props.pins, () => { onDataChange(); emitPinPosition(); });
watch(() => props.odps, () => { onDataChange(); emitOdpPosition(); });
watch(() => props.selectedId, () => { renderPins(); renderLines(); emitPinPosition(); });
watch(() => props.selectedOdpId, () => { renderOdps(); renderLines(); emitOdpPosition(); });
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
    background: rgb(var(--kv-slate-900));
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

/* Pin terbuka (bisa digeser) — cincin cyan putus-putus + kursor pindah. Dipakai
   pin ONU maupun pin ODP. */
.kv-pin--unlocked {
    cursor: move;
}

.kv-pin--unlocked::before {
    content: '';
    position: absolute;
    left: 50%;
    top: 11px;
    width: 26px;
    height: 26px;
    margin-left: -13px;
    margin-top: -13px;
    border-radius: 9999px;
    border: 2px dashed rgba(34, 211, 238, 0.9);
    animation: kv-pin-spin 6s linear infinite;
}

@keyframes kv-pin-spin {
    to {
        transform: rotate(360deg);
    }
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
    background: rgba(239, 68, 68, 0.55);
    z-index: -1;
    /* transform + opacity saja → dikerjakan compositor, tanpa repaint tiap frame
       (animasi box-shadow lama memaksa repaint untuk setiap pin offline). */
    animation: kv-pin-ripple 1.8s ease-out infinite;
    will-change: transform, opacity;
}

@keyframes kv-pin-ripple {
    0% {
        transform: scale(1);
        opacity: 0.8;
    }
    70%,
    100% {
        transform: scale(2.5);
        opacity: 0;
    }
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

/* Pin ODP — teardrop (sama bentuk dgn pin ONU) + badge jumlah ONU terhubung. Warna isi
   pin & badge disuntik inline dari `odps.color`, jadi aksen di sini dijaga netral. */
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
    filter: drop-shadow(0 0 5px rgba(255, 255, 255, 0.9)) drop-shadow(0 1px 2px rgba(0, 0, 0, 0.5));
}

.kv-odp-pin__badge {
    position: absolute;
    top: -5px;
    right: -3px;
    min-width: 15px;
    height: 15px;
    padding: 0 3px;
    border-radius: 9999px;
    /* Fallback; ditimpa inline mengikuti warna ODP + kontras teksnya. */
    background: #0f172a;
    border: 1px solid rgba(255, 255, 255, 0.75);
    color: #ffffff;
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
    background: rgb(var(--kv-slate-900) / 0.92);
    border: 1px solid rgb(var(--kv-slate-400) / 0.25);
    border-radius: 0.5rem;
    padding: 0.5rem 0.65rem;
    font-size: 11px;
    color: rgb(var(--kv-slate-300));
    line-height: 1.6;
    backdrop-filter: blur(6px);
}

.kv-map-legend__title {
    font-weight: 600;
    color: rgb(var(--kv-slate-100));
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

/* Kontrol Leaflet — lewat token, jadi ikut tema (nilai gelap identik dengan
   heks lama; di tema terang jadi kartu putih bertinta gelap). */
.leaflet-control-layers,
.leaflet-bar {
    background: rgb(var(--kv-slate-900) / 0.92) !important;
    color: rgb(var(--kv-slate-200)) !important;
    border: 1px solid rgb(var(--kv-slate-400) / 0.25) !important;
}

.leaflet-control-layers-expanded {
    color: rgb(var(--kv-slate-200)) !important;
}

.leaflet-bar a {
    background: rgb(var(--kv-slate-900) / 0.92) !important;
    color: rgb(var(--kv-slate-200)) !important;
}

.leaflet-bar a:hover {
    background: rgb(var(--kv-slate-800) / 0.95) !important;
}

.leaflet-control-attribution {
    background: rgb(var(--kv-slate-900) / 0.7) !important;
    color: rgb(var(--kv-slate-400)) !important;
}

.leaflet-control-attribution a {
    color: rgb(var(--kv-sky-300)) !important;
}
</style>
