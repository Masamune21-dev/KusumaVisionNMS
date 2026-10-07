# KusumaVision NMS — REST API v1

API untuk **memonitor jaringan FTTH/GPON** dari aplikasi lain (web app lain,
aplikasi Android, backend billing, dsb) plus sejumlah aksi tulis yang dipakai
aplikasi Android. Cukup panggil endpoint di bawah, kirim token, dan baca hasilnya
dalam format JSON.

> ✅ **STATUS: API AKTIF** (`$apiEnabled = true` di `routes/api.php`). Saklar itu
> tetap ada untuk menutup total permukaan API: `false` = semua `/api/*` membalas
> `404` dan tab **Pengaturan → API & Token** menolak pembuatan token. Karena rute
> API **ikut di-cache** (`bootstrap/cache/routes-v7.php`), perubahan saklar baru
> berlaku setelah `php artisan route:cache` — reload PHP-FPM saja tidak cukup.

> **Sifat API ini:** endpoint *baca* mengambil snapshot polling terakhir yang
> tersimpan di server — cepat dan tidak menyentuh OLT. Aksi *tulis* (register,
> reboot, rename, **hapus ONU**, refresh live) mengeksekusi Telnet/SNMP sinkron
> ke OLT dan di-gate role `admin`/`operator`/`partner` (lihat §3 "Aksi tulis").
> Pengecualian opsional: dua endpoint GenieACS (§3.16) memanggil server ACS saat dipanggil.

- Base URL (server Anda): `https://nms.example.com/api/v1` — ganti `nms.example.com` dengan domain
  instalasi NMS Anda di semua contoh di bawah
- Base URL (lokal dev): `http://localhost:8000/api/v1`
- Format: **JSON** (`Content-Type: application/json`)
- Zona waktu timestamp: **ISO-8601** (mis. `2026-06-28T10:15:30+07:00`)

---

## 0. Endpoint Publik (TANPA login) — untuk disisipkan di web lain

Bila ingin **menempelkan widget status** di halaman web lain tanpa proses login,
pakai endpoint publik ini. CORS sudah aktif untuk `api/*` (bisa dipanggil langsung
dari browser/JavaScript di domain mana pun).

`GET /api/v1/public/status` *(tanpa token)*

> ⚠️ **Demi privasi**, endpoint publik HANYA mengembalikan **angka agregat**
> (jumlah OLT/ONU online-offline, alarm aktif, status per-OLT). Ia **tidak**
> memuat data pelanggan (nama/alamat/serial) maupun IP OLT. Untuk data rinci ONU
> pelanggan, gunakan endpoint ber-token di bagian berikutnya — jangan pernah
> menaruh data pelanggan di halaman publik.
>
> Hasil di-cache 30 detik di server (kunci cache `api.public.status`). Kena limiter
> `api` yang sama dengan endpoint ber-token — tanpa token dihitung **per IP**
> (120 request/menit).

```bash
curl https://nms.example.com/api/v1/public/status
```

```json
{
  "data": {
    "olt": { "total": 2, "online": 2, "offline": 0 },
    "onu": { "total": 480, "online": 472, "offline": 8 },
    "online_share": 98.3,
    "alarms": { "active": 3 },
    "olts": [
      { "name": "OLT-C320-01", "reachable": true, "onu_total": 240, "onu_online": 236, "onu_offline": 4, "last_polled_at": "2026-06-28T10:14:00+07:00" }
    ]
  },
  "meta": { "generated_at": "2026-06-28T10:15:30+07:00" }
}
```

### Contoh siap-tempel (HTML + JS) untuk halaman web lain

Tempel potongan ini di halaman mana pun — ia menampilkan ringkasan status dan
menyegarkan tiap 60 detik:

```html
<div id="kv-status">Memuat status jaringan…</div>

<script>
(async function () {
  const BASE = "https://nms.example.com/api/v1";
  const el = document.getElementById("kv-status");
  async function render() {
    try {
      const r = await fetch(`${BASE}/public/status`);
      const { data } = await r.json();
      el.innerHTML = `
        <strong>Status Jaringan</strong><br>
        OLT online: ${data.olt.online}/${data.olt.total} &nbsp;|&nbsp;
        ONU online: ${data.onu.online}/${data.onu.total} (${data.online_share}%) &nbsp;|&nbsp;
        Alarm aktif: ${data.alarms.active}
      `;
    } catch (e) {
      el.textContent = "Gagal memuat status.";
    }
  }
  render();
  setInterval(render, 60000);
})();
</script>
```

> Catatan: karena dipanggil dari browser, endpoint ini **terbuka untuk publik**.
> Itu sebabnya isinya sengaja dibatasi ke angka agregat. Bila Anda butuh
> membatasi akses (mis. hanya domain tertentu), itu hanya bisa ditegakkan dari
> sisi server (panggil API lewat backend web lain memakai token, lalu sajikan
> hasilnya), bukan dari JavaScript di browser.

---

## 1. Autentikasi

API memakai **Bearer token** (Laravel Sanctum / *personal access token*).
Alurnya: login sekali untuk dapat token → simpan token → kirim token di setiap
request berikutnya lewat header:

```
Authorization: Bearer <TOKEN>
Accept: application/json
```

### 1.1. Login — dapatkan token

`POST /api/v1/auth/login` *(tanpa token — endpoint publik; throttle 10 percobaan/menit)*

Kredensial diperiksa terhadap **akun lokal NMS** (`Hash::check` terhadap `users.password`) —
email & kata sandi yang sama dengan login web.

**Body (JSON):**

| Field         | Wajib | Keterangan                                            |
|---------------|-------|-------------------------------------------------------|
| `email`       | ya    | Email akun NMS (format email)                          |
| `password`    | ya    | Kata sandi akun                                        |
| `device_name` | tidak | Label perangkat (mis. `"Android - Budi"`) = nama token. Kosong → User-Agent. |

**Contoh (curl):**

```bash
curl -X POST https://nms.example.com/api/v1/auth/login \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com","password":"rahasia","device_name":"Android - Budi"}'
```

**Respons `200`:**

```json
{
  "data": {
    "token": "12|aBcD3Fg...XyZ",
    "token_type": "Bearer",
    "user": {
      "id": 1,
      "name": "Administrator",
      "email": "admin@example.com",
      "role": "admin",
      "role_label": "Administrator",
      "is_admin": true,
      "is_demo": false
    }
  }
}
```

> Simpan `data.token` di sisi klien (mis. `EncryptedSharedPreferences` di Android,
> atau cookie httpOnly / secret store di backend web lain). Token hanya
> ditampilkan **sekali**.

Kredensial salah → `422` (error validasi pada `email`):

```json
{ "message": "Email atau kata sandi salah.", "errors": { "email": ["Email atau kata sandi salah."] } }
```

Body yang tak lolos validasi (mis. `email` kosong atau bukan format email) juga dijawab `422`
validasi Laravel biasa. Throttle login (10/menit) dijawab `429` bawaan Laravel. Teks `message`
mengikuti bahasa bawaan server (`APP_LOCALE`, lihat §2).

### 1.2. Token untuk integrasi server-ke-server (tanpa login)

**Cara termudah — lewat UI:** masuk sebagai admin → **Pengaturan → tab "API & Token"**
→ isi nama token → **Buat Token**. Token tampil **sekali**; salin dan simpan. Di tab
itu juga bisa melihat & **mencabut** token kapan saja.

**Atau lewat command** (untuk backend tanpa UI / otomasi):

```bash
php artisan api:token admin@example.com --name="Billing App"
```

Output mencetak token sekali. Pakai sebagai `Authorization: Bearer <token>`.

Token bertindak **atas nama user pemiliknya** — role user itu (admin/operator/partner/demo)
yang menentukan endpoint mana yang boleh dipanggil (§3).

Semua token — dari login aplikasi (§1.1), tab **API & Token**, maupun `php artisan api:token` —
punya akses penuh (ability `*`, baca + tulis); yang membatasinya hanya role pemiliknya. Token dari
tab **API & Token** milik admin yang membuatnya; untuk token atas nama user lain (mis. akun
`operator` khusus integrasi) pakai `api:token` dengan email user itu.

Umur token mengikuti batas global `SANCTUM_EXPIRATION` (§5) yang berlaku untuk **semua** token.
`.env.example` mengisinya `43200` menit (30 hari), jadi token integrasi pun perlu diperbarui
berkala — atau kosongkan nilai itu bila token harus berlaku sampai dicabut.

### 1.3. Identitas token saat ini

`GET /api/v1/me` → mengembalikan objek `user` yang sama seperti pada login.

### 1.4. Logout (cabut token)

`POST /api/v1/auth/logout` → menghapus token yang sedang dipakai. Token tak lagi valid.
Satu-satunya aksi tulis yang tetap diizinkan untuk akun `demo`.

Token push FCM ponsel itu ikut dicabut: yang didaftarkan lewat sesi ini (kolom
`fcm_device_tokens.personal_access_token_id`, FK cascade) dan `fcm_token` opsional di body
(dikirim aplikasi ≥1.8.5, menutup baris lama yang belum terkait sesi). Sesi yang kedaluwarsa
dibuang harian (`sanctum:prune-expired`), token push-nya ikut terhapus.

```json
{ "data": { "message": "Token dicabut." } }
```

---

## 2. Konvensi Umum

- **Sukses** dibungkus `{"data": ...}`; daftar menambah `{"meta": ...}`. Pengecualian:
  `GET /odps/{odp}/photo` yang mengirim berkas gambar (§3.6), dan dua endpoint GenieACS (§3.16)
  yang mengembalikan objek datar `{"ok": …}` tanpa `data`.
- **Error** memakai format Laravel standar: `{"message": "...", "errors": {...}}` (errors hanya
  untuk validasi). Semua `/api/*` selalu dijawab JSON, walau klien lupa
  `Accept: application/json` (`bootstrap/app.php`).
- Semua endpoint selain `auth/login` dan `public/status` butuh header `Authorization`.
- **Rate limit:** limiter `api` = **120 request / menit per user** (per IP bila tanpa token),
  didefinisikan di `AppServiceProvider`. Header respons: `X-RateLimit-Limit`,
  `X-RateLimit-Remaining`. Lewat batas → `429`. `auth/login` punya limiter sendiri
  (10/menit).
- **Scoping partner:** `PartnerOltScope` berlaku di model OLT & ODP — partner hanya melihat
  OLT yang di-assign/miliknya; `{olt}`/`{odp}` di luar itu → `404`.
- **Scoping demo:** akun ber-role `demo` hanya melihat data demo dan **read-only**:
  `BlockDemoWrites` terpasang di seluruh grup `api`, jadi setiap request non-GET dari akun
  demo ditolak `403` — termasuk `POST`/`DELETE /devices`; satu-satunya pengecualian
  `auth/logout`.
- **Bahasa pesan:** rute `api` tidak memasang `SetLocale` (preferensi bahasa per pengguna hanya
  berlaku di web), jadi `message` mengikuti bahasa bawaan server `APP_LOCALE` — `id` di
  `.env.example` (Indonesia), `en` bila variabel itu tidak diisi.
- **Kapabilitas driver:** aksi yang tak didukung family OLT-nya (mis. registrasi di non-ZTE)
  dijawab `422` ("Aksi ini tidak didukung untuk driver OLT ini.") — cek dulu `capabilities` di
  `GET /olts/{olt}`.

### Kode status

| Kode  | Arti                                                                 |
|-------|----------------------------------------------------------------------|
| `200` | OK                                                                    |
| `401` | Token tidak ada / tidak valid / kedaluwarsa (`{"message":"Unauthenticated."}`) |
| `403` | Role tidak berhak / akun demo menulis                               |
| `404` | Resource tidak ditemukan (termasuk OLT/ODP di luar scope partner)    |
| `422` | Validasi gagal (cek `errors`), aksi tak didukung driver, atau aksi ke OLT/ACS gagal |
| `429` | Terlalu banyak request (rate limit)                                  |
| `500` | Kesalahan server                                                     |

---

## 3. Endpoint

Semua path relatif terhadap `/api/v1`. Nama rute (`api.*`) dipakai test & `route:list`.

**Publik (tanpa token):**

| Method | Path | Nama rute | Fungsi |
|--------|------|-----------|--------|
| GET    | `/public/status` | `api.public.status` | Status agregat untuk embed (§0) |
| POST   | `/auth/login` | `api.auth.login` | Login, dapatkan token (§1.1) |

**Baca** — butuh token; semua role (admin, operator, partner, demo). Grup
`auth:sanctum` + `throttle:api`:

| Method | Path | Nama rute | Fungsi |
|--------|------|-----------|--------|
| GET    | `/me` | `api.me` | Info user token (§1.3) |
| POST   | `/auth/logout` | `api.auth.logout` | Cabut token (§1.4) |
| GET    | `/summary` | `api.summary` | Ringkasan dashboard (§3.1) |
| GET    | `/search?q=` | `api.search` | Pencarian global OLT + ONU (§3.11) |
| GET    | `/olts` | `api.olts.index` | Daftar OLT + status (§3.2) |
| GET    | `/olts/{olt}` | `api.olts.show` | Detail 1 OLT + `capabilities` (§3.3) |
| GET    | `/onus` | `api.onus.index` | Daftar ONU lintas-OLT, filter + paginasi (§3.4) |
| GET    | `/olts/{olt}/ports/{slot}/{port}/onus` | `api.olts.port-onus` | Daftar ONU 1 PON port (§3.12) |
| GET    | `/olts/{olt}/onus/{slot}/{port}/{onuId}` | `api.olts.onu.show` | Detail 1 ONU (§3.5) |
| GET    | `/olts/{olt}/unconfigured` | `api.olts.unconfigured` | ONU unconfigured dari snapshot (§3.12) |
| GET    | `/olts/{olt}/register/options` | `api.olts.register.options` | Bahan form registrasi ONU (§3.13) |
| GET    | `/alarms` | `api.alarms.index` | Daftar alarm + blok `target` (§3.10) |
| GET    | `/odps` | `api.odps.index` | Daftar ODP + palet warna (§3.6) |
| GET    | `/odps/{odp}` | `api.odps.show` | Detail 1 ODP (§3.6) |
| GET    | `/odps/{odp}/onus` | `api.odps.onus` | ONU di dalam sebuah ODP (§3.7) |
| GET    | `/odps/{odp}/photo` | `api.odps.photo` | Berkas foto ODP (WebP, butuh token) (§3.6) |
| GET    | `/map` | `api.map.index` | Pin ONU + pin ODP untuk peta (§3.8) |
| POST   | `/devices` | `api.devices.store` | Daftarkan token FCM (§3.15) — demo ditolak 403 |
| DELETE | `/devices` | `api.devices.destroy` | Cabut token FCM (§3.15) — demo ditolak 403 |
| POST   | `/devices/test` | `api.devices.test` | Kirim push tes ke perangkat sendiri (§3.15) — demo ditolak 403 |
| GET    | `/olts/{olt}/onus/{slot}/{port}/{onuId}/acs-clients` | `api.onus.acs-clients` | Perangkat terhubung di balik ONU, dari GenieACS (§3.16) — hanya admin/operator, role lain 403 |

**Aksi tulis** — grup `role:admin,operator,partner` + `BlockDemoWrites`. Akun `demo` ditolak
`403`; partner otomatis terbatas ke OLT/ODP miliknya atau yang di-assign (`404` di luar itu):

| Method | Path | Nama rute | Fungsi |
|--------|------|-----------|--------|
| POST   | `/olts/{olt}/register/preview` | `api.olts.register.preview` | Preview script CLI, tanpa menyentuh OLT (§3.13) |
| POST   | `/olts/{olt}/register` | `api.olts.register` | Registrasi ONU ZTE (`execute` bool) (§3.13) |
| POST   | `/olts/{olt}/unconfigured/refresh` | `api.olts.unconfigured.refresh` | Discovery unconfigured live, ZTE-only (§3.12) |
| POST   | `/olts/{olt}/ports/{slot}/{port}/refresh` | `api.olts.port.refresh` | Re-scan ONU 1 port, semua family (§3.14) |
| POST   | `/olts/{olt}/onus/{slot}/{port}/{onuId}/reboot` | `api.olts.onu.reboot` | Reboot ONU (§3.14) |
| POST   | `/olts/{olt}/onus/{slot}/{port}/{onuId}/name` | `api.olts.onu.name` | Ubah nama/deskripsi ONU (§3.14) |
| DELETE | `/olts/{olt}/onus/{slot}/{port}/{onuId}` | `api.olts.onu.delete` | Hapus (deregister) ONU dari OLT (§3.14) |
| POST   | `/odps/{odp}/color` | `api.odps.color` | Warna pin ODP di peta (§3.9) |
| POST   | `/odps/{odp}/photo` | `api.odps.photo.store` | Unggah/ganti foto ODP (§3.9) |
| DELETE | `/odps/{odp}/photo` | `api.odps.photo.destroy` | Hapus foto ODP (§3.9) |
| POST   | `/olts/{olt}/onus/{slot}/{port}/{onuId}/acs-wifi` | `api.onus.acs-wifi` | Ubah SSID & kata sandi WiFi ONU lewat GenieACS (§3.16) — partner tetap 403 |

> **Catatan mobile:** `GET /olts/{olt}` menyertakan `capabilities` (mis. `supports_provisioning`,
> `supports_reboot`, `supports_onu_info_write`, `supports_onu_delete`) agar klien
> menampilkan/menyembunyikan aksi per-driver. Registrasi ONU & discovery unconfigured
> **ZTE-only**; refresh per-port, reboot, rename, dan delete **bercabang per-family** (ZTE,
> C-Data EPON/GPON, HiOSO) — perintahnya menyesuaikan vendor OLT. Aksi tulis mengeksekusi
> Telnet/SNMP sinkron (klien memakai timeout ~120 dtk).

### Push notifikasi FCM (Firebase)

Aplikasi mendaftarkan token perangkat via `POST /devices` setelah login (dan tiap aplikasi dibuka
dalam keadaan login); baris itu **terkait sesi Sanctum** yang mendaftarkannya — sesi dihapus
(logout, `sanctum:prune-expired` harian 03:40) = push berhenti, dan sesi kedaluwarsa disaring
`FcmDeviceToken::deliverable()`. Saat `AlarmEvaluator` menaikkan/menurunkan alarm, server
men-dispatch job `SendFcmAlarmNotifications`.

**Alarm mana yang dikirim diatur terpusat di Pengaturan → tab Alarm** (`alarm_settings`, sama
untuk bot Telegram global & push mobile): severity minimum (`min_severity`), jenis alarm
(`notify_types`, kosong = semua), kirim saat naik / saat pulih (`notify_on_raise` /
`notify_on_clear`), dan konfirmasi 2 poll sebelum mengirim (`confirm_before_notify`) — plus
korelasi root-cause (`suppress_child_alarms`, `group_odp_alarms`). Tab **Notifikasi Mobile**
hanya mengurus saklar kanal push (`fcm_settings.enabled`), kirim notifikasi manual, dan daftar
perangkat; saklar alarm per-OLT menentukan penerimanya. Env `FCM_MIN_SEVERITY` (sisa di
`config/services.php`) tak dibaca kode lagi. Push aktif setelah service-account JSON dipasang
di `storage/app/firebase/service-account.json` (path bawaan `FIREBASE_CREDENTIALS`; isi variabel
itu hanya bila berkasnya di tempat lain) dan saklar di **Pengaturan → Notifikasi Mobile**
dinyalakan; tanpa itu fitur dormant (tak memengaruhi polling).

### 3.1. `GET /summary` — ringkasan dashboard

```bash
curl https://nms.example.com/api/v1/summary \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
```

```json
{
  "data": {
    "olt":  { "total": 2, "online": 2, "offline": 0 },
    "onu":  { "total": 480, "online": 472, "offline": 8, "warning": 5 },
    "online_share": 98.3,
    "alarms": { "total": 3, "critical": 1, "major": 1, "minor": 0, "warning": 1 }
  },
  "meta": { "generated_at": "2026-06-28T10:15:30+07:00" }
}
```

### 3.2. `GET /olts` — daftar OLT

```json
{
  "data": [
    {
      "id": 1,
      "name": "OLT-C320-01",
      "ip": "10.10.0.1",
      "vendor": "ZTE",
      "driver": "zte",
      "is_cdata": false,
      "reachable": true,
      "polling_enabled": true,
      "ports_total": 16,
      "ports_up": 8,
      "ports_down": 8,
      "onu_total": 240,
      "onu_online": 236,
      "onu_offline": 4,
      "last_polled_at": "2026-06-28T10:14:00+07:00",
      "last_tested_at": "2026-06-27T09:00:00+07:00"
    }
  ]
}
```

`driver` salah satu dari (konstanta `SmartOltSupport::DRIVER_*`): `zte` (C300/C320/C600),
`cdata-epon-17409`, `cdata-gpon-34592`, `hioso-epon-25355`, `unknown` (semua kapabilitas
mati). `is_cdata` hanya `true` untuk dua driver C-Data — HiOSO bernilai `false`.
Partner hanya menerima OLT miliknya/yang di-assign.

### 3.3. `GET /olts/{olt}` — detail OLT

`{olt}` = `id` OLT. Mengembalikan field ringkasan (sama seperti di atas) **plus**
`capabilities`, `system`, dan `ports`:

```json
{
  "data": {
    "id": 1,
    "name": "OLT-C320-01",
    "...": "(field ringkasan seperti pada GET /olts)",
    "capabilities": { "supports_provisioning": true, "supports_reboot": true, "supports_onu_delete": true, "...": "…" },
    "system": {
      "sys_name": "OLT-C320-01",
      "sys_descr": "ZTE ZXA10 C320 ...",
      "sys_object_id": "1.3.6.1.4.1.3902...",
      "sys_uptime": "12:34:56:00"
    },
    "ports": [
      {
        "if_index": 285278209,
        "name": "gpon-olt_1/1/1",
        "description": "AREA UTARA",
        "slot": 1,
        "port": 1,
        "oper_status": "up",
        "onu_total": 32,
        "onu_online": 31
      }
    ]
  }
}
```

OLT tak ada → `404`.

`description` = deskripsi port PON (mis. nama area) hasil parse CLI `show interface`
(tabel `smartolt_interface_statuses`) — `null` bila belum pernah ditarik; khusus C600
fallback ke `ifDescr` SNMP. Untuk family **non-ZTE** (C-Data/HiOSO) perangkatnya
tak punya deskripsi port, jadi field ini diisi **label port sisi-NMS** (`olt_port_labels`,
di-set dari dashboard web) — lihat handbook [07 §4c](handbook/07-modul-fitur.md).

### 3.4. `GET /onus` — inventaris ONU lintas-OLT

Endpoint paling berguna untuk aplikasi monitoring pelanggan.

**Query params:**

| Param      | Default | Keterangan                                                       |
|------------|---------|------------------------------------------------------------------|
| `olt_id`   | semua   | Saring 1 OLT saja                                                 |
| `status`   | semua   | `online` \| `offline` \| `warning` (online tapi RX di luar -25…-10 dBm) |
| `q`        | —       | Cari di SN, MAC, nama, deskripsi, nama pelanggan, interface, nama OLT |
| `page`     | `1`     | Halaman                                                          |
| `per_page` | `50`    | Item per halaman (maks `200`)                                    |

```bash
curl "https://nms.example.com/api/v1/onus?status=offline&per_page=20" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
```

```json
{
  "data": [
    {
      "olt_id": 1,
      "olt_name": "OLT-C320-01",
      "port_route": "smartolt.port-onus",
      "olt_cdata": false,
      "slot": 1,
      "port": 1,
      "onu_id": 5,
      "if_index": 285278209,
      "interface": "gpon-onu_1/1/1:5",
      "serial_number": "ZTEGC1234567",
      "mac": null,
      "type_name": "ZTE-F660",
      "name": "Budi Santoso",
      "description": "Jl. Merdeka 10",
      "customer_name": "Budi Santoso",
      "admin_state": "enable",
      "phase_state": "Working",
      "online": true,
      "last_down_cause": null,
      "rx_power_dbm": -21.5,
      "rx_power_label": "-21.5 dBm",
      "odp_id": 26,
      "odp_name": "ODP-A01",
      "acs": null
    }
  ],
  "meta": { "total": 8, "per_page": 20, "current_page": 1, "last_page": 1, "count": 8 }
}
```

Bentuk satu ONU ini (`OnuInventoryService::normalize()`) dipakai juga oleh
`/olts/{olt}/ports/{slot}/{port}/onus` dan detail ONU (§3.5):

- `port_route` = nama rute web halaman ONU per port (`smartolt.` / `cdata-olt.` /
  `hioso-olt.port-onus`); `olt_cdata` = `true` untuk **semua** family non-ZTE (C-Data
  **dan** HiOSO) — nama field-nya warisan.
- `odp_id` / `odp_name` = ODP tempat ONU dikaitkan (`null` bila belum).
- `acs` = pasangan ONU ini dengan device GenieACS (bentuk di §3.5), `null` bila belum
  berpasangan atau GenieACS tidak dipakai. Dibaca dari tabel lokal, tidak memanggil ACS.
- `admin_state` bawaan `"unknown"`, `phase_state` bawaan `"Unknown"` bila family-nya tak
  melaporkan.

### 3.5. `GET /olts/{olt}/onus/{slot}/{port}/{onuId}` — detail 1 ONU

```bash
curl "https://nms.example.com/api/v1/olts/1/onus/1/1/5" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
```

Mengembalikan satu objek ONU (bentuk sama seperti elemen `data` pada `/onus`)
di dalam `{"data": {...}}`, **termasuk `odp_id` + `odp_name`** bila ONU itu sudah
dikaitkan ke sebuah ODP. ONU tak ada di snapshot terakhir → `404`.

Juga menyertakan blok **`acs`** — pasangan ONU ini dengan device GenieACS (TR-069), atau `null`
bila belum berpasangan. Dibaca dari tabel lokal `genieacs_device_map`, **tidak** memanggil ACS:

```json
"acs": {
  "device_id": "D05FAF-FD512XW-R460-CDTCAF0012E6",
  "match_method": "serial",
  "product_class": "FD512XW-R460",
  "pppoe_username": "warga01",
  "ip": "198.51.100.12",
  "last_inform_at": "2026-10-07T10:15:00+07:00",
  "online": true
}
```

`match_method` bernilai `serial` · `mac` · `manual`; `pppoe_username` & `ip` (IP manajemen
TR-069) berasal dari virtual parameter GenieACS dan bisa `null`. `online` memakai ambang **2 jam**
karena `last_inform_at` hanya disegarkan penjadwal tiap 15 menit — bukan status realtime
perangkat. Kesegaran sesungguhnya ada di `/acs-clients` (§3.16).

### 3.6. `GET /odps` — daftar ODP

ODP (Optical Distribution Point) = splitter lapangan; satu ODP terkunci ke satu
OLT + satu PON port. Partner hanya melihat ODP pada OLT miliknya atau yang di-assign padanya.

**Query params:** `olt_id`, `slot`, `port`, `q` (cari nama ODP / nama OLT / catatan).

```bash
curl "https://nms.example.com/api/v1/odps?olt_id=2" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
```

```json
{
  "data": [
    {
      "id": 26, "snmp_olt_id": 2, "olt_name": "OLT-C300-02",
      "name": "ODP-A01", "slot": 2, "port": 3,
      "latitude": -6.2000512, "longitude": 106.8166231,
      "color": "#22d3ee",
      "photo_url": "https://nms.example.com/api/v1/odps/26/photo?v=1a2b3c4d",
      "notes": null, "onu_count": 6
    }
  ],
  "meta": {
    "count": 22,
    "color_palette": ["#f59e0b", "#fb923c", "…"],
    "color_default": "#f59e0b"
  }
}
```

`GET /odps/{odp}` mengembalikan satu ODP dengan bentuk yang sama.

`color` null = pakai `meta.color_default`. `meta.color_palette` adalah daftar warna
resmi dari server (`App\Support\OdpColors`) — klien menampilkannya apa adanya, jangan
menyalin daftarnya ke dalam aplikasi.

`photo_url` null = ODP belum punya foto. Berkasnya ada di disk **privat**, jadi
permintaan gambar (`GET /odps/{odp}/photo`) harus membawa header `Authorization` yang sama
seperti panggilan API lain (di Flutter: `Image.network(url, headers: {...})`); ODP tanpa foto
→ `404`. Query `?v=` berubah tiap foto diganti, sehingga respons aman di-cache lama
(`Cache-Control: private, max-age=604800`). Mengunggah/menghapus foto lewat endpoint tulis §3.9.

Query `q` juga cocok dengan teks `slot/port` (mis. `2/3`). ODP di luar scope partner → `404`.

### 3.7. `GET /odps/{odp}/onus` — ONU di dalam sebuah ODP

Kaitan ONU↔ODP disimpan sebagai posisi `(olt, slot, port, onu_id)`, lalu di-enrich
status live dari snapshot polling terakhir.

```json
{
  "data": [
    {
      "snmp_olt_id": 2, "slot": 2, "port": 3, "onu_id": 80,
      "serial_number": "ZTEGC0000080", "interface": "gpon-onu_1/2/3:80",
      "name": "Pelanggan Contoh", "online": true, "has_live": true,
      "phase_state": "Working", "last_down_cause": "DyingGasp", "admin_state": "active",
      "rx_power_dbm": -25.852, "rx_power_label": "-25.852 dBm",
      "latitude": null, "longitude": null
    }
  ],
  "meta": { "odp_id": 26, "count": 6, "online": 5 }
}
```

- `has_live: false` ⇒ ONU tak ada lagi di snapshot OLT (kaitan ODP-nya basi), bukan sekadar offline.
- `phase_state`/`last_down_cause`/`admin_state` = sebab ONU turun apa adanya dari OLT
  (lihat §3.4) — null pada family yang tak melaporkannya.
- `latitude`/`longitude` berasal dari **pin peta ONU** (null bila ONU belum di-pin).

### 3.8. `GET /map` — payload peta (pin ONU + pin ODP)

Satu request untuk seluruh peta: pin ONU pelanggan, pin ODP beserta ONU yang
tersambung (untuk menggambar garis ODP→ONU), daftar OLT untuk filter, dan titik
tengah default. **Query param:** `olt_id` (opsional).

```json
{
  "data": {
    "pins": [
      { "id": 9, "olt_id": 1, "olt_name": "OLT-C320-01", "slot": 1, "port": 1,
        "onu_id": 5, "interface": "gpon-onu_1/1/1:5", "serial_number": "ZTEG00000005",
        "latitude": -6.205, "longitude": 106.82, "customer_name": "Budi Santoso",
        "address": null, "phone": null, "notes": null,
        "rx_power_dbm": -21.5, "rx_power_label": "-21.50 dBm",
        "online": true, "phase_state": "Working", "last_down_cause": null,
        "admin_state": "active", "has_live": true }
    ],
    "odps": [
      { "id": 26, "snmp_olt_id": 2, "olt_name": "OLT-C300-02",
        "name": "ODP-A01", "slot": 2, "port": 3,
        "latitude": -6.2, "longitude": 106.8166, "color": "#22d3ee",
        "photo_url": null, "locked": true, "notes": null,
        "onus": [ /* bentuk sama dengan /odps/{id}/onus */ ] }
    ],
    "olts": [{ "id": 1, "name": "OLT-C320-01" }],
    "default_center": { "lat": -6.2025, "lng": 106.8183, "zoom": 13 }
  },
  "meta": { "pins": 1, "odps": 22 }
}
```

Payload dirakit `App\Services\Map\OnuMapPayloadService` — sumber yang sama dengan halaman
peta web (`OnuMapController`), hanya dipangkas dari field khusus web. `photo_url` ODP memakai
rute ber-token `api.odps.photo`.

`default_center` dihitung dari pin ONU **dan** pin ODP yang terlihat pengguna — **bukan
rata-rata koordinat** (`OnuMapPayloadService::defaultCenter()`):

- tanpa titik → "wilayah utama" bila diisi (`MAP_HOME_LAT`/`MAP_HOME_LNG`/`MAP_HOME_ZOOM` di
  `.env` → `services.map.home_*`), kalau tidak tampilan seluruh Indonesia (`-2.5, 118.0`, zoom 5);
- satu titik → titik itu (zoom 15);
- wilayah utama diisi dan ada titik dalam radiusnya (`MAP_HOME_RADIUS_KM`, bawaan 20 km) →
  wilayah utama;
- selain itu → rata-rata kelompok terpadat (sel grid 0,1°, zoom 13).

Jadi peta tetap terbuka di area kerja meski sebagian ONU belum di-pin.

Peta di aplikasi hampir sepenuhnya **baca-saja**: menambah/menggeser pin & CRUD ODP
tetap lewat dashboard web — kecuali warna pin dan foto ODP (§3.9).

### 3.9. `POST /odps/{odp}/color` — warna pin ODP (tulis)

Warna dipakai mengelompokkan ODP per PON port di peta, jadi **bawaannya mewarnai
semua ODP di port yang sama**. Butuh role `admin`/`operator`/`partner` (grup tulis, §3);
akun `demo` ditolak 403, ODP di luar scope partner 404.

| Field | Tipe | Arti |
|-------|------|------|
| `color` | string\|null | `#rrggbb` (case-insensitive). `null` = kembali ke warna bawaan. |
| `random` | bool | `true` = server memilih warna palet yang belum dipakai port lain di OLT itu (mengabaikan `color`). |
| `apply_to_port` | bool | Bawaan `true` = semua ODP di `(olt, slot, port)` yang sama. ODP tanpa slot/port selalu sendiri. |

```bash
curl -X POST -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  -d 'color=%2322d3ee&apply_to_port=1' \
  https://nms.example.com/api/v1/odps/26/color
# → { "data": { "id": 26, "color": "#22d3ee", "color_effective": "#22d3ee", "updated": 4 } }
```

`updated` = jumlah ODP yang ikut terwarnai. Hex tak valid → 422 (`errors.color`).

#### Foto ODP (`POST` / `DELETE /odps/{odp}/photo`)

Satu foto per ODP — unggahan baru menimpa yang lama. Kirim **multipart/form-data**
dengan field `photo` (`jpg`, `jpeg`, `png`, atau `webp`, maks 12 MB); server yang
mengonversinya ke WebP, klien cukup mengirim berkas aslinya. Otorisasi sama dengan
endpoint tulis lain (`admin`/`operator`/`partner`; demo 403, ODP luar scope 404).

```bash
curl -X POST -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  -F "photo=@odp-a01.jpg" \
  https://nms.example.com/api/v1/odps/26/photo
# → { "data": { "id": 26, "photo_url": "https://…/api/v1/odps/26/photo?v=1a2b3c4d", "ok": true } }

curl -X DELETE -H "Authorization: Bearer $TOKEN" \
  https://nms.example.com/api/v1/odps/26/photo
# → { "data": { "id": 26, "photo_url": null, "ok": true } }
```

Berkas yang tak lolos validasi → 422 (`errors.photo`). Ingat batas `upload_max_filesize`
PHP-FPM di server (disarankan ≥ 12M) — kalau lebih kecil, unggahan besar ditolak
sebelum sampai ke validasi Laravel.

### 3.10. `GET /alarms` — daftar alarm

**Query params:**

| Param      | Default  | Keterangan                                                    |
|------------|----------|---------------------------------------------------------------|
| `status`   | `active` | `active` \| `cleared` \| `all`                                |
| `severity` | semua    | `critical` \| `major` \| `minor` \| `warning`                 |
| `type`     | semua    | `olt_unreachable`,`port_down`,`port_disabled`,`odp_down`,`los`,`dying_gasp`,`onu_offline`,`high_rx_attenuation` (`AlarmEvent::types()`) |
| `olt_id`   | semua    | Saring 1 OLT                                                   |
| `page`     | `1`      | Halaman                                                        |
| `per_page` | `50`     | Maks `200`                                                     |

Nilai query yang tak dikenal diabaikan (bukan 422). Alarm berstatus `pending` (menunggu
konfirmasi poll ke-2, lihat Pengaturan → Alarm) **tidak pernah** dikembalikan — hanya
`active` dan `cleared`. Urut `last_seen_at` terbaru.

```json
{
  "data": [
    {
      "id": 12,
      "olt_id": 1,
      "olt_name": "OLT-C320-01",
      "type": "onu_offline",
      "type_label": "ONU offline",
      "severity": "major",
      "status": "active",
      "scope": "onu",
      "slot": 1,
      "port": 1,
      "onu_id": 5,
      "serial_number": "ZTEGC1234567",
      "customer_name": "Budi Santoso",
      "message": "ONU offline (dying gasp)",
      "first_seen_at": "2026-06-28T09:00:00+07:00",
      "last_seen_at": "2026-06-28T10:14:00+07:00",
      "cleared_at": null,
      "target": {
        "resource_type": "onu",
        "olt_id": 1,
        "slot": 1,
        "port": 1,
        "onu_id": 5,
        "openable": true,
        "reason": null
      }
    }
  ],
  "meta": { "total": 3, "per_page": 50, "current_page": 1, "last_page": 1, "count": 3 }
}
```

`scope` = `olt` \| `port` \| `onu` \| `odp`. `customer_name` diambil dari `meta` alarm
(dibersihkan `cleanCustomerName()`; `null` bila kosong, placeholder, sama dengan serial, atau
berupa nama interface). `type_label` menyesuaikan teknologi PON OLT
(mis. "Port EPON down").

#### `target` — tujuan navigasi (deep-link)

`slot`/`port`/`onu_id` tingkat-atas adalah posisi **saat alarm tercatat** (historis).
**Jangan bernavigasi memakai field itu**: bila ONU diprovisioning ulang, ONU lain bisa kini
menempati posisi yang sama dan klien akan membuka pelanggan yang salah.

Pakai `target`, yang diputuskan server (`AlarmNotificationTargetResolver::resolveLocation()`,
resolver yang sama dengan bel notifikasi web) dengan mengikuti `serial_number` di inventaris
terkini:

| Field | Arti |
|-------|------|
| `resource_type` | `onu` \| `port` \| `olt` — layar tujuan. Alarm `scope=odp` diarahkan ke `port` (daftar ONU port tempat ODP itu), atau `olt` bila ODP belum punya slot/port |
| `olt_id` | OLT tujuan (`null` bila OLT sudah tak ada) |
| `slot`/`port`/`onu_id` | Posisi **sekarang** (bisa berbeda dari posisi historis) |
| `openable` | `false` ⇒ **jangan** buka resource-nya; paling jauh buka OLT-nya |
| `reason` | `null` bila bersih; selain itu `onu_moved` (serial diikuti ke posisi baru, tetap `openable`), `position_reused` (posisi lama kini ditempati ONU lain), `onu_not_found`, `incomplete_location`, `olt_unavailable` |

`openable` **tidak** bergantung pada capability web `supports_cli_onu_detail`: aplikasi mobile
punya layar detail ONU sendiri (lewat API ini) yang berlaku untuk semua family. Diputuskan
tanpa SNMP/Telnet — hanya dari snapshot cache.

### 3.11. `GET /search?q=` — pencarian global

Sumber tunggal `App\Services\GlobalSearchService`, dipakai bersama pencarian web (⌘K).
`q` minimal 2 karakter (kurang → `data: []`); maksimal 10 hasil: OLT dulu (nama/IP, maks 5),
lalu ONU dari snapshot cache (serial, nama, interface, **MAC** — ONU EPON tak punya serial
terpisah). Hasilnya data navigasi terstruktur, bukan URL web:

```json
{
  "data": [
    { "type": "olt", "label": "OLT-C320-01", "sublabel": "10.10.0.1", "olt_id": 1, "olt_name": "OLT-C320-01",
      "slot": null, "port": null, "onu_id": null, "serial_number": null },
    { "type": "onu", "label": "ZTEGC1234567", "sublabel": "OLT-C320-01 · 1/1 · Budi Santoso", "olt_id": 1,
      "olt_name": "OLT-C320-01", "slot": 1, "port": 1, "onu_id": 5, "serial_number": "ZTEGC1234567" }
  ]
}
```

### 3.12. ONU per port & ONU unconfigured

- `GET /olts/{olt}/ports/{slot}/{port}/onus` — ONU satu PON port dari snapshot
  `last_test_result.port_onus.{slot}_{port}`; `data` = daftar ONU bentuk §3.4 (urut `onu_id`),
  `meta` = `{olt_id, slot, port, count, refreshed_at}` (`refreshed_at` = kapan port itu terakhir
  di-scan).
- `GET /olts/{olt}/unconfigured` — ONU autofind dari snapshot
  `last_test_result.unconfigured_onus`; `data` = daftar ONU, `meta` = `{olt_id, ok, count, error,
  refreshed_at}`. Hanya terisi di ZTE; family lain selalu daftar kosong.
- `POST /olts/{olt}/unconfigured/refresh` *(tulis)* — discovery live (**ZTE-only**; non-ZTE →
  `422`). `data` = `{ok, count, onus, error, refreshed_at}`; `422` bila discovery gagal.

### 3.13. Registrasi ONU (ZTE, mode dasar)

- `GET /olts/{olt}/register/options` — query opsional `slot`, `port`, `sn`,
  `suggested_onu_id`. `data` = `{capabilities, profiles, defaults, odps}`: `profiles` = profil
  onu_type/tcont/vlan/ip per-OLT (fallback global); `defaults` = nilai awal form (saran ONU-id
  bila slot/port diberikan); `odps` = **seluruh** ODP OLT itu lengkap `slot`/`port` — klien
  menyaringnya per PON port (slot/port masih bisa diubah pengguna).
- `POST /olts/{olt}/register/preview` *(tulis)* — body sama dengan register; `data` =
  `{script}`. Tidak menyentuh OLT.
- `POST /olts/{olt}/register` *(tulis)* — field utama: `serial_number`, `slot`, `port`, `onu_id`,
  `customer_name`, `onu_type`, `tcont_profile`, `vlan`/`vlan_profile`, `wan_mode` +
  `pppoe_username`/`pppoe_password` atau `ip_profile`/`static_ip`/`static_netmask`,
  `tr069_enabled` + `acs_url`/`acs_username`/`acs_password` (`acs_password` kosong = diisi server
  dari pengaturan ACS tersimpan — **Pengaturan → ACS / TR069**, fallback env `ACS_PASSWORD` di
  `config/services.php`, **hanya untuk OLT global non-demo**; OLT privat partner wajib membawa
  `acs_password` sendiri; klien hanya menerima `defaults.acs_password_set`; `script` pratinjau
  menyamarkannya jadi `password ********`), `remote_ont_*`, `odp_id` (opsional), dan `execute` (bool).
  Aturan lengkapnya `OnuRegistrationService::rules($olt)`.

  Gerbang: capability `supports_provisioning` (non-ZTE → `422`); `execute=true` juga butuh
  `supports_cli_onu_configure`. Setiap panggilan mencatat baris audit
  `smartolt_onu_registrations`. `data` = `{status, registration_id, script, output, error}` (+
  `odp_error` setelah eksekusi) dengan `status` `generated` (tanpa execute) \| `executed` → HTTP
  `200`, atau `failed` → `422`. Kaitan ODP dibuat **setelah** CLI sukses; kegagalannya muncul sebagai
  `data.odp_error` tanpa membatalkan registrasi.

### 3.14. Refresh port & aksi ONU (tulis)

Semua di grup tulis (§3). Exception dari OLT → `422 {"message": "..."}`.

- `POST /olts/{olt}/ports/{slot}/{port}/refresh` — re-scan live satu port: ZTE walk subtree
  tabel ONU port itu; C-Data/HiOSO query per-port lewat driver SNMP-nya. Hasil menimpa
  `port_onus.{slot}_{port}` (dibaca §3.12). `data` = `{ok, count, error, refreshed_at}`;
  `422` bila gagal.
- `POST /olts/{olt}/onus/{slot}/{port}/{onuId}/reboot` — capability `supports_reboot`.
  `data` = `{ok, message, error}`; `200`/`422`.
- `POST /olts/{olt}/onus/{slot}/{port}/{onuId}/name` — capability `supports_onu_info_write`.
  Body `name`, `description` (maks 191, minimal salah satu), `if_index` (ZTE, opsional — dicari
  dari snapshot bila kosong). C-Data/HiOSO hanya punya `name` (tanpa `name` → `422`); HiOSO
  HA7302 menulis lewat SNMP SET. Snapshot cache langsung diperbarui. `data` = `{ok, message}`.
- `DELETE /olts/{olt}/onus/{slot}/{port}/{onuId}` — **destruktif**, deregistrasi permanen dari
  OLT; capability `supports_onu_delete`. Perintah per family: ZTE `no onu {id}`, C-Data
  `ont delete {port} {id}`, HiOSO `delete onu {id}` (HA7302 dialeknya sendiri). Sukses → ONU
  dibuang dari snapshot cache. `data` = `{ok, message, error}`; `200`/`422`.

```bash
curl -X DELETE -H "Authorization: Bearer $TOKEN" \
  https://nms.example.com/api/v1/olts/1/onus/1/2/5
# → { "data": { "ok": true, "message": "ONU 5 dihapus dari OLT.", "error": null } }
```

### 3.15. Perangkat push (FCM)

- `POST /devices` — body `token` (wajib, maks 255), `device_name`, `platform` (bawaan
  `android`). Token unik: bila sudah ada, di-rebind ke user & sesi login saat ini.
  → `{"data": {"registered": true}}`.
- `DELETE /devices` — body `token`; hanya menghapus milik user sendiri.
  → `{"data": {"removed": true}}`.
- `POST /devices/test` — push tes ke semua perangkat user ini yang masih terkirim.
  → `{"data": {"ok": bool, "sent": int, "message": "..."}}`; `ok=false` (HTTP tetap `200`, tanpa
  `sent`) bila FCM belum dikonfigurasi atau perangkat belum terdaftar.

Akun `demo` ditolak `403` di ketiganya (semua non-GET, §2).

### 3.16. GenieACS / TR-069 — perangkat terhubung & ubah WiFi

Hanya berguna bila server NMS dihubungkan ke GenieACS (admin: **Pengaturan → ACS / TR069 →
GenieACS (NBI)**; lihat [`handbook/20-genieacs-tr069.md`](handbook/20-genieacs-tr069.md)). Dua
endpoint ini **memanggil NBI GenieACS** (berbeda dari endpoint lain yang membaca cache), jadi
panggil hanya saat layar dibuka atau tombol ditekan — jangan di dalam daftar. Respons **tidak**
dibungkus `data`.

**Akses:** hanya **admin & operator**, dan hanya pada OLT yang memakai ACS — OLT global non-demo
(`User::canUseAcsCatalogOn`). Partner (juga pada OLT yang di-assign kepadanya), demo, OLT privat
partner, dan OLT demo → `403`. Isinya data pelanggan (host LAN, sandi WiFi).

#### `GET /olts/{olt}/onus/{slot}/{port}/{onuId}/acs-clients`

Hasil di-cache server **30 detik** per device; kirim `?fresh=1` untuk membuang cache itu (tombol
"muat ulang").

```bash
curl "https://nms.example.com/api/v1/olts/1/onus/1/1/5/acs-clients" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
```

```json
{
  "ok": true,
  "error": null,
  "device_id": "D05FAF-FD512XW-R460-CDTCAF0012E6",
  "product_class": "FD512XW-R460",
  "last_inform_at": "2026-10-07T10:15:00+07:00",
  "active_count": 3,
  "unknown_count": 2,
  "total_count": 41,
  "wifi_networks": [
    { "index": "1", "label": "SSID 1", "ssid": "WIFI-RUMAH", "enabled": true, "security": "11i", "connected": 3 }
  ],
  "hosts": [
    {
      "hostname": "HP-Teknisi",
      "ip_address": "192.168.1.20",
      "mac_address": "AA:BB:CC:DD:EE:10",
      "interface_type": "802.11",
      "active": true,
      "active_source": "wifi"
    }
  ],
  "fetched_at": "2026-10-07T10:16:02+07:00"
}
```

- Contoh di atas diringkas: tiap `wifi_networks[]` juga memuat `password` (bila ONU
  melaporkannya), `clients`, `channel`, `frequency`, dll.
- `wifi_networks[].index` berupa **string** (dipakai apa adanya sebagai `wlan_index` saat menulis);
  `enabled` diteruskan **apa adanya dari perangkat** — bisa boolean, string `"1"`, atau `"N/A"`
  bila ONU tak melaporkannya, jadi jangan bandingkan dengan `=== true`.
- `active_source` menerangkan dari mana status itu diketahui: `wifi` (terlihat di tabel asosiasi
  WiFi), `device` (dari field `Active` perangkat), atau `null` (tidak diketahui).

> ⚠️ `hosts` berasal dari `InternetGatewayDevice…Hosts.Host`, yang sebenarnya **tabel sewa DHCP**,
> bukan daftar perangkat yang sedang tersambung — satu ONU bisa memuat 253 entri padahal hanya 11
> yang aktif. Server sudah menandai `active`; klien sebaiknya menampilkan `active === true` saja dan
> memakai `active_count`. Nilai **`null`** berarti perangkat itu tidak melaporkan statusnya —
> **jangan** diperlakukan sebagai `false`.

Gagal → HTTP `422` dengan `{"ok": false, "error": "..."}`: `not_linked` (ONU belum berpasangan —
keadaan normal, bukan kerusakan), `not_configured` (alamat NBI belum diisi di Pengaturan), atau
galat NBI (mis. `unreachable`). Bentuk respons gagal tetap lengkap (semua field ada, `hosts`
kosong), jadi klien tak perlu membedakan skema.

#### `POST /olts/{olt}/onus/{slot}/{port}/{onuId}/acs-wifi` (tulis)

Satu-satunya endpoint API yang **menulis ke perangkat pelanggan**. Berada di grup tulis
(`role:admin,operator,partner` + `BlockDemoWrites`), tetapi controller tetap menolak partner
dengan `403` (aturan akses di atas). Dicatat ke audit (`genieacs.wifi.updated`/`.failed`,
`channel: mobile`; kata sandinya tidak ikut dicatat). Validasi gagal → `422` Laravel biasa
(`errors`).

```bash
curl -X POST "https://nms.example.com/api/v1/olts/1/onus/1/1/5/acs-wifi" \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"ssid":"WIFI-RUMAH","password":"rahasia12345","wlan_index":1}'
# → { "ok": true, "error": null, "device_id": "…", "wlan_index": 1, "ssid": "WIFI-RUMAH" }
```

| Field | Wajib | Aturan |
|---|---|---|
| `ssid` | ya | 1–32 karakter |
| `password` | ya | **8–63 karakter** (WPA-PSK; lebih pendek ditolak ONU dengan CWMP fault) |
| `wlan_index` | ya | 1–8 — indeks SSID, ambil dari `wifi_networks` pada `/acs-clients` |
| `security_mode` | tidak | default `WPA2PSK` |

Perubahan dikirim lewat TR-069 dan **baru berlaku setelah ONU menerimanya**; perangkat yang
sedang tersambung akan terputus sesaat. Kode gagal (`422`): `not_linked`, `not_configured`, galat
NBI (mis. `unreachable`), `write_failed` (ONU menolak perintahnya).

> **Penyematan manual pasangan ONU↔device ACS belum ada di API** — hanya di web.

---

## 4. Contoh integrasi klien

### 4.1. JavaScript (fetch) — untuk web aplikasi lain

```js
const BASE = "https://nms.example.com/api/v1";

async function login(email, password) {
  const res = await fetch(`${BASE}/auth/login`, {
    method: "POST",
    headers: { "Content-Type": "application/json", Accept: "application/json" },
    body: JSON.stringify({ email, password, device_name: "Web Billing" }),
  });
  if (!res.ok) throw new Error("Login gagal");
  const { data } = await res.json();
  return data.token; // simpan
}

async function getOfflineOnus(token) {
  const res = await fetch(`${BASE}/onus?status=offline&per_page=100`, {
    headers: { Authorization: `Bearer ${token}`, Accept: "application/json" },
  });
  const json = await res.json();
  return json.data; // array ONU offline
}
```

### 4.2. Android (Kotlin + Retrofit)

```kotlin
// --- Model ---
data class LoginReq(val email: String, val password: String, val device_name: String)
data class LoginRes(val data: TokenData)
data class TokenData(val token: String, val token_type: String, val user: User)
data class Envelope<T>(val data: T, val meta: Meta?)
data class Onu(
  val olt_name: String, val interface: String?, val serial_number: String?,
  val customer_name: String?, val online: Boolean, val rx_power_dbm: Double?
)
data class Meta(val total: Int, val per_page: Int, val current_page: Int, val last_page: Int)

// --- Service ---
interface NmsApi {
  @POST("auth/login")
  suspend fun login(@Body body: LoginReq): LoginRes

  @GET("onus")
  suspend fun onus(
    @Header("Authorization") bearer: String,
    @Query("status") status: String? = null,
    @Query("q") q: String? = null,
    @Query("page") page: Int = 1,
    @Query("per_page") perPage: Int = 50
  ): Envelope<List<Onu>>

  @GET("summary")
  suspend fun summary(@Header("Authorization") bearer: String): Envelope<Map<String, Any>>
}

// --- Pemakaian ---
val api = Retrofit.Builder()
  .baseUrl("https://nms.example.com/api/v1/")
  .addConverterFactory(GsonConverterFactory.create())
  .build()
  .create(NmsApi::class.java)

val token = api.login(LoginReq("admin@example.com", "rahasia", "Android - Budi")).data.token
val bearer = "Bearer $token"
val offline = api.onus(bearer, status = "offline").data
```

### 4.3. PHP (Guzzle / backend lain)

```php
$client = new GuzzleHttp\Client(['base_uri' => 'https://nms.example.com/api/v1/']);

$token = json_decode($client->post('auth/login', ['json' => [
    'email' => 'admin@example.com', 'password' => 'rahasia', 'device_name' => 'Billing',
]])->getBody(), true)['data']['token'];

$onus = json_decode($client->get('onus', [
    'query'   => ['status' => 'offline'],
    'headers' => ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'],
])->getBody(), true)['data'];
```

---

## 5. Catatan operasional (untuk admin server)

- **Rute API ikut di-cache** bersama rute web (`bootstrap/cache/routes-v7.php`): rute baru/berubah
  (termasuk saklar `$apiEnabled`) akan `404`/`405` di produksi sampai `php artisan route:cache`
  dijalankan ulang. `scripts/test.sh` mengalihkan `APP_ROUTES_CACHE`, jadi test tetap hijau
  walau cache produksi basi — test lulus ≠ rute sudah aktif. Setelah mengubah `.env`/config:
  `php artisan config:cache`. nginx cukup meneruskan `/api/*` ke PHP-FPM (semua di-handle Laravel).
- Migrasi tabel token: `php artisan migrate` (membuat `personal_access_tokens`).
- **Umur token:** `SANCTUM_EXPIRATION` (menit, dihitung dari waktu terbit — bukan dari pemakaian
  terakhir) berlaku untuk **semua** token: sesi aplikasi Android, token dari Pengaturan, dan
  `api:token`. `.env.example` = `43200` (30 hari); kosong = tak pernah kedaluwarsa. Token yang
  lewat batas dijawab `401` (aplikasi Android lalu meminta login ulang). Setelah mengubahnya:
  `php artisan config:cache`.
- Token juga berakhir bila dicabut: `/auth/logout`, "Cabut" di **Pengaturan → API & Token**
  (token integrasi milik admin) atau **Pengaturan → Notifikasi Mobile** (sesi aplikasi per
  perangkat), atau hapus barisnya di tabel `personal_access_tokens`. Token kedaluwarsa dibersihkan
  `sanctum:prune-expired --hours=24` (harian 03:40) — token push FCM-nya ikut terhapus.
- Memperbesar/mengubah rate limit: edit limiter `api` di
  `app/Providers/AppServiceProvider.php`.

## 6. Roadmap (belum tersedia di v1)

- Aksi tulis lanjutan: enable/disable ONU (set state) — kini hanya di web.
- Webhook event alarm untuk server pihak lain (push FCM ke aplikasi Android sudah ada, §3).
- Filter rentang waktu & ekspor.

Bila butuh salah satu di atas, ajukan agar ditambahkan di `v2`.
