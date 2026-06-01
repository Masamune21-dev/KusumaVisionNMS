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
- `sendTo($chatId,$text)` / `dispatch()` — kirim ke Telegram Bot API; simpan `last_sent_at`/
  `last_error`.

### Inbound command — webhook
Aktif bila `commands_enabled` + token + `webhook_secret` (`commandsReady()`).

**Daftarkan webhook** (`telegram:webhook` atau tombol di Settings):
```bash
php artisan telegram:webhook set     # daftar webhook ke Telegram
php artisan telegram:webhook info    # lihat status webhook
php artisan telegram:webhook delete  # hapus webhook
```
`TelegramWebhookManager` (register/info/delete) memanggil Bot API `setWebhook` dengan URL
`route('telegram.webhook')` + header secret token.

**Terima update** — `TelegramWebhookController@handle` (route publik `POST /telegram/webhook`,
no-auth, CSRF-exempt):
1. Bandingkan header `X-Telegram-Bot-Api-Secret-Token` dengan `webhook_secret` (`hash_equals`) →
   403 bila salah.
2. Bila `commandsReady()` false → terima & abaikan (200).
3. Ambil `message.chat.id` + `message.text`; non-message diabaikan.
4. `TelegramCommandHandler::handle()` proses command → balas via `sendTo()`.
5. Selalu balas 200 (kecuali secret salah) agar Telegram tidak retry.

**Command yang didukung** (`TelegramCommandHandler`): `/help`, `/ping`, `/status`, `/olt [nama]`,
`/alarms`, `/onu <serial|nama>`, `/provisioning`, `/chatid`. Hanya chat di allow-list
(`isChatAuthorized`) boleh menjalankan command data; selain itu `accessDenied`. Handler memakai
`DashboardStatsService` untuk ringkasan.

### Catatan keamanan
- `bot_token` & `webhook_secret` terenkripsi + `$hidden`.
- Gerbang webhook adalah secret token header — jangan log token/secret.
- Bila handler error, dicatat ke log tapi tetap balas 200 (cegah retry loop Telegram).

## Selanjutnya

→ [11 — Keamanan, RBAC & Audit](11-keamanan-rbac-audit.md)
