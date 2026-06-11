<?php

namespace App\Services;

use App\Models\SnmpOlt;
use App\Support\CliOutputSanitizer;
use App\Support\SmartOltSupport;

/**
 * Reads an existing ONU's live running-config via CLI and parses it into the
 * structured shape consumed by the Configure ONU form (see guide Section 7).
 */
class ZteOnuRunningConfigService
{
    public function __construct(private readonly ZteCliProvisioningExecutor $executor) {}

    /**
     * @return array{ok:bool, config:array<string,mixed>, raw:string, error:string|null}
     */
    public function fetch(SnmpOlt $olt, int $slot, int $port, int $onuId): array
    {
        $iface = SmartOltSupport::onuInterfaceId($slot, $port, $onuId, SmartOltSupport::isC600($olt));

        $script = implode("\n", [
            'terminal length 0',
            "show running-config interface {$iface}",
            "show onu running config {$iface}",
        ]);

        $result = $this->executor->execute($olt, $script);
        $raw = CliOutputSanitizer::clean($result['output']);

        return [
            'ok' => $result['ok'],
            'config' => $this->parse($raw),
            'raw' => $raw,
            'error' => $result['error'] === null ? null : CliOutputSanitizer::clean($result['error']),
        ];
    }

    /**
     * Parse raw CLI output into the structured config map.
     *
     * @return array<string, mixed>
     */
    public function parse(string $raw): array
    {
        $config = [
            'name' => null,
            'description' => null,
            'tconts' => [],
            'gemports' => [],
            'service_ports' => [],
            'services' => [],
            'vlan_ports' => [],
            'wan_services' => [],
            'wan_mode' => 'none',
            'vlan_profile' => null,
            'pppoe_username' => null,
            'pppoe_password' => null,
            'ip_profile' => null,
            'static_ip' => null,
            'static_mask_length' => null,
            'tr069' => false,
            'acs_url' => null,
            'acs_username' => null,
            'acs_password' => null,
            'remote_ont' => false,
            'remote_ont_id' => null,
            'remote_ont_mode' => null,
            'remote_ont_protocol' => null,
            'primary_vlan' => null,
        ];

        foreach ($this->normalizeLines($raw) as $line) {
            $this->applyLine($config, $line);
        }

        $config['tconts'] = array_values($config['tconts']);
        $config['gemports'] = array_values($config['gemports']);
        $config['primary_vlan'] = $this->derivePrimaryVlan($config);

        return $config;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function applyLine(array &$config, string $line): void
    {
        if (preg_match('/^name\s+(.+)$/i', $line, $m)) {
            $config['name'] = trim($m[1]);

            return;
        }

        if (preg_match('/^description\s+(.+)$/i', $line, $m)) {
            $config['description'] = trim($m[1]);

            return;
        }

        if (preg_match('/^tcont\s+(\d+)\s+name\s+(\S+)\s+profile\s+(\S+)/i', $line, $m)) {
            $id = (int) $m[1];
            $config['tconts'][$id] = array_merge($config['tconts'][$id] ?? ['gap' => null], [
                'id' => $id,
                'name' => $m[2],
                'profile' => $m[3],
            ]);

            return;
        }

        if (preg_match('/^tcont\s+(\d+)\s+gap\s+(\S+)/i', $line, $m)) {
            $id = (int) $m[1];
            $config['tconts'][$id] = array_merge($config['tconts'][$id] ?? ['name' => null, 'profile' => null], [
                'id' => $id,
                'gap' => $m[2],
            ]);

            return;
        }

        if (preg_match('/^gemport\s+(\d+)\s+name\s+(\S+)\s+tcont\s+(\d+)/i', $line, $m)) {
            $id = (int) $m[1];
            $config['gemports'][$id] = array_merge($config['gemports'][$id] ?? ['traffic_up' => null, 'traffic_down' => null], [
                'id' => $id,
                'name' => $m[2],
                'tcont' => (int) $m[3],
            ]);

            return;
        }

        if (preg_match('/^gemport\s+(\d+)\s+traffic-limit\s+upstream\s+(\S+)\s+downstream\s+(\S+)/i', $line, $m)) {
            $id = (int) $m[1];
            $config['gemports'][$id] = array_merge($config['gemports'][$id] ?? ['name' => null, 'tcont' => null], [
                'id' => $id,
                'traffic_up' => $m[2],
                'traffic_down' => $m[3],
            ]);

            return;
        }

        if (preg_match('/^service-port\s+(\d+)\s+vport\s+(\d+)\s+user-vlan\s+(\d+)\s+vlan\s+(\d+)/i', $line, $m)) {
            $config['service_ports'][] = [
                'id' => (int) $m[1],
                'vport' => (int) $m[2],
                'user_vlan' => (int) $m[3],
                'vlan' => (int) $m[4],
            ];

            return;
        }

        if (preg_match('/^service\s+(\S+)(?:\s+type\s+(\S+))?\s+gemport\s+(\d+)(?:\s+cos\s+(\d+)\s+vlan\s+(\d+))?/i', $line, $m)) {
            $hasVlan = isset($m[5]) && $m[5] !== '';
            $config['services'][] = [
                'name' => $m[1],
                'type' => ($m[2] ?? '') !== '' ? $m[2] : null,
                'mode' => $hasVlan ? 'vlanpri' : 'transparent',
                'gem' => (int) $m[3],
                'cos' => $hasVlan ? (int) $m[4] : 0,
                'vlan' => $hasVlan ? (int) $m[5] : null,
            ];

            return;
        }

        if (preg_match('/^vlan\s+port\s+(\S+)\s+mode\s+(\S+)(.*)$/i', $line, $m)) {
            [$portType, $portNum] = $this->splitUniPort($m[1]);
            $rest = $m[3];

            $config['vlan_ports'][] = [
                'port_type' => $portType,
                'port' => $portNum,
                'mode' => $m[2],
                'vlan' => $this->matchInt($rest, '/(?<![a-z-])vlan\s+(\d+)/i'),
                'def_vlan' => $this->matchInt($rest, '/def-vlan\s+(\d+)/i'),
                'priority' => $this->matchInt($rest, '/priority\s+(\d+)/i'),
            ];

            return;
        }

        if (preg_match('/^wan\s+(\d+)\s+ethuni\s+(\S+)\s+ssid\s+(\S+)\s+service\s+(\S+)\s+mvlan\s+(\S+)\s+host\s+(\S+)/i', $line, $m)) {
            $config['wan_services'][] = [
                'id' => (int) $m[1],
                'ethuni' => $m[2],
                'ssid' => $m[3],
                'service' => $m[4],
                'mvlan' => $m[5],
                'host' => $m[6],
            ];

            return;
        }

        if (preg_match('/^wan-ip\s+\d+\s+mode\s+(pppoe|dhcp|static)(.*)$/i', $line, $m)) {
            $this->applyWanIp($config, strtolower($m[1]), $m[2]);

            return;
        }

        if (preg_match('/^tr069-mgmt\s+\d+\s+state\s+(unlock|lock)/i', $line, $m)) {
            $config['tr069'] = strtolower($m[1]) === 'unlock';

            return;
        }

        if (preg_match('/^tr069-mgmt\s+\d+\s+acs\s+(\S+)\s+validate\s+\S+\s+username\s+(\S+)\s+password\s+(\S+)/i', $line, $m)) {
            $config['acs_url'] = $m[1];
            $config['acs_username'] = $m[2];
            $config['acs_password'] = $m[3];

            return;
        }

        if (preg_match('/^security-mgmt\s+(\d+)\s+state\s+(enable|disable)(?:\s+mode\s+(\S+))?(?:\s+protocol\s+(\S+))?/i', $line, $m)) {
            $config['remote_ont'] = strtolower($m[2]) === 'enable';
            $config['remote_ont_id'] = (int) $m[1];
            $config['remote_ont_mode'] = ($m[3] ?? '') !== '' ? $m[3] : null;
            $config['remote_ont_protocol'] = ($m[4] ?? '') !== '' ? $m[4] : null;
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function applyWanIp(array &$config, string $mode, string $rest): void
    {
        $config['wan_mode'] = $mode;

        if ($mode === 'pppoe' && preg_match('/username\s+(\S+)\s+password\s+(\S+)/i', $rest, $m)) {
            $config['pppoe_username'] = $m[1];
            $config['pppoe_password'] = $m[2];
        }

        if ($mode === 'static') {
            if (preg_match('/ip-profile\s+(\S+)/i', $rest, $m)) {
                $config['ip_profile'] = $m[1];
            }
            if (preg_match('/ip-address\s+(\S+)/i', $rest, $m)) {
                $config['static_ip'] = $m[1];
            }
            if (preg_match('/mask\s+(\S+)/i', $rest, $m)) {
                $config['static_mask_length'] = $this->maskToLength($m[1]);
            }
        }

        if (preg_match('/vlan-profile\s+(\S+)/i', $rest, $m)) {
            $config['vlan_profile'] = $m[1];
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function derivePrimaryVlan(array $config): ?int
    {
        foreach ($config['service_ports'] as $row) {
            if (($row['vlan'] ?? null) !== null) {
                return (int) $row['vlan'];
            }
        }

        foreach ($config['services'] as $row) {
            if (($row['vlan'] ?? null) !== null) {
                return (int) $row['vlan'];
            }
        }

        return null;
    }

    /**
     * Pre-process raw CLI output into clean, single-directive config lines (guide Section 7.2).
     *
     * @return array<int, string>
     */
    private function normalizeLines(string $raw): array
    {
        // Repair ZTE token line-wrap before splitting.
        $raw = preg_replace('/vlan-profi\s*\n?\s*le\b/i', 'vlan-profile', $raw) ?? $raw;
        $raw = preg_replace('/ip-profi\s*\n?\s*le\b/i', 'ip-profile', $raw) ?? $raw;
        $raw = preg_replace('/mask-\s*\n?\s*length\b/i', 'mask-length', $raw) ?? $raw;

        $keywords = [
            'name', 'description', 'tcont', 'gemport', 'service-port', 'service',
            'vlan', 'wan-ip', 'wan', 'tr069-mgmt', 'security-mgmt', 'encrypt',
        ];

        $lines = [];

        foreach (preg_split('/\r?\n/', $raw) ?: [] as $rawLine) {
            $line = trim($rawLine);

            if ($line === '' || $this->isNoise($line)) {
                continue;
            }

            $first = strtolower(strtok($line, ' '));

            if (! in_array($first, $keywords, true) && $lines !== []) {
                // Continuation of the previous wrapped directive.
                $lines[array_key_last($lines)] .= ' '.$line;

                continue;
            }

            $lines[] = $line;
        }

        return $lines;
    }

    private function isNoise(string $line): bool
    {
        return (bool) preg_match(
            '/^(!|end|building configuration|interface\s+gpon-onu_|pon-onu-mng\s+gpon-onu_|---\s*show|show\s+|exit|conf\s+t|configure\s+terminal|\S*[#>]\s*$)/i',
            $line,
        );
    }

    /**
     * @return array{0:string,1:int}
     */
    private function splitUniPort(string $token): array
    {
        if (preg_match('/^(eth|wifi)_0\/(\d+)/i', $token, $m)) {
            return [strtolower($m[1]), (int) $m[2]];
        }

        if (preg_match('/(\d+)/', $token, $m)) {
            return ['eth', (int) $m[1]];
        }

        return ['eth', 1];
    }

    private function matchInt(string $haystack, string $pattern): ?int
    {
        return preg_match($pattern, $haystack, $m) ? (int) $m[1] : null;
    }

    private function maskToLength(string $mask): ?int
    {
        if (preg_match('/^\d{1,2}$/', $mask)) {
            $value = (int) $mask;

            return $value >= 1 && $value <= 32 ? $value : null;
        }

        if (! filter_var($mask, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return null;
        }

        $long = ip2long($mask);
        $bits = sprintf('%032b', $long);

        // Must be contiguous ones followed by zeros.
        if (! preg_match('/^1*0*$/', $bits)) {
            return null;
        }

        return substr_count($bits, '1');
    }
}
