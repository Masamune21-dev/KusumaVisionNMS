<?php

// Teks alarm & notifikasi (Telegram, push FCM). Dibuat sistem dalam bahasa instalasi
// (APP_LOCALE) lewat App\Support\SystemText. EN: lang/en/alarm.php.
return [
    'unknown_error' => 'unknown error',

    'msg' => [
        'olt_unreachable' => 'OLT tidak dapat dihubungi: :error',
        'olt_recovered' => 'OLT kembali terhubung.',
        'port_down' => ':pon port :port oper status down.',
        'port_down_affected' => ':pon port :port oper status down (:count ONU terdampak).',
        'port_up' => ':pon port :port kembali up.',
        'port_disabled_closed' => ':pon port :port dimatikan admin — alarm ditutup.',
        'port_enabled_outside' => ':pon port :port terbaca menyala lagi (dinyalakan di luar NMS).',
        'port_shutdown' => ':pon port :port dimatikan (shutdown) dari NMS:by:affected.',
        'port_shutdown_affected' => ' — :count ONU terputus sampai port dinyalakan lagi',
        'port_enabled_nms' => ':pon port :port dinyalakan lagi dari NMS:by.',
        'by_actor' => ' oleh :actor',
        'odp_down' => 'ODP :odp down — semua :total ONU offline.',
        'odp_down_port' => 'ODP :odp down — semua :total ONU offline (port :pon :port).',
        'odp_recovered' => 'ODP :odp kembali normal — ada ONU yang online lagi.',
        'onu_dying_gasp' => 'ONU :onu power off (dying gasp).',
        'onu_los' => 'ONU :onu loss of signal (LOS).',
        'onu_offline' => 'ONU :onu offline (phase: :phase).',
        'onu_online' => 'ONU :onu kembali online.',
        'onu_online_rx' => 'ONU :onu kembali online, RX :rx dBm.',
        'high_rx' => 'ONU :onu RX :rx dBm di luar rentang sehat.',
        'rx_normal' => 'ONU :onu RX kembali normal.',
        'rx_normal_value' => 'ONU :onu RX :rx dBm kembali normal.',
    ],

    'type' => [
        'olt_unreachable' => 'OLT tidak terhubung',
        'port_down' => 'Port :pon down',
        'port_disabled' => 'Port :pon dimatikan admin',
        'odp_down' => 'ODP down (semua ONU offline)',
        'los' => 'Loss of Signal (LOS)',
        'dying_gasp' => 'Power Off',
        'onu_offline' => 'ONU offline',
        'high_rx_attenuation' => 'Redaman RX tinggi / di luar rentang',
    ],

    'telegram' => [
        'test_ok' => 'Tes notifikasi Telegram berhasil. Bot terhubung dengan benar.',
        'not_configured' => 'Bot token dan chat ID harus diisi terlebih dahulu.',
        'token_missing' => 'Bot token belum dikonfigurasi.',
        'http_error' => 'HTTP :status dari Telegram.',
        'odp_recovered' => 'ODP PULIH',
        'onus_back_online' => ':count ONU kembali online',
        'odp_all_down_tag' => 'Semua ONU',
        'odp_all_down' => 'semua :total pelanggan offline.',
        'odp_degraded' => 'ODP GANGGUAN',
        'onus_down' => ':count ONU down',
        'of_total' => ' dari :total',
    ],

    'push' => [
        'odp_recovered_title' => 'ODP pulih · :odp (:count ONU)',
        'odp_recovered_body' => 'ODP :odp — :count ONU kembali online: :list',
        'odp_all_down_body' => 'ODP :odp — semua :total pelanggan offline.',
        'odp_degraded_title' => 'ODP gangguan · :odp (:count ONU)',
        'odp_degraded_body' => 'ODP :odp — :count ONU down: :list',
    ],
];
