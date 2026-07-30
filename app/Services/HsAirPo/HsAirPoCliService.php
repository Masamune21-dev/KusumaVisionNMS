<?php

namespace App\Services\HsAirPo;

use App\Models\SnmpOlt;
use App\Support\Telnet\TelnetIacFilter;
use RuntimeException;

/**
 * CLI telnet OLT HsAirPo / HSGQ (OEM Photon Broadband 12170, "4PON EPON-OLT").
 *
 * **Sumber tunggal data ONU family ini** — SNMP-nya tidak punya tabel ONU sama sekali
 * (terverifikasi full-walk live, lihat `docs/SMARTOLT_HSAIRPO_GUIDE.md` §2). Inventori diambil
 * dengan SATU perintah murah `show epon onu all info` (§3.1).
 *
 * Dialek CLI gaya Cisco-IOS/BDCOM: `Username:` → password → `EPON-OLT>` → `enable` → `EPON-OLT#`
 * (unit uji tanpa password enable; bila diminta, `cli_password` dikirim ulang). Ada negosiasi
 * **IAC telnet** di banner → dipakai ulang {@see TelnetIacFilter}. `terminal length 0` mematikan
 * pager, `--More--` tetap dijawab defensif.
 *
 * **Anti-hang wajib**: firmware ini punya perintah yang membekukan sesi (mis. `show epon port {n}
 * onu all optical-info`), jadi setiap pembacaan punya batas keras dan berakhir sebagai exception —
 * bukan menggantung worker polling.
 */
class HsAirPoCliService
{
    /** Batas waktu (detik) menunggu prompt sesudah sebuah perintah `show`. */
    private const COMMAND_TIMEOUT = 45.0;

    /** Batas waktu (detik) tiap tahap login. */
    private const LOGIN_TIMEOUT = 15.0;

    /** Prompt level enable (`EPON-OLT#`). */
    private const PROMPT_ENABLE = '/#[ \t]*$/';

    /** @var list<string> penanda CLI menolak perintah (case-insensitive). */
    private array $errorNeedles = [
        'invalid input', 'unknown command', 'ambiguous command', 'incomplete command',
        'permission denied', 'authorization failed',
    ];

    private ?TelnetIacFilter $iac = null;

    /**
     * Satu sesi telnet untuk seluruh kebutuhan scan: versi firmware + inventori ONU.
     * Digabung supaya scan/poll hanya membuka satu koneksi.
     *
     * @return array{version: array<string, string|null>, onus: array<int, array<string, mixed>>, total: ?int, online: ?int}
     */
    public function fetchSnapshot(SnmpOlt $olt): array
    {
        $connection = $this->openSession($olt);

        try {
            $version = $this->parseVersion($this->command($connection, 'show version', 15));
            $inventory = $this->command($connection, 'show epon onu all info', self::COMMAND_TIMEOUT);
        } finally {
            fclose($connection);
        }

        $this->assertAccepted($inventory, 'show epon onu all info');

        return [
            'version' => $version,
            'onus' => $this->parseOnuAllInfo($inventory),
            'total' => $this->parseFooterCount($inventory, 'total'),
            'online' => $this->parseFooterCount($inventory, 'online'),
        ];
    }

    /**
     * ONU yang terdeteksi tapi belum di-bind, per PON: `show epon port {n} onu-autofind-list all`
     * (sintaks terverifikasi live lewat context-help; varian tanpa `all` = "Incomplete command").
     * PON tanpa kandidat menjawab "Error: There is no ONU ..." → dianggap kosong.
     *
     * @param  array<int, int>  $ponPorts
     * @return array<int, array<string, mixed>>
     */
    public function fetchUnconfigured(SnmpOlt $olt, array $ponPorts): array
    {
        if ($ponPorts === []) {
            return [];
        }

        $connection = $this->openSession($olt);
        $found = [];

        try {
            foreach ($ponPorts as $pon) {
                $output = $this->command($connection, "show epon port {$pon} onu-autofind-list all", 20);

                foreach ($this->parseAutofindList($output) as $mac) {
                    $found[] = [
                        'slot' => 1,
                        'port' => $pon,
                        'mac' => $mac,
                        'serial_number' => $mac,
                        'onu_type' => null,
                        'interface' => "pon{$pon}",
                    ];
                }
            }
        } finally {
            fclose($connection);
        }

        return $found;
    }

    /**
     * Parse tabel `show epon onu all info` → baris ONU bentuk cache ZTE. Public agar bisa diuji unit.
     *
     * Format nyata (kolom dipisah campuran tab/spasi):
     * ```
     *         PON   ONU       MAC                     Control  Run      Config   Match
     *               ID                                flag     state    state    state
     *         1      1        0C:37:47:78:E9:27       Active   Online   Success  Match
     *         1      27       EC:23:7B:AF:B6:38       Active   Offline  Initial  Initial
     *   Total: 116, online 107
     * ```
     * `Run state` = Online/Offline; `Control flag` Active/Deactive → admin_state.
     *
     * @return array<int, array<string, mixed>>
     */
    public function parseOnuAllInfo(string $output): array
    {
        $onus = [];

        foreach (preg_split('/\r\n|\r|\n/', $output) ?: [] as $line) {
            $line = trim(str_replace("\t", ' ', $line));

            if (! preg_match('/^(\d+)\s+(\d+)\s+([0-9A-Fa-f]{2}(?::[0-9A-Fa-f]{2}){5})\s+(\S+)\s+(\S+)(?:\s+(\S+))?(?:\s+(\S+))?/', $line, $m)) {
                continue;
            }

            $pon = (int) $m[1];
            $onuId = (int) $m[2];
            $mac = strtoupper($m[3]);
            $controlFlag = $m[4];
            $runState = $m[5];
            $online = strcasecmp($runState, 'online') === 0;

            $onus["{$pon}.{$onuId}"] = [
                'onu_key' => "{$pon}.{$onuId}",
                'if_index' => null,
                // Chassis tunggal 4 PON → slot selalu 1, port = nomor PON (pon1..pon4 di IF-MIB).
                'slot' => 1,
                'port' => $pon,
                'onu_id' => $onuId,
                'interface' => "pon{$pon}:{$onuId}",
                'type_name' => null,
                // `show epon onu all info` tak memuat kolom nama; deskripsi per-ONU hanya ada di
                // `show epon port {n} onu {id} info` (1 perintah/ONU) → tak diambil saat scan.
                'name' => null,
                'description' => null,
                // EPON tak punya serial GPON — MAC adalah identifier ONU.
                'serial_number' => $mac,
                'mac' => $mac,
                'vendor_id' => null,
                'admin_state' => strcasecmp($controlFlag, 'active') === 0 ? 'enable' : 'disable',
                'phase_state' => $online ? 'Online' : 'Offline',
                'online' => $online,
                'last_down_cause' => null,
                'config_state' => $m[6] ?? null,
                'match_state' => $m[7] ?? null,
                // Rx per-ONU = Fase B (`show epon port {n} onu {id} optical-info`, per-ONU & mahal).
                'rx_power_dbm' => null,
                'rx_power_label' => null,
                'rx_power_source' => null,
            ];
        }

        $onus = array_values($onus);
        usort($onus, fn ($a, $b) => [$a['port'], $a['onu_id']] <=> [$b['port'], $b['onu_id']]);

        return $onus;
    }

    /**
     * Ambil angka footer `Total: 116, online 107` (kunci `total` / `online`), null bila tak ada.
     */
    public function parseFooterCount(string $output, string $key): ?int
    {
        $pattern = $key === 'total'
            ? '/total\s*:?\s*(\d+)/i'
            : '/online\s*:?\s*(\d+)/i';

        return preg_match($pattern, $output, $m) ? (int) $m[1] : null;
    }

    /**
     * Parse `show version` → firmware/produk/MAC. Public agar bisa diuji unit.
     *
     * @return array<string, string|null>
     */
    public function parseVersion(string $output): array
    {
        $field = static function (string $label) use ($output): ?string {
            $pattern = '/^\s*'.preg_quote($label, '/').'\s*:\s*(.+?)\s*$/mi';

            return preg_match($pattern, $output, $m) ? trim($m[1]) : null;
        };

        return [
            'firmware' => $field('Version'),
            'product' => $field('Product Name'),
            'product_oid' => $field('Product OID'),
            'mac' => $field('MAC Address'),
            'uptime' => $field('Running Time'),
        ];
    }

    /**
     * Daftar MAC dari `show epon port {n} onu-autofind-list all`. Toleran: ambil MAC dari baris mana
     * pun (format tabel populated belum pernah terlihat — unit uji selalu kosong), buang duplikat.
     *
     * @return array<int, string>
     */
    public function parseAutofindList(string $output): array
    {
        if (preg_match('/there is no onu/i', $output)) {
            return [];
        }

        preg_match_all('/\b([0-9A-Fa-f]{2}(?::[0-9A-Fa-f]{2}){5})\b/', $output, $matches);

        return array_values(array_unique(array_map('strtoupper', $matches[1] ?? [])));
    }

    // ---------------------------------------------------------------------
    // Aksi tulis ONU (config mode) + Rx per-ONU (Fase B/C). Semua verb sudah
    // diverifikasi live via context-help: `epon port {pon} onu {onu} <verb>`
    // (reboot / delete / description <str>). Enable/disable (activate / no
    // activate) sengaja BELUM dibuka (semantik belum diuji di perangkat asli).
    // ---------------------------------------------------------------------

    /** @var list<string> penanda kegagalan perintah TULIS (lebih ketat dari baca). */
    private array $writeErrorNeedles = [
        'invalid input', 'unknown command', 'ambiguous command', 'incomplete command',
        'permission denied', 'authorization failed', 'error:', 'failed', 'failure',
        'does not exist', 'not exist', 'no such', 'not found', 'illegal',
    ];

    /**
     * Reboot satu ONU: `epon port {pon} onu {onuId} reboot`.
     *
     * @return array{ok: bool, output: string, error: ?string}
     */
    public function reboot(SnmpOlt $olt, int $pon, int $onuId): array
    {
        return $this->runConfig($olt, ["epon port {$pon} onu {$onuId} reboot"], confirm: true);
    }

    /**
     * Hapus ONU: `epon port {pon} onu {onuId} delete`. Destruktif — auto-jawab konfirmasi bila muncul.
     *
     * @return array{ok: bool, output: string, error: ?string}
     */
    public function delete(SnmpOlt $olt, int $pon, int $onuId): array
    {
        return $this->runConfig($olt, ["epon port {$pon} onu {$onuId} delete"], confirm: true);
    }

    /**
     * Set nama/deskripsi ONU: `epon port {pon} onu {onuId} description {label}` (1-64 char).
     * Nama kosong ditolak (family ini tak punya "no description" teruji).
     *
     * @return array{ok: bool, output: string, error: ?string}
     */
    public function setDescription(SnmpOlt $olt, int $pon, int $onuId, ?string $name): array
    {
        $label = $this->sanitizeDescription($name);
        if ($label === '') {
            return ['ok' => false, 'output' => '', 'error' => 'Nama/deskripsi ONU tidak boleh kosong (huruf/angka/_-.).'];
        }

        return $this->runConfig($olt, ["epon port {$pon} onu {$onuId} description {$label}"]);
    }

    /**
     * Rx per-ONU via `show epon port {pon} onu {onuId} optical-info` — DILAKUKAN PER-ONU karena varian
     * `... onu all optical-info` MEMBEKUKAN CLI (guide §3.2). Throttle ringan + timeout keras per-ONU
     * supaya ONU yang lambat/offline tak menahan seluruh batch. Hanya panggil untuk ONU online.
     *
     * `$onEach` (opsional) dipanggil `($onuId, ?array $entry)` setelah tiap ONU — dipakai job background
     * untuk menyimpan Rx & progres inkremental (fill-in) tanpa menunggu seluruh port selesai.
     *
     * @param  array<int, int>  $onuIds
     * @param  callable(int, array{dbm: float, label: string}|null): void|null  $onEach
     * @return array<int, array{dbm: float, label: string}> di-key onuId
     */
    public function fetchPortRx(SnmpOlt $olt, int $pon, array $onuIds, ?callable $onEach = null): array
    {
        if ($onuIds === []) {
            return [];
        }

        $connection = $this->openSession($olt);
        $rx = [];

        try {
            foreach ($onuIds as $onuId) {
                $entry = null;

                try {
                    $out = $this->command($connection, "show epon port {$pon} onu {$onuId} optical-info", 12);
                    $dbm = $this->parseOpticalRx($out);
                    if ($dbm !== null) {
                        $entry = ['dbm' => $dbm, 'label' => sprintf('%.2f dBm', $dbm)];
                        $rx[$onuId] = $entry;
                    }
                } catch (RuntimeException) {
                    // ONU lambat/offline → lewati, jangan gagalkan seluruh batch (entry tetap null)
                }

                if ($onEach !== null) {
                    $onEach($onuId, $entry);
                }

                usleep(120_000); // throttle antar-ONU
            }
        } finally {
            fclose($connection);
        }

        return $rx;
    }

    /**
     * Ambil "Rx optical power(dBm)" dari output `optical-info`. Public agar bisa diuji unit.
     * Buang nilai sentinel di luar jendela wajar EPON (-40..0 dBm).
     */
    public function parseOpticalRx(string $output): ?float
    {
        if (! preg_match('/Rx\s*optical\s*power\s*\(dBm\)\s*:\s*(-?\d+(?:\.\d+)?)/i', $output, $m)) {
            return null;
        }

        $dbm = (float) $m[1];

        return ($dbm <= 0 && $dbm >= -40) ? $dbm : null;
    }

    /**
     * Jalankan perintah TULIS di config mode: `configure terminal` → perintah → `end`.
     * Prompt `EPON-OLT(config)#` juga berakhir `#` sehingga {@see self::PROMPT_ENABLE} tetap cocok.
     *
     * @param  list<string>  $commands
     * @return array{ok: bool, output: string, error: ?string}
     */
    private function runConfig(SnmpOlt $olt, array $commands, bool $confirm = false): array
    {
        $connection = $this->openSession($olt);
        $output = '';

        try {
            $this->command($connection, 'configure terminal', 8);
            foreach ($commands as $command) {
                $output .= $this->configCommand($connection, $command, 25, $confirm);
            }
            $this->command($connection, 'end', 6);
        } finally {
            fclose($connection);
        }

        $output = $this->mask($output, $olt);
        $error = $this->detectWriteError($output);

        return ['ok' => $error === null, 'output' => $output, 'error' => $error];
    }

    /**
     * Kirim satu perintah tulis, baca sampai prompt `#` (atau timeout). Auto-jawab konfirmasi y/n bila
     * $confirm (defensif — help menunjukkan `<cr>`, tapi firmware bisa berbeda). `--More--` dijawab spasi.
     *
     * @param  resource  $connection
     */
    private function configCommand($connection, string $command, float $max, bool $confirm): string
    {
        fwrite($connection, $command."\r\n");

        $buffer = '';
        $start = microtime(true);

        while (microtime(true) - $start < $max) {
            $chunk = fread($connection, 8192);

            if ($chunk === '' || $chunk === false) {
                usleep(30_000);

                continue;
            }

            [$clean, $reply] = $this->iac->feed($chunk);
            if ($reply !== '') {
                fwrite($connection, $reply);
            }
            $buffer .= $clean;

            if ($confirm && preg_match('/\[y\/n\]|\(y\/n\)|\byes\/no\b|are you sure|confirm/i', substr($buffer, -120))) {
                fwrite($connection, "y\r\n");

                continue;
            }

            if (preg_match('/--More--|---- more ----/i', substr($buffer, -80))) {
                fwrite($connection, ' ');

                continue;
            }

            if (preg_match(self::PROMPT_ENABLE, substr($buffer, -160))) {
                break;
            }
        }

        return $buffer;
    }

    private function detectWriteError(string $output): ?string
    {
        $lower = strtolower($output);

        foreach ($this->writeErrorNeedles as $needle) {
            if (str_contains($lower, $needle)) {
                return $needle;
            }
        }

        return null;
    }

    private function sanitizeDescription(?string $value): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]/', ' ', (string) $value) ?? '';
        $value = preg_replace('/\s+/', '_', trim($value)) ?? '';
        $value = preg_replace('/[^A-Za-z0-9_\-.]/', '', $value) ?? '';

        return mb_strimwidth(trim($value, '_-.'), 0, 64, '');
    }

    private function mask(string $output, SnmpOlt $olt): string
    {
        $password = (string) $olt->cli_password;

        return $password !== '' ? str_replace($password, '****', $output) : $output;
    }

    /**
     * Buka telnet + login sampai prompt enable. Pemanggil WAJIB fclose().
     *
     * @return resource
     */
    private function openSession(SnmpOlt $olt)
    {
        if ($olt->cli_transport !== 'telnet') {
            throw new RuntimeException('CLI HsAirPo hanya mendukung Telnet. Set CLI transport OLT ke telnet.');
        }

        $this->iac = new TelnetIacFilter;

        $connection = @fsockopen($olt->ip, (int) ($olt->cli_port ?: 23), $errno, $errstr, 12);
        if (! $connection) {
            throw new RuntimeException("Koneksi telnet gagal: {$errstr} ({$errno})");
        }

        stream_set_timeout($connection, 2);
        stream_set_blocking($connection, false);

        // Kegagalan APA PUN sesudah socket terbuka wajib menutup socket — jangan tinggalkan sesi
        // menggantung di OLT (jumlah sesi telnet perangkat ini terbatas).
        try {
            $this->readUntil($connection, '/(user ?name|login)\s*:\s*$/i', self::LOGIN_TIMEOUT);
            fwrite($connection, ((string) $olt->cli_username)."\r\n");

            $this->readUntil($connection, '/(password|passwd)\s*:\s*$/i', self::LOGIN_TIMEOUT);
            fwrite($connection, ((string) $olt->cli_password)."\r\n");

            // `EPON-OLT>` (user) atau langsung `#` bila akun sudah privileged.
            $prompt = $this->readUntil($connection, '/[>#][ \t]*$/', self::LOGIN_TIMEOUT);

            if (! preg_match(self::PROMPT_ENABLE, substr($prompt, -80))) {
                fwrite($connection, "enable\r\n");
                $afterEnable = $this->readUntil($connection, '/((password|passwd)\s*:\s*$)|(#[ \t]*$)/i', 10);

                // Sebagian unit meminta password enable → jawab dengan cli_password yang sama.
                if (preg_match('/(password|passwd)\s*:\s*$/i', substr($afterEnable, -80))) {
                    fwrite($connection, ((string) $olt->cli_password)."\r\n");
                    $afterEnable = $this->readUntil($connection, self::PROMPT_ENABLE, 10);
                }

                if (! preg_match(self::PROMPT_ENABLE, substr($afterEnable, -80))) {
                    throw new RuntimeException('Gagal masuk level enable CLI HsAirPo (periksa user/password CLI).');
                }
            }

            // Matikan pager supaya tabel ONU panjang tak terpotong `--More--`.
            $this->command($connection, 'terminal length 0', 8);
        } catch (RuntimeException $exception) {
            fclose($connection);

            throw $exception;
        }

        return $connection;
    }

    /**
     * Kirim satu perintah lalu baca sampai prompt enable. Melempar bila prompt tak kembali dalam
     * $max detik (guard anti-hang) supaya sesi buntu tak menahan worker polling.
     *
     * @param  resource  $connection
     */
    private function command($connection, string $command, float $max): string
    {
        fwrite($connection, $command."\r\n");
        $output = $this->readUntil($connection, self::PROMPT_ENABLE, $max);

        if (! preg_match(self::PROMPT_ENABLE, substr($output, -80))) {
            throw new RuntimeException("CLI HsAirPo tidak merespons perintah '{$command}' dalam {$max} detik (sesi dihentikan).");
        }

        return $output;
    }

    /**
     * Baca sampai $promptRegex muncul di ekor buffer atau $max detik terlampaui (tak pernah
     * menggantung). `--More--` dijawab spasi walau pager sudah dimatikan.
     *
     * @param  resource  $connection
     */
    private function readUntil($connection, string $promptRegex, float $max): string
    {
        $buffer = '';
        $start = microtime(true);

        while (microtime(true) - $start < $max) {
            $chunk = fread($connection, 8192);

            if ($chunk === '' || $chunk === false) {
                usleep(30_000);

                continue;
            }

            [$clean, $reply] = $this->iac->feed($chunk);
            if ($reply !== '') {
                fwrite($connection, $reply);
            }

            $buffer .= $clean;

            if (preg_match('/--More--|---- more ----/i', substr($buffer, -80))) {
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
     * Lempar bila CLI menolak perintah (mis. firmware varian tanpa `show epon onu all info`).
     */
    private function assertAccepted(string $output, string $command): void
    {
        $lower = strtolower($output);

        foreach ($this->errorNeedles as $needle) {
            if (str_contains($lower, $needle)) {
                throw new RuntimeException("CLI HsAirPo menolak '{$command}': {$needle}.");
            }
        }
    }
}
