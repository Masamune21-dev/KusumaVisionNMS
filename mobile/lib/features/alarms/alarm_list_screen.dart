import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:kusumavision_nms/core/icons.dart';

import '../../core/format.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/glass_card.dart';
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

    return Scaffold(
      appBar: AppBar(title: const Text('Alarm')),
      body: Column(
        children: [
          SizedBox(
            height: 46,
            child: ListView(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
              children: _severities.map((s) {
                final active = selected == s.$1;
                return Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 4),
                  child: ChoiceChip(
                    label: Text(s.$2),
                    selected: active,
                    onSelected: (_) => ref.read(alarmSeverityProvider.notifier).state = s.$1,
                    backgroundColor: AppColors.surface.withValues(alpha: 0.5),
                    selectedColor: AppColors.primary.withValues(alpha: 0.22),
                    side: BorderSide(
                        color: active ? AppColors.primary : AppColors.border),
                    labelStyle: TextStyle(
                        color: active ? AppColors.primary : AppColors.muted,
                        fontWeight: FontWeight.w600,
                        fontSize: 12.5),
                  ),
                );
              }).toList(),
            ),
          ),
          Expanded(
            child: RefreshIndicator(
              onRefresh: () async => ref.refresh(alarmsProvider.future),
              child: AsyncView<({List<Alarm> alarms, int total})>(
                value: data,
                onRetry: () => ref.refresh(alarmsProvider),
                data: (res) {
                  if (res.alarms.isEmpty) {
                    return ListView(children: const [
                      SizedBox(height: 80),
                      EmptyState(message: 'Tidak ada alarm aktif. 🎉', icon: LucideIcons.bellOff),
                    ]);
                  }
                  return ListView.separated(
                    padding: const EdgeInsets.fromLTRB(16, 10, 16, 24),
                    itemCount: res.alarms.length,
                    separatorBuilder: (_, __) => const SizedBox(height: 10),
                    itemBuilder: (_, i) => _AlarmCard(alarm: res.alarms[i]),
                  );
                },
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _AlarmCard extends StatelessWidget {
  const _AlarmCard({required this.alarm});
  final Alarm alarm;

  @override
  Widget build(BuildContext context) {
    final color = AppColors.severity(alarm.severity);
    final canOpen = alarm.oltId != null && alarm.slot != null && alarm.port != null && alarm.onuId != null;

    return GlassCard(
      onTap: canOpen
          ? () => context.push(
              '/olts/${alarm.oltId}/ports/${alarm.slot}/${alarm.port}/onus/${alarm.onuId}')
          : (alarm.oltId != null ? () => context.push('/olts/${alarm.oltId}') : null),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(width: 4, height: 42, decoration: BoxDecoration(
              color: color, borderRadius: BorderRadius.circular(4))),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Text(alarm.severity.toUpperCase(),
                        style: TextStyle(color: color, fontWeight: FontWeight.w800, fontSize: 11)),
                    const SizedBox(width: 8),
                    Text(alarm.typeLabel,
                        style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
                    const Spacer(),
                    Text(Fmt.relative(alarm.lastSeenAt),
                        style: const TextStyle(color: AppColors.faint, fontSize: 11)),
                  ],
                ),
                const SizedBox(height: 4),
                Text(alarm.message ?? '-',
                    style: const TextStyle(color: AppColors.text, fontSize: 13)),
                const SizedBox(height: 2),
                Text(
                  [
                    alarm.oltName,
                    if (alarm.serialNumber != null) alarm.serialNumber,
                  ].whereType<String>().join(' · '),
                  style: const TextStyle(color: AppColors.muted, fontSize: 11.5),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
