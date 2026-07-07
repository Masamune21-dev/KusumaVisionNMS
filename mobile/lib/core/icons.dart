import 'package:flutter/material.dart';

/// Peta ikon bergaya "line" (mirip Lucide di web) ke Material Icons bawaan.
///
/// Kami tidak memakai paket `lucide_icons` karena versinya belum kompatibel
/// dengan Flutter terbaru (IconData kini `final class`). Nama member sengaja
/// dipertahankan (`LucideIcons.*`) agar pemakaian di UI tetap konsisten.
///
/// Disiplin filled vs outline: gunakan varian *outline* untuk state non-aktif
/// dan varian *filled* (`*Filled`) hanya untuk item navigasi/aktif.
class LucideIcons {
  static const IconData alertTriangle = Icons.warning_amber_rounded;
  static const IconData bellOff = Icons.notifications_off_outlined;
  static const IconData bellRing = Icons.notifications_active_outlined;
  static const IconData bellFilled = Icons.notifications_rounded;
  static const IconData chevronRight = Icons.chevron_right;
  static const IconData arrowRight = Icons.arrow_forward_rounded;
  static const IconData info = Icons.info_outline;
  static const IconData user = Icons.person_outline;
  static const IconData userFilled = Icons.person_rounded;
  static const IconData eye = Icons.visibility_outlined;
  static const IconData eyeOff = Icons.visibility_off_outlined;
  static const IconData inbox = Icons.inbox_outlined;
  static const IconData layoutDashboard = Icons.dashboard_outlined;
  static const IconData layoutDashboardFilled = Icons.dashboard_rounded;
  static const IconData lock = Icons.lock_outline;
  static const IconData logOut = Icons.logout_rounded;
  static const IconData mail = Icons.mail_outline;
  static const IconData network = Icons.lan_outlined;
  static const IconData plugZap = Icons.electrical_services_outlined;
  static const IconData radioTower = Icons.cell_tower_rounded;
  static const IconData refreshCw = Icons.refresh_rounded;
  static const IconData router = Icons.router_outlined;
  static const IconData search = Icons.search_rounded;
  static const IconData searchFilled = Icons.saved_search_rounded;
  static const IconData searchCheck = Icons.fact_check_outlined;
  static const IconData searchCode = Icons.plagiarism_outlined;
  static const IconData searchX = Icons.search_off_rounded;
  static const IconData server = Icons.dns_outlined;
  static const IconData serverFilled = Icons.dns_rounded;
  static const IconData wifiOff = Icons.wifi_off_rounded;
  static const IconData signal = Icons.settings_input_antenna_rounded;
  static const IconData activity = Icons.monitor_heart_outlined;
  static const IconData zap = Icons.bolt_rounded;
  static const IconData checkCircle = Icons.check_circle_outline_rounded;
  static const IconData edit = Icons.edit_outlined;
  static const IconData restart = Icons.restart_alt_rounded;
  static const IconData x = Icons.close_rounded;
  static const IconData shieldCheck = Icons.verified_user_outlined;
  static const IconData copy = Icons.copy_rounded;
  static const IconData smartphone = Icons.smartphone_outlined;
}
