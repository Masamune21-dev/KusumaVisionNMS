<?php

namespace App\Services;

use App\Models\SnmpOlt;
use App\Support\CliOutputSanitizer;
use RuntimeException;

class ZteCliProvisioningExecutor
{
    /**
     * @param  bool  $largeOutput  true untuk perintah berukuran besar (mis. `show running-config`
     *                             seluruh OLT): baca dengan toleransi jeda & batas total jauh lebih
     *                             longgar supaya output tak terpotong di tengah.
     * @return array{ok:bool, output:string, error:string|null}
     */
    public function execute(SnmpOlt $olt, string $script, bool $largeOutput = false): array
    {
        return $this->run($olt, $script, false, $largeOutput);
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
     * Simpan running-config ke memori OLT (perintah `write`). Di C300 dengan config besar,
     * `write` bisa HENING ~30 detik sebelum prompt kembali — jadi kita menunggu prompt CLI
     * muncul lagi (patokan {@see self::readUntilIdle()}), dengan ambang jeda yang jauh lebih
     * besar dari durasi hening itu supaya read tak berhenti prematur di tengah write.
     *
     * @return array{ok:bool, output:string, error:string|null}
     */
    public function saveConfig(SnmpOlt $olt): array
    {
        if ($olt->cli_transport !== 'telnet') {
            throw new RuntimeException('Simpan konfigurasi saat ini baru mendukung Telnet. Set CLI transport OLT ke telnet.');
        }

        if (! $olt->cli_username || ! $olt->cli_password) {
            throw new RuntimeException('Username dan password CLI OLT wajib diisi sebelum simpan konfigurasi.');
        }

        $port = $olt->cli_port ?: $olt->defaultCliPort();
        $connection = @fsockopen($olt->ip, $port, $errno, $errstr, 10);

        if (! is_resource($connection)) {
            throw new RuntimeException("Gagal connect Telnet ke {$olt->ip}:{$port}: {$errstr} ({$errno})");
        }

        stream_set_timeout($connection, 2);
        stream_set_blocking($connection, false);

        $output = '';
        $sessionError = null;

        try {
            $output = $this->login($connection, $olt);

            $output .= "\n> write\n";
            fwrite($connection, "write\n");
            // Ambang jeda 75s > durasi hening write (~30s) → hanya prompt yang menghentikan
            // pembacaan; batas keras 120s sebagai jaring pengaman bila OLT ngadat.
            $output .= $this->readUntilIdle($connection, 75.0, true, 120);

            fwrite($connection, "exit\n");
            $output .= $this->readUntilIdle($connection, 0.8, false, 10);
        } catch (\Throwable $e) {
            $sessionError = $this->cliSessionError($olt, $e);
        } finally {
            if (is_resource($connection)) {
                fclose($connection);
            }
        }

        $output = CliOutputSanitizer::clean($output);
        $error = $sessionError ?? $this->detectError($output);

        return [
            'ok' => $error === null,
            'output' => $this->maskSecrets($output, $olt),
            'error' => $error,
        ];
    }

    /**
     * @return array{ok:bool, output:string, error:string|null}
     */
    private function run(SnmpOlt $olt, string $script, bool $autoConfirmYes, bool $largeOutput = false): array
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

        $output = '';
        $sessionError = null;

        try {
            $output = $this->login($connection, $olt);

            foreach ($this->commands($script) as $command) {
                $output .= "\n> {$command}\n";
                fwrite($connection, $command."\n");
                // Output besar (running-config penuh) bisa streaming puluhan detik tanpa jeda pager
                // (terminal length 0) — pakai toleransi jeda & batas total yang jauh lebih longgar.
                $output .= $largeOutput
                    ? $this->readUntilIdle($connection, 4.0, $autoConfirmYes, 240)
                    : $this->readUntilIdle($connection, 1.25, $autoConfirmYes, 45);
            }

            fwrite($connection, "exit\n");
            $output .= $this->readUntilIdle($connection, 0.8, false, 10);

            if (preg_match('/confirm to logout without saving|yes\/no|y\/n/i', $output)) {
                fwrite($connection, "no\n");
                $output .= $this->readUntilIdle($connection, 0.8, false, 10);
            }
        } catch (\Throwable $e) {
            // OLT memutus telnet di tengah sesi (mis. broken pipe saat write, telnet
            // diblokir ACL manajemen, atau daemon telnet OLT tidak aktif) — ubah jadi
            // error yang bisa ditampilkan alih-alih exception yang membuat halaman 500.
            $sessionError = $this->cliSessionError($olt, $e);
        } finally {
            if (is_resource($connection)) {
                fclose($connection);
            }
        }

        $output = CliOutputSanitizer::clean($output);
        $error = $sessionError ?? $this->detectError($output);

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
     * Ringkas kegagalan sesi CLI (mis. broken pipe saat OLT memutus telnet) menjadi
     * pesan yang aman ditampilkan ke pengguna, dengan rahasia tetap tersamar.
     */
    private function cliSessionError(SnmpOlt $olt, \Throwable $e): string
    {
        $port = $olt->cli_port ?: $olt->defaultCliPort();
        $detail = trim($e->getMessage());

        $message = "Sesi CLI ke {$olt->ip}:{$port} terputus sebelum selesai — pastikan "
            .'telnet aktif di OLT dan tidak diblokir ACL manajemen untuk IP server ini.'
            .($detail !== '' ? " (Detail: {$detail})" : '');

        return $this->maskSecrets($message, $olt);
    }

    /**
     * Baca output telnet sampai "selesai". Selesai dideteksi bila prompt CLI muncul (cepat) atau
     * output sudah sunyi (tak ada data) selama $quietSeconds. Batas $maxTotalSeconds adalah pengaman
     * keras absolut agar output raksasa / OLT ngadat tak menggantung proses selamanya.
     *
     * PENTING: patokan berhenti adalah INAKTIVITAS (jeda sejak data terakhir), BUKAN total waktu.
     * Selama data masih mengalir (mis. `show running-config` seluruh OLT via `terminal length 0`
     * yang bisa puluhan detik tanpa jeda pager), pembacaan terus berlanjut dan tak terpotong.
     *
     * @param  resource  $connection
     */
    private function readUntilIdle($connection, float $quietSeconds = 1.25, bool $autoConfirmYes = false, int $maxTotalSeconds = 45): string
    {
        $output = '';
        $hardStart = microtime(true);
        $lastRead = microtime(true);
        $confirms = 0;

        while (true) {
            $now = microtime(true);

            // Pengaman keras absolut (bukan patokan normal — hanya jaring pengaman).
            if (($now - $hardStart) >= $maxTotalSeconds) {
                break;
            }

            $chunk = fread($connection, 8192);

            if ($chunk === false || $chunk === '') {
                // Prompt terlihat & sudah sedikit sunyi → output selesai.
                if ($output !== '' && $this->hasCliPrompt($output) && ($now - $lastRead) >= 0.25) {
                    break;
                }

                // Sunyi cukup lama → anggap selesai (patokan utama end-of-output).
                if ($output !== '' && ($now - $lastRead) >= $quietSeconds) {
                    break;
                }

                usleep(150000);

                continue;
            }

            $output .= $chunk;
            $lastRead = microtime(true); // ada data → reset timer inaktivitas (mencegah truncation di stream panjang)

            if ($this->hasPagerPrompt($output)) {
                fwrite($connection, "\n");
                $output = $this->stripPagerPrompts($output);
                $lastRead = microtime(true);
            }

            if ($autoConfirmYes && $confirms < 3 && $this->hasConfirmPrompt($output)) {
                fwrite($connection, "y\n");
                $confirms++;
                $output = $this->stripConfirmPrompts($output);
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

    /**
     * Scan CLI output for rejected commands and return a human summary naming the
     * exact command(s) the OLT refused, or null if everything was accepted.
     *
     * ZTE reports failures as `%Code <n>: …` / `%Error …` lines right after the
     * offending `> command`. The benign `%Info …` config-mode banner is ignored.
     * Returning non-null flips the result to "not ok" so the UI flags a partial
     * failure instead of falsely reporting success.
     */
    private function detectError(string $output): ?string
    {
        $lastCommand = null;
        $failures = [];

        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                continue;
            }

            if (str_starts_with($trimmed, '> ')) {
                $lastCommand = trim(substr($trimmed, 2));

                continue;
            }

            if (! $this->isErrorLine($trimmed)) {
                continue;
            }

            $failures[] = ($lastCommand !== null && $lastCommand !== '')
                ? "`{$lastCommand}` → {$trimmed}"
                : $trimmed;
        }

        if ($failures === []) {
            return null;
        }

        return implode('; ', array_values(array_unique($failures)));
    }

    private function isErrorLine(string $line): bool
    {
        // `%Info …` is the benign "Enter configuration commands" banner, and the
        // login warning "% The password is not strong …" is not a config error.
        if (str_starts_with($line, '%Info') || stripos($line, 'password is not strong') !== false) {
            return false;
        }

        return preg_match(
            '/(%Code\b|%Error\b|invalid input|unknown command|incomplete command|command failed|operation is forbidden|already exist|conflicting with|error:)/i',
            $line,
        ) === 1;
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
