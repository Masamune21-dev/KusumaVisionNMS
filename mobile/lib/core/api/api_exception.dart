import 'package:dio/dio.dart';

/// Error API yang sudah diterjemahkan ke pesan berbahasa Indonesia untuk UI.
class ApiException implements Exception {
  ApiException(this.message, {this.statusCode, this.isAuth = false});

  final String message;
  final int? statusCode;
  final bool isAuth; // true bila 401 (token invalid → paksa login ulang)

  @override
  String toString() => message;

  factory ApiException.fromDio(DioException e) {
    final res = e.response;
    final code = res?.statusCode;

    if (e.type == DioExceptionType.connectionTimeout ||
        e.type == DioExceptionType.receiveTimeout ||
        e.type == DioExceptionType.sendTimeout) {
      return ApiException('Koneksi timeout. Server lama merespons.', statusCode: code);
    }
    if (e.type == DioExceptionType.connectionError) {
      return ApiException('Tidak bisa terhubung ke server. Cek jaringan.', statusCode: code);
    }

    if (code == 401) {
      return ApiException('Sesi berakhir. Silakan login kembali.',
          statusCode: 401, isAuth: true);
    }
    if (code == 403) {
      return ApiException(_serverMessage(res?.data) ?? 'Akses ditolak untuk peran Anda.',
          statusCode: 403);
    }
    if (code == 429) {
      return ApiException('Terlalu banyak permintaan. Coba lagi sebentar.', statusCode: 429);
    }

    // 422 — validasi Laravel: {message, errors:{field:[..]}}.
    if (code == 422) {
      final data = res?.data;
      if (data is Map && data['errors'] is Map) {
        final first = (data['errors'] as Map).values.first;
        if (first is List && first.isNotEmpty) {
          return ApiException(first.first.toString(), statusCode: 422);
        }
      }
      return ApiException(_serverMessage(data) ?? 'Data tidak valid.', statusCode: 422);
    }

    return ApiException(
      _serverMessage(res?.data) ?? 'Terjadi kesalahan (${code ?? '-'}).',
      statusCode: code,
    );
  }

  static String? _serverMessage(dynamic data) {
    if (data is Map && data['message'] is String) return data['message'] as String;
    return null;
  }
}
