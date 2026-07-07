import 'dart:ui' as ui;

import 'package:flutter/material.dart';

import '../../theme/app_theme.dart';

/// Kartu permukaan ter-elevasi — dasar visual seluruh UI (glassmorphism v2).
///
/// Depth dibangun lewat perbedaan warna surface vs bg + shadow lembut + sheen
/// gradient tipis (bukan border tebal). Secara default **tanpa BackdropFilter**
/// agar daftar panjang (ribuan ONU) tetap mulus. Set [blur] = true HANYA untuk
/// kartu hero tunggal (login/dashboard) supaya aurora latar tembus jadi frosted.
class GlassCard extends StatefulWidget {
  const GlassCard({
    super.key,
    required this.child,
    this.padding = const EdgeInsets.all(16),
    this.onTap,
    this.accent,
    this.radius = AppRadius.card,
    this.blur = false,
  });

  final Widget child;
  final EdgeInsetsGeometry padding;
  final VoidCallback? onTap;

  /// Warna aksen opsional (glow + garis tepi) untuk kartu terpilih/terfokus.
  final Color? accent;
  final double radius;

  /// Frosted glass (aurora latar tembus). Pakai hemat — hanya kartu hero.
  final bool blur;

  @override
  State<GlassCard> createState() => _GlassCardState();
}

class _GlassCardState extends State<GlassCard> {
  bool _pressed = false;

  @override
  Widget build(BuildContext context) {
    final br = BorderRadius.circular(widget.radius);
    final accent = widget.accent;

    final decoration = BoxDecoration(
      borderRadius: br,
      gradient: widget.blur
          ? LinearGradient(
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
              colors: [
                AppColors.surfaceHi.withValues(alpha: 0.72),
                AppColors.surface.withValues(alpha: 0.55),
              ],
            )
          : const LinearGradient(
              begin: Alignment.topCenter,
              end: Alignment.bottomCenter,
              colors: [AppColors.surfaceHi, AppColors.surface],
            ),
      border: Border.all(
        color: accent?.withValues(alpha: 0.45) ?? AppColors.border,
        width: accent != null ? 1.2 : 1,
      ),
      boxShadow: accent != null
          ? [...AppShadow.card, ...AppShadow.glow(accent, alpha: 0.22)]
          : AppShadow.card,
    );

    // Isi kartu dibiarkan LEBAR PENUH (Padding langsung) agar crossAxisAlignment
    // (center/start) bekerja benar. Sebelumnya dibungkus Stack → isi menyusut &
    // menempel kiri-atas sehingga konten yang seharusnya center jadi rata-kiri.
    Widget inner = Material(
      type: MaterialType.transparency,
      child: InkWell(
        onTap: widget.onTap,
        onHighlightChanged:
            widget.onTap == null ? null : (v) => setState(() => _pressed = v),
        splashColor: AppColors.primary.withValues(alpha: 0.08),
        highlightColor: AppColors.primary.withValues(alpha: 0.05),
        child: Padding(padding: widget.padding, child: widget.child),
      ),
    );

    if (widget.blur) {
      inner = BackdropFilter(
        filter: ui.ImageFilter.blur(sigmaX: 18, sigmaY: 18),
        child: inner,
      );
    }

    Widget card = DecoratedBox(
      decoration: decoration,
      child: ClipRRect(borderRadius: br, child: inner),
    );

    if (widget.onTap != null) {
      card = AnimatedScale(
        scale: _pressed ? 0.98 : 1.0,
        duration: AppMotion.fast,
        curve: AppMotion.enter,
        child: card,
      );
    }
    return card;
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
                fontFamily: AppFont.display,
                fontWeight: FontWeight.w700,
                fontSize: 15,
                letterSpacing: -0.2,
              )),
          if (trailing != null) ...[const Spacer(), trailing!],
        ],
      ),
    );
  }
}
