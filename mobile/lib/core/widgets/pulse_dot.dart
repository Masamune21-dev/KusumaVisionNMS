import 'package:flutter/material.dart';

/// Titik status dengan denyut (halo menyebar & memudar) — mis. ONU/OLT online.
/// Set [pulse] false (atau offline) → titik diam bercahaya lembut. Hormati
/// reduced-motion.
class PulseDot extends StatefulWidget {
  const PulseDot({super.key, required this.color, this.size = 9, this.pulse = true});

  final Color color;
  final double size;
  final bool pulse;

  @override
  State<PulseDot> createState() => _PulseDotState();
}

class _PulseDotState extends State<PulseDot> with SingleTickerProviderStateMixin {
  late final AnimationController _c =
      AnimationController(vsync: this, duration: const Duration(milliseconds: 1700))..repeat();

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final reduce = MediaQuery.maybeOf(context)?.disableAnimations ?? false;
    final animate = widget.pulse && !reduce;
    if (animate && !_c.isAnimating) _c.repeat();
    if (!animate && _c.isAnimating) _c.stop();

    final s = widget.size;
    return SizedBox(
      width: s * 2.4,
      height: s * 2.4,
      child: Center(
        child: animate
            ? AnimatedBuilder(
                animation: _c,
                builder: (_, __) => CustomPaint(
                  size: Size(s * 2.4, s * 2.4),
                  painter: _PulsePainter(t: _c.value, color: widget.color, core: s),
                ),
              )
            : Container(
                width: s,
                height: s,
                decoration: BoxDecoration(
                  color: widget.color,
                  shape: BoxShape.circle,
                  boxShadow: [BoxShadow(color: widget.color.withValues(alpha: 0.6), blurRadius: 5)],
                ),
              ),
      ),
    );
  }
}

class _PulsePainter extends CustomPainter {
  _PulsePainter({required this.t, required this.color, required this.core});
  final double t;
  final Color color;
  final double core;

  @override
  void paint(Canvas canvas, Size size) {
    final c = size.center(Offset.zero);
    // Halo menyebar & memudar.
    final haloR = (core / 2) + (core * 1.3) * t;
    canvas.drawCircle(c, haloR, Paint()..color = color.withValues(alpha: (1 - t) * 0.35));
    // Inti + glow.
    canvas.drawCircle(
      c,
      core / 2,
      Paint()
        ..color = color
        ..maskFilter = const MaskFilter.blur(BlurStyle.normal, 1.2),
    );
    canvas.drawCircle(c, core / 2, Paint()..color = color);
  }

  @override
  bool shouldRepaint(_PulsePainter old) => old.t != t || old.color != color;
}
