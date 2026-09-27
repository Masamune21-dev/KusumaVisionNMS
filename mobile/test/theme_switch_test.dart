import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:kusumavision_nms/app.dart';
import 'package:kusumavision_nms/core/widgets/kv_art.dart';
import 'package:kusumavision_nms/theme/app_theme.dart';
import 'package:kusumavision_nms/theme/theme_controller.dart';

import 'support/fake_app.dart';

/// Warna latar yang dipasang [KvBackdrop] di layar aktif.
Color _backdropColor(WidgetTester t) => t
    .widget<ColoredBox>(find.descendant(of: find.byType(KvBackdrop), matching: find.byType(ColoredBox)).first)
    .color;

void main() {
  testWidgets('ganti tema saat berjalan: warna ikut berganti, state layar tetap', (t) async {
    await pumpFakeApp(t, fixtures: {
      '/summary': {
        'data': {
          'olt': {'total': 2, 'online': 2, 'offline': 0},
          'onu': {'total': 10, 'online': 9, 'offline': 1, 'warning': 0},
          'online_share': 90,
          'alarms': {'total': 0},
        }
      },
    });

    expect(AppColors.isDark, isTrue);
    expect(_backdropColor(t), AppPalette.dark.bg);
    final scrollBefore = t.state<ScrollableState>(find.byType(Scrollable).first);

    final container = ProviderScope.containerOf(t.element(find.byType(KusumaVisionApp)));
    await t.runAsync(() => container.read(themeModeProvider.notifier).set(ThemeMode.light));
    await t.pump();

    expect(AppColors.isDark, isFalse);
    // Widget yang membaca AppColors langsung (bukan lewat Theme) ikut terbangun ulang.
    expect(_backdropColor(t), AppPalette.light.bg);
    // Pohon tidak dipasang ulang — state gulir dsb. tetap objek yang sama.
    expect(identical(t.state<ScrollableState>(find.byType(Scrollable).first), scrollBefore), isTrue);

    // Pilihan tersimpan untuk dibuka lagi berikutnya.
    expect(await t.runAsync(() => const FlutterSecureStorage().read(key: 'theme_mode')), 'light');

    await t.runAsync(() => container.read(themeModeProvider.notifier).set(ThemeMode.dark));
    await t.pump();
    expect(_backdropColor(t), AppPalette.dark.bg);
    await t.pump(const Duration(seconds: 3));
  });
}
