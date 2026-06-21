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

    /**
     * Inventory penuh ONU lewat `show ont info all`.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getOnts(SnmpOlt $olt): array
    {
        return $this->parseOntInfo($this->runShow($olt, 'show ont info all'));
    }

    private function runShow(SnmpOlt $olt, string $command): string
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
            $this->readUntil($connection, self::PROMPT_ENABLE, 6);
            fwrite($connection, $command."\r\n");
            $output = $this->readUntil($connection, self::PROMPT_ENABLE, 30, true);
        } finally {
            fclose($connection);
        }

        if (($error = $this->detectError($output)) !== null) {
            throw new RuntimeException("CLI menolak '{$command}': {$error}");
        }

        return $output;
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
