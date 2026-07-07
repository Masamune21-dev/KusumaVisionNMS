import 'package:flutter/material.dart';
import 'package:flutter_animate/flutter_animate.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_staggered_animations/flutter_staggered_animations.dart';
import 'package:go_router/go_router.dart';
import 'package:kusumavision_nms/core/icons.dart';

import '../../core/format.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/aurora_background.dart';
import '../../core/widgets/glass_card.dart';
import '../../core/widgets/pulse_dot.dart';
import '../../core/widgets/signal_ring.dart';
import '../../core/widgets/stagger.dart';
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
      body: AuroraBackground(
        intensity: 0.55,
        child: RefreshIndicator(
          onRefresh: () async => ref.refresh(oltDetailProvider(oltId).future),
          color: AppColors.primary,
          backgroundColor: AppColors.surfaceAlt,
          child: AsyncView<OltDetail>(
            value: detail,
            onRetry: () => ref.refresh(oltDetailProvider(oltId)),
            data: (d) => _Body(detail: d),
          ),
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
    final share = s.onuTotal > 0 ? s.onuOnline / s.onuTotal * 100 : 0.0;
    final healthColor = s.reachable
        ? (share >= 90 ? AppColors.success : AppColors.warning)
        : AppColors.danger;

    Widget seq(int i, Widget child) => child
        .animate(delay: (i * 70).ms)
        .fadeIn(duration: AppMotion.base)
        .slideY(begin: 0.12, curve: AppMotion.enter);

    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 24),
      children: [
        // Hero: identitas + gauge kesehatan ONU.
        seq(
          0,
          GlassCard(
            blur: true,
            padding: const EdgeInsets.all(18),
            child: Row(
              children: [
                SignalRing(percent: share, size: 96, color: healthColor),
                const SizedBox(width: 18),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(s.name,
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                          style: Theme.of(context).textTheme.titleLarge),
                      const SizedBox(height: 6),
                      Text(s.familyLabel,
                          style: const TextStyle(
                              color: AppColors.secondary, fontSize: 12.5, fontWeight: FontWeight.w600)),
                      const SizedBox(height: 8),
                      _ReachChip(reachable: s.reachable),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ),
        const SizedBox(height: 12),
        seq(
          1,
          Row(
            children: [
              Expanded(child: _stat(LucideIcons.router, 'ONU', '${Fmt.int(s.onuOnline)}/${Fmt.int(s.onuTotal)}', AppColors.primary)),
              const SizedBox(width: 10),
              Expanded(child: _stat(LucideIcons.network, 'Port up', '${s.portsUp}/${s.portsTotal}', AppColors.secondary)),
              const SizedBox(width: 10),
              Expanded(child: _stat(LucideIcons.wifiOff, 'Offline', Fmt.int(s.onuOffline), AppColors.danger)),
            ],
          ),
        ),
        const SizedBox(height: 12),
        seq(
          2,
          GlassCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                _kv('IP', s.ip, mono: true),
                if (detail.sysDescr != null) _kv('Deskripsi', detail.sysDescr!),
                if (detail.sysUptime != null) _kv('Uptime', detail.sysUptime!, mono: true),
                _kv('Terakhir polling', Fmt.relative(s.lastPolledAt)),
              ],
            ),
          ),
        ),
        const SizedBox(height: 20),
        SectionTitle('PON Port', icon: LucideIcons.network),
        if (detail.ports.isEmpty)
          const GlassCard(child: Text('Belum ada data port.', style: TextStyle(color: AppColors.muted)))
        else
          AnimationLimiter(
            child: Column(
              children: [
                for (var i = 0; i < detail.ports.length; i++)
                  staggeredItem(
                    i,
                    Padding(
                      padding: const EdgeInsets.only(bottom: 10),
                      child: _PortRow(oltId: s.id, port: detail.ports[i]),
                    ),
                  ),
              ],
            ),
          ),
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

class _PortRow extends StatelessWidget {
  const _PortRow({required this.oltId, required this.port});
  final int oltId;
  final OltPort port;

  @override
  Widget build(BuildContext context) {
    final share = port.onuTotal > 0 ? port.onuOnline / port.onuTotal : null;
    final color = port.isUp ? AppColors.success : AppColors.danger;
    return GlassCard(
      onTap: () => context.push('/olts/$oltId/ports/${port.slot}/${port.port}'),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
      child: Row(
        children: [
          PulseDot(color: color, size: 8, pulse: port.isUp),
          const SizedBox(width: 10),
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
