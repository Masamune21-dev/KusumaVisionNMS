<?php

namespace App\Services;

use App\Models\SmartOltProfile;
use App\Models\SnmpOlt;
use Illuminate\Support\Arr;

class ZteProfileCatalogService
{
    public const TYPES = ['onu_type', 'tcont', 'vlan', 'ip'];

    private const SHOW_COMMANDS = [
        'tcont' => 'show gpon profile tcont',
        'vlan' => 'show gpon onu profile vlan',
        'ip' => 'show gpon onu profile ip',
        'onu_type' => 'show onu-type',
    ];

    public function __construct(private readonly ZteCliProvisioningExecutor $executor)
    {
    }

    /**
     * @return array{count:int, output:string}
     */
    public function syncFromOlt(SnmpOlt $olt): array
    {
        $script = "terminal length 0\n".implode("\n", self::SHOW_COMMANDS);
        $result = $this->executor->execute($olt, $script);
        $profiles = $this->parse($result['output']);
        $count = 0;

        foreach ($profiles as $profile) {
            SmartOltProfile::updateOrCreate(
                [
                    'snmp_olt_id' => $olt->id,
                    'profile_type' => $profile['profile_type'],
                    'name' => $profile['name'],
                ],
                [
                    'vlan' => $profile['vlan'] ?? null,
                    'params' => $profile['params'] ?? null,
                    'notes' => $profile['notes'] ?? null,
                    'source' => $profile['source'],
                    'is_active' => true,
                    'last_synced_at' => now(),
                ],
            );

            $count++;
        }

        return [
            'count' => $count,
            'output' => $result['output'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parse(string $output): array
    {
        return [
            ...$this->parseTcont($output),
            ...$this->parseVlan($output),
            ...$this->parseIp($output),
            ...$this->parseOnuTypes($output),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function buildScript(string $action, array $data, ?string $oldName = null): string
    {
        $type = (string) $data['profile_type'];
        $name = (string) $data['name'];

        if ($action === 'delete') {
            return $this->deleteScript($type, $name);
        }

        $delete = $oldName && $oldName !== $name ? $this->deleteScript($type, $oldName)."\n\n" : '';

        return $delete.$this->addScript($type, $data);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseTcont(string $output): array
    {
        $output = $this->section($output, self::SHOW_COMMANDS['tcont']);
        preg_match_all('/Profile name\s*:\s*([^\r\n]+)(.*?)(?=Profile name\s*:|[A-Za-z0-9._-]+#\s*>|$)/is', $output, $matches, PREG_SET_ORDER);

        return collect($matches)
            ->map(function (array $match) {
                $name = trim($match[1]);
                preg_match('/^\s*(\d+)\s+\d+\s+\d+\s+(\d+)/m', $match[2], $params);

                return [
                    'profile_type' => 'tcont',
                    'name' => $name,
                    'source' => self::SHOW_COMMANDS['tcont'],
                    'params' => [
                        'type' => isset($params[1]) ? (int) $params[1] : null,
                        'maximum' => isset($params[2]) ? (int) $params[2] : null,
                    ],
                ];
            })
            ->filter(fn (array $row) => $this->validProfileName($row['name']))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseVlan(string $output): array
    {
        $output = $this->section($output, self::SHOW_COMMANDS['vlan']);
        preg_match_all('/Profile name:\s*([^\r\n]+)(.*?)(?=Profile name:|[A-Za-z0-9._-]+#\s*>|$)/is', $output, $matches, PREG_SET_ORDER);

        return collect($matches)
            ->map(function (array $match) {
                $name = trim($match[1]);
                preg_match('/CVLAN:\s*(\d+)/i', $match[2], $cvlan);
                preg_match('/Tag mode:\s*([A-Za-z0-9._-]+)/i', $match[2], $tagMode);
                preg_match('/CVLAN priority:\s*(\d+)/i', $match[2], $priority);

                if (! isset($cvlan[1])) {
                    return null;
                }

                return [
                    'profile_type' => 'vlan',
                    'name' => $name,
                    'vlan' => (int) $cvlan[1],
                    'source' => self::SHOW_COMMANDS['vlan'],
                    'params' => [
                        'tag_mode' => $tagMode[1] ?? 'tag',
                        'pri' => isset($priority[1]) ? (int) $priority[1] : 0,
                    ],
                ];
            })
            ->filter(fn (?array $row) => $row && $this->validProfileName($row['name']))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseIp(string $output): array
    {
        $output = $this->section($output, self::SHOW_COMMANDS['ip']);
        preg_match_all('/Profile name:\s*([^\r\n]+)(.*?)(?=Profile name:|[A-Za-z0-9._-]+#\s*>|$)/is', $output, $matches, PREG_SET_ORDER);

        return collect($matches)
            ->map(function (array $match) {
                $name = trim($match[1]);
                preg_match('/Gateway:\s*([0-9.]+)/i', $match[2], $gateway);

                if (! isset($gateway[1])) {
                    return null;
                }

                preg_match('/Primary DNS:\s*([0-9.]+)/i', $match[2], $primaryDns);
                preg_match('/Secondary DNS:\s*([0-9.]+)/i', $match[2], $secondaryDns);

                return [
                    'profile_type' => 'ip',
                    'name' => $name,
                    'source' => self::SHOW_COMMANDS['ip'],
                    'params' => [
                        'gateway' => $gateway[1],
                        'primary_dns' => $primaryDns[1] ?? null,
                        'secondary_dns' => $secondaryDns[1] ?? null,
                    ],
                ];
            })
            ->filter(fn (?array $row) => $row && $this->validProfileName($row['name']))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseOnuTypes(string $output): array
    {
        $output = $this->section($output, self::SHOW_COMMANDS['onu_type']);
        preg_match_all('/ONU type name:\s*([^\r\n]+)(.*?)(?=ONU type name:|[A-Za-z0-9._-]+#\s*>|$)/is', $output, $matches, PREG_SET_ORDER);

        return collect($matches)
            ->map(function (array $match) {
                $name = trim($match[1]);
                preg_match('/PON type:\s*([A-Za-z0-9._-]+)/i', $match[2], $ponType);
                preg_match('/Description:\s*([^\r\n]+)/i', $match[2], $description);

                if (strtolower($ponType[1] ?? '') !== 'gpon') {
                    return null;
                }

                return [
                    'profile_type' => 'onu_type',
                    'name' => $name,
                    'notes' => isset($description[1]) ? trim($description[1]) : null,
                    'source' => self::SHOW_COMMANDS['onu_type'],
                    'params' => [
                        'pon_type' => $ponType[1] ?? null,
                    ],
                ];
            })
            ->filter(fn (?array $row) => $row && $this->validProfileName($row['name']))
            ->values()
            ->all();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function addScript(string $type, array $data): string
    {
        $name = (string) $data['name'];
        $params = (array) ($data['params'] ?? []);

        return match ($type) {
            'onu_type' => implode("\n", array_filter([
                'conf t',
                'pon',
                sprintf('onu-type gpon %s%s', strtoupper($name), ($data['notes'] ?? null) ? ' description "'.str_replace('"', '', (string) $data['notes']).'"' : ''),
                'exit',
            ])),
            'tcont' => implode("\n", [
                'conf t',
                'gpon',
                sprintf('profile tcont %s type %d maximum %d', $name, (int) Arr::get($params, 'type', 4), (int) Arr::get($params, 'maximum', 1024000)),
                'exit',
            ]),
            'vlan' => implode("\n", [
                'conf t',
                'gpon',
                sprintf('onu profile vlan %s tag-mode %s cvlan %d pri %d', $name, Arr::get($params, 'tag_mode', 'tag'), (int) $data['vlan'], (int) Arr::get($params, 'pri', 0)),
                'exit',
            ]),
            'ip' => implode("\n", array_filter([
                'conf t',
                'gpon',
                sprintf(
                    'onu profile ip %s gateway %s%s%s',
                    $name,
                    Arr::get($params, 'gateway'),
                    Arr::get($params, 'primary_dns') ? ' primary-dns '.Arr::get($params, 'primary_dns') : '',
                    Arr::get($params, 'secondary_dns') ? ' secondary-dns '.Arr::get($params, 'secondary_dns') : '',
                ),
                'exit',
            ])),
            default => '',
        };
    }

    private function deleteScript(string $type, string $name): string
    {
        return match ($type) {
            'onu_type' => "conf t\npon\nno onu-type {$name}\nexit",
            'tcont' => "conf t\ngpon\nno profile tcont {$name}\nexit",
            'vlan' => "conf t\ngpon\nno onu profile vlan {$name}\nexit",
            'ip' => "conf t\ngpon\nno onu profile ip {$name}\nexit",
            default => '',
        };
    }

    private function validProfileName(string $name): bool
    {
        return $name !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $name) === 1;
    }

    private function section(string $output, string $command): string
    {
        $pattern = '/>\s*'.preg_quote($command, '/').'\s*(.*?)(?=\n\s*>|\r\n\s*>|$)/is';

        return preg_match($pattern, $output, $matches) ? $matches[1] : $output;
    }
}
