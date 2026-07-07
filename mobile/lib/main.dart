import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/date_symbol_data_local.dart';

import 'app.dart';
import 'core/fcm/fcm_service.dart';

Future<void> main() async {
  // Tangkap SEMUA error startup agar aplikasi tak "langsung close" tanpa jejak —
  // error ditampilkan di layar supaya bisa di-screenshot untuk diagnosa.
  runZonedGuarded(() async {
    WidgetsFlutterBinding.ensureInitialized();

    // Error saat build widget → tampilkan kotak merah terbaca, bukan layar abu.
    ErrorWidget.builder = (details) => _ErrorBox(message: details.exceptionAsString());

    try {
      await initializeDateFormatting('id');
    } catch (e, s) {
      debugPrint('initializeDateFormatting gagal: $e\n$s');
    }

    // Firebase/FCM best-effort — tak boleh menggagalkan startup.
    try {
      await FcmService.tryInitialize();
    } catch (e, s) {
      debugPrint('FCM init gagal (diabaikan): $e\n$s');
    }

    runApp(const ProviderScope(child: KusumaVisionApp()));
  }, (error, stack) {
    // Error fatal sebelum/di luar widget tree → tampilkan layar error.
    runApp(_FatalErrorApp(error: error, stack: stack));
  });
}

class _FatalErrorApp extends StatelessWidget {
  const _FatalErrorApp({required this.error, required this.stack});
  final Object error;
  final StackTrace stack;

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      debugShowCheckedModeBanner: false,
      home: _ErrorBox(message: '$error\n\n$stack'),
    );
  }
}

class _ErrorBox extends StatelessWidget {
  const _ErrorBox({required this.message});
  final String message;

  @override
  Widget build(BuildContext context) {
    return Directionality(
      textDirection: TextDirection.ltr,
      child: Container(
        color: const Color(0xFF0B1220),
        padding: const EdgeInsets.fromLTRB(20, 60, 20, 20),
        child: SingleChildScrollView(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text(
                'Aplikasi gagal start',
                style: TextStyle(color: Color(0xFFF87171), fontSize: 20, fontWeight: FontWeight.bold),
              ),
              const SizedBox(height: 12),
              SelectableText(
                message,
                style: const TextStyle(color: Color(0xFFE2E8F0), fontSize: 12, fontFamily: 'monospace'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
