import '../core/json.dart';
import 'odp.dart';

/// Pin ONU pelanggan di peta (`GET /map` → `data.pins`).
class MapPin {
  const MapPin({
    required this.id,
    required this.oltId,
    required this.oltName,
    required this.slot,
    required this.port,
    required this.onuId,
    required this.interface,
    required this.serialNumber,
    required this.latitude,
    required this.longitude,
    required this.customerName,
    required this.address,
    required this.phone,
    required this.notes,
    required this.rxPowerDbm,
    required this.rxPowerLabel,
    required this.online,
    required this.hasLive,
  });

  final int id, oltId;
  final String? oltName;
  final int slot, port, onuId;
  final String? interface, serialNumber;
  final double latitude, longitude;
  final String? customerName, address, phone, notes;
  final double? rxPowerDbm;
  final String? rxPowerLabel;
  final bool online, hasLive;

  String get title {
    final c = customerName;
    if (c != null && c.trim().isNotEmpty) return c;
    final s = serialNumber;
    if (s != null && s.trim().isNotEmpty) return s;
    return interface ?? 'ONU $onuId';
  }

  factory MapPin.fromJson(Map<String, dynamic> j) => MapPin(
        id: J.asInt(j['id']),
        oltId: J.asInt(j['olt_id']),
        oltName: J.asStrN(j['olt_name']),
        slot: J.asInt(j['slot']),
        port: J.asInt(j['port']),
        onuId: J.asInt(j['onu_id']),
        interface: J.asStrN(j['interface']),
        serialNumber: J.asStrN(j['serial_number']),
        latitude: J.asDoubleN(j['latitude']) ?? 0,
        longitude: J.asDoubleN(j['longitude']) ?? 0,
        customerName: J.asStrN(j['customer_name']),
        address: J.asStrN(j['address']),
        phone: J.asStrN(j['phone']),
        notes: J.asStrN(j['notes']),
        rxPowerDbm: J.asDoubleN(j['rx_power_dbm']),
        rxPowerLabel: J.asStrN(j['rx_power_label']),
        online: J.asBool(j['online']),
        hasLive: J.asBool(j['has_live']),
      );
}

/// Pin ODP di peta beserta ONU yang tersambung (untuk garis ODP→ONU).
class MapOdp {
  const MapOdp({
    required this.id,
    required this.oltId,
    required this.oltName,
    required this.name,
    required this.slot,
    required this.port,
    required this.latitude,
    required this.longitude,
    required this.color,
    required this.photoUrl,
    required this.notes,
    required this.onus,
  });

  final int id, oltId;
  final String? oltName;
  final String name;
  final int? slot, port;
  final double latitude, longitude;

  /// Warna pin ODP ("#rrggbb"); null = bawaan (lihat core/odp_colors.dart).
  final String? color;

  /// URL foto dokumentasi ODP (rute ber-token). Null = belum ada foto.
  final String? photoUrl;
  final String? notes;
  final List<OdpOnu> onus;

  String? get portLabel => slot == null || port == null ? null : '$slot/$port';

  int get onlineCount => onus.where((o) => o.online).length;

  factory MapOdp.fromJson(Map<String, dynamic> j) => MapOdp(
        id: J.asInt(j['id']),
        oltId: J.asInt(j['snmp_olt_id']),
        oltName: J.asStrN(j['olt_name']),
        name: J.asStr(j['name']),
        slot: J.asIntN(j['slot']),
        port: J.asIntN(j['port']),
        latitude: J.asDoubleN(j['latitude']) ?? 0,
        longitude: J.asDoubleN(j['longitude']) ?? 0,
        color: J.asStrN(j['color']),
        photoUrl: J.asStrN(j['photo_url']),
        notes: J.asStrN(j['notes']),
        onus: ((j['onus'] ?? []) as List)
            .map((e) => OdpOnu.fromJson(e as Map<String, dynamic>))
            .toList(),
      );
}

/// Ringkasan OLT untuk filter peta.
class MapOlt {
  const MapOlt({required this.id, required this.name});
  final int id;
  final String name;

  factory MapOlt.fromJson(Map<String, dynamic> j) =>
      MapOlt(id: J.asInt(j['id']), name: J.asStr(j['name']));
}

/// Seluruh payload peta dalam satu request (`GET /map`).
class MapData {
  const MapData({
    required this.pins,
    required this.odps,
    required this.olts,
    required this.centerLat,
    required this.centerLng,
    required this.centerZoom,
  });

  final List<MapPin> pins;
  final List<MapOdp> odps;
  final List<MapOlt> olts;
  final double centerLat, centerLng;
  final double centerZoom;

  bool get isEmpty => pins.isEmpty && odps.isEmpty;

  factory MapData.fromJson(Map<String, dynamic> j) {
    final center = (j['default_center'] ?? {}) as Map<String, dynamic>;
    return MapData(
      pins: ((j['pins'] ?? []) as List)
          .map((e) => MapPin.fromJson(e as Map<String, dynamic>))
          .toList(),
      odps: ((j['odps'] ?? []) as List)
          .map((e) => MapOdp.fromJson(e as Map<String, dynamic>))
          .toList(),
      olts: ((j['olts'] ?? []) as List)
          .map((e) => MapOlt.fromJson(e as Map<String, dynamic>))
          .toList(),
      centerLat: J.asDoubleN(center['lat']) ?? -6.7559,
      centerLng: J.asDoubleN(center['lng']) ?? 111.0381,
      centerZoom: J.asDoubleN(center['zoom']) ?? 11,
    );
  }
}
