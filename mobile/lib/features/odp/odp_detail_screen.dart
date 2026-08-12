import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_staggered_animations/flutter_staggered_animations.dart';
import 'package:go_router/go_router.dart';
import 'package:kusumavision_nms/core/icons.dart';

import '../../core/odp_colors.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/aurora_background.dart';
import '../../core/widgets/glass_card.dart';
import '../../core/widgets/rx_power_badge.dart';
import '../../core/widgets/stagger.dart';
import '../../core/widgets/status_chip.dart';
import '../../data/read_providers.dart';
import '../../models/odp.dart';
import '../../theme/app_theme.dart';
import '../map/map_providers.dart';
import 'odp_color_sheet.dart';

const _tnum = [FontFeature.tabularFigures()];

/// Detail ODP: identitas + daftar ONU yang tersambung (dengan kotak cari,
/// seperti halaman ONU per port).
class OdpDetailScreen extends ConsumerStatefulWidget {
  const OdpDetailScreen({super.key, required this.odpId});

  final int odpId;

  @override
  ConsumerState<OdpDetailScreen> createState() => _OdpDetailScreenState();
}

class _OdpDetailScreenState extends ConsumerState<OdpDetailScreen> {
  final _search = TextEditingController();
  String _filter = '';

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  /// Ganti warna pin ODP (bawaan: se-PON-port, sama seperti web).
  Future<void> _pickColor(Odp odp) async {
    // Jumlah ODP se-port untuk label saklar — dari daftar ODP yang sudah dimuat;
    // kalau daftarnya belum ada, cukup ODP ini.
    final all = ref.read(odpsProvider).valueOrNull ?? const <Odp>[];
    final siblings = odp.portLabel == null
        ? 1
        : all
            .where((o) => o.oltId == odp.oltId && o.slot == odp.slot && o.port == odp.port)
            .length;

    await showOdpColorSheet(
      context,
      odpId: odp.id,
      odpName: odp.name,
      currentColor: odp.color,
      portLabel: odp.portLabel,
      portCount: siblings < 1 ? 1 : siblings,
    );
  }

  void _openOnMap(Odp odp) {
    if (!odp.hasCoordinates) return;
    ref.read(mapFocusProvider.notifier).state =
        (lat: odp.latitude!, lng: odp.longitude!, odpId: odp.id, pinId: null);
    context.go('/map');
  }

  @override
  Widget build(BuildContext context) {
    final odp = ref.watch(odpDetailProvider(widget.odpId));
    final onus = ref.watch(odpOnusProvider(widget.odpId));

    return Scaffold(
      appBar: AppBar(
        title: Text(odp.valueOrNull?.name ?? 'Detail ODP'),
        actions: [
          if (odp.valueOrNull != null)
            IconButton(
              tooltip: 'Warna pin ODP',
              icon: Icon(LucideIcons.palette, size: 20, color: odpColorOf(odp.value!.color)),
              onPressed: () => _pickColor(odp.value!),
            ),
          if (odp.valueOrNull?.hasCoordinates ?? false)
            IconButton(
              tooltip: 'Lihat di peta',
              icon: const Icon(LucideIcons.map, size: 20),
              onPressed: () => _openOnMap(odp.value!),
            ),
          const SizedBox(width: 4),
        ],
      ),
      body: AuroraBackground(
        intensity: 0.5,
        child: RefreshIndicator(
          onRefresh: () async {
            ref.invalidate(odpDetailProvider(widget.odpId));
            ref.invalidate(odpOnusProvider(widget.odpId));
            await ref.read(odpOnusProvider(widget.odpId).future);
          },
          color: AppColors.primary,
          backgroundColor: AppColors.surfaceAlt,
          child: AsyncView<List<OdpOnu>>(
            value: onus,
            onRetry: () => ref.refresh(odpOnusProvider(widget.odpId)),
            data: (all) {
              final rows = _apply(all);
              final online = all.where((o) => o.online).length;

              return Column(
                children: [
                  Padding(
                    padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
                    child: _Header(
                      odp: odp.valueOrNull,
                      total: all.length,
                      online: online,
                      onOpenMap: odp.valueOrNull == null ? null : () => _openOnMap(odp.value!),
                    ),
                  ),
                  Padding(
                    padding: const EdgeInsets.fromLTRB(16, 10, 16, 6),
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
                        Text('${rows.length} ONU',
                            style: const TextStyle(
                                color: AppColors.text,
                                fontSize: 12.5,
                                fontWeight: FontWeight.w700,
                                fontFeatures: _tnum)),
                        const SizedBox(width: 8),
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
                          decoration: BoxDecoration(
                            color: AppColors.success.withValues(alpha: 0.13),
                            borderRadius: BorderRadius.circular(AppRadius.pill),
                          ),
                          child: Text('$online online',
                              style: const TextStyle(
                                  color: AppColors.success,
                                  fontSize: 11,
                                  fontWeight: FontWeight.w700,
                                  fontFeatures: _tnum)),
                        ),
                      ],
                    ),
                  ),
                  Expanded(
                    child: rows.isEmpty
                        ? EmptyState(
                            message: all.isEmpty
                                ? 'Belum ada ONU yang dikaitkan ke ODP ini.'
                                : 'Tidak ada ONU cocok.',
                            icon: LucideIcons.router,
                          )
                        : AnimationLimiter(
                            child: ListView.separated(
                              padding: const EdgeInsets.fromLTRB(16, 6, 16, 24),
                              itemCount: rows.length,
                              separatorBuilder: (_, __) => const SizedBox(height: 10),
                              itemBuilder: (_, i) => staggeredItem(i, _OnuRow(onu: rows[i])),
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

  List<OdpOnu> _apply(List<OdpOnu> list) {
    if (_filter.isEmpty) return list;
    return list.where((o) {
      final hay = [o.serialNumber, o.name, o.interface]
          .whereType<String>()
          .join(' ')
          .toLowerCase();
      return hay.contains(_filter);
    }).toList();
  }
}

class _Header extends StatelessWidget {
  const _Header({
    required this.odp,
    required this.total,
    required this.online,
    required this.onOpenMap,
  });

  final Odp? odp;
  final int total, online;
  final VoidCallback? onOpenMap;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context).textTheme;

    return GlassCard(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              // Ikon ODP memakai warna pin-nya di peta supaya mudah dicocokkan.
              Builder(builder: (_) {
                final color = odpColorOf(odp?.color);

                return Container(
                  padding: const EdgeInsets.all(10),
                  decoration: BoxDecoration(
                    color: color.withValues(alpha: 0.13),
                    borderRadius: BorderRadius.circular(AppRadius.chip),
                  ),
                  child: Icon(LucideIcons.odp, size: 20, color: color),
                );
              }),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(odp?.name ?? '…',
                        maxLines: 1, overflow: TextOverflow.ellipsis, style: t.titleMedium),
                    const SizedBox(height: 2),
                    Text(
                      [
                        odp?.oltName,
                        if (odp?.portLabel != null) 'Port ${odp!.portLabel}',
                      ].whereType<String>().join(' · '),
                      style: t.bodySmall?.copyWith(color: AppColors.muted),
                    ),
                  ],
                ),
              ),
            ],
          ),
          if ((odp?.notes ?? '').trim().isNotEmpty) ...[
            const SizedBox(height: 10),
            Text(odp!.notes!, style: t.bodySmall?.copyWith(color: AppColors.muted)),
          ],
          const SizedBox(height: 12),
          Row(
            children: [
              _stat('ONU', '$total', AppColors.primary),
              const SizedBox(width: 8),
              _stat('Online', '$online', AppColors.success),
              const SizedBox(width: 8),
              _stat('Offline', '${total - online}', AppColors.danger),
              const Spacer(),
              if (onOpenMap != null && (odp?.hasCoordinates ?? false))
                TextButton.icon(
                  onPressed: onOpenMap,
                  icon: const Icon(LucideIcons.mapPin, size: 16),
                  label: const Text('Peta'),
                  style: TextButton.styleFrom(foregroundColor: AppColors.primary),
                ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _stat(String label, String value, Color color) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
        decoration: BoxDecoration(
          color: color.withValues(alpha: 0.12),
          borderRadius: BorderRadius.circular(AppRadius.chip),
          border: Border.all(color: color.withValues(alpha: 0.28)),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(value,
                style: TextStyle(
                    color: color,
                    fontSize: 13,
                    fontWeight: FontWeight.w800,
                    fontFeatures: _tnum)),
            const SizedBox(width: 5),
            Text(label, style: const TextStyle(color: AppColors.muted, fontSize: 11)),
          ],
        ),
      );
}

class _OnuRow extends StatelessWidget {
  const _OnuRow({required this.onu});
  final OdpOnu onu;

  @override
  Widget build(BuildContext context) {
    final color = onu.online ? AppColors.success : AppColors.danger;

    return GlassCard(
      onTap: () => context.push(
          '/olts/${onu.oltId}/ports/${onu.slot}/${onu.port}/onus/${onu.onuId}'),
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 13),
      child: Row(
        children: [
          Container(
            padding: const EdgeInsets.all(8),
            decoration: BoxDecoration(
              color: color.withValues(alpha: 0.12),
              borderRadius: BorderRadius.circular(AppRadius.chip),
            ),
            child: Icon(LucideIcons.router, size: 16, color: color),
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
                  '#${onu.onuId} · ${onu.serialNumber ?? onu.interface ?? '-'}',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                      color: AppColors.muted, fontSize: 12, fontFeatures: _tnum),
                ),
              ],
            ),
          ),
          const SizedBox(width: 8),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              // ONU yang kaitannya masih ada tapi sudah hilang dari OLT ditandai
              // khusus supaya tidak terbaca sekadar "offline".
              onu.hasLive
                  ? StatusChip.online(onu.online, dense: true)
                  : const StatusChip(
                      label: 'Tak ada di OLT', color: AppColors.faint, dense: true),
              const SizedBox(height: 6),
              RxPowerBadge(dbm: onu.rxPowerDbm, online: onu.online),
            ],
          ),
        ],
      ),
    );
  }
}
