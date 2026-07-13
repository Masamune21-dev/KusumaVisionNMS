<?php

namespace App\Services\Hioso;

use App\Models\SnmpOlt;
use RuntimeException;

/**
 * Aksi write ONU HiOSO / V-Sol EPON (HA7304) via CLI telnet — rename & reboot (guide §5.5).
 *
 * Berdiri sendiri (tidak memakai plumbing CLI C-Data). Quirk HiOSO yang ditangani (guide §2.2 & §10):
 *   - CRLF (`\r\n`) WAJIB tiap baris (RFC 854 strict; `\n` saja tak dianggap Enter).
 *   - Banner login ~225 byte + IAC negotiation → prompt `Username:` agak lambat (timeout longgar).
 *   - Prompt `EPON>` (user) → `enable` → `EPON#` → `conf t` → `EPON(config)#` →
 *     `interface epon 0/{PON}` → `EPON(epon_0/{PON})#` → `onu {ONU} name {label}` / `onu {ONU} reboot`.
 *
 * Nama ONU: alfanumerik + `_ - .`, spasi → `_`, maks 32 karakter.
 */
class HiosoCliWriteService
{
    /** @var list<string> pola output yang menandakan CLI menolak perintah (case-insensitive). */
    private array $errorNeedles = [
        'invalid input', 'unknown command', 'ambiguous command', 'incomplete command',
        'command rejected', 'permission denied', 'authorization failed', 'not support',
        'operation failed', 'failure:', 'error:', '% bad', '% invalid',
    ];

    /**
     * Set nama ONU: `onu {ONU} name {label}`. Nama kosong ditolak (HiOSO tak punya "no name" teruji).
     *
     * @return array{ok: bool, output: string, error: ?string}
     */
    public function setName(SnmpOlt $olt, int $port, int $onuId, ?string $name): array
    {
        $label = $this->sanitizeName($name);
        if ($label === '') {
            return ['ok' => false, 'output' => '', 'error' => 'Nama ONU HiOSO tidak boleh kosong (hanya huruf/angka/_-.).'];
        }

        return $this->runInPon($olt, $port, ["onu {$onuId} name {$label}"]);
    }

    /**
     * Reboot satu ONU: `onu {ONU} reboot`.
     *
     * @return array{ok: bool, output: string, error: ?string}
     */
    public function reboot(SnmpOlt $olt, int $port, int $onuId): array
    {
        return $this->runInPon($olt, $port, ["onu {$onuId} reboot"], confirm: true);
    }

    /**
     * Enable/disable (aktif/nonaktif) satu ONU tanpa menghapus registrasi, di dalam
     * `interface epon 0/{PON}` → `onu {ONU} activate` / `onu {ONU} deactivate`
     * (terverifikasi live via context-help HA7304 `onu {id} ?`, keduanya command lengkap
     * `--Press Enter--`). Beda dari `dereg`/`delete` yang menghapus registrasi.
     *
     * @return array{ok: bool, output: string, error: ?string}
     */
    public function setState(SnmpOlt $olt, int $port, int $onuId, bool $active): array
    {
        $verb = $active ? 'activate' : 'deactivate';

        return $this->runInPon($olt, $port, ["onu {$onuId} {$verb}"]);
    }

    /**
     * Hapus ONU di dalam `interface epon 0/{PON}` → `delete onu {ONU}` (verb "delete config",
     * terverifikasi live via help HA7304 `EPON(epon_0/1)# ?`). Bukan `onu {id} delete`/`no onu {id}`
     * (keduanya "unknown command"). Alternatif `dereg onu {ONU}` hanya de-register (bisa muncul lagi
     * bila ONU auto-auth); "delete config" lebih permanen. Destruktif — auto-jawab konfirmasi bila muncul.
     *
     * @return array{ok: bool, output: string, error: ?string}
     */
    public function delete(SnmpOlt $olt, int $port, int $onuId): array
    {
        return $this->runInPon($olt, $port, ["delete onu {$onuId}"], confirm: true);
    }

    /**
     * @param  list<string>  $commands
     * @return array{ok: bool, output: string, error: ?string}
     */
    private function runInPon(SnmpOlt $olt, int $port, array $commands, bool $confirm = false): array
    {
        $connection = $this->openSession($olt);
        $output = '';

        try {
            $this->command($connection, 'conf t', 6);
            $this->command($connection, "interface epon 0/{$port}", 6);
            foreach ($commands as $command) {
                fwrite($connection, $command."\r\n");
                $output .= $this->readUntil($connection, '/#\s*$/', 20, $confirm);
            }
            $this->command($connection, 'end', 5);
        } finally {
            fclose($connection);
        }

        $output = $this->mask($output, $olt);
        $error = $this->detectError($output);

        return ['ok' => $error === null, 'output' => $output, 'error' => $error];
    }

    /**
     * Buka telnet, login (CRLF, banner panjang), masuk enable. Pemanggil wajib fclose().
     *
     * @return resource
     */
    private function openSession(SnmpOlt $olt)
    {
        if ($olt->cli_transport !== 'telnet') {
            throw new RuntimeException('CLI HiOSO hanya mendukung Telnet. Set CLI transport OLT ke telnet.');
        }

        $connection = @fsockopen($olt->ip, (int) ($olt->cli_port ?: 23), $errno, $errstr, 12);
        if (! $connection) {
            throw new RuntimeException("Koneksi telnet gagal: {$errstr} ({$errno})");
        }

        stream_set_timeout($connection, 2);
        stream_set_blocking($connection, false);

        $this->readUntil($connection, '/(user ?name|login|username)\s*:\s*$/i', 15);
        fwrite($connection, $olt->cli_username."\r\n");
        $this->readUntil($connection, '/(password|passwd)\s*:\s*$/i', 20);
        fwrite($connection, ((string) $olt->cli_password)."\r\n");
        $this->readUntil($connection, '/[\w\-.()\/]+\s*[>#]\s*$/', 12); // EPON>
        fwrite($connection, "enable\r\n");
        $this->readUntil($connection, '/#\s*$/', 8); // EPON#

        return $connection;
    }

    /**
     * Kirim satu command, baca sampai prompt `#`.
     *
     * @param  resource  $connection
     */
    private function command($connection, string $command, float $max): string
    {
        fwrite($connection, $command."\r\n");

        return $this->readUntil($connection, '/#\s*$/', $max);
    }

    /**
     * Baca sampai $promptRegex muncul di ekor buffer (atau total > $max detik). Auto-jawab
     * konfirmasi y/n bila $answerConfirm.
     *
     * @param  resource  $connection
     */
    private function readUntil($connection, string $promptRegex, float $max, bool $answerConfirm = false): string
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

            if ($answerConfirm && preg_match('/\[y\/n\]|\(y\/n\)|\byes\/no\b|are you sure|confirm/i', $chunk)) {
                fwrite($connection, "y\r\n");

                continue;
            }

            if (preg_match($promptRegex, substr($buffer, -160))) {
                break;
            }
        }

        return $buffer;
    }

    private function detectError(string $output): ?string
    {
        $lower = strtolower($output);

        foreach ($this->errorNeedles as $needle) {
            if (str_contains($lower, $needle)) {
                return $needle;
            }
        }

        return null;
    }

    private function sanitizeName(?string $value): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]/', ' ', (string) $value) ?? '';
        $value = preg_replace('/\s+/', '_', trim($value)) ?? '';
        $value = preg_replace('/[^A-Za-z0-9_\-.]/', '', $value) ?? '';

        return mb_strimwidth(trim($value, '_-.'), 0, 32, '');
    }

    private function mask(string $output, SnmpOlt $olt): string
    {
        $password = (string) $olt->cli_password;

        return $password !== '' ? str_replace($password, '****', $output) : $output;
    }
}
