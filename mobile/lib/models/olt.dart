import '../core/json.dart';

/// Ringkasan OLT (dari GET /olts dan bagian atas GET /olts/{id}).
class OltSummary {
  const OltSummary({
    required this.id,
    required this.name,
    required this.ip,
    required this.vendor,
    required this.driver,
    required this.isCdata,
    required this.reachable,
    required this.pollingEnabled,
    required this.portsTotal,
    required this.portsUp,
    required this.portsDown,
    required this.onuTotal,
    required this.onuOnline,
    required this.onuOffline,
    required this.lastPolledAt,
  });

  final int id;
  final String name;
  final String ip;
  final String? vendor;
  final String driver;
  final bool isCdata;
  final bool reachable;
  final bool pollingEnabled;
  final int portsTotal, portsUp, portsDown;
  final int onuTotal, onuOnline, onuOffline;
  final String? lastPolledAt;

  factory OltSummary.fromJson(Map<String, dynamic> j) => OltSummary(
        id: J.asInt(j['id']),
        name: J.asStr(j['name']),
        ip: J.asStr(j['ip']),
        vendor: J.asStrN(j['vendor']),
        driver: J.asStr(j['driver'], 'unknown'),
        isCdata: J.asBool(j['is_cdata']),
        reachable: J.asBool(j['reachable']),
        pollingEnabled: J.asBool(j['polling_enabled']),
        portsTotal: J.asInt(j['ports_total']),
        portsUp: J.asInt(j['ports_up']),
        portsDown: J.asInt(j['ports_down']),
        onuTotal: J.asInt(j['onu_total']),
        onuOnline: J.asInt(j['onu_online']),
        onuOffline: J.asInt(j['onu_offline']),
        lastPolledAt: J.asStrN(j['last_polled_at']),
      );

  /// Label family untuk badge.
  String get familyLabel => switch (driver) {
        'zte' => 'ZTE GPON',
        'cdata-epon-17409' => 'C-Data EPON',
        'cdata-gpon-34592' => 'C-Data GPON',
        'hioso-epon-25355' => 'HiOSO EPON',
        _ => vendor ?? 'Unknown',
      };
}

class OltPort {
  const OltPort({
    required this.slot,
    required this.port,
    required this.name,
    required this.operStatus,
    required this.onuTotal,
    required this.onuOnline,
  });

  final int slot, port;
  final String? name;
  final String? operStatus;
  final int onuTotal, onuOnline;

  bool get isUp => operStatus == 'up';

  factory OltPort.fromJson(Map<String, dynamic> j) => OltPort(
        slot: J.asInt(j['slot']),
        port: J.asInt(j['port']),
        name: J.asStrN(j['name']),
        operStatus: J.asStrN(j['oper_status']),
        onuTotal: J.asInt(j['onu_total']),
        onuOnline: J.asInt(j['onu_online']),
      );
}

/// Detail OLT (GET /olts/{id}) — summary + ports + capabilities.
class OltDetail {
  const OltDetail({
    required this.summary,
    required this.sysName,
    required this.sysDescr,
    required this.sysUptime,
    required this.ports,
    required this.capabilities,
  });

  final OltSummary summary;
  final String? sysName, sysDescr, sysUptime;
  final List<OltPort> ports;
  final Map<String, dynamic> capabilities;

  bool cap(String key) => J.asBool(capabilities[key]);

  factory OltDetail.fromJson(Map<String, dynamic> j) {
    final sys = (j['system'] ?? {}) as Map<String, dynamic>;
    return OltDetail(
      summary: OltSummary.fromJson(j),
      sysName: J.asStrN(sys['sys_name']),
      sysDescr: J.asStrN(sys['sys_descr']),
      sysUptime: J.asStrN(sys['sys_uptime']),
      ports: ((j['ports'] ?? []) as List)
          .map((e) => OltPort.fromJson(e as Map<String, dynamic>))
          .toList(),
      capabilities: (j['capabilities'] ?? {}) as Map<String, dynamic>,
    );
  }
}
