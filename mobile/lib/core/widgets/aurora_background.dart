import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../../theme/app_theme.dart';

/// Latar hidup bertema jaringan FTTH: aurora mesh gradient (cyan/biru/indigo)
/// yang bergerak pelan + jala **node-fiber** tipis sebagai metafora GPON.
///
/// Di-hand-roll (CustomPainter + 1 AnimationController) demi kendali penuh atas
/// performa: `RepaintBoundary`, jumlah node kecil, dan **hormati reduced-motion**
/// (freeze + latar statis). Tidak memakai BackdropFilter → aman untuk layar apa
/// pun. Pakai sebagai lapisan paling belakang Scaffold (biasanya via Stack).
class AuroraBackground extends StatefulWidget {
  const AuroraBackground({
    super.key,
    required this.child,
    this.particles = true,
    this.intensity = 1.0,
    this.animate = true,
  });

  final Widget child;

  /// Tampilkan jala node-fiber (matikan di layar dengan daftar sangat panjang).
  final bool particles;

  /// Pengali opasitas aurora (0..1). Turunkan bila konten butuh kontras lebih.
  final double intensity;

  /// Gerakkan aurora. Set false untuk latar statis (daftar sangat panjang) —
  /// aurora tetap tampil tapi tak repaint tiap frame.
  final bool animate;

  @override
  State<AuroraBackground> createState() => _AuroraBackgroundState();
}

class _AuroraBackgroundState extends State<AuroraBackground>
    with SingleTickerProviderStateMixin {
  late final AnimationController _c;
  late final List<_Node> _nodes;

  @override
  void initState() {
    super.initState();
    _c = AnimationController(vsync: this, duration: const Duration(seconds: 22))
      ..repeat();
    final rnd = math.Random(7);
    _nodes = List.generate(30, (_) {
      return _Node(
        base: Offset(rnd.nextDouble(), rnd.nextDouble()),
        phase: rnd.nextDouble() * math.pi * 2,
        speed: 0.15 + rnd.nextDouble() * 0.4,
        amp: 0.012 + rnd.nextDouble() * 0.03,
      );
    });
  }

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final reduce = MediaQuery.maybeOf(context)?.disableAnimations ?? false;
    final active = widget.animate && !reduce;
    if (active && !_c.isAnimating) {
      _c.repeat();
    } else if (!active && _c.isAnimating) {
      _c.stop();
    }

    return Stack(
      children: [
        Positioned.fill(
          child: RepaintBoundary(
            child: AnimatedBuilder(
              animation: _c,
              // t dikuantisasi ke 1/400 siklus (22 dtk → ~18 repaint/dtk):
              // shouldRepaint false di mayoritas vsync frame, gerakan sepelan
              // ini tak terlihat bedanya — hemat besar di GPU (Mali/Xclipse).
              builder: (context, _) => CustomPaint(
                painter: _AuroraPainter(
                  t: active ? (_c.value * 400).round() / 400 : 0.12,
                  intensity: widget.intensity,
                  nodes: widget.particles ? _nodes : const [],
                ),
              ),
            ),
          ),
        ),
        widget.child,
      ],
    );
  }
}

class _Node {
  _Node({required this.base, required this.phase, required this.speed, required this.amp});
  final Offset base; // posisi dasar dalam ruang 0..1
  final double phase, speed, amp;

  Offset at(double t) => Offset(
        base.dx + math.sin(t * math.pi * 2 * speed + phase) * amp,
        base.dy + math.cos(t * math.pi * 2 * speed * 0.8 + phase) * amp,
      );
}

class _AuroraPainter extends CustomPainter {
  _AuroraPainter({required this.t, required this.intensity, required this.nodes});

  final double t;
  final double intensity;
  final List<_Node> nodes;

  @override
  void paint(Canvas canvas, Size size) {
    final rect = Offset.zero & size;

    // Dasar OLED navy.
    canvas.drawRect(rect, Paint()..color = AppColors.bg);

    // Tiga blob aurora bergerak (Lissajous), blend screen (glow). Kelembutan
    // tepi dari stop tengah RadialGradient — TANPA MaskFilter.blur: blur
    // gaussian bersigma ~17% layar tiap frame terbukti mencekik GPU Mali/
    // Xclipse (Impeller) di banyak HP, sedangkan gradien murni nyaris gratis.
    for (var i = 0; i < AppGradient.aurora.length; i++) {
      final color = AppGradient.aurora[i];
      final a = (t * math.pi * 2) + i * 2.1;
      final center = Offset(
        size.width * (0.5 + 0.34 * math.sin(a + i)),
        size.height * (0.32 + 0.30 * math.cos(a * 0.7 + i * 1.3)),
      );
      final radius = size.shortestSide * (0.55 + 0.08 * math.sin(a * 1.3));
      final paint = Paint()
        ..blendMode = BlendMode.screen
        ..shader = RadialGradient(
          colors: [
            color.withValues(alpha: 0.32 * intensity),
            color.withValues(alpha: 0.14 * intensity),
            color.withValues(alpha: 0.0),
          ],
          stops: const [0.0, 0.55, 1.0],
        ).createShader(Rect.fromCircle(center: center, radius: radius));
      canvas.drawCircle(center, radius, paint);
    }

    // Vignette lembut supaya tepi lebih dalam (fokus ke tengah).
    canvas.drawRect(
      rect,
      Paint()
        ..shader = RadialGradient(
          radius: 1.1,
          colors: [Colors.transparent, AppColors.bg.withValues(alpha: 0.55)],
          stops: const [0.62, 1.0],
        ).createShader(rect),
    );

    if (nodes.isEmpty) return;

    // Jala node-fiber: hitung posisi piksel, sambungkan yang berdekatan.
    final pts = [for (final n in nodes) _toPx(n.at(t), size)];
    final linkDist = size.shortestSide * 0.26;
    final linePaint = Paint()
      ..strokeWidth = 0.8
      ..style = PaintingStyle.stroke;
    for (var i = 0; i < pts.length; i++) {
      for (var j = i + 1; j < pts.length; j++) {
        final d = (pts[i] - pts[j]).distance;
        if (d < linkDist) {
          final f = 1 - (d / linkDist); // makin dekat makin jelas
          linePaint.color = AppColors.primary.withValues(alpha: 0.10 * f);
          canvas.drawLine(pts[i], pts[j], linePaint);
        }
      }
    }
    final dotGlow = Paint()..maskFilter = const MaskFilter.blur(BlurStyle.normal, 2.2);
    for (final p in pts) {
      dotGlow.color = AppColors.primary.withValues(alpha: 0.55);
      canvas.drawCircle(p, 1.6, dotGlow);
    }
  }

  Offset _toPx(Offset unit, Size size) => Offset(unit.dx * size.width, unit.dy * size.height);

  @override
  bool shouldRepaint(_AuroraPainter old) =>
      old.t != t || old.intensity != intensity || old.nodes != nodes;
}
