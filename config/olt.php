<?php

// Batas alamat tujuan koneksi OLT — dipakai App\Support\OltTargetGuard.
//
// Server NMS yang membuka socket ke OLT (CLI telnet, proxy telnet web, klien web HSGQ) bisa
// dipakai sebagai jembatan ke jaringan internal bila alamat OLT bebas diisi. Dua daftar di
// bawah ini yang mengaturnya; keduanya IPv4 CIDR, dipisah koma.
return [
    'target_guard' => [
        // Jalur yang boleh dipakai OLT milik PARTNER (mis. subnet WireGuard/tunnel partner
        // atau blok publik yang di-NAT ke OLT mereka).
        //
        // KOSONG = partner tidak boleh menambah OLT sendiri lewat form; OLT untuk partner
        // dibuat staf Pusat lalu di-assign ke partner (tetap berfungsi penuh). Isi hanya bila
        // partner memang harus bisa menambah OLT-nya sendiri.
        'partner_cidrs' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('NMS_OLT_PARTNER_CIDRS', ''))
        ))),

        // CIDR tambahan yang selalu ditolak, untuk SEMUA OLT (partner maupun Pusat) —
        // mis. alamat server ini, host ACS/TR-069, VLAN manajemen, atau jaringan kantor.
        // Alamat server NMS sendiri sudah ditolak otomatis; daftar ini untuk alamat LAIN
        // yang tak pernah menjadi OLT.
        'blocked_cidrs' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('NMS_OLT_BLOCKED_CIDRS', ''))
        ))),
    ],
];
