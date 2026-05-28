<?php

namespace App\Services;

use App\Models\SnmpOlt;
use App\Support\CliOutputSanitizer;
use App\Support\SmartOltSupport;

/**
 * Reads an ONU's live detail-info via CLI and parses it into display groups
 * (identity / state / optical / last_event / all), supplemented by the
 * attenuation table for optical Rx/Tx (guide Section 6).
 */
class ZteOnuDetailService
{
    public function __construct(private readonly ZteCliProvisioningExecutor $executor) {}

    /**
     * @return array{ok:bool, groups:array<string,mixed>, raw:string, error:string|null}
     */
    public function fetch(SnmpOlt $olt, int $slot, int $port, int $onuId): array
    {
        $iface = SmartOltSupport::onuInterfaceId($slot, $port, $onuId, SmartOltSupport::isC600($olt));

        $script = implode("\n", [
            'terminal length 0',
            "show gpon onu detail-info {$iface}",
            "show pon power attenuation {$iface}",
        ]);

        $result = $this->executor->execute($olt, $script);
        $raw = CliOutputSanitizer::clean($result['output']);

        return [
            'ok' => $result['ok'],
            'groups' => $this->parse($raw),
            'raw' => $raw,
            'error' => $result['error'] === null ? null : CliOutputSanitizer::clean($result['error']),
        ];
    }

    /**
     * @return array{identity:array<string,?string>, state:array<string,?string>, optical:array<string,?string>, last_event:array<string,?string>, all:array<string,string>}
     */
    public function parse(string $raw): array
    {
        $all = $this->buildAllMap($raw);

        $groups = [
            'identity' => [
                'sn' => $this->pick($all, ['sn'], [['serial']]),
                'name' => $this->pick($all, ['name'], []),
                'type' => $this->pick($all, ['type'], []),
                'auth_mode' => $this->pick($all, ['auth_mode'], []),
                'vendor_id' => $this->pick($all, ['vendor_id'], [['vendor']]),
                'equipment_id' => $this->pick($all, ['equipment_id'], [['equipment']]),
                'model_id' => $this->pick($all, ['model_id'], [['model']]),
                'hardware_version' => $this->pick($all, ['hardware_version'], [['hw', 'version']]),
                'software_version' => $this->pick($all, ['software_version'], [['sw', 'version']]),
            ],
            'state' => [
                'state' => $this->pick($all, ['state'], [['ranging', 'state'], ['oper', 'state']]),
                'admin_state' => $this->pick($all, ['admin_state'], []),
                'phase_state' => $this->pick($all, ['phase_state'], []),
                'channel' => $this->pick($all, ['channel'], []),
                'online_duration' => $this->pick($all, ['online_duration', 'online_duration_s'], [['online', 'time'], ['uptime']]),
            ],
            'optical' => [
                'rx_power_dbm' => $this->pick($all, ['rx_power_dbm'], [['rx', 'power']]),
                'tx_power_dbm' => $this->pick($all, ['tx_power_dbm'], [['tx', 'opt']]),
                'distance_m' => $this->pick($all, ['distance_m'], [['distance']]),
                'temperature_c' => $this->pick($all, ['temperature_c'], [['temperature']]),
                'voltage_v' => $this->pick($all, ['voltage_v'], [['voltage']]),
                'bias_current_ma' => $this->pick($all, ['bias_current_ma'], [['bias']]),
            ],
            'last_event' => [
                'last_down_cause' => $this->pick($all, ['last_down_cause'], [['offline', 'cause']]),
                'last_down_time' => $this->pick($all, ['last_down_time'], [['offline', 'time']]),
                'last_up_time' => $this->pick($all, ['last_up_time'], [['authpass']]),
            ],
            'all' => $all,
        ];

        $this->applyAttenuation($raw, $groups['optical']);
        $this->applySessionHistory($raw, $groups['last_event']);

        return $groups;
    }

    /**
     * @return array<string, string>
     */
    private function buildAllMap(string $raw): array
    {
        $map = [];

        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }

            // Skip attenuation rows, separators, and session-history rows handled elsewhere.
            if (preg_match('/^\s*(up|down)\b/i', $line)
                || preg_match('/^\s*-{3,}\s*$/', $line)
                || preg_match('/^\s*\d+\s+\d{4}-\d\d-\d\d/', $line)) {
                continue;
            }

            [$rawKey, $value] = explode(':', $line, 2);
            $rawKey = trim($rawKey);
            $value = trim($value);

            if ($rawKey === '' || stripos($line, 'show gpon') !== false || preg_match('/^(zxan|olt)[#>]/i', $rawKey)) {
                continue;
            }

            $key = $this->normalizeKey($rawKey);

            if ($key === '' || $value === '') {
                continue;
            }

            $map[$key] = $value;
        }

        return $map;
    }

    /**
     * @param  array<string, string>  $map
     * @param  array<int, string>  $exactKeys
     * @param  array<int, array<int, string>>  $needleSets
     */
    private function pick(array $map, array $exactKeys, array $needleSets): ?string
    {
        foreach ($exactKeys as $key) {
            if (isset($map[$key]) && $map[$key] !== '') {
                return $map[$key];
            }
        }

        foreach ($needleSets as $needles) {
            foreach ($map as $key => $value) {
                $matchesAll = true;
                foreach ($needles as $needle) {
                    if (! str_contains($key, $needle)) {
                        $matchesAll = false;
                        break;
                    }
                }
                if ($matchesAll && $value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, ?string>  $optical
     */
    private function applyAttenuation(string $raw, array &$optical): void
    {
        $num = '(-?\d+(?:\.\d+)?)';

        if (preg_match("/^\s*up\s+Rx\s*:?\s*{$num}\s*\(?dbm\)?\s+Tx\s*:?\s*{$num}\s*\(?dbm\)?\s+{$num}/im", $raw, $m)) {
            $optical['onu_tx_dbm'] = $m[2];
            $optical['att_up_db'] = $m[3];
            if (($optical['tx_power_dbm'] ?? null) === null) {
                $optical['tx_power_dbm'] = $m[2];
            }
        }

        if (preg_match("/^\s*down\s+Tx\s*:?\s*{$num}\s*\(?dbm\)?\s+Rx\s*:?\s*{$num}\s*\(?dbm\)?\s+{$num}/im", $raw, $m)) {
            $optical['onu_rx_dbm'] = $m[2];
            $optical['att_down_db'] = $m[3];
            if (($optical['rx_power_dbm'] ?? null) === null) {
                $optical['rx_power_dbm'] = $m[2];
            }
        }
    }

    /**
     * @param  array<string, ?string>  $lastEvent
     */
    private function applySessionHistory(string $raw, array &$lastEvent): void
    {
        if (($lastEvent['last_down_cause'] ?? null) !== null && ($lastEvent['last_up_time'] ?? null) !== null) {
            return;
        }

        $rows = [];
        $dt = '\d{4}-\d\d-\d\d \d\d:\d\d:\d\d';

        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            if (preg_match("/^\s*\d+\s+({$dt})\s+({$dt})\s+(\S+)/", $line, $m)) {
                $rows[] = ['authpass' => $m[1], 'offline' => $m[2], 'cause' => $m[3]];
            }
        }

        if ($rows === []) {
            return;
        }

        $currentIndex = null;
        foreach ($rows as $index => $row) {
            if (str_starts_with($row['offline'], '0000-')) {
                $currentIndex = $index;
                break;
            }
        }

        if ($currentIndex !== null) {
            $lastEvent['last_up_time'] ??= $rows[$currentIndex]['authpass'];
            if ($currentIndex > 0) {
                $prev = $rows[$currentIndex - 1];
                $lastEvent['last_down_time'] ??= $prev['offline'];
                $lastEvent['last_down_cause'] ??= $prev['cause'];
            }

            return;
        }

        $last = end($rows);
        $lastEvent['last_down_time'] ??= $last['offline'];
        $lastEvent['last_down_cause'] ??= $last['cause'];
        $lastEvent['last_up_time'] ??= $last['authpass'];
    }

    private function normalizeKey(string $key): string
    {
        $key = strtolower($key);
        $key = preg_replace('/[^a-z0-9]+/', '_', $key) ?? '';

        return trim($key, '_');
    }
}
