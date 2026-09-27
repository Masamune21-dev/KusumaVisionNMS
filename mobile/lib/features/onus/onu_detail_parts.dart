import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_animate/flutter_animate.dart';
import 'package:go_router/go_router.dart';

import '../../core/icons.dart';
import '../../core/onu_status.dart';
import '../../core/widgets/glass_card.dart';
import '../../core/widgets/kv_art.dart';
import '../../core/widgets/pulse_dot.dart';
import '../../core/widgets/rx_power_badge.dart';
import '../../core/widgets/status_chip.dart';
import '../../models/onu.dart';
import '../../theme/app_theme.dart';

const _tnum = [FontFeature.tabularFigures()];

/// Rentang RX yang aman — sama dengan [RxPowerBadge] (di luar ini = marginal).
const _rxLow = -25.0, _rxHigh = -10.0;

// ---------------------------------------------------------------------------
// Hero: ilustrasi perangkat + lampu hidup + identitas pelanggan
// ---------------------------------------------------------------------------

/// Kartu pembuka detail ONU: ilustrasi perangkat yang lampunya mengikuti
/// status asli, lalu SATU nama pelanggan (nama/deskripsi/pelanggan digabung).
class OnuHero extends StatelessWidget {
  const OnuHero({super.key, required this.onu, required this.status});

  final Onu onu;
  final OnuStatus status;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context).textTheme;
    final name = onu.customerLabel;
    final ref = onu.customerRef;
    final note = onu.oltNote;

    return GlassCard(
      padding: EdgeInsets.zero,
      // passthrough: isi mendapat lebar penuh kartu. Dengan Stack bawaan (loose,
      // topStart) kolom menyusut selebar isi terlebarnya dan menempel kiri — nama
      // pendek membuat perangkat & nama tampak rata kiri, nama panjang tampak di tengah.
      child: Stack(
        fit: StackFit.passthrough,
        alignment: Alignment.topCenter,
        children: [
          // Cahaya lembut berwarna status di belakang perangkat.
          Positioned.fill(
            child: DecoratedBox(
              decoration: BoxDecoration(
                gradient: RadialGradient(
                  center: const Alignment(0, -0.55),
                  radius: 0.9,
                  colors: [status.color.withValues(alpha: 0.16), status.color.withValues(alpha: 0)],
                ),
              ),
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(18, 18, 18, 18),
            child: Column(
              children: [
                OnuDeviceArt(onu: onu, status: status, width: 190),
                const SizedBox(height: 14),
                Text(
                  name ?? onu.serialNumber ?? onu.interface ?? 'ONU ${onu.onuId}',
                  textAlign: TextAlign.center,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: t.titleLarge?.copyWith(fontSize: 20, letterSpacing: -0.4),
                ),
                const SizedBox(height: 4),
                Text(
                  [onu.interface ?? 'ONU ${onu.onuId}', if (onu.typeName != null) onu.typeName].join(' · '),
                  textAlign: TextAlign.center,
                  style: AppText.mono(size: 12, color: AppColors.muted),
                ),
                if (note != null) ...[
                  const SizedBox(height: 6),
                  Text('Catatan OLT: $note',
                      textAlign: TextAlign.center,
                      style: t.bodySmall?.copyWith(color: AppColors.faint, fontStyle: FontStyle.italic)),
                ],
                const SizedBox(height: 14),
                Wrap(
                  alignment: WrapAlignment.center,
                  spacing: 8,
                  runSpacing: 8,
                  children: [
                    StatusChip.onu(status),
                    RxPowerBadge(dbm: onu.rxPowerDbm, online: onu.online),
                    if (ref != null) _RefChip(ref: ref),
                  ],
                ),
                // Arti status buat teknisi — LOS (fiber) vs dying gasp (listrik
                // pelanggan) menentukan perlu tidaknya tim turun ke lapangan.
                if (!status.online) ...[
                  const SizedBox(height: 14),
                  Container(
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(
                      color: status.color.withValues(alpha: 0.08),
                      borderRadius: BorderRadius.circular(AppRadius.control),
                      border: Border.all(color: status.color.withValues(alpha: 0.25)),
                    ),
                    child: Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Icon(status.icon, size: 16, color: status.color),
                        const SizedBox(width: 9),
                        Expanded(
                          child: Text(status.detail,
                              style: TextStyle(color: AppColors.text, fontSize: 12.5, height: 1.45)),
                        ),
                      ],
                    ),
                  ),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// Lencana ID pelanggan — ketuk untuk menyalin.
class _RefChip extends StatelessWidget {
  const _RefChip({required this.ref});
  final String ref;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      borderRadius: BorderRadius.circular(AppRadius.chip),
      onTap: () => copyWithToast(context, ref, label: 'ID pelanggan'),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3.5),
        decoration: BoxDecoration(
          color: AppColors.secondary.withValues(alpha: 0.12),
          borderRadius: BorderRadius.circular(AppRadius.chip),
          border: Border.all(color: AppColors.secondary.withValues(alpha: 0.34)),
        ),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          Icon(LucideIcons.hash, size: 12, color: AppColors.secondary),
          const SizedBox(width: 3),
          Text(ref,
              style: TextStyle(
                  color: AppColors.secondary, fontSize: 12, fontWeight: FontWeight.w700, fontFeatures: _tnum)),
        ]),
      ),
    );
  }
}

/// Ilustrasi ONU dengan lampu PWR / PON / LOS yang meniru perangkat asli:
/// - PWR hijau, kecuali dying gasp (listrik pelanggan mati) → padam;
/// - PON hijau berdenyut saat online, padam saat offline/nonaktif;
/// - LOS merah berkedip saat kehilangan sinyal / sinyal buruk.
class OnuDeviceArt extends StatelessWidget {
  const OnuDeviceArt({super.key, required this.onu, required this.status, this.width = 190});

  final Onu onu;
  final OnuStatus status;
  final double width;

  // Koordinat pusat lampu di viewBox 200×130 `onu_device.svg`.
  static const _leds = [Offset(110, 85), Offset(133, 85), Offset(156, 85)];

  @override
  Widget build(BuildContext context) {
    final label = status.label.toLowerCase();
    final dyingGasp = label.contains('dying');
    final fiberFault = !status.online && status.tone == StatusTone.danger && !dyingGasp;

    final pwr = dyingGasp ? null : AppColors.success;
    final pon = status.online ? AppColors.success : null;
    final los = fiberFault ? AppColors.danger : null;

    final h = width * 130 / 200;
    final k = width / 200;

    Widget led(Offset at, Color? color, {bool pulse = false, bool blink = false}) {
      const d = 9.0;
      Widget dot = color == null
          ? Container(
              width: d,
              height: d,
              decoration: BoxDecoration(
                color: AppColors.faint.withValues(alpha: 0.35),
                shape: BoxShape.circle,
              ),
            )
          : pulse
              ? PulseDot(color: color, size: d)
              : Container(
                  width: d,
                  height: d,
                  decoration: BoxDecoration(
                    color: color,
                    shape: BoxShape.circle,
                    boxShadow: [BoxShadow(color: color.withValues(alpha: 0.7), blurRadius: 8)],
                  ),
                );
      final reduce = MediaQuery.maybeOf(context)?.disableAnimations ?? false;
      if (blink && !reduce) {
        dot = dot.animate(onPlay: (c) => c.repeat(reverse: true)).fade(begin: 1, end: 0.15, duration: 520.ms);
      }
      // PulseDot lebih besar dari titiknya (halo 2,4×) — pusatkan di koordinat lampu.
      final box = pulse && color != null ? d * 2.4 : d;
      return Positioned(left: at.dx * k - box / 2, top: at.dy * k - box / 2, child: dot);
    }

    return SizedBox(
      width: width,
      height: h,
      child: Stack(
        clipBehavior: Clip.none,
        children: [
          Positioned.fill(child: KvIllustration(KvArt.onuDevice, width: width)),
          led(_leds[0], pwr),
          led(_leds[1], pon, pulse: true),
          led(_leds[2], los, blink: true),
          // Label lampu tepat di bawah panel.
          for (final (i, name) in ['PWR', 'PON', 'LOS'].indexed)
            Positioned(
              left: _leds[i].dx * k - 14,
              top: 98 * k,
              width: 28,
              child: Text(name,
                  textAlign: TextAlign.center,
                  style: TextStyle(fontSize: 8, fontWeight: FontWeight.w700, color: AppColors.faint, letterSpacing: 0.4)),
            ),
        ],
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Jalur jaringan OLT → PON → ODP → ONU
// ---------------------------------------------------------------------------

/// Jalur fisik ONU ini; tiap simpul bisa diketuk untuk membuka halamannya.
class OnuPathCard extends StatelessWidget {
  const OnuPathCard({super.key, required this.onu, required this.status});

  final Onu onu;
  final OnuStatus status;

  @override
  Widget build(BuildContext context) {
    final nodes = <_PathNode>[
      _PathNode(
        icon: LucideIcons.server,
        caption: 'OLT',
        label: onu.oltName ?? 'OLT',
        onTap: () => context.push('/olts/${onu.oltId}'),
      ),
      _PathNode(
        icon: LucideIcons.cable,
        caption: 'PON',
        label: '${onu.slot}/${onu.port}',
        onTap: () => context.push('/olts/${onu.oltId}/ports/${onu.slot}/${onu.port}'),
      ),
      _PathNode(
        icon: LucideIcons.odp,
        caption: 'ODP',
        label: onu.odpName ?? 'Belum ada',
        muted: onu.odpId == null,
        onTap: onu.odpId == null ? null : () => context.push('/odps/${onu.odpId}'),
      ),
      _PathNode(
        icon: LucideIcons.router,
        caption: 'ONU',
        label: '#${onu.onuId}',
        color: status.color,
      ),
    ];

    return GlassCard(
      padding: const EdgeInsets.fromLTRB(10, 14, 10, 12),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          for (var i = 0; i < nodes.length; i++) ...[
            Expanded(child: nodes[i]),
            if (i < nodes.length - 1)
              // Sambungan "serat" antar simpul; ruas terakhir ke ONU ikut warna status.
              Padding(
                padding: const EdgeInsets.only(top: 19),
                child: Container(
                  width: 14,
                  height: 2.4,
                  decoration: BoxDecoration(
                    color: (i == nodes.length - 2 ? status.color : AppColors.primary).withValues(alpha: 0.7),
                    borderRadius: BorderRadius.circular(2),
                  ),
                ),
              ),
          ],
        ],
      ),
    );
  }
}

class _PathNode extends StatelessWidget {
  const _PathNode({
    required this.icon,
    required this.caption,
    required this.label,
    this.onTap,
    this.color,
    this.muted = false,
  });

  final IconData icon;
  final String caption, label;
  final VoidCallback? onTap;
  final Color? color;
  final bool muted;

  @override
  Widget build(BuildContext context) {
    final c = color ?? (muted ? AppColors.faint : AppColors.primary);
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(AppRadius.control),
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 2),
        child: Column(
          children: [
            Container(
              width: 40,
              height: 40,
              decoration: BoxDecoration(
                color: c.withValues(alpha: color != null ? 0.18 : 0.11),
                shape: BoxShape.circle,
                border: Border.all(color: c.withValues(alpha: color != null ? 0.6 : 0.3), width: color != null ? 1.6 : 1),
              ),
              child: Icon(icon, size: 18, color: c),
            ),
            const SizedBox(height: 6),
            Text(caption,
                style: TextStyle(fontSize: 10, fontWeight: FontWeight.w700, color: AppColors.faint, letterSpacing: 0.6)),
            const SizedBox(height: 1),
            Text(label,
                maxLines: 2,
                textAlign: TextAlign.center,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(
                    fontSize: 11.5,
                    height: 1.25,
                    fontWeight: FontWeight.w600,
                    color: muted ? AppColors.faint : AppColors.text,
                    fontFeatures: _tnum)),
          ],
        ),
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Sinyal optik
// ---------------------------------------------------------------------------

/// RX power besar + skala berwarna dengan penanda posisi nilai saat ini.
class OnuSignalCard extends StatelessWidget {
  const OnuSignalCard({super.key, required this.onu, required this.status});

  final Onu onu;
  final OnuStatus status;

  static const _min = -32.0, _max = -6.0;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context).textTheme;
    final dbm = onu.online ? onu.rxPowerDbm : null;

    final (String zone, Color zoneColor) = switch (dbm) {
      null when !onu.online => ('ONU offline', status.color),
      null => ('Belum terbaca', AppColors.faint),
      final v when v <= _rxLow => ('Redaman tinggi', AppColors.warning),
      final v when v >= _rxHigh => ('Terlalu kuat', AppColors.warning),
      _ => ('Bagus', AppColors.success),
    };

    double pos(double v) => ((v.clamp(_min, _max) - _min) / (_max - _min)).toDouble();

    return GlassCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text(dbm == null ? '—' : dbm.toStringAsFixed(1),
                  style: t.displaySmall?.copyWith(fontSize: 34, color: AppColors.text, fontFeatures: _tnum)),
              const SizedBox(width: 6),
              Padding(
                padding: const EdgeInsets.only(bottom: 5),
                child: Text('dBm', style: t.titleSmall?.copyWith(color: AppColors.muted)),
              ),
              const Spacer(),
              StatusChip(label: zone, color: zoneColor, icon: LucideIcons.signal),
            ],
          ),
          const SizedBox(height: 16),
          LayoutBuilder(builder: (context, box) {
            final w = box.maxWidth;
            final lowX = pos(_rxLow) * w, highX = pos(_rxHigh) * w;
            return SizedBox(
              height: 26,
              child: Stack(
                clipBehavior: Clip.none,
                children: [
                  Positioned(
                    left: 0,
                    right: 0,
                    top: 8,
                    child: ClipRRect(
                      borderRadius: BorderRadius.circular(AppRadius.pill),
                      child: SizedBox(
                        height: 10,
                        // stretch: ColoredBox tanpa anak setinggi 0 kalau tak dipaksa.
                        child: Row(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                          SizedBox(width: lowX, child: ColoredBox(color: AppColors.warning.withValues(alpha: 0.55))),
                          SizedBox(width: highX - lowX, child: ColoredBox(color: AppColors.success.withValues(alpha: 0.6))),
                          Expanded(child: ColoredBox(color: AppColors.warning.withValues(alpha: 0.55))),
                        ]),
                      ),
                    ),
                  ),
                  if (dbm != null)
                    Positioned(
                      left: pos(dbm) * w - 9,
                      top: 0,
                      child: Container(
                        width: 18,
                        height: 26,
                        alignment: Alignment.center,
                        child: Container(
                          width: 6,
                          height: 26,
                          decoration: BoxDecoration(
                            color: AppColors.text,
                            borderRadius: BorderRadius.circular(3),
                            border: Border.all(color: AppColors.surface, width: 1.5),
                          ),
                        ),
                      ),
                    ),
                ],
              ),
            );
          }),
          const SizedBox(height: 6),
          Row(
            children: [
              Text('${_min.toInt()}', style: AppText.mono(size: 10.5, color: AppColors.faint)),
              const Spacer(),
              Text('aman ${_rxLow.toInt()} s/d ${_rxHigh.toInt()} dBm',
                  style: TextStyle(fontSize: 11, color: AppColors.muted)),
              const Spacer(),
              Text('${_max.toInt()}', style: AppText.mono(size: 10.5, color: AppColors.faint)),
            ],
          ),
        ],
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Info teknis — ubin 2 kolom, ketuk untuk menyalin
// ---------------------------------------------------------------------------

typedef OnuFact = ({IconData icon, String label, String value, bool mono, bool copy});

/// Daftar fakta teknis ONU yang terisi saja.
List<OnuFact> onuFacts(Onu onu) {
  final facts = <OnuFact>[
    if (_has(onu.serialNumber))
      (icon: LucideIcons.hash, label: 'Serial Number', value: onu.serialNumber!, mono: true, copy: true),
    if (_has(onu.mac) && onu.mac != onu.serialNumber)
      (icon: LucideIcons.network, label: 'MAC', value: onu.mac!, mono: true, copy: true),
    if (_has(onu.typeName))
      (icon: LucideIcons.router, label: 'Tipe ONU', value: onu.typeName!, mono: false, copy: false),
    (
      icon: LucideIcons.cable,
      label: 'Slot / Port / ID',
      value: '${onu.slot} / ${onu.port} / ${onu.onuId}',
      mono: true,
      copy: false,
    ),
    (icon: LucideIcons.shieldCheck, label: 'Admin state', value: onu.adminState, mono: false, copy: false),
    (icon: LucideIcons.activity, label: 'Phase state', value: onu.phaseState, mono: false, copy: false),
    if (_has(onu.lastDownCause))
      (icon: LucideIcons.unlink, label: 'Penyebab down', value: onu.lastDownCause!, mono: false, copy: false),
  ];
  return facts;
}

bool _has(String? v) => v != null && v.trim().isNotEmpty;

class OnuFactGrid extends StatelessWidget {
  const OnuFactGrid({super.key, required this.facts});
  final List<OnuFact> facts;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(builder: (context, box) {
      const gap = 10.0;
      final w = (box.maxWidth - gap) / 2;
      return Wrap(
        spacing: gap,
        runSpacing: gap,
        children: [for (final f in facts) SizedBox(width: w, child: _FactTile(fact: f))],
      );
    });
  }
}

class _FactTile extends StatelessWidget {
  const _FactTile({required this.fact});
  final OnuFact fact;

  @override
  Widget build(BuildContext context) {
    return GlassCard(
      padding: const EdgeInsets.fromLTRB(12, 11, 10, 11),
      onTap: fact.copy ? () => copyWithToast(context, fact.value, label: fact.label) : null,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(children: [
            Icon(fact.icon, size: 13, color: AppColors.primary),
            const SizedBox(width: 6),
            Expanded(
              child: Text(fact.label,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(fontSize: 11.5, color: AppColors.muted, fontWeight: FontWeight.w600)),
            ),
            if (fact.copy) Icon(LucideIcons.copy, size: 12, color: AppColors.faint),
          ]),
          const SizedBox(height: 6),
          Text(
            fact.value,
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
            style: fact.mono
                ? AppText.mono(size: 12.5, weight: FontWeight.w600, color: AppColors.text)
                : TextStyle(fontSize: 13, fontWeight: FontWeight.w600, color: AppColors.text, height: 1.3),
          ),
        ],
      ),
    );
  }
}

/// Salin ke clipboard + beri tahu singkat.
void copyWithToast(BuildContext context, String value, {required String label}) {
  Clipboard.setData(ClipboardData(text: value));
  HapticFeedback.selectionClick();
  ScaffoldMessenger.of(context)
    ..hideCurrentSnackBar()
    ..showSnackBar(SnackBar(content: Text('$label disalin'), duration: const Duration(seconds: 2)));
}

// ---------------------------------------------------------------------------
// Aksi
// ---------------------------------------------------------------------------

/// Satu ubin aksi (ikon dalam kotak berwarna + label) untuk baris aksi ONU.
class OnuActionTile extends StatelessWidget {
  const OnuActionTile({
    super.key,
    required this.icon,
    required this.label,
    required this.color,
    required this.onTap,
    this.busy = false,
  });

  final IconData icon;
  final String label;
  final Color color;
  final VoidCallback? onTap;
  final bool busy;

  @override
  Widget build(BuildContext context) {
    return Expanded(
      child: Opacity(
        opacity: onTap == null ? 0.5 : 1,
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(AppRadius.control),
          child: Padding(
            padding: const EdgeInsets.symmetric(vertical: 6),
            child: Column(
              children: [
                Container(
                  width: 48,
                  height: 48,
                  decoration: BoxDecoration(
                    color: color.withValues(alpha: 0.13),
                    borderRadius: BorderRadius.circular(14),
                    border: Border.all(color: color.withValues(alpha: 0.3)),
                  ),
                  child: busy
                      ? Padding(
                          padding: const EdgeInsets.all(14),
                          child: CircularProgressIndicator(strokeWidth: 2, color: color),
                        )
                      : Icon(icon, size: 21, color: color),
                ),
                const SizedBox(height: 7),
                Text(label,
                    textAlign: TextAlign.center,
                    style: TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: AppColors.text)),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
