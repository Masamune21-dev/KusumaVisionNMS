import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:kusumavision_nms/core/icons.dart';

import '../../core/format.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/glass_card.dart';
import '../../core/widgets/rx_power_badge.dart';
import '../../core/widgets/status_chip.dart';
import '../../data/read_providers.dart';
import '../../models/onu.dart';
import '../../theme/app_theme.dart';

class PortOnusScreen extends ConsumerStatefulWidget {
  const PortOnusScreen({
    super.key,
    required this.oltId,
    required this.slot,
    required this.port,
    this.focusOnuId,
  });

  final int oltId, slot, port;
  final int? focusOnuId;

  @override
  ConsumerState<PortOnusScreen> createState() => _PortOnusScreenState();
}

class _PortOnusScreenState extends ConsumerState<PortOnusScreen> {
  String _filter = '';

  @override
  Widget build(BuildContext context) {
    final arg = (oltId: widget.oltId, slot: widget.slot, port: widget.port);
    final data = ref.watch(portOnusProvider(arg));

    return Scaffold(
      appBar: AppBar(title: Text('Port ${widget.slot}/${widget.port}')),
      body: RefreshIndicator(
        onRefresh: () async => ref.refresh(portOnusProvider(arg).future),
        child: AsyncView<({List<Onu> onus, String? refreshedAt})>(
          value: data,
          onRetry: () => ref.refresh(portOnusProvider(arg)),
          data: (res) {
            final onus = _apply(res.onus);
            return Column(
              children: [
                Padding(
                  padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
                  child: TextField(
                    decoration: const InputDecoration(
                      hintText: 'Cari SN / nama / interface',
                      prefixIcon: Icon(LucideIcons.search, size: 18),
                      isDense: true,
                    ),
                    onChanged: (v) => setState(() => _filter = v.toLowerCase()),
                  ),
                ),
                Padding(
                  padding: const EdgeInsets.fromLTRB(18, 4, 18, 4),
                  child: Row(
                    children: [
                      Text('${onus.length} ONU',
                          style: const TextStyle(color: AppColors.muted, fontSize: 12)),
                      const Spacer(),
                      Text('Refresh: ${Fmt.relative(res.refreshedAt)}',
                          style: const TextStyle(color: AppColors.faint, fontSize: 11.5)),
                    ],
                  ),
                ),
                Expanded(
                  child: onus.isEmpty
                      ? const EmptyState(message: 'Tidak ada ONU cocok.', icon: LucideIcons.router)
                      : ListView.separated(
                          padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
                          itemCount: onus.length,
                          separatorBuilder: (_, __) => const SizedBox(height: 10),
                          itemBuilder: (_, i) => _OnuRow(
                            onu: onus[i],
                            highlight: onus[i].onuId == widget.focusOnuId,
                          ),
                        ),
                ),
              ],
            );
          },
        ),
      ),
    );
  }

  List<Onu> _apply(List<Onu> list) {
    if (_filter.isEmpty) return list;
    return list.where((o) {
      final hay = [
        o.serialNumber, o.mac, o.name, o.customerName, o.interface,
      ].whereType<String>().join(' ').toLowerCase();
      return hay.contains(_filter);
    }).toList();
  }
}

class _OnuRow extends StatelessWidget {
  const _OnuRow({required this.onu, this.highlight = false});
  final Onu onu;
  final bool highlight;

  @override
  Widget build(BuildContext context) {
    final card = GlassCard(
      onTap: () => context.push(
          '/olts/${onu.oltId}/ports/${onu.slot}/${onu.port}/onus/${onu.onuId}'),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(onu.title,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14)),
                const SizedBox(height: 3),
                Text(
                  '#${onu.onuId} · ${onu.serialNumber ?? onu.mac ?? '-'}',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(color: AppColors.muted, fontSize: 12),
                ),
              ],
            ),
          ),
          const SizedBox(width: 8),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              StatusChip.online(onu.online),
              const SizedBox(height: 6),
              RxPowerBadge(dbm: onu.rxPowerDbm, online: onu.online),
            ],
          ),
        ],
      ),
    );

    if (!highlight) return card;
    return Container(
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: AppColors.primary.withValues(alpha: 0.7), width: 1.5),
      ),
      child: card,
    );
  }
}
