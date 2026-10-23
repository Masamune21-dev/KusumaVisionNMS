<?php

namespace Tests\Unit;

use App\Models\SnmpOlt;
use App\Services\Zte\Epon\ZteEponProvisioningScriptBuilder;
use App\Support\ZteEpon;
use InvalidArgumentException;
use Tests\TestCase;

class ZteEponTest extends TestCase
{
    private function olt(string $vendor = 'ZTE C320', array $ports = []): SnmpOlt
    {
        $olt = new SnmpOlt(['name' => 'OLT-UJI', 'vendor' => $vendor, 'ip' => '10.99.0.1']);
        $olt->last_test_result = [
            'ports' => $ports ?: [
                ['slot' => 2, 'port' => 1, 'name' => 'gpon_1/2/1', 'oper_status' => 'up'],
                ['slot' => 3, 'port' => 1, 'name' => 'epon-olt_1/3/1', 'oper_status' => 'up', 'pon_type' => 'epon'],
            ],
        ];

        return $olt;
    }

    public function test_switch_is_off_by_default_and_hides_epon_ports(): void
    {
        $this->assertFalse(ZteEpon::enabled());
        $this->assertFalse(ZteEpon::isEponPort($this->olt(), 3, 1));
    }

    public function test_epon_port_is_detected_only_when_enabled_and_not_c600(): void
    {
        config(['services.zte_epon.enabled' => true]);

        $this->assertTrue(ZteEpon::isEponPort($this->olt(), 3, 1));
        $this->assertFalse(ZteEpon::isEponPort($this->olt(), 2, 1));
        $this->assertFalse(ZteEpon::isEponPort($this->olt('ZTE C600'), 3, 1));
        $this->assertFalse(ZteEpon::isEponPort($this->olt('C-Data FD1608S EPON'), 3, 1));
    }

    public function test_mac_formats(): void
    {
        $this->assertSame('1c18.4a0d.d30f', ZteEpon::cliMac('1C:18:4A:0D:D3:0F'));
        $this->assertSame('1c18.4a0d.d30f', ZteEpon::cliMac('1c18.4a0d.d30f'));
        $this->assertSame('1c18.4a0d.d30f', ZteEpon::cliMac('1C184A0DD30F'));
        $this->assertSame('1C:18:4A:0D:D3:0F', ZteEpon::displayMac('1c18-4a0d-d30f'));
        $this->assertNull(ZteEpon::cliMac('0000.0000.0000'));
        $this->assertNull(ZteEpon::cliMac('ZTEGC1234567'));
        $this->assertNull(ZteEpon::cliMac('1c18.4a0d.d3'));
    }

    public function test_port_capabilities_close_gpon_only_features(): void
    {
        $caps = ZteEpon::portCapabilities([
            'driver' => 'zte',
            'supports_onu_toggle' => true,
            'supports_onu_config_write' => true,
            'supports_onu_replace' => true,
            'supports_cli_onu_detail' => true,
        ]);

        $this->assertSame('zte', $caps['driver']);
        $this->assertTrue($caps['is_epon_port']);
        $this->assertTrue($caps['supports_provisioning']);
        $this->assertTrue($caps['supports_reboot']);
        $this->assertTrue($caps['supports_onu_delete']);
        $this->assertTrue($caps['supports_onu_info_write']);
        $this->assertFalse($caps['supports_onu_toggle']);
        $this->assertFalse($caps['supports_onu_config_write']);
        $this->assertFalse($caps['supports_onu_replace']);
        $this->assertFalse($caps['supports_cli_onu_detail']);
        $this->assertFalse($caps['supports_onu_preregister']);
        $this->assertFalse($caps['supports_port_admin_write']);
    }

    public function test_carry_ports_brings_epon_ports_back_after_if_mib_rewrite(): void
    {
        config(['services.zte_epon.enabled' => true]);
        $olt = $this->olt();
        $previous = $olt->last_test_result;
        $next = ['ports' => [['slot' => 2, 'port' => 1, 'name' => 'gpon_1/2/1', 'oper_status' => 'down']]];

        $merged = ZteEpon::carryPorts($olt, $next, $previous);

        $this->assertSame(['gpon_1/2/1', 'epon-olt_1/3/1'], array_column($merged['ports'], 'name'));
        $this->assertSame('down', $merged['ports'][0]['oper_status']);
    }

    public function test_carry_ports_is_noop_when_switch_off(): void
    {
        $olt = $this->olt();
        $next = ['ports' => [['slot' => 2, 'port' => 1, 'name' => 'gpon_1/2/1']]];

        $this->assertSame($next, ZteEpon::carryPorts($olt, $next, $olt->last_test_result));
    }

    public function test_strip_removes_every_epon_trace(): void
    {
        $snapshot = [
            'ports' => [
                ['slot' => 2, 'port' => 1, 'name' => 'gpon_1/2/1'],
                ['slot' => 3, 'port' => 1, 'name' => 'epon-olt_1/3/1', 'pon_type' => 'epon'],
            ],
            'port_onus' => [
                '2_1' => ['onus' => [['onu_id' => 1]]],
                '3_1' => ['onus' => [['onu_id' => 1]], 'pon_type' => 'epon'],
            ],
            'epon' => ['ok' => true, 'cards' => []],
        ];

        $stripped = ZteEpon::strip($snapshot);

        $this->assertSame(['gpon_1/2/1'], array_column($stripped['ports'], 'name'));
        $this->assertSame(['2_1'], array_keys($stripped['port_onus']));
        $this->assertArrayNotHasKey('epon', $stripped);
    }

    public function test_builder_service_port_style(): void
    {
        $script = (new ZteEponProvisioningScriptBuilder)->build([
            'slot' => 3, 'port' => 1, 'onu_id' => 5, 'mac' => '1C:18:4A:0D:D3:0F',
            'onu_type' => 'ALL-EPON', 'customer_name' => 'Pelanggan Uji', 'description' => 'Jl. Contoh',
            'sla_profile' => 'EPON-20M', 'vlan' => 110, 'service_style' => 'service-port',
            'onu_vlan_mode' => 'tag', 'onu_eth_port' => 1,
        ]);

        $this->assertSame(implode("\n", [
            'conf t',
            '',
            'interface epon-olt_1/3/1',
            'onu 5 type ALL-EPON mac 1c18.4a0d.d30f',
            'exit',
            '',
            'interface epon-onu_1/3/1:5',
            'property description $$Pelanggan Uji$$Jl. Contoh',
            'sla-profile EPON-20M vport 1',
            'service-port 1 vport 1 user-vlan 110 vlan 110',
            'exit',
            '',
            'pon-onu-mng epon-onu_1/3/1:5',
            'vlan port eth_0/1 mode tag vlan 110 priority 0',
            'exit',
        ]), $script);
    }

    public function test_builder_switchport_style_without_onu_vlan(): void
    {
        $script = (new ZteEponProvisioningScriptBuilder)->build([
            'slot' => 2, 'port' => 1, 'onu_id' => 1, 'mac' => '1c184a0dd30f', 'onu_type' => 'ZTE-EPON',
            'customer_name' => '', 'sla_profile' => '10M', 'vlan' => 200,
            'service_style' => 'switchport', 'onu_vlan_mode' => 'none',
        ]);

        // Bentuk persis manual C300 V1.2.3: `sla-profile 10M` + `switchport vlan 200 tag`.
        $this->assertStringContainsString("interface epon-onu_1/2/1:1\nsla-profile 10M\nswitchport vlan 200 tag\nexit", $script);
        $this->assertStringNotContainsString('property description', $script);
        $this->assertStringNotContainsString('pon-onu-mng', $script);
    }

    public function test_builder_strips_injection_and_dollar_signs(): void
    {
        $script = (new ZteEponProvisioningScriptBuilder)->build([
            'slot' => 2, 'port' => 1, 'onu_id' => 1, 'mac' => '1c184a0dd30f', 'onu_type' => 'ALL-EPON',
            'customer_name' => "Budi\nno onu 1", 'description' => 'a$$b', 'vlan' => 100,
            'service_style' => 'service-port', 'onu_vlan_mode' => 'none',
        ]);

        $this->assertStringContainsString('property description $$Budi no onu 1$$ab', $script);
        // Baris baru di nama tak boleh menjadi perintah CLI tersendiri.
        $this->assertNotContains('no onu 1', explode("\n", $script));
    }

    public function test_builder_rejects_invalid_mac(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ZteEponProvisioningScriptBuilder)->build(['slot' => 2, 'port' => 1, 'onu_id' => 1, 'mac' => 'ZTEGC1234567']);
    }
}
