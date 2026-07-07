import 'package:flutter/material.dart';
import 'package:flutter_animate/flutter_animate.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:kusumavision_nms/core/icons.dart';
import 'package:shimmer/shimmer.dart';

import '../../theme/app_theme.dart';
import 'glass_card.dart';

/// Render seragam untuk AsyncValue: skeleton loading, error+retry, atau data.
class AsyncView<T> extends StatelessWidget {
  const AsyncView({
    super.key,
    required this.value,
    required this.data,
    this.onRetry,
    this.loading,
  });

  final AsyncValue<T> value;
  final Widget Function(T data) data;
  final VoidCallback? onRetry;

  /// Override skeleton bawaan bila layar butuh placeholder khusus.
  final Widget Function()? loading;

  @override
  Widget build(BuildContext context) {
    return value.when(
      data: data,
      loading: loading ?? () => const _SkeletonList(),
      error: (e, _) => ErrorRetry(message: '$e', onRetry: onRetry),
    );
  }
}

/// Kotak dasar skeleton (warna solid). Beri efek kilau dengan membungkus
/// grup-nya dalam [SkeletonShimmer].
class Skeleton extends StatelessWidget {
  const Skeleton({super.key, this.width, this.height = 14, this.radius = 8});

  final double? width;
  final double height;
  final double radius;

  @override
  Widget build(BuildContext context) {
    return ClipRRect(
      borderRadius: BorderRadius.circular(radius),
      child: SizedBox(
        width: width,
        height: height,
        child: Container(color: AppColors.surfaceAlt.withValues(alpha: 0.75)),
      ),
    );
  }
}

/// Membungkus sekelompok [Skeleton] dengan kilau shimmer bergerak (satu fase
/// seragam). Hormati reduced-motion (tampil statis).
class SkeletonShimmer extends StatelessWidget {
  const SkeletonShimmer({super.key, required this.child});
  final Widget child;

  @override
  Widget build(BuildContext context) {
    final reduce = MediaQuery.maybeOf(context)?.disableAnimations ?? false;
    if (reduce) return child;
    return Shimmer.fromColors(
      baseColor: AppColors.surfaceAlt.withValues(alpha: 0.55),
      highlightColor: AppColors.surfaceHi.withValues(alpha: 0.95),
      period: const Duration(milliseconds: 1500),
      child: child,
    );
  }
}

class _SkeletonList extends StatelessWidget {
  const _SkeletonList();

  @override
  Widget build(BuildContext context) {
    return SkeletonShimmer(
      child: ListView.separated(
        padding: const EdgeInsets.fromLTRB(16, 14, 16, 24),
        itemCount: 6,
        separatorBuilder: (_, __) => const SizedBox(height: 12),
        itemBuilder: (_, __) => GlassCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: const [
              Row(children: [
                Skeleton(width: 150, height: 15),
                Spacer(),
                Skeleton(width: 68, height: 22, radius: 999),
              ]),
              SizedBox(height: 12),
              Skeleton(width: 110, height: 12),
              SizedBox(height: 14),
              Skeleton(height: 12),
            ],
          ),
        ),
      ),
    );
  }
}

class ErrorRetry extends StatelessWidget {
  const ErrorRetry({super.key, required this.message, this.onRetry});

  final String message;
  final VoidCallback? onRetry;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(28),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              padding: const EdgeInsets.all(18),
              decoration: BoxDecoration(
                color: AppColors.danger.withValues(alpha: 0.12),
                shape: BoxShape.circle,
              ),
              child: const Icon(LucideIcons.wifiOff, color: AppColors.danger, size: 34),
            ),
            const SizedBox(height: 16),
            const Text('Gagal memuat data',
                style: TextStyle(color: AppColors.text, fontSize: 15, fontWeight: FontWeight.w700)),
            const SizedBox(height: 6),
            Text(
              message,
              textAlign: TextAlign.center,
              maxLines: 3,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(color: AppColors.muted, fontSize: 12.5),
            ),
            if (onRetry != null) ...[
              const SizedBox(height: 18),
              FilledButton.icon(
                onPressed: onRetry,
                icon: const Icon(LucideIcons.refreshCw, size: 18),
                label: const Text('Coba lagi'),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class EmptyState extends StatelessWidget {
  const EmptyState({super.key, required this.message, this.icon = LucideIcons.inbox});

  final String message;
  final IconData icon;

  @override
  Widget build(BuildContext context) {
    final reduce = MediaQuery.maybeOf(context)?.disableAnimations ?? false;

    Widget badge = Container(
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [
            AppColors.surfaceHi.withValues(alpha: 0.8),
            AppColors.surface.withValues(alpha: 0.6),
          ],
        ),
        shape: BoxShape.circle,
        border: Border.all(color: AppColors.borderStrong),
        boxShadow: AppShadow.glow(AppColors.primary, alpha: 0.12, blur: 26),
      ),
      child: Icon(icon, color: AppColors.muted, size: 34),
    );
    // Melayang lembut (naik-turun) — dimatikan saat reduced-motion.
    if (!reduce) {
      badge = badge
          .animate(onPlay: (c) => c.repeat(reverse: true))
          .moveY(begin: 0, end: -7, duration: 1900.ms, curve: Curves.easeInOut);
    }

    return Center(
      child: Padding(
        padding: const EdgeInsets.all(36),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            badge,
            const SizedBox(height: 18),
            Text(
              message,
              textAlign: TextAlign.center,
              style: const TextStyle(color: AppColors.muted, fontSize: 13.5, height: 1.4),
            ),
          ],
        ),
      ),
    ).animate().fadeIn(duration: AppMotion.base).scale(
          begin: const Offset(0.94, 0.94),
          curve: AppMotion.enter,
        );
  }
}
