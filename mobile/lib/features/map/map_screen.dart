import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:kusumavision_nms/core/icons.dart';
import 'package:latlong2/latlong.dart';

import '../../core/odp_colors.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/rx_power_badge.dart';
import '../../core/widgets/status_chip.dart';
import '../../data/read_providers.dart';
import '../../models/map_data.dart';
import '../../models/odp.dart';
import '../../theme/app_theme.dart';
import '../odp/odp_color_sheet.dart';
import 'map_providers.dart';

const _tnum = [FontFeature.tabularFigures()];

/// Peta ONU & ODP satu layar penuh. Navigasi bawah tetap terlihat karena layar
/// ini hidup sebagai cabang [HomeShell] (Scaffold shell memakai `extendBody`).
///
/// Baca-saja: menambah/menggeser pin tetap dilakukan di web. Aksi ONU (reboot,
/// ganti nama) dibuka lewat layar Detail ONU dari sheet pin.
class MapScreen extends ConsumerStatefulWidget {
  const MapScreen({super.key});

  @override
  ConsumerState<MapScreen> createState() => _MapScreenState();
}

class _MapScreenState extends ConsumerState<MapScreen> {
  final _map = MapController();

  /// Fokus yang diminta layar lain tapi belum sempat diterapkan (peta belum siap).
  MapFocus? _pending;
  bool _ready = false;

  @override
  void initState() {
    super.initState();
    // Fokus bisa sudah di-set sebelum tab peta pertama kali dibangun.
    _pending = ref.read(mapFocusProvider);
  }

  void _applyFocus(MapFocus focus) {
    _map.move(LatLng(focus.lat, focus.lng), 17);
    ref.read(mapFocusProvider.notifier).state = null;

    // Buka kartu ODP-nya sekaligus supaya konteksnya jelas setelah pindah tab.
    final odpId = focus.odpId;
    if (odpId == null) return;
    final data = ref.read(mapDataProvider).valueOrNull;
    final odp = data?.odps.where((o) => o.id == odpId).firstOrNull;
    if (odp != null) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) _showOdpSheet(odp);
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final data = ref.watch(mapDataProvider);
    final style = ref.watch(mapTileStyleProvider);
    final oltId = ref.watch(mapOltFilterProvider);
    final showOnus = ref.watch(mapShowOnusProvider);
    final showOdps = ref.watch(mapShowOdpsProvider);

    ref.listen<MapFocus?>(mapFocusProvider, (_, next) {
      if (next == null) return;
      if (_ready) {
        _applyFocus(next);
      } else {
        _pending = next;
      }
    });

    return Scaffold(
      extendBodyBehindAppBar: true,
      body: AsyncView<MapData>(
        value: data,
        onRetry: () => ref.refresh(mapDataProvider),
        data: (map) {
          final pins = showOnus
              ? map.pins.where((p) => oltId == null || p.oltId == oltId).toList()
              : <MapPin>[];
          final odps = showOdps
              ? map.odps.where((o) => oltId == null || o.oltId == oltId).toList()
              : <MapOdp>[];

          return Stack(
            children: [
              FlutterMap(
                mapController: _map,
                options: MapOptions(
                  initialCenter: LatLng(map.centerLat, map.centerLng),
                  initialZoom: map.centerZoom,
                  minZoom: 3,
                  maxZoom: 19,
                  backgroundColor: AppColors.bg,
                  onMapReady: () {
                    _ready = true;
                    final pending = _pending;
                    if (pending != null) {
                      _pending = null;
                      _applyFocus(pending);
                    }
                  },
                ),
                children: [
                  _tileLayer(style),
                  if (odps.isNotEmpty) PolylineLayer(polylines: _cables(odps)),
                  // `Alignment.topCenter` = widget digambar DI ATAS titik, jadi ujung
                  // pin harus berada di dasar-tengah kotak marker (lihat _PinGlyph).
                  if (odps.isNotEmpty)
                    MarkerLayer(
                      markers: [
                        for (final odp in odps)
                          Marker(
                            point: LatLng(odp.latitude, odp.longitude),
                            // Lebih lebar/tinggi dari glyph (34+3,5 garis tepi) supaya
                            // badge di kanan-atas muat tanpa memotong pinnya.
                            width: 48,
                            height: 44,
                            alignment: Alignment.topCenter,
                            child: _OdpMarker(odp: odp, onTap: () => _showOdpSheet(odp)),
                          ),
                      ],
                    ),
                  if (pins.isNotEmpty)
                    MarkerLayer(
                      markers: [
                        for (final pin in pins)
                          Marker(
                            point: LatLng(pin.latitude, pin.longitude),
                            width: 38,
                            height: 38,
                            alignment: Alignment.topCenter,
                            child: _OnuMarker(pin: pin, onTap: () => _showPinSheet(pin)),
                          ),
                      ],
                    ),
                ],
              ),
              _TopBar(
                olts: map.olts,
                selectedOltId: oltId,
                style: style,
                showOnus: showOnus,
                showOdps: showOdps,
                pinCount: pins.length,
                odpCount: odps.length,
                onPickOlt: (id) => ref.read(mapOltFilterProvider.notifier).state = id,
                onCycleStyle: () {
                  final next = MapTileStyle
                      .values[(style.index + 1) % MapTileStyle.values.length];
                  ref.read(mapTileStyleProvider.notifier).state = next;
                },
                onToggleOnus: () =>
                    ref.read(mapShowOnusProvider.notifier).state = !showOnus,
                onToggleOdps: () =>
                    ref.read(mapShowOdpsProvider.notifier).state = !showOdps,
                onRefresh: () => ref.invalidate(mapDataProvider),
                onRecenter: () =>
                    _map.move(LatLng(map.centerLat, map.centerLng), map.centerZoom),
              ),
              if (map.isEmpty)
                const Positioned(
                  left: 24,
                  right: 24,
                  bottom: 140,
                  child: _EmptyHint(),
                ),
            ],
          );
        },
      ),
    );
  }

  /// Tile: Google tanpa API key (sama seperti peta web) dengan OSM sebagai
  /// `fallbackUrl` — kalau tile Google menolak permintaan dari aplikasi, peta
  /// tetap tergambar alih-alih kosong. User-Agent di-set agar tak diblokir.
  Widget _tileLayer(MapTileStyle style) {
    const osm = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
    final google = style == MapTileStyle.googleSatellite ? 's' : 'm';

    if (style == MapTileStyle.osm) {
      return TileLayer(
        key: const ValueKey('osm'),
        urlTemplate: osm,
        userAgentPackageName: 'net.kusumavision.nms',
        maxNativeZoom: 19,
      );
    }

    return TileLayer(
      key: ValueKey('google-$google'),
      urlTemplate: 'https://mt{s}.google.com/vt/lyrs=$google&x={x}&y={y}&z={z}&hl=id',
      subdomains: const ['0', '1', '2', '3'],
      fallbackUrl: osm,
      maxNativeZoom: 19,
      userAgentPackageName: 'net.kusumavision.nms',
      tileProvider: NetworkTileProvider(
        headers: {
          'User-Agent':
              'Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
        },
        silenceExceptions: true,
      ),
    );
  }

  /// Garis "kabel" ODP→ONU, hanya untuk ONU yang pin-nya sudah ada koordinatnya.
  List<Polyline> _cables(List<MapOdp> odps) {
    final lines = <Polyline>[];
    for (final odp in odps) {
      final from = LatLng(odp.latitude, odp.longitude);
      for (final onu in odp.onus) {
        final lat = onu.latitude, lng = onu.longitude;
        if (lat == null || lng == null) continue;
        lines.add(Polyline(
          points: [from, LatLng(lat, lng)],
          strokeWidth: 2,
          color: (onu.online ? AppColors.success : AppColors.danger)
              .withValues(alpha: 0.65),
        ));
      }
    }
    return lines;
  }

  void _showPinSheet(MapPin pin) {
    showModalBottomSheet<void>(
      context: context,
      backgroundColor: Colors.transparent,
      builder: (_) => _SheetShell(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(pin.title,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: Theme.of(context).textTheme.titleMedium),
                ),
                StatusChip.online(pin.online, dense: true),
              ],
            ),
            const SizedBox(height: 4),
            Text(
              [pin.oltName, pin.interface].whereType<String>().join(' · '),
              style: const TextStyle(
                  color: AppColors.muted, fontSize: 12.5, fontFeatures: _tnum),
            ),
            const SizedBox(height: 12),
            Wrap(spacing: 8, runSpacing: 8, children: [
              if (pin.serialNumber != null) _chip(LucideIcons.router, pin.serialNumber!),
              if (pin.address != null && pin.address!.trim().isNotEmpty)
                _chip(LucideIcons.mapPin, pin.address!),
              if (pin.phone != null && pin.phone!.trim().isNotEmpty)
                _chip(LucideIcons.smartphone, pin.phone!),
            ]),
            const SizedBox(height: 12),
            Row(children: [
              RxPowerBadge(dbm: pin.rxPowerDbm, online: pin.online),
              const Spacer(),
              FilledButton.icon(
                onPressed: () {
                  Navigator.pop(context);
                  context.push(
                      '/olts/${pin.oltId}/ports/${pin.slot}/${pin.port}/onus/${pin.onuId}');
                },
                icon: const Icon(LucideIcons.arrowRight, size: 18),
                label: const Text('Detail ONU'),
              ),
            ]),
          ],
        ),
      ),
    );
  }

  /// Ganti warna pin ODP dari peta (bawaan: se-PON-port, sama seperti web).
  Future<void> _pickOdpColor(MapOdp odp) async {
    final odps = ref.read(mapDataProvider).valueOrNull?.odps ?? const <MapOdp>[];
    final siblings = odp.portLabel == null
        ? 1
        : odps
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

  void _showOdpSheet(MapOdp odp) {
    final color = odpColorOf(odp.color);

    showModalBottomSheet<void>(
      context: context,
      backgroundColor: Colors.transparent,
      isScrollControlled: true,
      builder: (_) => _SheetShell(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Container(
                  padding: const EdgeInsets.all(8),
                  decoration: BoxDecoration(
                    color: color.withValues(alpha: 0.14),
                    borderRadius: BorderRadius.circular(AppRadius.chip),
                  ),
                  child: Icon(LucideIcons.odp, size: 17, color: color),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(odp.name,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: Theme.of(context).textTheme.titleMedium),
                      Text(
                        [
                          odp.oltName,
                          if (odp.portLabel != null) 'Port ${odp.portLabel}',
                        ].whereType<String>().join(' · '),
                        style: const TextStyle(color: AppColors.muted, fontSize: 12),
                      ),
                    ],
                  ),
                ),
                Text('${odp.onlineCount}/${odp.onus.length}',
                    style: const TextStyle(
                        color: AppColors.primary,
                        fontWeight: FontWeight.w800,
                        fontFeatures: _tnum)),
              ],
            ),
            const SizedBox(height: 12),
            if (odp.onus.isEmpty)
              const Text('Belum ada ONU yang dikaitkan.',
                  style: TextStyle(color: AppColors.muted, fontSize: 12.5))
            else
              ConstrainedBox(
                constraints: BoxConstraints(
                    maxHeight: MediaQuery.of(context).size.height * 0.35),
                child: ListView.separated(
                  shrinkWrap: true,
                  itemCount: odp.onus.length,
                  separatorBuilder: (_, __) => const Divider(height: 14, color: AppColors.border),
                  itemBuilder: (_, i) => _OdpOnuTile(
                    onu: odp.onus[i],
                    onTap: () {
                      Navigator.pop(context);
                      final o = odp.onus[i];
                      context.push(
                          '/olts/${o.oltId}/ports/${o.slot}/${o.port}/onus/${o.onuId}');
                    },
                  ),
                ),
              ),
            const SizedBox(height: 12),
            Row(
              children: [
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: () {
                      Navigator.pop(context);
                      context.push('/odps/${odp.id}');
                    },
                    icon: const Icon(LucideIcons.odp, size: 18),
                    label: const Text('Halaman ODP'),
                  ),
                ),
                const SizedBox(width: 8),
                OutlinedButton.icon(
                  onPressed: () {
                    Navigator.pop(context);
                    _pickOdpColor(odp);
                  },
                  icon: Icon(LucideIcons.palette, size: 18, color: color),
                  label: const Text('Warna'),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  Widget _chip(IconData icon, String label) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
        decoration: BoxDecoration(
          color: AppColors.surfaceAlt.withValues(alpha: 0.6),
          borderRadius: BorderRadius.circular(AppRadius.pill),
          border: Border.all(color: AppColors.border),
        ),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          Icon(icon, size: 13, color: AppColors.faint),
          const SizedBox(width: 6),
          ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 220),
            child: Text(label,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(fontSize: 12, color: AppColors.text)),
          ),
        ]),
      );
}

/// Panel kaca pembungkus bottom-sheet.
class _SheetShell extends StatelessWidget {
  const _SheetShell({required this.child});
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: EdgeInsets.fromLTRB(12, 0, 12, MediaQuery.of(context).viewPadding.bottom + 12),
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 16),
      decoration: BoxDecoration(
        color: AppColors.bgElevated,
        borderRadius: BorderRadius.circular(AppRadius.card),
        border: Border.all(color: AppColors.borderStrong),
        boxShadow: const [
          BoxShadow(color: Color(0x66000000), blurRadius: 26, offset: Offset(0, 12)),
        ],
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            width: 38,
            height: 4,
            margin: const EdgeInsets.only(bottom: 12),
            decoration: BoxDecoration(
              color: AppColors.border,
              borderRadius: BorderRadius.circular(999),
            ),
          ),
          child,
        ],
      ),
    );
  }
}

class _OdpOnuTile extends StatelessWidget {
  const _OdpOnuTile({required this.onu, required this.onTap});
  final OdpOnu onu;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final color = onu.online ? AppColors.success : AppColors.danger;
    return InkWell(
      onTap: onTap,
      child: Row(
        children: [
          Container(width: 8, height: 8, decoration: BoxDecoration(color: color, shape: BoxShape.circle)),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(onu.title,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600)),
                Text('#${onu.onuId} · ${onu.serialNumber ?? '-'}',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                        fontSize: 11.5, color: AppColors.faint, fontFeatures: _tnum)),
              ],
            ),
          ),
          RxPowerBadge(dbm: onu.rxPowerDbm, online: onu.online),
        ],
      ),
    );
  }
}

/// Bentuk pin dasar, ujungnya menempel di dasar kotak marker.
///
/// **Jangan pakai `Icon(..., shadows: [...])`**: di sebagian perangkat (renderer
/// Impeller) bayangan ber-blur pada glyph ikon ter-render sebagai blok hitam pekat
/// yang menutupi pinnya. Kontras terhadap citra satelit didapat dari ikon gelap
/// sedikit lebih besar di belakang (garis tepi, tanpa blur).
class _PinGlyph extends StatelessWidget {
  const _PinGlyph({required this.color});

  final Color color;

  /// Tinggi glyph pin. Kotak marker di [MapScreen] disetel mengikuti angka ini
  /// (ujung pin di dasar kotak, badge ODP menimpa kepala pin di kanan-atas).
  static const double size = 34;

  @override
  Widget build(BuildContext context) {
    return Stack(
      alignment: Alignment.bottomCenter,
      children: [
        Icon(Icons.location_on, size: size + 3.5, color: const Color(0xCC000000)),
        Icon(Icons.location_on, size: size, color: color),
      ],
    );
  }
}

/// Pin ONU: hijau (online) / merah (offline) — sama seperti peta web.
class _OnuMarker extends StatelessWidget {
  const _OnuMarker({required this.pin, required this.onTap});
  final MapPin pin;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      child: Align(
        alignment: Alignment.bottomCenter,
        child: _PinGlyph(color: pin.online ? AppColors.success : AppColors.danger),
      ),
    );
  }
}

/// Pin ODP: kuning + badge jumlah ONU **menempel di kanan-atas pin**.
class _OdpMarker extends StatelessWidget {
  const _OdpMarker({required this.odp, required this.onTap});
  final MapOdp odp;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    // Warna pin dari `odps.color` (biasanya seragam per PON port); teks badge
    // mengikuti kecerahannya supaya tetap terbaca di warna terang maupun gelap.
    final color = odpColorOf(odp.color);

    return GestureDetector(
      onTap: onTap,
      child: Stack(
        children: [
          Align(
            alignment: Alignment.bottomCenter,
            child: _PinGlyph(color: color),
          ),
          Positioned(
            top: 0,
            right: 0,
            child: Container(
              constraints: const BoxConstraints(minWidth: 19),
              padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 1),
              decoration: BoxDecoration(
                color: color,
                borderRadius: BorderRadius.circular(999),
                border: Border.all(color: const Color(0xCC000000), width: 1.2),
              ),
              child: Text(
                '${odp.onus.length}',
                textAlign: TextAlign.center,
                style: TextStyle(
                  fontSize: 10.5,
                  height: 1.2,
                  fontWeight: FontWeight.w800,
                  color: odpTextOn(color),
                  fontFeatures: _tnum,
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// Bar kontrol melayang di atas peta: filter OLT, ganti tile, lapisan, refresh.
class _TopBar extends StatelessWidget {
  const _TopBar({
    required this.olts,
    required this.selectedOltId,
    required this.style,
    required this.showOnus,
    required this.showOdps,
    required this.pinCount,
    required this.odpCount,
    required this.onPickOlt,
    required this.onCycleStyle,
    required this.onToggleOnus,
    required this.onToggleOdps,
    required this.onRefresh,
    required this.onRecenter,
  });

  final List<MapOlt> olts;
  final int? selectedOltId;
  final MapTileStyle style;
  final bool showOnus, showOdps;
  final int pinCount, odpCount;
  final ValueChanged<int?> onPickOlt;
  final VoidCallback onCycleStyle, onToggleOnus, onToggleOdps, onRefresh, onRecenter;

  @override
  Widget build(BuildContext context) {
    return Positioned(
      top: MediaQuery.of(context).padding.top + 8,
      left: 12,
      right: 12,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
            decoration: BoxDecoration(
              color: AppColors.bgElevated.withValues(alpha: 0.92),
              borderRadius: BorderRadius.circular(AppRadius.pill),
              border: Border.all(color: AppColors.borderStrong),
            ),
            child: Row(
              children: [
                const Icon(LucideIcons.filter, size: 15, color: AppColors.faint),
                const SizedBox(width: 6),
                Expanded(
                  child: DropdownButtonHideUnderline(
                    child: DropdownButton<int?>(
                      value: olts.any((o) => o.id == selectedOltId) ? selectedOltId : null,
                      isDense: true,
                      isExpanded: true,
                      borderRadius: BorderRadius.circular(AppRadius.control),
                      dropdownColor: AppColors.bgElevated,
                      style: const TextStyle(
                          fontSize: 12.5, fontWeight: FontWeight.w600, color: AppColors.text),
                      items: [
                        const DropdownMenuItem(value: null, child: Text('Semua OLT')),
                        for (final olt in olts)
                          DropdownMenuItem(value: olt.id, child: Text(olt.name)),
                      ],
                      onChanged: onPickOlt,
                    ),
                  ),
                ),
                const SizedBox(width: 6),
                Text('$pinCount ONU · $odpCount ODP',
                    style: const TextStyle(
                        fontSize: 11, color: AppColors.faint, fontFeatures: _tnum)),
              ],
            ),
          ),
          const SizedBox(height: 8),
          Row(
            mainAxisAlignment: MainAxisAlignment.end,
            children: [
              _ctl(LucideIcons.router, 'ONU', active: showOnus, onTap: onToggleOnus),
              const SizedBox(width: 6),
              _ctl(LucideIcons.odp, 'ODP', active: showOdps, onTap: onToggleOdps),
              const SizedBox(width: 6),
              _ctl(LucideIcons.layers, style.label, active: true, onTap: onCycleStyle),
              const SizedBox(width: 6),
              _iconCtl(LucideIcons.navigation, onRecenter),
              const SizedBox(width: 6),
              _iconCtl(LucideIcons.refreshCw, onRefresh),
            ],
          ),
        ],
      ),
    );
  }

  Widget _ctl(IconData icon, String label, {required bool active, required VoidCallback onTap}) {
    final color = active ? AppColors.primary : AppColors.faint;
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(AppRadius.pill),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 7),
        decoration: BoxDecoration(
          color: AppColors.bgElevated.withValues(alpha: 0.92),
          borderRadius: BorderRadius.circular(AppRadius.pill),
          border: Border.all(
              color: active ? color.withValues(alpha: 0.45) : AppColors.borderStrong),
        ),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          Icon(icon, size: 14, color: color),
          const SizedBox(width: 5),
          Text(label,
              style: TextStyle(fontSize: 11.5, fontWeight: FontWeight.w700, color: color)),
        ]),
      ),
    );
  }

  Widget _iconCtl(IconData icon, VoidCallback onTap) => InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(AppRadius.pill),
        child: Container(
          padding: const EdgeInsets.all(8),
          decoration: BoxDecoration(
            color: AppColors.bgElevated.withValues(alpha: 0.92),
            borderRadius: BorderRadius.circular(AppRadius.pill),
            border: Border.all(color: AppColors.borderStrong),
          ),
          child: Icon(icon, size: 15, color: AppColors.muted),
        ),
      );
}

class _EmptyHint extends StatelessWidget {
  const _EmptyHint();

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.bgElevated.withValues(alpha: 0.92),
        borderRadius: BorderRadius.circular(AppRadius.card),
        border: Border.all(color: AppColors.borderStrong),
      ),
      child: const Row(
        children: [
          Icon(LucideIcons.mapPin, size: 18, color: AppColors.faint),
          SizedBox(width: 10),
          Expanded(
            child: Text(
              'Belum ada pin ONU maupun ODP. Tambahkan lewat dashboard web '
              '(menu Peta / ODP), lalu tarik ulang halaman ini.',
              style: TextStyle(fontSize: 12.5, color: AppColors.muted, height: 1.35),
            ),
          ),
        ],
      ),
    );
  }
}
