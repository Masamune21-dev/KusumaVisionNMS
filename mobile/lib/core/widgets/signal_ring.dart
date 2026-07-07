import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../../theme/app_theme.dart';

/// Gauge melingkar (hero data-viz) — persentase dengan busur gradient bercahaya
/// + angka menghitung naik di tengah. Dipakai untuk % ONU online / health OLT.
class SignalRing extends StatelessWidget {
  const SignalRing({
    super.key,
    required this.percent,
    this.size = 132,
    this.stroke = 11,
    this.color = AppColors.success,
    this.trackColor,
  });

  /// 0..100.
  final double percent;
  final double size;
  final double stroke;
  final Color color;
  final Color? trackColor;

  @override
  Widget build(BuildContext context) {
    final p = (percent.clamp(0, 100)) / 100.0;
    final reduce = MediaQuery.maybeOf(context)?.disableAnimations ?? false;
    final t = Theme.of(context).textTheme;

    Widget ring(double v) => CustomPaint(
          size: Size.square(size),
          painter: _RingPainter(
            value: v,
            stroke: stroke,
            color: color,
            track: trackColor ?? AppColors.surfaceAlt.withValues(alpha: 0.6),
          ),
        );

    return SizedBox(
      width: size,
      height: size,
      child: Stack(
        alignment: Alignment.center,
        children: [
          if (reduce)
            ring(p)
          else
            TweenAnimationBuilder<double>(
              tween: Tween(begin: 0, end: p),
              duration: const Duration(milliseconds: 1100),
              curve: AppMotion.enter,
              builder: (_, v, __) => ring(v),
            ),
          Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              if (reduce)
                Text('${percent.toStringAsFixed(0)}%',
                    style: _centerStyle(t, color))
              else
                TweenAnimationBuilder<double>(
                  tween: Tween(begin: 0, end: percent.clamp(0, 100)),
                  duration: const Duration(milliseconds: 1100),
                  curve: AppMotion.enter,
                  builder: (_, v, __) =>
                      Text('${v.toStringAsFixed(0)}%', style: _centerStyle(t, color)),
                ),
            ],
          ),
        ],
      ),
    );
  }

  TextStyle? _centerStyle(TextTheme t, Color c) => t.displaySmall?.copyWith(
        color: c,
        fontFeatures: const [FontFeature.tabularFigures()],
        shadows: [Shadow(color: c.withValues(alpha: 0.5), blurRadius: 18)],
      );
}

class _RingPainter extends CustomPainter {
  _RingPainter({
    required this.value,
    required this.stroke,
    required this.color,
    required this.track,
  });

  final double value;
  final double stroke;
  final Color color;
  final Color track;

  @override
  void paint(Canvas canvas, Size size) {
    final rect = Offset.zero & size;
    final center = rect.center;
    final radius = (size.shortestSide - stroke) / 2;
    const start = -math.pi / 2;
    final sweep = value * math.pi * 2;

    // Track penuh.
    canvas.drawCircle(
      center,
      radius,
      Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = stroke
        ..color = track,
    );

    if (value <= 0) return;

    final arcRect = Rect.fromCircle(center: center, radius: radius);
    // Glow di belakang busur.
    canvas.drawArc(
      arcRect,
      start,
      sweep,
      false,
      Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = stroke
        ..strokeCap = StrokeCap.round
        ..color = color.withValues(alpha: 0.55)
        ..maskFilter = const MaskFilter.blur(BlurStyle.normal, 6),
    );
    // Busur gradient utama.
    canvas.drawArc(
      arcRect,
      start,
      sweep,
      false,
      Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = stroke
        ..strokeCap = StrokeCap.round
        ..shader = SweepGradient(
          startAngle: start,
          endAngle: start + math.pi * 2,
          colors: [color.withValues(alpha: 0.35), color, AppColors.secondary],
          stops: const [0.0, 0.65, 1.0],
        ).createShader(arcRect),
    );
  }

  @override
  bool shouldRepaint(_RingPainter old) =>
      old.value != value || old.color != color || old.stroke != stroke;
}
