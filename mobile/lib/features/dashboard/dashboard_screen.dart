import 'package:flutter/material.dart';
import 'package:flutter_animate/flutter_animate.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:kusumavision_nms/core/icons.dart';

import '../../core/format.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/aurora_background.dart';
import '../../core/widgets/count_up_text.dart';
import '../../core/widgets/glass_card.dart';
import '../../core/widgets/signal_ring.dart';
import '../../data/read_providers.dart';
import '../../models/summary.dart';
import '../../theme/app_theme.dart';
import '../auth/auth_controller.dart';

const _tnum = [FontFeature.tabularFigures()];

class DashboardScreen extends ConsumerWidget {
  const DashboardScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final user = ref.watch(authControllerProvider).user;
    final summary = ref.watch(summaryProvider);

    return Scaffold(
      extendBodyBehindAppBar: true,
      appBar: AppBar(
        backgroundColor: Colors.transparent,
        titleSpacing: 16,
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Halo, ${user?.name ?? ''}'.trim(),
                style: const TextStyle(
                    fontFamily: AppFont.display,
                    fontSize: 19,
                    fontWeight: FontWeight.w800,
                    letterSpacing: -0.3)),
            if (user != null)
              Text(user.roleLabel,
                  style: const TextStyle(fontSize: 12, color: AppColors.muted)),
          ],
        ),
        actions: [
          IconButton(
            tooltip: 'Keluar',
            icon: const Icon(LucideIcons.logOut, size: 20),
            onPressed: () => _confirmLogout(context, ref),
          ),
          const SizedBox(width: 4),
        ],
      ),
      body: AuroraBackground(
        intensity: 0.85,
        child: RefreshIndicator(
          onRefresh: () async => ref.refresh(summaryProvider.future),
          color: AppColors.primary,
          backgroundColor: AppColors.surfaceAlt,
          child: ListView(
            padding: EdgeInsets.fromLTRB(
                16, MediaQuery.of(context).padding.top + kToolbarHeight + 8, 16, 28),
            children: [
              AsyncView<DashboardSummary>(
                value: summary,
                onRetry: () => ref.refresh(summaryProvider),
                loading: () => const _DashboardSkeleton(),
                data: (s) => _SummaryBody(summary: s),
              ),
            ],
          ),
        ),
      ),
    );
  }

  void _confirmLogout(BuildContext context, WidgetRef ref) {
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Keluar?'),
        content: const Text('Anda akan keluar dari sesi ini.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Batal')),
          FilledButton(
            style: FilledButton.styleFrom(
                backgroundColor: AppColors.danger, foregroundColor: Colors.white),
            onPressed: () {
              Navigator.pop(ctx);
              ref.read(authControllerProvider.notifier).logout();
            },
            child: const Text('Keluar'),
          ),
        ],
      ),
    );
  }
}

class _SummaryBody extends StatelessWidget {
  const _SummaryBody({required this.summary});
  final DashboardSummary summary;

  @override
  Widget build(BuildContext context) {
    // Entrance sekali: fade + naik halus, di-stagger antar seksi.
    Widget seq(int i, Widget child) => child
        .animate(delay: (i * 70).ms)
        .fadeIn(duration: AppMotion.base)
        .slideY(begin: 0.12, curve: AppMotion.enter);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        seq(0, _HealthHero(summary: summary)),
        const SizedBox(height: 12),
        seq(1, Row(children: [
          Expanded(
            child: _StatCard(
              icon: LucideIcons.server,
              watermark: LucideIcons.serverFilled,
              label: 'OLT total',
              value: summary.oltTotal,
              sub: '${summary.oltOnline} online · ${summary.oltOffline} offline',
              color: AppColors.secondary,
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: _StatCard(
              icon: LucideIcons.router,
              watermark: LucideIcons.router,
              label: 'ONU total',
              value: summary.onuTotal,
              sub: '${Fmt.int(summary.onuOnline)} online',
              color: AppColors.primary,
            ),
          ),
        ])),
        const SizedBox(height: 12),
        seq(2, Row(children: [
          Expanded(
            child: _StatCard(
              icon: LucideIcons.wifiOff,
              watermark: LucideIcons.wifiOff,
              label: 'ONU offline',
              value: summary.onuOffline,
              sub: '${Fmt.int(summary.onuWarning)} warning RX',
              color: AppColors.danger,
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: _StatCard(
              icon: LucideIcons.bellRing,
              watermark: LucideIcons.bellFilled,
              label: 'Alarm aktif',
              value: summary.alarmTotal,
              sub: '${summary.alarmCritical} kritis',
              color: AppColors.warning,
              onTap: () => context.go('/alarms'),
            ),
          ),
        ])),
        const SizedBox(height: 16),
        seq(3, _AlarmBreakdown(summary: summary)),
      ],
    );
  }
}

class _HealthHero extends StatelessWidget {
  const _HealthHero({required this.summary});
  final DashboardSummary summary;

  @override
  Widget build(BuildContext context) {
    final share = summary.onlineShare.toDouble().clamp(0, 100).toDouble();
    final t = Theme.of(context).textTheme;
    final healthColor = share >= 95
        ? AppColors.success
        : share >= 80
            ? AppColors.warning
            : AppColors.danger;

    return GlassCard(
      blur: true,
      padding: const EdgeInsets.all(18),
      child: Row(
        children: [
          SignalRing(percent: share, size: 118, color: healthColor),
          const SizedBox(width: 18),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Row(children: [
                  Icon(LucideIcons.activity, size: 15, color: healthColor),
                  const SizedBox(width: 7),
                  Text('Kesehatan jaringan',
                      style: t.labelMedium?.copyWith(color: AppColors.muted)),
                ]),
                const SizedBox(height: 8),
                CountUpText(
                  summary.onuOnline,
                  style: t.displaySmall?.copyWith(color: AppColors.text, fontFeatures: _tnum),
                ),
                Text('ONU online dari ${Fmt.int(summary.onuTotal)}',
                    style: t.bodySmall?.copyWith(color: AppColors.faint, fontFeatures: _tnum)),
                const SizedBox(height: 10),
                Wrap(spacing: 8, runSpacing: 6, children: [
                  _MiniStat(color: AppColors.danger, label: '${Fmt.int(summary.onuOffline)} offline'),
                  _MiniStat(color: AppColors.warning, label: '${Fmt.int(summary.onuWarning)} warning'),
                ]),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _MiniStat extends StatelessWidget {
  const _MiniStat({required this.color, required this.label});
  final Color color;
  final String label;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 4),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(AppRadius.pill),
        border: Border.all(color: color.withValues(alpha: 0.3)),
      ),
      child: Row(mainAxisSize: MainAxisSize.min, children: [
        Container(width: 6, height: 6, decoration: BoxDecoration(color: color, shape: BoxShape.circle)),
        const SizedBox(width: 6),
        Text(label,
            style: TextStyle(
                color: color, fontSize: 11.5, fontWeight: FontWeight.w600, fontFeatures: _tnum)),
      ]),
    );
  }
}

class _StatCard extends StatelessWidget {
  const _StatCard({
    required this.icon,
    required this.watermark,
    required this.label,
    required this.value,
    required this.sub,
    required this.color,
    this.onTap,
  });

  final IconData icon, watermark;
  final String label, sub;
  final num value;
  final Color color;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context).textTheme;
    return GlassCard(
      onTap: onTap,
      padding: EdgeInsets.zero,
      child: Stack(
        clipBehavior: Clip.hardEdge,
        alignment: Alignment.topCenter,
        children: [
          Positioned(
            right: -14,
            bottom: -16,
            child: Icon(watermark, size: 92, color: color.withValues(alpha: 0.07)),
          ),
          Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.center,
              children: [
                Container(
                  padding: const EdgeInsets.all(8),
                  decoration: BoxDecoration(
                    color: color.withValues(alpha: 0.14),
                    borderRadius: BorderRadius.circular(AppRadius.chip),
                  ),
                  child: Icon(icon, color: color, size: 18),
                ),
                const SizedBox(height: 12),
                CountUpText(
                  value,
                  style: t.displaySmall?.copyWith(fontSize: 30, color: AppColors.text, fontFeatures: _tnum),
                ),
                const SizedBox(height: 5),
                Text(label,
                    textAlign: TextAlign.center, style: t.titleSmall?.copyWith(color: AppColors.text)),
                const SizedBox(height: 2),
                Text(sub,
                    maxLines: 1,
                    textAlign: TextAlign.center,
                    overflow: TextOverflow.ellipsis,
                    style: t.bodySmall?.copyWith(color: AppColors.faint, fontFeatures: _tnum)),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _AlarmBreakdown extends StatelessWidget {
  const _AlarmBreakdown({required this.summary});
  final DashboardSummary summary;

  @override
  Widget build(BuildContext context) {
    final items = [
      ('Kritis', summary.alarmCritical, AppColors.severity('critical')),
      ('Mayor', summary.alarmMajor, AppColors.severity('major')),
      ('Minor', summary.alarmMinor, AppColors.severity('minor')),
      ('Warning', summary.alarmWarning, AppColors.severity('warning')),
    ];
    final total = items.fold<int>(0, (a, e) => a + e.$2);
    final t = Theme.of(context).textTheme;

    return GlassCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(children: [
            const Icon(LucideIcons.bellRing, size: 16, color: AppColors.warning),
            const SizedBox(width: 8),
            Text('Rincian alarm aktif', style: t.titleMedium),
          ]),
          const SizedBox(height: 14),
          ClipRRect(
            borderRadius: BorderRadius.circular(AppRadius.pill),
            child: SizedBox(
              height: 9,
              child: total == 0
                  ? Container(color: AppColors.bg)
                  : Row(
                      children: [
                        for (final e in items)
                          if (e.$2 > 0)
                            Expanded(
                              flex: e.$2,
                              child: Container(
                                margin: const EdgeInsets.symmetric(horizontal: 0.5),
                                color: e.$3,
                              ),
                            ),
                      ],
                    ),
            ),
          ),
          const SizedBox(height: 16),
          Row(
            children: items
                .map((e) => Expanded(
                      child: Column(
                        children: [
                          CountUpText(
                            e.$2,
                            style: t.headlineSmall?.copyWith(
                                color: e.$3, fontWeight: FontWeight.w800, fontFeatures: _tnum),
                          ),
                          const SizedBox(height: 2),
                          Text(e.$1, style: t.bodySmall?.copyWith(color: AppColors.muted)),
                        ],
                      ),
                    ))
                .toList(),
          ),
        ],
      ),
    );
  }
}

class _DashboardSkeleton extends StatelessWidget {
  const _DashboardSkeleton();

  @override
  Widget build(BuildContext context) {
    Widget tile() => const GlassCard(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Skeleton(width: 34, height: 34, radius: 10),
            SizedBox(height: 12),
            Skeleton(width: 70, height: 26),
            SizedBox(height: 8),
            Skeleton(width: 90, height: 12),
          ]),
        );
    return SkeletonShimmer(
        child: Column(children: [
      GlassCard(
        child: Row(children: const [
          Skeleton(width: 118, height: 118, radius: 999),
          SizedBox(width: 18),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Skeleton(width: 120, height: 12),
              SizedBox(height: 12),
              Skeleton(width: 90, height: 30),
              SizedBox(height: 10),
              Skeleton(width: 140, height: 12),
            ]),
          ),
        ]),
      ),
      const SizedBox(height: 12),
      Row(children: [Expanded(child: tile()), const SizedBox(width: 12), Expanded(child: tile())]),
      const SizedBox(height: 12),
      Row(children: [Expanded(child: tile()), const SizedBox(width: 12), Expanded(child: tile())]),
    ]));
  }
}
