import '../core/json.dart';

class DashboardSummary {
  const DashboardSummary({
    required this.oltTotal,
    required this.oltOnline,
    required this.oltOffline,
    required this.onuTotal,
    required this.onuOnline,
    required this.onuOffline,
    required this.onuWarning,
    required this.onlineShare,
    required this.alarmTotal,
    required this.alarmCritical,
    required this.alarmMajor,
    required this.alarmMinor,
    required this.alarmWarning,
  });

  final int oltTotal, oltOnline, oltOffline;
  final int onuTotal, onuOnline, onuOffline, onuWarning;
  final num onlineShare;
  final int alarmTotal, alarmCritical, alarmMajor, alarmMinor, alarmWarning;

  factory DashboardSummary.fromJson(Map<String, dynamic> j) {
    final olt = (j['olt'] ?? {}) as Map<String, dynamic>;
    final onu = (j['onu'] ?? {}) as Map<String, dynamic>;
    final al = (j['alarms'] ?? {}) as Map<String, dynamic>;
    return DashboardSummary(
      oltTotal: J.asInt(olt['total']),
      oltOnline: J.asInt(olt['online']),
      oltOffline: J.asInt(olt['offline']),
      onuTotal: J.asInt(onu['total']),
      onuOnline: J.asInt(onu['online']),
      onuOffline: J.asInt(onu['offline']),
      onuWarning: J.asInt(onu['warning']),
      onlineShare: J.asDoubleN(j['online_share']) ?? 0,
      alarmTotal: J.asInt(al['total']),
      alarmCritical: J.asInt(al['critical']),
      alarmMajor: J.asInt(al['major']),
      alarmMinor: J.asInt(al['minor']),
      alarmWarning: J.asInt(al['warning']),
    );
  }
}
