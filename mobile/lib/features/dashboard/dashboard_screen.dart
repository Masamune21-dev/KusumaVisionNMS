import 'package:flutter/material.dart';
import 'package:flutter_animate/flutter_animate.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:kusumavision_nms/core/icons.dart';

import '../../core/format.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/count_up_text.dart';
import '../../core/widgets/glass_card.dart';
import '../../core/widgets/kv_art.dart';
import '../../core/widgets/kv_header.dart';
import '../../core/widgets/signal_ring.dart';
import '../../data/read_providers.dart';
import '../../models/olt.dart';
import '../../models/summary.dart';
import '../../theme/app_theme.dart';
import '../auth/auth_controller.dart';

const _tnum = [FontFeature.tabularFigures()];

/// Warna status di atas header. Header selalu gelap/berwarna (navy di tema gelap,
/// cyan tua di tema terang), jadi pakai tingkat terang palet gelap di kedua tema —
/// hijau/amber/merah tema terang terlalu gelap di atas cyan tua.
const _hdrOk = Color(0xFF34D399), _hdrWarn = Color(0xFFFBBF24), _hdrBad = Color(0xFFFB7185);

/// Tingkat kesehatan jaringan dari % ONU online.
({String label, Color color, IconData icon}) _health(double share) => share >= 95
    ? (label: 'Jaringan sehat', color: _hdrOk, icon: LucideIcons.checkCircle)
    : share >= 80
        ? (label: 'Perlu perhatian', color: _hdrWarn, icon: LucideIcons.alertTriangle)
        : (label: 'Gangguan luas', color: _hdrBad, icon: LucideIcons.alertTriangle);

class DashboardScreen extends ConsumerWidget {
  const DashboardScreen({super.key});

  /// Tinggi bagian bawah header yang ditimpa strip ringkasan.
  static const _overlap = 40.0;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final user = ref.watch(authControllerProvider).user;
    final summary = ref.watch(summaryProvider);
    final olts = ref.watch(oltsProvider);

    Widget seq(int i, Widget child) => child
        .animate(delay: (i * 70).ms)
        .fadeIn(duration: AppMotion.base)
        .slideY(begin: 0.1, curve: AppMotion.enter);

    return Scaffold(
      body: KvBackdrop(
        intensity: 0.6,
        child: RefreshIndicator(
          onRefresh: () async {
            await Future.wait([
              ref.refresh(summaryProvider.future),
              ref.refresh(oltsProvider.future),
            ]);
          },
          color: AppColors.primary,
          backgroundColor: AppColors.surfaceAlt,
          edgeOffset: MediaQuery.of(context).padding.top,
          child: ListView(
            padding: EdgeInsets.zero,
            children: [
              KvHeader(
                overlap: _overlap,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    _Greeting(name: user?.name ?? '', role: user?.roleLabel),
                    const SizedBox(height: 20),
                    switch (summary) {
                      AsyncData(:final value) => _HeroHealth(summary: value),
                      AsyncError() => const SizedBox.shrink(),
                      _ => const _HeroSkeleton(),
                    },
                  ],
                ),
              ),
              // Isi digeser naik supaya strip ringkasan "menumpang" ke header.
              Transform.translate(
                offset: const Offset(0, -_overlap),
                child: Padding(
                  // `padding.bottom` sudah memuat tinggi navbar melayang (shell ber-
                  // `extendBody`) — tanpa ini kartu terakhir tak bisa keluar dari baliknya.
                  padding: EdgeInsets.fromLTRB(16, 0, 16, MediaQuery.of(context).padding.bottom + 16),
                  child: AsyncView<DashboardSummary>(
                    value: summary,
                    onRetry: () => ref.refresh(summaryProvider),
                    loading: () => const _BodySkeleton(),
                    data: (s) => Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        seq(0, _KpiStrip(summary: s)),
                        const SizedBox(height: 18),
                        seq(1, SectionTitle('OLT perlu perhatian', icon: LucideIcons.server)),
                        seq(1, _OltWatchList(olts: olts)),
                      ],
                    ),
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Sapaan di header: nama + peran + tanggal, dan tombol pencarian global.
class _Greeting extends StatelessWidget {
  const _Greeting({required this.name, this.role});
  final String name;
  final String? role;

  @override
  Widget build(BuildContext context) {
    final hour = DateTime.now().hour;
    final salam = hour < 11
        ? 'Selamat pagi'
        : hour < 15
            ? 'Selamat siang'
            : hour < 18
                ? 'Selamat sore'
                : 'Selamat malam';

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(salam,
                  style: const TextStyle(color: KvHeaderColors.fg3, fontSize: 12.5, fontWeight: FontWeight.w600)),
              const SizedBox(height: 2),
              Text(name.isEmpty ? 'KusumaVision NMS' : name,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    fontFamily: AppFont.display,
                    color: KvHeaderColors.fg,
                    fontSize: 22,
                    fontWeight: FontWeight.w800,
                    letterSpacing: -0.4,
                  )),
              if (role != null) ...[
                const SizedBox(height: 8),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                  decoration: BoxDecoration(
                    color: KvHeaderColors.control,
                    borderRadius: BorderRadius.circular(AppRadius.pill),
                    border: Border.all(color: KvHeaderColors.controlBorder),
                  ),
                  child: Row(mainAxisSize: MainAxisSize.min, children: [
                    const Icon(LucideIcons.shieldCheck, size: 13, color: KvHeaderColors.fg2),
                    const SizedBox(width: 5),
                    Text(role!,
                        style: const TextStyle(
                            color: KvHeaderColors.fg2, fontSize: 11.5, fontWeight: FontWeight.w700)),
                  ]),
                ),
              ],
            ],
          ),
        ),
        const SizedBox(width: 10),
        // Pencarian global tidak punya tab sendiri — pintu masuknya di sini.
        KvHeaderIconButton(
          icon: LucideIcons.search,
          tooltip: 'Cari OLT / ONU',
          onTap: () => context.push('/search'),
        ),
      ],
    );
  }
}

/// Hero di dalam header: cincin kesehatan + angka ONU online + lencana status,
/// lalu bilah komposisi ONU (online / warning / offline).
class _HeroHealth extends StatelessWidget {
  const _HeroHealth({required this.summary});
  final DashboardSummary summary;

  @override
  Widget build(BuildContext context) {
    final share = summary.onlineShare.toDouble().clamp(0, 100).toDouble();
    final h = _health(share);
    final t = Theme.of(context).textTheme;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            SignalRing(
              percent: share,
              size: 118,
              stroke: 10,
              color: h.color,
              trackColor: Colors.white.withValues(alpha: 0.14),
              textColor: KvHeaderColors.fg,
              caption: 'online',
            ),
            const SizedBox(width: 18),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  // Lencana status — warna & teks mengikuti tingkat kesehatan.
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                    decoration: BoxDecoration(
                      color: h.color.withValues(alpha: 0.18),
                      borderRadius: BorderRadius.circular(AppRadius.pill),
                      border: Border.all(color: h.color.withValues(alpha: 0.5)),
                    ),
                    child: Row(mainAxisSize: MainAxisSize.min, children: [
                      Icon(h.icon, size: 13, color: h.color),
                      const SizedBox(width: 5),
                      Text(h.label,
                          style: TextStyle(color: h.color, fontSize: 11.5, fontWeight: FontWeight.w800)),
                    ]),
                  ),
                  const SizedBox(height: 10),
                  CountUpText(
                    summary.onuOnline,
                    style: t.displaySmall?.copyWith(
                        color: KvHeaderColors.fg, fontSize: 34, fontFeatures: _tnum, height: 1),
                  ),
                  const SizedBox(height: 4),
                  Text('ONU online dari ${Fmt.int(summary.onuTotal)}',
                      style: const TextStyle(color: KvHeaderColors.fg3, fontSize: 12.5, fontFeatures: _tnum)),
                ],
              ),
            ),
          ],
        ),
        const SizedBox(height: 18),
        _OnuMixBar(summary: summary),
      ],
    );
  }
}

/// Komposisi ONU dalam satu bilah bertumpuk + legenda angka.
class _OnuMixBar extends StatelessWidget {
  const _OnuMixBar({required this.summary});
  final DashboardSummary summary;

  @override
  Widget build(BuildContext context) {
    // `warning` = ONU online dengan RX marginal — dipisah dari online sehat.
    final warn = summary.onuWarning.clamp(0, summary.onuOnline);
    final parts = [
      ('Online', summary.onuOnline - warn, _hdrOk),
      ('Warning RX', warn, _hdrWarn),
      ('Offline', summary.onuOffline, _hdrBad),
    ];
    final total = parts.fold<int>(0, (a, e) => a + e.$2);

    return Column(
      children: [
        ClipRRect(
          borderRadius: BorderRadius.circular(AppRadius.pill),
          child: SizedBox(
            height: 8,
            child: total == 0
                ? ColoredBox(color: Colors.white.withValues(alpha: 0.14))
                : Row(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      for (final p in parts)
                        if (p.$2 > 0)
                          Expanded(
                            flex: p.$2,
                            child: Container(
                              margin: const EdgeInsets.symmetric(horizontal: 1),
                              color: p.$3,
                            ),
                          ),
                    ],
                  ),
          ),
        ),
        const SizedBox(height: 10),
        Row(
          children: [
            for (final p in parts)
              Expanded(
                child: Row(children: [
                  Container(width: 8, height: 8, decoration: BoxDecoration(color: p.$3, shape: BoxShape.circle)),
                  const SizedBox(width: 6),
                  Flexible(
                    child: Text.rich(
                      TextSpan(children: [
                        TextSpan(
                            text: Fmt.int(p.$2),
                            style: const TextStyle(color: KvHeaderColors.fg, fontWeight: FontWeight.w800)),
                        TextSpan(text: ' ${p.$1}', style: const TextStyle(color: KvHeaderColors.fg3)),
                      ]),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(fontSize: 11.5, fontFeatures: _tnum),
                    ),
                  ),
                ]),
              ),
          ],
        ),
      ],
    );
  }
}

/// Ringkasan tiga angka dalam SATU kartu yang menumpang ke header — menggantikan
/// empat kotak statistik seragam.
class _KpiStrip extends StatelessWidget {
  const _KpiStrip({required this.summary});
  final DashboardSummary summary;

  @override
  Widget build(BuildContext context) {
    Widget divider() => Container(width: 1, height: 38, color: AppColors.border);

    return GlassCard(
      padding: const EdgeInsets.symmetric(vertical: 14, horizontal: 6),
      child: Row(
        children: [
          _Kpi(
            icon: LucideIcons.server,
            color: AppColors.secondary,
            value: '${summary.oltOnline}/${summary.oltTotal}',
            label: 'OLT aktif',
          ),
          divider(),
          _Kpi(
            icon: LucideIcons.wifiOff,
            color: AppColors.danger,
            value: Fmt.int(summary.onuOffline),
            label: 'ONU offline',
          ),
          divider(),
          _Kpi(
            icon: LucideIcons.bellRing,
            color: AppColors.warning,
            value: Fmt.int(summary.alarmTotal),
            label: summary.alarmCritical > 0 ? '${summary.alarmCritical} kritis' : 'Alarm aktif',
            onTap: () => context.push('/alarms'),
          ),
        ],
      ),
    );
  }
}

class _Kpi extends StatelessWidget {
  const _Kpi({required this.icon, required this.color, required this.value, required this.label, this.onTap});

  final IconData icon;
  final Color color;
  final String value, label;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    return Expanded(
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(AppRadius.control),
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 2),
          child: Column(
            children: [
              Row(mainAxisSize: MainAxisSize.min, children: [
                Icon(icon, size: 15, color: color),
                const SizedBox(width: 6),
                Text(value,
                    style: TextStyle(
                        fontFamily: AppFont.display,
                        fontSize: 19,
                        fontWeight: FontWeight.w800,
                        color: AppColors.text,
                        fontFeatures: _tnum)),
              ]),
              const SizedBox(height: 3),
              Text(label, style: TextStyle(fontSize: 11.5, color: AppColors.muted, fontWeight: FontWeight.w600)),
            ],
          ),
        ),
      ),
    );
  }
}

/// OLT yang paling butuh dilihat: tak terjangkau dulu, lalu ONU offline terbanyak.
/// Bukan pintasan navigasi — isinya informasi yang tak ada di header.
class _OltWatchList extends StatelessWidget {
  const _OltWatchList({required this.olts});
  final AsyncValue<List<OltSummary>> olts;

  static const _max = 4;

  @override
  Widget build(BuildContext context) {
    final list = olts.valueOrNull;
    if (list == null) {
      return olts.hasError
          ? GlassCard(
              child: Text('Daftar OLT gagal dimuat — tarik untuk memuat ulang.',
                  style: TextStyle(color: AppColors.muted, fontSize: 12.5)),
            )
          : const SkeletonShimmer(child: Skeleton(height: 140, radius: AppRadius.card));
    }

    final problem = list.where((o) => !o.reachable || o.onuOffline > 0).toList()
      ..sort((a, b) {
        if (a.reachable != b.reachable) return a.reachable ? 1 : -1;
        return b.onuOffline.compareTo(a.onuOffline);
      });

    if (problem.isEmpty) {
      return GlassCard(
        child: Row(children: [
          const KvIllustration(KvArt.clear, width: 74),
          const SizedBox(width: 12),
          Expanded(
            child: Text('Semua ${list.length} OLT terjangkau dan tak ada ONU offline.',
                style: TextStyle(color: AppColors.muted, fontSize: 13, height: 1.4)),
          ),
        ]),
      );
    }

    final shown = problem.take(_max).toList();
    return GlassCard(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 6),
      child: Column(
        children: [
          for (var i = 0; i < shown.length; i++) ...[
            if (i > 0) Divider(height: 1, color: AppColors.border),
            _OltRow(olt: shown[i]),
          ],
          if (problem.length > _max)
            Padding(
              padding: const EdgeInsets.only(top: 4, bottom: 6),
              child: Text('+${problem.length - _max} OLT lain dengan ONU offline',
                  style: TextStyle(fontSize: 11.5, color: AppColors.faint)),
            ),
        ],
      ),
    );
  }
}

class _OltRow extends StatelessWidget {
  const _OltRow({required this.olt});
  final OltSummary olt;

  @override
  Widget build(BuildContext context) {
    final share = olt.onuTotal == 0 ? 0.0 : olt.onuOnline / olt.onuTotal;
    final color = !olt.reachable
        ? AppColors.danger
        : share >= 0.95
            ? AppColors.success
            : share >= 0.8
                ? AppColors.warning
                : AppColors.danger;

    return InkWell(
      onTap: () => context.push('/olts/${olt.id}'),
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 11),
        child: Row(
          children: [
            Container(
              width: 36,
              height: 36,
              decoration: BoxDecoration(
                color: color.withValues(alpha: 0.13),
                borderRadius: BorderRadius.circular(AppRadius.chip),
              ),
              child: Icon(olt.reachable ? LucideIcons.server : LucideIcons.unlink, size: 17, color: color),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(olt.name,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(fontSize: 13.5, fontWeight: FontWeight.w700, color: AppColors.text)),
                  const SizedBox(height: 6),
                  ClipRRect(
                    borderRadius: BorderRadius.circular(AppRadius.pill),
                    child: LinearProgressIndicator(
                      value: olt.reachable ? share : 0,
                      minHeight: 5,
                      color: color,
                      backgroundColor: AppColors.surfaceAlt,
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(width: 12),
            Column(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                Text(olt.reachable ? Fmt.int(olt.onuOffline) : 'Down',
                    style: TextStyle(
                        fontSize: 15, fontWeight: FontWeight.w800, color: color, fontFeatures: _tnum)),
                Text(olt.reachable ? 'offline' : 'tak terjangkau',
                    style: TextStyle(fontSize: 11, color: AppColors.faint)),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

/// Kerangka hero saat memuat (di atas header, jadi memakai putih transparan).
class _HeroSkeleton extends StatelessWidget {
  const _HeroSkeleton();

  @override
  Widget build(BuildContext context) {
    Widget bar(double w, double h) => Container(
          width: w,
          height: h,
          decoration: BoxDecoration(
            color: Colors.white.withValues(alpha: 0.14),
            borderRadius: BorderRadius.circular(8),
          ),
        );
    return Column(children: [
      Row(children: [
        Container(
          width: 118,
          height: 118,
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            border: Border.all(color: Colors.white.withValues(alpha: 0.14), width: 10),
          ),
        ),
        const SizedBox(width: 18),
        Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          bar(110, 22),
          const SizedBox(height: 12),
          bar(90, 30),
          const SizedBox(height: 8),
          bar(140, 12),
        ]),
      ]),
      const SizedBox(height: 18),
      bar(double.infinity, 8),
    ]);
  }
}

class _BodySkeleton extends StatelessWidget {
  const _BodySkeleton();

  @override
  Widget build(BuildContext context) {
    return const SkeletonShimmer(
      child: Column(children: [
        Skeleton(height: 72, radius: AppRadius.card),
        SizedBox(height: 18),
        Skeleton(height: 180, radius: AppRadius.card),
      ]),
    );
  }
}
