<?php

// Pesan backend OLT C-Data (CLI/SNMP/VLAN/detail port). EN: lang/en/cdata.php.
return [
    'cli_telnet_only' => 'CLI C-Data baru mendukung Telnet. Set CLI transport OLT ke telnet.',
    'telnet_connect_failed' => 'Koneksi telnet gagal: :message (:code)',
    'cli_rejected' => 'CLI menolak \':command\': :error',
    'unknown_interface' => 'Interface C-Data tidak dikenal: :interface',
    'remote_access_gpon_only' => 'Remote ONT security-mgmt hanya tersedia di C-Data GPON.',
    'snmp_oid_unavailable' => 'SNMP walk: OID :oid tak tersedia di perangkat',
    'snmp_walk_failed' => 'SNMP walk gagal untuk :oid',
    'snmp_v3_unsupported' => 'C-Data hanya mendukung SNMP v1/v2c.',
    'vlan_exists' => 'VLAN :vlan sudah ada di OLT.',
    'vlan_not_created' => 'VLAN :vlan tidak muncul di \'show vlan\' setelah dibuat.',
    'tag_kind_unsupported' => 'Tag VLAN dari NMS hanya untuk port GE/XGE/EPON — port GPON otomatis ikut VLAN baru.',
    'vlan_missing' => 'VLAN :vlan belum ada di OLT. Buat dulu di halaman VLAN.',
    'port_vlan_unreadable' => 'Tidak bisa membaca VLAN port :port.',
    'port_mode_untaggable' => 'Port :port bermode :mode. Tag VLAN dari NMS hanya untuk port Trunk atau Hybrid — ubah mode lewat CLI dulu.',
    'vlan_already_untagged' => 'VLAN :vlan sudah untagged di :port; tidak diubah jadi tagged dari NMS.',
    'vlan_list_too_long' => 'Daftar VLAN :port terlalu panjang untuk dikirim aman dari NMS. Tag lewat CLI.',
    'vlan_not_on_port' => 'VLAN :vlan belum terlihat di port :port setelah perintah dikirim.',
    'vlans_lost' => 'Verifikasi: VLAN :vlans hilang dari :port. Periksa port lewat CLI segera.',
    'enter_interface_failed' => 'Gagal masuk \'interface :kind 0/:slot\'',
    'unknown_port_kind' => 'Jenis port C-Data tidak dikenal: :kind',
    'vlan_id_range' => 'VLAN ID harus 1-4094.',
    'scan_retry_on_open' => ') — akan dicoba lagi saat halaman dibuka.',
    'action_unsupported' => 'Aksi ini tidak didukung untuk OLT ini.',
    'vlan_write_forbidden' => 'Mengubah VLAN OLT ini hanya untuk staf Pusat atau pemilik OLT.',
];
