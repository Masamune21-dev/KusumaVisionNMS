import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:kusumavision_nms/core/onu_status.dart';
import 'package:kusumavision_nms/features/onus/onu_detail_parts.dart';
import 'package:kusumavision_nms/models/onu.dart';
import 'package:kusumavision_nms/theme/app_theme.dart';

/// Hero detail ONU dulu tampak "kadang di tengah, kadang rata kiri": isinya di
/// dalam Stack longgar sehingga kolom menyusut selebar nama. Nama pendek = semua
/// menempel kiri. Tengah perangkat & nama harus = tengah kartu, apa pun panjang namanya.
void main() {
  for (final name in ['PELANGGAN', 'Gudang Contoh (ODP CONTOH 2) Blok Timur Dekat Balai Desa']) {
    testWidgets('hero di tengah kartu — nama "${name.substring(0, 7)}…"', (t) async {
      t.view.physicalSize = const Size(1080, 2280);
      t.view.devicePixelRatio = 2.7;
      addTearDown(t.view.reset);

      final onu = Onu.fromJson({
        'olt_id': 1, 'slot': 1, 'port': 2, 'onu_id': 7, 'online': true,
        'name': name, 'interface': 'gpon-onu_1/1/2:7', 'rx_power_dbm': -20.0,
      });
      await t.pumpWidget(MaterialApp(
        theme: AppTheme.dark(),
        home: Scaffold(
          body: ListView(padding: const EdgeInsets.all(16), children: [
            OnuHero(onu: onu, status: OnuStatus.of(onu)),
          ]),
        ),
      ));

      final card = t.getRect(find.byType(OnuHero));
      final art = t.getRect(find.byType(OnuDeviceArt));
      final title = t.getRect(find.text(name));
      expect(art.center.dx, moreOrLessEquals(card.center.dx, epsilon: 1));
      expect(title.center.dx, moreOrLessEquals(card.center.dx, epsilon: 1));

      // Lepas pohon (lampu berdenyut memakai animasi berulang).
      await t.pumpWidget(const SizedBox.shrink());
      await t.pump(const Duration(seconds: 2));
    });
  }
}
