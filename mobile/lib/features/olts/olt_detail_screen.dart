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

class OltDetailScreen extends ConsumerWidget {
  const OltDetailScreen({super.key, required this.oltId});
  final int oltId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final detail = ref.watch(oltDetailProvider(oltId));

    return Scaffold(
      appBar: AppBar(
        title: const Text('Detail OLT'),
        actions: [
          detail.maybeWhen(
            data: (d) => d.cap('supports_provisioning')
                ? IconButton(
                    tooltip: 'Unconfigured ONU',
                    icon: const Icon(LucideIcons.searchCode, size: 20),
                    onPressed: () => context.push('/olts/$oltId/unconfigured'),
                  )
                : const SizedBox.shrink(),
            orElse: () => const SizedBox.shrink(),
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: () async => ref.refresh(oltDetailProvider(oltId).future),
        child: AsyncView<OltDetail>(
          value: detail,
          onRetry: () => ref.refresh(oltDetailProvider(oltId)),
          data: (d) => _Body(detail: d),
        ),
      ),
    );
  }
}

class _Body extends StatelessWidget {
  const _Body({required this.detail});
  final OltDetail detail;

  @override
  Widget build(BuildContext context) {
    final s = detail.summary;
    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 24),
      children: [
        GlassCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text(s.name,
                        style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 17)),
                  ),
                  StatusChip(
                    label: s.reachable ? 'Reachable' : 'Down',
                    color: s.reachable ? AppColors.success : AppColors.danger,
                  ),
                ],
              ),
              const SizedBox(height: 6),
              _kv('IP', s.ip),
              _kv('Family', s.familyLabel),
              if (detail.sysDescr != null) _kv('Deskripsi', detail.sysDescr!),
              _kv('Terakhir polling', Fmt.relative(s.lastPolledAt)),
            ],
          ),
        ),
        const SizedBox(height: 12),
        Row(
          children: [
            Expanded(child: _stat('ONU', '${Fmt.int(s.onuOnline)}/${Fmt.int(s.onuTotal)}', AppColors.primary)),
            const SizedBox(width: 12),
            Expanded(child: _stat('Port up', '${s.portsUp}/${s.portsTotal}', AppColors.secondary)),
            const SizedBox(width: 12),
            Expanded(child: _stat('Offline', Fmt.int(s.onuOffline), AppColors.danger)),
          ],
        ),
        const SizedBox(height: 18),
        const Padding(
          padding: EdgeInsets.only(left: 4, bottom: 8),
          child: Text('PON Port', style: TextStyle(fontWeight: FontWeight.w700)),
        ),
        if (detail.ports.isEmpty)
          const GlassCard(child: Text('Belum ada data port.', style: TextStyle(color: AppColors.muted)))
        else
          ...detail.ports.map((p) => Padding(
                padding: const EdgeInsets.only(bottom: 10),
                child: _PortRow(oltId: s.id, port: p),
              )),
      ],
    );
  }

  Widget _kv(String k, String v) => Padding(
        padding: const EdgeInsets.only(top: 4),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            SizedBox(width: 120, child: Text(k, style: const TextStyle(color: AppColors.muted, fontSize: 13))),
            Expanded(child: Text(v, style: const TextStyle(fontSize: 13))),
          ],
        ),
      );

  Widget _stat(String label, String value, Color color) => GlassCard(
        padding: const EdgeInsets.symmetric(vertical: 14, horizontal: 10),
        child: Column(
          children: [
            Text(value, style: TextStyle(color: color, fontWeight: FontWeight.w800, fontSize: 18)),
            const SizedBox(height: 2),
            Text(label, style: const TextStyle(color: AppColors.muted, fontSize: 11.5)),
          ],
        ),
      );
}

class _PortRow extends StatelessWidget {
  const _PortRow({required this.oltId, required this.port});
  final int oltId;
  final OltPort port;

  @override
  Widget build(BuildContext context) {
    return GlassCard(
      onTap: () => context.push('/olts/$oltId/ports/${port.slot}/${port.port}'),
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      child: Row(
        children: [
          Container(
            width: 8, height: 8,
            decoration: BoxDecoration(
              color: port.isUp ? AppColors.success : AppColors.danger,
              shape: BoxShape.circle,
            ),
          ),
          const SizedBox(width: 12),
          Text(port.name ?? 'Slot ${port.slot}/${port.port}',
              style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 14)),
          const Spacer(),
          Text('${port.onuOnline}/${port.onuTotal} ONU',
              style: const TextStyle(color: AppColors.muted, fontSize: 12.5)),
          const SizedBox(width: 8),
          const Icon(LucideIcons.chevronRight, color: AppColors.faint, size: 18),
        ],
      ),
    );
  }
}
