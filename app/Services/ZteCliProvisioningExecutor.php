<?php

namespace App\Services;

use App\Models\SnmpOlt;
use App\Support\CliOutputSanitizer;
use RuntimeException;

class ZteCliProvisioningExecutor
{
    /**
     * @return array{ok:bool, output:string, error:string|null}
     */
    public function execute(SnmpOlt $olt, string $script): array
    {
        return $this->run($olt, $script, false);
    }

    /**
     * Like execute(), but auto-answers "y" to confirmation prompts (e.g. ONU reboot).
     *
     * @return array{ok:bool, output:string, error:string|null}
     */
    public function executeConfirmable(SnmpOlt $olt, string $script): array
    {
        return $this->run($olt, $script, true);
    }

    /**
     * @return array{ok:bool, output:string, error:string|null}
     */
    private function run(SnmpOlt $olt, string $script, bool $autoConfirmYes): array
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
                $output .= $this->readUntilIdle($connection, 15, $autoConfirmYes);
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

        $output = CliOutputSanitizer::clean($output);
        $error = $this->detectError($output);

        return [
            'ok' => $error === null,
            'output' => $this->maskSecrets($output, $olt),
            'error' => $error,
        ];
    }

    /**
     * @param  resource  $connection
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
     * @param  resource  $connection
     */
    private function readUntilIdle($connection, int $timeoutSeconds = 8, bool $autoConfirmYes = false): string
    {
        $output = '';
        $started = microtime(true);
        $lastRead = microtime(true);
        $confirms = 0;

        while ((microtime(true) - $started) < $timeoutSeconds) {
            $chunk = fread($connection, 8192);

            if ($chunk === false || $chunk === '') {
                if ($output !== '' && $this->hasCliPrompt($output) && (microtime(true) - $lastRead) >= 0.25) {
                    break;
                }

                if ($output !== '' && (microtime(true) - $lastRead) >= 1.25) {
                    break;
                }

                usleep(150000);

                continue;
            }

            $output .= $chunk;
            $lastRead = microtime(true);

            if ($this->hasPagerPrompt($output)) {
                fwrite($connection, "\n");
                $output = $this->stripPagerPrompts($output);
                $started = microtime(true);
                $lastRead = microtime(true);
            }

            if ($autoConfirmYes && $confirms < 3 && $this->hasConfirmPrompt($output)) {
                fwrite($connection, "y\n");
                $confirms++;
                $output = $this->stripConfirmPrompts($output);
                $started = microtime(true);
                $lastRead = microtime(true);
            }
        }

        return $this->stripPagerPrompts($output);
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

    private function hasPagerPrompt(string $output): bool
    {
        return preg_match('/(--More--|----\s*More\s*----|<---\s*More\s*--->|press\s+(enter|return|any key)\s+to\s+continue)/i', $output) === 1;
    }

    private function hasCliPrompt(string $output): bool
    {
        return preg_match('/[\r\n][A-Za-z0-9_.()\/-]+(?:\(config[^\)]*\))?#\s*$/', $output) === 1;
    }

    private function stripPagerPrompts(string $output): string
    {
        return preg_replace('/(--More--|----\s*More\s*----|<---\s*More\s*--->|press\s+(enter|return|any key)\s+to\s+continue)/i', '', $output) ?? $output;
    }

    private function hasConfirmPrompt(string $output): bool
    {
        return preg_match('/(\(y\/n\)|\[y\/n\]|yes\/no|are you sure|confirm to reboot|continue\?)/i', $output) === 1;
    }

    private function stripConfirmPrompts(string $output): string
    {
        return preg_replace('/(\(y\/n\)|\[y\/n\]|yes\/no|are you sure|confirm to reboot|continue\?)/i', '', $output) ?? $output;
    }
}
