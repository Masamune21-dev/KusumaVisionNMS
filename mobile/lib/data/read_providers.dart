import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../core/providers.dart';
import '../models/alarm.dart';
import '../models/olt.dart';
import '../models/onu.dart';
import '../models/search_result.dart';
import '../models/summary.dart';

/// Ringkasan dashboard.
final summaryProvider = FutureProvider.autoDispose<DashboardSummary>(
  (ref) => ref.watch(nmsApiProvider).summary(),
);

/// Inventaris OLT.
final oltsProvider = FutureProvider.autoDispose<List<OltSummary>>(
  (ref) => ref.watch(nmsApiProvider).olts(),
);

/// Detail satu OLT.
final oltDetailProvider = FutureProvider.autoDispose.family<OltDetail, int>(
  (ref, id) => ref.watch(nmsApiProvider).olt(id),
);

/// Argumen ONU per port.
typedef PortArg = ({int oltId, int slot, int port});

final portOnusProvider =
    FutureProvider.autoDispose.family<({List<Onu> onus, String? refreshedAt}), PortArg>(
  (ref, a) => ref.watch(nmsApiProvider).portOnus(a.oltId, a.slot, a.port),
);

/// Detail satu ONU.
typedef OnuArg = ({int oltId, int slot, int port, int onuId});

final onuDetailProvider = FutureProvider.autoDispose.family<Onu, OnuArg>(
  (ref, a) => ref.watch(nmsApiProvider).onu(a.oltId, a.slot, a.port, a.onuId),
);

/// ONU unconfigured per OLT.
final unconfiguredProvider = FutureProvider.autoDispose
    .family<({List<Map<String, dynamic>> onus, bool ok, String? refreshedAt}), int>(
  (ref, oltId) => ref.watch(nmsApiProvider).unconfigured(oltId),
);

/// Alarm — filter severity (null = semua).
final alarmSeverityProvider = StateProvider.autoDispose<String?>((ref) => null);

final alarmsProvider = FutureProvider.autoDispose<({List<Alarm> alarms, int total})>(
  (ref) => ref.watch(nmsApiProvider).alarms(severity: ref.watch(alarmSeverityProvider)),
);

/// Opsi form registrasi (profil + default + kapabilitas) untuk sebuah OLT/port.
typedef RegisterArg = ({int oltId, int? slot, int? port, String? sn});

final registerOptionsProvider =
    FutureProvider.autoDispose.family<Map<String, dynamic>, RegisterArg>(
  (ref, a) => ref.watch(nmsApiProvider).registerOptions(a.oltId, slot: a.slot, port: a.port, sn: a.sn),
);

/// Pencarian global — query di-debounce di layar.
final searchQueryProvider = StateProvider.autoDispose<String>((ref) => '');

final searchProvider = FutureProvider.autoDispose<List<SearchResult>>((ref) async {
  final q = ref.watch(searchQueryProvider).trim();
  if (q.length < 2) return <SearchResult>[];
  return ref.watch(nmsApiProvider).search(q);
});
