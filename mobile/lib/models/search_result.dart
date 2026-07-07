import '../core/json.dart';

class SearchResult {
  const SearchResult({
    required this.type,
    required this.label,
    required this.sublabel,
    required this.oltId,
    required this.oltName,
    required this.slot,
    required this.port,
    required this.onuId,
    required this.serialNumber,
  });

  final String type; // 'olt' | 'onu'
  final String label;
  final String? sublabel;
  final int oltId;
  final String? oltName;
  final int? slot, port, onuId;
  final String? serialNumber;

  bool get isOnu => type == 'onu';
  bool get hasPort => slot != null && port != null;

  factory SearchResult.fromJson(Map<String, dynamic> j) => SearchResult(
        type: J.asStr(j['type']),
        label: J.asStr(j['label']),
        sublabel: J.asStrN(j['sublabel']),
        oltId: J.asInt(j['olt_id']),
        oltName: J.asStrN(j['olt_name']),
        slot: J.asIntN(j['slot']),
        port: J.asIntN(j['port']),
        onuId: J.asIntN(j['onu_id']),
        serialNumber: J.asStrN(j['serial_number']),
      );
}
