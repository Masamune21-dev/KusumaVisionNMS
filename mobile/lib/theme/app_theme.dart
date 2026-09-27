import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

/// Satu set warna aplikasi. Dua instans: [AppPalette.dark] (dark glass cyan/sky,
/// menyamakan dashboard web NMS) dan [AppPalette.light] (kanvas `#F5F7FB` + kartu
/// putih, mengikuti token `[data-theme="light"]` di `resources/css/app.css`).
///
/// Di tema terang aksen & status memakai tingkat yang lebih gelap (cyan-700,
/// emerald-700, dst.) supaya teks tetap lolos kontras AA di atas putih — sama
/// seperti aturan tema terang web.
@immutable
class AppPalette {
  const AppPalette({
    required this.brightness,
    required this.bg,
    required this.bgElevated,
    required this.surface,
    required this.surfaceHi,
    required this.surfaceAlt,
    required this.border,
    required this.borderStrong,
    required this.primary,
    required this.primaryDeep,
    required this.onPrimary,
    required this.secondary,
    required this.text,
    required this.muted,
    required this.faint,
    required this.success,
    required this.warning,
    required this.danger,
    required this.info,
    required this.major,
    required this.onWarning,
    required this.shadow,
  });

  final Brightness brightness;
  final Color bg, bgElevated, surface, surfaceHi, surfaceAlt, border, borderStrong;
  final Color primary, primaryDeep, onPrimary, secondary;
  final Color text, muted, faint;
  final Color success, warning, danger, info, major;

  /// Teks/ikon di atas latar [warning] (tombol peringatan).
  final Color onWarning;

  /// Warna bayangan kartu.
  final Color shadow;

  bool get isDark => brightness == Brightness.dark;

  static const dark = AppPalette(
    brightness: Brightness.dark,
    bg: Color(0xFF070D18), // near-black navy
    bgElevated: Color(0xFF0C1524), // appbar / bottom nav / sheet
    surface: Color(0xFF121D32), // dasar kartu (jelas lebih terang dari bg)
    surfaceHi: Color(0xFF17233C), // sheen kartu (gradient atas)
    surfaceAlt: Color(0xFF1B2A46), // chip / nested / pressed
    border: Color(0x14FFFFFF), // hairline 8% putih
    borderStrong: Color(0x24FFFFFF),
    primary: Color(0xFF22D3EE), // cyan-400
    primaryDeep: Color(0xFF0E7490),
    onPrimary: Color(0xFF04121A),
    secondary: Color(0xFF38BDF8), // sky-400
    text: Color(0xFFE8EEF7),
    muted: Color(0xFF97A6BF),
    faint: Color(0xFF5E6E88),
    success: Color(0xFF34D399),
    warning: Color(0xFFFBBF24),
    danger: Color(0xFFFB7185),
    info: Color(0xFF60A5FA),
    major: Color(0xFFFB923C),
    onWarning: Color(0xFF241A00),
    shadow: Color(0x40000000),
  );

  static const light = AppPalette(
    brightness: Brightness.light,
    bg: Color(0xFFF5F7FB), // = --kv-canvas terang
    bgElevated: Color(0xFFFFFFFF),
    surface: Color(0xFFFFFFFF), // kartu = kertas putih
    surfaceHi: Color(0xFFFFFFFF),
    surfaceAlt: Color(0xFFEEF2F7), // = --kv-surface-hover
    border: Color(0x170F172A), // 9% slate-900
    borderStrong: Color(0x2B0F172A),
    primary: Color(0xFF0E7490), // cyan-700 — 5,4:1 di atas putih
    primaryDeep: Color(0xFF155E75),
    onPrimary: Color(0xFFFFFFFF),
    secondary: Color(0xFF0369A1), // sky-700
    text: Color(0xFF0F172A),
    muted: Color(0xFF475569),
    faint: Color(0xFF64748B),
    success: Color(0xFF047857), // emerald-700
    warning: Color(0xFFB45309), // amber-700
    danger: Color(0xFFE11D48), // rose-600
    info: Color(0xFF2563EB),
    major: Color(0xFFC2410C),
    onWarning: Color(0xFFFFFFFF),
    shadow: Color(0x140F172A),
  );
}

/// Warna aplikasi — **selalu mengikuti tema aktif**. Nilainya dibaca dari palet
/// yang dipasang [KusumaVisionApp] lewat [use]; saat tema berganti, akar aplikasi
/// menandai seluruh pohon widget untuk dibangun ulang (state tetap utuh), jadi
/// cukup tulis `AppColors.primary` di `build` seperti biasa.
///
/// Konsekuensinya: warna ini **bukan konstanta** — jangan simpan di field
/// `static`/`const`, `initState`, atau objek yang dibuat sekali; baca saat build/paint.
class AppColors {
  static AppPalette _p = AppPalette.dark;

  /// Palet aktif.
  static AppPalette get palette => _p;

  /// Pasang palet untuk [brightness]. Mengembalikan true bila berubah.
  static bool use(Brightness brightness) {
    final next = brightness == Brightness.dark ? AppPalette.dark : AppPalette.light;
    if (identical(next, _p)) return false;
    _p = next;
    return true;
  }

  static bool get isDark => _p.isDark;

  // Background berlapis (makin gelap = makin dalam di tema gelap).
  static Color get bg => _p.bg;
  static Color get bgElevated => _p.bgElevated;
  static Color get surface => _p.surface;
  static Color get surfaceHi => _p.surfaceHi;
  static Color get surfaceAlt => _p.surfaceAlt;

  static Color get border => _p.border;
  static Color get borderStrong => _p.borderStrong;

  static Color get primary => _p.primary;
  static Color get primaryDeep => _p.primaryDeep;
  static Color get onPrimary => _p.onPrimary;
  static Color get secondary => _p.secondary;

  static Color get text => _p.text;
  static Color get muted => _p.muted;
  static Color get faint => _p.faint;

  static Color get success => _p.success; // online / reachable
  static Color get warning => _p.warning; // rx marginal
  static Color get danger => _p.danger; // offline / critical
  static Color get info => _p.info;
  static Color get major => _p.major; // orange severity
  static Color get onWarning => _p.onWarning;

  /// Warna severity alarm.
  static Color severity(String s) => switch (s) {
        'critical' => danger,
        'major' => major,
        'minor' => warning,
        'warning' => info,
        _ => muted,
      };
}

/// Token radius konsisten (12–16px = kesan modern; 999 = pill).
class AppRadius {
  static const card = 16.0;
  static const control = 12.0;
  static const chip = 10.0;
  static const pill = 999.0;
}

/// Shadow elevasi lembut (dipakai kartu — bukan border sebagai pemisah utama).
class AppShadow {
  static List<BoxShadow> get card => [
        BoxShadow(color: AppColors.palette.shadow, blurRadius: 18, offset: const Offset(0, 8)),
      ];

  /// Bayangan panel melayang (navbar, bottom-sheet) — pekat di gelap, lembut di terang.
  static List<BoxShadow> floating({double blur = 24, double dy = 10}) => [
        BoxShadow(
          color: AppColors.isDark ? const Color(0x55000000) : const Color(0x1F0F172A),
          blurRadius: blur,
          offset: Offset(0, dy),
        ),
      ];

  /// Glow aksen (mis. tombol/kartu terpilih).
  static List<BoxShadow> glow(Color c, {double alpha = 0.35, double blur = 20}) => [
        BoxShadow(color: c.withValues(alpha: alpha), blurRadius: blur, offset: const Offset(0, 4)),
      ];
}

/// Keluarga font (di-bundle sebagai aset variable-weight di `assets/fonts/`).
/// - [display] Sora  → heading, angka besar (geometric, modern).
/// - [body]    Inter → body & label (netral, tabular figures).
/// - [mono]    JetBrainsMono → data teknis: serial ONU, RX dBm, IP, uptime.
class AppFont {
  static const display = 'Sora';
  static const body = 'Inter';
  static const mono = 'JetBrainsMono';
}

/// Token durasi & kurva gerak — dipakai seragam agar animasi punya ritme sama.
class AppMotion {
  static const fast = Duration(milliseconds: 180);
  static const base = Duration(milliseconds: 260);
  static const slow = Duration(milliseconds: 420);

  /// Jeda antar item saat daftar/grid masuk (stagger).
  static const stagger = Duration(milliseconds: 45);

  static const enter = Curves.easeOutCubic; // elemen masuk
  static const exit = Curves.easeInCubic; // elemen keluar
  static const spring = Curves.easeOutBack; // pop/press
}

/// Gradient aksen — dipakai bar, badge, tombol terpilih, teks glow.
class AppGradient {
  static LinearGradient get accent => LinearGradient(
        begin: Alignment.topLeft,
        end: Alignment.bottomRight,
        colors: [AppColors.primary, AppColors.secondary],
      );
  static LinearGradient get success =>
      LinearGradient(colors: [const Color(0xFF10B981), AppColors.success]);
  static LinearGradient get warn =>
      LinearGradient(colors: [const Color(0xFFF59E0B), AppColors.warning]);
  static LinearGradient get danger =>
      LinearGradient(colors: [const Color(0xFFF43F5E), AppColors.danger]);
}

const _tnum = [FontFeature.tabularFigures()];

/// Helper gaya teks monospace untuk data teknis (rata kolom, tabular).
class AppText {
  static TextStyle mono({
    double size = 13,
    FontWeight weight = FontWeight.w500,
    Color? color,
    double? letterSpacing,
    double? height,
  }) =>
      TextStyle(
        fontFamily: AppFont.mono,
        fontFeatures: _tnum,
        fontSize: size,
        fontWeight: weight,
        color: color,
        letterSpacing: letterSpacing,
        height: height,
      );
}

class AppTheme {
  /// Skala tipografi Material 3 dengan pemetaan 3-keluarga (Sora/Inter).
  static TextTheme _textTheme(Color color) {
    TextStyle sora(double size, FontWeight w, double tracking) => TextStyle(
          fontFamily: AppFont.display,
          fontSize: size,
          fontWeight: w,
          letterSpacing: tracking,
          height: 1.05,
          color: color,
        );
    TextStyle inter(double size, FontWeight w, {double h = 1.45, double tracking = 0}) => TextStyle(
          fontFamily: AppFont.body,
          fontSize: size,
          fontWeight: w,
          height: h,
          letterSpacing: tracking,
          color: color,
        );

    return TextTheme(
      displayLarge: sora(40, FontWeight.w800, -1.4),
      displayMedium: sora(32, FontWeight.w800, -1.0),
      displaySmall: sora(28, FontWeight.w700, -0.7),
      headlineLarge: sora(26, FontWeight.w700, -0.5),
      headlineMedium: sora(23, FontWeight.w700, -0.4),
      headlineSmall: sora(20, FontWeight.w700, -0.3),
      titleLarge: sora(18, FontWeight.w700, -0.2),
      titleMedium: inter(15.5, FontWeight.w600, h: 1.3),
      titleSmall: inter(13.5, FontWeight.w600, h: 1.3),
      bodyLarge: inter(15.5, FontWeight.w400, h: 1.5),
      bodyMedium: inter(14, FontWeight.w400, h: 1.5),
      bodySmall: inter(12.5, FontWeight.w400, h: 1.45),
      labelLarge: inter(14, FontWeight.w600, h: 1.2),
      labelMedium: inter(12.5, FontWeight.w600, h: 1.2, tracking: 0.2),
      labelSmall: inter(11, FontWeight.w600, h: 1.1, tracking: 0.4),
    );
  }

  static ThemeData dark() => build(AppPalette.dark);
  static ThemeData light() => build(AppPalette.light);

  /// ThemeData untuk satu palet. Membaca [p] langsung (bukan [AppColors]) supaya
  /// tema gelap & terang bisa dibangun berdampingan untuk `MaterialApp`.
  static ThemeData build(AppPalette p) {
    final scheme = (p.isDark ? const ColorScheme.dark() : const ColorScheme.light()).copyWith(
      primary: p.primary,
      onPrimary: p.onPrimary,
      secondary: p.secondary,
      onSecondary: p.isDark ? const Color(0xFF04202B) : Colors.white,
      surface: p.bgElevated,
      onSurface: p.text,
      onSurfaceVariant: p.muted,
      surfaceContainerHighest: p.surfaceAlt,
      error: p.danger,
      onError: p.isDark ? const Color(0xFF2A0A0A) : Colors.white,
      outline: p.borderStrong,
      outlineVariant: p.border,
    );

    final base = ThemeData(
      useMaterial3: true,
      brightness: p.brightness,
      colorScheme: scheme,
      scaffoldBackgroundColor: p.bg,
      fontFamily: AppFont.body,
      splashFactory: InkSparkle.splashFactory,
    );

    return base.copyWith(
      appBarTheme: AppBarTheme(
        systemOverlayStyle: p.isDark ? SystemUiOverlayStyle.light : SystemUiOverlayStyle.dark,
        backgroundColor: p.bg,
        surfaceTintColor: Colors.transparent,
        scrolledUnderElevation: 0,
        elevation: 0,
        centerTitle: false,
        titleTextStyle: TextStyle(
          fontFamily: AppFont.display,
          color: p.text,
          fontSize: 20,
          fontWeight: FontWeight.w700,
          letterSpacing: -0.3,
        ),
        iconTheme: IconThemeData(color: p.text),
      ),
      cardTheme: CardThemeData(
        color: p.surface,
        elevation: 0,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(AppRadius.card)),
        margin: EdgeInsets.zero,
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: p.surfaceAlt.withValues(alpha: 0.55),
        hintStyle: TextStyle(color: p.faint),
        labelStyle: TextStyle(color: p.muted),
        floatingLabelStyle: TextStyle(color: p.primary, fontWeight: FontWeight.w600),
        prefixIconColor: p.faint,
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(AppRadius.control),
          borderSide: BorderSide.none,
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(AppRadius.control),
          borderSide: BorderSide(color: p.border),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(AppRadius.control),
          borderSide: BorderSide(color: p.primary, width: 1.6),
        ),
        errorBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(AppRadius.control),
          borderSide: BorderSide(color: p.danger, width: 1.2),
        ),
        focusedErrorBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(AppRadius.control),
          borderSide: BorderSide(color: p.danger, width: 1.6),
        ),
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 15),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          backgroundColor: p.primary,
          foregroundColor: p.onPrimary,
          textStyle: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14.5),
          minimumSize: const Size(0, 50),
          padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 14),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(AppRadius.control)),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          foregroundColor: p.secondary,
          side: BorderSide(color: p.borderStrong),
          textStyle: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14.5),
          minimumSize: const Size(0, 50),
          padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 14),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(AppRadius.control)),
        ),
      ),
      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(foregroundColor: p.secondary),
      ),
      navigationBarTheme: NavigationBarThemeData(
        backgroundColor: p.bgElevated,
        indicatorColor: p.primary.withValues(alpha: 0.16),
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        height: 66,
        indicatorShape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(AppRadius.control)),
        labelTextStyle: WidgetStateProperty.resolveWith(
          (states) => TextStyle(
            fontSize: 11,
            fontWeight: FontWeight.w600,
            color: states.contains(WidgetState.selected) ? p.primary : p.faint,
          ),
        ),
        iconTheme: WidgetStateProperty.resolveWith(
          (states) => IconThemeData(
            size: 23,
            color: states.contains(WidgetState.selected) ? p.primary : p.faint,
          ),
        ),
      ),
      chipTheme: ChipThemeData(
        backgroundColor: p.surfaceAlt.withValues(alpha: 0.5),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(AppRadius.pill)),
        side: BorderSide(color: p.border),
      ),
      dividerTheme: DividerThemeData(color: p.border, thickness: 1, space: 1),
      dialogTheme: DialogThemeData(
        backgroundColor: p.bgElevated,
        surfaceTintColor: Colors.transparent,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
        titleTextStyle: TextStyle(color: p.text, fontSize: 17, fontWeight: FontWeight.w700),
        contentTextStyle: TextStyle(color: p.muted, fontSize: 14, height: 1.45),
      ),
      snackBarTheme: SnackBarThemeData(
        backgroundColor: p.surfaceAlt,
        contentTextStyle: TextStyle(color: p.text, fontWeight: FontWeight.w500),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(AppRadius.control)),
        behavior: SnackBarBehavior.floating,
        insetPadding: const EdgeInsets.fromLTRB(16, 8, 16, 16),
      ),
      progressIndicatorTheme: ProgressIndicatorThemeData(color: p.primary),
      textTheme: _textTheme(p.text),
    );
  }
}
