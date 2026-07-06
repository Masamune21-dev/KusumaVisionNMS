# KusumaVision NMS — REST API v1

API read-only untuk **memonitor jaringan FTTH/GPON** dari aplikasi lain (web app
lain, aplikasi Android, backend billing, dsb). Cukup panggil endpoint di bawah,
kirim token, dan baca hasilnya dalam format JSON.

> ⛔ **STATUS: API DINONAKTIFKAN** (default, demi keamanan selama belum dipakai).
> Saklarnya `$apiEnabled` di `routes/api.php`. Untuk **mengaktifkan**: ubah
> `$apiEnabled = true`, lalu `sudo systemctl reload php8.3-fpm`. Selama mati,
> semua `/api/*` membalas `404` dan tab **Pengaturan → API & Token** menampilkan
> peringatan + tombol "Buat Token" dinonaktifkan.

> **Sifat API ini:** *read-only* (hanya membaca/monitoring). Data berasal dari
> snapshot polling terakhir yang tersimpan di server — pemanggilan API **tidak**
> mengakses perangkat OLT secara langsung, jadi cepat dan aman. Aksi tulis
> (register/reboot/hapus ONU) **belum** diekspos lewat API v1.

- Base URL (produksi): `https://nms.kusumavision.net/api/v1`
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
> Hasil di-cache 30 detik di server.

```bash
curl https://nms.kusumavision.net/api/v1/public/status
```

```json
{
  "data": {
    "olt": { "total": 2, "online": 2, "offline": 0 },
    "onu": { "total": 480, "online": 472, "offline": 8 },
    "online_share": 98.3,
    "alarms": { "active": 3 },
    "olts": [
      { "name": "OLT-C320-PATI", "reachable": true, "onu_total": 240, "onu_online": 236, "onu_offline": 4, "last_polled_at": "2026-06-28T10:14:00+07:00" }
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
  const BASE = "https://nms.kusumavision.net/api/v1";
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

`POST /api/v1/auth/login` *(tanpa token — endpoint publik)*

**Body (JSON):**

| Field         | Wajib | Keterangan                                            |
|---------------|-------|-------------------------------------------------------|
| `email`       | ya    | Email akun NMS                                         |
| `password`    | ya    | Kata sandi akun                                        |
| `device_name` | tidak | Label perangkat (mis. `"Android - Budi"`). Untuk identifikasi token. |

**Contoh (curl):**

```bash
curl -X POST https://nms.bmkv.net/api/v1/auth/login \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@bmkv.net","password":"rahasia","device_name":"Android - Budi"}'
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
      "email": "admin@bmkv.net",
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

Kredensial salah → `422`:

```json
{ "message": "Email atau kata sandi salah.", "errors": { "email": ["Email atau kata sandi salah."] } }
```

### 1.2. Token untuk integrasi server-ke-server (tanpa login)

**Cara termudah — lewat UI:** masuk sebagai admin → **Pengaturan → tab "API & Token"**
→ isi nama token → **Buat Token**. Token tampil **sekali**; salin dan simpan. Di tab
itu juga bisa melihat & **mencabut** token kapan saja.

**Atau lewat command** (untuk backend tanpa UI / otomasi):

```bash
php artisan api:token admin@bmkv.net --name="Billing App"
```

Output mencetak token sekali. Pakai sebagai `Authorization: Bearer <token>`.

### 1.3. Identitas token saat ini

`GET /api/v1/me` → mengembalikan objek `user` yang sama seperti pada login.

### 1.4. Logout (cabut token)

`POST /api/v1/auth/logout` → menghapus token yang sedang dipakai. Token tak lagi valid.

```json
{ "data": { "message": "Token dicabut." } }
```

---

## 2. Konvensi Umum

- **Sukses** selalu dibungkus `{"data": ...}`. Daftar yang dipaginasi menambah `{"meta": ...}`.
- **Error** memakai format Laravel standar: `{"message": "...", "errors": {...}}` (errors hanya untuk validasi).
- Semua endpoint selain login butuh header `Authorization`.
- **Rate limit:** 120 request / menit per token. Header respons: `X-RateLimit-Limit`, `X-RateLimit-Remaining`. Lewat batas → `429`.
- **Scoping demo:** akun ber-role `demo` hanya melihat data demo; akun nyata melihat data nyata.

### Kode status

| Kode  | Arti                                                                 |
|-------|----------------------------------------------------------------------|
| `200` | OK                                                                    |
| `401` | Token tidak ada / tidak valid (`{"message":"Unauthenticated."}`)     |
| `404` | Resource tidak ditemukan                                             |
| `422` | Validasi gagal (cek `errors`)                                        |
| `429` | Terlalu banyak request (rate limit)                                  |
| `500` | Kesalahan server                                                     |

---

## 3. Endpoint

Ringkasan:

| Method | Path                                              | Fungsi                                  |
|--------|---------------------------------------------------|-----------------------------------------|
| GET    | `/public/status`                                  | **Status agregat, tanpa token** (embed) |
| POST   | `/auth/login`                                     | Login, dapatkan token                   |
| GET    | `/me`                                             | Info user token                         |
| POST   | `/auth/logout`                                    | Cabut token                             |
| GET    | `/summary`                                        | Ringkasan dashboard (counter)           |
| GET    | `/olts`                                            | Daftar OLT + status                     |
| GET    | `/olts/{olt}`                                       | Detail 1 OLT (system, port, ONU)        |
| GET    | `/onus`                                            | Daftar ONU lintas-OLT (filter+paginasi) |
| GET    | `/olts/{olt}/onus/{slot}/{port}/{onuId}`           | Detail 1 ONU                            |
| GET    | `/alarms`                                          | Daftar alarm                            |

### 3.1. `GET /summary` — ringkasan dashboard

```bash
curl https://nms.bmkv.net/api/v1/summary \
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
      "name": "OLT-C320-PATI",
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

`driver` salah satu dari: `zte`, `cdata_epon`, `cdata_gpon`, `hioso_epon`, `unknown`.

### 3.3. `GET /olts/{olt}` — detail OLT

`{olt}` = `id` OLT. Mengembalikan field ringkasan (sama seperti di atas) **plus**
`system` dan `ports`:

```json
{
  "data": {
    "id": 1,
    "name": "OLT-C320-PATI",
    "...": "(field ringkasan seperti pada GET /olts)",
    "system": {
      "sys_name": "OLT-C320-PATI",
      "sys_descr": "ZTE ZXA10 C320 ...",
      "sys_object_id": "1.3.6.1.4.1.3902...",
      "sys_uptime": "12:34:56:00"
    },
    "ports": [
      {
        "if_index": 285278209,
        "name": "gpon-olt_1/1/1",
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
curl "https://nms.bmkv.net/api/v1/onus?status=offline&per_page=20" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
```

```json
{
  "data": [
    {
      "olt_id": 1,
      "olt_name": "OLT-C320-PATI",
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
      "rx_power_label": "-21.5 dBm"
    }
  ],
  "meta": { "total": 8, "per_page": 20, "current_page": 1, "last_page": 1, "count": 8 }
}
```

### 3.5. `GET /olts/{olt}/onus/{slot}/{port}/{onuId}` — detail 1 ONU

```bash
curl "https://nms.bmkv.net/api/v1/olts/1/onus/1/1/5" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
```

Mengembalikan satu objek ONU (bentuk sama seperti elemen `data` pada `/onus`)
di dalam `{"data": {...}}`. Tidak ditemukan → `404`.

### 3.6. `GET /alarms` — daftar alarm

**Query params:**

| Param      | Default  | Keterangan                                                    |
|------------|----------|---------------------------------------------------------------|
| `status`   | `active` | `active` \| `cleared` \| `all`                                |
| `severity` | semua    | `critical` \| `major` \| `minor` \| `warning`                 |
| `type`     | semua    | `olt_unreachable`,`port_down`,`los`,`dying_gasp`,`onu_offline`,`high_rx_attenuation` |
| `olt_id`   | semua    | Saring 1 OLT                                                   |
| `page`     | `1`      | Halaman                                                        |
| `per_page` | `50`     | Maks `200`                                                     |

```json
{
  "data": [
    {
      "id": 12,
      "olt_id": 1,
      "olt_name": "OLT-C320-PATI",
      "type": "onu_offline",
      "type_label": "ONU offline",
      "severity": "major",
      "status": "active",
      "scope": "onu",
      "slot": 1,
      "port": 1,
      "onu_id": 5,
      "serial_number": "ZTEGC1234567",
      "message": "ONU offline (dying gasp)",
      "first_seen_at": "2026-06-28T09:00:00+07:00",
      "last_seen_at": "2026-06-28T10:14:00+07:00",
      "cleared_at": null
    }
  ],
  "meta": { "total": 3, "per_page": 50, "current_page": 1, "last_page": 1, "count": 3 }
}
```

---

## 4. Contoh integrasi klien

### 4.1. JavaScript (fetch) — untuk web aplikasi lain

```js
const BASE = "https://nms.bmkv.net/api/v1";

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
  .baseUrl("https://nms.bmkv.net/api/v1/")
  .addConverterFactory(GsonConverterFactory.create())
  .build()
  .create(NmsApi::class.java)

val token = api.login(LoginReq("admin@bmkv.net", "rahasia", "Android - Budi")).data.token
val bearer = "Bearer $token"
val offline = api.onus(bearer, status = "offline").data
```

### 4.3. PHP (Guzzle / backend lain)

```php
$client = new GuzzleHttp\Client(['base_uri' => 'https://nms.bmkv.net/api/v1/']);

$token = json_decode($client->post('auth/login', ['json' => [
    'email' => 'admin@bmkv.net', 'password' => 'rahasia', 'device_name' => 'Billing',
]])->getBody(), true)['data']['token'];

$onus = json_decode($client->get('onus', [
    'query'   => ['status' => 'offline'],
    'headers' => ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'],
])->getBody(), true)['data'];
```

---

## 5. Catatan operasional (untuk admin server)

- Setelah deploy perubahan rute, **rebuild cache rute** di produksi:
  `php artisan route:cache && php artisan config:cache`, lalu pastikan nginx
  meneruskan `/api/*` ke PHP-FPM (umumnya sudah, karena semua di-handle Laravel).
- Migrasi tabel token: `php artisan migrate` (membuat `personal_access_tokens`).
- Token tak kedaluwarsa otomatis kecuali diatur. Cabut manual lewat `/auth/logout`
  atau hapus baris di tabel `personal_access_tokens`.
- Memperbesar/mengubah rate limit: edit limiter `api` di
  `app/Providers/AppServiceProvider.php`.

## 6. Roadmap (belum tersedia di v1)

- Aksi tulis: register ONU, reboot, rename, hapus, set state.
- Webhook/push event alarm real-time.
- Filter rentang waktu & ekspor.

Bila butuh salah satu di atas, ajukan agar ditambahkan di `v2`.
