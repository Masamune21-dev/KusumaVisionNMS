import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:kusumavision_nms/core/icons.dart';
import 'package:latlong2/latlong.dart';

import '../../core/odp_colors.dart';
import '../../core/onu_status.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/odp_photo.dart';
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

  /// Lapisan turunan (filter + garis + marker + titik) — dibangun ulang hanya saat
  /// data atau filter berubah. Membangunnya di tiap `build` membuat [PolylineLayer]
  /// menerima list baru sehingga cache proyeksinya selalu terbuang.
  _MapLayers? _layers;

  _MapLayers _layersFor(MapData map, int? oltId, bool showOnus, bool showOdps) {
    final cached = _layers;
    if (cached != null &&
        identical(cached.source, map) &&
        cached.oltId == oltId &&
        cached.showOnus == showOnus &&
        cached.showOdps == showOdps) {
      return cached;
    }

    final pins = showOnus
        ? map.pins.where((p) => oltId == null || p.oltId == oltId).toList()
        : <MapPin>[];
    final odps = showOdps
        ? map.odps.where((o) => oltId == null || o.oltId == oltId).toList()
        : <MapOdp>[];

    return _layers = _MapLayers(
      source: map,
      oltId: oltId,
      showOnus: showOnus,
      showOdps: showOdps,
      pins: pins,
      odps: odps,
      cables: _cables(odps),
      // `Alignment.topCenter` = widget digambar DI ATAS titik, jadi ujung pin harus
      // berada di dasar-tengah kotak marker (lihat _PinGlyph).
      odpMarkers: [
        for (final odp in odps)
          Marker(
            point: LatLng(odp.latitude, odp.longitude),
            // Lebih lebar/tinggi dari glyph supaya badge di kanan-atas muat tanpa
            // memotong pinnya.
            width: 48,
            height: 44,
            alignment: Alignment.topCenter,
            child: _OdpMarker(odp: odp, onTap: () => _showOdpSheet(odp)),
          ),
      ],
      pinMarkers: [
        for (final pin in pins)
          Marker(
            point: LatLng(pin.latitude, pin.longitude),
            width: 38,
            height: 38,
            alignment: Alignment.topCenter,
            child: _OnuMarker(pin: pin, onTap: () => _showPinSheet(pin)),
          ),
      ],
      // Titik ONU dulu, ODP belakangan → ODP tergambar di atas.
      dots: [
        for (final pin in pins)
          CircleMarker(
            point: LatLng(pin.latitude, pin.longitude),
            radius: 4.5,
            color: pin.online ? AppColors.success : AppColors.danger,
            borderColor: const Color(0xE6FFFFFF),
            borderStrokeWidth: 1,
          ),
        for (final odp in odps)
          CircleMarker(
            point: LatLng(odp.latitude, odp.longitude),
            radius: 6.5,
            color: odpColorOf(odp.color),
            borderColor: const Color(0xE6FFFFFF),
            borderStrokeWidth: 1.5,
          ),
      ],
    );
  }

  /// Ketuk peta di mode titik: buka pin/ODP terdekat dalam radius sentuh jari.
  /// (Di mode marker, ketukan pada pin sudah ditangkap GestureDetector-nya sendiri.)
  void _onMapTap(TapPosition tap, LatLng _) {
    final layers = _layers;
    final at = tap.relative;
    if (layers == null || at == null) return;

    final camera = _map.camera;
    const reach = 24.0 * 24.0;
    double best = reach;
    Object? hit;

    void consider(Object item, double lat, double lng) {
      final d = camera.latLngToScreenOffset(LatLng(lat, lng)) - at;
      final dist = d.dx * d.dx + d.dy * d.dy;
      if (dist < best) {
        best = dist;
        hit = item;
      }
    }

    for (final p in layers.pins) {
      consider(p, p.latitude, p.longitude);
    }
    for (final o in layers.odps) {
      consider(o, o.latitude, o.longitude);
    }

    final target = hit;
    if (target is MapPin) _showPinSheet(target);
    if (target is MapOdp) _showOdpSheet(target);
  }

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
          final layers = _layersFor(map, oltId, showOnus, showOdps);

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
                  onTap: _onMapTap,
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
                  if (layers.cables.isNotEmpty) PolylineLayer(polylines: layers.cables),
                  _PinLayer(layers: layers, kind: _PinLayerKind.dots),
                  _PinLayer(layers: layers, kind: _PinLayerKind.odps),
                  _PinLayer(layers: layers, kind: _PinLayerKind.pins),
                ],
              ),
              _TopBar(
                olts: map.olts,
                selectedOltId: oltId,
                pinCount: layers.pins.length,
                odpCount: layers.odps.length,
                onPickOlt: (id) => ref.read(mapOltFilterProvider.notifier).state = id,
                onLayers: _showLayersSheet,
                onRefresh: () => ref.invalidate(mapDataProvider),
              ),
              // Legenda warna pin (kiri-bawah) + tombol pusatkan ulang (kanan-bawah),
              // tepat di atas navbar melayang. `padding.bottom` di sini SUDAH memuat
              // tinggi navbar (Scaffold shell ber-`extendBody`), jadi cukup ditambah jarak.
              Positioned(
                left: 12,
                right: 12,
                bottom: MediaQuery.of(context).padding.bottom + 12,
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.end,
                  children: [
                    const _Legend(),
                    const Spacer(),
                    _FloatingIconButton(
                      icon: LucideIcons.navigation,
                      tooltip: 'Pusatkan ulang',
                      onTap: () => _map.move(LatLng(map.centerLat, map.centerLng), map.centerZoom),
                    ),
                  ],
                ),
              ),
              if (map.isEmpty)
                Positioned(
                  left: 24,
                  right: 24,
                  // Di atas baris legenda (±44 px) + jarak.
                  bottom: MediaQuery.of(context).padding.bottom + 72,
                  child: const _EmptyHint(),
                ),
            ],
          );
        },
      ),
    );
  }

  /// Sheet "Lapisan peta": tampilkan/sembunyikan ONU & ODP, pilih gaya tile.
  void _showLayersSheet() {
    showModalBottomSheet<void>(
      context: context,
      // Di atas navbar shell (tab Peta hidup di navigator cabang, di bawah navbar).
      useRootNavigator: true,
      backgroundColor: Colors.transparent,
      builder: (_) => Consumer(
        builder: (context, ref, _) {
          final style = ref.watch(mapTileStyleProvider);
          final showOnus = ref.watch(mapShowOnusProvider);
          final showOdps = ref.watch(mapShowOdpsProvider);
          final t = Theme.of(context).textTheme;

          return _SheetShell(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text('Lapisan peta', style: t.titleMedium),
                const SizedBox(height: 6),
                SwitchListTile(
                  contentPadding: EdgeInsets.zero,
                  secondary: Icon(LucideIcons.router, color: AppColors.success),
                  title: const Text('Pin ONU pelanggan'),
                  value: showOnus,
                  onChanged: (v) => ref.read(mapShowOnusProvider.notifier).state = v,
                ),
                SwitchListTile(
                  contentPadding: EdgeInsets.zero,
                  secondary: Icon(LucideIcons.odp, color: kDefaultOdpColor),
                  title: const Text('Pin ODP + kabel'),
                  value: showOdps,
                  onChanged: (v) => ref.read(mapShowOdpsProvider.notifier).state = v,
                ),
                const SizedBox(height: 10),
                Text('Gaya peta', style: t.labelMedium?.copyWith(color: AppColors.muted)),
                const SizedBox(height: 8),
                SegmentedButton<MapTileStyle>(
                  showSelectedIcon: false,
                  segments: [
                    for (final s in MapTileStyle.values)
                      ButtonSegment(value: s, label: Text(s.label)),
                  ],
                  selected: {style},
                  onSelectionChanged: (v) => ref.read(mapTileStyleProvider.notifier).state = v.first,
                  style: SegmentedButton.styleFrom(
                    minimumSize: const Size(0, 44),
                    selectedBackgroundColor: AppColors.primary.withValues(alpha: 0.16),
                    selectedForegroundColor: AppColors.primary,
                    foregroundColor: AppColors.muted,
                    side: BorderSide(color: AppColors.borderStrong),
                  ),
                ),
              ],
            ),
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
      // Di atas navbar shell (tab Peta hidup di navigator cabang, di bawah navbar).
      useRootNavigator: true,
      backgroundColor: Colors.transparent,
      builder: (sheet) => _SheetShell(
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
                StatusChip.onu(
                    OnuStatus.resolve(
                      online: pin.online,
                      phaseState: pin.phaseState,
                      lastDownCause: pin.lastDownCause,
                      adminState: pin.adminState,
                    ),
                    dense: true),
              ],
            ),
            const SizedBox(height: 4),
            Text(
              [pin.oltName, pin.interface].whereType<String>().join(' · '),
              style: TextStyle(
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
                  Navigator.pop(sheet);
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
      // Di atas navbar shell (tab Peta hidup di navigator cabang, di bawah navbar).
      useRootNavigator: true,
      backgroundColor: Colors.transparent,
      isScrollControlled: true,
      builder: (sheet) => _SheetShell(
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
                        style: TextStyle(color: AppColors.muted, fontSize: 12),
                      ),
                    ],
                  ),
                ),
                Text('${odp.onlineCount}/${odp.onus.length}',
                    style: TextStyle(
                        color: AppColors.primary,
                        fontWeight: FontWeight.w800,
                        fontFeatures: _tnum)),
              ],
            ),
            // Foto dokumentasi ODP (kalau ada) — lihat-saja, ketuk untuk perbesar.
            if ((odp.photoUrl ?? '').isNotEmpty) ...[
              const SizedBox(height: 12),
              OdpPhoto(url: odp.photoUrl, height: 130),
            ],
            const SizedBox(height: 12),
            if (odp.onus.isEmpty)
              Text('Belum ada ONU yang dikaitkan.',
                  style: TextStyle(color: AppColors.muted, fontSize: 12.5))
            else
              ConstrainedBox(
                constraints: BoxConstraints(
                    maxHeight: MediaQuery.of(context).size.height * 0.35),
                child: ListView.separated(
                  shrinkWrap: true,
                  itemCount: odp.onus.length,
                  separatorBuilder: (_, __) => Divider(height: 14, color: AppColors.border),
                  itemBuilder: (_, i) => _OdpOnuTile(
                    onu: odp.onus[i],
                    onTap: () {
                      Navigator.pop(sheet);
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
                      Navigator.pop(sheet);
                      context.push('/odps/${odp.id}');
                    },
                    icon: const Icon(LucideIcons.odp, size: 18),
                    label: const Text('Halaman ODP'),
                  ),
                ),
                const SizedBox(width: 8),
                OutlinedButton.icon(
                  onPressed: () {
                    Navigator.pop(sheet);
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
                style: TextStyle(fontSize: 12, color: AppColors.text)),
          ),
        ]),
      );
}

/// Hasil turunan data peta untuk satu kombinasi filter (lihat `_layersFor`).
class _MapLayers {
  _MapLayers({
    required this.source,
    required this.oltId,
    required this.showOnus,
    required this.showOdps,
    required this.pins,
    required this.odps,
    required this.cables,
    required this.odpMarkers,
    required this.pinMarkers,
    required this.dots,
  });

  final MapData source;
  final int? oltId;
  final bool showOnus, showOdps;
  final List<MapPin> pins;
  final List<MapOdp> odps;
  final List<Polyline> cables;
  final List<Marker> odpMarkers, pinMarkers;
  final List<CircleMarker> dots;

  /// Batas pin di layar yang masih digambar sebagai widget pin. Di atas itu (zoom
  /// jauh) semua pin digambar sebagai titik oleh satu painter — ratusan widget pin
  /// yang dibangun ulang tiap frame saat peta digeser itulah yang membuat aplikasi
  /// macet sampai muncul dialog "tidak merespons".
  static const markerLimit = 120;

  MapCamera? _camera;
  bool _markers = true;

  /// Mode marker bila pin di layar ≤ [markerLimit]. Dihitung sekali per posisi kamera
  /// (ketiga [_PinLayer] berbagi hasilnya) dan berhenti menghitung begitu lewat batas.
  bool useMarkers(MapCamera camera) {
    if (identical(camera, _camera)) return _markers;
    final bounds = camera.visibleBounds;
    var visible = 0;
    bool over() {
      for (final p in pins) {
        if (!bounds.contains(LatLng(p.latitude, p.longitude))) continue;
        if (++visible > markerLimit) return true;
      }
      for (final o in odps) {
        if (!bounds.contains(LatLng(o.latitude, o.longitude))) continue;
        if (++visible > markerLimit) return true;
      }
      return false;
    }

    _camera = camera;
    return _markers = !over();
  }
}

enum _PinLayerKind { dots, odps, pins }

/// Satu lapisan pin yang memilih sendiri wujudnya mengikuti kamera: titik (mode
/// jauh) atau widget pin (mode dekat). [MarkerLayer] sudah membuang marker di luar
/// layar, jadi di mode dekat hanya pin yang terlihat yang dibangun.
class _PinLayer extends StatelessWidget {
  const _PinLayer({required this.layers, required this.kind});

  final _MapLayers layers;
  final _PinLayerKind kind;

  @override
  Widget build(BuildContext context) {
    final markers = layers.useMarkers(MapCamera.of(context));

    return switch (kind) {
      _PinLayerKind.dots when !markers && layers.dots.isNotEmpty => CircleLayer(
        circles: layers.dots,
      ),
      _PinLayerKind.odps when markers && layers.odpMarkers.isNotEmpty => MarkerLayer(
        markers: layers.odpMarkers,
      ),
      _PinLayerKind.pins when markers && layers.pinMarkers.isNotEmpty => MarkerLayer(
        markers: layers.pinMarkers,
      ),
      _ => const SizedBox.shrink(),
    };
  }
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
        boxShadow: AppShadow.floating(blur: 26, dy: 12),
      ),
      // Material transparan sendiri: riak sentuh (InkWell/ListTile) digambar di
      // Material terdekat — tanpa ini jatuh ke Material sheet DI BAWAH warna panel
      // ini, jadi tak terlihat (dan SwitchListTile memicu assertion).
      child: Material(
        type: MaterialType.transparency,
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
                    style: TextStyle(
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
/// Dilukis langsung dengan [CustomPaint] — dulu dua `Icon` bertumpuk, artinya dua
/// tata-letak glyph font per pin per frame saat peta digeser.
///
/// **Jangan pakai bayangan ber-blur**: di sebagian perangkat (renderer Impeller)
/// bayangan blur pada glyph ter-render sebagai blok hitam pekat. Kontras terhadap
/// citra satelit didapat dari garis tepi gelap tanpa blur.
class _PinGlyph extends StatelessWidget {
  const _PinGlyph({required this.color});

  final Color color;

  /// Tinggi glyph pin. Kotak marker di [MapScreen] disetel mengikuti angka ini
  /// (ujung pin di dasar kotak, badge ODP menimpa kepala pin di kanan-atas).
  static const double size = 34;

  @override
  Widget build(BuildContext context) {
    return CustomPaint(size: const Size(size * 0.8, size), painter: _PinPainter(color));
  }
}

/// Teardrop pin (kepala bulat r=8 di (12,10), ujung di (12,22.5) pada kotak 24×24,
/// sama dengan pin peta web) + titik putih di tengah kepala.
class _PinPainter extends CustomPainter {
  const _PinPainter(this.color);

  final Color color;

  static final ui.Path _shape = ui.Path()
    ..moveTo(12, 22.5)
    ..cubicTo(9.5, 20.2, 4, 15, 4, 10)
    ..arcToPoint(const Offset(20, 10), radius: const Radius.circular(8))
    ..cubicTo(20, 15, 14.5, 20.2, 12, 22.5)
    ..close();

  static final Paint _outline = Paint()
    ..color = const Color(0xCC000000)
    ..style = PaintingStyle.stroke
    ..strokeWidth = 2.2
    ..strokeJoin = StrokeJoin.round;

  static final Paint _dot = Paint()..color = Colors.white;

  @override
  void paint(Canvas canvas, Size size) {
    // Skala seragam dari tinggi; kotak 24×24 dipusatkan horizontal, ujung di dasar.
    final scale = size.height / 23.5;
    canvas
      ..translate(size.width / 2 - 12 * scale, 0)
      ..scale(scale);
    canvas.drawPath(_shape, _outline);
    canvas.drawPath(_shape, Paint()..color = color);
    canvas.drawCircle(const Offset(12, 10), 3, _dot);
  }

  @override
  bool shouldRepaint(_PinPainter old) => old.color != color;
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

/// Panel kaca kecil untuk kontrol yang melayang di atas peta (pil, atau
/// lingkaran bila [circle]).
BoxDecoration _floatingDecoration({bool circle = false}) => BoxDecoration(
      color: AppColors.bgElevated.withValues(alpha: 0.94),
      shape: circle ? BoxShape.circle : BoxShape.rectangle,
      borderRadius: circle ? null : BorderRadius.circular(AppRadius.pill),
      border: Border.all(color: AppColors.borderStrong),
      boxShadow: AppShadow.floating(blur: 14, dy: 4),
    );

/// Bar kontrol peta — satu baris: filter OLT + jumlah, Lapisan, Muat ulang.
class _TopBar extends StatelessWidget {
  const _TopBar({
    required this.olts,
    required this.selectedOltId,
    required this.pinCount,
    required this.odpCount,
    required this.onPickOlt,
    required this.onLayers,
    required this.onRefresh,
  });

  final List<MapOlt> olts;
  final int? selectedOltId;
  final int pinCount, odpCount;
  final ValueChanged<int?> onPickOlt;
  final VoidCallback onLayers, onRefresh;

  @override
  Widget build(BuildContext context) {
    return Positioned(
      top: MediaQuery.of(context).padding.top + 8,
      left: 12,
      right: 12,
      child: Row(
        children: [
          Expanded(
            child: Container(
              height: 44,
              padding: const EdgeInsets.only(left: 12, right: 12),
              decoration: _floatingDecoration(),
              child: Row(
                children: [
                  Icon(LucideIcons.filter, size: 15, color: AppColors.faint),
                  const SizedBox(width: 6),
                  Expanded(
                    child: DropdownButtonHideUnderline(
                      child: DropdownButton<int?>(
                        value: olts.any((o) => o.id == selectedOltId) ? selectedOltId : null,
                        isDense: true,
                        isExpanded: true,
                        borderRadius: BorderRadius.circular(AppRadius.control),
                        dropdownColor: AppColors.bgElevated,
                        style: TextStyle(
                            fontSize: 13, fontWeight: FontWeight.w600, color: AppColors.text),
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
                      style: TextStyle(fontSize: 11, color: AppColors.faint, fontFeatures: _tnum)),
                ],
              ),
            ),
          ),
          const SizedBox(width: 8),
          _FloatingIconButton(icon: LucideIcons.layers, tooltip: 'Lapisan peta', onTap: onLayers),
          const SizedBox(width: 8),
          _FloatingIconButton(icon: LucideIcons.refreshCw, tooltip: 'Muat ulang', onTap: onRefresh),
        ],
      ),
    );
  }
}

/// Tombol ikon bulat 44 px yang melayang di atas peta.
class _FloatingIconButton extends StatelessWidget {
  const _FloatingIconButton({required this.icon, required this.tooltip, required this.onTap});

  final IconData icon;
  final String tooltip;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Tooltip(
      message: tooltip,
      child: Material(
        type: MaterialType.transparency,
        child: InkWell(
          onTap: onTap,
          customBorder: const CircleBorder(),
          child: Ink(
            width: 44,
            height: 44,
            decoration: _floatingDecoration(circle: true),
            child: Icon(icon, size: 19, color: AppColors.text),
          ),
        ),
      ),
    );
  }
}

/// Legenda warna pin — sama dengan legenda peta web.
class _Legend extends StatelessWidget {
  const _Legend();

  @override
  Widget build(BuildContext context) {
    Widget item(Color c, String label) => Row(mainAxisSize: MainAxisSize.min, children: [
          Container(
            width: 9,
            height: 9,
            decoration: BoxDecoration(
              color: c,
              shape: BoxShape.circle,
              border: Border.all(color: Colors.white, width: 1),
            ),
          ),
          const SizedBox(width: 5),
          Text(label, style: TextStyle(fontSize: 11, fontWeight: FontWeight.w600, color: AppColors.muted)),
        ]);

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      decoration: _floatingDecoration(),
      child: Row(mainAxisSize: MainAxisSize.min, children: [
        item(AppColors.success, 'Online'),
        const SizedBox(width: 10),
        item(AppColors.danger, 'Offline'),
        const SizedBox(width: 10),
        item(kDefaultOdpColor, 'ODP'),
      ]),
    );
  }
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
      child: Row(
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
