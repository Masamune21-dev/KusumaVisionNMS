<?php

namespace App\Services\CData;

use App\Models\SnmpOlt;
use App\Services\CData\Concerns\InteractsWithCDataCli;
use RuntimeException;

/**
 * CLI (telnet) C-Data GPON — sumber inventory utama untuk firmware FlashV3.x, karena SNMP V3
 * hanya mengembalikan 1 baris (guide §3.3 & §6.2).
 *
 * `show ont info all` (level enable) → tabel F/S P ONT_ID SN CONTROL RUN CONFIG MATCH LAST_DOWN DESC.
 * Rx per-ONU dari `show ont optical-info {port} all` (submode `interface gpon 0/{slot}`).
 * Verifikasi: FD1608S V3.3.86 (#277) → 31 ONU. Format diparse dari output asli, bukan tebakan.
 */
class CDataGponCliService
{
    use InteractsWithCDataCli;

    /**
     * Inventory penuh + Rx optical per port, dalam satu sesi telnet.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getOnts(SnmpOlt $olt): array
    {
        $connection = $this->openCliSession($olt);

        try {
            $info = $this->cliCommand($connection, 'show ont info all', 30, true);
            if (($error = $this->cliDetectError($info)) !== null) {
                throw new RuntimeException("CLI menolak 'show ont info all': {$error}");
            }
            $onus = $this->parseOntInfo($info);

            $rxByKey = $this->fetchOpticalMap($connection, $onus);
            foreach ($onus as &$onu) {
                $rx = $rxByKey["{$onu['slot']}.{$onu['port']}.{$onu['onu_id']}"] ?? null;
                if ($rx !== null) {
                    $onu['rx_power_dbm'] = $rx;
                    $onu['rx_power_label'] = sprintf('%.2f dBm', $rx);
                }
            }

            return $onus;
        } finally {
            fclose($connection);
        }
    }

    /**
     * Rx optical per ONU via `show ont optical-info {port} all` (submode `interface gpon 0/{slot}`).
     *
     * @param  resource  $connection
     * @param  array<int, array<string, mixed>>  $onus
     * @return array<string, float> di-key `slot.port.onuId`
     */
    private function fetchOpticalMap($connection, array $onus): array
    {
        $ports = [];
        foreach ($onus as $onu) {
            $ports["{$onu['slot']}.{$onu['port']}"] = ['slot' => (int) $onu['slot'], 'port' => (int) $onu['port']];
        }
        if ($ports === []) {
            return [];
        }

        $map = [];
        $this->cliCommand($connection, 'config', 5);
        $currentSlot = null;

        foreach ($ports as $p) {
            if ($currentSlot !== $p['slot']) {
                $this->cliCommand($connection, "interface gpon 0/{$p['slot']}", 5);
                $currentSlot = $p['slot'];
            }
            $out = $this->cliCommand($connection, "show ont optical-info {$p['port']} all", 15, true);
            foreach ($this->parseOpticalInfo($out) as $ontId => $rx) {
                $map["{$p['slot']}.{$p['port']}.{$ontId}"] = $rx;
            }
        }

        $this->cliCommand($connection, 'end', 4);

        return $map;
    }

    /**
     * Parse tabel `show ont info all` → baris ONU bentuk cache. Public agar bisa diuji unit.
     *
     * @return array<int, array<string, mixed>>
     */
    public function parseOntInfo(string $output): array
    {
        $onus = [];

        foreach (preg_split('/\r\n|\n|\r/', $output) ?: [] as $line) {
            // F/S P ONT_ID SN CONTROL RUN CONFIG MATCH LAST_DOWN DESC...
            if (! preg_match('/^\s*(\d+)\/(\d+)\s+(\d+)\s+(\d+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s*(.*?)\s*$/', $line, $m)) {
                continue;
            }

            [, , $slot, $port, $ontId, $sn, $control, $run, , , $lastDown, $desc] = $m;
            $slot = (int) $slot;
            $port = (int) $port;
            $ontId = (int) $ontId;
            $online = strcasecmp($run, 'Online') === 0;
            $desc = trim($desc);

            $onus[] = [
                'onu_key' => "{$slot}.{$port}.{$ontId}",
                'if_index' => $slot,
                'slot' => $slot,
                'port' => $port,
                'onu_id' => $ontId,
                'interface' => sprintf('gpon 0/%d/%d:%d', $slot, $port, $ontId),
                'type_name' => null,
                'name' => $desc !== '' ? $desc : null,
                'description' => $desc !== '' ? $desc : null,
                'serial_number' => strtoupper($sn),
                'mac' => null,
                'vendor_id' => strtoupper(substr($sn, 0, 4)),
                'admin_state' => strcasecmp($control, 'Active') === 0 ? 'enable' : 'disable',
                'phase_state' => ucfirst(strtolower($run)),
                'online' => $online,
                'last_down_cause' => $lastDown === '--' ? null : $lastDown,
                'rx_power_dbm' => null,
                'rx_power_label' => null,
                'source' => 'cli',
            ];
        }

        usort($onus, fn ($a, $b) => [$a['slot'], $a['port'], $a['onu_id']] <=> [$b['slot'], $b['port'], $b['onu_id']]);

        return $onus;
    }

    /**
     * Parse `show ont optical-info {port} all`:
     *   ONT_ID  Rx(dBm)  Tx(dBm)  OLT_Rx(dBm)  Temp(C)  Voltage(V)  Current(mA)   (`--` = N/A)
     * Public agar bisa diuji unit. Mengembalikan Rx ONU (dBm) di-key onuId.
     *
     * @return array<int, float>
     */
    public function parseOpticalInfo(string $output): array
    {
        $map = [];

        foreach (preg_split('/\r\n|\n|\r/', $output) ?: [] as $line) {
            if (! preg_match('/^\s*(\d+)\s+(-?[\d.]+|--)\s+(-?[\d.]+|--)\s+(-?[\d.]+|--)\s+([\d.]+|--)\s+([\d.]+|--)\s+([\d.]+|--)\s*$/', $line, $m)) {
                continue;
            }

            if ($m[2] !== '--') {
                $map[(int) $m[1]] = (float) $m[2];
            }
        }

        return $map;
    }
}
