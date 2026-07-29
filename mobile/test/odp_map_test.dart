import 'package:flutter_test/flutter_test.dart';
import 'package:kusumavision_nms/models/map_data.dart';
import 'package:kusumavision_nms/models/odp.dart';
import 'package:kusumavision_nms/models/onu.dart';

void main() {
  test('Odp.portLabel kosong saat ODP belum ditautkan ke port', () {
    final linked = Odp.fromJson({
      'id': 1, 'snmp_olt_id': 2, 'olt_name': 'OLT-A', 'name': 'ODP-01',
      'slot': 2, 'port': 3, 'latitude': -6.6, 'longitude': 111.0, 'onu_count': 5,
    });
    expect(linked.portLabel, '2/3');
    expect(linked.hasCoordinates, true);

    final floating = Odp.fromJson({'id': 2, 'snmp_olt_id': 2, 'name': 'ODP-BARU'});
    expect(floating.portLabel, isNull);
    expect(floating.hasCoordinates, false);
    expect(floating.onuCount, 0);
  });

  test('OdpOnu.title jatuh ke SN lalu interface', () {
    OdpOnu onu(Map<String, dynamic> extra) => OdpOnu.fromJson({
          'snmp_olt_id': 1, 'slot': 1, 'port': 1, 'onu_id': 7, ...extra,
        });

    expect(onu({'name': 'Pak Budi', 'serial_number': 'ZTEG1'}).title, 'Pak Budi');
    expect(onu({'serial_number': 'ZTEG1'}).title, 'ZTEG1');
    expect(onu({'interface': 'gpon-onu_1/1/1:7'}).title, 'gpon-onu_1/1/1:7');
  });

  test('MapData mem-parse pin, ODP + garis ODP→ONU, dan titik tengah', () {
    final map = MapData.fromJson({
      'pins': [
        {
          'id': 9, 'olt_id': 1, 'olt_name': 'OLT-A', 'slot': 1, 'port': 1, 'onu_id': 5,
          'latitude': -6.7, 'longitude': 111.0, 'customer_name': 'Bu Sri', 'online': true,
        },
      ],
      'odps': [
        {
          'id': 3, 'snmp_olt_id': 1, 'name': 'ODP-01', 'slot': 1, 'port': 1,
          'latitude': -6.75, 'longitude': 111.03,
          'onus': [
            {'snmp_olt_id': 1, 'slot': 1, 'port': 1, 'onu_id': 5, 'online': true,
             'latitude': -6.7, 'longitude': 111.0},
            {'snmp_olt_id': 1, 'slot': 1, 'port': 1, 'onu_id': 6, 'online': false},
          ],
        },
      ],
      'olts': [
        {'id': 1, 'name': 'OLT-A'},
      ],
      'default_center': {'lat': -6.72, 'lng': 111.01, 'zoom': 12},
    });

    expect(map.isEmpty, false);
    expect(map.pins.single.title, 'Bu Sri');
    expect(map.odps.single.onus.length, 2);
    expect(map.odps.single.onlineCount, 1);
    // ONU tanpa pin tak punya koordinat → tak digambar garis kabelnya.
    expect(map.odps.single.onus.last.latitude, isNull);
    expect(map.centerZoom, 12);
    expect(map.olts.single.name, 'OLT-A');
  });

  test('Onu membawa kaitan ODP dari API', () {
    final onu = Onu.fromJson({
      'olt_id': 1, 'slot': 1, 'port': 1, 'onu_id': 5, 'online': true,
      'odp_id': 3, 'odp_name': 'ODP-01',
    });
    expect(onu.odpId, 3);
    expect(onu.odpName, 'ODP-01');

    final tanpaOdp = Onu.fromJson({'olt_id': 1, 'slot': 1, 'port': 1, 'onu_id': 6});
    expect(tanpaOdp.odpId, isNull);
    expect(tanpaOdp.odpName, isNull);
  });
}
