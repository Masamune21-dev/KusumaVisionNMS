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
    this.odpId,
    this.odpName,
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

  /// ODP tempat ONU ini tersambung (null = belum dikaitkan ke ODP mana pun).
  final int? odpId;
  final String? odpName;

  /// Judul tampilan: nama pelanggan → SN → interface.
  String get title {
    final c = customerName;
    if (c != null && c.trim().isNotEmpty) return c;
    final s = serialNumber;
    if (s != null && s.trim().isNotEmpty) return s;
    return interface ?? 'ONU $onuId';
  }
  // ---- Identitas pelanggan (nama/deskripsi/pelanggan digabung jadi satu) ----
  //
  // Di OLT ketiga field itu hampir selalu berisi hal yang sama: deskripsi berupa
  // label otomatis `ONU-7:44`, nama yang sama dibungkus `12$$…$$`, atau sama persis.
  // Jadi layar detail menampilkan SATU nama; deskripsi hanya muncul bila benar-benar lain.

  static final _wrapped = RegExp(r'\$\$(.*?)\$\$');
  static final _refPrefix = RegExp(r'^#(\d{6,})\s*[-–·:]?\s*');
  static final _autoLabel = RegExp(r'^onu-\d+:\d+$', caseSensitive: false);
  static const _blank = {'-', 'n/a', 'na', 'null', 'none'};

  /// Isi field OLT yang sudah dibersihkan: `12$$Nama$$` → `Nama`; kosong,
  /// `-`/`n/a`, atau sama dengan SN → null.
  String? _clean(String? raw) {
    var v = (raw ?? '').trim();
    final m = _wrapped.firstMatch(v);
    if (m != null) v = m.group(1)!.trim();
    if (v.isEmpty || _blank.contains(v.toLowerCase())) return null;
    final sn = serialNumber;
    if (sn != null && v.toLowerCase() == sn.trim().toLowerCase()) return null;
    return v;
  }

  /// Nama mentah terbaik sebelum ID pelanggan dipisah.
  String? get _identity => _clean(customerName) ?? _clean(name) ?? _clean(description);

  /// Nama pelanggan tunggal untuk tampilan — tanpa awalan ID `#2403133251`.
  String? get customerLabel {
    final id = _identity;
    if (id == null) return null;
    final rest = id.replaceFirst(_refPrefix, '').trim();
    return rest.isEmpty ? id : rest;
  }

  /// ID pelanggan yang ditulis teknisi di awal nama ONU (`#2403133251 Budi …`).
  String? get customerRef {
    final id = _identity;
    return id == null ? null : _refPrefix.firstMatch(id)?.group(1);
  }

  /// Deskripsi OLT — hanya bila membawa informasi yang TIDAK ada di nama
  /// (bukan label otomatis `ONU-x:y`, tag sistem `zone_…`, atau nama yang sama).
  String? get oltNote {
    final d = _clean(description);
    if (d == null || _autoLabel.hasMatch(d) || d.toLowerCase().startsWith('zone_')) return null;
    final id = (_identity ?? '').toLowerCase();
    return id.contains(d.toLowerCase()) ? null : d;
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
        odpId: J.asIntN(j['odp_id']),
        odpName: J.asStrN(j['odp_name']),
      );
}
