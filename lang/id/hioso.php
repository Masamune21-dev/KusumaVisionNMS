<?php

// Pesan backend OLT HiOSO (CLI telnet/SNMP). EN: lang/en/hioso.php.
return [
    'onu_name_required' => 'Nama ONU HiOSO tidak boleh kosong (hanya huruf/angka/_-.).',
    'cli_telnet_only' => 'CLI HiOSO hanya mendukung Telnet. Set CLI transport OLT ke telnet.',
    'telnet_connect_failed' => 'Koneksi telnet gagal: :message (:code)',
    'snmp_set_name_rejected' => 'OLT menolak SNMP SET nama ONU (periksa write community / OID).',
    'snmp_write_community_missing' => 'SNMP write community OLT belum diisi — tak bisa menulis via SNMP.',
    'snmp_walk_failed' => 'SNMP walk gagal untuk :oid',
    'snmp_v3_unsupported' => 'HiOSO hanya mendukung SNMP v1/v2c.',
    'scan_retry_on_open' => ') — akan dicoba lagi saat halaman dibuka.',
    'action_unsupported' => 'Aksi ini tidak didukung untuk OLT ini.',
];
