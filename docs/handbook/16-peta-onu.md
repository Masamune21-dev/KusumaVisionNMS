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

- Marker ONU = `L.divIcon` teardrop berwarna **status saja**: hijau `#10b981` (`ONLINE_COLOR`) =
  online, merah `#ef4444` (`OFFLINE_COLOR`) = offline/LOS/dying-gasp (offline diberi kelas
  `kv-pin--offline` beranimasi pulsa). Info RX tetap tampil di kartu detail pin, tapi **tidak**
  menentukan warna pin. Legenda di pojok kanan-bawah: online, offline, dan ODP dengan warna bawaan
  amber (`DEFAULT_ODP_COLOR`) — ODP yang sudah diwarnai memakai warnanya sendiri. Legenda & kontrol
  layer dibuat ulang saat ganti bahasa (label Leaflet bukan reaktif Vue).
- Klik pin/ODP → kartu detail dipusatkan: `Pages/Map/Index.vue` mengukur tinggi kartu lalu
  `OnuMap.flyTo(lat, lng, zoom, offsetY)` supaya kartu + pin berada di tengah; kartu dibatasi setinggi
  peta (isi di-scroll di dalam kartu, dipantau `ResizeObserver`) dan digeser `panBy` bila membesar.
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
- `App\Services\Map\OnuMapPayloadService` — **perakit payload peta yang dipakai bersama** halaman web
  (`OnuMapController::index`) dan REST API v1 (`Api\V1\MapController`): `oltMeta()`, `pins()`,
  `odps()`, `onuOptions()`, `defaultCenter()`. Metadata OLT (driver/capabilities/prefix rute) di-memo
  di dalamnya agar `driverKey()` tak dihitung ulang per pin. Kalau menambah field pin, ubah di sini
  saja — web & aplikasi Android ikut.
- Payload pin sudah di-enrich data ONU **live** (nama, RX, online, interface, `if_index`) +
  `capabilities` OLT-nya, sehingga tombol aksi tahu apakah didukung.

## Kinerja halaman peta (aturan yang harus dijaga)

Peta menyentuh cache SEMUA OLT sekaligus, jadi pola yang di halaman lain tak terasa di sini
langsung jadi detik-detikan. Tiga aturan berikut hasil perbaikan 29 Jul 2026 (5,2 s → 0,09 s):

1. **Jangan akses `$olt->last_test_result` berulang.** Cast `array` Eloquent men-`json_decode`
   ULANG di tiap akses atribut — snapshot C300 ±1 MB. `OnuInventoryService` memo hasil decode
   per-OLT (`snapshot()` + `$routePrefixes`, per instance = per request); `OnuMapController::index()`
   mengambilnya sekali ke variabel lokal di loop `$oltMeta`. Kalau menulis kode baru yang memanggil
   `findOne()` di dalam loop (mis. per ONU-ODP), lewati servis itu — jangan baca atribut langsung.
2. **Prop berat dibungkus closure**, supaya partial reload melewatinya: `odps` (butuh
   `connectedOnus()` yang membaca snapshot semua OLT) closure biasa, `onus` (±4.500 baris ≈ 1 MB
   walau sudah dipangkas ke 12 kolom lewat `onuOptions()`) `Inertia::optional()` — hanya dikirim
   saat frontend meminta `only: ['onus']`, yaitu ketika pengguna masuk mode tambah pin.
   Menambah prop baru? Ikuti pola ini, jangan eager.
3. **Aksi pin memakai partial reload**: geser pin → `only: ['pins']`, geser ODP → `only: ['odps']`,
   lock/unlock → `only: ['pins'|'odps', 'flash']`. **`flash` wajib ikut** saat ada toast — `only`
   menyaring shared prop juga, jadi tanpa itu toast "Pin dikunci" tak pernah muncul.

Di sisi klien, `OnuMap.vue` **men-diff marker** (`markers`/`odpMarkers` menyimpan `{ marker, sig }`,
`syncMarker()` hanya `setLatLng`/`setIcon`/toggle draggable saat `sig` berubah) — jangan kembali ke
pola `clearLayers()` + bikin ulang semua marker: itulah yang dulu membuat tiap aksi terlihat seperti
reload halaman. Marker yang sedang diseret (`draggingPinId`/`draggingOdpId`) tak boleh ditimpa prop.

**Ribuan pin (Sep 2026, 1 → 60 fps pada 5.000 pin + 800 ODP dengan CPU 4× lebih lambat):**

- Pin DOM (teardrop, bisa diseret) hanya dibuat untuk pin **di layar** (+ margin `VIEW_PAD` 0,25) dan
  hanya bila jumlah **pin ONU + pin ODP** di area itu ≤ `DOM_LIMIT` (350) — dihitung ulang tiap
  `moveend` (`refreshView()`). Di atas itu (zoom jauh) semua pin ONU **dan** ODP digambar sebagai titik
  `L.circleMarker` di **satu kanvas** (`dotRenderer`, pane `kvDots`; ONU hijau/merah, ODP warnanya,
  ODP di atas ONU) — tetap bisa diklik. Pin/ODP yang **terpilih** atau sedang diseret selalu tetap
  marker DOM (`wantsDom()`), jadi pin yang dibuka kuncinya tetap bisa digeser di zoom jauh.
- Garis ODP→ONU yang diam digabung jadi **dua polyline multi-ruas** (online/offline) di kanvas
  (`lineRenderer`). Animasi aliran (`.kv-flow`, SVG) hanya untuk **ODP terpilih** atau ODP induk pin
  ONU yang terpilih (`flowOdpId()`).
- Loop ribuan item memakai objek mentah (`toRaw(props.pins)`), bukan proxy reaktif Inertia; watcher
  pin/ODP tidak `deep`.

**Titik awal peta** (`OnuMapPayloadService::defaultCenter()`, dipakai web & API): bukan rata-rata
koordinat (titik yang tersebar di beberapa wilayah berjauhan membuat rata-ratanya jatuh di tengah-tengah,
area yang bukan area kerja siapa pun). Urutannya: satu titik → pusatkan ke titik itu (zoom 15); ada titik
dalam radius **wilayah utama** opsional (`config('services.map')`, env `MAP_HOME_LAT/LNG/ZOOM/RADIUS_KM`)
→ buka di sana; selain itu → **kelompok terpadat** (sel grid 0,1°); tanpa titik → tampilan Indonesia.
Dijaga `tests/Feature/MapDefaultCenterTest`.

## Menambah pin

Tiga jalur (semua bermuara ke `POST map.pins.store`, `updateOrCreate` per kunci ONU):

1. **Klik di peta** → modal `AddPinModal.vue`: pilih OLT → Port → ONU (dropdown bertingkat) **atau**
   ketik di **search global** (interface/serial/nama/OLT) lalu klik hasil. Koordinat terisi dari titik
   klik (bisa diedit) + field pelanggan opsional.
2. **Tombol "Add Map" di Port ONUs** (`SmartOlt/PortOnus.vue`, `CDataOlt/PortOnus.vue`,
   `Hioso/PortOnus.vue`, per-ONU, desktop+mobile) → modal 2 opsi:
   - **Paste link Google Maps** → `POST map.resolve-link` mengekstrak koordinat (regex `@lat,lng` /
     `?q=` / `!3d!4d`; link pendek `maps.app.goo.gl`/`goo.gl` di-follow redirect server-side) → pin
     langsung terpasang.
   - **Klik langsung di map** → buka `/map?place_olt=…&place_slot=…&place_port=…&place_onu=…` (mode
     placement; ONU sudah pra-terpilih, tinggal klik lokasi).

## Aksi di detail pin (`PinDetailCard.vue`)

Klik pin → panel detail (nama pelanggan, OLT, slot/port/onu, badge RX, status online, alamat/HP/catatan).
Tombol (digerbang `capabilities` OLT):

- **Edit Nama** (gate `supports_onu_info_write`, maks 128) → `POST map.pins.rename` →
  `OnuMapController::renamePin()` delegasi ke `ZteRemoteOnuService::setInfo()` (ZTE, SNMP SET),
  `CDataCliWriteService::setDescription()` (C-Data, CLI), atau HiOSO — `HiosoCliWriteService::setName()`
  (HA7304, CLI) / `HiosoEponSnmpService::setOnuName()` (HA7302, SNMP SET, `description_mode = 'snmp'`);
  lalu update cache nama, **redirect balik ke `/map`**.
- **Reboot** (gate `supports_reboot`) → `POST map.pins.reboot` → `OnuMapController::rebootPin()`
  delegasi ke service per family (ZTE / C-Data / HiOSO), balik ke `/map`.
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
  hanya lihat ODP di OLT miliknya), `name`, `latitude/longitude`, `slot`/`port` (nullable, migrasi
  `2026_07_23_000001` — ODP = satu PON port), `locked` (`2026_07_28_000001`), `color` (migrasi
  `2026_08_12_000001`, lihat "Warna pin ODP"), `photo_path` (`2026_08_12_000002`), `notes`, `created_by`.
- Alarm: ODP ber-≥2 ONU yang semua ONU-nya offline memicu satu alarm `odp_down` (bukan puluhan alarm
  ONU) — lihat [10 §A](10-alarm-telegram.md#korelasi-root-cause-anti-banjir-notifikasi).
- Tabel `onu_odp_links` (migrasi `2026_07_22_000002`): `odp_id` + kunci ONU komposit
  `(snmp_olt_id, slot, port, onu_id)` — **unik 1 ODP per ONU** (assign ulang = pindah ODP),
  `serial_number` jangkar opsional.
- Service bersama `App\Services\OnuOdpService`:
  - `odpsForOlt()` / `linksForPort()` → prop `odps` + `odp_links` untuk kolom ODP di halaman Port ONUs.
  - `assign()` → pasang/pindah/lepas ODP sebuah ONU (`onu-odp.assign`).
  - `connectedOnus()` → daftar ONU sebuah ODP, di-enrich status online + koordinat pin ONU-nya.

**Di peta (`OnuMap.vue` + `OnuMapController::index` prop `odps`):**

- Pin ODP = teardrop **berwarna** (bentuk sama pin ONU, bawaan amber `#f59e0b`) + badge angka jumlah ONU
  terhubung — lihat "Warna pin ODP" di bawah.
- **Garis kabel animasi ODP→ONU** (polyline dashed, aliran via `stroke-dashoffset` CSS) ke setiap ONU
  terhubung yang punya pin — warna garis ikut status ONU (hijau online / merah offline).
- Klik pin ODP → kartu `Components/Map/OdpDetailCard.vue`: edit nama/notes, daftar ONU terhubung
  (klik → lompat ke pin ONU), hapus ODP. Kartu ini sengaja tidak mengubah OLT/port — itu lewat halaman ODP (di bawah).
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

**Halaman ODP (`odp.index`, `Pages/Odp/Index.vue`) — edit OLT & port:** modal Edit ODP bisa mengganti
**OLT**, slot, dan port (`OdpController::update()` menerima `snmp_olt_id`; OLT tujuan lewat
`SnmpOlt::findOrFail` sehingga kena `PartnerOltScope`). Bila OLT/slot/port benar-benar berubah
(`isDirty`), `OnuOdpService::releaseMismatchedLinks()` melepas kaitan ONU yang tak lagi di OLT ODP —
dan, bila ODP punya slot+port, yang di port lain — sesuai aturan `assign()` (ODP = satu PON port).
Modal menampilkan peringatan (`odp.move_release_warning`) sebelum simpan bila ODP punya ONU; flash
`flash.odp_updated_links_released` menyebut jumlah yang dilepas.

> ⚠️ **Hapus ODP permanen** (tanpa soft delete): `onu_odp_links`-nya ikut cascade, fotonya ikut dibuang,
> dan aksinya **tidak tercatat di `audit_logs`**. Pemulihan hanya dari cadangan database — lihat
> [13-troubleshooting](13-troubleshooting-maintenance.md#odp-terhapus-tidak-sengaja).

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

## Warna pin ODP (Agu 2026)

Warna dipakai untuk **mengelompokkan ODP per PON port** di peta, jadi bawaan aksinya menyapu satu port
sekaligus.

- **Simpan**: kolom `odps.color` (`#rrggbb`, nullable — null = warna bawaan `OdpColors::DEFAULT`
  amber, jadi ODP lama tak berubah tampilan tanpa backfill).
- **Palet**: `App\Support\OdpColors::PALETTE` (16 warna, **sengaja tanpa hijau/merah** karena keduanya
  dipakai pin ONU untuk status). Ini **satu-satunya sumber daftar warna**: web menerimanya sebagai prop
  Inertia `odp_color_palette` (halaman Peta & ODP), aplikasi Android lewat `meta.color_palette` di
  `GET /api/v1/odps`. Jangan menyalin daftarnya ke JS/Dart — di klien hanya ada nilai default +
  hitungan kontras (`resources/js/lib/odpColors.js`, `mobile/lib/core/odp_colors.dart`).
- **Cakupan**: `OnuOdpService::setColor()` — `apply_to_port` (bawaan **true**) mewarnai semua ODP di
  `(snmp_olt_id, slot, port)` yang sama lewat satu bulk update (tetap kena `PartnerOltScope`); ODP yang
  belum punya slot/port hanya bisa mewarnai dirinya sendiri.
- **Warisan warna port**: ODP yang **masuk** ke sebuah port ikut warna yang sudah dipakai di port itu —
  `OnuOdpService::portColor()` (warna terbanyak, seri → `updated_at` terbaru; port polos → null).
  Dipanggil di `OdpController::store()`, `update()` saat OLT/slot/port berubah (port tujuan polos →
  warna ODP dipertahankan), dan `assign()` saat port ODP terisi otomatis oleh ONU pertama.
- **Acak**: `OdpColors::randomFor()` memilih warna palet yang **belum dipakai port lain di OLT itu**
  (kalau palet habis, warna yang paling jarang dipakai) — supaya antar-port tetap mudah dibedakan.
  Dihitung di server agar web & aplikasi berperilaku sama.
- **UI web**: tombol **Warna** di `OdpDetailCard` (peta) dan ikon palet per baris di halaman ODP, dua-duanya
  membuka `Components/Map/OdpColorModal.vue` (palet + `<input type="color">` + Acak + Default + saklar
  se-port). Submit `POST map.odps.color` dengan `only: ['odps', 'flash']` — prop `odps` ada di kedua
  halaman itu, dan `flash` wajib ikut supaya toast tidak tersaring.
- ⚠️ `OdpColorModal` (seperti semua modal di atas `Modal.vue`) **wajib dirender terus** dengan `:show`
  yang berubah — `Modal.vue` hanya memanggil `showModal()` di watcher `show`. Dipasang lewat `v-if`
  dengan `:show="true"`, tombol warna di halaman ODP tak membuka apa pun (dijaga `tests/js/OdpPage.spec.js`).
- **UI mobile**: `mobile/lib/features/odp/odp_color_sheet.dart` (palet + Acak + saklar se-port; **tanpa**
  hex bebas), dibuka dari AppBar Detail ODP maupun sheet pin ODP di peta.
- ⚠️ Signature marker di `OnuMap.vue` (`renderOdps`) **harus memuat warna** — tanpa itu marker dianggap
  tak berubah oleh diff dan pin tetap warna lama sampai halaman dimuat ulang.
- Garis kabel ODP→ONU **tetap** hijau/merah status ONU (bukan warna ODP) supaya sinyal gangguan tak hilang.

## Foto dokumentasi ODP (Agu 2026)

Satu foto per ODP (unggah baru menimpa yang lama), untuk dokumentasi lapangan.

- **Simpan**: kolom `odps.photo_path` (migrasi `2026_08_12_000002`) → berkas di disk **privat**
  `local` (`storage/app/private/odp-photos/{odp}/{acak}.webp`). **Bukan** `/storage` publik: berkas
  hanya keluar lewat rute ber-auth `odp.photo` (web, session) dan `api.odps.photo` (aplikasi, token
  Sanctum) — route-model binding kena `PartnerOltScope`, jadi ODP di luar scope 404.
- **Konversi WebP**: `App\Services\Odp\OdpPhotoService` menjalankan biner **`cwebp`** (paket apt
  `webp`), **bukan** GD/Imagick — PHP produksi tak memuat kedua ekstensi itu, dan cara ini juga
  menghindari mendekode gambar tak dipercaya di dalam proses PHP. Kualitas & batas dimensi diatur
  di `config/services.php` (`cwebp.quality` 82, `cwebp.max_dimension` 1600 — `-resize` hanya dipakai
  bila gambar memang lebih besar, karena cwebp juga akan MEMPERBESAR gambar kecil). Kalau `cwebp`
  tak ada, foto tetap tersimpan dalam format aslinya (fitur tidak mati, berkas lebih besar).
- **Batas**: `jpg/jpeg/png/webp`, maks 12 MB (`OdpPhotoService::MAX_KILOBYTES`). PHP-FPM harus
  `upload_max_filesize ≥ 12M` — `install.sh` menulis `99-kusumavision-uploads.ini` (16M/20M);
  `scripts/check-requirements.sh` memperingatkan bila lebih kecil.
- **Cache**: nama berkas acak + query `?v=` (hash path) → URL berubah tiap foto diganti, jadi respons
  boleh `Cache-Control: private, max-age=604800`.
- **UI web**: `Components/Map/OdpPhotoField.vue` (pratinjau, unggah/ganti, hapus, lightbox) dipakai
  inline di `OdpDetailCard` (peta) dan di modal halaman ODP; halaman ODP juga menampilkan thumbnail
  kecil di kolom nama. Upload memakai `router.post(..., { forceFormData: true, only: ['odps','flash'] })`.
- **Mobile**: tampil lewat `mobile/lib/core/widgets/odp_photo.dart` (`Image.network` + header
  `Authorization`, ketuk = penampil zoom) di Detail ODP dan sheet pin ODP di peta. **Unggah/ganti/hapus
  juga bisa dari aplikasi** (Detail ODP → ikon kamera → Kamera/Galeri/Hapus) lewat
  `POST|DELETE /api/v1/odps/{odp}/photo` (grup tulis `role:admin,operator,partner` + `BlockDemoWrites`,
  aturan validasi dipakai bersama `OdpPhotoService::rules()`). Paket `image_picker` dikecilkan dulu di
  perangkat (1600px, q88) supaya unggahan ringan di jaringan lapangan; **tanpa izin Android baru** —
  Android 13+ memakai photo picker sistem dan kamera lewat intent bawaan.
- Menghapus ODP ikut membuang berkas fotonya (`OdpController::destroy`).

## Halaman ODP (`odp.index`)

Pusat pengelolaan ODP di luar peta — nav **ODP**, tepat di bawah Peta ONU. Terbuka untuk semua user
login, dibatasi `PartnerOltScope` (partner hanya lihat ODP di OLT miliknya); tak ada policy khusus.

- `Pages/Odp/Index.vue`: filter (cari nama, OLT, port/PON) + tabel (Nama · OLT · Port · Jumlah ONU ·
  Koordinat, thumbnail foto di kolom nama, ikon palet per baris) dengan paginasi sisi-klien
  (`usePagination` + `ClientPagination`).
- **Tambah/Edit**: satu modal; koordinat bisa diisi manual atau lewat **tempel link Google Maps**
  yang memakai ulang endpoint `POST map.resolve-link`. **Slot / Port PON = satu dropdown** (30 Sep 2026)
  berisi port hasil scan terakhir OLT terpilih, dikelompokkan per slot, label = deskripsi port ZTE
  (`if_descr`) atau label port sisi-NMS (C-Data/HiOSO), plus "— Belum diketahui —". Data dari
  `OdpController::portChoices()` yang hanya meng-query kolom JSON `last_test_result->ports` (bukan
  snapshot penuh). OLT tanpa daftar port jatuh ke dua input angka; port lama yang tak ada di scan
  terakhir tetap bisa dipertahankan. Validasi server tak berubah (nullable integer).
- **Kelola ONU**: modal dua daftar (ONU di ODP ini / kandidat) yang dimuat dari
  `GET odp.onus` (JSON). Penambahan & pelepasan memakai ulang `onu-odp.assign` (`odp_id: null` =
  lepas) — **tak ada endpoint tulis baru**.
- Prefix rute sengaja `odp.*`, bukan `map.odps.index`, supaya penanda menu aktif `map.*` milik Peta
  ONU tidak ikut menyala. `OdpController::store/update/destroy` memakai `back()` agar bisa dipanggil
  dari peta maupun halaman ODP.

CRUD ODP, Kelola ONU, dan geser pin hanya di web. Aplikasi Android membaca daftar/detail ODP dan
bisa mengganti **warna** serta **foto** ODP (lihat bagian Android di bawah).

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
| POST | `/map/odps/{odp}/color` | `map.odps.color` | Warna pin ODP (bawaan se-PON-port; `random`/reset) |
| POST | `/map/odps/{odp}/photo` | `map.odps.photo.store` | Unggah/ganti foto ODP (dikonversi ke WebP) |
| DELETE | `/map/odps/{odp}/photo` | `map.odps.photo.destroy` | Hapus foto ODP |
| GET | `/odp/{odp}/photo` | `odp.photo` | Sajikan berkas foto (ber-auth, disk privat) |
| POST | `/onu-odp` | `onu-odp.assign` | Pasang/pindah/lepas ODP sebuah ONU |
| GET | `/odp` | `odp.index` | Halaman pengelolaan ODP |
| GET | `/odp/{odp}/onus` | `odp.onus` | JSON ONU terhubung + kandidat (modal Kelola ONU) |

`map.pins.update` juga menerima `locked` (dan payload koordinat-saja dari geser pin).
`map.index` menerima query `?focus_odp={id}` untuk membuka kartu detail sebuah ODP langsung, dan
`?focus_olt=&focus_slot=&focus_port=&focus_onu=` ("Lihat di Peta" dari Port ONUs) untuk pin ONU;
keduanya ikut dipusatkan begitu kartunya muncul. Mode penempatan pin memakai `place_*` dengan pola
yang sama.

## Peta & ODP di aplikasi Android (`mobile/`)

Ditambahkan 29 Jul 2026 (APK 1.3.0+17). Navigasi bawah dirombak jadi
**Dashboard · OLT · ODP · Peta · Akun**; Alarm & Pencarian tak lagi punya tab
(Alarm dibuka dari kartu di Akun/Dashboard, Pencarian dari ikon 🔍 di AppBar
Dashboard — tombol keluar pindah sepenuhnya ke halaman Akun).

**Endpoint yang dipakai** (baca-saja kecuali warna & foto ODP, detail di `docs/API.md` §3.6–3.9):

| Endpoint | Dipakai layar |
|----------|---------------|
| `GET /odps` | Tab ODP (daftar + cari + filter OLT) |
| `GET /odps/{odp}` + `/odps/{odp}/onus` | Detail ODP (ONU di dalamnya + cari) |
| `GET /map` | Tab Peta (pin ONU + pin ODP + garis + titik tengah) |
| `POST /odps/{odp}/color` | Ganti warna pin ODP (Detail ODP & sheet pin di peta) |
| `GET /odps/{odp}/photo` | Foto dokumentasi ODP (butuh header Authorization) |
| `POST` / `DELETE /odps/{odp}/photo` | Unggah/ganti & hapus foto ODP dari aplikasi |
| `GET /olts/{olt}/register/options` → `odps` | Dropdown "ODP (opsional)" di form registrasi |
| `GET /olts/{olt}/onus/{slot}/{port}/{onuId}` → `odp_id`/`odp_name` | Baris ODP di detail ONU |

- **Peta**: `flutter_map` + `latlong2` (murni Flutter, **tanpa API key / Play Services**), tile sama
  seperti web (`mt{s}.google.com/vt`, toggle Peta/Satelit) dengan **`fallbackUrl` OSM** dan
  User-Agent browser — kalau Google menolak permintaan dari aplikasi, peta tetap tergambar.
  Layar peta adalah cabang shell sehingga navbar melayang tetap terlihat di atas peta.
- **Nyaris baca-saja**: menambah/menggeser pin dan CRUD ODP tetap di web. Aksi tulis ODP dari
  aplikasi hanya **ganti warna pin** (`POST /odps/{odp}/color`, APK 1.4.0+19 — lihat "Warna pin
  ODP") dan **unggah/ganti/hapus foto** (`POST|DELETE /odps/{odp}/photo` — lihat "Foto dokumentasi
  ODP"). Aksi ONU (reboot/rename/hapus) dibuka lewat Detail ONU dari sheet pin.
- Fokus lintas-layar ("Lihat di peta" pada detail ODP) memakai state Riverpod `mapFocusProvider`,
  **bukan** query URL: tab peta hidup di `IndexedStack` sehingga rutenya tidak dibangun ulang
  saat berpindah tab.
- Kaitan ODP tampil sebagai chip kuning (`core/widgets/odp_chip.dart`) di daftar ONU per port dan
  di detail ONU; chip-nya bisa ditekan untuk membuka halaman ODP.
- Registrasi ONU: dropdown ODP disaring per slot/port di klien (ODP tanpa port muncul di semua
  port). `odp_id` dikirim opsional; server mengaitkannya **setelah** CLI sukses, dan kegagalan
  pengaitan muncul sebagai snackbar peringatan (`data.odp_error`) tanpa membatalkan registrasi.
