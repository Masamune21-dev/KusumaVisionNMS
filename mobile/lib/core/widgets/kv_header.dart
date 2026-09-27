import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_svg/flutter_svg.dart';

import '../../theme/app_theme.dart';

/// Warna teks/ikon di atas blok header (kedua tema — header selalu gelap/berwarna).
class KvHeaderColors {
  static const fg = Colors.white;
  static const fg2 = Color(0xE6FFFFFF);
  static const fg3 = Color(0xB3FFFFFF);

  /// Latar kontrol tembus pandang (tombol ikon, chip) di atas header.
  static const control = Color(0x26FFFFFF);
  static const controlBorder = Color(0x38FFFFFF);
}

/// Latar blok header: tema terang = gradasi cyan merek; tema gelap = navy
/// berlapis (tanpa cyan menyala). Keduanya ditimpa pola serat putih tipis.
BoxDecoration _headerDecoration() => BoxDecoration(
      gradient: LinearGradient(
        begin: Alignment.topLeft,
        end: Alignment.bottomRight,
        colors: AppColors.isDark
            ? [const Color(0xFF12294A), const Color(0xFF0C1A33)]
            : [AppColors.primary, AppColors.primaryDeep],
      ),
    );

/// Blok header layar utama: menampung sapaan/judul,
/// ikut menutupi status bar, dan memaksa ikon status bar terang.
///
/// Kartu pertama di bawahnya bisa "menumpang" ke header dengan [overlap]:
/// tinggi bagian bawah header yang sengaja dibiarkan kosong, lalu isi di
/// bawahnya digeser naik sebesar itu (`Transform.translate`).
class KvHeader extends StatelessWidget {
  const KvHeader({
    super.key,
    required this.child,
    this.overlap = 0,
    this.padding = const EdgeInsets.fromLTRB(16, 8, 12, 24),
  });

  final Widget child;
  final double overlap;
  final EdgeInsetsGeometry padding;

  @override
  Widget build(BuildContext context) {
    return AnnotatedRegion<SystemUiOverlayStyle>(
      value: SystemUiOverlayStyle.light,
      child: ClipRRect(
        borderRadius: const BorderRadius.vertical(bottom: Radius.circular(26)),
        child: DecoratedBox(
          decoration: _headerDecoration(),
          child: Stack(
            children: [
              // Pola serat putih di atas warna merek — pola khusus header yang garisnya
              // hanya di sisi kanan-atas. Pola latar layar punya simpul besar di tengah
              // yang jatuh tepat di belakang angka hero dan terbaca seperti tanda "°".
              Positioned.fill(
                child: Opacity(
                  opacity: AppColors.isDark ? 0.6 : 0.45,
                  child: SvgPicture.asset(
                    'assets/illustrations/header_fiber.svg',
                    fit: BoxFit.cover,
                    alignment: Alignment.topRight,
                    colorFilter: const ColorFilter.mode(Colors.white, BlendMode.srcIn),
                    excludeFromSemantics: true,
                  ),
                ),
              ),
              SafeArea(
                bottom: false,
                child: Padding(
                  padding: padding.add(EdgeInsets.only(bottom: overlap)),
                  child: child,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Tombol ikon bulat untuk dipasang di dalam [KvHeader] (target sentuh 44 px).
class KvHeaderIconButton extends StatelessWidget {
  const KvHeaderIconButton({super.key, required this.icon, required this.tooltip, required this.onTap});

  final IconData icon;
  final String tooltip;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Tooltip(
      message: tooltip,
      child: Material(
        color: KvHeaderColors.control,
        shape: const CircleBorder(side: BorderSide(color: KvHeaderColors.controlBorder)),
        child: InkWell(
          customBorder: const CircleBorder(),
          onTap: onTap,
          child: SizedBox.square(
            dimension: 44,
            child: Icon(icon, size: 20, color: KvHeaderColors.fg),
          ),
        ),
      ),
    );
  }
}
