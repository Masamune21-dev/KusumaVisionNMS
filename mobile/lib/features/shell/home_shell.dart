import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:kusumavision_nms/core/icons.dart';

import '../../theme/app_theme.dart';

/// Kerangka utama dengan bottom navigation (5 tab).
/// Ikon non-aktif memakai varian outline; ikon aktif varian filled + aksen cyan.
class HomeShell extends StatelessWidget {
  const HomeShell({super.key, required this.shell});

  final StatefulNavigationShell shell;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: shell,
      bottomNavigationBar: DecoratedBox(
        decoration: const BoxDecoration(
          border: Border(top: BorderSide(color: AppColors.border)),
        ),
        child: NavigationBar(
          selectedIndex: shell.currentIndex,
          onDestinationSelected: (i) =>
              shell.goBranch(i, initialLocation: i == shell.currentIndex),
          destinations: const [
            NavigationDestination(
              icon: Icon(LucideIcons.layoutDashboard),
              selectedIcon: Icon(LucideIcons.layoutDashboardFilled),
              label: 'Dashboard',
            ),
            NavigationDestination(
              icon: Icon(LucideIcons.server),
              selectedIcon: Icon(LucideIcons.serverFilled),
              label: 'OLT',
            ),
            NavigationDestination(
              icon: Icon(LucideIcons.bellRing),
              selectedIcon: Icon(LucideIcons.bellFilled),
              label: 'Alarm',
            ),
            NavigationDestination(
              icon: Icon(LucideIcons.search),
              selectedIcon: Icon(LucideIcons.searchFilled),
              label: 'Cari',
            ),
            NavigationDestination(
              icon: Icon(LucideIcons.user),
              selectedIcon: Icon(LucideIcons.userFilled),
              label: 'Akun',
            ),
          ],
        ),
      ),
    );
  }
}
