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
| port down | port | (di `portAlarm`) | port up → down | port up lagi |
| ONU state (LOS / dying-gasp / offline) | onu | (di `onuStateAlarms`) | online → fault | online lagi |
| ONU RX out-of-range | onu | warning/major | RX < −28 dBm atau > −8 dBm | kembali ke dalam −26..−10 dBm (histeresis) |

Ambang RX (konstanta di kelas):
```
RX_LOW_DBM       = -28.0   RX_HIGH_DBM       = -8.0    (raise)
RX_CLEAR_LOW_DBM = -26.0   RX_CLEAR_HIGH_DBM = -10.0   (clear, histeresis cegah flapping)
```
ONU dengan `admin_state = disabled` dilewati (tidak dialarmkan).

### Reconcile (`reconcile()`)
Membandingkan alarm aktif di DB (`activeAlarms`) dengan yang terdeteksi sekarang (`$detected`):
- **baru** → buat `AlarmEvent` (status `active`, `first_seen_at`/`last_seen_at`).
- **masih ada** → update `last_seen_at`.
- **hilang** → tandai `cleared` (`cleared_at`) + `buildRecovery()` mengisi konteks pemulihan.
- Tiap alarm punya `signature` unik untuk dedup; lokasi (`slot/port/onu_id/serial_number`) dan
  `meta` (json) disimpan untuk konteks.
- Setelah reconcile, raise/clear diteruskan ke `TelegramNotifier::notify()` (bila ada).

### Penyajian
- Halaman **Alarms** (`AlarmController` → `SmartOlt/Alarms.vue`) baca `alarm_events`.
- Bell notifikasi: `HandleInertiaRequests::notificationsPayload()` ambil 8 alarm aktif terbaru
  → dishare ke semua page. `NotificationsController@markAllRead` set
  `users.last_notifications_read_at` (penanda sudah dibaca).

## B. Notifikasi Telegram

Dua arah: **push** (alarm ke chat) dan **inbound command** (query dari chat).

### Konfigurasi — `telegram_settings` (singleton)
Diatur di **Pengaturan → Telegram** (`SettingsController`, admin). Field: `enabled`, `bot_token`
(enc), `chat_id` (boleh banyak, pisah spasi/koma), `min_severity`, `notify_on_raise`,
`notify_on_clear`, `notify_types` (json), `commands_enabled`, `webhook_secret` (enc). Helper
model: `isReady()`, `commandsReady()`, `isChatAuthorized()`, `minSeverityRank()`, `chatIds()`,
`notifyTypes()`, `shouldNotifyType()`.

**Filter per-jenis alarm** (`notify_types`): admin memilih jenis alarm mana yang dikirim ke
Telegram (mis. hanya LOS, dying gasp, redaman RX tinggi, port down). Daftar jenis kanonis +
labelnya ada di `AlarmEvent::TYPE_LABELS` (sumber tunggal; `AlarmEvent::types()` =
`olt_unreachable`, `port_down`, `los`, `dying_gasp`, `onu_offline`, `high_rx_attenuation`).
`notify_types = null` berarti **semua jenis** (default/kompat lama); array eksplisit (termasuk
kosong = semua dibisukan) dihormati apa adanya.

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

**Pencarian global** (`/search`/`/cari`, juga `/onu`/`/cek`): substring match lintas-OLT atas
serial/nama/customer/interface (`runSearch`, cap `SEARCH_LIMIT=60`). 0 hasil → "tidak ditemukan",
1 hasil → langsung detail, >1 → daftar tombol **berpaginasi**. Karena `callback_data` tak muat
teks query, query disimpan di `Cache` (`tg:search:{token}`, TTL 1 jam) di balik token acak; tombol
halaman = `sr:{token}:{page}`, tombol ONU = `su:{token}:{page}:olt:slot:port:onu` (back ke halaman
hasil). Token kedaluwarsa → minta kirim ulang. Tombol "🔎 Cari ONU" di menu membuka instruksi
(`srh`) karena pencarian butuh argumen teks yang tak bisa lewat tombol.

**Command yang didukung** (`TelegramCommandHandler`): `/menu` (`/start`), `/help`, `/ping`,
`/status`, `/olt [nama|id]`, `/los [olt]`, `/redaman` (`/rx`) `[olt]`, `/search` (`/cari`)
`<nama|serial>`, `/alarm`, `/onu` (`/cek`) `<serial|nama>`, `/prov`, `/refresh` (`/segarkan`)
`[nama|id]`, `/id`. Hanya chat di allow-list
(`isChatAuthorized`) boleh menjalankan command/tombol data — termasuk `callback_query` (dicek ulang
di `handleCallback`); selain itu `accessDenied`. **`/refresh` adalah satu-satunya command non-read-only**:
men-scan ulang OLT C-Data via `CDataOltScanner` (sinkron — EPON SNMP cepat, GPON V3 CLI ~10 dtk/OLT) lalu
menulis cache `port_onus`, supaya menu/port tampil terbaru. OLT ZTE diabaikan (sudah dipoll background).

### Catatan keamanan
- `bot_token` & `webhook_secret` terenkripsi + `$hidden`.
- Gerbang webhook adalah secret token header — jangan log token/secret.
- Bila handler error, dicatat ke log tapi tetap balas 200 (cegah retry loop Telegram).

## Selanjutnya

→ [11 — Keamanan, RBAC & Audit](11-keamanan-rbac-audit.md)
