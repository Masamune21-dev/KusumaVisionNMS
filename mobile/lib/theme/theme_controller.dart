import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../core/providers.dart';
import '../core/storage/secure_storage.dart';

/// Mode tema tersimpan → [ThemeMode]; nilai tak dikenal = ikuti sistem.
ThemeMode themeModeFromName(String? name) => switch (name) {
      'light' => ThemeMode.light,
      'dark' => ThemeMode.dark,
      _ => ThemeMode.system,
    };

String themeModeName(ThemeMode mode) => switch (mode) {
      ThemeMode.light => 'light',
      ThemeMode.dark => 'dark',
      ThemeMode.system => 'system',
    };

/// Mode tema awal, dibaca dari penyimpanan di `main()` sebelum `runApp` supaya
/// layar pertama langsung bertema benar (tanpa kedip gelap → terang).
final initialThemeModeProvider = Provider<ThemeMode>((ref) => ThemeMode.system);

/// Pilihan tema pengguna: Ikuti sistem / Terang / Gelap.
final themeModeProvider = StateNotifierProvider<ThemeController, ThemeMode>(
  (ref) => ThemeController(ref.watch(secureStoreProvider), ref.watch(initialThemeModeProvider)),
);

class ThemeController extends StateNotifier<ThemeMode> {
  ThemeController(this._store, ThemeMode initial) : super(initial);

  final SecureStore _store;

  Future<void> set(ThemeMode mode) async {
    if (mode == state) return;
    state = mode;
    await _store.writeThemeMode(themeModeName(mode));
  }

  /// Dibaca sekali saat startup; gagal baca (Keystore bermasalah) = ikuti sistem.
  static Future<ThemeMode> load() async {
    try {
      return themeModeFromName(await SecureStore(kSecureStorage).readThemeMode());
    } catch (_) {
      return ThemeMode.system;
    }
  }
}
