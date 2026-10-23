<?php

namespace App\Services\Hsgq;

use App\Contracts\SmartOltSnmpDriver;
use App\Models\SnmpOlt;
use App\Services\AlarmEvaluator;
use App\Support\SmartOltSupport;
use Throwable;

/**
 * Driver SNMP read HSGQ asli (Shenzhen HS Fiber, enterprise IANA 50224) — EPON & GPON.
 *
 * ⚠️ BELUM TERVERIFIKASI DI PERANGKAT MILIK KITA (dibangun 23 Okt 2026 tanpa OLT uji). Sumber OID:
 * peta OID HSGQ-G02ID firmware `IGC_V1.2.9ID_Rel` yang tiap barisnya dicocokkan ke web UI
 * (repo komunitas `Ogydump-mile/hsgq-g02id-olt-zabbix`) untuk skema `.3.12`, dan dua bot EPON
 * independen untuk skema `.3.3`. Rincian + tingkat keyakinan: `docs/SMARTOLT_HSGQ_GUIDE.md`.
 *
 * - Dua skema tabel ONU ditemukan di lapangan dan tak bisa ditebak dari model, jadi keduanya dicoba
 *   ({@see self::SCHEMAS}); yang berisi baris dipakai.
 * - Arti kolom status ONU belum pernah terlihat saat ada ONU offline. Karena itu status dipilih dari
 *   beberapa sinyal — yang jumlah online per PON-nya cocok dengan penghitung OLT `.3.2.3.1.4`. Bila tak
 *   ada yang cocok, baris ONU ditandai `status_verified=false` dan {@see AlarmEvaluator} tidak
 *   menaikkan alarm ONU offline/ODP untuknya (lebih baik diam daripada banjir alarm palsu).
 * - JANGAN walk subtree sistem `.3.1.1` maupun `.3.1.5`: berisi kredensial (community tulis, akun)
 *   yang terbaca lewat community baca. Skalar diambil dengan GET OID persis.
 */
class HsgqSnmpService implements SmartOltSnmpDriver
{
    private const SYS_DESCR = '1.3.6.1.2.1.1.1.0';

    private const SYS_OBJECT_ID = '1.3.6.1.2.1.1.2.0';

    private const SYS_UPTIME = '1.3.6.1.2.1.1.3.0';

    private const SYS_NAME = '1.3.6.1.2.1.1.5.0';

    private const IF_DESCR = '1.3.6.1.2.1.2.2.1.2';

    private const IF_OPER_STATUS = '1.3.6.1.2.1.2.2.1.8';

    private const OLT_MAC = '1.3.6.1.4.1.50224.3.1.1.1.0';

    private const OLT_FIRMWARE = '1.3.6.1.4.1.50224.3.1.1.6.0';

    private const OLT_CPU = '1.3.6.1.4.1.50224.3.1.1.17.0';

    private const OLT_MEMORY = '1.3.6.1.4.1.50224.3.1.1.18.0';

    private const OLT_SERIAL = '1.3.6.1.4.1.50224.3.1.1.20.0';

    /** Jumlah ONU per PON (indeks = indeks PON): terdaftar / online / offline. */
    private const PON_REGISTERED = '1.3.6.1.4.1.50224.3.2.3.1.3';

    private const PON_ONLINE = '1.3.6.1.4.1.50224.3.2.3.1.4';

    private const PON_OFFLINE = '1.3.6.1.4.1.50224.3.2.3.1.5';

    /** Optik modul SFP PON OLT (÷100): suhu °C, tegangan V, bias mA, Tx dBm. */
    private const SFP_COLUMNS = [
        'temperature_c' => '1.3.6.1.4.1.50224.3.2.4.1.8',
        'voltage_v' => '1.3.6.1.4.1.50224.3.2.4.1.9',
        'bias_ma' => '1.3.6.1.4.1.50224.3.2.4.1.10',
        'tx_dbm' => '1.3.6.1.4.1.50224.3.2.4.1.11',
    ];

    /**
     * `id` = firmware "ID"/GPON (G02ID/G04ID, keyakinan A); `legacy` = tabel EPON yang dipakai dua bot
     * EPON (keyakinan C). Kolom null = tak ada di skema itu.
     *
     * @var array<string, array<string, mixed>>
     */
    private const SCHEMAS = [
        'id' => [
            'table' => '1.3.6.1.4.1.50224.3.12.2.1',
            'optic' => '1.3.6.1.4.1.50224.3.12.3.1',
            'name' => 2,
            'status' => 3,
            'identity' => 15,
            'identity_is_mac' => false,
            'vendor' => 8,
            'model' => 9,
            'firmware' => 13,
            'distance' => 19,
            'down_cause' => 22,
            'online_time' => 23,
        ],
        'legacy' => [
            'table' => '1.3.6.1.4.1.50224.3.3.2.1',
            'optic' => '1.3.6.1.4.1.50224.3.3.3.1',
            'name' => 2,
            'status' => 9,
            'identity' => 7,
            'identity_is_mac' => true,
            'vendor' => 25,
            'model' => null,
            'firmware' => null,
            'distance' => 15,
            'down_cause' => null,
            'online_time' => null,
        ],
    ];

    private const OPTIC_RX = 4;

    private const OPTIC_TX = 5;

    /** Urutan kandidat sinyal status ONU (lihat {@see self::resolveStatus()}). */
    private const STATUS_CANDIDATES = ['status_column', 'online_time', 'rx'];

    /** @var array<string, string|null> skema per OLT untuk satu instance (satu scan) */
    private array $schemaMemo = [];

    /** @var array<int, array<int, array<string, int|null>>> penghitung per PON per OLT */
    private array $countersMemo = [];

    /** @var array<string, mixed>|null hasil pemilihan status scan terakhir (untuk diagnostik) */
    private ?array $lastStatusCheck = null;

    public function __construct(private readonly HsgqSnmp $snmp) {}

    public function ping(SnmpOlt $olt): bool
    {
        try {
            return str_contains((string) $this->snmp->get($olt, self::SYS_OBJECT_ID), '50224');
        } catch (Throwable) {
            return false;
        }
    }

    public function getSystemInfo(SnmpOlt $olt): array
    {
        $descr = $this->snmp->get($olt, self::SYS_DESCR);
        $schema = $this->schema($olt);

        return [
            'sys_descr' => $descr,
            'sys_object_id' => $this->snmp->get($olt, self::SYS_OBJECT_ID),
            'sys_uptime' => $this->snmp->get($olt, self::SYS_UPTIME),
            'sys_name' => $this->snmp->get($olt, self::SYS_NAME),
            'model' => $descr,
            'firmware' => HsgqValue::text($this->snmp->get($olt, self::OLT_FIRMWARE)),
            'serial' => HsgqValue::text($this->snmp->get($olt, self::OLT_SERIAL)),
            'mac' => HsgqValue::mac($this->snmp->get($olt, self::OLT_MAC)),
            'cpu_percent' => HsgqValue::toInt($this->snmp->get($olt, self::OLT_CPU)),
            'memory_percent' => HsgqValue::toInt($this->snmp->get($olt, self::OLT_MEMORY)),
            'pon_type' => SmartOltSupport::hsgqPonTypeFromText($descr) ?? $this->ponTypeFromSchema($olt, $schema),
            'hsgq_schema' => $schema,
        ];
    }

    /**
     * Port PON dari IF-MIB (`ifDescr` = `PON01`…, `ifOperStatus` 1 = up) — keduanya WAJIB walk. Bila
     * firmware tak menamai port PON di IF-MIB, daftar PON diturunkan dari penghitung ONU per PON.
     */
    public function getPorts(SnmpOlt $olt): array
    {
        $descr = $this->snmp->walk($olt, self::IF_DESCR);
        $operByIndex = [];
        foreach ($this->snmp->walk($olt, self::IF_OPER_STATUS) as $oid => $value) {
            $suffix = HsgqValue::suffix($oid, self::IF_OPER_STATUS);
            if ($suffix !== []) {
                $operByIndex[$suffix[0]] = HsgqValue::toInt($value);
            }
        }

        $counters = $this->ponCounters($olt);
        $sfp = $this->sfpOptics($olt);

        $ports = [];
        foreach ($descr as $oid => $label) {
            $suffix = HsgqValue::suffix($oid, self::IF_DESCR);
            $pon = $this->ponFromLabel((string) $label);
            if ($suffix === [] || $pon === null) {
                continue;
            }

            $ports[$pon] = $this->portRow($pon, $suffix[0], (string) $label, $operByIndex[$suffix[0]] ?? null, $counters[$pon] ?? [], $sfp[$pon] ?? []);
        }

        foreach ($counters as $pon => $count) {
            $ports[$pon] ??= $this->portRow($pon, null, null, null, $count, $sfp[$pon] ?? []);
        }

        ksort($ports);

        return array_values($ports);
    }

    public function getRegisteredOnus(SnmpOlt $olt): array
    {
        $schemaKey = $this->schema($olt);
        if ($schemaKey === null) {
            $this->lastStatusCheck = ['source' => null, 'verified' => false, 'reason' => 'no_onu_table'];

            return [];
        }

        $schema = self::SCHEMAS[$schemaKey];
        $column = fn (?int $col): array => $col === null ? [] : $this->walkColumn($olt, $schema['table'].'.'.$col);

        $names = $column($schema['name']);
        $identities = $column($schema['identity']);
        $statuses = $column($schema['status']);
        $vendors = $column($schema['vendor']);
        $models = $column($schema['model']);
        $firmwares = $column($schema['firmware']);
        $distances = $column($schema['distance']);
        $causes = $column($schema['down_cause']);
        $onlineTimes = $column($schema['online_time']);
        [$rxOnu, $rxOlt] = $this->opticColumn($olt, $schema['optic'].'.'.self::OPTIC_RX);
        [$txOnu] = $this->opticColumn($olt, $schema['optic'].'.'.self::OPTIC_TX);

        $rows = [];
        foreach (array_keys($identities + $names) as $index) {
            $position = HsgqValue::decodeIndex($index);
            if ($position === null) {
                continue;
            }

            $identity = HsgqValue::identity($identities[$index] ?? null, $schema['identity_is_mac']);
            $rows[$index] = [
                'position' => $position,
                'identity' => $identity,
                'name' => HsgqValue::text($names[$index] ?? null),
                'status_raw' => HsgqValue::toInt($statuses[$index] ?? null),
                'online_time' => HsgqValue::toInt($onlineTimes[$index] ?? null),
                'rx' => HsgqValue::centiDbm($rxOnu[$index] ?? null),
                'olt_rx' => HsgqValue::centiDbm($rxOlt[$index] ?? null),
                'tx' => HsgqValue::centi($txOnu[$index] ?? null),
                'vendor' => HsgqValue::text($vendors[$index] ?? null),
                'model' => HsgqValue::text($models[$index] ?? null),
                'firmware' => HsgqValue::text($firmwares[$index] ?? null),
                'distance' => HsgqValue::toInt($distances[$index] ?? null),
                'down_cause' => HsgqValue::text($causes[$index] ?? null),
            ];
        }

        [$source, $verified] = $this->resolveStatus($olt, $rows, $statuses !== [], $onlineTimes !== []);

        $onus = [];
        foreach ($rows as $index => $row) {
            $onus[] = $this->buildOnu($index, $row, $this->isOnline($row, $source), $source, $verified);
        }

        usort($onus, fn ($a, $b) => [$a['port'], $a['onu_id']] <=> [$b['port'], $b['onu_id']]);

        return $onus;
    }

    public function getRegisteredOnusByPort(SnmpOlt $olt, int $slot, int $port): array
    {
        return array_values(array_filter(
            $this->getRegisteredOnus($olt),
            fn (array $onu) => $onu['slot'] === $slot && $onu['port'] === $port,
        ));
    }

    public function getPortRxMap(SnmpOlt $olt): array
    {
        $map = [];
        foreach ($this->getRegisteredOnus($olt) as $onu) {
            $map[$onu['onu_key']] = $onu['rx_power_dbm'];
        }

        return $map;
    }

    public function countRegisteredOnus(SnmpOlt $olt): int
    {
        return count($this->getRegisteredOnus($olt));
    }

    /**
     * Daftar ONU belum terdaftar belum didukung: subtree kandidatnya (`.3.12.8`) belum pernah
     * diperiksa isinya, dan bentuk respons web (`/gponont_mgmt?form=auth`, `/onu_allow_list`) tak
     * ditemukan di sumber mana pun.
     */
    public function getUnconfiguredOnus(SnmpOlt $olt): array
    {
        return [];
    }

    /**
     * Laporan diagnostik untuk memverifikasi driver di perangkat pertama (disalin pengguna ke issue).
     * Identitas ONU disamarkan, nama tidak disertakan, dan tak ada OID sistem selain skalar di atas.
     *
     * @return array<string, mixed>
     */
    public function diagnose(SnmpOlt $olt): array
    {
        $report = ['snmp_ok' => false, 'errors' => []];

        try {
            $system = $this->getSystemInfo($olt);
            $report['snmp_ok'] = $system['sys_object_id'] !== null || $system['sys_descr'] !== null;
            $report['system'] = [
                'sys_object_id' => $system['sys_object_id'],
                'sys_descr' => $system['sys_descr'],
                'firmware' => $system['firmware'],
                'pon_type' => $system['pon_type'],
                'schema' => $system['hsgq_schema'],
            ];
            $report['onu_table_rows'] = [
                'id' => count($this->walkColumn($olt, self::SCHEMAS['id']['table'].'.'.self::SCHEMAS['id']['name'])),
                'legacy' => count($this->walkColumn($olt, self::SCHEMAS['legacy']['table'].'.'.self::SCHEMAS['legacy']['name'])),
            ];

            $ports = $this->getPorts($olt);
            $report['ports'] = array_map(fn (array $p) => [
                'port' => $p['port'],
                'if_descr' => $p['label'],
                'oper_status' => $p['oper_status'],
                'registered' => $p['onu_registered_snmp'],
                'online' => $p['onu_online_snmp'],
                'offline' => $p['onu_offline_snmp'],
            ], $ports);

            $onus = $this->getRegisteredOnus($olt);
            $report['onu_count'] = count($onus);
            $report['status_check'] = $this->lastStatusCheck;
            $sample = $onus[0] ?? null;
            $report['sample_onu'] = $sample === null ? null : [
                'onu_key' => $sample['onu_key'],
                'if_index' => $sample['if_index'],
                'identity' => HsgqValue::mask($sample['serial_number']),
                'identity_kind' => $sample['mac'] !== null ? 'mac' : 'serial',
                'name_length' => strlen((string) $sample['name']),
                'status_raw' => $sample['status_raw'],
                'online' => $sample['online'],
                'rx_power_dbm' => $sample['rx_power_dbm'],
                'olt_rx_power_dbm' => $sample['olt_rx_power_dbm'],
                'distance_m' => $sample['distance_m'],
                'vendor_id' => $sample['vendor_id'],
                'type_name' => $sample['type_name'],
                'last_down_cause' => $sample['last_down_cause'],
            ];
        } catch (Throwable $exception) {
            $report['errors'][] = $exception->getMessage();
        }

        return $report;
    }

    /**
     * Skema tabel ONU yang hidup di OLT ini: 'id' (`.3.12`), 'legacy' (`.3.3`), atau null.
     */
    private function schema(SnmpOlt $olt): ?string
    {
        $key = (string) ($olt->id ?? spl_object_id($olt));

        if (! array_key_exists($key, $this->schemaMemo)) {
            $found = null;
            foreach (self::SCHEMAS as $name => $schema) {
                if ($this->snmp->walk($olt, $schema['table'].'.'.$schema['identity']) !== []
                    || $this->snmp->walk($olt, $schema['table'].'.'.$schema['name']) !== []) {
                    $found = $name;
                    break;
                }
            }
            $this->schemaMemo[$key] = $found;
        }

        return $this->schemaMemo[$key];
    }

    /** Skema `.3.3` = tabel ber-MAC (EPON); skema `.3.12` dinilai dari jenis identitas ONU pertama. */
    private function ponTypeFromSchema(SnmpOlt $olt, ?string $schema): ?string
    {
        if ($schema === 'legacy') {
            return 'epon';
        }

        if ($schema !== 'id') {
            return null;
        }

        foreach ($this->walkColumn($olt, self::SCHEMAS['id']['table'].'.'.self::SCHEMAS['id']['identity']) as $raw) {
            $identity = HsgqValue::identity($raw);
            if ($identity['serial'] !== null) {
                return $identity['mac'] !== null ? 'epon' : 'gpon';
            }
        }

        return null;
    }

    /**
     * Pilih sinyal status ONU yang jumlah online per PON-nya sama dengan penghitung OLT.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{0: string, 1: bool} [sumber, terverifikasi]
     */
    private function resolveStatus(SnmpOlt $olt, array $rows, bool $hasStatusColumn, bool $hasOnlineTime): array
    {
        $available = [
            'status_column' => $hasStatusColumn,
            'online_time' => $hasOnlineTime,
            'rx' => true,
        ];

        $expected = [];
        foreach ($this->ponCounters($olt) as $pon => $count) {
            if ($count['online'] !== null && ($count['registered'] === null || $count['online'] <= $count['registered'])) {
                $expected[$pon] = $count['online'];
            }
        }

        $perCandidate = [];
        foreach (self::STATUS_CANDIDATES as $candidate) {
            if (! $available[$candidate]) {
                continue;
            }

            $counts = [];
            foreach ($rows as $row) {
                $pon = $row['position'][0];
                $counts[$pon] = ($counts[$pon] ?? 0) + ($this->isOnline($row, $candidate) ? 1 : 0);
            }
            $perCandidate[$candidate] = $counts;

            if ($expected === [] || $rows === []) {
                continue;
            }

            $matches = true;
            foreach (array_keys($expected + $counts) as $pon) {
                if (($counts[$pon] ?? 0) !== ($expected[$pon] ?? 0)) {
                    $matches = false;
                    break;
                }
            }

            if ($matches) {
                $this->lastStatusCheck = ['source' => $candidate, 'verified' => true, 'expected' => $expected, 'candidates' => $perCandidate];

                return [$candidate, true];
            }
        }

        $fallback = $hasStatusColumn ? 'status_column' : ($hasOnlineTime ? 'online_time' : 'rx');
        $this->lastStatusCheck = [
            'source' => $fallback,
            'verified' => false,
            'reason' => $expected === [] ? 'no_pon_counters' : 'mismatch',
            'expected' => $expected,
            'candidates' => $perCandidate,
        ];

        return [$fallback, false];
    }

    /** @param  array<string, mixed>  $row */
    private function isOnline(array $row, string $source): bool
    {
        return match ($source) {
            'status_column' => $row['status_raw'] === 1,
            'online_time' => ($row['online_time'] ?? 0) > 0,
            default => $row['rx'] !== null,
        };
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function buildOnu(int $index, array $row, bool $online, string $statusSource, bool $statusVerified): array
    {
        [$pon, $onuId] = $row['position'];
        $rx = $online ? $row['rx'] : null;
        $cause = $row['down_cause'];

        return [
            'onu_key' => "{$pon}.{$onuId}",
            'if_index' => $index,
            'slot' => 1,
            'port' => $pon,
            'onu_id' => $onuId,
            'interface' => sprintf('PON%02d/%d', $pon, $onuId),
            'type_name' => $row['model'],
            'name' => $row['name'],
            'description' => null,
            // GPON: SN (`HWTCA9089620`); EPON: MAC (`AA:BB:…`, sama seperti HiOSO) — pencocok ACS
            // memperlakukan serial ber-`:` sebagai MAC.
            'serial_number' => $row['identity']['serial'],
            'mac' => $row['identity']['mac'],
            'vendor_id' => $row['vendor'],
            'firmware' => $row['firmware'],
            'admin_state' => 'unknown',
            'phase_state' => $online ? 'Online' : HsgqValue::offlinePhase($cause),
            'online' => $online,
            'status_raw' => $row['status_raw'],
            'status_source' => $statusSource,
            // false = sinyal status tak cocok dengan penghitung OLT → alarm ONU offline ditahan.
            'status_verified' => $statusVerified,
            'last_down_cause' => $cause,
            'uptime' => $online ? HsgqValue::duration($row['online_time']) : null,
            'distance_m' => $row['distance'] !== null && $row['distance'] > 0 ? $row['distance'] : null,
            'rx_power_dbm' => $rx,
            'rx_power_label' => $rx !== null ? sprintf('%.2f dBm', $rx) : null,
            'rx_power_source' => $rx !== null ? 'snmp' : null,
            'olt_rx_power_dbm' => $online ? $row['olt_rx'] : null,
            'tx_power_dbm' => $online ? $row['tx'] : null,
            'missed_polls' => 0,
        ];
    }

    /**
     * @param  array<string, int|null>  $count
     * @param  array<string, float|null>  $sfp
     * @return array<string, mixed>
     */
    private function portRow(int $pon, ?int $ifIndex, ?string $label, ?int $operCode, array $count, array $sfp): array
    {
        return [
            'if_index' => $ifIndex,
            'name' => sprintf('PON%02d', $pon),
            'label' => $label,
            'slot' => 1,
            'port' => $pon,
            'oper_status_code' => $operCode,
            'oper_status' => match ($operCode) {
                1 => 'up',
                2 => 'down',
                default => 'unknown',
            },
            'onu_registered_snmp' => $count['registered'] ?? null,
            'onu_online_snmp' => $count['online'] ?? null,
            'onu_offline_snmp' => $count['offline'] ?? null,
            'sfp' => $sfp === [] ? null : $sfp,
        ];
    }

    /** Nomor PON dari ifDescr (`PON01`, `PON 1`, `EPON0/3`); bukan port PON → null. */
    private function ponFromLabel(string $label): ?int
    {
        if (! preg_match('/pon/i', $label) || preg_match('/nni|uplink/i', $label)) {
            return null;
        }

        return preg_match('/(\d+)\D*$/', $label, $m) && (int) $m[1] >= 1 ? (int) $m[1] : null;
    }

    /**
     * @return array<int, array<string, int|null>> [PON => [registered, online, offline]]
     */
    private function ponCounters(SnmpOlt $olt): array
    {
        $key = (int) ($olt->id ?? spl_object_id($olt));

        if (! isset($this->countersMemo[$key])) {
            $counters = [];
            foreach (['registered' => self::PON_REGISTERED, 'online' => self::PON_ONLINE, 'offline' => self::PON_OFFLINE] as $field => $column) {
                foreach ($this->walkColumn($olt, $column) as $index => $value) {
                    $position = HsgqValue::decodeIndex($index);
                    if ($position === null) {
                        continue;
                    }
                    $counters[$position[0]] ??= ['registered' => null, 'online' => null, 'offline' => null];
                    $counters[$position[0]][$field] = HsgqValue::toInt($value);
                }
            }
            ksort($counters);
            $this->countersMemo[$key] = $counters;
        }

        return $this->countersMemo[$key];
    }

    /**
     * @return array<int, array<string, float|null>> [PON => optik SFP]; port tanpa modul tak ikut
     */
    private function sfpOptics(SnmpOlt $olt): array
    {
        $optics = [];
        foreach (self::SFP_COLUMNS as $field => $column) {
            foreach ($this->walkColumn($olt, $column) as $index => $value) {
                $position = HsgqValue::decodeIndex($index);
                $reading = HsgqValue::centi($value);
                if ($position === null || $reading === null) {
                    continue;
                }
                $optics[$position[0]][$field] = $reading;
            }
        }

        return $optics;
    }

    /**
     * Walk satu kolom berindeks tunggal → [indeks => nilai].
     *
     * @return array<int, string>
     */
    private function walkColumn(SnmpOlt $olt, string $column): array
    {
        $values = [];
        foreach ($this->snmp->walk($olt, $column) as $oid => $value) {
            $suffix = HsgqValue::suffix($oid, $column);
            if (count($suffix) === 1) {
                $values[$suffix[0]] = $value;
            }
        }

        return $values;
    }

    /**
     * Kolom optik ONU berakhiran dua segmen: `.0.0` = dibaca ONU, `.65535.65535` = Rx di sisi OLT.
     *
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private function opticColumn(SnmpOlt $olt, string $column): array
    {
        $onuSide = [];
        $oltSide = [];
        foreach ($this->snmp->walk($olt, $column) as $oid => $value) {
            $suffix = HsgqValue::suffix($oid, $column);
            if (count($suffix) !== 3) {
                continue;
            }

            [$index, $a, $b] = $suffix;
            if ($a === 0 && $b === 0) {
                $onuSide[$index] = $value;
            } elseif ($a === 65535 && $b === 65535) {
                $oltSide[$index] = $value;
            }
        }

        return [$onuSide, $oltSide];
    }
}
