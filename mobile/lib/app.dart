import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'core/fcm/fcm_service.dart';
import 'features/auth/auth_controller.dart';
import 'router.dart';
import 'theme/app_theme.dart';
import 'theme/theme_controller.dart';

class KusumaVisionApp extends ConsumerStatefulWidget {
  const KusumaVisionApp({super.key});

  @override
  ConsumerState<KusumaVisionApp> createState() => _KusumaVisionAppState();
}

class _KusumaVisionAppState extends ConsumerState<KusumaVisionApp> with WidgetsBindingObserver {
  static final _light = AppTheme.light();
  static final _dark = AppTheme.dark();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    // Pasang listener notifikasi & deep-link setelah frame pertama.
    WidgetsBinding.instance.addPostFrameCallback((_) {
      ref.read(fcmServiceProvider).wireHandlers(ref.read(routerProvider));
    });
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  /// Mode "Ikuti sistem": HP berganti gelap/terang → bangun ulang akar.
  @override
  void didChangePlatformBrightness() => setState(() {});

  /// Tandai SELURUH turunan untuk dibangun ulang. [AppColors] dibaca di `build`
  /// tiap widget, jadi setelah palet ditukar semua widget harus build lagi — ini
  /// mempertahankan state (posisi gulir, isi form, tumpukan halaman), beda dengan
  /// memasang ulang pohon lewat key. Sah dipanggil di tengah `build` akar karena
  /// yang ditandai hanya turunannya.
  void _rebuildDescendants() {
    void mark(Element el) {
      el.markNeedsBuild();
      el.visitChildren(mark);
    }

    (context as Element).visitChildren(mark);
  }

  @override
  Widget build(BuildContext context) {
    final mode = ref.watch(themeModeProvider);
    final brightness = switch (mode) {
      ThemeMode.light => Brightness.light,
      ThemeMode.dark => Brightness.dark,
      ThemeMode.system => WidgetsBinding.instance.platformDispatcher.platformBrightness,
    };
    if (AppColors.use(brightness)) _rebuildDescendants();

    // Sinkronkan token FCM mengikuti status login.
    ref.listen(authControllerProvider, (prev, next) {
      final fcm = ref.read(fcmServiceProvider);
      if (prev?.status != AuthStatus.authenticated && next.status == AuthStatus.authenticated) {
        fcm.onLogin();
      } else if (prev?.status == AuthStatus.authenticated &&
          next.status == AuthStatus.unauthenticated) {
        fcm.onLogout();
      }
    });

    return MaterialApp.router(
      title: 'KusumaVision NMS',
      debugShowCheckedModeBanner: false,
      theme: _light,
      darkTheme: _dark,
      themeMode: mode,
      // Palet [AppColors] berganti seketika; animasi lerp tema bawaan akan membuat
      // komponen Material & widget kustom sempat berbeda warna.
      themeAnimationDuration: Duration.zero,
      routerConfig: ref.watch(routerProvider),
    );
  }
}
