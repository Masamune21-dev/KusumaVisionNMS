import 'dart:convert';
import 'dart:io';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:kusumavision_nms/app.dart';
import 'package:kusumavision_nms/core/providers.dart';
import 'package:kusumavision_nms/features/auth/auth_controller.dart';
import 'package:kusumavision_nms/models/user.dart';
import 'package:kusumavision_nms/theme/theme_controller.dart';

/// Adapter Dio yang menjawab dari [fixtures] (path → body JSON); path lain `{data: []}`.
class FakeApiAdapter implements HttpClientAdapter {
  FakeApiAdapter(this.fixtures);
  final Map<String, Object> fixtures;

  @override
  Future<ResponseBody> fetch(RequestOptions o, Stream<Uint8List>? _, Future<void>? __) async {
    return ResponseBody.fromString(jsonEncode(fixtures[o.path] ?? {'data': []}), 200, headers: {
      Headers.contentTypeHeader: ['application/json'],
    });
  }

  @override
  void close({bool force = false}) {}
}

/// Sesi login palsu (tanpa membaca secure storage / memanggil API).
class LoggedInAuth extends AuthController {
  LoggedInAuth(super.ref) {
    state = const AuthState(
      status: AuthStatus.authenticated,
      token: 't',
      user: AppUser(
          id: 1, name: 'NOC', email: 'noc@example.test', role: 'admin', roleLabel: 'Administrator',
          isAdmin: true, isDemo: false),
    );
  }

  @override
  Future<void> bootstrap() async {}
}

bool _fontsLoaded = false;

/// Muat font asli aplikasi — font uji bawaan berukuran lain sehingga tata letak
/// (mis. navbar 64 px) bisa meluap palsu.
Future<void> _loadFonts() async {
  if (_fontsLoaded) return;
  _fontsLoaded = true;
  const sdk = '/opt/flutter/bin/cache/artifacts/material_fonts';
  final fonts = {
    'Inter': ['assets/fonts/Inter.ttf'],
    'Sora': ['assets/fonts/Sora.ttf'],
    'JetBrainsMono': ['assets/fonts/JetBrainsMono.ttf'],
    'MaterialIcons': ['$sdk/MaterialIcons-Regular.otf'],
    'Roboto': ['$sdk/Roboto-Regular.ttf', '$sdk/Roboto-Medium.ttf'],
  };
  for (final e in fonts.entries) {
    final loader = FontLoader(e.key);
    for (final path in e.value) {
      final f = File(path);
      if (f.existsSync()) loader.addFont(Future.value(ByteData.view(f.readAsBytesSync().buffer)));
    }
    await loader.load();
  }
}

/// Pasang [KusumaVisionApp] utuh (router + shell navbar) di atas API palsu.
Future<void> pumpFakeApp(
  WidgetTester t, {
  Map<String, Object> fixtures = const {},
  ThemeMode theme = ThemeMode.dark,
}) async {
  await t.runAsync(_loadFonts);
  FlutterSecureStorage.setMockInitialValues({});
  // flutter_map menyimpan cache tile lewat path_provider — beri direktori sementara.
  final tmp = Directory.systemTemp.createTempSync('kv_test_');
  t.binding.defaultBinaryMessenger.setMockMethodCallHandler(
    const MethodChannel('plugins.flutter.io/path_provider'),
    (_) async => tmp.path,
  );

  final dio = Dio(BaseOptions(baseUrl: 'http://x'))..httpClientAdapter = FakeApiAdapter(fixtures);
  await t.pumpWidget(ProviderScope(
    overrides: [
      dioProvider.overrideWithValue(dio),
      authControllerProvider.overrideWith(LoggedInAuth.new),
      initialThemeModeProvider.overrideWithValue(theme),
    ],
    child: const KusumaVisionApp(),
  ));
  await settle(t);
}

/// Biarkan future palsu selesai lalu jalankan animasi (tanpa pumpAndSettle —
/// beberapa widget beranimasi terus).
Future<void> settle(WidgetTester t) async {
  for (var i = 0; i < 4; i++) {
    await t.runAsync(() => Future.delayed(const Duration(milliseconds: 60)));
    await t.pump(const Duration(milliseconds: 500));
  }
}
