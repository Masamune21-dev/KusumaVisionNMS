import 'package:flutter/material.dart';

import '../../theme/app_theme.dart';

/// Chip status kecil berwarna (online/offline, up/down, severity, dll).
///
/// Badge solid dengan latar warna transparan (~14%) + garis tepi tipis, agar
/// enak dipandang dan konsisten di seluruh app. Info tak hanya lewat warna —
/// selalu ada teks (dan titik/ikon pendamping).
class StatusChip extends StatelessWidget {
  const StatusChip({
    super.key,
    required this.label,
    required this.color,
    this.icon,
    this.dense = false,
  });

  final String label;
  final Color color;
  final IconData? icon;
  final bool dense;

  factory StatusChip.online(bool online, {bool dense = false}) => online
      ? StatusChip(label: 'Online', color: AppColors.success, dense: dense)
      : StatusChip(label: 'Offline', color: AppColors.danger, dense: dense);

  factory StatusChip.reachable(bool up) => up
      ? const StatusChip(label: 'Reachable', color: AppColors.success)
      : const StatusChip(label: 'Down', color: AppColors.danger);

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: EdgeInsets.symmetric(horizontal: dense ? 8 : 10, vertical: dense ? 3 : 4.5),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.14),
        borderRadius: BorderRadius.circular(AppRadius.pill),
        border: Border.all(color: color.withValues(alpha: 0.38)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (icon != null) ...[
            Icon(icon, size: 12.5, color: color),
            const SizedBox(width: 5),
          ] else ...[
            _GlowDot(color: color),
            const SizedBox(width: 6),
          ],
          Text(
            label,
            style: TextStyle(
                color: color, fontSize: dense ? 11 : 11.5, fontWeight: FontWeight.w700),
          ),
        ],
      ),
    );
  }
}

/// Titik status dengan halo lembut (kesan "menyala").
class _GlowDot extends StatelessWidget {
  const _GlowDot({required this.color});
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 7,
      height: 7,
      decoration: BoxDecoration(
        color: color,
        shape: BoxShape.circle,
        boxShadow: [BoxShadow(color: color.withValues(alpha: 0.6), blurRadius: 5)],
      ),
    );
  }
}
