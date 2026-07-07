import 'package:flutter/material.dart';
import 'package:flutter_staggered_animations/flutter_staggered_animations.dart';

import '../../theme/app_theme.dart';

/// Bungkus tiap item daftar agar masuk ber-stagger (fade + geser naik, sekali).
/// Bungkus ListView/Column induk dengan [AnimationLimiter].
///
/// Contoh:
/// ```dart
/// AnimationLimiter(
///   child: ListView.builder(
///     itemBuilder: (_, i) => staggeredItem(i, MyCard(...)),
///   ),
/// )
/// ```
Widget staggeredItem(int index, Widget child) {
  return AnimationConfiguration.staggeredList(
    position: index,
    duration: AppMotion.base,
    child: SlideAnimation(
      verticalOffset: 22,
      curve: AppMotion.enter,
      child: FadeInAnimation(curve: AppMotion.enter, child: child),
    ),
  );
}
