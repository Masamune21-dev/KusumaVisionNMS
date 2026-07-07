<?php

namespace App\Services;

use App\Jobs\SendFcmAlarmNotifications;
use App\Models\AlarmEvent;
use App\Models\SnmpOlt;
use App\Services\Fcm\FcmAlarmNotifier;
use App\Services\Telegram\TelegramNotifier;
use App\Support\SmartOltSupport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AlarmEvaluator
{
    private const RX_LOW_DBM = -28.0;

    private const RX_HIGH_DBM = -8.0;

    private const RX_CLEAR_LOW_DBM = -26.0;

    private const RX_CLEAR_HIGH_DBM = -10.0;

    public function __construct(private ?TelegramNotifier $telegram = null) {}

    /**
     * Evaluate the latest poll snapshot and reconcile active alarms for the OLT.
     *
     * Alarms are raised only on a transition from a healthy state into a fault
     * (online → LOS/dying-gasp/offline, port up → down, RX healthy → out of range).
     * Devices that are already in a fault state when first observed are NOT alarmed.
     * The previous poll snapshot supplies the prior state used to detect transitions.
     *
     * @param  array<string, mixed>  $previous  the snapshot from the prior poll
     * @return array{active:int, raised:int, cleared:int}
     */
    public function evaluate(SnmpOlt $olt, array $previous = []): array
    {
        // Evaluasi SELALU jalan (event tetap tercatat). Saklar alarm per-OLT/per-partner
        // hanya menentukan SIAPA yang menerima notifikasi — di-gerbang di TelegramNotifier
        // & FcmAlarmNotifier (bukan di sini).
        $snapshot = $olt->last_test_result ?? [];
        $active = $this->activeAlarms($olt);

        if (! ($snapshot['ok'] ?? false)) {
            $detected = [];

            if ($active->has('olt:unreachable') || ($previous['ok'] ?? false)) {
                $detected['olt:unreachable'] = [
                    'type' => AlarmEvent::TYPE_OLT_UNREACHABLE,
                    'severity' => AlarmEvent::SEVERITY_CRITICAL,
                    'scope' => 'olt',
                    'message' => 'OLT tidak dapat dihubungi: '.($snapshot['error'] ?? 'unknown error'),
                ];
            }

            return $this->reconcile($olt, $active, $detected, []);
        }

        $prev = $this->indexPrevious($previous);
        $detected = [];

        foreach ($snapshot['ports'] ?? [] as $port) {
            $detected += $this->portAlarm($port, $prev, $active);
        }

        foreach ($snapshot['port_onus'] ?? [] as $portData) {
            foreach ($portData['onus'] ?? [] as $onu) {
                if (($onu['admin_state'] ?? null) === 'disabled') {
                    continue;
                }

                $detected += $this->onuStateAlarms($onu, $prev, $active);
                $detected += $this->onuRxAlarm($onu, $prev, $active);
            }
        }

        return $this->reconcile($olt, $active, $detected, $this->indexCurrent($snapshot));
    }

    /**
     * Index the current snapshot so cleared alarms can report the recovered state.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array{onus: array<string, array<string, mixed>>, ports: array<string, array<string, mixed>>}
     */
    private function indexCurrent(array $snapshot): array
    {
        $onus = [];
        $ports = [];

        foreach ($snapshot['ports'] ?? [] as $port) {
            $slot = (int) ($port['slot'] ?? 0);
            $portNo = (int) ($port['port'] ?? 0);
            $ports["{$slot}/{$portNo}"] = $port;
        }

        foreach ($snapshot['port_onus'] ?? [] as $portData) {
            foreach ($portData['onus'] ?? [] as $onu) {
                $onus[$this->onuKey($onu)] = $onu;
            }
        }

        return ['onus' => $onus, 'ports' => $ports];
    }

    /**
     * Build the recovery context for an alarm that is clearing, using the live snapshot.
     *
     * @param  array{onus: array<string, array<string, mixed>>, ports: array<string, array<string, mixed>>}  $current
     * @return array<string, mixed>|null
     */
    private function buildRecovery(AlarmEvent $alarm, array $current): ?array
    {
        if ($alarm->scope === 'olt') {
            return ['message' => 'OLT kembali terhubung.', 'online' => true];
        }

        if ($alarm->scope === 'port') {
            $port = $current['ports']["{$alarm->slot}/{$alarm->port}"] ?? null;
            $name = $port['name'] ?? "{$alarm->slot}/{$alarm->port}";

            return ['message' => "GPON port {$name} kembali up.", 'online' => true];
        }

        $key = $alarm->serial_number ?: sprintf('%d/%d:%d', $alarm->slot ?? 0, $alarm->port ?? 0, $alarm->onu_id ?? 0);
        $onu = $current['onus'][$key] ?? null;

        if ($onu === null) {
            return null;
        }

        $iface = $onu['interface'] ?? $key;
        $rx = $onu['rx_power_dbm'] ?? null;
        $online = (bool) ($onu['online'] ?? false);

        if ($alarm->type === AlarmEvent::TYPE_HIGH_RX) {
            $message = $rx !== null
                ? "ONU {$iface} RX {$rx} dBm kembali normal."
                : "ONU {$iface} RX kembali normal.";
        } else {
            $message = "ONU {$iface} kembali online".($rx !== null ? ", RX {$rx} dBm." : '.');
        }

        return ['message' => $message, 'rx_power_dbm' => $rx, 'online' => $online];
    }

    /**
     * Index the prior snapshot into quick lookups for transition detection.
     *
     * @param  array<string, mixed>  $previous
     * @return array{online: array<string, bool>, rx: array<string, float|null>, portStatus: array<string, string|null>}
     */
    private function indexPrevious(array $previous): array
    {
        $online = [];
        $rx = [];
        $portStatus = [];

        foreach ($previous['ports'] ?? [] as $port) {
            $slot = (int) ($port['slot'] ?? 0);
            $portNo = (int) ($port['port'] ?? 0);
            $portStatus["{$slot}/{$portNo}"] = $port['oper_status'] ?? null;
        }

        foreach ($previous['port_onus'] ?? [] as $portData) {
            foreach ($portData['onus'] ?? [] as $onu) {
                $key = $this->onuKey($onu);
                $online[$key] = (bool) ($onu['online'] ?? false);
                $rx[$key] = $onu['rx_power_dbm'] ?? null;
            }
        }

        return ['online' => $online, 'rx' => $rx, 'portStatus' => $portStatus];
    }

    /**
     * @param  array<string, mixed>  $port
     * @param  array{portStatus: array<string, string|null>}  $prev
     * @param  Collection<string, AlarmEvent>  $active
     * @return array<string, array<string, mixed>>
     */
    private function portAlarm(array $port, array $prev, $active): array
    {
        if (($port['oper_status'] ?? null) !== 'down') {
            return [];
        }

        $slot = (int) ($port['slot'] ?? 0);
        $portNo = (int) ($port['port'] ?? 0);
        $signature = "port:{$slot}/{$portNo}:port_down";

        // Raise only when a port goes up -> down (or when the alarm is already open).
        $prevUp = ($prev['portStatus']["{$slot}/{$portNo}"] ?? null) === 'up';
        if (! $active->has($signature) && ! $prevUp) {
            return [];
        }

        return [$signature => [
            'type' => AlarmEvent::TYPE_PORT_DOWN,
            'severity' => AlarmEvent::SEVERITY_CRITICAL,
            'scope' => 'port',
            'slot' => $slot,
            'port' => $portNo,
            'message' => "GPON port {$port['name']} oper status down.",
        ]];
    }

    /**
     * Currently-active alarms for the OLT, keyed by signature.
     *
     * @return Collection<string, AlarmEvent>
     */
    private function activeAlarms(SnmpOlt $olt)
    {
        return AlarmEvent::query()
            ->where('snmp_olt_id', $olt->id)
            ->where('status', AlarmEvent::STATUS_ACTIVE)
            ->get()
            ->keyBy('signature');
    }

    /**
     * @param  array<string, mixed>  $onu
     * @param  array{online: array<string, bool>}  $prev
     * @param  Collection<string, AlarmEvent>  $active
     * @return array<string, array<string, mixed>>
     */
    private function onuStateAlarms(array $onu, array $prev, $active): array
    {
        // An ONU that is currently up has no active fault. last_down_cause records the
        // historical reason it was *previously* down and persists after recovery, so it
        // must not raise an alarm while the ONU is back online.
        if ($onu['online'] ?? false) {
            return [];
        }

        $key = $this->onuKey($onu);

        // Only alarm a fault that started as an online -> fault transition, or that is
        // already an open alarm episode. An ONU that was already down when first observed
        // (never seen online) is skipped, so long-offline devices stay silent.
        $prevOnline = ($prev['online'][$key] ?? false) === true;
        if (! $prevOnline && ! $this->onuHasStateAlarm($active, $key)) {
            return [];
        }

        $base = $this->onuScopeFields($onu);
        $iface = $onu['interface'] ?? $key;
        $phase = $onu['phase_state'] ?? null;
        $lastDown = $onu['last_down_cause'] ?? null;

        if ($phase === 'DyingGasp' || $lastDown === 'DyingGasp') {
            return ["onu:{$key}:dying_gasp" => [
                ...$base,
                'type' => AlarmEvent::TYPE_DYING_GASP,
                'severity' => AlarmEvent::SEVERITY_MINOR,
                'message' => "ONU {$iface} dying gasp.",
            ]];
        }

        if ($phase === 'LOS' || $lastDown === 'LOS') {
            return ["onu:{$key}:los" => [
                ...$base,
                'type' => AlarmEvent::TYPE_LOS,
                'severity' => AlarmEvent::SEVERITY_MAJOR,
                'message' => "ONU {$iface} loss of signal (LOS).",
            ]];
        }

        return ["onu:{$key}:onu_offline" => [
            ...$base,
            'type' => AlarmEvent::TYPE_ONU_OFFLINE,
            'severity' => AlarmEvent::SEVERITY_MINOR,
            'message' => "ONU {$iface} offline (phase: ".($phase ?? 'unknown').').',
        ]];
    }

    /**
     * @param  Collection<string, AlarmEvent>  $active
     */
    private function onuHasStateAlarm($active, string $key): bool
    {
        return $active->has("onu:{$key}:dying_gasp")
            || $active->has("onu:{$key}:los")
            || $active->has("onu:{$key}:onu_offline");
    }

    /**
     * @param  array<string, mixed>  $onu
     * @param  array{rx: array<string, float|null>}  $prev
     * @param  Collection<string, AlarmEvent>  $active
     * @return array<string, array<string, mixed>>
     */
    private function onuRxAlarm(array $onu, array $prev, $active): array
    {
        $rx = $onu['rx_power_dbm'] ?? null;

        if ($rx === null) {
            return [];
        }

        $key = $this->onuKey($onu);
        $signature = "onu:{$key}:high_rx_attenuation";

        $breaching = $rx <= self::RX_LOW_DBM || $rx >= self::RX_HIGH_DBM;
        $recovered = $rx >= self::RX_CLEAR_LOW_DBM && $rx <= self::RX_CLEAR_HIGH_DBM;

        if ($active->has($signature)) {
            // Keep the alarm open until RX clearly recovers (>= -26 dBm / <= -10 dBm),
            // so a reading still hovering at -27 does not clear prematurely.
            if ($recovered) {
                return [];
            }
        } else {
            // Raise only the moment RX first crosses out of range from a healthy
            // reading. If it was already out of range, stay silent (no re-alarm).
            $prevRx = $prev['rx'][$key] ?? null;
            $prevHealthy = $prevRx !== null && $prevRx > self::RX_LOW_DBM && $prevRx < self::RX_HIGH_DBM;

            if (! ($breaching && $prevHealthy)) {
                return [];
            }
        }

        $iface = $onu['interface'] ?? $key;

        return [$signature => [
            ...$this->onuScopeFields($onu),
            'type' => AlarmEvent::TYPE_HIGH_RX,
            'severity' => AlarmEvent::SEVERITY_WARNING,
            'message' => "ONU {$iface} RX {$rx} dBm di luar rentang sehat.",
            'meta' => [
                ...$this->onuMeta($onu),
                'rx_power_dbm' => $rx,
            ],
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
        $fields = [
            'scope' => 'onu',
            'slot' => (int) ($onu['slot'] ?? 0),
            'port' => (int) ($onu['port'] ?? 0),
            'onu_id' => (int) ($onu['onu_id'] ?? 0),
            'serial_number' => $onu['serial_number'] ?? null,
        ];

        $meta = $this->onuMeta($onu);

        if ($meta !== []) {
            $fields['meta'] = $meta;
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $onu
     * @return array<string, mixed>
     */
    private function onuMeta(array $onu): array
    {
        return array_filter([
            'customer_name' => SmartOltSupport::customerNameFromOnu($onu),
            'onu_name' => $onu['name'] ?? null,
            'onu_description' => $onu['description'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @param  Collection<string, AlarmEvent>  $active
     * @param  array<string, array<string, mixed>>  $detected
     * @param  array{onus: array<string, array<string, mixed>>, ports: array<string, array<string, mixed>>}  $current
     * @return array{active:int, raised:int, cleared:int}
     */
    private function reconcile(SnmpOlt $olt, $active, array $detected, array $current): array
    {
        $now = Carbon::now();
        $raisedAlarms = [];
        $clearedAlarms = [];

        foreach ($active as $signature => $alarm) {
            if (! isset($detected[$signature])) {
                $recovery = $this->buildRecovery($alarm, $current);
                $meta = $alarm->meta ?? [];

                if ($recovery !== null) {
                    $meta['recovery'] = $recovery;
                }

                $alarm->update([
                    'status' => AlarmEvent::STATUS_CLEARED,
                    'cleared_at' => $now,
                    'meta' => $meta,
                ]);
                $clearedAlarms[] = $alarm;
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

            $raisedAlarms[] = AlarmEvent::create([
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
        }

        if ($raisedAlarms !== [] || $clearedAlarms !== []) {
            ($this->telegram ??= app(TelegramNotifier::class))
                ->notify($olt, $raisedAlarms, $clearedAlarms);

            // Push FCM ke aplikasi Android — di queue agar tak menahan polling.
            // Hanya di-dispatch bila kredensial ada DAN diaktifkan admin di Settings.
            if (app(FcmAlarmNotifier::class)->active()) {
                SendFcmAlarmNotifications::dispatch(
                    $olt->id,
                    array_map(fn (AlarmEvent $a) => $a->id, $raisedAlarms),
                    array_map(fn (AlarmEvent $a) => $a->id, $clearedAlarms),
                );
            }
        }

        return [
            'active' => count($detected),
            'raised' => count($raisedAlarms),
            'cleared' => count($clearedAlarms),
        ];
    }
}
