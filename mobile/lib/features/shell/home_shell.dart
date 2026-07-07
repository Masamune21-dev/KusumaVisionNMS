import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:kusumavision_nms/core/icons.dart';

import '../../theme/app_theme.dart';

/// Kerangka utama dengan **floating glass bottom navigation** (5 tab).
/// Item aktif: pill cyan yang melebar (animatif) + ikon filled + label cyan.
class HomeShell extends StatelessWidget {
  const HomeShell({super.key, required this.shell});

  final StatefulNavigationShell shell;

  static const _items = <_NavItem>[
    _NavItem(LucideIcons.layoutDashboard, LucideIcons.layoutDashboardFilled, 'Dashboard'),
    _NavItem(LucideIcons.server, LucideIcons.serverFilled, 'OLT'),
    _NavItem(LucideIcons.bellRing, LucideIcons.bellFilled, 'Alarm'),
    _NavItem(LucideIcons.search, LucideIcons.searchFilled, 'Cari'),
    _NavItem(LucideIcons.user, LucideIcons.userFilled, 'Akun'),
  ];

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      extendBody: true,
      body: shell,
      bottomNavigationBar: SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(14, 0, 14, 10),
          child: ClipRRect(
            borderRadius: BorderRadius.circular(AppRadius.pill),
            child: BackdropFilter(
              filter: ui.ImageFilter.blur(sigmaX: 18, sigmaY: 18),
              child: Container(
                height: 64,
                decoration: BoxDecoration(
                  borderRadius: BorderRadius.circular(AppRadius.pill),
                  gradient: LinearGradient(
                    begin: Alignment.topCenter,
                    end: Alignment.bottomCenter,
                    colors: [
                      AppColors.surfaceHi.withValues(alpha: 0.82),
                      AppColors.bgElevated.withValues(alpha: 0.86),
                    ],
                  ),
                  border: Border.all(color: AppColors.borderStrong),
                  boxShadow: const [
                    BoxShadow(color: Color(0x55000000), blurRadius: 24, offset: Offset(0, 10)),
                  ],
                ),
                child: Row(
                  children: [
                    for (var i = 0; i < _items.length; i++)
                      Expanded(
                        child: _NavButton(
                          item: _items[i],
                          selected: i == shell.currentIndex,
                          onTap: () => shell.goBranch(i, initialLocation: i == shell.currentIndex),
                        ),
                      ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _NavItem {
  const _NavItem(this.icon, this.activeIcon, this.label);
  final IconData icon, activeIcon;
  final String label;
}

class _NavButton extends StatelessWidget {
  const _NavButton({required this.item, required this.selected, required this.onTap});

  final _NavItem item;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final color = selected ? AppColors.primary : AppColors.faint;
    return Semantics(
      selected: selected,
      button: true,
      label: item.label,
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(AppRadius.pill),
        splashColor: AppColors.primary.withValues(alpha: 0.08),
        highlightColor: Colors.transparent,
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            AnimatedContainer(
              duration: AppMotion.base,
              curve: AppMotion.enter,
              padding: EdgeInsets.symmetric(horizontal: selected ? 18 : 10, vertical: 5),
              decoration: BoxDecoration(
                color: selected ? AppColors.primary.withValues(alpha: 0.16) : Colors.transparent,
                borderRadius: BorderRadius.circular(AppRadius.pill),
              ),
              child: Icon(selected ? item.activeIcon : item.icon, size: 22, color: color),
            ),
            const SizedBox(height: 3),
            Text(
              item.label,
              style: TextStyle(
                fontSize: 10.5,
                fontWeight: FontWeight.w600,
                color: color,
              ),
            ),
          ],
        ),
      ),
    );
  }
}
