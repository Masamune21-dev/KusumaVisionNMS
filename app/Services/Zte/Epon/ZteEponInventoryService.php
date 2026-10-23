<?php

namespace App\Services\Zte\Epon;

use App\Models\SnmpOlt;
use App\Services\ZteCliProvisioningExecutor;
use App\Support\CliOutputSanitizer;
use App\Support\ZteEpon;
use Carbon\CarbonInterface;
use Throwable;

/**
 * Inventaris port & ONU EPON ZTE C300/C320 lewat CLI telnet (bukan SNMP: OID tabel ONU EPON
 * `3902.1015.1010.*` tak punya sumber yang bisa dipegang, sedang `show epon onu state` punya
 * contoh keluaran asli). Hasilnya ditulis ke `last_test_result` dalam bentuk yang SAMA dengan
 * ONU GPON (`ports[]` + `port_onus["{slot}_{port}"]`), sehingga halaman, pencarian, peta, ODP,
 * dan AlarmEvaluator membacanya tanpa cabang khusus.
 *
 * Satu sesi telnet per poll: `show card` (kartu ETG*), `show epon onu state` (status semua ONU
 * EPON), `show running-config interface epon-olt_…` per port yang SUDAH dikenal (MAC & tipe tetap
 * ONU, juga yang offline — kunci alarm memakai serial/MAC, jadi harus stabil), dan bila RX jatuh
 * tempo `show pon power onu-rx epon-olt_…`. Nama ONU (baris `property description` per ONU)
 * hanya dibaca saat Refresh port manual — terlalu mahal untuk tiap poll — lalu dibawa antar-poll.
 *
 * ⚠️ Belum diverifikasi di OLT ETG asli; lihat {@see ZteEpon}.
 */
class ZteEponInventoryService
{
    /** OLT tanpa kartu EPON dicek ulang `show card`-nya paling sering tiap sekian jam. */
    private const NO_CARD_RECHECK_HOURS = 6;

    public function __construct(
        private readonly ZteCliProvisioningExecutor $executor,
        private readonly ZteEponCliParser $parser,
    ) {}

    /**
     * Lengkapi snapshot poll (yang `ports[]`-nya baru saja ditulis ulang dari IF-MIB/GPON) dengan
     * port & ONU EPON. Gagal baca CLI tak pernah membuang data EPON lama: port dibawa dari snapshot
     * sebelumnya dan `port_onus` EPON dibiarkan apa adanya.
     *
     * @param  array<string, mixed>  $merged  snapshot baru (sudah berisi hasil GPON)
     * @param  array<string, mixed>  $previous  snapshot sebelum poll ini
     * @return array{snapshot: array<string, mixed>, onus: list<array<string, mixed>>, rx_ok: bool}
     */
    public function augmentPoll(SnmpOlt $olt, array $merged, array $previous, CarbonInterface $now, bool $rxDue): array
    {
        if (! ZteEpon::appliesTo($olt)) {
            return ['snapshot' => ZteEpon::strip($merged), 'onus' => [], 'rx_ok' => false];
        }

        if (! $this->hasCli($olt)) {
            return ['snapshot' => $merged, 'onus' => [], 'rx_ok' => false];
        }

        $knownPorts = ZteEpon::ports($previous);
        $meta = is_array($previous['epon'] ?? null) ? $previous['epon'] : [];

        // OLT GPON murni: jangan buka telnet tiap poll hanya untuk `show card` yang sama.
        if ($knownPorts === [] && ($meta['ok'] ?? false) === true && ($meta['cards'] ?? null) === []) {
            $checked = strtotime((string) ($meta['checked_at'] ?? '')) ?: 0;
            if ($checked > $now->copy()->subHours(self::NO_CARD_RECHECK_HOURS)->getTimestamp()) {
                return ['snapshot' => $merged, 'onus' => [], 'rx_ok' => false];
            }
        }

        $script = ['terminal length 0', 'show card', 'show epon onu state'];
        foreach ($knownPorts as $port) {
            $script[] = 'show running-config interface '.ZteEpon::oltInterface((int) $port['slot'], (int) $port['port']);
        }
        if ($rxDue) {
            foreach ($knownPorts as $port) {
                $script[] = 'show pon power onu-rx '.ZteEpon::oltInterface((int) $port['slot'], (int) $port['port']);
            }
        }

        try {
            $result = $this->executor->executeScan($olt, implode("\n", $script));
            $output = $result['output'];
        } catch (Throwable $e) {
            return $this->pollFailed($olt, $merged, $previous, $meta, $now, CliOutputSanitizer::clean($e->getMessage()));
        }

        $cardOutput = $this->parser->outputOf($output, 'show card');
        if (! $this->looksLikeCardTable($cardOutput)) {
            return $this->pollFailed($olt, $merged, $previous, $meta, $now, $result['error'] ?? __('zte.epon_cli_unreadable'));
        }

        $cards = $this->parser->eponCards($cardOutput);
        $merged['epon'] = [
            'ok' => true,
            'error' => null,
            'checked_at' => $now->toIso8601String(),
            'cards' => $cards,
        ];

        if ($cards === []) {
            return ['snapshot' => $merged, 'onus' => [], 'rx_ok' => false];
        }

        $states = $this->parser->onuStates($this->parser->outputOf($output, 'show epon onu state'));
        $registered = $this->parser->registeredOnus($this->parser->outputOf($output, 'show running-config interface epon-olt'));
        $rxOutput = $rxDue ? $this->parser->outputOf($output, 'show pon power onu-rx') : '';
        $rx = $rxDue ? $this->parser->onuRxPowers($rxOutput) : [];
        $rxOk = $rxDue && $rx !== [];

        // `show epon onu state` kosong padahal port punya ONU terdaftar = baca gagal (perintah ditolak
        // / sesi terpotong). Menandai semuanya offline di sini = badai alarm palsu → bawa data lama.
        $registeredCount = array_sum(array_map('count', $registered));
        $previousCount = $this->countEponOnus($previous);
        if ($states === [] && ($registeredCount > 0 || $previousCount > 0)) {
            $failed = $this->pollFailed($olt, $merged, $previous, $meta, $now, __('zte.epon_state_unreadable'));
            $failed['snapshot']['epon']['cards'] = $cards;

            return $failed;
        }

        $ports = $this->portsFromCards($cards);
        $statesByPort = [];
        foreach ($states as $row) {
            $statesByPort["{$row['slot']}_{$row['port']}"][$row['onu_id']] = $row;
        }

        $allOnus = [];
        foreach ($ports as $i => $port) {
            $key = "{$port['slot']}_{$port['port']}";
            $previousRows = $this->rowsById(data_get($previous, "port_onus.{$key}.onus", []));
            // Port yang baru ditemukan belum punya baris running-config di skrip poll ini — pakai
            // cache lama (bila ada) daripada membuang MAC/tipe.
            $portRegistered = $registered[$key] ?? null;

            $rows = $this->buildRows(
                (int) $port['slot'],
                (int) $port['port'],
                $statesByPort[$key] ?? [],
                $portRegistered,
                $previousRows,
                [],
                $rxDue ? $rx : null,
            );

            $ports[$i]['oper_status'] = $this->operStatus($rows);
            $merged['port_onus'][$key] = $this->portEntry($ports[$i], $rows, data_get($previous, "port_onus.{$key}.rx_power", []), $rxDue, $rxOk, $now);
            array_push($allOnus, ...$rows);
        }

        $merged['ports'] = ZteEpon::sortPorts([
            ...array_filter($merged['ports'] ?? [], fn ($p): bool => ($p['pon_type'] ?? null) !== 'epon'),
            ...$ports,
        ]);

        return ['snapshot' => $merged, 'onus' => $allOnus, 'rx_ok' => $rxOk];
    }

    /**
     * Refresh manual satu port EPON (tombol Refresh di halaman ONU per port): status + MAC/tipe +
     * RX dalam sesi pertama, lalu nama/deskripsi/SLA/VLAN tiap ONU di sesi kedua.
     *
     * @return array<string, mixed> entri `port_onus["{slot}_{port}"]`
     */
    public function refreshPort(SnmpOlt $olt, int $slot, int $port, CarbonInterface $now): array
    {
        $started = microtime(true);
        $oltIface = ZteEpon::oltInterface($slot, $port);
        $key = "{$slot}_{$port}";
        $snapshot = $olt->last_test_result ?? [];
        $previousRows = $this->rowsById(data_get($snapshot, "port_onus.{$key}.onus", []));
        $portRow = $this->portRow($olt, $slot, $port);

        try {
            $first = $this->executor->executeScan($olt, implode("\n", [
                'terminal length 0',
                'show epon onu state',
                "show running-config interface {$oltIface}",
                "show pon power onu-rx {$oltIface}",
            ]));
        } catch (Throwable $e) {
            return $this->failedPortEntry($snapshot, $key, $portRow, CliOutputSanitizer::clean($e->getMessage()), $now);
        }

        $states = array_filter(
            $this->parser->onuStates($this->parser->outputOf($first['output'], 'show epon onu state')),
            fn (array $row): bool => $row['slot'] === $slot && $row['port'] === $port,
        );
        $registered = $this->parser->registeredOnus($this->parser->outputOf($first['output'], 'show running-config interface'))[$key] ?? null;
        $rx = $this->parser->onuRxPowers($this->parser->outputOf($first['output'], 'show pon power onu-rx'));

        if ($states === [] && $registered === null) {
            return $this->failedPortEntry($snapshot, $key, $portRow, $first['error'] ?? __('zte.epon_state_unreadable'), $now);
        }

        $onuIds = array_unique([
            ...array_map(fn (array $row): int => $row['onu_id'], array_values($states)),
            ...array_keys($registered ?? []),
        ]);
        sort($onuIds);

        $configs = [];
        if ($onuIds !== []) {
            try {
                $second = $this->executor->executeScan($olt, implode("\n", [
                    'terminal length 0',
                    ...array_map(fn (int $id): string => 'show running-config interface '.ZteEpon::onuInterface($slot, $port, $id), $onuIds),
                ]));
                $configs = $this->parser->onuConfigs($second['output']);
            } catch (Throwable) {
                // Nama hanya pelengkap: tetap simpan status/MAC hasil sesi pertama.
                $configs = [];
            }
        }

        $byId = [];
        foreach ($states as $row) {
            $byId[$row['onu_id']] = $row;
        }

        $rows = $this->buildRows($slot, $port, $byId, $registered, $previousRows, $configs, $rx);
        $portRow['oper_status'] = $this->operStatus($rows);
        $entry = $this->portEntry($portRow, $rows, data_get($snapshot, "port_onus.{$key}.rx_power", []), true, $rx !== [], $now);
        $entry['latency_ms'] = (int) round((microtime(true) - $started) * 1000);

        return $entry;
    }

    /**
     * ONU EPON yang belum terdaftar, per port EPON yang dikenal snapshot (`show onu unauthentication
     * epon-olt_1/s/p`). Bentuk baris mengikuti `unconfigured_onus.onus` GPON; `serial_number` = MAC
     * (`AA:BB:…`) karena ONU EPON dikenali dari MAC, bukan SN.
     *
     * @return array{ok:bool, error:string|null, onus:list<array<string, mixed>>}
     */
    public function unconfigured(SnmpOlt $olt): array
    {
        $ports = ZteEpon::appliesTo($olt) ? ZteEpon::ports($olt->last_test_result ?? []) : [];
        if ($ports === [] || ! $this->hasCli($olt)) {
            return ['ok' => true, 'error' => null, 'onus' => []];
        }

        $script = ['terminal length 0'];
        foreach ($ports as $port) {
            $script[] = 'show onu unauthentication '.ZteEpon::oltInterface((int) $port['slot'], (int) $port['port']);
        }

        try {
            $result = $this->executor->executeScan($olt, implode("\n", $script));
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => CliOutputSanitizer::clean($e->getMessage()), 'onus' => []];
        }

        $rows = [];
        foreach ($this->parser->unauthenticated($result['output']) as $row) {
            $rows[] = [
                'serial_number' => $row['mac'],
                'mac' => $row['mac'],
                'raw_value' => $row['mac'],
                'oid' => null,
                'source_oid' => 'cli:show onu unauthentication',
                'oid_index' => '',
                'if_index' => null,
                'suggested_onu_id' => $this->nextFreeOnuId($olt, $row['slot'], $row['port']),
                'slot' => $row['slot'],
                'port' => $row['port'],
                'port_alias' => ZteEpon::oltInterface($row['slot'], $row['port']),
                'model' => $row['model'],
                'firmware' => $row['firmware'],
                'loid' => $row['loid'],
                'auth_state' => $row['state'],
                'pon_type' => 'epon',
            ];
        }

        // Perintah ditolak di SEMUA port (output tanpa satu baris pun yang dikenali) tetap dianggap
        // "tak ada ONU baru" — tak ada pembeda yang bisa dipegang antara keduanya tanpa perangkat.
        return ['ok' => true, 'error' => $rows === [] ? $result['error'] : null, 'onus' => $rows];
    }

    /**
     * ID ONU kosong terkecil di port EPON (1..64 — kapasitas split ETGO; s4lfanet memakai 1..128).
     */
    public function nextFreeOnuId(SnmpOlt $olt, int $slot, int $port): int
    {
        $used = [];
        foreach (data_get($olt->last_test_result ?? [], "port_onus.{$slot}_{$port}.onus", []) as $onu) {
            $used[(int) ($onu['onu_id'] ?? 0)] = true;
        }

        for ($id = 1; $id <= 128; $id++) {
            if (! isset($used[$id])) {
                return $id;
            }
        }

        return 1;
    }

    /**
     * Daftar nama onu-type EPON & SLA profile yang sudah dipakai — saran (datalist) di form
     * registrasi. Dibaca dari cache ONU EPON, tanpa telnet.
     *
     * @return array{onu_types:list<string>, sla_profiles:list<string>}
     */
    public function formSuggestions(SnmpOlt $olt): array
    {
        $types = ['ALL-EPON'];
        $sla = [];

        foreach (ZteEpon::ports($olt->last_test_result ?? []) as $port) {
            foreach (data_get($olt->last_test_result, "port_onus.{$port['slot']}_{$port['port']}.onus", []) as $onu) {
                if (($onu['type_name'] ?? '') !== '') {
                    $types[] = (string) $onu['type_name'];
                }
                if (($onu['sla_profile'] ?? '') !== '') {
                    $sla[] = (string) $onu['sla_profile'];
                }
            }
        }

        return [
            'onu_types' => array_values(array_unique($types)),
            'sla_profiles' => array_values(array_unique($sla)),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $states  onu_id → baris `show epon onu state`
     * @param  array<int, array<string, mixed>>|null  $registered  onu_id → {type_name, mac}; null = tak dibaca
     * @param  array<int, array<string, mixed>>  $previousRows  onu_id → baris snapshot lama
     * @param  array<string, array<string, mixed>>  $configs  "{s}_{p}_{id}" → nama/deskripsi/SLA/VLAN
     * @param  array<string, float>|null  $rx  "{s}_{p}_{id}" → dBm; null = RX tak dibaca (bawa nilai lama)
     * @return list<array<string, mixed>>
     */
    private function buildRows(int $slot, int $port, array $states, ?array $registered, array $previousRows, array $configs, ?array $rx): array
    {
        $ids = array_unique([...array_keys($states), ...array_keys($registered ?? [])]);
        // Running-config tak terbaca (port baru di poll ini): ONU lama yang tak muncul di state tetap
        // dipertahankan agar tak "hilang" sesaat.
        if ($registered === null) {
            $ids = array_unique([...$ids, ...array_keys($previousRows)]);
        }
        sort($ids);

        $rows = [];
        foreach ($ids as $id) {
            $state = $states[$id] ?? null;
            $reg = $registered[$id] ?? null;
            $prev = $previousRows[$id] ?? [];
            $mac = $reg['mac'] ?? $state['mac'] ?? $prev['mac'] ?? null;
            // Unit diganti (MAC beda) → nama lama milik pelanggan lain, jangan dibawa.
            $sameUnit = $prev !== [] && (($prev['mac'] ?? null) === null || $mac === null || $prev['mac'] === $mac);
            $carried = $sameUnit ? $prev : [];
            $config = $configs["{$slot}_{$port}_{$id}"] ?? null;
            $status = $this->parser->statusFields((string) ($state['status'] ?? 'Offline'));
            $rxKey = "{$slot}_{$port}_{$id}";

            if ($rx !== null) {
                $dbm = $rx[$rxKey] ?? null;
            } else {
                $dbm = $carried['rx_power_dbm'] ?? null;
            }

            $rows[] = [
                'if_index' => null,
                'onu_id' => $id,
                'slot' => $slot,
                'port' => $port,
                'interface' => ZteEpon::onuInterface($slot, $port, $id),
                'type_name' => $reg['type_name'] ?? $carried['type_name'] ?? null,
                'name' => $config !== null ? $config['name'] : ($carried['name'] ?? null),
                'description' => $config !== null ? $config['description'] : ($carried['description'] ?? null),
                'serial_number' => $mac,
                'mac' => $mac,
                'admin_state_code' => null,
                'admin_state' => null,
                'phase_state_code' => null,
                'phase_state' => $status['phase_state'],
                'online' => $status['online'],
                'oam_status' => $state['oam_status'] ?? null,
                'last_down_cause_code' => null,
                'last_down_cause' => $status['last_down_cause'],
                'sla_profile' => $config !== null ? $config['sla_profile'] : ($carried['sla_profile'] ?? null),
                'vlans' => $config !== null ? $config['vlans'] : ($carried['vlans'] ?? []),
                'rx_power_port' => null,
                'raw_rx_power' => $dbm === null ? null : (string) $dbm,
                'rx_power_dbm' => $dbm,
                'rx_power_label' => $dbm === null ? null : sprintf('%.3f dBm', $dbm),
                'rx_power_source' => $dbm === null ? null : ($rx !== null ? 'cli' : ($carried['rx_power_source'] ?? 'cli')),
                'pon_type' => 'epon',
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $cards
     * @return list<array<string, mixed>>
     */
    private function portsFromCards(array $cards): array
    {
        $ports = [];
        foreach ($cards as $card) {
            for ($p = 1; $p <= (int) $card['ports']; $p++) {
                $ports[] = [
                    'if_index' => null,
                    'name' => ZteEpon::oltInterface((int) $card['slot'], $p),
                    'if_name' => null,
                    'if_descr' => null,
                    'slot' => (int) $card['slot'],
                    'port' => $p,
                    'oper_status_code' => null,
                    'oper_status' => 'unknown',
                    'pon_type' => 'epon',
                    'card_type' => $card['real_type'] ?? $card['cfg_type'],
                ];
            }
        }

        return $ports;
    }

    /**
     * @return array<string, mixed>
     */
    private function portRow(SnmpOlt $olt, int $slot, int $port): array
    {
        foreach (ZteEpon::ports($olt->last_test_result ?? []) as $row) {
            if ((int) $row['slot'] === $slot && (int) $row['port'] === $port) {
                return $row;
            }
        }

        return [
            'if_index' => null,
            'name' => ZteEpon::oltInterface($slot, $port),
            'slot' => $slot,
            'port' => $port,
            'oper_status_code' => null,
            'oper_status' => 'unknown',
            'pon_type' => 'epon',
        ];
    }

    /**
     * Status port turunan: tanpa IF-MIB EPON yang terbukti, port "up" hanya bila ada ONU online.
     * Selain itu 'unknown' — BUKAN 'down', supaya AlarmEvaluator tak membuat alarm port palsu.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function operStatus(array $rows): string
    {
        foreach ($rows as $row) {
            if ($row['online'] ?? false) {
                return 'up';
            }
        }

        return 'unknown';
    }

    /**
     * @param  array<string, mixed>  $portRow
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $previousRx
     * @return array<string, mixed>
     */
    private function portEntry(array $portRow, array $rows, $previousRx, bool $rxDue, bool $rxOk, CarbonInterface $now): array
    {
        $rxCount = count(array_filter($rows, fn (array $row): bool => $row['rx_power_dbm'] !== null));

        return [
            'ok' => true,
            'slot' => (int) $portRow['slot'],
            'port' => (int) $portRow['port'],
            'if_index' => null,
            'port_row' => $portRow,
            'onus' => $rows,
            'count' => count($rows),
            'latency_ms' => null,
            'rx_power' => [
                'ok' => ! $rxDue || $rxOk,
                'source' => 'cli',
                'count' => $rxCount,
                'error' => $rxDue && ! $rxOk && $rows !== [] ? __('zte.epon_rx_unreadable') : null,
                'polled_at' => $rxOk ? $now->toIso8601String() : (is_array($previousRx) ? ($previousRx['polled_at'] ?? null) : null),
            ],
            'error' => null,
            'refreshed_at' => $now->toIso8601String(),
            'pon_type' => 'epon',
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $portRow
     * @return array<string, mixed>
     */
    private function failedPortEntry(array $snapshot, string $key, array $portRow, string $error, CarbonInterface $now): array
    {
        $existing = data_get($snapshot, "port_onus.{$key}");
        $existing = is_array($existing) ? $existing : [
            'slot' => (int) $portRow['slot'],
            'port' => (int) $portRow['port'],
            'if_index' => null,
            'port_row' => $portRow,
            'onus' => [],
            'count' => 0,
            'pon_type' => 'epon',
        ];

        return [...$existing, 'ok' => false, 'error' => CliOutputSanitizer::clean($error), 'latency_ms' => null];
    }

    /**
     * @param  array<string, mixed>  $merged
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $meta
     * @return array{snapshot: array<string, mixed>, onus: list<array<string, mixed>>, rx_ok: bool}
     */
    private function pollFailed(SnmpOlt $olt, array $merged, array $previous, array $meta, CarbonInterface $now, string $error): array
    {
        $merged = ZteEpon::carryPorts($olt, $merged, $previous);
        $merged['epon'] = [
            ...$meta,
            'ok' => false,
            'error' => $error,
            'failed_at' => $now->toIso8601String(),
        ];

        return ['snapshot' => $merged, 'onus' => [], 'rx_ok' => false];
    }

    private function looksLikeCardTable(string $output): bool
    {
        // Setiap chassis ZTE punya kartu kontrol/daya, jadi `show card` yang terbaca selalu memuat
        // baris "rack shelf slot tipe …".
        return preg_match('/^\s*\d+\s+\d+\s+\d+\s+[A-Za-z]\S*/m', $output) === 1;
    }

    private function hasCli(SnmpOlt $olt): bool
    {
        return $olt->cli_transport === 'telnet' && (string) $olt->cli_username !== '' && (string) $olt->cli_password !== '';
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function countEponOnus(array $snapshot): int
    {
        $count = 0;
        foreach (ZteEpon::ports($snapshot) as $port) {
            $count += count(data_get($snapshot, "port_onus.{$port['slot']}_{$port['port']}.onus", []));
        }

        return $count;
    }

    /**
     * @param  mixed  $rows
     * @return array<int, array<string, mixed>>
     */
    private function rowsById($rows): array
    {
        $map = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && isset($row['onu_id'])) {
                $map[(int) $row['onu_id']] = $row;
            }
        }

        return $map;
    }
}
