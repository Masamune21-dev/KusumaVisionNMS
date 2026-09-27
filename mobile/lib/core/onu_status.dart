import 'package:flutter/widgets.dart';

import '../models/onu.dart';
import '../theme/app_theme.dart';
import 'icons.dart';

/// Status ONU yang sudah diterjemahkan dari `phase_state` / `last_down_cause`
/// OLT — bukan sekadar online/offline.
///
/// Taksonomi dan teksnya sengaja dicerminkan dari web (`resources/js/lib/onu.js`
/// + `resources/js/lang/id.json` namespace `onu.phase_*` / `onu.ldc_*`) supaya
/// teknisi membaca istilah yang sama di dashboard maupun di HP. Yang beda hanya
/// panjangnya: [label] untuk chip sempit di daftar, [detail] untuk halaman detail.
///
/// Nilai mentahnya beda ejaan per family OLT — ZTE C300/C320 mengirim
/// `DyingGasp`/`LOSi`, C600 `OffLine`, C-Data GPON `dying-gasp` — jadi semua kode
/// dinormalkan dulu lewat [_key] (huruf kecil, tanpa pemisah) sebelum dipetakan.
/// Nada warna status — disimpan sebagai nada (bukan [Color]) supaya status bisa
/// tetap `const` dan warnanya mengikuti tema aktif saat dibaca.
enum StatusTone {
  success,
  danger,
  warning,
  info,
  muted;

  Color get color => switch (this) {
        success => AppColors.success,
        danger => AppColors.danger,
        warning => AppColors.warning,
        info => AppColors.info,
        muted => AppColors.muted,
      };
}

@immutable
class OnuStatus {
  const OnuStatus({
    required this.label,
    required this.detail,
    required this.tone,
    required this.icon,
    this.online = false,
  });

  /// Teks pendek untuk chip (mis. "Dying Gasp", "LOS").
  final String label;

  /// Satu kalimat penjelas untuk halaman detail — apa artinya buat teknisi.
  final String detail;

  final StatusTone tone;
  final IconData icon;

  /// Warna status di tema aktif.
  Color get color => tone.color;

  /// True hanya untuk ONU yang benar-benar melayani trafik.
  final bool online;

  /// Status ini menandakan gangguan yang perlu ditindak (bukan online, bukan
  /// dinonaktifkan sengaja). Dipakai untuk ringkasan jumlah per port.
  bool get isProblem => !online && tone != StatusTone.muted;

  static const _online = OnuStatus(
    label: 'Online',
    detail: 'ONU aktif dan melayani trafik.',
    tone: StatusTone.success,
    icon: LucideIcons.checkCircle,
    online: true,
  );

  static const _offline = OnuStatus(
    label: 'Offline',
    detail: 'ONU tidak terhubung dan OLT tidak menyebutkan sebabnya.',
    tone: StatusTone.danger,
    icon: LucideIcons.wifiOff,
  );

  static const _disabled = OnuStatus(
    label: 'Nonaktif',
    detail: 'ONU dinonaktifkan dari OLT (admin state disable) — bukan gangguan jaringan.',
    tone: StatusTone.muted,
    icon: LucideIcons.ban,
  );

  /// Peta kode → status. Kunci sudah dinormalkan: `phase_state` dan
  /// `last_down_cause` memakai tabel yang sama karena taksonominya beririsan
  /// (LOS & DyingGasp muncul di keduanya).
  static const _byCode = <String, OnuStatus>{
    'los': OnuStatus(
      label: 'LOS',
      detail: 'Kehilangan sinyal (LOS) — tidak ada cahaya masuk sama sekali: '
          'fiber putus, konektor lepas, atau ONU dicabut.',
      tone: StatusTone.danger,
      icon: LucideIcons.unlink,
    ),
    'losi': OnuStatus(
      label: 'LOS',
      detail: 'Kehilangan sinyal (LOS) — tidak ada cahaya masuk sama sekali: '
          'fiber putus, konektor lepas, atau ONU dicabut.',
      tone: StatusTone.danger,
      icon: LucideIcons.unlink,
    ),
    'dyinggasp': OnuStatus(
      label: 'Dying Gasp',
      detail: 'Listrik pelanggan mati (dying gasp) — ONU sempat melapor sebelum padam, '
          'jadi fiber kemungkinan besar masih baik.',
      tone: StatusTone.warning,
      icon: LucideIcons.powerOff,
    ),
    'lofi': OnuStatus(
      label: 'LOF',
      detail: 'Kehilangan frame (LOF) — cahaya ada tapi sinyal tidak terkunci; '
          'redaman tinggi atau fiber bermasalah.',
      tone: StatusTone.danger,
      icon: LucideIcons.alertTriangle,
    ),
    'sfi': OnuStatus(
      label: 'Signal Fail',
      detail: 'Sinyal buruk (Signal Fail) — bit error terlalu tinggi; periksa redaman '
          'dan sambungan fiber.',
      tone: StatusTone.danger,
      icon: LucideIcons.alertTriangle,
    ),
    'loai': OnuStatus(
      label: 'LOA',
      detail: 'Kehilangan alignment (LOA) — ONU gagal sinkron dengan OLT.',
      tone: StatusTone.danger,
      icon: LucideIcons.alertTriangle,
    ),
    'loami': OnuStatus(
      label: 'LOAM',
      detail: 'Gangguan OAM (LOAM) — kanal manajemen ke ONU terputus.',
      tone: StatusTone.danger,
      icon: LucideIcons.alertTriangle,
    ),
    'authfailed': OnuStatus(
      label: 'Auth gagal',
      detail: 'Autentikasi gagal — SN/password ONU tidak cocok dengan konfigurasi OLT.',
      tone: StatusTone.danger,
      icon: LucideIcons.lock,
    ),
    'deactivated': _disabled,
    'manual': OnuStatus(
      label: 'Nonaktif',
      detail: 'Dimatikan manual oleh admin dari OLT.',
      tone: StatusTone.muted,
      icon: LucideIcons.ban,
    ),
    'logging': OnuStatus(
      label: 'Logging',
      detail: 'Sedang proses masuk ke OLT — belum melayani trafik.',
      tone: StatusTone.info,
      icon: LucideIcons.sync,
    ),
    'syncmib': OnuStatus(
      label: 'Sync MIB',
      detail: 'Sinkronisasi MIB dengan OLT — belum melayani trafik.',
      tone: StatusTone.info,
      icon: LucideIcons.sync,
    ),
  };

  /// Kode yang tidak menambah informasi apa pun soal sebab ONU turun, jadi
  /// dilewati supaya jatuh ke sumber berikutnya (phase → last down cause →
  /// [_offline]).
  ///
  /// 'offline' ikut di sini — C600 mengirim phase `OffLine` dan C-Data GPON
  /// `Offline` untuk SEMUA ONU yang turun, sebabnya menyusul di
  /// `last_down_cause`. Kalau 'offline' dianggap jawaban final, kedua family itu
  /// selamanya cuma menampilkan "Offline".
  static const _uninformative = {'', 'unknown', 'normal', 'working', 'online', 'offline'};

  /// Status dari nilai mentah OLT. Urutan sumbernya:
  /// admin disable → `phase_state` (paling terkini) → `last_down_cause`
  /// (penyebab terakhir turun; satu-satunya petunjuk di C-Data GPON yang
  /// phase_state-nya cuma Online/Offline) → Offline polos.
  factory OnuStatus.resolve({
    required bool online,
    String? phaseState,
    String? lastDownCause,
    String? adminState,
  }) {
    final phase = _key(phaseState);

    if (online) {
      // Fase transisi (logging/sync MIB) tetap ditampilkan apa adanya walau OLT
      // sudah menghitungnya online — ONU-nya memang belum benar-benar melayani.
      final transitional = _byCode[phase];
      if (transitional != null && transitional.tone == StatusTone.info) {
        return transitional;
      }

      return _online;
    }

    // ZTE mengirim 'disabled', C-Data 'disable' — dua-duanya tertangkap.
    if (_key(adminState).startsWith('disab')) {
      return _disabled;
    }

    if (!_uninformative.contains(phase)) {
      final byPhase = _byCode[phase];
      if (byPhase != null) return byPhase;
    }

    final cause = _key(lastDownCause);
    if (!_uninformative.contains(cause)) {
      final byCause = _byCode[cause];
      if (byCause != null) return byCause;
    }

    return _offline;
  }

  factory OnuStatus.of(Onu onu) => OnuStatus.resolve(
        online: onu.online,
        phaseState: onu.phaseState,
        lastDownCause: onu.lastDownCause,
        adminState: onu.adminState,
      );

  /// 'DyingGasp', 'dying-gasp', 'Sync MIB', 'OffLine' → 'dyinggasp', 'syncmib', 'offline'.
  static String _key(String? raw) =>
      (raw ?? '').toLowerCase().replaceAll(RegExp(r'[^a-z0-9]'), '');
}
