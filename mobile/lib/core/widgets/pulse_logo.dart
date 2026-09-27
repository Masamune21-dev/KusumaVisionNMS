import 'package:flutter/material.dart';

import '../../theme/app_theme.dart';
import 'nms_logo.dart';

/// Ikon aplikasi (logomark NMS di kotak cyan — sama dengan ikon launcher) yang
/// dikelilingi cincin konsentris menyebar & memudar: sinyal yang dipancarkan.
/// Reusable di Splash & Login. Hormati reduced-motion (cincin diam).
class PulseLogo extends StatefulWidget {
  const PulseLogo({super.key, this.size = 104});

  final double size;

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

    final badge = widget.size * 0.5;
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
          DecoratedBox(
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(badge * 0.26),
              boxShadow: AppShadow.glow(NmsBrand.cyanLight, alpha: 0.4, blur: 26),
            ),
            child: NmsAppIcon(size: badge, radiusFactor: 0.26),
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
