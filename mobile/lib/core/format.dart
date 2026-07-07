import 'package:intl/intl.dart';

/// Utilitas format tampilan.
class Fmt {
  static final _int = NumberFormat.decimalPattern('id');

  /// 2148 → "2.148".
  static String int(num? v) => v == null ? '-' : _int.format(v);

  /// "5 menit lalu" dari string ISO8601.
  static String relative(String? iso) {
    if (iso == null || iso.isEmpty) return '-';
    final dt = DateTime.tryParse(iso);
    if (dt == null) return '-';
    final d = DateTime.now().difference(dt.toLocal());
    if (d.inSeconds < 60) return 'baru saja';
    if (d.inMinutes < 60) return '${d.inMinutes} menit lalu';
    if (d.inHours < 24) return '${d.inHours} jam lalu';
    if (d.inDays < 30) return '${d.inDays} hari lalu';
    return DateFormat('d MMM yyyy', 'id').format(dt.toLocal());
  }

  /// RX power dBm → "-20.5 dBm".
  static String rx(num? dbm) => dbm == null ? '—' : '${dbm.toStringAsFixed(1)} dBm';
}
