<?php

namespace App\Services;

use App\Support\SmartOltSupport;

class ZteProvisioningScriptBuilder
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function build(array $data): string
    {
        $slot = (int) $data['slot'];
        $port = (int) $data['port'];
        $onuId = (int) $data['onu_id'];
        $sn = strtoupper((string) $data['serial_number']);
        $name = trim((string) $data['customer_name']);
        $onuType = strtoupper((string) ($data['onu_type'] ?? 'ALL-ONT'));
        $tcontProfile = (string) ($data['tcont_profile'] ?? 'SERVER');
        $vlan = (int) $data['vlan'];
        $serviceName = (string) ($data['service_name'] ?? 'ServiceName');
        $wanLine = $this->wanLine($data, $name);
        $description = "{$onuId}\$\${$name}\$\$";
        $isC600 = (bool) ($data['is_c600'] ?? false);
        $oltIface = SmartOltSupport::gponOltInterface($slot, $port, $isC600);
        $onuIface = SmartOltSupport::onuInterfaceId($slot, $port, $onuId, $isC600);

        $lines = [
            'conf t',
            '',
            "interface {$oltIface}",
            "onu {$onuId} type {$onuType} sn {$sn}",
            'exit',
            '',
            "interface {$onuIface}",
            "name {$name}",
        ];

        // C600 has no separate description OID; omit from script
        if (! $isC600) {
            $lines[] = "description {$description}";
        }

        $lines = array_merge($lines, [
            "tcont 1 name 1 profile {$tcontProfile}",
            'gemport 1 name 1 tcont 1',
            'encrypt 1 enable downstream',
            "service-port 1 vport 1 user-vlan {$vlan} vlan {$vlan}",
            'exit',
            '',
            "pon-onu-mng {$onuIface}",
            "service {$serviceName} gemport 1 cos 0 vlan {$vlan}",
            ...$this->tr069Lines($data),
            $wanLine,
            $this->remoteOntLine($data),
            'wan-ip 1 ping-response enable traceroute-response enable',
            'exit',
        ]);

        return implode("\n", array_filter($lines, fn (?string $line) => $line !== null));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function tr069Lines(array $data): array
    {
        if (! filter_var($data['tr069_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return [];
        }

        return [
            'tr069-mgmt 1 state unlock',
            sprintf(
                'tr069-mgmt 1 acs %s validate basic username %s password %s',
                $data['acs_url'],
                $data['acs_username'],
                $data['acs_password'],
            ),
        ];
    }

    /**
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
     * @param  array<string, mixed>  $data
     */
    private function wanLine(array $data, string $name): string
    {
        $mode = (string) ($data['wan_mode'] ?? 'pppoe');
        $vlanProfile = trim((string) ($data['vlan_profile'] ?? ''));

        if ($mode === 'dhcp') {
            $line = 'wan-ip 1 mode dhcp';
        } elseif ($mode === 'static') {
            $line = sprintf(
                'wan-ip 1 mode static ip-profile %s ip-address %s mask %s',
                $data['ip_profile'],
                $data['static_ip'],
                $data['static_netmask'],
            );
        } else {
            $username = trim((string) ($data['pppoe_username'] ?? '')) ?: $this->defaultCredential($name);
            $password = trim((string) ($data['pppoe_password'] ?? '')) ?: $username;
            $line = "wan-ip 1 mode pppoe username {$username} password {$password}";
        }

        if ($vlanProfile !== '') {
            $line .= " vlan-profile {$vlanProfile}";
        }

        return $line.' host 1';
    }

    private function defaultCredential(string $name): string
    {
        $value = strtolower(preg_replace('/[^A-Za-z0-9]+/', '', $name) ?? '');

        return substr($value !== '' ? $value : 'customer', 0, 32);
    }
}
