import '../core/json.dart';

class Alarm {
  const Alarm({
    required this.id,
    required this.oltId,
    required this.oltName,
    required this.type,
    required this.typeLabel,
    required this.severity,
    required this.status,
    required this.scope,
    required this.slot,
    required this.port,
    required this.onuId,
    required this.serialNumber,
    required this.customerName,
    required this.message,
    required this.firstSeenAt,
    required this.lastSeenAt,
    required this.clearedAt,
  });

  final int id;
  final int? oltId;
  final String? oltName;
  final String type, typeLabel, severity, status;
  final String? scope;
  final int? slot, port, onuId;
  final String? serialNumber, customerName, message, firstSeenAt, lastSeenAt, clearedAt;

  bool get active => status == 'active';

  factory Alarm.fromJson(Map<String, dynamic> j) => Alarm(
        id: J.asInt(j['id']),
        oltId: J.asIntN(j['olt_id']),
        oltName: J.asStrN(j['olt_name']),
        type: J.asStr(j['type']),
        typeLabel: J.asStr(j['type_label'], j['type']?.toString() ?? ''),
        severity: J.asStr(j['severity'], 'warning'),
        status: J.asStr(j['status'], 'active'),
        scope: J.asStrN(j['scope']),
        slot: J.asIntN(j['slot']),
        port: J.asIntN(j['port']),
        onuId: J.asIntN(j['onu_id']),
        serialNumber: J.asStrN(j['serial_number']),
        customerName: J.asStrN(j['customer_name']),
        message: J.asStrN(j['message']),
        firstSeenAt: J.asStrN(j['first_seen_at']),
        lastSeenAt: J.asStrN(j['last_seen_at']),
        clearedAt: J.asStrN(j['cleared_at']),
      );
}
