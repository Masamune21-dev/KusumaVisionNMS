<?php

namespace App\Services;

use App\Support\SmartOltSupport;
use RuntimeException;

/**
 * Script provisioning ONU untuk ZTE C600 (Titan) — **Model B / gaya SmartOLT**, direproduksi
 * PERSIS dari running-config ONU yang sudah jalan di C600 lapangan (LAS GALERAS, verifikasi live
 * 18 Jul 2026 atas `gpon_onu-1/3/1:2/:11/:80`).
 *
 * C600 **bukan** C300 dengan nama interface lain — strukturnya beda dan dipisah dari
 * {@see ZteProvisioningScriptBuilder} (C300/C320). Dua layanan: **internet** (gemport 1, VLAN data)
 * dan **manajemen/TR069** (gemport 2, VLAN mgmt) + `mgmt-ip` in-band + VEIP/ACS.
 *
 * Struktur (terverifikasi verbatim dari `show this` config-mode ONU asli):
 *   interface gpon_olt-1/{s}/{p}   → onu {id} type {model} sn {SN}
 *   interface gpon_onu-1/{s}/{p}:{id}
 *       name / description / tcont 1&2 / gemport 1 internet & 2 mgmt
 *   pon-onu-mng gpon_onu-1/{s}/{p}:{id}
 *       mgmt-ip … / [security-mgmt] / service vlan{data} & vlan{mgmt} / veip 1 port 1232 /
 *       wan 2 service tr069 / tr069-mgmt 1 state unlock acs … tag pri {prio} vlan {mgmt}
 *   interface vport-1/{s}/{p}.{id}:1
 *       service-port 1 user-vlan {data} vlan {data} / [qos traffic-policy … direction egress]
 *   write
 *
 * Catatan realita lapangan yang MENANG atas dokumen builder-v2 (yang berbeda di titik ini):
 *   - tr069-mgmt = SATU baris tergabung `state unlock acs … tag pri N vlan M` (bukan dipecah dua).
 *   - `tag pri` (bukan `pr1`) — terbukti dari config asli.
 *   - TANPA `vport-mode manual`/`vport-map` (ONU Model B pakai vport mode default).
 *   - service-port TANPA ingress/egress inline; laju downstream lewat `qos traffic-policy … egress`.
 *
 * Hanya WAN TR069/VEIP yang terpetakan. pppoe/dhcp/static tak muncul di running-config C600 mana pun
 * → ditolak, bukan ditebak.
 */
class ZteC600ProvisioningScriptBuilder
{
    /** Field wajib untuk membangun script Model B (validasi juga di OnuRegistrationService). */
    private const REQUIRED = [
        'slot', 'port', 'onu_id', 'serial_number', 'customer_name', 'onu_type',
        'internet_vlan', 'internet_tcont_profile',
        'mgmt_vlan', 'mgmt_tcont_profile',
        'mgmt_ip', 'mgmt_mask', 'mgmt_gateway',
        'acs_url', 'acs_username', 'acs_password',
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    public function build(array $data): string
    {
        foreach (self::REQUIRED as $key) {
            if (! isset($data[$key]) || trim((string) $data[$key]) === '') {
                throw new RuntimeException("Field '{$key}' wajib untuk provisioning C600 (Model B TR069).");
            }
        }

        $slot = (int) $data['slot'];
        $port = (int) $data['port'];
        $onuId = (int) $data['onu_id'];
        $sn = strtoupper(self::cli((string) $data['serial_number']));
        $name = self::cli((string) $data['customer_name']);
        $onuType = self::cli((string) $data['onu_type']);

        $internetVlan = (int) $data['internet_vlan'];
        $mgmtVlan = (int) $data['mgmt_vlan'];
        $internetTcont = self::cli((string) $data['internet_tcont_profile']);
        $mgmtTcont = self::cli((string) $data['mgmt_tcont_profile']);
        $egressPolicy = self::cli((string) ($data['egress_traffic_policy'] ?? ''));

        $mgmtIp = self::cli((string) $data['mgmt_ip']);
        $mgmtMask = self::cli((string) $data['mgmt_mask']);
        $mgmtGw = self::cli((string) $data['mgmt_gateway']);
        $prio = (int) ($data['mgmt_priority'] ?? 2);
        $host = (int) ($data['mgmt_host'] ?? 2);

        $acsUrl = self::cli((string) $data['acs_url']);
        $acsUser = self::cli((string) $data['acs_username']);
        $acsPass = self::cli((string) $data['acs_password']);

        $description = $this->description($data);

        $oltIface = SmartOltSupport::gponOltInterface($slot, $port, true);
        $onuIface = SmartOltSupport::onuInterfaceId($slot, $port, $onuId, true);
        $vportIface = self::vportInterface($slot, $port, $onuId);

        $lines = [
            'configure terminal',
            '',
            // Registrasi fisik di interface OLT.
            "interface {$oltIface}",
            "onu {$onuId} type {$onuType} sn {$sn}",
            'exit',
            '',
            // T-CONT & GEM: 1=internet, 2=mgmt.
            "interface {$onuIface}",
            "name {$name}",
            "description {$description}",
            "tcont 1 profile {$internetTcont}",
            "tcont 2 profile {$mgmtTcont}",
            'gemport 1 name internet tcont 1',
            'gemport 2 name mgmt tcont 2',
            'exit',
            '',
            // Manajemen ONU: mgmt-ip in-band, service VLAN, VEIP, WAN TR069, ACS.
            "pon-onu-mng {$onuIface}",
            "mgmt-ip {$mgmtIp} {$mgmtMask} vlan {$mgmtVlan} priority {$prio} route 0.0.0.0 0.0.0.0 {$mgmtGw} host {$host}",
        ];

        $lines = array_merge($lines, $this->remoteOntLines($data));

        $lines = array_merge($lines, [
            "service vlan{$internetVlan} gemport 1 vlan {$internetVlan}",
            "service vlan{$mgmtVlan} gemport 2 vlan {$mgmtVlan}",
            "veip 1 port 1232 ipv4 host {$host}",
            'wan 2 service tr069',
            sprintf(
                'tr069-mgmt 1 state unlock acs %s validate basic username %s password %s tag pri %d vlan %d',
                $acsUrl,
                $acsUser,
                $acsPass,
                $prio,
                $mgmtVlan,
            ),
            'exit',
            '',
            // Service-port di interface vport tersendiri; laju downstream via qos traffic-policy.
            "interface {$vportIface}",
            "service-port 1 user-vlan {$internetVlan} vlan {$internetVlan}",
        ]);

        if ($egressPolicy !== '') {
            $lines[] = "qos traffic-policy {$egressPolicy} direction egress";
        }

        $lines[] = 'exit';
        // `end` kembali ke privileged-exec (ZXAN#). `write` C600 HANYA valid di sana — dijalankan
        // dari mode config (ZXAN(config)#) ia error "Invalid input" & config tak tersimpan ke flash.
        $lines[] = 'end';
        $lines[] = '';
        $lines[] = 'write';

        return implode("\n", $lines);
    }

    /**
     * Nama interface vport C600: `vport-1/{slot}/{port}.{onuId}:{vport}` (asli: `vport-1/3/1.11:1`).
     */
    public static function vportInterface(int $slot, int $port, int $onuId, int $vport = 1): string
    {
        return sprintf('vport-1/%d/%d.%d:%d', $slot, $port, $onuId, $vport);
    }

    /**
     * Deskripsi: pakai `description` eksplisit bila ada; jika tidak, susun gaya SmartOLT
     * `zone_<zona>_authd_<YYYYMMDD>` dari `zone` (hari ini bila tak diberi tanggal).
     *
     * @param  array<string, mixed>  $data
     */
    private function description(array $data): string
    {
        $explicit = self::cli((string) ($data['description'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        $zone = self::cli((string) ($data['zone'] ?? ''));
        if ($zone === '') {
            return self::cli((string) $data['customer_name']);
        }

        $date = self::cli((string) ($data['authd_date'] ?? now()->format('Ymd')));

        return "zone_{$zone}_authd_{$date}";
    }

    /**
     * `security-mgmt` (akses web ONU jarak jauh) — OPT-IN. Config asli memakai indeks 1 & 5;
     * hanya ditambahkan bila `remote_ont_enabled` true, karena memperluas permukaan akses.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function remoteOntLines(array $data): array
    {
        if (! filter_var($data['remote_ont_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return [];
        }

        return [
            'security-mgmt 1 state enable mode forward protocol web https',
            'security-mgmt 5 state enable mode forward protocol web https',
        ];
    }

    /**
     * Sanitasi nilai teks-bebas sebelum disisipkan ke baris CLI — script dieksekusi baris-per-baris
     * ke sesi telnet, jadi CR/LF/kontrol di field bebas bisa menyuntikkan perintah config tambahan.
     */
    private static function cli(string $value): string
    {
        return trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value) ?? '');
    }
}
