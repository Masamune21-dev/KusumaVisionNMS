<?php

namespace App\Services\Zte\Epon;

use App\Support\ZteEpon;
use InvalidArgumentException;

/**
 * Skrip registrasi ONU EPON ZTE C300/C320 — model bridge: OLT hanya mengotorisasi MAC ONU,
 * memasang SLA (bandwidth) dan VLAN layanan; PPPoE/WiFi diatur di ONU/router pelanggan (ONU EPON
 * dikelola lewat ExtOAM, tak ada wan-ip/tr069-mgmt OMCI seperti GPON).
 *
 *   conf t
 *   interface epon-olt_1/{s}/{p}
 *   onu {n} type {T} mac {xxxx.xxxx.xxxx}
 *   exit
 *   interface epon-onu_1/{s}/{p}:{n}
 *   property description $${nama}$${deskripsi}
 *   sla-profile {SLA} vport 1                        ← gaya V2.x (service-port)
 *   service-port 1 vport 1 user-vlan {V} vlan {V}
 *     — atau gaya V1.x (manual C300 V1.2): `sla-profile {SLA}` + `switchport vlan {V} tag`
 *   exit
 *   pon-onu-mng epon-onu_1/{s}/{p}:{n}               ← opsional: VLAN di port LAN ONU
 *   vlan port eth_0/{e} mode tag vlan {V} priority 0 | vlan port eth_0/{e} mode transparent
 *   exit
 *
 * ⚠️ Belum pernah dijalankan ke OLT ETG asli (lihat {@see ZteEpon}); operator selalu melihat
 * pratinjau skrip ini sebelum mengeksekusinya.
 */
class ZteEponProvisioningScriptBuilder
{
    public const SERVICE_STYLES = ['service-port', 'switchport'];

    public const ONU_VLAN_MODES = ['none', 'tag', 'transparent'];

    /**
     * @param  array<string, mixed>  $data
     */
    public function build(array $data): string
    {
        $slot = (int) $data['slot'];
        $port = (int) $data['port'];
        $onuId = (int) $data['onu_id'];
        $mac = ZteEpon::cliMac((string) ($data['mac'] ?? ''));

        if ($mac === null) {
            throw new InvalidArgumentException(__('zte.epon_mac_invalid'));
        }

        $type = self::cli((string) ($data['onu_type'] ?? '')) ?: 'ALL-EPON';
        $name = self::text((string) ($data['customer_name'] ?? ''));
        $description = self::text((string) ($data['description'] ?? ''));
        $sla = self::cli((string) ($data['sla_profile'] ?? ''));
        $vlan = (int) ($data['vlan'] ?? 0);
        $style = in_array($data['service_style'] ?? null, self::SERVICE_STYLES, true) ? $data['service_style'] : 'service-port';
        $onuVlanMode = in_array($data['onu_vlan_mode'] ?? null, self::ONU_VLAN_MODES, true) ? $data['onu_vlan_mode'] : 'none';
        $ethPort = max(1, min(4, (int) ($data['onu_eth_port'] ?? 1)));
        $onuIface = ZteEpon::onuInterface($slot, $port, $onuId);

        $lines = [
            'conf t',
            '',
            'interface '.ZteEpon::oltInterface($slot, $port),
            "onu {$onuId} type {$type} mac {$mac}",
            'exit',
            '',
            "interface {$onuIface}",
        ];

        if ($name !== '' || $description !== '') {
            $lines[] = self::propertyDescription($name, $description);
        }

        if ($sla !== '') {
            $lines[] = $style === 'service-port' ? "sla-profile {$sla} vport 1" : "sla-profile {$sla}";
        }

        if ($vlan >= 1 && $vlan <= 4094) {
            $lines[] = $style === 'service-port'
                ? "service-port 1 vport 1 user-vlan {$vlan} vlan {$vlan}"
                : "switchport vlan {$vlan} tag";
        }

        $lines[] = 'exit';

        if ($onuVlanMode !== 'none') {
            $lines[] = '';
            $lines[] = "pon-onu-mng {$onuIface}";
            $lines[] = $onuVlanMode === 'transparent' || $vlan < 1
                ? "vlan port eth_0/{$ethPort} mode transparent"
                : "vlan port eth_0/{$ethPort} mode tag vlan {$vlan} priority 0";
            $lines[] = 'exit';
        }

        return implode("\n", $lines);
    }

    /**
     * Baris nama+deskripsi ONU EPON (satu-satunya tempat nama disimpan di ONU EPON ZTE).
     */
    public static function propertyDescription(string $name, string $description): string
    {
        return 'property description $$'.self::text($name).'$$'.self::text($description);
    }

    /**
     * Teks bebas di baris CLI: buang karakter kontrol (injeksi baris perintah baru) dan `$`
     * (pemisah format `$$nama$$deskripsi` — kalau lolos, nama terbaca terpotong).
     */
    public static function text(string $value): string
    {
        return trim(str_replace('$', '', self::cli($value)));
    }

    private static function cli(string $value): string
    {
        return trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value) ?? '');
    }
}
