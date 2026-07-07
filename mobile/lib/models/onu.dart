import '../core/json.dart';

class Onu {
  const Onu({
    required this.oltId,
    required this.oltName,
    required this.slot,
    required this.port,
    required this.onuId,
    required this.ifIndex,
    required this.interface,
    required this.serialNumber,
    required this.mac,
    required this.typeName,
    required this.name,
    required this.description,
    required this.customerName,
    required this.adminState,
    required this.phaseState,
    required this.online,
    required this.lastDownCause,
    required this.rxPowerDbm,
    required this.rxPowerLabel,
    required this.portRoute,
  });

  final int oltId;
  final String? oltName;
  final int slot, port, onuId;
  final int? ifIndex;
  final String? interface, serialNumber, mac, typeName, name, description, customerName;
  final String adminState, phaseState;
  final bool online;
  final String? lastDownCause;
  final double? rxPowerDbm;
  final String? rxPowerLabel;
  final String? portRoute;

  /// Judul tampilan: nama pelanggan → SN → interface.
  String get title {
    final c = customerName;
    if (c != null && c.trim().isNotEmpty) return c;
    final s = serialNumber;
    if (s != null && s.trim().isNotEmpty) return s;
    return interface ?? 'ONU $onuId';
  }

  /// Klasifikasi RX untuk pewarnaan: null=unknown, marginal jika di luar -25..-10.
  bool get rxMarginal =>
      rxPowerDbm != null && (rxPowerDbm! <= -25 || rxPowerDbm! >= -10);

  factory Onu.fromJson(Map<String, dynamic> j) => Onu(
        oltId: J.asInt(j['olt_id']),
        oltName: J.asStrN(j['olt_name']),
        slot: J.asInt(j['slot']),
        port: J.asInt(j['port']),
        onuId: J.asInt(j['onu_id']),
        ifIndex: J.asIntN(j['if_index']),
        interface: J.asStrN(j['interface']),
        serialNumber: J.asStrN(j['serial_number']),
        mac: J.asStrN(j['mac']),
        typeName: J.asStrN(j['type_name']),
        name: J.asStrN(j['name']),
        description: J.asStrN(j['description']),
        customerName: J.asStrN(j['customer_name']),
        adminState: J.asStr(j['admin_state'], 'unknown'),
        phaseState: J.asStr(j['phase_state'], 'Unknown'),
        online: J.asBool(j['online']),
        lastDownCause: J.asStrN(j['last_down_cause']),
        rxPowerDbm: J.asDoubleN(j['rx_power_dbm']),
        rxPowerLabel: J.asStrN(j['rx_power_label']),
        portRoute: J.asStrN(j['port_route']),
      );
}
