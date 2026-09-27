import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Penyimpanan token akses & data user secara aman (Android Keystore).
class SecureStore {
  SecureStore(this._storage);

  final FlutterSecureStorage _storage;

  static const _kToken = 'api_token';
  static const _kUser = 'user_json';
  static const _kTheme = 'theme_mode';

  Future<String?> readToken() => _storage.read(key: _kToken);
  Future<void> writeToken(String token) => _storage.write(key: _kToken, value: token);

  Future<String?> readUser() => _storage.read(key: _kUser);
  Future<void> writeUser(String json) => _storage.write(key: _kUser, value: json);

  /// Pilihan tema ('system' | 'light' | 'dark'). Tidak ikut dihapus saat logout.
  Future<String?> readThemeMode() => _storage.read(key: _kTheme);
  Future<void> writeThemeMode(String mode) => _storage.write(key: _kTheme, value: mode);

  Future<void> clear() async {
    await _storage.delete(key: _kToken);
    await _storage.delete(key: _kUser);
  }
}
