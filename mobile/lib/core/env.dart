/// Konfigurasi runtime yang diberikan saat build via `--dart-define`.
///
/// Contoh:
///   flutter build apk --release \
///     --dart-define=API_BASE_URL=https://nms.kusumavision.net/api/v1
class Env {
  /// Base URL REST API v1 (tanpa trailing slash).
  static const String apiBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'https://nms.kusumavision.net/api/v1',
  );
}
