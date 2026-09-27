import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:kusumavision_nms/app.dart';
import 'package:kusumavision_nms/features/map/map_providers.dart';
import 'package:kusumavision_nms/features/onus/onu_detail_screen.dart';
import 'package:kusumavision_nms/router.dart';

import 'support/fake_app.dart';

/// Tab Peta hidup di navigator cabang shell, DI BAWAH navbar melayang. Sheet yang
/// dibuka dari situ wajib memakai navigator root — kalau tidak, bagian bawahnya
/// (gaya peta, tombol Detail ONU) tertutup navbar dan ketukan jatuh ke navbar.
void main() {
  const fixtures = <String, Object>{
    '/map': {
      'data': {
        'pins': [
          {
            'id': 9, 'olt_id': 1, 'olt_name': 'OLT-A', 'slot': 1, 'port': 1, 'onu_id': 5,
            'latitude': -7.00, 'longitude': 110.40, 'customer_name': 'Bu Sri', 'online': true,
          },
        ],
        'odps': [],
        'olts': [
          {'id': 1, 'name': 'OLT-A'},
        ],
        'default_center': {'lat': -7.00, 'lng': 110.40, 'zoom': 16},
      },
    },
  };

  Future<ProviderContainer> openMap(WidgetTester t) async {
    t.view.physicalSize = const Size(1080, 2280);
    t.view.devicePixelRatio = 2.7;
    // Inset navigasi gestur seperti HP asli — offset yang salah baru kelihatan di sini.
    t.view.padding = const FakeViewPadding(bottom: 48 * 2.7);
    t.view.viewPadding = const FakeViewPadding(bottom: 48 * 2.7);
    addTearDown(t.view.reset);

    await pumpFakeApp(t, fixtures: fixtures);
    final container = ProviderScope.containerOf(t.element(find.byType(KusumaVisionApp)));
    container.read(routerProvider).go('/map');
    await settle(t);
    return container;
  }

  testWidgets('sheet Lapisan: gaya peta di dasar sheet bisa diketuk (tak tertutup navbar)', (t) async {
    final container = await openMap(t);

    await t.tap(find.byTooltip('Lapisan peta'));
    await settle(t);
    expect(find.text('Gaya peta'), findsOneWidget);

    await t.tap(find.text('OSM'));
    await t.pump();
    expect(container.read(mapTileStyleProvider), MapTileStyle.osm);
  });

  testWidgets('legenda & tombol pusatkan menempel tepat di atas navbar', (t) async {
    await openMap(t);

    final screenH = t.view.physicalSize.height / t.view.devicePixelRatio;
    final recenter = t.getRect(find.byTooltip('Pusatkan ulang'));
    final legend = t.getRect(find.text('Online'));
    // Label navbar "Akun" hanya ada di navbar (tab Peta tak punya teks itu).
    final navLabel = t.getRect(find.text('Akun'));

    for (final r in [recenter, legend]) {
      expect(r.bottom, lessThan(navLabel.top), reason: 'tak boleh tertutup navbar');
      expect(navLabel.top - r.bottom, lessThan(80), reason: 'harus menempel navbar, bukan melayang');
      expect(r.center.dy, greaterThan(screenH * 0.75), reason: 'di seperempat bawah layar');
    }
  });

  testWidgets('sheet pin ONU: tombol Detail ONU membuka layar detail', (t) async {
    await openMap(t);

    await t.tap(find.byWidgetPredicate((w) => w.runtimeType.toString() == '_OnuMarker'));
    await settle(t);
    expect(find.text('Bu Sri'), findsWidgets);

    await t.tap(find.text('Detail ONU'));
    await settle(t);
    expect(find.byType(OnuDetailScreen), findsOneWidget);
  });
}
