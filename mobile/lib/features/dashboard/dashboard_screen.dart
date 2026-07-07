import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:kusumavision_nms/core/icons.dart';

import '../../core/format.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/glass_card.dart';
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
      appBar: AppBar(
        titleSpacing: 16,
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('Dashboard',
                style: TextStyle(fontSize: 19, fontWeight: FontWeight.w800, letterSpacing: -0.3)),
            if (user != null)
              Text('${user.name} · ${user.roleLabel}',
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
      body: RefreshIndicator(
        onRefresh: () async => ref.refresh(summaryProvider.future),
        color: AppColors.primary,
        backgroundColor: AppColors.surfaceAlt,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 28),
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
            style: FilledButton.styleFrom(backgroundColor: AppColors.danger, foregroundColor: Colors.white),
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
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _OnlineShareCard(summary: summary),
        const SizedBox(height: 12),
        Row(children: [
          Expanded(
            child: _StatCard(
              icon: LucideIcons.server,
              watermark: LucideIcons.serverFilled,
              label: 'OLT total',
              value: Fmt.int(summary.oltTotal),
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
              value: Fmt.int(summary.onuTotal),
              sub: '${Fmt.int(summary.onuOnline)} online',
              color: AppColors.primary,
            ),
          ),
        ]),
        const SizedBox(height: 12),
        Row(children: [
          Expanded(
            child: _StatCard(
              icon: LucideIcons.wifiOff,
              watermark: LucideIcons.wifiOff,
              label: 'ONU offline',
              value: Fmt.int(summary.onuOffline),
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
              value: Fmt.int(summary.alarmTotal),
              sub: '${summary.alarmCritical} kritis',
              color: AppColors.warning,
              onTap: () => context.go('/alarms'),
            ),
          ),
        ]),
        const SizedBox(height: 16),
        _AlarmBreakdown(summary: summary),
      ],
    );
  }
}

class _OnlineShareCard extends StatelessWidget {
  const _OnlineShareCard({required this.summary});
  final DashboardSummary summary;

  @override
  Widget build(BuildContext context) {
    final share = (summary.onlineShare.toDouble()).clamp(0, 100).toDouble();
    return GlassCard(
      padding: const EdgeInsets.all(18),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(children: const [
                    Icon(LucideIcons.activity, size: 15, color: AppColors.success),
                    SizedBox(width: 7),
                    Text('ONU online', style: TextStyle(color: AppColors.muted, fontSize: 13.5)),
                  ]),
                  const SizedBox(height: 2),
                  Text('${Fmt.int(summary.onuOnline)} dari ${Fmt.int(summary.onuTotal)} aktif',
                      style: const TextStyle(color: AppColors.faint, fontSize: 12, fontFeatures: _tnum)),
                ],
              ),
              const Spacer(),
              Text(
                '${share.toStringAsFixed(1)}%',
                style: const TextStyle(
                  color: AppColors.success,
                  fontWeight: FontWeight.w900,
                  fontSize: 38,
                  height: 1,
                  letterSpacing: -1.5,
                  fontFeatures: _tnum,
                  shadows: [Shadow(color: Color(0x8034D399), blurRadius: 18)],
                ),
              ),
            ],
          ),
          const SizedBox(height: 14),
          ClipRRect(
            borderRadius: BorderRadius.circular(AppRadius.pill),
            child: Stack(children: [
              Container(height: 12, color: AppColors.bg),
              FractionallySizedBox(
                widthFactor: (share / 100).clamp(0.0, 1.0),
                child: Container(
                  height: 12,
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(AppRadius.pill),
                    gradient: const LinearGradient(
                      colors: [Color(0xFF10B981), AppColors.success],
                    ),
                    boxShadow: [BoxShadow(color: AppColors.success.withValues(alpha: 0.5), blurRadius: 8)],
                  ),
                ),
              ),
            ]),
          ),
        ],
      ),
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
  final String label, value, sub;
  final Color color;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    return GlassCard(
      onTap: onTap,
      padding: EdgeInsets.zero,
      child: Stack(
        clipBehavior: Clip.hardEdge,
        children: [
          // Watermark ikon besar transparan di latar.
          Positioned(
            right: -14,
            bottom: -16,
            child: Icon(watermark, size: 92, color: color.withValues(alpha: 0.07)),
          ),
          Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
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
                Text(value,
                    style: const TextStyle(
                        fontSize: 30, fontWeight: FontWeight.w900, height: 1, letterSpacing: -1, fontFeatures: _tnum)),
                const SizedBox(height: 5),
                Text(label, style: const TextStyle(color: AppColors.text, fontSize: 13, fontWeight: FontWeight.w600)),
                const SizedBox(height: 2),
                Text(sub,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(color: AppColors.faint, fontSize: 11.5, fontFeatures: _tnum)),
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

    return GlassCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(children: const [
            Icon(LucideIcons.bellRing, size: 16, color: AppColors.warning),
            SizedBox(width: 8),
            Text('Rincian alarm aktif', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 14.5)),
          ]),
          const SizedBox(height: 14),
          // Bar proporsi tersegmentasi.
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
                          Text(Fmt.int(e.$2),
                              style: TextStyle(
                                  color: e.$3, fontWeight: FontWeight.w900, fontSize: 22, fontFeatures: _tnum)),
                          const SizedBox(height: 2),
                          Text(e.$1, style: const TextStyle(color: AppColors.muted, fontSize: 11)),
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
    return Column(children: [
      const GlassCard(
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [Skeleton(width: 120, height: 14), Spacer(), Skeleton(width: 90, height: 34)]),
          SizedBox(height: 16),
          Skeleton(height: 12, radius: 999),
        ]),
      ),
      const SizedBox(height: 12),
      Row(children: [Expanded(child: tile()), const SizedBox(width: 12), Expanded(child: tile())]),
      const SizedBox(height: 12),
      Row(children: [Expanded(child: tile()), const SizedBox(width: 12), Expanded(child: tile())]),
    ]);
  }
}
