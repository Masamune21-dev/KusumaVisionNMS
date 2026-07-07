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
        color: AppColors.primary,
        backgroundColor: AppColors.surfaceAlt,
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
                  Container(
                    padding: const EdgeInsets.all(10),
                    decoration: BoxDecoration(
                      color: AppColors.secondary.withValues(alpha: 0.13),
                      borderRadius: BorderRadius.circular(AppRadius.chip),
                    ),
                    child: const Icon(LucideIcons.server, color: AppColors.secondary, size: 22),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Text(s.name,
                        style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 17, letterSpacing: -0.3)),
                  ),
                  const SizedBox(width: 8),
                  StatusChip.reachable(s.reachable),
                ],
              ),
              const SizedBox(height: 14),
              const Divider(height: 1),
              const SizedBox(height: 10),
              _kv('IP', s.ip, mono: true),
              _kv('Family', s.familyLabel),
              if (detail.sysDescr != null) _kv('Deskripsi', detail.sysDescr!),
              _kv('Terakhir polling', Fmt.relative(s.lastPolledAt)),
            ],
          ),
        ),
        const SizedBox(height: 12),
        Row(
          children: [
            Expanded(child: _stat(LucideIcons.router, 'ONU', '${Fmt.int(s.onuOnline)}/${Fmt.int(s.onuTotal)}', AppColors.primary)),
            const SizedBox(width: 10),
            Expanded(child: _stat(LucideIcons.network, 'Port up', '${s.portsUp}/${s.portsTotal}', AppColors.secondary)),
            const SizedBox(width: 10),
            Expanded(child: _stat(LucideIcons.wifiOff, 'Offline', Fmt.int(s.onuOffline), AppColors.danger)),
          ],
        ),
        const SizedBox(height: 20),
        SectionTitle('PON Port', icon: LucideIcons.network),
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

  Widget _kv(String k, String v, {bool mono = false}) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 5),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            SizedBox(width: 118, child: Text(k, style: const TextStyle(color: AppColors.muted, fontSize: 13))),
            Expanded(
                child: Text(v,
                    style: TextStyle(fontSize: 13, height: 1.35, fontFeatures: mono ? _tnum : null))),
          ],
        ),
      );

  Widget _stat(IconData icon, String label, String value, Color color) => GlassCard(
        padding: const EdgeInsets.symmetric(vertical: 14, horizontal: 10),
        child: Column(
          children: [
            Icon(icon, size: 16, color: color),
            const SizedBox(height: 8),
            FittedBox(
              fit: BoxFit.scaleDown,
              child: Text(value,
                  style: TextStyle(color: color, fontWeight: FontWeight.w900, fontSize: 19, fontFeatures: _tnum)),
            ),
            const SizedBox(height: 3),
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
    final share = port.onuTotal > 0 ? port.onuOnline / port.onuTotal : null;
    return GlassCard(
      onTap: () => context.push('/olts/$oltId/ports/${port.slot}/${port.port}'),
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      child: Row(
        children: [
          Container(
            width: 9, height: 9,
            decoration: BoxDecoration(
              color: port.isUp ? AppColors.success : AppColors.danger,
              shape: BoxShape.circle,
              boxShadow: [
                BoxShadow(
                    color: (port.isUp ? AppColors.success : AppColors.danger).withValues(alpha: 0.6),
                    blurRadius: 5)
              ],
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(port.name ?? 'Slot ${port.slot}/${port.port}',
                    style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 14)),
                if (share != null) ...[
                  const SizedBox(height: 7),
                  ClipRRect(
                    borderRadius: BorderRadius.circular(AppRadius.pill),
                    child: LinearProgressIndicator(
                      value: share.clamp(0.0, 1.0),
                      minHeight: 4,
                      backgroundColor: AppColors.surfaceAlt,
                      valueColor: const AlwaysStoppedAnimation(AppColors.primary),
                    ),
                  ),
                ],
              ],
            ),
          ),
          const SizedBox(width: 12),
          Text('${port.onuOnline}/${port.onuTotal}',
              style: const TextStyle(color: AppColors.text, fontSize: 13, fontWeight: FontWeight.w700, fontFeatures: _tnum)),
          const Text(' ONU', style: TextStyle(color: AppColors.faint, fontSize: 11.5)),
          const SizedBox(width: 4),
          const Icon(LucideIcons.chevronRight, color: AppColors.faint, size: 18),
        ],
      ),
    );
  }
}
