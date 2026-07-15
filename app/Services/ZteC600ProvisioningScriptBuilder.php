<?php

namespace App\Services;

use App\Support\SmartOltSupport;
use RuntimeException;

/**
 * Script provisioning ONU untuk ZTE C600 (Titan).
 *
 * C600 **bukan** sekadar C300 dengan nama interface lain — struktur config-nya beda:
 * `vport-mode manual` + `vport-map` di interface ONU, dan `service-port` pindah ke
 * `interface vport-1/{slot}/{port}.{onuId}:{vport}` tersendiri. Karena itu jalur ini terpisah dari
 * {@see ZteProvisioningScriptBuilder} (yang tetap dipakai C300/C320).
 *
 * **Status: BELUM DIVERIFIKASI KE PERANGKAT.** Setiap baris di bawah diturunkan dari `show
 * running-config` satu ONU di C600 asli (V1.2.2) — output itu menunjukkan *hasil* config, bukan
 * bukti bahwa urutan input ini diterima apa adanya. `capabilities()['supports_provisioning']`
 * sengaja `false` untuk C600, jadi kelas ini belum terpanggil dari mana pun. Jangan diaktifkan
 * sebelum satu ONU uji benar-benar ter-provision lewat script ini.
 *
 * Provenance tiap baris ditandai:
 *   [asli]     = terlihat verbatim di running-config C600
 *   [turunan]  = pola dari running-config, nilainya di-parameterkan
 *
 * Referensi lengkap: docs/SMARTOLT_ZTE_C600_GUIDE.md §11.
 */
class ZteC600ProvisioningScriptBuilder
{
    /**
     * Mode WAN yang polanya terlihat di running-config C600. `pppoe`/`dhcp`/`static` memakai
     * `wan-ip …` gaya C300 yang **tak pernah terlihat** di C600 (sampelnya memakai `wan … service
     * tr069` + `veip`), jadi ditolak ketimbang ditebak.
     */
    private const SUPPORTED_WAN_MODES = ['tr069'];

    /**
     * @param  array<string, mixed>  $data
     */
    public function build(array $data): string
    {
        $slot = (int) $data['slot'];
        $port = (int) $data['port'];
        $onuId = (int) $data['onu_id'];
        $sn = strtoupper(self::cli((string) $data['serial_number']));
        $name = self::cli((string) $data['customer_name']);
        $onuType = strtoupper((string) ($data['onu_type'] ?? 'ALL-ONT'));
        $tcontProfile = (string) ($data['tcont_profile'] ?? 'SERVER');
        $vlan = (int) $data['vlan'];
        $serviceName = (string) ($data['service_name'] ?? 'ServiceName');
        $gemportName = (string) ($data['gemport_name'] ?? 'internet');

        $wanMode = strtolower((string) ($data['wan_mode'] ?? 'tr069'));
        if (! in_array($wanMode, self::SUPPORTED_WAN_MODES, true)) {
            throw new RuntimeException(
                "Mode WAN '{$wanMode}' belum dipetakan untuk C600 — sampel running-config C600 hanya ".
                'menunjukkan pola TR069/VEIP. Verifikasi sintaksnya ke OLT dulu sebelum dipakai.'
            );
        }

        $oltIface = SmartOltSupport::gponOltInterface($slot, $port, true);
        $onuIface = SmartOltSupport::onuInterfaceId($slot, $port, $onuId, true);
        $vportIface = self::vportInterface($slot, $port, $onuId);
        $description = "{$onuId}\$\${$name}\$\$";

        $lines = [
            'conf t',
            '',
            // [asli] interface gpon_olt-1/3/13 → onu 8 type F620IBV9.3.11 sn ZTEGDC480F1C
            "interface {$oltIface}",
            "onu {$onuId} type {$onuType} sn {$sn}",
            'exit',
            '',
            // [asli] blok interface gpon_onu-1/3/13:8
            "interface {$onuIface}",
            "name {$name}",
            "description {$description}",     // [asli] C600 punya name DAN description
            'vport-mode manual',              // [asli]
            "tcont 1 profile {$tcontProfile}",     // [asli] tanpa token `name` (beda dari C300)
            "gemport 1 name {$gemportName} tcont 1",  // [asli]
            'vport 1 map-type vlan',          // [asli]
            "vport-map 1 1 vlan {$vlan}",     // [turunan] asli: vport-map 1 1 vlan 200
            'exit',
            '',
            // [asli] service-port ada di interface vport tersendiri, BUKAN di interface ONU
            "interface {$vportIface}",
            $this->servicePortLine($data, $vlan),
            'exit',
            '',
            // [asli] blok pon-onu-mng gpon_onu-1/3/13:8
            "pon-onu-mng {$onuIface}",
            "service {$serviceName} gemport 1 vlan {$vlan}",  // [asli] tanpa `cos` (beda dari C300)
        ];

        $lines = array_merge($lines, $this->tr069Lines($data));
        $lines[] = $this->remoteOntLine($data);
        $lines[] = 'exit';

        return implode("\n", array_filter($lines, fn (?string $line) => $line !== null));
    }

    /**
     * Nama interface vport C600: `vport-1/{slot}/{port}.{onuId}:{vport}`.
     * [asli] `interface vport-1/3/13.8:1`.
     */
    public static function vportInterface(int $slot, int $port, int $onuId, int $vport = 1): string
    {
        return sprintf('vport-1/%d/%d.%d:%d', $slot, $port, $onuId, $vport);
    }

    /**
     * [asli] `service-port 1 user-vlan 200 vlan 200 ingress 10MB egress SMARTOLT-10M-DOWN`.
     * Profil ingress/egress dilewati bila tak diisi — sampel selalu menyertakannya, jadi **belum
     * terbukti** apakah baris tanpa profil diterima C600.
     *
     * @param  array<string, mixed>  $data
     */
    private function servicePortLine(array $data, int $vlan): string
    {
        $line = "service-port 1 user-vlan {$vlan} vlan {$vlan}";
        $ingress = trim((string) ($data['ingress_profile'] ?? ''));
        $egress = trim((string) ($data['egress_profile'] ?? ''));

        if ($ingress !== '') {
            $line .= ' ingress '.self::cli($ingress);
        }

        if ($egress !== '') {
            $line .= ' egress '.self::cli($egress);
        }

        return $line;
    }

    /**
     * C600 menyatukan unlock + acs dalam **satu** baris; C300 memecahnya jadi dua.
     * [asli] `tr069-mgmt 1 state unlock acs http://… validate basic username … password … tag pr1 2 vlan 601`.
     * Token `tag pr1 2 vlan {mgmt-vlan}` bergantung VLAN manajemen per-site → hanya ditambahkan bila
     * `tr069_mgmt_vlan` diisi. `wan {n} service tr069` menyusul pola sampel.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function tr069Lines(array $data): array
    {
        if (! filter_var($data['tr069_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return [];
        }

        $line = sprintf(
            'tr069-mgmt 1 state unlock acs %s validate basic username %s password %s',
            self::cli((string) $data['acs_url']),
            self::cli((string) $data['acs_username']),
            self::cli((string) $data['acs_password']),
        );

        $mgmtVlan = (int) ($data['tr069_mgmt_vlan'] ?? 0);
        if ($mgmtVlan > 0) {
            $line .= " tag pr1 2 vlan {$mgmtVlan}";
        }

        return [
            'wan 2 service tr069',  // [asli]
            $line,
        ];
    }

    /**
     * [asli] `security-mgmt 1 state enable mode forward protocol web https`.
     *
     * @param  array<string, mixed>  $data
     */
    private function remoteOntLine(array $data): ?string
    {
        if (! filter_var($data['remote_ont_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }

        return sprintf(
            'security-mgmt %d state enable mode %s protocol %s',
            (int) $data['remote_ont_id'],
            $data['remote_ont_mode'],
            $data['remote_ont_protocol'],
        );
    }

    /**
     * Sanitasi nilai teks-bebas sebelum disisipkan ke baris CLI — script dieksekusi baris-per-baris
     * ke sesi telnet, jadi CR/LF di field bebas bisa menyuntikkan perintah config tambahan.
     */
    private static function cli(string $value): string
    {
        return trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value) ?? '');
    }
}
