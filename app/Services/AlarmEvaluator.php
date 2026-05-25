<?php

namespace App\Services;

use App\Models\AlarmEvent;
use App\Models\SnmpOlt;
use Illuminate\Support\Carbon;

class AlarmEvaluator
{
    private const RX_LOW_DBM = -28.0;

    private const RX_HIGH_DBM = -8.0;

    /**
     * Evaluate the latest poll snapshot and reconcile active alarms for the OLT.
     *
     * @return array{active:int, raised:int, cleared:int}
     */
    public function evaluate(SnmpOlt $olt): array
    {
        $snapshot = $olt->last_test_result ?? [];

        if (! ($snapshot['ok'] ?? false)) {
            return $this->reconcile($olt, [
                'olt:unreachable' => [
                    'type' => 'olt_unreachable',
                    'severity' => AlarmEvent::SEVERITY_CRITICAL,
                    'scope' => 'olt',
                    'message' => 'OLT tidak dapat dihubungi: '.($snapshot['error'] ?? 'unknown error'),
                ],
            ]);
        }

        $detected = [];

        foreach ($snapshot['ports'] ?? [] as $port) {
            if (($port['oper_status'] ?? null) === 'down') {
                $slot = (int) ($port['slot'] ?? 0);
                $portNo = (int) ($port['port'] ?? 0);
                $detected["port:{$slot}/{$portNo}:port_down"] = [
                    'type' => 'port_down',
                    'severity' => AlarmEvent::SEVERITY_CRITICAL,
                    'scope' => 'port',
                    'slot' => $slot,
                    'port' => $portNo,
                    'message' => "GPON port {$port['name']} oper status down.",
                ];
            }
        }

        foreach ($snapshot['port_onus'] ?? [] as $portData) {
            foreach ($portData['onus'] ?? [] as $onu) {
                if (($onu['admin_state'] ?? null) === 'disabled') {
                    continue;
                }

                $detected += $this->onuStateAlarms($onu);
                $detected += $this->onuRxAlarm($onu);
            }
        }

        return $this->reconcile($olt, $detected);
    }

    /**
     * @param  array<string, mixed>  $onu
     * @return array<string, array<string, mixed>>
     */
    private function onuStateAlarms(array $onu): array
    {
        $key = $this->onuKey($onu);
        $base = $this->onuScopeFields($onu);
        $iface = $onu['interface'] ?? $key;
        $phase = $onu['phase_state'] ?? null;
        $lastDown = $onu['last_down_cause'] ?? null;

        if ($phase === 'DyingGasp' || $lastDown === 'DyingGasp') {
            return ["onu:{$key}:dying_gasp" => [
                ...$base,
                'type' => 'dying_gasp',
                'severity' => AlarmEvent::SEVERITY_MINOR,
                'message' => "ONU {$iface} dying gasp.",
            ]];
        }

        if ($phase === 'LOS' || $lastDown === 'LOS') {
            return ["onu:{$key}:los" => [
                ...$base,
                'type' => 'los',
                'severity' => AlarmEvent::SEVERITY_MAJOR,
                'message' => "ONU {$iface} loss of signal (LOS).",
            ]];
        }

        if (! ($onu['online'] ?? false)) {
            return ["onu:{$key}:onu_offline" => [
                ...$base,
                'type' => 'onu_offline',
                'severity' => AlarmEvent::SEVERITY_MINOR,
                'message' => "ONU {$iface} offline (phase: ".($phase ?? 'unknown').').',
            ]];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $onu
     * @return array<string, array<string, mixed>>
     */
    private function onuRxAlarm(array $onu): array
    {
        $rx = $onu['rx_power_dbm'] ?? null;

        if ($rx === null || ($rx > self::RX_LOW_DBM && $rx < self::RX_HIGH_DBM)) {
            return [];
        }

        $key = $this->onuKey($onu);
        $iface = $onu['interface'] ?? $key;

        return ["onu:{$key}:high_rx_attenuation" => [
            ...$this->onuScopeFields($onu),
            'type' => 'high_rx_attenuation',
            'severity' => AlarmEvent::SEVERITY_WARNING,
            'message' => "ONU {$iface} RX {$rx} dBm di luar rentang sehat.",
            'meta' => ['rx_power_dbm' => $rx],
        ]];
    }

    /**
     * @param  array<string, mixed>  $onu
     */
    private function onuKey(array $onu): string
    {
        $sn = $onu['serial_number'] ?? null;

        return $sn ?: sprintf('%d/%d:%d', $onu['slot'] ?? 0, $onu['port'] ?? 0, $onu['onu_id'] ?? 0);
    }

    /**
     * @param  array<string, mixed>  $onu
     * @return array<string, mixed>
     */
    private function onuScopeFields(array $onu): array
    {
        return [
            'scope' => 'onu',
            'slot' => (int) ($onu['slot'] ?? 0),
            'port' => (int) ($onu['port'] ?? 0),
            'onu_id' => (int) ($onu['onu_id'] ?? 0),
            'serial_number' => $onu['serial_number'] ?? null,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $detected
     * @return array{active:int, raised:int, cleared:int}
     */
    private function reconcile(SnmpOlt $olt, array $detected): array
    {
        $active = AlarmEvent::query()
            ->where('snmp_olt_id', $olt->id)
            ->where('status', AlarmEvent::STATUS_ACTIVE)
            ->get()
            ->keyBy('signature');

        $now = Carbon::now();
        $raised = 0;
        $cleared = 0;

        foreach ($active as $signature => $alarm) {
            if (! isset($detected[$signature])) {
                $alarm->update([
                    'status' => AlarmEvent::STATUS_CLEARED,
                    'cleared_at' => $now,
                ]);
                $cleared++;
            }
        }

        foreach ($detected as $signature => $data) {
            $existing = $active->get($signature);

            if ($existing) {
                $existing->update([
                    'last_seen_at' => $now,
                    'severity' => $data['severity'],
                    'message' => $data['message'],
                    'meta' => $data['meta'] ?? null,
                ]);

                continue;
            }

            AlarmEvent::create([
                'snmp_olt_id' => $olt->id,
                'signature' => $signature,
                'type' => $data['type'],
                'severity' => $data['severity'],
                'status' => AlarmEvent::STATUS_ACTIVE,
                'scope' => $data['scope'],
                'slot' => $data['slot'] ?? null,
                'port' => $data['port'] ?? null,
                'onu_id' => $data['onu_id'] ?? null,
                'serial_number' => $data['serial_number'] ?? null,
                'message' => $data['message'],
                'meta' => $data['meta'] ?? null,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
            ]);
            $raised++;
        }

        return [
            'active' => count($detected),
            'raised' => $raised,
            'cleared' => $cleared,
        ];
    }
}
