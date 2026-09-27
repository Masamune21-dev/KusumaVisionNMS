import 'dart:async';
import 'dart:convert';

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../providers.dart';
import '../widgets/nms_logo.dart';

/// Handler pesan latar belakang (harus top-level). Sistem sudah menampilkan
/// notifikasi tray untuk pesan ber-`notification`; di sini cukup no-op.
@pragma('vm:entry-point')
Future<void> _bgHandler(RemoteMessage message) async {}

final fcmServiceProvider = Provider<FcmService>((ref) => FcmService(ref));

/// Firebase Cloud Messaging: registrasi token, channel 'alarms', tampilkan
/// notifikasi saat foreground, dan deep-link saat notifikasi di-tap.
///
/// Seluruhnya di-guard `available` — jika project Firebase belum dipasang
/// (`google-services.json` belum ada), semua method jadi no-op.
class FcmService {
  FcmService(this._ref);

  final Ref _ref;

  /// Satu langganan saja — dulu tiap login menambah listener onTokenRefresh baru.
  StreamSubscription<String>? _refreshSub;

  static bool available = false;
  static final FlutterLocalNotificationsPlugin _local = FlutterLocalNotificationsPlugin();

  static const AndroidNotificationChannel _channel = AndroidNotificationChannel(
    'alarms',
    'Alarm Jaringan',
    description: 'Notifikasi alarm OLT/ONU KusumaVision NMS',
    importance: Importance.high,
  );

  /// Dipanggil di main() sebelum runApp.
  static Future<void> tryInitialize() async {
    try {
      await Firebase.initializeApp();

      // `ic_notification` (siluet putih logomark), BUKAN `ic_launcher`: Android 5+
      // membuang semua warna ikon kecil notifikasi, jadi ikon launcher berwarna
      // tampil sebagai kotak putih polos.
      const initSettings = InitializationSettings(
        android: AndroidInitializationSettings('@drawable/ic_notification'),
      );
      await _local.initialize(initSettings);
      await _local
          .resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>()
          ?.createNotificationChannel(_channel);

      FirebaseMessaging.onBackgroundMessage(_bgHandler);
      available = true;
    } catch (e) {
      available = false;
      debugPrint('FCM belum aktif (Firebase belum dikonfigurasi): $e');
    }
  }

  /// Pasang listener foreground & tap; panggil sekali dari widget root.
  void wireHandlers(GoRouter router) {
    if (!available) return;

    FirebaseMessaging.onMessage.listen((m) {
      final n = m.notification;
      if (n == null) return;
      _local.show(
        n.hashCode,
        n.title,
        n.body,
        const NotificationDetails(
          android: AndroidNotificationDetails(
            'alarms',
            'Alarm Jaringan',
            importance: Importance.high,
            priority: Priority.high,
            icon: '@drawable/ic_notification',
            color: NmsBrand.cyan,
          ),
        ),
        payload: jsonEncode(m.data),
      );
    });

    FirebaseMessaging.onMessageOpenedApp.listen((m) => _routeFromData(router, m.data));
    FirebaseMessaging.instance.getInitialMessage().then((m) {
      if (m != null) _routeFromData(router, m.data);
    });
  }

  /// Setelah login: minta izin, ambil token, daftarkan ke server.
  Future<void> onLogin() async {
    if (!available) return;
    try {
      await FirebaseMessaging.instance.requestPermission();
      final token = await FirebaseMessaging.instance.getToken();
      if (token != null) await _register(token);

      _refreshSub ??= FirebaseMessaging.instance.onTokenRefresh.listen((t) => _register(t));
    } catch (e) {
      debugPrint('FCM onLogin gagal: $e');
    }
  }

  /// Token push perangkat ini, untuk dikirim bersama permintaan logout supaya
  /// server mencabutnya SELAGI sesi masih sah.
  Future<String?> currentToken() async {
    if (!available) return null;
    try {
      return await FirebaseMessaging.instance.getToken();
    } catch (_) {
      return null;
    }
  }

  /// Setelah keluar (atau sesi dicabut server): buang token push di sisi Firebase.
  ///
  /// Dulu di sini aplikasi memanggil `DELETE /devices` — padahal token login
  /// sudah dicabut lebih dulu, jadi selalu 401 dan server terus mengirim alarm
  /// ke ponsel yang sudah logout. Pencabutan di server kini ikut `POST
  /// /auth/logout`; di sini token dihapus di Firebase agar kiriman yang masih
  /// tersisa pun ditolak FCM. Login berikutnya mendapat token baru.
  Future<void> onLogout() async {
    if (!available) return;
    try {
      await _refreshSub?.cancel();
      _refreshSub = null;
      await FirebaseMessaging.instance.deleteToken();
    } catch (e) {
      debugPrint('FCM onLogout gagal: $e');
    }
  }

  Future<void> _register(String token) async {
    try {
      await _ref.read(nmsApiProvider).registerDevice(token);
    } catch (e) {
      debugPrint('Registrasi token FCM gagal: $e');
    }
  }

  void _routeFromData(GoRouter router, Map<String, dynamic> data) {
    final oltId = data['olt_id'];
    if (oltId == null || oltId.toString().isEmpty) {
      // /alarms bukan tab lagi, melainkan halaman yang di-push. Mendarat di
      // Dashboard dulu supaya tombol Back kembali ke aplikasi, bukan keluar.
      router.go('/dashboard');
      router.push('/alarms');
      return;
    }
    final slot = data['slot'];
    final port = data['port'];
    final onuId = data['onu_id'];
    if ([slot, port, onuId].every((v) => v != null && v.toString().isNotEmpty)) {
      router.push('/olts/$oltId/ports/$slot/$port/onus/$onuId');
    } else {
      router.push('/olts/$oltId');
    }
  }
}
