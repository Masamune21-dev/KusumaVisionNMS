import 'dart:convert';

import 'package:device_info_plus/device_info_plus.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_exception.dart';
import '../../core/providers.dart';
import '../../models/user.dart';

enum AuthStatus { unknown, authenticated, unauthenticated }

class AuthState {
  const AuthState({
    this.status = AuthStatus.unknown,
    this.user,
    this.token,
    this.busy = false,
    this.error,
  });

  final AuthStatus status;
  final AppUser? user;
  final String? token;
  final bool busy;
  final String? error;

  AuthState copyWith({
    AuthStatus? status,
    AppUser? user,
    String? token,
    bool? busy,
    String? error,
    bool clearError = false,
  }) =>
      AuthState(
        status: status ?? this.status,
        user: user ?? this.user,
        token: token ?? this.token,
        busy: busy ?? this.busy,
        error: clearError ? null : (error ?? this.error),
      );
}

final authControllerProvider =
    StateNotifierProvider<AuthController, AuthState>((ref) => AuthController(ref)..bootstrap());

class AuthController extends StateNotifier<AuthState> {
  AuthController(this._ref) : super(const AuthState());

  final Ref _ref;

  /// Muat token tersimpan saat aplikasi start.
  Future<void> bootstrap() async {
    final store = _ref.read(secureStoreProvider);
    final token = await store.readToken();

    if (token == null || token.isEmpty) {
      state = state.copyWith(status: AuthStatus.unauthenticated);
      return;
    }

    final userJson = await store.readUser();
    AppUser? user;
    if (userJson != null) {
      try {
        user = AppUser.fromJson(jsonDecode(userJson) as Map<String, dynamic>);
      } catch (_) {}
    }

    state = state.copyWith(status: AuthStatus.authenticated, token: token, user: user);

    // Segarkan profil di latar belakang; 401 akan memicu onUnauthorized lewat interceptor.
    try {
      final fresh = await _ref.read(nmsApiProvider).me();
      await store.writeUser(jsonEncode(fresh.toJson()));
      if (mounted) state = state.copyWith(user: fresh);
    } catch (_) {/* diamkan; sesi lokal tetap dipakai */}
  }

  Future<bool> login(String email, String password) async {
    state = state.copyWith(busy: true, clearError: true);
    try {
      final device = await _deviceName();
      final res = await _ref.read(nmsApiProvider).login(
            email: email.trim(),
            password: password,
            deviceName: device,
          );
      final store = _ref.read(secureStoreProvider);
      await store.writeToken(res.token);
      await store.writeUser(jsonEncode(res.user.toJson()));
      state = AuthState(status: AuthStatus.authenticated, token: res.token, user: res.user);
      return true;
    } on ApiException catch (e) {
      state = state.copyWith(busy: false, error: e.message);
      return false;
    } catch (e) {
      state = state.copyWith(busy: false, error: 'Gagal login: $e');
      return false;
    }
  }

  Future<void> logout() async {
    try {
      await _ref.read(nmsApiProvider).logout();
    } catch (_) {}
    await _ref.read(secureStoreProvider).clear();
    state = const AuthState(status: AuthStatus.unauthenticated);
  }

  /// Dipanggil interceptor saat menerima 401.
  void onUnauthorized() {
    _ref.read(secureStoreProvider).clear();
    if (mounted) {
      state = const AuthState(status: AuthStatus.unauthenticated, error: 'Sesi berakhir. Login kembali.');
    }
  }

  Future<String> _deviceName() async {
    try {
      final info = await DeviceInfoPlugin().androidInfo;
      return '${info.manufacturer} ${info.model}'.trim();
    } catch (_) {
      return 'Android';
    }
  }
}
