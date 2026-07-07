import 'package:flutter/material.dart';

import '../../theme/app_theme.dart';

/// Kartu permukaan ter-elevasi — dasar visual seluruh UI.
///
/// Pemisahan dari background dibangun lewat perbedaan warna tegas + shadow
/// lembut + sheen gradient tipis (bukan border tebal). Tanpa BackdropFilter
/// per-kartu agar daftar panjang (ribuan ONU) tetap mulus di-scroll.
class GlassCard extends StatelessWidget {
  const GlassCard({
    super.key,
    required this.child,
    this.padding = const EdgeInsets.all(16),
    this.onTap,
    this.accent,
    this.radius = AppRadius.card,
  });

  final Widget child;
  final EdgeInsetsGeometry padding;
  final VoidCallback? onTap;

  /// Warna aksen opsional (glow + garis tepi) untuk kartu terpilih/terfokus.
  final Color? accent;
  final double radius;

  @override
  Widget build(BuildContext context) {
    final br = BorderRadius.circular(radius);
    return DecoratedBox(
      decoration: BoxDecoration(
        borderRadius: br,
        gradient: const LinearGradient(
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
          colors: [AppColors.surfaceHi, AppColors.surface],
        ),
        border: Border.all(
          color: accent?.withValues(alpha: 0.45) ?? AppColors.border,
          width: accent != null ? 1.2 : 1,
        ),
        boxShadow: accent != null
            ? [...AppShadow.card, ...AppShadow.glow(accent!, alpha: 0.22)]
            : AppShadow.card,
      ),
      child: ClipRRect(
        borderRadius: br,
        child: Material(
          type: MaterialType.transparency,
          child: InkWell(
            onTap: onTap,
            splashColor: AppColors.primary.withValues(alpha: 0.08),
            highlightColor: AppColors.primary.withValues(alpha: 0.05),
            child: Padding(padding: padding, child: child),
          ),
        ),
      ),
    );
  }
}

/// Judul seksi kecil dengan ikon aksen — dipakai antar-kelompok kartu.
class SectionTitle extends StatelessWidget {
  const SectionTitle(this.label, {super.key, this.icon, this.trailing});

  final String label;
  final IconData? icon;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(left: 2, bottom: 10, top: 2),
      child: Row(
        children: [
          if (icon != null) ...[
            Icon(icon, size: 16, color: AppColors.primary),
            const SizedBox(width: 8),
          ],
          Text(label,
              style: const TextStyle(
                  fontWeight: FontWeight.w700, fontSize: 14.5, letterSpacing: 0.1)),
          if (trailing != null) ...[const Spacer(), trailing!],
        ],
      ),
    );
  }
}
