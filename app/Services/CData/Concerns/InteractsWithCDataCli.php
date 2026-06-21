<?php

namespace App\Services\CData\Concerns;

use App\Models\SnmpOlt;
use RuntimeException;

/**
 * Plumbing telnet bersama untuk CLI C-Data (read & write). Baca **berbasis prompt** (berhenti begitu
 * prompt `#`/`>` muncul) supaya cepat. Login prompt `User name:`/`Password:` CRLF strict.
 */
trait InteractsWithCDataCli
{
    /** @var list<string> */
    private array $cliErrorNeedles = [
        'invalid input', 'unknown command', 'ambiguous command', 'incomplete command',
        'command rejected', 'permission denied', 'authorization failed', 'not support',
        'operation failed', 'failure:', '% bad', '% invalid', '% command', '% there is no',
    ];

    /**
     * Buka telnet, login, masuk level enable. Pemanggil wajib fclose() di finally.
     *
     * @return resource
     */
    protected function openCliSession(SnmpOlt $olt)
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

        $this->cliReadUntil($connection, '/(user ?name|login|username)\s*:\s*$/i', 8);
        fwrite($connection, $olt->cli_username."\r\n");
        $this->cliReadUntil($connection, '/(password|passwd)\s*:\s*$/i', 8);
        fwrite($connection, ((string) $olt->cli_password)."\r\n");
        $this->cliReadUntil($connection, '/[\w\-.]+\s*[>#]\s*$/', 8);
        fwrite($connection, "enable\r\n");
        $this->cliReadUntil($connection, '/#\s*$/', 6);

        return $connection;
    }

    /**
     * Kirim satu command, baca sampai prompt `#` (enable/config/interface).
     *
     * @param  resource  $connection
     */
    protected function cliCommand($connection, string $command, float $max, bool $answerPager = false): string
    {
        fwrite($connection, $command."\r\n");

        return $this->cliReadUntil($connection, '/#\s*$/', $max, $answerPager);
    }

    /**
     * Baca sampai $promptRegex muncul di ekor buffer (atau total > $max). Auto-jawab pager (spasi)
     * dan konfirmasi y/n bila $answerConfirm.
     *
     * @param  resource  $connection
     */
    protected function cliReadUntil($connection, string $promptRegex, float $max, bool $answerPager = false, bool $answerConfirm = false): string
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

    protected function cliDetectError(string $output): ?string
    {
        $lower = strtolower($output);

        foreach ($this->cliErrorNeedles as $needle) {
            if (str_contains($lower, $needle)) {
                return $needle;
            }
        }

        return null;
    }
}
