# 10 — Alarm & Notifikasi Telegram

[← Indeks](README.md) · [← 09 CLI & Telnet](09-cli-telnet.md) · [11 Keamanan, RBAC & Audit →](11-keamanan-rbac-audit.md)

## A. Alarm — `AlarmEvaluator`

`app/Services/AlarmEvaluator.php`. Dipanggil di akhir `PollOltJob` dengan snapshot poll
sebelumnya dan sesudahnya. Prinsip inti:

> **Alarm hanya di-raise pada transisi sehat → fault.** Perangkat yang sudah fault saat pertama
> kali terlihat tidak dialarmkan. Snapshot poll sebelumnya menyediakan state lama untuk deteksi
> transisi.

### Jenis & severity yang dievaluasi
| Type | Scope | Severity | Kondisi raise | Kondisi clear |
|------|-------|----------|---------------|---------------|
| `olt_unreachable` | olt | critical | snapshot `ok=false` & sebelumnya `ok` | OLT `ok` lagi |
| `port_down` | port | critical (di `portAlarm`) | port up → down, **dan port punya ONU terdaftar** | port up lagi |
| `port_disabled` | port | major | **bukan dari poll** — dinaikkan langsung oleh aksi "Matikan Port" di NMS | port dinyalakan dari NMS, atau terbaca UP lewat tenggang 10 menit |
| `odp_down` | odp | major (di `odpAlarm`) | SEMUA ONU satu ODP (≥2 ONU) offline, sebelumnya masih ada yang online | ada ONU ODP itu online lagi |
| `los` / `dying_gasp` / `onu_offline` | onu | major / minor / minor (di `onuStateAlarms`) | online → fault (`phase_state`/`last_down_cause`) | online lagi |
| `high_rx_attenuation` | onu | warning | RX ≤ −28 dBm atau ≥ −8 dBm, dari bacaan sebelumnya yang sehat | kembali ke dalam −26..−10 dBm (histeresis) |

Daftar jenis kanonis + label: `AlarmEvent::TYPE_LABELS` (label frontend dwibahasa lewat
`resources/js/lib/alarm.js` → `alarms.type_*`; jenis baru wajib ditambahkan ke `KNOWN_TYPES` di sana).

**Port kosong bukan gangguan** (28 Sep 2026): port yang terbaca utuh (`port_onus.{slot}_{port}.ok ===
true`) tanpa satu pun ONU terdaftar dianggap tak dipakai — down-nya tidak dialarmkan, dan episode
`port_down` yang terlanjur terbuka ditutup **diam** (`meta.recovery.silent = true`, tanpa pesan "kembali
up" yang keliru). Port yang bacaannya gagal/tak lengkap tidak dianggap kosong.

### Korelasi root-cause (anti banjir notifikasi)
Hierarki induk→anak, saklarnya `alarm_settings.suppress_child_alarms` (Settings → Alarm, default ON):

1. **OLT unreachable** → port & ONU tak dievaluasi sama sekali.
2. **Port PON down** → alarm ONU di port itu tak dibuat baru; pesan port menyebut jumlah ONU
   terdampak (`meta.affected_onus`).
3. **ODP down** (`App\Services\Alarm\OdpAlarmGrouper::statuses()` menghitung per-ODP: total ONU
   yang muncul di snapshot vs yang offline) → satu alarm `odp_down`, alarm ONU anggotanya diam.
   ODP yang portnya sedang down dilewati (port = akar yang lebih dalam).
4. **Episode ONU yang SUDAH terbuka** saat induknya turun tetap direkonsiliasi (tak ter-clear
   palsu) tapi ditandai `meta.notified = false` → notifikasi raise **dan** clear-nya dilewati.
   Begitu induknya pulih sementara ONU-nya masih mati, tanda itu dilepas dan alarm ONU dikirim
   sebagai gangguan mandiri (`$parentRecovered` juga membuka ONU yang tak pernah punya transisi
   online→offline karena matinya tertutup gangguan induk).

ODP yang gangguannya terlanjur tercatat per-ONU (episode lama) diangkat sekali jadi satu alarm
`odp_down` (`$hasOpenChildren`), supaya pemulihannya punya induk yang melapor.

Sisanya (ODP baru **sebagian** ONU-nya down) dirangkum di layer notifikasi jadi satu pesan berisi
daftar pelanggan — `OdpAlarmGrouper::group()`, saklar `alarm_settings.group_odp_alarms`.

### Port yang dimatikan dari NMS (`port_disabled`)
Aksi "Matikan Port" di Detail Port ZTE ([07 §5a](07-modul-fitur.md#5a-matikan--nyalakan-port-pon-zte-c300c320-sep-2026))
memanggil langsung metode publik `AlarmEvaluator`, bukan menunggu poll:

- **`raisePortDisabled()`** — membuat alarm `port_disabled` (major, scope `port`, signature
  `port:{slot}/{port}:port_disabled` = `portDisabledSignature()`) langsung **ACTIVE** (tanpa debounce
  2 poll — aksinya disengaja) dan mengirim **satu** notifikasi lewat `dispatchNotifications()`.
  `meta` memuat `affected_onus`, `actor`, `disabled_at`. Mematikan port yang sudah bertanda tidak
  mengirim ulang.
- **Selama terbuka, alarm ini jadi penanda bagi poll** (`disabledPorts()`): `port_down` untuk port itu
  tak dievaluasi, port dihitung sebagai induk yang down sehingga **alarm ONU dan `odp_down` di port itu
  ditahan — apa pun saklar `suppress_child_alarms`**, dan `port_down` lama di port itu ditutup diam
  ("dimatikan admin — alarm ditutup"). Penanda tetap dipegang saat OLT tak terjangkau.
- **`clearPortDisabled()`** — dipanggil saat "Nyalakan Port" sukses: menutup penanda dan mengirim
  **satu** notifikasi pulih ("… dinyalakan lagi dari NMS oleh {user}").
  ONU yang tetap mati sesudahnya beralarm mandiri di poll berikutnya.
- **Tenggang 10 menit** (`PORT_DISABLED_GRACE_MINUTES`): port bertanda yang terbaca oper **UP** lewat
  10 menit sejak `disabled_at` dianggap dinyalakan di luar NMS (CLI langsung, atau OLT reboot karena
  shutdown tak di-`write`) → penanda dilepas poll dengan satu notifikasi pulih "… terbaca menyala lagi
  (dinyalakan di luar NMS)". Tenggang menutup jeda SNMP sesaat setelah `shutdown`.
- UI membaca penanda ini: prop `port_disabled` di `SmartOltController::portDetail` = ada alarm
  `port_disabled` ACTIVE/PENDING untuk port itu.
- Notifikasi raise/pulih tetap lewat filter kanal seperti alarm lain (lihat §B); migrasi data
  `2026_09_30_000001_add_port_disabled_to_alarm_notify_types` menambahkan `port_disabled` ke
  `alarm_settings.notify_types` yang berupa daftar eksplisit (null = semua jenis, tak disentuh).

Ambang RX (konstanta di kelas):
```
RX_LOW_DBM       = -28.0   RX_HIGH_DBM       = -8.0    (raise)
RX_CLEAR_LOW_DBM = -26.0   RX_CLEAR_HIGH_DBM = -10.0   (clear, histeresis cegah flapping)
```
ONU dengan `admin_state = disabled` dilewati (tidak dialarmkan).

### Reconcile (`reconcile()`)
Membandingkan alarm aktif di DB (`activeAlarms`) dengan yang terdeteksi sekarang (`$detected`):
- **baru** → buat `AlarmEvent` — status `pending` bila debounce 2 poll aktif
  (`confirm_before_notify`, bawaan), dipromosikan ke `active` + dikirim bila fault masih ada di poll
  berikutnya; `active` langsung bila mode realtime. Pending yang pulih sebelum konfirmasi dihapus diam.
- **masih ada** → update `last_seen_at`.
- **hilang** → tandai `cleared` (`cleared_at`) + `buildRecovery()` mengisi konteks pemulihan.
- Tiap alarm punya `signature` unik untuk dedup; lokasi (`slot/port/onu_id/serial_number`) dan
  `meta` (json) disimpan untuk konteks.
- **hilang** dengan `meta.recovery.silent = true` (port kosong, port yang kini dimatikan admin) →
  ditutup tanpa notifikasi pemulihan.
- Setelah reconcile, raise/clear diteruskan `dispatchNotifications()` ke `TelegramNotifier::notify()`
  dan — bila FCM aktif — job antre `SendFcmAlarmNotifications`. Jalur yang sama dipakai
  `raisePortDisabled()`/`clearPortDisabled()`.
- Pesan alarm (`message`, `meta.recovery.message`) **sengaja tetap bahasa Indonesia** — disimpan sebagai
  data oleh worker tanpa locale pengguna; UI menerjemahkan label jenis/status, bukan isi pesan.

### Penyajian
- Halaman **Alarms** (`AlarmController` → `SmartOlt/Alarms.vue`) baca `alarm_events`.
- **Nama pelanggan** di baris alarm diresolusi berlapis: registrasi (`smartolt_onu_registrations`)
  → snapshot `port_onus` live → `meta.customer_name` yang direkam saat alarm dinaikkan. Dua lapis
  pertama **ber-kunci serial**, jadi untuk ONU **tanpa serial** (C-Data EPON & HiOSO — identitasnya
  MAC) hanya lapis meta yang berlaku; jangan menambah gerbang `serial_number === null` di depan
  resolusi ini (pernah jadi bug: seluruh baris C-Data/HiOSO tampil tanpa nama). Fallback lewat
  **posisi** slot/port/onu_id sengaja tidak dipakai — untuk ONU tanpa serial, posisi yang sudah
  dihuni pelanggan lain akan menampilkan nama yang salah pada alarm lama.
- **Bel notifikasi** — `HandleInertiaRequests` → `App\Services\Alarm\AlarmNotificationService::payloadFor()`:
  - Maks 8 item (`BELL_LIMIT`). **Belum dibaca didahulukan**; sisa slot diisi alarm ACTIVE yang
    sudah dibaca tapi berjenis **`PERSISTENT_UNTIL_RECOVERY`** — `high_rx_attenuation`, `port_down`,
    `port_disabled`, `odp_down`, `los`, `olt_unreachable`. Jenis ini tetap tampil sampai
    `AlarmEvaluator` menutupnya; `onu_offline` dan `dying_gasp` hilang dari bel begitu dibaca
    (`dismiss_on_read`). Aturan visibilitas yang sama dipakai filter **Aktif** halaman Alarms
    (`applyActiveVisibility`).
  - Status baca per pengguna per alarm di tabel `alarm_notification_reads`, ditambah penanda global
    `users.last_notifications_read_at`. `markAllRead` menulis satu baris per alarm aktif (upsert) —
    timestamp saja tak cukup karena poll menyegarkan `last_seen_at` tiap siklus, sehingga badge dulu
    muncul lagi beberapa menit kemudian. `unread_count` = semua alarm ACTIVE belum dibaca (bukan
    hanya 8 yang tampil).
  - Payload membawa ID terstruktur (`resource_type` = scope, `smartolt_id`, `board_id`, `port_id`,
    `resource_id`, `serial_number`) tapi **sengaja tanpa URL tujuan** (menghitungnya berarti
    men-decode snapshot OLT di setiap request).
  - **Deep-link**: klik → `POST notifications.alarms.open` → tandai dibaca →
    `AlarmNotificationTargetResolver::resolve()` (hanya membaca cache `last_test_result`, tanpa
    SNMP/Telnet). ONU dicari lewat serial dulu: pindah port → buka posisi sekarang (`onu_moved`);
    posisi lama kini serial lain → ditolak (`position_reused`); hilang → `onu_not_found`. ONU → detail
    ONU bila `supports_cli_onu_detail`, selain itu `{prefix}.port-onus?focus={onuId}`; port & ODP
    ber-slot/port → halaman ONU port itu; OLT (dan ODP tanpa port) → Detail OLT. Gagal → bel
    menampilkan alasan (`flash.notif_*`) + tautan cadangan ke `alarms.index` terfilter. Lokasi
    **tidak pernah** di-parse dari `message`.
  - API/mobile memakai resolver yang sama lewat `resolveLocation()` (blok `target` di
    `GET /api/v1/alarms`); field `slot/port/onu_id` tingkat-atas di API/FCM adalah posisi historis,
    jangan dipakai untuk navigasi.

## B. Notifikasi Telegram

Dua arah: **push** (alarm ke chat) dan **inbound command** (query dari chat).

### Kebijakan alarm TERPUSAT — `alarm_settings` (singleton)
Semua aturan alarm ada di **Pengaturan → tab Alarm** (`SettingsController::updateAlarm`, admin) dan
berlaku untuk **semua kanal**: `confirm_before_notify` (debounce 2 poll vs realtime), `min_severity`,
`notify_on_raise`, `notify_on_clear`, `notify_types` (json, null = semua jenis), `suppress_child_alarms`,
`group_odp_alarms`. Daftar jenis kanonis + labelnya di `AlarmEvent::TYPE_LABELS` (`AlarmEvent::types()`
= `olt_unreachable`, `port_down`, `port_disabled`, `odp_down`, `los`, `dying_gasp`, `onu_offline`,
`high_rx_attenuation`). `notify_types` null = semua jenis; daftar kosong `[]` = **tak ada** jenis yang
dikirim (UI: "Tidak ada yang dicentang — semua notifikasi alarm dimatikan"). Form hanya menimpa
`notify_types` bila field-nya dikirim. Bawaan instance baru: debounce ON, `min_severity` warning, raise
ON, **clear OFF**, korelasi ON, grup ODP ON.

`TelegramSetting` (bot global) & `FcmSetting` **mendelegasikan** `minSeverityRank()`/`notifyTypes()`/
`shouldNotifyType()`/`notifyOnRaise()`/`notifyOnClear()` ke `AlarmSetting` — kolom senama di kedua
tabel kanal masih ada tapi tak dipakai lagi (dipertahankan demi rollback). **Bot partner**
(`PartnerTelegramBot`) tetap memakai filter per-bot miliknya sendiri (diatur partner di halamannya) —
karena itu jenis baru perlu migrasi **kedua** untuk `partner_telegram_bots.notify_types` eksplisit
(`2026_10_01_000001_add_port_disabled_to_partner_bot_notify_types`; dulu hanya `alarm_settings` yang
diperbarui, sehingga partner yang mematikan port di OLT-nya sendiri tidak menerima notifikasinya). Jenis
`odp_down` (Agu 2026) tidak pernah dimigrasikan ke bot partner — bot partner dengan daftar lama bisa tidak
menerima gangguan ODP total sama sekali (alarm ONU anaknya ditahan).

Menambah **jenis alarm baru**: konstanta + label di `AlarmEvent`, `KNOWN_TYPES` di `lib/alarm.js` +
kunci `alarms.type_*` (id/en), putuskan apakah masuk `PERSISTENT_UNTIL_RECOVERY`, dan migrasi data yang
menambahkannya ke `alarm_settings.notify_types` **dan** `partner_telegram_bots.notify_types` yang eksplisit
(pola `2026_09_30_000001` + `2026_10_01_000001`).

**Saklar alarm per-OLT = per-penerima, bukan mute evaluasi.** `AlarmEvaluator::evaluate()` selalu
jalan (event tetap tercatat); tombol On/Off per OLT (`smartolt.alarms.toggle`) hanya menentukan siapa
yang dikirimi: `snmp_olts.alarms_enabled` untuk admin/operator (bot global + FCM staf),
`olt_user.alarms_enabled` per partner per OLT (bot & FCM partner).

### Konfigurasi koneksi — `telegram_settings` (singleton)
Diatur di **Pengaturan → Bot Telegram** (admin), kini murni koneksi: `enabled`, `bot_token` (enc),
`chat_id` (boleh banyak, pisah spasi/koma), `commands_enabled`, `webhook_secret` (enc). Helper model:
`isReady()`, `commandsReady()`, `isChatAuthorized()`, `chatIds()`.

### Multi-bot: bot global (admin) + bot partner (self-service)
`telegram_settings` = bot **global** (alarm SEMUA OLT, command lintas-OLT). Selain itu tiap user role
`partner` bisa punya **bot sendiri** (`partner_telegram_bots`, 1 baris/partner) yang hanya menerima alarm
& melayani command untuk OLT yang di-assign ke partner ([11 — RBAC](11-keamanan-rbac-audit.md)). Keduanya
mengimplementasikan kontrak `App\Contracts\Telegram\TelegramBotConfig` (logika bersama di trait
`App\Models\Concerns\TelegramBotConfigTrait`) sehingga notifier/manager/handler memperlakukannya seragam:
- **Push** — `TelegramNotifier::notify($olt,…)` mengumpulkan bot global (bila ready) + tiap bot partner
  yang partner-nya assigned ke `$olt`, lalu kirim per-bot dengan filter severity/jenis milik bot itu.
- **Webhook** — rute `POST /telegram/webhook/{bot?}`. `{bot}` kosong = bot global; `{bot}`=id =
  `PartnerTelegramBot`. Untuk bot partner, controller memanggil `Auth::setUser($partner)` sehingga
  `SnmpOlt::query()` di `TelegramCommandHandler`/`TelegramOnuQueryService` **otomatis ter-scope**
  `PartnerOltScope` — command handler tak perlu tahu soal partner. Secret diverifikasi per-bot.
- **Setup partner** — halaman **Bot Telegram Saya** (`Pages/Partner/TelegramBot.vue`, rute
  `partner.telegram.*`): isi token + allow-list chat + register webhook sendiri. `TelegramWebhookManager`
  mendaftarkan webhook per-bot ke URL yang menyisipkan id-nya. Setelah upgrade: **daftar-ulang webhook**
  tiap bot (allowed_updates butuh `callback_query`).

### Push — `TelegramNotifier`
`app/Services/Telegram/TelegramNotifier.php`.
- `notify($olt, $raised, $cleared)` — kirim alarm baru/clear bila `isReady()`; filter berdasar
  `min_severity` (`filterBySeverity`) **dan** jenis alarm (`shouldNotifyType()`, berlaku untuk
  raise & clear), hormati `notify_on_raise`/`notify_on_clear`. Format pesan `formatAlarm()`
  (escape MarkdownV2 via `escape()`).
- `sendTest()` — tombol "Test" di Settings.
- `sendTo($chatId,$text,$keyboard=null)` / `dispatch()` — kirim ke Bot API (`reply_markup` inline
  bila ada keyboard). `dispatch()` simpan `last_sent_at`/`last_error`; `sendTo()` tidak (itu khusus
  balasan command).
- `editMessage($chatId,$messageId,$text,$keyboard)` — `editMessageText` untuk navigasi tombol
  in-place; "not modified" dianggap sukses, error lain → fallback `sendTo`. `answerCallback($id)`
  — `answerCallbackQuery` (matikan spinner, best-effort).

### Inbound command + menu interaktif — webhook
Aktif bila `commands_enabled` + token + `webhook_secret` (`commandsReady()`). Bot punya dua
jenis interaksi: **slash command** (teks) dan **tombol inline** (`callback_query`) untuk
navigasi tekan-tekan.

**Daftarkan webhook** (`telegram:webhook` atau tombol di Settings):
```bash
php artisan telegram:webhook set     # daftar webhook ke Telegram
php artisan telegram:webhook info    # lihat status webhook
php artisan telegram:webhook delete  # hapus webhook
```
`TelegramWebhookManager` (register/info/delete) memanggil Bot API `setWebhook` dengan URL
`route('telegram.webhook')` + header secret token + `allowed_updates = ['message','callback_query']`
(tombol tidak akan terkirim Telegram tanpa ini — **daftar-ulang webhook setelah upgrade**).

**Terima update** — `TelegramWebhookController@handle` (route publik `POST /telegram/webhook`,
no-auth, CSRF-exempt):
1. Bandingkan header `X-Telegram-Bot-Api-Secret-Token` dengan `webhook_secret` (`hash_equals`) →
   403 bila salah.
2. Bila `commandsReady()` false → terima & abaikan (200).
3. Update teks → `handleMessage()`: ambil `message.chat.id` + `message.text` →
   `TelegramCommandHandler::handle()` → kirim via `sendTo()` (dgn inline keyboard).
4. Update tombol → `handleCallback()`: ambil `callback_query.{id,data,message.*}` →
   selalu `answerCallback()` (matikan spinner) → `handleCallback()` →
   `editMessage()` (edit pesan yang sama; fallback `sendTo` bila pesan >48 jam) agar chat bersih.
5. Selalu balas 200 (kecuali secret salah) agar Telegram tidak retry.

**Arsitektur handler** (`app/Services/Telegram/`):
- `TelegramCommandHandler` — parse command/callback → panggil "screen renderer"; tiap layar
  balikkan `TelegramReply` (text + keyboard) jadi command & tombol pakai render yang sama.
- `TelegramReply` — DTO `{text, keyboard}`.
- `TelegramKeyboard` — encode/parse `callback_data` (skema ringkas <64 byte, mis. `on:5:1:2:1:3`
  = OLT5 slot1 PON2 filter LOS page3), builder tombol/pager/back. Konstanta filter (`FILTER_ALL/
  LOS/RX`), sumber-balik (`SRC_*`), `PAGE_SIZE`.
- `TelegramOnuQueryService` — query read-only atas cache `port_onus`: daftar OLT + ringkasan
  (online/offline/los/rx_alert), port per-OLT, ONU per-port, daftar LOS & redaman tinggi
  (global/per-OLT, urut terparah), detail ONU. **Sumber tunggal klasifikasi RX & LOS bot**:
  RX bertingkat `RX_WARN_DBM=-25`, `RX_CRIT_DBM=-28`, `RX_HIGH_DBM=-8` (`rxSeverity/rxIsAlert/
  rxBars/statusIcon`); LOS = `online=false` (ditandai 🔴 bila `last_down_cause`/`phase_state` ∈
  {LOS,LOSi,DyingGasp}, selain itu ⚫ nonaktif/lain). Ambang ini khusus bot — `AlarmEvaluator`
  & `DashboardStatsService` punya ambang sendiri (tak diubah).

**Alur menu** (`/menu` atau `/start`): Menu → Status / Daftar OLT / ONU LOS / Redaman Tinggi /
Cari ONU / Alarm. Daftar OLT → detail OLT → pilih Port PON (grid, paginasi) → daftar ONU per-port
(paginasi ⬅️➡️ + filter Semua/🔴 LOS/📉 Redaman) → detail ONU. LOS & Redaman bisa global
(semua OLT) atau per-OLT, paginasi, tiap baris bisa ditekan ke detail ONU.

**Reboot ONU dari bot**: layar detail ONU (semua jalur: menu, /search, /los, /redaman) menampilkan
tombol "🔄 Reboot ONU" bila driver OLT `supports_reboot` (`SmartOltSupport::capabilities`). Dua
langkah: `rb:` membuka layar konfirmasi (✅ Ya / ❌ Batal, argumen back-context sama dengan `u:`),
`rbx:` mengeksekusi — cermin `OnuMapController::rebootPin`: ZTE via `ZteRemoteOnuService`, C-Data
via `CDataCliWriteService` (iface epon/gpon dari driver), HiOSO via `HiosoCliWriteService`. Sinkron
di request webhook (telnet beberapa detik, seperti /refresh). Dari detail hasil pencarian, konteks
token tidak terbawa ke callback numerik → back setelah reboot jatuh ke Menu (`SRC_MENU`).

**Pencarian global** (`/search`/`/cari`, juga `/onu`/`/cek`): substring match lintas-OLT atas
serial/nama/customer/interface (`runSearch`, cap `SEARCH_LIMIT=60`). 0 hasil → "tidak ditemukan",
1 hasil → langsung detail, >1 → daftar tombol **berpaginasi**. Karena `callback_data` tak muat
teks query, query disimpan di `Cache` (`tg:search:{token}`, TTL 1 jam) di balik token acak; tombol
halaman = `sr:{token}:{page}`, tombol ONU = `su:{token}:{page}:olt:slot:port:onu` (back ke halaman
hasil). Token kedaluwarsa → minta kirim ulang. Tombol "🔎 Cari ONU" di menu membuka instruksi
(`srh`) karena pencarian butuh argumen teks yang tak bisa lewat tombol.

**Command yang didukung** (`TelegramCommandHandler`): `/menu` (`/start`), `/help`, `/ping`,
`/id` (`/chatid`), `/status`, `/olt` (`/olts`) `[nama|id]`, `/los [olt]`, `/redaman` (`/rx`) `[olt]`,
`/search` (`/cari`) `<nama|serial>`, `/alarm` (`/alarms`), `/onu` (`/cek`) `<serial|nama>`, `/prov`
(`/provisioning`), `/uncfg` (`/unconfigured`) `[nama|id]`, `/refresh` (`/segarkan`) `[nama|id]`.
`/menu`, `/help`, `/ping`, dan `/id` terbuka untuk chat mana pun (`/id` menampilkan chat-id untuk
diisi ke allow-list); selebihnya hanya chat di allow-list (`isChatAuthorized`) yang boleh menjalankan
command/tombol data — termasuk `callback_query` (dicek ulang di `handleCallback`); selain itu
`accessDenied`. **Aksi di luar cache**: `/refresh` men-scan ulang
OLT C-Data via `CDataOltScanner` (sinkron — EPON SNMP cepat, GPON V3 CLI ~10 dtk/OLT) lalu menulis cache
`port_onus`, supaya menu/port tampil terbaru (OLT ZTE diabaikan — sudah dipoll background); `/uncfg
[nama|id]` menampilkan ONU ZTE yang belum dikonfigurasi **live dari CLI** (`show gpon onu uncfg` via
`ZteUncfgOnuService`, read-only, sengaja bukan cache agar ONU baru dicolok langsung terlihat; callback
`uc:{scope}` = tombol "Cek Ulang", scope 0 = semua OLT ZTE); dan tombol "🔄 Reboot ONU" di detail ONU
(lihat blok di atas — konfirmasi dua langkah, gated `supports_reboot`).

### Jaringan — IPv4 dipaksa, dan ini bukan opsional

`TelegramNotifier::http()` dan `TelegramWebhookManager::http()` memasang
`CURLOPT_IPRESOLVE = CURL_IPRESOLVE_V4`, `connectTimeout(5)`, dan `retry(3, 500, throw: false)`.

Alasannya kejadian nyata: di host yang **IPv6-nya tidak tersambung ke internet** (umum di
container/VPS), `api.telegram.org` tetap punya record AAAA. glibc mengembalikan alamat IPv6 lebih dulu, cURL
mencobanya, dan baru menyerah setelah 10 detik dengan `cURL error 28`. Akibatnya alarm OLT
gagal terkirim berulang-ulang — dan **alarm tidak punya kesempatan kedua**: kalau
pengirimannya gagal, kabar itu hilang, bukan tertunda.

Opsional di sisi server: `precedence ::ffff:0:0/96 100` di `/etc/gai.conf` membuat IPv4
didahulukan untuk seluruh proses. Di container Proxmox, `/etc/resolv.conf` ditulis ulang setiap
container start — atur nameserver dari host (`pct set <ctid> --nameserver …`), bukan dari dalam.

### Catatan keamanan
- `bot_token` & `webhook_secret` terenkripsi + `$hidden`.
- Gerbang webhook adalah secret token header — jangan log token/secret.
- Bila handler error, dicatat ke log tapi tetap balas 200 (cegah retry loop Telegram).
- **Setiap pesan galat yang memuat URL Telegram wajib lewat `redactToken()`.** URL API
  Telegram memuat bot token di dalam path-nya (`/bot<id>:<secret>/…`), dan pesan galat cURL
  menyertakan URL lengkap — sehingga token pernah tertulis polos di `laravel.log`.
  Penyaringnya dipakai di `notify()`, `apiCall()`, `TelegramWebhookManager::call()`, dan
  `TelegramWebhookController`.
- Berkas log ber-mode **0640** (`config/logging.php` channel `single` & `daily`; samakan
  `create` di konfigurasi logrotate bila ada). Bawaan Laravel 0664 berarti terbaca setiap
  user lokal di server.

## Selanjutnya

→ [11 — Keamanan, RBAC & Audit](11-keamanan-rbac-audit.md)
