<?php

namespace Tests\Unit;

use App\Services\ZteC600ProvisioningScriptBuilder;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ZteC600ProvisioningBuilderTest extends TestCase
{
    /** @return array<string, mixed> */
    private function input(array $overrides = []): array
    {
        return array_merge([
            'slot' => 3,
            'port' => 1,
            'onu_id' => 11,
            'serial_number' => 'hwtcc62b52af',
            'customer_name' => 'NET LINK DOMINICANA',
            'onu_type' => 'HG8145X6-10',
            'zone' => 'GUAZUMA',
            'authd_date' => '20260718',
            'internet_vlan' => 200,
            'internet_tcont_profile' => '10MB',
            'mgmt_vlan' => 601,
            'mgmt_tcont_profile' => 'SMARTOLT-VOIPMNG-10M',
            'egress_traffic_policy' => 'SMARTOLT-10M-DOWN',
            'mgmt_ip' => '10.64.71.83',
            'mgmt_mask' => '255.255.240.0',
            'mgmt_gateway' => '10.64.64.1',
            'acs_url' => 'http://10.69.69.1:14501',
            'acs_username' => 'acsuser',
            'acs_password' => 'acspass',
        ], $overrides);
    }

    /** Output builder harus mereproduksi struktur config ASLI (gpon_onu-1/3/1:11 Model B). */
    public function test_builds_model_b_matching_live_config(): void
    {
        $script = (new ZteC600ProvisioningScriptBuilder)->build($this->input());

        // Registrasi fisik.
        $this->assertStringContainsString("interface gpon_olt-1/3/1\nonu 11 type HG8145X6-10 sn HWTCC62B52AF", $script);

        // T-CONT & GEM: internet=1, mgmt=2.
        $this->assertStringContainsString('interface gpon_onu-1/3/1:11', $script);
        $this->assertStringContainsString('name NET LINK DOMINICANA', $script);
        $this->assertStringContainsString('description zone_GUAZUMA_authd_20260718', $script);
        $this->assertStringContainsString('tcont 1 profile 10MB', $script);
        $this->assertStringContainsString('tcont 2 profile SMARTOLT-VOIPMNG-10M', $script);
        $this->assertStringContainsString('gemport 1 name internet tcont 1', $script);
        $this->assertStringContainsString('gemport 2 name mgmt tcont 2', $script);

        // Manajemen.
        $this->assertStringContainsString(
            'mgmt-ip 10.64.71.83 255.255.240.0 vlan 601 priority 2 route 0.0.0.0 0.0.0.0 10.64.64.1 host 2',
            $script,
        );
        $this->assertStringContainsString('service vlan200 gemport 1 vlan 200', $script);
        $this->assertStringContainsString('service vlan601 gemport 2 vlan 601', $script);
        $this->assertStringContainsString('veip 1 port 1232 ipv4 host 2', $script);
        $this->assertStringContainsString('wan 2 service tr069', $script);

        // tr069-mgmt: SATU baris tergabung + `tag pri` (bukan pr1) — realita menang atas dokumen.
        $this->assertStringContainsString(
            'tr069-mgmt 1 state unlock acs http://10.69.69.1:14501 validate basic username acsuser password acspass tag pri 2 vlan 601',
            $script,
        );

        // service-port + qos downstream di interface vport.
        $this->assertStringContainsString('interface vport-1/3/1.11:1', $script);
        $this->assertStringContainsString('service-port 1 user-vlan 200 vlan 200', $script);
        $this->assertStringContainsString('qos traffic-policy SMARTOLT-10M-DOWN direction egress', $script);

        // Simpan config.
        $this->assertStringEndsWith("\nwrite", $script);

        // Jangan pernah keluarkan kesalahan yang sudah dikoreksi.
        $this->assertStringNotContainsString('vport-mode manual', $script);
        $this->assertStringNotContainsString('vport-map', $script);
        $this->assertStringNotContainsString('tag pr1', $script);
    }

    /** security-mgmt opt-in: default OFF, muncul saat remote_ont_enabled. */
    public function test_security_mgmt_is_opt_in(): void
    {
        $off = (new ZteC600ProvisioningScriptBuilder)->build($this->input());
        $this->assertStringNotContainsString('security-mgmt', $off);

        $on = (new ZteC600ProvisioningScriptBuilder)->build($this->input(['remote_ont_enabled' => true]));
        $this->assertStringContainsString('security-mgmt 1 state enable mode forward protocol web https', $on);
        $this->assertStringContainsString('security-mgmt 5 state enable mode forward protocol web https', $on);
    }

    /** Field wajib yang hilang → tolak (jangan hasilkan script setengah). */
    public function test_missing_required_field_throws(): void
    {
        $this->expectException(RuntimeException::class);
        (new ZteC600ProvisioningScriptBuilder)->build($this->input(['mgmt_ip' => '']));
    }
}
