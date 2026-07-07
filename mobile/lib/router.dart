import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import 'features/account/account_screen.dart';
import 'features/alarms/alarm_list_screen.dart';
import 'features/auth/auth_controller.dart';
import 'features/auth/login_screen.dart';
import 'features/dashboard/dashboard_screen.dart';
import 'features/olts/olt_detail_screen.dart';
import 'features/olts/olt_list_screen.dart';
import 'features/onus/onu_detail_screen.dart';
import 'features/onus/port_onus_screen.dart';
import 'features/register/register_screen.dart';
import 'features/search/search_screen.dart';
import 'features/shell/home_shell.dart';
import 'features/unconfigured/unconfigured_screen.dart';
import 'splash_screen.dart';

final _rootKey = GlobalKey<NavigatorState>();
final _shellKey = GlobalKey<NavigatorState>();

/// Router aplikasi. Redirect berbasis status auth; refresh saat auth berubah.
final routerProvider = Provider<GoRouter>((ref) {
  final refresh = ValueNotifier(0);
  ref.listen(authControllerProvider, (_, __) => refresh.value++);
  ref.onDispose(refresh.dispose);

  return GoRouter(
    navigatorKey: _rootKey,
    initialLocation: '/dashboard',
    refreshListenable: refresh,
    redirect: (context, state) {
      final auth = ref.read(authControllerProvider);
      final loc = state.matchedLocation;

      if (auth.status == AuthStatus.unknown) {
        return loc == '/splash' ? null : '/splash';
      }
      final loggedIn = auth.status == AuthStatus.authenticated;
      if (!loggedIn) return loc == '/login' ? null : '/login';
      if (loc == '/login' || loc == '/splash') return '/dashboard';
      return null;
    },
    routes: [
      GoRoute(path: '/splash', builder: (_, __) => const SplashScreen()),
      GoRoute(path: '/login', builder: (_, __) => const LoginScreen()),

      // Tab bawah (state per tab dipertahankan).
      StatefulShellRoute.indexedStack(
        parentNavigatorKey: _rootKey,
        builder: (context, state, shell) => HomeShell(shell: shell),
        branches: [
          StatefulShellBranch(navigatorKey: _shellKey, routes: [
            GoRoute(path: '/dashboard', builder: (_, __) => const DashboardScreen()),
          ]),
          StatefulShellBranch(routes: [
            GoRoute(path: '/olts', builder: (_, __) => const OltListScreen()),
          ]),
          StatefulShellBranch(routes: [
            GoRoute(path: '/alarms', builder: (_, __) => const AlarmListScreen()),
          ]),
          StatefulShellBranch(routes: [
            GoRoute(path: '/search', builder: (_, __) => const SearchScreen()),
          ]),
          StatefulShellBranch(routes: [
            GoRoute(path: '/account', builder: (_, __) => const AccountScreen()),
          ]),
        ],
      ),

      // Halaman dalam (menutupi shell) — root navigator.
      GoRoute(
        parentNavigatorKey: _rootKey,
        path: '/olts/:id',
        builder: (_, s) => OltDetailScreen(oltId: int.parse(s.pathParameters['id']!)),
      ),
      GoRoute(
        parentNavigatorKey: _rootKey,
        path: '/olts/:id/unconfigured',
        builder: (_, s) => UnconfiguredScreen(oltId: int.parse(s.pathParameters['id']!)),
      ),
      GoRoute(
        parentNavigatorKey: _rootKey,
        path: '/olts/:id/register',
        builder: (_, s) => RegisterScreen(
          oltId: int.parse(s.pathParameters['id']!),
          sn: s.uri.queryParameters['sn'],
          slot: int.tryParse(s.uri.queryParameters['slot'] ?? ''),
          port: int.tryParse(s.uri.queryParameters['port'] ?? ''),
        ),
      ),
      GoRoute(
        parentNavigatorKey: _rootKey,
        path: '/olts/:id/ports/:slot/:port',
        builder: (_, s) => PortOnusScreen(
          oltId: int.parse(s.pathParameters['id']!),
          slot: int.parse(s.pathParameters['slot']!),
          port: int.parse(s.pathParameters['port']!),
          focusOnuId: int.tryParse(s.uri.queryParameters['focus'] ?? ''),
        ),
      ),
      GoRoute(
        parentNavigatorKey: _rootKey,
        path: '/olts/:id/ports/:slot/:port/onus/:onuId',
        builder: (_, s) => OnuDetailScreen(
          oltId: int.parse(s.pathParameters['id']!),
          slot: int.parse(s.pathParameters['slot']!),
          port: int.parse(s.pathParameters['port']!),
          onuId: int.parse(s.pathParameters['onuId']!),
        ),
      ),
    ],
  );
});
