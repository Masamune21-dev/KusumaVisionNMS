import 'package:flutter/material.dart';

/// Palet & tema aplikasi — dark glass cyan/sky menyamakan dashboard web
/// KusumaVision (kelas `kv-*`). Material 3, dark-only, gaya OLED.
///
/// Depth dibangun lewat perbedaan warna tegas antara background dan surface
/// (bukan hanya border) + shadow lembut, sesuai pedoman UI/UX pro-max.
class AppColors {
  // Background berlapis (OLED navy → makin gelap = makin dalam).
  static const bg = Color(0xFF070D18); // background aplikasi (near-black navy)
  static const bgElevated = Color(0xFF0C1524); // appbar / bottom nav
  static const surface = Color(0xFF121D32); // dasar kartu (jelas lebih terang dari bg)
  static const surfaceHi = Color(0xFF17233C); // sheen kartu (gradient atas)
  static const surfaceAlt = Color(0xFF1B2A46); // chip / nested / pressed

  static const border = Color(0x14FFFFFF); // hairline 8% putih (halus)
  static const borderStrong = Color(0x24FFFFFF);

  static const primary = Color(0xFF22D3EE); // cyan aksen (glow)
  static const primaryDeep = Color(0xFF0E7490);
  static const onPrimary = Color(0xFF04121A);
  static const secondary = Color(0xFF38BDF8); // sky

  static const text = Color(0xFFE8EEF7);
  static const muted = Color(0xFF97A6BF);
  static const faint = Color(0xFF5E6E88);

  static const success = Color(0xFF34D399); // online / reachable
  static const warning = Color(0xFFFBBF24); // rx marginal
  static const danger = Color(0xFFFB7185); // offline / critical (merah terang)
  static const info = Color(0xFF60A5FA);
  static const major = Color(0xFFFB923C); // orange severity

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
  static const card = [
    BoxShadow(color: Color(0x40000000), blurRadius: 18, offset: Offset(0, 8)),
  ];

  /// Glow aksen (mis. tombol/kartu terpilih).
  static List<BoxShadow> glow(Color c, {double alpha = 0.35, double blur = 20}) => [
        BoxShadow(color: c.withValues(alpha: alpha), blurRadius: blur, offset: const Offset(0, 4)),
      ];
}

class AppTheme {
  static ThemeData dark() {
    const scheme = ColorScheme.dark(
      primary: AppColors.primary,
      onPrimary: AppColors.onPrimary,
      secondary: AppColors.secondary,
      onSecondary: Color(0xFF04202B),
      surface: AppColors.bgElevated,
      onSurface: AppColors.text,
      error: AppColors.danger,
      onError: Color(0xFF2A0A0A),
      outline: AppColors.border,
    );

    final base = ThemeData(
      useMaterial3: true,
      brightness: Brightness.dark,
      colorScheme: scheme,
      scaffoldBackgroundColor: AppColors.bg,
      fontFamily: 'Roboto',
      splashFactory: InkSparkle.splashFactory,
    );

    return base.copyWith(
      appBarTheme: const AppBarTheme(
        backgroundColor: AppColors.bg,
        surfaceTintColor: Colors.transparent,
        scrolledUnderElevation: 0,
        elevation: 0,
        centerTitle: false,
        titleTextStyle: TextStyle(
          color: AppColors.text,
          fontSize: 20,
          fontWeight: FontWeight.w700,
          letterSpacing: -0.2,
        ),
        iconTheme: IconThemeData(color: AppColors.text),
      ),
      cardTheme: CardThemeData(
        color: AppColors.surface,
        elevation: 0,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(AppRadius.card)),
        margin: EdgeInsets.zero,
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: AppColors.surfaceAlt.withValues(alpha: 0.55),
        hintStyle: const TextStyle(color: AppColors.faint),
        labelStyle: const TextStyle(color: AppColors.muted),
        floatingLabelStyle: const TextStyle(color: AppColors.primary, fontWeight: FontWeight.w600),
        prefixIconColor: AppColors.faint,
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(AppRadius.control),
          borderSide: BorderSide.none,
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(AppRadius.control),
          borderSide: const BorderSide(color: AppColors.border),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(AppRadius.control),
          borderSide: const BorderSide(color: AppColors.primary, width: 1.6),
        ),
        errorBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(AppRadius.control),
          borderSide: const BorderSide(color: AppColors.danger, width: 1.2),
        ),
        focusedErrorBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(AppRadius.control),
          borderSide: const BorderSide(color: AppColors.danger, width: 1.6),
        ),
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 15),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          backgroundColor: AppColors.primary,
          foregroundColor: AppColors.onPrimary,
          textStyle: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14.5),
          minimumSize: const Size(0, 50),
          padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 14),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(AppRadius.control)),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          foregroundColor: AppColors.secondary,
          side: const BorderSide(color: AppColors.borderStrong),
          textStyle: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14.5),
          minimumSize: const Size(0, 50),
          padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 14),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(AppRadius.control)),
        ),
      ),
      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(foregroundColor: AppColors.secondary),
      ),
      navigationBarTheme: NavigationBarThemeData(
        backgroundColor: AppColors.bgElevated,
        indicatorColor: AppColors.primary.withValues(alpha: 0.16),
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        height: 66,
        indicatorShape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(AppRadius.control)),
        labelTextStyle: WidgetStateProperty.resolveWith(
          (states) => TextStyle(
            fontSize: 11,
            fontWeight: FontWeight.w600,
            color: states.contains(WidgetState.selected) ? AppColors.primary : AppColors.faint,
          ),
        ),
        iconTheme: WidgetStateProperty.resolveWith(
          (states) => IconThemeData(
            size: 23,
            color: states.contains(WidgetState.selected) ? AppColors.primary : AppColors.faint,
          ),
        ),
      ),
      chipTheme: ChipThemeData(
        backgroundColor: AppColors.surfaceAlt.withValues(alpha: 0.5),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(AppRadius.pill)),
        side: const BorderSide(color: AppColors.border),
      ),
      dividerTheme: const DividerThemeData(color: AppColors.border, thickness: 1, space: 1),
      dialogTheme: DialogThemeData(
        backgroundColor: AppColors.bgElevated,
        surfaceTintColor: Colors.transparent,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
        titleTextStyle: const TextStyle(color: AppColors.text, fontSize: 17, fontWeight: FontWeight.w700),
        contentTextStyle: const TextStyle(color: AppColors.muted, fontSize: 14, height: 1.45),
      ),
      snackBarTheme: SnackBarThemeData(
        backgroundColor: AppColors.surfaceAlt,
        contentTextStyle: const TextStyle(color: AppColors.text, fontWeight: FontWeight.w500),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(AppRadius.control)),
        behavior: SnackBarBehavior.floating,
        insetPadding: const EdgeInsets.fromLTRB(16, 8, 16, 16),
      ),
      progressIndicatorTheme: const ProgressIndicatorThemeData(color: AppColors.primary),
      textTheme: base.textTheme.apply(bodyColor: AppColors.text, displayColor: AppColors.text),
    );
  }
}
