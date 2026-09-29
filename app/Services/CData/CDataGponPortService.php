<?php

namespace App\Services\CData;

use App\Models\SnmpOlt;
use App\Services\CData\Concerns\InteractsWithCDataCli;
use RuntimeException;

/**
 * VLAN & detail port OLT C-Data firmware V3 (GPON **dan** EPON) lewat CLI telnet. Nama kelas lahir
 * sebagai GPON-only; sejak 29 Sep 2026 juga melayani EPON FD1304E. Semua format di bawah diparse dari
 * output asli FD1608S-B1, FD1601S-B1 & FD1304E (firmware V3.x), bukan dari manual:
 *
 *   (enable)#            show vlan all                       blok per VLAN: ID/Description/Type/Tagged/Untagged
 *   (config)#            show vlan {id}                      blok yang sama; VLAN tak ada → output kosong
 *   (config)#            show port ddm-info {kind} 0/{s}/{p} DDM transceiver (TIDAK dikenal di level enable);
 *                                                            GE RJ45 → "Transceiver parm error!"
 *   (config-{kind}-0/s)# show port info {p}                  admin/link/speed/mode + tagged/untagged VLAN
 *                                                            (TIDAK ada di port EPON → show port state + vlan)
 *   (config-{kind}-0/s)# show port vlan {p}                  mode + tagged/untagged saja
 *   (config-{kind}-0/s)# show statistics port {p}            rate kbps + counter RX/TX
 *   (config)#            vlan {id} · vlan description {id} {teks}
 *   (config-{kind}-0/s)# vlan trunk {p} {vlanlist}           port Trunk  (ada `no vlan trunk …`)
 *   (config-{kind}-0/s)# vlan hybrid {p} tagged {vlanlist}   port Hybrid (ada `no vlan hybrid {p} tagged …`)
 *
 * Interface C-Data dimasuki per frame/slot (`interface xge 0/0`); nomor port jadi argumen
 * (`interface xge 0/0/1` ditolak "Incorrect F/S parameters"). Port **GPON** otomatis ikut setiap VLAN
 * baru (VLAN yang baru dibuat langsung ter-tag di gpon 1-8), sedangkan port **EPON** Hybrid dan harus di-tag manual
 * seperti uplink. Uplink EPON umumnya Hybrid (xge 0/0/1 di FD1304E), uplink GPON Trunk.
 */
class CDataGponPortService
{
    use InteractsWithCDataCli;

    public const KINDS = ['gpon', 'epon', 'ge', 'xge'];

    public const PON_KINDS = ['gpon', 'epon'];

    /** Port yang boleh di-tag dari NMS. GPON tak perlu: port PON-nya otomatis ikut VLAN baru. */
    public const TAGGABLE_KINDS = ['ge', 'xge', 'epon'];

    /** Daftar VLAN yang dikirim ke `vlan trunk` dijaga pendek supaya tak terpotong buffer CLI. */
    private const MAX_VLANLIST_LENGTH = 200;

    /**
     * Semua VLAN di OLT (`show vlan all`, level enable).
     *
     * @return list<array<string, mixed>>
     */
    public function vlans(SnmpOlt $olt): array
    {
        $connection = $this->openCliSession($olt);

        try {
            $output = $this->cliCommand($connection, 'show vlan all', 30, true);
        } finally {
            fclose($connection);
        }

        if (($error = $this->cliDetectError($output)) !== null) {
            throw new RuntimeException("CLI menolak 'show vlan all': {$error}");
        }

        return $this->parseVlans($output);
    }

    /**
     * Detail satu port: atribut & VLAN (`show port info`), DDM, dan statistik trafik. Port EPON tak
     * punya `show port info` (`% Unknown command`) → dirakit dari `show port state` + `show port vlan`.
     *
     * @return array<string, mixed>
     */
    public function portDetail(SnmpOlt $olt, string $kind, int $slot, int $port): array
    {
        $kind = $this->kind($kind);
        $connection = $this->openCliSession($olt);

        try {
            $this->cliCommand($connection, 'config', 5);
            $ddm = $this->cliCommand($connection, "show port ddm-info {$kind} 0/{$slot}/{$port}", 15, true);
            $this->enterInterface($connection, $kind, $slot);
            $info = $this->cliCommand($connection, "show port info {$port}", 15, true);
            $vlan = null;
            if ($this->cliDetectError($info) !== null) {
                $info = $this->cliCommand($connection, "show port state {$port}", 15, true);
                $vlan = $this->cliCommand($connection, "show port vlan {$port}", 10, true);
            }
            $stats = $this->cliCommand($connection, "show statistics port {$port}", 15, true);
            $this->cliCommand($connection, 'end', 4);
        } finally {
            fclose($connection);
        }

        if (($error = $this->cliDetectError($info)) !== null) {
            throw new RuntimeException("CLI menolak 'show port info|state {$port}': {$error}");
        }

        $parsed = $this->parsePortInfo($info);
        if ($vlan !== null && $this->cliDetectError($vlan) === null) {
            $pv = $this->parsePortVlan($vlan);
            $parsed = array_merge($parsed, [
                'mode' => $pv['mode'],
                'native_vlan' => $pv['native_vlan'] ?? $parsed['native_vlan'],
                'tagged' => $pv['tagged'],
                'untagged' => $pv['untagged'],
            ]);
        }

        return [
            'name' => sprintf('%s 0/%d/%d', $kind, $slot, $port),
            'kind' => $kind,
            'slot' => $slot,
            'port' => $port,
            'info' => $parsed,
            'ddm' => $this->cliDetectError($ddm) === null ? $this->parseDdm($ddm) : null,
            'stats' => $this->cliDetectError($stats) === null ? $this->parseStatistics($stats) : null,
        ];
    }

    /**
     * Buat VLAN (+ deskripsi). VLAN yang sudah ada ditolak — form ini untuk menambah, bukan menimpa.
     * Hasil diverifikasi dengan `show vlan {id}` di sesi yang sama.
     *
     * @return array{ok: bool, error: ?string, output: string, vlan: ?array<string, mixed>}
     */
    public function createVlan(SnmpOlt $olt, int $vlanId, ?string $description): array
    {
        $this->assertVlanId($vlanId);
        $description = $this->sanitizeVlanDescription($description);
        $connection = $this->openCliSession($olt);
        $output = '';

        try {
            $this->cliCommand($connection, 'config', 5);

            if ($this->findVlan($this->cliCommand($connection, "show vlan {$vlanId}", 10, true), $vlanId) !== null) {
                return ['ok' => false, 'error' => "VLAN {$vlanId} sudah ada di OLT.", 'output' => '', 'vlan' => null];
            }

            $output .= $this->cliCommand($connection, "vlan {$vlanId}", 10);
            if ($description !== '') {
                $output .= $this->cliCommand($connection, "vlan description {$vlanId} {$description}", 10);
            }
            $vlan = $this->findVlan($this->cliCommand($connection, "show vlan {$vlanId}", 10, true), $vlanId);
            $this->cliCommand($connection, 'end', 4);
        } finally {
            fclose($connection);
        }

        $output = $this->mask($output, $olt);
        $error = $this->cliDetectError($output)
            ?? ($vlan === null ? "VLAN {$vlanId} tidak muncul di 'show vlan' setelah dibuat." : null);

        return ['ok' => $error === null, 'error' => $error, 'output' => $output, 'vlan' => $vlan];
    }

    /**
     * Tag VLAN ke port GE/XGE/EPON bermode Trunk (`vlan trunk {p} …`) atau Hybrid
     * (`vlan hybrid {p} tagged …`). Dikirim sebagai GABUNGAN daftar tagged lama + VLAN baru: bila
     * perintahnya ternyata mengganti (bukan menambah) daftar, port tetap tak kehilangan VLAN yang sudah
     * jalan. Sebelum & sesudahnya dibaca `show port vlan` untuk verifikasi (tagged & untagged lama harus
     * tetap ada). VLAN yang sedang untagged di port itu ditolak — menandainya tagged akan memindahkannya.
     *
     * @return array{ok: bool, error: ?string, output: string, already: bool, tagged: list<string>}
     */
    public function tagPortVlan(SnmpOlt $olt, string $kind, int $slot, int $port, int $vlanId): array
    {
        $kind = $this->kind($kind);
        if (! in_array($kind, self::TAGGABLE_KINDS, true)) {
            throw new RuntimeException('Tag VLAN dari NMS hanya untuk port GE/XGE/EPON — port GPON otomatis ikut VLAN baru.');
        }
        $this->assertVlanId($vlanId);
        $name = sprintf('%s 0/%d/%d', $kind, $slot, $port);

        $connection = $this->openCliSession($olt);
        $output = '';
        $fail = fn (string $error, array $tagged = []) => ['ok' => false, 'error' => $error, 'output' => '', 'already' => false, 'tagged' => $tagged];

        try {
            $this->cliCommand($connection, 'config', 5);

            if ($this->findVlan($this->cliCommand($connection, "show vlan {$vlanId}", 10, true), $vlanId) === null) {
                return $fail("VLAN {$vlanId} belum ada di OLT. Buat dulu di halaman VLAN.");
            }

            $this->enterInterface($connection, $kind, $slot);
            $before = $this->parsePortVlan($this->cliCommand($connection, "show port vlan {$port}", 10, true));

            if ($before['mode'] === null) {
                return $fail("Tidak bisa membaca VLAN port {$name}.");
            }
            $mode = strtolower($before['mode']);
            if (! in_array($mode, ['trunk', 'hybrid'], true)) {
                return $fail("Port {$name} bermode {$before['mode']}. Tag VLAN dari NMS hanya untuk port Trunk atau Hybrid — ubah mode lewat CLI dulu.", $before['tagged']);
            }

            $ranges = self::parseVlanList(implode(',', $before['tagged']));
            $untagged = self::parseVlanList(implode(',', $before['untagged']));
            if (self::vlanListContains($ranges, $vlanId)) {
                return ['ok' => true, 'error' => null, 'output' => '', 'already' => true, 'tagged' => $before['tagged']];
            }
            if (self::vlanListContains($untagged, $vlanId)) {
                return $fail("VLAN {$vlanId} sudah untagged di {$name}; tidak diubah jadi tagged dari NMS.", $before['tagged']);
            }

            $list = self::compressVlanRanges([...$ranges, [$vlanId, $vlanId]]);
            if (strlen($list) > self::MAX_VLANLIST_LENGTH) {
                return $fail("Daftar VLAN {$name} terlalu panjang untuk dikirim aman dari NMS. Tag lewat CLI.", $before['tagged']);
            }

            $command = $mode === 'trunk'
                ? "vlan trunk {$port} {$list}"
                : "vlan hybrid {$port} tagged {$list}";
            $output .= $this->cliCommand($connection, $command, 15);
            $after = $this->parsePortVlan($this->cliCommand($connection, "show port vlan {$port}", 10, true));
            $this->cliCommand($connection, 'end', 4);
        } finally {
            fclose($connection);
        }

        $output = $this->mask($output, $olt);
        $afterTagged = self::parseVlanList(implode(',', $after['tagged']));
        $afterUntagged = self::parseVlanList(implode(',', $after['untagged']));
        $missing = array_values(array_merge(
            array_filter(self::expandVlanRanges($ranges), fn (int $id) => ! self::vlanListContains($afterTagged, $id)),
            array_filter(self::expandVlanRanges($untagged), fn (int $id) => ! self::vlanListContains($afterUntagged, $id)),
        ));

        $error = $this->cliDetectError($output);
        if ($error === null && ! self::vlanListContains($afterTagged, $vlanId)) {
            $error = "VLAN {$vlanId} belum terlihat di port {$name} setelah perintah dikirim.";
        }
        if ($missing !== []) {
            $error = 'Verifikasi: VLAN '.implode(',', $missing)." hilang dari {$name}. Periksa port lewat CLI segera.";
        }

        return ['ok' => $error === null, 'error' => $error, 'output' => $output, 'already' => false, 'tagged' => $after['tagged']];
    }

    // ── Parser (public agar bisa diuji unit) ────────────────────────────────────────────────

    /**
     * Parse `show vlan all` / `show vlan {id}`.
     *
     * @return list<array{id: int, description: ?string, type: ?string, user_bridge: ?string, tagged: list<string>, untagged: list<string>}>
     */
    public function parseVlans(string $output): array
    {
        $vlans = [];
        $current = null;
        $section = null;

        foreach (preg_split('/\r\n|\n|\r/', $output) ?: [] as $line) {
            if (preg_match('/^\s*VLAN ID\s*:\s*(\d+)/i', $line, $m)) {
                if ($current !== null) {
                    $vlans[] = $current;
                }
                $current = ['id' => (int) $m[1], 'description' => null, 'type' => null, 'user_bridge' => null, 'tagged' => [], 'untagged' => []];
                $section = null;

                continue;
            }

            if ($current === null) {
                continue;
            }

            if (preg_match('/^\s*VLAN Description\s*:\s*(.*?)\s*$/i', $line, $m)) {
                $current['description'] = $m[1] !== '' ? $m[1] : null;
                $section = null;
            } elseif (preg_match('/^\s*VLAN Type\s*:\s*(.*?)\s*$/i', $line, $m)) {
                $current['type'] = $m[1] !== '' ? $m[1] : null;
                $section = null;
            } elseif (preg_match('/^\s*User-bridge\s*:\s*(\S*)/i', $line, $m)) {
                $current['user_bridge'] = $m[1] !== '' ? $m[1] : null;
                $section = null;
            } elseif (preg_match('/^\s*(Tagged|Untagged) Ports\s*:(.*)$/i', $line, $m)) {
                $section = strtolower($m[1]);
                array_push($current[$section], ...$this->portNames($m[2]));
            } elseif ($section !== null) {
                array_push($current[$section], ...$this->portNames($line));
            }
        }

        if ($current !== null) {
            $vlans[] = $current;
        }

        usort($vlans, fn ($a, $b) => $a['id'] <=> $b['id']);

        return $vlans;
    }

    /**
     * Parse `show port info {p}` (GE/XGE/GPON) atau `show port state {p}` (EPON). Label berbeda antar
     * jenis port & firmware ("Admin States" uplink, "Admin State" GPON, "Admin state" EPON; "Link  state"
     * berspasi ganda; Link "Up/Down" vs "on/off"), jadi dicocokkan tanpa peduli huruf & spasi.
     * Daftar VLAN bisa terbungkus ke baris berikutnya TANPA koma (`…,810 ⏎ 900`).
     *
     * @return array<string, mixed>
     */
    public function parsePortInfo(string $output): array
    {
        $attributes = [];
        $vlanLists = ['tagged' => [], 'untagged' => []];
        $collecting = null;

        foreach (preg_split('/\r\n|\n|\r/', $output) ?: [] as $line) {
            if (preg_match('/^\s*-{5,}\s*$/', $line)) {
                $collecting = null;

                continue;
            }

            if (preg_match('/^\s*([A-Za-z][^:]*?)\s*:\s*(.*?)\s*$/', $line, $m)) {
                $label = preg_replace('/\s+/', ' ', $m[1]);
                $value = $m[2];

                if (preg_match('/^(Tagged|Untagged) Vlan ID$/i', $label, $vm)) {
                    $collecting = strtolower($vm[1]);
                    $vlanLists[$collecting][] = $value;

                    continue;
                }

                $collecting = null;
                $attributes[$label] = $value;

                continue;
            }

            if ($collecting !== null && trim($line) !== '' && ! str_contains($line, '#')) {
                $vlanLists[$collecting][] = trim($line);
            }
        }

        $byLabel = array_change_key_case($attributes, CASE_LOWER);
        $get = function (string ...$labels) use ($byLabel): ?string {
            foreach ($labels as $label) {
                $value = $byLabel[strtolower($label)] ?? null;
                if ($value !== null && $value !== '' && $value !== '--') {
                    return $value;
                }
            }

            return null;
        };

        $speed = $get('Link Speed');
        $duplex = $get('Link Duplex');
        $link = $get('Link States', 'Link State');

        return [
            'admin_status' => $get('Admin States', 'Admin State'),
            'link_uptime' => $get('Link uptime'),
            'last_down_cause' => $get('Last down case'),
            'link_status' => $link,
            'link_up' => $link !== null && in_array(strtolower($link), ['up', 'on'], true),
            'description' => $get('Port Description'),
            'media_type' => $get('Media Type'),
            'speed_mbps' => $speed !== null && preg_match('/(\d+)/', $speed, $sm) ? (int) $sm[1] : null,
            'speed_label' => $speed,
            'duplex' => $duplex !== null && preg_match('/\(([^)]*)\)/', $duplex, $dm) ? trim($dm[1]) : $duplex,
            'negotiation' => $get('Auto Negotiation'),
            'flow_control' => $get('Flow Control', 'Flow-control'),
            'mtu' => ($mtu = $get('The Maximum Frame Size', 'Maximum frame size')) !== null ? (int) $mtu : null,
            'native_vlan' => ($native = $get('Native-vlan', 'Native vlan')) !== null ? (int) $native : null,
            'mode' => $get('Link type'),
            'tagged' => $this->vlanTokens(implode(',', $vlanLists['tagged'])),
            'untagged' => $this->vlanTokens(implode(',', $vlanLists['untagged'])),
            'optical_status' => $get('Optical Module Status'),
            'attributes' => array_map(
                fn ($label, $value) => ['label' => $label, 'value' => $value],
                array_keys($attributes),
                array_values($attributes),
            ),
        ];
    }

    /**
     * Parse `show port vlan {p}`: `Port: xge 0/0/1   Mode: Trunk  Native-Vlan: 1 …` lalu blok
     * Tagged-Vlan / Untagged-Vlan (isi `--` = kosong).
     *
     * @return array{mode: ?string, native_vlan: ?int, tagged: list<string>, untagged: list<string>}
     */
    public function parsePortVlan(string $output): array
    {
        $mode = null;
        $native = null;
        $lists = ['tagged' => [], 'untagged' => []];
        $collecting = null;

        foreach (preg_split('/\r\n|\n|\r/', $output) ?: [] as $line) {
            if (preg_match('/Mode:\s*(\S+)/i', $line, $m)) {
                $mode = $m[1];
                $native = preg_match('/Native-Vlan:\s*(\d+)/i', $line, $nm) ? (int) $nm[1] : null;

                continue;
            }
            if (preg_match('/^\s*(Tagged|Untagged)-Vlan\s*:/i', $line, $m)) {
                $collecting = strtolower($m[1]);

                continue;
            }
            if (preg_match('/^\s*-{5,}\s*$/', $line)) {
                $collecting = null;

                continue;
            }
            if ($collecting !== null && trim($line) !== '' && ! str_contains($line, '#')) {
                $lists[$collecting][] = trim($line);
            }
        }

        return [
            'mode' => $mode,
            'native_vlan' => $native,
            'tagged' => $this->vlanTokens(implode(',', $lists['tagged'])),
            'untagged' => $this->vlanTokens(implode(',', $lists['untagged'])),
        ];
    }

    /**
     * Parse `show port ddm-info …`. Port tanpa modul → "Info: Transceiver is absent." (present=false).
     * Ambang (hanya dilaporkan port PON) berbentuk `[lo,hi]`.
     *
     * @return array<string, mixed>
     */
    public function parseDdm(string $output): array
    {
        if (preg_match('/transceiver is absent/i', $output)) {
            return ['present' => false];
        }

        $values = [];
        foreach (preg_split('/\r\n|\n|\r/', $output) ?: [] as $line) {
            if (preg_match('/^\s*([A-Za-z][^:]*?)\s*:\s*(.*?)\s*$/', $line, $m) && ! isset($values[$m[1]])) {
                $values[$m[1]] = $m[2];
            }
        }

        $num = fn (string $key) => isset($values[$key]) && preg_match('/^-?\d+(?:\.\d+)?/', $values[$key], $nm) ? (float) $nm[0] : null;
        $text = fn (string $key) => isset($values[$key]) && trim($values[$key]) !== '' ? trim($values[$key]) : null;
        $range = function (string $key) use ($values): ?array {
            return isset($values[$key]) && preg_match('/\[\s*(-?[\d.]+)\s*,\s*(-?[\d.]+)\s*\]/', $values[$key], $rm)
                ? [(float) $rm[1], (float) $rm[2]]
                : null;
        };

        if ($num('Temp(C)') === null && $text('Transceiver Type') === null) {
            return ['present' => false];
        }

        return [
            'present' => true,
            'temperature_c' => $num('Temp(C)'),
            'voltage_v' => $num('Voltage(V)'),
            'bias_ma' => $num('Bias(mA)'),
            'rx_power_dbm' => $num('RX power(dBM)'),
            'tx_power_dbm' => $num('TX power(dBM)'),
            'module_type' => $text('Transceiver Type'),
            'connector' => $text('Connector Type'),
            'wavelength_nm' => $num('Wavelength(nm)') !== null ? (int) $num('Wavelength(nm)') : null,
            'distance' => $text('Transfer Distance'),
            'diagnostic' => $text('Diagnostic Monitor'),
            'vendor_name' => $text('Vendor Name'),
            // SFP PON EPON melaporkan "Product name" alih-alih "Ordering Name".
            'vendor_pn' => $text('Ordering Name') ?? $text('Product name'),
            'serial' => $text('Serial Number'),
            'manufacture_date' => $text('Manufact Date'),
            'thresholds' => array_filter([
                'temp_warn' => $range('Temp warning threshold(C)'),
                'temp_alarm' => $range('Temp alarm threshold(C)'),
                'voltage_warn' => $range('Supply voltage warning threshold(V)'),
                'voltage_alarm' => $range('Supply voltage alarm threshold(V)'),
                'bias_warn' => $range('Tx bias current warning threshold(mA)'),
                'bias_alarm' => $range('Tx bias current alarm threshold(mA)'),
                'tx_warn' => $range('Tx power current warning threshold(dBm)'),
                'tx_alarm' => $range('Tx power current alarm threshold(dBm)'),
            ]),
        ];
    }

    /**
     * Parse `show statistics port {p}`: rate kbps di baris judul + tabel `Label : RX TX`.
     *
     * @return array<string, mixed>
     */
    public function parseStatistics(string $output): array
    {
        $counters = [];
        foreach (preg_split('/\r\n|\n|\r/', $output) ?: [] as $line) {
            if (preg_match('/^\s*([A-Za-z][A-Za-z0-9 ]*?)\s*:\s*(-?[\d.]+)\s+(-?[\d.]+)\s*$/', $line, $m)) {
                $counters[$m[1]] = [$m[2], $m[3]];
            }
        }

        $pair = fn (string $key, int $i) => isset($counters[$key]) ? $counters[$key][$i] : null;
        $int = fn (?string $v) => $v !== null ? (int) $v : null;

        return [
            'rx_kbps' => preg_match('/Rx rate\(kbps\)\s*:\s*(\d+)/i', $output, $rm) ? (int) $rm[1] : null,
            'tx_kbps' => preg_match('/Tx rate\(kbps\)\s*:\s*(\d+)/i', $output, $tm) ? (int) $tm[1] : null,
            'rx_octets' => $pair('Octets', 0),
            'tx_octets' => $pair('Octets', 1),
            'rx_packets' => $pair('Packets', 0),
            'tx_packets' => $pair('Packets', 1),
            'rx_pps' => $int($pair('Rate pps', 0)),
            'tx_pps' => $int($pair('Rate pps', 1)),
            'rx_discards' => $pair('Discards', 0),
            'tx_discards' => $pair('Discards', 1),
            'rx_errors' => $pair('Errors', 0),
            'tx_errors' => $pair('Errors', 1),
            'rx_utilization' => ($v = $pair('Utilization', 0)) !== null ? (float) $v : null,
            'tx_utilization' => ($v = $pair('Utilization', 1)) !== null ? (float) $v : null,
        ];
    }

    // ── Daftar VLAN "1,210,230-231" ────────────────────────────────────────────────────────────

    /**
     * @return list<array{0: int, 1: int}>
     */
    public static function parseVlanList(string $list): array
    {
        $ranges = [];
        foreach (preg_split('/[\s,]+/', $list) ?: [] as $token) {
            if (preg_match('/^(\d+)(?:-(\d+))?$/', $token, $m)) {
                $lo = (int) $m[1];
                $hi = isset($m[2]) ? (int) $m[2] : $lo;
                $ranges[] = [min($lo, $hi), max($lo, $hi)];
            }
        }

        return $ranges;
    }

    /**
     * @param  list<array{0: int, 1: int}>  $ranges
     */
    public static function vlanListContains(array $ranges, int $vlanId): bool
    {
        foreach ($ranges as [$lo, $hi]) {
            if ($vlanId >= $lo && $vlanId <= $hi) {
                return true;
            }
        }

        return false;
    }

    /**
     * Gabung & rapatkan rentang → "210,220,230-231,300".
     *
     * @param  list<array{0: int, 1: int}>  $ranges
     */
    public static function compressVlanRanges(array $ranges): string
    {
        usort($ranges, fn ($a, $b) => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($ranges as [$lo, $hi]) {
            $last = array_key_last($merged);
            if ($last !== null && $lo <= $merged[$last][1] + 1) {
                $merged[$last][1] = max($merged[$last][1], $hi);
            } else {
                $merged[] = [$lo, $hi];
            }
        }

        return implode(',', array_map(fn ($r) => $r[0] === $r[1] ? (string) $r[0] : "{$r[0]}-{$r[1]}", $merged));
    }

    /**
     * @param  list<array{0: int, 1: int}>  $ranges
     * @return list<int>
     */
    public static function expandVlanRanges(array $ranges): array
    {
        $ids = [];
        foreach ($ranges as [$lo, $hi]) {
            array_push($ids, ...range($lo, $hi));
        }

        return array_values(array_unique($ids));
    }

    // ── Internal ────────────────────────────────────────────────────────────────────────────

    /**
     * @param  resource  $connection
     */
    private function enterInterface($connection, string $kind, int $slot): void
    {
        $out = $this->cliCommand($connection, "interface {$kind} 0/{$slot}", 5);

        if (($error = $this->cliDetectError($out)) !== null || ! preg_match('/\(config-'.$kind.'-/i', $out)) {
            throw new RuntimeException("Gagal masuk 'interface {$kind} 0/{$slot}'".($error ? ": {$error}" : '.'));
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findVlan(string $output, int $vlanId): ?array
    {
        foreach ($this->parseVlans($output) as $vlan) {
            if ($vlan['id'] === $vlanId) {
                return $vlan;
            }
        }

        return null;
    }

    /**
     * @return list<string> nama port ternormalisasi, mis. "xge 0/0/1"
     */
    private function portNames(string $text): array
    {
        preg_match_all('/\b(gpon|epon|xge|ge)\s+(\d+)\/(\d+)\/(\d+)/i', $text, $m, PREG_SET_ORDER);

        return array_map(fn ($p) => sprintf('%s %d/%d/%d', strtolower($p[1]), $p[2], $p[3], $p[4]), $m);
    }

    /**
     * "1,210,230-231 , 900" → ["1","210","230-231","900"]; "--"/kosong dibuang.
     *
     * @return list<string>
     */
    private function vlanTokens(string $text): array
    {
        return array_values(array_filter(
            preg_split('/[\s,]+/', $text) ?: [],
            fn ($t) => preg_match('/^\d+(?:-\d+)?$/', $t) === 1,
        ));
    }

    private function kind(string $kind): string
    {
        $kind = strtolower($kind);
        if (! in_array($kind, self::KINDS, true)) {
            throw new RuntimeException("Jenis port C-Data tidak dikenal: {$kind}");
        }

        return $kind;
    }

    private function assertVlanId(int $vlanId): void
    {
        if ($vlanId < 1 || $vlanId > 4094) {
            throw new RuntimeException('VLAN ID harus 1-4094.');
        }
    }

    /** Deskripsi VLAN C-Data: satu kata (`<S>` 1-64); spasi & karakter lain dibuang. */
    private function sanitizeVlanDescription(?string $text): string
    {
        return mb_substr(preg_replace('/[^A-Za-z0-9._-]/', '', (string) $text) ?? '', 0, 64);
    }

    private function mask(string $output, SnmpOlt $olt): string
    {
        $password = (string) $olt->cli_password;

        return $password !== '' ? str_replace($password, '****', $output) : $output;
    }
}
