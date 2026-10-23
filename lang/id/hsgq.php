<?php

// Pesan backend OLT HSGQ asli (HS Fiber 50224 — SNMP + API web JSON). EN: lang/en/hsgq.php.
return [
    'snmp_walk_failed' => 'SNMP walk gagal untuk :oid',
    'snmp_v3_unsupported' => 'HSGQ hanya mendukung SNMP v1/v2c.',
    'snmp_no_answer' => 'OLT tidak menjawab SNMP (sysObjectID & sysDescr kosong).',
    'scan_retry_on_open' => ') — akan dicoba lagi saat halaman dibuka.',
    'action_unsupported' => 'Aksi ini tidak didukung untuk OLT HSGQ ini.',
    'olt_updated' => 'OLT HSGQ berhasil diperbarui.',
    'olt_deleted' => 'OLT HSGQ dihapus.',
    'test_ok' => 'SNMP OK. Family: :family. Latency: :latency ms. ONU terbaca: :onus. Laporan diagnostik ada di halaman ini.',
    'web_credentials_missing' => 'Username/sandi web OLT belum diisi (form OLT → Akses web & CLI).',
    'web_target_blocked' => 'Alamat OLT ini tidak boleh dihubungi dari server (loopback/link-local/multicast/cadangan).',
    'demo_device' => 'OLT demo tidak terhubung ke perangkat.',
    'web_port_missing' => 'Port web OLT belum diisi — aksi ONU HSGQ lewat web butuh port HTTP (biasanya 80).',
    'web_captcha_required' => 'Web OLT meminta captcha, jadi NMS tidak bisa login otomatis. Matikan captcha login di web OLT.',
    'web_login_failed' => 'Login web OLT gagal: :message',
    'web_session_expired' => 'Sesi web OLT berulang kali kedaluwarsa (Token Check Failed).',
    'web_unreachable' => 'Web OLT tidak bisa dihubungi: :message',
    'web_bad_response' => 'Respons web OLT tidak dikenali (:detail).',
    'web_rejected' => 'OLT menolak perintah tanpa pesan.',
    'onu_name_required' => 'Nama ONU tidak boleh kosong (huruf, angka, spasi, _ - . @ # ( ) /).',
    'onu_not_in_cache' => 'ONU tidak ada di cache NMS — refresh port dulu.',
    'onu_not_on_web' => 'ONU :identity tidak ditemukan di tabel ONU web OLT.',
    'onu_identifier_missing' => 'Tabel ONU web OLT tidak memuat identifier ONU — aksi dibatalkan.',
    'onu_renamed_save_hint' => 'Nama ONU diubah. Tekan Simpan Config agar tetap ada setelah OLT restart.',
    'config_saved' => 'Konfigurasi OLT :name disimpan.',
];
