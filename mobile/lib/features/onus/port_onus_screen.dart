import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_staggered_animations/flutter_staggered_animations.dart';
import 'package:go_router/go_router.dart';
import 'package:kusumavision_nms/core/icons.dart';

import '../../core/api/api_exception.dart';
import '../../core/format.dart';
import '../../core/providers.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/aurora_background.dart';
import '../../core/widgets/glass_card.dart';
import '../../core/widgets/rx_power_badge.dart';
import '../../core/widgets/stagger.dart';
import '../../core/widgets/status_chip.dart';
import '../../data/read_providers.dart';
import '../../models/onu.dart';
import '../../theme/app_theme.dart';
import '../auth/auth_controller.dart';

const _tnum = [FontFeature.tabularFigures()];

class PortOnusScreen extends ConsumerStatefulWidget {
  const PortOnusScreen({
    super.key,
    required this.oltId,
    required this.slot,
    required this.port,
    this.focusOnuId,
    this.initialFilter,
  });

  final int oltId, slot, port;
  final int? focusOnuId;

  /// Filter awal untuk kotak cari (dipakai dari pencarian global agar hanya ONU
  /// yang dicari yang tampil). Null = daftar penuh.
  final String? initialFilter;

  @override
  ConsumerState<PortOnusScreen> createState() => _PortOnusScreenState();
}

class _PortOnusScreenState extends ConsumerState<PortOnusScreen> {
  late final TextEditingController _search =
      TextEditingController(text: widget.initialFilter ?? '');
  late String _filter = (widget.initialFilter ?? '').trim().toLowerCase();
  bool _refreshing = false;

  PortArg get _arg => (oltId: widget.oltId, slot: widget.slot, port: widget.port);

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  /// Walk SNMP live subtree port ini (bukan baca cache polling) lalu muat ulang.
  Future<void> _refreshLive() async {
    setState(() => _refreshing = true);
    try {
      final res = await ref.read(nmsApiProvider).refreshPort(widget.oltId, widget.slot, widget.port);
      ref.invalidate(portOnusProvider(_arg));
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text('Data live diperbarui — ${res['count'] ?? 0} ONU.'),
        backgroundColor: AppColors.success.withValues(alpha: 0.95),
      ));
    } on ApiException catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text(e.message),
          backgroundColor: AppColors.danger.withValues(alpha: 0.95),
        ));
      }
    } finally {
      if (mounted) setState(() => _refreshing = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final data = ref.watch(portOnusProvider(_arg));
    // Refresh live per-port didukung semua family (ZTE: walk subtree; non-ZTE: query
    // per-port lewat driver). Cukup gate izin tulis (admin/operator, non-demo).
    final canRefreshLive = ref.watch(authControllerProvider).user?.canWrite ?? false;

    return Scaffold(
      appBar: AppBar(
        title: Text('Port ${widget.slot}/${widget.port}'),
        actions: [
          if (canRefreshLive)
            IconButton(
              tooltip: 'Ambil data terbaru (live)',
              onPressed: _refreshing ? null : _refreshLive,
              icon: _refreshing
                  ? const SizedBox(height: 18, width: 18, child: CircularProgressIndicator(strokeWidth: 2))
                  : const Icon(LucideIcons.refreshCw, size: 20),
            ),
          const SizedBox(width: 4),
        ],
      ),
      // Jala node-fiber + animasi kini aktif juga di sini — dulu dimatikan demi
      // daftar panjang, tapi painter aurora sudah murah (tanpa blur raksasa,
      // repaint terquantisasi ~18fps + RepaintBoundary) jadi aman.
      body: AuroraBackground(
        intensity: 0.5,
        child: RefreshIndicator(
        onRefresh: () async => ref.refresh(portOnusProvider(_arg).future),
        color: AppColors.primary,
        backgroundColor: AppColors.surfaceAlt,
        child: AsyncView<({List<Onu> onus, String? refreshedAt})>(
          value: data,
          onRetry: () => ref.refresh(portOnusProvider(_arg)),
          data: (res) {
            final onus = _apply(res.onus);
            final online = res.onus.where((o) => o.online).length;
            return Column(
              children: [
                Padding(
                  padding: const EdgeInsets.fromLTRB(16, 12, 16, 6),
                  child: TextField(
                    controller: _search,
                    decoration: InputDecoration(
                      hintText: 'Cari SN / nama / interface',
                      prefixIcon: const Icon(LucideIcons.search, size: 19),
                      isDense: true,
                      suffixIcon: _filter.isEmpty
                          ? null
                          : IconButton(
                              icon: const Icon(LucideIcons.x, size: 18),
                              tooltip: 'Bersihkan',
                              onPressed: () {
                                _search.clear();
                                setState(() => _filter = '');
                              },
                            ),
                    ),
                    onChanged: (v) => setState(() => _filter = v.trim().toLowerCase()),
                  ),
                ),
                Padding(
                  padding: const EdgeInsets.fromLTRB(18, 2, 18, 6),
                  child: Row(
                    children: [
                      Text('${onus.length} ONU',
                          style: const TextStyle(
                              color: AppColors.text, fontSize: 12.5, fontWeight: FontWeight.w700, fontFeatures: _tnum)),
                      const SizedBox(width: 8),
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
                        decoration: BoxDecoration(
                          color: AppColors.success.withValues(alpha: 0.13),
                          borderRadius: BorderRadius.circular(AppRadius.pill),
                        ),
                        child: Text('$online online',
                            style: const TextStyle(
                                color: AppColors.success, fontSize: 11, fontWeight: FontWeight.w700, fontFeatures: _tnum)),
                      ),
                      const Spacer(),
                      const Icon(LucideIcons.refreshCw, size: 12, color: AppColors.faint),
                      const SizedBox(width: 4),
                      Text(Fmt.relative(res.refreshedAt),
                          style: const TextStyle(color: AppColors.faint, fontSize: 11.5)),
                    ],
                  ),
                ),
                Expanded(
                  child: onus.isEmpty
                      ? const EmptyState(message: 'Tidak ada ONU cocok.', icon: LucideIcons.router)
                      : AnimationLimiter(
                          child: ListView.separated(
                            padding: const EdgeInsets.fromLTRB(16, 6, 16, 24),
                            itemCount: onus.length,
                            separatorBuilder: (_, __) => const SizedBox(height: 10),
                            itemBuilder: (_, i) => staggeredItem(
                              i,
                              _OnuRow(
                                onu: onus[i],
                                highlight: onus[i].onuId == widget.focusOnuId,
                              ),
                            ),
                          ),
                        ),
                ),
              ],
            );
          },
        ),
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
    return GlassCard(
      accent: highlight ? AppColors.primary : null,
      onTap: () => context.push(
          '/olts/${onu.oltId}/ports/${onu.slot}/${onu.port}/onus/${onu.onuId}'),
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 13),
      child: Row(
        children: [
          Container(
            padding: const EdgeInsets.all(8),
            decoration: BoxDecoration(
              color: (onu.online ? AppColors.success : AppColors.danger).withValues(alpha: 0.12),
              borderRadius: BorderRadius.circular(AppRadius.chip),
            ),
            child: Icon(LucideIcons.router,
                size: 16, color: onu.online ? AppColors.success : AppColors.danger),
          ),
          const SizedBox(width: 12),
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
                  style: const TextStyle(color: AppColors.muted, fontSize: 12, fontFeatures: _tnum),
                ),
              ],
            ),
          ),
          const SizedBox(width: 8),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              StatusChip.online(onu.online, dense: true),
              const SizedBox(height: 6),
              RxPowerBadge(dbm: onu.rxPowerDbm, online: onu.online),
            ],
          ),
        ],
      ),
    );
  }
}
