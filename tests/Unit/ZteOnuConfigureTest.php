<?php

namespace Tests\Unit;

use App\Models\SnmpOlt;
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
  tr069-mgmt 1 acs http://acs.example.net:7547 validate basic username acsuser password acspass123!
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

        $this->assertCount(1, $config['wan_ips']);
        $this->assertSame(1, $config['wan_ips'][0]['id']);
        $this->assertSame('pppoe', $config['wan_ips'][0]['mode']);
        $this->assertSame('serversemampir', $config['wan_ips'][0]['pppoe_username']);
        $this->assertSame('rahasia', $config['wan_ips'][0]['pppoe_password']);
        $this->assertSame('PPPOEPATI', $config['wan_ips'][0]['vlan_profile']);
        $this->assertFalse($config['wan_ips'][0]['ping_response']);
        $this->assertFalse($config['wan_ips'][0]['traceroute_response']);

        $this->assertTrue($config['tr069']);
        $this->assertSame('acsuser', $config['acs_username']);

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

        $this->assertCount(1, $config['wan_ips']);
        $this->assertSame('static', $config['wan_ips'][0]['mode']);
        $this->assertSame('INTERNET', $config['wan_ips'][0]['ip_profile']);
        $this->assertSame('10.0.0.5', $config['wan_ips'][0]['static_ip']);
        $this->assertSame(24, $config['wan_ips'][0]['static_mask_length']);
    }

    public function test_parses_wan_ip_value_wrapped_mid_token(): void
    {
        // ZTE hard-wraps long config lines at the terminal width, splitting a
        // value token mid-string ("KSM" + "-PPPOE-VLAN-125"). The parser must
        // glue the fragments back without inserting a stray space.
        $config = $this->parser()->parse(
            "pon-onu-mng gpon-onu_1/2/1:3\n".
            "  wan-ip 1 mode pppoe username gudangkulon password \n".
            "gudangkulon vlan-profile KSM\n".
            "-PPPOE-VLAN-125 host 1\n"
        );

        $this->assertCount(1, $config['wan_ips']);
        $this->assertSame('gudangkulon', $config['wan_ips'][0]['pppoe_username']);
        $this->assertSame('gudangkulon', $config['wan_ips'][0]['pppoe_password']);
        $this->assertSame('KSM-PPPOE-VLAN-125', $config['wan_ips'][0]['vlan_profile']);
    }

    public function test_parses_wan_ip_wrapped_at_space_boundary(): void
    {
        // Wrap that falls on a space: the device keeps the space character on the
        // fragment, so verbatim concatenation still rebuilds separate tokens.
        $config = $this->parser()->parse(
            "pon-onu-mng gpon-onu_1/2/1:3\n".
            "  wan-ip 1 mode pppoe username ahmadkhoironketanen \n".
            "password ahmadkhoironketanen \n".
            "vlan-profile KSM-PPPOE-VLAN-125 host 1\n"
        );

        $this->assertSame('ahmadkhoironketanen', $config['wan_ips'][0]['pppoe_username']);
        $this->assertSame('ahmadkhoironketanen', $config['wan_ips'][0]['pppoe_password']);
        $this->assertSame('KSM-PPPOE-VLAN-125', $config['wan_ips'][0]['vlan_profile']);
    }

    public function test_parses_flexible_wan_binding(): void
    {
        // NetNumen emits sparse `wan` lines (optional, loosely-ordered tokens).
        // The old rigid parser missed `wan 2 service other mvlan 1001`.
        $config = $this->parser()->parse(
            "pon-onu-mng gpon-onu_1/2/3:3\n".
            "  wan 1 service internet tr069 voip ethuni 1,2 ssid 1 host 1\n".
            "  wan 2 service other mvlan 1001\n"
        );

        $this->assertCount(2, $config['wan_services']);

        $this->assertSame(1, $config['wan_services'][0]['id']);
        $this->assertSame(['internet', 'tr069', 'voip'], $config['wan_services'][0]['services']);
        $this->assertSame('1,2', $config['wan_services'][0]['ethuni']);
        $this->assertSame('1', $config['wan_services'][0]['host']);

        $this->assertSame(2, $config['wan_services'][1]['id']);
        $this->assertSame(['other'], $config['wan_services'][1]['services']);
        $this->assertSame('1001', $config['wan_services'][1]['mvlan']);
    }

    public function test_wan_binding_numeric_tokens_ignore_trailing_text(): void
    {
        // Trailing device text yang ikut tergabung saat unwrap tidak boleh
        // mencemari nilai numerik (mvlan/host) — "mvlan 1001The..." → 1001.
        $config = $this->parser()->parse(
            "pon-onu-mng x\n".
            "  wan 2 service other mvlan 1001\n".
            "The configuration is displayed\n"
        );

        $this->assertSame('1001', $config['wan_services'][0]['mvlan']);
        $this->assertSame(['other'], $config['wan_services'][0]['services']);
    }

    public function test_wan_binding_builds_line_with_mvlan_only_for_other(): void
    {
        $base = $this->parser()->parse("pon-onu-mng x\n  wan 2 service other mvlan 1001\n");

        // internet-only binding must NOT carry mvlan even if a stale value lingers.
        $target = $base;
        $target['wan_services'][] = ['id' => 1, 'services' => ['internet', 'tr069'], 'mvlan' => '99', 'ethuni' => '1', 'ssid' => '', 'host' => '1'];

        $delta = (new ZteOnuReconfigureScriptBuilder)->build($base, $target, ['onu_iface' => 'gpon-onu_1/2/3:3']);

        $this->assertStringContainsString('wan 1 service internet tr069 ethuni 1 host 1', $delta['script']);
        $this->assertStringNotContainsString('wan 1 service internet tr069 mvlan', $delta['script']);
        // Unchanged wan 2 must not be re-emitted.
        $this->assertStringNotContainsString('wan 2 ', $delta['script']);
    }

    public function test_wan_binding_canonical_service_order_avoids_spurious_delta(): void
    {
        $base = $this->parser()->parse("pon-onu-mng x\n  wan 1 service internet voip other mvlan 10\n");
        $target = $base;
        // Same set, different input order → canonical order means no change.
        $target['wan_services'][0]['services'] = ['other', 'internet', 'voip'];

        $delta = (new ZteOnuReconfigureScriptBuilder)->build($base, $target, ['onu_iface' => 'gpon-onu_1/2/3:3']);

        $this->assertSame('', $delta['script']);
    }

    public function test_parses_multiple_wan_ips_with_probe_responses(): void
    {
        $config = $this->parser()->parse(
            "pon-onu-mng gpon-onu_1/2/1:3\n".
            "  wan-ip 1 mode pppoe username userone password passone vlan-profile VLAN10 host 1\n".
            "  wan-ip 1 ping-response enable traceroute-response enable\n".
            "  wan-ip 2 mode dhcp host 2\n".
            "  wan-ip 2 ping-response disable traceroute-response enable\n"
        );

        $this->assertCount(2, $config['wan_ips']);

        $this->assertSame(1, $config['wan_ips'][0]['id']);
        $this->assertSame('pppoe', $config['wan_ips'][0]['mode']);
        $this->assertTrue($config['wan_ips'][0]['ping_response']);
        $this->assertTrue($config['wan_ips'][0]['traceroute_response']);

        $this->assertSame(2, $config['wan_ips'][1]['id']);
        $this->assertSame('dhcp', $config['wan_ips'][1]['mode']);
        $this->assertSame(2, $config['wan_ips'][1]['host']);
        $this->assertFalse($config['wan_ips'][1]['ping_response']);
        $this->assertTrue($config['wan_ips'][1]['traceroute_response']);
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

    public function test_service_port_modify_emits_no_then_recreate(): void
    {
        $base = $this->parser()->parse($this->sampleRaw());
        $target = $base;
        // ubah id=2 (user-vlan 100 -> 15); id=1 tetap.
        $target['service_ports'] = [
            ['id' => 1, 'vport' => 1, 'user_vlan' => 22, 'vlan' => 22],
            ['id' => 2, 'vport' => 1, 'user_vlan' => 15, 'vlan' => 15],
        ];

        $delta = (new ZteOnuReconfigureScriptBuilder)->build($base, $target, ['onu_iface' => 'gpon-onu_1/2/1:3']);

        // Modify harus hapus dulu baru buat ulang (hindari %Code 66661 already existed),
        // dengan urutan no -> service-port.
        $this->assertStringContainsString('no service-port 2', $delta['script']);
        $this->assertStringContainsString('service-port 2 vport 1 user-vlan 15 vlan 15', $delta['script']);
        $this->assertLessThan(
            strpos($delta['script'], 'service-port 2 vport 1 user-vlan 15 vlan 15'),
            strpos($delta['script'], 'no service-port 2'),
        );
        // id=1 tidak berubah → tidak boleh ada no service-port 1.
        $this->assertStringNotContainsString('no service-port 1', $delta['script']);
    }

    public function test_wan_and_toggle_deltas(): void
    {
        $base = $this->parser()->parse($this->sampleRaw());
        $target = $base;
        $target['wan_ips'][0]['pppoe_username'] = 'pelanggan_baru';
        $target['tr069'] = false;
        $target['remote_ont'] = false;

        $delta = (new ZteOnuReconfigureScriptBuilder)->build($base, $target, ['onu_iface' => 'gpon-onu_1/2/1:3']);

        $this->assertStringContainsString('wan-ip 1 mode pppoe username pelanggan_baru', $delta['script']);
        $this->assertStringContainsString('vlan-profile PPPOEPATI', $delta['script']);
        $this->assertStringContainsString('tr069-mgmt 1 state lock', $delta['script']);
        $this->assertStringContainsString('security-mgmt 212 state disable', $delta['script']);
    }

    public function test_enabling_probe_response_emits_only_probe_line(): void
    {
        $base = $this->parser()->parse($this->sampleRaw());
        $target = $base;
        // Hanya nyalakan ping/traceroute — baris mode wan-ip tidak boleh ikut ter-emit ulang.
        $target['wan_ips'][0]['ping_response'] = true;
        $target['wan_ips'][0]['traceroute_response'] = true;

        $delta = (new ZteOnuReconfigureScriptBuilder)->build($base, $target, ['onu_iface' => 'gpon-onu_1/2/1:3']);

        $this->assertStringContainsString('wan-ip 1 ping-response enable traceroute-response enable', $delta['script']);
        $this->assertStringNotContainsString('wan-ip 1 mode pppoe', $delta['script']);
    }

    public function test_adding_second_wan_ip_emits_new_lines(): void
    {
        $base = $this->parser()->parse($this->sampleRaw());
        $target = $base;
        $target['wan_ips'][] = [
            'id' => 2,
            'mode' => 'dhcp',
            'vlan_profile' => null,
            'pppoe_username' => null,
            'pppoe_password' => null,
            'ip_profile' => null,
            'static_ip' => null,
            'static_mask_length' => null,
            'host' => 2,
            'ping_response' => true,
            'traceroute_response' => false,
        ];

        $delta = (new ZteOnuReconfigureScriptBuilder)->build($base, $target, ['onu_iface' => 'gpon-onu_1/2/1:3']);

        $this->assertStringContainsString('wan-ip 2 mode dhcp host 2', $delta['script']);
        $this->assertStringContainsString('wan-ip 2 ping-response enable traceroute-response disable', $delta['script']);
        // WAN-IP 1 tidak berubah → tidak ikut ter-emit.
        $this->assertStringNotContainsString('wan-ip 1 mode', $delta['script']);
    }

    public function test_removing_wan_ip_emits_no_directive(): void
    {
        $base = $this->parser()->parse($this->sampleRaw());
        $target = $base;
        $target['wan_ips'] = [];

        $delta = (new ZteOnuReconfigureScriptBuilder)->build($base, $target, ['onu_iface' => 'gpon-onu_1/2/1:3']);

        $this->assertStringContainsString('no wan-ip 1', $delta['script']);
    }

    public function test_uni_vlan_trunk_and_transparent_omit_def_vlan_and_priority(): void
    {
        $base = $this->parser()->parse($this->sampleRaw());
        $target = $base;
        // tag → emit def-vlan + priority; trunk & transparent → keduanya di-skip.
        $target['vlan_ports'] = [
            ['port_type' => 'eth', 'port' => 1, 'mode' => 'tag', 'def_vlan' => 100, 'priority' => 3],
            ['port_type' => 'eth', 'port' => 2, 'mode' => 'trunk', 'def_vlan' => 200, 'priority' => 5],
            ['port_type' => 'eth', 'port' => 3, 'mode' => 'transparent', 'def_vlan' => 300, 'priority' => 7],
        ];

        $delta = (new ZteOnuReconfigureScriptBuilder)->build($base, $target, ['onu_iface' => 'gpon-onu_1/2/1:3']);

        $this->assertStringContainsString('vlan port eth_0/1 mode tag def-vlan 100 priority 3', $delta['script']);
        $this->assertStringContainsString('vlan port eth_0/2 mode trunk', $delta['script']);
        $this->assertStringNotContainsString('mode trunk def-vlan', $delta['script']);
        $this->assertStringContainsString('vlan port eth_0/3 mode transparent', $delta['script']);
        $this->assertStringNotContainsString('mode transparent def-vlan', $delta['script']);
        $this->assertStringNotContainsString('priority 5', $delta['script']);
        $this->assertStringNotContainsString('priority 7', $delta['script']);
    }

    public function test_uni_vlan_removal_emits_mode_na(): void
    {
        $base = $this->parser()->parse($this->sampleRaw());
        $base['vlan_ports'] = [
            ['port_type' => 'eth', 'port' => 2, 'mode' => 'tag', 'def_vlan' => 100, 'priority' => 0],
        ];
        $target = $base;
        $target['vlan_ports'] = []; // hapus baris UNI VLAN

        $delta = (new ZteOnuReconfigureScriptBuilder)->build($base, $target, ['onu_iface' => 'gpon-onu_1/2/1:3']);

        // ZTE: `mode na` invalid (20202), `no vlan port eth_0/2` saja incomplete
        // (20203) — yang diterima `no vlan port {token} mode` (mode tanpa nilai).
        $this->assertStringContainsString('no vlan port eth_0/2 mode', $delta['script']);
        $this->assertStringNotContainsString('no vlan port eth_0/2 mode tag', $delta['script']);
    }

    public function test_uni_vlan_explicit_na_mode_clears_mapping(): void
    {
        $base = $this->parser()->parse($this->sampleRaw());
        $base['vlan_ports'] = [
            ['port_type' => 'wifi', 'port' => 1, 'mode' => 'hybrid', 'def_vlan' => 10, 'priority' => 0],
        ];
        $target = $base;
        $target['vlan_ports'] = [
            ['port_type' => 'wifi', 'port' => 1, 'mode' => 'na'],
        ];

        $delta = (new ZteOnuReconfigureScriptBuilder)->build($base, $target, ['onu_iface' => 'gpon-onu_1/2/1:3']);

        // Hapus = `no vlan port wifi_0/1 mode` (tanpa nilai mode), bukan `mode na`.
        $this->assertStringContainsString('no vlan port wifi_0/1 mode', $delta['script']);
        $this->assertStringNotContainsString('mode na', $delta['script']);
        $this->assertStringNotContainsString('mode hybrid', $delta['script']);
    }

    public function test_detect_error_flags_zte_code_and_names_failing_command(): void
    {
        $ref = new \ReflectionMethod(ZteCliProvisioningExecutor::class, 'detectError');
        $ref->setAccessible(true);

        $output = "> service Hotspot gemport 1 cos 0 vlan 15\n"
            ."%Code 64007-GPONRM : Operation is forbidden for conflicting with some applied on u-profile.\n"
            .'> exit';
        $error = $ref->invoke(new ZteCliProvisioningExecutor, $output);

        $this->assertNotNull($error);
        $this->assertStringContainsString('service Hotspot gemport 1 cos 0 vlan 15', $error);
        $this->assertStringContainsString('64007', $error);
    }

    public function test_detect_error_ignores_info_banner_and_login_warning(): void
    {
        $ref = new \ReflectionMethod(ZteCliProvisioningExecutor::class, 'detectError');
        $ref->setAccessible(true);

        $output = "% The password is not strong, please change the password.\n"
            ."> conf t\n"
            ."%Info 20272: Enter configuration commands, one per line. End with CTRL/Z.\n"
            ."> interface gpon-onu_1/2/7:50\n> exit\nBMKV-C300#";

        $this->assertNull($ref->invoke(new ZteCliProvisioningExecutor, $output));
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

    public function test_service_modify_emits_no_then_recreate(): void
    {
        $base = $this->parser()->parse($this->sampleRaw());
        $target = $base;
        // ubah vlan service yang sama (22 -> 15); ONU profile-bound menolak overwrite.
        $target['services'] = [
            ['name' => 'ServiceName', 'type' => null, 'mode' => 'vlanpri', 'gem' => 1, 'cos' => 0, 'vlan' => 15],
        ];

        $delta = (new ZteOnuReconfigureScriptBuilder)->build($base, $target, ['onu_iface' => 'gpon-onu_1/2/1:3']);

        $this->assertStringContainsString('no service ServiceName', $delta['script']);
        $this->assertStringContainsString('service ServiceName gemport 1 cos 0 vlan 15', $delta['script']);
        $this->assertLessThan(
            strpos($delta['script'], 'service ServiceName gemport 1 cos 0 vlan 15'),
            strpos($delta['script'], 'no service ServiceName'),
        );
    }

    public function test_new_service_added_without_no_directive(): void
    {
        $base = $this->parser()->parse($this->sampleRaw());
        $target = $base;
        // tambah service baru "Hotspot" — tidak boleh ada "no service Hotspot".
        $target['services'] = array_merge($base['services'], [
            ['name' => 'Hotspot', 'type' => null, 'mode' => 'vlanpri', 'gem' => 1, 'cos' => 0, 'vlan' => 15],
        ]);

        $delta = (new ZteOnuReconfigureScriptBuilder)->build($base, $target, ['onu_iface' => 'gpon-onu_1/2/1:3']);

        $this->assertStringContainsString('service Hotspot gemport 1 cos 0 vlan 15', $delta['script']);
        $this->assertStringNotContainsString('no service Hotspot', $delta['script']);
    }

    public function test_build_for_copy_emits_full_registration_on_target_iface(): void
    {
        $config = $this->parser()->parse($this->sampleRaw());

        $script = (new ZteOnuReconfigureScriptBuilder)->buildForCopy($config, [
            'olt_iface' => 'gpon-olt_1/5/4',
            'onu_iface' => 'gpon-onu_1/5/4:7',
            'onu_id' => 7,
            'sn' => 'zteg12345678',
            'onu_type' => 'F660',
            'is_c600' => false,
        ]);

        // OLT-side register block (SN uppercased).
        $this->assertStringContainsString("interface gpon-olt_1/5/4\nonu 7 type F660 sn ZTEG12345678", $script);
        // Full config replayed on the new ONU interface (empty baseline → everything emitted).
        $this->assertStringContainsString('interface gpon-onu_1/5/4:7', $script);
        $this->assertStringContainsString('name Server Semampir', $script);
        $this->assertStringContainsString('description Server Semampir', $script);
        $this->assertStringContainsString('tcont 1 name 1 profile SERVER', $script);
        $this->assertStringContainsString('gemport 1 name 1 tcont 1', $script);
        $this->assertStringContainsString('encrypt 1 enable downstream', $script);
        $this->assertStringContainsString('service-port 1 vport 1 user-vlan 22 vlan 22', $script);
        $this->assertStringContainsString('pon-onu-mng gpon-onu_1/5/4:7', $script);
        $this->assertStringContainsString('service ServiceName gemport 1 cos 0 vlan 22', $script);
        $this->assertStringContainsString('wan-ip 1 mode pppoe username serversemampir password rahasia vlan-profile PPPOEPATI host 1', $script);
        $this->assertStringContainsString('tr069-mgmt 1 state unlock', $script);
        $this->assertStringContainsString('security-mgmt 212 state enable mode forward protocol web', $script);
    }

    public function test_build_for_copy_omits_wan_service_binding_line(): void
    {
        // ZTE shows `wan N service …` in running-config but rejects it as input on
        // C300 ("Invalid command key word"); buildForCopy must skip it and rely on
        // `wan-ip N mode …` to create the WAN.
        $config = $this->parser()->parse(
            "interface gpon-onu_1/2/1:3\n".
            "  name Cust\n".
            "  tcont 1 name 1 profile SERVER\n".
            "  gemport 1 name 1 tcont 1\n".
            "pon-onu-mng gpon-onu_1/2/1:3\n".
            "  service ServiceName gemport 1 cos 0 vlan 125\n".
            "  wan 1 service internet tr069 host 1\n".
            "  wan-ip 1 mode pppoe username parno password parno vlan-profile KSM host 1\n"
        );

        $script = (new ZteOnuReconfigureScriptBuilder)->buildForCopy($config, [
            'olt_iface' => 'gpon-olt_1/4/9',
            'onu_iface' => 'gpon-onu_1/4/9:1',
            'onu_id' => 1,
            'sn' => 'ZTEGC9140803',
            'onu_type' => 'ALL-ONT',
            'is_c600' => false,
        ]);

        $this->assertStringNotContainsString('wan 1 service', $script);
        $this->assertStringContainsString('wan-ip 1 mode pppoe username parno', $script);
    }

    public function test_build_for_copy_defaults_type_and_keeps_c600_description(): void
    {
        $config = $this->parser()->parse($this->sampleRaw());

        $script = (new ZteOnuReconfigureScriptBuilder)->buildForCopy($config, [
            'olt_iface' => 'gpon_olt-1/3/13',
            'onu_iface' => 'gpon_onu-1/3/13:3',
            'onu_id' => 3,
            'sn' => 'ZTEGabcdef01',
            'onu_type' => '',
            'is_c600' => true,
        ]);

        // Empty type falls back to ALL-ONT.
        $this->assertStringContainsString('onu 3 type ALL-ONT sn ZTEGABCDEF01', $script);
        // C600 does carry a separate CLI `description` — its running-config lists `name` and
        // `description` side by side under `interface gpon_onu-1/3/13:8`. Only the *SNMP* write
        // OID is missing, which is a different thing; this used to drop the line for C600.
        $this->assertStringContainsString('name Server Semampir', $script);
        $this->assertStringContainsString('description Server Semampir', $script);
    }

    public function test_fetch_many_segments_one_session_dump_per_interface(): void
    {
        // One telnet session reads several ONUs at once; fetchMany must split the
        // combined dump back into per-interface configs (the "ringan" path).
        $combined = implode("\n", [
            '> show running-config interface gpon-onu_1/2/3:5',
            'interface gpon-onu_1/2/3:5',
            '  name Cust A',
            '  tcont 1 name 1 profile SERVER',
            '  service-port 1 vport 1 user-vlan 100 vlan 100',
            '> show onu running config gpon-onu_1/2/3:5',
            'pon-onu-mng gpon-onu_1/2/3:5',
            '  service ServiceName gemport 1 cos 0 vlan 100',
            '> show running-config interface gpon-onu_1/2/3:6',
            'interface gpon-onu_1/2/3:6',
            '  name Cust B',
            '  tcont 1 name 1 profile SERVER',
            '  service-port 1 vport 1 user-vlan 200 vlan 200',
            '> show onu running config gpon-onu_1/2/3:6',
            'pon-onu-mng gpon-onu_1/2/3:6',
            '  service ServiceName gemport 1 cos 0 vlan 200',
        ]);

        $executor = new class($combined) extends ZteCliProvisioningExecutor
        {
            public function __construct(private string $out) {}

            public function execute(SnmpOlt $olt, string $script, bool $largeOutput = false): array
            {
                return ['ok' => true, 'error' => null, 'output' => $this->out];
            }
        };

        $olt = new SnmpOlt(['vendor' => 'ZTE C300', 'name' => 'BMKV-C300']);
        $result = (new ZteOnuRunningConfigService($executor))->fetchMany($olt, 2, 3, [5, 6]);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['onus'][5]['ok']);
        $this->assertSame('Cust A', $result['onus'][5]['config']['name']);
        $this->assertSame(100, $result['onus'][5]['config']['primary_vlan']);
        $this->assertTrue($result['onus'][6]['ok']);
        $this->assertSame('Cust B', $result['onus'][6]['config']['name']);
        $this->assertSame(200, $result['onus'][6]['config']['primary_vlan']);
    }

    public function test_fetch_many_segments_c600_gpon_onu_spelling(): void
    {
        // C600 mengeja interface `gpon_onu-` (dash/underscore ditukar dari C300 `gpon-onu_`).
        // segmentByInterface harus tetap memecah dump per-interface untuk C600.
        $combined = implode("\n", [
            '> show running-config interface gpon_onu-1/3/1:5',
            'interface gpon_onu-1/3/1:5',
            '  name Cust C600',
            '  tcont 1 name 1 profile SERVER',
            '  service-port 1 vport 1 user-vlan 100 vlan 100',
            '> show onu running config gpon_onu-1/3/1:5',
            'pon-onu-mng gpon_onu-1/3/1:5',
            '  service ServiceName gemport 1 cos 0 vlan 100',
            '> show running-config interface gpon_onu-1/3/1:6',
            'interface gpon_onu-1/3/1:6',
            '  name Cust C600 B',
            '  tcont 1 name 1 profile SERVER',
            '  service-port 1 vport 1 user-vlan 200 vlan 200',
            '> show onu running config gpon_onu-1/3/1:6',
            'pon-onu-mng gpon_onu-1/3/1:6',
            '  service ServiceName gemport 1 cos 0 vlan 200',
        ]);

        $executor = new class($combined) extends ZteCliProvisioningExecutor
        {
            public function __construct(private string $out) {}

            public function execute(SnmpOlt $olt, string $script, bool $largeOutput = false): array
            {
                return ['ok' => true, 'error' => null, 'output' => $this->out];
            }
        };

        $olt = new SnmpOlt(['vendor' => 'ZTE C600', 'name' => 'LAS GALERAS']);
        $result = (new ZteOnuRunningConfigService($executor))->fetchMany($olt, 3, 1, [5, 6]);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['onus'][5]['ok']);
        $this->assertSame('Cust C600', $result['onus'][5]['config']['name']);
        $this->assertSame(100, $result['onus'][5]['config']['primary_vlan']);
        $this->assertTrue($result['onus'][6]['ok']);
        $this->assertSame('Cust C600 B', $result['onus'][6]['config']['name']);
        $this->assertSame(200, $result['onus'][6]['config']['primary_vlan']);
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

    public function test_provisioning_builder_neutralizes_cli_injection_in_free_text_fields(): void
    {
        // Payload: newline + perintah config-mode diselipkan ke field teks-bebas.
        $data = [
            'slot' => 1, 'port' => 2, 'onu_id' => 3,
            'serial_number' => "ZTEGabc12345\nno onu 99",
            'customer_name' => "Budi\nno onu 5\ninterface gpon-olt_1/2/1",
            'onu_type' => 'ALL-ONT', 'tcont_profile' => 'SERVER',
            'vlan' => 125, 'service_name' => 'ServiceName', 'wan_mode' => 'pppoe',
            'pppoe_username' => "user\nreboot", 'pppoe_password' => "pass\nno onu 7",
            'tr069_enabled' => true, 'acs_url' => 'http://acs.example.net:7547',
            'acs_username' => "acs\nexit", 'acs_password' => "secret\nconf t",
        ];

        $script = (new ZteProvisioningScriptBuilder)->build($data);

        // Setiap baris skrip dikirim sebagai satu perintah telnet: tak boleh ada
        // baris yang HANYA berisi perintah suntikan.
        $lines = array_map('trim', explode("\n", $script));
        $this->assertNotContains('no onu 5', $lines);
        $this->assertNotContains('no onu 7', $lines);
        $this->assertNotContains('no onu 99', $lines);
        $this->assertNotContains('reboot', $lines);
        $this->assertNotContains('interface gpon-olt_1/2/1', $lines);
        $this->assertNotContains('conf t', array_slice($lines, 1)); // 'conf t' sah hanya sbg baris pertama

        // Nilai jadi satu-baris (newline diganti spasi), payload tak jadi perintah sendiri.
        $this->assertStringContainsString('name Budi no onu 5 interface gpon-olt_1/2/1', $script);
        $this->assertStringContainsString('sn ZTEGABC12345 NO ONU 99', $script);
    }

    /**
     * Running-config asli C320 gaya SmartOLT (EL VALLE) — verbatim dari perangkat,
     * kredensial ACS disamarkan. Beda dari PATI: `tcont N profile P` & `gemport N tcont M`
     * TANPA token `name`, `traffic-limit downstream` saja, dan model bridge flow/ip-host/veip.
     */
    private function smartOltRaw(): string
    {
        return <<<'RAW'
interface gpon-onu_1/2/1:28
  name CARLOS MANUEL DISHMEY BALBUENA
  description none
  tcont 1 profile SMARTOLT-1G-UP
  tcont 2 profile SMARTOLT-VOIPMNG-10M
  gemport 1 tcont 1
  gemport 1 traffic-limit downstream SMARTOLT-1G-DOWN
  gemport 2 tcont 2
  gemport 2 traffic-limit downstream SMARTOLT-VOIPMNG-10M
  service-port 1 vport 1 user-vlan 202 vlan 202
  service-port 2 vport 2 user-vlan 602 vlan 602
!
pon-onu-mng gpon-onu_1/2/1:28
  voip protocol sip
  flow 2 switch switch_0/1
  flow mode 1 tag-filter vlan-filter untag-filter discard
  flow 1 pri 0 vlan 202
  flow 2 pri 2 vlan 602
  gemport 1 flow 1
  gemport 2 flow 2
  switchport-bind switch_0/1 iphost 1
  switchport-bind switch_0/1 veip 1
  ip-host 2 ip 10.65.67.193 mask 255.255.252.0 gateway 10.65.64.1
  veip 1 port udp 1232 host 2
  tr069-mgmt 1 state unlock
  tr069-mgmt 1 acs http://10.69.69.1:14501 validate basic username acsuser password acspass
  security-mgmt 1 state enable mode forward protocol web https
  security-mgmt 5 state enable mode forward protocol web https
  security-mgmt 998 state enable mode forward ingress-type lan protocol web https
  security-mgmt 999 state enable ingress-type lan protocol ftp telnet ssh snmp tr069
RAW;
    }

    public function test_parses_smartolt_bridge_style_config(): void
    {
        $config = $this->parser()->parse($this->smartOltRaw());

        $this->assertSame('CARLOS MANUEL DISHMEY BALBUENA', $config['name']);

        // tcont TANPA name — dulu kosong, sekarang terbaca dgn name null.
        $this->assertCount(2, $config['tconts']);
        $this->assertSame(1, $config['tconts'][0]['id']);
        $this->assertNull($config['tconts'][0]['name']);
        $this->assertSame('SMARTOLT-1G-UP', $config['tconts'][0]['profile']);
        $this->assertSame('SMARTOLT-VOIPMNG-10M', $config['tconts'][1]['profile']);

        // gemport TANPA name + traffic-limit downstream saja.
        $this->assertCount(2, $config['gemports']);
        $this->assertNull($config['gemports'][0]['name']);
        $this->assertSame(1, $config['gemports'][0]['tcont']);
        $this->assertNull($config['gemports'][0]['traffic_up']);
        $this->assertSame('SMARTOLT-1G-DOWN', $config['gemports'][0]['traffic_down']);

        $this->assertCount(2, $config['service_ports']);
        $this->assertTrue($config['tr069']);
        $this->assertSame('acsuser', $config['acs_username']);

        // Semua security-mgmt tertangkap (bukan cuma satu).
        $this->assertCount(4, $config['security_mgmts']);
        $this->assertSame([1, 5, 998, 999], array_column($config['security_mgmts'], 'id'));

        // Baris bridge yg belum dimodelkan tersimpan mentah (tak hilang).
        $this->assertContains('ip-host 2 ip 10.65.67.193 mask 255.255.252.0 gateway 10.65.64.1', $config['extra_mgmt']);
        $this->assertContains('gemport 1 flow 1', $config['extra_mgmt']);
        $this->assertContains('veip 1 port udp 1232 host 2', $config['extra_mgmt']);
    }

    public function test_smartolt_config_round_trips_to_empty_delta(): void
    {
        // GERBANG KEAMANAN: membuka editor lalu Simpan TANPA mengubah apa pun tidak boleh
        // mengirim perintah apa pun ke OLT (kalau tidak, config pelanggan bisa rusak).
        $config = $this->parser()->parse($this->smartOltRaw());

        $delta = (new ZteOnuReconfigureScriptBuilder)->build($config, $config, ['onu_iface' => 'gpon-onu_1/2/1:28']);

        $this->assertSame('', $delta['script']);
        $this->assertSame([], $delta['changes']);
    }

    public function test_smartolt_tcont_and_gemport_changes_emit_nameless_form(): void
    {
        $base = $this->parser()->parse($this->smartOltRaw());
        $target = $base;
        $target['tconts'][0]['profile'] = 'SMARTOLT-2G-UP';          // ganti profil tcont
        $target['gemports'][0]['traffic_down'] = 'SMARTOLT-2G-DOWN'; // ganti traffic-limit down

        $delta = (new ZteOnuReconfigureScriptBuilder)->build($base, $target, ['onu_iface' => 'gpon-onu_1/2/1:28']);

        // Bentuk SmartOLT: tanpa `name`, downstream saja — bukan bentuk routed PATI.
        $this->assertStringContainsString('tcont 1 profile SMARTOLT-2G-UP', $delta['script']);
        $this->assertStringNotContainsString('tcont 1 name', $delta['script']);
        $this->assertStringContainsString('gemport 1 traffic-limit downstream SMARTOLT-2G-DOWN', $delta['script']);
        $this->assertStringNotContainsString('upstream', $delta['script']);
        // tcont 2 & gemport 2 tak berubah → tak ikut ter-emit.
        $this->assertStringNotContainsString('tcont 2', $delta['script']);
    }
}
