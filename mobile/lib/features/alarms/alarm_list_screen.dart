import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_staggered_animations/flutter_staggered_animations.dart';
import 'package:go_router/go_router.dart';
import 'package:kusumavision_nms/core/icons.dart';

import '../../core/format.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/aurora_background.dart';
import '../../core/widgets/glass_card.dart';
import '../../core/widgets/stagger.dart';
import '../../data/read_providers.dart';
import '../../models/alarm.dart';
import '../../theme/app_theme.dart';

class AlarmListScreen extends ConsumerWidget {
  const AlarmListScreen({super.key});

  static const _severities = [
    (null, 'Semua'),
    ('critical', 'Kritis'),
    ('major', 'Mayor'),
    ('minor', 'Minor'),
    ('warning', 'Warning'),
  ];

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final selected = ref.watch(alarmSeverityProvider);
    final data = ref.watch(alarmsProvider);
    final topInset = MediaQuery.of(context).padding.top + kToolbarHeight;
    // Layar ini di-push (bukan tab lagi), jadi tak ada navbar melayang yang
    // perlu diberi ruang — cukup inset sistem + sedikit napas.
    final bottomInset = MediaQuery.of(context).viewPadding.bottom + 24;

    return Scaffold(
      extendBodyBehindAppBar: true,
      appBar: AppBar(backgroundColor: Colors.transparent, title: const Text('Alarm')),
      body: AuroraBackground(
        intensity: 0.6,
        child: Column(
          children: [
            SizedBox(height: topInset),
            SizedBox(
              height: 50,
              child: ListView.separated(
                scrollDirection: Axis.horizontal,
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                itemCount: _severities.length,
                separatorBuilder: (_, __) => const SizedBox(width: 8),
                itemBuilder: (_, i) {
                  final s = _severities[i];
                  final active = selected == s.$1;
                  final tint = s.$1 == null ? AppColors.primary : AppColors.severity(s.$1!);
                  return GestureDetector(
                    onTap: () => ref.read(alarmSeverityProvider.notifier).state = s.$1,
                    child: AnimatedContainer(
                      duration: AppMotion.fast,
                      curve: AppMotion.enter,
                      padding: const EdgeInsets.symmetric(horizontal: 16),
                      alignment: Alignment.center,
                      decoration: BoxDecoration(
                        color: active ? tint.withValues(alpha: 0.16) : AppColors.surface.withValues(alpha: 0.7),
                        borderRadius: BorderRadius.circular(AppRadius.pill),
                        border: Border.all(color: active ? tint.withValues(alpha: 0.6) : AppColors.border),
                      ),
                      child: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          if (s.$1 != null) ...[
                            Container(
                              width: 7,
                              height: 7,
                              decoration: BoxDecoration(color: tint, shape: BoxShape.circle),
                            ),
                            const SizedBox(width: 7),
                          ],
                          Text(s.$2,
                              style: TextStyle(
                                  color: active ? tint : AppColors.muted,
                                  fontWeight: FontWeight.w700,
                                  fontSize: 12.5)),
                        ],
                      ),
                    ),
                  );
                },
              ),
            ),
            Expanded(
              child: RefreshIndicator(
                onRefresh: () async => ref.refresh(alarmsProvider.future),
                color: AppColors.primary,
                backgroundColor: AppColors.surfaceAlt,
                child: AsyncView<({List<Alarm> alarms, int total})>(
                  value: data,
                  onRetry: () => ref.refresh(alarmsProvider),
                  data: (res) {
                    if (res.alarms.isEmpty) {
                      return ListView(children: const [
                        SizedBox(height: 70),
                        EmptyState(message: 'Tidak ada alarm aktif.', icon: LucideIcons.checkCircle),
                      ]);
                    }
                    return AnimationLimiter(
                      child: ListView.separated(
                        padding: EdgeInsets.fromLTRB(16, 8, 16, bottomInset),
                        itemCount: res.alarms.length,
                        separatorBuilder: (_, __) => const SizedBox(height: 10),
                        itemBuilder: (_, i) => staggeredItem(i, _AlarmCard(alarm: res.alarms[i])),
                      ),
                    );
                  },
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _AlarmCard extends StatelessWidget {
  const _AlarmCard({required this.alarm});
  final Alarm alarm;

  IconData get _icon => switch (alarm.severity) {
        'critical' || 'major' => LucideIcons.alertTriangle,
        'warning' => LucideIcons.signal,
        _ => LucideIcons.bellRing,
      };

  /// Navegación basada en la ubicación que RESOLVIÓ el servidor, no en los IDs históricos
  /// del evento: si la ONU se movió abre su posición actual, y si esa posición la ocupa
  /// ahora otra ONU (serial distinto) no se abre para no mostrar el cliente equivocado.
  VoidCallback? _onTap(BuildContext context) {
    final target = alarm.target;

    if (target != null && target.openable) {
      if (target.isOnu) {
        return () => context.push(
            '/olts/${target.oltId}/ports/${target.slot}/${target.port}/onus/${target.onuId}');
      }
      if (target.isPort) {
        return () => context.push('/olts/${target.oltId}/ports/${target.slot}/${target.port}');
      }
    }

    // Sin destino fiable (o servidor sin `target`): como máximo el OLT.
    final oltId = target?.oltId ?? alarm.oltId;

    return oltId != null ? () => context.push('/olts/$oltId') : null;
  }

  @override
  Widget build(BuildContext context) {
    final color = AppColors.severity(alarm.severity);
    final t = Theme.of(context).textTheme;

    return GlassCard(
      accent: alarm.severity == 'critical' ? color : null,
      onTap: _onTap(context),
      padding: const EdgeInsets.all(14),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            padding: const EdgeInsets.all(9),
            decoration: BoxDecoration(
              color: color.withValues(alpha: 0.14),
              borderRadius: BorderRadius.circular(AppRadius.chip),
            ),
            child: Icon(_icon, color: color, size: 18),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    _SevBadge(severity: alarm.severity, color: color),
                    const Spacer(),
                    Text(Fmt.relative(alarm.lastSeenAt),
                        style: t.bodySmall?.copyWith(color: AppColors.faint)),
                  ],
                ),
                const SizedBox(height: 7),
                Text(alarm.typeLabel, style: t.titleSmall),
                const SizedBox(height: 3),
                Text(alarm.message ?? '-',
                    style: t.bodySmall?.copyWith(color: AppColors.muted, height: 1.35)),
                if (alarm.customerName != null) ...[
                  const SizedBox(height: 6),
                  Row(
                    children: [
                      const Icon(LucideIcons.user, size: 12, color: AppColors.primary),
                      const SizedBox(width: 5),
                      Expanded(
                        child: Text(
                          alarm.customerName!,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: t.bodySmall?.copyWith(
                              color: AppColors.text, fontWeight: FontWeight.w600),
                        ),
                      ),
                    ],
                  ),
                ],
                const SizedBox(height: 6),
                Row(
                  children: [
                    const Icon(LucideIcons.server, size: 12, color: AppColors.faint),
                    const SizedBox(width: 5),
                    Expanded(
                      child: Text(
                        [
                          alarm.oltName,
                          if (alarm.serialNumber != null) alarm.serialNumber,
                        ].whereType<String>().join(' · '),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: t.bodySmall?.copyWith(color: AppColors.faint),
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _SevBadge extends StatelessWidget {
  const _SevBadge({required this.severity, required this.color});
  final String severity;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.16),
        borderRadius: BorderRadius.circular(6),
      ),
      child: Text(
        severity.toUpperCase(),
        style: TextStyle(color: color, fontWeight: FontWeight.w800, fontSize: 10.5, letterSpacing: 0.5),
      ),
    );
  }
}
