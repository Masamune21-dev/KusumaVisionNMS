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
- Hint "belum ada pin" hanya muncul bila pin ONU **dan** pin ODP sama-sama kosong.

## Kunci / buka posisi pin (ONU & ODP)

Kolom `locked` (boolean, **default true**) di `onu_map_pins` dan `odps` — migrasi
`2026_07_28_000001`. Pin baru selalu terkunci; posisi hanya bisa digeser setelah dibuka.

- Tombol **Buka Kunci / Kunci** ada di `PinDetailCard.vue` & `OdpDetailCard.vue`, keduanya
  `PUT map.pins.update` / `map.odps.update` dengan `{ locked }`.
- Saat `locked=false`, marker Leaflet dibuat `draggable` dan diberi cincin cyan putus-putus
  (`.kv-pin--unlocked`). Event `drag` menggeser kartu detail agar tetap menempel; event `dragend`
  **langsung menyimpan** koordinat baru (`pin-moved`/`odp-moved` → PUT lat/lng) supaya posisi tak
  hilang bila halaman ter-refresh sebelum dikunci. Tombol Kunci hanya mengubah `locked` jadi true.
- PUT yang berisi **hanya koordinat** sengaja tak memberi flash (kalau tidak, tiap geser
  memunculkan toast). Rule `name` di `OdpController::update` memakai `sometimes` supaya PUT
  koordinat-saja tak perlu mengirim ulang nama, dan `notes` hanya ditimpa bila field-nya dikirim.

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
yang submit ke `onu-odp.assign`. Ketiga halaman itu juga punya **filter ODP** (`semua` / `tanpa ODP`
/ ODP tertentu) di bar filter masing-masing.

**Di Monitoring ONU** (`monitoring.onu`): dropdown filter ODP + kolom ODP. Datanya ikut baris ONU —
`OnuInventoryService::normalize()` menambahkan `odp_id`/`odp_name` dari peta lookup `OnuOdpLink`
yang dibangun **sekali** per request di `collect()`/`forPort()` (hindari N+1 di ribuan ONU).
`findOne()` sengaja TIDAK melakukan lookup ODP karena dipanggil di dalam loop
`OnuOdpService::connectedOnus()`. Query `OnuOdpLink` dilakukan langsung di `OnuInventoryService`,
**bukan** lewat `OnuOdpService` — servis itu sudah bergantung pada `OnuInventoryService`, jadi
meng-inject balik akan membuat dependensi melingkar di container.

**Saat registrasi ONU (ZTE):** field **ODP (opsional)** di ketiga form `Pages/SmartOlt/RegisterOnu.vue`
(C600 / Dasar / Lanjutan). Dropdown disaring di klien ke slot/port yang sedang dipilih (plus ODP yang
belum punya port). Rule `odp_id` nullable ada di `OnuRegistrationService::rules()`/`c600Rules()` dan
`SmartOltController::validatedProvisioning()`/`validatedAdvancedProvisioning()`.

> ⚠️ Aturan pengaitan: ODP dikaitkan **hanya setelah CLI benar-benar sukses**, dan pemanggilannya
> berada **di luar blok `try`** eksekusi Telnet. Kalau dikaitkan lebih awal, generate-script atau
> eksekusi gagal bisa menimpa kaitan ODP milik ONU lain yang kebetulan menempati slot/port/onu_id
> yang sama; kalau berada di dalam `try`, kegagalan menyimpan kaitan akan ter-`catch` dan menulis
> baris audit `failed` kedua untuk ONU yang sebenarnya sudah teregister. Kegagalan mengaitkan tidak
> membatalkan registrasi — hanya ditempel sebagai peringatan (`flash.onu_odp_link_failed`) lewat
> `OnuOdpService::assignQuietly()`.

## Halaman ODP (`odp.index`)

Pusat pengelolaan ODP di luar peta — nav **ODP**, tepat di bawah Peta ONU. Terbuka untuk semua user
login, dibatasi `PartnerOltScope` (partner hanya lihat ODP di OLT miliknya); tak ada policy khusus.

- `Pages/Odp/Index.vue`: filter (cari nama, OLT, port/PON) + tabel (Nama · OLT · Port · Jumlah ONU ·
  Koordinat) dengan paginasi sisi-klien (`usePagination` + `ClientPagination`).
- **Tambah/Edit**: satu modal; koordinat bisa diisi manual atau lewat **tempel link Google Maps**
  yang memakai ulang endpoint `POST map.resolve-link`.
- **Kelola ONU**: modal dua daftar (ONU di ODP ini / kandidat) yang dimuat dari
  `GET odp.onus` (JSON). Penambahan & pelepasan memakai ulang `onu-odp.assign` (`odp_id: null` =
  lepas) — **tak ada endpoint tulis baru**.
- Prefix rute sengaja `odp.*`, bukan `map.odps.index`, supaya penanda menu aktif `map.*` milik Peta
  ONU tidak ikut menyala. `OdpController::store/update/destroy` memakai `back()` agar bisa dipanggil
  dari peta maupun halaman ODP.

Scope v1: web saja (mobile/API belum).

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
| PUT | `/map/odps/{odp}` | `map.odps.update` | Ubah nama/notes/koordinat/kunci ODP |
| DELETE | `/map/odps/{odp}` | `map.odps.destroy` | Hapus ODP (link ONU ikut terhapus) |
| POST | `/onu-odp` | `onu-odp.assign` | Pasang/pindah/lepas ODP sebuah ONU |
| GET | `/odp` | `odp.index` | Halaman pengelolaan ODP |
| GET | `/odp/{odp}/onus` | `odp.onus` | JSON ONU terhubung + kandidat (modal Kelola ONU) |

`map.pins.update` juga menerima `locked` (dan payload koordinat-saja dari geser pin).
`map.index` menerima query `?focus_odp={id}` untuk membuka kartu detail sebuah ODP langsung.
