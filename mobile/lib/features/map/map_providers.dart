import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Gaya tile peta. Google tanpa API key (sama seperti peta web), OSM sebagai
/// cadangan bila tile Google menolak permintaan dari aplikasi.
enum MapTileStyle {
  googleRoad('Peta'),
  googleSatellite('Satelit'),
  osm('OSM');

  const MapTileStyle(this.label);
  final String label;
}

final mapTileStyleProvider = StateProvider<MapTileStyle>((ref) => MapTileStyle.googleRoad);

/// Filter OLT pada peta (null = semua OLT dalam scope pengguna).
final mapOltFilterProvider = StateProvider<int?>((ref) => null);

/// Lapisan yang ditampilkan.
final mapShowOnusProvider = StateProvider<bool>((ref) => true);
final mapShowOdpsProvider = StateProvider<bool>((ref) => true);

/// Permintaan "buka peta di titik ini" dari layar lain (mis. tombol Lihat di
/// Peta pada detail ODP). Dipakai lewat state, bukan query URL, karena tab peta
/// hidup di IndexedStack — rutenya tidak dibangun ulang saat berpindah tab.
typedef MapFocus = ({double lat, double lng, int? odpId, int? pinId});

final mapFocusProvider = StateProvider<MapFocus?>((ref) => null);
