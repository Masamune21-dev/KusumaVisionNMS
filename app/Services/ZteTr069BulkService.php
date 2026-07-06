<?php

namespace App\Services;

use App\Models\AcsSetting;
use App\Models\SnmpOlt;
use App\Support\CliOutputSanitizer;
use App\Support\SmartOltSupport;

/**
 * Bulk-activates TR069 (ACS management) on every registered ONU of a ZTE OLT,
 * scoped to a single PON port (pass $onlySlot/$onlyPort to {@see run()}; omit
 * for the whole OLT). For each PON port it reads the ONUs' running-config in ONE telnet session
 * to learn the current TR069 state, then — only for ONUs that are NOT already
 * pointing at the target ACS — writes the `tr069-mgmt 1 state unlock` + acs line
 * in one more session per port.
 *
 * Skip rule: an ONU whose running-config already has TR069 unlocked AND whose
 * ACS url + username already match the target is left untouched. Password is
 * NOT part of the skip test because some firmwares mask/encrypt it in
 * `show running-config` — re-applying the full acs line (which always includes
 * the password) when url/username differ keeps credentials correct anyway.
 *
 * Two modes via $execute:
 * - false (dry-run): scan only, mark each ONU skipped / would-apply / read-error.
 * - true  (execute): scan + write, mark each ONU skipped / applied / failed.
 */
class ZteTr069BulkService
{
    /** Max ONUs read per telnet session (bounds blast radius of a degraded read). */
    private const READ_CHUNK = 40;

    public function __construct(
        private readonly ZteOnuRunningConfigService $runningConfig,
        private readonly ZteCliProvisioningExecutor $executor,
    ) {}

    /**
     * @param  (callable(array{processed:int, applied:int, skipped:int, failed:int}): void)|null  $onProgress
     *                                                                                                         invoked after each ONU so a queued job can persist live progress
     * @param  int|null  $onlySlot  scope the run to a single PON port (null = whole OLT)
     * @param  int|null  $onlyPort  scope the run to a single PON port (null = whole OLT)
     * @return array{applied:int, skipped:int, failed:int, total:int, items:array<int, array<string, mixed>>}
     */
    public function run(SnmpOlt $olt, bool $execute, ?int $userId = null, ?callable $onProgress = null, ?int $onlySlot = null, ?int $onlyPort = null): array
    {
        $acs = $this->acs();
        $isC600 = SmartOltSupport::isC600($olt);

        $applied = 0;
        $skipped = 0;
        $failed = 0;
        $items = [];

        $record = function (array $item) use (&$items, &$applied, &$skipped, &$failed, $onProgress): void {
            $items[] = $item;
            match ($item['status']) {
                'applied', 'would-apply' => $applied++,
                'skipped' => $skipped++,
                default => $failed++,
            };

            if ($onProgress !== null) {
                $onProgress([
                    'processed' => count($items),
                    'applied' => $applied,
                    'skipped' => $skipped,
                    'failed' => $failed,
                ]);
            }
        };

        foreach ($this->portsFromCache($olt, $onlySlot, $onlyPort) as [$slot, $port, $onus]) {
            // Only registered ONUs (with a serial) can have a running-config read.
            $readable = [];
            foreach ($onus as $onu) {
                $id = (int) ($onu['onu_id'] ?? 0);
                if ($id > 0 && trim((string) ($onu['serial_number'] ?? '')) !== '') {
                    $readable[] = $id;
                }
            }

            $configs = $this->readPortConfigs($olt, $slot, $port, $readable);

            $applyIds = [];
            $applyOnus = [];

            foreach ($onus as $onu) {
                $id = (int) ($onu['onu_id'] ?? 0);
                $sn = strtoupper(trim((string) ($onu['serial_number'] ?? '')));

                if ($id <= 0 || $sn === '') {
                    $record($this->item($slot, $port, $id, $sn, 'skipped', 'ONU tidak terdaftar (tanpa serial) — dilewati.'));

                    continue;
                }

                $read = $configs[$id] ?? null;

                // Crucial: a partial read (interface block present but the
                // `show onu running config` management block missing) must NOT be
                // treated as "TR069 off". Otherwise a flaky telnet read silently
                // queues a working ONU for a write. Surface it as a read failure
                // so it shows up and can be re-scanned instead.
                if ($read === null || ! $read['mgmt']) {
                    $record($this->item($slot, $port, $id, $sn, 'failed', 'Running-config tak lengkap terbaca (blok management hilang) — TR069 tidak diubah, coba pindai ulang.'));

                    continue;
                }

                if ($this->alreadyActive($read['config'], $acs)) {
                    $record($this->item($slot, $port, $id, $sn, 'skipped', 'TR069 sudah aktif & mengarah ke ACS target.'));

                    continue;
                }

                $applyIds[] = $id;
                $applyOnus[$id] = $sn;
            }

            if ($applyIds === []) {
                continue;
            }

            // Dry-run: report intent without touching the OLT.
            if (! $execute) {
                foreach ($applyIds as $id) {
                    $record($this->item($slot, $port, $id, $applyOnus[$id], 'would-apply', 'Akan diaktifkan TR069 + ACS.'));
                }

                continue;
            }

            // Execute: one telnet session per port writes every pending ONU.
            $script = $this->buildScript($slot, $port, $applyIds, $isC600, $acs);

            try {
                $result = $this->executor->execute($olt, $script);
                if ($result['ok']) {
                    foreach ($applyIds as $id) {
                        $record($this->item($slot, $port, $id, $applyOnus[$id], 'applied', 'TR069 diaktifkan + ACS di-set.'));
                    }
                } else {
                    $error = CliOutputSanitizer::clean((string) ($result['error'] ?? 'unknown'));
                    foreach ($applyIds as $id) {
                        $record($this->item($slot, $port, $id, $applyOnus[$id], 'failed', "Eksekusi port {$slot}/{$port} error: {$error}"));
                    }
                }
            } catch (\Throwable $exception) {
                $error = CliOutputSanitizer::clean($exception->getMessage());
                foreach ($applyIds as $id) {
                    $record($this->item($slot, $port, $id, $applyOnus[$id], 'failed', "Eksekusi port {$slot}/{$port} gagal: {$error}"));
                }
            }
        }

        return [
            'applied' => $applied,
            'skipped' => $skipped,
            'failed' => $failed,
            'total' => count($items),
            'items' => $items,
        ];
    }

    /**
     * Read every registered ONU's running-config on one port and tag whether the
     * management block (`show onu running config` → `pon-onu-mng …`) was actually
     * captured. The bulk read goes in modest chunks (bounds one telnet session's
     * size so a degraded session can't swallow a whole big port), then any ONU
     * whose management block came back missing is re-read individually — a small,
     * reliable read that recovers transient truncation under telnet contention.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array{config:array<string,mixed>, mgmt:bool}>
     */
    private function readPortConfigs(SnmpOlt $olt, int $slot, int $port, array $ids): array
    {
        $out = [];

        foreach (array_chunk($ids, self::READ_CHUNK) as $chunk) {
            $onus = $this->runningConfig->fetchMany($olt, $slot, $port, $chunk)['onus'];
            foreach ($chunk as $id) {
                $row = $onus[$id] ?? ['config' => [], 'raw' => ''];
                $out[$id] = ['config' => $row['config'], 'mgmt' => $this->mgmtRead($row)];
            }
        }

        // Retry only the ONUs whose management block was lost in the bulk pass.
        foreach ($ids as $id) {
            if (($out[$id]['mgmt'] ?? false) === false) {
                $single = $this->runningConfig->fetch($olt, $slot, $port, $id);
                $out[$id] = [
                    'config' => $single['config'],
                    'mgmt' => $this->mgmtRead(['config' => $single['config'], 'raw' => $single['raw']]),
                ];
            }
        }

        return $out;
    }

    /**
     * Did the `show onu running config` (management/OMCI) block actually come
     * back? Its output starts with a `pon-onu-mng gpon-onu_…` header; every
     * configured ONU also has at least a `service`/`wan` directive there. Without
     * any of these the read is incomplete and TR069 state is unknown — distinct
     * from the interface block (name/tcont), which can parse on its own.
     *
     * @param  array{config?:array<string,mixed>, raw?:string}  $row
     */
    private function mgmtRead(array $row): bool
    {
        if (stripos((string) ($row['raw'] ?? ''), 'pon-onu-mng') !== false) {
            return true;
        }

        $config = $row['config'] ?? [];

        return ($config['services'] ?? []) !== []
            || ($config['wan_ips'] ?? []) !== []
            || ($config['wan_services'] ?? []) !== []
            || (bool) ($config['tr069'] ?? false)
            || (bool) ($config['remote_ont'] ?? false);
    }

    /**
     * Total ONUs in the OLT cache — used as the progress-bar denominator. Every
     * cached ONU is recorded exactly once by run(), so this matches the final
     * processed count.
     */
    public function cachedOnuCount(SnmpOlt $olt, ?int $onlySlot = null, ?int $onlyPort = null): int
    {
        $total = 0;
        foreach ($this->portsFromCache($olt, $onlySlot, $onlyPort) as [, , $onus]) {
            $total += count($onus);
        }

        return $total;
    }

    /**
     * Build the TR069-enable script for a batch of ONUs on one port. Each ONU is
     * configured under its own `pon-onu-mng` block (guide §5.3).
     *
     * @param  array<int, int>  $onuIds
     * @param  array{url:string, username:string, password:string}  $acs
     */
    private function buildScript(int $slot, int $port, array $onuIds, bool $isC600, array $acs): string
    {
        $lines = ['conf t'];

        foreach ($onuIds as $id) {
            $iface = SmartOltSupport::onuInterfaceId($slot, $port, $id, $isC600);
            $lines[] = "pon-onu-mng {$iface}";
            $lines[] = 'tr069-mgmt 1 state unlock';
            $lines[] = sprintf(
                'tr069-mgmt 1 acs %s validate basic username %s password %s',
                $acs['url'],
                $acs['username'],
                $acs['password'],
            );
            $lines[] = 'exit';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array{url:string, username:string, password:string}  $acs
     */
    private function alreadyActive(array $config, array $acs): bool
    {
        return ($config['tr069'] ?? false) === true
            && strcasecmp(trim((string) ($config['acs_url'] ?? '')), $acs['url']) === 0
            && (string) ($config['acs_username'] ?? '') === $acs['username'];
    }

    /**
     * Group cached ONUs by PON port from `snmp_olts.last_test_result.port_onus`.
     * Slot/port come from the canonical cache key ("{slot}_{port}") rather than
     * per-ONU fields, which may be absent. When $onlySlot/$onlyPort are given the
     * result is scoped to that single port (TR069 massal is now per-port).
     *
     * @return array<int, array{0:int, 1:int, 2:array<int, array<string, mixed>>}>
     */
    private function portsFromCache(SnmpOlt $olt, ?int $onlySlot = null, ?int $onlyPort = null): array
    {
        $portOnus = data_get($olt->last_test_result ?? [], 'port_onus', []);
        $ports = [];

        foreach ((is_array($portOnus) ? $portOnus : []) as $key => $entry) {
            if (! preg_match('/^(\d+)_(\d+)$/', (string) $key, $m)) {
                continue;
            }

            if ($onlySlot !== null && $onlyPort !== null
                && ((int) $m[1] !== $onlySlot || (int) $m[2] !== $onlyPort)) {
                continue;
            }

            $onus = data_get($entry, 'onus', []);
            if (! is_array($onus) || $onus === []) {
                continue;
            }

            $ports[] = [(int) $m[1], (int) $m[2], array_values($onus)];
        }

        // Deterministic order (slot, port) so progress reads naturally.
        usort($ports, fn (array $a, array $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return $ports;
    }

    /**
     * @return array{url:string, username:string, password:string}
     */
    private function acs(): array
    {
        // Endpoint tersimpan di Pengaturan (AcsSetting), fallback ke config/env.
        return AcsSetting::resolved();
    }

    /**
     * @return array{slot:int, port:int, onu_id:int, serial_number:string, status:string, message:string}
     */
    private function item(int $slot, int $port, int $onuId, string $sn, string $status, string $message): array
    {
        return [
            'slot' => $slot,
            'port' => $port,
            'onu_id' => $onuId,
            'serial_number' => $sn,
            'status' => $status,
            'message' => $message,
        ];
    }
}
