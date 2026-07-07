import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'core/fcm/fcm_service.dart';
import 'features/auth/auth_controller.dart';
import 'router.dart';
import 'theme/app_theme.dart';

class KusumaVisionApp extends ConsumerStatefulWidget {
  const KusumaVisionApp({super.key});

  @override
  ConsumerState<KusumaVisionApp> createState() => _KusumaVisionAppState();
}

class _KusumaVisionAppState extends ConsumerState<KusumaVisionApp> {
  @override
  void initState() {
    super.initState();
    // Pasang listener notifikasi & deep-link setelah frame pertama.
    WidgetsBinding.instance.addPostFrameCallback((_) {
      ref.read(fcmServiceProvider).wireHandlers(ref.read(routerProvider));
    });
  }

  @override
  Widget build(BuildContext context) {
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
      theme: AppTheme.dark(),
      routerConfig: ref.watch(routerProvider),
    );
  }
}
