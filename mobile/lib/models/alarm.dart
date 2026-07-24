import '../core/json.dart';

/// Ubicación RESUELTA por el servidor para navegar (no la histórica del evento).
///
/// El servidor sigue el `serial_number` en el snapshot actual del OLT: si la ONU se
/// movió de puerto devuelve su posición de AHORA, y si esa posición la ocupa otra ONU
/// pone [openable] en false. Nunca navegar con `alarm.slot/port/onuId` directamente:
/// son los valores del momento en que se registró la alarma y pueden apuntar a la ONU
/// de otro cliente.
class AlarmTarget {
  const AlarmTarget({
    required this.resourceType,
    required this.oltId,
    required this.slot,
    required this.port,
    required this.onuId,
    required this.openable,
    required this.reason,
  });

  final String? resourceType; // onu | port | olt
  final int? oltId, slot, port, onuId;
  final bool openable;
  final String? reason;

  bool get isOnu => resourceType == 'onu';
  bool get isPort => resourceType == 'port';

  factory AlarmTarget.fromJson(Map<String, dynamic> j) => AlarmTarget(
        resourceType: J.asStrN(j['resource_type']),
        oltId: J.asIntN(j['olt_id']),
        slot: J.asIntN(j['slot']),
        port: J.asIntN(j['port']),
        onuId: J.asIntN(j['onu_id']),
        openable: J.asBool(j['openable']),
        reason: J.asStrN(j['reason']),
      );
}

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
    required this.target,
  });

  final int id;
  final int? oltId;
  final String? oltName;
  final String type, typeLabel, severity, status;
  final String? scope;
  final int? slot, port, onuId;
  final String? serialNumber, customerName, message, firstSeenAt, lastSeenAt, clearedAt;

  /// null si el servidor es anterior a este campo (entonces no se hace deep-link a la ONU).
  final AlarmTarget? target;

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
        target: j['target'] is Map<String, dynamic>
            ? AlarmTarget.fromJson(j['target'] as Map<String, dynamic>)
            : null,
      );
}
