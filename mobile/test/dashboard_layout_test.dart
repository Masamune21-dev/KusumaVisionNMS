import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/fake_app.dart';

/// Dashboard hidup di shell ber-`extendBody`: navbar melayang MENUTUPI isi. Jarak
/// bawah daftar wajib memuat tinggi navbar, kalau tidak kartu terakhir tak bisa
/// digulir keluar dari balik navbar.
void main() {
  testWidgets('kartu terakhir Dashboard bisa digulir keluar dari balik navbar', (t) async {
    // HP pendek (±630 dp) supaya isi Dashboard pasti lebih tinggi dari layar.
    t.view.physicalSize = const Size(1080, 1700);
    t.view.devicePixelRatio = 2.7;
    t.view.padding = const FakeViewPadding(top: 30 * 2.7, bottom: 48 * 2.7);
    t.view.viewPadding = const FakeViewPadding(top: 30 * 2.7, bottom: 48 * 2.7);
    addTearDown(t.view.reset);

    await pumpFakeApp(t, fixtures: {
      '/summary': {
        'data': {
          'olt': {'total': 3, 'online': 3, 'offline': 0},
          'onu': {'total': 100, 'online': 90, 'offline': 10, 'warning': 2},
          'online_share': 90,
          'alarms': {'total': 5, 'critical': 1, 'major': 2, 'minor': 1, 'warning': 1},
        }
      },
      '/olts': {
        'data': [
          for (var i = 1; i <= 5; i++)
            {'id': i, 'name': 'OLT-$i', 'reachable': true, 'onu_total': 50, 'onu_online': 50 - i, 'onu_offline': i},
        ]
      },
    });

    // Gulir sampai mentok.
    await t.drag(find.byType(ListView).first, const Offset(0, -3000));
    await settle(t);

    // Teks terakhir Dashboard = catatan "+N OLT lain" di bawah daftar OLT (5 OLT
    // bermasalah, 4 ditampilkan); "Akun" = label navbar.
    final lastLabel = t.getRect(find.text('+1 OLT lain dengan ONU offline'));
    final navLabel = t.getRect(find.text('Akun'));
    expect(lastLabel.bottom, lessThan(navLabel.top - 30));
  });
}
