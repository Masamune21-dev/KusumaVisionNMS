import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../features/auth/auth_controller.dart';
import 'api/nms_api.dart';
import 'env.dart';
import 'storage/secure_storage.dart';

/// Penyimpanan aman token/user.
final secureStoreProvider = Provider<SecureStore>(
  (ref) => SecureStore(const FlutterSecureStorage()),
);

/// Dio terkonfigurasi: base URL, header JSON, Bearer token dari AuthController,
/// dan penanganan 401 (paksa logout).
final dioProvider = Provider<Dio>((ref) {
  final dio = Dio(BaseOptions(
    baseUrl: Env.apiBaseUrl,
    connectTimeout: const Duration(seconds: 15),
    receiveTimeout: const Duration(seconds: 120), // aksi telnet sinkron (Fase 5) bisa lama
    headers: {'Accept': 'application/json'},
  ));

  dio.interceptors.add(InterceptorsWrapper(
    onRequest: (options, handler) {
      final token = ref.read(authControllerProvider).token;
      if (token != null && token.isNotEmpty) {
        options.headers['Authorization'] = 'Bearer $token';
      }
      handler.next(options);
    },
    onError: (e, handler) {
      // Token invalid/kadaluarsa → bersihkan sesi (router akan redirect ke login).
      if (e.response?.statusCode == 401) {
        ref.read(authControllerProvider.notifier).onUnauthorized();
      }
      handler.next(e);
    },
  ));

  return dio;
});

/// Repository API.
final nmsApiProvider = Provider<NmsApi>((ref) => NmsApi(ref.watch(dioProvider)));
