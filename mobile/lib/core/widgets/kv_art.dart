import 'package:flutter/material.dart';
import 'package:flutter_svg/flutter_svg.dart';

import '../../theme/app_theme.dart';

/// Ilustrasi aplikasi (SVG buatan sendiri di `assets/illustrations/`, bertema
/// jaringan fiber OLT → ODP → ONU; bebas lisensi pihak ketiga).
enum KvArt {
  /// Data belum ada — kotak ODP terbuka dan kosong.
  empty('state_empty'),

  /// Gagal memuat — kabel fiber putus.
  error('state_error'),

  /// Pencarian/penyaringan tanpa hasil — kaca pembesar di atas jaringan.
  search('state_search'),

  /// Semuanya beres (tak ada alarm / tak ada ONU menunggu) — perisai hijau.
  clear('state_clear'),

  /// Ilustrasi pembuka layar masuk: OLT → splitter → rumah pelanggan.
  loginHero('login_hero'),

  /// Perangkat ONU tampak depan (lampunya ditumpuk aplikasi — lihat OnuDeviceArt).
  onuDevice('onu_device');

  const KvArt(this.file);
  final String file;

  String get asset => 'assets/illustrations/$file.svg';
}

/// Satu ilustrasi [KvArt] berukuran lebar [width] (tinggi mengikuti rasio SVG).
class KvIllustration extends StatelessWidget {
  const KvIllustration(this.art, {super.key, this.width = 200, this.semanticLabel});

  final KvArt art;
  final double width;
  final String? semanticLabel;

  @override
  Widget build(BuildContext context) {
    return SvgPicture.asset(
      art.asset,
      width: width,
      colorMapper: KvArtColors.forTheme(),
      semanticsLabel: semanticLabel,
      excludeFromSemantics: semanticLabel == null,
    );
  }
}

/// Aset SVG digambar dengan palet gelap. Di tema terang tiap warnanya ditukar
/// ke padanan terang (opasitas tetap) saat SVG diurai — satu berkas untuk dua tema.
class KvArtColors extends ColorMapper {
  const KvArtColors._();

  static const _light = KvArtColors._();

  /// null di tema gelap (warna asli berkas).
  static ColorMapper? forTheme() => AppColors.isDark ? null : _light;

  static const _map = <int, int>{
    0xFF17233C: 0xFFFFFFFF, // permukaan objek → putih
    0xFF0C1524: 0xFFEEF2F7, // isian dalam → surface-alt
    0xFF1B2A46: 0xFFE2E8F0, // tutup/node → slate-200
    0xFF2A3B5C: 0xFF94A3B8, // garis tepi → slate-400
    0xFF22D3EE: 0xFF0891B2, // cyan-400 → cyan-600
    0xFF38BDF8: 0xFF0284C7, // sky-400 → sky-600
    0xFF0E7490: 0xFF155E75,
    0xFFE8EEF7: 0xFF0F172A, // detail terang → tinta gelap
    0xFF34D399: 0xFF059669, // emerald
    0xFFFB7185: 0xFFE11D48, // rose
  };

  @override
  Color substitute(String? id, String elementName, String attributeName, Color color) {
    final mapped = _map[color.withValues(alpha: 1).toARGB32()];
    return mapped == null ? color : Color(mapped).withValues(alpha: color.a);
  }
}

/// Latar layar yang **statis** (pengganti latar aurora beranimasi): warna dasar
/// polos, cahaya lembut di pojok kanan-atas, dan pola
/// serat optik tipis di bagian atas yang memudar ke bawah.
///
/// Semuanya digambar sekali di dalam [RepaintBoundary] — tidak ada animasi,
/// jadi tidak ada biaya per frame saat daftar digulir.
class KvBackdrop extends StatelessWidget {
  const KvBackdrop({super.key, required this.child, this.intensity = 1.0});

  final Widget child;

  /// Pengali opasitas cahaya & pola (0..1). Turunkan di layar padat data.
  final double intensity;

  static const _patternHeight = 260.0;

  @override
  Widget build(BuildContext context) {
    final k = intensity.clamp(0.0, 1.0);

    return Stack(
      children: [
        Positioned.fill(
          child: RepaintBoundary(
            child: Stack(
              children: [
                Positioned.fill(child: ColoredBox(color: AppColors.bg)),
                Positioned.fill(
                  child: DecoratedBox(
                    decoration: BoxDecoration(
                      gradient: RadialGradient(
                        center: const Alignment(0.9, -1.05),
                        radius: 1.1,
                        colors: [
                          AppColors.primary.withValues(alpha: 0.13 * k),
                          AppColors.secondary.withValues(alpha: 0.04 * k),
                          AppColors.bg.withValues(alpha: 0),
                        ],
                        stops: const [0, 0.45, 1],
                      ),
                    ),
                  ),
                ),
                Positioned(
                  top: 0,
                  left: 0,
                  right: 0,
                  height: _patternHeight,
                  child: Opacity(
                    opacity: 0.55 * k,
                    // Pola memudar ke bawah supaya tak bertabrakan dengan isi kartu.
                    child: ShaderMask(
                      blendMode: BlendMode.dstIn,
                      shaderCallback: (rect) => const LinearGradient(
                        begin: Alignment.topCenter,
                        end: Alignment.bottomCenter,
                        colors: [Colors.white, Colors.transparent],
                        stops: [0.35, 1],
                      ).createShader(rect),
                      child: SvgPicture.asset(
                        'assets/illustrations/backdrop_fiber.svg',
                        colorMapper: KvArtColors.forTheme(),
                        fit: BoxFit.cover,
                        alignment: Alignment.topCenter,
                        excludeFromSemantics: true,
                      ),
                    ),
                  ),
                ),
              ],
            ),
          ),
        ),
        child,
      ],
    );
  }
}
