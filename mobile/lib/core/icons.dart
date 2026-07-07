import 'package:flutter/material.dart';

/// Peta ikon bergaya "line" (mirip Lucide di web) ke Material Icons bawaan.
///
/// Kami tidak memakai paket `lucide_icons` karena versinya belum kompatibel
/// dengan Flutter terbaru (IconData kini `final class`). Nama member sengaja
/// dipertahankan (`LucideIcons.*`) agar pemakaian di UI tetap konsisten.
class LucideIcons {
  static const IconData alertTriangle = Icons.warning_amber_rounded;
  static const IconData bellOff = Icons.notifications_off_outlined;
  static const IconData bellRing = Icons.notifications_active_outlined;
  static const IconData chevronRight = Icons.chevron_right;
  static const IconData info = Icons.info_outline;
  static const IconData user = Icons.person_outline;
  static const IconData eye = Icons.visibility_outlined;
  static const IconData eyeOff = Icons.visibility_off_outlined;
  static const IconData inbox = Icons.inbox_outlined;
  static const IconData layoutDashboard = Icons.dashboard_outlined;
  static const IconData lock = Icons.lock_outline;
  static const IconData logOut = Icons.logout;
  static const IconData mail = Icons.mail_outline;
  static const IconData network = Icons.lan_outlined;
  static const IconData plugZap = Icons.electrical_services_outlined;
  static const IconData radioTower = Icons.cell_tower;
  static const IconData refreshCw = Icons.refresh;
  static const IconData router = Icons.router_outlined;
  static const IconData search = Icons.search;
  static const IconData searchCheck = Icons.fact_check_outlined;
  static const IconData searchCode = Icons.plagiarism_outlined;
  static const IconData searchX = Icons.search_off;
  static const IconData server = Icons.dns_outlined;
  static const IconData wifiOff = Icons.wifi_off_outlined;
  static const IconData x = Icons.close;
}
