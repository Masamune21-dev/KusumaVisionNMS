import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:kusumavision_nms/core/icons.dart';

/// Kerangka utama dengan bottom navigation (4 tab).
class HomeShell extends StatelessWidget {
  const HomeShell({super.key, required this.shell});

  final StatefulNavigationShell shell;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: shell,
      bottomNavigationBar: NavigationBar(
        selectedIndex: shell.currentIndex,
        onDestinationSelected: (i) => shell.goBranch(i, initialLocation: i == shell.currentIndex),
        destinations: const [
          NavigationDestination(icon: Icon(LucideIcons.layoutDashboard), label: 'Dashboard'),
          NavigationDestination(icon: Icon(LucideIcons.server), label: 'OLT'),
          NavigationDestination(icon: Icon(LucideIcons.bellRing), label: 'Alarm'),
          NavigationDestination(icon: Icon(LucideIcons.search), label: 'Cari'),
          NavigationDestination(icon: Icon(LucideIcons.user), label: 'Akun'),
        ],
      ),
    );
  }
}
