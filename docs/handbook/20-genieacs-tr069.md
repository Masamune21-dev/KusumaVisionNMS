# 20 — GenieACS / TR-069

[← Indeks](README.md) · [← 18 Docker Appliance](18-docker-appliance.md)

Jembatan antara NMS dan **GenieACS** (ACS TR-069) supaya satu halaman ONU bisa menjawab
pertanyaan yang selama ini butuh dua aplikasi: *ONU ini sudah terpantau ACS belum, siapa saja
yang tersambung ke WiFi-nya, dan tolong ganti nama/kata sandi WiFi-nya.*

> **Ringkas:** ONU di NMS dikenali dari **posisi** (`olt/slot/port/onu`), device di GenieACS
> dikenali dari **device id TR-069**. Modul ini pekerjaannya satu: menautkan keduanya, lalu
> menyimpan hasilnya di tabel lokal supaya halaman ONU tidak pernah memanggil ACS saat dirender.

> **Opsional.** NMS **tidak** memasang atau menjalankan GenieACS — modul ini memakai server
> GenieACS yang sudah Anda punya. Selama alamat NBI belum diisi di Pengaturan, modul dorman:
> kolom ACS menampilkan "Belum", `genieacs:match-onu` selesai tanpa galat, dan tidak ada panggilan
> keluar.

> **Status:** diverifikasi terhadap kode edisi ini per **7 Okt 2026**.

---

## 1. Topologi & peringatan keamanan

```
 ONU ──CWMP :7547──► GenieACS (+ MongoDB) ◄──NBI :7557 (HTTP)── server NMS (bukan browser)
```

ONU tidak di-repoint sama sekali: ia tetap berbicara CWMP ke ACS seperti biasa. NMS hanya
membaca **NBI** GenieACS (dan menulis lewatnya untuk satu aksi: ubah WiFi, §8).

> ⚠️ **NBI bawaan GenieACS tanpa autentikasi** (HTTP polos), dan lewat NBI siapa pun bisa
> mengedit *provision script* — artinya eksekusi kode di server ACS. Karena itu:
>
> - **Jangan pernah membuka port NBI (7557) ke internet.** Jangkau dari server NMS lewat
>   jaringan privat, VPN, atau reverse proxy yang mewajibkan autentikasi (sebaiknya ber-TLS).
> - Di firewall server ACS, hanya **7547 (CWMP)** yang perlu terjangkau ONU; NBI (7557), file
>   server (7567), UI (3000), dan MongoDB tetap tertutup dari publik.
> - NMS tidak punya rute yang meneruskan (*proxy*) NBI ke browser — dan jangan menambahkannya.
>   Semua panggilan NBI dilakukan server lewat `GenieacsCredential::client()`; browser hanya
>   menerima hasil olahan.

Pengguna & kata sandi NBI di Pengaturan dikirim sebagai **HTTP Basic auth** — berguna bila NBI
dipasang di belakang reverse proxy berautentikasi; kosongkan bila tidak. Host boleh ditulis dengan
skema (`https://acs.internal`) dan skema itu dipakai apa adanya; tanpa skema = `http`.

**Virtual parameter yang dibaca.** Selain `_id`, `_deviceId`, dan `_lastInform`, sinkronisasi
membaca tiga *virtual parameter* GenieACS: `VirtualParameters.PonMac` (kunci pencocokan MAC),
`VirtualParameters.pppoeUsername`, dan `VirtualParameters.IPTR069`. Ketiganya **harus
didefinisikan di GenieACS Anda dengan nama yang sama**. Tanpa `PonMac` pencocokan hanya lewat
serial; tanpa dua lainnya kolom PPPoE/IP di tabel ONU kosong. Nama lain → sesuaikan
`GenieacsDeviceSyncService::PROJECTION` dan `readDevice()` (lihat batas di §4).

## 2. Pengaturan: dua tabel, dua peran yang sering tertukar

Keduanya diatur admin di **Pengaturan → tab ACS / TR069**, dalam dua kartu terpisah:

| Tabel | Model | Kartu | Isi | Arah |
|-------|-------|-------|-----|------|
| `acs_settings` | `AcsSetting` | ACS / TR069 | URL **CWMP** (7547) + user/pass yang **ditanam ke ONU** saat registrasi TR069 & TR069 Massal | ONU → ACS |
| `genieacs_credentials` | `GenieacsCredential` | **GenieACS (NBI)** | Host, port (bawaan 7557), pengguna & kata sandi **NBI** yang dibaca dasbor | NMS → ACS |

Keduanya sengaja **tidak digabung**: yang satu dikirim ke perangkat, yang satu dipakai server
ini. Keduanya singleton (satu baris). Password di keduanya `encrypted` + `$hidden`; form hanya
menerima `password_set`, tidak pernah menerima nilainya kembali, dan field kata sandi yang
dikosongkan berarti "pertahankan yang lama".

Kartu **GenieACS (NBI)**:

- **Simpan** → `settings.genieacs.update` (`PUT /settings/genieacs`, `role:admin`). Mengganti
  host/port/pengguna mereset status koneksi (`is_connected`, `last_test_at`, `last_test_error`).
- **Uji koneksi** → `settings.genieacs.test` (`POST /settings/genieacs/test`, `role:admin`) memakai
  pengaturan **tersimpan** (simpan dulu, baru uji). Hasilnya dicatat di baris itu (`is_connected`,
  `last_test_at`, `last_test_error`, dan `role` dari endpoint `/users` NBI bila ada) supaya UI
  menampilkan status terakhir tanpa memanggil ACS lagi.
- `GenieacsCredential` ber-`Auditable` — perubahan pengaturan tercatat di audit, kata sandinya tidak.

Tabel ketiga menyimpan hasil pencocokan:

| Kolom `genieacs_device_map` | Arti |
|---|---|
| `device_id` | Device id GenieACS (`_id`), **unik** |
| `serial_number`, `pon_mac` | Identitas dari sisi ACS (MAC ternormalisasi 12 hex huruf kecil) |
| `manufacturer`, `product_class` | Merk & model, dari `_deviceId` |
| `pppoe_username`, `tr069_ip` | Identitas yang dikenali teknisi (lihat §4) |
| `snmp_olt_id`, `slot`, `port`, `onu_id` | Posisi ONU hasil pencocokan — **null bila belum tercocok** |
| `match_method`, `matched_at` | `serial` · `mac` · `manual`, dan kapan pasangan terbentuk |
| `manual_ref_type`, `manual_ref` | Identitas yang disematkan operator (lihat §5) |
| `manual_stale` | Pin manual yang identitasnya hilang dari inventori |
| `manual_by`, `manual_at` | Siapa menyemat, kapan |
| `last_inform_at` | Inform terakhir, **sudah dikonversi ke zona waktu aplikasi** |

> **ONU tetap tanpa tabel.** Tabel ini hanya menyimpan *referensi* posisi, persis pola pin Peta
> dan `onu_odp_links` — lihat [05 Database & Model](05-database-model.md). Bedanya, tabel ini
> **tidak** memasang `PartnerOltScope`: aksesnya dijaga di controller (§9).

Migrasi: `2026_09_22_100000_create_genieacs_credentials_table`,
`2026_09_22_110000_create_genieacs_device_map_table`,
`2026_09_23_090000_add_manual_pin_to_genieacs_device_map_table`.

## 3. Pencocokan otomatis: dua kunci, keduanya terukur

`App\Services\Genieacs\GenieacsOnuMatcher` sengaja dibuat **murni** (tanpa HTTP/DB) supaya
aturannya bisa diuji langsung. Diukur pada satu armada produksi (±2.200 device ACS):

| Kunci | Dapat | Kenapa berlaku |
|---|---:|---|
| **Serial sama persis** | 859 | C-Data (`CDTC…`), ZICG, dan sebagian ZTE melaporkan serial GPON lewat TR-069 |
| **MAC toleransi ±1** | 605 | MAC PON yang dilaporkan ONU dan MAC yang dilihat OLT berselisih tepat satu pada byte terakhir (mis. serial `CDTCAF0012E6` ↔ PonMac `d0:5f:af:00:12:e7`) |
| **Total** | **1.464 (66,8%)** | sisanya 729 dibiarkan tidak tercocok |

Tiga aturan yang **jangan dilonggarkan**:

1. **Toleransi MAC dikunci di ±1.** Pengukuran menunjukkan hasilnya berhenti bertambah di ±2
   dan ±3 — melonggarkannya hanya menambah risiko salah pasang tanpa menambah cakupan.
2. **Kunci ambigu dibuang, bukan dipilih salah satu.** Satu serial/MAC yang dipakai lebih dari
   satu ONU disimpan sebagai `null` di indeks dan tidak pernah dicocokkan.
3. **Satu posisi diperebutkan dua device → keduanya dilepas.** Lebih baik kolom ACS kosong
   daripada menampilkan perangkat milik pelanggan lain. Klaim `manual` dikecualikan.

Serial dianggap MAC **hanya bila memuat titik dua** (`D0:5F:AF:…`) — OLT EPON menaruh MAC di
kolom serial, sedangkan serial vendor seperti `D05FAF0012E6` kebetulan juga 12 digit hex dan
tidak boleh ikut tertarik ke indeks MAC.

## 4. Sinkronisasi: `genieacs:match-onu`

```bash
php artisan genieacs:match-onu             # tarik katalog ACS, cocokkan, tulis hasil
php artisan genieacs:match-onu --dry-run   # hitung saja, tidak menulis apa pun
```

Terjadwal di `routes/console.php`:

```php
Schedule::command('genieacs:match-onu')->everyFifteenMinutes()->withoutOverlapping(10)->runInBackground();
```

Sengaja 15 menit, bukan tiap menit: peta ONU tidak berubah secepat itu dan scheduler sudah sibuk
oleh poller SNMP. Perintah ini **selesai `SUCCESS` tanpa berbuat apa-apa bila NBI belum diisi** di
Pengaturan — modul dorman, bukan gagal, supaya penjadwal tidak berisik di instalasi yang tak
memakai ACS. NBI terisi tapi tak menjawab → `FAILURE`. Ringkasannya: jumlah device di ACS, cocok
lewat serial / MAC (±1), disematkan manual, pin manual basi, belum tercocok, dan dilepas karena
diperebutkan. Device yang sudah dihapus dari ACS ikut dihapus barisnya, supaya tak meninggalkan
lencana basi.

**OLT mana yang ikut dicocokkan.** Indeks ONU dibangun dari
`GenieacsDeviceSyncService::eligibleOlts()`: OLT **global** (`owner_user_id` null) yang **bukan
demo**, dibaca **tanpa** global scope lalu disaring eksplisit (`isEligibleOlt()` untuk satu OLT).
Dua alasannya:

1. **Hasilnya harus sama dari mana pun sinkronisasi dipicu** — konsol, atau tombol tarik-ulang
   dari akun mana pun. Kalau indeks mengikuti OLT yang terlihat oleh pemicu, tarik-ulang dari akun
   ter-scope (operator ber-penugasan) akan melepas semua pasangan OLT lain, padahal hasilnya
   ditulis ke seluruh katalog.
2. **OLT privat partner dan OLT demo tidak pernah dipasangkan.** ACS di Pengaturan milik staf
   Pusat (admin/operator); MAC ±1 yang kebetulan cocok di OLT privat akan memberi partner akses
   baca & ubah WiFi ONU pelanggan yang bukan miliknya.

### ⚠️ `PROJECTION` wajib — jangan pernah dilepas

`GenieacsDeviceSyncService::PROJECTION`
(`_id,_deviceId,_lastInform,VirtualParameters.PonMac,VirtualParameters.pppoeUsername,VirtualParameters.IPTR069`)
membatasi jalur yang ditarik dari NBI. Diukur pada ±2.200 device:

| Yang ditarik | Ukuran | Waktu |
|---|---:|---:|
| Dengan projection | **761 KB** | 0,25 dtk |
| Tanpa projection (seluruh pohon parameter) | **±124 MB** | ±19 dtk |

Itu **180 kali lebih berat**. Setiap jalur tambahan pun berbiaya nyata **±290 KB**, jadi isinya
dipilih dengan bukti, bukan selera:

| Jalur | Terisi | Diambil? |
|---|---:|---|
| `VirtualParameters.PonMac` | — | ya, kunci pencocokan MAC |
| `VirtualParameters.pppoeUsername` | 100% | ya, identitas yang dikenali teknisi |
| `VirtualParameters.IPTR069` | 100% | ya, IP manajemen |
| `VirtualParameters.pppoeUsername2` | 100% | **tidak** — identik dengan `pppoeUsername` di 2.192/2.192 device |
| `…ManagementServer.ConnectionRequestURL` | 100% | **tidak** — host-nya identik dengan `IPTR069` di 2.192/2.192 device |

Sinkronisasi penuh berjalan **1,4–2,0 detik** (timeout permintaan katalog 120 dtk).

### Zona waktu

`_lastInform` dari GenieACS berformat UTC (`…T12:18:13.751Z`) sedangkan aplikasi berjalan di
zona `config('app.timezone')` (bawaan `Asia/Jakarta`). Tanpa konversi, stempelnya tersimpan
tertinggal 7 jam dan **seluruh armada** tampak "lama tak inform" walau baru saja melapor. Konversi
ada di `GenieacsDeviceSyncService::toTimestamp()` — jangan dihapus.

## 5. Penyematan manual — yang disimpan **identitas**, bukan posisi

Pencocokan otomatis berhenti di ±67%. Sisanya — terutama ZTE yang tak melaporkan serial GPON
lewat TR-069 — hanya bisa dipasangkan oleh orang yang memasang ONU-nya.

> **Rancangan pertama dibuang sebelum dipakai.** Versi awal menyimpan pasangan manual sebagai
> **posisi**. Itu berbohong di dua keadaan lapangan yang lazim:
>
> 1. **ONU dipindah ke PON lain** → pin tertinggal di port lama; kalau posisi itu kelak
>    ditempati pelanggan lain, halamannya menampilkan perangkat milik orang lain.
> 2. **ONU diganti unit baru** → pin tetap menunjuk device yang sudah dicopot, sementara ONU
>    barunya tampak "belum ter-ACS" selamanya.

Karena itu pin menyimpan **identitas ONU**, dan **posisinya diturunkan ulang setiap
sinkronisasi** (`GenieacsOnuMatcher::resolveRef()`):

| `manual_ref_type` | `manual_ref` | Tahan pindah port? |
|---|---|---|
| `serial` | serial ONU huruf besar | ya |
| `mac` | MAC ternormalisasi 12 hex | ya |
| `position` | `"{oltId}.{slot}.{port}.{onuId}"` | **tidak** — jalan terakhir untuk ONU yang tak melaporkan serial maupun MAC |

Identitas yang **tidak lagi ditemukan** di inventori tidak dihapus diam-diam: pasangannya
dilepas dan barisnya ditandai **`manual_stale`**. Pin basi itu justru penanda ada ONU yang
berganti unit, jadi ia disimpan untuk ditinjau — dan dihitung di ringkasan perintah.

Aturan lain di `GenieacsManualPinService`:

- **Hanya OLT yang memakai ACS.** Menyemat ke ONU di OLT privat partner atau OLT demo ditolak
  (`olt_not_eligible`; lewat web/API sudah 403 lebih dulu di controller, §9).
- **Menyemat ke ONU yang sudah dipegang device lain** melepas device lama lebih dulu, dalam satu
  transaksi. Satu ONU hanya boleh punya satu device.
- **Device yang sedang dipegang OLT yang tak terlihat oleh pengguna tidak bisa "diambil"**
  (`device_not_found`) — menyematkannya ke ONU sendiri sama dengan mendapat akses ke ONU
  pelanggan orang lain. Relevan untuk operator ber-penugasan OLT.
- **ONU yang tak ada di snapshot terakhir** ditolak (`onu_not_found`) — segarkan port-nya dulu.
- **Melepas pin** langsung mencoba pencocokan otomatis sekali, supaya operator melihat hasilnya
  tanpa menunggu penjadwal 15 menit. Bila otomatisnya memasangkan kembali ke ONU yang sama,
  itu dilaporkan (`rematch_method`) — bukan kegagalan, tapi operator berhak tahu.
- **Baris manual lama yang hanya punya posisi** (dibuat sebelum kolom identitas ada) tetap
  dihormati lewat `pinRef()`: posisinya dipakai sebagai referensi, tak ada pasangan yang hilang.

**Pemilih device** (`genieacs.devices.search`) hanya membaca tabel lokal — aman dipanggil selagi
pengguna mengetik. Mencari di `pppoe_username`, `serial_number`, `device_id`, `tr069_ip` (tak peka
huruf besar) dan `pon_mac` (potongan hex ≥ 4 digit, pemisah diabaikan); yang belum berpasangan
didahulukan, lalu yang terakhir inform. Device yang sudah berpasangan hanya tampil bila OLT-nya
terlihat oleh pengguna (`PartnerOltScope`), lengkap dengan `linked_to` supaya operator tidak
memindahkan pasangan pelanggan lain tanpa sadar.

### Penjaga balapan saat sinkronisasi

Daftar pin manual dibaca **sebelum** katalog ACS ditarik, jadi operator bisa menyemat atau
melepas justru selagi sinkronisasi berjalan. Device yang status manualnya **sudah berubah di
basis data** dilewati sepenuhnya saat menulis — keadaan di basis data yang lebih baru, dan
sinkronisasi berikutnya yang menyegarkan barisnya. Tanpa penjaga ini, pin yang baru dibuat
lenyap atau pin yang sudah dilepas hidup lagi.

## 6. Lencana ter-ACS di tabel ONU

`GenieacsMapService` melakukan **satu query untuk seluruh permintaan** (`forOlts()` /
`forPort()`) — jangan pernah memanggilnya per ONU di dalam loop, pola yang sama dengan lookup
ODP di `OnuInventoryService`. `OnuInventoryService` memakainya untuk `collect()` (Monitoring ONU,
`GET /api/v1/onus`), `forPort()`, dan `findOne(withAcs: true)` (detail ONU REST).

Bentuk satu entri (`null` = ONU belum berpasangan):

```json
{
  "device_id": "D05FAF-FD512XW-R460-CDTCAF0012E6",
  "match_method": "serial",
  "product_class": "FD512XW-R460",
  "pppoe_username": "warga01",
  "ip": "198.51.100.12",
  "last_inform_at": "2026-10-07T10:15:00+07:00",
  "online": true
}
```

Lencana tiga keadaan (`OnuAcsBadge`): **Belum** (tak berpasangan) · **Terhubung** (inform dalam
ambang) · **Lama diam** (berpasangan tapi lewat ambang). Di bawah lencana, `AcsPinCell` menampilkan
nama PPPoE dan IP manajemen; IP hanya dijadikan tautan bila berupa IPv4 polos.

### Ambang "online" = 2 jam, dan itu disengaja

`GenieacsMapService::ONLINE_THRESHOLD_SECONDS = 7200`. Alasannya **bukan** soal perangkat
melainkan kesegaran data kita sendiri: `last_inform_at` hanya disegarkan tiap 15 menit oleh
penjadwal, jadi stempel tersimpan bisa tertinggal sampai 15 menit dari kenyataan. Ambang 10 menit
membuat **seluruh armada** (1.462 dari 1.462 ONU berpasangan pada pengukuran) selalu tampak "lama
tak inform". Kesegaran sesungguhnya ada di panel perangkat terhubung, yang memang memanggil ACS
saat dibuka.

> ⚠️ Prop halaman bernama **`genieacs_map`** (ber-key `onu_id`), bukan `acs` — halaman ONU per
> port ZTE sudah punya prop `acs` milik **TR069 Massal** (default ACS dari
> `config('services.acs')`). Menamainya `acs` akan menimpa prop itu diam-diam dan mematikan
> tombol TR069 Massal. Ada test yang menjaganya.

## 7. Panel perangkat terhubung

`GenieacsDeviceDetailService` (cache 30 detik per `device_id`; `?fresh=1` membuang cache) membaca
pohon parameter satu device dari NBI dan menampilkan host LAN + klien WiFi + jaringan WiFi (SSID;
kata sandi hanya bila ONU melaporkannya — sebagian firmware, mis. ZTE F660, mengembalikan kosong).
Parser-nya `GenieACSParserService` (model TR-098 `InternetGatewayDevice.*`, untuk WiFi juga TR-181
`Device.WiFi.*`).

> **`Hosts.Host` itu tabel sewa DHCP, bukan daftar perangkat aktif.** Satu ONU bisa berisi 253
> entri padahal hanya 11 yang benar-benar tersambung. Panel menampilkan **yang aktif saja**,
> ditentukan dari tabel asosiasi WiFi **dan** field `Active` yang benar-benar dilaporkan ONU
> (dibaca dari dokumen mentah — parser mengisi `true` bila field itu absen). Yang tak diketahui
> dibiarkan `null` (`active_source` `wifi` · `device` · `null`) — tidak pernah dipaksa jadi `false`.

Panel inilah satu-satunya bagian modul ini yang memanggil ACS saat **dibaca**; daftar ONU tidak
pernah melakukannya. Gagal → `422` dengan `error`: `not_linked` (ONU belum berpasangan — keadaan
normal), `not_configured` (NBI belum diisi), atau galat NBI (mis. `unreachable`).

## 8. Ubah SSID & kata sandi WiFi

`GenieacsWifiService` — **satu-satunya aksi modul ini yang menulis ke perangkat pelanggan**.
Karena itu: dibatasi peran tulis, dicatat ke audit, dan tidak pernah dijalankan massal (satu
ONU, satu WLAN, satu permintaan).

- Validasi WPA-PSK **8–63 karakter** dicegat di controller supaya pesannya jelas, bukan berujung
  CWMP fault yang tampil sebagai `write_failed`.
- Kata sandi **tidak ikut** dicatat ke audit — event `genieacs.wifi.updated` / `genieacs.wifi.failed`
  merekam OLT, slot/port/ONU, indeks WLAN, SSID baru, `device_id`, dan galat. Berlaku di web
  **dan** API (`Api\V1\GenieacsController::updateWifi`, properti `channel: mobile`).
- Validasi: SSID 1–32 karakter, `wlan_index` 1–8, mode keamanan bawaan `WPA2PSK`.
- Jalur tulis (`GenieACSService::setWiFiConfigForDevice()`) membaca pohon device lebih dulu lalu
  hanya menulis path yang memang ada — standar (`KeyPassphrase`, `PreSharedKey.1.KeyPassphrase`)
  maupun vendor (`X_CMS_KeyPassphrase` untuk C-Data, `X_CT-COM_`, `X_ZTE-COM_`, …). Pada armada
  yang diukur, `PreSharedKey` kosong di seluruh unit C-Data; yang dipakai justru
  `X_CMS_KeyPassphrase`. Field vendor menolak nilai kosong dengan CWMP fault 9007 (dan
  `setParameterValues` atomik, jadi seluruh task gagal) — karena itu dilewati bila kata sandi
  dikosongkan; jalur NMS sendiri selalu mengirim 8–63 karakter.
- Sukses → cache panel perangkat terhubung device itu dibuang, supaya SSID baru langsung terlihat.
- Perubahan baru berlaku setelah ONU menerimanya; perangkat yang sedang tersambung terputus sesaat.

## 9. Izin & rute

### Siapa boleh apa

| Peran | Lencana + PPPoE/IP di tabel ONU | Cari/semat/lepas/tarik ulang, perangkat terhubung, ubah WiFi |
|---|---|---|
| admin, operator (staf Pusat) | ya | ya — pada OLT global non-demo yang terlihat olehnya |
| partner | ya, pada OLT global yang di-assign kepadanya (OLT privatnya tak pernah berpasangan) | **tidak** — 403, tombolnya disembunyikan |
| demo | — (OLT demo tak pernah berpasangan) | **tidak** — 403 |

- `User::canManageAcs()` = `isCentralStaff()` (admin/operator) — katalog ACS memuat device
  **seluruh** pelanggan, jadi partner & demo tidak punya akses.
- `User::canUseAcsCatalogOn($olt)` = `canManageAcs()` **dan**
  `GenieacsDeviceSyncService::isEligibleOlt($olt)` — gerbang aksi pada satu ONU.
- Gerbang `role:admin,operator,partner` di rute saja **tidak cukup** (partner ikut lolos): kedua
  controller memanggil `authorizeAcsCatalog()` sebelum apa pun.
- UI membaca `auth.can.manage_acs` (prop Inertia bersama) untuk menyembunyikan tombol; backend
  tetap yang menegakkan.
- Pencarian device dibatasi seperti aksi tulis walau hanya membaca tabel lokal: isinya memuat
  **nama secret PPPoE pelanggan**.

### Web (`routes/web.php`, semua di grup `auth`)

| Method | URI | Nama | Akses |
|--------|-----|------|-------|
| GET | `/olts/{olt}/onus/{slot}/{port}/{onuId}/acs-clients` | `genieacs.onu.clients` | `auth` + `canUseAcsCatalogOn` di controller |
| POST | `/olts/{olt}/onus/{slot}/{port}/{onuId}/acs-wifi` | `genieacs.onu.wifi` | `role:admin,operator,partner` + `canUseAcsCatalogOn` |
| GET | `/genieacs/devices` | `genieacs.devices.search` | `role:admin,operator,partner` + `canManageAcs` |
| POST | `/genieacs/devices/refresh` | `genieacs.devices.refresh` | `role:admin,operator,partner` + `throttle:olt-refresh` + `canManageAcs` |
| POST | `/olts/{olt}/onus/{slot}/{port}/{onuId}/acs-pin` | `genieacs.onu.pin` | `role:admin,operator,partner` + `canUseAcsCatalogOn` |
| DELETE | `/olts/{olt}/onus/{slot}/{port}/{onuId}/acs-pin` | `genieacs.onu.unpin` | `role:admin,operator,partner` + `canUseAcsCatalogOn` |
| PUT | `/settings/genieacs` | `settings.genieacs.update` | `role:admin` |
| POST | `/settings/genieacs/test` | `settings.genieacs.test` | `role:admin` |

**Tarik ulang katalog** (`refreshDevices`) = sinkronisasi yang sama persis dengan
`genieacs:match-onu`, untuk ONU yang baru di-set TR069 sebelum jadwal 15 menit berikutnya. Dua
gerbang supaya tombolnya tak jadi beban ke ACS: kunci (`genieacs:device-refresh`, 409 `sync_busy`
bila sedang jalan) dan jeda 20 detik bersama untuk semua pengguna (`skipped: true`). NBI gagal →
502 `sync_failed`.

Pin & lepas pin dicatat ke audit (`genieacs.pin.created` / `genieacs.pin.removed` /
`genieacs.pin.failed`) karena menentukan perangkat siapa yang muncul di halaman pelanggan.

**Satu rute untuk semua family** (ZTE, C-Data, HiOSO) — posisi ONU sudah cukup mengenali
perangkatnya, pola yang sama dengan `onu-odp.assign` dan `olt.port-label.store`. Kepemilikan OLT
ditegakkan otomatis oleh route-model binding + `PartnerOltScope` (OLT di luar cakupan → 404).

### API v1 (`routes/api.php`, Sanctum)

| Method | URI | Nama | Grup |
|--------|-----|------|------|
| GET | `/api/v1/olts/{olt}/onus/{slot}/{port}/{onuId}/acs-clients` | `api.onus.acs-clients` | baca (controller: `canUseAcsCatalogOn`, selain itu 403) |
| POST | `/api/v1/olts/{olt}/onus/{slot}/{port}/{onuId}/acs-wifi` | `api.onus.acs-wifi` | tulis (`role:admin,operator,partner` + `BlockDemoWrites`; controller tetap menolak partner) |

Detail satu ONU (`GET /api/v1/olts/{olt}/onus/{slot}/{port}/{onuId}`) membawa blok `data.acs`, dan
tiap ONU di daftar (`/onus`, ONU per port) membawa field `acs` — `null` bila belum berpasangan.
Lihat [`docs/API.md` §3.16](../API.md#316-genieacs--tr-069--perangkat-terhubung--ubah-wifi).

> **Rute web & API sama-sama di-cache.** Rute baru akan 404/405 sampai
> `php artisan route:cache` dijalankan ulang.

## 10. Frontend

| Berkas | Peran |
|---|---|
| `Components/OnuAcsBadge.vue` | Lencana tiga keadaan: belum berpasangan · berpasangan & baru inform · berpasangan tapi lama diam |
| `Components/Genieacs/AcsPinCell.vue` | Isi kolom ACS: lencana + PPPoE/IP + tombol penyematan manual |
| `Components/Genieacs/PinDeviceModal.vue` | Pemilih device (cari nama PPPoE/serial/MAC/IP), semat & lepas, tarik ulang katalog |
| `Components/Genieacs/ConnectedDevicesModal.vue` | Panel perangkat terhubung |
| `Components/Genieacs/WifiSettingsModal.vue` | Ubah SSID & kata sandi |

Terpasang di `Pages/{SmartOlt,CDataOlt,Hioso}/PortOnus.vue` (tabel desktop **dan** kartu
mobile, prop `genieacs_map`). Tombol semat muncul di **semua** baris, bukan hanya yang belum
berpasangan — baris yang pasangannya meleset justru yang perlu dibetulkan. **Monitoring ONU**
(`Pages/SmartOlt/OnuMonitor.vue`) menampilkan kolom ACS dari field `acs` tiap ONU, filter ACS
(semua / sudah / belum ter-ACS), dan tombol perangkat terhubung — tanpa penyematan. Semua tombol
ACS hanya tampil bila `auth.can.manage_acs`; `AcsPinCell` lebih dulu memakai
`olt.can.use_acs_catalog` bila halaman mengirimnya.

Kartu **GenieACS (NBI)** ada di `Pages/Settings/Index.vue` (tab ACS / TR069), dan halaman
Panduan (`/panduan`) memuat ringkasan untuk pengguna.

> ⚠️ **Modal dirender terus**, tanpa `v-if` pada keadaan terbuka. `Modal.vue` memanggil
> `dialog.showModal()` di dalam watcher `show`; kalau komponennya baru di-mount saat `show`
> sudah `true`, watcher itu tak pernah berjalan dan modal tak muncul sama sekali. Ada test
> Vitest yang menjaganya (`tests/js/ConnectedDevicesModal.spec.js`).

Namespace i18n: `portonus.acs_*` & `onumonitor.acs_*` (kolom, lencana, filter), `acsclients.*`,
`acswifi.*`, `acspin.*`, `settings.genieacs_*`, `panduan.genieacs_*` — semuanya wajib ada di
**`id.json` dan `en.json`**. Pesan backend: `lang/{id,en}/acs.php` + kunci `flash.genieacs_*`.

## 11. API & aplikasi Android

Endpoint REST-nya sudah ada (§9, [`docs/API.md` §3.16](../API.md#316-genieacs--tr-069--perangkat-terhubung--ubah-wifi)),
tetapi aplikasi Android di `mobile/` **belum** punya layar perangkat terhubung maupun ubah WiFi.
**Penyematan manual belum ada di API** — hanya di web.

## 12. Laporan backlog TR-069

```bash
php artisan genieacs:unlinked-report --csv=laporan.csv   # relatif ke storage/app/private
php artisan genieacs:unlinked-report --limit=30          # jumlah baris merk di ringkasan (bawaan 15)
```

ONU yang **belum** berpasangan, dikelompokkan per merk ONT (prefiks serial, atau OUI yang dipelajari
dari device yang sudah berpasangan) — dipakai menentukan urutan pengaktifan TR-069. Seluruhnya
dibaca dari tabel lokal, tidak memanggil ACS.

> ⚠️ Perintah ini berjalan di **konteks konsol**, dan di sana `PartnerOltScope` sengaja tidak
> membatasi apa pun (supaya penjadwal bisa mem-poll seluruh OLT). Kalau dibiarkan, laporan ikut
> menghitung ONU di OLT privat partner dan OLT demo — padahal ONU itu memang tak pernah dicocokkan
> (§4). Karena itu penyaringannya dilakukan **eksplisit** di `scopedOlts()`; ada flag
> `--include-partner` / `--include-demo` bila memang ingin diikutkan.

## 13. Batas yang disadari (jangan dijanjikan ke pengguna)

- **Kata sandi web ONU tidak bisa dibaca.** `ConfigPassword` dikembalikan sebagai string kosong
  — perangkat mem-masking-nya (perilaku TR-069 baku: parameter password bersifat write-only).
  Satu-satunya yang terbaca adalah `X_CT-COM_TeleComAccount.Password` (akun telekom).
- **Jenis akses PON jangan disimpulkan dari `WANAccessType`.** Firmware C-Data
  FD512XW-R460/FD511GW melaporkan "Ethernet" untuk 1.258 dari 1.268 unit padahal 764 di
  antaranya GPON. Pakai keberadaan `X_CT-COM_Gpon/EponInterfaceConfig.RXPower`.
- **`DeviceInfo.MemoryStatus` hanya didukung C-Data** (94,6%); ZTE/TM/ZICG ~0% — sembunyikan
  kolomnya untuk vendor itu, jangan tampilkan kolom kosong.
- **Penyematan manual tidak tersedia di Monitoring ONU** (halaman lintas-OLT, ribuan baris),
  di API, maupun di aplikasi Android.
- **Satu ACS per instalasi.** OLT privat partner dan OLT demo tidak memakai ACS di Pengaturan.
- **Klien NBI (`GenieACSService`) punya metode yang belum dipakai** — baca/tulis provision script,
  reboot, summon, tag, dll. (pesan galatnya di `lang/{id,en}/acs.php`). Tak ada rute yang
  memanggilnya. Bila menambahkannya, perlakukan seperti ubah WiFi (peran tulis + audit + satu
  perangkat per aksi), dan jangan pernah meneruskan isi bebas dari browser ke provision script.

## 14. Kalau ada yang aneh

| Gejala | Penyebab paling sering |
|---|---|
| Rute ACS baru 404/405 | `php artisan route:cache` belum dijalankan ulang |
| Semua ONU "Belum" padahal ACS berisi device | NBI belum diisi/tak terjangkau (cek **Uji koneksi**, lalu `genieacs:match-onu --dry-run`); virtual parameter `PonMac` tidak ada (hanya serial yang bisa cocok, §1); atau OLT-nya privat partner/demo (§4) |
| Semua lencana kuning ("lama diam") | Zona waktu `_lastInform`, atau ambang diturunkan dari 7200 detik |
| Kolom PPPoE/IP kosong | Virtual parameter `pppoeUsername`/`IPTR069` tidak ada di GenieACS (§1) |
| Partner melihat lencana tapi tanpa tombol, atau 403 | Disengaja — katalog ACS hanya untuk staf Pusat (§9) |
| Tombol modal ditekan, tak ada yang muncul | Modal dibungkus `v-if` (lihat §10) |
| Tombol TR069 Massal mati | Prop `genieacs_map` dinamai `acs` dan menimpa prop TR069 (lihat §6) |
| Sinkronisasi lambat/berat | `PROJECTION` dilepas atau ditambahi jalur baru (lihat §4) |
| Laporan ikut menghitung ONU partner/demo | `PartnerOltScope` tidak berlaku di konsol (lihat §12) |

## Selanjutnya

→ [05 Database & Model](05-database-model.md) · [06 Routing](06-routing.md) ·
[11 Keamanan, RBAC & Audit](11-keamanan-rbac-audit.md)
