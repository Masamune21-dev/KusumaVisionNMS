import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Penyimpanan token akses & data user secara aman (Android Keystore).
class SecureStore {
  SecureStore(this._storage);

  final FlutterSecureStorage _storage;

  static const _kToken = 'api_token';
  static const _kUser = 'user_json';

  Future<String?> readToken() => _storage.read(key: _kToken);
  Future<void> writeToken(String token) => _storage.write(key: _kToken, value: token);

  Future<String?> readUser() => _storage.read(key: _kUser);
  Future<void> writeUser(String json) => _storage.write(key: _kUser, value: json);

  Future<void> clear() async {
    await _storage.delete(key: _kToken);
    await _storage.delete(key: _kUser);
  }
}
