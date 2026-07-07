import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:kusumavision_nms/core/icons.dart';

import '../../core/format.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/glass_card.dart';
import '../../core/widgets/status_chip.dart';
import '../../data/read_providers.dart';
import '../../models/olt.dart';
import '../../theme/app_theme.dart';

const _tnum = [FontFeature.tabularFigures()];

class OltListScreen extends ConsumerWidget {
  const OltListScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final olts = ref.watch(oltsProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Inventory OLT')),
      body: RefreshIndicator(
        onRefresh: () async => ref.refresh(oltsProvider.future),
        color: AppColors.primary,
        backgroundColor: AppColors.surfaceAlt,
        child: AsyncView<List<OltSummary>>(
          value: olts,
          onRetry: () => ref.refresh(oltsProvider),
          data: (list) {
            if (list.isEmpty) {
              return const EmptyState(message: 'Belum ada OLT terdaftar.', icon: LucideIcons.server);
            }
            return ListView.separated(
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 24),
              itemCount: list.length,
              separatorBuilder: (_, __) => const SizedBox(height: 12),
              itemBuilder: (_, i) => OltCard(olt: list[i]),
            );
          },
        ),
      ),
    );
  }
}

class OltCard extends StatelessWidget {
  const OltCard({super.key, required this.olt});
  final OltSummary olt;

  @override
  Widget build(BuildContext context) {
    return GlassCard(
      onTap: () => context.push('/olts/${olt.id}'),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                padding: const EdgeInsets.all(9),
                decoration: BoxDecoration(
                  color: AppColors.secondary.withValues(alpha: 0.13),
                  borderRadius: BorderRadius.circular(AppRadius.chip),
                ),
                child: const Icon(LucideIcons.server, color: AppColors.secondary, size: 19),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(olt.name,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
                    const SizedBox(height: 3),
                    Row(children: [
                      Text(olt.ip,
                          style: const TextStyle(color: AppColors.muted, fontSize: 12, fontFeatures: _tnum)),
                      const SizedBox(width: 7),
                      Container(width: 3, height: 3, decoration: const BoxDecoration(
                          color: AppColors.faint, shape: BoxShape.circle)),
                      const SizedBox(width: 7),
                      Flexible(
                        child: Text(olt.familyLabel,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: const TextStyle(color: AppColors.secondary, fontSize: 12, fontWeight: FontWeight.w600)),
                      ),
                    ]),
                  ],
                ),
              ),
              const SizedBox(width: 8),
              StatusChip.reachable(olt.reachable),
            ],
          ),
          const SizedBox(height: 14),
          Row(
            children: [
              Expanded(
                child: _MiniStat(
                  icon: LucideIcons.router,
                  color: AppColors.primary,
                  value: '${Fmt.int(olt.onuOnline)}/${Fmt.int(olt.onuTotal)}',
                  label: 'ONU online',
                  progress: olt.onuTotal > 0 ? olt.onuOnline / olt.onuTotal : null,
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: _MiniStat(
                  icon: LucideIcons.network,
                  color: AppColors.secondary,
                  value: '${olt.portsUp}/${olt.portsTotal}',
                  label: 'Port up',
                  progress: olt.portsTotal > 0 ? olt.portsUp / olt.portsTotal : null,
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _MiniStat extends StatelessWidget {
  const _MiniStat({
    required this.icon,
    required this.color,
    required this.value,
    required this.label,
    this.progress,
  });

  final IconData icon;
  final Color color;
  final String value, label;
  final double? progress;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: AppColors.bg.withValues(alpha: 0.4),
        borderRadius: BorderRadius.circular(AppRadius.chip),
        border: Border.all(color: AppColors.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(children: [
            Icon(icon, size: 14, color: color),
            const SizedBox(width: 6),
            Text(value,
                style: TextStyle(fontWeight: FontWeight.w800, fontSize: 14, color: color, fontFeatures: _tnum)),
          ]),
          const SizedBox(height: 2),
          Text(label, style: const TextStyle(color: AppColors.faint, fontSize: 11)),
          if (progress != null) ...[
            const SizedBox(height: 7),
            ClipRRect(
              borderRadius: BorderRadius.circular(AppRadius.pill),
              child: LinearProgressIndicator(
                value: progress!.clamp(0.0, 1.0),
                minHeight: 4,
                backgroundColor: AppColors.surfaceAlt,
                valueColor: AlwaysStoppedAnimation(color),
              ),
            ),
          ],
        ],
      ),
    );
  }
}
