import 'package:flutter_test/flutter_test.dart';
import 'package:kusumavision_nms/core/onu_status.dart';
import 'package:kusumavision_nms/models/onu.dart';
import 'package:kusumavision_nms/theme/app_theme.dart';

/// Taksonomi status ONU — ejaan kodenya beda per family OLT, jadi yang diuji di
/// sini adalah nilai mentah persis seperti yang dikirim tiap OLT di lapangan.
void main() {
  Onu onu(Map<String, dynamic> extra) => Onu.fromJson({
        'olt_id': 1, 'slot': 1, 'port': 1, 'onu_id': 9, ...extra,
      });

  test('ZTE C300/C320: phase_state jadi sebab yang tampil', () {
    final gasp = OnuStatus.of(onu({'online': false, 'phase_state': 'DyingGasp'}));
    expect(gasp.label, 'Dying Gasp');
    expect(gasp.color, AppColors.warning);
    expect(gasp.online, false);

    final los = OnuStatus.of(onu({'online': false, 'phase_state': 'LOS'}));
    expect(los.label, 'LOS');
    expect(los.color, AppColors.danger);
  });

  test('C600 mengeja OffLine; sebabnya diambil dari last_down_cause', () {
    // Phase OffLine tak menjelaskan apa-apa, jadi jatuh ke penyebab terakhir turun.
    expect(
      OnuStatus.of(onu({'online': false, 'phase_state': 'OffLine', 'last_down_cause': 'LOSi'})).label,
      'LOS',
    );
    // Tanpa penyebab sama sekali → tetap "Offline" seperti dulu.
    expect(OnuStatus.of(onu({'online': false, 'phase_state': 'OffLine'})).label, 'Offline');
  });

  test('C-Data GPON menulis dying-gasp bertanda hubung', () {
    final s = OnuStatus.of(onu({
      'online': false,
      'phase_state': 'Offline',
      'last_down_cause': 'dying-gasp',
    }));
    expect(s.label, 'Dying Gasp');
  });

  test('EPON tanpa keterangan apa pun tetap Offline', () {
    final s = OnuStatus.of(onu({'online': false, 'phase_state': 'Offline'}));
    expect(s.label, 'Offline');
    expect(s.color, AppColors.danger);
  });

  test('ONU online tak terbaca dari penyebab turun yang lama', () {
    // last_down_cause bersifat historis — di C-Data hampir semua ONU online
    // menyimpan 'dying-gasp' dari padam terakhir. Jangan sampai terbaca gangguan.
    final s = OnuStatus.of(onu({
      'online': true,
      'phase_state': 'Working',
      'last_down_cause': 'DyingGasp',
    }));
    expect(s.label, 'Online');
    expect(s.online, true);
    expect(s.isProblem, false);
  });

  test('ONU yang sengaja dinonaktifkan bukan gangguan', () {
    for (final admin in ['disabled', 'disable']) {
      final s = OnuStatus.of(onu({
        'online': false,
        'admin_state': admin,
        'phase_state': 'LOS',
      }));
      expect(s.label, 'Nonaktif', reason: 'admin_state $admin');
      expect(s.color, AppColors.muted);
      expect(s.isProblem, false, reason: 'admin_state $admin');
    }
  });

  test('fase transisi tetap ditampilkan walau OLT menghitungnya online', () {
    final s = OnuStatus.of(onu({'online': true, 'phase_state': 'Sync MIB'}));
    expect(s.label, 'Sync MIB');
    expect(s.color, AppColors.info);
  });

  test('gangguan non-LOS punya labelnya sendiri', () {
    expect(OnuStatus.resolve(online: false, lastDownCause: 'LOFi').label, 'LOF');
    expect(OnuStatus.resolve(online: false, lastDownCause: 'SFi').label, 'Signal Fail');
    expect(OnuStatus.resolve(online: false, lastDownCause: 'LOAi').label, 'LOA');
    expect(OnuStatus.resolve(online: false, lastDownCause: 'LOAMi').label, 'LOAM');
    expect(OnuStatus.resolve(online: false, phaseState: 'Auth Failed').label, 'Auth gagal');
    // 'Normal'/'Unknown' tak menjelaskan apa-apa → jangan dipakai jadi label.
    expect(OnuStatus.resolve(online: false, lastDownCause: 'Normal').label, 'Offline');
    expect(OnuStatus.resolve(online: false, lastDownCause: 'Unknown').label, 'Offline');
  });
}
