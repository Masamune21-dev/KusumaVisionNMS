/// Konfigurasi runtime yang diberikan saat build via `--dart-define`.
///
/// Contoh:
///   flutter build apk --release \
///     --dart-define=API_BASE_URL=https://nms.domain-anda.com/api/v1
class Env {
  /// Base URL REST API v1 (tanpa trailing slash). SENGAJA tanpa nilai bawaan: APK yang dibangun
  /// tanpa `--dart-define=API_BASE_URL` harus berhenti dengan pesan jelas, bukan diam-diam
  /// menghubungi server NMS milik orang lain.
  static const String apiBaseUrl = String.fromEnvironment('API_BASE_URL');

  static bool get isConfigured => apiBaseUrl.trim().isNotEmpty;
}
