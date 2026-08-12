import '../core/json.dart';

/// ODP (Optical Distribution Point) — splitter lapangan milik satu OLT & satu PON port.
/// Sumber: `GET /odps` (daftar) dan `GET /odps/{id}` (detail).
class Odp {
  const Odp({
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
    required this.onuCount,
  });

  final int id;
  final int oltId;
  final String? oltName;
  final String name;

  /// Slot/port PON tempat ODP dipasang. Null = ODP belum punya ONU (port terisi
  /// otomatis saat ONU pertama dikaitkan di web).
  final int? slot, port;
  final double? latitude, longitude;

  /// Warna pin ODP di peta ("#rrggbb"); null = warna bawaan (lihat core/odp_colors.dart).
  final String? color;

  /// URL foto dokumentasi (rute ber-token; butuh header Authorization). Null = belum ada.
  final String? photoUrl;
  final String? notes;
  final int onuCount;

  /// Label port siap tampil ("2/3"), null bila ODP belum ditautkan ke port.
  String? get portLabel => slot == null || port == null ? null : '$slot/$port';

  bool get hasCoordinates => latitude != null && longitude != null;

  factory Odp.fromJson(Map<String, dynamic> j) => Odp(
        id: J.asInt(j['id']),
        oltId: J.asInt(j['snmp_olt_id']),
        oltName: J.asStrN(j['olt_name']),
        name: J.asStr(j['name']),
        slot: J.asIntN(j['slot']),
        port: J.asIntN(j['port']),
        latitude: J.asDoubleN(j['latitude']),
        longitude: J.asDoubleN(j['longitude']),
        color: J.asStrN(j['color']),
        photoUrl: J.asStrN(j['photo_url']),
        notes: J.asStrN(j['notes']),
        onuCount: J.asInt(j['onu_count']),
      );
}

/// ONU yang terhubung ke sebuah ODP (`GET /odps/{id}/onus`).
///
/// Bentuknya lebih ramping dari [Onu] karena berasal dari tabel kaitan
/// `onu_odp_links` yang di-enrich status live seadanya di server.
class OdpOnu {
  const OdpOnu({
    required this.oltId,
    required this.slot,
    required this.port,
    required this.onuId,
    required this.serialNumber,
    required this.interface,
    required this.name,
    required this.online,
    required this.hasLive,
    required this.rxPowerDbm,
    required this.rxPowerLabel,
    required this.latitude,
    required this.longitude,
  });

  final int oltId, slot, port, onuId;
  final String? serialNumber, interface, name;
  final bool online;

  /// False = ONU tak ditemukan di snapshot OLT (mis. sudah dihapus dari OLT
  /// tapi kaitan ODP-nya belum dibersihkan).
  final bool hasLive;
  final double? rxPowerDbm;
  final String? rxPowerLabel;

  /// Koordinat pin ONU di peta (null bila ONU belum di-pin).
  final double? latitude, longitude;

  String get title {
    final n = name;
    if (n != null && n.trim().isNotEmpty) return n;
    final s = serialNumber;
    if (s != null && s.trim().isNotEmpty) return s;
    return interface ?? 'ONU $onuId';
  }

  factory OdpOnu.fromJson(Map<String, dynamic> j) => OdpOnu(
        oltId: J.asInt(j['snmp_olt_id']),
        slot: J.asInt(j['slot']),
        port: J.asInt(j['port']),
        onuId: J.asInt(j['onu_id']),
        serialNumber: J.asStrN(j['serial_number']),
        interface: J.asStrN(j['interface']),
        name: J.asStrN(j['name']),
        online: J.asBool(j['online']),
        hasLive: J.asBool(j['has_live']),
        rxPowerDbm: J.asDoubleN(j['rx_power_dbm']),
        rxPowerLabel: J.asStrN(j['rx_power_label']),
        latitude: J.asDoubleN(j['latitude']),
        longitude: J.asDoubleN(j['longitude']),
      );
}
