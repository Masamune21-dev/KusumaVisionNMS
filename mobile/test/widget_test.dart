import 'package:flutter_test/flutter_test.dart';
import 'package:kusumavision_nms/models/onu.dart';

void main() {
  test('Onu.title memilih nama pelanggan → SN → interface', () {
    final withCustomer = Onu.fromJson({
      'olt_id': 1, 'slot': 1, 'port': 1, 'onu_id': 3,
      'serial_number': 'ZTEGC0001', 'customer_name': 'Budi', 'online': true,
    });
    expect(withCustomer.title, 'Budi');

    final snOnly = Onu.fromJson({
      'olt_id': 1, 'slot': 1, 'port': 1, 'onu_id': 4,
      'serial_number': 'ZTEGC0002', 'online': false,
    });
    expect(snOnly.title, 'ZTEGC0002');
  });

  test('rxMarginal menandai RX di luar zona aman', () {
    Onu onu(double rx) => Onu.fromJson({
          'olt_id': 1, 'slot': 1, 'port': 1, 'onu_id': 1,
          'online': true, 'rx_power_dbm': rx,
        });
    expect(onu(-20).rxMarginal, false);
    expect(onu(-9).rxMarginal, true);
    expect(onu(-26).rxMarginal, true);
  });
}
