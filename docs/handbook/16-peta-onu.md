# 16 — Peta ONU

Peta geografis sebaran **pin ONU pelanggan** dari semua OLT (ZTE & C-Data). Operator bisa melihat
kualitas redaman RX per lokasi, menambah pin, dan melakukan aksi cepat (ganti nama / reboot) langsung
dari detail pin. Route: `map.index` (`/map`), nav **Peta ONU**.

## Peta & tile (Leaflet)

- Library: [Leaflet](https://leafletjs.com/) (`npm i leaflet`), dimuat **lazy** lewat
  `defineAsyncComponent` di `Pages/Map/Index.vue` agar key manifest Inertia tidak hilang saat build
  (lihat gotcha di [13-troubleshooting](13-troubleshooting-maintenance.md)).
- Komponen peta: `resources/js/Components/Map/OnuMap.vue`.
- Base layer (switcher `L.control.layers`):
  - **Google keyless** via tile XYZ `https://mt{s}.google.com/vt/lyrs={m|s|y|p}` — Streets/Satelit/
    Hybrid/Terrain. Tanpa API key.
  - **OpenStreetMap** sebagai fallback.

> ⚠️ Endpoint tile Google tanpa key bersifat **tidak resmi** (gratis, cocok untuk NMS internal). Bila
> sewaktu-waktu diblokir Google, pakai layer OpenStreetMap dari switcher (sudah tersedia). Untuk
> pemakaian resmi/skala besar, ganti ke Google Maps JS API + API key.

- Marker = `L.divIcon` bulat berwarna sesuai level RX (`Composables/useRxLevel.js`:
  hijau=baik, kuning=waspada, merah=kritis, abu=offline/N/A). Offline diberi animasi pulsa. Legenda
  ada di pojok kanan-bawah.

## Data & penyimpanan

- ONU tetap **tanpa tabel** — pin hanya menyimpan **referensi** ke ONU di cache `port_onus`.
- Tabel `onu_map_pins` (migrasi `2026_06_22_000000`): `snmp_olt_id, slot, port, onu_id` (kunci unik =
  1 pin/ONU), `serial_number` (jangkar identitas), `latitude/longitude`, field pelanggan opsional
  (`customer_name` override, `address`, `phone`, `notes`), `created_by`. Model `App\Models\OnuMapPin`.
- `App\Services\OnuInventoryService` — agregasi ONU lintas-OLT dari cache (`collect()` untuk daftar +
  search global modal; `findOne()` untuk enrich satu pin). **Dipakai bersama** oleh `OnuMapController`
  & `SmartOltController::onuMonitor()`.
- `OnuMapController::index()` mengirim tiap pin sudah di-enrich data ONU **live** (nama, RX, online,
  interface, `if_index`) + `capabilities` OLT-nya, sehingga tombol aksi tahu apakah didukung.

## Menambah pin

Tiga jalur (semua bermuara ke `POST map.pins.store`, `updateOrCreate` per kunci ONU):

1. **Klik di peta** → modal `AddPinModal.vue`: pilih OLT → Port → ONU (dropdown bertingkat) **atau**
   ketik di **search global** (interface/serial/nama/OLT) lalu klik hasil. Koordinat terisi dari titik
   klik (bisa diedit) + field pelanggan opsional.
2. **Tombol "Add Map" di Port ONUs** (`SmartOlt/PortOnus.vue` & `CDataOlt/PortOnus.vue`, per-ONU,
   desktop+mobile) → modal 2 opsi:
   - **Paste link Google Maps** → `POST map.resolve-link` mengekstrak koordinat (regex `@lat,lng` /
     `?q=` / `!3d!4d`; link pendek `maps.app.goo.gl`/`goo.gl` di-follow redirect server-side) → pin
     langsung terpasang.
   - **Klik langsung di map** → buka `/map?place_olt=…&place_slot=…&place_port=…&place_onu=…` (mode
     placement; ONU sudah pra-terpilih, tinggal klik lokasi).

## Aksi di detail pin (`PinDetailCard.vue`)

Klik pin → panel detail (nama pelanggan, OLT, slot/port/onu, badge RX, status online, alamat/HP/catatan).
Tombol (digerbang `capabilities` OLT):

- **Edit Nama** → `POST map.pins.rename` → `OnuMapController::renamePin()` delegasi ke
  `ZteRemoteOnuService::setInfo()` (ZTE, SNMP SET) atau `CDataCliWriteService::setDescription()` (C-Data,
  CLI), update cache nama, **redirect balik ke `/map`**.
- **Reboot** → `POST map.pins.reboot` → `OnuMapController::rebootPin()` delegasi ke service yang sama
  per jenis OLT, balik ke `/map`.
- **Detail ONU** (hanya ZTE + `supports_cli_onu_detail`), **Port** (buka Port ONUs), **Google Maps**
  (link eksternal), **Hapus Pin** (`DELETE map.pins.destroy`).

> Catatan: aksi reboot/rename pakai **endpoint khusus peta** (`map.pins.reboot|rename`) — bukan rute
> `smartolt.onu.*`/`cdata-olt.onu.*` — karena rute lama redirect ke halaman Port ONUs (akan keluar dari
> peta). Endpoint peta mendelegasikan ke service yang sama lalu kembali ke `/map`.

## Rute

| Method | URI | Name | Aksi |
|--------|-----|------|------|
| GET | `/map` | `map.index` | Halaman peta |
| POST | `/map/pins` | `map.pins.store` | Tambah/geser pin |
| PUT | `/map/pins/{pin}` | `map.pins.update` | Ubah field/koordinat |
| DELETE | `/map/pins/{pin}` | `map.pins.destroy` | Hapus pin |
| POST | `/map/pins/{pin}/reboot` | `map.pins.reboot` | Reboot ONU dari pin |
| POST | `/map/pins/{pin}/rename` | `map.pins.rename` | Ganti nama ONU dari pin |
| POST | `/map/resolve-link` | `map.resolve-link` | Ekstrak koordinat link Google Maps |
