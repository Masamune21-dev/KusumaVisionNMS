# Rencana Rombak Total UI/UX — Aplikasi Mobile KusumaVision NMS

> Status: **rencana** (belum dieksekusi). Disusun dengan skill `ui-ux-pro-max` + riset library/font Flutter.
> Tema produk: monitoring & provisioning **OLT/ONU FTTH GPON** untuk ISP.

## 0. Arah desain (terkunci)

| Dimensi | Keputusan |
|---|---|
| **Font** | Heading **Sora** · Body **Inter** · Data teknis **JetBrains Mono** |
| **Latar** | **Aurora mesh gradient** (cyan/sky bergerak pelan) + **node-fiber halus** (partikel node+garis tipis) sebagai metafora jaringan GPON |
| **Mode** | **Dark-only** (OLED, sesuai brand web) — dipoles, bukan ditambah light mode |
| **Ilustrasi/animasi** | **Rive** (aset custom on-brand) + **flutter_animate** (micro-interaction) |
| **Gaya** | Glassmorphism v2: translucent tipis + gradient border + noise + soft shadow di atas gelap |

Prinsip yang dipertahankan dari kode sekarang: depth lewat **beda warna surface vs bg + shadow** (bukan border tebal), **tanpa `BackdropFilter` per-item** di daftar panjang (ribuan ONU) demi performa.

---

## 1. Dependencies baru (`pubspec.yaml`)

```yaml
dependencies:
  google_fonts: ^6.2.1          # inject Sora/Inter/JetBrains Mono (lihat catatan bundling)
  flutter_animate: ^4.5.0       # animasi deklaratif .animate() — fade/slide/scale/shimmer
  flutter_staggered_animations: ^1.1.1  # stagger daftar OLT/ONU/alarm
  mesh_gradient: ^1.3.8         # aurora mesh gradient bergerak
  particles_network: ^1.0.x     # node+garis fiber (dipakai TIPIS di latar)
  rive: ^0.13.x                 # animasi custom (splash, empty-state, status ONU)
  shimmer: ^3.0.0               # skeleton bercahaya (ganti skeleton solid sekarang)
```

**Catatan penting:**
- **Bundling font, bukan runtime fetch.** App dipakai teknisi di lapangan (koneksi buruk). Unduh TTF Sora/Inter/JetBrains Mono, taruh di `assets/fonts/`, deklarasikan di `pubspec.yaml` `fonts:` — jangan andalkan `google_fonts` menarik dari jaringan saat runtime. (`google_fonts` boleh dipakai hanya di dev untuk cepat coba.)
- **APK size** akan naik (~1–2 MB font + aset Rive). Masih wajar. Build tetap via `bin/build-apk.sh` (server 8GB, gradle terkonstrain — jangan build sebagai www-data).
- `particles_network`/mesh dijaga **jumlah partikel rendah** + `RepaintBoundary` agar 60fps di HP low-end.

---

## 2. Fondasi baru (design system + shared widgets)

### 2.1 `theme/app_theme.dart` — perluas token yang sudah ada
- **Typography**: buat `TextTheme` 3-keluarga.
  - `display/headline/title` → **Sora** (w700/800, tracking rapat -0.5).
  - `body/label` → **Inter** (w400/500/600, `FontFeature.tabularFigures`).
  - angka data (serial ONU, RX dBm, IP, uptime) → helper `AppText.mono` = **JetBrains Mono**.
- **Motion tokens** (baru): `AppMotion.fast=180ms`, `.base=240ms`, `.slow=360ms`; easing `easeOutCubic` masuk, `easeInCubic` keluar; stagger 40ms.
- **Gradient tokens** (baru): `AppGradient.aurora`, `.accent` (cyan→sky), `.success`, `.danger`.
- Pertahankan palet `AppColors` sekarang (cyan `#22D3EE`, navy OLED) — cukup ditambah gradient + noise.

### 2.2 `AuroraBackground` (widget baru) — pembungkus Scaffold global
Layer dari belakang ke depan:
1. `AppColors.bg` solid.
2. `mesh_gradient` (cyan/sky/indigo, animasi ~8–12 dtk, opacity ~0.35).
3. `particles_network` node-fiber **tipis** (opacity ~0.12, ~30–40 node) — hanya di layar utama (dashboard/login), bukan di list panjang.
4. Konten (`SafeArea`).
- Hormati `MediaQuery.disableAnimations` (reduced-motion) → matikan mesh/particle, pakai gradient statis.

### 2.3 `GlassCard` v2 (upgrade widget yang ada)
- Tambah opsi **noise texture** (PNG 1×) di overlay ~4% + **gradient border** (cyan→transparan) untuk kartu ter-highlight.
- Opsi `blur` (BackdropFilter) HANYA untuk kartu hero/tunggal (login, dashboard hero), tidak di list.
- Press: `scale 0.98` via flutter_animate (bukan layout shift).

### 2.4 Komponen aksen tema FTTH (baru)
- `SignalRing` — gauge melingkar animatif (% ONU online, health OLT) dengan count-up.
- `RxPowerBar` — upgrade `rx_power_badge` jadi bar gradient (hijau→kuning→merah) + nilai mono.
- `FiberDivider` — pemisah bergaya "serat" tipis antar seksi.
- `StatusDot` — titik status dengan **pulse** (online berdenyut, offline diam).

---

## 3. Rombak per layar

| Layar | Rombakan utama |
|---|---|
| **Splash** (`splash_screen.dart`) | Rive: **radio-tower memancar sinyal** + reveal wordmark "KusumaVision NMS" (Sora), progress halus. |
| **Login** (`login_screen.dart`) | `AuroraBackground`; kartu glass v2 dengan blur; logo tower Rive animatif; field floating-label + ikon; error inline animatif; tombol dengan state loading + haptic. |
| **Dashboard** (`dashboard_screen.dart`) | Hero **`SignalRing`** besar (% ONU online, count-up) di atas aurora; 4 stat card dengan **count-up + stagger masuk**; donut/segmented alarm animatif; salam + waktu. Fokus data-viz sebagai hero. |
| **OLT list** (`olt_list_screen.dart`) | Kartu OLT **staggered**; `StatusDot` pulse; mini-sparkline/health; badge vendor (ZTE/C-Data/HiOSO) berwarna; tap → shared-element ke detail. |
| **OLT detail** (`olt_detail_screen.dart`) | Faceplate/panel depan sebagai hero (kalau ada `panel`); grid port dengan indikator; `RxPowerBar` per metrik; seksi ber-`FiberDivider`. |
| **Port ONUs** (`port_onus_screen.dart`) | List ONU stagger; `RxPowerBar` animatif; status pulse; tombol refresh per-port dengan spinner + hasil. |
| **ONU detail** (`onu_detail_screen.dart`) | Kartu hero ONU (serial mono, status besar); aksi (reboot/rename) tombol jelas + konfirmasi + haptic + success feedback. |
| **Alarms** (`alarm_list_screen.dart`) | Timeline severity berwarna; header ringkas count per severity; **empty-state Rive** ("semua aman"); pull-to-refresh. |
| **Search** (`search_screen.dart`) | Field animatif fokus (glow); hasil stagger; kategori (OLT/ONU); empty & recent state. |
| **Unconfigured** (`unconfigured_screen.dart`) | Kartu ONU belum-terdaftar; **empty-state Rive**; CTA registrasi. |
| **Register** (`register_screen.dart`) | Stepper/progress multi-langkah; field bergrup; ringkasan sebelum submit; success animatif. |
| **Account** (`account_screen.dart`) | Header profil (avatar inisial + role chip); tile glass (info app, tes push, logout dipisah warna danger). |
| **Bottom nav** (`home_shell.dart`) | Nav bar **floating glass** dengan indikator pill animatif geser; ikon aktif filled + glow; hormati safe-area. |
| **Skeleton/loading** | Ganti `Skeleton` solid → **shimmer** bercahaya di semua async view. |

---

## 4. Aset yang perlu dibuat

- **Rive** (`assets/rive/`): `tower_signal.riv` (splash/login), `empty_safe.riv` (alarm kosong), `empty_box.riv` (unconfigured/search kosong), `onu_status.riv` (online/offline state-machine — opsional). *Bisa mulai dari community Rive lalu re-brand ke cyan.*
- **Noise texture** `assets/textures/noise.png` (tileable, ~4% overlay glass).
- **Font TTF** `assets/fonts/` — Sora, Inter, JetBrains Mono (weight yang dipakai saja).
- (Opsional) refresh **adaptive launcher icon** agar selaras tema baru.

---

## 5. Guardrail performa & aksesibilitas

- Reduced-motion: matikan mesh/particle/rive-loop, animasi jadi fade cepat.
- `RepaintBoundary` pada latar animatif; cap jumlah partikel; jangan animasi width/height (pakai transform/opacity).
- Kontras teks ≥4.5:1 di atas aurora (uji — aurora bisa menurunkan kontras; beri scrim di belakang teks).
- Touch target ≥48dp; press feedback <100ms.
- Tidak ada emoji sebagai ikon (tetap Lucide-mapped).

---

## 6. Rencana rilis bertahap (fase)

1. **Fondasi**: tambah deps + bundle font + `AppMotion`/gradient token + `AuroraBackground` + `GlassCard` v2 + shimmer. → rombak **Splash + Login** (bukti konsep).
2. **Dashboard**: `SignalRing` hero + stat count-up + stagger + nav floating glass.
3. **Daftar**: OLT list, Port ONUs, Alarms, Search (stagger + status pulse + shimmer).
4. **Detail & form**: OLT detail (faceplate), ONU detail, Register (stepper), Account.
5. **Ilustrasi & polish**: aset Rive empty-state, reduced-motion, uji kontras, QA di HP low-end + landscape.
6. **Build & verifikasi**: `bash bin/build-apk.sh` → uji APK di perangkat → update `WORKLOG.md` → `/done`.

Setiap fase = commit terpisah + entri WORKLOG (verifikasi di perangkat nyata).

---

## 7. Risiko & catatan

- **Migrasi font menyentuh semua layar** (Roboto→Sora/Inter). Aman karena lewat `TextTheme` global, tapi cek layar padat-angka.
- **Bundle font vs `google_fonts`**: pilih bundle demi offline-first lapangan.
- **Build server 8GB**: build APK hanya via `bin/build-apk.sh`, jangan naikkan heap gradle, jangan build sebagai www-data (bisa swap-thrash/reboot).
- **`versionName+versionCode`** di `pubspec.yaml` di-bump saat rilis.
