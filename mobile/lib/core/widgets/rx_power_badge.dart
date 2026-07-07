import 'package:flutter/material.dart';

import '../../theme/app_theme.dart';
import '../format.dart';

/// Badge RX power dengan warna zona: hijau aman (-25..-10), amber marginal,
/// abu-abu bila tak diketahui.
class RxPowerBadge extends StatelessWidget {
  const RxPowerBadge({super.key, required this.dbm, this.online = true});

  final double? dbm;
  final bool online;

  @override
  Widget build(BuildContext context) {
    final Color color;
    if (dbm == null || !online) {
      color = AppColors.faint;
    } else if (dbm! <= -25 || dbm! >= -10) {
      color = AppColors.warning;
    } else {
      color = AppColors.success;
    }

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(8),
        border: Border.all(color: color.withValues(alpha: 0.35)),
      ),
      child: Text(
        online ? Fmt.rx(dbm) : '—',
        style: TextStyle(
          color: color,
          fontSize: 12,
          fontWeight: FontWeight.w700,
          fontFeatures: const [FontFeature.tabularFigures()],
        ),
      ),
    );
  }
}
