<?php

namespace Tests\Unit;

use App\Services\ZteCliProvisioningExecutor;
use App\Services\ZteOnuReconfigureScriptBuilder;
use App\Services\ZteOnuRunningConfigService;
use App\Services\ZteProvisioningScriptBuilder;
use PHPUnit\Framework\TestCase;

class ZteOnuConfigureTest extends TestCase
{
    private function parser(): ZteOnuRunningConfigService
    {
        return new ZteOnuRunningConfigService($this->createMock(ZteCliProvisioningExecutor::class));
    }

    private function sampleRaw(): string
    {
        return <<<'RAW'
--- show running-config interface gpon-onu_1/2/1:3 ---
Building configuration...
interface gpon-onu_1/2/1:3
  name Server Semampir
  description Server Semampir
  tcont 1 name 1 profile SERVER
  tcont 1 gap mode0
  gemport 1 name 1 tcont 1
  service-port 1 vport 1 user-vlan 22 vlan 22
  service-port 2 vport 1 user-vlan 100 vlan 100
!
end
LO.Berkah-Pati.v2#

--- show onu running config gpon-onu_1/2/1:3 ---
pon-onu-mng gpon-onu_1/2/1:3
  service ServiceName gemport 1 cos 0 vlan 22
  wan-ip 1 mode pppoe username serversemampir password rahasia vlan-profile PPPOEPATI host 1
  tr069-mgmt 1 state unlock
  tr069-mgmt 1 acs http://acs.bmkv.net:7547 validate basic username cms password kusuma123!
  security-mgmt 212 state enable mode forward protocol web
RAW;
    }

    public function test_parses_running_config_into_structured_shape(): void
    {
        $config = $this->parser()->parse($this->sampleRaw());

        $this->assertSame('Server Semampir', $config['name']);
        $this->assertCount(1, $config['tconts']);
        $this->assertSame(1, $config['tconts'][0]['id']);
        $this->assertSame('SERVER', $config['tconts'][0]['profile']);
        $this->assertSame('mode0', $config['tconts'][0]['gap']);
        $this->assertCount(1, $config['gemports']);
        $this->assertSame(1, $config['gemports'][0]['tcont']);
        $this->assertCount(2, $config['service_ports']);
        $this->assertSame(100, $config['service_ports'][1]['vlan']);
        $this->assertCount(1, $config['services']);
        $this->assertSame('ServiceName', $config['services'][0]['name']);
        $this->assertNull($config['services'][0]['type']);
        $this->assertSame(22, $config['primary_vlan']);

        $this->assertSame('pppoe', $config['wan_mode']);
        $this->assertSame('serversemampir', $config['pppoe_username']);
        $this->assertSame('rahasia', $config['pppoe_password']);
        $this->assertSame('PPPOEPATI', $config['vlan_profile']);

        $this->assertTrue($config['tr069']);
        $this->assertSame('cms', $config['acs_username']);

        $this->assertTrue($config['remote_ont']);
        $this->assertSame(212, $config['remote_ont_id']);
        $this->assertSame('forward', $config['remote_ont_mode']);
        $this->assertSame('web', $config['remote_ont_protocol']);
    }

    public function test_parses_static_mask_to_length(): void
    {
        $config = $this->parser()->parse(
            "interface gpon-onu_1/1/1:5\n".
            "  wan-ip 1 mode static ip-profile INTERNET ip-address 10.0.0.5 mask 255.255.255.0 host 1\n"
        );

        $this->assertSame('static', $config['wan_mode']);
        $this->assertSame('INTERNET', $config['ip_profile']);
        $this->assertSame('10.0.0.5', $config['static_ip']);
        $this->assertSame(24, $config['static_mask_length']);
    }

    public function test_no_change_produces_empty_script(): void
    {
        $config = $this->parser()->parse($this->sampleRaw());

        $delta = (new ZteOnuReconfigureScriptBuilder)->build($config, $config, ['onu_iface' => 'gpon-onu_1/2/1:3']);

        $this->assertSame('', $delta['script']);
        $this->assertSame([], $delta['changes']);
    }

    public function test_name_change_emits_minimal_interface_delta(): void
    {
        $base = $this->parser()->parse($this->sampleRaw());
        $target = $base;
        $target['name'] = 'Server Pati Baru';

        $delta = (new ZteOnuReconfigureScriptBuilder)->build($base, $target, ['onu_iface' => 'gpon-onu_1/2/1:3']);

        $this->assertStringContainsString('interface gpon-onu_1/2/1:3', $delta['script']);
        $this->assertStringContainsString('name Server Pati Baru', $delta['script']);
        $this->assertStringContainsString('description Server Pati Baru', $delta['script']);
        // Unchanged sections must not leak into the delta.
        $this->assertStringNotContainsString('service-port 1', $delta['script']);
        $this->assertStringNotContainsString('pon-onu-mng', $delta['script']);
        $this->assertSame('Name', $delta['changes'][0]['label']);
    }

    public function test_service_port_add_and_remove(): void
    {
        $base = $this->parser()->parse($this->sampleRaw());
        $target = $base;
        // remove id=2, add id=3
        $target['service_ports'] = [
            ['id' => 1, 'vport' => 1, 'user_vlan' => 22, 'vlan' => 22],
            ['id' => 3, 'vport' => 1, 'user_vlan' => 300, 'vlan' => 300],
        ];

        $delta = (new ZteOnuReconfigureScriptBuilder)->build($base, $target, ['onu_iface' => 'gpon-onu_1/2/1:3']);

        $this->assertStringContainsString('service-port 3 vport 1 user-vlan 300 vlan 300', $delta['script']);
        $this->assertStringContainsString('no service-port 2', $delta['script']);
        $this->assertStringNotContainsString('service-port 1 ', $delta['script']);
    }

    public function test_wan_and_toggle_deltas(): void
    {
        $base = $this->parser()->parse($this->sampleRaw());
        $target = $base;
        $target['pppoe_username'] = 'pelanggan_baru';
        $target['tr069'] = false;
        $target['remote_ont'] = false;

        $delta = (new ZteOnuReconfigureScriptBuilder)->build($base, $target, ['onu_iface' => 'gpon-onu_1/2/1:3']);

        $this->assertStringContainsString('wan-ip 1 mode pppoe username pelanggan_baru', $delta['script']);
        $this->assertStringContainsString('vlan-profile PPPOEPATI', $delta['script']);
        $this->assertStringContainsString('tr069-mgmt 1 state lock', $delta['script']);
        $this->assertStringContainsString('security-mgmt 212 state disable', $delta['script']);
    }

    public function test_parses_service_mode_vlanpri_and_transparent(): void
    {
        $vlanpri = $this->parser()->parse(
            "pon-onu-mng gpon-onu_1/2/1:3\n  service ServiceName gemport 1 cos 0 vlan 22\n"
        );
        $this->assertSame('vlanpri', $vlanpri['services'][0]['mode']);
        $this->assertSame(22, $vlanpri['services'][0]['vlan']);

        $transparent = $this->parser()->parse(
            "pon-onu-mng gpon-onu_1/2/1:3\n  service ServiceName gemport 1\n"
        );
        $this->assertSame('transparent', $transparent['services'][0]['mode']);
        $this->assertNull($transparent['services'][0]['vlan']);
        $this->assertSame(1, $transparent['services'][0]['gem']);
    }

    public function test_reconfigure_switch_service_to_transparent(): void
    {
        $base = $this->parser()->parse($this->sampleRaw());
        $target = $base;
        $target['services'] = [
            ['name' => 'ServiceName', 'type' => null, 'mode' => 'transparent', 'gem' => 1, 'cos' => 0, 'vlan' => null],
        ];

        $delta = (new ZteOnuReconfigureScriptBuilder)->build($base, $target, ['onu_iface' => 'gpon-onu_1/2/1:3']);

        $this->assertStringContainsString('service ServiceName gemport 1', $delta['script']);
        $this->assertStringNotContainsString('service ServiceName gemport 1 cos', $delta['script']);
    }

    public function test_provisioning_builder_emits_transparent_service_line(): void
    {
        $data = [
            'slot' => 1, 'port' => 2, 'onu_id' => 3, 'serial_number' => 'ZTEGabc12345',
            'customer_name' => 'Budi', 'onu_type' => 'ALL-ONT', 'tcont_profile' => 'SERVER',
            'vlan' => 125, 'service_name' => 'ServiceName', 'wan_mode' => 'dhcp',
        ];

        $vlanpri = (new ZteProvisioningScriptBuilder)->build($data + ['service_mode' => 'vlanpri']);
        $this->assertStringContainsString('service ServiceName gemport 1 cos 0 vlan 125', $vlanpri);

        $transparent = (new ZteProvisioningScriptBuilder)->build($data + ['service_mode' => 'transparent']);
        $this->assertStringContainsString("service ServiceName gemport 1\n", $transparent."\n");
        $this->assertStringNotContainsString('cos 0 vlan', $transparent);
    }
}
