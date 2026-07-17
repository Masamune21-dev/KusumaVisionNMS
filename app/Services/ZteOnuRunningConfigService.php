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
     * Read several ONUs' running-config in a SINGLE telnet session, then split
     * the combined output per interface and parse each. This is the "ringan"
     * path behind batch copy: one login + one pager instead of one session per
     * ONU (a 72-ONU read drops from 72 sessions to 1).
     *
     * @param  array<int, int>  $onuIds
     * @return array{ok:bool, error:string|null, onus:array<int, array{ok:bool, config:array<string,mixed>, raw:string}>}
     */
    public function fetchMany(SnmpOlt $olt, int $slot, int $port, array $onuIds): array
    {
        $isC600 = SmartOltSupport::isC600($olt);
        $ifaceById = [];
        $script = ['terminal length 0'];

        foreach ($onuIds as $id) {
            $id = (int) $id;
            $iface = SmartOltSupport::onuInterfaceId($slot, $port, $id, $isC600);
            $ifaceById[$id] = strtolower($iface);
            $script[] = "show running-config interface {$iface}";
            $script[] = "show onu running config {$iface}";
        }

        $result = $this->executor->execute($olt, implode("\n", $script));
        $raw = CliOutputSanitizer::clean($result['output']);
        $rawByIface = $this->segmentByInterface($raw);

        $onus = [];
        foreach ($ifaceById as $id => $iface) {
            $chunk = $rawByIface[$iface] ?? '';
            $config = $this->parse($chunk);
            $onus[$id] = [
                'ok' => $result['ok'] && $this->looksConfigured($config),
                'config' => $config,
                'raw' => $chunk,
            ];
        }

        return [
            'ok' => $result['ok'],
            'error' => $result['error'] === null ? null : CliOutputSanitizer::clean($result['error']),
            'onus' => $onus,
        ];
    }

    /**
     * Split a combined multi-ONU dump into per-interface chunks, keyed by the
     * lowercased onu interface. Each `show running-config interface gpon-onu_…`
     * command echo starts a new chunk; the following `show onu running config …`
     * output falls into the same chunk (its command isn't a split point).
     *
     * @return array<string, string>
     */
    private function segmentByInterface(string $raw): array
    {
        $parts = preg_split(
            // Terima ejaan C300/C320 `gpon-onu_` maupun C600 `gpon_onu-` (dash/underscore ditukar).
            '/show running-config interface (gpon[-_]onu[-_]\S+)/i',
            $raw,
            -1,
            PREG_SPLIT_DELIM_CAPTURE,
        ) ?: [];

        $out = [];
        for ($i = 1; $i < count($parts); $i += 2) {
            $iface = strtolower(trim($parts[$i]));
            $out[$iface] = ($out[$iface] ?? '')."\n".($parts[$i + 1] ?? '');
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function looksConfigured(array $config): bool
    {
        return ($config['name'] ?? null) !== null
            || ($config['tconts'] ?? []) !== []
            || ($config['service_ports'] ?? []) !== []
            || ($config['services'] ?? []) !== []
            || ($config['wan_ips'] ?? []) !== [];
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
            'wan_ips' => [],
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
        ksort($config['wan_ips']);
        $config['wan_ips'] = array_values($config['wan_ips']);
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

        // OLT gaya bridge (mis. Bulumanis Lor) menyimpan bentuk panjang
        // `gemport 1 name 1 unicast tcont 1 dir both`; OLT routed memakai bentuk
        // pendek `gemport 1 name 1 tcont 1`. Token `unicast` (opsional) & `dir …`
        // (trailing) diabaikan — keduanya dinormalisasi firmware saat re-emit.
        if (preg_match('/^gemport\s+(\d+)\s+name\s+(\S+)\s+(?:unicast\s+|multicast\s+|broadcast\s+)?tcont\s+(\d+)/i', $line, $m)) {
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

        if (preg_match('/^wan\s+(\d+)\s+(.*)$/i', $line, $m)) {
            $config['wan_services'][] = $this->parseWanService((int) $m[1], $m[2]);

            return;
        }

        if (preg_match('/^wan-ip\s+(\d+)\s+mode\s+(pppoe|dhcp|static)(.*)$/i', $line, $m)) {
            $this->applyWanIpMode($config, (int) $m[1], strtolower($m[2]), $m[3]);

            return;
        }

        if (preg_match('/^wan-ip\s+(\d+)\s+.*(?:ping-response|traceroute-response)/i', $line, $m)) {
            $ping = preg_match('/ping-response\s+(enable|disable)/i', $line, $mm) && strtolower($mm[1]) === 'enable';
            $trace = preg_match('/traceroute-response\s+(enable|disable)/i', $line, $mm) && strtolower($mm[1]) === 'enable';
            $this->applyWanIpProbe($config, (int) $m[1], $ping, $trace);

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
    private function applyWanIpMode(array &$config, int $id, string $mode, string $rest): void
    {
        $entry = $this->ensureWanIp($config, $id);
        $entry['mode'] = $mode;

        if ($mode === 'pppoe' && preg_match('/username\s+(\S+)\s+password\s+(\S+)/i', $rest, $m)) {
            $entry['pppoe_username'] = $m[1];
            $entry['pppoe_password'] = $m[2];
        }

        if ($mode === 'static') {
            if (preg_match('/ip-profile\s+(\S+)/i', $rest, $m)) {
                $entry['ip_profile'] = $m[1];
            }
            if (preg_match('/ip-address\s+(\S+)/i', $rest, $m)) {
                $entry['static_ip'] = $m[1];
            }
            if (preg_match('/mask\s+(\S+)/i', $rest, $m)) {
                $entry['static_mask_length'] = $this->maskToLength($m[1]);
            }
        }

        if (preg_match('/vlan-profile\s+(\S+)/i', $rest, $m)) {
            $entry['vlan_profile'] = $m[1];
        }

        if (preg_match('/host\s+(\d+)/i', $rest, $m)) {
            $entry['host'] = (int) $m[1];
        }

        $config['wan_ips'][$id] = $entry;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function applyWanIpProbe(array &$config, int $id, bool $ping, bool $trace): void
    {
        $entry = $this->ensureWanIp($config, $id);
        $entry['ping_response'] = $ping;
        $entry['traceroute_response'] = $trace;

        $config['wan_ips'][$id] = $entry;
    }

    /**
     * Fetch the existing WAN-IP entry for an index or seed a fresh one with defaults.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function ensureWanIp(array $config, int $id): array
    {
        return $config['wan_ips'][$id] ?? [
            'id' => $id,
            'mode' => 'pppoe',
            'vlan_profile' => null,
            'pppoe_username' => null,
            'pppoe_password' => null,
            'ip_profile' => null,
            'static_ip' => null,
            'static_mask_length' => null,
            'host' => 1,
            'ping_response' => false,
            'traceroute_response' => false,
        ];
    }

    /**
     * Parse a `wan {id} ...` binding line. NetNumen emits these with optional,
     * loosely-ordered tokens (e.g. `wan 2 service other mvlan 1001`), so each
     * field is matched independently rather than with one rigid pattern.
     *
     * @return array<string, mixed>
     */
    private function parseWanService(int $id, string $rest): array
    {
        $service = '';
        if (preg_match('/\bservice\s+(.+?)(?=\s+(?:ethuni|ssid|mvlan|host|max-frame-size|tag|cos|gemport)\b|$)/i', $rest, $m)) {
            $service = trim($m[1]);
        }

        // mvlan/host = id numerik; ethuni/ssid = daftar UNI/SSID (angka, koma, range).
        // Pola ketat ini mencegah teks lain (mis. baris trailing device yang ikut
        // tergabung saat unwrap) mencemari nilai — "mvlan 1001The" → "1001".
        return [
            'id' => $id,
            'services' => $this->normalizeServiceTypes($service),
            'mvlan' => $this->matchToken($rest, '/\bmvlan\s+(\d+)/i'),
            'ethuni' => $this->matchToken($rest, '/\bethuni\s+([\d,\-]+)/i'),
            'ssid' => $this->matchToken($rest, '/\bssid\s+([\d,\-]+)/i'),
            'host' => $this->matchToken($rest, '/\bhost\s+(\d+)/i'),
        ];
    }

    private function matchToken(string $haystack, string $pattern): ?string
    {
        return preg_match($pattern, $haystack, $m) ? $m[1] : null;
    }

    /**
     * Normalize a service-type string (`internet tr069 other`) into a canonical,
     * de-duplicated list of known WAN service types.
     *
     * @return array<int, string>
     */
    private function normalizeServiceTypes(string $value): array
    {
        $allowed = ['internet', 'tr069', 'voip', 'other'];
        $tokens = preg_split('/[\s,]+/', strtolower(trim($value))) ?: [];

        return array_values(array_filter($allowed, static fn (string $type): bool => in_array($type, $tokens, true)));
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
            $trimmed = trim($rawLine);

            if ($trimmed === '' || $this->isNoise($trimmed)) {
                continue;
            }

            $first = strtolower(strtok($trimmed, ' '));

            if (! in_array($first, $keywords, true) && $lines !== []) {
                // Continuation of a wrapped directive. ZTE hard-wraps long config
                // lines at the terminal width WITHOUT inserting a separator, so a
                // value token can be split mid-string (e.g. "KSM" + "-PPPOE-VLAN-125").
                // Concatenate the *raw* fragments verbatim: since the device only
                // injected a newline into the original character stream, gluing the
                // pieces back (no extra space) reconstructs the line exactly —
                // boundary spaces that fell at the wrap are preserved on the
                // fragments themselves. Continuation lines are not re-indented.
                $lines[array_key_last($lines)] .= $rawLine;

                continue;
            }

            $lines[] = $rawLine;
        }

        return array_map(static fn (string $line): string => trim($line), $lines);
    }

    private function isNoise(string $line): bool
    {
        // `\S*[#>]` menangkap baris prompt/echo perintah ZTE di mana pun penanda
        // prompt (#/>) berada di token pertama — mis. `ZXAN#`, `ZXAN>`,
        // `ZXAN(config)#`, `ZXAN#exit` (prompt + echo `exit` saat logout), dan
        // `> show onu running config …`. Tanpa ini, `ZXAN#exit` bukan noise → logika
        // continuation menge-lem-nya ke direktif terakhir (mis. `mode hybrid` →
        // `mode hybridZXAN#exit`), bikin nilai field rusak. Baris keyword config
        // (name/tcont/gemport/service/vlan/…) tak mengandung #/> di token pertama.
        // `(the )?configuration is changed` = pesan konfirmasi/simpan sesi ZTE.
        return (bool) preg_match(
            '/^(!|end|building configuration|(the\s+)?configuration\s+is\s+changed|interface\s+gpon[-_]onu[-_]|pon-onu-mng\s+gpon[-_]onu[-_]|---\s*show|show\s+|exit|conf\s+t|configure\s+terminal|\S*[#>])/i',
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

        // VEIP (Virtual Ethernet Interface Point) — token ZTE `veip_{N}` tanpa `0/`
        // (mis. `vlan port veip_1 mode hybrid` pada ONU routed/bridge HGU).
        if (preg_match('/^veip_(\d+)/i', $token, $m)) {
            return ['veip', (int) $m[1]];
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
