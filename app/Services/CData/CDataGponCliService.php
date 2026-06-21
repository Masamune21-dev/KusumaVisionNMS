<?php

namespace App\Services\CData;

use App\Models\SnmpOlt;
use RuntimeException;

/**
 * CLI (telnet) C-Data GPON — sumber inventory utama untuk firmware FlashV3.x, karena SNMP V3
 * hanya mengembalikan 1 baris (guide §3.3 & §6.2).
 *
 * `show ont info all` (di level enable) mengembalikan tabel:
 *   F/S  P  ONT_ID  SN  CONTROL  RUN  CONFIG  MATCH  LAST_DOWN  DESC
 * DESC boleh mengandung spasi/slash. Verifikasi: FD1608S V3.3.86 (#277) → 31 ONU.
 *
 * Shell mirip Cisco IOS; login prompt `User name:` + `Password:` dengan CRLF strict.
 */
class CDataGponCliService
{
    /** @var list<string> */
    private const ERROR_NEEDLES = [
        'invalid input', 'unknown command', 'ambiguous command', 'incomplete command',
        'command rejected', 'permission denied', 'authorization failed', 'not support',
        '% bad', '% invalid', '% command', '% there is no',
    ];

    private const PROMPT_LOGIN = '/(user ?name|login|username)\s*:\s*$/i';

    private const PROMPT_PASSWORD = '/(password|passwd)\s*:\s*$/i';

    private const PROMPT_ANY = '/[\w\-.]+\s*[>#]\s*$/';

    private const PROMPT_ENABLE = '/[\w\-.]+\s*#\s*$/';

    // Prompt apa pun yg diakhiri `#` (enable `host#`, config `(config)#`, interface `(config-if-...)#`).
    private const PROMPT_CMD = '/#\s*$/';

    /**
     * Inventory penuh ONU (`show ont info all`) + Rx optical per port (`show ont optical-info`),
     * digabung dalam satu sesi telnet.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getOnts(SnmpOlt $olt): array
    {
        if ($olt->cli_transport !== 'telnet') {
            throw new RuntimeException('CLI C-Data baru mendukung Telnet. Set CLI transport OLT ke telnet.');
        }

        $connection = @fsockopen($olt->ip, (int) ($olt->cli_port ?: 23), $errno, $errstr, 10);
        if (! $connection) {
            throw new RuntimeException("Koneksi telnet gagal: {$errstr} ({$errno})");
        }

        stream_set_timeout($connection, 2);
        stream_set_blocking($connection, false);

        try {
            $this->login($connection, $olt);
            fwrite($connection, "enable\r\n");
            $this->readUntil($connection, self::PROMPT_CMD, 6);

            $info = $this->command($connection, 'show ont info all', 30);
            if (($error = $this->detectError($info)) !== null) {
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
     * Kirim satu command, baca sampai prompt CLI.
     *
     * @param  resource  $connection
     */
    private function command($connection, string $command, float $max): string
    {
        fwrite($connection, $command."\r\n");

        return $this->readUntil($connection, self::PROMPT_CMD, $max, true);
    }

    /**
     * Ambil Rx optical per ONU via `show ont optical-info {port} all` (submode interface gpon 0/{slot}).
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
        $this->command($connection, 'config', 5);
        $currentSlot = null;

        foreach ($ports as $p) {
            if ($currentSlot !== $p['slot']) {
                $this->command($connection, "interface gpon 0/{$p['slot']}", 5);
                $currentSlot = $p['slot'];
            }
            $out = $this->command($connection, "show ont optical-info {$p['port']} all", 15);
            foreach ($this->parseOpticalInfo($out) as $ontId => $rx) {
                $map["{$p['slot']}.{$p['port']}.{$ontId}"] = $rx;
            }
        }

        $this->command($connection, 'end', 4);

        return $map;
    }

    /**
     * @param  resource  $connection
     */
    private function login($connection, SnmpOlt $olt): void
    {
        $this->readUntil($connection, self::PROMPT_LOGIN, 8);
        fwrite($connection, $olt->cli_username."\r\n");
        $this->readUntil($connection, self::PROMPT_PASSWORD, 8);
        fwrite($connection, ((string) $olt->cli_password)."\r\n");
        $this->readUntil($connection, self::PROMPT_ANY, 8);
    }

    /**
     * Baca sampai prompt CLI ($promptRegex) muncul di ekor buffer — bukan menunggu jeda diam — sehingga
     * cepat (kembali begitu OLT selesai, ~detik). Auto-jawab spasi untuk pager. $max = batas aman.
     *
     * @param  resource  $connection
     */
    private function readUntil($connection, string $promptRegex, float $max, bool $answerPager = false): string
    {
        $buffer = '';
        $start = microtime(true);

        while (microtime(true) - $start < $max) {
            $chunk = fread($connection, 8192);

            if ($chunk === '' || $chunk === false) {
                usleep(30_000);

                continue;
            }

            $buffer .= $chunk;

            if ($answerPager && preg_match('/--\s*more\s*--|press any key|\(more\)|next page/i', $chunk)) {
                fwrite($connection, ' ');

                continue;
            }

            if (preg_match($promptRegex, substr($buffer, -160))) {
                break;
            }
        }

        return $buffer;
    }

    /**
     * Parse tabel `show ont info all` → baris ONU bentuk cache. Public agar bisa diuji unit
     * dengan sampel output asli.
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

    private function detectError(string $output): ?string
    {
        $lower = strtolower($output);

        foreach (self::ERROR_NEEDLES as $needle) {
            if (str_contains($lower, $needle)) {
                return $needle;
            }
        }

        return null;
    }
}
