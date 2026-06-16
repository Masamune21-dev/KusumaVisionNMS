<?php

namespace App\Services\Telegram;

use App\Models\SmartOltOnuRegistration;
use App\Models\SnmpOlt;
use App\Support\SmartOltSupport;

/**
 * Read-only queries over the cached OLT poll snapshots (snmp_olts.last_test_result →
 * port_onus) for the interactive Telegram bot. No SNMP/CLI is triggered here; the bot
 * answers from the same cache the dashboard reads.
 *
 * Also the single home for the bot's RX (attenuation) and LOS classification so every
 * screen labels an ONU the same way.
 */
class TelegramOnuQueryService
{
    /**
     * RX attenuation thresholds (dBm). Lower (more negative) = weaker signal = higher
     * attenuation. A reading at/below WARN is flagged; at/below CRIT is severe; at/above
     * HIGH means too much power (overload). Tiered per product decision.
     */
    public const RX_WARN_DBM = -25.0;

    public const RX_CRIT_DBM = -28.0;

    public const RX_HIGH_DBM = -8.0;

    /** last_down_cause / phase_state values that count as a genuine LOS-type outage. */
    private const LOS_CAUSES = ['los', 'losi', 'dyinggasp'];

    /**
     * Per-OLT summary list for the OLT-list / counters screens.
     *
     * @return array<int, array{id:int, name:string, reachable:bool, total:int, online:int, offline:int, los:int, rx_alert:int}>
     */
    public function olts(): array
    {
        return SnmpOlt::query()
            ->orderBy('name')
            ->get(['id', 'name', 'last_test_result'])
            ->map(fn (SnmpOlt $olt) => $this->summariseOlt($olt))
            ->all();
    }

    /**
     * @return array{id:int, name:string, reachable:bool, total:int, online:int, offline:int, los:int, rx_alert:int}|null
     */
    public function oltSummary(int $oltId): ?array
    {
        $olt = SnmpOlt::query()->find($oltId, ['id', 'name', 'last_test_result']);

        return $olt ? $this->summariseOlt($olt) : null;
    }

    public function findOlt(int $oltId): ?SnmpOlt
    {
        return SnmpOlt::query()->find($oltId);
    }

    /**
     * PON ports of one OLT with per-port counts, sorted by slot/port.
     *
     * @return array<int, array{slot:int, port:int, label:string, total:int, online:int, offline:int, los:int, rx_alert:int}>
     */
    public function ports(int $oltId): array
    {
        $olt = SnmpOlt::query()->find($oltId, ['id', 'last_test_result']);
        if ($olt === null) {
            return [];
        }

        $ports = [];
        foreach (($olt->last_test_result['port_onus'] ?? []) as $port) {
            $onus = $port['onus'] ?? [];
            if ($onus === [] && (int) ($port['count'] ?? 0) === 0) {
                continue;
            }

            $slot = (int) ($port['slot'] ?? 0);
            $pon = (int) ($port['port'] ?? 0);
            $ports[] = [
                'slot' => $slot,
                'port' => $pon,
                'label' => "PON {$slot}/{$pon}",
                ...$this->tally($onus),
            ];
        }

        usort($ports, fn ($a, $b) => [$a['slot'], $a['port']] <=> [$b['slot'], $b['port']]);

        return $ports;
    }

    /**
     * Normalised ONUs on one PON port, sorted by onu id.
     *
     * @return array<int, array<string, mixed>>
     */
    public function portOnus(int $oltId, int $slot, int $port): array
    {
        $olt = SnmpOlt::query()->find($oltId, ['id', 'name', 'last_test_result']);
        if ($olt === null) {
            return [];
        }

        $entry = data_get($olt->last_test_result, 'port_onus.'.$slot.'_'.$port, []);
        $onus = array_map(
            fn (array $onu) => $this->normalise($olt, $onu),
            $entry['onus'] ?? [],
        );

        usort($onus, fn ($a, $b) => $a['onu_id'] <=> $b['onu_id']);

        return $onus;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function onu(int $oltId, int $slot, int $port, int $onuId): ?array
    {
        foreach ($this->portOnus($oltId, $slot, $port) as $onu) {
            if ($onu['onu_id'] === $onuId) {
                return $onu;
            }
        }

        return null;
    }

    /**
     * Offline ONUs (scope 0 = all OLTs, else one OLT). LOS-type causes sort first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function losOnus(int $scope = 0): array
    {
        $onus = array_filter(
            $this->collect($scope),
            fn (array $onu) => ! $onu['online'],
        );

        usort($onus, function (array $a, array $b) {
            // Genuine LOS/dying-gasp first, then by OLT / interface for stability.
            return [! $a['los_cause'], $a['olt_name'], $a['slot'], $a['port'], $a['onu_id']]
                <=> [! $b['los_cause'], $b['olt_name'], $b['slot'], $b['port'], $b['onu_id']];
        });

        return array_values($onus);
    }

    /**
     * ONUs whose RX power is out of the safe band (scope 0 = all OLTs, else one OLT).
     * Worst first: critical attenuation, then warning, then overload; ties by RX asc.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rxOnus(int $scope = 0): array
    {
        $onus = array_filter(
            $this->collect($scope),
            fn (array $onu) => self::rxIsAlert($onu['rx_power_dbm']),
        );

        usort($onus, function (array $a, array $b) {
            $rank = [
                self::severityOrder(self::rxSeverity($a['rx_power_dbm'])),
                $a['rx_power_dbm'],
            ];
            $other = [
                self::severityOrder(self::rxSeverity($b['rx_power_dbm'])),
                $b['rx_power_dbm'],
            ];

            return $rank <=> $other;
        });

        return array_values($onus);
    }

    // --- classification & presentation (single source of truth) ---

    /**
     * @return 'none'|'ok'|'warning'|'critical'|'high'
     */
    public static function rxSeverity(?float $rx): string
    {
        if ($rx === null) {
            return 'none';
        }
        if ($rx <= self::RX_CRIT_DBM) {
            return 'critical';
        }
        if ($rx <= self::RX_WARN_DBM) {
            return 'warning';
        }
        if ($rx >= self::RX_HIGH_DBM) {
            return 'high';
        }

        return 'ok';
    }

    public static function rxIsAlert(?float $rx): bool
    {
        return in_array(self::rxSeverity($rx), ['warning', 'critical', 'high'], true);
    }

    /**
     * Signal-strength bar, e.g. "▰▰▰▱▱". A too-hot reading shows full bars (flagged by icon).
     */
    public static function rxBars(?float $rx): string
    {
        if ($rx === null) {
            return '▱▱▱▱▱';
        }

        $filled = match (true) {
            $rx >= self::RX_HIGH_DBM => 5,
            $rx >= -15 => 5,
            $rx >= -20 => 4,
            $rx >= -24 => 3,
            $rx >= -26 => 2,
            $rx > self::RX_CRIT_DBM => 1,
            default => 0,
        };

        return str_repeat('▰', $filled).str_repeat('▱', 5 - $filled);
    }

    /**
     * One-glance status icon used in lists.
     *
     * @param  array<string, mixed>  $onu
     */
    public static function statusIcon(array $onu): string
    {
        if (! ($onu['online'] ?? false)) {
            return ($onu['los_cause'] ?? false) ? '🔴' : '⚫';
        }

        return match (self::rxSeverity($onu['rx_power_dbm'] ?? null)) {
            'critical', 'high' => '🟠',
            'warning' => '🟡',
            default => '✅',
        };
    }

    /**
     * Human RX label, e.g. "▰▰▱▱▱ -27.8 dBm 🟡" or "RX belum terukur".
     *
     * @param  array<string, mixed>  $onu
     */
    public static function rxLine(array $onu): string
    {
        $rx = $onu['rx_power_dbm'] ?? null;
        if ($rx === null) {
            return 'RX belum terukur';
        }

        $icon = match (self::rxSeverity($rx)) {
            'critical' => ' 🔴',
            'warning' => ' 🟡',
            'high' => ' 🟠',
            default => '',
        };
        $label = $onu['rx_power_label'] ?: (round((float) $rx, 1).' dBm');

        return self::rxBars($rx).' '.$label.$icon;
    }

    /**
     * Resolve customer name with the registration fallback (DB hit — use for single ONU).
     *
     * @param  array<string, mixed>  $onu
     */
    public function customerFor(int $oltId, array $onu): ?string
    {
        if (($name = SmartOltSupport::customerNameFromOnu($onu)) !== null) {
            return $name;
        }

        $serial = (string) ($onu['serial_number'] ?? '');
        if ($serial === '') {
            return null;
        }

        $registration = SmartOltOnuRegistration::query()
            ->where('snmp_olt_id', $oltId)
            ->where('serial_number', $serial)
            ->orderByDesc('created_at')
            ->first(['customer_name', 'serial_number']);

        return $registration
            ? SmartOltSupport::cleanCustomerName($registration->customer_name, $serial)
            : null;
    }

    // --- internals ---

    private static function severityOrder(string $severity): int
    {
        return match ($severity) {
            'critical' => 0,
            'warning' => 1,
            'high' => 2,
            default => 3,
        };
    }

    /**
     * @return array{id:int, name:string, reachable:bool, total:int, online:int, offline:int, los:int, rx_alert:int}
     */
    private function summariseOlt(SnmpOlt $olt): array
    {
        $result = $olt->last_test_result ?? [];
        $tally = ['total' => 0, 'online' => 0, 'offline' => 0, 'los' => 0, 'rx_alert' => 0];

        foreach (($result['port_onus'] ?? []) as $port) {
            foreach ($this->tally($port['onus'] ?? []) as $key => $value) {
                $tally[$key] += $value;
            }
        }

        return [
            'id' => $olt->id,
            'name' => (string) $olt->name,
            'reachable' => (bool) ($result['ok'] ?? false),
            ...$tally,
        ];
    }

    /**
     * Count total/online/offline/los/rx_alert for a list of raw ONUs.
     *
     * @param  array<int, array<string, mixed>>  $onus
     * @return array{total:int, online:int, offline:int, los:int, rx_alert:int}
     */
    private function tally(array $onus): array
    {
        $total = $online = $los = $rxAlert = 0;

        foreach ($onus as $onu) {
            $total++;
            $isOnline = (bool) ($onu['online'] ?? false);
            $online += $isOnline ? 1 : 0;

            if (! $isOnline && $this->losCause($onu)) {
                $los++;
            }

            $rx = $onu['rx_power_dbm'] ?? $onu['rx_power'] ?? $onu['rx'] ?? null;
            if (self::rxIsAlert(is_numeric($rx) ? (float) $rx : null)) {
                $rxAlert++;
            }
        }

        return [
            'total' => $total,
            'online' => $online,
            'offline' => max($total - $online, 0),
            'los' => $los,
            'rx_alert' => $rxAlert,
        ];
    }

    /**
     * Flatten every cached ONU (optionally one OLT) into normalised rows.
     *
     * @return array<int, array<string, mixed>>
     */
    public function allOnus(int $scope = 0): array
    {
        return $this->collect($scope);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collect(int $scope): array
    {
        $query = SnmpOlt::query()->orderBy('name');
        if ($scope > 0) {
            $query->where('id', $scope);
        }

        $rows = [];
        foreach ($query->get(['id', 'name', 'last_test_result']) as $olt) {
            foreach (($olt->last_test_result['port_onus'] ?? []) as $port) {
                foreach (($port['onus'] ?? []) as $onu) {
                    $rows[] = $this->normalise($olt, $onu);
                }
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $onu
     * @return array<string, mixed>
     */
    private function normalise(SnmpOlt $olt, array $onu): array
    {
        $rx = $onu['rx_power_dbm'] ?? $onu['rx_power'] ?? $onu['rx'] ?? null;

        return [
            'olt_id' => $olt->id,
            'olt_name' => (string) $olt->name,
            'slot' => (int) ($onu['slot'] ?? 0),
            'port' => (int) ($onu['port'] ?? 0),
            'onu_id' => (int) ($onu['onu_id'] ?? 0),
            'interface' => $onu['interface'] ?? null,
            'serial_number' => $onu['serial_number'] ?? null,
            'name' => $onu['name'] ?? null,
            'online' => (bool) ($onu['online'] ?? false),
            'phase_state' => $onu['phase_state'] ?? 'Unknown',
            'last_down_cause' => $onu['last_down_cause'] ?? null,
            'los_cause' => $this->losCause($onu),
            'rx_power_dbm' => is_numeric($rx) ? (float) $rx : null,
            'rx_power_label' => $onu['rx_power_label'] ?? null,
            'customer' => SmartOltSupport::customerNameFromOnu($onu),
        ];
    }

    /**
     * Whether an ONU's down state is a genuine LOS / dying-gasp (vs admin disable).
     *
     * @param  array<string, mixed>  $onu
     */
    private function losCause(array $onu): bool
    {
        $cause = strtolower((string) ($onu['last_down_cause'] ?? ''));
        $phase = strtolower((string) ($onu['phase_state'] ?? ''));

        return in_array($cause, self::LOS_CAUSES, true) || in_array($phase, self::LOS_CAUSES, true);
    }
}
