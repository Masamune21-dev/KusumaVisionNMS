<?php

namespace App\Services\CData;

use App\Models\SnmpOlt;
use App\Services\CData\Concerns\InteractsWithCDataCli;
use RuntimeException;

/**
 * Aksi write ONU C-Data via CLI telnet — rename (deskripsi), reboot & hapus.
 *
 * Sintaks identik EPON & GPON (terverifikasi help CLI live FD1608S/FD1108S #276/#277),
 * beda hanya keyword interface:
 *   (config)# interface {epon|gpon} 0/{slot}
 *   ont reboot {port} {onuId}
 *   ont description {port} {onuId} <text>      / no ont description {port} {onuId}
 *   ont delete {port} {onuId}                  # destruktif: deregister ONU dari OLT
 *
 * SNMP write ONU C-Data umumnya ditolak, jadi semuanya lewat CLI (guide §6 & §7).
 */
class CDataCliWriteService
{
    use InteractsWithCDataCli;

    /**
     * Set/hapus deskripsi (nama) ONU. Teks kosong → `no ont description`.
     *
     * @return array{ok: bool, output: string, error: ?string}
     */
    public function setDescription(SnmpOlt $olt, string $iface, int $slot, int $port, int $onuId, ?string $text): array
    {
        $clean = $this->sanitizeDescription($text);
        $command = $clean === ''
            ? "no ont description {$port} {$onuId}"
            : "ont description {$port} {$onuId} {$clean}";

        return $this->runInInterface($olt, $iface, $slot, [$command]);
    }

    /**
     * Reboot satu ONU.
     *
     * @return array{ok: bool, output: string, error: ?string}
     */
    public function reboot(SnmpOlt $olt, string $iface, int $slot, int $port, int $onuId): array
    {
        return $this->runInInterface($olt, $iface, $slot, ["ont reboot {$port} {$onuId}"], confirm: true);
    }

    /**
     * Hapus (deregister) satu ONU — `ont delete {port} {onuId}`. Destruktif: registrasi ONU
     * dihapus permanen dari OLT. OLT minta konfirmasi y/n → dijawab otomatis (confirm: true).
     *
     * @return array{ok: bool, output: string, error: ?string}
     */
    public function delete(SnmpOlt $olt, string $iface, int $slot, int $port, int $onuId): array
    {
        return $this->runInInterface($olt, $iface, $slot, ["ont delete {$port} {$onuId}"], confirm: true);
    }

    /**
     * @param  list<string>  $commands
     * @return array{ok: bool, output: string, error: ?string}
     */
    private function runInInterface(SnmpOlt $olt, string $iface, int $slot, array $commands, bool $confirm = false): array
    {
        $iface = strtolower($iface);
        if (! in_array($iface, ['epon', 'gpon'], true)) {
            throw new RuntimeException("Interface C-Data tidak dikenal: {$iface}");
        }

        $connection = $this->openCliSession($olt);
        $output = '';

        try {
            $this->cliCommand($connection, 'config', 5);
            $this->cliCommand($connection, "interface {$iface} 0/{$slot}", 5);
            foreach ($commands as $command) {
                fwrite($connection, $command."\r\n");
                $output .= $this->cliReadUntil($connection, '/#\s*$/', 15, false, $confirm);
            }
            $this->cliCommand($connection, 'end', 4);
        } finally {
            fclose($connection);
        }

        $output = $this->mask($output, $olt);
        $error = $this->cliDetectError($output);

        return ['ok' => $error === null, 'output' => $output, 'error' => $error];
    }

    private function sanitizeDescription(?string $text): string
    {
        $text = preg_replace('/[\x00-\x1F\x7F]/', '', (string) $text) ?? '';
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');

        return mb_substr($text, 0, 128);
    }

    private function mask(string $output, SnmpOlt $olt): string
    {
        $password = (string) $olt->cli_password;

        return $password !== '' ? str_replace($password, '****', $output) : $output;
    }
}
