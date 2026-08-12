import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_staggered_animations/flutter_staggered_animations.dart';
import 'package:go_router/go_router.dart';
import 'package:kusumavision_nms/core/icons.dart';

import '../../core/odp_colors.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/aurora_background.dart';
import '../../core/widgets/glass_card.dart';
import '../../core/widgets/stagger.dart';
import '../../data/read_providers.dart';
import '../../models/odp.dart';
import '../../theme/app_theme.dart';

const _tnum = [FontFeature.tabularFigures()];

/// Daftar ODP (splitter lapangan) lintas-OLT, dengan kotak cari + filter OLT —
/// pola yang sama dengan halaman ONU per port.
class OdpListScreen extends ConsumerStatefulWidget {
  const OdpListScreen({super.key});

  @override
  ConsumerState<OdpListScreen> createState() => _OdpListScreenState();
}

class _OdpListScreenState extends ConsumerState<OdpListScreen> {
  final _search = TextEditingController();
  String _filter = '';
  int? _oltId;

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final data = ref.watch(odpsProvider);
    final bottomInset = MediaQuery.of(context).viewPadding.bottom + 96;

    return Scaffold(
      extendBodyBehindAppBar: true,
      appBar: AppBar(backgroundColor: Colors.transparent, title: const Text('ODP')),
      body: AuroraBackground(
        intensity: 0.55,
        child: RefreshIndicator(
          onRefresh: () async => ref.refresh(odpsProvider.future),
          color: AppColors.primary,
          backgroundColor: AppColors.surfaceAlt,
          child: AsyncView<List<Odp>>(
            value: data,
            onRetry: () => ref.refresh(odpsProvider),
            data: (all) {
              final olts = _oltOptions(all);
              // Filter OLT yang sudah tak ada di data (mis. setelah refresh) di-reset
              // supaya daftar tidak tampak kosong tanpa sebab.
              final activeOltId = olts.any((o) => o.$1 == _oltId) ? _oltId : null;
              final rows = _apply(all, activeOltId);

              return Column(
                children: [
                  SizedBox(height: MediaQuery.of(context).padding.top + kToolbarHeight),
                  Padding(
                    padding: const EdgeInsets.fromLTRB(16, 8, 16, 6),
                    child: TextField(
                      controller: _search,
                      decoration: InputDecoration(
                        hintText: 'Cari nama ODP / OLT / port',
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
                  if (olts.length > 1)
                    SizedBox(
                      height: 38,
                      child: ListView(
                        scrollDirection: Axis.horizontal,
                        padding: const EdgeInsets.symmetric(horizontal: 16),
                        children: [
                          _OltChip(
                            label: 'Semua OLT',
                            selected: activeOltId == null,
                            onTap: () => setState(() => _oltId = null),
                          ),
                          for (final olt in olts)
                            _OltChip(
                              label: olt.$2,
                              selected: activeOltId == olt.$1,
                              onTap: () => setState(() => _oltId = olt.$1),
                            ),
                        ],
                      ),
                    ),
                  Padding(
                    padding: const EdgeInsets.fromLTRB(18, 8, 18, 6),
                    child: Row(
                      children: [
                        Text('${rows.length} ODP',
                            style: const TextStyle(
                                color: AppColors.text,
                                fontSize: 12.5,
                                fontWeight: FontWeight.w700,
                                fontFeatures: _tnum)),
                        const SizedBox(width: 8),
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
                          decoration: BoxDecoration(
                            color: AppColors.primary.withValues(alpha: 0.13),
                            borderRadius: BorderRadius.circular(AppRadius.pill),
                          ),
                          child: Text('${rows.fold<int>(0, (a, e) => a + e.onuCount)} ONU',
                              style: const TextStyle(
                                  color: AppColors.primary,
                                  fontSize: 11,
                                  fontWeight: FontWeight.w700,
                                  fontFeatures: _tnum)),
                        ),
                      ],
                    ),
                  ),
                  Expanded(
                    child: rows.isEmpty
                        ? const EmptyState(
                            message: 'Tidak ada ODP cocok.', icon: LucideIcons.odp)
                        : AnimationLimiter(
                            child: ListView.separated(
                              padding: EdgeInsets.fromLTRB(16, 6, 16, bottomInset),
                              itemCount: rows.length,
                              separatorBuilder: (_, __) => const SizedBox(height: 10),
                              itemBuilder: (_, i) => staggeredItem(
                                i,
                                OdpRow(
                                  odp: rows[i],
                                  onTap: () => context.push('/odps/${rows[i].id}'),
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

  /// Daftar (id, nama) OLT yang punya ODP — untuk chip filter.
  List<(int, String)> _oltOptions(List<Odp> all) {
    final seen = <int, String>{};
    for (final odp in all) {
      seen.putIfAbsent(odp.oltId, () => odp.oltName ?? 'OLT ${odp.oltId}');
    }
    final list = seen.entries.map((e) => (e.key, e.value)).toList();
    list.sort((a, b) => a.$2.compareTo(b.$2));
    return list;
  }

  List<Odp> _apply(List<Odp> all, int? oltId) {
    return all.where((odp) {
      if (oltId != null && odp.oltId != oltId) return false;
      if (_filter.isEmpty) return true;
      final hay = [odp.name, odp.oltName, odp.portLabel, odp.notes]
          .whereType<String>()
          .join(' ')
          .toLowerCase();
      return hay.contains(_filter);
    }).toList();
  }
}

class _OltChip extends StatelessWidget {
  const _OltChip({required this.label, required this.selected, required this.onTap});
  final String label;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(right: 8),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(AppRadius.pill),
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 7),
          decoration: BoxDecoration(
            color: selected
                ? AppColors.primary.withValues(alpha: 0.16)
                : AppColors.surfaceAlt.withValues(alpha: 0.55),
            borderRadius: BorderRadius.circular(AppRadius.pill),
            border: Border.all(
              color: selected ? AppColors.primary.withValues(alpha: 0.45) : AppColors.border,
            ),
          ),
          child: Text(
            label,
            style: TextStyle(
              fontSize: 12,
              fontWeight: FontWeight.w700,
              color: selected ? AppColors.primary : AppColors.muted,
            ),
          ),
        ),
      ),
    );
  }
}

/// Baris ODP — dipakai daftar ODP (dan bisa dipakai ulang di tempat lain).
class OdpRow extends StatelessWidget {
  const OdpRow({super.key, required this.odp, required this.onTap});

  final Odp odp;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    // Ikon memakai warna pin ODP di peta — daftar & peta jadi mudah dicocokkan.
    final color = odpColorOf(odp.color);

    return GlassCard(
      onTap: onTap,
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 13),
      child: Row(
        children: [
          Container(
            padding: const EdgeInsets.all(8),
            decoration: BoxDecoration(
              color: color.withValues(alpha: 0.13),
              borderRadius: BorderRadius.circular(AppRadius.chip),
            ),
            child: Icon(LucideIcons.odp, size: 17, color: color),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(odp.name,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14)),
                const SizedBox(height: 3),
                Text(
                  [odp.oltName, if (odp.portLabel != null) 'Port ${odp.portLabel}']
                      .whereType<String>()
                      .join(' · '),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                      color: AppColors.muted, fontSize: 12, fontFeatures: _tnum),
                ),
              ],
            ),
          ),
          const SizedBox(width: 8),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 4),
            decoration: BoxDecoration(
              color: AppColors.primary.withValues(alpha: 0.12),
              borderRadius: BorderRadius.circular(AppRadius.pill),
              border: Border.all(color: AppColors.primary.withValues(alpha: 0.3)),
            ),
            child: Text('${odp.onuCount} ONU',
                style: const TextStyle(
                    color: AppColors.primary,
                    fontSize: 11.5,
                    fontWeight: FontWeight.w700,
                    fontFeatures: _tnum)),
          ),
          const SizedBox(width: 6),
          const Icon(LucideIcons.chevronRight, size: 18, color: AppColors.faint),
        ],
      ),
    );
  }
}
