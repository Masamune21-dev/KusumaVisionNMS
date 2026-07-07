import 'package:flutter/material.dart';

import '../../theme/app_theme.dart';
import '../format.dart';

/// Teks angka yang "menghitung naik" ke nilainya saat pertama muncul, lalu
/// beranimasi mulus dari nilai lama ke baru tiap data berubah. Hormati
/// reduced-motion (langsung tampil nilai akhir).
class CountUpText extends StatelessWidget {
  const CountUpText(
    this.value, {
    super.key,
    this.style,
    this.duration = const Duration(milliseconds: 900),
    this.format,
  });

  final num value;
  final TextStyle? style;
  final Duration duration;
  final String Function(num v)? format;

  @override
  Widget build(BuildContext context) {
    final fmt = format ?? (v) => Fmt.int(v.round());
    final reduce = MediaQuery.maybeOf(context)?.disableAnimations ?? false;
    if (reduce) return Text(fmt(value), style: style);

    return TweenAnimationBuilder<double>(
      tween: Tween(begin: 0, end: value.toDouble()),
      duration: duration,
      curve: AppMotion.enter,
      builder: (_, v, __) => Text(fmt(v), style: style),
    );
  }
}
