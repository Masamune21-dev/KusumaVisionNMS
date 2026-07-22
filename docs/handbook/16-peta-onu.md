# 16 — Peta ONU & ODP

Peta geografis sebaran **pin ONU pelanggan** dari semua OLT (ZTE, C-Data & HiOSO), plus **pin ODP
(Optical Distribution Point / splitter lapangan)** dengan garis kabel ODP→ONU. Operator bisa melihat
status pelanggan per lokasi, menambah pin, dan melakukan aksi cepat (ganti nama / reboot) langsung
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

- Marker ONU = `L.divIcon` teardrop berwarna **status saja**: hijau = online, merah =
  offline/LOS/dying-gasp (offline diberi animasi pulsa). Info RX tetap tampil di kartu detail pin,
  tapi **tidak lagi** menentukan warna pin. Legenda (hijau/merah/ODP kuning) di pojok kanan-bawah.

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

## ODP (Optical Distribution Point)

Konsep **splitter lapangan** + topologi ODP→ONU (Jul 2026). ONU tetap tanpa tabel — relasi memakai
kunci komposit yang sama dengan pin.

**Data:**

- Tabel `odps` (migrasi `2026_07_22_000001`): `snmp_olt_id` (per-OLT, ikut `PartnerOltScope` — partner
  hanya lihat ODP di OLT miliknya), `name`, `latitude/longitude`, `notes`, `created_by`.
- Tabel `onu_odp_links` (migrasi `2026_07_22_000002`): `odp_id` + kunci ONU komposit
  `(snmp_olt_id, slot, port, onu_id)` — **unik 1 ODP per ONU** (assign ulang = pindah ODP),
  `serial_number` jangkar opsional.
- Service bersama `App\Services\OnuOdpService`:
  - `odpsForOlt()` / `linksForPort()` → prop `odps` + `odp_links` untuk kolom ODP di halaman Port ONUs.
  - `assign()` → pasang/pindah/lepas ODP sebuah ONU (`onu-odp.assign`).
  - `connectedOnus()` → daftar ONU sebuah ODP, di-enrich status online + koordinat pin ONU-nya.

**Di peta (`OnuMap.vue` + `OnuMapController::index` prop `odps`):**

- Pin ODP = teardrop **kuning** (bentuk sama pin ONU) + badge angka jumlah ONU terhubung.
- **Garis kabel animasi ODP→ONU** (polyline dashed, aliran via `stroke-dashoffset` CSS) ke setiap ONU
  terhubung yang punya pin — warna garis ikut status ONU (hijau online / merah offline).
- Klik pin ODP → kartu `Components/Map/OdpDetailCard.vue`: edit nama/notes, daftar ONU terhubung
  (klik → lompat ke pin ONU), hapus ODP.
- **Membuat ODP**: klik peta → `AddPinModal.vue` punya **toggle jenis ONU / ODP** — mode ODP cukup
  nama + OLT (koordinat dari titik klik).

**Di tabel ONU (ketiga family):** kolom **ODP** di `Pages/{SmartOlt,CDataOlt,Hioso}/PortOnus.vue` via
komponen bersama `Components/OnuOdpCell.vue` — dropdown pilih ODP (lebar mengikuti nama terpanjang)
yang submit ke `onu-odp.assign`.

Scope v1: web saja (mobile/API belum). CRUD ODP via `OdpController` (`map.odps.*`).

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
| POST | `/map/odps` | `map.odps.store` | Tambah ODP |
| PUT | `/map/odps/{odp}` | `map.odps.update` | Ubah nama/notes/koordinat ODP |
| DELETE | `/map/odps/{odp}` | `map.odps.destroy` | Hapus ODP (link ONU ikut terhapus) |
| POST | `/onu-odp` | `onu-odp.assign` | Pasang/pindah/lepas ODP sebuah ONU |
