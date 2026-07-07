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

class OltListScreen extends ConsumerWidget {
  const OltListScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final olts = ref.watch(oltsProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Inventory OLT')),
      body: RefreshIndicator(
        onRefresh: () async => ref.refresh(oltsProvider.future),
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
              Expanded(
                child: Text(olt.name,
                    style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
              ),
              StatusChip(
                label: olt.reachable ? 'Reachable' : 'Down',
                color: olt.reachable ? AppColors.success : AppColors.danger,
              ),
            ],
          ),
          const SizedBox(height: 4),
          Row(
            children: [
              Text(olt.ip, style: const TextStyle(color: AppColors.muted, fontSize: 12.5)),
              const SizedBox(width: 8),
              Container(width: 3, height: 3, decoration: const BoxDecoration(
                  color: AppColors.faint, shape: BoxShape.circle)),
              const SizedBox(width: 8),
              Text(olt.familyLabel, style: const TextStyle(color: AppColors.secondary, fontSize: 12.5)),
            ],
          ),
          const SizedBox(height: 12),
          Row(
            children: [
              _mini(LucideIcons.router, '${Fmt.int(olt.onuOnline)}/${Fmt.int(olt.onuTotal)}', 'ONU online'),
              const SizedBox(width: 16),
              _mini(LucideIcons.network, '${olt.portsUp}/${olt.portsTotal}', 'Port up'),
              const Spacer(),
              const Icon(LucideIcons.chevronRight, color: AppColors.faint, size: 18),
            ],
          ),
        ],
      ),
    );
  }

  Widget _mini(IconData icon, String value, String label) {
    return Row(
      children: [
        Icon(icon, size: 15, color: AppColors.faint),
        const SizedBox(width: 6),
        Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(value, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
            Text(label, style: const TextStyle(color: AppColors.faint, fontSize: 10.5)),
          ],
        ),
      ],
    );
  }
}
