import 'dart:io';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:kusumavision_nms/core/widgets/nms_logo.dart';

/// Merender ikon launcher & ikon notifikasi dari painter logo yang sama dengan
/// aplikasi:
///   RENDER_ICONS=1 flutter test test/tools/render_icons_test.dart
///   dart run flutter_launcher_icons
/// Dilewati pada `flutter test` biasa.
void main() {
  final enabled = Platform.environment['RENDER_ICONS'] == '1';

  Future<void> render(WidgetTester t, Widget child, String path, double size) async {
    t.view.physicalSize = Size(size + 200, size + 200);
    t.view.devicePixelRatio = 1;
    addTearDown(t.view.reset);
    final key = GlobalKey();
    await t.pumpWidget(Directionality(
      textDirection: TextDirection.ltr,
      child: Center(child: RepaintBoundary(key: key, child: SizedBox.square(dimension: size, child: child))),
    ));
    await t.pump();
    await t.runAsync(() async {
      final boundary = key.currentContext!.findRenderObject()! as RenderRepaintBoundary;
      final image = await boundary.toImage(pixelRatio: 1);
      final bytes = await image.toByteData(format: ui.ImageByteFormat.png);
      File(path)
        ..parent.createSync(recursive: true)
        ..writeAsBytesSync(bytes!.buffer.asUint8List());
    });
  }

  const gradient = BoxDecoration(
    gradient: LinearGradient(
      begin: Alignment.topLeft,
      end: Alignment.bottomRight,
      colors: [NmsBrand.cyanLight, NmsBrand.cyan, NmsBrand.cyanDeep],
      stops: [0, 0.45, 1],
    ),
  );

  testWidgets('icon.png — ikon lama (pra-Android 8): kotak membulat cyan + logo putih', (t) async {
    await render(t, const NmsAppIcon(size: 1024, radiusFactor: 0.22), 'assets/icon/icon.png', 1024);
  }, skip: !enabled);

  testWidgets('icon_background.png — latar adaptif penuh (gradien cyan)', (t) async {
    await render(t, const DecoratedBox(decoration: gradient), 'assets/icon/icon_background.png', 1024);
  }, skip: !enabled);

  testWidgets('icon_foreground.png — logo putih di zona aman adaptif (66%)', (t) async {
    await render(t, const Center(child: NmsLogo(size: 1024 * 0.46)), 'assets/icon/icon_foreground.png', 1024);
  }, skip: !enabled);

  testWidgets('icon_monochrome.png — ikon bertema Android 13+ (siluet)', (t) async {
    await render(t, const Center(child: NmsLogo(size: 1024 * 0.46)), 'assets/icon/icon_monochrome.png', 1024);
  }, skip: !enabled);

  // Ikon notifikasi: WAJIB siluet putih di atas transparan — Android 5+ membuang
  // semua warna ikon kecil notifikasi, jadi ikon launcher berwarna tampil kotak putih.
  // 24 dp dengan bantalan ±2 dp (logo 84% kanvas).
  for (final (dir, px) in [('mdpi', 24.0), ('hdpi', 36.0), ('xhdpi', 48.0), ('xxhdpi', 72.0), ('xxxhdpi', 96.0)]) {
    testWidgets('ic_notification $dir ($px px)', (t) async {
      await render(
        t,
        Center(child: NmsLogo(size: px * 0.84)),
        'android/app/src/main/res/drawable-$dir/ic_notification.png',
        px,
      );
    }, skip: !enabled);
  }
}
