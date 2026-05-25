<?php

namespace App\Services;

use App\Models\SnmpOlt;
use RuntimeException;

class ZteCliProvisioningExecutor
{
    /**
     * @return array{ok:bool, output:string, error:string|null}
     */
    public function execute(SnmpOlt $olt, string $script): array
    {
        if ($olt->cli_transport !== 'telnet') {
            throw new RuntimeException('Eksekusi otomatis saat ini baru mendukung Telnet. Set CLI transport OLT ke telnet.');
        }

        if (! $olt->cli_username || ! $olt->cli_password) {
            throw new RuntimeException('Username dan password CLI OLT wajib diisi sebelum eksekusi provisioning.');
        }

        $port = $olt->cli_port ?: $olt->defaultCliPort();
        $connection = @fsockopen($olt->ip, $port, $errno, $errstr, 10);

        if (! is_resource($connection)) {
            throw new RuntimeException("Gagal connect Telnet ke {$olt->ip}:{$port}: {$errstr} ({$errno})");
        }

        stream_set_timeout($connection, 2);
        stream_set_blocking($connection, false);

        try {
            $output = $this->login($connection, $olt);

            foreach ($this->commands($script) as $command) {
                $output .= "\n> {$command}\n";
                fwrite($connection, $command."\n");
                $output .= $this->readUntilIdle($connection);
            }

            fwrite($connection, "exit\n");
            $output .= $this->readUntilIdle($connection, 1);

            if (preg_match('/confirm to logout without saving|yes\/no|y\/n/i', $output)) {
                fwrite($connection, "no\n");
                $output .= $this->readUntilIdle($connection, 1);
            }
        } finally {
            fclose($connection);
        }

        $error = $this->detectError($output);

        return [
            'ok' => $error === null,
            'output' => $this->maskSecrets($output, $olt),
            'error' => $error,
        ];
    }

    /**
     * @param resource $connection
     */
    private function login($connection, SnmpOlt $olt): string
    {
        $output = $this->readUntilIdle($connection);
        $lower = strtolower($output);

        if (str_contains($lower, 'login') || str_contains($lower, 'username')) {
            fwrite($connection, $olt->cli_username."\n");
            $output .= $this->readUntilIdle($connection);
        }

        if (str_contains(strtolower($output), 'password')) {
            fwrite($connection, $olt->cli_password."\n");
            $output .= $this->readUntilIdle($connection);
        }

        return $output;
    }

    /**
     * @param resource $connection
     */
    private function readUntilIdle($connection, int $timeoutSeconds = 2): string
    {
        $output = '';
        $started = microtime(true);
        $lastRead = microtime(true);

        while ((microtime(true) - $started) < $timeoutSeconds) {
            $chunk = fread($connection, 8192);

            if ($chunk === false || $chunk === '') {
                if ($output !== '' && (microtime(true) - $lastRead) >= 0.35) {
                    break;
                }

                usleep(150000);
                continue;
            }

            $output .= $chunk;
            $lastRead = microtime(true);
        }

        return $output;
    }

    /**
     * @return array<int, string>
     */
    private function commands(string $script): array
    {
        return collect(explode("\n", $script))
            ->map(fn (string $line) => trim($line))
            ->filter(fn (string $line) => $line !== '')
            ->values()
            ->all();
    }

    private function detectError(string $output): ?string
    {
        if (preg_match('/(invalid input|unknown command|incomplete command|command failed|error:|failed)/i', $output, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function maskSecrets(string $output, SnmpOlt $olt): string
    {
        $values = array_filter([
            $olt->cli_password,
        ]);

        foreach ($values as $value) {
            $output = str_replace((string) $value, '********', $output);
        }

        return trim($output);
    }
}
