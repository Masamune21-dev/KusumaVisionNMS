import 'package:dio/dio.dart';

import '../../models/alarm.dart';
import '../../models/olt.dart';
import '../../models/onu.dart';
import '../../models/search_result.dart';
import '../../models/summary.dart';
import '../../models/user.dart';
import 'api_exception.dart';

/// Repository REST API v1 — semua panggilan HTTP terpusat di sini dan
/// mengembalikan model, bukan Map mentah. Error dibungkus [ApiException].
class NmsApi {
  NmsApi(this._dio);

  final Dio _dio;

  Future<T> _run<T>(Future<Response> Function() call, T Function(dynamic data) map) async {
    try {
      final res = await call();
      return map(res.data);
    } on DioException catch (e) {
      throw ApiException.fromDio(e);
    }
  }

  // ---- Auth ----------------------------------------------------------------

  /// Login → kembalikan (token, user).
  Future<({String token, AppUser user})> login({
    required String email,
    required String password,
    required String deviceName,
  }) {
    return _run(
      () => _dio.post('/auth/login', data: {
        'email': email,
        'password': password,
        'device_name': deviceName,
      }),
      (data) {
        final d = data['data'] as Map<String, dynamic>;
        return (
          token: d['token'] as String,
          user: AppUser.fromJson(d['user'] as Map<String, dynamic>),
        );
      },
    );
  }

  Future<AppUser> me() =>
      _run(() => _dio.get('/me'), (d) => AppUser.fromJson(d['data'] as Map<String, dynamic>));

  Future<void> logout() => _run(() => _dio.post('/auth/logout'), (_) {});

  // ---- Perangkat FCM -------------------------------------------------------

  Future<void> registerDevice(String token, {String? deviceName}) => _run(
        () => _dio.post('/devices', data: {
          'token': token,
          if (deviceName != null) 'device_name': deviceName,
          'platform': 'android',
        }),
        (_) {},
      );

  Future<void> deleteDevice(String token) => _run(
        () => _dio.delete('/devices', data: {'token': token}),
        (_) {},
      );

  /// Kirim notifikasi tes ke perangkat user saat ini → {ok, message}.
  Future<({bool ok, String message})> testPush() => _run(
        () => _dio.post('/devices/test'),
        (d) => (
          ok: (d['data']?['ok'] ?? false) as bool,
          message: (d['data']?['message'] ?? '') as String,
        ),
      );

  // ---- Read ----------------------------------------------------------------

  Future<DashboardSummary> summary() => _run(
        () => _dio.get('/summary'),
        (d) => DashboardSummary.fromJson(d['data'] as Map<String, dynamic>),
      );

  Future<List<OltSummary>> olts() => _run(
        () => _dio.get('/olts'),
        (d) => ((d['data'] ?? []) as List)
            .map((e) => OltSummary.fromJson(e as Map<String, dynamic>))
            .toList(),
      );

  Future<OltDetail> olt(int id) => _run(
        () => _dio.get('/olts/$id'),
        (d) => OltDetail.fromJson(d['data'] as Map<String, dynamic>),
      );

  Future<({List<Onu> onus, String? refreshedAt})> portOnus(int oltId, int slot, int port) => _run(
        () => _dio.get('/olts/$oltId/ports/$slot/$port/onus'),
        (d) => (
          onus: ((d['data'] ?? []) as List)
              .map((e) => Onu.fromJson(e as Map<String, dynamic>))
              .toList(),
          refreshedAt: (d['meta']?['refreshed_at']) as String?,
        ),
      );

  Future<Onu> onu(int oltId, int slot, int port, int onuId) => _run(
        () => _dio.get('/olts/$oltId/onus/$slot/$port/$onuId'),
        (d) => Onu.fromJson(d['data'] as Map<String, dynamic>),
      );

  Future<({List<Map<String, dynamic>> onus, bool ok, String? refreshedAt})> unconfigured(int oltId) =>
      _run(
        () => _dio.get('/olts/$oltId/unconfigured'),
        (d) => (
          onus: ((d['data'] ?? []) as List).cast<Map<String, dynamic>>(),
          ok: (d['meta']?['ok'] ?? false) as bool,
          refreshedAt: (d['meta']?['refreshed_at']) as String?,
        ),
      );

  Future<List<SearchResult>> search(String q) => _run(
        () => _dio.get('/search', queryParameters: {'q': q}),
        (d) => ((d['data'] ?? []) as List)
            .map((e) => SearchResult.fromJson(e as Map<String, dynamic>))
            .toList(),
      );

  Future<({List<Alarm> alarms, int total})> alarms({String status = 'active', String? severity}) => _run(
        () => _dio.get('/alarms', queryParameters: {
          'status': status,
          if (severity != null) 'severity': severity,
          'per_page': 100,
        }),
        (d) => (
          alarms: ((d['data'] ?? []) as List)
              .map((e) => Alarm.fromJson(e as Map<String, dynamic>))
              .toList(),
          total: (d['meta']?['total'] ?? 0) as int,
        ),
      );

  // ---- Register options + aksi tulis ---------------------------------------

  Future<Map<String, dynamic>> registerOptions(int oltId, {int? slot, int? port, String? sn}) => _run(
        () => _dio.get('/olts/$oltId/register/options', queryParameters: {
          if (slot != null) 'slot': slot,
          if (port != null) 'port': port,
          if (sn != null) 'sn': sn,
        }),
        (d) => d['data'] as Map<String, dynamic>,
      );

  /// Preview script CLI dari form registrasi (tanpa menyentuh OLT).
  Future<String> registerPreview(int oltId, Map<String, dynamic> form) => _run(
        () => _dio.post('/olts/$oltId/register/preview', data: form),
        (d) => (d['data']?['script'] ?? '') as String,
      );

  /// Simpan audit & (bila execute) eksekusi registrasi ke OLT.
  Future<Map<String, dynamic>> register(int oltId, Map<String, dynamic> form, {required bool execute}) => _run(
        () => _dio.post('/olts/$oltId/register', data: {...form, 'execute': execute}),
        (d) => d['data'] as Map<String, dynamic>,
      );

  Future<Map<String, dynamic>> rebootOnu(int oltId, int slot, int port, int onuId) => _run(
        () => _dio.post('/olts/$oltId/onus/$slot/$port/$onuId/reboot'),
        (d) => d['data'] as Map<String, dynamic>,
      );

  Future<Map<String, dynamic>> renameOnu(int oltId, int slot, int port, int onuId, {String? name, String? description}) => _run(
        () => _dio.post('/olts/$oltId/onus/$slot/$port/$onuId/name', data: {
          if (name != null) 'name': name,
          if (description != null) 'description': description,
        }),
        (d) => d['data'] as Map<String, dynamic>,
      );

  Future<Map<String, dynamic>> refreshUnconfigured(int oltId) => _run(
        () => _dio.post('/olts/$oltId/unconfigured/refresh'),
        (d) => d['data'] as Map<String, dynamic>,
      );

  Future<Map<String, dynamic>> refreshPort(int oltId, int slot, int port) => _run(
        () => _dio.post('/olts/$oltId/ports/$slot/$port/refresh'),
        (d) => d['data'] as Map<String, dynamic>,
      );
}
