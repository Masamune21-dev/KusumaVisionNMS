<?php

namespace Tests\Unit;

use App\Services\ZteC600ProvisioningScriptBuilder;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Mengunci bentuk script C600 ke `show running-config` C600 asli (V1.2.2). Bila salah satu
 * assertion di bawah gagal, script tak lagi mencerminkan config yang benar-benar ada di OLT.
 */
class ZteC600ProvisioningScriptBuilderTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'slot' => 3,
            'port' => 13,
            'onu_id' => 8,
            'serial_number' => 'ZTEGdc480f1c',
            'customer_name' => 'Budi Santoso',
            'onu_type' => 'F620IBV9.3.11',
            'tcont_profile' => 'SMARTOLT_DEFAULT_TCONT_GPN',
            'vlan' => 200,
            'service_name' => 'vlan200',
            'wan_mode' => 'tr069',
        ], $overrides);
    }

    public function test_build_matches_c600_running_config_shape(): void
    {
        $script = (new ZteC600ProvisioningScriptBuilder)->build($this->payload());

        // Nama interface C600 = 3-tier, eja `gpon_olt-` / `gpon_onu-` (bukan 4-tier `gpon-olt_1/1/…`).
        $this->assertStringContainsString('interface gpon_olt-1/3/13', $script);
        $this->assertStringContainsString('onu 8 type F620IBV9.3.11 sn ZTEGDC480F1C', $script);
        $this->assertStringContainsString('interface gpon_onu-1/3/13:8', $script);
        $this->assertStringContainsString('pon-onu-mng gpon_onu-1/3/13:8', $script);
        $this->assertStringNotContainsString('gpon-onu_1/1/', $script);

        // C600 punya name DAN description.
        $this->assertStringContainsString('name Budi Santoso', $script);
        $this->assertStringContainsString('description 8$$Budi Santoso$$', $script);

        // tcont C600 tanpa token `name` (C300: `tcont 1 name 1 profile …`).
        $this->assertStringContainsString('tcont 1 profile SMARTOLT_DEFAULT_TCONT_GPN', $script);
        $this->assertStringNotContainsString('tcont 1 name 1 profile', $script);

        // Model vport C600.
        $this->assertStringContainsString('vport-mode manual', $script);
        $this->assertStringContainsString('vport 1 map-type vlan', $script);
        $this->assertStringContainsString('vport-map 1 1 vlan 200', $script);

        // service-port ada di interface vport tersendiri, bukan di interface ONU.
        $this->assertStringContainsString('interface vport-1/3/13.8:1', $script);
        $this->assertStringContainsString('service-port 1 user-vlan 200 vlan 200', $script);
        $this->assertStringNotContainsString('service-port 1 vport 1', $script);

        // service pon-onu-mng C600 tanpa `cos`.
        $this->assertStringContainsString('service vlan200 gemport 1 vlan 200', $script);
        $this->assertStringNotContainsString('cos 0', $script);
    }

    public function test_service_port_appends_bandwidth_profiles_when_given(): void
    {
        $script = (new ZteC600ProvisioningScriptBuilder)->build($this->payload([
            'ingress_profile' => '10MB',
            'egress_profile' => 'SMARTOLT-10M-DOWN',
        ]));

        $this->assertStringContainsString(
            'service-port 1 user-vlan 200 vlan 200 ingress 10MB egress SMARTOLT-10M-DOWN',
            $script,
        );
    }

    public function test_tr069_is_a_single_unlock_plus_acs_line(): void
    {
        $script = (new ZteC600ProvisioningScriptBuilder)->build($this->payload([
            'tr069_enabled' => true,
            'acs_url' => 'http://10.69.69.1:14501',
            'acs_username' => 'acsuser',
            'acs_password' => 'acspass',
            'tr069_mgmt_vlan' => 601,
        ]));

        $this->assertStringContainsString('wan 2 service tr069', $script);
        $this->assertStringContainsString(
            'tr069-mgmt 1 state unlock acs http://10.69.69.1:14501 validate basic username acsuser password acspass tag pr1 2 vlan 601',
            $script,
        );
        // C300 memecah jadi dua baris; C600 tidak.
        $this->assertStringNotContainsString("tr069-mgmt 1 state unlock\n", $script);
    }

    public function test_unmapped_wan_modes_are_rejected_instead_of_guessed(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/belum dipetakan untuk C600/');

        (new ZteC600ProvisioningScriptBuilder)->build($this->payload(['wan_mode' => 'pppoe']));
    }

    public function test_cli_injection_via_customer_name_is_stripped(): void
    {
        $script = (new ZteC600ProvisioningScriptBuilder)->build($this->payload([
            'customer_name' => "Budi\nexit\nno onu 8",
        ]));

        // Script dieksekusi baris-per-baris, jadi yang berbahaya adalah baris CLI *baru* —
        // bukan substring-nya. CR/LF diratakan jadi spasi sehingga tetap satu nilai `name`.
        $lines = array_map('trim', explode("\n", $script));
        $this->assertNotContains('no onu 8', $lines);
        $this->assertContains('name Budi exit no onu 8', $lines);
    }
}
