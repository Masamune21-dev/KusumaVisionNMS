import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_staggered_animations/flutter_staggered_animations.dart';
import 'package:go_router/go_router.dart';
import 'package:kusumavision_nms/core/icons.dart';

import '../../core/format.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/aurora_background.dart';
import '../../core/widgets/glass_card.dart';
import '../../core/widgets/pulse_dot.dart';
import '../../core/widgets/stagger.dart';
import '../../data/read_providers.dart';
import '../../models/olt.dart';
import '../../theme/app_theme.dart';

const _tnum = [FontFeature.tabularFigures()];

/// Warna badge family per driver.
Color _familyColor(String driver) => switch (driver) {
      'zte' => AppColors.secondary,
      'cdata-epon-17409' || 'cdata-gpon-34592' => const Color(0xFF34D399),
      'hioso-epon-25355' => const Color(0xFFA78BFA),
      _ => AppColors.muted,
    };

class OltListScreen extends ConsumerWidget {
  const OltListScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final olts = ref.watch(oltsProvider);
    final topInset = MediaQuery.of(context).padding.top + kToolbarHeight + 8;
    final bottomInset = MediaQuery.of(context).viewPadding.bottom + 88;

    return Scaffold(
      extendBodyBehindAppBar: true,
      appBar: AppBar(
        backgroundColor: Colors.transparent,
        title: const Text('Inventory OLT'),
      ),
      body: AuroraBackground(
        intensity: 0.7,
        child: RefreshIndicator(
          onRefresh: () async => ref.refresh(oltsProvider.future),
          color: AppColors.primary,
          backgroundColor: AppColors.surfaceAlt,
          child: AsyncView<List<OltSummary>>(
            value: olts,
            onRetry: () => ref.refresh(oltsProvider),
            data: (list) {
              if (list.isEmpty) {
                return ListView(children: const [
                  SizedBox(height: 120),
                  EmptyState(message: 'Belum ada OLT terdaftar.', icon: LucideIcons.server),
                ]);
              }
              return AnimationLimiter(
                child: ListView.separated(
                  padding: EdgeInsets.fromLTRB(16, topInset, 16, bottomInset),
                  itemCount: list.length,
                  separatorBuilder: (_, __) => const SizedBox(height: 12),
                  itemBuilder: (_, i) => staggeredItem(i, OltCard(olt: list[i])),
                ),
              );
            },
          ),
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
    final t = Theme.of(context).textTheme;
    final famColor = _familyColor(olt.driver);
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
                        maxLines: 1, overflow: TextOverflow.ellipsis, style: t.titleMedium),
                    const SizedBox(height: 4),
                    Row(children: [
                      Text(olt.ip, style: AppText.mono(size: 12, color: AppColors.muted)),
                      const SizedBox(width: 8),
                      _FamilyBadge(label: olt.familyLabel, color: famColor),
                    ]),
                  ],
                ),
              ),
              const SizedBox(width: 8),
              _ReachChip(reachable: olt.reachable),
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

class _FamilyBadge extends StatelessWidget {
  const _FamilyBadge({required this.label, required this.color});
  final String label;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Flexible(
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
        decoration: BoxDecoration(
          color: color.withValues(alpha: 0.13),
          borderRadius: BorderRadius.circular(6),
          border: Border.all(color: color.withValues(alpha: 0.3)),
        ),
        child: Text(label,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: TextStyle(color: color, fontSize: 11, fontWeight: FontWeight.w700)),
      ),
    );
  }
}

class _ReachChip extends StatelessWidget {
  const _ReachChip({required this.reachable});
  final bool reachable;

  @override
  Widget build(BuildContext context) {
    final color = reachable ? AppColors.success : AppColors.danger;
    return Container(
      padding: const EdgeInsets.only(left: 6, right: 10, top: 4, bottom: 4),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.13),
        borderRadius: BorderRadius.circular(AppRadius.pill),
        border: Border.all(color: color.withValues(alpha: 0.36)),
      ),
      child: Row(mainAxisSize: MainAxisSize.min, children: [
        PulseDot(color: color, size: 7, pulse: reachable),
        const SizedBox(width: 3),
        Text(reachable ? 'Reachable' : 'Down',
            style: TextStyle(color: color, fontSize: 11.5, fontWeight: FontWeight.w700)),
      ]),
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
                style: TextStyle(
                    fontWeight: FontWeight.w800, fontSize: 14, color: color, fontFeatures: _tnum)),
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
