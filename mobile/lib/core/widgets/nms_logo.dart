import 'package:flutter/material.dart';

/// Logomark KusumaVision NMS: huruf «K» daun (tiga sapuan berbentuk daun) yang ujung-ujungnya
/// menjadi **simpul jaringan**: batang = feeder dari OLT, dua lengan = serat
/// distribusi, titik di tiap ujung = ODP/ONU. Satu painter untuk logo di aplikasi,
/// ikon launcher, dan ikon notifikasi (dirender oleh `test/tools/render_icons_test.dart`).
class NmsLogoPainter extends CustomPainter {
  const NmsLogoPainter({this.color = Colors.white, this.nodes = true});

  final Color color;

  /// Gambar titik simpul di ujung daun. Dimatikan untuk ukuran sangat kecil.
  final bool nodes;

  /// Geometri daun «K» dalam ruang 0..1.
  static List<Path> leaves(Size s) {
    Offset p(double x, double y) => Offset(x * s.width, y * s.height);
    Path leaf(Offset a, Offset c1, Offset c2, Offset b, Offset c3, Offset c4) => Path()
      ..moveTo(a.dx, a.dy)
      ..cubicTo(c1.dx, c1.dy, c2.dx, c2.dy, b.dx, b.dy)
      ..cubicTo(c3.dx, c3.dy, c4.dx, c4.dy, a.dx, a.dy)
      ..close();

    return [
      leaf(p(0.34, 0.06), p(0.17, 0.30), p(0.16, 0.70), p(0.27, 0.94), p(0.40, 0.72), p(0.44, 0.34)),
      leaf(p(0.35, 0.52), p(0.36, 0.26), p(0.58, 0.08), p(0.92, 0.07), p(0.74, 0.28), p(0.52, 0.46)),
      leaf(p(0.35, 0.50), p(0.37, 0.72), p(0.57, 0.88), p(0.86, 0.92), p(0.72, 0.76), p(0.52, 0.60)),
    ];
  }

  /// Simpul di ujung kedua lengan (ODP/ONU) — sedikit keluar dari ujung daun
  /// supaya terbaca sebagai titik tersendiri, bukan ujung yang menebal.
  static const nodeCenters = [Offset(0.93, 0.06), Offset(0.875, 0.93)];
  static const nodeRadius = 0.075;

  /// Geser optis ke kiri: simpul di ujung lengan membuat massa logo condong ke
  /// kanan (kotak batasnya x 0,16..1,0) — tanpa ini ikon terlihat tidak di tengah.
  static const opticalShift = -0.07;

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = color
      ..isAntiAlias = true;
    canvas.translate(opticalShift * size.width, 0);
    for (final leaf in leaves(size)) {
      canvas.drawPath(leaf, paint);
    }
    if (nodes) {
      for (final c in nodeCenters) {
        canvas.drawCircle(Offset(c.dx * size.width, c.dy * size.height), nodeRadius * size.width, paint);
      }
    }
  }

  @override
  bool shouldRepaint(NmsLogoPainter old) => old.color != color || old.nodes != nodes;
}

/// Logomark polos (tanpa latar).
class NmsLogo extends StatelessWidget {
  const NmsLogo({super.key, this.size = 48, this.color = Colors.white, this.nodes = true});

  final double size;
  final Color color;
  final bool nodes;

  @override
  Widget build(BuildContext context) =>
      SizedBox.square(dimension: size, child: CustomPaint(painter: NmsLogoPainter(color: color, nodes: nodes)));
}

/// Warna merek ikon (tetap, tidak ikut tema): cyan KusumaVision NMS.
class NmsBrand {
  static const cyanLight = Color(0xFF22D3EE); // cyan-400
  static const cyan = Color(0xFF0891B2); // cyan-600
  static const cyanDeep = Color(0xFF155E75); // cyan-800
}

/// Ikon aplikasi: kotak membulat bergradien cyan + logomark putih (≈58%).
/// Inilah yang dirender menjadi `assets/icon/icon.png`.
class NmsAppIcon extends StatelessWidget {
  const NmsAppIcon({super.key, this.size = 64, this.radiusFactor = 0.24});

  final double size;
  final double radiusFactor;

  @override
  Widget build(BuildContext context) => Container(
        width: size,
        height: size,
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(size * radiusFactor),
          gradient: const LinearGradient(
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
            colors: [NmsBrand.cyanLight, NmsBrand.cyan, NmsBrand.cyanDeep],
            stops: [0, 0.45, 1],
          ),
        ),
        alignment: Alignment.center,
        child: NmsLogo(size: size * 0.56),
      );
}
