<?php

namespace App\Services;

use App\Models\SmartOltOnuRegistration;
use App\Models\SnmpOlt;
use App\Support\CliOutputSanitizer;
use App\Support\SmartOltSupport;

/**
 * Batch-copies one or more registered ONUs from a source PON port to another
 * port on the *same* OLT. For each source ONU it reads the live running-config,
 * rebuilds a full provisioning script targeting the destination interface (with
 * a freshly allocated onu-id), and persists a {@see SmartOltOnuRegistration}
 * row — reusing the existing register/execute/audit pipeline.
 *
 * The source ONU is never touched: this generates the destination config only.
 */
class ZteOnuCopyService
{
    public function __construct(
        private readonly ZteOnuRunningConfigService $runningConfig,
        private readonly ZteOnuReconfigureScriptBuilder $scriptBuilder,
        private readonly ZteCliProvisioningExecutor $executor,
    ) {}

    /**
     * @param  array<int, int>  $sourceOnuIds
     * @param  (callable(array{processed:int, created:int, executed:int, failed:int, item:array<string,mixed>}): void)|null  $onProgress
     *                                                                                                                                    invoked after each ONU so a queued job can persist live progress
     * @return array{created:int, executed:int, failed:int, items:array<int, array{onu_id:int, target_onu_id:int, serial_number:string, ok:bool, message:string}>}
     */
    public function copy(
        SnmpOlt $olt,
        int $srcSlot,
        int $srcPort,
        array $sourceOnuIds,
        int $dstSlot,
        int $dstPort,
        bool $execute,
        ?int $userId,
        ?callable $onProgress = null,
    ): array {
        $isC600 = SmartOltSupport::isC600($olt);
        $oltIface = SmartOltSupport::gponOltInterface($dstSlot, $dstPort, $isC600);
        $sourceOnus = $this->keyedCachedOnus($olt, $srcSlot, $srcPort);
        $usedIds = $this->usedOnuIds($olt, $dstSlot, $dstPort);

        $created = 0;
        $executed = 0;
        $failed = 0;
        $items = [];

        // Push a result row and surface running progress to the optional callback.
        $record = function (array $item) use (&$items, &$created, &$executed, &$failed, $onProgress): void {
            $items[] = $item;
            if ($onProgress !== null) {
                $onProgress([
                    'processed' => count($items),
                    'created' => $created,
                    'executed' => $executed,
                    'failed' => $failed,
                    'item' => $item,
                ]);
            }
        };

        // Read every source ONU's running-config in ONE telnet session (ringan).
        $readable = [];
        foreach ($sourceOnuIds as $id) {
            $row = $sourceOnus[(int) $id] ?? null;
            if ($row !== null && trim((string) ($row['serial_number'] ?? '')) !== '') {
                $readable[] = (int) $id;
            }
        }
        $configs = $readable !== []
            ? $this->runningConfig->fetchMany($olt, $srcSlot, $srcPort, $readable)['onus']
            : [];

        foreach ($sourceOnuIds as $srcId) {
            $srcId = (int) $srcId;
            $cached = $sourceOnus[$srcId] ?? null;

            if ($cached === null) {
                $failed++;
                $record($this->item($srcId, 0, '', false, 'ONU sumber tidak ada di cache port asal.'));

                continue;
            }

            $sn = strtoupper(trim((string) ($cached['serial_number'] ?? '')));
            if ($sn === '') {
                $failed++;
                $record($this->item($srcId, 0, '', false, 'Serial number ONU sumber kosong — tidak bisa diregister ulang.'));

                continue;
            }

            $live = $configs[$srcId] ?? ['ok' => false, 'config' => []];
            if (! $live['ok']) {
                $failed++;
                $record($this->item($srcId, 0, $sn, false, 'Gagal baca running-config ONU sumber (kosong/tidak terbaca).'));

                continue;
            }

            $config = $live['config'];
            $targetId = $this->allocateOnuId($usedIds);
            if ($targetId === 0) {
                $failed++;
                $record($this->item($srcId, 0, $sn, false, 'Slot onu-id di port tujuan habis.'));

                continue;
            }

            $onuIface = SmartOltSupport::onuInterfaceId($dstSlot, $dstPort, $targetId, $isC600);
            $type = $this->onuTypeToken($cached);
            $script = $this->scriptBuilder->buildForCopy($config, [
                'olt_iface' => $oltIface,
                'onu_iface' => $onuIface,
                'onu_id' => $targetId,
                'sn' => $sn,
                'onu_type' => $type,
                'is_c600' => $isC600,
            ]);

            $registration = SmartOltOnuRegistration::create([
                ...$this->registrationData($config, $cached),
                'snmp_olt_id' => $olt->id,
                'serial_number' => $sn,
                'slot' => $dstSlot,
                'port' => $dstPort,
                'onu_id' => $targetId,
                'pon_port' => $onuIface,
                'onu_type' => $type,
                'cli_script' => $script,
                'status' => 'generated',
                'created_by' => $userId,
            ]);
            $created++;

            if (! $execute) {
                $record($this->item($srcId, $targetId, $sn, true, "Script untuk {$onuIface} digenerate."));

                continue;
            }

            try {
                $result = $this->executor->execute($olt, $script);
                $output = CliOutputSanitizer::clean($result['output']);
                $error = $result['error'] === null ? null : CliOutputSanitizer::clean($result['error']);

                $registration->update([
                    'status' => $result['ok'] ? 'executed' : 'failed',
                    'execution_output' => $output,
                    'execution_error' => $error,
                    'executed_at' => now(),
                    'executed_by' => $userId,
                ]);

                if ($result['ok']) {
                    $executed++;
                    $record($this->item($srcId, $targetId, $sn, true, "Diregister & dieksekusi ke {$onuIface}."));
                } else {
                    $failed++;
                    $record($this->item($srcId, $targetId, $sn, false, "Eksekusi {$onuIface} error: ".$error));
                }
            } catch (\Throwable $exception) {
                $error = CliOutputSanitizer::clean($exception->getMessage());
                $registration->update([
                    'status' => 'failed',
                    'execution_error' => $error,
                    'executed_at' => now(),
                    'executed_by' => $userId,
                ]);
                $failed++;
                $record($this->item($srcId, $targetId, $sn, false, "Eksekusi {$onuIface} gagal: ".$error));
            }
        }

        return [
            'created' => $created,
            'executed' => $executed,
            'failed' => $failed,
            'items' => $items,
        ];
    }

    /**
     * Map registration columns from a parsed running-config + cached ONU row,
     * mirroring SmartOltController::configureOnuApply().
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $cached
     * @return array<string, mixed>
     */
    private function registrationData(array $config, array $cached): array
    {
        $tcont = $config['tconts'][0] ?? [];
        $service = $config['services'][0] ?? [];
        $wan = $config['wan_ips'][0] ?? [];
        $mode = strtolower((string) ($wan['mode'] ?? 'pppoe'));

        return [
            'customer_name' => (string) ($config['name'] ?? ($cached['name'] ?? '')),
            'tcont_profile' => (string) ($tcont['profile'] ?? 'SERVER'),
            'vlan' => $this->primaryVlan($config),
            'vlan_profile' => $wan['vlan_profile'] ?? null,
            'service_name' => (string) ($service['name'] ?? 'ServiceName'),
            'wan_mode' => in_array($mode, ['pppoe', 'dhcp', 'static'], true) ? $mode : 'pppoe',
            'pppoe_username' => $wan['pppoe_username'] ?? null,
            'pppoe_password' => $wan['pppoe_password'] ?? null,
            'ip_profile' => $wan['ip_profile'] ?? null,
            'static_ip' => $wan['static_ip'] ?? null,
            'static_netmask' => isset($wan['static_mask_length']) ? (string) $wan['static_mask_length'] : null,
            'tr069_enabled' => (bool) ($config['tr069'] ?? false),
            'acs_url' => $config['acs_url'] ?? null,
            'acs_username' => $config['acs_username'] ?? null,
            'acs_password' => $config['acs_password'] ?? null,
            'remote_ont_enabled' => (bool) ($config['remote_ont'] ?? false),
            'remote_ont_id' => $config['remote_ont_id'] ?? null,
            'remote_ont_mode' => $config['remote_ont_mode'] ?? null,
            'remote_ont_protocol' => $config['remote_ont_protocol'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function primaryVlan(array $config): int
    {
        foreach (($config['service_ports'] ?? []) as $row) {
            if ((int) ($row['vlan'] ?? 0) > 0) {
                return (int) $row['vlan'];
            }
        }

        foreach (($config['services'] ?? []) as $row) {
            if ((int) ($row['vlan'] ?? 0) > 0) {
                return (int) $row['vlan'];
            }
        }

        return 0;
    }

    /**
     * The `type` token for `onu N type T sn S` comes from the SNMP ONU-table
     * type name; fall back to ALL-ONT. Strip any whitespace defensively.
     *
     * @param  array<string, mixed>  $cached
     */
    private function onuTypeToken(array $cached): string
    {
        $type = preg_replace('/\s.*$/', '', trim((string) ($cached['type_name'] ?? ''))) ?? '';

        return $type !== '' ? strtoupper($type) : 'ALL-ONT';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function keyedCachedOnus(SnmpOlt $olt, int $slot, int $port): array
    {
        $onus = data_get($olt->last_test_result ?? [], "port_onus.{$slot}_{$port}.onus", []);
        $out = [];

        foreach ((is_array($onus) ? $onus : []) as $onu) {
            $out[(int) ($onu['onu_id'] ?? 0)] = $onu;
        }

        return $out;
    }

    /**
     * @return array<int, true>
     */
    private function usedOnuIds(SnmpOlt $olt, int $slot, int $port): array
    {
        $used = [];
        foreach (array_keys($this->keyedCachedOnus($olt, $slot, $port)) as $id) {
            if ($id > 0) {
                $used[$id] = true;
            }
        }

        return $used;
    }

    /**
     * Reserve the lowest free onu-id (1..4096) on the target port, marking it
     * used so subsequent items in the batch don't collide.
     *
     * @param  array<int, true>  $used
     */
    private function allocateOnuId(array &$used): int
    {
        for ($id = 1; $id <= 4096; $id++) {
            if (! isset($used[$id])) {
                $used[$id] = true;

                return $id;
            }
        }

        return 0;
    }

    /**
     * @return array{onu_id:int, target_onu_id:int, serial_number:string, ok:bool, message:string}
     */
    private function item(int $srcId, int $targetId, string $sn, bool $ok, string $message): array
    {
        return [
            'onu_id' => $srcId,
            'target_onu_id' => $targetId,
            'serial_number' => $sn,
            'ok' => $ok,
            'message' => $message,
        ];
    }
}
