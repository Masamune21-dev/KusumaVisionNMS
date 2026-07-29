import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:kusumavision_nms/core/icons.dart';

import '../../theme/app_theme.dart';

/// Chip kuning penanda ODP tempat sebuah ONU tersambung. Bila [odpId] diisi,
/// chip bisa ditekan untuk membuka halaman ODP-nya.
class OdpChip extends StatelessWidget {
  const OdpChip({super.key, required this.name, this.odpId, this.dense = true});

  final String name;
  final int? odpId;
  final bool dense;

  @override
  Widget build(BuildContext context) {
    final chip = Container(
      padding: EdgeInsets.symmetric(horizontal: dense ? 8 : 10, vertical: dense ? 3 : 5),
      decoration: BoxDecoration(
        color: AppColors.warning.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(AppRadius.pill),
        border: Border.all(color: AppColors.warning.withValues(alpha: 0.32)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(LucideIcons.odp, size: dense ? 11 : 13, color: AppColors.warning),
          const SizedBox(width: 5),
          Flexible(
            child: Text(
              name,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(
                color: AppColors.warning,
                fontSize: dense ? 10.5 : 12,
                fontWeight: FontWeight.w700,
              ),
            ),
          ),
        ],
      ),
    );

    if (odpId == null) return chip;

    return InkWell(
      onTap: () => context.push('/odps/$odpId'),
      borderRadius: BorderRadius.circular(AppRadius.pill),
      child: chip,
    );
  }
}
