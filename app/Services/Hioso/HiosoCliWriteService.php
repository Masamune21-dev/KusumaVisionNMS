<?php

namespace App\Services\Hioso;

use App\Models\SnmpOlt;
use App\Support\SmartOltSupport;
use App\Support\Telnet\TelnetIacFilter;
use RuntimeException;

/**
 * Aksi write ONU HiOSO / V-Sol EPON via CLI telnet — reboot / enable-disable / delete / save-config.
 *
 * Berdiri sendiri (tidak memakai plumbing CLI C-Data). Quirk HiOSO yang ditangani (guide §2.2 & §10):
 *   - CRLF (`\r\n`) WAJIB tiap baris (RFC 854 strict; `\n` saja tak dianggap Enter).
 *   - Banner login ~225 byte + IAC negotiation → prompt `Username:` agak lambat (timeout longgar).
 *
 * **Dua dialek**, dipilih otomatis via {@see SmartOltSupport::isHiosoHa7302()}:
 *   - **HA7304**: `EPON>` → `enable` → `EPON#` → `conf t` → `interface epon 0/{PON}` →
 *     `onu {ONU} name|reboot|activate|deactivate` / `delete onu {ONU}` (guide §5.5). Rename via CLI.
 *   - **HA7302** (mis. HA7302CSM v7.76, terverifikasi live Jul 2026): login **3-lapis** (login +
 *     Access + Enable password) — semua dijawab `cli_password`. ONU dialamati `olt/pon/onu` = `1/{port}/{onu}`
 *     (oltId chip = 1; `port` = pon dari index SNMP; `onu` = LLID datar 1..128 = onu_id, terbukti cocok
 *     dengan `search mac-address`). Node `epon` → `pon 1/{port}` → `set onu {onu} reboot`;
 *     `delete onu 1/{port}/{onu}`; `set pon 1/{port} onu {onu} auth-mode pass|deny` (enable/disable).
 *     **TAK ada rename di CLI** → rename ditangani `HiosoEponSnmpService::setOnuName` (SNMP SET).
 *
 * Nama ONU (HA7304): alfanumerik + `_ - .`, spasi → `_`, maks 32 karakter.
 */
class HiosoCliWriteService
{
    /** @var list<string> pola output yang menandakan CLI menolak perintah (case-insensitive). */
    private array $errorNeedles = [
        'invalid input', 'unknown command', 'ambiguous command', 'incomplete command',
        'command incomplete', 'no command matched', 'command rejected', 'permission denied',
        'authorization failed', 'not support', 'operation failed', 'failure:', 'error:',
        '% bad', '% invalid',
    ];

    /**
     * Negosiator IAC telnet aktif untuk sesi berjalan (di-set per {@see self::openSession}). HA7302
     * MENAHAN banner login sampai opsi telnet dijawab; tanpa ini agen diam & login timeout. HA7304 tak
     * memerlukannya (dibiarkan null → perilaku byte-for-byte seperti sebelumnya, tak ada risiko regresi).
     */
    private ?TelnetIacFilter $iac = null;

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
        if (SmartOltSupport::isHiosoHa7302($olt)) {
            // epon → pon 1/{port} → set onu {onu} reboot → exit
            return $this->runHa7302Epon($olt, [
                'pon '.$this->ha7302Pon($port),
                "set onu {$onuId} reboot",
                'exit',
            ], confirm: true);
        }

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
        if (SmartOltSupport::isHiosoHa7302($olt)) {
            // HA7302: auth-mode per-ONU di node epon. `deny` = blokir (nonaktif), `pass` = izinkan (aktif).
            $verb = $active ? 'pass' : 'deny';

            return $this->runHa7302Epon($olt, [
                'set pon '.$this->ha7302Pon($port)." onu {$onuId} auth-mode {$verb}",
            ]);
        }

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
        if (SmartOltSupport::isHiosoHa7302($olt)) {
            // HA7302: `delete onu {olt}/{pon}/{onu}` di node epon (ONUIF, bukan onuId telanjang).
            return $this->runHa7302Epon($olt, [
                'delete onu '.$this->ha7302Onuif($port, $onuId),
            ], confirm: true);
        }

        return $this->runInPon($olt, $port, ["delete onu {$onuId}"], confirm: true);
    }

    /**
     * Simpan running-config ke memori OLT: `enable` → `write`. {@see self::openSession()} sudah
     * masuk level enable (`EPON#`), jadi tinggal `write`. Konfirmasi (bila muncul) dijawab otomatis.
     *
     * @return array{ok: bool, output: string, error: ?string}
     */
    public function saveConfig(SnmpOlt $olt): array
    {
        $connection = $this->openSession($olt);
        $output = '';

        try {
            fwrite($connection, "write\r\n");
            $output .= $this->readUntil($connection, '/#\s*$/', 40, true);
        } finally {
            fclose($connection);
        }

        $output = $this->mask($output, $olt);
        $error = $this->detectError($output);

        return ['ok' => $error === null, 'output' => $output, 'error' => $error];
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

        // HA7302 menahan banner sampai opsi telnet IAC dijawab → aktifkan negosiator untuk sesi ini.
        // HA7304 tetap null (tanpa IAC, seperti semula). Reset tiap openSession (filter stateful).
        $this->iac = SmartOltSupport::isHiosoHa7302($olt) ? new TelnetIacFilter : null;

        $connection = @fsockopen($olt->ip, (int) ($olt->cli_port ?: 23), $errno, $errstr, 12);
        if (! $connection) {
            throw new RuntimeException("Koneksi telnet gagal: {$errstr} ({$errno})");
        }

        stream_set_timeout($connection, 2);
        stream_set_blocking($connection, false);

        $this->readUntil($connection, '/(user ?name|login|username)\s*:\s*$/i', 15);
        fwrite($connection, $olt->cli_username."\r\n");

        if (SmartOltSupport::isHiosoHa7302($olt)) {
            // HA7302: login 3-lapis (Password → Access Password → Enable Password). Jawab tiap prompt
            // password dengan cli_password, kirim `enable` di prompt `>`, berhenti di prompt `#`.
            $this->loginMultiTier($connection, $olt);

            return $connection;
        }

        $this->readUntil($connection, '/(password|passwd)\s*:\s*$/i', 20);
        fwrite($connection, ((string) $olt->cli_password)."\r\n");
        $this->readUntil($connection, '/[\w\-.()\/]+\s*[>#]\s*$/', 12); // EPON>
        fwrite($connection, "enable\r\n");
        $this->readUntil($connection, '/#\s*$/', 8); // EPON#

        return $connection;
    }

    /**
     * Login bertingkat HA7302: agen meminta beberapa password beruntun (login → Access → Enable) yang
     * SEMUANYA dijawab `cli_password`, dengan `enable` disisipkan saat prompt user `>` muncul. Loop
     * generik ini menangani jumlah tingkat berapa pun sampai mencapai prompt enable `#`.
     *
     * @param  resource  $connection
     */
    private function loginMultiTier($connection, SnmpOlt $olt): void
    {
        $password = (string) $olt->cli_password;
        $prompt = '/((pass\s?word|passwd)\s*:\s*$)|(>\s*$)|(#\s*$)/i';

        for ($step = 0; $step < 8; $step++) {
            $tail = substr($this->readUntil($connection, $prompt, 12), -160);

            if (preg_match('/#\s*$/', $tail)) {
                return; // sudah di prompt enable
            }

            if (preg_match('/>\s*$/', $tail)) {
                fwrite($connection, "enable\r\n");

                continue;
            }

            if (preg_match('/(pass\s?word|passwd)\s*:\s*$/i', $tail)) {
                fwrite($connection, $password."\r\n");

                continue;
            }

            // Tak ada prompt dikenali dalam jendela → pancing dengan Enter, lalu coba lagi.
            fwrite($connection, "\r\n");
        }
        // Tak mencapai `#` → biarkan; command berikutnya akan gagal & dilaporkan detectError.
    }

    /**
     * Jalankan perintah HA7302 di node `epon` (setelah `configure terminal`). Beberapa perintah
     * (mis. reboot) menyelam ke sub-node `pon 1/{port}` lalu `exit` di dalam daftar `$commands`.
     *
     * @param  list<string>  $commands
     * @return array{ok: bool, output: string, error: ?string}
     */
    private function runHa7302Epon(SnmpOlt $olt, array $commands, bool $confirm = false): array
    {
        $connection = $this->openSession($olt);
        $output = '';

        try {
            $this->command($connection, 'configure terminal', 6);
            $this->command($connection, 'epon', 6);
            foreach ($commands as $command) {
                fwrite($connection, $command."\r\n");
                $output .= $this->readUntil($connection, '/#\s*$/', 25, $confirm);
            }
            $this->command($connection, 'end', 5);
        } finally {
            fclose($connection);
        }

        $output = $this->mask($output, $olt);
        $error = $this->detectError($output);

        return ['ok' => $error === null, 'output' => $output, 'error' => $error];
    }

    /** ONUIF CLI HA7302 `olt/pon/onu` (oltId chip = 1, pon = `port` index SNMP). */
    private function ha7302Onuif(int $port, int $onuId): string
    {
        return "1/{$port}/{$onuId}";
    }

    /** Alamat PON CLI HA7302 `olt/pon` (oltId chip = 1). */
    private function ha7302Pon(int $port): string
    {
        return "1/{$port}";
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

            // HA7302: strip IAC & balas negosiasi opsi (WONT/DONT) supaya agen mengirim banner/prompt.
            // HA7304 (iac null): $chunk apa adanya — perilaku lama tak berubah.
            if ($this->iac !== null) {
                [$chunk, $reply] = $this->iac->feed($chunk);
                if ($reply !== '') {
                    fwrite($connection, $reply);
                }
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
