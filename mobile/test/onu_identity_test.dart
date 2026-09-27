import 'package:flutter_test/flutter_test.dart';
import 'package:kusumavision_nms/models/onu.dart';

/// Pola yang umum di OLT — nama, deskripsi, dan pelanggan nyaris selalu berisi
/// hal yang sama, jadi layar detail menampilkan satu. Semua nama di sini fiktif.
Onu _onu({String? name, String? description, String? customer, String? sn}) => Onu.fromJson({
      'olt_id': 1, 'slot': 1, 'port': 2, 'onu_id': 7, 'online': true,
      'name': name, 'description': description, 'customer_name': customer, 'serial_number': sn,
    });

void main() {
  test('deskripsi label otomatis ONU-x:y tidak ditampilkan', () {
    final o = _onu(name: 'Gudang Contoh (ODP CONTOH 2)', description: 'ONU-3:92');
    expect(o.customerLabel, 'Gudang Contoh (ODP CONTOH 2)');
    expect(o.oltNote, isNull);
  });

  test('nama yang dibungkus 12\$\$…\$\$ dianggap sama', () {
    final o = _onu(name: 'PELANGGAN SATU', description: '62\$\$PELANGGAN SATU\$\$');
    expect(o.customerLabel, 'PELANGGAN SATU');
    expect(o.oltNote, isNull);
  });

  test('ID pelanggan di awal nama dipisah jadi lencana', () {
    final o = _onu(
      name: '#0800123456 Pelanggan Uji Dua',
      description: '72\$\$Pelanggan Uji Dua\$\$',
      customer: '#0800123456 Pelanggan Uji Dua',
    );
    expect(o.customerRef, '0800123456');
    expect(o.customerLabel, 'Pelanggan Uji Dua');
    expect(o.oltNote, isNull);
  });

  test('tag sistem zone_… dan \$\$\$\$ kosong disembunyikan', () {
    expect(_onu(name: 'PELANGGAN TIGA', description: '\$\$\$\$zone_Zone_1_authd_20241228').oltNote, isNull);
    expect(_onu(name: 'Pelanggan Empat', description: 'zone_Zone_1_authd_20241025').oltNote, isNull);
  });

  test('deskripsi yang memang berbeda tetap ditampilkan sebagai catatan', () {
    final o = _onu(name: '#0800654321 Bengkel Uji', description: '2\$\$tes123\$\$');
    expect(o.customerLabel, 'Bengkel Uji');
    expect(o.oltNote, 'tes123');
  });

  test('tanpa nama: jatuh ke deskripsi; nama = SN diabaikan', () {
    expect(_onu(description: 'Toko Contoh').customerLabel, 'Toko Contoh');
    expect(_onu(name: 'ZTEGC8A12B34', sn: 'ZTEGC8A12B34').customerLabel, isNull);
    expect(_onu(name: '-').customerLabel, isNull);
  });
}
