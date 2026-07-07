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

class DashboardScreen extends ConsumerWidget {
  const DashboardScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final user = ref.watch(authControllerProvider).user;
    final summary = ref.watch(summaryProvider);

    return Scaffold(
      appBar: AppBar(
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('Dashboard', style: TextStyle(fontSize: 18)),
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
        ],
      ),
      body: RefreshIndicator(
        onRefresh: () async => ref.refresh(summaryProvider.future),
        child: ListView(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
          children: [
            AsyncView<DashboardSummary>(
              value: summary,
              onRetry: () => ref.refresh(summaryProvider),
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
        backgroundColor: AppColors.bgElevated,
        title: const Text('Keluar?'),
        content: const Text('Anda akan keluar dari sesi ini.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Batal')),
          FilledButton(
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
        const SizedBox(height: 14),
        Row(
          children: [
            Expanded(child: _StatTile(
              icon: LucideIcons.server, label: 'OLT total',
              value: Fmt.int(summary.oltTotal),
              sub: '${summary.oltOnline} online · ${summary.oltOffline} offline',
              color: AppColors.secondary,
            )),
            const SizedBox(width: 12),
            Expanded(child: _StatTile(
              icon: LucideIcons.router, label: 'ONU total',
              value: Fmt.int(summary.onuTotal),
              sub: '${Fmt.int(summary.onuOnline)} online',
              color: AppColors.primary,
            )),
          ],
        ),
        const SizedBox(height: 12),
        Row(
          children: [
            Expanded(child: _StatTile(
              icon: LucideIcons.wifiOff, label: 'ONU offline',
              value: Fmt.int(summary.onuOffline),
              sub: '${Fmt.int(summary.onuWarning)} warning RX',
              color: AppColors.danger,
            )),
            const SizedBox(width: 12),
            Expanded(child: _StatTile(
              icon: LucideIcons.bellRing, label: 'Alarm aktif',
              value: Fmt.int(summary.alarmTotal),
              sub: '${summary.alarmCritical} kritis',
              color: AppColors.warning,
              onTap: () => context.go('/alarms'),
            )),
          ],
        ),
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
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              const Text('ONU online', style: TextStyle(color: AppColors.muted)),
              Text('${share.toStringAsFixed(1)}%',
                  style: const TextStyle(
                      color: AppColors.success, fontWeight: FontWeight.w800, fontSize: 18)),
            ],
          ),
          const SizedBox(height: 12),
          ClipRRect(
            borderRadius: BorderRadius.circular(999),
            child: LinearProgressIndicator(
              value: share / 100,
              minHeight: 10,
              backgroundColor: AppColors.bg,
              valueColor: const AlwaysStoppedAnimation(AppColors.success),
            ),
          ),
          const SizedBox(height: 8),
          Text('${Fmt.int(summary.onuOnline)} dari ${Fmt.int(summary.onuTotal)} ONU aktif',
              style: const TextStyle(color: AppColors.faint, fontSize: 12)),
        ],
      ),
    );
  }
}

class _StatTile extends StatelessWidget {
  const _StatTile({
    required this.icon,
    required this.label,
    required this.value,
    required this.sub,
    required this.color,
    this.onTap,
  });

  final IconData icon;
  final String label, value, sub;
  final Color color;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    return GlassCard(
      onTap: onTap,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, color: color, size: 22),
          const SizedBox(height: 10),
          Text(value, style: const TextStyle(fontSize: 26, fontWeight: FontWeight.w800)),
          const SizedBox(height: 2),
          Text(label, style: const TextStyle(color: AppColors.muted, fontSize: 13)),
          const SizedBox(height: 2),
          Text(sub, style: const TextStyle(color: AppColors.faint, fontSize: 11.5)),
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
    return GlassCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text('Rincian alarm aktif',
              style: TextStyle(fontWeight: FontWeight.w700)),
          const SizedBox(height: 12),
          Row(
            children: items
                .map((e) => Expanded(
                      child: Column(
                        children: [
                          Text(Fmt.int(e.$2),
                              style: TextStyle(
                                  color: e.$3, fontWeight: FontWeight.w800, fontSize: 20)),
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
