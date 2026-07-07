import 'package:flutter/material.dart';

import '../../theme/app_theme.dart';
import '../icons.dart';

/// Lambang menara memancar sinyal — cincin konsentris menyebar & memudar
/// mengelilingi badge kaca berisi ikon tower. Metafora OLT/FTTH memancar.
/// Reusable di Splash & Login. Hormati reduced-motion (cincin diam).
class PulseLogo extends StatefulWidget {
  const PulseLogo({super.key, this.size = 104, this.icon = LucideIcons.radioTower});

  final double size;
  final IconData icon;

  @override
  State<PulseLogo> createState() => _PulseLogoState();
}

class _PulseLogoState extends State<PulseLogo> with SingleTickerProviderStateMixin {
  late final AnimationController _c;

  @override
  void initState() {
    super.initState();
    _c = AnimationController(vsync: this, duration: const Duration(milliseconds: 2600))
      ..repeat();
  }

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final reduce = MediaQuery.maybeOf(context)?.disableAnimations ?? false;
    if (reduce && _c.isAnimating) _c.stop();
    if (!reduce && !_c.isAnimating) _c.repeat();

    final badge = widget.size * 0.44;
    return SizedBox(
      width: widget.size,
      height: widget.size,
      child: Stack(
        alignment: Alignment.center,
        children: [
          Positioned.fill(
            child: RepaintBoundary(
              child: AnimatedBuilder(
                animation: _c,
                builder: (_, __) => CustomPaint(
                  painter: _RingPainter(reduce ? 0.35 : _c.value),
                ),
              ),
            ),
          ),
          Container(
            width: badge,
            height: badge,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              gradient: LinearGradient(
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
                colors: [
                  AppColors.primary.withValues(alpha: 0.22),
                  AppColors.secondary.withValues(alpha: 0.10),
                ],
              ),
              border: Border.all(color: AppColors.primary.withValues(alpha: 0.5), width: 1.2),
              boxShadow: AppShadow.glow(AppColors.primary, alpha: 0.4, blur: 26),
            ),
            child: Icon(widget.icon, color: AppColors.primary, size: badge * 0.5),
          ),
        ],
      ),
    );
  }
}

class _RingPainter extends CustomPainter {
  _RingPainter(this.t);
  final double t;

  @override
  void paint(Canvas canvas, Size size) {
    final center = size.center(Offset.zero);
    final minR = size.width * 0.24;
    final maxR = size.width * 0.5;
    for (var k = 0; k < 3; k++) {
      final p = (t + k / 3) % 1.0;
      final r = minR + (maxR - minR) * p;
      final alpha = (1 - p) * 0.45;
      canvas.drawCircle(
        center,
        r,
        Paint()
          ..style = PaintingStyle.stroke
          ..strokeWidth = 1.4
          ..color = AppColors.primary.withValues(alpha: alpha),
      );
    }
  }

  @override
  bool shouldRepaint(_RingPainter old) => old.t != t;
}
